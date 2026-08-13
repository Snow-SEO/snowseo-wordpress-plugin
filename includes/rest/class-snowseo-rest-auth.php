<?php
/**
 * Permission callbacks for the SnowSEO REST routes.
 *
 * Static, so routes register the callable directly and SnowSEO_Perf can check a
 * plugin key without building a REST controller. Option names stay owned by
 * SnowSEO_Rest_API so there is one spelling of each.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Rest_Auth
{
	/**
	 * Permission check: require manage_options capability.
	 */
	public static function check_admin_permission()
	{
		return current_user_can('manage_options');
	}

	/**
	 * Permission check: validate the X-Plugin-Key header against the stored API key.
	 * Used for server-to-server calls from the SnowSEO backend.
	 */
	public static function check_plugin_key_permission($request)
	{
		return self::verify_plugin_key($request);
	}

	/**
	 * Static form of the key check.
	 *
	 * Instantiating this class just to compare two strings costs a
	 * wp_kses_allowed_html('post') build plus a 20-key array on every call, so
	 * other classes (SnowSEO_Perf) use this instead. One implementation of the
	 * comparison, two entry points.
	 */
	public static function verify_plugin_key($request)
	{
		$plugin_key = $request->get_header('X-Plugin-Key');
		$stored_key = get_option(SnowSEO_Rest_API::OPTION_API_KEY, '');

		if (empty($plugin_key) || empty($stored_key)) {
			return false;
		}

		return hash_equals($stored_key, $plugin_key);
	}

	/**
	 * Permission check for /invalidate endpoint.
	 *
	 * Requires the X-Plugin-Key header to match the key currently stored on this
	 * site. SnowSEO sends the *outgoing* key when it rotates credentials, so the
	 * header still matches at the moment of the call. A team ID is an identifier
	 * rather than a secret, so it can never authorize disconnecting a site on its
	 * own; it is only re-checked in the handler as a second, non-authorizing
	 * assertion that the call targets this connection.
	 */
	public static function check_invalidation_permission($request)
	{
		return self::check_plugin_key_permission($request);
	}
}
