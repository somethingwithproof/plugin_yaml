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
	 * O(1) lookup set of keywords that must be quoted as strings to avoid ambiguous scalar parsing.
	 *
	 * @var array<string, true>
	 */
	private const RESERVED_SCALAR_MAP = [
		'null'  => true,
		'true'  => true,
		'false' => true,
		'~'     => true,
		'yes'   => true,
		'no'    => true,
		'on'    => true,
		'off'   => true,
		'y'     => true,
		'n'     => true,
		'.nan'  => true,
		'.inf'  => true,
		'-.inf' => true,
		'+.inf' => true,
	];

	/**
	 * Characters permitted after the first character of an unquoted mapping key.
	 */
	private const KEY_TAIL_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-';

	/**
	 * Leading characters that require quoting for a plain YAML scalar.
	 */
	private const SPECIAL_LEADING = "-?:,[]{}#&*!|>'\"%@`";

	/**
	 * Emit a PHP array or scalar as canonical YAML 1.2 text.
	 *
	 * @param mixed $data Native PHP structure to serialize.
	 * @param bool $sortKeys Sort mapping keys lexically when true.
	 * @return string Canonical YAML 1.2 text ending in a single newline.
	 */
	public static function emit(mixed $data, bool $sortKeys = false): string {
		$out = [];
		self::renderValueInto($data, 0, $sortKeys, $out);

		return rtrim(implode("\n", $out), "\n") . "\n";
	}

	/**
	 * Render any PHP value directly into the shared line buffer without intermediate joins/splits.
	 *
	 * @param mixed $value Value to render.
	 * @param int $indent Number of leading spaces.
	 * @param bool $sortKeys Sort mapping keys lexically when true.
	 * @param list<string> $out Output line accumulator.
	 * @return void
	 */
	private static function renderValueInto(mixed $value, int $indent, bool $sortKeys, array &$out): void {
		if (!is_array($value)) {
			$out[] = str_repeat(' ', $indent) . self::formatScalar($value, $indent);

			return;
		}

		if ($value === []) {
			$out[] = str_repeat(' ', $indent) . '[]';

			return;
		}

		if (array_is_list($value)) {
			self::renderSequenceInto($value, $indent, $sortKeys, $out);

			return;
		}

		self::renderMappingInto($value, $indent, $sortKeys, $out);
	}

	/**
	 * Render a sequential PHP list as a YAML block sequence into the shared line buffer.
	 *
	 * @param list<mixed> $list List items.
	 * @param int $indent Indentation in spaces.
	 * @param bool $sortKeys Sort nested mapping keys when true.
	 * @param list<string> $out Output line accumulator.
	 * @return void
	 */
	private static function renderSequenceInto(array $list, int $indent, bool $sortKeys, array &$out): void {
		$prefix = str_repeat(' ', $indent);

		foreach ($list as $item) {
			if (!is_array($item)) {
				if (is_string($item) && str_contains($item, "\n")) {
					$out[] = $prefix . '- ' . self::formatBlockString($item, $indent + 2);
				} else {
					$out[] = $prefix . '- ' . self::formatScalar($item, $indent + 2);
				}

				continue;
			}

			if ($item === []) {
				$out[] = $prefix . '- []';

				continue;
			}

			if (!array_is_list($item)) {
				$startIdx = count($out);
				self::renderMappingInto($item, $indent + 2, $sortKeys, $out);

				if (isset($out[$startIdx])) {
					$out[$startIdx] = $prefix . '- ' . ltrim($out[$startIdx], ' ');
				}
			} else {
				$out[] = $prefix . '-';
				self::renderSequenceInto($item, $indent + 2, $sortKeys, $out);
			}
		}
	}

	/**
	 * Render an associative PHP array as a YAML block mapping into the shared line buffer.
	 *
	 * @param array<int|string, mixed> $map Associative map.
	 * @param int $indent Indentation in spaces.
	 * @param bool $sortKeys Sort keys lexically when true.
	 * @param list<string> $out Output line accumulator.
	 * @return void
	 */
	private static function renderMappingInto(array $map, int $indent, bool $sortKeys, array &$out): void {
		if ($sortKeys) {
			ksort($map, SORT_STRING);
		}

		$prefix = str_repeat(' ', $indent);

		foreach ($map as $key => $val) {
			$encodedKey = self::formatKey((string) $key);

			if (!is_array($val)) {
				if (is_string($val) && str_contains($val, "\n")) {
					$out[] = $prefix . $encodedKey . ': ' . self::formatBlockString($val, $indent + 2);
				} else {
					$out[] = $prefix . $encodedKey . ': ' . self::formatScalar($val, $indent + 2);
				}

				continue;
			}

			if ($val === []) {
				$out[] = $prefix . $encodedKey . ': []';

				continue;
			}

			$out[] = $prefix . $encodedKey . ':';
			self::renderValueInto($val, $indent + 2, $sortKeys, $out);
		}
	}

	/**
	 * Format a mapping key, quoting when characters could confuse YAML parsing.
	 *
	 * @param string $key Mapping key string.
	 * @return string Safe YAML key token.
	 */
	private static function formatKey(string $key): string {
		$len = strlen($key);

		if ($len > 0) {
			$first = $key[0];

			if (
				(($first >= 'a' && $first <= 'z') || ($first >= 'A' && $first <= 'Z') || $first === '_')
				&& strspn($key, self::KEY_TAIL_CHARS, 1) === $len - 1
				&& !isset(self::RESERVED_SCALAR_MAP[strtolower($key)])
			) {
				return $key;
			}
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
		$normalized = str_contains($value, "\r") ? str_replace(["\r\n", "\r"], "\n", $value) : $value;
		$hasTrail   = str_ends_with($normalized, "\n");
		$body       = $hasTrail ? substr($normalized, 0, -1) : $normalized;
		$header     = $hasTrail ? '|' : '|-';
		$pad        = str_repeat(' ', $indent);
		$lines      = explode("\n", $body);

		foreach ($lines as $i => $line) {
			if ($line !== '') {
				$lines[$i] = $pad . $line;
			}
		}

		return $header . "\n" . implode("\n", $lines);
	}

	/**
	 * Determine whether a string scalar requires quoting in YAML 1.2.
	 *
	 * @param string $value Candidate string scalar.
	 * @return bool True when quoting is necessary for round-trip safety.
	 */
	private static function needsQuoting(string $value): bool {
		$len = strlen($value);

		if ($len === 0) {
			return true;
		}

		$first = $value[0];
		$last  = $value[$len - 1];

		if ($first <= ' ' || $last <= ' ') {
			return true;
		}

		if (str_contains(self::SPECIAL_LEADING, $first)) {
			return true;
		}

		if ($len <= 5 && isset(self::RESERVED_SCALAR_MAP[strtolower($value)])) {
			return true;
		}

		if (
			($first >= '0' && $first <= '9')
			|| $first === '+'
			|| $first === '-'
			|| $first === '.'
		) {
			if (preg_match('/^[+-]?(?:0|[1-9][0-9]*|0x[0-9a-fA-F]+|0o[0-7]+|(?:[0-9]+\.[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)$/D', $value) === 1) {
				return true;
			}
		}

		if ($last === ':' || str_contains($value, ': ') || str_contains($value, ' #')) {
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
