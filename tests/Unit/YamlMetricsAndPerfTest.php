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

namespace Somethingwithproof\Yaml\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Yaml\Merge\FragmentMerger;
use Somethingwithproof\Yaml\Merge\MergePolicy;
use Somethingwithproof\Yaml\Metrics\YamlMetricsCollector;
use Somethingwithproof\Yaml\Repository\YamlStateRepository;
use Somethingwithproof\Yaml\Service\YamlService;
use Somethingwithproof\Yaml\Settings;

/**
 * Unit tests for YamlMetricsCollector, script-server formatting, and
 * multi-fragment indexed list merging edge cases.
 */
final class YamlMetricsAndPerfTest extends TestCase {
	/**
	 * Verify Cacti script-server output formatting, audit fallback, and single-metric lookup.
	 *
	 * @return void
	 */
	public function testYamlMetricsCollectorRendersAllCactiFieldsAndHandlesErrors(): void {
		$pluginRoot = dirname(__DIR__, 2);
		$service    = new YamlService(
			Settings::fromRow([]),
			new YamlStateRepository(false),
			'',
			$pluginRoot
		);

		$packs = [
			['name' => 'network-core', 'status' => 'applied'],
			['name' => 'storage-san', 'status' => 'valid'],
			['name' => 'broken-pack', 'status' => 'failed'],
		];
		$audits = [
			[
				'status'       => 'failed',
				'summary_json' => json_encode([
					'compileMs' => 12.345,
					'mergeMs'   => 4.567,
					'errors'    => ['err1', 'err2'],
				]),
			],
		];

		$collector = new YamlMetricsCollector(
			$service,
			static fn (): array => $packs,
			static fn (): array => $audits
		);

		$collected = $collector->collect();
		$this->assertSame(3, $collected['packs_total']);
		$this->assertSame(2, $collected['packs_valid']);
		$this->assertGreaterThanOrEqual(1, $collected['schema_providers']);
		$this->assertGreaterThanOrEqual(1, $collected['schema_properties']);
		$this->assertSame(12.35, $collected['last_compile_ms']);
		$this->assertSame(4.57, $collected['last_merge_ms']);
		$this->assertSame(2, $collected['validation_errors']);

		$line = $collector->render();
		$this->assertStringContainsString('packs_total:3', $line);
		$this->assertStringContainsString('packs_valid:2', $line);
		$this->assertStringContainsString('schema_providers:' . $collected['schema_providers'], $line);
		$this->assertStringContainsString('schema_properties:' . $collected['schema_properties'], $line);
		$this->assertStringContainsString('last_compile_ms:12.35', $line);
		$this->assertStringContainsString('last_merge_ms:4.57', $line);
		$this->assertStringContainsString('validation_errors:2', $line);

		// Verify single-metric selection and unknown field fallback.
		$this->assertSame('3', $collector->render('packs_total'));
		$this->assertSame('12.35', $collector->render('last_compile_ms'));
		$this->assertSame('U', $collector->render('nonexistent_field'));

		// Verify error path returns RRDtool unknown ('U') values rather than false zeros.
		$failing = new YamlMetricsCollector(
			$service,
			static function (): array {
				throw new \RuntimeException('DB unavailable');
			}
		);
		$this->assertSame(
			'packs_total:U packs_valid:U schema_providers:U schema_properties:U last_compile_ms:U last_merge_ms:U validation_errors:U',
			$failing->render()
		);
		$this->assertSame('U', $failing->render('packs_total'));
	}

	/**
	 * Verify in-process YamlService timings and validation error counters override audit fallbacks.
	 *
	 * @return void
	 */
	public function testYamlServiceRecordsCompileMergeAndValidationTelemetry(): void {
		$pluginRoot = dirname(__DIR__, 2);
		$service    = new YamlService(
			Settings::fromRow([]),
			new YamlStateRepository(false),
			'',
			$pluginRoot
		);

		$dir = sys_get_temp_dir() . '/plugin_yaml_metrics_' . bin2hex(random_bytes(4));
		mkdir($dir, 0750, true);

		try {
			$file = $dir . '/config.yaml';
			file_put_contents($file, "apiVersion: cacti.net/v1alpha1\nkind: CactiConfig\nspec:\n  devices:\n    - hostname: r1\n      snmpVersion: 2\n");

			$validation = $service->validateSource($file);
			$this->assertFalse($validation['valid']);
			$this->assertNotNull($service->lastMergeMs());
			$this->assertGreaterThanOrEqual(1, $service->lastValidationErrors() ?? 0);

			$dqYaml = <<<'YAML'
apiVersion: cacti.yaml/v1
kind: SnmpDataQuery
metadata:
  name: metrics_dq
  outputFile: resource/snmp_queries/metrics_dq.xml
spec:
  name: Metrics Query
  oidIndex: .1.3.6.1.2.1.2.2.1.1
  indexOrder: [ifIndex]
  fields:
    ifIndex:
      name: Index
      method: walk
      source: value
      direction: input
      oid: .1.3.6.1.2.1.2.2.1.1
YAML;
			$compiled = $service->compileYaml($dqYaml);
			$this->assertSame('SnmpDataQuery', $compiled['kind']);
			$this->assertNotNull($service->lastCompileMs());

			$collector = new YamlMetricsCollector($service, static fn (): array => [], static fn (): array => []);
			$metrics   = $collector->collect();
			$this->assertSame($service->lastCompileMs(), $metrics['last_compile_ms']);
			$this->assertSame($service->lastMergeMs(), $metrics['last_merge_ms']);
			$this->assertSame($service->lastValidationErrors(), $metrics['validation_errors']);
		} finally {
			array_map('unlink', glob($dir . '/*') ?: []);
			@rmdir($dir);
		}
	}

	/**
	 * Verify script-server entry file executes cleanly in script-server mode without side effects.
	 *
	 * @return void
	 */
	public function testScriptServerEntryPointFunctionAndNoSecrets(): void {
		$called_by_script_server = true;
		require_once dirname(__DIR__, 2) . '/scripts/ss_yaml_stats.php';
		$this->assertTrue(function_exists('ss_yaml_stats'));

		$output = ss_yaml_stats();
		$this->assertStringContainsString('packs_total:', $output);
		$this->assertStringContainsString('validation_errors:', $output);
		$this->assertStringNotContainsString('password', strtolower($output));
		$this->assertStringNotContainsString('secret', strtolower($output));
	}

	/**
	 * Verify IndexedList accumulator preserves natural-key order, nested list merging,
	 * and graceful fallback when a later fragment introduces an unkeyed list item.
	 *
	 * @return void
	 */
	public function testIndexedListMergingAndFallbackSemantics(): void {
		$fragments = [
			[
				'source' => '01.yaml',
				'data'   => [
					'devices' => [
						['hostname' => '01', 'site' => 'sjc'],
						['hostname' => '1', 'site' => 'nyc'],
					],
				],
			],
			[
				'source' => '02.yaml',
				'data'   => [
					'devices' => [
						['hostname' => '01', 'notes' => 'leading zero preserved'],
						['hostname' => '2', 'site' => 'lon'],
					],
				],
			],
		];

		$merged = FragmentMerger::mergeFragments($fragments, MergePolicy::STRICT, MergePolicy::LIST_MERGE_BY_KEY);
		$this->assertCount(3, $merged['document']['devices']);
		$this->assertSame('01', $merged['document']['devices'][0]['hostname']);
		$this->assertSame('leading zero preserved', $merged['document']['devices'][0]['notes']);
		$this->assertSame('1', $merged['document']['devices'][1]['hostname']);
		$this->assertArrayNotHasKey('notes', $merged['document']['devices'][1]);
		$this->assertSame('2', $merged['document']['devices'][2]['hostname']);

		// Fallback when a later fragment drops the natural key (should append cleanly).
		$fallbackFragments = [
			['source' => 'a.yaml', 'data' => ['items' => [['id' => 'a', 'v' => 1]]]],
			['source' => 'b.yaml', 'data' => ['items' => [['id' => 'b', 'v' => 2]]]],
			['source' => 'c.yaml', 'data' => ['items' => [['other' => 3]]]],
		];
		$fallback = FragmentMerger::mergeFragments($fallbackFragments, MergePolicy::OVERLAY, MergePolicy::LIST_MERGE_BY_KEY);
		$this->assertCount(3, $fallback['document']['items']);
		$this->assertSame(['other' => 3], $fallback['document']['items'][2]);
	}
}
