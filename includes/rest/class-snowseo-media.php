<?php
/**
 * Media handling for SnowSEO REST endpoints.
 *
 * Importing remote images into the media library, rewriting inline <img> srcs to
 * the local copies, and resolving a rendered image URL back to its attachment.
 *
 * All static: these are pure helpers over WordPress media functions with no
 * state of their own, so the publish handlers can call them without an instance
 * being threaded through, and the /media/alt route registers the static
 * callable directly.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Media
{
	/**
	 * Resolve a media URL to its attachment ID.
	 *
	 * attachment_url_to_postid() only matches the ORIGINAL upload, so a rendered
	 * srcset/resized variant ("photo-1024x683.jpg") returns 0. Strip the
	 * "-{width}x{height}" suffix and retry before giving up.
	 */
	public static function attachment_id_from_url($url)
	{
		$clean = strtok($url, '?');

		$attachment_id = attachment_url_to_postid($clean);
		if ($attachment_id) {
			return (int) $attachment_id;
		}

		// Resized variant -> original file name.
		$stripped = preg_replace('/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $clean);
		if ($stripped && $stripped !== $clean) {
			$attachment_id = attachment_url_to_postid($stripped);
			if ($attachment_id) {
				return (int) $attachment_id;
			}
		}

		return 0;
	}

	/**
	 * Handle POST /media/alt - set alt text on media library attachments by URL.
	 *
	 * Body: { items: [ { url, alt }, ... ] }
	 * Always 200; each item reports its own success/failure so one unresolvable
	 * URL never fails the whole batch.
	 */
	public static function handle_update_media_alt($request)
	{
		$items = $request->get_param('items');
		if (! is_array($items)) {
			return new WP_Error('invalid_items', 'items must be an array', array('status' => 400));
		}

		$results = array();
		$updated = 0;
		$skipped = 0;

		foreach ($items as $item) {
			$url = isset($item['url']) ? esc_url_raw($item['url']) : '';
			$alt = isset($item['alt']) ? sanitize_text_field($item['alt']) : '';

			if (empty($url) || $alt === '') {
				$results[] = array(
					'url'     => $url,
					'success' => false,
					'error'   => 'url and alt are required',
				);
				continue;
			}

			$attachment_id = self::attachment_id_from_url($url);
			// Confirm the resolved id really is an attachment before writing meta
			// to it, so a URL that resolves to anything else is refused instead of
			// having alt text written onto an unrelated post.
			if (! $attachment_id || 'attachment' !== get_post_type($attachment_id)) {
				$results[] = array(
					'url'     => $url,
					'success' => false,
					'error'   => 'No media library attachment found for this URL',
				);
				continue;
			}

			// Never silently replace alt text the owner already wrote.
			//
			// The crawler flags an image from the RENDERED page, and plenty of
			// themes and page builders emit <img src> without ever reading
			// _wp_attachment_image_alt. On those sites the attachment already
			// has good alt, the page shows none, and overwriting would destroy
			// real content, spend a credit, and still not fix the page - the
			// theme goes on not rendering it, so the issue returns next audit.
			// Report it instead, so the caller can say the alt exists and the
			// theme is at fault. `force` is the deliberate override.
			$existing = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
			if (empty($item['force']) && is_string($existing) && '' !== trim($existing)) {
				$skipped++;
				$results[] = array(
					'url'          => $url,
					'success'      => false,
					'skipped'      => true,
					'reason'       => 'already_has_alt',
					'existingAlt'  => $existing,
					'attachmentId' => $attachment_id,
				);
				continue;
			}

			update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
			$updated++;
			$results[] = array(
				'url'          => $url,
				'success'      => true,
				'attachmentId' => $attachment_id,
			);
		}

		return new WP_REST_Response(array(
			'success' => true,
			'updated' => $updated,
			'skipped' => $skipped,
			'results' => $results,
		), 200);
	}

	/**
	 * Get a valid post author ID for server-to-server publish calls.
	 * When no user is logged in (X-Plugin-Key auth), falls back to the
	 * first active administrator, or 1 as a last resort.
	 */
	public static function default_post_author()
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
	public static function import_remote_image($url, $post_id, $desc = '')
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
	public static function sideload_content_images($content, $post_id)
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
				SnowSEO_Log::write('error', 'Reached the inline-image import cap; remaining images kept as external links.');
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

			$attachment_id = self::import_remote_image($src, $post_id);
			if (is_wp_error($attachment_id)) {
				SnowSEO_Log::write('error', 'Failed to import inline image: ' . $attachment_id->get_error_message());
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
}
