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

namespace Somethingwithproof\Yaml\Template;

use Somethingwithproof\Yaml\Codec\YamlException;

/**
 * Bidirectional registry mapping Cacti 1.2 native template hashes
 * (`hash_<2-hex-type><4-hex-version><32-hex-id>`) to human-readable symbolic
 * references (`<type>/<slug>`).
 */
final class HashRegistry {
	/**
	 * Default Cacti 1.2.31 XML hash version code (matching $cacti_version_codes).
	 */
	public const DEFAULT_VERSION_CODE = '0103';

	/**
	 * Map of Cacti template object type names to 2-hex-digit hash type codes
	 * (matching Cacti 1.2 include/global_arrays.php $hash_type_codes).
	 *
	 * @var array<string, string>
	 */
	public const TYPE_CODES = [
		'graph_template'            => '00',
		'data_template'             => '01',
		'host_template'             => '02',
		'data_input_method'         => '03',
		'data_query'                => '04',
		'cdef'                      => '05',
		'gprint_preset'             => '06',
		'data_input_field'          => '07',
		'data_template_item'        => '08',
		'graph_template_input'      => '09',
		'graph_template_item'       => '10',
		'data_query_graph'          => '11',
		'data_query_sv_graph'       => '12',
		'data_query_sv_data_source' => '13',
		'cdef_item'                 => '14',
		'round_robin_archive'       => '15',
		'vdef'                      => '18',
		'vdef_item'                 => '19',
		'data_source_profile'       => '20',
	];

	/**
	 * Built-in Cacti system data input method hashes that may be referenced
	 * without being redefined inside an exported bundle.
	 *
	 * @var array<string, string>
	 */
	public const SYSTEM_DATA_INPUTS = [
		'3eb92bb845b9660a7445cf9740726522' => 'system/get_snmp_data',
		'bf566c869ac6443b0c75d1c32b5a350e' => 'system/get_snmp_data_indexed',
		'80e9e4c4191a5da189ae26d0e237f015' => 'system/get_script_data_indexed',
		'332111d8b54ac8ce939af87a7eac0c06' => 'system/get_script_server_data_indexed',
	];

	/**
	 * Mapping from canonical 32-hex Cacti hash to symbolic reference (`type/slug`).
	 *
	 * @var array<string, string>
	 */
	private array $hashToRef = [];

	/**
	 * Mapping from symbolic reference (`type/slug`) to full Cacti XML tag metadata.
	 *
	 * @var array<string, array{type: string, typeCode: string, versionCode: string, hash: string}>
	 */
	private array $refToMeta = [];

	/**
	 * Tracks used symbolic refs to guarantee unique disambiguated slugs.
	 *
	 * @var array<string, int>
	 */
	private array $slugCounters = [];

	/**
	 * Parse a Cacti XML hash identifier (`hash_<type2><ver4><id32>`).
	 *
	 * @param string $tag Raw XML tag name or text value.
	 * @return ?array{type: string, typeCode: string, versionCode: string, hash: string} Parsed components or null if not a Cacti hash.
	 */
	public static function parseTag(string $tag): ?array {
		if (preg_match('/^hash_([0-9a-f]{2})([0-9a-f]{4})([0-9a-f]{32})$/iD', trim($tag), $matches) !== 1) {
			return null;
		}

		$typeCode    = strtolower($matches[1]);
		$versionCode = strtolower($matches[2]);
		$hash        = strtolower($matches[3]);
		$type        = self::typeNameForCode($typeCode);

		if ($type === null) {
			return null;
		}

		return [
			'type'        => $type,
			'typeCode'    => $typeCode,
			'versionCode' => $versionCode,
			'hash'        => $hash,
		];
	}

	/**
	 * Look up the Cacti template type name for a 2-hex-digit code.
	 *
	 * @param string $typeCode Two-digit hex type code.
	 * @return ?string Type name or null if unrecognized.
	 */
	public static function typeNameForCode(string $typeCode): ?string {
		$lower = strtolower($typeCode);

		foreach (self::TYPE_CODES as $name => $code) {
			if ($code === $lower) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Format a complete Cacti XML hash token (`hash_<type2><ver4><id32>`).
	 *
	 * @param string $type Cacti template object type name.
	 * @param string $hash32 Lowercase 32-hex identifier.
	 * @param string $versionCode 4-hex Cacti version code.
	 * @return string Formatted Cacti hash token.
	 * @throws YamlException When type, version code, or hash is invalid.
	 */
	public static function formatTag(string $type, string $hash32, string $versionCode = self::DEFAULT_VERSION_CODE): string {
		if (!isset(self::TYPE_CODES[$type])) {
			throw new YamlException(sprintf('Unknown Cacti template object type "%s".', $type));
		}

		$lowerHash = strtolower($hash32);
		$lowerVer  = strtolower($versionCode);

		if (preg_match('/^[0-9a-f]{32}$/D', $lowerHash) !== 1) {
			throw new YamlException(sprintf('Invalid 32-hex Cacti hash "%s".', $hash32));
		}

		if (preg_match('/^[0-9a-f]{4}$/D', $lowerVer) !== 1) {
			throw new YamlException(sprintf('Invalid 4-hex Cacti version code "%s".', $versionCode));
		}

		return 'hash_' . self::TYPE_CODES[$type] . $lowerVer . $lowerHash;
	}

	/**
	 * Derive a deterministic, FIPS-compliant 32-hex Cacti hash from namespace,
	 * object type, and symbolic slug using SHA-256 truncation.
	 *
	 * @param string $namespace Logical bundle or pack namespace.
	 * @param string $type Cacti template object type name.
	 * @param string $slug Symbolic slug within the type.
	 * @return string Lowercase 32-hex hash string.
	 */
	public static function deriveHash(string $namespace, string $type, string $slug): string {
		return substr(hash('sha256', sprintf('cacti.yaml/v1|%s|%s|%s', $namespace, $type, $slug)), 0, 32);
	}

	/**
	 * Convert an arbitrary human-readable display name into a deterministic slug.
	 *
	 * @param string $name Display name from XML or YAML.
	 * @param string $fallback Fallback prefix when name has no alphanumeric characters.
	 * @return string Lowercase ASCII slug.
	 */
	public static function slugify(string $name, string $fallback = 'item'): string {
		$slug = strtolower(trim($name));
		$slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
		$slug = trim($slug, '_');

		return $slug !== '' ? substr($slug, 0, 64) : $fallback;
	}

	/**
	 * Register a Cacti XML object during decompilation and assign a unique symbolic ref.
	 *
	 * @param string $type Cacti object type.
	 * @param string $versionCode 4-hex version code.
	 * @param string $hash32 32-hex object hash.
	 * @param string $hintName Human-readable name hint for slug generation.
	 * @return string Assigned symbolic reference (`<type>/<slug>`).
	 */
	public function registerDecompiled(string $type, string $versionCode, string $hash32, string $hintName): string {
		$lower = strtolower($hash32);

		if (isset($this->hashToRef[$lower])) {
			return $this->hashToRef[$lower];
		}

		$baseSlug = self::slugify($hintName, substr($lower, 0, 8));
		$baseRef  = $type . '/' . $baseSlug;

		if (!isset($this->slugCounters[$baseRef])) {
			$this->slugCounters[$baseRef] = 1;
			$ref                          = $baseRef;
		} else {
			$this->slugCounters[$baseRef]++;
			$ref = $baseRef . '_' . $this->slugCounters[$baseRef];
		}

		$this->hashToRef[$lower] = $ref;
		$this->refToMeta[$ref]   = [
			'type'        => $type,
			'typeCode'    => self::TYPE_CODES[$type] ?? '00',
			'versionCode' => strtolower($versionCode),
			'hash'        => $lower,
		];

		return $ref;
	}

	/**
	 * Register a YAML object for compilation, preserving explicit cactiHash when
	 * provided or deriving a deterministic 32-hex hash otherwise.
	 *
	 * @param string $ref Symbolic reference (`<type>/<slug>`).
	 * @param string $type Cacti template object type.
	 * @param ?string $explicitHash Optional existing 32-hex Cacti hash.
	 * @param string $versionCode 4-hex Cacti version code.
	 * @param string $namespace Deterministic derivation namespace.
	 * @return array{type: string, typeCode: string, versionCode: string, hash: string} Registered metadata.
	 * @throws YamlException When type or explicit hash is invalid.
	 */
	public function registerCompiled(
		string $ref,
		string $type,
		?string $explicitHash = null,
		string $versionCode = self::DEFAULT_VERSION_CODE,
		string $namespace = 'default',
	): array {
		if (!isset(self::TYPE_CODES[$type])) {
			throw new YamlException(sprintf('Unknown Cacti template object type "%s" for ref "%s".', $type, $ref));
		}

		$hash = $explicitHash !== null && $explicitHash !== ''
			? strtolower($explicitHash)
			: self::deriveHash($namespace, $type, $ref);

		if (preg_match('/^[0-9a-f]{32}$/D', $hash) !== 1) {
			throw new YamlException(sprintf('Invalid cactiHash "%s" for ref "%s".', $hash, $ref));
		}

		$meta = [
			'type'        => $type,
			'typeCode'    => self::TYPE_CODES[$type],
			'versionCode' => strtolower($versionCode),
			'hash'        => $hash,
		];

		$this->refToMeta[$ref]  = $meta;
		$this->hashToRef[$hash] = $ref;

		return $meta;
	}

	/**
	 * Look up the symbolic reference for a 32-hex hash, including built-in system inputs.
	 *
	 * @param string $hash32 32-hex hash.
	 * @return ?string Symbolic ref or null if not registered.
	 */
	public function refForHash(string $hash32): ?string {
		$lower = strtolower($hash32);

		if (isset($this->hashToRef[$lower])) {
			return $this->hashToRef[$lower];
		}

		return self::SYSTEM_DATA_INPUTS[$lower] ?? null;
	}

	/**
	 * Resolve a symbolic reference (`type/slug` or `system/...`) back into a full
	 * Cacti XML hash tag (`hash_<type2><ver4><id32>`).
	 *
	 * @param string $ref Symbolic reference without leading `ref:`.
	 * @param string $defaultVersionCode Fallback 4-hex version code for system refs.
	 * @return string Full Cacti XML hash token.
	 * @throws YamlException When the symbolic reference cannot be resolved.
	 */
	public function resolveRefToTag(string $ref, string $defaultVersionCode = self::DEFAULT_VERSION_CODE): string {
		if (isset($this->refToMeta[$ref])) {
			$meta = $this->refToMeta[$ref];

			return self::formatTag($meta['type'], $meta['hash'], $meta['versionCode']);
		}

		foreach (self::SYSTEM_DATA_INPUTS as $hash => $sysRef) {
			if ($sysRef === $ref) {
				return self::formatTag('data_input_method', $hash, $defaultVersionCode);
			}
		}

		throw new YamlException(sprintf('Unresolved symbolic template reference "ref:%s".', $ref));
	}
}
