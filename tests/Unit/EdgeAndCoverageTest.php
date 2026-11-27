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
use Somethingwithproof\Yaml\Cli\CliRunner;
use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Integration\ApiResources;
use Somethingwithproof\Yaml\Integration\CascProvider;
use Somethingwithproof\Yaml\Integration\DoctorChecks;
use Somethingwithproof\Yaml\Integration\McpAbilities;
use Somethingwithproof\Yaml\Merge\MergePolicy;
use Somethingwithproof\Yaml\Repository\YamlStateRepository;
use Somethingwithproof\Yaml\Schema;
use Somethingwithproof\Yaml\Schema\StaticSchemaLoader;
use Somethingwithproof\Yaml\Service\YamlService;
use Somethingwithproof\Yaml\Settings;
use Somethingwithproof\Yaml\Ui\PagesRenderer;

/**
 * Edge-case unit tests covering Schema migrations, YamlStateRepository CRUD,
 * CLI pack/error paths, Doctor warning/critical paths, StaticSchemaLoader plugin
 * scanning, and Console UI populated views.
 */
final class EdgeAndCoverageTest extends TestCase {
	/**
	 * Reset global DB mock after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['__yaml_db_mock'] = null;
	}

	/**
	 * Verify Schema lifecycle methods including migration, downgrade refusal,
	 * missing-table failure, hooks check, and uninstall helpers.
	 *
	 * @return void
	 */
	public function testSchemaMigrationAndLifecycleBranches(): void {
		$db = new class {
			/** @var array<string, string> */
			public array $settings = [];
			/** @var array<string, bool> */
			public array $tables = [];
			public bool $failTableCreate = false;
			public string $pluginVersion = '0.1.0';
			public int $pluginStatus = 1;
			public int $hookCount = 11;
			public int $realmCount = 3;
			/** @var list<string> */
			public array $executed = [];

			/**
			 * Record simulated table creation.
			 *
			 * @param string $plugin Plugin name.
			 * @param string $table Table name.
			 * @param array<string, mixed> $def Table definition.
			 * @return void
			 */
			public function createTable(string $plugin, string $table, array $def): void {
				if (!$this->failTableCreate) {
					$this->tables[$table] = true;
				}
			}

			/**
			 * Simulate db_fetch_cell_prepared queries.
			 *
			 * @param string $sql Query string.
			 * @param array<int, mixed> $params Bound params.
			 * @return mixed
			 */
			public function fetchCell(string $sql, array $params): mixed {
				if (str_contains($sql, 'FROM settings')) {
					return $this->settings[(string) ($params[0] ?? '')] ?? false;
				}

				if (str_contains($sql, 'information_schema.TABLES')) {
					return isset($this->tables[(string) ($params[0] ?? '')]) ? 1 : 0;
				}

				if (str_contains($sql, 'SELECT version FROM plugin_config')) {
					return $this->pluginVersion;
				}

				if (str_contains($sql, 'SELECT status FROM plugin_config')) {
					return $this->pluginStatus;
				}

				if (str_contains($sql, 'FROM plugin_hooks')) {
					return $this->hookCount;
				}

				if (str_contains($sql, 'FROM plugin_realms')) {
					return $this->realmCount;
				}

				return 0;
			}

			/**
			 * Simulate db_execute_prepared statements.
			 *
			 * @param string $sql Query string.
			 * @param array<int, mixed> $params Bound params.
			 * @return bool
			 */
			public function execute(string $sql, array $params): bool {
				$this->executed[] = $sql;

				if (str_contains($sql, 'REPLACE INTO settings')) {
					$this->settings[(string) ($params[0] ?? '')] = (string) ($params[1] ?? '');
				} elseif (str_contains($sql, 'DELETE FROM settings')) {
					unset($this->settings[(string) ($params[0] ?? '')]);
				}

				return true;
			}
		};

		$GLOBALS['__yaml_db_mock'] = $db;
		$schema                    = new Schema(false);

		$this->assertSame(0, $schema->storedVersion());
		$this->assertFalse($schema->isCurrent());
		$this->assertTrue($schema->migrate());
		$this->assertTrue($schema->isCurrent());
		$this->assertSame('0.1.0', $schema->recordedPluginVersion());
		$this->assertTrue($schema->pluginEnabled());
		$this->assertTrue($schema->hooksRegistered());

		$schema->recordPluginVersion('0.2.0', 'Author', 'https://example.org');
		$schema->untrackTables();
		$schema->forgetVersion();
		$this->assertSame(0, $schema->storedVersion());

		// Refuses downgrade when stored version > code version.
		$db->settings['yaml_schema_version'] = '99';
		$this->assertFalse($schema->migrate());

		// Fails cleanly when table creation fails.
		$db->settings['yaml_schema_version'] = '0';
		$db->tables                          = [];
		$db->failTableCreate                 = true;
		$this->assertFalse($schema->migrate());
	}

	/**
	 * Verify YamlStateRepository CRUD, ApiResources populated responses, Doctor warning/critical
	 * branches, and PagesRenderer populated tables.
	 *
	 * @return void
	 */
	public function testRepositoryCrudDoctorWarningsAndPopulatedUi(): void {
		$root      = sys_get_temp_dir() . '/plugin_yaml_edge_' . bin2hex(random_bytes(4));
		$cactiRoot = $root . '/cacti';
		mkdir($cactiRoot . '/resource/snmp_queries', 0750, true);
		mkdir($cactiRoot . '/plugins/demo/schema', 0750, true);
		mkdir($cactiRoot . '/plugins/bad/schema', 0750, true);

		file_put_contents($cactiRoot . '/resource/snmp_queries/drifted.xml', '<interface><name>Changed</name></interface>');
		file_put_contents($cactiRoot . '/plugins/demo/schema/demo.schema.yaml', "plugin: demo\nschemaVersion: '1.0'\ntitle: Demo\nconfig:\n  enabled:\n    type: boolean\n    default: true\nstate:\n  active:\n    type: integer\n");
		file_put_contents($cactiRoot . '/plugins/bad/schema/bad.schema.yaml', "plugin: foreign_slug\n");

		$db = new class {
			/** @var array<string, array<string, mixed>> */
			public array $packs = [];
			/** @var list<array<string, mixed>> */
			public array $queries = [];
			/** @var list<array<string, mixed>> */
			public array $audits = [];
			public int $nextId = 1;

			/**
			 * Simulate db_execute_prepared for packs, queries, and audits.
			 *
			 * @param string $sql Query string.
			 * @param array<int, mixed> $params Bound params.
			 * @return bool
			 */
			public function execute(string $sql, array $params): bool {
				if (str_contains($sql, 'INTO plugin_yaml_packs')) {
					$this->packs[(string) $params[0]] = [
						'id'              => $this->nextId++,
						'name'            => $params[0],
						'version'         => $params[1],
						'manifest_sha256' => $params[2],
						'lock_sha256'     => $params[3],
						'source_path'     => $params[4],
						'applied_at'      => $params[5],
						'applied_by'      => $params[6],
						'status'          => $params[7],
						'metadata_json'   => $params[8],
					];
				} elseif (str_contains($sql, 'INTO plugin_yaml_queries')) {
					$this->queries[] = [
						'id'          => $this->nextId++,
						'name'        => $params[0],
						'kind'        => $params[1],
						'output_file' => $params[2],
						'xml_sha256'  => $params[3],
						'updated_at'  => $params[4],
					];
				} elseif (str_contains($sql, 'INTO plugin_yaml_audit')) {
					$this->audits[] = [
						'id'           => $this->nextId++,
						'occurred_at'  => $params[0],
						'actor'        => $params[1],
						'source'       => $params[2],
						'action'       => $params[3],
						'plan_hash'    => $params[4],
						'status'       => $params[5],
						'summary_json' => $params[6],
					];
				}

				return true;
			}

			/**
			 * Simulate db_fetch_assoc_prepared.
			 *
			 * @param string $sql Query string.
			 * @param array<int, mixed> $params Bound params.
			 * @return list<array<string, mixed>>
			 */
			public function fetchAssoc(string $sql, array $params): array {
				if (str_contains($sql, 'FROM plugin_yaml_packs')) {
					return array_values($this->packs);
				}

				if (str_contains($sql, 'FROM plugin_yaml_queries')) {
					return $this->queries;
				}

				if (str_contains($sql, 'FROM plugin_yaml_audit')) {
					return $this->audits;
				}

				return [];
			}

			/**
			 * Simulate db_fetch_row_prepared.
			 *
			 * @param string $sql Query string.
			 * @param array<int, mixed> $params Bound params.
			 * @return array<string, mixed>
			 */
			public function fetchRow(string $sql, array $params): array {
				return $this->packs[(string) ($params[0] ?? '')] ?? [];
			}

			/**
			 * Simulate db_fetch_insert_id.
			 *
			 * @return int
			 */
			public function insertId(): int {
				return $this->nextId - 1;
			}
		};

		try {
			$GLOBALS['__yaml_db_mock'] = $db;
			$repo                      = new YamlStateRepository(false);

			$repo->upsertPack('net-pack', '2.0.0', str_repeat('a', 64), str_repeat('b', 64), '/packs/net.yaml', 'admin', 'applied', ['name' => 'net-pack']);
			$repo->upsertQuery('drifted', 'SnmpDataQuery', 'resource/snmp_queries/drifted.xml', str_repeat('0', 64));
			$auditId = $repo->recordAudit('admin', 'cli', 'reload', str_repeat('c', 64), 'succeeded', ['ok' => true]);

			$this->assertGreaterThan(0, $auditId);
			$this->assertCount(1, $repo->listPacks());
			$this->assertNotNull($repo->getPackByName('net-pack'));
			$this->assertCount(1, $repo->listQueries());
			$this->assertCount(1, $repo->getRecentAudits(10));

			// Verify plugin directory schema discovery loads valid demo plugin and isolates foreign-slug plugin.
			$discovered = StaticSchemaLoader::discover($cactiRoot . '/plugins');
			$this->assertArrayHasKey('demo', $discovered);
			$this->assertArrayNotHasKey('bad', $discovered);

			// Missing source triggers Doctor warning + critical fragment check + query checksum drift warning.
			$settings = new Settings([$root . '/does-not-exist.yaml'], MergePolicy::STRICT, $root, false);
			$service  = new YamlService($settings, $repo, $cactiRoot, dirname(__DIR__, 2));

			$checks = DoctorChecks::build(static fn (): YamlService => $service, static fn (): Settings => $settings, $cactiRoot);
			$this->assertSame('warn', $checks[0]['status']);
			$this->assertSame('fail', $checks[1]['status']);
			$this->assertSame('warn', $checks[2]['status']);

			// Verify populated UI tables render packs and audits.
			$html = PagesRenderer::renderViewPage($service->exportRedactedState(), $service->discoverSchemas(), $repo->listPacks(), $repo->getRecentAudits(10));
			$this->assertStringContainsString('net-pack', $html);
			$this->assertStringContainsString('reload', $html);

			// Verify ApiResources populated pack list, get, & codec closures.
			$resources = ApiResources::register([], static fn (): YamlService => $service);
			$packList  = ($resources[0]['handlers']['list'])(['user_id' => 1]);
			$this->assertCount(1, $packList);
			$this->assertSame('net-pack', $packList[0]['id']);
			$packEntry = ($resources[0]['handlers']['get'])('net-pack', ['user_id' => 1]);
			$this->assertIsArray($packEntry);
			$this->assertSame('2.0.0', $packEntry['version']);
			$this->assertSame(['a' => 1], ($resources[0]['codec']['decode'])("a: 1\n"));
			$this->assertSame("a: 1\n", ($resources[0]['codec']['encode'])(['a' => 1]));

			// Verify CLI pack-export, pack-validate, pack-plan, pack-apply, and error exit codes.
			$packDir = $root . '/cli_pack';
			mkdir($packDir . '/casc.d', 0750, true);
			file_put_contents($packDir . '/casc.d/00-base.yaml', "apiVersion: cacti.net/v1alpha1\nkind: CactiConfig\nspec:\n  sites:\n    - name: s1\n");

			$runner = new CliRunner($service);
			$this->assertSame(2, $runner->run(['decompile'])['exitCode']);
			$this->assertSame(2, $runner->run(['compile'])['exitCode']);
			$this->assertSame(2, $runner->run(['merge'])['exitCode']);
			$this->assertSame(2, $runner->run(['validate'])['exitCode']);
			$this->assertSame(2, $runner->run(['reload'])['exitCode']);
			$this->assertSame(2, $runner->run(['pack-export'])['exitCode']);
			$this->assertSame(2, $runner->run(['pack-validate'])['exitCode']);
			$this->assertSame(2, $runner->run(['pack-plan'])['exitCode']);
			$this->assertSame(2, $runner->run(['pack-apply'])['exitCode']);

			$manifestOut = $packDir . '/pack.yaml';
			$this->assertSame(0, $runner->run(['pack-export', '--dir=' . $packDir, '--name=cli-pack', '--version=1.0.0', '--out=' . $manifestOut])['exitCode']);
			$this->assertSame(0, $runner->run(['pack-validate', '--manifest=' . $manifestOut, '--require-lock'])['exitCode']);

			$planRes = $runner->run(['pack-plan', '--manifest=' . $manifestOut]);
			$this->assertSame(0, $planRes['exitCode']);
			preg_match('/planHash=([a-f0-9]{64})/', $planRes['stdout'], $m);
			$this->assertSame(0, $runner->run(['pack-apply', '--manifest=' . $manifestOut, '--confirm=' . $m[1]])['exitCode']);

			// Verify duck-typed object registrar in CascProvider without plugin_casc loaded.
			$captured  = null;
			$registrar = new class($captured) {
				/**
				 * Capture registered provider descriptor.
				 *
				 * @param mixed $target Captured reference.
				 */
				public function __construct(public mixed &$target) {
				}

				/**
				 * Store descriptor passed by CascProvider.
				 *
				 * @param mixed $descriptor Provider array or object.
				 * @return void
				 */
				public function register(mixed $descriptor): void {
					$this->target = $descriptor;
				}
			};
			CascProvider::registerProvider($registrar, static fn (): YamlService => $service);
			$this->assertIsArray($captured);
			$this->assertSame('yaml', $captured['slug']);

			// Verify MCP userHasViewRealm returns false when userId <= 0.
			$this->assertFalse(McpAbilities::userHasViewRealm(0));

			// Verify YamlParser rejects undefined alias, bad block scalar, and bad double-quote escapes.
			foreach (["a: *missing\n", "a: \"bad\\q\"\n", "a: 'unterminated\n", "a: [1, 2\n", "a: {x: 1\n"] as $badYaml) {
				try {
					YamlParser::parse($badYaml);
					$this->fail('Expected YamlException for: ' . $badYaml);
				} catch (YamlException $e) {
					$this->assertNotEmpty($e->getMessage());
				}
			}
		} finally {
			$this->removeTree($root);
		}
	}

	/**
	 * Recursively remove a temporary directory tree.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function removeTree(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			if ($item instanceof \SplFileInfo) {
				if ($item->isDir()) {
					@rmdir($item->getPathname());
				} else {
					@unlink($item->getPathname());
				}
			}
		}

		@rmdir($dir);
	}
}
