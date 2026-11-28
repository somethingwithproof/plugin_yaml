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

namespace Somethingwithproof\Yaml\Tests\Security;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Codec\YamlParser;
use Somethingwithproof\Yaml\Merge\PlaceholderParser;
use Somethingwithproof\Yaml\Query\DataQueryCompiler;
use Somethingwithproof\Yaml\Security\PathGuard;
use Somethingwithproof\Yaml\Template\TemplateDecompiler;

/**
 * Adversarial security tests covering YAML deserialization tags, billion-laughs
 * alias bombs, resource limits, XXE/DTD rejection, symlink/traversal defense,
 * and secret redaction.
 */
final class YamlSecurityTest extends TestCase {
	/**
	 * Reject PHP object and custom executable YAML tags.
	 *
	 * @return void
	 */
	public function testRejectsExecutableAndPhpObjectTags(): void {
		$payloads = [
			"payload: !php/object 'O:8:\"stdClass\":0:{}'",
			"payload: !!php/object 'O:8:\"stdClass\":0:{}'",
			"payload: !ruby/object:Gem::Installer {}",
			"%TAG !custom! tag:evil.example,2026:\nkey: val\n",
		];

		foreach ($payloads as $payload) {
			try {
				YamlParser::parse($payload);
				$this->fail('Expected YamlException for payload: ' . $payload);
			} catch (YamlException $e) {
				$this->assertNotEmpty($e->getMessage());
			}
		}
	}

	/**
	 * Reject YAML billion-laughs exponential alias expansion payloads.
	 *
	 * @return void
	 */
	public function testRejectsBillionLaughsAliasBomb(): void {
		$bomb = <<<'YAML'
a: &a ["lol", "lol", "lol", "lol", "lol", "lol", "lol", "lol", "lol"]
b: &b [*a, *a, *a, *a, *a, *a, *a, *a, *a]
c: &c [*b, *b, *b, *b, *b, *b, *b, *b, *b]
d: &d [*c, *c, *c, *c, *c, *c, *c, *c, *c]
YAML;

		$this->expectException(YamlException::class);
		$this->expectExceptionMessageMatches('/alias expansion/i');
		YamlParser::parse($bomb);
	}

	/**
	 * Enforce max bytes, max depth, max mapping keys, null-byte, and tab guards.
	 *
	 * @return void
	 */
	public function testEnforcesResourceBoundsAndControlGuards(): void {
		$smallParser = new YamlParser(maxBytes: 64, maxDepth: 4, maxKeys: 5);

		try {
			$smallParser->parseDocument(str_repeat('a: 1' . "\n", 20));
			$this->fail('Expected maxBytes rejection.');
		} catch (YamlException $e) {
			$this->assertStringContainsString('maximum size', $e->getMessage());
		}

		try {
			$smallParser->parseDocument("a:\n  b:\n    c:\n      d:\n        e:\n          f: 1\n");
			$this->fail('Expected maxDepth rejection.');
		} catch (YamlException $e) {
			$this->assertStringContainsString('nesting depth', $e->getMessage());
		}

		try {
			$smallParser->parseDocument("k1: 1\nk2: 2\nk3: 3\nk4: 4\nk5: 5\nk6: 6\n");
			$this->fail('Expected maxKeys rejection.');
		} catch (YamlException $e) {
			$this->assertStringContainsString('mapping key limit', $e->getMessage());
		}

		try {
			YamlParser::parse("key: val\0ue");
			$this->fail('Expected null byte rejection.');
		} catch (YamlException $e) {
			$this->assertStringContainsString('null bytes', $e->getMessage());
		}

		try {
			YamlParser::parse("key:\n\tchild: 1\n");
			$this->fail('Expected tab indentation rejection.');
		} catch (YamlException $e) {
			$this->assertStringContainsString('tabs', $e->getMessage());
		}
	}

	/**
	 * Reject XXE/DTD declarations in both template and Data Query XML decompilers.
	 *
	 * @return void
	 */
	public function testRejectsXxeAndDoctypeInXml(): void {
		$xxe = '<?xml version="1.0"?><!DOCTYPE cacti [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><cacti>&xxe;</cacti>';

		try {
			TemplateDecompiler::decompile($xxe);
			$this->fail('Expected TemplateDecompiler to reject DOCTYPE/ENTITY.');
		} catch (YamlException $e) {
			$this->assertStringContainsString('DOCTYPE', $e->getMessage());
		}

		$dqXxe = '<?xml version="1.0"?><!DOCTYPE interface [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><interface/>';

		try {
			DataQueryCompiler::decompile($dqXxe);
			$this->fail('Expected DataQueryCompiler to reject DOCTYPE/ENTITY.');
		} catch (YamlException $e) {
			$this->assertStringContainsString('DOCTYPE', $e->getMessage());
		}
	}

	/**
	 * Verify PathGuard refuses symlinks, parent traversal, and non-allowlisted query paths.
	 *
	 * @return void
	 */
	public function testPathGuardRefusesSymlinksTraversalAndUnapprovedOutputs(): void {
		$tmp = sys_get_temp_dir() . '/plugin_yaml_sec_' . bin2hex(random_bytes(4));
		mkdir($tmp . '/sub', 0750, true);
		file_put_contents($tmp . '/real.yaml', "a: 1\n");
		symlink($tmp . '/real.yaml', $tmp . '/sub/link.yaml');

		try {
			try {
				PathGuard::resolveConfined('../etc/passwd', $tmp);
				$this->fail('Expected traversal rejection.');
			} catch (YamlException $e) {
				$this->assertStringContainsString('traversal', $e->getMessage());
			}

			try {
				PathGuard::resolveConfined('sub/link.yaml', $tmp);
				$this->fail('Expected symlink rejection.');
			} catch (YamlException $e) {
				$this->assertStringContainsString('symlink', $e->getMessage());
			}

			try {
				PathGuard::validateQueryOutputPath('plugins/yaml/evil.xml');
				$this->fail('Expected query output allowlist rejection.');
			} catch (YamlException $e) {
				$this->assertStringContainsString('must reside directly', $e->getMessage());
			}

			$this->assertSame(
				'resource/snmp_queries/custom_if.xml',
				PathGuard::validateQueryOutputPath('resource/snmp_queries/custom_if.xml')
			);
		} finally {
			@unlink($tmp . '/sub/link.yaml');
			@unlink($tmp . '/real.yaml');
			@rmdir($tmp . '/sub');
			@rmdir($tmp);
		}
	}

	/**
	 * Verify PlaceholderParser redacts all secret keys, file placeholders, vault refs, and env defaults.
	 *
	 * @return void
	 */
	public function testSecretRedactionLeavesZeroSecretMaterial(): void {
		$doc = [
			'devices' => [
				[
					'hostname'       => 'core-sw-01',
					'community'      => 's3cr3t-comm',
					'authPassword'   => 'super-auth-pass',
					'privPassphrase' => 'super-priv-pass',
					'vaultRef'       => 'vault:secret/cacti#snmp',
					'fileRef'        => '${file:/run/secrets/snmp_pass}',
					'envWithDefault' => '${SNMP_PORT:-fallbackSecret}',
					'envPlain'       => '${CACTI_ENV}',
				],
			],
		];

		$redacted = PlaceholderParser::redact($doc);
		$encoded  = json_encode($redacted);
		$this->assertIsString($encoded);

		$this->assertStringNotContainsString('s3cr3t-comm', $encoded);
		$this->assertStringNotContainsString('super-auth-pass', $encoded);
		$this->assertStringNotContainsString('super-priv-pass', $encoded);
		$this->assertStringNotContainsString('fallbackSecret', $encoded);
		$this->assertStringNotContainsString('/run/secrets/snmp_pass', $encoded);
		$this->assertSame('${CACTI_ENV}', $redacted['devices'][0]['envPlain']);
	}
}
