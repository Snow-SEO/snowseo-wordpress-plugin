<?php
/**
 * Connection and diagnostics endpoints.
 *
 * Everything about this site's link to SnowSEO: connecting and disconnecting,
 * reporting status, serving the activity log, and the health-check ping the
 * backend uses to confirm requests reach the plugin at all.
 *
 * Static, like the other REST classes here - these handlers read and write
 * options and talk to the backend, none of them need instance state, and
 * snowseo.php can ask whether the site is connected without constructing a
 * whole REST controller.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Rest_Connection
{
	/**
	 * Handle POST /connect request.
	 * Validates the API key against the SnowSEO server and stores credentials.
	 */
	public static function handle_connect($request)
	{
		$api_key = $request->get_param('apiKey');

		if (empty($api_key)) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => 'API key is required.',
			), 400);
		}

		// Validate the key against SnowSEO server
		$validation = self::validate_key_with_server($api_key);

		if (is_wp_error($validation)) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $validation->get_error_message(),
			), 400);
		}

		if (! $validation['valid']) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $validation['error'] ?? 'Invalid API key.',
			), 401);
		}

		// Store the API key and connection data
		update_option(SnowSEO_Rest_API::OPTION_API_KEY, $api_key);
		update_option(SnowSEO_Rest_API::OPTION_CONNECTION, array(
			'connected'      => true,
			'connected_at'   => current_time('mysql'),
			'team_id'        => $validation['data']['teamId'] ?? '',
			'team_name'      => $validation['data']['teamName'] ?? '',
			'team_website'   => $validation['data']['teamWebsite'] ?? '',
			'site_url'       => $validation['data']['siteUrl'] ?? '',
			'site_title'     => $validation['data']['siteTitle'] ?? get_bloginfo('name'),
			'wp_version'     => get_bloginfo('version'),
		));

		// Log the connection event
		SnowSEO_Log::write('connected', 'Plugin connected to SnowSEO');

		return new WP_REST_Response(array(
			'success' => true,
			'message' => 'Successfully connected to SnowSEO!',
			'data'    => array(
				'siteTitle'   => $validation['data']['siteTitle'] ?? get_bloginfo('name'),
				'siteUrl'     => $validation['data']['siteUrl'] ?? '',
				'connectedAt' => current_time('mysql'),
			),
		), 200);
	}

	/**
	 * Handle POST /disconnect request.
	 */
	public static function handle_disconnect($request)
	{
		// Notify the SnowSEO backend to deactivate the integration
		$api_key = get_option(SnowSEO_Rest_API::OPTION_API_KEY, '');
		if (! empty($api_key)) {
			$url = trailingslashit(SnowSEO_Rest_API::api_url()) . 'integrations/wordpress/plugin-disconnect';
			$response = wp_remote_post($url, array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-Plugin-Key' => $api_key,
				),
				'body' => '{}',
			));
			if (is_wp_error($response)) {
				SnowSEO_Log::write('error', 'Failed to notify SnowSEO of disconnect: ' . $response->get_error_message());
			}
		}

		// Log before clearing
		SnowSEO_Log::write('disconnected', 'Plugin disconnected from SnowSEO');

		// Clear stored data
		delete_option(SnowSEO_Rest_API::OPTION_API_KEY);
		delete_option(SnowSEO_Rest_API::OPTION_CONNECTION);

		return new WP_REST_Response(array(
			'success' => true,
			'message' => 'Successfully disconnected from SnowSEO.',
		), 200);
	}

	/**
	 * Handle API key invalidation request.
	 *
	 * Authorization already happened in check_invalidation_permission() via the
	 * X-Plugin-Key header. When SnowSEO also sends a teamId, confirm it matches
	 * the stored connection so a call meant for a different connection is a no-op.
	 */
	public static function handle_invalidate($request)
	{
		$team_id    = (string) $request->get_param('teamId');
		$connection = get_option(SnowSEO_Rest_API::OPTION_CONNECTION, null);

		if ('' !== $team_id) {
			$stored_team_id = is_array($connection) && isset($connection['team_id'])
				? (string) $connection['team_id']
				: '';
			if ('' === $stored_team_id || ! hash_equals($stored_team_id, $team_id)) {
				return new WP_Error(
					'unauthorized',
					'Team ID mismatch or no connection exists.',
					array('status' => 403)
				);
			}
		}

		delete_option(SnowSEO_Rest_API::OPTION_API_KEY);
		delete_option(SnowSEO_Rest_API::OPTION_CONNECTION);

		SnowSEO_Log::write('error', 'API key is no longer valid - disconnecting automatically');

		return new WP_REST_Response(array('success' => true), 200);
	}

	/**
	 * Handle GET /status request.
	 */
	public static function handle_status($request)
	{
		$api_key    = get_option(SnowSEO_Rest_API::OPTION_API_KEY, '');
		$connection = get_option(SnowSEO_Rest_API::OPTION_CONNECTION, array());

		$is_connected = ! empty($api_key) && ! empty($connection['connected']);

		if (! $is_connected) {
			return new WP_REST_Response(array(
				'connected' => false,
			), 200);
		}

		// Re-validate key against the server (no side effects - just check validity).
		$validation = self::validate_key_with_server($api_key, false);

		if (is_wp_error($validation) || empty($validation['valid'])) {
			// Key is no longer valid - auto-disconnect
			SnowSEO_Log::write('invalidated', 'API key is no longer valid - disconnecting automatically');
			delete_option(SnowSEO_Rest_API::OPTION_API_KEY);
			delete_option(SnowSEO_Rest_API::OPTION_CONNECTION);

			return new WP_REST_Response(array(
				'connected' => false,
			), 200);
		}

		// Plugin updates are delivered through WordPress.org's standard update
		// mechanism (per WordPress.org plugin guideline #8). The plugin must not
		// fetch its own version metadata or download URLs from an external server.

		return new WP_REST_Response(array(
			'connected'       => true,
			'data'            => array(
				'teamName'     => $connection['team_name'] ?? '',
				'teamWebsite'  => $connection['team_website'] ?? '',
				'siteTitle'    => $connection['site_title'] ?? get_bloginfo('name'),
				'siteUrl'      => $connection['site_url'] ?? home_url(),
				'teamId'       => $connection['team_id'] ?? '',
				'connectedAt'  => $connection['connected_at'] ?? '',
				'wpVersion'    => get_bloginfo('version'),
				'phpVersion'   => phpversion(),
				'pluginVersion' => SNOWSEO_VERSION,
			),
		), 200);
	}

	/**
	 * Handle GET /logs request.
	 */
	public static function handle_logs($request)
	{
		$page     = $request->get_param('page');
		$per_page = $request->get_param('per_page');
		$status   = $request->get_param('status');

		$all_logs = get_option(SnowSEO_Log::OPTION, array());

		// Filter by status if not 'all'
		if ('all' !== $status && ! empty($status)) {
			$all_logs = array_filter($all_logs, function ($log) use ($status) {
				return ($log['status'] ?? '') === $status;
			});
			$all_logs = array_values($all_logs);
		}

		// Sort by date descending (newest first)
		usort($all_logs, function ($a, $b) {
			return strtotime($b['date'] ?? '0') - strtotime($a['date'] ?? '0');
		});

		$total       = count($all_logs);
		$total_pages = ceil($total / $per_page);
		$offset      = ($page - 1) * $per_page;
		$paged_logs  = array_slice($all_logs, $offset, $per_page);

		return new WP_REST_Response(array(
			'logs'       => $paged_logs,
			'pagination' => array(
				'page'       => $page,
				'perPage'    => $per_page,
				'total'      => $total,
				'totalPages' => $total_pages,
			),
		), 200);
	}

	/**
	 * Handle GET /ping request.
	 * Lightweight health check for the SnowSEO server to verify
	 * that inbound requests reach the plugin without being blocked by a WAF.
	 */
	public static function handle_ping($request)
	{
		return new WP_REST_Response(array(
			'pong'          => true,
			'pluginVersion' => SNOWSEO_VERSION,
		), 200);
	}

	/**
	 * Handle GET /logs/stats - return computed stats from local logs.
	 */
	public static function handle_log_stats($request)
	{
		$all_logs = get_option(SnowSEO_Log::OPTION, array());
		$today_str = gmdate('Y-m-d');
		$stats = array(
			'total'          => count($all_logs),
			'publishedToday' => 0,
			'failed'         => 0,
			'connected'      => 0,
			'disconnected'   => 0,
			'publishedTotal' => 0,
		);

		foreach ($all_logs as $log) {
			$log_date = isset($log['date']) ? substr($log['date'], 0, 10) : '';
			$status   = $log['status'] ?? '';

			if ('published' === $status) {
				$stats['publishedToday'] += ($log_date === $today_str) ? 1 : 0;
				$stats['publishedTotal']++;
			}
			if ('error' === $status || 'failed' === $status) {
				$stats['failed']++;
			}
			if ('connected' === $status) {
				$stats['connected']++;
			}
			if ('disconnected' === $status || 'invalidated' === $status) {
				$stats['disconnected']++;
			}
		}

		$total_attempts = $stats['publishedTotal'] + $stats['failed'];
		$stats['successRate'] = $total_attempts > 0
			? round(($stats['publishedTotal'] / $total_attempts) * 100)
			: 0;

		return new WP_REST_Response($stats, 200);
	}

	/**
	 * Validate the plugin API key against the SnowSEO server.
	 * When $connect is true (initial setup), the SnowSEO backend persists the connection.
	 * When false (status check), it just validates without side effects.
	 *
	 * @param string $api_key The plugin API key.
	 * @param bool   $connect Whether to persist the connection on the backend.
	 */
	private static function validate_key_with_server($api_key, $connect = true)
	{
		$url = trailingslashit(SnowSEO_Rest_API::api_url()) . 'integrations/wordpress/validate-plugin-key';

		$response = wp_remote_post($url, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-Plugin-Key'  => $api_key,
			),
			'body'    => wp_json_encode(array(
				'apiKey'          => $api_key,
				'siteUrl'         => home_url(),
				'siteTitle'       => get_bloginfo('name'),
				'siteDescription' => get_bloginfo('description'),
				'wpVersion'       => get_bloginfo('version'),
				'pluginVersion'   => SNOWSEO_VERSION,
				'connect'         => $connect,
			)),
		));

		if (is_wp_error($response)) {
			return new WP_Error(
				'connection_failed',
				'Could not connect to SnowSEO server: ' . $response->get_error_message()
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (200 !== $code) {
			return array(
				'valid' => false,
				'error' => $body['error'] ?? 'Server returned error code: ' . $code,
			);
		}

		return $body;
	}

	/**
	 * Check if the plugin is currently connected.
	 */
	public static function is_connected()
	{
		$api_key    = get_option(SnowSEO_Rest_API::OPTION_API_KEY, '');
		$connection = get_option(SnowSEO_Rest_API::OPTION_CONNECTION, array());
		return ! empty($api_key) && ! empty($connection['connected']);
	}

}
