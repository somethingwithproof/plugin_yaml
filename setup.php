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

/*
 * Cacti includes setup.php on every request regardless of PHP version. Keep
 * this file PHP 8.0 parseable so sites running PHP 8.0 or 8.1 receive a clean
 * refusal instead of a fatal parse error.
 */

/**
 * Return true when the active PHP runtime satisfies the plugin minimum (8.2.0).
 *
 * @return bool
 */
function yaml_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

/**
 * Register the zero-dependency PSR-4 autoloader when running on PHP 8.2+.
 *
 * @return bool True when booted cleanly; false on unsupported PHP versions.
 */
function yaml_boot(): bool {
	if (!yaml_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Emit an error and return true if the PHP runtime is older than 8.2.0.
 *
 * @return bool True when refused due to unsupported PHP version.
 */
function yaml_refuse_unsupported_php(): bool {
	if (yaml_php_supported()) {
		return false;
	}

	$message = __('The YAML plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'yaml');

	cacti_log('ERROR: ' . $message, false, 'YAML');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('yaml_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * Parse and return plugin metadata from the INFO file.
 *
 * @return array<string, string>
 */
function plugin_yaml_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

/**
 * Cacti plugin installation lifecycle entry point.
 *
 * @return void
 */
function plugin_yaml_install(): void {
	if (yaml_refuse_unsupported_php()) {
		return;
	}

	plugin_yaml_upgrade();
}

/**
 * Cacti plugin configuration check entry point.
 *
 * @return bool
 */
function plugin_yaml_check_config(): bool {
	if (yaml_refuse_unsupported_php()) {
		return false;
	}

	return plugin_yaml_upgrade();
}

/**
 * Run idempotent schema migrations, hook registrations, and version sync.
 *
 * @return bool
 */
function plugin_yaml_upgrade(): bool {
	if (!yaml_boot()) {
		return false;
	}

	$info   = plugin_yaml_version();
	$schema = new \Somethingwithproof\Yaml\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === ($info['version'] ?? '') && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	yaml_setup_hooks();
	$schema->recordPluginVersion($info['version'] ?? '0.1.0', $info['author'] ?? '', $info['homepage'] ?? '');

	return true;
}

/**
 * Register plugin hooks and 3-tier RBAC realms.
 *
 * Kept procedural with *_setup_* naming because Cacti's
 * api_plugin_register_hook() and api_plugin_register_realm() validate the
 * caller's function name.
 *
 * @return void
 */
function yaml_setup_hooks(): void {
	global $config;

	if (!function_exists('replicate_out') && isset($config['library_path']) && is_file($config['library_path'] . '/poller.php')) {
		include_once($config['library_path'] . '/poller.php');
	}

	$enabled = (new \Somethingwithproof\Yaml\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Yaml\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('yaml', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Yaml\Pages::realms() as $realm) {
		api_plugin_register_realm('yaml', implode(',', $realm['files']), $realm['name'], $realm['default']);
	}
}

/**
 * Cacti plugin uninstall entry point with optional table retention.
 *
 * @return void
 */
function plugin_yaml_uninstall(): void {
	if (!yaml_boot()) {
		return;
	}

	$schema = new \Somethingwithproof\Yaml\Schema(false);

	if (read_config_option('yaml_uninstall_keep_tables') == 'on') {
		$schema->untrackTables();
		cacti_log('NOTE: Uninstall kept plugin_yaml tables as configured', false, 'YAML');
	} else {
		$schema->forgetVersion();
	}
}

/**
 * Load active plugin_yaml settings from Cacti's settings table.
 *
 * @return \Somethingwithproof\Yaml\Settings
 */
function yaml_settings(): \Somethingwithproof\Yaml\Settings {
	$row = array();

	foreach (\Somethingwithproof\Yaml\Settings::NAMES as $name) {
		$row[$name] = read_config_option($name);
	}

	return \Somethingwithproof\Yaml\Settings::fromRow($row);
}

/**
 * Collect plain-array schema descriptors from enabled plugins implementing the
 * optional `casc_describe_schema` hook without importing foreign plugin classes.
 *
 * @return list<array<string, mixed>>
 */
function yaml_collect_schema_hooks(): array {
	if (!function_exists('api_plugin_hook_function')) {
		return array();
	}

	$collected = api_plugin_hook_function('casc_describe_schema', array());

	if (!is_array($collected)) {
		return array();
	}

	$result = array();

	foreach ($collected as $item) {
		if (is_array($item)) {
			$normalized = array();

			foreach ($item as $k => $v) {
				$normalized[(string) $k] = $v;
			}

			$result[] = $normalized;
		}
	}

	return $result;
}

/**
 * Construct the core YamlService instance for CLI, UI, and hooks.
 *
 * @return \Somethingwithproof\Yaml\Service\YamlService
 */
function yaml_service(): \Somethingwithproof\Yaml\Service\YamlService {
	global $config;

	$settings = yaml_settings();
	$repo     = new \Somethingwithproof\Yaml\Repository\YamlStateRepository(false);
	$basePath = is_string($config['base_path'] ?? null) ? $config['base_path'] : '';

	return new \Somethingwithproof\Yaml\Service\YamlService(
		$settings,
		$repo,
		$basePath,
		__DIR__,
		static function (): array {
			return yaml_collect_schema_hooks();
		}
	);
}

/**
 * Cacti config_arrays hook: register Console menu items and trigger upgrades.
 *
 * @return void
 */
function yaml_config_arrays(): void {
	global $menu;

	if (!yaml_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Yaml\Pages::menu() as $file => $title) {
		$menu[__('Import/Export')]['plugins/yaml/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Yaml\Pages::upgradeTriggers(), true)) {
		plugin_yaml_upgrade();
	}
}

/**
 * Cacti config_settings hook: expose plugin settings tab to YAML Admin users.
 *
 * @return void
 */
function yaml_config_settings(): void {
	global $tabs, $settings;

	if (PHP_SAPI !== 'cli' && !api_plugin_user_realm_auth(\Somethingwithproof\Yaml\Pages::ADMIN)) {
		return;
	}

	if (!yaml_boot()) {
		return;
	}

	$tabs['yaml']     = __('YAML', 'yaml');
	$settings['yaml'] = \Somethingwithproof\Yaml\Settings::form();
}

/**
 * Cacti draw_navigation_text hook: register breadcrumbs for Console pages.
 *
 * @param array<string, mixed> $nav Existing navigation map.
 * @return array<string, mixed>
 */
function yaml_draw_navigation_text($nav) {
	global $current_user;

	if (!yaml_boot()) {
		return $nav;
	}

	if (get_current_page() === 'settings.php' && (PHP_SAPI === 'cli' || ($current_user['enabled'] ?? '') === 'on')) {
		yaml_config_settings();
	}

	return array_merge($nav, \Somethingwithproof\Yaml\Pages::navigation());
}

/**
 * Cacti auth_alternate_realms hook: enforce 3-tier RBAC inheritance
 * (Admin > Operator > View).
 *
 * @return void
 */
function yaml_auth_alternate_realms(): void {
	global $user_auth_realm_filenames;

	static $deferred = array();

	foreach ($deferred as $file => $realm) {
		$user_auth_realm_filenames[$file] = $realm;
	}
	$deferred = array();

	if (!api_plugin_is_enabled('yaml')) {
		return;
	}

	if (empty($_SESSION['sess_user_id'])) {
		$page = get_current_page();

		if (in_array($page, array('yaml_view.php', 'yaml_operator.php'), true) && isset($user_auth_realm_filenames[$page])) {
			$deferred[$page]                  = $user_auth_realm_filenames[$page];
			$user_auth_realm_filenames[$page] = -1;
		}

		return;
	}

	$adminRealm    = $user_auth_realm_filenames['yaml_admin.php'] ?? 0;
	$operatorRealm = $user_auth_realm_filenames['yaml_operator.php'] ?? 0;

	if ($adminRealm > 0 && is_realm_allowed($adminRealm)) {
		$user_auth_realm_filenames['yaml_operator.php'] = $adminRealm;
		$user_auth_realm_filenames['yaml_view.php']     = $adminRealm;
	} elseif ($operatorRealm > 0 && is_realm_allowed($operatorRealm)) {
		$user_auth_realm_filenames['yaml_view.php'] = $operatorRealm;
	}
}

/**
 * Check whether the active Console user is authorized for the current YAML page.
 *
 * @return bool
 */
function yaml_page_allowed(): bool {
	global $user_auth_realm_filenames;

	yaml_auth_alternate_realms();
	$page  = get_current_page();
	$realm = $user_auth_realm_filenames[$page] ?? 0;

	return $realm > 0 && is_realm_allowed($realm);
}

/**
 * Guard entry point for YAML Console pages, enforcing PHP support, plugin
 * activation, and 3-tier RBAC authorization.
 *
 * @return bool True when page execution may continue.
 */
function yaml_page_start(): bool {
	if (yaml_boot() && api_plugin_is_enabled('yaml')) {
		if (yaml_page_allowed()) {
			return true;
		}

		http_response_code(403);

		return false;
	}

	top_header();
	html_start_box(__('YAML Templates, Schema & CasC Fragments', 'yaml'), '100%', false, 3, 'center', '');
	print '<tr><td>' . html_escape(yaml_php_supported() ? __('The YAML plugin is not enabled.', 'yaml') : __('The YAML plugin requires PHP %s or later.', '8.2.0', 'yaml')) . '</td></tr>';
	html_end_box();
	bottom_footer();

	return false;
}

/**
 * Verify CSRF token for state-changing POST actions when Cacti's csrf-magic
 * library is loaded.
 *
 * @return bool True when CSRF checks pass or are handled upstream.
 */
function yaml_verify_csrf(): bool {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		return true;
	}

	if (function_exists('csrf_check_tokens')) {
		return (bool) csrf_check_tokens();
	}

	return true;
}

/**
 * Hook `casc_register_providers`: register the `yaml` provider descriptor
 * without hard-importing plugin_casc classes.
 *
 * @param mixed $registrar Hook payload (array accumulator or registrar object).
 * @return mixed
 */
function yaml_casc_register_providers($registrar) {
	if (!yaml_boot()) {
		return $registrar;
	}

	return \Somethingwithproof\Yaml\Integration\CascProvider::registerProvider(
		$registrar,
		static function (): \Somethingwithproof\Yaml\Service\YamlService {
			return yaml_service();
		}
	);
}

/**
 * Hook `casc_register_template_compilers`: register `.yaml` and `.yml` template
 * compiler callback for plugin_casc.
 *
 * @param mixed $compilers Existing compiler descriptors.
 * @return mixed
 */
function yaml_casc_register_template_compilers($compilers) {
	if (!yaml_boot()) {
		return $compilers;
	}

	return \Somethingwithproof\Yaml\Integration\CascProvider::registerTemplateCompilers($compilers);
}

/**
 * Hook `casc_describe_schema`: return plugin_yaml's Config/State schema descriptor.
 *
 * @param mixed $schemas Existing schema descriptors.
 * @return mixed
 */
function yaml_casc_describe_schema($schemas) {
	if (!yaml_boot()) {
		return $schemas;
	}

	return \Somethingwithproof\Yaml\Integration\CascProvider::describeSchema($schemas, __DIR__);
}

/**
 * Hook `doctor_register_checks`: contribute YAML diagnostic checks to plugin_doctor.
 *
 * @param mixed $context Hook context array containing mode ('run'|'list') and plugin slug.
 * @return list<array<string, mixed>>
 */
function yaml_doctor_register_checks($context = array()): array {
	global $config;

	if (!yaml_boot()) {
		return array();
	}

	$normalized = array();

	if (is_array($context)) {
		foreach ($context as $key => $value) {
			$normalized[(string) $key] = $value;
		}
	}

	$basePath = is_string($config['base_path'] ?? null) ? $config['base_path'] : '';

	return \Somethingwithproof\Yaml\Integration\DoctorChecks::build(
		static function (): \Somethingwithproof\Yaml\Service\YamlService {
			return yaml_service();
		},
		static function (): \Somethingwithproof\Yaml\Settings {
			return yaml_settings();
		},
		$basePath,
		$normalized
	);
}

/**
 * Hook `doctor_register_debug_info`: contribute redacted YAML runtime metadata to plugin_doctor.
 *
 * @param mixed $context Optional hook context passed by plugin_doctor.
 * @return list<array<string, mixed>>
 */
function yaml_doctor_register_debug_info($context = array()): array {
	if (!yaml_boot()) {
		return array();
	}

	$info = plugin_yaml_version();

	return \Somethingwithproof\Yaml\Integration\DoctorChecks::debugInfo(
		static function (): \Somethingwithproof\Yaml\Service\YamlService {
			return yaml_service();
		},
		static function (): \Somethingwithproof\Yaml\Settings {
			return yaml_settings();
		},
		$info['version'] ?? '0.1.0'
	);
}

/**
 * Hook `abilities_register`: register read-only View-realm abilities with plugin_mcp.
 *
 * @param mixed $registrar plugin_mcp AbilitiesRegistrar instance.
 * @return void
 */
function yaml_abilities_register($registrar): void {
	if (!yaml_boot() || !is_object($registrar)) {
		return;
	}

	\Somethingwithproof\Yaml\Integration\McpAbilities::register(
		$registrar,
		static function (): \Somethingwithproof\Yaml\Service\YamlService {
			return yaml_service();
		},
		static function (int $userId): bool {
			return \Somethingwithproof\Yaml\Integration\McpAbilities::userHasViewRealm($userId);
		}
	);
}

/**
 * Hook `api_register_resources`: expose `yamlPacks` and `yamlSchema` plain-array
 * descriptors for plugin_api.
 *
 * @param mixed $resources Existing resource descriptors.
 * @return list<array<string, mixed>>
 */
function yaml_api_register_resources($resources = array()): array {
	if (!yaml_boot()) {
		return array();
	}

	return \Somethingwithproof\Yaml\Integration\ApiResources::register(
		$resources,
		static function (): \Somethingwithproof\Yaml\Service\YamlService {
			return yaml_service();
		}
	);
}
