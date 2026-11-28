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

use Somethingwithproof\Yaml\Pages;
use Somethingwithproof\Yaml\Service\YamlService;

/**
 * Registers read-only View-realm abilities with `plugin_mcp`'s
 * `abilities_register` hook (`yaml-get-schema`, `yaml-list-packs`,
 * `yaml-validate-source`) without exposing secrets or coupling to `plugin_mcp`
 * classes at boot.
 */
final class McpAbilities {
	/**
	 * Read-only MCP tool annotations shared across all plugin_yaml abilities.
	 *
	 * @var array{readOnly: bool, destructive: bool, idempotent: bool}
	 */
	private const ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * Register plugin_yaml abilities on a duck-typed registrar object.
	 *
	 * @param object $registrar plugin_mcp AbilitiesRegistrar instance.
	 * @param callable(): YamlService $serviceFactory Lazy service factory.
	 * @param callable(int): bool $permissionCheck Cacti View-realm authorization predicate.
	 * @return void
	 */
	public static function register(
		object $registrar,
		callable $serviceFactory,
		callable $permissionCheck,
	): void {
		if (!method_exists($registrar, 'register')) {
			return;
		}

		$registrar->register('yaml-get-schema', [
			'label'        => 'Get live Cacti YAML JSON Schema',
			'description'  => 'Return the aggregated Draft 2020-12 JSON Schema and discovered plugin slugs without exposing secrets.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'schemaId' => ['type' => 'string', 'maxLength' => 256],
					'plugins'  => [
						'type'     => 'array',
						'items'    => ['type' => 'string', 'maxLength' => 64],
						'maxItems' => 128,
					],
					'schemaJson' => ['type' => 'string', 'maxLength' => 524288],
				],
				'required'             => ['schemaId', 'plugins', 'schemaJson'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($serviceFactory): array {
				$generated = $serviceFactory()->generateSchema();

				return [
					'schemaId'   => is_string($generated['schema']['$id'] ?? null) ? $generated['schema']['$id'] : '',
					'plugins'    => $generated['plugins'],
					'schemaJson' => $generated['json'],
				];
			},
		]);

		$registrar->register('yaml-list-packs', [
			'label'        => 'List installed YAML Monitoring Packs',
			'description'  => 'Return installed Monitoring Packs, versions, and SHA-256 lock digests without secret values.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
					'packs' => [
						'type'     => 'array',
						'maxItems' => 500,
						'items'    => [
							'type'       => 'object',
							'properties' => [
								'name'       => ['type' => 'string', 'maxLength' => 128],
								'version'    => ['type' => 'string', 'maxLength' => 32],
								'status'     => ['type' => 'string', 'maxLength' => 32],
								'lockSha256' => ['type' => 'string', 'maxLength' => 64],
							],
							'required'             => ['name', 'version', 'status', 'lockSha256'],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => ['count', 'packs'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($serviceFactory): array {
				$rows  = $serviceFactory()->repository()->listPacks();
				$packs = array_map(
					static fn (array $row): array => [
						'name'       => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
						'version'    => is_scalar($row['version'] ?? null) ? (string) $row['version'] : '',
						'status'     => is_scalar($row['status'] ?? null) ? (string) $row['status'] : '',
						'lockSha256' => is_scalar($row['lock_sha256'] ?? null) ? (string) $row['lock_sha256'] : '',
					],
					$rows
				);

				return [
					'count' => count($packs),
					'packs' => $packs,
				];
			},
		]);

		$registrar->register('yaml-validate-source', [
			'label'        => 'Validate configured YAML fragment sources',
			'description'  => 'Validate configured casc.d YAML fragments for syntax, placeholders, and cross-parameter invariants.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'configured' => ['type' => 'boolean'],
					'valid'      => ['type' => 'boolean'],
					'kind'       => ['type' => 'string', 'maxLength' => 64],
					'fileCount'  => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
					'planHash'   => ['type' => 'string', 'maxLength' => 64],
					'errors'     => [
						'type'     => 'array',
						'items'    => ['type' => 'string', 'maxLength' => 512],
						'maxItems' => 200,
					],
				],
				'required'             => ['configured', 'valid', 'kind', 'fileCount', 'planHash', 'errors'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($serviceFactory): array {
				$service = $serviceFactory();
				$sources = $service->settings()->sourcePaths;

				if ($sources === []) {
					return [
						'configured' => false,
						'valid'      => true,
						'kind'       => 'none',
						'fileCount'  => 0,
						'planHash'   => '',
						'errors'     => [],
					];
				}

				$res = $service->validateSource($sources);

				return [
					'configured' => true,
					'valid'      => $res['valid'],
					'kind'       => $res['kind'],
					'fileCount'  => count($res['files']),
					'planHash'   => $res['planHash'],
					'errors'     => array_slice($res['errors'], 0, 200),
				];
			},
		]);
	}

	/**
	 * Default Cacti realm permission check for MCP abilities (YAML View or higher).
	 *
	 * @param int $userId Cacti user ID.
	 * @return bool
	 */
	public static function userHasViewRealm(int $userId): bool {
		if ($userId <= 0 || !function_exists('db_fetch_cell_prepared') || !function_exists('is_realm_allowed')) {
			return false;
		}

		foreach ([Pages::VIEW, Pages::OPERATOR, Pages::ADMIN] as $file) {
			/** @var mixed $realmId */
			$realmId = db_fetch_cell_prepared(
				'SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?',
				['yaml', $file]
			);

			if (is_numeric($realmId) && (int) $realmId > 0 && is_realm_allowed((int) $realmId + 100, $userId)) {
				return true;
			}
		}

		return false;
	}
}
