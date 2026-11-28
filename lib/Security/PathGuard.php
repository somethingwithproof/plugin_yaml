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

namespace Somethingwithproof\Yaml\Security;

use Somethingwithproof\Yaml\Codec\YamlException;

/**
 * Confines file reads and writes against symlinks, traversal, and oversized inputs.
 */
final class PathGuard {
	/**
	 * Default maximum file size in bytes (4 MiB) for YAML and XML reads.
	 */
	public const DEFAULT_MAX_BYTES = 4194304;

	/**
	 * Allowlisted relative prefixes for compiled Data Query XML output files.
	 */
	public const QUERY_OUTPUT_PREFIXES = [
		'resource/snmp_queries/',
		'resource/script_queries/',
		'resource/script_server/',
	];

	/**
	 * Resolve a relative path inside a base directory while refusing symlinks
	 * and parent-directory traversal.
	 *
	 * @param string $relativePath Relative path beneath $baseDir.
	 * @param string $baseDir Existing root directory that confines the lookup.
	 * @param bool $mustExist When true, require the target to exist as a regular file.
	 * @return string Canonical absolute path inside $baseDir.
	 * @throws YamlException When path validation or symlink checks fail.
	 */
	public static function resolveConfined(string $relativePath, string $baseDir, bool $mustExist = true): string {
		if ($relativePath === '' || str_contains($relativePath, "\0")) {
			throw new YamlException('Path must be a non-empty relative path without null bytes.');
		}

		$normalized = str_replace('\\', '/', $relativePath);

		if (str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
			throw new YamlException(sprintf('Path "%s" must be relative without parent traversal.', $relativePath));
		}

		$realBase = realpath($baseDir);

		if ($realBase === false || !is_dir($realBase)) {
			throw new YamlException(sprintf('Base directory "%s" does not exist.', $baseDir));
		}

		$walk     = $realBase;
		$segments = array_values(array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== '' && $s !== '.'));

		if ($segments === []) {
			throw new YamlException('Path must reference a file inside the base directory.');
		}

		foreach ($segments as $segment) {
			$walk .= '/' . $segment;

			if (is_link($walk)) {
				throw new YamlException(sprintf('Path "%s" must not traverse symlinks.', $relativePath));
			}
		}

		if ($mustExist) {
			$resolved = realpath($walk);

			if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
				throw new YamlException(sprintf('File "%s" is missing or unreadable.', $relativePath));
			}

			if (!str_starts_with($resolved, $realBase . '/')) {
				throw new YamlException(sprintf('Path "%s" escapes base directory.', $relativePath));
			}

			return $resolved;
		}

		$parent = dirname($walk);

		if (is_dir($parent)) {
			$realParent = realpath($parent);

			if ($realParent === false || ($realParent !== $realBase && !str_starts_with($realParent, $realBase . '/'))) {
				throw new YamlException(sprintf('Target parent for "%s" escapes base directory.', $relativePath));
			}
		}

		return $walk;
	}

	/**
	 * Validate a standalone filesystem path (file or directory) against null bytes,
	 * parent traversal segments, and symlink targets.
	 *
	 * @param string $path Absolute or relative filesystem path.
	 * @param bool $allowDirectory When true, permit directories in addition to files.
	 * @return string Canonical resolved path.
	 * @throws YamlException When the path is unsafe, a symlink, or missing.
	 */
	public static function assertSafePath(string $path, bool $allowDirectory = false): string {
		if ($path === '' || str_contains($path, "\0")) {
			throw new YamlException('Filesystem path must not be empty or contain null bytes.');
		}

		$normalized = str_replace('\\', '/', $path);

		if (preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
			throw new YamlException(sprintf('Path "%s" must not contain parent traversal segments.', $path));
		}

		if (is_link($path)) {
			throw new YamlException(sprintf('Path "%s" must not be a symlink.', $path));
		}

		$resolved = realpath($path);

		if ($resolved === false) {
			throw new YamlException(sprintf('Path "%s" does not exist.', $path));
		}

		if ($allowDirectory && is_dir($resolved)) {
			return $resolved;
		}

		if (!is_file($resolved) || !is_readable($resolved)) {
			throw new YamlException(sprintf('File "%s" is missing or unreadable.', $path));
		}

		return $resolved;
	}

	/**
	 * Validate a Data Query outputFile path against the allowlisted resource directories.
	 *
	 * @param string $outputFile Relative output path declared in metadata.outputFile.
	 * @return string Normalized relative path.
	 * @throws YamlException When the path violates allowlist or XML extension rules.
	 */
	public static function validateQueryOutputPath(string $outputFile): string {
		$normalized = ltrim(str_replace('\\', '/', trim($outputFile)), '/');

		if ($normalized === '' || str_contains($normalized, "\0") || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
			throw new YamlException(sprintf('Invalid Data Query outputFile "%s".', $outputFile));
		}

		if (!str_ends_with(strtolower($normalized), '.xml')) {
			throw new YamlException(sprintf('Data Query outputFile "%s" must end with .xml.', $outputFile));
		}

		foreach (self::QUERY_OUTPUT_PREFIXES as $prefix) {
			if (str_starts_with($normalized, $prefix)) {
				$tail = substr($normalized, strlen($prefix));

				if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.xml$/D', $tail) === 1) {
					return $normalized;
				}
			}
		}

		throw new YamlException(sprintf(
			'Data Query outputFile "%s" must reside directly in one of: %s.',
			$outputFile,
			implode(', ', self::QUERY_OUTPUT_PREFIXES)
		));
	}

	/**
	 * Read a bounded file safely after verifying symlink and size constraints.
	 *
	 * @param string $path Path to read.
	 * @param int $maxBytes Maximum allowed byte length.
	 * @return string Raw file contents.
	 * @throws YamlException When the file cannot be read or exceeds $maxBytes.
	 */
	public static function readBoundedFile(string $path, int $maxBytes = self::DEFAULT_MAX_BYTES): string {
		$resolved = self::assertSafePath($path, false);
		$size     = filesize($resolved);

		if ($size === false || $size > $maxBytes) {
			throw new YamlException(sprintf('File "%s" exceeds the %d byte safety limit.', $path, $maxBytes));
		}

		$raw = file_get_contents($resolved, false, null, 0, $maxBytes + 1);

		if ($raw === false || strlen($raw) > $maxBytes) {
			throw new YamlException(sprintf('Unable to read file "%s" within the %d byte limit.', $path, $maxBytes));
		}

		return $raw;
	}

	/**
	 * Atomically write content inside an already validated target path.
	 *
	 * @param string $targetPath Absolute destination path (must not be a symlink).
	 * @param string $content File contents to write.
	 * @return string SHA-256 digest of the written content.
	 * @throws YamlException When the destination is a symlink or write fails.
	 */
	public static function writeAtomic(string $targetPath, string $content): string {
		if (is_link($targetPath)) {
			throw new YamlException(sprintf('Refusing to overwrite symlink "%s".', $targetPath));
		}

		$dir = dirname($targetPath);

		if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
			throw new YamlException(sprintf('Unable to create directory "%s".', $dir));
		}

		$tmp = tempnam($dir, '.yaml_tmp_');

		if ($tmp === false) {
			throw new YamlException(sprintf('Unable to allocate temporary file in "%s".', $dir));
		}

		$written = file_put_contents($tmp, $content, LOCK_EX);

		if ($written === false || $written !== strlen($content)) {
			@unlink($tmp);

			throw new YamlException(sprintf('Failed writing content to "%s".', $targetPath));
		}

		@chmod($tmp, 0644);

		if (!rename($tmp, $targetPath)) {
			@unlink($tmp);

			throw new YamlException(sprintf('Failed moving temporary file into "%s".', $targetPath));
		}

		return hash('sha256', $content);
	}
}
