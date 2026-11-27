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

namespace Somethingwithproof\Yaml\Merge;

use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Security\PathGuard;

/**
 * JCasC-style multi-file YAML fragment loader and deterministic merger.
 *
 * Loads single files, comma-separated source lists, or casc.d/*.yaml
 * directories in lexical order with symlink/traversal refusal, deep map
 * merging, configurable list merge modes, and strict conflict detection.
 */
final class FragmentMerger {
	/**
	 * Natural identity keys used when merging object lists in mergeByKey mode.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_NATURAL_KEYS = [
		'hostname',
		'slug',
		'name',
		'path',
		'outputFile',
		'id',
	];

	/**
	 * Expand one or more source paths (single file, comma-separated list, or
	 * directories) into a lexically ordered list of validated YAML files.
	 *
	 * @param string|list<string> $sources Single path, comma-separated paths, or list of paths.
	 * @return list<string> Canonical absolute file paths in deterministic order.
	 * @throws YamlException When any source is missing, a symlink, or unsafe.
	 */
	public static function discoverFiles(string|array $sources): array {
		$entries = is_array($sources)
			? $sources
			: array_values(array_filter(array_map('trim', explode(',', $sources)), static fn (string $s): bool => $s !== ''));

		if ($entries === []) {
			throw new YamlException('At least one YAML source file or directory is required.');
		}

		$files = [];

		foreach ($entries as $entry) {
			$resolved = PathGuard::assertSafePath($entry, true);

			if (is_dir($resolved)) {
				$dirEntries = scandir($resolved);

				if ($dirEntries === false) {
					throw new YamlException(sprintf('Unable to read directory "%s".', $entry));
				}

				sort($dirEntries, SORT_STRING);

				foreach ($dirEntries as $child) {
					if ($child === '.' || $child === '..' || str_starts_with($child, '.')) {
						continue;
					}

					$lower = strtolower($child);

					if (!str_ends_with($lower, '.yaml') && !str_ends_with($lower, '.yml')) {
						continue;
					}

					$files[] = PathGuard::resolveConfined($child, $resolved, true);
				}
			} else {
				$files[] = $resolved;
			}
		}

		if ($files === []) {
			throw new YamlException('No .yaml or .yml fragment files were discovered in the configured sources.');
		}

		return array_values(array_unique($files));
	}

	/**
	 * Load and merge YAML fragments from disk sources.
	 *
	 * @param string|list<string> $sources Single file, comma-separated list, or directory paths.
	 * @param MergePolicy $policy Conflict resolution policy (strict or overlay).
	 * @param string $listMode List merge mode (replace, append, or mergeByKey).
	 * @return array{document: array<string, mixed>, files: list<string>, provenance: array<string, string>, placeholders: list<array{path: string, kind: string, target: string, hasDefault: bool}>}
	 * @throws YamlException On invalid YAML, path violation, placeholder error, or strict conflict.
	 */
	public static function mergeSources(
		string|array $sources,
		MergePolicy $policy = MergePolicy::STRICT,
		string $listMode = MergePolicy::LIST_MERGE_BY_KEY,
	): array {
		$files     = self::discoverFiles($sources);
		$fragments = [];

		foreach ($files as $file) {
			$raw         = PathGuard::readBoundedFile($file, YamlParser::MAX_BYTES);
			$fragments[] = [
				'source' => $file,
				'data'   => YamlParser::parseMap($raw),
			];
		}

		$merged = self::mergeFragments($fragments, $policy, $listMode);

		return [
			'document'     => $merged['document'],
			'files'        => $files,
			'provenance'   => $merged['provenance'],
			'placeholders' => $merged['placeholders'],
		];
	}

	/**
	 * Merge pre-parsed named YAML mapping fragments in order.
	 *
	 * @param list<array{source: string, data: array<string, mixed>}> $fragments Ordered fragment list.
	 * @param MergePolicy $policy Conflict resolution policy.
	 * @param string $listMode List merge mode (replace, append, or mergeByKey).
	 * @return array{document: array<string, mixed>, provenance: array<string, string>, placeholders: list<array{path: string, kind: string, target: string, hasDefault: bool}>}
	 * @throws YamlException When listMode is invalid, strict scalar conflicts occur, or placeholders are malformed.
	 */
	public static function mergeFragments(
		array $fragments,
		MergePolicy $policy = MergePolicy::STRICT,
		string $listMode = MergePolicy::LIST_MERGE_BY_KEY,
	): array {
		if (!in_array($listMode, MergePolicy::listModes(), true)) {
			throw new YamlException(sprintf('Unsupported list merge mode "%s".', $listMode));
		}

		$merged     = [];
		$provenance = [];
		$conflicts  = [];

		foreach ($fragments as $fragment) {
			$source = $fragment['source'];
			$merged = self::mergeMaps($merged, $fragment['data'], '', $source, $policy, $listMode, $provenance, $conflicts);
		}

		if ($conflicts !== []) {
			throw new YamlException(
				sprintf('Strict merge rejected %d conflicting scalar key(s).', count($conflicts)),
				$conflicts
			);
		}

		$inspection = PlaceholderParser::inspect($merged);

		if ($inspection['errors'] !== []) {
			throw new YamlException(
				sprintf('Merged YAML contains %d invalid placeholder(s).', count($inspection['errors'])),
				$inspection['errors']
			);
		}

		return [
			'document'     => $merged,
			'provenance'   => $provenance,
			'placeholders' => $inspection['references'],
		];
	}

	/**
	 * Deep-merge two associative mappings with conflict and provenance tracking.
	 *
	 * @param array<string, mixed> $base Accumulated base mapping.
	 * @param array<string, mixed> $incoming Incoming fragment mapping.
	 * @param string $prefix Dotted key prefix for diagnostics.
	 * @param string $source Fragment source identifier.
	 * @param MergePolicy $policy Conflict policy.
	 * @param string $listMode List merge mode.
	 * @param array<string, string> $provenance Map of dotted paths to defining source.
	 * @param list<string> $conflicts Collected strict conflict diagnostics.
	 * @return array<string, mixed> Merged mapping.
	 */
	private static function mergeMaps(
		array $base,
		array $incoming,
		string $prefix,
		string $source,
		MergePolicy $policy,
		string $listMode,
		array &$provenance,
		array &$conflicts,
	): array {
		foreach ($incoming as $key => $value) {
			$keyStr = (string) $key;
			$path   = $prefix === '' ? $keyStr : $prefix . '.' . $keyStr;

			if (!array_key_exists($keyStr, $base)) {
				$base[$keyStr] = $value;
				self::recordProvenance($path, $value, $source, $provenance);

				continue;
			}

			$existing = $base[$keyStr];

			if (self::isAssocArray($existing) && self::isAssocArray($value)) {
				/** @var array<string, mixed> $existingMap */
				$existingMap = $existing;
				/** @var array<string, mixed> $incomingMap */
				$incomingMap   = $value;
				$base[$keyStr] = self::mergeMaps($existingMap, $incomingMap, $path, $source, $policy, $listMode, $provenance, $conflicts);

				continue;
			}

			if (is_array($existing) && array_is_list($existing) && is_array($value) && array_is_list($value)) {
				$base[$keyStr] = self::mergeLists($existing, $value, $path, $source, $policy, $listMode, $provenance, $conflicts);
				$provenance[$path] = $source;

				continue;
			}

			if ($existing === $value) {
				continue;
			}

			if ($policy === MergePolicy::STRICT) {
				$prior       = $provenance[$path] ?? 'earlier fragment';
				$conflicts[] = sprintf('Conflict at "%s": already defined by %s and redefined by %s.', $path, $prior, $source);

				continue;
			}

			$base[$keyStr] = $value;
			self::recordProvenance($path, $value, $source, $provenance);
		}

		return $base;
	}

	/**
	 * Merge two sequential lists according to the configured list merge mode.
	 *
	 * @param list<mixed> $base Existing list.
	 * @param list<mixed> $incoming Incoming list.
	 * @param string $path Dotted path for diagnostics.
	 * @param string $source Fragment source identifier.
	 * @param MergePolicy $policy Conflict policy.
	 * @param string $listMode List merge mode.
	 * @param array<string, string> $provenance Provenance table.
	 * @param list<string> $conflicts Collected strict conflicts.
	 * @return list<mixed> Merged list.
	 */
	private static function mergeLists(
		array $base,
		array $incoming,
		string $path,
		string $source,
		MergePolicy $policy,
		string $listMode,
		array &$provenance,
		array &$conflicts,
	): array {
		if ($listMode === MergePolicy::LIST_REPLACE) {
			return $incoming;
		}

		if ($listMode === MergePolicy::LIST_APPEND) {
			return array_merge($base, $incoming);
		}

		$naturalKey = self::detectNaturalKey($base, $incoming);

		if ($naturalKey === null) {
			return array_merge($base, $incoming);
		}

		$indexed = [];
		$order   = [];

		foreach ($base as $item) {
			if (!self::isAssocArray($item) || !isset($item[$naturalKey]) || !is_scalar($item[$naturalKey])) {
				return array_merge($base, $incoming);
			}

			$id = (string) $item[$naturalKey];
			/** @var array<string, mixed> $itemMap */
			$itemMap      = $item;
			$indexed[$id] = $itemMap;
			$order[]      = $id;
		}

		foreach ($incoming as $item) {
			if (!self::isAssocArray($item) || !isset($item[$naturalKey]) || !is_scalar($item[$naturalKey])) {
				return array_merge($base, $incoming);
			}

			$id       = (string) $item[$naturalKey];
			$itemPath = $path . '[' . $naturalKey . '=' . $id . ']';
			/** @var array<string, mixed> $itemMap */
			$itemMap = $item;

			if (isset($indexed[$id])) {
				$indexed[$id] = self::mergeMaps($indexed[$id], $itemMap, $itemPath, $source, $policy, $listMode, $provenance, $conflicts);
			} else {
				$indexed[$id] = $itemMap;
				$order[]      = $id;
				self::recordProvenance($itemPath, $itemMap, $source, $provenance);
			}
		}

		$result = [];

		foreach ($order as $id) {
			$result[] = $indexed[$id];
		}

		return $result;
	}

	/**
	 * Detect a shared natural key across two lists of associative maps.
	 *
	 * @param list<mixed> $base Base list.
	 * @param list<mixed> $incoming Incoming list.
	 * @return ?string Natural key name or null when lists are not keyed object lists.
	 */
	private static function detectNaturalKey(array $base, array $incoming): ?string {
		$all = array_merge($base, $incoming);

		if ($all === []) {
			return null;
		}

		foreach (self::DEFAULT_NATURAL_KEYS as $candidate) {
			$matched = true;

			foreach ($all as $item) {
				if (!self::isAssocArray($item) || !isset($item[$candidate]) || !is_scalar($item[$candidate])) {
					$matched = false;

					break;
				}
			}

			if ($matched) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Record provenance recursively for newly inserted values.
	 *
	 * @param string $path Dotted path.
	 * @param mixed $value Inserted value.
	 * @param string $source Fragment source identifier.
	 * @param array<string, string> $provenance Provenance map updated in place.
	 * @return void
	 */
	private static function recordProvenance(string $path, mixed $value, string $source, array &$provenance): void {
		$provenance[$path] = $source;

		if (self::isAssocArray($value)) {
			foreach ($value as $k => $v) {
				self::recordProvenance($path . '.' . (string) $k, $v, $source, $provenance);
			}
		}
	}

	/**
	 * Return true when the value is a non-list associative array.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 * @phpstan-assert-if-true array<string, mixed> $value
	 */
	private static function isAssocArray(mixed $value): bool {
		return is_array($value) && ($value === [] || !array_is_list($value));
	}
}
