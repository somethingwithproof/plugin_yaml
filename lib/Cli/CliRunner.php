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

namespace Somethingwithproof\Yaml\Cli;

use Somethingwithproof\Yaml\Codec\YamlException;
use Somethingwithproof\Yaml\Merge\MergePolicy;
use Somethingwithproof\Yaml\Security\PathGuard;
use Somethingwithproof\Yaml\Service\YamlService;

/**
 * Testable CLI command dispatcher backing `cli/yaml.php`.
 *
 * Supports `decompile`, `compile`, `merge`, `schema`, `validate`, `reload`,
 * `pack-export`, `pack-validate`, `pack-plan`, `pack-apply`, and `help`.
 */
final class CliRunner {
	/**
	 * Construct a CLI runner around a configured YamlService.
	 *
	 * @param YamlService $service Core YAML service.
	 */
	public function __construct(
		/**
		 * Core YAML service instance.
		 */
		private readonly YamlService $service,
	) {
	}

	/**
	 * Execute a CLI subcommand and return structured stdout, stderr, and exit code.
	 *
	 * @param list<string> $argv Command arguments excluding script name.
	 * @param string $actor Operating system user running the CLI command.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	public function run(array $argv, string $actor = 'cli'): array {
		if ($argv === [] || in_array($argv[0], ['help', '--help', '-h'], true)) {
			return [
				'exitCode' => 0,
				'stdout'   => self::usage(),
				'stderr'   => '',
			];
		}

		$command = $argv[0];
		$opts    = self::parseOptions(array_slice($argv, 1));

		try {
			return match ($command) {
				'decompile'     => $this->cmdDecompile($opts),
				'compile'       => $this->cmdCompile($opts),
				'merge'         => $this->cmdMerge($opts),
				'schema'        => $this->cmdSchema($opts),
				'validate'      => $this->cmdValidate($opts),
				'reload'        => $this->cmdReload($opts, $actor),
				'pack-export'   => $this->cmdPackExport($opts),
				'pack-validate' => $this->cmdPackValidate($opts),
				'pack-plan'     => $this->cmdPackPlan($opts),
				'pack-apply'    => $this->cmdPackApply($opts, $actor),
				default         => [
					'exitCode' => 2,
					'stdout'   => '',
					'stderr'   => sprintf("Unknown command: %s\n\n%s", $command, self::usage()),
				],
			};
		} catch (YamlException $e) {
			return [
				'exitCode' => 1,
				'stdout'   => '',
				'stderr'   => implode("\n", $e->lines()) . "\n",
			];
		} catch (\Throwable $e) {
			return [
				'exitCode' => 1,
				'stdout'   => '',
				'stderr'   => $e->getMessage() . "\n",
			];
		}
	}

	/**
	 * Handle `decompile --in=<file.xml> [--out=<file.yaml>] [--name=<hint>]`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdDecompile(array $opts): array {
		$in = self::strOpt($opts, 'in');

		if ($in === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "Missing required option --in=<file.xml>\n"];
		}

		$xml  = PathGuard::readBoundedFile($in, 16777216);
		$hint = self::strOpt($opts, 'name');
		$yaml = $this->service->decompileXml($xml, $hint);
		$out  = self::strOpt($opts, 'out');

		if ($out !== '') {
			$sha = PathGuard::writeAtomic($out, $yaml);

			return ['exitCode' => 0, 'stdout' => sprintf("Wrote %s (sha256: %s)\n", $out, $sha), 'stderr' => ''];
		}

		return ['exitCode' => 0, 'stdout' => $yaml, 'stderr' => ''];
	}

	/**
	 * Handle `compile --in=<file.yaml> [--out=<file.xml>]`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdCompile(array $opts): array {
		$in = self::strOpt($opts, 'in');

		if ($in === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "Missing required option --in=<file.yaml>\n"];
		}

		$yaml     = PathGuard::readBoundedFile($in);
		$compiled = $this->service->compileYaml($yaml);
		$out      = self::strOpt($opts, 'out');

		if ($out !== '') {
			$sha = PathGuard::writeAtomic($out, $compiled['xml']);

			return ['exitCode' => 0, 'stdout' => sprintf("Compiled %s -> %s (sha256: %s)\n", $compiled['kind'], $out, $sha), 'stderr' => ''];
		}

		return ['exitCode' => 0, 'stdout' => $compiled['xml'], 'stderr' => ''];
	}

	/**
	 * Handle `merge --source=<path> [--policy=strict|overlay] [--list-mode=mergeByKey|append|replace] [--out=<file.yaml>]`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdMerge(array $opts): array {
		$source = self::strOpt($opts, 'source');

		if ($source === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "Missing required option --source=<dir-or-list>\n"];
		}

		$policy   = $this->resolvePolicy(self::strOpt($opts, 'policy'));
		$listMode = self::strOpt($opts, 'list-mode') ?: MergePolicy::LIST_MERGE_BY_KEY;
		$merged   = $this->service->mergeSources($source, $policy, $listMode);
		$out      = self::strOpt($opts, 'out');

		if ($out !== '') {
			PathGuard::writeAtomic($out, $merged['redactedYaml']);

			return ['exitCode' => 0, 'stdout' => sprintf("Merged %d file(s) -> %s (planHash: %s)\n", count($merged['files']), $out, $merged['planHash']), 'stderr' => ''];
		}

		return ['exitCode' => 0, 'stdout' => $merged['redactedYaml'], 'stderr' => ''];
	}

	/**
	 * Handle `schema [--out=<file.json>] [--reference=markdown|html]`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdSchema(array $opts): array {
		$refFormat = self::strOpt($opts, 'reference');
		$out       = self::strOpt($opts, 'out');

		if ($refFormat !== '') {
			$rendered = $this->service->renderReference($refFormat === 'html' ? 'html' : 'markdown');

			if ($out !== '') {
				PathGuard::writeAtomic($out, $rendered);

				return ['exitCode' => 0, 'stdout' => sprintf("Wrote reference docs to %s\n", $out), 'stderr' => ''];
			}

			return ['exitCode' => 0, 'stdout' => $rendered, 'stderr' => ''];
		}

		$generated = $this->service->generateSchema();

		if ($out !== '') {
			$sha = PathGuard::writeAtomic($out, $generated['json']);

			return ['exitCode' => 0, 'stdout' => sprintf("Wrote JSON Schema (%d plugin(s)) -> %s (sha256: %s)\n", count($generated['plugins']), $out, $sha), 'stderr' => ''];
		}

		return ['exitCode' => 0, 'stdout' => $generated['json'], 'stderr' => ''];
	}

	/**
	 * Handle `validate --source=<path> [--policy=strict|overlay]`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdValidate(array $opts): array {
		$source = self::strOpt($opts, 'source');

		if ($source === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "Missing required option --source=<path>\n"];
		}

		$policy = $this->resolvePolicy(self::strOpt($opts, 'policy'));
		$result = $this->service->validateSource($source, $policy);

		if (!$result['valid']) {
			return [
				'exitCode' => 1,
				'stdout'   => '',
				'stderr'   => implode("\n", $result['errors']) . "\n",
			];
		}

		return [
			'exitCode' => 0,
			'stdout'   => sprintf("VALID kind=%s files=%d planHash=%s\n", $result['kind'], count($result['files']), $result['planHash']),
			'stderr'   => '',
		];
	}

	/**
	 * Handle `reload --source=<path> [--dry-run] [--confirm=<PLAN_HASH>] [--policy=strict|overlay]`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @param string $actor Operator identity.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdReload(array $opts, string $actor): array {
		$source = self::strOpt($opts, 'source');

		if ($source === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "Missing required option --source=<path>\n"];
		}

		$confirm = self::strOpt($opts, 'confirm');
		$dryRun  = ($opts['dry-run'] ?? false) === true || $confirm === '';
		$policy  = $this->resolvePolicy(self::strOpt($opts, 'policy'));

		$result = $this->service->reload($source, $dryRun, $confirm, $actor, 'cli', $policy);

		if (!$result['succeeded']) {
			return [
				'exitCode' => 1,
				'stdout'   => '',
				'stderr'   => implode("\n", $result['errors'] !== [] ? $result['errors'] : [$result['message']]) . "\n",
			];
		}

		return [
			'exitCode' => 0,
			'stdout'   => $result['message'] . "\nplanHash=" . $result['planHash'] . "\n",
			'stderr'   => '',
		];
	}

	/**
	 * Handle `pack-export --dir=<pack-dir> --name=<slug> [--version=1.0.0] [--out=<manifest.yaml>]`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdPackExport(array $opts): array {
		$dir  = self::strOpt($opts, 'dir');
		$name = self::strOpt($opts, 'name');

		if ($dir === '' || $name === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "Missing required options --dir=<pack-dir> --name=<slug>\n"];
		}

		$version = self::strOpt($opts, 'version') ?: '1.0.0';
		$desc    = self::strOpt($opts, 'description');
		$export  = $this->service->packService()->export($dir, $name, $version, $desc);
		$out     = self::strOpt($opts, 'out');

		if ($out !== '') {
			PathGuard::writeAtomic($out, $export['manifestYaml']);
			$lockOut = dirname($out) . '/cacti-pack.lock.yaml';
			PathGuard::writeAtomic($lockOut, $export['lockYaml']);

			return [
				'exitCode' => 0,
				'stdout'   => sprintf("Exported pack %s@%s (manifest=%s, lock=%s)\n", $name, $version, $export['manifestSha256'], $export['lockSha256']),
				'stderr'   => '',
			];
		}

		return ['exitCode' => 0, 'stdout' => $export['manifestYaml'], 'stderr' => ''];
	}

	/**
	 * Handle `pack-validate --manifest=<pack.yaml> [--require-lock]`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdPackValidate(array $opts): array {
		$manifest = self::strOpt($opts, 'manifest');

		if ($manifest === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "Missing required option --manifest=<pack.yaml>\n"];
		}

		$requireLock = ($opts['require-lock'] ?? false) === true;
		$result      = $this->service->packService()->validate($manifest, $requireLock);

		if (!$result['valid']) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => implode("\n", $result['errors']) . "\n"];
		}

		return [
			'exitCode' => 0,
			'stdout'   => sprintf("VALID pack=%s@%s manifestSha256=%s\n", $result['manifest']->name, $result['manifest']->version, $result['manifestSha256']),
			'stderr'   => '',
		];
	}

	/**
	 * Handle `pack-plan --manifest=<pack.yaml>`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdPackPlan(array $opts): array {
		$manifest = self::strOpt($opts, 'manifest');

		if ($manifest === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "Missing required option --manifest=<pack.yaml>\n"];
		}

		$plan = $this->service->packService()->plan($manifest);

		if ($plan['errors'] !== []) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => implode("\n", $plan['errors']) . "\n"];
		}

		$lines = [
			sprintf('pack=%s@%s', $plan['manifest']->name, $plan['manifest']->version),
			sprintf('planHash=%s', $plan['planHash']),
			sprintf('lockSha256=%s', $plan['lockSha256']),
			sprintf('actions=%d', count($plan['actions'])),
		];

		return ['exitCode' => 0, 'stdout' => implode("\n", $lines) . "\n", 'stderr' => ''];
	}

	/**
	 * Handle `pack-apply --manifest=<pack.yaml> --confirm=<PLAN_HASH>`.
	 *
	 * @param array<string, string|bool> $opts Parsed CLI flags.
	 * @param string $actor Operator identity.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdPackApply(array $opts, string $actor): array {
		$manifest = self::strOpt($opts, 'manifest');
		$confirm  = self::strOpt($opts, 'confirm');

		if ($manifest === '' || $confirm === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "Missing required options --manifest=<pack.yaml> --confirm=<PLAN_HASH>\n"];
		}

		$res = $this->service->packService()->apply($manifest, $confirm, $actor, 'cli');

		if (!$res['succeeded']) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => implode("\n", $res['errors']) . "\n"];
		}

		return [
			'exitCode' => 0,
			'stdout'   => sprintf("APPLIED planHash=%s lockSha256=%s files=%d\n", $res['planHash'], $res['lockSha256'], count($res['writtenFiles'])),
			'stderr'   => '',
		];
	}

	/**
	 * Parse `--key=value` and `--flag` arguments cleanly.
	 *
	 * @param list<string> $args Raw argument slice.
	 * @return array<string, string|bool>
	 */
	private static function parseOptions(array $args): array {
		$opts = [];

		foreach ($args as $arg) {
			if (!str_starts_with($arg, '--')) {
				continue;
			}

			$body = substr($arg, 2);

			if (str_contains($body, '=')) {
				[$key, $value] = explode('=', $body, 2);
				$opts[$key]    = $value;
			} else {
				$opts[$body] = true;
			}
		}

		return $opts;
	}

	/**
	 * Read a string option from the parsed flags array.
	 *
	 * @param array<string, string|bool> $opts Parsed flags.
	 * @param string $key Flag name without leading dashes.
	 * @return string Trimmed value or empty string.
	 */
	private static function strOpt(array $opts, string $key): string {
		return isset($opts[$key]) && is_string($opts[$key]) ? trim($opts[$key]) : '';
	}

	/**
	 * Resolve an optional CLI `--policy` flag into a MergePolicy enum.
	 *
	 * @param string $raw Raw policy string or empty string for default.
	 * @return ?MergePolicy
	 * @throws YamlException When an unknown policy value is supplied.
	 */
	private function resolvePolicy(string $raw): ?MergePolicy {
		if ($raw === '') {
			return null;
		}

		$policy = MergePolicy::tryFrom(strtolower($raw));

		if ($policy === null) {
			throw new YamlException(sprintf('Invalid merge policy "%s" (expected "strict" or "overlay").', $raw));
		}

		return $policy;
	}

	/**
	 * Return CLI usage text.
	 *
	 * @return string
	 */
	public static function usage(): string {
		return <<<'TXT'
Usage: php plugins/yaml/cli/yaml.php <command> [options]

Commands:
  decompile     --in=<file.xml> [--out=<file.yaml>] [--name=<hint>]
  compile       --in=<file.yaml> [--out=<file.xml>]
  merge         --source=<dir-or-list> [--policy=strict|overlay] [--list-mode=mergeByKey|append|replace] [--out=<file.yaml>]
  schema        [--out=<cacti-casc.schema.json>] [--reference=markdown|html]
  validate      --source=<path> [--policy=strict|overlay]
  reload        --source=<path> [--dry-run] [--confirm=<PLAN_HASH>] [--policy=strict|overlay]
  pack-export   --dir=<pack-dir> --name=<slug> [--version=1.0.0] [--out=<pack.yaml>]
  pack-validate --manifest=<pack.yaml> [--require-lock]
  pack-plan     --manifest=<pack.yaml>
  pack-apply    --manifest=<pack.yaml> --confirm=<PLAN_HASH>
  help

TXT;
	}
}
