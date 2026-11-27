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
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Validation\CrossParameterValidator;

/**
 * Compiles symbolic CactiTemplateBundle YAML back into canonical native
 * Cacti 1.2 `<cacti><hash_...>` XML with deterministic hash stability and
 * SHA-256 verification.
 */
final class TemplateCompiler {
	/**
	 * Compile a YAML string representing a CactiTemplateBundle into canonical
	 * Cacti `<cacti>` XML plus its deterministic SHA-256 digest.
	 *
	 * @param string $yaml Raw YAML template bundle.
	 * @return array{xml: string, sha256: string, hashes: list<string>} Compiled XML output and metadata.
	 * @throws YamlException When YAML syntax, schema, or symbolic references are invalid.
	 */
	public static function compileYaml(string $yaml): array {
		$doc = YamlParser::parseMap($yaml);

		return self::compileArray($doc);
	}

	/**
	 * Compile a decoded CactiTemplateBundle array into canonical Cacti XML.
	 *
	 * @param array<string, mixed> $doc Decoded YAML bundle mapping.
	 * @return array{xml: string, sha256: string, hashes: list<string>} Compiled XML, SHA-256, and sorted 32-hex hashes.
	 * @throws YamlException On invalid structure or dangling symbolic references.
	 */
	public static function compileArray(array $doc): array {
		$apiVersion = is_string($doc['apiVersion'] ?? null) ? $doc['apiVersion'] : '';
		$kind       = is_string($doc['kind'] ?? null) ? $doc['kind'] : '';

		if ($apiVersion !== TemplateDecompiler::API_VERSION) {
			throw new YamlException(sprintf('Unsupported template apiVersion "%s" (expected "%s").', $apiVersion, TemplateDecompiler::API_VERSION));
		}

		if ($kind !== TemplateDecompiler::KIND) {
			throw new YamlException(sprintf('Unsupported template kind "%s" (expected "%s").', $kind, TemplateDecompiler::KIND));
		}

		$invariantErrors = CrossParameterValidator::validate($doc);

		if ($invariantErrors !== []) {
			throw new YamlException('Template bundle failed invariant validation.', $invariantErrors);
		}

		$metadata = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];
		$spec     = is_array($doc['spec'] ?? null) ? $doc['spec'] : [];
		$objects  = is_array($spec['objects'] ?? null) ? $spec['objects'] : [];

		if ($objects === []) {
			throw new YamlException('CactiTemplateBundle spec.objects must contain at least one template object.');
		}

		$namespace      = is_string($metadata['name'] ?? null) && $metadata['name'] !== '' ? $metadata['name'] : 'default';
		$defaultVersion = is_string($metadata['versionCode'] ?? null) && preg_match('/^[0-9a-f]{4}$/iD', $metadata['versionCode']) === 1
			? strtolower($metadata['versionCode'])
			: HashRegistry::DEFAULT_VERSION_CODE;

		$registry = new HashRegistry();
		$hashes   = [];

		// First pass: register all top-level and nested objects so forward refs resolve cleanly.
		$validatedObjects = [];

		foreach ($objects as $idx => $obj) {
			if (!is_array($obj)) {
				throw new YamlException(sprintf('spec.objects[%s] must be a mapping.', (string) $idx));
			}

			self::registerObjectTree($obj, $registry, $defaultVersion, $namespace, $hashes);
			$validatedObjects[] = $obj;
		}

		// Second pass: emit canonical XML DOM.
		$dom                     = new \DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput       = true;
		$dom->preserveWhiteSpace = false;

		$root = $dom->createElement('cacti');
		$dom->appendChild($root);

		foreach ($validatedObjects as $obj) {
			$root->appendChild(self::buildObjectElement($dom, $obj, $registry, $defaultVersion));
		}

		$xml = $dom->saveXML();

		if (!is_string($xml) || $xml === '') {
			throw new YamlException('Failed to serialize compiled Cacti template XML.');
		}

		$hashes = array_values(array_unique($hashes));
		sort($hashes, SORT_STRING);

		return [
			'xml'    => $xml,
			'sha256' => hash('sha256', $xml),
			'hashes' => $hashes,
		];
	}

	/**
	 * Register a template object and any nested hash object lists in the HashRegistry.
	 *
	 * @param array<int|string, mixed> $obj Template object definition.
	 * @param HashRegistry $registry Shared symbol table.
	 * @param string $defaultVersion Fallback 4-hex version code.
	 * @param string $namespace Deterministic hash derivation namespace.
	 * @param list<string> $hashes Collected 32-hex hashes.
	 * @return void
	 * @throws YamlException When required ref or type fields are missing.
	 */
	private static function registerObjectTree(
		array $obj,
		HashRegistry $registry,
		string $defaultVersion,
		string $namespace,
		array &$hashes,
	): void {
		$ref  = is_string($obj['ref'] ?? null) ? trim($obj['ref']) : '';
		$type = is_string($obj['type'] ?? null) ? trim($obj['type']) : '';

		if ($ref === '' || $type === '') {
			throw new YamlException('Every template object must define non-empty "ref" and "type" strings.');
		}

		$version  = is_string($obj['versionCode'] ?? null) && $obj['versionCode'] !== '' ? $obj['versionCode'] : $defaultVersion;
		$explicit = is_string($obj['cactiHash'] ?? null) ? $obj['cactiHash'] : null;

		$meta     = $registry->registerCompiled($ref, $type, $explicit, $version, $namespace);
		$hashes[] = $meta['hash'];

		if (isset($obj['fields']) && is_array($obj['fields'])) {
			self::registerNestedFields($obj['fields'], $registry, $defaultVersion, $namespace, $hashes);
		}
	}

	/**
	 * Walk nested fields looking for lists of embedded hash_* objects.
	 *
	 * @param array<int|string, mixed> $fields Field mapping.
	 * @param HashRegistry $registry Shared symbol table.
	 * @param string $defaultVersion Fallback version code.
	 * @param string $namespace Hash namespace.
	 * @param list<string> $hashes Collected hashes.
	 * @return void
	 */
	private static function registerNestedFields(
		array $fields,
		HashRegistry $registry,
		string $defaultVersion,
		string $namespace,
		array &$hashes,
	): void {
		foreach ($fields as $value) {
			if (!is_array($value)) {
				continue;
			}

			if (array_is_list($value)) {
				foreach ($value as $item) {
					if (is_array($item) && isset($item['ref'], $item['type'])) {
						self::registerObjectTree($item, $registry, $defaultVersion, $namespace, $hashes);
					}
				}
			} else {
				self::registerNestedFields($value, $registry, $defaultVersion, $namespace, $hashes);
			}
		}
	}

	/**
	 * Build a `<hash_...>` DOMElement from a template object definition.
	 *
	 * @param \DOMDocument $dom Owning XML document.
	 * @param array<int|string, mixed> $obj Template object definition.
	 * @param HashRegistry $registry Shared symbol table.
	 * @param string $defaultVersion Default 4-hex version code.
	 * @return \DOMElement Constructed XML element.
	 * @throws YamlException On invalid element names or unresolved symbolic refs.
	 */
	private static function buildObjectElement(
		\DOMDocument $dom,
		array $obj,
		HashRegistry $registry,
		string $defaultVersion,
	): \DOMElement {
		$ref     = is_string($obj['ref'] ?? null) ? trim($obj['ref']) : '';
		$tagName = $registry->resolveRefToTag($ref, $defaultVersion);
		$el      = $dom->createElement($tagName);
		$fields  = is_array($obj['fields'] ?? null) ? $obj['fields'] : [];

		self::appendFields($dom, $el, $fields, $registry, $defaultVersion);

		return $el;
	}

	/**
	 * Append converted YAML fields onto a parent DOMElement.
	 *
	 * @param \DOMDocument $dom Owning XML document.
	 * @param \DOMElement $parent Target parent element.
	 * @param array<int|string, mixed> $fields Field map to serialize.
	 * @param HashRegistry $registry Shared symbol table.
	 * @param string $defaultVersion Default version code.
	 * @return void
	 * @throws YamlException When an XML element name is invalid.
	 */
	private static function appendFields(
		\DOMDocument $dom,
		\DOMElement $parent,
		array $fields,
		HashRegistry $registry,
		string $defaultVersion,
	): void {
		foreach ($fields as $key => $value) {
			$name = (string) $key;

			if ($name === '__hashItems' && is_array($value)) {
				foreach ($value as $subObj) {
					if (is_array($subObj)) {
						$parent->appendChild(self::buildObjectElement($dom, $subObj, $registry, $defaultVersion));
					}
				}

				continue;
			}

			if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) !== 1) {
				throw new YamlException(sprintf('Invalid XML element name "%s" in template fields.', $name));
			}

			$child = $dom->createElement($name);

			if (is_array($value)) {
				if (array_is_list($value)) {
					foreach ($value as $item) {
						if (is_array($item) && isset($item['ref'], $item['type'])) {
							$child->appendChild(self::buildObjectElement($dom, $item, $registry, $defaultVersion));
						}
					}
				} else {
					self::appendFields($dom, $child, $value, $registry, $defaultVersion);
				}
			} else {
				$text = self::resolveValueText($value, $registry, $defaultVersion);

				if ($text !== '') {
					$child->appendChild($dom->createTextNode($text));
				}
			}

			$parent->appendChild($child);
		}
	}

	/**
	 * Resolve scalar values and single/pipe-delimited `ref:...` strings into raw
	 * Cacti XML text.
	 *
	 * @param mixed $value Scalar field value.
	 * @param HashRegistry $registry Shared symbol table.
	 * @param string $defaultVersion Default version code.
	 * @return string XML text content.
	 * @throws YamlException When a symbolic reference cannot be resolved.
	 */
	private static function resolveValueText(mixed $value, HashRegistry $registry, string $defaultVersion): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value)) {
			return $value ? 'on' : '';
		}

		$str = is_scalar($value) ? (string) $value : '';

		if ($str === '') {
			return '';
		}

		if (str_contains($str, '|') && str_contains($str, 'ref:')) {
			$parts    = explode('|', $str);
			$resolved = [];

			foreach ($parts as $part) {
				if (str_starts_with($part, 'ref:')) {
					$resolved[] = $registry->resolveRefToTag(substr($part, 4), $defaultVersion);
				} else {
					$resolved[] = $part;
				}
			}

			return implode('|', $resolved);
		}

		if (str_starts_with($str, 'ref:')) {
			return $registry->resolveRefToTag(substr($str, 4), $defaultVersion);
		}

		return $str;
	}
}
