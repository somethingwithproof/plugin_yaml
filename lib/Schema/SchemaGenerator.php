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

/**
 * Generates a live JSON Schema (Draft 2020-12) document by combining core
 * Cacti CasC definitions, Drupal-style static `schema/*.yaml` descriptors, and
 * dynamic plain-array `casc_describe_schema` hook responses.
 */
final class SchemaGenerator {
	/**
	 * JSON Schema Draft 2020-12 meta-schema URI.
	 */
	public const DRAFT_2020_12 = 'https://json-schema.org/draft/2020-12/schema';

	/**
	 * Canonical schema $id URI emitted by plugin_yaml.
	 */
	public const SCHEMA_ID = 'https://github.com/somethingwithproof/plugin_yaml/schemas/cacti-casc.schema.json';

	/**
	 * Build a merged catalog of plugin schemas from static files and dynamic hooks.
	 *
	 * Dynamic hook descriptors may augment or override static descriptors for the
	 * same plugin slug while keeping Config and State cleanly separated.
	 *
	 * @param array<string, array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}> $staticSchemas
	 * @param list<array<string, mixed>> $hookDescriptors Plain-array descriptors returned by `casc_describe_schema` hooks.
	 * @return array<string, array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}>
	 */
	public static function mergeDescriptors(array $staticSchemas, array $hookDescriptors = []): array {
		$merged = $staticSchemas;

		foreach ($hookDescriptors as $descriptor) {
			$slug = is_string($descriptor['plugin'] ?? null)
				? trim($descriptor['plugin'])
				: (is_string($descriptor['slug'] ?? null) ? trim($descriptor['slug']) : '');

			if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $slug) !== 1) {
				continue;
			}

			$existing = $merged[$slug] ?? [
				'plugin'        => $slug,
				'schemaVersion' => '1.0',
				'title'         => $slug,
				'description'   => '',
				'config'        => [],
				'state'         => [],
				'source'        => 'hook:casc_describe_schema',
			];

			$config = $existing['config'];

			if (isset($descriptor['config']) && is_array($descriptor['config'])) {
				foreach ($descriptor['config'] as $k => $v) {
					if (is_array($v)) {
						$config[(string) $k] = $v;
					}
				}
			}

			$state = $existing['state'];

			if (isset($descriptor['state']) && is_array($descriptor['state'])) {
				foreach ($descriptor['state'] as $k => $v) {
					if (is_array($v)) {
						$v['readOnly']      = true;
						$state[(string) $k] = $v;
					}
				}
			}

			$merged[$slug] = [
				'plugin'        => $slug,
				'schemaVersion' => is_scalar($descriptor['schemaVersion'] ?? null) ? (string) $descriptor['schemaVersion'] : $existing['schemaVersion'],
				'title'         => is_string($descriptor['title'] ?? null) && trim($descriptor['title']) !== '' ? trim($descriptor['title']) : $existing['title'],
				'description'   => is_string($descriptor['description'] ?? null) && trim($descriptor['description']) !== '' ? trim($descriptor['description']) : $existing['description'],
				'config'        => $config,
				'state'         => $state,
				'source'        => $existing['source'],
			];
		}

		ksort($merged, SORT_STRING);

		return $merged;
	}

	/**
	 * Generate a complete JSON Schema (Draft 2020-12) array for Cacti CasC and
	 * all discovered plugins.
	 *
	 * @param array<string, array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}> $pluginSchemas
	 * @return array<string, mixed> Draft 2020-12 JSON Schema structure.
	 */
	public static function generate(array $pluginSchemas = []): array {
		$pluginProperties = [];
		$stateDefs        = [];

		foreach ($pluginSchemas as $slug => $schema) {
			$pluginProperties[$slug] = [
				'type'                 => 'object',
				'title'                => $schema['title'],
				'description'          => $schema['description'],
				'x-schema-version'     => $schema['schemaVersion'],
				'properties'           => $schema['config'] !== [] ? $schema['config'] : new \stdClass(),
				'additionalProperties' => false,
			];

			if ($schema['state'] !== []) {
				$stateDefs[$slug] = [
					'type'                 => 'object',
					'title'                => $schema['title'] . ' Runtime State',
					'readOnly'             => true,
					'properties'           => $schema['state'],
					'additionalProperties' => false,
				];
			}
		}

		return [
			'$schema'     => self::DRAFT_2020_12,
			'$id'         => self::SCHEMA_ID,
			'title'       => 'Cacti 1.2 Declarative Configuration Schema',
			'description' => 'Live JSON Schema Draft 2020-12 aggregated from Cacti CasC core definitions, static plugin schema/*.yaml files, and casc_describe_schema hooks.',
			'type'        => 'object',
			'required'    => ['apiVersion', 'kind', 'spec'],
			'properties'  => [
				'apiVersion' => [
					'type'        => 'string',
					'enum'        => ['cacti.net/v1alpha1', 'cacti.yaml/v1'],
					'description' => 'Declarative manifest API version.',
				],
				'kind' => [
					'type'        => 'string',
					'enum'        => ['CactiConfig', 'CactiTemplateBundle', 'SnmpDataQuery', 'ScriptDataQuery', 'MonitoringPack'],
					'description' => 'Document kind identifier.',
				],
				'metadata' => [
					'type'       => 'object',
					'properties' => [
						'name'        => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
						'version'     => ['type' => 'string', 'maxLength' => 32],
						'description' => ['type' => 'string', 'maxLength' => 1024],
						'outputFile'  => ['type' => 'string', 'maxLength' => 255],
						'cactiCompat' => ['type' => 'string', 'maxLength' => 32],
					],
					'additionalProperties' => true,
				],
				'spec' => [
					'type'       => 'object',
					'properties' => [
						'settings' => [
							'type'       => 'object',
							'properties' => [
								'allowlistMode' => ['type' => 'string', 'enum' => ['merge', 'strict']],
								'values'        => ['type' => 'object', 'additionalProperties' => true],
							],
							'additionalProperties' => false,
						],
						'sites' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'required'   => ['name'],
								'properties' => [
									'name'     => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
									'city'     => ['type' => 'string', 'maxLength' => 64],
									'country'  => ['type' => 'string', 'maxLength' => 64],
									'timezone' => ['type' => 'string', 'maxLength' => 64],
								],
								'additionalProperties' => true,
							],
						],
						'pollers' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'required'   => ['name'],
								'properties' => [
									'name'     => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
									'hostname' => ['type' => 'string', 'maxLength' => 250],
								],
								'additionalProperties' => true,
							],
						],
						'templates' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'required'   => ['path'],
								'properties' => [
									'name'   => ['type' => 'string', 'maxLength' => 128],
									'path'   => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512],
									'sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
								],
								'additionalProperties' => false,
							],
						],
						'devices' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'required'   => ['hostname'],
								'properties' => [
									'hostname'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 250],
									'description' => ['type' => 'string', 'maxLength' => 250],
									'site'        => ['type' => 'string', 'maxLength' => 100],
									'poller'      => ['type' => 'string', 'maxLength' => 64],
									'template'    => ['type' => 'string', 'maxLength' => 128],
									'snmpVersion' => ['type' => 'integer', 'enum' => [0, 1, 2, 3]],
								],
								'additionalProperties' => true,
							],
						],
						'trees' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'required'   => ['name'],
								'properties' => [
									'name'     => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
									'enabled'  => ['type' => 'boolean'],
									'sortType' => ['type' => 'string', 'enum' => ['manual', 'alphabetic', 'natural', 'numeric']],
									'items'    => ['type' => 'array'],
								],
								'additionalProperties' => false,
							],
						],
						'plugins' => [
							'type'                 => 'object',
							'description'          => 'Declarative configuration sections owned by individual Cacti plugins.',
							'properties'           => $pluginProperties !== [] ? $pluginProperties : new \stdClass(),
							'additionalProperties' => false,
						],
					],
					'additionalProperties' => true,
				],
			],
			'$defs' => [
				'pluginState' => [
					'type'        => 'object',
					'description' => 'Read-only runtime state definitions exposed by installed plugins (never written into declarative config manifests).',
					'readOnly'    => true,
					'properties'  => $stateDefs !== [] ? $stateDefs : new \stdClass(),
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * Encode the generated JSON Schema into deterministic pretty-printed JSON.
	 *
	 * @param array<string, array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}> $pluginSchemas
	 * @return string Pretty-printed JSON Schema text.
	 */
	public static function toJson(array $pluginSchemas = []): string {
		$encoded = json_encode(self::generate($pluginSchemas), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		return (is_string($encoded) ? $encoded : '{}') . "\n";
	}
}
