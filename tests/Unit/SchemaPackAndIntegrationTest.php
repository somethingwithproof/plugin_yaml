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
use Somethingwithproof\Yaml\Integration\ApiResources;
use Somethingwithproof\Yaml\Integration\CascProvider;
use Somethingwithproof\Yaml\Integration\DoctorChecks;
use Somethingwithproof\Yaml\Integration\McpAbilities;
use Somethingwithproof\Yaml\Log;
use Somethingwithproof\Yaml\Merge\MergePolicy;
use Somethingwithproof\Yaml\Pack\PackManifest;
use Somethingwithproof\Yaml\Pack\PackService;
use Somethingwithproof\Yaml\Pages;
use Somethingwithproof\Yaml\Repository\YamlStateRepository;
use Somethingwithproof\Yaml\Schema\ReferenceRenderer;
use Somethingwithproof\Yaml\Schema\SchemaGenerator;
use Somethingwithproof\Yaml\Schema\StaticSchemaLoader;
use Somethingwithproof\Yaml\Service\YamlService;
use Somethingwithproof\Yaml\Settings;
use Somethingwithproof\Yaml\Ui\PagesRenderer;

/**
 * Unit tests covering JSON Schema generation, Reference rendering, Monitoring
 * Packs, YamlService, CLI subcommands, Console UI renderer, and decoupled
 * ecosystem hooks (CasC, Doctor, MCP, API).
 */
final class SchemaPackAndIntegrationTest extends TestCase {
	/**
	 * Verify Drupal-style static schema discovery, dynamic hook merging, Draft 2020-12
	 * JSON Schema output, and Config/State split Reference rendering.
	 *
	 * @return void
	 */
	public function testSchemaDiscoveryGenerationAndReferenceRendering(): void {
		$pluginRoot = dirname(__DIR__, 2);
		$static     = StaticSchemaLoader::discover('', [$pluginRoot . '/schema/yaml.schema.yaml']);
		$this->assertArrayHasKey('yaml', $static);
		$this->assertArrayHasKey('mergePolicy', $static['yaml']['config']);
		$this->assertTrue($static['yaml']['state']['installedPacksCount']['readOnly']);

		$merged = SchemaGenerator::mergeDescriptors($static, [
			[
				'plugin'        => 'thold',
				'schemaVersion' => '1.0',
				'title'         => 'Thresholds',
				'description'   => 'Threshold templates and alerting rules.',
				'config'        => [
					'baselineThresholds' => ['type' => 'array', 'description' => 'Declarative threshold rules.'],
				],
				'state' => [
					'triggeredCount' => ['type' => 'integer', 'description' => 'Active breached thresholds.'],
				],
			],
		]);

		$schema = SchemaGenerator::generate($merged);
		$this->assertSame(SchemaGenerator::DRAFT_2020_12, $schema['$schema']);
		$this->assertArrayHasKey('yaml', $schema['properties']['spec']['properties']['plugins']['properties']);
		$this->assertArrayHasKey('thold', $schema['properties']['spec']['properties']['plugins']['properties']);
		$this->assertArrayHasKey('thold', $schema['$defs']['pluginState']['properties']);

		$json = SchemaGenerator::toJson($merged);
		$this->assertNotNull(json_decode($json, true));

		$md = ReferenceRenderer::renderMarkdown($merged);
		$this->assertStringContainsString('Declarative Config (`spec.plugins.yaml`)', $md);
		$this->assertStringContainsString('Runtime State (Read-Only)', $md);

		$html = ReferenceRenderer::renderHtml($merged);
		$this->assertStringContainsString('Declarative Config', $html);
		$this->assertStringContainsString('Runtime State (Read-Only)', $html);
		$this->assertStringContainsString('No plugin schemas', ReferenceRenderer::renderHtml([]));
		$this->assertStringContainsString('No plugin schemas', ReferenceRenderer::renderMarkdown([]));
	}

	/**
	 * Verify MonitoringPack export, validate, plan, confirmation hash, apply, and lockfile drift detection.
	 *
	 * @return void
	 */
	public function testMonitoringPackLifecycleAndLockfileVerification(): void {
		$root      = sys_get_temp_dir() . '/plugin_yaml_pack_' . bin2hex(random_bytes(4));
		$packDir   = $root . '/pack';
		$cactiRoot = $root . '/cacti';
		mkdir($packDir . '/templates', 0750, true);
		mkdir($packDir . '/queries', 0750, true);
		mkdir($packDir . '/casc.d', 0750, true);
		mkdir($cactiRoot . '/resource/snmp_queries', 0750, true);

		file_put_contents($packDir . '/templates/cpu.yaml', <<<'YAML'
apiVersion: cacti.yaml/v1
kind: CactiTemplateBundle
metadata:
  name: cpu_pack
spec:
  objects:
    - ref: gprint_preset/normal
      type: gprint_preset
      fields:
        name: Normal
        gprint_text: '%8.2lf %s'
YAML);

		file_put_contents($packDir . '/queries/if.yaml', <<<'YAML'
apiVersion: cacti.yaml/v1
kind: SnmpDataQuery
metadata:
  name: pack-if
  outputFile: resource/snmp_queries/pack_if.xml
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

		file_put_contents($packDir . '/casc.d/00-site.yaml', <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  sites:
    - name: pack-site
YAML);

		try {
			$packService = new PackService(null, $cactiRoot);
			$exported    = $packService->export($packDir, 'linux-pack', '1.2.0', 'Linux baseline pack');
			$manifestPath = $packDir . '/pack.yaml';
			file_put_contents($manifestPath, $exported['manifestYaml']);

			$manifestObj = PackManifest::fromYaml($exported['manifestYaml']);
			$this->assertSame('linux-pack', $manifestObj->name);
			$this->assertSame('1.2.0', $manifestObj->version);

			$validated = $packService->validate($manifestPath, false);
			$this->assertTrue($validated['valid'], implode('; ', $validated['errors']));

			$plan = $packService->plan($manifestPath);
			$this->assertSame([], $plan['errors']);
			$this->assertSame(64, strlen($plan['planHash']));

			// Wrong confirmation hash must fail cleanly.
			$badApply = $packService->apply($manifestPath, 'deadbeef');
			$this->assertFalse($badApply['succeeded']);

			// Exact confirmation hash succeeds, writes Data Query XML and lockfile.
			$goodApply = $packService->apply($manifestPath, $plan['planHash'], 'ci-admin', 'cli');
			$this->assertTrue($goodApply['succeeded'], implode('; ', $goodApply['errors']));
			$this->assertFileExists($cactiRoot . '/resource/snmp_queries/pack_if.xml');
			$this->assertFileExists($packDir . '/' . PackService::LOCKFILE_NAME);

			// Validating with --require-lock now passes.
			$locked = $packService->validate($manifestPath, true);
			$this->assertTrue($locked['valid']);

			// Tampering an artifact breaks lockfile verification.
			file_put_contents($packDir . '/casc.d/00-site.yaml', "apiVersion: cacti.net/v1alpha1\nkind: CactiConfig\nspec:\n  sites:\n    - name: tampered\n");
			$tampered = $packService->validate($manifestPath, true);
			$this->assertFalse($tampered['valid']);
			$this->assertStringContainsString('Lockfile SHA-256 mismatch', implode("\n", $tampered['errors']));
		} finally {
			$this->removeTree($root);
		}
	}

	/**
	 * Verify all CLI subcommands, YamlService standalone reload, Console renderer,
	 * Settings, Pages, Log, and decoupled ecosystem hooks (CasC, Doctor, MCP, API).
	 *
	 * @return void
	 */
	public function testCliServiceRendererAndAllEcosystemHooks(): void {
		$root      = sys_get_temp_dir() . '/plugin_yaml_cli_' . bin2hex(random_bytes(4));
		$cactiRoot = $root . '/cacti';
		$fragDir   = $root . '/casc.d';
		mkdir($cactiRoot . '/resource/snmp_queries', 0750, true);
		mkdir($fragDir, 0750, true);

		$fragFile = $fragDir . '/00-config.yaml';
		file_put_contents($fragFile, <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  settings:
    values:
      poller_interval: 300
      cron_interval: 300
  sites:
    - name: cli-site
YAML);

		$queryFile = $root . '/query.yaml';
		file_put_contents($queryFile, <<<'YAML'
apiVersion: cacti.yaml/v1
kind: SnmpDataQuery
metadata:
  name: cli-if
  outputFile: resource/snmp_queries/cli_if.xml
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
			$settings = Settings::fromRow([
				'yaml_source_paths'          => $fragDir,
				'yaml_merge_policy'          => 'strict',
				'yaml_pack_dir'              => $root,
				'yaml_uninstall_keep_tables' => 'on',
			]);
			$this->assertSame([$fragDir], $settings->sourcePaths);
			$this->assertSame(MergePolicy::STRICT, $settings->mergePolicy);
			$this->assertTrue($settings->uninstallKeepTables);
			$this->assertArrayHasKey('yaml_merge_policy', Settings::form());
			$this->assertCount(3, Pages::realms());
			$this->assertCount(3, Pages::menu());
			$this->assertCount(3, Pages::navigation());
			$this->assertContains('plugins.php', Pages::upgradeTriggers());

			Log::info('info-test');
			Log::warning('warn-test');
			Log::error('err-test');
			$this->assertNotEmpty($GLOBALS['__yaml_log']);

			$pluginDir = dirname(__DIR__, 2);
			$service   = new YamlService($settings, new YamlStateRepository(false), $cactiRoot, $pluginDir);
			$runner    = new CliRunner($service);

			$this->assertSame(0, $runner->run(['help'])['exitCode']);
			$this->assertSame(2, $runner->run(['unknown-cmd'])['exitCode']);

			$schemaOut = $root . '/cacti-casc.schema.json';
			$this->assertSame(0, $runner->run(['schema', '--out=' . $schemaOut])['exitCode']);
			$this->assertFileExists($schemaOut);
			$this->assertSame(0, $runner->run(['schema', '--reference=markdown'])['exitCode']);

			$xmlOut  = $root . '/compiled_query.xml';
			$yamlOut = $root . '/decompiled_query.yaml';
			$this->assertSame(0, $runner->run(['compile', '--in=' . $queryFile, '--out=' . $xmlOut])['exitCode']);
			$this->assertSame(0, $runner->run(['decompile', '--in=' . $xmlOut, '--out=' . $yamlOut, '--name=resource/snmp_queries/cli_if.xml'])['exitCode']);
			$this->assertSame(0, $runner->run(['merge', '--source=' . $fragDir])['exitCode']);
			$this->assertSame(0, $runner->run(['validate', '--source=' . $fragDir])['exitCode']);

			$dryReload = $runner->run(['reload', '--source=' . $fragDir, '--dry-run']);
			$this->assertSame(0, $dryReload['exitCode']);
			$this->assertMatchesRegularExpression('/planHash=([a-f0-9]{64})/', $dryReload['stdout']);
			preg_match('/planHash=([a-f0-9]{64})/', $dryReload['stdout'], $m);
			$planHash = $m[1];

			$applyReload = $runner->run(['reload', '--source=' . $fragDir, '--confirm=' . $planHash]);
			$this->assertSame(0, $applyReload['exitCode']);

			// Verify standalone reload writes SnmpDataQuery XML.
			$dryQuery = $service->reload($queryFile, true);
			$this->assertTrue($dryQuery['succeeded']);
			$appliedQuery = $service->reload($queryFile, false, $dryQuery['planHash']);
			$this->assertTrue($appliedQuery['succeeded']);
			$this->assertFileExists($cactiRoot . '/resource/snmp_queries/cli_if.xml');

			// Verify Console UI renderer outputs escaped HTML cleanly.
			$viewHtml = PagesRenderer::renderViewPage($service->exportRedactedState(), $service->discoverSchemas(), [], []);
			$this->assertStringContainsString('Current Configuration', $viewHtml);
			$adminHtml = PagesRenderer::renderAdminPlan($planHash, "a: 1\n", [], 'Ready');
			$this->assertStringContainsString('Confirm &amp; Apply Reload', $adminHtml);
			$errHtml = PagesRenderer::renderOperatorResult(null, null, ['bad <script>']);
			$this->assertStringContainsString('&lt;script&gt;', $errHtml);

			// Verify decoupled CasC hooks (plain array descriptor mode AND duck-typed registrar).
			$factory = static fn (): YamlService => $service;
			$providers = CascProvider::registerProvider([], $factory);
			$this->assertIsArray($providers);
			$this->assertArrayHasKey('yaml', $providers);
			$this->assertSame([], ($providers['yaml']['validate'])(['mergePolicy' => 'strict']));
			$this->assertNotEmpty(($providers['yaml']['validate'])(['mergePolicy' => 'bogus']));
			$this->assertIsArray(($providers['yaml']['export'])(['omitSecrets' => true]));
			$this->assertNotEmpty(($providers['yaml']['plan'])(['mergePolicy' => 'overlay'], []));
			$this->assertTrue(($providers['yaml']['apply'])(['mergePolicy' => 'overlay'], $planHash, [])['ok']);

			$compilers = CascProvider::registerTemplateCompilers([]);
			$this->assertCount(1, $compilers);
			$this->assertSame(['yaml', 'yml'], $compilers[0]['extensions']);

			$described = CascProvider::describeSchema([], $pluginDir);
			$this->assertCount(1, $described);
			$this->assertSame('yaml', $described[0]['plugin']);

			// Verify Doctor checks.
			$checks = DoctorChecks::build($factory, static fn (): Settings => $settings, $cactiRoot);
			$this->assertCount(3, $checks);

			foreach ($checks as $check) {
				$res = ($check['run'])();
				$this->assertSame('ok', $res['status'], $check['id'] . ': ' . $res['message']);
			}

			// Verify MCP abilities registration & execution.
			$registeredAbilities = [];
			$mockRegistrar       = new class($registeredAbilities) {
				/**
				 * Construct a test registrar capturing abilities by name.
				 *
				 * @param array<string, array<string, mixed>> $store Output map.
				 */
				public function __construct(public array &$store) {
				}

				/**
				 * Record an ability descriptor.
				 *
				 * @param string $name Ability slug.
				 * @param array<string, mixed> $spec Ability definition.
				 * @return void
				 */
				public function register(string $name, array $spec): void {
					$this->store[$name] = $spec;
				}
			};

			McpAbilities::register($mockRegistrar, $factory, static fn (int $uid): bool => $uid === 1);
			$this->assertArrayHasKey('yaml-get-schema', $registeredAbilities);
			$this->assertArrayHasKey('yaml-list-packs', $registeredAbilities);
			$this->assertArrayHasKey('yaml-validate-source', $registeredAbilities);

			$schemaAbilityRes = ($registeredAbilities['yaml-get-schema']['execute'])(1, new \stdClass());
			$this->assertContains('yaml', $schemaAbilityRes['plugins']);
			$packsAbilityRes = ($registeredAbilities['yaml-list-packs']['execute'])(1, new \stdClass());
			$this->assertSame(0, $packsAbilityRes['count']);
			$valAbilityRes = ($registeredAbilities['yaml-validate-source']['execute'])(1, new \stdClass());
			$this->assertTrue($valAbilityRes['valid']);

			// Verify plugin_api resource descriptors.
			$apiResources = ApiResources::register([], $factory);
			$this->assertCount(2, $apiResources);
			$this->assertSame('yamlPacks', $apiResources[0]['resource']);
			$this->assertSame('yamlSchema', $apiResources[1]['resource']);
			$this->assertSame(['yamlPacks' => []], ($apiResources[0]['list'])());
			$this->assertNull(($apiResources[0]['get'])('missing'));
			$this->assertArrayHasKey('$schema', ($apiResources[1]['get'])());

			// Verify setup.php metadata & standalone hook wrappers.
			$versionInfo = plugin_yaml_version();
			$this->assertSame('yaml', $versionInfo['name']);
			$this->assertTrue(yaml_php_supported());
			$this->assertTrue(yaml_verify_csrf());
		} finally {
			$this->removeTree($root);
		}
	}

	/**
	 * Recursively remove a temporary test directory tree.
	 *
	 * @param string $dir Directory path to remove.
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
