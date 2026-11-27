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

namespace Somethingwithproof\Yaml\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Yaml\Pages;
use Somethingwithproof\Yaml\Query\DataQueryCompiler;
use Somethingwithproof\Yaml\Schema;

/**
 * Live MariaDB 11.8 + Cacti 1.2 integration tests verifying schema installation,
 * 3-tier RBAC realms, hook registration, real Cacti Data Query round-trips,
 * Monitoring Pack persistence, and decoupled ecosystem hooks.
 */
final class MariaDbIntegrationTest extends TestCase {
	/**
	 * Verify schema, hooks, realms, Data Query round-trips, and pack persistence
	 * against a live Cacti 1.2 MariaDB instance.
	 *
	 * @return void
	 */
	public function testSchemaHooksRealmsPackPersistenceAndStockQueryRoundTrip(): void {
		$schema = new Schema(false);
		$this->assertTrue($schema->isCurrent());
		$this->assertTrue($schema->hooksRegistered());

		$realmCount = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', ['yaml']);
		$this->assertSame(count(Pages::realms()), $realmCount);

		$service = yaml_service();

		// 1. Live JSON Schema discovery includes plugin_yaml's static schema/yaml.schema.yaml.
		$generated = $service->generateSchema();
		$this->assertContains('yaml', $generated['plugins']);
		$this->assertSame('https://json-schema.org/draft/2020-12/schema', $generated['schema']['$schema']);

		// 2. Decompile and recompile real Cacti 1.2 stock SNMP and Script Data Queries.
		$cactiRoot = rtrim((string) getenv('CACTI_PATH'), '/');
		$ifXml     = file_get_contents($cactiRoot . '/resource/snmp_queries/interface.xml');
		$this->assertIsString($ifXml);

		$ifYaml     = DataQueryCompiler::decompile($ifXml, 'resource/snmp_queries/interface.xml');
		$ifCompiled = DataQueryCompiler::compileYaml($ifYaml);
		$this->assertSame('SnmpDataQuery', $ifCompiled['kind']);
		$this->assertStringContainsString('<oid_index>.1.3.6.1.2.1.2.2.1.1</oid_index>', $ifCompiled['xml']);

		$diskXml = file_get_contents($cactiRoot . '/resource/script_queries/unix_disk.xml');
		$this->assertIsString($diskXml);

		$diskYaml     = DataQueryCompiler::decompile($diskXml, 'resource/script_queries/unix_disk.xml');
		$diskCompiled = DataQueryCompiler::compileYaml($diskYaml);
		$this->assertSame('ScriptDataQuery', $diskCompiled['kind']);
		$this->assertStringContainsString('<query_name>device</query_name>', $diskCompiled['xml']);

		// 3. Apply a Monitoring Pack against live MariaDB and verify persisted rows.
		$packDir = sys_get_temp_dir() . '/cacti_yaml_int_pack_' . bin2hex(random_bytes(4));
		mkdir($packDir . '/queries', 0750, true);
		file_put_contents($packDir . '/queries/ci_if.yaml', <<<'YAML'
apiVersion: cacti.yaml/v1
kind: SnmpDataQuery
metadata:
  name: ci-int-if
  outputFile: resource/snmp_queries/ci_int_if.xml
spec:
  oidIndex: .1.3.6.1.2.1.2.2.1.1
  indexOrder: [ifIndex]
  indexOrderType: numeric
  fields:
    ifIndex:
      name: Index
      method: walk
      source: value
      direction: input
      oid: .1.3.6.1.2.1.2.2.1.1
YAML);

		try {
			$packService = $service->packService();
			$exported    = $packService->export($packDir, 'ci-integration-pack', '1.0.0', 'CI Pack');
			$manifest    = $packDir . '/pack.yaml';
			file_put_contents($manifest, $exported['manifestYaml']);

			$plan  = $packService->plan($manifest);
			$apply = $packService->apply($manifest, $plan['planHash'], 'ci', 'cli');
			$this->assertTrue($apply['succeeded'], implode('; ', $apply['errors']));

			$packs   = $service->repository()->listPacks();
			$queries = $service->repository()->listQueries();
			$audits  = $service->repository()->getRecentAudits(10);

			$this->assertCount(1, $packs);
			$this->assertSame('ci-integration-pack', $packs[0]['name']);
			$this->assertCount(1, $queries);
			$this->assertSame('resource/snmp_queries/ci_int_if.xml', $queries[0]['output_file']);
			$this->assertNotEmpty($audits);

			// 4. Verify Doctor checks pass against live compiled query XML checksum.
			$checks = yaml_doctor_register_checks([]);
			$this->assertIsArray($checks);

			foreach ($checks as $check) {
				$result = ($check['run'])();
				$this->assertSame('ok', $result['status'], $check['id'] . ': ' . $result['message']);
			}

			// 5. Verify decoupled CasC and API hooks operate cleanly without plugin_casc/plugin_api installed.
			$providers = yaml_casc_register_providers([]);
			$this->assertIsArray($providers);
			$this->assertArrayHasKey('yaml', $providers);
			$exportedState = ($providers['yaml']['export'])([]);
			$this->assertCount(1, $exportedState['packs']);

			$apiResources = yaml_api_register_resources([]);
			$this->assertIsArray($apiResources);
			$this->assertCount(2, $apiResources);
		} finally {
			@unlink($cactiRoot . '/resource/snmp_queries/ci_int_if.xml');
			@unlink($packDir . '/queries/ci_if.yaml');
			@unlink($packDir . '/pack.yaml');
			@unlink($packDir . '/cacti-pack.lock.yaml');
			@rmdir($packDir . '/queries');
			@rmdir($packDir);
		}
	}
}
