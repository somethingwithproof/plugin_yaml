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

/*
 * Zero-dependency PSR-4 class loader for lib/. Required only by yaml_boot()
 * after the PHP version check, so class files may use PHP 8.2 syntax while
 * this file stays PHP 8.0 parseable.
 */

spl_autoload_register(
	/**
	 * Load a Somethingwithproof\Yaml class from lib/.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	function ($class) {
		$prefix = 'Somethingwithproof\\Yaml\\';

		if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
			return;
		}

		$relative = substr($class, strlen($prefix));

		if ($relative === '' || strpos($relative, '..') !== false) {
			return;
		}

		$file = dirname(__DIR__) . '/lib/' . str_replace('\\', '/', $relative) . '.php';

		if (is_file($file)) {
			require_once($file);
		}
	}
);
