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

use Somethingwithproof\Yaml\Codec\YamlEmitter;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Pages;
use Somethingwithproof\Yaml\Service\YamlService;

/**
 * Exposes plain-array resource descriptors (`yamlPacks` and `yamlSchema`) for
 * `plugin_api`'s `api_register_resources` hook with zero hard coupling.
 */
final class ApiResources {
	/**
	 * Relative path to plugin_yaml's static Config/State YAML schema file.
	 */
	private const SCHEMA_PATH = 'schema/yaml.schema.yaml';

	/**
	 * Build plain-array Google AIP-compatible resource descriptors for `plugin_api`.
	 *
	 * @param mixed                   $resources      Existing hook payload.
	 * @param callable(): YamlService $serviceFactory Lazy service factory.
	 * @return list<array<string, mixed>>
	 */
	public static function register(mixed $resources, callable $serviceFactory): array {
		$list = [];

		if (is_array($resources)) {
			foreach ($resources as $item) {
				if (is_array($item) && is_string($item['collection'] ?? null) && $item['collection'] !== '') {
					$normalized = [];

					foreach ($item as $k => $v) {
						$normalized[(string) $k] = $v;
					}

					$list[] = $normalized;
				}
			}
		}

		$list[] = [
			'plugin'        => 'yaml',
			'collection'    => 'yamlPacks',
			'singleton'     => 'yamlPack',
			'description'   => 'Installed YAML Monitoring Packs and SHA-256 lock digests.',
			'realm_read'    => Pages::VIEW,
			'schema'        => self::SCHEMA_PATH,
			'filter_fields' => ['id', 'name', 'version', 'status'],
			'order_fields'  => ['id', 'name', 'version'],
			'handlers'      => [
				'list' => static function (array $context = []) use ($serviceFactory): array {
					$packs = $serviceFactory()->repository()->listPacks();

					return array_map(
						static function (array $row): array {
							$packName = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';

							return [
								'id'             => $packName,
								'name'           => 'yamlPacks/' . $packName,
								'packId'         => $packName,
								'version'        => is_scalar($row['version'] ?? null) ? (string) $row['version'] : '',
								'status'         => is_scalar($row['status'] ?? null) ? (string) $row['status'] : '',
								'manifestSha256' => is_scalar($row['manifest_sha256'] ?? null) ? (string) $row['manifest_sha256'] : '',
								'lockSha256'     => is_scalar($row['lock_sha256'] ?? null) ? (string) $row['lock_sha256'] : '',
							];
						},
						$packs
					);
				},
				'get' => static function (string $packId, array $context = []) use ($serviceFactory): ?array {
					$row = $serviceFactory()->repository()->getPackByName($packId);

					if ($row === null) {
						return null;
					}

					return [
						'id'             => $packId,
						'name'           => 'yamlPacks/' . $packId,
						'packId'         => $packId,
						'version'        => is_scalar($row['version'] ?? null) ? (string) $row['version'] : '',
						'status'         => is_scalar($row['status'] ?? null) ? (string) $row['status'] : '',
						'manifestSha256' => is_scalar($row['manifest_sha256'] ?? null) ? (string) $row['manifest_sha256'] : '',
						'lockSha256'     => is_scalar($row['lock_sha256'] ?? null) ? (string) $row['lock_sha256'] : '',
					];
				},
			],
			'codec' => [
				'decode' => static fn (string $yaml): mixed => YamlParser::parse($yaml),
				'encode' => static fn (mixed $data): string => YamlEmitter::emit($data),
			],
		];

		$list[] = [
			'plugin'        => 'yaml',
			'collection'    => 'yamlSchema',
			'singleton'     => 'yamlSchema',
			'description'   => 'Aggregated Draft 2020-12 JSON Schema for Cacti YAML manifests.',
			'realm_read'    => Pages::VIEW,
			'schema'        => self::SCHEMA_PATH,
			'filter_fields' => ['id', 'name'],
			'order_fields'  => ['id', 'name'],
			'handlers'      => [
				'list' => static function (array $context = []) use ($serviceFactory): array {
					$generated = $serviceFactory()->generateSchema();

					return [
						[
							'id'       => 'current',
							'name'     => 'yamlSchema/current',
							'schemaId' => is_string($generated['schema']['$id'] ?? null) ? $generated['schema']['$id'] : '',
							'plugins'  => $generated['plugins'],
							'schema'   => $generated['schema'],
						],
					];
				},
				'get' => static function (string $id = 'current', array $context = []) use ($serviceFactory): ?array {
					if ($id !== '' && $id !== 'current' && $id !== 'latest') {
						return null;
					}

					return $serviceFactory()->generateSchema()['schema'];
				},
			],
		];

		return $list;
	}
}
