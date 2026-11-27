<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_yaml, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_yaml
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

declare(strict_types=1);

namespace Somethingwithproof\Yaml\Pack;

use Somethingwithproof\Yaml\Codec\YamlEmitter;
use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Merge\FragmentMerger;
use Somethingwithproof\Yaml\Merge\MergePolicy;
use Somethingwithproof\Yaml\Query\DataQueryCompiler;
use Somethingwithproof\Yaml\Repository\YamlStateRepository;
use Somethingwithproof\Yaml\Security\PathGuard;
use Somethingwithproof\Yaml\Template\TemplateCompiler;

/**
 * Manages versioned Monitoring Packs: export, validate, plan, apply, and
 * SHA-256 lockfile generation and verification (`cacti-pack.lock.yaml`).
 */
final class PackService {
	/**
	 * Default filename for Monitoring Pack SHA-256 lockfiles.
	 */
	public const LOCKFILE_NAME = 'cacti-pack.lock.yaml';

	/**
	 * Construct a PackService with optional database state repository and Cacti root path.
	 *
	 * @param ?YamlStateRepository $repository Optional persistence layer for applied packs and queries.
	 * @param string $cactiBasePath Optional Cacti root path for writing compiled Data Query XML files.
	 */
	public function __construct(
		/**
		 * Optional MariaDB persistence repository.
		 */
		private readonly ?YamlStateRepository $repository = null,
		/**
		 * Optional Cacti base directory for compiled Data Query XML output.
		 */
		private readonly string $cactiBasePath = '',
	) {
	}

	/**
	 * Export a MonitoringPack manifest and its SHA-256 lockfile for a pack directory.
	 *
	 * @param string $packDir Directory containing pack artifacts.
	 * @param string $name Pack slug.
	 * @param string $version Pack SemVer string.
	 * @param string $description Human-readable summary.
	 * @return array{manifestYaml: string, lockYaml: string, manifestSha256: string, lockSha256: string}
	 * @throws YamlException When directory or artifact validation fails.
	 */
	public function export(string $packDir, string $name, string $version = '1.0.0', string $description = ''): array {
		$realDir = PathGuard::assertSafePath($packDir, true);

		if (!is_dir($realDir)) {
			throw new YamlException(sprintf('Pack directory "%s" does not exist.', $packDir));
		}

		$templates   = self::scanSubdir($realDir, 'templates', ['.yaml', '.yml', '.xml']);
		$dataQueries = self::scanSubdir($realDir, 'queries', ['.yaml', '.yml']);
		$fragments   = self::scanSubdir($realDir, 'casc.d', ['.yaml', '.yml']);

		$manifest = new PackManifest($name, $version, $description, '1.2.31', $templates, $dataQueries, $fragments);
		$manifestYaml = $manifest->toYaml();
		$lockData     = $this->buildLockData($manifest, $realDir);
		$lockYaml     = YamlEmitter::emit($lockData, false);

		return [
			'manifestYaml'   => $manifestYaml,
			'lockYaml'       => $lockYaml,
			'manifestSha256' => hash('sha256', $manifestYaml),
			'lockSha256'     => hash('sha256', $lockYaml),
		];
	}

	/**
	 * Validate a MonitoringPack manifest, all referenced templates, Data Queries,
	 * CasC fragments, and optional lockfile digests.
	 *
	 * @param string $manifestPath Path to the MonitoringPack YAML manifest.
	 * @param bool $requireLockfile When true, fail if `cacti-pack.lock.yaml` is missing.
	 * @return array{valid: bool, manifest: PackManifest, errors: list<string>, lockData: array<string, mixed>, manifestSha256: string}
	 */
	public function validate(string $manifestPath, bool $requireLockfile = false): array {
		$raw            = PathGuard::readBoundedFile($manifestPath);
		$manifest       = PackManifest::fromYaml($raw);
		$baseDir        = dirname(PathGuard::assertSafePath($manifestPath));
		$manifestSha256 = hash('sha256', $raw);
		$errors         = [];
		$lockData       = [];

		try {
			$lockData = $this->buildLockData($manifest, $baseDir);
		} catch (YamlException $e) {
			$errors = array_merge($errors, $e->lines());
		}

		$lockPath = $baseDir . '/' . self::LOCKFILE_NAME;

		if (is_file($lockPath)) {
			try {
				$lockRaw    = PathGuard::readBoundedFile($lockPath);
				$parsedLock = YamlParser::parseMap($lockRaw);
				$errors     = array_merge($errors, $this->verifyLock($parsedLock, $lockData));
			} catch (YamlException $e) {
				$errors = array_merge($errors, $e->lines());
			}
		} elseif ($requireLockfile) {
			$errors[] = sprintf('Required lockfile "%s" is missing.', self::LOCKFILE_NAME);
		}

		return [
			'valid'          => $errors === [],
			'manifest'       => $manifest,
			'errors'         => array_values(array_unique($errors)),
			'lockData'       => $lockData,
			'manifestSha256' => $manifestSha256,
		];
	}

	/**
	 * Compute a deterministic dry-run plan and confirmation hash for applying a pack.
	 *
	 * @param string $manifestPath Path to MonitoringPack YAML file.
	 * @return array{planHash: string, manifest: PackManifest, actions: list<array{type: string, target: string, sha256: string, operation: string}>, errors: list<string>, lockYaml: string, lockSha256: string}
	 */
	public function plan(string $manifestPath): array {
		$validation = $this->validate($manifestPath, false);
		$manifest   = $validation['manifest'];
		$lockData   = $validation['lockData'];
		$lockYaml   = $lockData !== [] ? YamlEmitter::emit($lockData, false) : '';
		$lockSha256 = hash('sha256', $lockYaml);
		$actions    = [];

		$artifacts = is_array($lockData['artifacts'] ?? null) ? $lockData['artifacts'] : [];
		$existing  = $this->repository !== null ? $this->repository->getPackByName($manifest->name) : null;

		$actions[] = [
			'type'      => 'pack',
			'target'    => $manifest->name . '@' . $manifest->version,
			'sha256'    => $lockSha256,
			'operation' => $existing === null ? 'create' : ($existing['lock_sha256'] === $lockSha256 ? 'noop' : 'update'),
		];

		foreach ($artifacts as $artifact) {
			if (!is_array($artifact)) {
				continue;
			}

			$type   = is_string($artifact['type'] ?? null) ? $artifact['type'] : 'artifact';
			$target = is_string($artifact['outputFile'] ?? null)
				? $artifact['outputFile']
				: (is_string($artifact['path'] ?? null) ? $artifact['path'] : '');
			$digest = is_string($artifact['compiledSha256'] ?? null)
				? $artifact['compiledSha256']
				: (is_string($artifact['sourceSha256'] ?? null) ? $artifact['sourceSha256'] : '');

			$actions[] = [
				'type'      => $type,
				'target'    => $target,
				'sha256'    => $digest,
				'operation' => 'sync',
			];
		}

		$encoded  = json_encode(['pack' => $manifest->name, 'version' => $manifest->version, 'actions' => $actions]);
		$planHash = hash('sha256', is_string($encoded) ? $encoded : $lockSha256);

		return [
			'planHash'   => $planHash,
			'manifest'   => $manifest,
			'actions'    => $actions,
			'errors'     => $validation['errors'],
			'lockYaml'   => $lockYaml,
			'lockSha256' => $lockSha256,
		];
	}

	/**
	 * Apply a validated MonitoringPack after verifying the confirmation plan hash.
	 *
	 * Writes compiled Data Query XML files inside allowlisted resource directories
	 * (when `$cactiBasePath` is configured), writes `cacti-pack.lock.yaml`, and
	 * records pack + audit metadata in the repository when present.
	 *
	 * @param string $manifestPath Path to MonitoringPack YAML file.
	 * @param string $confirmHash Confirmation hash matching `plan()['planHash']`.
	 * @param string $actor Operator username or CLI identity.
	 * @param string $source Invocation channel (`cli` or `web`).
	 * @return array{succeeded: bool, planHash: string, lockSha256: string, writtenFiles: list<string>, errors: list<string>}
	 */
	public function apply(string $manifestPath, string $confirmHash, string $actor = 'cli', string $source = 'cli'): array {
		$plan = $this->plan($manifestPath);

		if ($plan['errors'] !== []) {
			return [
				'succeeded'    => false,
				'planHash'     => $plan['planHash'],
				'lockSha256'   => $plan['lockSha256'],
				'writtenFiles' => [],
				'errors'       => $plan['errors'],
			];
		}

		if ($confirmHash === '' || !hash_equals($plan['planHash'], strtolower(trim($confirmHash)))) {
			return [
				'succeeded'    => false,
				'planHash'     => $plan['planHash'],
				'lockSha256'   => $plan['lockSha256'],
				'writtenFiles' => [],
				'errors'       => [sprintf('Confirmation hash mismatch. Re-run with --confirm=%s.', $plan['planHash'])],
			];
		}

		$manifest     = $plan['manifest'];
		$realManifest = PathGuard::assertSafePath($manifestPath);
		$baseDir      = dirname($realManifest);
		$writtenFiles = [];

		// Compile and write Data Query XML files into confined Cacti resource paths when configured.
		foreach ($manifest->dataQueries as $relQuery) {
			$queryFile = PathGuard::resolveConfined($relQuery, $baseDir, true);
			$compiled  = DataQueryCompiler::compileYaml(PathGuard::readBoundedFile($queryFile));

			if ($this->cactiBasePath !== '' && is_dir($this->cactiBasePath)) {
				$targetPath     = PathGuard::resolveConfined($compiled['outputFile'], $this->cactiBasePath, false);
				$writtenSha     = PathGuard::writeAtomic($targetPath, $compiled['xml']);
				$writtenFiles[] = $compiled['outputFile'];

				if ($this->repository !== null) {
					$this->repository->upsertQuery(
						$compiled['name'],
						$compiled['kind'],
						$compiled['outputFile'],
						$writtenSha
					);
				}
			}
		}

		// Write deterministic lockfile alongside the manifest.
		$lockPath = PathGuard::resolveConfined(self::LOCKFILE_NAME, $baseDir, false);
		PathGuard::writeAtomic($lockPath, $plan['lockYaml']);
		$writtenFiles[] = self::LOCKFILE_NAME;

		if ($this->repository !== null) {
			$rawManifest = PathGuard::readBoundedFile($realManifest);
			$this->repository->upsertPack(
				$manifest->name,
				$manifest->version,
				hash('sha256', $rawManifest),
				$plan['lockSha256'],
				$realManifest,
				$actor,
				'applied',
				$manifest->toArray()
			);
			$this->repository->recordAudit(
				$actor,
				$source,
				'pack-apply',
				$plan['planHash'],
				'succeeded',
				[
					'pack'         => $manifest->name,
					'version'      => $manifest->version,
					'writtenFiles' => $writtenFiles,
				]
			);
		}

		return [
			'succeeded'    => true,
			'planHash'     => $plan['planHash'],
			'lockSha256'   => $plan['lockSha256'],
			'writtenFiles' => $writtenFiles,
			'errors'       => [],
		];
	}

	/**
	 * Compile and hash all referenced artifacts in a pack into a deterministic lock structure.
	 *
	 * @param PackManifest $manifest Validated manifest.
	 * @param string $baseDir Base directory confining relative artifact paths.
	 * @return array<string, mixed> Lockfile data ready for YAML emission.
	 * @throws YamlException When any artifact fails security, syntax, or compilation checks.
	 */
	private function buildLockData(PackManifest $manifest, string $baseDir): array {
		$artifacts = [];

		foreach ($manifest->templates as $relPath) {
			$file   = PathGuard::resolveConfined($relPath, $baseDir, true);
			$raw    = PathGuard::readBoundedFile($file);
			$lower  = strtolower($file);
			$entry  = [
				'type'         => 'template',
				'path'         => $relPath,
				'sourceSha256' => hash('sha256', $raw),
			];

			if (str_ends_with($lower, '.yaml') || str_ends_with($lower, '.yml')) {
				$compiled                = TemplateCompiler::compileYaml($raw);
				$entry['compiledSha256'] = $compiled['sha256'];
			} else {
				if (preg_match('/<!(DOCTYPE|ENTITY)\b/i', $raw) === 1 || !str_contains($raw, '<cacti>')) {
					throw new YamlException(sprintf('Template XML "%s" is invalid or contains disallowed DTD/ENTITY declarations.', $relPath));
				}

				$entry['compiledSha256'] = hash('sha256', $raw);
			}

			$artifacts[] = $entry;
		}

		foreach ($manifest->dataQueries as $relPath) {
			$file     = PathGuard::resolveConfined($relPath, $baseDir, true);
			$raw      = PathGuard::readBoundedFile($file);
			$compiled = DataQueryCompiler::compileYaml($raw);

			$artifacts[] = [
				'type'           => 'dataQuery',
				'path'           => $relPath,
				'outputFile'     => $compiled['outputFile'],
				'sourceSha256'   => hash('sha256', $raw),
				'compiledSha256' => $compiled['sha256'],
			];
		}

		if ($manifest->cascFragments !== []) {
			$resolvedFragments = [];

			foreach ($manifest->cascFragments as $relPath) {
				$file                = PathGuard::resolveConfined($relPath, $baseDir, true);
				$resolvedFragments[] = $file;
				$raw                 = PathGuard::readBoundedFile($file);

				$artifacts[] = [
					'type'         => 'cascFragment',
					'path'         => $relPath,
					'sourceSha256' => hash('sha256', $raw),
				];
			}

			// Verify fragments merge cleanly under strict mode.
			FragmentMerger::mergeSources($resolvedFragments, MergePolicy::STRICT);
		}

		return [
			'apiVersion' => PackManifest::API_VERSION,
			'kind'       => 'MonitoringPackLock',
			'pack'       => [
				'name'    => $manifest->name,
				'version' => $manifest->version,
			],
			'artifacts' => $artifacts,
		];
	}

	/**
	 * Verify an existing lockfile on disk against freshly computed artifact digests.
	 *
	 * @param array<string, mixed> $recorded Lockfile parsed from disk.
	 * @param array<string, mixed> $computed Freshly computed lock data.
	 * @return list<string> Mismatch error lines.
	 */
	private function verifyLock(array $recorded, array $computed): array {
		$errors       = [];
		$recArtifacts = is_array($recorded['artifacts'] ?? null) ? $recorded['artifacts'] : [];
		$curArtifacts = is_array($computed['artifacts'] ?? null) ? $computed['artifacts'] : [];

		$recByPath = [];

		foreach ($recArtifacts as $item) {
			if (is_array($item) && is_string($item['path'] ?? null)) {
				$recByPath[$item['path']] = $item;
			}
		}

		foreach ($curArtifacts as $item) {
			if (!is_array($item) || !is_string($item['path'] ?? null)) {
				continue;
			}

			$path = $item['path'];

			if (!isset($recByPath[$path])) {
				$errors[] = sprintf('Lockfile drift: artifact "%s" is not pinned in %s.', $path, self::LOCKFILE_NAME);

				continue;
			}

			$recSha = is_string($recByPath[$path]['sourceSha256'] ?? null) ? $recByPath[$path]['sourceSha256'] : '';
			$curSha = is_string($item['sourceSha256'] ?? null) ? $item['sourceSha256'] : '';

			if (!hash_equals($recSha, $curSha)) {
				$errors[] = sprintf('Lockfile SHA-256 mismatch for "%s" (locked %s, current %s).', $path, $recSha, $curSha);
			}
		}

		return $errors;
	}

	/**
	 * Scan a subdirectory of a pack root for files matching allowed extensions.
	 *
	 * @param string $packRoot Canonical pack root directory.
	 * @param string $subDir Subdirectory name.
	 * @param list<string> $extensions Allowed lowercase suffixes.
	 * @return list<string> Relative paths in lexical order.
	 */
	private static function scanSubdir(string $packRoot, string $subDir, array $extensions): array {
		$dir = $packRoot . '/' . $subDir;

		if (!is_dir($dir) || is_link($dir)) {
			return [];
		}

		$entries = scandir($dir);

		if (!is_array($entries)) {
			return [];
		}

		sort($entries, SORT_STRING);
		$found = [];

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
				continue;
			}

			$lower = strtolower($entry);

			foreach ($extensions as $ext) {
				if (str_ends_with($lower, $ext)) {
					$found[] = $subDir . '/' . $entry;

					break;
				}
			}
		}

		return $found;
	}
}
