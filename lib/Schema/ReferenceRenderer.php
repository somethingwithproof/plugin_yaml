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
 * Renders human-readable Markdown and HTML Reference Documentation from merged
 * plugin Config/State schemas.
 */
final class ReferenceRenderer {
	/**
	 * Render a Markdown reference document grouping each plugin into Declarative
	 * Config keys and Read-Only Runtime State keys.
	 *
	 * @param array<string, array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}> $pluginSchemas
	 * @return string GitHub-flavored Markdown reference text.
	 */
	public static function renderMarkdown(array $pluginSchemas): string {
		$lines = [
			'# Cacti YAML & CasC Schema Reference',
			'',
			'Generated from discovered static `schema/*.yaml` descriptors and `casc_describe_schema` hooks.',
			'',
		];

		if ($pluginSchemas === []) {
			$lines[] = 'No plugin schemas were discovered.';

			return implode("\n", $lines) . "\n";
		}

		foreach ($pluginSchemas as $slug => $schema) {
			$lines[] = sprintf('## `%s` (%s, schema v%s)', $slug, $schema['title'], $schema['schemaVersion']);
			$lines[] = '';

			if ($schema['description'] !== '') {
				$lines[] = $schema['description'];
				$lines[] = '';
			}

			$lines[] = '### Declarative Config (`spec.plugins.' . $slug . '`)';
			$lines[] = '';
			$lines   = array_merge($lines, self::renderMarkdownTable($schema['config']));
			$lines[] = '';

			if ($schema['state'] !== []) {
				$lines[] = '### Runtime State (Read-Only)';
				$lines[] = '';
				$lines   = array_merge($lines, self::renderMarkdownTable($schema['state']));
				$lines[] = '';
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * Render an XSS-safe HTML reference view suitable for embedding in the Cacti
	 * Console View page.
	 *
	 * @param array<string, array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}> $pluginSchemas
	 * @return string Escaped HTML markup.
	 */
	public static function renderHtml(array $pluginSchemas): string {
		if ($pluginSchemas === []) {
			return '<p>' . self::esc('No plugin schemas were discovered.') . '</p>';
		}

		$html = '<div class="yaml-reference">';

		foreach ($pluginSchemas as $slug => $schema) {
			$html .= '<h3>' . self::esc($schema['title']) . ' <code>' . self::esc($slug) . '</code> (v' . self::esc($schema['schemaVersion']) . ')</h3>';

			if ($schema['description'] !== '') {
				$html .= '<p>' . self::esc($schema['description']) . '</p>';
			}

			$html .= '<h4>' . self::esc('Declarative Config') . '</h4>';
			$html .= self::renderHtmlTable($schema['config']);

			if ($schema['state'] !== []) {
				$html .= '<h4>' . self::esc('Runtime State (Read-Only)') . '</h4>';
				$html .= self::renderHtmlTable($schema['state']);
			}
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render a Markdown table of schema properties.
	 *
	 * @param array<string, mixed> $properties Map of property name to JSON Schema snippet.
	 * @return list<string> Markdown lines.
	 */
	private static function renderMarkdownTable(array $properties): array {
		if ($properties === []) {
			return ['_None defined._'];
		}

		$rows = [
			'| Key | Type | Default / Constraints | Description |',
			'| --- | --- | --- | --- |',
		];

		foreach ($properties as $name => $spec) {
			if (!is_array($spec)) {
				continue;
			}

			$type        = is_string($spec['type'] ?? null) ? $spec['type'] : 'mixed';
			$constraints = self::summarizeConstraints($spec);
			$desc        = is_string($spec['description'] ?? null) ? str_replace('|', '\\|', $spec['description']) : '';

			$rows[] = sprintf('| `%s` | `%s` | %s | %s |', $name, $type, str_replace('|', '\\|', $constraints), $desc);
		}

		return $rows;
	}

	/**
	 * Render an escaped HTML table of schema properties.
	 *
	 * @param array<string, mixed> $properties Map of property name to JSON Schema snippet.
	 * @return string Escaped HTML table.
	 */
	private static function renderHtmlTable(array $properties): string {
		if ($properties === []) {
			return '<p><em>' . self::esc('None defined.') . '</em></p>';
		}

		$html = '<table class="cactiTable"><thead><tr>'
			. '<th>' . self::esc('Key') . '</th>'
			. '<th>' . self::esc('Type') . '</th>'
			. '<th>' . self::esc('Default / Constraints') . '</th>'
			. '<th>' . self::esc('Description') . '</th>'
			. '</tr></thead><tbody>';

		foreach ($properties as $name => $spec) {
			if (!is_array($spec)) {
				continue;
			}

			$type        = is_string($spec['type'] ?? null) ? $spec['type'] : 'mixed';
			$constraints = self::summarizeConstraints($spec);
			$desc        = is_string($spec['description'] ?? null) ? $spec['description'] : '';

			$html .= '<tr>'
				. '<td><code>' . self::esc($name) . '</code></td>'
				. '<td><code>' . self::esc($type) . '</code></td>'
				. '<td>' . self::esc($constraints) . '</td>'
				. '<td>' . self::esc($desc) . '</td>'
				. '</tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Build a concise human-readable constraint summary for a schema property.
	 *
	 * @param array<int|string, mixed> $spec Schema property specification.
	 * @return string Summary text.
	 */
	private static function summarizeConstraints(array $spec): string {
		$parts = [];

		if (array_key_exists('default', $spec)) {
			$encoded = json_encode($spec['default']);
			$parts[] = 'default=' . (is_string($encoded) ? $encoded : '');
		}

		if (isset($spec['enum']) && is_array($spec['enum'])) {
			$vals    = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $spec['enum']);
			$parts[] = 'enum=[' . implode(', ', $vals) . ']';
		}

		if (isset($spec['pattern']) && is_string($spec['pattern'])) {
			$parts[] = 'pattern=' . $spec['pattern'];
		}

		if (($spec['readOnly'] ?? false) === true) {
			$parts[] = 'readOnly';
		}

		return $parts !== [] ? implode('; ', $parts) : '-';
	}

	/**
	 * Escape HTML content using Cacti's html_escape() when loaded, falling back
	 * to UTF-8 htmlspecialchars().
	 *
	 * @param string $value Raw string.
	 * @return string Escaped string.
	 */
	private static function esc(string $value): string {
		if (function_exists('html_escape')) {
			return (string) html_escape($value);
		}

		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
	}
}
