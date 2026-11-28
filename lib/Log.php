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
 * Structured wrapper around Cacti's cacti_log() for plugin_yaml events.
 */
final class Log {
	/**
	 * Log environment prefix used in Cacti's cacti.log output.
	 */
	private const ENVIRON = 'YAML';

	/**
	 * Record an informational log message.
	 *
	 * @param string $message Sanitized message without secret values.
	 * @return void
	 */
	public static function info(string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log('NOTE: ' . $message, false, self::ENVIRON);
		}
	}

	/**
	 * Record a warning log message.
	 *
	 * @param string $message Sanitized warning message without secret values.
	 * @return void
	 */
	public static function warning(string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log('WARNING: ' . $message, false, self::ENVIRON);
		}
	}

	/**
	 * Record an error log message.
	 *
	 * @param string $message Sanitized error message without secret values.
	 * @return void
	 */
	public static function error(string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log('ERROR: ' . $message, false, self::ENVIRON);
		}
	}
}
