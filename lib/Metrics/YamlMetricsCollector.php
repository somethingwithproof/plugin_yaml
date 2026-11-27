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

namespace Somethingwithproof\Yaml\Metrics;

use Somethingwithproof\Yaml\Service\YamlService;

/**
 * Collects safe, non-secret operational telemetry for Cacti self-graphing via
 * `scripts/ss_yaml_stats.php` in standard `field:value` format.
 */
final class YamlMetricsCollector {
	/**
	 * Ordered list of Cacti RRDtool data source fields emitted by this collector.
	 *
	 * @var list<string>
	 */
	public const FIELDS = [
		'packs_total',
		'packs_valid',
		'schema_providers',
		'schema_properties',
		'last_compile_ms',
		'last_merge_ms',
		'validation_errors',
	];

	/**
	 * Optional pack-row provider overriding repository lookups during unit tests.
	 *
	 * @var (callable(): list<array<string, mixed>>)|null
	 */
	private readonly mixed $packsProvider;

	/**
	 * Optional audit-row provider overriding repository lookups during unit tests.
	 *
	 * @var (callable(): list<array<string, mixed>>)|null
	 */
	private readonly mixed $auditsProvider;

	/**
	 * Construct a metrics collector backed by a YamlService instance.
	 *
	 * @param YamlService $service Active plugin_yaml service.
	 * @param (callable(): list<array<string, mixed>>)|null $packsProvider Optional override returning pack rows.
	 * @param (callable(): list<array<string, mixed>>)|null $auditsProvider Optional override returning recent audit rows.
	 */
	public function __construct(
		/**
		 * Active plugin_yaml service.
		 */
		private readonly YamlService $service,
		?callable $packsProvider = null,
		?callable $auditsProvider = null,
	) {
		$this->packsProvider  = $packsProvider;
		$this->auditsProvider = $auditsProvider;
	}

	/**
	 * Collect all numeric telemetry fields without exposing paths, credentials, or secrets.
	 *
	 * @return array{
	 *   packs_total: int,
	 *   packs_valid: int,
	 *   schema_providers: int,
	 *   schema_properties: int,
	 *   last_compile_ms: float,
	 *   last_merge_ms: float,
	 *   validation_errors: int
	 * }
	 */
	public function collect(): array {
		$packs   = $this->packsProvider !== null ? ($this->packsProvider)() : $this->service->repository()->listPacks();
		$audits  = $this->auditsProvider !== null ? ($this->auditsProvider)() : $this->service->repository()->getRecentAudits(25);
		$schemas = $this->service->discoverSchemas();

		$packsTotal = count($packs);
		$packsValid = 0;

		foreach ($packs as $pack) {
			$status = is_string($pack['status'] ?? null) ? strtolower(trim($pack['status'])) : '';

			if ($status === 'applied' || $status === 'valid') {
				$packsValid++;
			}
		}

		$schemaProviders  = count($schemas);
		$schemaProperties = 0;

		foreach ($schemas as $provider) {
			$schemaProperties += count($provider['config']) + count($provider['state']);
		}

		$auditTelemetry   = self::extractAuditTelemetry($audits);
		$lastCompileMs    = $this->service->lastCompileMs() ?? $auditTelemetry['last_compile_ms'];
		$lastMergeMs      = $this->service->lastMergeMs() ?? $auditTelemetry['last_merge_ms'];
		$validationErrors = $this->service->lastValidationErrors() ?? $auditTelemetry['validation_errors'];

		return [
			'packs_total'       => max(0, $packsTotal),
			'packs_valid'       => max(0, $packsValid),
			'schema_providers'  => max(0, $schemaProviders),
			'schema_properties' => max(0, $schemaProperties),
			'last_compile_ms'   => round(max(0.0, $lastCompileMs), 2),
			'last_merge_ms'     => round(max(0.0, $lastMergeMs), 2),
			'validation_errors' => max(0, $validationErrors),
		];
	}

	/**
	 * Collect and render telemetry in Cacti script-server `field:value` format,
	 * or return a single metric value when `$metric` is specified.
	 *
	 * Returns RRDtool unknown (`U`) values on any collection error so graphs show
	 * a gap instead of corrupted zeros.
	 *
	 * @param string $metric Optional single metric field name from self::FIELDS.
	 * @return string Cacti script-server output line.
	 */
	public function render(string $metric = ''): string {
		try {
			$metrics = $this->collect();
		} catch (\Throwable) {
			return self::unknownOutput($metric);
		}

		if ($metric !== '') {
			if (!array_key_exists($metric, $metrics)) {
				return 'U';
			}

			return self::formatMetricValue($metric, $metrics[$metric]);
		}

		return self::formatCactiOutput($metrics);
	}

	/**
	 * Format a collected metric array into a space-delimited Cacti `field:value` line.
	 *
	 * @param array{
	 *   packs_total: int,
	 *   packs_valid: int,
	 *   schema_providers: int,
	 *   schema_properties: int,
	 *   last_compile_ms: float,
	 *   last_merge_ms: float,
	 *   validation_errors: int
	 * } $metrics Collected metrics map.
	 * @return string Space-delimited `field:value` string.
	 */
	public static function formatCactiOutput(array $metrics): string {
		$parts = [];

		foreach (self::FIELDS as $field) {
			$value   = $metrics[$field] ?? 0;
			$parts[] = $field . ':' . self::formatMetricValue($field, $value);
		}

		return implode(' ', $parts);
	}

	/**
	 * Build an RRDtool unknown (`U`) output line for error or unsupported-runtime paths.
	 *
	 * @param string $metric Optional single metric field name.
	 * @return string Either `'U'` or space-delimited `field:U` pairs.
	 */
	public static function unknownOutput(string $metric = ''): string {
		if ($metric !== '') {
			return 'U';
		}

		$parts = [];

		foreach (self::FIELDS as $field) {
			$parts[] = $field . ':U';
		}

		return implode(' ', $parts);
	}

	/**
	 * Format an individual metric value consistently for Cacti RRDtool ingestion.
	 *
	 * @param string $field Metric name.
	 * @param int|float $value Numeric metric value.
	 * @return string Formatted numeric token.
	 */
	private static function formatMetricValue(string $field, int|float $value): string {
		if ($field === 'last_compile_ms' || $field === 'last_merge_ms') {
			return sprintf('%.2f', max(0.0, (float) $value));
		}

		return (string) max(0, (int) $value);
	}

	/**
	 * Extract timing and validation error counters from recent sanitized audit records.
	 *
	 * @param list<array<string, mixed>> $audits Recent rows from plugin_yaml_audit (newest first).
	 * @return array{last_compile_ms: float, last_merge_ms: float, validation_errors: int}
	 */
	private static function extractAuditTelemetry(array $audits): array {
		$lastCompileMs    = null;
		$lastMergeMs      = null;
		$validationErrors = null;

		foreach ($audits as $row) {
			$rawSummary = is_string($row['summary_json'] ?? null) ? $row['summary_json'] : '';
			$summary    = [];

			if ($rawSummary !== '') {
				$decoded = json_decode($rawSummary, true);

				if (is_array($decoded)) {
					$summary = $decoded;
				}
			}

			if ($lastCompileMs === null && is_numeric($summary['compileMs'] ?? null)) {
				$lastCompileMs = max(0.0, (float) $summary['compileMs']);
			}

			if ($lastMergeMs === null && is_numeric($summary['mergeMs'] ?? null)) {
				$lastMergeMs = max(0.0, (float) $summary['mergeMs']);
			}

			if ($validationErrors === null) {
				if (isset($summary['errors']) && is_array($summary['errors'])) {
					$validationErrors = count($summary['errors']);
				} elseif (is_string($row['status'] ?? null) && strtolower($row['status']) === 'failed') {
					$validationErrors = 1;
				}
			}

			if ($lastCompileMs !== null && $lastMergeMs !== null && $validationErrors !== null) {
				break;
			}
		}

		return [
			'last_compile_ms'   => $lastCompileMs ?? 0.0,
			'last_merge_ms'     => $lastMergeMs ?? 0.0,
			'validation_errors' => $validationErrors ?? 0,
		];
	}
}
