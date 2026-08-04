<?php

/**
 * SnowSEO REST API Handler
 *
 * Registers REST API endpoints for the SnowSEO WordPress plugin.
 * Handles connection validation, status, disconnect, and activity logs.
 *
 * @package SnowSEO_Connector
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Rest_API
{

	/**
	 * The SnowSEO API base URL.
	 */
	private $api_url;

	/**
	 * Allowed HTML tags for post content from SnowSEO server.
	 * Extends wp_kses_post defaults with HTML5 semantic tags.
	 */
	private $allowed_html;

	/**
	 * Option keys used for storage.
	 */
	const OPTION_API_KEY    = 'snowseo_api_key';
	const OPTION_CONNECTION = 'snowseo_connection';
	const OPTION_LOGS       = 'snowseo_activity_logs';

	/**
	 * Constructor. Uses SNOWSEO_API_URL (set in main plugin file); filter 'snowseo_api_url' can override.
	 * Initializes the allowed HTML tags for post content filtering.
	 */
	public function __construct()
	{
		$this->api_url = apply_filters('snowseo_api_url', SNOWSEO_API_URL);

		// Extend wp_kses_post defaults with HTML5 semantic tags.
		$this->allowed_html = wp_kses_allowed_html('post');
		$this->allowed_html['article']    = array();
		$this->allowed_html['section']    = array();
		$this->allowed_html['aside']      = array();
		$this->allowed_html['header']     = array();
		$this->allowed_html['footer']     = array();
		$this->allowed_html['nav']        = array();
		$this->allowed_html['figure']     = array();
		$this->allowed_html['figcaption'] = array();
		$this->allowed_html['details']    = array();
		$this->allowed_html['summary']    = array();
		$this->allowed_html['time']       = array('datetime' => true);
		$this->allowed_html['picture']    = array();
		$this->allowed_html['source']     = array('srcset' => true, 'sizes' => true, 'media' => true, 'type' => true);

		// Allow video embeds (e.g. YouTube). wp_kses_post() strips <iframe> by
		// default, which would remove the embeds SnowSEO generates. We allow the
		// tag here and then restrict its src to trusted hosts in
		// strip_disallowed_iframes() so it cannot become an XSS vector.
		$this->allowed_html['iframe'] = array(
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'frameborder'     => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'loading'         => true,
			'referrerpolicy'  => true,
			'title'           => true,
			'style'           => true,
			'class'           => true,
		);
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes()
	{
		$namespace = 'snowseo/v1';

		// POST /connect - validate API key against SnowSEO server
		register_rest_route($namespace, '/connect', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'handle_connect'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args'                => array(
				'apiKey' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		));

		// POST /disconnect - remove stored credentials
		register_rest_route($namespace, '/disconnect', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'handle_disconnect'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		// GET /status - return connection status
		register_rest_route($namespace, '/status', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'handle_status'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		// GET /logs - return activity logs
		register_rest_route($namespace, '/logs', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'handle_logs'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args'                => array(
				'page'     => array(
					'default'           => 1,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'default'           => 20,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'status'   => array(
					'default'           => 'all',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		));

		// GET /articles - proxy to SnowSEO API
		register_rest_route($namespace, '/articles', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'handle_articles'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args'                => array(
				'page'     => array('default' => 1, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				'per_page' => array('default' => 20, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				'status'   => array('default' => 'all', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
			),
		));

		// GET /articles/(?P<slug>[a-zA-Z0-9-]+) - proxy single article
		register_rest_route($namespace, '/articles/(?P<slug>[a-zA-Z0-9-]+)', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'handle_article_single'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		// POST /publish - proxy publish to SnowSEO API
		register_rest_route($namespace, '/publish', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'handle_publish'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args'                => array(
				'articleSlug' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'status'      => array('default' => 'publish', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
			),
		));

		// GET /settings - proxy settings from SnowSEO API
		register_rest_route($namespace, '/settings', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'handle_settings'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		// GET /posts-status - check status of multiple posts
		register_rest_route($namespace, '/posts-status', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'handle_posts_status'),
			'permission_callback' => array($this, 'check_plugin_key_permission'),
			'args'                => array(
				'ids' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		));

		// GET /logs/stats - computed stats from local logs
		register_rest_route($namespace, '/logs/stats', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'handle_log_stats'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		// GET /ping - health check endpoint for SnowSEO server to verify inbound connectivity.
		// Public: no auth required. The key hasn't been stored locally yet during the initial
		// connection handshake, so requiring check_plugin_key_permission would create a
		// chicken-and-egg failure.
		register_rest_route($namespace, '/ping', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'handle_ping'),
			'permission_callback' => '__return_true',
		));

		// POST /receive-publish - receive article content from SnowSEO server and publish locally
		register_rest_route($namespace, '/receive-publish', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'handle_receive_publish'),
			'permission_callback' => array($this, 'check_plugin_key_permission'),
			'args'                => array(
				'title'                  => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'content'                => array('required' => true, 'type' => 'string', 'sanitize_callback' => array($this, 'sanitize_content_param')),
				'status'                 => array('default' => 'publish', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'excerpt'                => array('default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'articleId'              => array('default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'date'                   => array('default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'type'                   => array('default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'seoScore'               => array('default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'featuredImage'          => array('default' => '', 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'),
				'featuredImageCaption'   => array('default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'featured_image_url'     => array('default' => '', 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'),
				'featured_image_caption' => array('default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'meta'                   => array('default' => array(), 'type' => 'object'),
				'categories'             => array('default' => array(), 'type' => 'array', 'items' => array('type' => 'string')),
				'tags'                   => array('default' => array(), 'type' => 'array', 'items' => array('type' => 'string')),
			),
		));

		// POST /invalidate - called by SnowSEO server when the API key is regenerated.
		// Authenticated with the outgoing key in X-Plugin-Key; teamId is optional
		// and only used as a secondary check that the call targets this connection.
		register_rest_route($namespace, '/invalidate', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'handle_invalidate'),
			'permission_callback' => array($this, 'check_invalidation_permission'),
			'args'                => array(
				'teamId' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		));

		// DELETE /posts/(?P<id>\d+) - delete a post locally
		register_rest_route($namespace, '/posts/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array($this, 'handle_delete_post'),
			'permission_callback' => array($this, 'check_plugin_key_permission'),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		));

		// GET /posts/by-url - resolve a URL to a post ID and return its current content/meta
		register_rest_route($namespace, '/posts/by-url', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'handle_get_post_by_url'),
			'permission_callback' => array($this, 'check_plugin_key_permission'),
			'args'                => array(
				'url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		));

		// POST /posts/(?P<id>\d+)/update - update specific fields and metadata on a post
		register_rest_route($namespace, '/posts/(?P<id>\d+)/update', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'handle_update_post'),
			'permission_callback' => array($this, 'check_plugin_key_permission'),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'title' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'content' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => array($this, 'sanitize_content_param'),
				),
				'meta' => array(
					'required'          => false,
					'type'              => 'object',
				),
			),
		));
	}

	/**
	 * Permission check: require manage_options capability.
	 */
	public function check_admin_permission()
	{
		return current_user_can('manage_options');
	}

	/**
	 * Permission check: validate the X-Plugin-Key header against the stored API key.
	 * Used for server-to-server calls from the SnowSEO backend.
	 */
	public function check_plugin_key_permission($request)
	{
		$plugin_key = $request->get_header('X-Plugin-Key');
		$stored_key = get_option(self::OPTION_API_KEY, '');

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
	public function check_invalidation_permission($request)
	{
		return $this->check_plugin_key_permission($request);
	}

	/**
	 * Handle POST /connect request.
	 * Validates the API key against the SnowSEO server and stores credentials.
	 */
	public function handle_connect($request)
	{
		$api_key = $request->get_param('apiKey');

		if (empty($api_key)) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => 'API key is required.',
			), 400);
		}

		// Validate the key against SnowSEO server
		$validation = $this->validate_key_with_server($api_key);

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
		update_option(self::OPTION_API_KEY, $api_key);
		update_option(self::OPTION_CONNECTION, array(
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
		$this->add_log_entry('connected', 'Plugin connected to SnowSEO');

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
	public function handle_disconnect($request)
	{
		// Notify the SnowSEO backend to deactivate the integration
		$api_key = get_option(self::OPTION_API_KEY, '');
		if (! empty($api_key)) {
			$url = trailingslashit($this->api_url) . 'integrations/wordpress/plugin-disconnect';
			$response = wp_remote_post($url, array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-Plugin-Key' => $api_key,
				),
				'body' => '{}',
			));
			if (is_wp_error($response)) {
				$this->add_log_entry('error', 'Failed to notify SnowSEO of disconnect: ' . $response->get_error_message());
			}
		}

		// Log before clearing
		$this->add_log_entry('disconnected', 'Plugin disconnected from SnowSEO');

		// Clear stored data
		delete_option(self::OPTION_API_KEY);
		delete_option(self::OPTION_CONNECTION);

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
	public function handle_invalidate($request)
	{
		$team_id    = (string) $request->get_param('teamId');
		$connection = get_option(self::OPTION_CONNECTION, null);

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

		delete_option(self::OPTION_API_KEY);
		delete_option(self::OPTION_CONNECTION);

		$this->add_log_entry('error', 'API key is no longer valid - disconnecting automatically');

		return new WP_REST_Response(array('success' => true), 200);
	}

	/**
	 * Handle deleting a post from SnowSEO.
	 */
	public function handle_delete_post($request)
	{
		$post_id = $request->get_param('id');

		if (! get_post($post_id)) {
			return new WP_Error('not_found', 'Post not found', array('status' => 404));
		}

		// Only allow deleting posts that originated from SnowSEO (i.e. they carry
		// our article-id meta). Even though this endpoint is authenticated by the
		// site token, scoping deletion to SnowSEO-created posts prevents a leaked
		// token from permanently removing unrelated WordPress content.
		if (! get_post_meta($post_id, '_snowseo_article_id', true)) {
			return new WP_Error('forbidden', 'This post was not created by SnowSEO and cannot be deleted here.', array('status' => 403));
		}

		$result = wp_delete_post($post_id, true); // true to skip trash and permanently delete

		if (! $result) {
			$this->add_log_entry('error', "Failed to delete post ID {$post_id}");
			return new WP_Error('delete_failed', 'Failed to delete post', array('status' => 500));
		}

		$this->add_log_entry('success', "Deleted post ID {$post_id} remotely");
		return new WP_REST_Response(array('success' => true), 200);
	}

	/**
	 * Handle GET /posts/by-url request to find a post by its URL/permalink.
	 */
	public function handle_get_post_by_url($request)
	{
		$url     = $request->get_param('url');
		$post_id = url_to_postid($url);

		if (! $post_id) {
			// Fallback: match slug from URL path if url_to_postid fails (e.g. custom permalinks structure)
			$path = wp_parse_url($url, PHP_URL_PATH);
			if ($path) {
				$slug = basename(untrailingslashit($path));
				$posts = get_posts(array(
					'name'           => $slug,
					'post_type'      => array('post', 'page'),
					'posts_per_page' => 1,
					'fields'         => 'ids',
				));
				if (! empty($posts)) {
					$post_id = (int) $posts[0];
				}
			}
		}

		if (! $post_id) {
			return new WP_Error('not_found', 'Post not found for URL: ' . $url, array('status' => 404));
		}

		$post = get_post($post_id);
		if (! $post) {
			return new WP_Error('not_found', 'Post not found', array('status' => 404));
		}

		// Fetch existing SEO metadata from custom fields
		$seo_meta = array(
			'metaTitle'       => get_post_meta($post_id, '_snowseo_meta_metaTitle', true),
			'metaDescription' => get_post_meta($post_id, '_snowseo_meta_metaDescription', true),
			'canonicalUrl'    => get_post_meta($post_id, '_snowseo_meta_canonicalUrl', true),
		);

		// Yoast fallback
		if (defined('WPSEO_VERSION')) {
			$seo_meta['metaTitle']       = $seo_meta['metaTitle'] ?: get_post_meta($post_id, '_yoast_wpseo_title', true);
			$seo_meta['metaDescription'] = $seo_meta['metaDescription'] ?: get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
			$seo_meta['canonicalUrl']    = $seo_meta['canonicalUrl'] ?: get_post_meta($post_id, '_yoast_wpseo_canonical', true);
		}

		// Rank Math fallback
		if (defined('RANK_MATH_VERSION')) {
			$seo_meta['metaTitle']       = $seo_meta['metaTitle'] ?: get_post_meta($post_id, 'rank_math_title', true);
			$seo_meta['metaDescription'] = $seo_meta['metaDescription'] ?: get_post_meta($post_id, 'rank_math_description', true);
			$seo_meta['canonicalUrl']    = $seo_meta['canonicalUrl'] ?: get_post_meta($post_id, 'rank_math_canonical_url', true);
		}

		// SEOPress fallback
		if (defined('SEOPRESS_VERSION')) {
			$seo_meta['metaTitle']       = $seo_meta['metaTitle'] ?: get_post_meta($post_id, '_seopress_titles_title', true);
			$seo_meta['metaDescription'] = $seo_meta['metaDescription'] ?: get_post_meta($post_id, '_seopress_titles_desc', true);
			$seo_meta['canonicalUrl']    = $seo_meta['canonicalUrl'] ?: get_post_meta($post_id, '_seopress_robots_canonical', true);
		}

		return new WP_REST_Response(array(
			'success' => true,
			'data'    => array(
				'id'      => $post_id,
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'status'  => $post->post_status,
				'seoMeta' => $seo_meta,
			),
		), 200);
	}

	/**
	 * Handle POST /posts/<id>/update request to update a post's content or metadata.
	 */
	public function handle_update_post($request)
	{
		$post_id = $request->get_param('id');
		$post    = get_post($post_id);

		if (! $post) {
			return new WP_Error('not_found', 'Post not found', array('status' => 404));
		}

		$post_data = array('ID' => $post_id);

		$title = $request->get_param('title');
		if ($title !== null) {
			$post_data['post_title'] = sanitize_text_field($title);
		}

		$content = $request->get_param('content');
		if ($content !== null) {
			add_filter('wp_kses_allowed_html', array($this, 'allow_iframe_in_post_kses'), 10, 2);
			$post_data['post_content'] = $this->strip_disallowed_iframes(wp_kses($content, $this->allowed_html));
		}

		if (count($post_data) > 1) {
			$result = wp_update_post($post_data, true);
			if ($content !== null) {
				remove_filter('wp_kses_allowed_html', array($this, 'allow_iframe_in_post_kses'), 10);
			}
			if (is_wp_error($result)) {
				return new WP_Error('update_failed', 'Failed to update post: ' . $result->get_error_message(), array('status' => 500));
			}
		}

		$meta = $request->get_param('meta');
		if (! empty($meta) && is_array($meta)) {
			$allowed_keys = array('metaTitle', 'metaDescription', 'ogTitle', 'ogDescription', 'twitterTitle', 'twitterDescription', 'canonicalUrl');
			$clean_meta   = array();
			foreach ($allowed_keys as $key) {
				if (isset($meta[$key]) && is_string($meta[$key])) {
					$clean_meta[$key] = sanitize_text_field($meta[$key]);
					update_post_meta($post_id, '_snowseo_meta_' . $key, $clean_meta[$key]);
				}
			}
			$this->sync_seo_plugin_meta($post_id, $clean_meta);
		}

		$this->add_log_entry('success', "Updated post ID {$post_id} remotely");
		return new WP_REST_Response(array(
			'success' => true,
			'message' => 'Post updated successfully',
		), 200);
	}

	/**
	 * Handle GET /status request.
	 */
	public function handle_status($request)
	{
		$api_key    = get_option(self::OPTION_API_KEY, '');
		$connection = get_option(self::OPTION_CONNECTION, array());

		$is_connected = ! empty($api_key) && ! empty($connection['connected']);

		if (! $is_connected) {
			return new WP_REST_Response(array(
				'connected' => false,
			), 200);
		}

		// Re-validate key against the server (no side effects - just check validity).
		$validation = $this->validate_key_with_server($api_key, false);

		if (is_wp_error($validation) || empty($validation['valid'])) {
			// Key is no longer valid - auto-disconnect
			$this->add_log_entry('invalidated', 'API key is no longer valid - disconnecting automatically');
			delete_option(self::OPTION_API_KEY);
			delete_option(self::OPTION_CONNECTION);

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
	public function handle_logs($request)
	{
		$page     = $request->get_param('page');
		$per_page = $request->get_param('per_page');
		$status   = $request->get_param('status');

		$all_logs = get_option(self::OPTION_LOGS, array());

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
	 * Handle GET /articles - proxy to SnowSEO API.
	 */
	public function handle_articles($request)
	{
		$api_key = get_option(self::OPTION_API_KEY, '');
		if (empty($api_key)) {
			return new WP_REST_Response(array('error' => 'Not connected'), 401);
		}

		$page     = $request->get_param('page');
		$per_page = $request->get_param('per_page');
		$status   = $request->get_param('status');

		$url = trailingslashit($this->api_url) . "cms/articles?page={$page}&per_page={$per_page}&status={$status}";

		$response = wp_remote_get($url, array(
			'timeout' => 15,
			'headers' => array('X-Plugin-Key' => $api_key),
		));

		if (is_wp_error($response)) {
			return new WP_REST_Response(array('error' => $response->get_error_message()), 500);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		$code = wp_remote_retrieve_response_code($response);

		if ($body === null) {
			return new WP_REST_Response(array('error' => 'Invalid response from SnowSEO API'), 502);
		}

		return new WP_REST_Response($body, $code);
	}

	/**
	 * Handle GET /articles/:slug - proxy single article to SnowSEO API.
	 */
	public function handle_article_single($request)
	{
		$api_key = get_option(self::OPTION_API_KEY, '');
		if (empty($api_key)) {
			return new WP_REST_Response(array('error' => 'Not connected'), 401);
		}

		$slug  = $request->get_param('slug');
		$url = trailingslashit($this->api_url) . "cms/articles/{$slug}";

		$response = wp_remote_get($url, array(
			'timeout' => 15,
			'headers' => array('X-Plugin-Key' => $api_key),
		));

		if (is_wp_error($response)) {
			return new WP_REST_Response(array('error' => $response->get_error_message()), 500);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		$code = wp_remote_retrieve_response_code($response);

		if ($body === null) {
			return new WP_REST_Response(array('error' => 'Invalid response from SnowSEO API'), 502);
		}

		return new WP_REST_Response($body, $code);
	}

	/**
	 * Handle POST /publish - fetch article from SnowSEO API and create WP post locally.
	 * Called from the WP plugin admin UI.
	 */
	public function handle_publish($request)
	{
		$api_key = get_option(self::OPTION_API_KEY, '');
		if (empty($api_key)) {
			return new WP_REST_Response(array('error' => 'Not connected'), 401);
		}

		$article_slug   = $request->get_param('articleSlug');
		$publish_status = $request->get_param('status');

		// Proxy the publish command to SnowSEO API
		// The SnowSEO API will convert markdown to HTML and push it back via /receive-publish
		$url = trailingslashit($this->api_url) . 'cms/publish';

		$response = wp_remote_post($url, array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-Plugin-Key' => $api_key,
			),
			'body' => wp_json_encode(array(
				'articleSlug' => $article_slug,
				'provider'    => 'wordpress',
				'status'      => $publish_status,
			)),
		));

		if (is_wp_error($response)) {
			return new WP_REST_Response(array(
				'success' => false,
				'error' => $response->get_error_message()
			), 500);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		$code = wp_remote_retrieve_response_code($response);

		if ($body === null) {
			return new WP_REST_Response(array('error' => 'Invalid response from SnowSEO API'), 502);
		}

		return new WP_REST_Response($body, $code);
	}

	/**
	 * Handle GET /ping request.
	 * Lightweight health check for the SnowSEO server to verify
	 * that inbound requests reach the plugin without being blocked by a WAF.
	 */
	public function handle_ping($request)
	{
		return new WP_REST_Response(array(
			'pong'          => true,
			'pluginVersion' => SNOWSEO_VERSION,
		), 200);
	}

	/**
	 * Resolve taxonomy term NAMES coming from SnowSEO into local term IDs,
	 * creating any that don't exist yet (same behaviour as the direct
	 * Application Password publish path, which creates missing terms over REST).
	 *
	 * Uses wp_insert_term() for the lookup rather than get_term_by('name', ...):
	 * core stores term names HTML-escaped and expects an already-escaped value
	 * for name lookups, so matching by hand is fragile. wp_insert_term() does the
	 * normalisation itself and hands back the existing id via the `term_exists`
	 * error when the term is already there.
	 *
	 * @param array  $names    Term names.
	 * @param string $taxonomy 'category' or 'post_tag'.
	 * @return int[] Unique term IDs.
	 */
	private function resolve_term_ids($names, $taxonomy)
	{
		$ids = array();
		if (! is_array($names)) {
			return $ids;
		}

		// Bound the work: a publish payload should never carry more than a
		// handful of terms, and each miss costs an INSERT.
		$max_terms = 20;
		$seen      = 0;

		foreach ($names as $raw_name) {
			if ($seen >= $max_terms) {
				break;
			}
			if (! is_string($raw_name)) {
				continue;
			}
			$name = sanitize_text_field(wp_strip_all_tags($raw_name));
			if ('' === $name) {
				continue;
			}
			$seen++;

			$term = wp_insert_term($name, $taxonomy);
			if (is_wp_error($term)) {
				$existing = $term->get_error_data();
				if ('term_exists' === $term->get_error_code() && is_numeric($existing)) {
					$ids[] = (int) $existing;
				}
				continue;
			}
			if (isset($term['term_id'])) {
				$ids[] = (int) $term['term_id'];
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Handle POST /receive-publish request.
	 * Receives article content from SnowSEO server and publishes it locally.
	 * Authenticated via X-Plugin-Key header (not WordPress nonce).
	 */
	public function handle_receive_publish($request)
	{
		$title                  = $request->get_param('title');
		$content                = $request->get_param('content');
		$status                 = $request->get_param('status');
		$excerpt                = $request->get_param('excerpt');
		$date                   = $request->get_param('date');
		$featured_image         = $request->get_param('featuredImage') ?: $request->get_param('featured_image_url');
		$featured_image_caption = $request->get_param('featuredImageCaption') ?: $request->get_param('featured_image_caption');
		$type                   = $request->get_param('type');
		$seo_score              = $request->get_param('seoScore');
		$article_id             = $request->get_param('articleId');
		$meta                   = $request->get_param('meta');
		$categories             = $request->get_param('categories');
		$tags                   = $request->get_param('tags');

		if (empty($title) || empty($content)) {
			return new WP_REST_Response(array(
				'success' => false,
				'error'   => 'Title and content are required.',
			), 400);
		}

		// Guard against excessively large payloads to prevent memory exhaustion.
		$max_title_length = 500;
		$max_content_length = 2000000; // 2MB
		if (mb_strlen($title) > $max_title_length) {
			return new WP_REST_Response(array(
				'success' => false,
				'error'   => 'Title exceeds maximum allowed length of ' . $max_title_length . ' characters.',
			), 400);
		}
		if (mb_strlen($content) > $max_content_length) {
			return new WP_REST_Response(array(
				'success' => false,
				'error'   => 'Content exceeds maximum allowed size.',
			), 400);
		}

		$wp_status_map = array(
			'publish'   => 'publish',
			'draft'     => 'draft',
			'scheduled' => 'future',
			'private'   => 'private',
		);
		$wp_status = isset($wp_status_map[$status]) ? $wp_status_map[$status] : 'draft';

		// Determine post author. When called via X-Plugin-Key (server-to-server),
		// get_current_user_id() returns 0 - fall back to the first active admin user.
		$current_user_id = get_current_user_id();
		$post_author     = $current_user_id > 0 ? $current_user_id : $this->get_default_post_author();

		$post_data = array(
			'post_title'   => sanitize_text_field($title),
			'post_content' => $this->strip_disallowed_iframes(wp_kses($content, $this->allowed_html)),
			'post_status'  => $wp_status,
			'post_type'    => 'post',
			'post_author'  => $post_author,
		);

		if (! empty($excerpt)) {
			$post_data['post_excerpt'] = sanitize_text_field($excerpt);
		}

		// Validate date format before using it for scheduled posts.
		if ('future' === $wp_status && ! empty($date)) {
			$timestamp = strtotime($date);
			if ($timestamp) {
				$wordpress_date = date_i18n('Y-m-d H:i:s', $timestamp);
				$post_data['post_date']     = $wordpress_date;
				$post_data['post_date_gmt'] = get_gmt_from_date($wordpress_date);
			}
		}

		// WordPress re-runs wp_kses_post() on post_content during wp_insert_post /
		// wp_update_post when the request lacks the unfiltered_html capability (this
		// endpoint authenticates by site token, not a WP user), which would strip the
		// already-vetted, host-restricted iframes. Allow our iframe tag during the writes below.
		add_filter('wp_kses_allowed_html', array($this, 'allow_iframe_in_post_kses'), 10, 2);

		// Deduplicate: if this articleId was already published, update the existing post instead of creating a duplicate.
		// Mapping a SnowSEO article ID back to its post can only be done through
		// post meta. The lookup runs at most once per publish request, never on a
		// front-end page load, and is capped to a single ID.
		if (! empty($article_id)) {
			$existing_post = get_posts(array(
				'post_type'      => 'post',
				'post_status'    => array('publish', 'draft', 'future', 'private'),
				'meta_key'       => '_snowseo_article_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded server-to-server lookup, see note above.
				'meta_value'     => $article_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded server-to-server lookup, see note above.
				'posts_per_page' => 1,
				'fields'         => 'ids',
			));
			if (! empty($existing_post)) {
				$post_id = (int) $existing_post[0];
				$post_data['ID'] = $post_id;
				$result = wp_update_post($post_data, true);
				if (is_wp_error($result)) {
					remove_filter('wp_kses_allowed_html', array($this, 'allow_iframe_in_post_kses'), 10);
					$this->add_log_entry('error', 'Failed to update existing article: ' . $result->get_error_message());
					return new WP_REST_Response(array(
						'success' => false,
						'error'   => $result->get_error_message(),
					), 500);
				}
				$post_id = $result;
			} else {
				$post_id = wp_insert_post($post_data, true);
			}
		} else {
			$post_id = wp_insert_post($post_data, true);
		}

		if (is_wp_error($post_id)) {
			remove_filter('wp_kses_allowed_html', array($this, 'allow_iframe_in_post_kses'), 10);
			$this->add_log_entry('error', 'Failed to publish article: ' . $post_id->get_error_message());
			return new WP_REST_Response(array(
				'success' => false,
				'error'   => $post_id->get_error_message(),
			), 500);
		}

		// Apply categories / tags. Only touched when SnowSEO actually sent terms —
		// an empty payload must not strip the terms an editor set locally, and on
		// republish a non-empty payload REPLACES (not appends) so terms removed in
		// SnowSEO are removed here too.
		if (! empty($categories)) {
			$category_ids = $this->resolve_term_ids($categories, 'category');
			if (! empty($category_ids)) {
				wp_set_post_terms($post_id, $category_ids, 'category', false);
			}
		}
		if (! empty($tags)) {
			$tag_ids = $this->resolve_term_ids($tags, 'post_tag');
			if (! empty($tag_ids)) {
				wp_set_post_terms($post_id, $tag_ids, 'post_tag', false);
			}
		}

		if (! empty($featured_image)) {
			$desc     = ! empty($featured_image_caption) ? sanitize_text_field($featured_image_caption) : $title;
			$image_id = $this->import_remote_image($featured_image, $post_id, $desc);

			if (! is_wp_error($image_id)) {
				set_post_thumbnail($post_id, $image_id);
			} else {
				$this->add_log_entry('error', 'Failed to sideload featured image: ' . $image_id->get_error_message());
			}
		}

		// Rehost inline content images into the media library so the published
		// post no longer hotlinks to external hosts. Runs after the post exists
		// so imported images can be attached to it.
		$localized_content = $this->sideload_content_images($post_data['post_content'], $post_id);
		if ($localized_content !== $post_data['post_content']) {
			wp_update_post(array(
				'ID'           => $post_id,
				'post_content' => $localized_content,
			));
		}

		remove_filter('wp_kses_allowed_html', array($this, 'allow_iframe_in_post_kses'), 10);

		if (! empty($meta) && is_array($meta)) {
			$allowed_keys = array('metaTitle', 'metaDescription', 'ogTitle', 'ogDescription', 'twitterTitle', 'twitterDescription', 'canonicalUrl');
			$clean_meta   = array();
			foreach ($allowed_keys as $key) {
				if (isset($meta[$key]) && is_string($meta[$key]) && $meta[$key] !== '') {
					$clean_meta[$key] = sanitize_text_field($meta[$key]);
					update_post_meta($post_id, '_snowseo_meta_' . $key, $clean_meta[$key]);
				}
			}

			// Mirror the generated SEO fields into the active SEO plugin's own
			// post meta so they render through it. We suppress our own <head>
			// output whenever an SEO plugin is active, so this is what makes the
			// SnowSEO metadata actually appear on Yoast / Rank Math / SEOPress sites.
			$this->sync_seo_plugin_meta($post_id, $clean_meta);
		}

		// Store articleId as post meta so future receive-publish calls for the
		// same article update this post instead of creating a duplicate.
		if (! empty($article_id)) {
			update_post_meta($post_id, '_snowseo_article_id', sanitize_text_field($article_id));
		}

		$post_url = get_permalink($post_id);

		$this->add_log_entry('published', 'Article published from SnowSEO', array(
			'postId'    => $post_id,
			'articleId' => $article_id,
			'url'       => $post_url,
			'title'     => $title,
			'type'      => $type,
			'seoScore'  => $seo_score,
		));

		return new WP_REST_Response(array(
			'success' => true,
			'data'    => array(
				'postId' => $post_id,
				'url'    => $post_url,
				'status' => $wp_status,
			),
		), 200);
	}

	/**
	 * Handle GET /settings - proxy to SnowSEO API.
	 */
	public function handle_settings($request)
	{
		$api_key = get_option(self::OPTION_API_KEY, '');
		if (empty($api_key)) {
			return new WP_REST_Response(array('error' => 'Not connected'), 401);
		}

		$url = trailingslashit($this->api_url) . 'integrations/wordpress/settings';

		$response = wp_remote_get($url, array(
			'timeout' => 15,
			'headers' => array('X-Plugin-Key' => $api_key),
		));

		if (is_wp_error($response)) {
			return new WP_REST_Response(array('error' => $response->get_error_message()), 500);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		$code = wp_remote_retrieve_response_code($response);

		if ($body === null) {
			return new WP_REST_Response(array('error' => 'Invalid response from SnowSEO API'), 502);
		}

		return new WP_REST_Response($body, $code);
	}

	/**
	 * Handle GET /posts-status request.
	 * Returns the status, date, and link for a list of post IDs.
	 * Allowed via X-Plugin-Key header.
	 */
	public function handle_posts_status($request)
	{
		$ids_str = $request->get_param('ids');
		$ids     = ! empty($ids_str) ? array_map('intval', explode(',', $ids_str)) : array();

		$args = array(
			'post_type'      => 'post',
			'post_status'    => array('publish', 'future', 'draft', 'pending', 'private'),
			'posts_per_page' => 100,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if (! empty($ids)) {
			$args['post__in'] = $ids;
		}

		$query  = new WP_Query($args);
		$result = array();

		foreach ($query->posts as $post) {
			$date = null;
			if ($post->post_date_gmt && $post->post_date_gmt !== '0000-00-00 00:00:00') {
				$date = $post->post_date_gmt . 'Z';
			} elseif ($post->post_date && $post->post_date !== '0000-00-00 00:00:00') {
				$date = get_gmt_from_date($post->post_date) . 'Z';
			}

			$result[$post->ID] = array(
				'status' => $post->post_status,
				'date'   => $date,
				'link'   => get_permalink($post->ID),
			);
		}

		wp_reset_postdata();

		return new WP_REST_Response(array('success' => true, 'data' => $result), 200);
	}

	/**
	 * Handle GET /logs/stats - return computed stats from local logs.
	 */
	public function handle_log_stats($request)
	{
		$all_logs = get_option(self::OPTION_LOGS, array());
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
	private function validate_key_with_server($api_key, $connect = true)
	{
		$url = trailingslashit($this->api_url) . 'integrations/wordpress/validate-plugin-key';

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
	 * Add an entry to the activity log.
	 */
	public function add_log_entry($status, $message, $details = array())
	{
		$logs = get_option(self::OPTION_LOGS, array());

		$entry = array(
			'id'      => wp_generate_uuid4(),
			'status'  => $status,
			'message' => $message,
			'date'    => gmdate('c'),
			'details' => $details,
		);

		// Prepend new entry (newest first)
		array_unshift($logs, $entry);

		// Cap at 100 entries
		$logs = array_slice($logs, 0, 100);

		update_option(self::OPTION_LOGS, $logs, false); // Don't autoload - avoid loading logs on every page request.

		return $entry;
	}

	/**
	 * Check if the plugin is currently connected.
	 */
	public function is_connected()
	{
		$api_key    = get_option(self::OPTION_API_KEY, '');
		$connection = get_option(self::OPTION_CONNECTION, array());
		return ! empty($api_key) && ! empty($connection['connected']);
	}

	/**
	 * Sanitize the content parameter for receive-publish.
	 * Applies wp_kses with allowed HTML tags to prevent XSS.
	 */
	public function sanitize_content_param($raw)
	{
		if (! is_string($raw)) {
			return '';
		}
		return $this->strip_disallowed_iframes(wp_kses($raw, $this->allowed_html));
	}

	/**
	 * Remove any <iframe> whose src host is not on the trusted embed allowlist.
	 * Runs after wp_kses (which permits the iframe tag but cannot whitelist by
	 * host), keeping legitimate video embeds while blocking arbitrary iframes.
	 */
	private function strip_disallowed_iframes($html)
	{
		if (! is_string($html) || false === strpos($html, '<iframe')) {
			return (string) $html;
		}

		$allowed_hosts = array(
			'www.youtube.com',
			'youtube.com',
			'www.youtube-nocookie.com',
			'youtube-nocookie.com',
			'player.vimeo.com',
		);

		return preg_replace_callback('#<iframe\b[^>]*>.*?</iframe>#is', function ($match) use ($allowed_hosts) {
			if (preg_match('/(?<![\w-])src\s*=\s*([\'"])(.*?)\1/i', $match[0], $src)) {
				$host = wp_parse_url(html_entity_decode($src[2], ENT_QUOTES), PHP_URL_HOST);
				$host = is_string($host) ? strtolower($host) : '';
				if (in_array($host, $allowed_hosts, true)) {
					return $match[0];
				}
			}
			return '';
		}, $html);
	}

	/**
	 * Allow our trusted iframe tag in WordPress's 'post' kses pass. Used as a
	 * temporary wp_kses_allowed_html filter around wp_insert_post / wp_update_post
	 * so the host-restricted embeds we already vetted survive persistence even
	 * though this endpoint runs without the unfiltered_html capability.
	 */
	public function allow_iframe_in_post_kses($tags, $context)
	{
		if ('post' === $context && isset($this->allowed_html['iframe'])) {
			$tags['iframe'] = $this->allowed_html['iframe'];
		}
		return $tags;
	}

	/**
	 * Get a valid post author ID for server-to-server publish calls.
	 * When no user is logged in (X-Plugin-Key auth), falls back to the
	 * first active administrator, or 1 as a last resort.
	 */
	private function get_default_post_author()
	{
		$authors = get_users(array(
			'role'    => 'administrator',
			'orderby' => 'ID',
			'number'  => 1,
		));
		if (! empty($authors) && ! is_wp_error($authors) && isset($authors[0]->ID)) {
			return (int) $authors[0]->ID;
		}
		return 1; // Fallback to first user (standard WP admin).
	}

	/**
	 * Download a remote image into the media library and return its attachment
	 * ID (or WP_Error). Reuses a prior import of the same source URL so that
	 * re-publishing an article does not create duplicate attachments, derives a
	 * file extension from the content type when the URL lacks one, and sets the
	 * attachment author to match the post for consistent attribution.
	 */
	private function import_remote_image($url, $post_id, $desc = '')
	{
		if (! function_exists('media_handle_sideload')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// SSRF protection: only fetch URLs WordPress considers safe.
		if (! wp_http_validate_url($url)) {
			return new WP_Error('invalid_image_url', 'Unsafe image URL: ' . $url);
		}

		// Reuse a previously imported copy of the same source URL. The attachment
		// meta is the only record of where an imported image came from; this runs
		// during an authenticated publish, never on a front-end page load, and is
		// capped to a single ID.
		$existing = get_posts(array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_key'       => '_snowseo_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded server-to-server lookup, see note above.
			'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded server-to-server lookup, see note above.
			'posts_per_page' => 1,
			'fields'         => 'ids',
		));
		if (! empty($existing)) {
			return (int) $existing[0];
		}

		$tmp = download_url($url, 20);
		if (is_wp_error($tmp)) {
			return $tmp;
		}

		// Build a filename with a valid image extension, deriving one from the
		// content type when the URL path has none (common with CDN URLs).
		$path     = wp_parse_url($url, PHP_URL_PATH);
		$filename = $path ? basename($path) : '';
		$type     = wp_check_filetype($filename);

		if (empty($type['ext'])) {
			$mime    = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp) : '';
			$ext_map = array(
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
				'image/avif' => 'avif',
			);
			if ($mime && isset($ext_map[$mime])) {
				$base     = '' !== $filename ? preg_replace('/\.[^.]*$/', '', $filename) : '';
				$base     = '' !== $base ? sanitize_file_name($base) : 'snowseo-image';
				$filename = $base . '.' . $ext_map[$mime];
			} else {
				if (file_exists($tmp)) {
					wp_delete_file($tmp);
				}
				return new WP_Error('bad_image_type', 'Unrecognized image type for URL: ' . $url);
			}
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload($file_array, $post_id, $desc);

		if (is_wp_error($attachment_id)) {
			if (file_exists($tmp)) {
				wp_delete_file($tmp);
			}
			return $attachment_id;
		}

		// Record the source URL so future publishes reuse this attachment.
		update_post_meta($attachment_id, '_snowseo_source_url', $url);

		// Inherit the post author for consistent attribution.
		$parent = get_post($post_id);
		if ($parent && (int) $parent->post_author > 0) {
			wp_update_post(array(
				'ID'          => $attachment_id,
				'post_author' => (int) $parent->post_author,
			));
		}

		return (int) $attachment_id;
	}

	/**
	 * Import external <img> sources in post content into the media library and
	 * rewrite their URLs to the local copies. Skips data URIs and images already
	 * hosted on this site. Bounded by a hard cap so one publish cannot trigger
	 * an unbounded number of downloads.
	 */
	private function sideload_content_images($content, $post_id)
	{
		if (! is_string($content) || false === strpos($content, '<img')) {
			return $content;
		}

		if (! preg_match_all('/<img\b[^>]*?(?<![\w-])src\s*=\s*([\'"])(.*?)\1[^>]*>/i', $content, $matches)) {
			return $content;
		}

		$site_host   = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
		$upload_dir  = wp_get_upload_dir();
		$upload_host = strtolower((string) wp_parse_url($upload_dir['baseurl'], PHP_URL_HOST));

		$replacements = array();
		$max_images   = 15;
		$imported     = 0;

		foreach (array_unique($matches[2]) as $raw_src) {
			if ($imported >= $max_images) {
				$this->add_log_entry('error', 'Reached the inline-image import cap; remaining images kept as external links.');
				break;
			}

			$src = html_entity_decode($raw_src, ENT_QUOTES);

			if (0 === stripos($src, 'data:')) {
				continue;
			}

			$src_host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
			if ('' === $src_host || $src_host === $site_host || $src_host === $upload_host) {
				continue;
			}

			$attachment_id = $this->import_remote_image($src, $post_id);
			if (is_wp_error($attachment_id)) {
				$this->add_log_entry('error', 'Failed to import inline image: ' . $attachment_id->get_error_message());
				continue;
			}

			$local_url = wp_get_attachment_url($attachment_id);
			if ($local_url) {
				$replacements[$raw_src] = $local_url;
				$imported++;
			}
		}

		if (empty($replacements)) {
			return $content;
		}

		foreach ($replacements as $from => $to) {
			$content = str_replace('"' . $from . '"', '"' . $to . '"', $content);
			$content = str_replace("'" . $from . "'", "'" . $to . "'", $content);
		}

		return $content;
	}

	/**
	 * Mirror SnowSEO's generated SEO fields into the active SEO plugin's native
	 * post meta (Yoast, Rank Math, SEOPress) so the values render through that
	 * plugin and stay editable there. No-op when none is active, in which case
	 * the plugin emits its own tags via snowseo_output_seo_meta().
	 *
	 * All in One SEO and The SEO Framework keep post SEO in their own database
	 * tables / formats rather than simple post meta, so they are not mirrored
	 * here; on those sites the generated SEO is not applied.
	 */
	private function sync_seo_plugin_meta($post_id, $meta)
	{
		if (empty($meta) || ! is_array($meta)) {
			return;
		}

		$title       = isset($meta['metaTitle']) ? $meta['metaTitle'] : '';
		$description = isset($meta['metaDescription']) ? $meta['metaDescription'] : '';
		$og_title    = isset($meta['ogTitle']) ? $meta['ogTitle'] : '';
		$og_desc     = isset($meta['ogDescription']) ? $meta['ogDescription'] : '';
		$tw_title    = isset($meta['twitterTitle']) ? $meta['twitterTitle'] : '';
		$tw_desc     = isset($meta['twitterDescription']) ? $meta['twitterDescription'] : '';
		$canonical   = isset($meta['canonicalUrl']) ? $meta['canonicalUrl'] : '';

		$set = function ($key, $value) use ($post_id) {
			if ('' !== $value) {
				update_post_meta($post_id, $key, $value);
			}
		};

		// Yoast SEO.
		if (defined('WPSEO_VERSION')) {
			$set('_yoast_wpseo_title', $title);
			$set('_yoast_wpseo_metadesc', $description);
			$set('_yoast_wpseo_opengraph-title', $og_title);
			$set('_yoast_wpseo_opengraph-description', $og_desc);
			$set('_yoast_wpseo_twitter-title', $tw_title);
			$set('_yoast_wpseo_twitter-description', $tw_desc);
			$set('_yoast_wpseo_canonical', $canonical);
		}

		// Rank Math.
		if (defined('RANK_MATH_VERSION')) {
			$set('rank_math_title', $title);
			$set('rank_math_description', $description);
			$set('rank_math_facebook_title', $og_title);
			$set('rank_math_facebook_description', $og_desc);
			$set('rank_math_twitter_title', $tw_title);
			$set('rank_math_twitter_description', $tw_desc);
			$set('rank_math_canonical_url', $canonical);
		}

		// SEOPress.
		if (defined('SEOPRESS_VERSION')) {
			$set('_seopress_titles_title', $title);
			$set('_seopress_titles_desc', $description);
			$set('_seopress_social_fb_title', $og_title);
			$set('_seopress_social_fb_desc', $og_desc);
			$set('_seopress_social_twitter_title', $tw_title);
			$set('_seopress_social_twitter_desc', $tw_desc);
			$set('_seopress_robots_canonical', $canonical);
		}
	}
}
