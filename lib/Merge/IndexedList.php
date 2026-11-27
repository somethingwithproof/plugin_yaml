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
 * Internal hash-indexed accumulator for keyed object lists during multi-fragment
 * mergeByKey passes.
 *
 * Retains insertion order natively through PHP's ordered hash table while
 * avoiding O(F * N) re-indexing across repeated fragment merges. Materialized
 * back to a plain sequential list once all fragments are merged.
 *
 * @internal
 */
final class IndexedList {
	/**
	 * Natural identity key shared by all items in this list.
	 */
	public readonly string $naturalKey;

	/**
	 * Map of prefixed natural key IDs ("\0" . $id) to associative item mappings.
	 *
	 * The leading null-byte prefix prevents PHP from coercing numeric string IDs
	 * such as "01" and "1" into colliding integer array keys.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $items;

	/**
	 * Construct an indexed list accumulator.
	 *
	 * @param string $naturalKey Natural identity key name.
	 * @param array<string, array<string, mixed>> $items Initial indexed items.
	 */
	public function __construct(string $naturalKey, array $items = []) {
		$this->naturalKey = $naturalKey;
		$this->items      = $items;
	}

	/**
	 * Materialize the indexed items into a sequential PHP list preserving insertion order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function toList(): array {
		return array_values($this->items);
	}
}
