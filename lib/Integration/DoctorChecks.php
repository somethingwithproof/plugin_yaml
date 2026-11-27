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

use Somethingwithproof\Yaml\Security\PathGuard;
use Somethingwithproof\Yaml\Service\YamlService;
use Somethingwithproof\Yaml\Settings;

/**
 * Diagnostic checks exposed to `plugin_doctor` via the `doctor_register_checks` hook.
 */
final class DoctorChecks {
	/**
	 * Build structured diagnostic check descriptors compatible with
	 * `plugin_doctor`'s plain-array hook contract.
	 *
	 * @param callable(): YamlService $serviceFactory Lazy service factory.
	 * @param callable(): Settings $settingsProvider Settings provider.
	 * @param string $cactiBasePath Cacti installation root directory.
	 * @return list<array{id: string, name: string, category: string, run: callable(): array{status: string, message: string, details: array<string, mixed>}}>
	 */
	public static function build(callable $serviceFactory, callable $settingsProvider, string $cactiBasePath = ''): array {
		return [
			[
				'id'       => 'yaml_source_permissions',
				'name'     => 'YAML Fragment Source Permissions',
				'category' => 'yaml',
				'run'      => static function () use ($settingsProvider): array {
					$sources = $settingsProvider()->sourcePaths;

					if ($sources === []) {
						return [
							'status'  => 'ok',
							'message' => 'No yaml_source_paths configured.',
							'details' => ['sources' => []],
						];
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
						return [
							'status'  => 'warning',
							'message' => implode('; ', $problems),
							'details' => ['problems' => $problems],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('%d YAML source path(s) verified.', count($sources)),
						'details' => ['sources' => $sources],
					];
				},
			],
			[
				'id'       => 'yaml_fragment_validation',
				'name'     => 'YAML Fragment Syntax & Invariant Check',
				'category' => 'yaml',
				'run'      => static function () use ($serviceFactory, $settingsProvider): array {
					$sources = $settingsProvider()->sourcePaths;

					if ($sources === []) {
						return [
							'status'  => 'ok',
							'message' => 'No staged YAML fragments configured.',
							'details' => ['configured' => false],
						];
					}

					$result = $serviceFactory()->validateSource($sources);

					if (!$result['valid']) {
						return [
							'status'  => 'critical',
							'message' => sprintf('Staged YAML validation failed with %d error(s).', count($result['errors'])),
							'details' => ['errors' => $result['errors'], 'files' => $result['files']],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('%d YAML fragment(s) validated cleanly.', count($result['files'])),
						'details' => ['files' => $result['files'], 'planHash' => $result['planHash']],
					];
				},
			],
			[
				'id'       => 'yaml_query_checksums',
				'name'     => 'Compiled Data Query XML Checksums',
				'category' => 'yaml',
				'run'      => static function () use ($serviceFactory, $cactiBasePath): array {
					$queries = $serviceFactory()->repository()->listQueries();

					if ($queries === []) {
						return [
							'status'  => 'ok',
							'message' => 'No managed Data Query XML files recorded.',
							'details' => ['count' => 0],
						];
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
						return [
							'status'  => 'warning',
							'message' => sprintf('%d Data Query XML file(s) drifted or missing.', count($drifted)),
							'details' => ['drifted' => $drifted],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('%d compiled Data Query XML file(s) match recorded SHA-256 digests.', count($queries)),
						'details' => ['count' => count($queries)],
					];
				},
			],
		];
	}
}
