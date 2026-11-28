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
use Somethingwithproof\Yaml\Codec\YamlEmitter;
use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;

/**
 * Unit tests for the pure-PHP YAML 1.2 parser and canonical emitter.
 */
final class YamlCodecTest extends TestCase {
	/**
	 * Verify scalars, nested maps, sequences, block scalars, flow containers,
	 * anchors, aliases, merge keys, and comments parse accurately.
	 *
	 * @return void
	 */
	public function testParsesFullSafeYaml12Subset(): void {
		$yaml = <<<'YAML'
%YAML 1.2
---
# Top-level comment
defaults: &def
  timeout: 500
  retries: 3
  enabled: true
  ratio: 1.5
  hexVal: 0x10
  octVal: 0o10
  empty: null
  tilde: ~
  single: 'it''s quoted'
  double: "line1\nline2\t\x41\u0042"
  inlineComment: hello # trailing comment

service:
  <<: *def
  retries: 5
  tags: [edge, core, "quoted, tag"]
  meta: {env: prod, active: false}
  literalKeep: |
    first line
    second line
  literalStrip: |-
    no trailing newline
  folded: >
    folded paragraph
    continues here

    new paragraph
  items:
    - name: alpha
      ports:
        - 161
        - 162
    - name: beta
      ports: []
  explicitStr: !!str 42
  explicitInt: !!int "99"
  explicitFloat: !!float "3.25"
  explicitBool: !!bool "true"
  explicitNull: !!null "ignored"
...
YAML;

		$doc = YamlParser::parseMap($yaml);

		$this->assertSame(500, $doc['service']['timeout']);
		$this->assertSame(5, $doc['service']['retries']);
		$this->assertTrue($doc['service']['enabled']);
		$this->assertSame(1.5, $doc['service']['ratio']);
		$this->assertSame(16, $doc['service']['hexVal']);
		$this->assertSame(8, $doc['service']['octVal']);
		$this->assertNull($doc['service']['empty']);
		$this->assertNull($doc['service']['tilde']);
		$this->assertSame("it's quoted", $doc['service']['single']);
		$this->assertSame("line1\nline2\tAB", $doc['service']['double']);
		$this->assertSame('hello', $doc['service']['inlineComment']);
		$this->assertSame(['edge', 'core', 'quoted, tag'], $doc['service']['tags']);
		$this->assertSame(['env' => 'prod', 'active' => false], $doc['service']['meta']);
		$this->assertSame("first line\nsecond line\n", $doc['service']['literalKeep']);
		$this->assertSame('no trailing newline', $doc['service']['literalStrip']);
		$this->assertSame("folded paragraph continues here\nnew paragraph\n", $doc['service']['folded']);
		$this->assertSame('alpha', $doc['service']['items'][0]['name']);
		$this->assertSame([161, 162], $doc['service']['items'][0]['ports']);
		$this->assertSame([], $doc['service']['items'][1]['ports']);
		$this->assertSame('42', $doc['service']['explicitStr']);
		$this->assertSame(99, $doc['service']['explicitInt']);
		$this->assertSame(3.25, $doc['service']['explicitFloat']);
		$this->assertTrue($doc['service']['explicitBool']);
		$this->assertNull($doc['service']['explicitNull']);
	}

	/**
	 * Verify canonical YAML emission round-trips losslessly through YamlParser.
	 *
	 * @return void
	 */
	public function testEmitterCanonicalRoundTrip(): void {
		$data = [
			'apiVersion' => 'cacti.yaml/v1',
			'ambiguous'  => ['yes', 'no', 'true', 'null', '123', '0x10', 'leading space ', 'has: colon', "multi\nline\n", "no\ntrail"],
			'nested'     => [
				['name' => 'item1', 'active' => true, 'count' => 7, 'score' => 2.5, 'note' => null],
				['name' => 'item2', 'empty' => []],
			],
			'matrix' => [[1, 2], [3, 4]],
			'special:key' => 'ok',
		];

		$emitted = YamlEmitter::emit($data, false);
		$parsed  = YamlParser::parseMap($emitted);

		$this->assertSame($data['apiVersion'], $parsed['apiVersion']);
		$this->assertSame($data['ambiguous'], $parsed['ambiguous']);
		$this->assertSame($data['nested'], $parsed['nested']);
		$this->assertSame($data['matrix'], $parsed['matrix']);
		$this->assertSame('ok', $parsed['special:key']);

		$sorted = YamlParser::parseMap(YamlEmitter::emit(['z' => 1, 'a' => 2], true));
		$this->assertSame(['a', 'z'], array_keys($sorted));
	}

	/**
	 * Verify UTF-8 BOM, empty input, sequence at same indent as mapping key, and scalar root.
	 *
	 * @return void
	 */
	public function testEdgeCasesAndBom(): void {
		$this->assertSame([], YamlParser::parse(''));
		$this->assertSame('hello', YamlParser::parse("\xEF\xBB\xBFhello"));

		$yaml = "items:\n- one\n- two\n";
		$this->assertSame(['items' => ['one', 'two']], YamlParser::parseMap($yaml));
	}

	/**
	 * Verify YamlException preserves structured lines and parseMap rejects non-map roots.
	 *
	 * @return void
	 */
	public function testParseMapRejectsSequenceRoot(): void {
		$ex = new YamlException('summary', ['line 1', 'line 2']);
		$this->assertSame(['line 1', 'line 2'], $ex->lines());

		$this->expectException(YamlException::class);
		YamlParser::parseMap("- one\n- two\n");
	}
}
