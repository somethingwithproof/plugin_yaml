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
use Somethingwithproof\Yaml\Validation\CrossParameterValidator;

/**
 * Unit tests for CrossParameterValidator grounded in Yin et al. (ACM SOSP 2011),
 * covering both illegal single-field values and cross-parameter contradictions.
 */
final class CrossParameterValidatorTest extends TestCase {
	/**
	 * Verify valid SNMPv2, SNMPv3 authPriv, intervals, and referential consistency pass cleanly.
	 *
	 * @return void
	 */
	public function testValidCascConfigPassesAllInvariants(): void {
		$doc = [
			'apiVersion' => 'cacti.net/v1alpha1',
			'kind'       => 'CactiConfig',
			'spec'       => [
				'settings' => [
					'values' => [
						'poller_interval' => 60,
						'cron_interval'   => 300,
					],
				],
				'sites' => [
					['name' => 'dc-west'],
				],
				'pollers' => [
					['name' => 'Main Poller'],
				],
				'templates' => [
					['name' => 'Generic SNMP Device', 'path' => 'templates/snmp.yaml'],
				],
				'devices' => [
					[
						'hostname'    => 'sw-1.dc-west.example',
						'site'        => 'dc-west',
						'poller'      => 'Main Poller',
						'template'    => 'Generic SNMP Device',
						'snmpVersion' => 2,
						'community'   => '${SNMP_COMMUNITY}',
					],
					[
						'hostname'       => 'fw-1.dc-west.example',
						'site'           => 'dc-west',
						'poller'         => 'Main Poller',
						'template'       => 'Generic SNMP Device',
						'snmpVersion'    => 3,
						'username'       => 'cacti-ro',
						'authProtocol'   => 'SHA256',
						'authPassword'   => '${SNMP_AUTH_PASS}',
						'privProtocol'   => 'AES256',
						'privPassphrase' => '${SNMP_PRIV_PASS}',
					],
				],
				'trees' => [
					[
						'name'  => 'Core',
						'items' => [
							[
								'header'   => 'West',
								'children' => [
									['site' => 'dc-west'],
									['device' => 'sw-1.dc-west.example'],
								],
							],
						],
					],
				],
				'plugins' => [
					'yaml' => [
						'mergePolicy' => 'strict',
						'packs'       => [
							['name' => 'linux-base', 'version' => '1.0.0', 'sourcePath' => 'packs/linux/pack.yaml', 'lockSha256' => str_repeat('a', 64)],
						],
					],
				],
			],
		];

		$this->assertSame([], CrossParameterValidator::validate($doc));
	}

	/**
	 * Verify SNMPv1/v2 and SNMPv3 cross-parameter contradictions are detected.
	 *
	 * @return void
	 */
	public function testDetectsSnmpCrossParameterContradictions(): void {
		$doc = [
			'kind' => 'CactiConfig',
			'spec' => [
				'devices' => [
					// Illegal SNMP version
					['hostname' => 'bad-ver', 'snmpVersion' => 9],
					// SNMPv2 missing community + mixing SNMPv3 credentials
					['hostname' => 'bad-v2', 'snmpVersion' => 2, 'username' => 'oops', 'authPassword' => 'secret'],
					// SNMPv3 privProtocol without authProtocol (noAuthPriv contradiction) and missing username/passphrase + community
					[
						'hostname'     => 'bad-v3-noauthpriv',
						'snmpVersion'  => 3,
						'authProtocol' => '[None]',
						'privProtocol' => 'AES',
						'community'    => 'public',
					],
					// SNMPv3 authProtocol set without authPassword, and privPassphrase set with privProtocol [None]
					[
						'hostname'       => 'bad-v3-pass',
						'snmpVersion'    => 3,
						'username'       => 'cacti',
						'authProtocol'   => 'SHA256',
						'privProtocol'   => '[None]',
						'privPassphrase' => 'orphan-priv',
					],
					// SNMPv3 authPassword set while authProtocol is [None]
					[
						'hostname'     => 'bad-v3-authnone',
						'snmpVersion'  => 3,
						'username'     => 'cacti',
						'authProtocol' => '[None]',
						'authPassword' => 'orphan-auth',
					],
				],
			],
		];

		$errors = CrossParameterValidator::validate($doc);
		$joined = implode("\n", $errors);

		$this->assertStringContainsString('snmpVersion must be one of', $joined);
		$this->assertStringContainsString('requires a non-empty community string', $joined);
		$this->assertStringContainsString('must not combine SNMPv3 credentials', $joined);
		$this->assertStringContainsString('SNMPv3 requires a non-empty username', $joined);
		$this->assertStringContainsString('cannot be enabled without an authProtocol', $joined);
		$this->assertStringContainsString('requires a non-empty privPassphrase', $joined);
		$this->assertStringContainsString('must not declare a v1/v2 community string', $joined);
		$this->assertStringContainsString('requires a non-empty authPassword', $joined);
		$this->assertStringContainsString('privPassphrase is set while privProtocol is [None]', $joined);
		$this->assertStringContainsString('authPassword is set while authProtocol is [None]', $joined);
	}

	/**
	 * Verify poller/cron interval, undeclared references, and plugin_yaml policy contradictions.
	 *
	 * @return void
	 */
	public function testDetectsIntervalReferenceAndPluginPolicyContradictions(): void {
		$doc = [
			'kind' => 'CactiConfig',
			'spec' => [
				'settings' => [
					'values' => [
						'poller_interval' => 90,
						'cron_interval'   => 300,
					],
				],
				'sites' => [
					['name' => 'dc-east'],
				],
				'templates' => [
					['name' => 'Known Template', 'path' => 'templates/known.yaml'],
				],
				'devices' => [
					[
						'hostname' => 'router-1',
						'site'     => 'ghost-site',
						'poller'   => 'ghost-poller',
						'template' => 'ghost-template',
					],
				],
				'trees' => [
					[
						'name'  => 'Broken Tree',
						'items' => [
							['site' => 'ghost-site'],
							['device' => 'ghost-device'],
						],
					],
				],
				'plugins' => [
					'yaml' => [
						'mergePolicy' => 'invalid-mode',
						'packs'       => [
							['name' => 'dup-pack', 'version' => '1.0.0', 'lockSha256' => str_repeat('a', 64)],
							['name' => 'dup-pack', 'version' => '2.0.0'],
						],
					],
				],
			],
		];

		$errors = CrossParameterValidator::validate($doc);
		$joined = implode("\n", $errors);

		$this->assertStringContainsString('must divide cron_interval', $joined);
		$this->assertStringContainsString('undeclared site "ghost-site"', $joined);
		$this->assertStringContainsString('undeclared poller "ghost-poller"', $joined);
		$this->assertStringContainsString('undeclared template "ghost-template"', $joined);
		$this->assertStringContainsString('undeclared device "ghost-device"', $joined);
		$this->assertStringContainsString('unsupported policy "invalid-mode"', $joined);
		$this->assertStringContainsString('duplicate pack name "dup-pack"', $joined);
		$this->assertStringContainsString('lockSha256 requires a non-empty sourcePath', $joined);
	}

	/**
	 * Verify Data Query, Template Bundle, and Monitoring Pack cross-parameter checks.
	 *
	 * @return void
	 */
	public function testDetectsDataQueryTemplateAndPackInvariants(): void {
		$dqErrors = CrossParameterValidator::validate([
			'kind' => 'SnmpDataQuery',
			'spec' => [
				'oidIndexParse' => 'OID/REGEXP:^.*\\.([0-9]+)$',
				'indexOrder'    => ['ifOutOctets', 'missingField'],
				'fields'        => [
					'ifOutOctets' => [
						'name'      => 'Bytes Out',
						'direction' => 'output',
						'queryName' => 'invalid_for_snmp',
					],
				],
			],
		]);

		$dqJoined = implode("\n", $dqErrors);
		$this->assertStringContainsString('must not define script queryName', $dqJoined);
		$this->assertStringContainsString('at least one field must have direction "input"', $dqJoined);
		$this->assertStringContainsString('must have direction "input" or "input-output"', $dqJoined);
		$this->assertStringContainsString('references undefined field "missingField"', $dqJoined);
		$this->assertStringContainsString('requires spec.oidIndex', $dqJoined);

		$tplErrors = CrossParameterValidator::validate([
			'kind' => 'CactiTemplateBundle',
			'spec' => [
				'objects' => [
					['ref' => 'graph_template/cpu', 'type' => 'graph_template', 'fields' => ['item' => 'ref:data_template/missing']],
					['ref' => 'graph_template/cpu', 'type' => 'graph_template', 'fields' => []],
				],
			],
		]);
		$this->assertStringContainsString('duplicate symbolic ref', implode("\n", $tplErrors));
		$this->assertStringContainsString('dangling symbolic reference', implode("\n", $tplErrors));

		$packErrors = CrossParameterValidator::validate([
			'kind'     => 'MonitoringPack',
			'metadata' => ['cactiCompat' => '0.8.8'],
			'spec'     => [],
		]);
		$this->assertStringContainsString('at least one template, dataQuery, or cascFragment', implode("\n", $packErrors));
		$this->assertStringContainsString('below minimum supported Cacti 1.2.0', implode("\n", $packErrors));
	}
}
