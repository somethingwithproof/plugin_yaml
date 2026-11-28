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

$yaml_service  = yaml_service();
$yaml_post     = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$yaml_action   = $yaml_post && isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';
$yaml_user_id  = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;
$yaml_actor    = $yaml_user_id > 0 ? 'user:' . $yaml_user_id : 'web';
$yaml_planHash = null;
$yaml_preview  = '';
$yaml_status   = null;
$yaml_errors   = array();

if ($yaml_post && !yaml_verify_csrf()) {
	$yaml_errors[] = __('CSRF token validation failed. Reload the page and retry.', 'yaml');
} elseif ($yaml_action === 'plan') {
	unset($_SESSION['yaml_pending_reload']);
	$sources = $yaml_service->settings()->sourcePaths;

	if ($sources === array()) {
		$yaml_errors[] = __('Configure yaml_source_paths in Cacti Settings before running reload.', 'yaml');
	} else {
		$result = $yaml_service->reload($sources, true, '', $yaml_actor, 'web');

		if (!$result['succeeded']) {
			$yaml_errors = $result['errors'];
		} else {
			$yaml_planHash = $result['planHash'];
			$yaml_preview  = $result['redactedYaml'];
			$yaml_status   = $result['message'];

			$_SESSION['yaml_pending_reload'] = array(
				'user'    => $yaml_user_id,
				'sources' => $sources,
				'hash'    => $result['planHash'],
			);
		}
	}
} elseif ($yaml_action === 'apply') {
	$pending   = $_SESSION['yaml_pending_reload'] ?? null;
	$submitted = isset_request_var('plan_hash') ? (string) get_nfilter_request_var('plan_hash') : '';

	unset($_SESSION['yaml_pending_reload']);

	if (!is_array($pending) || ($pending['user'] ?? -1) !== $yaml_user_id || !hash_equals((string) ($pending['hash'] ?? ''), $submitted)) {
		$yaml_errors[] = __('No matching pending reload plan was found in your session. Compute a fresh plan first.', 'yaml');
	} else {
		$sources = is_array($pending['sources'] ?? null) ? $pending['sources'] : array();
		$result  = $yaml_service->reload($sources, false, $submitted, $yaml_actor, 'web');

		if ($result['succeeded']) {
			$yaml_status  = $result['message'];
			$yaml_preview = $result['redactedYaml'];

			if (function_exists('raise_message')) {
				raise_message('yaml_reload', $result['message'], MESSAGE_LEVEL_INFO);
			}
		} else {
			$yaml_errors = $result['errors'];
		}
	}
}

top_header();
html_start_box(__('YAML Reload & Monitoring Packs (Administrator)', 'yaml'), '100%', false, 3, 'center', '');
print '<tr><td>';
print '<form method="post" action="yaml_admin.php">'
	. '<input type="hidden" name="action" value="plan">'
	. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Compute Reload Plan from Configured Sources', 'yaml')) . '</button>'
	. '</form>';
print \Somethingwithproof\Yaml\Ui\PagesRenderer::renderAdminPlan($yaml_planHash, $yaml_preview, $yaml_errors, $yaml_status);
print '</td></tr>';
html_end_box();
bottom_footer();
