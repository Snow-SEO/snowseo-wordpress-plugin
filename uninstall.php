<?php
/**
 * SnowSEO Uninstall
 *
 * Fired when the plugin is uninstalled (deleted via WP Admin).
 *
 * @package SnowSEO
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove current plugin options.
delete_option( 'snowseo_version' );
delete_option( 'snowseo_activated' );
delete_option( 'snowseo_api_key' );
delete_option( 'snowseo_connection' );
delete_option( 'snowseo_activity_logs' );

// Remove legacy option keys (pre-1.1.0, when the slug was "snowseo-wordpress-plugin").
delete_option( 'snowseo_wordpress_plugin_version' );
delete_option( 'snowseo_wordpress_plugin_activated' );
delete_option( 'snowseo_wordpress_plugin_settings' );

// Remove the performance fix consent flags and their applied-state bookkeeping.
delete_option( 'snowseo_perf_remote_enabled' );
delete_option( 'snowseo_perf_robots_repair' );
delete_option( 'snowseo_perf_compression_enabled' );
delete_option( 'snowseo_perf_cache_headers_enabled' );
delete_option( 'snowseo_perf_render_enabled' );
delete_option( 'snowseo_perf_render_state' );
delete_option( 'snowseo_perf_font_display_enabled' );
delete_option( 'snowseo_perf_preconnect_enabled' );
delete_option( 'snowseo_perf_state' );
delete_option( 'snowseo_perf_lock' );

delete_transient( 'snowseo_perf_probe_robots' );
delete_transient( 'snowseo_perf_probe_compression' );

wp_clear_scheduled_hook( 'snowseo_perf_recanary' );

/**
 * The performance blocks live in the site-root .htaccess, outside the plugin
 * directory. An orphaned block referencing a plugin that no longer exists is
 * the worst outcome here, because nothing in the admin would ever show it
 * again.
 *
 * Both markers are stripped. Missing one would leave a year-long Cache-Control
 * rule on a site whose owner has no idea where it came from.
 *
 * The site root is resolved with get_home_path(), never from ABSPATH: where
 * WordPress lives in a subdirectory, ABSPATH is that subdirectory and the
 * .htaccess these blocks were written to is the one above it. get_home_path()
 * lives in an admin-only include, so load it explicitly rather than assuming
 * the caller did, and confirm the result is a real directory - it falls back to
 * string arithmetic over home_url()/site_url(), which a symlinked or unusually
 * mapped docroot can defeat. When the site root cannot be resolved, nothing is
 * touched: a wrong path here means editing a file this plugin never wrote.
 *
 * This file deliberately depends on nothing but WordPress. SnowSEO_FS has the
 * same resolver and the same writer, but a second copy of this plugin installed
 * under another folder name would already have declared that class, and loading
 * it again would be a fatal error in the middle of an uninstall.
 */
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}

$snowseo_home     = get_home_path();
$snowseo_htaccess = ( is_string( $snowseo_home ) && '' !== $snowseo_home && is_dir( $snowseo_home ) )
	? $snowseo_home . '.htaccess'
	: '';

if ( '' !== $snowseo_htaccess && file_exists( $snowseo_htaccess ) && wp_is_writable( $snowseo_htaccess ) ) {
	$snowseo_ht_contents = file_get_contents( $snowseo_htaccess );
	$snowseo_ht_markers  = array( 'SnowSEO Performance', 'SnowSEO Cache Headers' );
	$snowseo_ht_changed  = false;
	foreach ( $snowseo_ht_markers as $snowseo_ht_marker ) {
		if ( ! is_string( $snowseo_ht_contents ) || false === strpos( $snowseo_ht_contents, '# BEGIN ' . $snowseo_ht_marker ) ) {
			continue;
		}
		// No match limit: strip duplicates too, the same way the plugin's own
		// remove() does.
		$snowseo_ht_stripped = preg_replace(
			'/^[ \t]*# BEGIN ' . preg_quote( $snowseo_ht_marker, '/' ) . '.*?# END ' . preg_quote( $snowseo_ht_marker, '/' ) . '[ \t]*\r?\n?/ms',
			'',
			$snowseo_ht_contents
		);
		if ( is_string( $snowseo_ht_stripped ) ) {
			$snowseo_ht_contents = $snowseo_ht_stripped;
			$snowseo_ht_changed  = true;
		}
	}
	if ( $snowseo_ht_changed ) {
		/*
		 * Temp file then rename(), never a direct write. A direct write that is
		 * interrupted - killed process, full disk - leaves a truncated site-root
		 * .htaccess, which makes Apache answer 500 for every request on the site,
		 * with the plugin already gone and nothing left to repair it. rename(2) is
		 * atomic within a filesystem, so the file is either the old one or the new
		 * one. SnowSEO_FS::write_atomic() is the same routine, deliberately not
		 * loaded here - see above.
		 */
		$snowseo_ht_tmp = $snowseo_htaccess . '.snowseo-tmp';
		if ( false !== file_put_contents( $snowseo_ht_tmp, $snowseo_ht_contents, LOCK_EX ) ) {
			$snowseo_ht_perms = @fileperms( $snowseo_htaccess );
			if ( false !== $snowseo_ht_perms ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Carries the original file's mode onto the temp file so the rename below cannot change it. WP_Filesystem has no equivalent that keeps the swap atomic.
				@chmod( $snowseo_ht_tmp, $snowseo_ht_perms & 0777 );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- rename(2) is the atomic swap described above. WP_Filesystem::move() is not atomic on every transport, and this runs with the plugin already gone, so a torn .htaccess could not be repaired.
			if ( ! @rename( $snowseo_ht_tmp, $snowseo_htaccess ) ) {
				wp_delete_file( $snowseo_ht_tmp );
			}
		}
	}
}
