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

namespace Somethingwithproof\Yaml;

use Somethingwithproof\Yaml\Merge\MergePolicy;

/**
 * Plugin configuration settings stored in Cacti's settings table.
 */
final class Settings {
	/**
	 * Canonical list of Cacti settings table keys owned by plugin_yaml.
	 */
	public const NAMES = [
		'yaml_source_paths',
		'yaml_merge_policy',
		'yaml_pack_dir',
		'yaml_uninstall_keep_tables',
	];

	/**
	 * Construct immutable settings from validated values.
	 *
	 * @param list<string> $sourcePaths Ordered YAML files or casc.d directories.
	 * @param MergePolicy $mergePolicy Scalar conflict resolution policy.
	 * @param string $packDir Root directory for versioned Monitoring Packs.
	 * @param bool $uninstallKeepTables Retain plugin tables during uninstall when true.
	 */
	public function __construct(
		/**
		 * Ordered YAML fragment files or casc.d directories.
		 *
		 * @var list<string>
		 */
		public readonly array $sourcePaths,
		/**
		 * Scalar conflict resolution policy.
		 */
		public readonly MergePolicy $mergePolicy,
		/**
		 * Base directory for versioned Monitoring Packs.
		 */
		public readonly string $packDir,
		/**
		 * Whether to retain plugin tables on uninstall.
		 */
		public readonly bool $uninstallKeepTables,
	) {
	}

	/**
	 * Hydrate a Settings object from raw key-value settings rows.
	 *
	 * @param array<string, mixed> $row Raw setting values keyed by name.
	 * @return self
	 */
	public static function fromRow(array $row): self {
		$rawSources = is_scalar($row['yaml_source_paths'] ?? null) ? (string) $row['yaml_source_paths'] : '';
		$sources    = [];

		foreach (preg_split('/[\r\n,]+/', $rawSources) ?: [] as $entry) {
			$trimmed = trim($entry);

			if ($trimmed !== '') {
				$sources[] = $trimmed;
			}
		}

		$rawPolicy = is_scalar($row['yaml_merge_policy'] ?? null) ? strtolower(trim((string) $row['yaml_merge_policy'])) : 'strict';
		$policy    = MergePolicy::tryFrom($rawPolicy) ?? MergePolicy::STRICT;
		$packDir   = is_scalar($row['yaml_pack_dir'] ?? null) ? trim((string) $row['yaml_pack_dir']) : '';
		$keep      = ($row['yaml_uninstall_keep_tables'] ?? '') === 'on';

		return new self($sources, $policy, $packDir, $keep);
	}

	/**
	 * Build the Cacti Console settings form array for the YAML tab.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		return [
			'yaml_header' => [
				'friendly_name' => __('YAML Templates, Schema & CasC Fragments', 'yaml'),
				'method'        => 'spacer',
			],
			'yaml_source_paths' => [
				'friendly_name' => __('YAML Fragment Sources (casc.d)', 'yaml'),
				'description'   => __('Comma-separated list of YAML files or casc.d directories merged in deterministic lexical order.', 'yaml'),
				'method'        => 'textbox',
				'default'       => '',
				'max_length'    => '1024',
				'size'          => '80',
			],
			'yaml_merge_policy' => [
				'friendly_name' => __('Fragment Merge Policy', 'yaml'),
				'description'   => __('Strict rejects conflicting scalar keys across fragments; overlay lets later fragments override earlier values.', 'yaml'),
				'method'        => 'drop_array',
				'default'       => MergePolicy::STRICT->value,
				'array'         => [
					MergePolicy::STRICT->value  => __('Strict (fail on scalar conflicts)', 'yaml'),
					MergePolicy::OVERLAY->value => __('Overlay (later fragments override)', 'yaml'),
				],
			],
			'yaml_pack_dir' => [
				'friendly_name' => __('Monitoring Packs Directory', 'yaml'),
				'description'   => __('Base directory containing versioned YAML Monitoring Packs and lockfiles.', 'yaml'),
				'method'        => 'textbox',
				'default'       => '',
				'max_length'    => '512',
				'size'          => '80',
			],
			'yaml_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Plugin Tables on Uninstall', 'yaml'),
				'description'   => __('Preserve plugin_yaml_packs, plugin_yaml_queries, and plugin_yaml_audit tables when uninstalling the plugin.', 'yaml'),
				'method'        => 'checkbox',
				'default'       => '',
			],
		];
	}
}
