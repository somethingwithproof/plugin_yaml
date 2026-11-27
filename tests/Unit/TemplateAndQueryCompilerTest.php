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
use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Query\DataQueryCompiler;
use Somethingwithproof\Yaml\Query\DataQueryValidator;
use Somethingwithproof\Yaml\Template\HashRegistry;
use Somethingwithproof\Yaml\Template\TemplateCompiler;
use Somethingwithproof\Yaml\Template\TemplateDecompiler;

/**
 * Unit tests for lossless bidirectional YAML <-> Cacti XML template compilation
 * and SNMP / Script Data Query YAML validation and compilation.
 */
final class TemplateAndQueryCompilerTest extends TestCase {
	/**
	 * Verify lossless XML -> YAML -> XML -> YAML round-trip across Host, Graph,
	 * Data, and Data Query templates with symbolic references and hash stability.
	 *
	 * @return void
	 */
	public function testLosslessTemplateRoundTripAndDeterministicDerivation(): void {
		$sampleXml = <<<'XML'
<cacti>
	<hash_020103a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1>
		<name>Linux Host</name>
		<graph_templates>hash_000103b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2</graph_templates>
		<data_queries>hash_040103c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3</data_queries>
	</hash_020103a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1>
	<hash_000103b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2>
		<name>Interface Traffic</name>
		<items>
			<hash_100103d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4>
				<task_item_id>hash_080103e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5</task_item_id>
				<gprint_id>hash_060103f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6</gprint_id>
				<text_format>Inbound</text_format>
			</hash_100103d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4>
		</items>
	</hash_000103b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2>
	<hash_01010311111111111111111111111111111111>
		<name>Interface Traffic Data</name>
		<data>
			<data_input_id>hash_030103bf566c869ac6443b0c75d1c32b5a350e</data_input_id>
		</data>
		<items>
			<hash_080103e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5>
				<data_source_name>traffic_in</data_source_name>
			</hash_080103e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5>
		</items>
	</hash_01010311111111111111111111111111111111>
	<hash_040103c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3>
		<name>SNMP - Interface Statistics</name>
		<xml_path>&lt;path_cacti&gt;/resource/snmp_queries/interface.xml</xml_path>
	</hash_040103c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3>
	<hash_060103f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6>
		<name>Normal</name>
		<gprint_text>%8.2lf %s</gprint_text>
	</hash_060103f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6>
</cacti>
XML;

		$yaml1 = TemplateDecompiler::decompile($sampleXml, 'linux_bundle');
		$this->assertStringContainsString('ref:graph_template/interface_traffic', $yaml1);
		$this->assertStringContainsString('ref:system/get_snmp_data_indexed', $yaml1);

		$compiled1 = TemplateCompiler::compileYaml($yaml1);
		$this->assertStringContainsString('<hash_020103a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1>', $compiled1['xml']);
		$this->assertStringContainsString('hash_030103bf566c869ac6443b0c75d1c32b5a350e', $compiled1['xml']);
		$this->assertSame(64, strlen($compiled1['sha256']));

		$yaml2     = TemplateDecompiler::decompile($compiled1['xml'], 'linux_bundle');
		$compiled2 = TemplateCompiler::compileYaml($yaml2);

		$this->assertSame($yaml1, $yaml2);
		$this->assertSame($compiled1['sha256'], $compiled2['sha256']);

		// Authored from scratch without explicit cactiHash derives deterministic hashes.
		$authored = <<<'YAML'
apiVersion: cacti.yaml/v1
kind: CactiTemplateBundle
metadata:
  name: custom_metrics
spec:
  objects:
    - ref: gprint_preset/exact
      type: gprint_preset
      fields:
        name: Exact Numbers
        gprint_text: '%8.0lf'
    - ref: graph_template/app_latency
      type: graph_template
      fields:
        name: App Latency
        multiple: false
        test_source: true
        gprint_ref: ref:gprint_preset/exact
YAML;

		$first  = TemplateCompiler::compileYaml($authored);
		$second = TemplateCompiler::compileYaml($authored);
		$this->assertSame($first['sha256'], $second['sha256']);
		$this->assertSame($first['hashes'], $second['hashes']);
		$this->assertSame(
			HashRegistry::deriveHash('custom_metrics', 'gprint_preset', 'gprint_preset/exact'),
			$first['hashes'][0] === HashRegistry::deriveHash('custom_metrics', 'gprint_preset', 'gprint_preset/exact')
				? $first['hashes'][0]
				: $first['hashes'][1]
		);
	}

	/**
	 * Verify bidirectional SNMP and Script Data Query YAML <-> XML compilation
	 * and validation error reporting.
	 *
	 * @return void
	 */
	public function testSnmpAndScriptDataQueryCompilationAndRoundTrip(): void {
		$snmpYaml = <<<'YAML'
apiVersion: cacti.yaml/v1
kind: SnmpDataQuery
metadata:
  name: interface-extended
  outputFile: resource/snmp_queries/interface_extended.xml
spec:
  name: Extended Interface Statistics
  description: Queries 64-bit interface counters
  oidIndex: .1.3.6.1.2.1.2.2.1.1
  oidNumIndexes: .1.3.6.1.2.1.2.1.0
  indexOrder: [ifName, ifIndex]
  indexOrderType: numeric
  fields:
    ifIndex:
      name: Index
      method: walk
      source: value
      direction: input
      oid: .1.3.6.1.2.1.2.2.1.1
    ifName:
      name: Interface Name
      method: walk
      outputFormat: ascii
      source: value
      direction: input
      oid: .1.3.6.1.2.1.31.1.1.1.1
    ifOperStatus:
      name: Status
      method: walk
      source: 'VALUE/REGEXP:[a-zA-Z]+\(([0-9]+)\)$'
      direction: input-output
      oid: .1.3.6.1.2.1.2.2.1.8
    ifHCInOctets:
      name: Bytes In (64-bit)
      method: walk
      source: value
      direction: output
      oid: .1.3.6.1.2.1.31.1.1.1.6
YAML;

		$compiled = DataQueryCompiler::compileYaml($snmpYaml);
		$this->assertSame('SnmpDataQuery', $compiled['kind']);
		$this->assertSame('resource/snmp_queries/interface_extended.xml', $compiled['outputFile']);
		$this->assertStringContainsString('<index_order>ifName:ifIndex</index_order>', $compiled['xml']);

		$decompiled = DataQueryCompiler::decompile($compiled['xml'], $compiled['outputFile']);
		$recompiled = DataQueryCompiler::compileYaml($decompiled);
		$this->assertSame($compiled['sha256'], $recompiled['sha256']);

		$scriptYaml = <<<'YAML'
apiVersion: cacti.yaml/v1
kind: ScriptDataQuery
metadata:
  name: unix-disk
  outputFile: resource/script_queries/unix_disk_custom.xml
spec:
  name: Get Unix Mounted Partitions
  description: Queries mounted filesystems
  scriptPath: perl |path_cacti|/scripts/query_unix_partitions.pl
  argIndex: index
  argQuery: query
  argGet: get
  outputDelimiter: ':'
  indexOrder: [dskDevice]
  indexOrderType: alphabetic
  fields:
    dskDevice:
      name: Device Name
      direction: input
      queryName: device
    dskTotal:
      name: Total Blocks
      direction: output
      queryName: total
YAML;

		$scriptCompiled = DataQueryCompiler::compileYaml($scriptYaml);
		$this->assertSame('ScriptDataQuery', $scriptCompiled['kind']);
		$this->assertStringContainsString('<query_name>device</query_name>', $scriptCompiled['xml']);

		$scriptDecompiled = DataQueryCompiler::decompile($scriptCompiled['xml'], $scriptCompiled['outputFile']);
		$scriptRecompiled = DataQueryCompiler::compileYaml($scriptDecompiled);
		$this->assertSame($scriptCompiled['sha256'], $scriptRecompiled['sha256']);

		// Validate broken Data Query catches bad OID, bad regex, bad outputFile, duplicate case-insensitive field.
		$badErrors = DataQueryValidator::validate(YamlParser::parseMap(<<<'YAML'
apiVersion: cacti.yaml/v1
kind: SnmpDataQuery
metadata:
  name: broken
  outputFile: /etc/passwd
spec:
  oidIndex: not-an-oid
  indexOrderType: random
  fields:
    ifIndex:
      name: Index
      method: invalid
      source: 'VALUE/REGEXP:[unclosed('
      direction: sideways
      oid: bad
    ifindex:
      name: Duplicate
      method: walk
      source: value
      direction: input
      oid: .1.3.6.1
YAML));

		$joined = implode("\n", $badErrors);
		$this->assertStringContainsString('must end with .xml', $joined);
		$this->assertStringContainsString('must be a valid dotted numeric OID', $joined);
		$this->assertStringContainsString('invalid regular expression', $joined);
		$this->assertStringContainsString('duplicate case-insensitive field', $joined);
	}

	/**
	 * Verify HashRegistry rejects invalid tags/hashes and formatTag works cleanly.
	 *
	 * @return void
	 */
	public function testHashRegistryErrorPaths(): void {
		$this->assertNull(HashRegistry::parseTag('not_a_hash'));
		$this->assertNull(HashRegistry::parseTag('hash_990103' . str_repeat('a', 32)));
		$this->assertSame('item', HashRegistry::slugify('!!!'));

		$this->expectException(YamlException::class);
		HashRegistry::formatTag('unknown_type', str_repeat('a', 32));
	}
}
