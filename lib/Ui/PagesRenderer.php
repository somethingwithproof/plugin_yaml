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

namespace Somethingwithproof\Yaml\Ui;

use Somethingwithproof\Yaml\Schema\ReferenceRenderer;

/**
 * XSS-safe HTML renderers for plugin_yaml's 3-tier Console pages.
 */
final class PagesRenderer {
	/**
	 * Render the View page body: redacted state YAML, Reference Documentation,
	 * installed packs, and recent audit log.
	 *
	 * @param string $redactedYaml Redacted YAML state preview.
	 * @param array<string, array{plugin: string, schemaVersion: string, title: string, description: string, config: array<string, mixed>, state: array<string, mixed>, source: string}> $schemas Discovered plugin schemas.
	 * @param list<array<string, mixed>> $packs Installed Monitoring Packs.
	 * @param list<array<string, mixed>> $audits Recent audit rows.
	 * @return string Escaped HTML markup.
	 */
	public static function renderViewPage(
		string $redactedYaml,
		array $schemas,
		array $packs,
		array $audits,
	): string {
		$html = '<h3>' . self::esc('Current Configuration (Redacted YAML)') . '</h3>'
			. '<pre class="yaml-code">' . self::esc($redactedYaml) . '</pre>'
			. '<p><a class="linkEditMain" href="yaml_view.php?action=download_schema">'
			. self::esc('Download cacti-casc.schema.json (Draft 2020-12)')
			. '</a></p>';

		$html .= '<h3>' . self::esc('Schema Reference') . '</h3>'
			. ReferenceRenderer::renderHtml($schemas);

		$html .= '<h3>' . self::esc('Installed Monitoring Packs') . '</h3>';

		if ($packs === []) {
			$html .= '<p>' . self::esc('No Monitoring Packs have been applied yet.') . '</p>';
		} else {
			$html .= '<table class="cactiTable"><thead><tr>'
				. '<th>' . self::esc('Pack') . '</th>'
				. '<th>' . self::esc('Version') . '</th>'
				. '<th>' . self::esc('Status') . '</th>'
				. '<th>' . self::esc('Lock SHA-256') . '</th>'
				. '</tr></thead><tbody>';

			foreach ($packs as $pack) {
				$html .= '<tr>'
					. '<td>' . self::esc(is_scalar($pack['name'] ?? null) ? (string) $pack['name'] : '') . '</td>'
					. '<td>' . self::esc(is_scalar($pack['version'] ?? null) ? (string) $pack['version'] : '') . '</td>'
					. '<td>' . self::esc(is_scalar($pack['status'] ?? null) ? (string) $pack['status'] : '') . '</td>'
					. '<td><code>' . self::esc(substr(is_scalar($pack['lock_sha256'] ?? null) ? (string) $pack['lock_sha256'] : '', 0, 16)) . '</code></td>'
					. '</tr>';
			}

			$html .= '</tbody></table>';
		}

		$html .= '<h3>' . self::esc('Recent Audit History') . '</h3>';

		if ($audits === []) {
			$html .= '<p>' . self::esc('No reload or pack actions recorded yet.') . '</p>';
		} else {
			$html .= '<table class="cactiTable"><thead><tr>'
				. '<th>' . self::esc('Actor') . '</th>'
				. '<th>' . self::esc('Source') . '</th>'
				. '<th>' . self::esc('Action') . '</th>'
				. '<th>' . self::esc('Status') . '</th>'
				. '<th>' . self::esc('Plan Hash') . '</th>'
				. '</tr></thead><tbody>';

			foreach ($audits as $audit) {
				$html .= '<tr>'
					. '<td>' . self::esc(is_scalar($audit['actor'] ?? null) ? (string) $audit['actor'] : '') . '</td>'
					. '<td>' . self::esc(is_scalar($audit['source'] ?? null) ? (string) $audit['source'] : '') . '</td>'
					. '<td>' . self::esc(is_scalar($audit['action'] ?? null) ? (string) $audit['action'] : '') . '</td>'
					. '<td>' . self::esc(is_scalar($audit['status'] ?? null) ? (string) $audit['status'] : '') . '</td>'
					. '<td><code>' . self::esc(substr(is_scalar($audit['plan_hash'] ?? null) ? (string) $audit['plan_hash'] : '', 0, 16)) . '</code></td>'
					. '</tr>';
			}

			$html .= '</tbody></table>';
		}

		return $html;
	}

	/**
	 * Render validation results, compiled XML preview, or error diagnostics.
	 *
	 * @param ?string $compiledXml Optional compiled XML output.
	 * @param ?string $sha256 Optional SHA-256 digest of compiled output or plan hash.
	 * @param list<string> $errors Validation or compilation errors.
	 * @param ?string $statusMessage Optional informational status line.
	 * @return string Escaped HTML markup.
	 */
	public static function renderOperatorResult(
		?string $compiledXml,
		?string $sha256,
		array $errors,
		?string $statusMessage = null,
	): string {
		$html = '';

		if ($errors !== []) {
			$html .= '<div class="yaml-errors"><h4>' . self::esc('Validation Errors') . '</h4><ul>';

			foreach ($errors as $error) {
				$html .= '<li>' . self::esc($error) . '</li>';
			}

			$html .= '</ul></div>';

			return $html;
		}

		if ($statusMessage !== null && $statusMessage !== '') {
			$html .= '<p><strong>' . self::esc($statusMessage) . '</strong></p>';
		}

		if ($sha256 !== null && $sha256 !== '') {
			$html .= '<p>' . self::esc('SHA-256:') . ' <code>' . self::esc($sha256) . '</code></p>';
		}

		if ($compiledXml !== null && $compiledXml !== '') {
			$html .= '<h4>' . self::esc('Compiled Preview') . '</h4>'
				. '<pre class="yaml-code">' . self::esc($compiledXml) . '</pre>';
		}

		return $html;
	}

	/**
	 * Render the Admin reload plan preview with a confirmation hash apply form.
	 *
	 * @param ?string $planHash Computed plan hash when dry-run succeeded.
	 * @param string $redactedYaml Redacted merged YAML preview.
	 * @param list<string> $errors Validation or apply errors.
	 * @param ?string $statusMessage Informational message.
	 * @return string Escaped HTML markup.
	 */
	public static function renderAdminPlan(
		?string $planHash,
		string $redactedYaml,
		array $errors,
		?string $statusMessage = null,
	): string {
		$html = self::renderOperatorResult(
			$redactedYaml !== '' ? $redactedYaml : null,
			$planHash,
			$errors,
			$statusMessage
		);

		if ($errors === [] && $planHash !== null && $planHash !== '') {
			$html .= '<form method="post" action="yaml_admin.php">'
				. '<input type="hidden" name="action" value="apply">'
				. '<input type="hidden" name="plan_hash" value="' . self::esc($planHash) . '">'
				. '<button type="submit" class="ui-button ui-corner-all ui-widget">'
				. self::esc('Confirm & Apply Reload (' . substr($planHash, 0, 12) . ')')
				. '</button>'
				. '</form>';
		}

		return $html;
	}

	/**
	 * Escape HTML content safely.
	 *
	 * @param string $value Raw string.
	 * @return string Escaped HTML string.
	 */
	private static function esc(string $value): string {
		if (function_exists('html_escape')) {
			return (string) html_escape($value);
		}

		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
	}
}
