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
use Somethingwithproof\Yaml\Merge\FragmentMerger;
use Somethingwithproof\Yaml\Merge\MergePolicy;
use Somethingwithproof\Yaml\Merge\PlaceholderParser;

/**
 * Unit tests for JCasC-style multi-file fragment merging, list merge modes,
 * strict conflict detection, and placeholder inspection.
 */
final class FragmentMergerTest extends TestCase {
	/**
	 * Verify lexical directory merging, deep map merge, mergeByKey lists, and provenance.
	 *
	 * @return void
	 */
	public function testDirectoryMergeByKeyAndStrictVsOverlay(): void {
		$dir = sys_get_temp_dir() . '/plugin_yaml_merge_' . bin2hex(random_bytes(4));
		mkdir($dir, 0750, true);

		file_put_contents($dir . '/00-base.yaml', <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  settings:
    values:
      poller_interval: 300
      cron_interval: 300
  devices:
    - hostname: edge-01.example.net
      site: sjc
      snmpVersion: 2
      community: ${SNMP_COMM:-public}
YAML);

		file_put_contents($dir . '/10-extra.yaml', <<<'YAML'
apiVersion: cacti.net/v1alpha1
kind: CactiConfig
spec:
  sites:
    - name: sjc
      timezone: UTC
  devices:
    - hostname: edge-01.example.net
      description: Edge Router 01
    - hostname: edge-02.example.net
      site: sjc
      snmpVersion: 2
      community: vault:cacti/snmp#edge
YAML);

		try {
			$merged = FragmentMerger::mergeSources($dir, MergePolicy::STRICT, MergePolicy::LIST_MERGE_BY_KEY);

			$this->assertCount(2, $merged['files']);
			$this->assertStringEndsWith('00-base.yaml', $merged['files'][0]);
			$this->assertStringEndsWith('10-extra.yaml', $merged['files'][1]);
			$this->assertCount(2, $merged['document']['spec']['devices']);
			$this->assertSame('Edge Router 01', $merged['document']['spec']['devices'][0]['description']);
			$this->assertSame('sjc', $merged['document']['spec']['devices'][0]['site']);
			$this->assertCount(2, $merged['placeholders']);

			// Add conflicting scalar file to test strict rejection vs overlay override.
			file_put_contents($dir . '/20-override.yaml', <<<'YAML'
spec:
  settings:
    values:
      poller_interval: 60
YAML);

			try {
				FragmentMerger::mergeSources($dir, MergePolicy::STRICT);
				$this->fail('Expected strict scalar conflict failure.');
			} catch (YamlException $e) {
				$this->assertStringContainsString('conflicting scalar', $e->getMessage());
			}

			$overlaid = FragmentMerger::mergeSources($dir, MergePolicy::OVERLAY);
			$this->assertSame(60, $overlaid['document']['spec']['settings']['values']['poller_interval']);
		} finally {
			array_map('unlink', glob($dir . '/*.yaml') ?: []);
			@rmdir($dir);
		}
	}

	/**
	 * Verify replace and append list merge modes plus invalid placeholder rejection.
	 *
	 * @return void
	 */
	public function testListModesAndPlaceholderValidation(): void {
		$fragments = [
			['source' => 'a.yaml', 'data' => ['tags' => ['one', 'two']]],
			['source' => 'b.yaml', 'data' => ['tags' => ['three']]],
		];

		$replaced = FragmentMerger::mergeFragments($fragments, MergePolicy::OVERLAY, MergePolicy::LIST_REPLACE);
		$this->assertSame(['three'], $replaced['document']['tags']);

		$appended = FragmentMerger::mergeFragments($fragments, MergePolicy::OVERLAY, MergePolicy::LIST_APPEND);
		$this->assertSame(['one', 'two', 'three'], $appended['document']['tags']);

		$bad = PlaceholderParser::inspect([
			'a' => '${file:relative/path}',
			'b' => '${bad-var}',
			'c' => 'vault:../bad',
		]);
		$this->assertCount(3, $bad['errors']);
		$this->assertTrue(PlaceholderParser::containsPlaceholder('vault:a#b'));
		$this->assertTrue(PlaceholderParser::containsPlaceholder('${ENV_VAR}'));
		$this->assertFalse(PlaceholderParser::containsPlaceholder('plain text'));
	}
}
