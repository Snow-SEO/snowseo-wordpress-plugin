<?php

/**
 * Performance fixes that live in WordPress's own enqueue system rather than on
 * disk.
 *
 * Neither of these writes a file, so both work on nginx, IIS and every managed
 * host where the .htaccess fixes report `unsupported`. They are also completely
 * reversible by flipping an option, with no filesystem state to get out of sync.
 *
 * SECURITY: the preconnect origin list is a compile-time constant. This is the
 * same rule the .htaccess routes follow - the API says "turn this on", it never
 * names an origin. A `<link rel=preconnect>` in every page head is a resource
 * the browser opens a TLS connection to before anything else, so an origin
 * supplied over the network would let a leaked site token point every visitor's
 * browser at an attacker. The allowlist is intersected at runtime with what the
 * site actually enqueues, so we only ever hint at hosts the site already talks
 * to on its own.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Perf_Assets
{
	const OPTION_FONT_DISPLAY = 'snowseo_perf_font_display_enabled';
	const OPTION_PRECONNECT   = 'snowseo_perf_preconnect_enabled';

	/** Google's font CSS host, and the host it in turn pulls font files from. */
	const GOOGLE_FONTS_CSS   = 'fonts.googleapis.com';
	const GOOGLE_FONTS_FILES = 'fonts.gstatic.com';

	/** Browsers ignore more than a handful, and each one costs a connection. */
	const MAX_HINTS = 4;

	/**
	 * Hosts we may ever hint at, and how.
	 *
	 * `preconnect` opens the TCP and TLS handshake immediately and is only worth
	 * it for an origin on the critical path. `dns-prefetch` is just a name
	 * lookup, which is the right weight for analytics and CDN hosts that load
	 * later. Guessing wrong the other way costs a connection the page never uses.
	 *
	 * COMPILE TIME. Never build this from a request parameter.
	 *
	 * @return array<string,string>
	 */
	public static function allowlist()
	{
		return array(
			self::GOOGLE_FONTS_CSS       => 'preconnect',
			self::GOOGLE_FONTS_FILES     => 'preconnect',
			'use.typekit.net'            => 'preconnect',
			'use.fontawesome.com'        => 'preconnect',
			'kit.fontawesome.com'        => 'preconnect',
			'cdnjs.cloudflare.com'       => 'dns-prefetch',
			'cdn.jsdelivr.net'           => 'dns-prefetch',
			'unpkg.com'                  => 'dns-prefetch',
			'ajax.googleapis.com'        => 'dns-prefetch',
			'maxcdn.bootstrapcdn.com'    => 'dns-prefetch',
			'stackpath.bootstrapcdn.com' => 'dns-prefetch',
			'www.googletagmanager.com'   => 'dns-prefetch',
			'www.google-analytics.com'   => 'dns-prefetch',
			'connect.facebook.net'       => 'dns-prefetch',
		);
	}

	public static function init()
	{
		add_filter('style_loader_src', array(__CLASS__, 'filter_style_src'), 10, 2);
		add_filter('wp_resource_hints', array(__CLASS__, 'filter_resource_hints'), 10, 2);
		add_action('wp_head', array(__CLASS__, 'print_sized_image_rule'), 1);
	}

	// ─── Consent ──────────────────────────────────────────────────────────────

	public static function font_display_enabled()
	{
		return '1' === (string) get_option(self::OPTION_FONT_DISPLAY, '0');
	}

	public static function preconnect_enabled()
	{
		return '1' === (string) get_option(self::OPTION_PRECONNECT, '0');
	}

	// ─── font-display ─────────────────────────────────────────────────────────

	/**
	 * Append `display=swap` to Google Fonts stylesheet URLs.
	 *
	 * Without it the browser hides text for up to three seconds while the font
	 * downloads, which Lighthouse reports as a font-display failure and a real
	 * visitor experiences as a blank page. `swap` shows the fallback font
	 * immediately and swaps when the web font arrives.
	 *
	 * Only Google Fonts, and only when the theme has not already chosen a value:
	 * a theme that deliberately set `display=block` or `display=optional` made
	 * that choice for a reason and it is not ours to overrule.
	 *
	 * @param string $src
	 * @param string $handle
	 * @return string
	 */
	public static function filter_style_src($src, $handle)
	{
		unset($handle);
		if (! self::font_display_enabled() || ! is_string($src) || '' === $src) {
			return $src;
		}
		if (false === strpos($src, self::GOOGLE_FONTS_CSS)) {
			return $src;
		}
		if (false !== strpos($src, 'display=')) {
			return $src;
		}

		return add_query_arg('display', 'swap', $src);
	}

	// ─── preconnect ───────────────────────────────────────────────────────────

	/**
	 * Hosts this site actually enqueues something from, restricted to the
	 * allowlist.
	 *
	 * fonts.gstatic.com is added whenever fonts.googleapis.com is present even
	 * though nothing enqueues it directly: Google's stylesheet is what references
	 * it, so the browser cannot discover it until that CSS has downloaded and
	 * parsed. That second round trip is the single most expensive one on a
	 * typical WordPress page, and preconnecting to it is the highest-value hint
	 * available anywhere in this list.
	 *
	 * @return array<string,string> host => relation
	 */
	public static function detected_origins()
	{
		$allowed = self::allowlist();
		$found = array();

		foreach (array(wp_scripts(), wp_styles()) as $collection) {
			if (! is_object($collection) || empty($collection->registered)) {
				continue;
			}
			foreach ($collection->registered as $dependency) {
				if (empty($dependency->src) || ! is_string($dependency->src)) {
					continue;
				}
				$host = wp_parse_url($dependency->src, PHP_URL_HOST);
				if (! is_string($host) || '' === $host) {
					continue;
				}
				$host = strtolower($host);
				if (isset($allowed[$host])) {
					$found[$host] = $allowed[$host];
				}
			}
		}

		if (isset($found[self::GOOGLE_FONTS_CSS])) {
			$found[self::GOOGLE_FONTS_FILES] = $allowed[self::GOOGLE_FONTS_FILES];
		}

		return array_slice($found, 0, self::MAX_HINTS, true);
	}

	/**
	 * @param array  $urls
	 * @param string $relation_type
	 * @return array
	 */
	public static function filter_resource_hints($urls, $relation_type)
	{
		if (! self::preconnect_enabled() || ! is_array($urls)) {
			return $urls;
		}
		if ('preconnect' !== $relation_type && 'dns-prefetch' !== $relation_type) {
			return $urls;
		}

		// Whatever WordPress or another plugin already listed, in whichever shape.
		$existing = array();
		foreach ($urls as $entry) {
			$href = is_array($entry) && isset($entry['href']) ? $entry['href'] : $entry;
			if (is_string($href)) {
				$host = wp_parse_url($href, PHP_URL_HOST);
				if (is_string($host)) {
					$existing[strtolower($host)] = true;
				}
			}
		}

		foreach (self::detected_origins() as $host => $relation) {
			if ($relation !== $relation_type || isset($existing[$host])) {
				continue;
			}
			// Font files are fetched anonymously, so the preconnect has to be too
			// or the browser opens a second connection and the hint is wasted.
			if (self::GOOGLE_FONTS_FILES === $host) {
				$urls[] = array(
					'href'        => 'https://' . $host,
					'crossorigin' => 'anonymous',
				);
				continue;
			}
			$urls[] = 'https://' . $host;
		}

		return $urls;
	}

	// ─── Sized-image guard ────────────────────────────────────────────────────

	/**
	 * One zero-specificity rule for images SnowSEO gave explicit dimensions to.
	 *
	 * Width and height attributes are what stop layout shift, but on a theme with
	 * `img { width: 100% }` and no matching `height: auto`, the image renders at
	 * the attribute height and distorts. `:where()` has zero specificity, so this
	 * loses to any rule the theme sets, and the attribute selector means it can
	 * only ever touch images this plugin sized itself.
	 *
	 * The fix also writes an inline `height:auto`, so this is the second of two
	 * independent guards rather than the only one.
	 *
	 * @return void
	 */
	public static function print_sized_image_rule()
	{
		echo "<style id=\"snowseo-sized-images\">:where(img[data-snowseo-sized]){height:auto}</style>\n";
	}
}
