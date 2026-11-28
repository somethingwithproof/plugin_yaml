#!/usr/bin/env php
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
 * Cacti Script Server entry point for plugin_yaml operational telemetry.
 * Kept strictly PHP 8.0 parseable so Cacti script_server.php never encounters
 * a syntax error on PHP 8.0/8.1 hosts; returns RRDtool unknown ('U') on
 * unsupported PHP runtimes or collection errors.
 */

error_reporting(0);

if (!isset($called_by_script_server)) {
	if (PHP_SAPI !== 'cli') {
		exit(1);
	}

	$dir = __DIR__;
	chdir($dir . '/../../..');

	include_once('./include/cli_check.php');
	include_once($config['base_path'] . '/plugins/yaml/setup.php');

	$metric = isset($argv[1]) && is_string($argv[1]) ? trim($argv[1]) : '';
	print call_user_func('ss_yaml_stats', $metric) . PHP_EOL;
}

/**
 * Return plugin_yaml operational telemetry in Cacti `field:value` format,
 * or a single numeric metric value when `$metric` is supplied.
 *
 * @param string $metric Optional single metric field name.
 * @return string Space-delimited `field:value` line or single metric / 'U'.
 */
function ss_yaml_stats(string $metric = ''): string {
	$unknown = $metric !== ''
		? 'U'
		: 'packs_total:U packs_valid:U schema_providers:U schema_properties:U last_compile_ms:U last_merge_ms:U validation_errors:U';

	$setupPath = __DIR__ . '/../setup.php';

	if (!function_exists('yaml_boot') && is_file($setupPath)) {
		require_once($setupPath);
	}

	if (!function_exists('yaml_boot') || !yaml_boot()) {
		return $unknown;
	}

	try {
		$collector = new \Somethingwithproof\Yaml\Metrics\YamlMetricsCollector(yaml_service());

		return $collector->render(trim($metric));
	} catch (\Throwable $error) {
		return $unknown;
	}
}
