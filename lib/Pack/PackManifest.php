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

namespace Somethingwithproof\Yaml\Pack;

use Somethingwithproof\Yaml\Codec\YamlEmitter;
use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Validation\CrossParameterValidator;

/**
 * Immutable value object representing a versioned YAML Monitoring Pack manifest.
 */
final class PackManifest {
	/**
	 * Expected apiVersion for MonitoringPack manifests.
	 */
	public const API_VERSION = 'cacti.yaml/v1';

	/**
	 * Expected kind for MonitoringPack manifests.
	 */
	public const KIND = 'MonitoringPack';

	/**
	 * Construct a validated MonitoringPack manifest.
	 *
	 * @param string $name Unique pack slug.
	 * @param string $version Semantic version string.
	 * @param string $description Human-readable summary.
	 * @param string $cactiCompat Minimum compatible Cacti version.
	 * @param list<string> $templates Relative template file paths (.yaml, .yml, or .xml).
	 * @param list<string> $dataQueries Relative Data Query YAML file paths.
	 * @param list<string> $cascFragments Relative CasC YAML fragment file paths.
	 * @param list<array<string, mixed>> $tholdTemplates Optional Thold baseline template definitions.
	 * @param list<array<string, mixed>> $maintSchedules Optional Maint schedule definitions.
	 * @param list<array<string, mixed>> $treeRules Optional Graph Tree placement rules.
	 */
	public function __construct(
		/**
		 * Unique pack slug.
		 */
		public readonly string $name,
		/**
		 * Semantic version string.
		 */
		public readonly string $version,
		/**
		 * Human-readable pack description.
		 */
		public readonly string $description,
		/**
		 * Minimum compatible Cacti version.
		 */
		public readonly string $cactiCompat,
		/**
		 * Relative template paths (.yaml, .yml, .xml).
		 *
		 * @var list<string>
		 */
		public readonly array $templates,
		/**
		 * Relative Data Query YAML file paths.
		 *
		 * @var list<string>
		 */
		public readonly array $dataQueries,
		/**
		 * Relative CasC YAML fragment file paths.
		 *
		 * @var list<string>
		 */
		public readonly array $cascFragments,
		/**
		 * Optional Thold baseline template definitions.
		 *
		 * @var list<array<string, mixed>>
		 */
		public readonly array $tholdTemplates = [],
		/**
		 * Optional Maint schedule definitions.
		 *
		 * @var list<array<string, mixed>>
		 */
		public readonly array $maintSchedules = [],
		/**
		 * Optional Graph Tree placement rules.
		 *
		 * @var list<array<string, mixed>>
		 */
		public readonly array $treeRules = [],
	) {
	}

	/**
	 * Parse and validate a MonitoringPack YAML string.
	 *
	 * @param string $yaml Raw pack manifest YAML.
	 * @return self Validated manifest instance.
	 * @throws YamlException When YAML syntax or manifest validation fails.
	 */
	public static function fromYaml(string $yaml): self {
		return self::fromArray(YamlParser::parseMap($yaml));
	}

	/**
	 * Hydrate and validate a MonitoringPack from a decoded array.
	 *
	 * @param array<string, mixed> $doc Decoded YAML manifest.
	 * @return self Validated manifest instance.
	 * @throws YamlException When required fields or cross-parameter invariants fail.
	 */
	public static function fromArray(array $doc): self {
		$errors     = [];
		$apiVersion = is_string($doc['apiVersion'] ?? null) ? $doc['apiVersion'] : '';
		$kind       = is_string($doc['kind'] ?? null) ? $doc['kind'] : '';
		$metadata   = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];
		$spec       = is_array($doc['spec'] ?? null) ? $doc['spec'] : [];

		if ($apiVersion !== self::API_VERSION) {
			$errors[] = sprintf('apiVersion must be "%s".', self::API_VERSION);
		}

		if ($kind !== self::KIND) {
			$errors[] = sprintf('kind must be "%s".', self::KIND);
		}

		$name    = is_string($metadata['name'] ?? null) ? trim($metadata['name']) : '';
		$version = is_string($metadata['version'] ?? null) ? trim($metadata['version']) : '';

		if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $name) !== 1) {
			$errors[] = sprintf('metadata.name "%s" must match ^[a-z0-9][a-z0-9._-]{0,127}$.', $name);
		}

		if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/D', $version) !== 1) {
			$errors[] = sprintf('metadata.version "%s" must be a valid SemVer string (e.g. 1.0.0).', $version);
		}

		$errors = array_merge($errors, CrossParameterValidator::validate($doc));

		if ($errors !== []) {
			throw new YamlException('MonitoringPack manifest validation failed.', array_values(array_unique($errors)));
		}

		return new self(
			$name,
			$version,
			is_string($metadata['description'] ?? null) ? trim($metadata['description']) : '',
			is_string($metadata['cactiCompat'] ?? null) && trim($metadata['cactiCompat']) !== '' ? trim($metadata['cactiCompat']) : '1.2.31',
			self::stringList($spec['templates'] ?? []),
			self::stringList($spec['dataQueries'] ?? []),
			self::stringList($spec['cascFragments'] ?? []),
			self::mapList($spec['tholdTemplates'] ?? []),
			self::mapList($spec['maintSchedules'] ?? []),
			self::mapList($spec['treeRules'] ?? []),
		);
	}

	/**
	 * Convert the manifest into a canonical array suitable for YAML serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$spec = [
			'templates'     => $this->templates,
			'dataQueries'   => $this->dataQueries,
			'cascFragments' => $this->cascFragments,
		];

		if ($this->tholdTemplates !== []) {
			$spec['tholdTemplates'] = $this->tholdTemplates;
		}

		if ($this->maintSchedules !== []) {
			$spec['maintSchedules'] = $this->maintSchedules;
		}

		if ($this->treeRules !== []) {
			$spec['treeRules'] = $this->treeRules;
		}

		return [
			'apiVersion' => self::API_VERSION,
			'kind'       => self::KIND,
			'metadata'   => [
				'name'        => $this->name,
				'version'     => $this->version,
				'description' => $this->description,
				'cactiCompat' => $this->cactiCompat,
			],
			'spec' => $spec,
		];
	}

	/**
	 * Serialize the manifest to canonical YAML 1.2 text.
	 *
	 * @return string
	 */
	public function toYaml(): string {
		return YamlEmitter::emit($this->toArray(), false);
	}

	/**
	 * Normalize a candidate value into a list of non-empty trimmed strings.
	 *
	 * @param mixed $raw Candidate list.
	 * @return list<string>
	 */
	private static function stringList(mixed $raw): array {
		if (!is_array($raw)) {
			return [];
		}

		$out = [];

		foreach ($raw as $item) {
			if (is_string($item) && trim($item) !== '') {
				$out[] = trim($item);
			}
		}

		return $out;
	}

	/**
	 * Normalize a candidate value into a list of associative mappings.
	 *
	 * @param mixed $raw Candidate list.
	 * @return list<array<string, mixed>>
	 */
	private static function mapList(mixed $raw): array {
		if (!is_array($raw)) {
			return [];
		}

		$out = [];

		foreach ($raw as $item) {
			if (is_array($item) && !array_is_list($item)) {
				$map = [];

				foreach ($item as $k => $v) {
					$map[(string) $k] = $v;
				}

				$out[] = $map;
			}
		}

		return $out;
	}
}
