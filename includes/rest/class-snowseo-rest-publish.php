<?php
/**
 * Publishing endpoints and the HTML safety layer they depend on.
 *
 * Everything that writes to this site's posts: receiving an article from
 * SnowSEO, updating or deleting one, proxying the article/publish/settings
 * calls, and resolving taxonomy terms.
 *
 * An instance class, unlike the rest of the REST split. `allowed_html` is real
 * per-instance state, and the kses group hooks `allow_iframe_in_post_kses` with
 * add_filter/remove_filter around each write - remove_filter only unhooks when
 * the callback identity matches exactly, so the sanitizer has to live on the
 * same object as the handlers that pair those calls. Splitting them apart is
 * how you get a filter that silently never unhooks.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Rest_Publish
{
	/**
	 * SEO fields SnowSEO may write to a post. Any other key in a `meta` payload
	 * is ignored. See sanitize_seo_meta() for the per-field sanitization.
	 */
	const SEO_META_KEYS = array(
		'metaTitle',
		'metaDescription',
		'ogTitle',
		'ogDescription',
		'twitterTitle',
		'twitterDescription',
		'canonicalUrl',
	);

	/**
	 * Allowed HTML tags for post content from SnowSEO server.
	 * Extends wp_kses_post defaults with HTML5 semantic tags.
	 */
	private $allowed_html;

	/**
	 * Constructor. Initializes the allowed HTML tags for post content filtering.
	 */
	public function __construct()
	{
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
			SnowSEO_Log::write('error', "Failed to delete post ID {$post_id}");
			return new WP_Error('delete_failed', 'Failed to delete post', array('status' => 500));
		}

		SnowSEO_Log::write('success', "Deleted post ID {$post_id} remotely");
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
			$loss = $this->guard_content_loss($content);
			if (is_wp_error($loss)) {
				remove_filter('wp_kses_allowed_html', array($this, 'allow_iframe_in_post_kses'), 10);
				return $loss;
			}
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

		// Empty strings are kept here so SnowSEO can clear a field it previously set.
		$clean_meta = $this->sanitize_seo_meta($request->get_param('meta'), true);
		foreach ($clean_meta as $key => $value) {
			update_post_meta($post_id, '_snowseo_meta_' . $key, $value);
		}
		$this->sync_seo_plugin_meta($post_id, $clean_meta);

		SnowSEO_Log::write('success', "Updated post ID {$post_id} remotely");
		return new WP_REST_Response(array(
			'success' => true,
			'message' => 'Post updated successfully',
		), 200);
	}

	/**
	 * Handle GET /articles - proxy to SnowSEO API.
	 */
	public function handle_articles($request)
	{
		$api_key = get_option(SnowSEO_Rest_API::OPTION_API_KEY, '');
		if (empty($api_key)) {
			return new WP_REST_Response(array('error' => 'Not connected'), 401);
		}

		$page     = $request->get_param('page');
		$per_page = $request->get_param('per_page');
		$status   = $request->get_param('status');

		$url = trailingslashit(SnowSEO_Rest_API::api_url()) . "cms/articles?page={$page}&per_page={$per_page}&status={$status}";

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
		$api_key = get_option(SnowSEO_Rest_API::OPTION_API_KEY, '');
		if (empty($api_key)) {
			return new WP_REST_Response(array('error' => 'Not connected'), 401);
		}

		$slug  = $request->get_param('slug');
		$url = trailingslashit(SnowSEO_Rest_API::api_url()) . "cms/articles/{$slug}";

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
		$api_key = get_option(SnowSEO_Rest_API::OPTION_API_KEY, '');
		if (empty($api_key)) {
			return new WP_REST_Response(array('error' => 'Not connected'), 401);
		}

		$article_slug   = $request->get_param('articleSlug');
		$publish_status = $request->get_param('status');

		// Proxy the publish command to SnowSEO API
		// The SnowSEO API will convert markdown to HTML and push it back via /receive-publish
		$url = trailingslashit(SnowSEO_Rest_API::api_url()) . 'cms/publish';

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
		$post_author     = $current_user_id > 0 ? $current_user_id : SnowSEO_Media::default_post_author();

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
					SnowSEO_Log::write('error', 'Failed to update existing article: ' . $result->get_error_message());
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
			SnowSEO_Log::write('error', 'Failed to publish article: ' . $post_id->get_error_message());
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
			$image_id = SnowSEO_Media::import_remote_image($featured_image, $post_id, $desc);

			if (! is_wp_error($image_id)) {
				set_post_thumbnail($post_id, $image_id);
			} else {
				SnowSEO_Log::write('error', 'Failed to sideload featured image: ' . $image_id->get_error_message());
			}
		}

		// Rehost inline content images into the media library so the published
		// post no longer hotlinks to external hosts. Runs after the post exists
		// so imported images can be attached to it.
		$localized_content = SnowSEO_Media::sideload_content_images($post_data['post_content'], $post_id);
		if ($localized_content !== $post_data['post_content']) {
			wp_update_post(array(
				'ID'           => $post_id,
				'post_content' => $localized_content,
			));
		}

		remove_filter('wp_kses_allowed_html', array($this, 'allow_iframe_in_post_kses'), 10);

		$clean_meta = $this->sanitize_seo_meta($meta);
		foreach ($clean_meta as $key => $value) {
			update_post_meta($post_id, '_snowseo_meta_' . $key, $value);
		}

		// Mirror the generated SEO fields into the active SEO plugin's own
		// post meta so they render through it. We suppress our own <head>
		// output whenever an SEO plugin is active, so this is what makes the
		// SnowSEO metadata actually appear on Yoast / Rank Math / SEOPress sites.
		$this->sync_seo_plugin_meta($post_id, $clean_meta);

		// Store articleId as post meta so future receive-publish calls for the
		// same article update this post instead of creating a duplicate.
		if (! empty($article_id)) {
			update_post_meta($post_id, '_snowseo_article_id', sanitize_text_field($article_id));
		}

		$post_url = get_permalink($post_id);

		SnowSEO_Log::write('published', 'Article published from SnowSEO', array(
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
		$api_key = get_option(SnowSEO_Rest_API::OPTION_API_KEY, '');
		if (empty($api_key)) {
			return new WP_REST_Response(array('error' => 'Not connected'), 401);
		}

		$url = trailingslashit(SnowSEO_Rest_API::api_url()) . 'integrations/wordpress/settings';

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
	 * Tags the sanitizer would delete from this content, if any.
	 *
	 * Every write runs the WHOLE post body through wp_kses, not just the part
	 * SnowSEO changed. That is correct for safety and destructive for content:
	 * an inline <svg>, a contact <form>, or a non-video <iframe> is simply gone
	 * afterwards, and the owner finds out when a visitor tells them.
	 *
	 * The check compares tag counts before and after rather than guessing at
	 * core's allowlist, because that list changes between WordPress versions and
	 * a hardcoded copy of it would be wrong on exactly the sites it matters for.
	 *
	 * @param string $raw
	 * @return string[] Distinct tag names that would be lost.
	 */
	private function tags_kses_would_remove($raw)
	{
		if (! is_string($raw) || '' === $raw) {
			return array();
		}
		if (! preg_match_all('/<\s*([a-zA-Z][a-zA-Z0-9-]*)\b/', $raw, $matches)) {
			return array();
		}

		$before = array_count_values(array_map('strtolower', $matches[1]));

		// BOTH passes, because both really run. wp_update_post() puts the body
		// through content_save_pre -> wp_filter_post_kses, whose allowlist is
		// core's own and narrower than this plugin's - so measuring only the
		// first pass would miss every tag core deletes on the way to the
		// database, which is most of the ones worth warning about.
		$clean = $this->strip_disallowed_iframes(wp_kses($raw, $this->allowed_html));
		$clean = wp_kses($clean, 'post');
		$after = array();
		if (preg_match_all('/<\s*([a-zA-Z][a-zA-Z0-9-]*)\b/', $clean, $clean_matches)) {
			$after = array_count_values(array_map('strtolower', $clean_matches[1]));
		}

		$lost = array();
		foreach ($before as $tag => $count) {
			$kept = isset($after[$tag]) ? (int) $after[$tag] : 0;
			if ($kept < $count) {
				$lost[] = $tag;
			}
		}

		return $lost;
	}

	/**
	 * Refuse a write that would destroy part of the post.
	 *
	 * Deliberately checks the content SnowSEO is about to store rather than what
	 * is already on the site: if the two disagree the incoming version is the one
	 * that gets saved, so it is the one that has to survive sanitizing.
	 *
	 * @param string $raw
	 * @return WP_Error|null
	 */
	private function guard_content_loss($raw)
	{
		$lost = $this->tags_kses_would_remove($raw);
		if (empty($lost)) {
			return null;
		}

		return new WP_Error(
			'snowseo_content_would_be_lost',
			sprintf(
				/* translators: %s: comma-separated list of HTML tag names. */
				__('This page contains markup SnowSEO cannot save without removing it (%s). The change was not applied, so nothing on the page was altered. Edit this page by hand instead.', 'snowseo'),
				'<' . implode('>, <', array_slice($lost, 0, 5)) . '>'
			),
			array('status' => 409)
		);
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
	 * REST sanitize_callback for the `meta` object, so nothing in the payload
	 * reaches a handler unsanitized. Empty strings are preserved here because
	 * the update endpoint uses them to clear a stored field; handlers that do
	 * not want them filter them out themselves.
	 */
	public function sanitize_meta_param($value)
	{
		return $this->sanitize_seo_meta($value, true);
	}

	/**
	 * Whitelist and sanitize an incoming SEO meta payload.
	 *
	 * canonicalUrl holds a URL, so it is validated with esc_url_raw() limited to
	 * http/https rather than sanitize_text_field(): plain-text sanitization
	 * passes a javascript: or data: value straight through, and this value is
	 * both rendered in our own <head> and mirrored into the Yoast / Rank Math /
	 * SEOPress canonical meta, which those plugins print under their own
	 * escaping rules. A non-empty canonical that is not a valid http(s) URL is
	 * dropped rather than stored, leaving any existing value untouched.
	 *
	 * @param mixed $meta       Raw meta map from the request.
	 * @param bool  $keep_empty Keep empty strings, which the update endpoint
	 *                          uses to clear a previously stored field.
	 * @return array Sanitized map, limited to the keys we store.
	 */
	private function sanitize_seo_meta($meta, $keep_empty = false)
	{
		$clean = array();
		if (empty($meta) || ! is_array($meta)) {
			return $clean;
		}

		foreach (self::SEO_META_KEYS as $key) {
			if (! isset($meta[$key]) || ! is_string($meta[$key])) {
				continue;
			}

			if ('canonicalUrl' === $key) {
				$raw   = trim($meta[$key]);
				$value = '' === $raw ? '' : esc_url_raw($raw, array('http', 'https'));
				if ('' === $value && '' !== $raw) {
					continue; // Not a usable http(s) URL - leave any stored value alone.
				}
			} else {
				$value = sanitize_text_field($meta[$key]);
			}

			if ('' === $value && ! $keep_empty) {
				continue;
			}

			$clean[$key] = $value;
		}

		return $clean;
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
