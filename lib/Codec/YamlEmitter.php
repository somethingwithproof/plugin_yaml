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

namespace Somethingwithproof\Yaml\Codec;

/**
 * Deterministic pure-PHP YAML 1.2 emitter producing canonical, safe output.
 */
final class YamlEmitter {
	/**
	 * Keywords that must be quoted as strings to avoid ambiguous scalar parsing.
	 *
	 * @var list<string>
	 */
	private const RESERVED_SCALARS = [
		'null',
		'true',
		'false',
		'~',
		'yes',
		'no',
		'on',
		'off',
		'y',
		'n',
		'.nan',
		'.inf',
		'-.inf',
		'+.inf',
	];

	/**
	 * Emit a PHP array or scalar as canonical YAML 1.2 text.
	 *
	 * @param mixed $data Native PHP structure to serialize.
	 * @param bool $sortKeys Sort mapping keys lexically when true.
	 * @return string Canonical YAML 1.2 text ending in a single newline.
	 */
	public static function emit(mixed $data, bool $sortKeys = false): string {
		$body = self::renderValue($data, 0, $sortKeys);

		return rtrim($body, "\n") . "\n";
	}

	/**
	 * Render any PHP value at the given indentation depth.
	 *
	 * @param mixed $value Value to render.
	 * @param int $indent Number of leading spaces.
	 * @param bool $sortKeys Sort mapping keys lexically when true.
	 * @return string Rendered YAML fragment.
	 */
	private static function renderValue(mixed $value, int $indent, bool $sortKeys): string {
		if (!is_array($value)) {
			return str_repeat(' ', $indent) . self::formatScalar($value, $indent);
		}

		if ($value === []) {
			return str_repeat(' ', $indent) . '[]';
		}

		if (array_is_list($value)) {
			return self::renderSequence($value, $indent, $sortKeys);
		}

		return self::renderMapping($value, $indent, $sortKeys);
	}

	/**
	 * Render a sequential PHP list as a YAML block sequence.
	 *
	 * @param list<mixed> $list List items.
	 * @param int $indent Indentation in spaces.
	 * @param bool $sortKeys Sort nested mapping keys when true.
	 * @return string Rendered YAML sequence.
	 */
	private static function renderSequence(array $list, int $indent, bool $sortKeys): string {
		$lines  = [];
		$prefix = str_repeat(' ', $indent);

		foreach ($list as $item) {
			if (!is_array($item)) {
				if (is_string($item) && str_contains($item, "\n")) {
					$lines[] = $prefix . '- ' . self::formatBlockString($item, $indent + 2);
				} else {
					$lines[] = $prefix . '- ' . self::formatScalar($item, $indent + 2);
				}

				continue;
			}

			if ($item === []) {
				$lines[] = $prefix . '- []';

				continue;
			}

			if (!array_is_list($item)) {
				$nested    = self::renderMapping($item, $indent + 2, $sortKeys);
				$subLines  = explode("\n", $nested);
				$firstLine = ltrim($subLines[0]);
				$lines[]   = $prefix . '- ' . $firstLine;

				for ($i = 1, $total = count($subLines); $i < $total; $i++) {
					$lines[] = $subLines[$i];
				}
			} else {
				$lines[] = $prefix . '-';
				$lines[] = self::renderSequence($item, $indent + 2, $sortKeys);
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * Render an associative PHP array as a YAML block mapping.
	 *
	 * @param array<int|string, mixed> $map Associative map.
	 * @param int $indent Indentation in spaces.
	 * @param bool $sortKeys Sort keys lexically when true.
	 * @return string Rendered YAML mapping.
	 */
	private static function renderMapping(array $map, int $indent, bool $sortKeys): string {
		if ($sortKeys) {
			ksort($map, SORT_STRING);
		}

		$lines  = [];
		$prefix = str_repeat(' ', $indent);

		foreach ($map as $key => $val) {
			$encodedKey = self::formatKey((string) $key);

			if (!is_array($val)) {
				if (is_string($val) && str_contains($val, "\n")) {
					$lines[] = $prefix . $encodedKey . ': ' . self::formatBlockString($val, $indent + 2);
				} else {
					$lines[] = $prefix . $encodedKey . ': ' . self::formatScalar($val, $indent + 2);
				}

				continue;
			}

			if ($val === []) {
				$lines[] = $prefix . $encodedKey . ': []';

				continue;
			}

			$lines[] = $prefix . $encodedKey . ':';
			$lines[] = self::renderValue($val, $indent + 2, $sortKeys);
		}

		return implode("\n", $lines);
	}

	/**
	 * Format a mapping key, quoting when characters could confuse YAML parsing.
	 *
	 * @param string $key Mapping key string.
	 * @return string Safe YAML key token.
	 */
	private static function formatKey(string $key): string {
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $key) === 1 && !in_array(strtolower($key), self::RESERVED_SCALARS, true)) {
			return $key;
		}

		return self::quoteString($key);
	}

	/**
	 * Format a scalar value into a canonical YAML 1.2 representation.
	 *
	 * @param mixed $value Scalar or null value.
	 * @param int $indent Current block indentation for multiline strings.
	 * @return string Encoded scalar token.
	 */
	private static function formatScalar(mixed $value, int $indent): string {
		if ($value === null) {
			return 'null';
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_int($value)) {
			return (string) $value;
		}

		if (is_float($value)) {
			$formatted = json_encode($value);

			return is_string($formatted) ? $formatted : (string) $value;
		}

		if (!is_string($value)) {
			return self::quoteString('');
		}

		if (str_contains($value, "\n")) {
			return self::formatBlockString($value, $indent);
		}

		if (self::needsQuoting($value)) {
			return self::quoteString($value);
		}

		return $value;
	}

	/**
	 * Render a multiline string using YAML literal block scalar syntax.
	 *
	 * @param string $value Multiline string.
	 * @param int $indent Indentation in spaces for body lines.
	 * @return string Block scalar YAML representation.
	 */
	private static function formatBlockString(string $value, int $indent): string {
		$normalized = str_replace(["\r\n", "\r"], "\n", $value);
		$hasTrail   = str_ends_with($normalized, "\n");
		$body       = $hasTrail ? substr($normalized, 0, -1) : $normalized;
		$header     = $hasTrail ? '|' : '|-';
		$pad        = str_repeat(' ', $indent);
		$lines      = explode("\n", $body);
		$indented   = array_map(static fn (string $line): string => $line === '' ? '' : $pad . $line, $lines);

		return $header . "\n" . implode("\n", $indented);
	}

	/**
	 * Determine whether a string scalar requires quoting in YAML 1.2.
	 *
	 * @param string $value Candidate string scalar.
	 * @return bool True when quoting is necessary for round-trip safety.
	 */
	private static function needsQuoting(string $value): bool {
		if ($value === '' || trim($value) !== $value) {
			return true;
		}

		if (in_array(strtolower($value), self::RESERVED_SCALARS, true)) {
			return true;
		}

		if (preg_match('/^[+-]?(?:0|[1-9][0-9]*|0x[0-9a-fA-F]+|0o[0-7]+|(?:[0-9]+\.[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)$/D', $value) === 1) {
			return true;
		}

		if (preg_match('/^[-?:,\[\]{}#&*!|>\'"%@`]/', $value) === 1) {
			return true;
		}

		if (str_contains($value, ': ') || str_ends_with($value, ':') || str_contains($value, ' #')) {
			return true;
		}

		return preg_match('/[\x00-\x1F\x7F\\\\"\']/', $value) === 1;
	}

	/**
	 * Quote a string using single quotes when printable, or double quotes with escapes.
	 *
	 * @param string $value Raw string.
	 * @return string Quoted YAML scalar.
	 */
	private static function quoteString(string $value): string {
		if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
			$escaped = str_replace(
				['\\', '"', "\n", "\r", "\t"],
				['\\\\', '\\"', '\\n', '\\r', '\\t'],
				$value
			);

			return '"' . $escaped . '"';
		}

		return "'" . str_replace("'", "''", $value) . "'";
	}
}
