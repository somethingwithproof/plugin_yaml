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

namespace Somethingwithproof\Yaml\Integration;

use Somethingwithproof\Yaml\Schema;
use Somethingwithproof\Yaml\Security\PathGuard;
use Somethingwithproof\Yaml\Service\YamlService;
use Somethingwithproof\Yaml\Settings;

/**
 * Diagnostic checks and runtime debug metadata exposed to `plugin_doctor` via
 * the `doctor_register_checks` and `doctor_register_debug_info` hooks.
 */
final class DoctorChecks {
	/**
	 * Plugin slug reported in diagnostic descriptors and debug info sections.
	 */
	private const PLUGIN = 'yaml';

	/**
	 * Diagnostic check category displayed in plugin_doctor reports.
	 */
	private const CATEGORY = 'YAML';

	/**
	 * Evaluate or list structured diagnostic check descriptors compatible with
	 * `plugin_doctor`'s `doctor_register_checks` contract.
	 *
	 * @param callable(): YamlService $serviceFactory   Lazy service factory.
	 * @param callable(): Settings    $settingsProvider Settings provider.
	 * @param string                  $cactiBasePath    Cacti installation root directory.
	 * @param array<string, mixed>    $context          Optional hook context containing mode ('run'|'list').
	 * @return list<array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}>
	 */
	public static function build(
		callable $serviceFactory,
		callable $settingsProvider,
		string $cactiBasePath = '',
		array $context = [],
	): array {
		$mode = is_string($context['mode'] ?? null) ? strtolower(trim($context['mode'])) : 'run';

		if ($mode === 'list') {
			return [
				self::descriptor(
					'yaml.source_permissions',
					'YAML Fragment Source Permissions',
					'info',
					'Verifies that configured yaml_source_paths exist, are not symlinks, and are not world-writable.',
					['mode' => 'list'],
					''
				),
				self::descriptor(
					'yaml.fragment_validation',
					'YAML Fragment Syntax & Invariant Check',
					'info',
					'Validates configured casc.d YAML fragments for syntax, placeholders, and cross-parameter invariants.',
					['mode' => 'list'],
					''
				),
				self::descriptor(
					'yaml.query_checksums',
					'Compiled Data Query XML Checksums',
					'info',
					'Verifies compiled Data Query XML files on disk against recorded SHA-256 digests.',
					['mode' => 'list'],
					''
				),
			];
		}

		return [
			self::checkSourcePermissions($settingsProvider),
			self::checkFragmentValidation($serviceFactory, $settingsProvider),
			self::checkQueryChecksums($serviceFactory, $cactiBasePath),
		];
	}

	/**
	 * Build a redacted runtime debug info section compatible with
	 * `doctor_register_debug_info` (zero secrets).
	 *
	 * @param callable(): YamlService $serviceFactory   Lazy service factory.
	 * @param callable(): Settings    $settingsProvider Settings provider.
	 * @param string                  $version          Plugin version string from INFO.
	 * @return list<array{id: string, plugin: string, label: string, description: string, private: bool, fields: array<string, array{label: string, value: bool|int|string}>}>
	 */
	public static function debugInfo(
		callable $serviceFactory,
		callable $settingsProvider,
		string $version = '0.1.0',
	): array {
		$settings = $settingsProvider();
		$service  = $serviceFactory();
		$repo     = $service->repository();

		return [
			[
				'id'          => 'yaml.overview',
				'plugin'      => self::PLUGIN,
				'label'       => 'YAML Templates, Schema & CasC Fragments (plugin_yaml)',
				'description' => 'Declarative YAML fragment settings, Monitoring Pack counts, and compiled Data Query XML summary (zero secrets).',
				'private'     => false,
				'fields'      => [
					'version' => [
						'label' => 'Plugin Version',
						'value' => $version !== '' ? $version : '0.1.0',
					],
					'schema_version' => [
						'label' => 'Schema Version',
						'value' => Schema::VERSION,
					],
					'merge_policy' => [
						'label' => 'Merge Policy',
						'value' => $settings->mergePolicy->value,
					],
					'source_paths_count' => [
						'label' => 'Configured Source Paths',
						'value' => count($settings->sourcePaths),
					],
					'pack_dir_configured' => [
						'label' => 'Pack Directory Configured',
						'value' => $settings->packDir !== '' ? 'yes' : 'no',
					],
					'installed_packs' => [
						'label' => 'Installed Monitoring Packs',
						'value' => count($repo->listPacks()),
					],
					'managed_data_queries' => [
						'label' => 'Managed Data Queries',
						'value' => count($repo->listQueries()),
					],
					'discovered_schemas' => [
						'label' => 'Discovered Plugin Schemas',
						'value' => count($service->discoverSchemas()),
					],
					'uninstall_keep_tables' => [
						'label' => 'Keep Tables on Uninstall',
						'value' => $settings->uninstallKeepTables ? 'yes' : 'no',
					],
				],
			],
		];
	}

	/**
	 * Verify configured yaml_source_paths exist, are not symlinks, and are not world-writable.
	 *
	 * @param callable(): Settings $settingsProvider Settings provider.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkSourcePermissions(callable $settingsProvider): array {
		$sources = $settingsProvider()->sourcePaths;

		if ($sources === []) {
			return self::descriptor(
				'yaml.source_permissions',
				'YAML Fragment Source Permissions',
				'ok',
				'No yaml_source_paths configured.',
				['sources' => []],
				''
			);
		}

		$problems = [];

		foreach ($sources as $path) {
			if (is_link($path)) {
				$problems[] = sprintf('Source is a symlink: %s', $path);

				continue;
			}

			if (!file_exists($path)) {
				$problems[] = sprintf('Source missing: %s', $path);

				continue;
			}

			$stat = lstat($path);

			if ($stat !== false && (($stat['mode'] & 0002) !== 0)) {
				$problems[] = sprintf('Source is world-writable: %s', $path);
			}
		}

		if ($problems !== []) {
			return self::descriptor(
				'yaml.source_permissions',
				'YAML Fragment Source Permissions',
				'warn',
				implode('; ', $problems),
				['problems' => $problems],
				'Ensure every path in yaml_source_paths exists, is not a symlink, and is not world-writable.'
			);
		}

		return self::descriptor(
			'yaml.source_permissions',
			'YAML Fragment Source Permissions',
			'ok',
			sprintf('%d YAML source path(s) verified.', count($sources)),
			['sources' => $sources],
			''
		);
	}

	/**
	 * Validate staged YAML fragment syntax and cross-parameter invariants.
	 *
	 * @param callable(): YamlService $serviceFactory   Lazy service factory.
	 * @param callable(): Settings    $settingsProvider Settings provider.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkFragmentValidation(callable $serviceFactory, callable $settingsProvider): array {
		$sources = $settingsProvider()->sourcePaths;

		if ($sources === []) {
			return self::descriptor(
				'yaml.fragment_validation',
				'YAML Fragment Syntax & Invariant Check',
				'ok',
				'No staged YAML fragments configured.',
				['configured' => false],
				''
			);
		}

		$result = $serviceFactory()->validateSource($sources);

		if (!$result['valid']) {
			return self::descriptor(
				'yaml.fragment_validation',
				'YAML Fragment Syntax & Invariant Check',
				'fail',
				sprintf('Staged YAML validation failed with %d error(s).', count($result['errors'])),
				['errors' => $result['errors'], 'files' => $result['files']],
				'Run cli/yaml.php validate on configured source paths and resolve all reported syntax or invariant errors.'
			);
		}

		return self::descriptor(
			'yaml.fragment_validation',
			'YAML Fragment Syntax & Invariant Check',
			'ok',
			sprintf('%d YAML fragment(s) validated cleanly.', count($result['files'])),
			['files' => $result['files'], 'planHash' => $result['planHash']],
			''
		);
	}

	/**
	 * Verify compiled Data Query XML files match recorded SHA-256 digests.
	 *
	 * @param callable(): YamlService $serviceFactory Lazy service factory.
	 * @param string                  $cactiBasePath  Cacti installation root directory.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkQueryChecksums(callable $serviceFactory, string $cactiBasePath): array {
		$queries = $serviceFactory()->repository()->listQueries();

		if ($queries === []) {
			return self::descriptor(
				'yaml.query_checksums',
				'Compiled Data Query XML Checksums',
				'ok',
				'No managed Data Query XML files recorded.',
				['count' => 0],
				''
			);
		}

		$drifted = [];

		foreach ($queries as $row) {
			$rel      = is_scalar($row['output_file'] ?? null) ? (string) $row['output_file'] : '';
			$expected = is_scalar($row['xml_sha256'] ?? null) ? (string) $row['xml_sha256'] : '';

			if ($rel === '' || $cactiBasePath === '') {
				continue;
			}

			try {
				$resolved = PathGuard::resolveConfined($rel, $cactiBasePath, true);
				$actual   = hash('sha256', PathGuard::readBoundedFile($resolved));

				if (!hash_equals($expected, $actual)) {
					$drifted[] = sprintf('%s (checksum mismatch)', $rel);
				}
			} catch (\Throwable $e) {
				$drifted[] = sprintf('%s (%s)', $rel, $e->getMessage());
			}
		}

		if ($drifted !== []) {
			return self::descriptor(
				'yaml.query_checksums',
				'Compiled Data Query XML Checksums',
				'warn',
				sprintf('%d Data Query XML file(s) drifted or missing.', count($drifted)),
				['drifted' => $drifted],
				'Recompile drifted Data Query XML files using cli/yaml.php compile or reload.'
			);
		}

		return self::descriptor(
			'yaml.query_checksums',
			'Compiled Data Query XML Checksums',
			'ok',
			sprintf('%d compiled Data Query XML file(s) match recorded SHA-256 digests.', count($queries)),
			['count' => count($queries)],
			''
		);
	}

	/**
	 * Construct a contract-compliant plugin_doctor check descriptor with bounded string lengths.
	 *
	 * @param string               $id          Dot-prefixed check identifier (yaml.*).
	 * @param string               $title       Human-readable check title.
	 * @param string               $status      Allowed status enum (ok|warn|fail|info).
	 * @param string               $message     Summary message.
	 * @param array<string, mixed> $details     Structured diagnostic details.
	 * @param string               $remediation Actionable remediation text.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function descriptor(
		string $id,
		string $title,
		string $status,
		string $message,
		array $details,
		string $remediation,
	): array {
		return [
			'id'          => $id,
			'plugin'      => self::PLUGIN,
			'category'    => self::CATEGORY,
			'title'       => substr($title, 0, 256),
			'status'      => $status,
			'message'     => substr($message !== '' ? $message : $title, 0, 2048),
			'details'     => $details,
			'remediation' => substr($remediation, 0, 2048),
		];
	}
}
