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
	 * Build plain-array Google AIP-compatible resource descriptors for `plugin_api`.
	 *
	 * @param mixed $resources Existing hook payload.
	 * @param callable(): YamlService $serviceFactory Lazy service factory.
	 * @return list<array<string, mixed>>
	 */
	public static function register(mixed $resources, callable $serviceFactory): array {
		$list = [];

		if (is_array($resources)) {
			foreach ($resources as $item) {
				if (is_array($item)) {
					$normalized = [];

					foreach ($item as $k => $v) {
						$normalized[(string) $k] = $v;
					}

					$list[] = $normalized;
				}
			}
		}

		$list[] = [
			'resource'    => 'yamlPacks',
			'singular'    => 'yamlPack',
			'collection'  => 'yamlPacks',
			'plugin'      => 'yaml',
			'realm'       => Pages::VIEW,
			'mediaTypes'  => ['application/json', 'application/yaml'],
			'methods'     => ['list', 'get'],
			'list'        => static function () use ($serviceFactory): array {
				$packs = $serviceFactory()->repository()->listPacks();

				return [
					'yamlPacks' => array_map(
						static fn (array $row): array => [
							'name'           => 'yamlPacks/' . (is_scalar($row['name'] ?? null) ? (string) $row['name'] : ''),
							'packId'         => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
							'version'        => is_scalar($row['version'] ?? null) ? (string) $row['version'] : '',
							'status'         => is_scalar($row['status'] ?? null) ? (string) $row['status'] : '',
							'manifestSha256' => is_scalar($row['manifest_sha256'] ?? null) ? (string) $row['manifest_sha256'] : '',
							'lockSha256'     => is_scalar($row['lock_sha256'] ?? null) ? (string) $row['lock_sha256'] : '',
						],
						$packs
					),
				];
			},
			'get' => static function (string $packId) use ($serviceFactory): ?array {
				$row = $serviceFactory()->repository()->getPackByName($packId);

				if ($row === null) {
					return null;
				}

				return [
					'name'           => 'yamlPacks/' . $packId,
					'packId'         => $packId,
					'version'        => is_scalar($row['version'] ?? null) ? (string) $row['version'] : '',
					'status'         => is_scalar($row['status'] ?? null) ? (string) $row['status'] : '',
					'manifestSha256' => is_scalar($row['manifest_sha256'] ?? null) ? (string) $row['manifest_sha256'] : '',
					'lockSha256'     => is_scalar($row['lock_sha256'] ?? null) ? (string) $row['lock_sha256'] : '',
				];
			},
			'codec' => [
				'decode' => static fn (string $yaml): mixed => YamlParser::parse($yaml),
				'encode' => static fn (mixed $data): string => YamlEmitter::emit($data),
			],
		];

		$list[] = [
			'resource'    => 'yamlSchema',
			'singular'    => 'yamlSchema',
			'collection'  => 'yamlSchema',
			'plugin'      => 'yaml',
			'realm'       => Pages::VIEW,
			'mediaTypes'  => ['application/schema+json', 'application/json'],
			'methods'     => ['get'],
			'get'         => static function () use ($serviceFactory): array {
				return $serviceFactory()->generateSchema()['schema'];
			},
		];

		return $list;
	}
}
