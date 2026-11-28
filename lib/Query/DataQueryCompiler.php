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

use Somethingwithproof\Yaml\Codec\YamlEmitter;
use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Security\PathGuard;

/**
 * Bidirectional compiler between SnmpDataQuery / ScriptDataQuery YAML and
 * strict Cacti `resource/snmp_queries/*.xml` and `resource/script_queries/*.xml`.
 */
final class DataQueryCompiler {
	/**
	 * Compile a Data Query YAML string into strict Cacti `<interface>` XML and
	 * return its normalized outputFile and deterministic SHA-256 digest.
	 *
	 * @param string $yaml Raw SnmpDataQuery or ScriptDataQuery YAML.
	 * @return array{kind: string, name: string, outputFile: string, xml: string, sha256: string}
	 * @throws YamlException When YAML syntax or Data Query validation fails.
	 */
	public static function compileYaml(string $yaml): array {
		return self::compileArray(YamlParser::parseMap($yaml));
	}

	/**
	 * Compile a decoded Data Query array into strict Cacti `<interface>` XML.
	 *
	 * @param array<string, mixed> $doc Decoded Data Query document.
	 * @return array{kind: string, name: string, outputFile: string, xml: string, sha256: string}
	 * @throws YamlException When validation fails.
	 */
	public static function compileArray(array $doc): array {
		$errors = DataQueryValidator::validate($doc);

		if ($errors !== []) {
			throw new YamlException('Data Query YAML validation failed.', $errors);
		}

		$kind       = is_string($doc['kind'] ?? null) ? $doc['kind'] : 'SnmpDataQuery';
		$metadata   = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];
		$spec       = is_array($doc['spec'] ?? null) ? $doc['spec'] : [];
		$name       = is_string($metadata['name'] ?? null) ? trim($metadata['name']) : '';
		$outputFile = PathGuard::validateQueryOutputPath(is_string($metadata['outputFile'] ?? null) ? $metadata['outputFile'] : '');

		$dom                     = new \DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput       = true;
		$dom->preserveWhiteSpace = false;

		$root = $dom->createElement('interface');
		$dom->appendChild($root);

		$displayName = is_string($spec['name'] ?? null) && trim($spec['name']) !== '' ? trim($spec['name']) : $name;
		self::appendText($dom, $root, 'name', $displayName);

		if (is_string($spec['description'] ?? null) && $spec['description'] !== '') {
			self::appendText($dom, $root, 'description', $spec['description']);
		}

		if ($kind === 'SnmpDataQuery') {
			self::appendIfString($dom, $root, 'oid_index', $spec['oidIndex'] ?? null);
			self::appendIfString($dom, $root, 'oid_index_parse', $spec['oidIndexParse'] ?? null);
			self::appendIfString($dom, $root, 'oid_num_indexes', $spec['oidNumIndexes'] ?? null);
		} else {
			self::appendIfString($dom, $root, 'script_path', $spec['scriptPath'] ?? null);
			self::appendIfString($dom, $root, 'script_function', $spec['scriptFunction'] ?? null);
			self::appendIfString($dom, $root, 'arg_prepend', $spec['argPrepend'] ?? null);
			self::appendIfString($dom, $root, 'arg_index', $spec['argIndex'] ?? null);
			self::appendIfString($dom, $root, 'arg_query', $spec['argQuery'] ?? null);
			self::appendIfString($dom, $root, 'arg_get', $spec['argGet'] ?? null);
			self::appendIfString($dom, $root, 'arg_num_indexes', $spec['argNumIndexes'] ?? null);
			self::appendIfString($dom, $root, 'output_delimiter', $spec['outputDelimiter'] ?? null);
		}

		if (isset($spec['indexOrder']) && is_array($spec['indexOrder'])) {
			$orderTokens = array_values(array_filter($spec['indexOrder'], static fn (mixed $v): bool => is_string($v) && $v !== ''));

			if ($orderTokens !== []) {
				self::appendText($dom, $root, 'index_order', implode(':', $orderTokens));
			}
		}

		self::appendIfString($dom, $root, 'index_order_type', $spec['indexOrderType'] ?? null);
		self::appendIfString($dom, $root, 'index_title_format', $spec['indexTitleFormat'] ?? '|chosen_order_field|');

		$fieldsEl = $dom->createElement('fields');
		$root->appendChild($fieldsEl);

		$fields = is_array($spec['fields'] ?? null) ? $spec['fields'] : [];

		foreach ($fields as $fieldId => $def) {
			if (!is_array($def)) {
				continue;
			}

			$fieldEl = $dom->createElement((string) $fieldId);
			self::appendIfString($dom, $fieldEl, 'name', $def['name'] ?? null);

			if ($kind === 'SnmpDataQuery') {
				self::appendIfString($dom, $fieldEl, 'method', $def['method'] ?? null);
				self::appendIfString($dom, $fieldEl, 'output_format', $def['outputFormat'] ?? null);
				self::appendIfString($dom, $fieldEl, 'source', $def['source'] ?? null);
				self::appendIfString($dom, $fieldEl, 'direction', $def['direction'] ?? null);
				self::appendIfString($dom, $fieldEl, 'oid', $def['oid'] ?? null);
				self::appendIfString($dom, $fieldEl, 'oid_suffix', $def['oidSuffix'] ?? null);
				self::appendIfString($dom, $fieldEl, 'rewrite_index', $def['rewriteIndex'] ?? null);
				self::appendIfString($dom, $fieldEl, 'oid_rewrite_pattern', $def['oidRewritePattern'] ?? null);
				self::appendIfString($dom, $fieldEl, 'oid_rewrite_replacement', $def['oidRewriteReplacement'] ?? null);
			} else {
				self::appendIfString($dom, $fieldEl, 'direction', $def['direction'] ?? null);
				self::appendIfString($dom, $fieldEl, 'query_name', $def['queryName'] ?? null);
			}

			$fieldsEl->appendChild($fieldEl);
		}

		$xml = $dom->saveXML();

		if (!is_string($xml) || $xml === '') {
			throw new YamlException('Unable to serialize Data Query XML.');
		}

		return [
			'kind'       => $kind,
			'name'       => $name,
			'outputFile' => $outputFile,
			'xml'        => $xml,
			'sha256'     => hash('sha256', $xml),
		];
	}

	/**
	 * Decompile a Cacti Data Query `<interface>` XML file into a canonical YAML
	 * `SnmpDataQuery` or `ScriptDataQuery` document.
	 *
	 * @param string $xml Raw Cacti `<interface>` XML string.
	 * @param string $outputFile Relative resource path (e.g. `resource/snmp_queries/interface.xml`).
	 * @return string Canonical YAML 1.2 document.
	 * @throws YamlException On DTD/ENTITY injection, malformed XML, or missing `<interface>` root.
	 */
	public static function decompile(string $xml, string $outputFile = 'resource/snmp_queries/custom.xml'): string {
		return YamlEmitter::emit(self::decompileToArray($xml, $outputFile), false);
	}

	/**
	 * Decompile a Cacti Data Query `<interface>` XML file into a structured array.
	 *
	 * @param string $xml Raw Cacti `<interface>` XML string.
	 * @param string $outputFile Relative resource path for metadata.outputFile.
	 * @return array<string, mixed> Decoded Data Query YAML document array.
	 * @throws YamlException On XXE/DTD payloads or malformed XML.
	 */
	public static function decompileToArray(string $xml, string $outputFile = 'resource/snmp_queries/custom.xml'): array {
		if (preg_match('/<!(DOCTYPE|ENTITY)\b/i', $xml) === 1) {
			throw new YamlException('Data Query XML must not contain DOCTYPE or ENTITY declarations.');
		}

		$prev = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$dom = new \DOMDocument('1.0', 'UTF-8');
		$ok  = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if (!$ok || $dom->documentElement === null || $dom->documentElement->nodeName !== 'interface') {
			throw new YamlException('Data Query XML must have an <interface> root element.');
		}

		$root = $dom->documentElement;
		$tags = self::directTextMap($root);

		$isScript = isset($tags['script_path']);
		$kind     = $isScript ? 'ScriptDataQuery' : 'SnmpDataQuery';

		$normalizedOut = PathGuard::validateQueryOutputPath($outputFile);
		$baseName      = pathinfo($normalizedOut, PATHINFO_FILENAME);

		$spec = [];

		if (isset($tags['name']) && $tags['name'] !== '') {
			$spec['name'] = $tags['name'];
		}

		if (isset($tags['description']) && $tags['description'] !== '') {
			$spec['description'] = $tags['description'];
		}

		if ($isScript) {
			$map = [
				'script_path'      => 'scriptPath',
				'script_function'  => 'scriptFunction',
				'arg_prepend'      => 'argPrepend',
				'arg_index'        => 'argIndex',
				'arg_query'        => 'argQuery',
				'arg_get'          => 'argGet',
				'arg_num_indexes'  => 'argNumIndexes',
				'output_delimiter' => 'outputDelimiter',
			];
		} else {
			$map = [
				'oid_index'       => 'oidIndex',
				'oid_index_parse' => 'oidIndexParse',
				'oid_num_indexes' => 'oidNumIndexes',
			];
		}

		foreach ($map as $xmlKey => $yamlKey) {
			if (isset($tags[$xmlKey]) && $tags[$xmlKey] !== '') {
				$spec[$yamlKey] = $tags[$xmlKey];
			}
		}

		if (isset($tags['index_order']) && $tags['index_order'] !== '') {
			$spec['indexOrder'] = array_values(array_filter(explode(':', $tags['index_order']), static fn (string $s): bool => $s !== ''));
		}

		if (isset($tags['index_order_type']) && $tags['index_order_type'] !== '') {
			$spec['indexOrderType'] = $tags['index_order_type'];
		}

		if (isset($tags['index_title_format']) && $tags['index_title_format'] !== '') {
			$spec['indexTitleFormat'] = $tags['index_title_format'];
		}

		$fields = [];

		foreach ($root->childNodes as $node) {
			if (!$node instanceof \DOMElement || $node->nodeName !== 'fields') {
				continue;
			}

			foreach ($node->childNodes as $fieldNode) {
				if (!$fieldNode instanceof \DOMElement) {
					continue;
				}

				$fTags = self::directTextMap($fieldNode);
				$entry = [];

				if ($isScript) {
					$fieldMap = [
						'name'       => 'name',
						'direction'  => 'direction',
						'query_name' => 'queryName',
					];
				} else {
					$fieldMap = [
						'name'                    => 'name',
						'method'                  => 'method',
						'output_format'           => 'outputFormat',
						'source'                  => 'source',
						'direction'               => 'direction',
						'oid'                     => 'oid',
						'oid_suffix'              => 'oidSuffix',
						'rewrite_index'           => 'rewriteIndex',
						'oid_rewrite_pattern'     => 'oidRewritePattern',
						'oid_rewrite_replacement' => 'oidRewriteReplacement',
					];
				}

				foreach ($fieldMap as $xmlKey => $yamlKey) {
					if (isset($fTags[$xmlKey]) && $fTags[$xmlKey] !== '') {
						$entry[$yamlKey] = $fTags[$xmlKey];
					}
				}

				$fields[$fieldNode->nodeName] = $entry;
			}
		}

		$spec['fields'] = $fields;

		return [
			'apiVersion' => DataQueryValidator::API_VERSION,
			'kind'       => $kind,
			'metadata'   => [
				'name'       => $baseName,
				'outputFile' => $normalizedOut,
			],
			'spec' => $spec,
		];
	}

	/**
	 * Append a text element to a parent DOMElement.
	 *
	 * @param \DOMDocument $dom Owning document.
	 * @param \DOMElement $parent Parent element.
	 * @param string $tag XML element name.
	 * @param string $value Text content.
	 * @return void
	 */
	private static function appendText(\DOMDocument $dom, \DOMElement $parent, string $tag, string $value): void {
		$el = $dom->createElement($tag);
		$el->appendChild($dom->createTextNode($value));
		$parent->appendChild($el);
	}

	/**
	 * Append a text element only when the candidate value is a non-empty string.
	 *
	 * @param \DOMDocument $dom Owning document.
	 * @param \DOMElement $parent Parent element.
	 * @param string $tag XML element name.
	 * @param mixed $value Candidate string value.
	 * @return void
	 */
	private static function appendIfString(\DOMDocument $dom, \DOMElement $parent, string $tag, mixed $value): void {
		if (is_string($value) && trim($value) !== '') {
			self::appendText($dom, $parent, $tag, trim($value));
		}
	}

	/**
	 * Extract direct leaf child elements of a DOMElement into a key-value map.
	 *
	 * @param \DOMElement $parent Parent element.
	 * @return array<string, string> Map of tag name to trimmed text.
	 */
	private static function directTextMap(\DOMElement $parent): array {
		$map = [];

		foreach ($parent->childNodes as $child) {
			if (!$child instanceof \DOMElement || $child->nodeName === 'fields') {
				continue;
			}

			$map[$child->nodeName] = trim($child->textContent);
		}

		return $map;
	}
}
