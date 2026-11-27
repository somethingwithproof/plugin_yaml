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

namespace Somethingwithproof\Yaml\Query;

use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Security\PathGuard;
use Somethingwithproof\Yaml\Validation\CrossParameterValidator;

/**
 * Validates SnmpDataQuery and ScriptDataQuery YAML definitions before
 * compilation to Cacti resource XML.
 */
final class DataQueryValidator {
	/**
	 * Expected apiVersion for Data Query YAML documents.
	 */
	public const API_VERSION = 'cacti.yaml/v1';

	/**
	 * Supported Data Query YAML kinds.
	 *
	 * @var list<string>
	 */
	public const KINDS = ['SnmpDataQuery', 'ScriptDataQuery'];

	/**
	 * Allowed SNMP query retrieval methods.
	 *
	 * @var list<string>
	 */
	public const METHODS = ['walk', 'get'];

	/**
	 * Allowed Cacti Data Query field directions.
	 *
	 * @var list<string>
	 */
	public const DIRECTIONS = ['input', 'output', 'input-output'];

	/**
	 * Allowed Cacti Data Query index_order_type values.
	 *
	 * @var list<string>
	 */
	public const INDEX_ORDER_TYPES = ['numeric', 'alphabetic', 'natural'];

	/**
	 * Regex validating numeric dotted SNMP OID syntax.
	 */
	private const OID_PATTERN = '/^\.[0-9]+(?:\.[0-9]+)+$/D';

	/**
	 * Validate a decoded Data Query YAML mapping and return a list of errors.
	 *
	 * @param array<string, mixed> $doc Decoded YAML document.
	 * @return list<string> Validation errors (empty when valid).
	 */
	public static function validate(array $doc): array {
		$errors     = [];
		$apiVersion = is_string($doc['apiVersion'] ?? null) ? $doc['apiVersion'] : '';
		$kind       = is_string($doc['kind'] ?? null) ? $doc['kind'] : '';
		$metadata   = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];
		$spec       = is_array($doc['spec'] ?? null) ? $doc['spec'] : [];

		if ($apiVersion !== self::API_VERSION) {
			$errors[] = sprintf('apiVersion must be "%s".', self::API_VERSION);
		}

		if (!in_array($kind, self::KINDS, true)) {
			$errors[] = sprintf('kind must be one of: %s.', implode(', ', self::KINDS));
		}

		$name = is_string($metadata['name'] ?? null) ? trim($metadata['name']) : '';

		if ($name === '' || strlen($name) > 128) {
			$errors[] = 'metadata.name must be a non-empty string up to 128 bytes.';
		}

		$outputFile = is_string($metadata['outputFile'] ?? null) ? trim($metadata['outputFile']) : '';

		if ($outputFile === '') {
			$errors[] = 'metadata.outputFile is required.';
		} else {
			try {
				PathGuard::validateQueryOutputPath($outputFile);
			} catch (YamlException $e) {
				$errors[] = $e->getMessage();
			}
		}

		if ($kind === 'SnmpDataQuery') {
			self::validateSnmpSpec($spec, $errors);
		} elseif ($kind === 'ScriptDataQuery') {
			self::validateScriptSpec($spec, $errors);
		}

		$indexOrderType = is_string($spec['indexOrderType'] ?? null) ? trim($spec['indexOrderType']) : '';

		if ($indexOrderType !== '' && !in_array($indexOrderType, self::INDEX_ORDER_TYPES, true)) {
			$errors[] = sprintf('spec.indexOrderType must be one of: %s.', implode(', ', self::INDEX_ORDER_TYPES));
		}

		$fields = is_array($spec['fields'] ?? null) ? $spec['fields'] : [];

		if ($fields === [] || array_is_list($fields)) {
			$errors[] = 'spec.fields must be a non-empty mapping of field identifiers.';
		} else {
			$seenLower = [];

			foreach ($fields as $fieldId => $fieldDef) {
				$fieldName = (string) $fieldId;

				if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $fieldName) !== 1) {
					$errors[] = sprintf('spec.fields.%s: invalid XML field identifier.', $fieldName);
				}

				$lowerId = strtolower($fieldName);

				if (isset($seenLower[$lowerId])) {
					$errors[] = sprintf('spec.fields.%s: duplicate case-insensitive field identifier.', $fieldName);
				}

				$seenLower[$lowerId] = true;

				if (!is_array($fieldDef)) {
					$errors[] = sprintf('spec.fields.%s: field definition must be a mapping.', $fieldName);

					continue;
				}

				self::validateField($kind, $fieldName, $fieldDef, $errors);
			}
		}

		$errors = array_merge($errors, CrossParameterValidator::validate($doc));

		return array_values(array_unique($errors));
	}

	/**
	 * Validate SNMP-specific top-level Data Query spec fields.
	 *
	 * @param array<int|string, mixed> $spec Data query spec.
	 * @param list<string> $errors Error accumulator.
	 * @return void
	 */
	private static function validateSnmpSpec(array $spec, array &$errors): void {
		$oidIndex = is_string($spec['oidIndex'] ?? null) ? trim($spec['oidIndex']) : '';

		if ($oidIndex === '' || preg_match(self::OID_PATTERN, $oidIndex) !== 1) {
			$errors[] = sprintf('spec.oidIndex "%s" must be a valid dotted numeric OID (e.g. .1.3.6.1.2.1.2.2.1.1).', $oidIndex);
		}

		if (isset($spec['oidNumIndexes'])) {
			$numOid = is_string($spec['oidNumIndexes']) ? trim($spec['oidNumIndexes']) : '';

			if (preg_match(self::OID_PATTERN, $numOid) !== 1) {
				$errors[] = sprintf('spec.oidNumIndexes "%s" must be a valid dotted numeric OID.', $numOid);
			}
		}

		if (isset($spec['oidIndexParse'])) {
			$parseExpr = is_string($spec['oidIndexParse']) ? trim($spec['oidIndexParse']) : '';
			self::validateRegexExpression($parseExpr, 'spec.oidIndexParse', $errors);
		}
	}

	/**
	 * Validate Script-specific top-level Data Query spec fields.
	 *
	 * @param array<int|string, mixed> $spec Data query spec.
	 * @param list<string> $errors Error accumulator.
	 * @return void
	 */
	private static function validateScriptSpec(array $spec, array &$errors): void {
		$scriptPath = is_string($spec['scriptPath'] ?? null) ? trim($spec['scriptPath']) : '';

		if ($scriptPath === '') {
			$errors[] = 'spec.scriptPath is required for ScriptDataQuery.';
		} elseif (preg_match('/[;&|`$<>]/', $scriptPath) === 1 && !str_contains($scriptPath, '|path_cacti|') && !str_contains($scriptPath, '|path_php_binary|')) {
			$errors[] = 'spec.scriptPath contains disallowed shell metacharacters.';
		}

		if (isset($spec['argIndex']) && (!is_string($spec['argIndex']) || trim($spec['argIndex']) === '')) {
			$errors[] = 'spec.argIndex must be a non-empty string when set.';
		}
	}

	/**
	 * Validate an individual SNMP or Script Data Query field entry.
	 *
	 * @param string $kind Data query kind.
	 * @param string $fieldName Field XML tag name.
	 * @param array<int|string, mixed> $def Field definition mapping.
	 * @param list<string> $errors Error accumulator.
	 * @return void
	 */
	private static function validateField(string $kind, string $fieldName, array $def, array &$errors): void {
		$prefix = 'spec.fields.' . $fieldName;

		if (!is_string($def['name'] ?? null) || trim($def['name']) === '') {
			$errors[] = $prefix . '.name must be a non-empty string.';
		}

		$direction = is_string($def['direction'] ?? null) ? trim($def['direction']) : '';

		if (!in_array($direction, self::DIRECTIONS, true)) {
			$errors[] = sprintf('%s.direction must be one of: %s.', $prefix, implode(', ', self::DIRECTIONS));
		}

		if ($kind === 'SnmpDataQuery') {
			$method = is_string($def['method'] ?? null) ? trim($def['method']) : '';

			if (!in_array($method, self::METHODS, true)) {
				$errors[] = sprintf('%s.method must be one of: %s.', $prefix, implode(', ', self::METHODS));
			}

			$oid = is_string($def['oid'] ?? null) ? trim($def['oid']) : '';

			if (preg_match(self::OID_PATTERN, $oid) !== 1) {
				$errors[] = sprintf('%s.oid "%s" must be a valid dotted numeric OID.', $prefix, $oid);
			}

			$source = is_string($def['source'] ?? null) ? trim($def['source']) : '';

			if ($source === '') {
				$errors[] = $prefix . '.source is required for SnmpDataQuery fields.';
			} elseif (preg_match('/^(VALUE|OID)\/REGEXP:/i', $source) === 1) {
				self::validateRegexExpression($source, $prefix . '.source', $errors);
			}
		} elseif ($kind === 'ScriptDataQuery') {
			$queryName = is_string($def['queryName'] ?? null) ? trim($def['queryName']) : '';

			if ($queryName === '') {
				$errors[] = $prefix . '.queryName is required for ScriptDataQuery fields.';
			}
		}
	}

	/**
	 * Validate a Cacti `OID/REGEXP:<pattern>` or `VALUE/REGEXP:<pattern>` expression.
	 *
	 * @param string $expression Raw Cacti regex directive.
	 * @param string $fieldPath Diagnostic field path.
	 * @param list<string> $errors Error accumulator.
	 * @return void
	 */
	private static function validateRegexExpression(string $expression, string $fieldPath, array &$errors): void {
		if (preg_match('/^(?:OID|VALUE)\/REGEXP:(.+)$/i', $expression, $matches) !== 1) {
			$errors[] = sprintf('%s: regex expression must start with OID/REGEXP: or VALUE/REGEXP:.', $fieldPath);

			return;
		}

		$pattern = $matches[1];
		$delim   = '/';
		$escaped = str_replace($delim, '\\' . $delim, $pattern);

		if (@preg_match($delim . $escaped . $delim, '') === false) {
			$errors[] = sprintf('%s: invalid regular expression "%s".', $fieldPath, $pattern);
		}
	}
}
