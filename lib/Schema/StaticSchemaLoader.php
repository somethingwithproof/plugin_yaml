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

namespace Somethingwithproof\Yaml\Schema;

use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Security\PathGuard;

/**
 * Discovers and loads Drupal-style static `schema/*.yaml` descriptors across
 * installed Cacti plugins without executing or importing third-party plugin code.
 */
final class StaticSchemaLoader {
	/**
	 * Valid plugin slug pattern matching Cacti plugin directories.
	 */
	private const SLUG_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

	/**
	 * Discover static `schema/*.yaml` files across one or more plugin directories.
	 *
	 * @param string $pluginsRoot Absolute path to Cacti's `plugins/` directory.
	 * @param list<string> $extraSchemaFiles Optional explicit schema file paths to include.
	 * @return array<string, array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}>
	 */
	public static function discover(string $pluginsRoot, array $extraSchemaFiles = []): array {
		$schemas = [];

		if ($pluginsRoot !== '' && is_dir($pluginsRoot) && !is_link($pluginsRoot)) {
			$entries = scandir($pluginsRoot);

			if (is_array($entries)) {
				sort($entries, SORT_STRING);

				foreach ($entries as $slug) {
					if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
						continue;
					}

					$schemaDir = $pluginsRoot . '/' . $slug . '/schema';

					if (!is_dir($schemaDir) || is_link($schemaDir)) {
						continue;
					}

					$files = scandir($schemaDir);

					if (!is_array($files)) {
						continue;
					}

					sort($files, SORT_STRING);

					foreach ($files as $file) {
						$lower = strtolower($file);

						if (!str_ends_with($lower, '.yaml') && !str_ends_with($lower, '.yml')) {
							continue;
						}

						try {
							$resolved = PathGuard::resolveConfined($file, $schemaDir, true);
							$loaded   = self::loadFile($resolved, $slug);
							$schemas[$loaded['plugin']] = $loaded;
						} catch (YamlException) {
							// Fault-isolate malformed plugin schema files.
							continue;
						}
					}
				}
			}
		}

		foreach ($extraSchemaFiles as $extraFile) {
			try {
				$loaded = self::loadFile($extraFile);
				$schemas[$loaded['plugin']] = $loaded;
			} catch (YamlException) {
				continue;
			}
		}

		ksort($schemas, SORT_STRING);

		return $schemas;
	}

	/**
	 * Load and normalize a single static schema YAML file with Config vs State split.
	 *
	 * @param string $filePath Path to the static `*.schema.yaml` file.
	 * @param ?string $expectedSlug Optional owning plugin slug enforced when scanning plugin dirs.
	 * @return array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}
	 * @throws YamlException When the file is unreadable, invalid YAML, or lacks required keys.
	 */
	public static function loadFile(string $filePath, ?string $expectedSlug = null): array {
		$raw = PathGuard::readBoundedFile($filePath, YamlParser::MAX_BYTES);
		$doc = YamlParser::parseMap($raw);

		$plugin = is_string($doc['plugin'] ?? null) ? trim($doc['plugin']) : ($expectedSlug ?? '');

		if (preg_match(self::SLUG_PATTERN, $plugin) !== 1) {
			throw new YamlException(sprintf('Static schema "%s" has invalid plugin slug "%s".', $filePath, $plugin));
		}

		if ($expectedSlug !== null && $plugin !== $expectedSlug) {
			throw new YamlException(sprintf('Static schema "%s" attempted to declare foreign plugin slug "%s".', $filePath, $plugin));
		}

		$schemaVersion = is_scalar($doc['schemaVersion'] ?? null) ? (string) $doc['schemaVersion'] : '1.0';
		$title         = is_string($doc['title'] ?? null) && trim($doc['title']) !== '' ? trim($doc['title']) : $plugin;
		$description   = is_string($doc['description'] ?? null) ? trim($doc['description']) : '';

		$config = self::normalizePropertyMap($doc['config'] ?? []);
		$state  = self::normalizePropertyMap($doc['state'] ?? [], true);

		return [
			'plugin'        => $plugin,
			'schemaVersion' => $schemaVersion,
			'title'         => $title,
			'description'   => $description,
			'config'        => $config,
			'state'         => $state,
			'source'        => $filePath,
		];
	}

	/**
	 * Normalize a config or state property map, marking all state fields readOnly.
	 *
	 * @param mixed $raw Raw config or state section.
	 * @param bool $forceReadOnly Force `readOnly: true` for state properties.
	 * @return array<string, mixed> Normalized property map.
	 */
	private static function normalizePropertyMap(mixed $raw, bool $forceReadOnly = false): array {
		if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
			return [];
		}

		$out = [];

		foreach ($raw as $key => $spec) {
			$name = (string) $key;

			if (!is_array($spec)) {
				continue;
			}

			$normalized = [];

			foreach ($spec as $k => $v) {
				$normalized[(string) $k] = $v;
			}

			if ($forceReadOnly) {
				$normalized['readOnly'] = true;
			}

			$out[$name] = $normalized;
		}

		return $out;
	}
}
