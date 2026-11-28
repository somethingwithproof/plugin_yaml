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

chdir('../../');
include('./include/auth.php');
include_once('./plugins/yaml/setup.php');

if (!yaml_page_start()) {
	exit;
}

$yaml_service = yaml_service();
$yaml_post    = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$yaml_action  = $yaml_post && isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';
$yaml_preview = null;
$yaml_sha256  = null;
$yaml_status  = null;
$yaml_errors  = array();

if ($yaml_post && !yaml_verify_csrf()) {
	$yaml_errors[] = __('CSRF token validation failed. Reload the page and retry.', 'yaml');
} elseif ($yaml_action === 'compile_preview' || $yaml_action === 'validate_yaml') {
	$yaml_text = isset_request_var('yaml_content') ? (string) get_nfilter_request_var('yaml_content') : '';

	if (trim($yaml_text) === '') {
		$yaml_errors[] = __('Provide a YAML document to validate or compile.', 'yaml');
	} else {
		try {
			if ($yaml_action === 'compile_preview') {
				$compiled     = $yaml_service->compileYaml($yaml_text);
				$yaml_preview = $compiled['xml'];
				$yaml_sha256  = $compiled['sha256'];
				$yaml_status  = sprintf(__('Compiled %s successfully.', 'yaml'), $compiled['kind']);
			} else {
				$doc          = \Somethingwithproof\Yaml\Codec\YamlParser::parseMap($yaml_text);
				$invariants   = \Somethingwithproof\Yaml\Validation\CrossParameterValidator::validate($doc);
				$placeholders = \Somethingwithproof\Yaml\Merge\PlaceholderParser::inspect($doc);
				$yaml_errors  = array_merge($invariants, $placeholders['errors']);

				if ($yaml_errors === array()) {
					$yaml_preview = \Somethingwithproof\Yaml\Codec\YamlEmitter::emit(
						\Somethingwithproof\Yaml\Merge\PlaceholderParser::redact($doc),
						false
					);
					$yaml_sha256 = hash('sha256', $yaml_preview);
					$yaml_status = __('YAML syntax, placeholders, and cross-parameter invariants passed.', 'yaml');
				}
			}
		} catch (\Somethingwithproof\Yaml\Codec\YamlException $e) {
			$yaml_errors = $e->lines();
		}
	}
}

top_header();
html_start_box(__('YAML Validate, Compile & Dry-Run Preview (Operator)', 'yaml'), '100%', false, 3, 'center', '');
print '<tr><td>';
print '<form method="post" action="yaml_operator.php">'
	. '<label for="yaml_content">' . html_escape(__('YAML Template, Data Query, or CasC Fragment:', 'yaml')) . '</label><br>'
	. '<textarea id="yaml_content" name="yaml_content" rows="16" cols="100"></textarea><br>'
	. '<button type="submit" name="action" value="validate_yaml" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Validate & Redact', 'yaml')) . '</button> '
	. '<button type="submit" name="action" value="compile_preview" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Compile to Cacti XML', 'yaml')) . '</button>'
	. '</form>';
print \Somethingwithproof\Yaml\Ui\PagesRenderer::renderOperatorResult($yaml_preview, $yaml_sha256, $yaml_errors, $yaml_status);
print '</td></tr>';
html_end_box();
bottom_footer();
