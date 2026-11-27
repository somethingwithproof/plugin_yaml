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

use Somethingwithproof\Yaml\Codec\YamlEmitter;
use Somethingwithproof\Yaml\Codec\YamlException;

/**
 * Losslessly decompiles native Cacti 1.2 `<cacti><hash_...>` template XML
 * (Host, Graph, Data, and Data Query templates plus dependencies) into clean
 * symbolic YAML while rejecting DTD/ENTITY (XXE) declarations.
 */
final class TemplateDecompiler {
	/**
	 * API version emitted in decompiled CactiTemplateBundle YAML documents.
	 */
	public const API_VERSION = 'cacti.yaml/v1';

	/**
	 * Kind emitted in decompiled template YAML documents.
	 */
	public const KIND = 'CactiTemplateBundle';

	/**
	 * Maximum accepted XML size in bytes (16 MiB).
	 */
	public const MAX_XML_BYTES = 16777216;

	/**
	 * Decompile native Cacti template XML into a structured PHP array ready for
	 * YAML emission or inspection.
	 *
	 * @param string $xml Raw native Cacti `<cacti>` template XML.
	 * @param string $bundleName Optional metadata.name for the bundle.
	 * @return array<string, mixed> Symbolic CactiTemplateBundle structure.
	 * @throws YamlException On XXE/DTD payloads, invalid XML, or missing `<cacti>` root.
	 */
	public static function decompileToArray(string $xml, string $bundleName = ''): array {
		if (strlen($xml) > self::MAX_XML_BYTES) {
			throw new YamlException(sprintf('Template XML exceeds maximum size of %d bytes.', self::MAX_XML_BYTES));
		}

		if (preg_match('/<!(DOCTYPE|ENTITY)\b/i', $xml) === 1) {
			throw new YamlException('Template XML must not contain DOCTYPE or ENTITY declarations.');
		}

		$prev = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$dom = new \DOMDocument('1.0', 'UTF-8');
		$ok  = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

		$xmlErrors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if (!$ok || $dom->documentElement === null) {
			$first = $xmlErrors !== [] ? trim($xmlErrors[0]->message) : 'malformed XML';

			throw new YamlException(sprintf('Unable to parse Cacti template XML: %s', $first));
		}

		$root = $dom->documentElement;

		if ($root->nodeName !== 'cacti') {
			throw new YamlException(sprintf('Expected <cacti> root element, found <%s>.', $root->nodeName));
		}

		$registry = new HashRegistry();
		$nodes    = [];

		// First pass: register every top-level and nested hash_<type><ver><id> object.
		foreach (self::elementChildren($root) as $child) {
			$parsed = HashRegistry::parseTag($child->nodeName);

			if ($parsed === null) {
				throw new YamlException(sprintf('Unexpected top-level XML element <%s> inside <cacti>.', $child->nodeName));
			}

			$hint = self::extractDisplayName($child);
			$ref  = $registry->registerDecompiled($parsed['type'], $parsed['versionCode'], $parsed['hash'], $hint);
			self::registerNestedHashes($child, $registry);

			$nodes[] = [
				'element' => $child,
				'meta'    => $parsed,
				'ref'     => $ref,
				'name'    => $hint,
			];
		}

		if ($nodes === []) {
			throw new YamlException('Template XML <cacti> root contains no hash_* template objects.');
		}

		// Second pass: convert element subtrees, rewriting hash cross-references to symbolic refs.
		$objects = [];

		foreach ($nodes as $entry) {
			$fields    = self::convertChildren($entry['element'], $registry);
			$objects[] = [
				'ref'         => $entry['ref'],
				'type'        => $entry['meta']['type'],
				'versionCode' => $entry['meta']['versionCode'],
				'cactiHash'   => $entry['meta']['hash'],
				'fields'      => $fields,
			];
		}

		$resolvedName = $bundleName !== ''
			? $bundleName
			: HashRegistry::slugify($nodes[0]['name'] !== '' ? $nodes[0]['name'] : $nodes[0]['ref'], 'cacti_template');

		return [
			'apiVersion' => self::API_VERSION,
			'kind'       => self::KIND,
			'metadata'   => [
				'name'        => $resolvedName,
				'versionCode' => $nodes[0]['meta']['versionCode'],
			],
			'spec' => [
				'objects' => $objects,
			],
		];
	}

	/**
	 * Decompile native Cacti template XML directly into canonical YAML 1.2 text.
	 *
	 * @param string $xml Raw native Cacti `<cacti>` template XML.
	 * @param string $bundleName Optional metadata.name.
	 * @return string Canonical YAML 1.2 representation.
	 * @throws YamlException When XML parsing or validation fails.
	 */
	public static function decompile(string $xml, string $bundleName = ''): string {
		return YamlEmitter::emit(self::decompileToArray($xml, $bundleName), false);
	}

	/**
	 * Register nested hash_* elements (such as graph_template_item, data_template_item,
	 * graph_template_input, cdef_item, data_query_graph) into the registry before
	 * converting references.
	 *
	 * @param \DOMElement $element Parent DOM element.
	 * @param HashRegistry $registry Shared registry.
	 * @return void
	 */
	private static function registerNestedHashes(\DOMElement $element, HashRegistry $registry): void {
		foreach (self::elementChildren($element) as $child) {
			$parsed = HashRegistry::parseTag($child->nodeName);

			if ($parsed !== null) {
				$hint = self::extractDisplayName($child);
				$registry->registerDecompiled($parsed['type'], $parsed['versionCode'], $parsed['hash'], $hint);
			}

			self::registerNestedHashes($child, $registry);
		}
	}

	/**
	 * Convert child elements of a DOM node into a YAML-friendly structure,
	 * preserving nested hash_* objects as structured entries and rewriting
	 * inline hash_... references to `ref:<type>/<slug>`.
	 *
	 * @param \DOMElement $element Parent DOM element.
	 * @param HashRegistry $registry Shared symbol table.
	 * @return array<string, mixed> Converted field mapping.
	 */
	private static function convertChildren(\DOMElement $element, HashRegistry $registry): array {
		$result = [];

		foreach (self::elementChildren($element) as $child) {
			$children = self::elementChildren($child);
			$parsed   = HashRegistry::parseTag($child->nodeName);

			if ($parsed !== null) {
				$ref      = $registry->refForHash($parsed['hash']) ?? ($parsed['type'] . '/' . substr($parsed['hash'], 0, 8));
				$subEntry = [
					'ref'         => $ref,
					'type'        => $parsed['type'],
					'versionCode' => $parsed['versionCode'],
					'cactiHash'   => $parsed['hash'],
					'fields'      => self::convertChildren($child, $registry),
				];

				if (!isset($result['__hashItems']) || !is_array($result['__hashItems'])) {
					$result['__hashItems'] = [];
				}

				$result['__hashItems'][] = $subEntry;

				continue;
			}

			$key = $child->nodeName;

			if ($children !== []) {
				$converted = self::convertChildren($child, $registry);

				if (count($converted) === 1 && isset($converted['__hashItems']) && is_array($converted['__hashItems'])) {
					$result[$key] = $converted['__hashItems'];
				} else {
					$result[$key] = $converted;
				}
			} else {
				$result[$key] = self::rewriteHashText($child->textContent, $registry);
			}
		}

		return $result;
	}

	/**
	 * Rewrite single or pipe-delimited Cacti `hash_...` reference values into
	 * symbolic `ref:<type>/<slug>` strings.
	 *
	 * @param string $rawText Text content of an XML leaf element.
	 * @param HashRegistry $registry Shared symbol table.
	 * @return string Symbolic reference string or original literal text.
	 */
	private static function rewriteHashText(string $rawText, HashRegistry $registry): string {
		$trimmed = trim($rawText);

		if ($trimmed === '') {
			return $rawText;
		}

		// Handle pipe-delimited hash lists (e.g. host_template <graph_templates> or <data_queries>).
		if (str_contains($trimmed, '|')) {
			$parts     = explode('|', $trimmed);
			$allHashes = true;
			$rewritten = [];

			foreach ($parts as $part) {
				$parsed = HashRegistry::parseTag($part);

				if ($parsed === null) {
					$allHashes = false;

					break;
				}

				$ref         = $registry->refForHash($parsed['hash']);
				$rewritten[] = $ref !== null ? 'ref:' . $ref : $part;
			}

			if ($allHashes) {
				return implode('|', $rewritten);
			}
		}

		$parsed = HashRegistry::parseTag($trimmed);

		if ($parsed !== null) {
			$ref = $registry->refForHash($parsed['hash']);

			if ($ref !== null) {
				return 'ref:' . $ref;
			}
		}

		return $rawText;
	}

	/**
	 * Extract a human-readable hint name from a template XML element for slugging.
	 *
	 * @param \DOMElement $element Template DOM element.
	 * @return string Hint name or empty string.
	 */
	private static function extractDisplayName(\DOMElement $element): string {
		foreach (['name', 'data_source_name', 'text_format', 'field_name', 'sequence'] as $candidate) {
			foreach (self::elementChildren($element) as $child) {
				if ($child->nodeName === $candidate) {
					$val = trim($child->textContent);

					if ($val !== '') {
						return $val;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Return direct child DOMElement nodes of a DOMElement.
	 *
	 * @param \DOMElement $element Parent element.
	 * @return list<\DOMElement> Direct element children.
	 */
	private static function elementChildren(\DOMElement $element): array {
		$out = [];

		foreach ($element->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$out[] = $child;
			}
		}

		return $out;
	}
}
