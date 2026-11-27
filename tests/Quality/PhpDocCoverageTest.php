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

namespace Somethingwithproof\Yaml\Tests\Quality;

use PHPUnit\Framework\TestCase;

/**
 * Enforces 100% PHPDoc coverage across every class, interface, trait, enum,
 * enum case, constant, property, method, and function in lib/ and setup.php.
 */
final class PhpDocCoverageTest extends TestCase {
	/**
	 * Verify every declared PHP symbol in lib/ and setup.php has a PHPDoc block.
	 *
	 * @return void
	 */
	public function testAllSourceSymbolsHavePhpDoc(): void {
		$root  = dirname(__DIR__, 2);
		$files = [$root . '/setup.php'];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}

		sort($files, SORT_STRING);
		$missing = [];

		foreach ($files as $path) {
			$missing = array_merge($missing, $this->inspectFile($path, $root));
		}

		$this->assertSame([], $missing, "Symbols missing PHPDoc:\n" . implode("\n", $missing));
	}

	/**
	 * Tokenize a single PHP source file and report declarations lacking a preceding T_DOC_COMMENT.
	 *
	 * @param string $path Absolute PHP source file path.
	 * @param string $root Repository root for relative reporting.
	 * @return list<string> Human-readable list of undocumented symbols.
	 */
	private function inspectFile(string $path, string $root): array {
		$source = file_get_contents($path);
		$this->assertIsString($source);

		$rel        = ltrim(str_replace($root, '', $path), '/');
		$tokens     = token_get_all($source);
		$count      = count($tokens);
		$missing    = [];
		$braceDepth = 0;
		$typeDepths = [];
		$inEnum     = [];

		for ($i = 0; $i < $count; $i++) {
			$tok = $tokens[$i];

			if ($tok === '{') {
				$braceDepth++;

				continue;
			}

			if ($tok === '}') {
				$braceDepth--;

				while ($typeDepths !== [] && end($typeDepths) >= $braceDepth) {
					array_pop($typeDepths);
					array_pop($inEnum);
				}

				continue;
			}

			if (!is_array($tok)) {
				continue;
			}

			[$id, $text, $line] = $tok;

			// 1. Class, interface, trait, enum declarations.
			if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
				// Skip ::class and anonymous classes (`new class`).
				$prev = $this->prevSignificant($tokens, $i);

				if ($prev !== null && (is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true))) {
					continue;
				}

				$name = $this->nextIdentifier($tokens, $i);

				if ($name !== null) {
					if (!$this->hasDocCommentBefore($tokens, $i)) {
						$missing[] = sprintf('%s:%d %s %s', $rel, $line, strtolower($text), $name);
					}

					$typeDepths[] = $braceDepth;
					$inEnum[]     = ($id === T_ENUM);
				}

				continue;
			}

			// 2. Named functions and methods.
			if ($id === T_FUNCTION) {
				$name = $this->nextIdentifier($tokens, $i);

				// Closures have '(' right after function, not an identifier.
				if ($name === null) {
					continue;
				}

				if (!$this->hasDocCommentBefore($tokens, $i)) {
					$missing[] = sprintf('%s:%d function %s()', $rel, $line, $name);
				}

				continue;
			}

			// 3. Class / interface / enum constants.
			if ($id === T_CONST && $typeDepths !== [] && end($typeDepths) === $braceDepth - 1) {
				$name = $this->nextIdentifier($tokens, $i);

				if ($name !== null && !$this->hasDocCommentBefore($tokens, $i)) {
					$missing[] = sprintf('%s:%d const %s', $rel, $line, $name);
				}

				continue;
			}

			// 4. Enum cases.
			if ($id === T_CASE && $typeDepths !== [] && end($inEnum) === true && end($typeDepths) === $braceDepth - 1) {
				$name = $this->nextIdentifier($tokens, $i);

				if ($name !== null && !$this->hasDocCommentBefore($tokens, $i)) {
					$missing[] = sprintf('%s:%d enum case %s', $rel, $line, $name);
				}

				continue;
			}

			// 5. Class properties (direct members at class body depth).
			if ($id === T_VARIABLE && $typeDepths !== [] && end($typeDepths) === $braceDepth - 1) {
				$prev = $this->prevSignificant($tokens, $i);

				// Ensure this is a property declaration rather than a default value or parameter.
				if ($this->isClassProperty($tokens, $i)) {
					if (!$this->hasDocCommentBefore($tokens, $i)) {
						$missing[] = sprintf('%s:%d property %s', $rel, $line, $text);
					}
				}
			}
		}

		return $missing;
	}

	/**
	 * Determine whether a T_VARIABLE token at class-body depth is a property declaration.
	 *
	 * @param array<int, mixed> $tokens Token stream.
	 * @param int $index Variable token index.
	 * @return bool
	 */
	private function isClassProperty(array $tokens, int $index): bool {
		for ($j = $index - 1; $j >= 0; $j--) {
			$t = $tokens[$j];

			if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR], true)) {
				return true;
			}

			if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_ARRAY, T_CALLABLE], true)) {
				continue;
			}

			if ($t === '?' || $t === '|' || $t === '&') {
				continue;
			}

			return false;
		}

		return false;
	}

	/**
	 * Return the preceding non-whitespace, non-comment token.
	 *
	 * @param array<int, mixed> $tokens Token stream.
	 * @param int $index Starting index.
	 * @return mixed
	 */
	private function prevSignificant(array $tokens, int $index): mixed {
		for ($j = $index - 1; $j >= 0; $j--) {
			$t = $tokens[$j];

			if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			return $t;
		}

		return null;
	}

	/**
	 * Return the next identifier name immediately following a keyword (or null for closures).
	 *
	 * @param array<int, mixed> $tokens Token stream.
	 * @param int $index Keyword token index.
	 * @return ?string
	 */
	private function nextIdentifier(array $tokens, int $index): ?string {
		$count = count($tokens);

		for ($j = $index + 1; $j < $count; $j++) {
			$t = $tokens[$j];

			if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			if ($t === '&') {
				continue;
			}

			if (is_array($t) && $t[0] === T_STRING) {
				return $t[1];
			}

			return null;
		}

		return null;
	}

	/**
	 * Walk backwards across declaration modifiers, types, and attributes to check
	 * for a T_DOC_COMMENT immediately preceding the declaration.
	 *
	 * @param array<int, mixed> $tokens Token stream.
	 * @param int $index Declaration keyword or property variable index.
	 * @return bool
	 */
	private function hasDocCommentBefore(array $tokens, int $index): bool {
		$attrDepth = 0;

		for ($j = $index - 1; $j >= 0; $j--) {
			$t = $tokens[$j];

			if ($t === ']') {
				$attrDepth++;

				continue;
			}

			if ($attrDepth > 0) {
				if (is_array($t) && $t[0] === T_ATTRIBUTE) {
					$attrDepth--;
				} elseif ($t === '[') {
					$attrDepth--;
				}

				continue;
			}

			if (is_array($t)) {
				if ($t[0] === T_DOC_COMMENT) {
					return true;
				}

				if (in_array($t[0], [
					T_WHITESPACE,
					T_COMMENT,
					T_PUBLIC,
					T_PROTECTED,
					T_PRIVATE,
					T_FINAL,
					T_ABSTRACT,
					T_STATIC,
					T_READONLY,
					T_VAR,
					T_STRING,
					T_NAME_FULLY_QUALIFIED,
					T_NAME_QUALIFIED,
					T_ARRAY,
					T_CALLABLE,
				], true)) {
					continue;
				}

				return false;
			}

			if ($t === '?' || $t === '|' || $t === '&') {
				continue;
			}

			return false;
		}

		return false;
	}
}
