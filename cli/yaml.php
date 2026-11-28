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

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$dir = __DIR__;
chdir($dir . '/../../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/yaml/setup.php');

if (yaml_refuse_unsupported_php()) {
	exit(1);
}

yaml_boot();

$args = array_slice($argv, 1);
$user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
	? ((posix_getpwuid(posix_geteuid())['name'] ?? '') ?: 'cli')
	: 'cli';

$runner = new \Somethingwithproof\Yaml\Cli\CliRunner(yaml_service());
$result = $runner->run($args, (string) $user);

if ($result['stdout'] !== '') {
	fwrite(STDOUT, $result['stdout']);
}

if ($result['stderr'] !== '') {
	fwrite(STDERR, $result['stderr']);
}

exit($result['exitCode']);
