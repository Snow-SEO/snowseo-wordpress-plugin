<?php

/**
 * Plugin Name:       SnowSEO
 * Plugin URI:        https://github.com/Snow-SEO/snowseo-wordpress-plugin
 * Description:       Connect WordPress to SnowSEO for AI-assisted content publishing, scheduling, and analytics.
 * Version:           1.3.4
 * Requires at least: 5.6
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            SnowSEO
 * Author URI:        https://snowseo.com/about-us
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       snowseo
 */

// If this file is called directly, abort.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Plugin constants.
 */
define('SNOWSEO_VERSION', '1.3.4');
define('SNOWSEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SNOWSEO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SNOWSEO_PLUGIN_FILE', __FILE__);

// API base URL: constant / getenv override; otherwise default below.
// The default is the production SnowSEO API endpoint. Override via
// `define('SNOWSEO_API_URL', 'https://your-instance.com/v3')` in wp-config.php
// or the SNOWSEO_API_URL environment variable.
if (! defined('SNOWSEO_API_URL')) {
	$snowseo_env_url = getenv('SNOWSEO_API_URL');
	if ($snowseo_env_url !== false && $snowseo_env_url !== '') {
		define('SNOWSEO_API_URL', $snowseo_env_url);
	} else {
		define('SNOWSEO_API_URL', 'https://api.snowseo.com/v3');  // prod
		// define('SNOWSEO_API_URL', 'https://dev-api.snowseo.com/v3');  // dev
	}
}

/**
 * Include required files.
 */
require_once SNOWSEO_PLUGIN_DIR . 'includes/class-snowseo-rest-api.php';

/**
 * Initialize REST API routes.
 */
function snowseo_register_rest_routes()
{
	$rest_api = new SnowSEO_Rest_API();
	$rest_api->register_routes();
}
add_action('rest_api_init', 'snowseo_register_rest_routes');

/**
 * Plugin activation hook.
 *
 * Also deactivates the legacy `snowseo-wordpress-plugin` folder if a user
 * installed both versions side-by-side after the 1.1.0 rename, to avoid
 * duplicate REST route registration and admin menu collisions.
 */
function snowseo_activate()
{
	add_option('snowseo_version', SNOWSEO_VERSION);
	add_option('snowseo_activated', true);

	// Safety: if the legacy plugin folder is still active, deactivate it.
	if (! function_exists('is_plugin_active')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$legacy_basename = 'snowseo-wordpress-plugin/snowseo-wordpress-plugin.php';
	if (function_exists('is_plugin_active') && is_plugin_active($legacy_basename)) {
		deactivate_plugins($legacy_basename, true);
	}
}
register_activation_hook(__FILE__, 'snowseo_activate');

/**
 * Plugin deactivation hook.
 */
function snowseo_deactivate()
{
	// Intentionally minimal. Stored credentials are preserved so reconnecting
	// after a temporary deactivate (e.g. during update) is seamless.
}
register_deactivation_hook(__FILE__, 'snowseo_deactivate');

/**
 * Runtime safety: if the legacy plugin happens to be active at the same time
 * as 1.1.0 (e.g. WP auto-activated a manually unzipped legacy copy), defer
 * to the newer plugin and quietly disable the legacy one.
 */
function snowseo_disable_legacy_if_present()
{
	if (! is_admin()) {
		return;
	}
	if (! function_exists('is_plugin_active')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$legacy_basename = 'snowseo-wordpress-plugin/snowseo-wordpress-plugin.php';
	if (function_exists('is_plugin_active') && is_plugin_active($legacy_basename)) {
		deactivate_plugins($legacy_basename, true);
	}
}
add_action('admin_init', 'snowseo_disable_legacy_if_present');

/**
 * SnowSEO sidebar icon as a base64-encoded SVG data URI.
 * Uses the official SnowSEO logo with fill/stroke set to #a7aaad (WordPress
 * default admin sidebar icon color) for proper contrast on the dark sidebar.
 */
function snowseo_icon()
{
	return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzMwIiBoZWlnaHQ9IjMzMCIgdmlld0JveD0iMCAwIDMzMCAzMzAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHBhdGggZD0iTTY3LjE3NDYgMTA2LjU5MkwxNDMuMDEgMTUxLjgxNCIgc3Ryb2tlPSIjYTdhYWFkIiBzdHJva2Utd2lkdGg9IjExLjYyMzkiLz48cGF0aCBkPSJNOTkuNTEzMSA5OC42NzI0QzEwMC4wNSA5Ni4wNDU3IDk4LjM1NTEgOTMuNDgxMyA5NS43MjgyIDkyLjk0NDdDOTMuMTAxMyA5Mi40MDgyIDkwLjUzNjkgOTQuMTAyOCA5MC4wMDA1IDk2LjcyOTdMOTQuNzU2OCA5Ny43MDExTDk5LjUxMzEgOTguNjcyNFpNOTQuNzU2OCA5Ny43MDExTDkwLjAwMDUgOTYuNzI5N0M4OC4xNTk0IDEwNS43NDQgODcuOTI1MyAxMTQuNzMyIDkzLjg5NTMgMTI2LjM1M0w5OC4yMTM0IDEyNC4xMzVMMTAyLjUzMSAxMjEuOTE2Qzk3LjgxNzYgMTEyLjc0MSA5Ny45OTIzIDEwNi4xMTkgOTkuNTEzMSA5OC42NzI0TDk0Ljc1NjggOTcuNzAxMVoiIGZpbGw9IiNhN2FhYWQiLz48cGF0aCBkPSJNMTIyLjQ0NCAxMDMuNjVDMTIyLjkzNCAxMDEuMDU0IDEyMS4xNzkgOTguNDYyMyAxMTguNTI3IDk3Ljg2MjRDMTE1Ljg3NSA5Ny4yNjI0IDExMy4zMjkgOTguODgxMiAxMTIuODQgMTAxLjQ3OEwxMTcuNjQyIDEwMi41NjRMMTIyLjQ0NCAxMDMuNjVaTTExNy42NDIgMTAyLjU2NEwxMTIuODQgMTAxLjQ3OEMxMTAuNTgyIDExMy40NjggMTEwLjUxOCAxMjUuMzI1IDExOC45NTggMTQwLjk0MkwxMjMuMjQzIDEzOC44MThMMTI3LjUyOCAxMzYuNjk1QzEyMC40MjggMTIzLjU1NyAxMjAuNDgyIDExNC4wNzYgMTIyLjQ0NCAxMDMuNjVMMTE3LjY0MiAxMDIuNTY0WiIgZmlsbD0iI2E3YWFhZCIvPjxwYXRoIGQ9Ik03NS42Mjg5IDEzOC4wMzhDNzMuNTQ2OCAxMzkuNzI3IDcwLjQ4OTcgMTM5LjQwOCA2OC44MDA2IDEzNy4zMjZDNjcuMTExNSAxMzUuMjQ1IDY3LjQyOTkgMTMyLjE4NyA2OS41MTIgMTMwLjQ5OEw3Mi41NzA1IDEzNC4yNjhMNzUuNjI4OSAxMzguMDM4Wk03Mi41NzA1IDEzNC4yNjhMNjkuNTEyIDEzMC40OThDNzYuNjU2OSAxMjQuNzAyIDg0LjUyMDcgMTIwLjM0MyA5Ny41ODUxIDEyMC4yN0w5Ny42MTIxIDEyNS4xMjVMOTcuNjM5IDEyOS45NzlDODcuMzIzNCAxMzAuMDM2IDgxLjUzMDggMTMzLjI1IDc1LjYyODkgMTM4LjAzOEw3Mi41NzA1IDEzNC4yNjhaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTkwLjg2ODggMTU1LjY5MUM4OC43OTE2IDE1Ny4zMjMgODUuNjgzMSAxNTYuOTY1IDgzLjkyNjEgMTU0Ljg5QzgyLjE2ODkgMTUyLjgxNCA4Mi40Mjg0IDE0OS44MDggODQuNTA1NiAxNDguMTc1TDg3LjY4NzMgMTUxLjkzM0w5MC44Njg4IDE1NS42OTFaTTg3LjY4NzMgMTUxLjkzM0w4NC41MDU2IDE0OC4xNzVDOTQuMDk3MiAxNDAuNjM1IDEwNC41ODQgMTM1LjEwMiAxMjIuMzMzIDEzNS4zNzVMMTIyLjQyOSAxNDAuMTU2TDEyMi41MjcgMTQ0LjkzN0MxMDcuNTk0IDE0NC43MDcgOTkuMjA4OCAxNDkuMTM0IDkwLjg2ODggMTU1LjY5MUw4Ny42ODczIDE1MS45MzNaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTU1Ljg2MTIgOTguMTc2NUM1NS4wNDI0IDk4LjI1OSA1NC41Mjk2IDk5LjEwMjMgNTQuODMxNyA5OS44Njk2TDYxLjAwNjIgMTE1LjU1N0M2MS4zODI3IDExNi41MTQgNjIuNjg4NCAxMTYuNjI3IDYzLjIyMTggMTE1Ljc1TDczLjc4NzcgOTguMzc0NUM3NC4zMjEzIDk3LjQ5NzEgNzMuNjIyMyA5Ni4zODU4IDcyLjYwMTcgOTYuNDg4N0w1NS44NjEyIDk4LjE3NjVaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTE2Mi41NTIgNTEuNDIwM0wxNjMuMjMzIDEzOS43MTIiIHN0cm9rZT0iI2E3YWFhZCIgc3Ryb2tlLXdpZHRoPSIxMS42MjM5Ii8+PHBhdGggZD0iTTE4Ni4xMDEgNzQuOTU3N0MxODguNjI0IDc0LjA1MzUgMTg5LjkzNyA3MS4yNzQ1IDE4OS4wMzQgNjguNzUwNEMxODguMTMgNjYuMjI2NCAxODUuMzUxIDY0LjkxMzIgMTgyLjgyNiA2NS44MTczTDE4NC40NjQgNzAuMzg3NUwxODYuMTAxIDc0Ljk1NzdaTTE4NC40NjQgNzAuMzg3NUwxODIuODI2IDY1LjgxNzNDMTc0LjE2NSA2OC45MTk4IDE2Ni4zNiA3My4zODI1IDE1OS41MjIgODQuNTE0OUwxNjMuNjU4IDg3LjA1NTZMMTY3Ljc5NCA4OS41OTY0QzE3My4xOTQgODAuODA2MyAxNzguOTQ1IDc3LjUyMDQgMTg2LjEwMSA3NC45NTc3TDE4NC40NjQgNzAuMzg3NVoiIGZpbGw9IiNhN2FhYWQiLz48cGF0aCBkPSJNMTkzLjczOSA5Ny4xNDI3QzE5Ni4yMTMgOTYuMjEzNyAxOTcuNTE5IDkzLjM3IDE5Ni42NTUgOTAuNzkxNEMxOTUuNzkzIDg4LjIxMjcgMTkzLjA4OCA4Ni44NzU0IDE5MC42MTQgODcuODA0NUwxOTIuMTc3IDkyLjQ3MzZMMTkzLjczOSA5Ny4xNDI3Wk0xOTIuMTc3IDkyLjQ3MzZMMTkwLjYxNCA4Ny44MDQ1QzE3OS4xOTMgOTIuMDk0NCAxNjkuMDIyIDk4LjE5MDggMTYwLjA1MSAxMTMuNTA4TDE2NC4wODggMTE2LjA3TDE2OC4xMjYgMTE4LjYzMkMxNzUuNjc0IDEwNS43NDYgMTgzLjgwOCAxMDAuODczIDE5My43MzkgOTcuMTQyN0wxOTIuMTc3IDkyLjQ3MzZaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTE0MC4wNTQgNzQuOTYwM0MxMzcuNTMxIDc0LjA1NjYgMTM2LjIxNyA3MS4yNzc4IDEzNy4xMiA2OC43NTM2QzEzOC4wMjQgNjYuMjI5NCAxNDAuODAzIDY0LjkxNTggMTQzLjMyNiA2NS44MTk0TDE0MS42OSA3MC4zODk5TDE0MC4wNTQgNzQuOTYwM1pNMTQxLjY5IDcwLjM4OTlMMTQzLjMyNiA2NS44MTk0QzE1MS45ODkgNjguOTIwNSAxNTkuNzk1IDczLjM4MjEgMTY2LjYzNCA4NC41MTM0TDE2Mi40OTggODcuMDU0N0wxNTguMzYyIDg5LjU5NjJDMTUyLjk2MiA4MC44MDcgMTQ3LjIxIDc3LjUyMiAxNDAuMDU0IDc0Ljk2MDNMMTQxLjY5IDcwLjM4OTlaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTEzMi44NzEgOTcuMTQ4NUMxMzAuMzk3IDk2LjIxOTkgMTI5LjA5MSA5My4zNzY0IDEyOS45NTQgOTAuNzk3NkMxMzAuODE3IDg4LjIxODggMTMzLjUyIDg2Ljg4MTEgMTM1Ljk5NSA4Ny44MDk3TDEzNC40MzIgOTIuNDc5MkwxMzIuODcxIDk3LjE0ODVaTTEzNC40MzIgOTIuNDc5MkwxMzUuOTk1IDg3LjgwOTdDMTQ3LjQxNyA5Mi4wOTc5IDE1Ny41ODggOTguMTkyNiAxNjYuNTYxIDExMy41MDlMMTYyLjUyNSAxMTYuMDcxTDE1OC40ODcgMTE4LjYzNEMxNTAuOTM4IDEwNS43NDkgMTQyLjgwMiAxMDAuODc3IDEzMi44NzEgOTcuMTQ4NUwxMzQuNDMyIDkyLjQ3OTJaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTE2My44OTIgMzcuMzU2OEMxNjMuMzk2IDM2LjY5OTYgMTYyLjQwOSAzNi42OTg3IDE2MS45MSAzNy4zNTQ5TDE1MS43MDIgNTAuNzcyMUMxNTEuMDggNTEuNTkwMSAxNTEuNjYgNTIuNzY1MiAxNTIuNjg3IDUyLjc2NjJMMTczLjAyMyA1Mi43ODVDMTc0LjA0OSA1Mi43ODYgMTc0LjYzNiA1MS42MTIgMTc0LjAxOSA1MC43OTI4TDE2My44OTIgMzcuMzU2OFoiIGZpbGw9IiNhN2FhYWQiLz48cGF0aCBkPSJNMjU5LjIgMTA0LjMzOEwxODQuMDcxIDE1MC43MjQiIHN0cm9rZT0iI2E3YWFhZCIgc3Ryb2tlLXdpZHRoPSIxMS42MjM5Ii8+PHBhdGggZD0iTTI1MS4yOTIgMTM2LjY4MUMyNTMuMzc1IDEzOC4zNyAyNTYuNDMyIDEzOC4wNSAyNTguMTIxIDEzNS45NjhDMjU5LjgxIDEzMy44ODYgMjU5LjQ5MSAxMzAuODI5IDI1Ny40MDggMTI5LjE0TDI1NC4zNSAxMzIuOTFMMjUxLjI5MiAxMzYuNjgxWk0yNTQuMzUgMTMyLjkxTDI1Ny40MDggMTI5LjE0QzI1MC4yNjMgMTIzLjM0NSAyNDIuMzk5IDExOC45ODcgMjI5LjMzNCAxMTguOTE3TDIyOS4zMDggMTIzLjc3TDIyOS4yODIgMTI4LjYyNUMyMzkuNTk3IDEyOC42OCAyNDUuMzkgMTMxLjg5MyAyNTEuMjkyIDEzNi42ODFMMjU0LjM1IDEzMi45MVoiIGZpbGw9IiNhN2FhYWQiLz48cGF0aCBkPSJNMjM2LjI4OSAxNTQuNzIzQzIzOC4zNjggMTU2LjM1NiAyNDEuNDc1IDE1NS45OTYgMjQzLjIzMiAxNTMuOTIxQzI0NC45ODkgMTUxLjg0NiAyNDQuNzI4IDE0OC44MzkgMjQyLjY1MSAxNDcuMjA3TDIzOS40NyAxNTAuOTY0TDIzNi4yODkgMTU0LjcyM1pNMjM5LjQ3IDE1MC45NjRMMjQyLjY1MSAxNDcuMjA3QzIzMy4wNTggMTM5LjY2OCAyMjIuNTcxIDEzNC4xMzYgMjA0LjgyMSAxMzQuNDEyTDIwNC43MjYgMTM5LjE5M0wyMDQuNjMgMTQzLjk3NEMyMTkuNTYzIDE0My43NDIgMjI3Ljk0OSAxNDguMTY4IDIzNi4yODkgMTU0LjcyM0wyMzkuNDcgMTUwLjk2NFoiIGZpbGw9IiNhN2FhYWQiLz48cGF0aCBkPSJNMjI3LjQwMyA5Ny4zMjAyQzIyNi44NjYgOTQuNjkzNCAyMjguNTYxIDkyLjEyODcgMjMxLjE4NyA5MS41OTE5QzIzMy44MTQgOTEuMDU0OSAyMzYuMzc4IDkyLjc0OTEgMjM2LjkxNSA5NS4zNzU4TDIzMi4xNTkgOTYuMzQ3OUwyMjcuNDAzIDk3LjMyMDJaTTIzMi4xNTkgOTYuMzQ3OUwyMzYuOTE1IDk1LjM3NThDMjM4Ljc1OCAxMDQuMzkgMjM4Ljk5MyAxMTMuMzc4IDIzMy4wMjUgMTI0Ljk5OUwyMjguNzA3IDEyMi43ODJMMjI0LjM4OCAxMjAuNTY0QzIyOS4xMDEgMTExLjM4OCAyMjguOTI1IDEwNC43NjYgMjI3LjQwMyA5Ny4zMjAyTDIzMi4xNTkgOTYuMzQ3OVoiIGZpbGw9IiNhN2FhYWQiLz48cGF0aCBkPSJNMjA0LjcwOSAxMDIuNjg1QzIwNC4yMiAxMDAuMDg5IDIwNS45NzMgOTcuNDk3MiAyMDguNjI1IDk2Ljg5NjlDMjExLjI3NyA5Ni4yOTY3IDIxMy44MjQgOTcuOTE1IDIxNC4zMTQgMTAwLjUxMUwyMDkuNTExIDEwMS41OThMMjA0LjcwOSAxMDIuNjg1Wk0yMDkuNTExIDEwMS41OThMMjE0LjMxNCAxMDAuNTExQzIxNi41NzQgMTEyLjUwMSAyMTYuNjM5IDEyNC4zNTcgMjA4LjIgMTM5Ljk3NUwyMDMuOTE1IDEzNy44NTRMMTk5LjYzIDEzNS43MzFDMjA2LjcyOSAxMjIuNTkyIDIwNi42NzQgMTEzLjExMSAyMDQuNzA5IDEwMi42ODVMMjA5LjUxMSAxMDEuNTk4WiIgZmlsbD0iI2E3YWFhZCIvPjxwYXRoIGQ9Ik0yNzEuOTMyIDk3Ljc1MTlDMjcyLjIzNiA5Ni45ODc0IDI3MS43MjQgOTYuMTQzMiAyNzAuOTA1IDk2LjA1NjhMMjU0LjEzOCA5NC4yOUMyNTMuMTE2IDk0LjE4MjMgMjUyLjQxMyA5NS4yODggMjUyLjk0NCA5Ni4xNjYzTDI2My40NzkgMTEzLjU2MUMyNjQuMDEgMTE0LjQ0IDI2NS4zMTkgMTE0LjMzMyAyNjUuNjk5IDExMy4zOEwyNzEuOTMyIDk3Ljc1MTlaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTI2MC4xMzEgMjIxLjU5OEwxODQuMjc3IDE3Ni40MDkiIHN0cm9rZT0iI2E3YWFhZCIgc3Ryb2tlLXdpZHRoPSIxMS42MjM5Ii8+PHBhdGggZD0iTTI1MS43MTIgMTg5LjM4NEMyNTMuNzY2IDE4Ny42NjIgMjU2LjgyOSAxODcuOTMzIDI1OC41NTEgMTg5Ljk4OEMyNjAuMjcxIDE5Mi4wNDMgMjYwLjAwMiAxOTUuMTA2IDI1Ny45NDYgMTk2LjgyNkwyNTQuODI5IDE5My4xMDVMMjUxLjcxMiAxODkuMzg0Wk0yNTQuODI5IDE5My4xMDVMMjU3Ljk0NiAxOTYuODI2QzI1MC44OTQgMjAyLjczNSAyNDMuMDk4IDIwNy4yMTcgMjMwLjAzNiAyMDcuNDk1TDIyOS45MzMgMjAyLjY0MUwyMjkuODMxIDE5Ny43ODdDMjQwLjE0NCAxOTcuNTY4IDI0NS44ODUgMTk0LjI2NCAyNTEuNzEyIDE4OS4zODRMMjU0LjgyOSAxOTMuMTA1WiIgZmlsbD0iI2E3YWFhZCIvPjxwYXRoIGQ9Ik0yMzYuNDI0IDE3MS41ODVDMjM4LjQ3NSAxNjkuOTE5IDI0MS41ODkgMTcwLjIyOSAyNDMuMzc5IDE3Mi4yNzZDMjQ1LjE2OCAxNzQuMzI0IDI0NC45NTUgMTc3LjMzNCAyNDIuOTA0IDE3OC45OTlMMjM5LjY2NSAxNzUuMjkyTDIzNi40MjQgMTcxLjU4NVpNMjM5LjY2NSAxNzUuMjkyTDI0Mi45MDQgMTc4Ljk5OUMyMzMuNDMzIDE4Ni42ODkgMjIzLjAzNCAxOTIuMzg3IDIwNS4yODIgMTkyLjM5MUwyMDUuMTExIDE4Ny42MTNMMjA0LjkzOSAxODIuODM0QzIxOS44NzMgMTgyLjgzIDIyOC4xODggMTc4LjI3MSAyMzYuNDI0IDE3MS41ODVMMjM5LjY2NSAxNzUuMjkyWiIgZmlsbD0iI2E3YWFhZCIvPjxwYXRoIGQ9Ik0yMjguNDQ5IDIyOS4xMjNDMjI3Ljk1MyAyMzEuNzU3IDIyOS42ODggMjM0LjI5NSAyMzIuMzI0IDIzNC43OUMyMzQuOTU5IDIzNS4yODUgMjM3LjQ5NiAyMzMuNTUxIDIzNy45OTEgMjMwLjkxNkwyMzMuMjE5IDIzMC4wMTlMMjI4LjQ0OSAyMjkuMTIzWk0yMzMuMjE5IDIzMC4wMTlMMjM3Ljk5MSAyMzAuOTE2QzIzOS42OSAyMjEuODczIDIzOS43ODMgMjEyLjg4MiAyMzMuNjMyIDIwMS4zNTdMMjI5LjM0OSAyMDMuNjQzTDIyNS4wNjYgMjA1LjkyOEMyMjkuOTIzIDIxNS4wMyAyMjkuODUzIDIyMS42NTMgMjI4LjQ0OSAyMjkuMTIzTDIzMy4yMTkgMjMwLjAxOVoiIGZpbGw9IiNhN2FhYWQiLz48cGF0aCBkPSJNMjA1LjY3MSAyMjQuMTEzQzIwNS4yMjIgMjI2LjcxNyAyMDcuMDE3IDIyOS4yODEgMjA5LjY3OSAyMjkuODM5QzIxMi4zMzkgMjMwLjM5NiAyMTQuODYgMjI4LjczOCAyMTUuMzA4IDIyNi4xMzVMMjEwLjQ5IDIyNS4xMjNMMjA1LjY3MSAyMjQuMTEzWk0yMTAuNDkgMjI1LjEyM0wyMTUuMzA4IDIyNi4xMzVDMjE3LjM3OCAyMTQuMTEgMjE3LjI1NiAyMDIuMjU0IDIwOC41NzEgMTg2Ljc3MkwyMDQuMzIgMTg4Ljk2MkwyMDAuMDY5IDE5MS4xNTJDMjA3LjM3NCAyMDQuMTc2IDIwNy40NzEgMjEzLjY1OCAyMDUuNjcxIDIyNC4xMTNMMjEwLjQ5IDIyNS4xMjNaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTI3Mi45NTYgMjI3LjQyNUMyNzMuMjczIDIyOC4xODUgMjcyLjc3NSAyMjkuMDM3IDI3MS45NTcgMjI5LjEzNUwyNTUuMjIgMjMxLjE2N0MyNTQuMiAyMzEuMjkyIDI1My40NzkgMjMwLjE5NyAyNTMuOTk3IDIyOS4zMTFMMjY0LjI1NCAyMTEuNzUxQzI2NC43NzIgMjEwLjg2NSAyNjYuMDgxIDIxMC45NSAyNjYuNDc2IDIxMS44OTdMMjcyLjk1NiAyMjcuNDI1WiIgZmlsbD0iI2E3YWFhZCIvPjxwYXRoIGQ9Ik0xNjQuMzM0IDI3Ni4wNDVMMTYzLjYxNSAxODcuNzUzIiBzdHJva2U9IiNhN2FhYWQiIHN0cm9rZS13aWR0aD0iMTEuNjIzOSIvPjxwYXRoIGQ9Ik0xODcuNTA1IDI1Mi4xMzVDMTkwLjA0NCAyNTIuOTk5IDE5MS40MDEgMjU1Ljc1NyAxOTAuNTM2IDI1OC4yOTVDMTg5LjY3MiAyNjAuODMzIDE4Ni45MTUgMjYyLjE5MSAxODQuMzc3IDI2MS4zMjZMMTg1Ljk0MiAyNTYuNzMxTDE4Ny41MDUgMjUyLjEzNVpNMTg1Ljk0MiAyNTYuNzMxTDE4NC4zNzcgMjYxLjMyNkMxNzUuNjY3IDI1OC4zNjIgMTY3Ljc5MiAyNTQuMDIzIDE2MC43NzggMjQzTDE2NC44NzUgMjQwLjM5NUwxNjguOTcgMjM3Ljc4OEMxNzQuNTA4IDI0Ni40OTIgMTgwLjMxMSAyNDkuNjg2IDE4Ny41MDUgMjUyLjEzNUwxODUuOTQyIDI1Ni43MzFaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTE5NC43OTIgMjI5LjgzMkMxOTcuMjggMjMwLjcyMiAxOTguNjMxIDIzMy41NDQgMTk3LjgwOCAyMzYuMTM2QzE5Ni45ODYgMjM4LjcyOCAxOTQuMzAzIDI0MC4xMDggMTkxLjgxNSAyMzkuMjE4TDE5My4zMDMgMjM0LjUyNUwxOTQuNzkyIDIyOS44MzJaTTE5My4zMDMgMjM0LjUyNUwxOTEuODE1IDIzOS4yMThDMTgwLjMyNiAyMzUuMTEgMTcwLjA2MSAyMjkuMTc2IDE2MC44NDcgMjE0LjAwMkwxNjQuODQ0IDIxMS4zNzdMMTY4Ljg0MSAyMDguNzUxQzE3Ni41OTIgMjIxLjUxNiAxODQuODAyIDIyNi4yNiAxOTQuNzkyIDIyOS44MzJMMTkzLjMwMyAyMzQuNTI1WiIgZmlsbD0iI2E3YWFhZCIvPjxwYXRoIGQ9Ik0xNDEuNDY2IDI1Mi44NjJDMTM4Ljk1NiAyNTMuODA2IDEzNy42ODYgMjU2LjYwNSAxMzguNjMgMjU5LjExNEMxMzkuNTc0IDI2MS42MjUgMTQyLjM3MyAyNjIuODk0IDE0NC44ODIgMjYxLjk1TDE0My4xNzMgMjU3LjQwNkwxNDEuNDY2IDI1Mi44NjJaTTE0My4xNzMgMjU3LjQwNkwxNDQuODgyIDI2MS45NUMxNTMuNDk0IDI1OC43MTIgMTYxLjIyOCAyNTQuMTI3IDE2Ny44OSAyNDIuODg5TDE2My43MTUgMjQwLjQxM0wxNTkuNTM5IDIzNy45MzhDMTU0LjI3OSAyNDYuODEyIDE0OC41NzkgMjUwLjE4NyAxNDEuNDY2IDI1Mi44NjJMMTQzLjE3MyAyNTcuNDA2WiIgZmlsbD0iI2E3YWFhZCIvPjxwYXRoIGQ9Ik0xMzMuOTMgMjMwLjc5MkMxMzEuNDcyIDIzMS43NiAxMzAuMjExIDIzNC42MjMgMTMxLjExNCAyMzcuMTg4QzEzMi4wMTggMjM5Ljc1MiAxMzQuNzQzIDI0MS4wNDggMTM3LjIwMiAyNDAuMDc5TDEzNS41NjYgMjM1LjQzNkwxMzMuOTMgMjMwLjc5MlpNMTM1LjU2NiAyMzUuNDM2TDEzNy4yMDIgMjQwLjA3OUMxNDguNTU1IDIzNS42MTEgMTU4LjYyOCAyMjkuMzU2IDE2Ny4zNTcgMjEzLjlMMTYzLjI4MSAyMTEuNDAxTDE1OS4yMDMgMjA4LjkwM0MxNTEuODU4IDIyMS45MDYgMTQzLjgwMiAyMjYuOTA2IDEzMy45MyAyMzAuNzkyTDEzNS41NjYgMjM1LjQzNloiIGZpbGw9IiNhN2FhYWQiLz48cGF0aCBkPSJNMTY1LjcyMyAyOTAuMDgzQzE2NS4yMzcgMjkwLjc0OCAxNjQuMjUgMjkwLjc2NCAxNjMuNzQxIDI5MC4xMTZMMTUzLjMyMSAyNzYuODYyQzE1Mi42ODYgMjc2LjA1NCAxNTMuMjQ3IDI3NC44NzEgMTU0LjI3NCAyNzQuODUzTDE3NC42MDcgMjc0LjUxMkMxNzUuNjM0IDI3NC40OTQgMTc2LjI0IDI3NS42NTkgMTc1LjYzNSAyNzYuNDg4TDE2NS43MjMgMjkwLjA4M1oiIGZpbGw9IiNhN2FhYWQiLz48cGF0aCBkPSJNNjguMDkyIDIyMi4zODhMMTQzLjIwMSAxNzUuOTciIHN0cm9rZT0iI2E3YWFhZCIgc3Ryb2tlLXdpZHRoPSIxMS42MjM5Ii8+PHBhdGggZD0iTTEwMC41NTQgMjI5Ljc5NUMxMDEuMTMyIDIzMi40MTIgOTkuNDc4IDIzNS4wMDMgOTYuODU5OSAyMzUuNTgxQzk0LjI0MiAyMzYuMTU5IDkxLjY1MSAyMzQuNTA1IDkxLjA3MjkgMjMxLjg4OEw5NS44MTMzIDIzMC44NDFMMTAwLjU1NCAyMjkuNzk1Wk05NS44MTMzIDIzMC44NDFMOTEuMDcyOSAyMzEuODg4Qzg5LjA4OTIgMjIyLjkwNCA4OC43MTI2IDIxMy45MiA5NC40OTc2IDIwMi4yMDZMOTguODUwMiAyMDQuMzU2TDEwMy4yMDMgMjA2LjUwNkM5OC42MzUyIDIxNS43NTQgOTguOTE0OSAyMjIuMzczIDEwMC41NTQgMjI5Ljc5NUw5NS44MTMzIDIzMC44NDFaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTEyMy40IDIyNC40NTRDMTIzLjkzIDIyNy4wNDMgMTIyLjIxNyAyMjkuNjYxIDExOS41NzYgMjMwLjMwNEMxMTYuOTMzIDIzMC45NDUgMTE0LjM2MSAyMjkuMzY3IDExMy44MzEgMjI2Ljc3OEwxMTguNjE2IDIyNS42MTdMMTIzLjQgMjI0LjQ1NFpNMTE4LjYxNiAyMjUuNjE3TDExMy44MzEgMjI2Ljc3OEMxMTEuMzgzIDIxNC44MjUgMTExLjEzMSAyMDIuOTcyIDExOS4zMjQgMTg3LjIyM0wxMjMuNjQyIDE4OS4yNzdMMTI3Ljk1OSAxOTEuMzMyQzEyMS4wNjggMjA0LjU4MSAxMjEuMjcyIDIxNC4wNjEgMTIzLjQgMjI0LjQ1NEwxMTguNjE2IDIyNS42MTdaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTc2LjA0NzggMTkwLjgxMkM3My45MzkzIDE4OS4xNTUgNzAuODg3NSAxODkuNTIyIDY5LjIzMTYgMTkxLjYzMUM2Ny41NzU3IDE5My43MzkgNjcuOTQyNyAxOTYuNzkyIDcwLjA1MTMgMTk4LjQ0OEw3My4wNDk2IDE5NC42MjlMNzYuMDQ3OCAxOTAuODEyWk03My4wNDk2IDE5NC42MjlMNzAuMDUxMyAxOTguNDQ4Qzc3LjI4NzEgMjA0LjEzIDg1LjIxOTEgMjA4LjM2MyA5OC4yODMgMjA4LjIyOUw5OC4yMzMgMjAzLjM3NUw5OC4xODI5IDE5OC41MkM4Ny44Njc4IDE5OC42MjcgODIuMDI0OSAxOTUuNTA2IDc2LjA0NzggMTkwLjgxMkw3My4wNDk2IDE5NC42MjlaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTkxLjAwNTYgMTcyLjkyMkM4OC45MDI3IDE3MS4zMjIgODUuODAwNCAxNzEuNzMgODQuMDc2NCAxNzMuODMzQzgyLjM1MjQgMTc1LjkzNSA4Mi42NTk1IDE3OC45MzcgODQuNzYyNCAxODAuNTM3TDg3Ljg4NCAxNzYuNzNMOTEuMDA1NiAxNzIuOTIyWk04Ny44ODQgMTc2LjczTDg0Ljc2MjQgMTgwLjUzN0M5NC40NzI0IDE4Ny45MjUgMTA1LjA0NSAxOTMuMjkyIDEyMi43ODggMTkyLjczNkwxMjIuODA5IDE4Ny45NTRMMTIyLjgyOSAxODMuMTczQzEwNy45MDMgMTgzLjYzOSA5OS40NDg1IDE3OS4zNDUgOTEuMDA1NiAxNzIuOTIyTDg3Ljg4NCAxNzYuNzNaIiBmaWxsPSIjYTdhYWFkIi8+PHBhdGggZD0iTTU2LjkxNDkgMjMwLjg5MUM1Ni4wOTUgMjMwLjgyMiA1NS41Njg5IDIyOS45ODYgNTUuODU4NyAyMjkuMjE1TDYxLjc4MzcgMjEzLjQzQzYyLjE0NSAyMTIuNDY4IDYzLjQ0ODcgMjEyLjMzNSA2My45OTYgMjEzLjIwM0w3NC44MzYxIDIzMC40MDlDNzUuMzgzNSAyMzEuMjc4IDc0LjcwMjIgMjMyLjM5OSA3My42ODAxIDIzMi4zMTNMNTYuOTE0OSAyMzAuODkxWiIgZmlsbD0iI2E3YWFhZCIvPjxwYXRoIGQ9Ik0xNDAuOTM4IDE0OS45MzlMMTY0LjMzNCAxMzcuMjhDMTY0LjUzNCAxMzcuMTcyIDE2NC43NzggMTM3LjE3NiAxNjQuOTc0IDEzNy4yOTVMMTg3LjgxIDE1MS4wNDRDMTg4LjAwNiAxNTEuMTYzIDE4OC4xMjYgMTUxLjM3NyAxODguMTIxIDE1MS42MDhMMTg3LjY2MSAxNzkuMjI2QzE4Ny42NTcgMTc5LjQ1OSAxODcuNTI3IDE3OS42NzIgMTg3LjMyMiAxNzkuNzgzTDE2My45NDIgMTkyLjQzNEMxNjMuNzQxIDE5Mi41NDMgMTYzLjQ5NyAxOTIuNTM3IDE2My4zMDEgMTkyLjQxOUwxMzkuMzkzIDE3Ny45ODdDMTM5LjE4OCAxNzcuODY0IDEzOS4wNjkgMTc3LjYzNyAxMzkuMDgzIDE3Ny4zOTlMMTQwLjYwMSAxNTAuNDcxQzE0MC42MTMgMTUwLjI0NyAxNDAuNzQxIDE1MC4wNDYgMTQwLjkzOCAxNDkuOTM5WiIgc3Ryb2tlPSIjYTdhYWFkIiBzdHJva2Utd2lkdGg9IjExLjY1MzUiLz48L3N2Zz4=';
}

/**
 * Add SnowSEO menu item to the WordPress admin sidebar.
 */
function snowseo_admin_menu()
{
	add_menu_page(
		__('SnowSEO', 'snowseo'),
		__('SnowSEO', 'snowseo'),
		'manage_options',
		'snowseo',
		'snowseo_render_admin_page',
		snowseo_icon(),
		81
	);
}
add_action('admin_menu', 'snowseo_admin_menu');


/**
 * Enqueue admin scripts and styles only on our plugin page.
 */
function snowseo_enqueue_admin_assets($hook_suffix)
{
	// Only load on our plugin's admin page
	if ('toplevel_page_snowseo' !== $hook_suffix) {
		return;
	}

	// Load the auto-generated asset file for dependencies and version
	$asset_file = SNOWSEO_PLUGIN_DIR . 'build/index.asset.php';

	if (! file_exists($asset_file)) {
		return;
	}

	$asset = include $asset_file;

	// Enqueue the React app JS
	wp_enqueue_script(
		'snowseo-admin',
		SNOWSEO_PLUGIN_URL . 'build/index.js',
		$asset['dependencies'],
		$asset['version'],
		true // Load in footer
	);

	// Enqueue the CSS
	wp_enqueue_style(
		'snowseo-admin',
		SNOWSEO_PLUGIN_URL . 'build/index.css',
		array('dashicons'),
		$asset['version']
	);

	// Pass PHP data to the React app
	wp_localize_script(
		'snowseo-admin',
		'snowseoData',
		array(
			'version' => SNOWSEO_VERSION,
			'restUrl' => rest_url('snowseo/v1/'),
			'siteUrl' => home_url(),
			'nonce'   => wp_create_nonce('wp_rest'),
			'adminUrl' => admin_url(),
			'connected' => (new SnowSEO_Rest_API())->is_connected(),
		)
	);
}
add_action('admin_enqueue_scripts', 'snowseo_enqueue_admin_assets');

/**
 * Render the admin page - just a mount point for React.
 */
function snowseo_render_admin_page()
{
	// Check user capabilities
	if (! current_user_can('manage_options')) {
		return;
	}

	// Remove default WP admin page styling wrapper
	echo '<div id="snowseo-root" class="snowseo-root-wrap"></div>';
}

/**
 * Add custom admin body class to allow full-width layout.
 */
function snowseo_admin_body_class($classes)
{
	$screen = get_current_screen();
	if ($screen && 'toplevel_page_snowseo' === $screen->id) {
		$classes .= ' snowseo-admin-page';
	}
	return $classes;
}
add_filter('admin_body_class', 'snowseo_admin_body_class');

/**
 * Whether a dedicated SEO plugin is managing document head output. When one is
 * active, SnowSEO defers to it so we never emit duplicate or conflicting meta,
 * Open Graph, Twitter, or canonical tags.
 */
function snowseo_seo_plugin_active()
{
	return defined('WPSEO_VERSION')              // Yoast SEO
		|| defined('RANK_MATH_VERSION')          // Rank Math
		|| defined('AIOSEO_VERSION')             // All in One SEO
		|| defined('SEOPRESS_VERSION')           // SEOPress
		|| function_exists('the_seo_framework'); // The SEO Framework
}

/**
 * Whether SnowSEO owns this post's head SEO output. True when SnowSEO published
 * the post (_snowseo_article_id) OR has written SEO meta to it - e.g. a website
 * audit auto-fix applied to the user's own existing page. This is what lets
 * meta-title / meta-description fixes actually render on existing pages, not
 * just on SnowSEO-authored posts.
 */
function snowseo_manages_post_seo($post_id)
{
	if (! $post_id) {
		return false;
	}
	if (get_post_meta($post_id, '_snowseo_article_id', true)) {
		return true;
	}
	return '' !== trim((string) get_post_meta($post_id, '_snowseo_meta_metaTitle', true))
		|| '' !== trim((string) get_post_meta($post_id, '_snowseo_meta_metaDescription', true));
}

/**
 * Override the document <title> with the SnowSEO-generated meta title on
 * singular SnowSEO-managed posts, unless another SEO plugin owns the title.
 */
function snowseo_filter_document_title($title)
{
	if (is_admin() || ! is_singular() || snowseo_seo_plugin_active()) {
		return $title;
	}
	$post_id = get_queried_object_id();
	if (! snowseo_manages_post_seo($post_id)) {
		return $title;
	}
	$meta_title = trim((string) get_post_meta($post_id, '_snowseo_meta_metaTitle', true));
	return '' !== $meta_title ? $meta_title : $title;
}
add_filter('pre_get_document_title', 'snowseo_filter_document_title', 20);

/**
 * Emit SnowSEO-managed SEO and social meta tags in the document head for
 * singular SnowSEO-managed posts (published by SnowSEO, or with SEO meta set by
 * a website-audit auto-fix). Values are stored as post meta. Skipped when a
 * dedicated SEO plugin is active to avoid duplicate tags.
 */
function snowseo_output_seo_meta()
{
	if (! is_singular() || snowseo_seo_plugin_active()) {
		return;
	}

	$post_id = get_queried_object_id();
	if (! snowseo_manages_post_seo($post_id)) {
		return;
	}

	$get = function ($key) use ($post_id) {
		return trim((string) get_post_meta($post_id, '_snowseo_meta_' . $key, true));
	};

	$meta_title       = $get('metaTitle');
	$meta_description = $get('metaDescription');
	$og_title         = $get('ogTitle');
	$og_description   = $get('ogDescription');
	$tw_title         = $get('twitterTitle');
	$tw_description   = $get('twitterDescription');
	$canonical        = $get('canonicalUrl');

	$permalink = get_permalink($post_id);
	$og_url    = '' !== $canonical ? $canonical : $permalink;

	// Sensible fallbacks so social cards stay complete.
	$post_title     = get_the_title($post_id);
	$og_title_final = '' !== $og_title ? $og_title : ('' !== $meta_title ? $meta_title : $post_title);
	$og_desc_final  = '' !== $og_description ? $og_description : $meta_description;
	$tw_title_final = '' !== $tw_title ? $tw_title : $og_title_final;
	$tw_desc_final  = '' !== $tw_description ? $tw_description : $og_desc_final;

	$image_url = '';
	if (has_post_thumbnail($post_id)) {
		$image_url = (string) wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'full');
	}

	$tags = array();

	if ('' !== $meta_description) {
		$tags[] = sprintf('<meta name="description" content="%s" />', esc_attr($meta_description));
	}
	if ('' !== $canonical) {
		$tags[] = sprintf('<link rel="canonical" href="%s" />', esc_url($canonical));
	}

	// Open Graph.
	$tags[] = '<meta property="og:type" content="article" />';
	if ('' !== $og_title_final) {
		$tags[] = sprintf('<meta property="og:title" content="%s" />', esc_attr($og_title_final));
	}
	if ('' !== $og_desc_final) {
		$tags[] = sprintf('<meta property="og:description" content="%s" />', esc_attr($og_desc_final));
	}
	if ($og_url) {
		$tags[] = sprintf('<meta property="og:url" content="%s" />', esc_url($og_url));
	}
	if ('' !== $image_url) {
		$tags[] = sprintf('<meta property="og:image" content="%s" />', esc_url($image_url));
	}

	// Twitter.
	$tags[] = sprintf('<meta name="twitter:card" content="%s" />', '' !== $image_url ? 'summary_large_image' : 'summary');
	if ('' !== $tw_title_final) {
		$tags[] = sprintf('<meta name="twitter:title" content="%s" />', esc_attr($tw_title_final));
	}
	if ('' !== $tw_desc_final) {
		$tags[] = sprintf('<meta name="twitter:description" content="%s" />', esc_attr($tw_desc_final));
	}
	if ('' !== $image_url) {
		$tags[] = sprintf('<meta name="twitter:image" content="%s" />', esc_url($image_url));
	}

	// Every value above is already escaped with esc_attr()/esc_url(); the final
	// wp_kses() pass restricts the markup to head meta/link tags so the output
	// path is unambiguously safe.
	$allowed_head_tags = array(
		'meta' => array('name' => true, 'property' => true, 'content' => true),
		'link' => array('rel' => true, 'href' => true),
	);

	echo "\n<!-- SnowSEO -->\n\t";
	echo wp_kses(implode("\n\t", $tags), $allowed_head_tags);
	echo "\n<!-- /SnowSEO -->\n";
}
add_action('wp_head', 'snowseo_output_seo_meta', 1);

/**
 * Emit BlogPosting structured data (JSON-LD) for singular SnowSEO posts, built
 * from the live WordPress post so the URL, dates, author, and image always
 * match the published page. Skipped when a dedicated SEO plugin is active,
 * since it outputs its own structured data.
 */
function snowseo_output_jsonld()
{
	if (! is_singular() || snowseo_seo_plugin_active()) {
		return;
	}

	$post_id = get_queried_object_id();
	if (! $post_id || ! get_post_meta($post_id, '_snowseo_article_id', true)) {
		return;
	}

	$post = get_post($post_id);
	if (! $post) {
		return;
	}

	$permalink   = get_permalink($post_id);
	$title       = get_the_title($post_id);
	$description = trim((string) get_post_meta($post_id, '_snowseo_meta_metaDescription', true));
	if ('' === $description) {
		$description = trim(wp_strip_all_tags((string) get_the_excerpt($post_id)));
	}

	$node = array(
		'@context'         => 'https://schema.org',
		'@type'            => 'BlogPosting',
		'mainEntityOfPage' => array(
			'@type' => 'WebPage',
			'@id'   => $permalink,
		),
		'headline'         => function_exists('mb_substr') ? mb_substr($title, 0, 110) : substr($title, 0, 110),
		'url'              => $permalink,
		'datePublished'    => get_the_date('c', $post_id),
		'dateModified'     => get_the_modified_date('c', $post_id),
		'author'           => array(
			'@type' => 'Person',
			'name'  => get_the_author_meta('display_name', (int) $post->post_author),
		),
		'publisher'        => array(
			'@type' => 'Organization',
			'name'  => get_bloginfo('name'),
		),
	);

	if ('' !== $description) {
		$node['description'] = $description;
	}

	if (has_post_thumbnail($post_id)) {
		$thumb = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'full');
		if ($thumb) {
			$node['image'] = $thumb;
		}
	}

	$site_icon = get_site_icon_url(512);
	if ($site_icon) {
		$node['publisher']['logo'] = array(
			'@type' => 'ImageObject',
			'url'   => $site_icon,
		);
	}

	echo "\n<script type=\"application/ld+json\">" . wp_json_encode($node) . "</script>\n";
}
add_action('wp_head', 'snowseo_output_jsonld', 2);
