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

use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Merge\MergePolicy;
use Somethingwithproof\Yaml\Schema\StaticSchemaLoader;
use Somethingwithproof\Yaml\Service\YamlService;
use Somethingwithproof\Yaml\Template\TemplateCompiler;
use Somethingwithproof\Yaml\Validation\CrossParameterValidator;

/**
 * Decoupled integration adapter for `plugin_casc` hooks:
 * `casc_register_providers`, `casc_register_template_compilers`, and
 * `casc_describe_schema`.
 *
 * Uses plain PHP arrays and closures with ZERO hard PHP imports of
 * `plugin_casc` classes so `plugin_yaml` boots and operates 100% standalone.
 */
final class CascProvider {
	/**
	 * Provider slug owned by plugin_yaml inside CasC manifests (`spec.plugins.yaml`).
	 */
	public const SLUG = 'yaml';

	/**
	 * Schema version advertised by this provider.
	 */
	public const SCHEMA_VERSION = '1.0';

	/**
	 * Build a plain-array provider descriptor (`slug: yaml`) with export,
	 * validate, plan, and apply callables.
	 *
	 * @param callable(): YamlService $serviceFactory Lazy service factory.
	 * @return array{slug: string, schemaVersion: string, export: callable(array<string, mixed>): array<string, mixed>, validate: callable(array<string, mixed>): list<string>, plan: callable(array<string, mixed>, array<string, mixed>): list<array<string, mixed>>, apply: callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed>}
	 */
	public static function buildProviderDescriptor(callable $serviceFactory): array {
		return [
			'slug'          => self::SLUG,
			'schemaVersion' => self::SCHEMA_VERSION,
			'export'        => static function (array $options) use ($serviceFactory): array {
				$service = $serviceFactory();
				$repo    = $service->repository();

				return [
					'sourcePaths'    => $service->settings()->sourcePaths,
					'mergePolicy'    => $service->settings()->mergePolicy->value,
					'packDir'        => $service->settings()->packDir,
					'managedQueries' => array_map(
						static fn (array $row): array => [
							'name'       => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
							'kind'       => is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : '',
							'outputFile' => is_scalar($row['output_file'] ?? null) ? (string) $row['output_file'] : '',
							'sha256'     => is_scalar($row['xml_sha256'] ?? null) ? (string) $row['xml_sha256'] : '',
						],
						$repo->listQueries()
					),
					'packs' => array_map(
						static fn (array $row): array => [
							'name'       => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
							'version'    => is_scalar($row['version'] ?? null) ? (string) $row['version'] : '',
							'sourcePath' => is_scalar($row['source_path'] ?? null) ? (string) $row['source_path'] : '',
							'lockSha256' => is_scalar($row['lock_sha256'] ?? null) ? (string) $row['lock_sha256'] : '',
						],
						$repo->listPacks()
					),
				];
			},
			'validate' => static function (array $desired): array {
				$errors = [];

				if (isset($desired['mergePolicy']) && is_string($desired['mergePolicy']) && MergePolicy::tryFrom($desired['mergePolicy']) === null) {
					$errors[] = sprintf('yaml.mergePolicy "%s" must be "strict" or "overlay".', $desired['mergePolicy']);
				}

				if (isset($desired['sourcePaths']) && !is_array($desired['sourcePaths'])) {
					$errors[] = 'yaml.sourcePaths must be a list of file or directory paths.';
				}

				$docErrors = CrossParameterValidator::validate([
					'kind' => 'CactiConfig',
					'spec' => ['plugins' => ['yaml' => $desired]],
				]);

				return array_values(array_unique(array_merge($errors, $docErrors)));
			},
			'plan' => static function (array $desired, array $options) use ($serviceFactory): array {
				$service = $serviceFactory();
				$actions = [];

				if (isset($desired['mergePolicy']) && is_string($desired['mergePolicy']) && $desired['mergePolicy'] !== $service->settings()->mergePolicy->value) {
					$actions[] = [
						'operation' => 'update',
						'key'       => 'yaml.mergePolicy',
						'from'      => $service->settings()->mergePolicy->value,
						'to'        => $desired['mergePolicy'],
					];
				}

				if (isset($desired['sourcePaths']) && is_array($desired['sourcePaths']) && $desired['sourcePaths'] !== $service->settings()->sourcePaths) {
					$actions[] = [
						'operation' => 'update',
						'key'       => 'yaml.sourcePaths',
						'from'      => $service->settings()->sourcePaths,
						'to'        => $desired['sourcePaths'],
					];
				}

				return $actions;
			},
			'apply' => static function (array $desired, string $planHash, array $options) use ($serviceFactory): array {
				$service = $serviceFactory();

				if (function_exists('db_execute_prepared')) {
					if (isset($desired['mergePolicy']) && is_string($desired['mergePolicy']) && MergePolicy::tryFrom($desired['mergePolicy']) !== null) {
						db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', ['yaml_merge_policy', $desired['mergePolicy']]);
					}

					if (isset($desired['sourcePaths']) && is_array($desired['sourcePaths'])) {
						$paths = array_values(array_filter($desired['sourcePaths'], static fn (mixed $p): bool => is_string($p) && trim($p) !== ''));
						db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', ['yaml_source_paths', implode(',', $paths)]);
					}

					if (isset($desired['packDir']) && is_string($desired['packDir'])) {
						db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', ['yaml_pack_dir', trim($desired['packDir'])]);
					}
				}

				$service->repository()->recordAudit('casc', 'hook', 'provider-apply', $planHash, 'succeeded', ['slug' => self::SLUG]);

				return ['ok' => true, 'slug' => self::SLUG, 'planHash' => $planHash];
			},
		];
	}

	/**
	 * Register the `yaml` provider into either a plain-array hook accumulator or
	 * a duck-typed registrar object without hard-importing plugin_casc classes.
	 *
	 * @param mixed $registrar Hook payload (array accumulator or registrar object).
	 * @param callable(): YamlService $serviceFactory Lazy service factory.
	 * @return mixed Updated hook payload when array-based, or original registrar object.
	 */
	public static function registerProvider(mixed $registrar, callable $serviceFactory): mixed {
		$descriptor = self::buildProviderDescriptor($serviceFactory);

		if (is_array($registrar)) {
			$registrar[self::SLUG] = $descriptor;

			return $registrar;
		}

		if (is_object($registrar) && method_exists($registrar, 'register')) {
			$descriptorClass = 'Somethingwithproof\\Casc\\Extension\\PluginProviderDescriptor';

			if (class_exists($descriptorClass, false)) {
				$instance = new $descriptorClass(
					$descriptor['slug'],
					$descriptor['schemaVersion'],
					$descriptor['export'],
					$descriptor['validate'],
					$descriptor['plan'],
					$descriptor['apply']
				);
				$registrar->register($instance);
			} else {
				$registrar->register($descriptor);
			}
		}

		return $registrar;
	}

	/**
	 * Build plain-array template compiler descriptors for `casc_register_template_compilers`.
	 *
	 * @param mixed $compilers Existing hook payload.
	 * @return list<array{id: string, extensions: list<string>, compile: callable(string): array{xml: string, sha256: string, hashes: list<string>}}>
	 */
	public static function registerTemplateCompilers(mixed $compilers): array {
		$existing = [];

		if (is_array($compilers)) {
			foreach ($compilers as $item) {
				if (is_array($item)) {
					/** @var array{id: string, extensions: list<string>, compile: callable(string): array{xml: string, sha256: string, hashes: list<string>}} $item */
					$existing[] = $item;
				}
			}
		}

		$existing[] = [
			'id'         => 'plugin_yaml_template_compiler',
			'extensions' => ['yaml', 'yml'],
			'compile'    => static function (string $yaml): array {
				return TemplateCompiler::compileYaml($yaml);
			},
		];

		return $existing;
	}

	/**
	 * Build a plain-array schema descriptor for `casc_describe_schema` from the
	 * plugin's static `schema/yaml.schema.yaml` file.
	 *
	 * @param mixed $schemas Existing hook payload.
	 * @param string $pluginDir Path to plugin_yaml root directory.
	 * @return list<array<string, mixed>>
	 */
	public static function describeSchema(mixed $schemas, string $pluginDir): array {
		$list = [];

		if (is_array($schemas)) {
			foreach ($schemas as $item) {
				if (is_array($item)) {
					$normalized = [];

					foreach ($item as $k => $v) {
						$normalized[(string) $k] = $v;
					}

					$list[] = $normalized;
				}
			}
		}

		$file = rtrim($pluginDir, '/') . '/schema/yaml.schema.yaml';

		try {
			$loaded = StaticSchemaLoader::loadFile($file, self::SLUG);
			$list[] = $loaded;
		} catch (YamlException) {
			$list[] = [
				'plugin'        => self::SLUG,
				'schemaVersion' => self::SCHEMA_VERSION,
				'title'         => 'YAML Templates, Schema & CasC Fragments',
				'description'   => 'Declarative YAML fragment sources, merge policy, compiled Data Queries, and Monitoring Packs.',
				'config'        => [],
				'state'         => [],
			];
		}

		return $list;
	}
}
