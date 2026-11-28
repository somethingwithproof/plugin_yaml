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

namespace Somethingwithproof\Yaml\Merge;

/**
 * Parses, validates, and redacts JCasC-style secret and environment placeholders.
 *
 * Supports `${ENV_VAR}`, `${ENV_VAR:-default}`, `${file:/abs/path}`, and
 * `vault:path#key` expressions without exposing secret values in logs or diffs.
 */
final class PlaceholderParser {
	/**
	 * Replacement token written into redacted Console views and plan diffs.
	 */
	public const REDACTED = '<REDACTED>';

	/**
	 * Regex matching valid JCasC `${...}` placeholder tokens.
	 */
	private const INTERPOLATION_PATTERN = '/\$\{([^{}]+)\}/';

	/**
	 * Regex matching secret-bearing key names that must always be redacted.
	 */
	private const SECRET_KEY_PATTERN = '/(password|passphrase|secret|token|community|private_key|auth_pass|priv_pass)/i';

	/**
	 * Inspect a merged document and extract all placeholder references alongside
	 * syntax validation errors.
	 *
	 * @param array<string, mixed> $document Decoded YAML mapping.
	 * @return array{references: list<array{path: string, kind: string, target: string, hasDefault: bool}>, errors: list<string>}
	 */
	public static function inspect(array $document): array {
		$references = [];
		$errors     = [];
		self::walkInspect($document, '', $references, $errors);

		return [
			'references' => $references,
			'errors'     => $errors,
		];
	}

	/**
	 * Produce a deep copy of a document with secret keys and inline secret
	 * placeholders safely redacted for Console preview, CLI diffs, and logs.
	 *
	 * @param array<string, mixed> $document Decoded YAML mapping.
	 * @return array<string, mixed> Redacted document copy.
	 */
	public static function redact(array $document): array {
		$result = [];

		foreach ($document as $key => $value) {
			$keyStr = (string) $key;

			if (preg_match(self::SECRET_KEY_PATTERN, $keyStr) === 1 && !is_array($value)) {
				$result[$keyStr] = self::REDACTED;

				continue;
			}

			$result[$keyStr] = self::redactValue($value);
		}

		return $result;
	}

	/**
	 * Determine whether a string contains a secret or environment placeholder.
	 *
	 * @param string $value Candidate scalar string.
	 * @return bool
	 */
	public static function containsPlaceholder(string $value): bool {
		return str_starts_with($value, 'vault:')
			|| preg_match(self::INTERPOLATION_PATTERN, $value) === 1;
	}

	/**
	 * Recursively redact nested values and inline secret placeholders.
	 *
	 * @param mixed $value Arbitrary YAML node.
	 * @return mixed Redacted node.
	 */
	private static function redactValue(mixed $value): mixed {
		if (is_array($value)) {
			if (array_is_list($value)) {
				return array_map(static fn (mixed $item): mixed => self::redactValue($item), $value);
			}

			$map = [];

			foreach ($value as $k => $v) {
				$map[(string) $k] = $v;
			}

			return self::redact($map);
		}

		if (!is_string($value)) {
			return $value;
		}

		if (str_starts_with($value, 'vault:')) {
			return self::REDACTED;
		}

		$replaced = preg_replace_callback(
			self::INTERPOLATION_PATTERN,
			static function (array $match): string {
				$expr = $match[1];

				if (str_starts_with($expr, 'file:')) {
					return self::REDACTED;
				}

				if (str_contains($expr, ':-')) {
					[$var] = explode(':-', $expr, 2);

					return '${' . $var . ':-' . self::REDACTED . '}';
				}

				return '${' . $expr . '}';
			},
			$value
		);

		return is_string($replaced) ? $replaced : self::REDACTED;
	}

	/**
	 * Recursively walk a document tree collecting placeholder references and errors.
	 *
	 * @param mixed $node Current node.
	 * @param string $path Dotted path for diagnostics.
	 * @param list<array{path: string, kind: string, target: string, hasDefault: bool}> $references Collected references.
	 * @param list<string> $errors Collected syntax errors.
	 * @return void
	 */
	private static function walkInspect(mixed $node, string $path, array &$references, array &$errors): void {
		if (is_array($node)) {
			foreach ($node as $key => $value) {
				$nextPath = $path === '' ? (string) $key : $path . '.' . (string) $key;
				self::walkInspect($value, $nextPath, $references, $errors);
			}

			return;
		}

		if (!is_string($node)) {
			return;
		}

		if (str_starts_with($node, 'vault:')) {
			$target = substr($node, 6);

			if (preg_match('/^[A-Za-z0-9._\/-]+#[A-Za-z0-9._-]+$/D', $target) !== 1 || str_contains($target, '..')) {
				$errors[] = sprintf('%s: invalid vault: reference format (expected vault:path#key).', $path);
			} else {
				$references[] = [
					'path'       => $path,
					'kind'       => 'vault',
					'target'     => $target,
					'hasDefault' => false,
				];
			}

			return;
		}

		if (preg_match_all(self::INTERPOLATION_PATTERN, $node, $matches) < 1) {
			return;
		}

		foreach ($matches[1] as $expr) {
			if (str_starts_with($expr, 'file:')) {
				$fileTarget = substr($expr, 5);

				if ($fileTarget === '' || !str_starts_with($fileTarget, '/') || str_contains($fileTarget, '..') || str_contains($fileTarget, "\0")) {
					$errors[] = sprintf('%s: invalid ${file:...} placeholder path "%s".', $path, $fileTarget);
				} else {
					$references[] = [
						'path'       => $path,
						'kind'       => 'file',
						'target'     => $fileTarget,
						'hasDefault' => false,
					];
				}

				continue;
			}

			$hasDefault = str_contains($expr, ':-');
			$varName    = $hasDefault ? explode(':-', $expr, 2)[0] : $expr;

			if (preg_match('/^[A-Z_][A-Z0-9_]{0,127}$/D', $varName) !== 1) {
				$errors[] = sprintf('%s: invalid environment variable placeholder "${%s}".', $path, $expr);
			} else {
				$references[] = [
					'path'       => $path,
					'kind'       => 'env',
					'target'     => $varName,
					'hasDefault' => $hasDefault,
				];
			}
		}
	}
}
