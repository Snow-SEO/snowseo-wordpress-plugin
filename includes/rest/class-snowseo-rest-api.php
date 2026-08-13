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
	 * Option keys used for storage.
	 */
	const OPTION_API_KEY    = 'snowseo_api_key';
	const OPTION_CONNECTION = 'snowseo_connection';

	/**
	 * SnowSEO API base URL, from SNOWSEO_API_URL with a 'snowseo_api_url' filter.
	 *
	 * Static because every class that talks to the backend needs it and none of
	 * them should have to build a REST controller to read it - it is a filtered
	 * constant, not per-instance state.
	 */
	public static function api_url()
	{
		return apply_filters('snowseo_api_url', SNOWSEO_API_URL);
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes()
	{
		$namespace = 'snowseo/v1';

		// One publisher instance shared by every route it owns. Its kses filter is
		// hooked and unhooked by callback identity, so all of those routes must
		// resolve to the SAME object or a remove_filter silently no-ops.
		$publish = new SnowSEO_Rest_Publish();

		// POST /connect - validate API key against SnowSEO server
		register_rest_route($namespace, '/connect', array(
			'methods'             => 'POST',
			'callback'            => array('SnowSEO_Rest_Connection', 'handle_connect'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
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
			'callback'            => array('SnowSEO_Rest_Connection', 'handle_disconnect'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
		));

		// GET /status - return connection status
		register_rest_route($namespace, '/status', array(
			'methods'             => 'GET',
			'callback'            => array('SnowSEO_Rest_Connection', 'handle_status'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
		));

		// GET /logs - return activity logs
		register_rest_route($namespace, '/logs', array(
			'methods'             => 'GET',
			'callback'            => array('SnowSEO_Rest_Connection', 'handle_logs'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
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
			'callback'            => array($publish, 'handle_articles'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
			'args'                => array(
				'page'     => array('default' => 1, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				'per_page' => array('default' => 20, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				'status'   => array('default' => 'all', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
			),
		));

		// GET /articles/(?P<slug>[a-zA-Z0-9-]+) - proxy single article
		register_rest_route($namespace, '/articles/(?P<slug>[a-zA-Z0-9-]+)', array(
			'methods'             => 'GET',
			'callback'            => array($publish, 'handle_article_single'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
		));

		// POST /publish - proxy publish to SnowSEO API
		register_rest_route($namespace, '/publish', array(
			'methods'             => 'POST',
			'callback'            => array($publish, 'handle_publish'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
			'args'                => array(
				'articleSlug' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'status'      => array('default' => 'publish', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
			),
		));

		// GET /settings - proxy settings from SnowSEO API
		register_rest_route($namespace, '/settings', array(
			'methods'             => 'GET',
			'callback'            => array($publish, 'handle_settings'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
		));

		// GET /posts-status - check status of multiple posts
		register_rest_route($namespace, '/posts-status', array(
			'methods'             => 'GET',
			'callback'            => array($publish, 'handle_posts_status'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_plugin_key_permission'),
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
			'callback'            => array('SnowSEO_Rest_Connection', 'handle_log_stats'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
		));

		// GET /ping - health check endpoint for SnowSEO server to verify inbound connectivity.
		// Public: no auth required. The key hasn't been stored locally yet during the initial
		// connection handshake, so requiring check_plugin_key_permission would create a
		// chicken-and-egg failure.
		register_rest_route($namespace, '/ping', array(
			'methods'             => 'GET',
			'callback'            => array('SnowSEO_Rest_Connection', 'handle_ping'),
			'permission_callback' => '__return_true',
		));

		// POST /receive-publish - receive article content from SnowSEO server and publish locally
		register_rest_route($namespace, '/receive-publish', array(
			'methods'             => 'POST',
			'callback'            => array($publish, 'handle_receive_publish'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_plugin_key_permission'),
			'args'                => array(
				'title'                  => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'content'                => array('required' => true, 'type' => 'string', 'sanitize_callback' => array($publish, 'sanitize_content_param')),
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
				'meta'                   => array('default' => array(), 'type' => 'object', 'sanitize_callback' => array($publish, 'sanitize_meta_param')),
				'categories'             => array('default' => array(), 'type' => 'array', 'items' => array('type' => 'string')),
				'tags'                   => array('default' => array(), 'type' => 'array', 'items' => array('type' => 'string')),
			),
		));

		// POST /invalidate - called by SnowSEO server when the API key is regenerated.
		// Authenticated with the outgoing key in X-Plugin-Key; teamId is optional
		// and only used as a secondary check that the call targets this connection.
		register_rest_route($namespace, '/invalidate', array(
			'methods'             => 'POST',
			'callback'            => array('SnowSEO_Rest_Connection', 'handle_invalidate'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_invalidation_permission'),
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
			'callback'            => array($publish, 'handle_delete_post'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_plugin_key_permission'),
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
			'callback'            => array($publish, 'handle_get_post_by_url'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_plugin_key_permission'),
			'args'                => array(
				'url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		));

		// POST /media/alt - set alt text on media library attachments by URL.
		// Needed because featured images, theme-rendered images and page-builder
		// images are NOT in post_content: WordPress renders their alt from the
		// attachment's _wp_attachment_image_alt meta, so rewriting the post body
		// can never fix them.
		register_rest_route($namespace, '/media/alt', array(
			'methods'             => 'POST',
			'callback'            => array('SnowSEO_Media', 'handle_update_media_alt'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_plugin_key_permission'),
			'args'                => array(
				'items' => array(
					'required' => true,
					'type'     => 'array',
				),
			),
		));

		// POST /posts/(?P<id>\d+)/update - update specific fields and metadata on a post
		register_rest_route($namespace, '/posts/(?P<id>\d+)/update', array(
			'methods'             => 'POST',
			'callback'            => array($publish, 'handle_update_post'),
			'permission_callback' => array('SnowSEO_Rest_Auth', 'check_plugin_key_permission'),
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
					'sanitize_callback' => array($publish, 'sanitize_content_param'),
				),
				'meta' => array(
					'required'          => false,
					'type'              => 'object',
					'sanitize_callback' => array($publish, 'sanitize_meta_param'),
				),
			),
		));
	}

}
