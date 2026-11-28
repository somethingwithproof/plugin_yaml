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
 * Scalar conflict policy used when merging multiple YAML fragments.
 */
enum MergePolicy: string {
	/**
	 * Fail hard when two fragments assign different values to the same scalar path.
	 */
	case STRICT = 'strict';

	/**
	 * Allow later fragments in lexical order to override earlier scalar values.
	 */
	case OVERLAY = 'overlay';

	/**
	 * List merge mode replacing the previous list wholesale.
	 */
	public const LIST_REPLACE = 'replace';

	/**
	 * List merge mode appending items from later fragments.
	 */
	public const LIST_APPEND = 'append';

	/**
	 * List merge mode deep-merging associative list entries by natural key.
	 */
	public const LIST_MERGE_BY_KEY = 'mergeByKey';

	/**
	 * Return all valid list merge mode identifiers.
	 *
	 * @return list<string>
	 */
	public static function listModes(): array {
		return [
			self::LIST_REPLACE,
			self::LIST_APPEND,
			self::LIST_MERGE_BY_KEY,
		];
	}
}
