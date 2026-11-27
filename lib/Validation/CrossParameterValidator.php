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

namespace Somethingwithproof\Yaml\Validation;

use Somethingwithproof\Yaml\Merge\MergePolicy;

/**
 * Cross-parameter configuration invariant validator grounded in Yin et al.
 * (ACM SOSP 2011, "An Empirical Study on Configuration Errors in Commercial
 * and Open Source Systems").
 *
 * Single-field JSON Schema validation catches individual illegal values, while
 * this validator detects inter-parameter contradictions across SNMP security
 * levels, poller/cron intervals, device/tree references, Data Query index
 * bindings, template references, and plugin_yaml policy combinations.
 */
final class CrossParameterValidator {
	/**
	 * Supported SNMPv3 authentication protocols in Cacti 1.2.
	 *
	 * @var list<string>
	 */
	public const SNMPV3_AUTH_PROTOCOLS = ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512', '[None]'];

	/**
	 * Supported SNMPv3 privacy protocols in Cacti 1.2.
	 *
	 * @var list<string>
	 */
	public const SNMPV3_PRIV_PROTOCOLS = ['DES', 'AES', 'AES128', 'AES192', 'AES256', '[None]'];

	/**
	 * Allowed SNMP version identifiers in Cacti 1.2.
	 *
	 * @var list<int>
	 */
	public const SNMP_VERSIONS = [0, 1, 2, 3];

	/**
	 * Validate a decoded YAML document for cross-parameter consistency invariants.
	 *
	 * Dispatches checks based on `kind` (`CactiConfig`, `SnmpDataQuery`,
	 * `ScriptDataQuery`, `CactiTemplateBundle`, `MonitoringPack`) and returns a
	 * list of concrete diagnostic messages (empty when all invariants hold).
	 *
	 * @param array<string, mixed> $document Decoded YAML root mapping.
	 * @param array<string, mixed> $context Optional known catalog context (knownSites, knownPollers, knownTemplates).
	 * @return list<string> Validation error messages.
	 */
	public static function validate(array $document, array $context = []): array {
		$kind = is_string($document['kind'] ?? null) ? $document['kind'] : '';
		$spec = is_array($document['spec'] ?? null) ? $document['spec'] : [];

		$errors = [];

		if ($kind === 'SnmpDataQuery' || $kind === 'ScriptDataQuery') {
			$errors = array_merge($errors, self::validateDataQueryInvariants($kind, $spec));
		} elseif ($kind === 'CactiTemplateBundle') {
			$errors = array_merge($errors, self::validateTemplateBundleInvariants($spec));
		} elseif ($kind === 'MonitoringPack') {
			$errors = array_merge($errors, self::validateMonitoringPackInvariants($document));
		} else {
			$errors = array_merge($errors, self::validateCascConfigInvariants($spec, $context));
		}

		return array_values(array_unique($errors));
	}

	/**
	 * Validate inter-parameter invariants across a CactiConfig (CasC) spec.
	 *
	 * @param array<int|string, mixed> $spec Root `spec` mapping.
	 * @param array<string, mixed> $context Optional known Cacti catalog values.
	 * @return list<string> Validation errors.
	 */
	private static function validateCascConfigInvariants(array $spec, array $context): array {
		$errors = [];

		// 1. Settings cross-parameter consistency (poller_interval vs cron_interval).
		$settingsSpec = is_array($spec['settings'] ?? null) ? $spec['settings'] : [];
		$values       = is_array($settingsSpec['values'] ?? null) ? $settingsSpec['values'] : [];

		if (isset($values['poller_interval'], $values['cron_interval'])
			&& is_numeric($values['poller_interval'])
			&& is_numeric($values['cron_interval'])
		) {
			$poller = (int) $values['poller_interval'];
			$cron   = (int) $values['cron_interval'];

			if ($poller <= 0 || $cron <= 0) {
				$errors[] = 'spec.settings.values: poller_interval and cron_interval must both be positive integers.';
			} elseif ($poller > $cron || ($cron % $poller) !== 0) {
				$errors[] = sprintf(
					'spec.settings.values: poller_interval (%d) must divide cron_interval (%d) evenly and not exceed it.',
					$poller,
					$cron
				);
			}
		}

		// Collect declared sites, pollers, and templates for referential checks.
		$declaredSites     = self::extractKnownNames($spec['sites'] ?? [], 'name', $context['knownSites'] ?? []);
		$declaredPollers   = self::extractKnownNames($spec['pollers'] ?? [], 'name', $context['knownPollers'] ?? ['Main Poller']);
		$declaredTemplates = self::extractKnownNames($spec['templates'] ?? [], 'name', $context['knownTemplates'] ?? []);
		$declaredHosts     = [];

		// 2. Device SNMPv1/v2/v3 and site/poller/template consistency.
		if (isset($spec['devices']) && is_array($spec['devices'])) {
			foreach ($spec['devices'] as $idx => $device) {
				if (!is_array($device)) {
					continue;
				}

				$prefix = sprintf('spec.devices[%s]', (string) $idx);

				if (is_string($device['hostname'] ?? null) && $device['hostname'] !== '') {
					$declaredHosts[] = $device['hostname'];
				}

				$errors = array_merge($errors, self::validateDeviceSnmp($device, $prefix));

				if (is_string($device['site'] ?? null) && $device['site'] !== '' && $declaredSites !== [] && !in_array($device['site'], $declaredSites, true)) {
					$errors[] = sprintf('%s.site: references undeclared site "%s".', $prefix, $device['site']);
				}

				if (is_string($device['poller'] ?? null) && $device['poller'] !== '' && $declaredPollers !== [] && !in_array($device['poller'], $declaredPollers, true)) {
					$errors[] = sprintf('%s.poller: references undeclared poller "%s".', $prefix, $device['poller']);
				}

				if (is_string($device['template'] ?? null) && $device['template'] !== '' && $declaredTemplates !== [] && !in_array($device['template'], $declaredTemplates, true)) {
					$errors[] = sprintf('%s.template: references undeclared template "%s".', $prefix, $device['template']);
				}
			}
		}

		// 3. Graph tree item references to sites and devices.
		if (isset($spec['trees']) && is_array($spec['trees'])) {
			foreach ($spec['trees'] as $idx => $tree) {
				if (!is_array($tree) || !is_array($tree['items'] ?? null)) {
					continue;
				}

				self::validateTreeItems(
					$tree['items'],
					sprintf('spec.trees[%s].items', (string) $idx),
					$declaredSites,
					$declaredHosts,
					$errors
				);
			}
		}

		// 4. Plugin-specific yaml provider config invariants.
		$plugins = is_array($spec['plugins'] ?? null) ? $spec['plugins'] : [];

		if (isset($plugins['yaml']) && is_array($plugins['yaml'])) {
			$yamlCfg = $plugins['yaml'];

			if (isset($yamlCfg['mergePolicy']) && is_string($yamlCfg['mergePolicy'])) {
				if (MergePolicy::tryFrom($yamlCfg['mergePolicy']) === null) {
					$errors[] = sprintf('spec.plugins.yaml.mergePolicy: unsupported policy "%s".', $yamlCfg['mergePolicy']);
				}
			}

			if (isset($yamlCfg['packs']) && is_array($yamlCfg['packs'])) {
				$seenPacks = [];

				foreach ($yamlCfg['packs'] as $pIdx => $pack) {
					if (!is_array($pack)) {
						continue;
					}

					$packName = is_string($pack['name'] ?? null) ? $pack['name'] : '';

					if ($packName !== '' && in_array($packName, $seenPacks, true)) {
						$errors[] = sprintf('spec.plugins.yaml.packs[%s]: duplicate pack name "%s".', (string) $pIdx, $packName);
					}

					if ($packName !== '') {
						$seenPacks[] = $packName;
					}

					if (isset($pack['lockSha256']) && is_string($pack['lockSha256']) && $pack['lockSha256'] !== '') {
						if (!isset($pack['sourcePath']) || !is_string($pack['sourcePath']) || trim($pack['sourcePath']) === '') {
							$errors[] = sprintf('spec.plugins.yaml.packs[%s]: lockSha256 requires a non-empty sourcePath.', (string) $pIdx);
						}
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * Validate SNMP version, community, and SNMPv3 auth/priv parameter dependencies.
	 *
	 * @param array<int|string, mixed> $device Device mapping.
	 * @param string $prefix Diagnostic field path prefix.
	 * @return list<string> Validation errors.
	 */
	private static function validateDeviceSnmp(array $device, string $prefix): array {
		$errors = [];

		if (!array_key_exists('snmpVersion', $device) && !array_key_exists('snmp', $device)) {
			return [];
		}

		$snmp = is_array($device['snmp'] ?? null) ? $device['snmp'] : $device;

		$rawVersion = $snmp['version'] ?? $snmp['snmpVersion'] ?? null;

		if ($rawVersion === null) {
			return [];
		}

		if (!is_int($rawVersion) || !in_array($rawVersion, self::SNMP_VERSIONS, true)) {
			return [sprintf('%s: snmpVersion must be one of 0, 1, 2, or 3.', $prefix)];
		}

		$community = is_string($snmp['community'] ?? null) ? trim($snmp['community']) : '';
		$username  = is_string($snmp['username'] ?? $snmp['snmpUsername'] ?? null) ? trim((string) ($snmp['username'] ?? $snmp['snmpUsername'])) : '';
		$authProto = is_string($snmp['authProtocol'] ?? $snmp['snmpAuthProtocol'] ?? null) ? trim((string) ($snmp['authProtocol'] ?? $snmp['snmpAuthProtocol'])) : '[None]';
		$authPass  = is_string($snmp['authPassword'] ?? $snmp['snmpPassword'] ?? null) ? trim((string) ($snmp['authPassword'] ?? $snmp['snmpPassword'])) : '';
		$privProto = is_string($snmp['privProtocol'] ?? $snmp['snmpPrivProtocol'] ?? null) ? trim((string) ($snmp['privProtocol'] ?? $snmp['snmpPrivProtocol'])) : '[None]';
		$privPass  = is_string($snmp['privPassphrase'] ?? $snmp['snmpPrivPassphrase'] ?? null) ? trim((string) ($snmp['privPassphrase'] ?? $snmp['snmpPrivPassphrase'])) : '';

		if ($rawVersion === 1 || $rawVersion === 2) {
			if ($community === '') {
				$errors[] = sprintf('%s: SNMP v%d requires a non-empty community string.', $prefix, $rawVersion);
			}

			if ($authPass !== '' || $privPass !== '' || $username !== '') {
				$errors[] = sprintf('%s: SNMP v%d must not combine SNMPv3 credentials (username/authPassword/privPassphrase).', $prefix, $rawVersion);
			}

			return $errors;
		}

		if ($rawVersion === 3) {
			if ($username === '') {
				$errors[] = sprintf('%s: SNMPv3 requires a non-empty username.', $prefix);
			}

			if (!in_array($authProto, self::SNMPV3_AUTH_PROTOCOLS, true)) {
				$errors[] = sprintf('%s: invalid SNMPv3 authProtocol "%s".', $prefix, $authProto);
			}

			if (!in_array($privProto, self::SNMPV3_PRIV_PROTOCOLS, true)) {
				$errors[] = sprintf('%s: invalid SNMPv3 privProtocol "%s".', $prefix, $privProto);
			}

			$hasAuth = $authProto !== '' && $authProto !== '[None]';
			$hasPriv = $privProto !== '' && $privProto !== '[None]';

			if ($hasAuth && $authPass === '') {
				$errors[] = sprintf('%s: SNMPv3 authProtocol "%s" requires a non-empty authPassword.', $prefix, $authProto);
			}

			if (!$hasAuth && $authPass !== '') {
				$errors[] = sprintf('%s: SNMPv3 authPassword is set while authProtocol is [None].', $prefix);
			}

			if ($hasPriv && !$hasAuth) {
				$errors[] = sprintf('%s: SNMPv3 privProtocol "%s" cannot be enabled without an authProtocol (authPriv requires auth).', $prefix, $privProto);
			}

			if ($hasPriv && $privPass === '') {
				$errors[] = sprintf('%s: SNMPv3 privProtocol "%s" requires a non-empty privPassphrase.', $prefix, $privProto);
			}

			if (!$hasPriv && $privPass !== '') {
				$errors[] = sprintf('%s: SNMPv3 privPassphrase is set while privProtocol is [None].', $prefix);
			}

			if ($community !== '') {
				$errors[] = sprintf('%s: SNMPv3 must not declare a v1/v2 community string.', $prefix);
			}
		}

		return $errors;
	}

	/**
	 * Validate Graph Tree item cross-references against declared sites and hosts.
	 *
	 * @param array<int|string, mixed> $items Tree items array.
	 * @param string $prefix Path prefix.
	 * @param list<string> $knownSites Declared/known site names.
	 * @param list<string> $knownHosts Declared device hostnames.
	 * @param list<string> $errors Error accumulator.
	 * @return void
	 */
	private static function validateTreeItems(
		array $items,
		string $prefix,
		array $knownSites,
		array $knownHosts,
		array &$errors,
	): void {
		foreach ($items as $idx => $item) {
			if (!is_array($item)) {
				continue;
			}

			$itemPath = $prefix . '[' . (string) $idx . ']';

			if (is_string($item['site'] ?? null) && $item['site'] !== '' && $knownSites !== [] && !in_array($item['site'], $knownSites, true)) {
				$errors[] = sprintf('%s.site: references undeclared site "%s".', $itemPath, $item['site']);
			}

			if (is_string($item['device'] ?? null) && $item['device'] !== '' && $knownHosts !== [] && !in_array($item['device'], $knownHosts, true)) {
				$errors[] = sprintf('%s.device: references undeclared device "%s".', $itemPath, $item['device']);
			}

			if (isset($item['children']) && is_array($item['children'])) {
				self::validateTreeItems($item['children'], $itemPath . '.children', $knownSites, $knownHosts, $errors);
			}
		}
	}

	/**
	 * Validate cross-parameter invariants for SNMP and Script Data Query specs.
	 *
	 * @param string $kind Document kind (`SnmpDataQuery` or `ScriptDataQuery`).
	 * @param array<int|string, mixed> $spec Data query `spec` mapping.
	 * @return list<string> Validation errors.
	 */
	private static function validateDataQueryInvariants(string $kind, array $spec): array {
		$errors = [];
		$fields = is_array($spec['fields'] ?? null) ? $spec['fields'] : [];

		$inputFields = [];
		$allFields   = [];

		foreach ($fields as $fieldKey => $fieldDef) {
			if (!is_array($fieldDef)) {
				continue;
			}

			$name        = (string) $fieldKey;
			$allFields[] = $name;
			$direction   = is_string($fieldDef['direction'] ?? null) ? $fieldDef['direction'] : '';

			if ($direction === 'input' || $direction === 'input-output') {
				$inputFields[] = $name;
			}

			if ($kind === 'SnmpDataQuery' && isset($fieldDef['queryName'])) {
				$errors[] = sprintf('spec.fields.%s: SnmpDataQuery field must not define script queryName.', $name);
			}

			if ($kind === 'ScriptDataQuery' && isset($fieldDef['oid'])) {
				$errors[] = sprintf('spec.fields.%s: ScriptDataQuery field must not define SNMP oid.', $name);
			}
		}

		if ($inputFields === [] && $allFields !== []) {
			$errors[] = 'spec.fields: at least one field must have direction "input" or "input-output" for index resolution.';
		}

		if (isset($spec['indexOrder']) && is_array($spec['indexOrder'])) {
			foreach ($spec['indexOrder'] as $orderField) {
				if (!is_string($orderField)) {
					continue;
				}

				if (!in_array($orderField, $allFields, true)) {
					$errors[] = sprintf('spec.indexOrder: references undefined field "%s".', $orderField);
				} elseif (!in_array($orderField, $inputFields, true)) {
					$errors[] = sprintf('spec.indexOrder: field "%s" must have direction "input" or "input-output" (not output-only).', $orderField);
				}
			}
		}

		if ($kind === 'SnmpDataQuery' && isset($spec['oidIndexParse']) && is_string($spec['oidIndexParse']) && !isset($spec['oidIndex'])) {
			$errors[] = 'spec.oidIndexParse: requires spec.oidIndex to be defined.';
		}

		if ($kind === 'ScriptDataQuery') {
			if (isset($spec['argQuery']) && (!isset($spec['outputDelimiter']) || !is_string($spec['outputDelimiter']) || $spec['outputDelimiter'] === '')) {
				$errors[] = 'spec.argQuery: requires a non-empty spec.outputDelimiter.';
			}
		}

		return $errors;
	}

	/**
	 * Validate symbolic reference consistency inside a CactiTemplateBundle.
	 *
	 * @param array<int|string, mixed> $spec Bundle `spec` mapping.
	 * @return list<string> Validation errors.
	 */
	private static function validateTemplateBundleInvariants(array $spec): array {
		$errors  = [];
		$objects = is_array($spec['objects'] ?? null) ? $spec['objects'] : [];

		$definedRefs = [];
		$usedRefs    = [];

		foreach ($objects as $obj) {
			if (!is_array($obj)) {
				continue;
			}

			self::collectDefinedRefs($obj, $definedRefs, $errors);
			self::collectRefStrings($obj, $usedRefs);
		}

		foreach ($usedRefs as $refString) {
			$target = substr($refString, 4);

			if (!in_array($target, $definedRefs, true) && !str_starts_with($target, 'system/')) {
				$errors[] = sprintf('spec.objects: dangling symbolic reference "ref:%s" not defined in bundle.', $target);
			}
		}

		return $errors;
	}

	/**
	 * Recursively collect all defined `ref` identifiers from top-level and nested
	 * template objects while detecting duplicates.
	 *
	 * @param array<int|string, mixed> $node Template object or nested field container.
	 * @param list<string> $definedRefs Accumulated defined refs.
	 * @param list<string> $errors Duplicate error accumulator.
	 * @return void
	 */
	private static function collectDefinedRefs(array $node, array &$definedRefs, array &$errors): void {
		if (isset($node['ref'], $node['type']) && is_string($node['ref']) && $node['ref'] !== '') {
			if (in_array($node['ref'], $definedRefs, true)) {
				$errors[] = sprintf('spec.objects: duplicate symbolic ref "%s".', $node['ref']);
			}

			$definedRefs[] = $node['ref'];
		}

		foreach ($node as $key => $value) {
			if ($key === 'ref' || !is_array($value)) {
				continue;
			}

			self::collectDefinedRefs($value, $definedRefs, $errors);
		}
	}

	/**
	 * Validate cross-parameter consistency inside a MonitoringPack manifest.
	 *
	 * @param array<string, mixed> $document Decoded MonitoringPack manifest.
	 * @return list<string> Validation errors.
	 */
	private static function validateMonitoringPackInvariants(array $document): array {
		$errors   = [];
		$metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];
		$spec     = is_array($document['spec'] ?? null) ? $document['spec'] : [];

		$templates   = is_array($spec['templates'] ?? null) ? $spec['templates'] : [];
		$dataQueries = is_array($spec['dataQueries'] ?? null) ? $spec['dataQueries'] : [];
		$fragments   = is_array($spec['cascFragments'] ?? null) ? $spec['cascFragments'] : [];

		if ($templates === [] && $dataQueries === [] && $fragments === []) {
			$errors[] = 'spec: MonitoringPack must declare at least one template, dataQuery, or cascFragment.';
		}

		$compat = is_string($metadata['cactiCompat'] ?? null) ? trim($metadata['cactiCompat']) : '';

		if ($compat !== '' && version_compare($compat, '1.2.0', '<')) {
			$errors[] = sprintf('metadata.cactiCompat: "%s" is below minimum supported Cacti 1.2.0.', $compat);
		}

		return $errors;
	}

	/**
	 * Collect all `"ref:<type>/<slug>"` string values recursively from a template object.
	 *
	 * @param mixed $node Arbitrary template node.
	 * @param list<string> $refs Collected reference strings.
	 * @return void
	 */
	private static function collectRefStrings(mixed $node, array &$refs): void {
		if (is_array($node)) {
			foreach ($node as $key => $value) {
				if ($key === 'ref') {
					continue;
				}

				self::collectRefStrings($value, $refs);
			}

			return;
		}

		if (is_string($node) && str_starts_with($node, 'ref:')) {
			$refs[] = $node;
		}
	}

	/**
	 * Combine declared resource names from a spec list with optional live catalog names.
	 *
	 * @param mixed $list Spec resource list.
	 * @param string $field Field name containing the natural identifier.
	 * @param mixed $extra Optional list of pre-existing catalog names.
	 * @return list<string> Unique known names.
	 */
	private static function extractKnownNames(mixed $list, string $field, mixed $extra): array {
		$names = [];

		if (is_array($extra)) {
			foreach ($extra as $item) {
				if (is_string($item) && $item !== '') {
					$names[] = $item;
				}
			}
		}

		if (is_array($list)) {
			foreach ($list as $entry) {
				if (is_array($entry) && is_string($entry[$field] ?? null) && $entry[$field] !== '') {
					$names[] = $entry[$field];
				}
			}
		}

		return array_values(array_unique($names));
	}
}
