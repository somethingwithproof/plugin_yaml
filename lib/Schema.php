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

namespace Somethingwithproof\Yaml;

/**
 * Plugin database schema migrations, hook registrations, and uninstall control.
 */
final class Schema {
	/**
	 * Current schema migration version for plugin_yaml.
	 */
	public const VERSION = 1;

	/**
	 * Cacti hooks registered by plugin_yaml in setup.php.
	 */
	public const HOOKS = [
		'auth_alternate_realms'            => 'yaml_auth_alternate_realms',
		'config_arrays'                    => 'yaml_config_arrays',
		'config_settings'                  => 'yaml_config_settings',
		'draw_navigation_text'             => 'yaml_draw_navigation_text',
		'casc_register_providers'          => 'yaml_casc_register_providers',
		'casc_register_template_compilers' => 'yaml_casc_register_template_compilers',
		'casc_describe_schema'             => 'yaml_casc_describe_schema',
		'doctor_register_checks'           => 'yaml_doctor_register_checks',
		'abilities_register'               => 'yaml_abilities_register',
		'api_register_resources'           => 'yaml_api_register_resources',
	];

	/**
	 * Cacti plugin directory slug.
	 */
	private const PLUGIN = 'yaml';

	/**
	 * Settings table key storing the current schema migration version.
	 */
	private const SETTING = 'yaml_schema_version';

	/**
	 * Construct a Schema manager bound to an optional PDO connection.
	 *
	 * @param \PDO|false $connection Cacti database connection handle or false for default.
	 */
	public function __construct(
		/**
		 * Cacti PDO connection handle or false for the active default connection.
		 */
		private readonly \PDO|false $connection = false,
	) {
	}

	/**
	 * Read the currently stored schema version from Cacti's settings table.
	 *
	 * @return int Stored version number, or 0 if not installed.
	 */
	public function storedVersion(): int {
		$value = db_fetch_cell_prepared(
			'SELECT value FROM settings WHERE name = ?',
			[self::SETTING],
			'',
			true,
			$this->connection
		);

		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * Return true when the stored schema version matches the code version.
	 *
	 * @return bool
	 */
	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Run idempotent schema installation and upgrade steps.
	 *
	 * @return bool True when all tables exist and the schema version was recorded.
	 */
	public function migrate(): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than plugin code (%d); refusing downgrade', $from, self::VERSION));

			return false;
		}

		foreach (self::tables() as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s; schema left at version %d', $table, $from));

				return false;
			}
		}

		db_execute_prepared(
			'REPLACE INTO settings (name, value) VALUES (?, ?)',
			[self::SETTING, (string) self::VERSION],
			true,
			$this->connection
		);

		if ($from !== self::VERSION) {
			Log::info(sprintf('Schema migrated from version %d to %d', $from, self::VERSION));
		}

		return true;
	}

	/**
	 * Return the recorded plugin version string from plugin_config.
	 *
	 * @return string
	 */
	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared(
			'SELECT version FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);
	}

	/**
	 * Record updated plugin metadata in Cacti's plugin_config table.
	 *
	 * @param string $version Plugin semantic version from INFO.
	 * @param string $author Plugin author string from INFO.
	 * @param string $homepage Plugin homepage URL from INFO.
	 * @return void
	 */
	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared(
			'UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?',
			[$version, $author, $homepage, self::PLUGIN],
			true,
			$this->connection
		);
	}

	/**
	 * Return true when plugin_yaml is enabled in plugin_config.
	 *
	 * @return bool
	 */
	public function pluginEnabled(): bool {
		return (int) db_fetch_cell_prepared(
			'SELECT status FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		) === 1;
	}

	/**
	 * Return true when all hooks and RBAC realms are registered in Cacti.
	 *
	 * @return bool
	 */
	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));
		$hooks        = (int) db_fetch_cell_prepared(
			"SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)",
			array_merge([self::PLUGIN], array_keys(self::HOOKS)),
			'',
			true,
			$this->connection
		);
		$realms = (int) db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return $hooks === count(self::HOOKS) && $realms === count(Pages::realms());
	}

	/**
	 * Remove table ownership records so Cacti preserves plugin tables on uninstall.
	 *
	 * @return void
	 */
	public function untrackTables(): void {
		db_execute_prepared(
			"DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'",
			[self::PLUGIN],
			true,
			$this->connection
		);
	}

	/**
	 * Delete the stored schema version setting during full uninstall.
	 *
	 * @return void
	 */
	public function forgetVersion(): void {
		db_execute_prepared(
			'DELETE FROM settings WHERE name = ?',
			[self::SETTING],
			true,
			$this->connection
		);
	}

	/**
	 * Check whether a table exists in the active database schema.
	 *
	 * @param string $table Unqualified table name.
	 * @return bool
	 */
	private function tableExists(string $table): bool {
		return db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
			[$table],
			'',
			true,
			$this->connection
		) > 0;
	}

	/**
	 * Return Cacti api_plugin_db_table_create() table definitions for plugin_yaml.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		return [
			'plugin_yaml_packs' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'name', 'type' => 'varchar(128)', 'NULL' => false, 'default' => ''],
					['name' => 'version', 'type' => 'varchar(32)', 'NULL' => false, 'default' => '0.0.0'],
					['name' => 'manifest_sha256', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'lock_sha256', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'source_path', 'type' => 'varchar(512)', 'NULL' => false, 'default' => ''],
					['name' => 'applied_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'applied_by', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'applied'],
					['name' => 'metadata_json', 'type' => 'mediumtext', 'NULL' => false],
				],
				'primary' => 'id',
				'unique_keys' => [
					['name' => 'uq_pack_name', 'columns' => 'name'],
				],
				'keys' => [
					['name' => 'idx_applied_at', 'columns' => 'applied_at'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Installed YAML Monitoring Packs and SHA-256 lock digests',
			],
			'plugin_yaml_queries' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'name', 'type' => 'varchar(128)', 'NULL' => false, 'default' => ''],
					['name' => 'kind', 'type' => 'varchar(32)', 'NULL' => false, 'default' => 'SnmpDataQuery'],
					['name' => 'output_file', 'type' => 'varchar(255)', 'NULL' => false, 'default' => ''],
					['name' => 'xml_sha256', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'updated_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'id',
				'unique_keys' => [
					['name' => 'uq_query_output_file', 'columns' => 'output_file'],
				],
				'keys' => [
					['name' => 'idx_query_name', 'columns' => 'name'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Managed Data Query XML files compiled from YAML with SHA-256 verification',
			],
			'plugin_yaml_audit' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'occurred_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'actor', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'source', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'cli'],
					['name' => 'action', 'type' => 'varchar(32)', 'NULL' => false, 'default' => 'validate'],
					['name' => 'plan_hash', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'succeeded'],
					['name' => 'summary_json', 'type' => 'mediumtext', 'NULL' => false],
				],
				'primary' => 'id',
				'keys' => [
					['name' => 'idx_yaml_audit_time', 'columns' => 'occurred_at'],
					['name' => 'idx_yaml_audit_hash', 'columns' => 'plan_hash'],
				],
				'type'    => 'InnoDB',
				'comment' => 'YAML reload, compile, and pack audit trail without secret values',
			],
		];
	}
}
