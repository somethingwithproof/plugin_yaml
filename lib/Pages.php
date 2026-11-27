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

namespace Somethingwithproof\Yaml;

/**
 * 3-tier RBAC realms, Console menu entries, and breadcrumbs for plugin_yaml.
 */
final class Pages {
	/**
	 * Read-only View Console page filename.
	 */
	public const VIEW = 'yaml_view.php';

	/**
	 * Operator validation and dry-run Console page filename.
	 */
	public const OPERATOR = 'yaml_operator.php';

	/**
	 * Administrator reload and pack management Console page filename.
	 */
	public const ADMIN = 'yaml_admin.php';

	/**
	 * Return Cacti plugin realm definitions for 3-tier RBAC.
	 *
	 * @return list<array{name: string, files: list<string>, default: bool}>
	 */
	public static function realms(): array {
		return [
			['name' => __('YAML View', 'yaml'), 'files' => [self::VIEW], 'default' => false],
			['name' => __('YAML Operator', 'yaml'), 'files' => [self::OPERATOR], 'default' => false],
			['name' => __('YAML Admin', 'yaml'), 'files' => [self::ADMIN], 'default' => true],
		];
	}

	/**
	 * Return all Console page filenames registered by plugin_yaml.
	 *
	 * @return list<string>
	 */
	public static function files(): array {
		return [self::VIEW, self::OPERATOR, self::ADMIN];
	}

	/**
	 * Return Console Import/Export menu entries keyed by page filename.
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			self::VIEW     => __('YAML Schema & Reference', 'yaml'),
			self::OPERATOR => __('YAML Validate & Compile', 'yaml'),
			self::ADMIN    => __('YAML Reload & Packs', 'yaml'),
		];
	}

	/**
	 * Return page names that trigger idempotent plugin upgrade checks.
	 *
	 * @return list<string>
	 */
	public static function upgradeTriggers(): array {
		return array_merge(['plugins.php'], self::files());
	}

	/**
	 * Return Cacti navigation breadcrumb metadata for Console pages.
	 *
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [];

		foreach (self::menu() as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
