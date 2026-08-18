<?php

/**
 * Render-blocking resource optimizer.
 *
 * The load-bearing architectural call here is that this works at the ENQUEUE
 * layer, not on the output buffer.
 *
 * Deferring a script that has inline `after` data breaks the site with
 * "jQuery is not defined", and `wp_scripts()->get_data($handle, 'after')` is the
 * only place that dependency is visible. It does not exist in a buffered string.
 * A regex over full page HTML is also the single largest cause of "the plugin
 * blanked my site". So we filter `script_loader_tag` with the handle in hand and
 * never touch anything we cannot identify.
 *
 * What this does NOT do, deliberately:
 *  - No minification. A PHP regex JS minifier breaks on automatic semicolon
 *    insertion and cannot tell a regex literal from a division.
 *  - No concatenation. Order, conditional enqueues and relative url() make it
 *    unsafe, for roughly zero gain under HTTP/2.
 *  - No page cache. A second, worse page cache inside an SEO plugin is a support
 *    catastrophe.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Perf_Render
{
	const OPTION_ENABLED = 'snowseo_perf_render_enabled';

	/** WP_HTML_Tag_Processor and reliable script metadata. */
	const MIN_WP_VERSION = '6.4';

	/**
	 * Handles that must never be deferred.
	 *
	 * jQuery first, because half of WordPress assumes it is already defined by
	 * the time inline scripts run. Everything core ships under `wp-` is a
	 * dependency of something, often of an inline script we cannot see from here.
	 */
	const NEVER_DEFER = array(
		'jquery',
		'jquery-core',
		'jquery-migrate',
		'utils',
		'wp-polyfill',
		'modernizr',
	);

	/** Handle prefixes that are never deferred, for the same reason. */
	const NEVER_DEFER_PREFIXES = array('wp-', 'wc-', 'woocommerce');

	/**
	 * Stylesheet handles safe to load asynchronously.
	 *
	 * A compile-time allowlist, never the theme's main stylesheet. Swapping the
	 * stylesheet that lays the page out produces a flash of unstyled content on
	 * every single page load, which is a worse experience than the render block
	 * it removes.
	 */
	const ASYNC_STYLE_HANDLES = array(
		'dashicons',
		'font-awesome',
		'fontawesome',
		'elementor-icons',
		'wp-block-library-theme',
	);

	/** Hosts whose stylesheets are fonts or icons, safe to swap. */
	// phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Hostnames matched against stylesheets the site already enqueues, never a source. This plugin loads nothing from them; it only decides whether an existing <link> may be loaded without blocking the first paint.
	const ASYNC_STYLE_HOSTS = array(
		'fonts.googleapis.com',
		'use.fontawesome.com',
		'cdnjs.cloudflare.com',
	);
	// phpcs:enable PluginCheck.CodeAnalysis.Offloading.OffloadedContent

	public static function init()
	{
		SnowSEO_Perf_Guard::init();

		if (! self::active()) {
			return;
		}
		add_filter('script_loader_tag', array(__CLASS__, 'filter_script_tag'), 20, 3);
		add_filter('style_loader_tag', array(__CLASS__, 'filter_style_tag'), 20, 4);
		add_action('wp_head', array(__CLASS__, 'print_marker'), 0);
	}

	// ─── Availability ─────────────────────────────────────────────────────────

	public static function is_enabled()
	{
		return '1' === (string) get_option(self::OPTION_ENABLED, '0');
	}

	/** Whether this WordPress is new enough to run the optimizer at all. */
	public static function supported()
	{
		return version_compare(get_bloginfo('version'), self::MIN_WP_VERSION, '>=');
	}

	/**
	 * Whether the optimizer should touch THIS request.
	 *
	 * Admin, REST, cron, feeds and logged-in previews are all excluded: none of
	 * them is a page a visitor's Core Web Vitals are measured on, and every one
	 * of them is somewhere a broken script is expensive.
	 *
	 * @return bool
	 */
	public static function active()
	{
		if (! (self::is_enabled() && self::supported())) {
			return false;
		}
		if ('' !== SnowSEO_Perf_Guard::blocked_reason()) {
			return false;
		}
		if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
			return false;
		}
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return false;
		}
		if (is_feed() || is_preview() || is_customize_preview()) {
			return false;
		}

		return true;
	}

	/**
	 * Proof that the optimizer ran on this response.
	 *
	 * The canary asserts on this. Without it, a page cache replaying a copy from
	 * before the change would look like a passing test.
	 *
	 * @return void
	 */
	public static function print_marker()
	{
		SnowSEO_Perf_Guard::watch_request();
		echo SnowSEO_Perf_Guard::MARKER . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- a compile-time constant comment.
	}

	// ─── Scripts ──────────────────────────────────────────────────────────────

	/**
	 * Whether jQuery is queued in the head alongside anything with inline data.
	 *
	 * When it is, we do not defer ANYTHING on the page. Head jQuery plus inline
	 * scripts is the classic theme shape where deferral produces
	 * "$ is not defined" on the first paint, and no per-handle rule catches it
	 * because the inline script belongs to a different handle than the one being
	 * deferred.
	 *
	 * @return bool
	 */
	private static function head_jquery_conflict()
	{
		$scripts = wp_scripts();
		if (! is_object($scripts) || empty($scripts->registered)) {
			return false;
		}
		$jquery_in_head = false;
		foreach (array('jquery', 'jquery-core') as $handle) {
			if (isset($scripts->registered[$handle]) && 1 !== (int) $scripts->get_data($handle, 'group')) {
				$jquery_in_head = true;
				break;
			}
		}
		if (! $jquery_in_head) {
			return false;
		}
		foreach ($scripts->registered as $handle => $dependency) {
			unset($dependency);
			if ($scripts->get_data($handle, 'after') || $scripts->get_data($handle, 'before')) {
				return true;
			}
		}

		return false;
	}

	private static function never_defer($handle)
	{
		if (in_array($handle, self::NEVER_DEFER, true)) {
			return true;
		}
		foreach (self::NEVER_DEFER_PREFIXES as $prefix) {
			if (0 === strpos($handle, $prefix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add `defer` to head scripts that can take it.
	 *
	 * `defer` rather than `async`: deferred scripts still execute in document
	 * order, so a script that depends on an earlier one keeps working. `async`
	 * would reorder them and break exactly the dependency chains WordPress's
	 * enqueue system exists to express.
	 *
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 * @return string
	 */
	public static function filter_script_tag($tag, $handle, $src)
	{
		unset($src);
		try {
			if (! is_string($tag) || '' === $tag) {
				return $tag;
			}
			if (self::never_defer($handle)) {
				return $tag;
			}
			// Footer scripts do not block rendering, so there is nothing to win
			// and a dependency to risk.
			$scripts = wp_scripts();
			if (! is_object($scripts) || 1 === (int) $scripts->get_data($handle, 'group')) {
				return $tag;
			}
			// Inline code attached to this handle runs immediately after the tag
			// in the document. Deferring the tag runs it AFTER that inline code.
			if ($scripts->get_data($handle, 'after') || $scripts->get_data($handle, 'before')) {
				return $tag;
			}
			if (self::head_jquery_conflict()) {
				return $tag;
			}
			// A module is already deferred by definition; async is a deliberate
			// choice by whoever enqueued it.
			if (
				false !== stripos($tag, ' async')
				|| false !== stripos($tag, ' defer')
				|| false !== stripos($tag, 'type="module"')
				|| false !== stripos($tag, "type='module'")
			) {
				return $tag;
			}
			if (false === stripos($tag, ' src=')) {
				return $tag;
			}

			return str_replace('<script ', '<script defer ', $tag);
		} catch (Throwable $e) {
			SnowSEO_Perf_Guard::record_strike('script: ' . $e->getMessage());
			return $tag;
		}
	}

	// ─── Styles ───────────────────────────────────────────────────────────────

	/**
	 * Whether a Content-Security-Policy is in force.
	 *
	 * The async-CSS trick needs an inline `onload` handler, which a strict policy
	 * forbids. On a hardened site the stylesheet would then load with
	 * `media="print"` and never be applied to the screen - every stylesheet
	 * swapped this way would silently stop working.
	 *
	 * @return bool
	 */
	private static function csp_present()
	{
		if (! function_exists('headers_list')) {
			return false;
		}
		foreach (headers_list() as $header) {
			if (0 === stripos($header, 'content-security-policy')) {
				return true;
			}
		}

		return false;
	}

	private static function async_style_allowed($handle, $href)
	{
		foreach (self::ASYNC_STYLE_HANDLES as $allowed) {
			if ($handle === $allowed || 0 === strpos($handle, $allowed . '-')) {
				return true;
			}
		}
		$host = is_string($href) ? wp_parse_url($href, PHP_URL_HOST) : '';
		if (is_string($host) && '' !== $host) {
			return in_array(strtolower($host), self::ASYNC_STYLE_HOSTS, true);
		}

		return false;
	}

	/**
	 * Load allowlisted stylesheets without blocking the first paint.
	 *
	 * `media="print"` makes the browser fetch the file at low priority without
	 * applying it, and the onload handler switches it to `all` once it arrives.
	 * The `<noscript>` copy means a visitor without JavaScript still gets the
	 * styles, which is the difference between an optimization and a bug.
	 *
	 * @param string $tag
	 * @param string $handle
	 * @param string $href
	 * @param string $media
	 * @return string
	 */
	public static function filter_style_tag($tag, $handle, $href, $media)
	{
		try {
			if (! is_string($tag) || '' === $tag) {
				return $tag;
			}
			if ('all' !== $media && '' !== (string) $media) {
				return $tag;
			}
			if (! self::async_style_allowed($handle, $href)) {
				return $tag;
			}
			if (self::csp_present()) {
				return $tag;
			}
			if (false !== stripos($tag, 'onload=')) {
				return $tag;
			}

			$async = str_replace(
				"media='all'",
				"media='print' onload=\"this.media='all';this.onload=null;\"",
				$tag
			);
			if ($async === $tag) {
				$async = str_replace(
					'media="all"',
					'media="print" onload="this.media=\'all\';this.onload=null;"',
					$tag
				);
			}
			if ($async === $tag) {
				return $tag;
			}

			return $async . '<noscript>' . $tag . '</noscript>';
		} catch (Throwable $e) {
			SnowSEO_Perf_Guard::record_strike('style: ' . $e->getMessage());
			return $tag;
		}
	}

	// ─── Apply / revert ───────────────────────────────────────────────────────

	/**
	 * Turn the optimizer on, then immediately prove it did not break the site.
	 *
	 * The canary runs synchronously, inside the caller's write lock, and the flag
	 * goes straight back if anything looks wrong. A change that cannot be proven
	 * safe is not left on while we wait for someone to notice.
	 *
	 * @return true|WP_Error
	 */
	public static function apply()
	{
		if (! self::supported()) {
			return new WP_Error(
				'perf_wp_version',
				sprintf(
					/* translators: %s: minimum WordPress version. */
					__('This needs WordPress %s or newer.', 'snowseo'),
					self::MIN_WP_VERSION
				),
				array('status' => 409)
			);
		}
		$blocked = SnowSEO_Perf_Guard::blocked_reason();
		if ('constant' === $blocked || 'file' === $blocked) {
			return new WP_Error(
				'perf_kill_switch',
				__('A kill switch is active on this site, so the optimizer cannot be turned on. Remove it first.', 'snowseo'),
				array('status' => 409)
			);
		}

		update_option(self::OPTION_ENABLED, '1');
		SnowSEO_Perf_Guard::update_state(array('safe_until' => 0, 'strikes' => 0));
		SnowSEO_Perf_Guard::purge_caches();

		$canary = SnowSEO_Perf_Guard::run_canary();
		if (! $canary['ok']) {
			update_option(self::OPTION_ENABLED, '0');
			SnowSEO_Perf_Guard::purge_caches();
			SnowSEO_Perf_Guard::update_state(array(
				'last_failure' => $canary['failure'],
				'failed_url'   => $canary['url'],
				'failed_at'    => time(),
			));

			return new WP_Error(
				'perf_canary_failed',
				self::canary_message($canary['failure']),
				array('status' => 409)
			);
		}

		SnowSEO_Perf_Guard::update_state(array(
			'last_canary'  => time(),
			'last_failure' => '',
			'failed_url'   => '',
		));
		SnowSEO_Perf_Guard::open_watch_window();

		return true;
	}

	/** @return true */
	public static function revert()
	{
		update_option(self::OPTION_ENABLED, '0');
		SnowSEO_Perf_Guard::close_watch_window();
		SnowSEO_Perf_Guard::purge_caches();

		return true;
	}

	/**
	 * Turn the optimizer off because a check failed, and remember why.
	 *
	 * @param string $failure
	 * @return void
	 */
	public static function disable_after_failure($failure)
	{
		update_option(self::OPTION_ENABLED, '0');
		SnowSEO_Perf_Guard::close_watch_window();
		SnowSEO_Perf_Guard::purge_caches();
		SnowSEO_Perf_Guard::update_state(array(
			'last_failure' => (string) $failure,
			'failed_at'    => time(),
		));
	}

	/** Plain-English reason a canary refused, for the site owner. */
	public static function canary_message($failure)
	{
		switch ($failure) {
			case 'truncated':
				return __('With the change on, your pages stopped loading all the way to the end, so SnowSEO put it straight back.', 'snowseo');
			case 'status':
				return __('With the change on, your pages returned an error, so SnowSEO put it straight back.', 'snowseo');
			case 'shorter':
			case 'missing_elements':
			case 'missing_scripts':
				return __('With the change on, part of your page went missing, so SnowSEO put it straight back.', 'snowseo');
			case 'not_applied':
				return __('SnowSEO could not see its own change on the live page, usually because a caching plugin served an older copy. Clear your cache and try again.', 'snowseo');
			case 'unreachable':
				return __('SnowSEO could not load your pages from the outside to check the change, so it did not leave it on. This is usually a firewall blocking loopback requests.', 'snowseo');
			case 'no_urls':
				return __('This site has no published pages to test the change against yet.', 'snowseo');
			default:
				return __('SnowSEO could not confirm the change was safe, so it put it back.', 'snowseo');
		}
	}

	// ─── Status ───────────────────────────────────────────────────────────────

	public static function status()
	{
		$state = SnowSEO_Perf_Guard::state();
		$out = array(
			'id'           => SnowSEO_Perf::FIX_RENDER_BLOCKING,
			'appliedAt'    => isset($state['last_canary']) ? (int) $state['last_canary'] : 0,
			'appliedBy'    => '',
			'snippet'      => '',
			'handler'      => '',
			'revertable'   => self::is_enabled(),
			'probe'        => null,
			'killSwitches' => SnowSEO_Perf_Guard::kill_switches(),
			'watching'     => ! empty($state['watching_since']),
		);

		if (! self::supported()) {
			return array_merge($out, array(
				'status'  => 'unsupported',
				'reason'  => 'wp_version',
				'message' => sprintf(
					/* translators: %s: minimum WordPress version. */
					__('This needs WordPress %s or newer.', 'snowseo'),
					self::MIN_WP_VERSION
				),
				'remedy'  => __('Update WordPress, then check again.', 'snowseo'),
			));
		}
		$blocked = SnowSEO_Perf_Guard::blocked_reason();
		if ('constant' === $blocked || 'file' === $blocked) {
			return array_merge($out, array(
				'status'  => 'blocked',
				'reason'  => 'kill_switch',
				'message' => 'file' === $blocked
					? __('A snowseo-perf-off file in wp-content is switching this off.', 'snowseo')
					: __('The SNOWSEO_PERF_DISABLE constant is switching this off.', 'snowseo'),
				'remedy'  => __('Remove it to allow the optimizer to run.', 'snowseo'),
			));
		}
		if (self::is_enabled()) {
			return array_merge($out, array(
				'status'  => 'applied',
				'reason'  => 'ok',
				'message' => 'safe_mode' === $blocked
					? __('The optimizer is on but paused for the next hour after an error on this site.', 'snowseo')
					: __('Scripts and icon stylesheets no longer hold up the first paint.', 'snowseo'),
				'remedy'  => '',
			));
		}
		if (! empty($state['last_failure'])) {
			return array_merge($out, array(
				'status'  => 'blocked',
				'reason'  => 'canary_failed',
				'message' => self::canary_message($state['last_failure']),
				'remedy'  => __('Nothing was left changed. This usually means a theme or plugin on this site needs its scripts loaded in order.', 'snowseo'),
			));
		}

		return array_merge($out, array(
			'status'  => 'available',
			'reason'  => 'ok',
			'message' => __('SnowSEO can stop scripts and icon fonts from holding up the first paint, and will check your pages still work before leaving it on.', 'snowseo'),
			'remedy'  => '',
		));
	}
}
