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
