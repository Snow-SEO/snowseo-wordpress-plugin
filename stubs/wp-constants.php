<?php
/**
 * Editor-only stubs for WordPress runtime constants. NOT shipped and NOT loaded.
 *
 * php-stubs/wordpress-stubs declares WordPress functions and classes but no
 * constants: WordPress defines these at runtime (wp-load.php, wp-config.php,
 * wp_initial_constants()), so there is no static `define()` for the stub
 * generator to extract. Without them Intelephense reports every use of ABSPATH,
 * ARRAY_A, WP_DEBUG and friends as "Undefined constant" (P1011) even though the
 * code is correct.
 *
 * Referenced through `intelephense.environment.includePaths` in
 * .vscode/settings.json. The packaging allowlist in zip-plugin.py only ships
 * snowseo.php, readme.txt, uninstall.php, LICENSE.txt, build/ and includes/, so
 * this directory can never reach a user's site.
 *
 * Values are placeholders - only the fact that each constant EXISTS matters to
 * the language server.
 */

// Core paths.
define('ABSPATH', '/var/www/html/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_CONTENT_URL', 'https://example.com/wp-content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WP_PLUGIN_URL', WP_CONTENT_URL . '/plugins');
define('WP_LANG_DIR', WP_CONTENT_DIR . '/languages');

// Debug flags.
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', true);

// $wpdb result formats.
define('OBJECT', 'OBJECT');
define('OBJECT_K', 'OBJECT_K');
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');

// Time helpers.
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('YEAR_IN_SECONDS', 31536000);

// Request-context flags.
define('WP_ADMIN', true);
define('DOING_AJAX', true);
define('DOING_CRON', true);
define('DOING_AUTOSAVE', true);
define('EMPTY_TRASH_DAYS', 30);

// Third-party SEO plugins. Only ever read via defined() for feature detection,
// but declaring them keeps that check from being flagged too.
define('WPSEO_VERSION', '0.0.0');
define('RANK_MATH_VERSION', '0.0.0');
define('SEOPRESS_VERSION', '0.0.0');
