<?php

/**
 * Safety loop for the render optimizer.
 *
 * The load-bearing decision here is that the safety decision lives in the
 * PLUGIN, not in SnowSEO. If the site 500s because of us, PageSpeed cannot load
 * it and the site cannot reach our API, so a remotely-decided rollback would
 * never fire - exactly when it is needed most. Everything below heals with zero
 * network. The API's re-measurement is a slower quality signal that can REQUEST
 * a revert; it is never the only line of defence.
 *
 * Four independent layers, in the order they catch things:
 *
 *  1. A synchronous canary at apply time. Three loopback requests, before and
 *     after, compared. Anything suspicious and the change is put straight back.
 *  2. A native shutdown observer. A fatal inside our own filter truncates the
 *     page while PHP still returns 200, so nothing above would notice - but
 *     error_get_last() sees it, and the next request serves unoptimized.
 *  3. A try/catch(Throwable) around the transform, with a strike counter that
 *     drops the site into safe mode for an hour after three strikes.
 *  4. Scheduled re-canaries at +15m, +2h and +24h, against a rotating URL set,
 *     because three URLs seen once as an anonymous visitor is not a site.
 *
 * catch(Throwable) does not catch memory exhaustion, which is precisely why the
 * shutdown observer exists. They are complements, not alternatives.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Perf_Guard
{
	/** Marker written into optimized pages so the canary can prove we ran. */
	const MARKER = '<!--snowseo-perf-->';

	/** The file kill switch, relative to wp-content. */
	const KILL_FILE = 'snowseo-perf-off';

	/** Query argument that disables the optimizer for one request. */
	const KILL_QUERY = 'snowseo_perf';

	const OPTION_STATE = 'snowseo_perf_render_state';

	const STRIKE_LIMIT = 3;
	const SAFE_MODE_SECONDS = 3600;

	/** Loopback budget for one canary run. Three URLs, twice each. */
	const CANARY_TIMEOUT = 12;
	const CANARY_URLS = 3;

	/** A page shorter than this fraction of its baseline lost content. */
	const MIN_LENGTH_RATIO = 0.9;

	const CRON_RECHECK = 'snowseo_perf_recanary';

	/** Re-canary delays, in seconds: 15 minutes, 2 hours, 24 hours. */
	const RECHECK_DELAYS = array(900, 7200, 86400);

	public static function init()
	{
		add_action(self::CRON_RECHECK, array(__CLASS__, 'run_scheduled_recanary'));
	}

	// ─── Kill switches ────────────────────────────────────────────────────────

	/**
	 * Whether the optimizer must stay out of this request.
	 *
	 * Four layers, and the file is the one that matters. The person who needs a
	 * kill switch has SFTP and a white screen, and cannot reach wp-admin either.
	 * A kill switch nobody can find at 2am is not a kill switch.
	 *
	 * @return string '' when allowed, otherwise the reason it is off.
	 */
	public static function blocked_reason()
	{
		if (defined('SNOWSEO_PERF_DISABLE') && SNOWSEO_PERF_DISABLE) {
			return 'constant';
		}
		if (file_exists(WP_CONTENT_DIR . '/' . self::KILL_FILE)) {
			return 'file';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only switch, no state change.
		if (isset($_GET[self::KILL_QUERY]) && 'off' === $_GET[self::KILL_QUERY]) {
			return 'query';
		}
		$state = self::state();
		if (! empty($state['safe_until']) && time() < (int) $state['safe_until']) {
			return 'safe_mode';
		}

		return '';
	}

	/** Every kill switch, for the payload and the admin screen. */
	public static function kill_switches()
	{
		return array(
			'constant' => 'SNOWSEO_PERF_DISABLE',
			'file'     => WP_CONTENT_DIR . '/' . self::KILL_FILE,
			'query'    => '?' . self::KILL_QUERY . '=off',
		);
	}

	// ─── State ────────────────────────────────────────────────────────────────

	public static function state()
	{
		$state = get_option(self::OPTION_STATE, array());

		return is_array($state) ? $state : array();
	}

	public static function update_state(array $changes)
	{
		update_option(self::OPTION_STATE, array_merge(self::state(), $changes), false);
	}

	/**
	 * What this site is made of, so a change of theme or plugins re-opens the
	 * watch window.
	 *
	 * A last-known-good that survives a theme switch is a lie, and "your
	 * optimizer broke my site three weeks later" is almost always a theme or
	 * plugin update rather than the optimizer.
	 *
	 * @return string
	 */
	public static function fingerprint()
	{
		$theme = wp_get_theme();
		$parts = array(
			get_bloginfo('version'),
			is_object($theme) ? $theme->get_stylesheet() . '@' . $theme->get('Version') : '',
			implode(',', (array) get_option('active_plugins', array())),
		);

		return md5(implode('|', $parts));
	}

	public static function fingerprint_changed()
	{
		$state = self::state();

		return empty($state['fingerprint']) || self::fingerprint() !== $state['fingerprint'];
	}

	// ─── Strikes and the shutdown observer ────────────────────────────────────

	/**
	 * Record that the transform threw, and enter safe mode once it is a pattern.
	 *
	 * One strike is a fluke on one page; three is the optimizer being wrong about
	 * this site. Safe mode expires on its own so a transient cause does not
	 * disable the feature forever.
	 *
	 * @param string $reason
	 * @return void
	 */
	public static function record_strike($reason)
	{
		$state = self::state();
		$strikes = isset($state['strikes']) ? (int) $state['strikes'] + 1 : 1;
		$changes = array(
			'strikes'     => $strikes,
			'last_error'  => (string) $reason,
			'last_strike' => time(),
		);
		if ($strikes >= self::STRIKE_LIMIT) {
			$changes['safe_until'] = time() + self::SAFE_MODE_SECONDS;
			$changes['strikes'] = 0;
		}
		self::update_state($changes);
	}

	/**
	 * Watch for a fatal that happened while our filter was running.
	 *
	 * A native register_shutdown_function, NOT add_action('shutdown'): a fatal
	 * error skips the rest of WordPress entirely, so an action hook never fires.
	 * The native callback still does.
	 *
	 * Registered only once the optimizer has actually touched the request, so a
	 * fatal in an unrelated plugin is never blamed on us.
	 *
	 * @return void
	 */
	public static function watch_request()
	{
		register_shutdown_function(array(__CLASS__, 'on_shutdown'));
	}

	public static function on_shutdown()
	{
		$error = error_get_last();
		if (null === $error) {
			return;
		}
		$fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
		if (! in_array((int) $error['type'], $fatal, true)) {
			return;
		}
		// Straight to safe mode, not a strike: the page the visitor just got was
		// truncated, and the next one must not be.
		self::update_state(array(
			'safe_until' => time() + self::SAFE_MODE_SECONDS,
			'strikes'    => 0,
			'last_error' => 'fatal: ' . (string) $error['message'],
		));
	}

	// ─── Canary ───────────────────────────────────────────────────────────────

	/** URLs to sample: the front page, and the two most recent posts. */
	public static function canary_urls($offset = 0)
	{
		$urls = array(home_url('/'));
		$posts = get_posts(array(
			'numberposts'      => self::CANARY_URLS,
			'offset'           => max(0, (int) $offset),
			'post_status'      => 'publish',
			'suppress_filters' => false,
			'fields'           => 'ids',
		));
		foreach ((array) $posts as $id) {
			$permalink = get_permalink($id);
			if (is_string($permalink) && '' !== $permalink) {
				$urls[] = $permalink;
			}
		}

		return array_slice(array_unique($urls), 0, self::CANARY_URLS);
	}

	/**
	 * Fetch one URL as an anonymous visitor.
	 *
	 * @param string $url
	 * @param bool   $optimized Whether to let the optimizer run.
	 * @return array|null
	 */
	private static function sample($url, $optimized)
	{
		$target = $optimized
			? $url
			: add_query_arg(self::KILL_QUERY, 'off', $url);
		// Cache-busting arg: a page cache would otherwise hand back the same body
		// before and after, and every comparison below would pass vacuously.
		$target = add_query_arg('snowseo_cb', (string) wp_rand(100000, 999999), $target);

		$response = wp_remote_get($target, array(
			'timeout'     => self::CANARY_TIMEOUT,
			'redirection' => 2,
			'sslverify'   => false,
			'headers'     => array('Cache-Control' => 'no-cache'),
			'user-agent'  => 'SnowSEO-Canary/1.0',
		));
		if (is_wp_error($response)) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body($response);

		return array(
			'status'  => (int) wp_remote_retrieve_response_code($response),
			'length'  => strlen($body),
			'html'    => false !== strpos($body, '</html>'),
			'bodyEnd' => false !== strpos($body, '</body>'),
			'imgs'    => substr_count($body, '<img'),
			'scripts' => substr_count($body, '<script'),
			'divs'    => substr_count($body, '</div>'),
			'marker'  => false !== strpos($body, self::MARKER),
		);
	}

	/**
	 * Compare one page before and after, and say what is wrong.
	 *
	 * The `</html>` check is the highest-value assertion available: a fatal
	 * inside an output filter truncates the page while PHP still returns 200, so
	 * status codes alone would call a destroyed page healthy.
	 *
	 * @return string '' when the page is fine, otherwise the failure.
	 */
	private static function compare($before, $after)
	{
		if (null === $after) {
			return 'unreachable';
		}
		if (null === $before) {
			return 'no_baseline';
		}
		if ($after['status'] !== $before['status'] || $after['status'] >= 400) {
			return 'status';
		}
		if (! ($after['html'] && $after['bodyEnd'])) {
			return 'truncated';
		}
		if ($before['length'] > 0 && $after['length'] < $before['length'] * self::MIN_LENGTH_RATIO) {
			return 'shorter';
		}
		if ($after['imgs'] < $before['imgs'] || $after['divs'] < $before['divs']) {
			return 'missing_elements';
		}
		if ($after['scripts'] < $before['scripts']) {
			return 'missing_scripts';
		}
		if (! $after['marker']) {
			// The optimizer did not run on the page we just measured, so this
			// proves nothing. Usually a page cache replaying an older copy.
			return 'not_applied';
		}

		return '';
	}

	/**
	 * Run the canary against the current configuration.
	 *
	 * Callers apply the change first and call this immediately, still holding the
	 * write lock. A failure means the caller must put it back.
	 *
	 * @param int $offset Rotate the sampled posts on scheduled re-checks.
	 * @return array{ok:bool,failure:string,url:string,checked:int}
	 */
	public static function run_canary($offset = 0)
	{
		$urls = self::canary_urls($offset);
		if (empty($urls)) {
			// Nothing publishable to measure. Refusing is the safe answer: we
			// would otherwise enable an unverified transform on an unknown site.
			return array('ok' => false, 'failure' => 'no_urls', 'url' => '', 'checked' => 0);
		}

		$checked = 0;
		foreach ($urls as $url) {
			$before = self::sample($url, false);
			$after  = self::sample($url, true);
			$failure = self::compare($before, $after);
			$checked++;
			if ('' !== $failure) {
				return array(
					'ok'      => false,
					'failure' => $failure,
					'url'     => $url,
					'checked' => $checked,
				);
			}
		}

		return array('ok' => true, 'failure' => '', 'url' => '', 'checked' => $checked);
	}

	// ─── Watch window ─────────────────────────────────────────────────────────

	/**
	 * Schedule the re-checks that follow a passing canary.
	 *
	 * The canary sees three URLs once, as an anonymous visitor. It cannot see
	 * checkout, search, a logged-in view, or a page builder's editor. These
	 * re-checks are the admission that a single pass is not proof.
	 *
	 * @return void
	 */
	public static function open_watch_window()
	{
		self::close_watch_window();
		foreach (self::RECHECK_DELAYS as $index => $delay) {
			wp_schedule_single_event(time() + $delay, self::CRON_RECHECK, array($index));
		}
		self::update_state(array(
			'watching_since' => time(),
			'fingerprint'    => self::fingerprint(),
		));
	}

	public static function close_watch_window()
	{
		foreach (array_keys(self::RECHECK_DELAYS) as $index) {
			$timestamp = wp_next_scheduled(self::CRON_RECHECK, array($index));
			while ($timestamp) {
				wp_unschedule_event($timestamp, self::CRON_RECHECK, array($index));
				$timestamp = wp_next_scheduled(self::CRON_RECHECK, array($index));
			}
		}
	}

	/**
	 * @param int $index Which re-check this is, used to rotate sampled posts.
	 * @return void
	 */
	public static function run_scheduled_recanary($index = 0)
	{
		if (! SnowSEO_Perf_Render::is_enabled()) {
			return;
		}
		$result = self::run_canary(((int) $index + 1) * self::CANARY_URLS);
		if ($result['ok']) {
			self::update_state(array('last_canary' => time(), 'last_failure' => ''));
			return;
		}
		SnowSEO_Perf_Render::disable_after_failure($result['failure']);
	}

	// ─── Cache purge ──────────────────────────────────────────────────────────

	/**
	 * Empty every page cache we can reach.
	 *
	 * A hard prerequisite, not a nicety. Verifying without purging measures the
	 * cached pre-fix HTML and concludes "no change", or worse reverts a fix that
	 * worked. Reverting without purging keeps serving the broken page and looks
	 * exactly like the revert failed.
	 *
	 * @return string[] Names of the caches that were cleared.
	 */
	public static function purge_caches()
	{
		$cleared = array();

		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
			$cleared[] = 'WP Rocket';
		}
		if (has_action('litespeed_purge_all')) {
			do_action('litespeed_purge_all');
			$cleared[] = 'LiteSpeed Cache';
		}
		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
			$cleared[] = 'W3 Total Cache';
		}
		if (function_exists('wp_cache_clear_cache')) {
			wp_cache_clear_cache();
			$cleared[] = 'WP Super Cache';
		}
		if (function_exists('sg_cachepress_purge_cache')) {
			sg_cachepress_purge_cache();
			$cleared[] = 'SiteGround Optimizer';
		}
		if (class_exists('WpeCommon') && method_exists('WpeCommon', 'purge_varnish_cache')) {
			WpeCommon::purge_varnish_cache();
			$cleared[] = 'WP Engine';
		}
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}

		return $cleared;
	}
}
