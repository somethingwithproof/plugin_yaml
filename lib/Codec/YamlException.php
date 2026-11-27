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

namespace Somethingwithproof\Yaml\Codec;

/**
 * Structured exception raised when YAML parsing, emitting, merging, or
 * compilation encounters invalid syntax, security violations, or schema errors.
 */
final class YamlException extends \RuntimeException {
	/**
	 * Ordered list of human-readable diagnostic messages.
	 *
	 * @var list<string>
	 */
	private readonly array $errors;

	/**
	 * Construct a YamlException with one primary message and optional detail lines.
	 *
	 * @param string $message Summary error message.
	 * @param list<string> $errors Additional structured error lines.
	 * @param int $code Optional numeric error code.
	 * @param ?\Throwable $previous Optional underlying exception.
	 */
	public function __construct(string $message, array $errors = [], int $code = 0, ?\Throwable $previous = null) {
		parent::__construct($message, $code, $previous);
		$this->errors = $errors !== [] ? array_values($errors) : [$message];
	}

	/**
	 * Return all structured error lines associated with this exception.
	 *
	 * @return list<string>
	 */
	public function lines(): array {
		return $this->errors;
	}
}
