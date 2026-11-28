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
$yaml_action  = isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';

if ($yaml_action === 'download_schema') {
	$schema_json = $yaml_service->generateSchema()['json'];

	header('Content-Type: application/schema+json; charset=UTF-8');
	header('Content-Disposition: attachment; filename="cacti-casc.schema.json"');
	header('X-Content-Type-Options: nosniff');
	header('Content-Length: ' . strlen($schema_json));

	print $schema_json;
	exit;
}

$yaml_redacted = $yaml_service->exportRedactedState();
$yaml_schemas  = $yaml_service->discoverSchemas();
$yaml_packs    = $yaml_service->repository()->listPacks();
$yaml_audits   = $yaml_service->repository()->getRecentAudits(50);

top_header();
html_start_box(__('YAML Schema, Reference & Redacted State', 'yaml'), '100%', false, 3, 'center', '');
print '<tr><td>' . \Somethingwithproof\Yaml\Ui\PagesRenderer::renderViewPage($yaml_redacted, $yaml_schemas, $yaml_packs, $yaml_audits) . '</td></tr>';
html_end_box();
bottom_footer();
