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

$GLOBALS['__yaml_log']      = [];
$GLOBALS['__yaml_options']  = [];
$GLOBALS['__yaml_db_mock']  = null;

if (!function_exists('__')) {
	/**
	 * Minimal unit-test stub for Cacti's translation helper.
	 *
	 * @return string
	 */
	function __() {
		$args = func_get_args();

		if (count($args) === 1 || preg_match('/%(%|c|[-0-9.]*[bdeEfFgGhHosuxX])/', (string) $args[0]) !== 1) {
			return (string) $args[0];
		}

		return call_user_func_array('sprintf', $args);
	}
}

if (!function_exists('cacti_log')) {
	/**
	 * Minimal unit-test stub capturing cacti_log calls.
	 *
	 * @param mixed $string Log message.
	 * @param bool $output Echo flag.
	 * @param string $environ Subsystem prefix.
	 * @param string $level Severity level.
	 * @return bool
	 */
	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
		$GLOBALS['__yaml_log'][] = ['message' => (string) $string, 'environ' => (string) $environ];

		return true;
	}
}

if (!function_exists('html_escape')) {
	/**
	 * Minimal unit-test stub for Cacti's html_escape().
	 *
	 * @param mixed $string Raw value.
	 * @return string
	 */
	function html_escape($string) {
		return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8', false);
	}
}

if (!function_exists('read_config_option')) {
	/**
	 * Minimal unit-test stub for Cacti's read_config_option().
	 *
	 * @param string $config_name Setting key.
	 * @param bool $force Force DB reload flag.
	 * @return mixed
	 */
	function read_config_option($config_name, $force = false) {
		return $GLOBALS['__yaml_options'][$config_name] ?? '';
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	/**
	 * Minimal unit-test stub delegating to $__yaml_db_mock when configured.
	 *
	 * @param string $sql SQL query.
	 * @param array<int, mixed> $params Bound parameters.
	 * @param string $col Column name.
	 * @param bool $log Log flag.
	 * @param mixed $db_conn Optional connection.
	 * @return mixed
	 */
	function db_fetch_cell_prepared($sql, $params = [], $col = '', $log = true, $db_conn = false) {
		$mock = $GLOBALS['__yaml_db_mock'] ?? null;

		return is_object($mock) && method_exists($mock, 'fetchCell') ? $mock->fetchCell($sql, $params) : false;
	}
}

if (!function_exists('db_fetch_row_prepared')) {
	/**
	 * Minimal unit-test stub delegating to $__yaml_db_mock when configured.
	 *
	 * @param string $sql SQL query.
	 * @param array<int, mixed> $params Bound parameters.
	 * @param bool $log Log flag.
	 * @param mixed $db_conn Optional connection.
	 * @return mixed
	 */
	function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false) {
		$mock = $GLOBALS['__yaml_db_mock'] ?? null;

		return is_object($mock) && method_exists($mock, 'fetchRow') ? $mock->fetchRow($sql, $params) : [];
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	/**
	 * Minimal unit-test stub delegating to $__yaml_db_mock when configured.
	 *
	 * @param string $sql SQL query.
	 * @param array<int, mixed> $params Bound parameters.
	 * @param bool $log Log flag.
	 * @param mixed $db_conn Optional connection.
	 * @return mixed
	 */
	function db_fetch_assoc_prepared($sql, $params = [], $log = true, $db_conn = false) {
		$mock = $GLOBALS['__yaml_db_mock'] ?? null;

		return is_object($mock) && method_exists($mock, 'fetchAssoc') ? $mock->fetchAssoc($sql, $params) : [];
	}
}

if (!function_exists('db_execute_prepared')) {
	/**
	 * Minimal unit-test stub delegating to $__yaml_db_mock when configured.
	 *
	 * @param string $sql SQL query.
	 * @param array<int, mixed> $params Bound parameters.
	 * @param bool $log Log flag.
	 * @param mixed $db_conn Optional connection.
	 * @return bool
	 */
	function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false) {
		$mock = $GLOBALS['__yaml_db_mock'] ?? null;

		return is_object($mock) && method_exists($mock, 'execute') ? (bool) $mock->execute($sql, $params) : true;
	}
}

if (!function_exists('db_fetch_insert_id')) {
	/**
	 * Minimal unit-test stub returning the last simulated insert ID.
	 *
	 * @param mixed $db_conn Optional connection.
	 * @return int
	 */
	function db_fetch_insert_id($db_conn = false) {
		$mock = $GLOBALS['__yaml_db_mock'] ?? null;

		return is_object($mock) && method_exists($mock, 'insertId') ? (int) $mock->insertId() : 0;
	}
}

if (!function_exists('api_plugin_db_table_create')) {
	/**
	 * Minimal unit-test stub delegating table creation to $__yaml_db_mock.
	 *
	 * @param string $plugin Plugin slug.
	 * @param string $table Table name.
	 * @param array<string, mixed> $data Table definition.
	 * @return void
	 */
	function api_plugin_db_table_create($plugin, $table, $data) {
		$mock = $GLOBALS['__yaml_db_mock'] ?? null;

		if (is_object($mock) && method_exists($mock, 'createTable')) {
			$mock->createTable($plugin, $table, $data);
		}
	}
}

$GLOBALS['config'] = [
	'base_path'    => dirname(__DIR__, 3),
	'library_path' => dirname(__DIR__, 3) . '/lib',
	'poller_id'    => 1,
];

require_once dirname(__DIR__) . '/include/autoload.php';
require_once dirname(__DIR__) . '/setup.php';
