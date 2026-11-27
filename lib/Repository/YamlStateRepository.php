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

namespace Somethingwithproof\Yaml\Repository;

/**
 * Parameterized MariaDB persistence for plugin_yaml_packs, plugin_yaml_queries,
 * and plugin_yaml_audit tables.
 */
final class YamlStateRepository {
	/**
	 * Construct a repository bound to an optional PDO handle.
	 *
	 * @param \PDO|false $connection Cacti DB connection or false for default.
	 */
	public function __construct(
		/**
		 * Cacti PDO connection handle or false for the active default connection.
		 */
		private readonly \PDO|false $connection = false,
	) {
	}

	/**
	 * Return all installed Monitoring Packs ordered by name.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listPacks(): array {
		if (!function_exists('db_fetch_assoc_prepared')) {
			return [];
		}

		$rows = db_fetch_assoc_prepared(
			'SELECT id, name, version, manifest_sha256, lock_sha256, source_path, applied_at, applied_by, status, metadata_json FROM plugin_yaml_packs ORDER BY name ASC',
			[],
			true,
			$this->connection
		);

		return $this->normalizeRows($rows);
	}

	/**
	 * Look up a single Monitoring Pack row by unique pack name.
	 *
	 * @param string $name Pack slug.
	 * @return ?array<string, mixed>
	 */
	public function getPackByName(string $name): ?array {
		if (!function_exists('db_fetch_row_prepared')) {
			return null;
		}

		$row = db_fetch_row_prepared(
			'SELECT id, name, version, manifest_sha256, lock_sha256, source_path, applied_at, applied_by, status, metadata_json FROM plugin_yaml_packs WHERE name = ?',
			[$name],
			true,
			$this->connection
		);

		if (!is_array($row) || $row === []) {
			return null;
		}

		$out = [];

		foreach ($row as $k => $v) {
			$out[(string) $k] = $v;
		}

		return $out;
	}

	/**
	 * Insert or update an applied Monitoring Pack record.
	 *
	 * @param string $name Pack slug.
	 * @param string $version Pack SemVer string.
	 * @param string $manifestSha256 SHA-256 digest of manifest YAML.
	 * @param string $lockSha256 SHA-256 digest of lockfile YAML.
	 * @param string $sourcePath Canonical path to manifest file.
	 * @param string $actor Operator identity.
	 * @param string $status Status string (e.g. `applied`).
	 * @param array<string, mixed> $metadata Redacted manifest metadata.
	 * @return void
	 */
	public function upsertPack(
		string $name,
		string $version,
		string $manifestSha256,
		string $lockSha256,
		string $sourcePath,
		string $actor,
		string $status,
		array $metadata,
	): void {
		if (!function_exists('db_execute_prepared')) {
			return;
		}

		$json = json_encode($metadata, JSON_UNESCAPED_SLASHES);

		db_execute_prepared(
			'INSERT INTO plugin_yaml_packs (name, version, manifest_sha256, lock_sha256, source_path, applied_at, applied_by, status, metadata_json)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
			 ON DUPLICATE KEY UPDATE
			   version = VALUES(version),
			   manifest_sha256 = VALUES(manifest_sha256),
			   lock_sha256 = VALUES(lock_sha256),
			   source_path = VALUES(source_path),
			   applied_at = VALUES(applied_at),
			   applied_by = VALUES(applied_by),
			   status = VALUES(status),
			   metadata_json = VALUES(metadata_json)',
			[
				substr($name, 0, 128),
				substr($version, 0, 32),
				substr($manifestSha256, 0, 64),
				substr($lockSha256, 0, 64),
				substr($sourcePath, 0, 512),
				time(),
				substr($actor, 0, 64),
				substr($status, 0, 16),
				is_string($json) ? $json : '{}',
			],
			true,
			$this->connection
		);
	}

	/**
	 * Return all tracked compiled Data Query XML records ordered by output_file.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listQueries(): array {
		if (!function_exists('db_fetch_assoc_prepared')) {
			return [];
		}

		$rows = db_fetch_assoc_prepared(
			'SELECT id, name, kind, output_file, xml_sha256, updated_at FROM plugin_yaml_queries ORDER BY output_file ASC',
			[],
			true,
			$this->connection
		);

		return $this->normalizeRows($rows);
	}

	/**
	 * Insert or update a tracked Data Query XML checksum record.
	 *
	 * @param string $name Query name.
	 * @param string $kind Query kind (`SnmpDataQuery` or `ScriptDataQuery`).
	 * @param string $outputFile Allowlisted relative output path.
	 * @param string $xmlSha256 SHA-256 of compiled XML on disk.
	 * @return void
	 */
	public function upsertQuery(string $name, string $kind, string $outputFile, string $xmlSha256): void {
		if (!function_exists('db_execute_prepared')) {
			return;
		}

		db_execute_prepared(
			'INSERT INTO plugin_yaml_queries (name, kind, output_file, xml_sha256, updated_at)
			 VALUES (?, ?, ?, ?, ?)
			 ON DUPLICATE KEY UPDATE
			   name = VALUES(name),
			   kind = VALUES(kind),
			   xml_sha256 = VALUES(xml_sha256),
			   updated_at = VALUES(updated_at)',
			[
				substr($name, 0, 128),
				substr($kind, 0, 32),
				substr($outputFile, 0, 255),
				substr($xmlSha256, 0, 64),
				time(),
			],
			true,
			$this->connection
		);
	}

	/**
	 * Insert a sanitized audit record into plugin_yaml_audit.
	 *
	 * @param string $actor Operator username or CLI identity.
	 * @param string $source Invocation channel (`cli`, `web`, `hook`).
	 * @param string $action Operation name (`reload`, `pack-apply`, `compile`).
	 * @param string $planHash SHA-256 plan or artifact digest.
	 * @param string $status Outcome (`succeeded` or `failed`).
	 * @param array<string, mixed> $summary Redacted structured summary.
	 * @return int Inserted row ID or 0 when unavailable.
	 */
	public function recordAudit(
		string $actor,
		string $source,
		string $action,
		string $planHash,
		string $status,
		array $summary,
	): int {
		if (!function_exists('db_execute_prepared')) {
			return 0;
		}

		$json = json_encode($summary, JSON_UNESCAPED_SLASHES);

		db_execute_prepared(
			'INSERT INTO plugin_yaml_audit (occurred_at, actor, source, action, plan_hash, status, summary_json)
			 VALUES (?, ?, ?, ?, ?, ?, ?)',
			[
				time(),
				substr($actor, 0, 64),
				substr($source, 0, 16),
				substr($action, 0, 32),
				substr($planHash, 0, 64),
				substr($status, 0, 16),
				is_string($json) ? $json : '{}',
			],
			true,
			$this->connection
		);

		return function_exists('db_fetch_insert_id') ? (int) db_fetch_insert_id($this->connection) : 0;
	}

	/**
	 * Retrieve recent audit log rows ordered newest first.
	 *
	 * @param int $limit Maximum rows to return (bounded between 1 and 200).
	 * @return list<array<string, mixed>>
	 */
	public function getRecentAudits(int $limit = 50): array {
		if (!function_exists('db_fetch_assoc_prepared')) {
			return [];
		}

		$bounded = max(1, min(200, $limit));
		$rows    = db_fetch_assoc_prepared(
			'SELECT id, occurred_at, actor, source, action, plan_hash, status, summary_json FROM plugin_yaml_audit ORDER BY id DESC LIMIT ' . $bounded,
			[],
			true,
			$this->connection
		);

		return $this->normalizeRows($rows);
	}

	/**
	 * Normalize arbitrary database result rows into a typed list of string-keyed arrays.
	 *
	 * @param mixed $rows Raw result from db_fetch_assoc_prepared().
	 * @return list<array<string, mixed>>
	 */
	private function normalizeRows(mixed $rows): array {
		if (!is_array($rows)) {
			return [];
		}

		$out = [];

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$normalized = [];

			foreach ($row as $k => $v) {
				$normalized[(string) $k] = $v;
			}

			$out[] = $normalized;
		}

		return $out;
	}
}
