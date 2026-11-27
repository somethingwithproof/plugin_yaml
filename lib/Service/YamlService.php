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

namespace Somethingwithproof\Yaml\Service;

use Somethingwithproof\Yaml\Codec\YamlEmitter;
use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Merge\FragmentMerger;
use Somethingwithproof\Yaml\Merge\MergePolicy;
use Somethingwithproof\Yaml\Merge\PlaceholderParser;
use Somethingwithproof\Yaml\Pack\PackService;
use Somethingwithproof\Yaml\Query\DataQueryCompiler;
use Somethingwithproof\Yaml\Query\DataQueryValidator;
use Somethingwithproof\Yaml\Repository\YamlStateRepository;
use Somethingwithproof\Yaml\Schema\ReferenceRenderer;
use Somethingwithproof\Yaml\Schema\SchemaGenerator;
use Somethingwithproof\Yaml\Schema\StaticSchemaLoader;
use Somethingwithproof\Yaml\Security\PathGuard;
use Somethingwithproof\Yaml\Settings;
use Somethingwithproof\Yaml\Template\TemplateCompiler;
use Somethingwithproof\Yaml\Template\TemplateDecompiler;
use Somethingwithproof\Yaml\Validation\CrossParameterValidator;

/**
 * Core application service orchestrating YAML compilation, fragment merging,
 * schema discovery, validation, reload pipelines, and Monitoring Packs.
 *
 * Operates 100% standalone without requiring plugin_casc, while optionally
 * accepting hook callbacks to integrate live `casc_describe_schema` and reload
 * execution when companion plugins are installed.
 */
final class YamlService {
	/**
	 * Pack lifecycle service.
	 */
	private readonly PackService $packs;

	/**
	 * Optional callback collecting dynamic `casc_describe_schema` hook arrays.
	 *
	 * @var (callable(): list<array<string, mixed>>)|null
	 */
	private readonly mixed $schemaHookCollector;

	/**
	 * Optional callback delegating merged CasC reload apply when plugin_casc is installed.
	 *
	 * @var (callable(array<string, mixed>, string, string, string): array{succeeded: bool, message: string, errors: list<string>})|null
	 */
	private readonly mixed $cascApplyDelegate;

	/**
	 * Most recent compile duration in milliseconds measured in this service instance.
	 */
	private ?float $lastCompileMs = null;

	/**
	 * Most recent fragment merge duration in milliseconds measured in this service instance.
	 */
	private ?float $lastMergeMs = null;

	/**
	 * Most recent validation error count observed in this service instance.
	 */
	private ?int $lastValidationErrors = null;

	/**
	 * Construct a YamlService instance.
	 *
	 * @param Settings $settings Active plugin settings.
	 * @param YamlStateRepository $repository Database state repository.
	 * @param string $cactiBasePath Cacti root directory (for plugins/ and resource/ resolution).
	 * @param string $pluginDir Path to this plugin's root directory (for bundled schema/yaml.schema.yaml).
	 * @param (callable(): list<array<string, mixed>>)|null $schemaHookCollector Optional collector for dynamic hook schemas.
	 * @param (callable(array<string, mixed>, string, string, string): array{succeeded: bool, message: string, errors: list<string>})|null $cascApplyDelegate Optional apply delegate when plugin_casc is present.
	 */
	public function __construct(
		/**
		 * Active plugin settings.
		 */
		private readonly Settings $settings,
		/**
		 * Database state repository.
		 */
		private readonly YamlStateRepository $repository,
		/**
		 * Cacti installation root directory.
		 */
		private readonly string $cactiBasePath = '',
		/**
		 * Root directory of plugin_yaml itself.
		 */
		private readonly string $pluginDir = '',
		?callable $schemaHookCollector = null,
		?callable $cascApplyDelegate = null,
	) {
		$this->packs               = new PackService($repository, $cactiBasePath);
		$this->schemaHookCollector = $schemaHookCollector;
		$this->cascApplyDelegate   = $cascApplyDelegate;
	}

	/**
	 * Return the active Settings instance.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Return the state repository instance.
	 *
	 * @return YamlStateRepository
	 */
	public function repository(): YamlStateRepository {
		return $this->repository;
	}

	/**
	 * Return the Monitoring Pack service instance.
	 *
	 * @return PackService
	 */
	public function packService(): PackService {
		return $this->packs;
	}

	/**
	 * Return the most recent compile duration in milliseconds, or null if not yet measured.
	 *
	 * @return ?float
	 */
	public function lastCompileMs(): ?float {
		return $this->lastCompileMs;
	}

	/**
	 * Return the most recent fragment merge duration in milliseconds, or null if not yet measured.
	 *
	 * @return ?float
	 */
	public function lastMergeMs(): ?float {
		return $this->lastMergeMs;
	}

	/**
	 * Return the most recent validation error count, or null if not yet measured.
	 *
	 * @return ?int
	 */
	public function lastValidationErrors(): ?int {
		return $this->lastValidationErrors;
	}

	/**
	 * Decompile either a native Cacti `<cacti>` template XML or `<interface>`
	 * Data Query XML string into canonical YAML 1.2.
	 *
	 * @param string $xml Raw XML content.
	 * @param string $hintName Optional bundle name or outputFile hint.
	 * @return string Canonical YAML 1.2 document.
	 * @throws YamlException When XML is malformed or carries disallowed DTD/ENTITY declarations.
	 */
	public function decompileXml(string $xml, string $hintName = ''): string {
		if (preg_match('/<!(DOCTYPE|ENTITY)\b/i', $xml) === 1) {
			throw new YamlException('XML input must not contain DOCTYPE or ENTITY declarations.');
		}

		if (str_contains($xml, '<interface>') && !str_contains($xml, '<cacti>')) {
			$outFile = $hintName !== '' && str_ends_with(strtolower($hintName), '.xml')
				? $hintName
				: 'resource/snmp_queries/custom.xml';

			return DataQueryCompiler::decompile($xml, $outFile);
		}

		return TemplateDecompiler::decompile($xml, $hintName);
	}

	/**
	 * Compile a YAML template bundle or Data Query YAML string into canonical XML.
	 *
	 * @param string $yaml Raw YAML string.
	 * @return array{kind: string, xml: string, sha256: string, outputFile: ?string}
	 * @throws YamlException When YAML syntax, validation, or compilation fails.
	 */
	public function compileYaml(string $yaml): array {
		$started = hrtime(true);
		$doc     = YamlParser::parseMap($yaml);
		$kind    = is_string($doc['kind'] ?? null) ? $doc['kind'] : '';

		if ($kind === 'SnmpDataQuery' || $kind === 'ScriptDataQuery') {
			$compiled            = DataQueryCompiler::compileArray($doc);
			$this->lastCompileMs = round((hrtime(true) - $started) / 1e6, 2);

			return [
				'kind'       => $compiled['kind'],
				'xml'        => $compiled['xml'],
				'sha256'     => $compiled['sha256'],
				'outputFile' => $compiled['outputFile'],
			];
		}

		$compiled            = TemplateCompiler::compileArray($doc);
		$this->lastCompileMs = round((hrtime(true) - $started) / 1e6, 2);

		return [
			'kind'       => TemplateDecompiler::KIND,
			'xml'        => $compiled['xml'],
			'sha256'     => $compiled['sha256'],
			'outputFile' => null,
		];
	}

	/**
	 * Merge one or more YAML fragment sources into a validated document.
	 *
	 * @param string|list<string> $sources File, comma-separated list, or directory path(s).
	 * @param ?MergePolicy $policy Optional override for configured merge policy.
	 * @param string $listMode List merge mode (`replace`, `append`, `mergeByKey`).
	 * @return array{document: array<string, mixed>, redactedYaml: string, files: list<string>, provenance: array<string, string>, placeholders: list<array{path: string, kind: string, target: string, hasDefault: bool}>, planHash: string}
	 * @throws YamlException When discovery, parsing, or strict merging fails.
	 */
	public function mergeSources(
		string|array $sources,
		?MergePolicy $policy = null,
		string $listMode = MergePolicy::LIST_MERGE_BY_KEY,
	): array {
		$started      = hrtime(true);
		$activePolicy = $policy ?? $this->settings->mergePolicy;
		$merged       = FragmentMerger::mergeSources($sources, $activePolicy, $listMode);
		$redacted     = PlaceholderParser::redact($merged['document']);
		$redactedYaml = YamlEmitter::emit($redacted, false);
		$planHash     = hash('sha256', YamlEmitter::emit($merged['document'], true));

		$this->lastMergeMs = round((hrtime(true) - $started) / 1e6, 2);

		return [
			'document'     => $merged['document'],
			'redactedYaml' => $redactedYaml,
			'files'        => $merged['files'],
			'provenance'   => $merged['provenance'],
			'placeholders' => $merged['placeholders'],
			'planHash'     => $planHash,
		];
	}

	/**
	 * Discover all plugin static `schema/*.yaml` files and dynamic hook schemas.
	 *
	 * @return array<string, array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}>
	 */
	public function discoverSchemas(): array {
		$pluginsRoot = $this->cactiBasePath !== '' ? rtrim($this->cactiBasePath, '/') . '/plugins' : '';
		$extra       = [];

		if ($this->pluginDir !== '') {
			$bundled = rtrim($this->pluginDir, '/') . '/schema/yaml.schema.yaml';

			if (is_file($bundled)) {
				$extra[] = $bundled;
			}
		}

		$static = StaticSchemaLoader::discover($pluginsRoot, $extra);
		$hooks  = $this->schemaHookCollector !== null ? ($this->schemaHookCollector)() : [];

		return SchemaGenerator::mergeDescriptors($static, $hooks);
	}

	/**
	 * Generate live JSON Schema Draft 2020-12 as an array and JSON string.
	 *
	 * @return array{schema: array<string, mixed>, json: string, plugins: list<string>}
	 */
	public function generateSchema(): array {
		$discovered = $this->discoverSchemas();
		$schema     = SchemaGenerator::generate($discovered);
		$encoded    = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$json       = (is_string($encoded) ? $encoded : '{}') . "\n";

		return [
			'schema'  => $schema,
			'json'    => $json,
			'plugins' => array_keys($discovered),
		];
	}

	/**
	 * Render human-readable Reference Documentation in Markdown or HTML.
	 *
	 * @param string $format Target format (`markdown` or `html`).
	 * @return string Rendered documentation.
	 */
	public function renderReference(string $format = 'markdown'): string {
		$discovered = $this->discoverSchemas();

		return $format === 'html'
			? ReferenceRenderer::renderHtml($discovered)
			: ReferenceRenderer::renderMarkdown($discovered);
	}

	/**
	 * Validate a YAML source path (single file, comma-separated list, or directory)
	 * across syntax, placeholder rules, compilation, and cross-parameter invariants.
	 *
	 * @param string|list<string> $sources Source path(s).
	 * @param ?MergePolicy $policy Optional merge policy override.
	 * @return array{valid: bool, kind: string, files: list<string>, planHash: string, errors: list<string>, placeholders: list<array{path: string, kind: string, target: string, hasDefault: bool}>}
	 */
	public function validateSource(string|array $sources, ?MergePolicy $policy = null): array {
		try {
			$merged = $this->mergeSources($sources, $policy);
		} catch (YamlException $e) {
			$lines                      = $e->lines();
			$this->lastValidationErrors = count($lines);

			return [
				'valid'        => false,
				'kind'         => 'unknown',
				'files'        => [],
				'planHash'     => '',
				'errors'       => $lines,
				'placeholders' => [],
			];
		}

		$validated                  = $this->validateDocument($merged['document']);
		$this->lastValidationErrors = count($validated['errors']);

		return [
			'valid'        => $validated['errors'] === [],
			'kind'         => $validated['kind'],
			'files'        => $merged['files'],
			'planHash'     => $merged['planHash'],
			'errors'       => $validated['errors'],
			'placeholders' => $merged['placeholders'],
		];
	}

	/**
	 * Execute a dry-run or confirmed JCasC-style reload pipeline without redundant re-merging.
	 *
	 * @param string|list<string> $sources Fragment source path(s).
	 * @param bool $dryRun When true, only compute plan hash and redacted preview.
	 * @param string $confirmHash Confirmation hash required when `$dryRun` is false.
	 * @param string $actor Operator identity.
	 * @param string $channel Invocation channel (`cli` or `web`).
	 * @param ?MergePolicy $policy Optional merge policy override.
	 * @return array{succeeded: bool, dryRun: bool, planHash: string, files: list<string>, redactedYaml: string, message: string, errors: list<string>}
	 */
	public function reload(
		string|array $sources,
		bool $dryRun = true,
		string $confirmHash = '',
		string $actor = 'cli',
		string $channel = 'cli',
		?MergePolicy $policy = null,
	): array {
		try {
			$merged = $this->mergeSources($sources, $policy);
		} catch (YamlException $e) {
			$lines                      = $e->lines();
			$this->lastValidationErrors = count($lines);

			return [
				'succeeded'    => false,
				'dryRun'       => $dryRun,
				'planHash'     => '',
				'files'        => [],
				'redactedYaml' => '',
				'message'      => 'Validation failed; reload aborted.',
				'errors'       => $lines,
			];
		}

		$validated                  = $this->validateDocument($merged['document']);
		$this->lastValidationErrors = count($validated['errors']);

		if ($validated['errors'] !== []) {
			return [
				'succeeded'    => false,
				'dryRun'       => $dryRun,
				'planHash'     => $merged['planHash'],
				'files'        => $merged['files'],
				'redactedYaml' => '',
				'message'      => 'Validation failed; reload aborted.',
				'errors'       => $validated['errors'],
			];
		}

		if ($dryRun) {
			return [
				'succeeded'    => true,
				'dryRun'       => true,
				'planHash'     => $merged['planHash'],
				'files'        => $merged['files'],
				'redactedYaml' => $merged['redactedYaml'],
				'message'      => sprintf('Dry-run validated %d fragment(s). Confirm with %s.', count($merged['files']), $merged['planHash']),
				'errors'       => [],
			];
		}

		if ($confirmHash === '' || !hash_equals($merged['planHash'], strtolower(trim($confirmHash)))) {
			return [
				'succeeded'    => false,
				'dryRun'       => false,
				'planHash'     => $merged['planHash'],
				'files'        => $merged['files'],
				'redactedYaml' => $merged['redactedYaml'],
				'message'      => 'Confirmation hash mismatch.',
				'errors'       => [sprintf('Expected --confirm=%s.', $merged['planHash'])],
			];
		}

		if ($this->cascApplyDelegate !== null) {
			$result = ($this->cascApplyDelegate)($merged['document'], $merged['planHash'], $actor, $channel);
			$this->repository->recordAudit(
				$actor,
				$channel,
				'reload',
				$merged['planHash'],
				$result['succeeded'] ? 'succeeded' : 'failed',
				[
					'files'   => $merged['files'],
					'message' => $result['message'],
					'errors'  => $result['errors'],
					'mergeMs' => $this->lastMergeMs ?? 0.0,
				]
			);

			return [
				'succeeded'    => $result['succeeded'],
				'dryRun'       => false,
				'planHash'     => $merged['planHash'],
				'files'        => $merged['files'],
				'redactedYaml' => $merged['redactedYaml'],
				'message'      => $result['message'],
				'errors'       => $result['errors'],
			];
		}

		// Standalone apply mode: handle SnmpDataQuery / ScriptDataQuery or record validated state.
		$doc       = $merged['document'];
		$kind      = $validated['kind'];
		$compileMs = 0.0;

		if (($kind === 'SnmpDataQuery' || $kind === 'ScriptDataQuery') && $this->cactiBasePath !== '' && is_dir($this->cactiBasePath)) {
			$compileStart        = hrtime(true);
			$compiled            = DataQueryCompiler::compileArray($doc);
			$compileMs           = round((hrtime(true) - $compileStart) / 1e6, 2);
			$this->lastCompileMs = $compileMs;
			$targetPath          = PathGuard::resolveConfined($compiled['outputFile'], $this->cactiBasePath, false);
			$sha                 = PathGuard::writeAtomic($targetPath, $compiled['xml']);
			$this->repository->upsertQuery($compiled['name'], $compiled['kind'], $compiled['outputFile'], $sha);
		}

		$this->repository->recordAudit(
			$actor,
			$channel,
			'reload',
			$merged['planHash'],
			'succeeded',
			[
				'files'      => $merged['files'],
				'kind'       => $kind,
				'standalone' => true,
				'mergeMs'    => $this->lastMergeMs ?? 0.0,
				'compileMs'  => $compileMs,
			]
		);

		return [
			'succeeded'    => true,
			'dryRun'       => false,
			'planHash'     => $merged['planHash'],
			'files'        => $merged['files'],
			'redactedYaml' => $merged['redactedYaml'],
			'message'      => sprintf('Applied YAML reload %s in standalone mode.', substr($merged['planHash'], 0, 12)),
			'errors'       => [],
		];
	}

	/**
	 * Validate a merged YAML document according to its declared kind.
	 *
	 * @param array<string, mixed> $doc Merged YAML mapping.
	 * @return array{kind: string, errors: list<string>}
	 */
	private function validateDocument(array $doc): array {
		$kind   = is_string($doc['kind'] ?? null) ? $doc['kind'] : 'CactiConfig';
		$errors = [];

		if ($kind === 'SnmpDataQuery' || $kind === 'ScriptDataQuery') {
			$errors = DataQueryValidator::validate($doc);
		} elseif ($kind === 'CactiTemplateBundle') {
			$started = hrtime(true);

			try {
				TemplateCompiler::compileArray($doc);
				$this->lastCompileMs = round((hrtime(true) - $started) / 1e6, 2);
			} catch (YamlException $e) {
				$errors = $e->lines();
			}
		} else {
			$errors = CrossParameterValidator::validate($doc);
		}

		return [
			'kind'   => $kind,
			'errors' => $errors,
		];
	}

	/**
	 * Build a redacted YAML snapshot representing plugin_yaml's current config and state.
	 *
	 * @return string Redacted YAML text.
	 */
	public function exportRedactedState(): string {
		$packs   = $this->repository->listPacks();
		$queries = $this->repository->listQueries();

		$snapshot = [
			'apiVersion' => 'cacti.yaml/v1',
			'kind'       => 'CactiConfig',
			'spec'       => [
				'plugins' => [
					'yaml' => [
						'sourcePaths'    => $this->settings->sourcePaths,
						'mergePolicy'    => $this->settings->mergePolicy->value,
						'packDir'        => $this->settings->packDir,
						'managedQueries' => array_map(
							static fn (array $row): array => [
								'name'       => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
								'kind'       => is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : '',
								'outputFile' => is_scalar($row['output_file'] ?? null) ? (string) $row['output_file'] : '',
								'sha256'     => is_scalar($row['xml_sha256'] ?? null) ? (string) $row['xml_sha256'] : '',
							],
							$queries
						),
						'packs' => array_map(
							static fn (array $row): array => [
								'name'       => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
								'version'    => is_scalar($row['version'] ?? null) ? (string) $row['version'] : '',
								'sourcePath' => is_scalar($row['source_path'] ?? null) ? (string) $row['source_path'] : '',
								'lockSha256' => is_scalar($row['lock_sha256'] ?? null) ? (string) $row['lock_sha256'] : '',
							],
							$packs
						),
					],
				],
			],
		];

		return YamlEmitter::emit(PlaceholderParser::redact($snapshot), false);
	}
}
