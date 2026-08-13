<?php

/**
 * Site-level performance fixes.
 *
 * This is the first plugin-key surface that writes anything outside a post, so
 * it is built around three non-negotiable rules:
 *
 *  1. ZERO attacker-controlled bytes reach disk. The route takes a fix id from a
 *     fixed enum and nothing else. It never accepts content, directives, rules,
 *     a snippet, or a path. Every byte written is a compile-time constant in the
 *     plugin. This is what keeps a leaked site token a nuisance rather than a
 *     compromise: .htaccess accepts `php_value auto_prepend_file` and
 *     `SetHandler`, so a route that wrote remote input would be remote code
 *     execution. If a future feature wants custom directives, the answer is to
 *     ship a plugin version, not to add a parameter.
 *  2. The remote channel is INERT until a local administrator turns it on. This
 *     mirrors what the plugin already does elsewhere: handle_provision() refuses
 *     to enable tracking as a side effect, and the wp-config edit is "offered,
 *     never applied silently". Consent belongs to the site, not the SaaS.
 *  3. Every write is reversible without SnowSEO being reachable: marker
 *     delimited, removable over SFTP, from this screen, and on uninstall.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Perf
{
	const OPTION_REMOTE        = 'snowseo_perf_remote_enabled';
	const OPTION_COMPRESSION   = 'snowseo_perf_compression_enabled';
	const OPTION_CACHE_HEADERS = 'snowseo_perf_cache_headers_enabled';
	const OPTION_STATE       = 'snowseo_perf_state';
	const OPTION_LOCK        = 'snowseo_perf_lock';

	const FIX_COMPRESSION   = 'text-compression';
	const FIX_ROBOTS        = 'robots-txt';
	const FIX_CACHE_HEADERS = 'cache-headers';
	const FIX_FONT_DISPLAY  = 'font-display';
	const FIX_PRECONNECT    = 'preconnect';
	const FIX_RENDER_BLOCKING = 'render-blocking';

	/** Seconds a write lock is honoured before it is treated as abandoned. */
	const LOCK_TTL = 60;

	/** Probe caches. Robots changes more often than server compression config. */
	const PROBE_ROBOTS_TTL      = 300;
	const PROBE_COMPRESSION_TTL = 900;

	/** Cap on the physical robots.txt we echo back. It is world-readable anyway. */
	const MAX_ROBOTS_BYTES = 65536;

	public static function init()
	{
		add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
		add_action('admin_init', array(__CLASS__, 'register_settings'));
		SnowSEO_Perf_Robots::init();
		SnowSEO_Perf_Assets::init();
		SnowSEO_Perf_Render::init();
	}

	/** The fixes this plugin knows how to apply. */
	public static function fixes()
	{
		return array(
			self::FIX_ROBOTS,
			self::FIX_COMPRESSION,
			self::FIX_CACHE_HEADERS,
			self::FIX_FONT_DISPLAY,
			self::FIX_PRECONNECT,
			self::FIX_RENDER_BLOCKING,
		);
	}

	/**
	 * The .htaccess block a fix owns, or '' when it does not write to disk.
	 *
	 * @param string $fix
	 * @return string
	 */
	private static function block_for($fix)
	{
		if (self::FIX_COMPRESSION === $fix) {
			return SnowSEO_Perf_Htaccess::BLOCK_COMPRESSION;
		}
		if (self::FIX_CACHE_HEADERS === $fix) {
			return SnowSEO_Perf_Htaccess::BLOCK_EXPIRES;
		}

		return '';
	}

	/**
	 * The option flag a filter-based fix reads, or '' for the others.
	 *
	 * @param string $fix
	 * @return string
	 */
	private static function option_for($fix)
	{
		if (self::FIX_FONT_DISPLAY === $fix) {
			return SnowSEO_Perf_Assets::OPTION_FONT_DISPLAY;
		}
		if (self::FIX_PRECONNECT === $fix) {
			return SnowSEO_Perf_Assets::OPTION_PRECONNECT;
		}

		return '';
	}

	// ─── Consent ──────────────────────────────────────────────────────────────

	/**
	 * Whether SnowSEO may apply performance fixes remotely.
	 *
	 * Wrapped in a filter so a hardened site can refuse regardless of what is in
	 * the database: `add_filter('snowseo_perf_remote_enabled', '__return_false')`
	 * in an mu-plugin kills the channel permanently.
	 */
	public static function remote_allowed()
	{
		$on = '1' === (string) get_option(self::OPTION_REMOTE, '0');

		/**
		 * Filter whether SnowSEO may apply performance fixes remotely.
		 *
		 * @param bool $on
		 */
		return (bool) apply_filters('snowseo_perf_remote_enabled', $on);
	}

	// ─── Permissions ──────────────────────────────────────────────────────────

	/**
	 * Capability data is site fingerprinting - not a secret, but not public.
	 * Both channels need it: the API to decide, the React page to render.
	 *
	 * Both underlying checks live in SnowSEO_Rest_Auth. Same reasoning as the key
	 * comparison already had: one implementation, not two. A second copy of
	 * "which capability counts as admin here" is the kind of thing that drifts
	 * silently and only shows up as a permissions bug.
	 */
	public static function check_read_permission($request)
	{
		return SnowSEO_Rest_Auth::check_admin_permission()
			|| SnowSEO_Rest_Auth::check_plugin_key_permission($request);
	}

	/**
	 * A logged-in manage_options user with a valid nonce is strictly stronger
	 * authority than a bearer token, and is not gated by a switch they control
	 * themselves. The remote channel is gated.
	 *
	 * Returns WP_Error rather than false on refusal: `false` collapses into
	 * WordPress's generic rest_forbidden, and the API could not then tell "wrong
	 * key" from "not permitted" - which is the difference between "reconnect" and
	 * "ask the site owner to enable this".
	 */
	public static function check_write_permission($request)
	{
		if (SnowSEO_Rest_Auth::check_admin_permission()) {
			return true;
		}
		if (! SnowSEO_Rest_Auth::check_plugin_key_permission($request)) {
			return false;
		}
		if (! self::remote_allowed()) {
			return new WP_Error(
				'perf_not_permitted',
				__('SnowSEO is not permitted to apply performance fixes on this site. An administrator can allow it under SnowSEO > Page Speed, or Settings > Reading.', 'snowseo'),
				array('status' => 403)
			);
		}

		return true;
	}

	// ─── Routes ───────────────────────────────────────────────────────────────

	public static function register_rest_routes()
	{
		$namespace = 'snowseo/v1';
		$fix_arg = array(
			'fix' => array(
				'required'          => true,
				'type'              => 'string',
				'enum'              => self::fixes(),
				'sanitize_callback' => 'sanitize_key',
			),
		);

		register_rest_route($namespace, '/perf', array(
			array(
				'methods'             => 'GET',
				'callback'            => array(__CLASS__, 'handle_get_settings'),
				'permission_callback' => array(__CLASS__, 'check_read_permission'),
			),
			array(
				// Consent is set locally only. Deliberately NOT the write callback.
				'methods'             => 'POST',
				'callback'            => array(__CLASS__, 'handle_update_settings'),
				'permission_callback' => array('SnowSEO_Rest_Auth', 'check_admin_permission'),
				'args'                => array(
					'remoteEnabled' => array('type' => 'boolean'),
					'robotsRepair'  => array('type' => 'boolean'),
				),
			),
		));

		register_rest_route($namespace, '/perf/apply', array(
			'methods'             => 'POST',
			'callback'            => array(__CLASS__, 'handle_apply'),
			'permission_callback' => array(__CLASS__, 'check_write_permission'),
			'args'                => $fix_arg,
		));

		// Purging is idempotent and destroys no data, but it is still a write:
		// on a large site it makes every next request rebuild its page.
		register_rest_route($namespace, '/perf/purge', array(
			'methods'             => 'POST',
			'callback'            => array(__CLASS__, 'handle_purge'),
			'permission_callback' => array(__CLASS__, 'check_write_permission'),
		));

		register_rest_route($namespace, '/perf/revert', array(
			'methods'             => 'POST',
			'callback'            => array(__CLASS__, 'handle_revert'),
			'permission_callback' => array(__CLASS__, 'check_write_permission'),
			'args'                => $fix_arg,
		));
	}

	public static function handle_get_settings($request)
	{
		return new WP_REST_Response(self::perf_payload(true), 200);
	}

	public static function handle_update_settings($request)
	{
		$remote = $request->get_param('remoteEnabled');
		if (null !== $remote) {
			update_option(self::OPTION_REMOTE, rest_sanitize_boolean($remote) ? '1' : '0');
		}
		$robots = $request->get_param('robotsRepair');
		if (null !== $robots) {
			$on = rest_sanitize_boolean($robots);
			update_option(SnowSEO_Perf_Robots::OPTION_REPAIR, $on ? '1' : '0');
			self::note(self::FIX_ROBOTS, $on, 'admin');
		}

		return new WP_REST_Response(self::perf_payload(false), 200);
	}

	public static function handle_apply($request)
	{
		return self::run_action($request->get_param('fix'), 'apply', $request);
	}

	public static function handle_revert($request)
	{
		return self::run_action($request->get_param('fix'), 'revert', $request);
	}

	/**
	 * Empty every page cache we can reach.
	 *
	 * Its own route because verification needs it independently of any fix: a
	 * measurement taken against a cached copy of the old page is worse than no
	 * measurement, since it looks like a real result.
	 */
	public static function handle_purge($request)
	{
		unset($request);
		$cleared = SnowSEO_Perf_Guard::purge_caches();

		return new WP_REST_Response(array('cleared' => $cleared), 200);
	}

	/**
	 * Apply or revert one fix.
	 *
	 * The enum on the route arg already constrains `fix`, but it is re-checked
	 * here: the plugin belt-and-braces this way elsewhere too (handle_invalidate
	 * re-checks teamId after its permission callback already passed).
	 */
	private static function run_action($fix, $action, $request)
	{
		if (! in_array($fix, self::fixes(), true)) {
			return new WP_Error('perf_unknown_fix', __('Unknown performance fix.', 'snowseo'), array('status' => 400));
		}
		if (! self::acquire_lock()) {
			return new WP_Error('perf_busy', __('Another performance change is already in progress. Try again shortly.', 'snowseo'), array('status' => 409));
		}

		$actor = SnowSEO_Rest_Auth::check_admin_permission() ? 'admin' : 'remote';
		try {
			$result = ('apply' === $action)
				? self::apply_fix($fix, $actor)
				: self::revert_fix($fix, $actor);
		} finally {
			self::release_lock();
		}

		if (is_wp_error($result)) {
			$data = (array) $result->get_error_data();
			$status = isset($data['status']) ? (int) $data['status'] : 409;
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array('status' => $status, 'perf' => self::perf_payload(false))
			);
		}

		return new WP_REST_Response(self::perf_payload(false), 200);
	}

	private static function apply_fix($fix, $actor)
	{
		if (self::FIX_ROBOTS === $fix) {
			// A physical file is matched before WordPress runs, so our filter can
			// never reach it. Refuse and report rather than overwrite something
			// the owner put there deliberately.
			if (SnowSEO_Perf_Robots::shadowed()) {
				return new WP_Error(
					'perf_physical_robots',
					__('This site serves a robots.txt file from disk, which WordPress cannot modify. Replace its contents by hand with the text shown on the SnowSEO Performance screen.', 'snowseo'),
					array('status' => 409)
				);
			}
			if (! (int) get_option('blog_public', 1)) {
				return new WP_Error(
					'perf_site_not_public',
					__('This site is set to discourage search engines, so its robots.txt intentionally blocks crawling. Change that under Settings > Reading first.', 'snowseo'),
					array('status' => 409)
				);
			}
			update_option(SnowSEO_Perf_Robots::OPTION_REPAIR, '1');
			self::note($fix, true, $actor);
			return true;
		}

		// The render optimizer verifies itself before it stays on, so it owns its
		// own apply path rather than being one option write like the others.
		if (self::FIX_RENDER_BLOCKING === $fix) {
			$result = SnowSEO_Perf_Render::apply();
			if (is_wp_error($result)) {
				return $result;
			}
			self::note($fix, true, $actor);
			return true;
		}

		// Filter-based fixes touch no files, so there is nothing to refuse over:
		// no server type to support, no permissions, nothing another plugin can
		// own. They are one option write and they work everywhere.
		$option = self::option_for($fix);
		if ('' !== $option) {
			update_option($option, '1');
			self::note($fix, true, $actor);
			return true;
		}

		return self::apply_htaccess_fix($fix, $actor);
	}

	/**
	 * Apply one of the .htaccess-writing fixes.
	 *
	 * Shared by compression and cache headers because every refusal below is a
	 * property of writing to that file, not of the directives being written.
	 *
	 * @param string $fix
	 * @param string $actor
	 * @return true|WP_Error
	 */
	private static function apply_htaccess_fix($fix, $actor)
	{
		$block = self::block_for($fix);
		if ('' === $block) {
			return new WP_Error('perf_unknown_fix', __('Unknown performance fix.', 'snowseo'), array('status' => 400));
		}
		$concern = self::concern_label($fix);

		$handler = SnowSEO_Perf_Htaccess::foreign_handler();
		if ('' !== $handler) {
			return new WP_Error(
				'perf_handled_by_plugin',
				sprintf(
					/* translators: 1: name of the plugin or host, 2: what it manages. */
					__('%1$s already manages %2$s on this site. Change it in that plugin\'s settings instead - a block added here would be overwritten.', 'snowseo'),
					$handler,
					$concern
				),
				array('status' => 409)
			);
		}
		if (SnowSEO_Perf_Htaccess::has_foreign_directives($block)) {
			return new WP_Error(
				'perf_existing_directives',
				sprintf(
					/* translators: %s: what the existing rules control. */
					__('This site\'s .htaccess already contains %s rules that SnowSEO did not add. They have been left untouched.', 'snowseo'),
					$concern
				),
				array('status' => 409)
			);
		}
		// get_home_path() on subdirectory multisite resolves to the NETWORK root,
		// so one site's admin would be editing the whole network's config.
		if (is_multisite() && 'admin' !== $actor) {
			return new WP_Error(
				'perf_multisite',
				__('On a multisite network this change has to be made by a network administrator.', 'snowseo'),
				array('status' => 409)
			);
		}

		// Verified install: proves the site still serves afterwards and puts the
		// block back if it does not. See install_verified().
		$result = SnowSEO_Perf_Htaccess::install_verified($block);
		if (is_wp_error($result)) {
			return $result;
		}
		update_option(self::mirror_option_for($fix), '1');
		self::note($fix, true, $actor);

		return true;
	}

	private static function revert_fix($fix, $actor)
	{
		if (self::FIX_ROBOTS === $fix) {
			update_option(SnowSEO_Perf_Robots::OPTION_REPAIR, '0');
			self::note($fix, false, $actor);
			return true;
		}

		if (self::FIX_RENDER_BLOCKING === $fix) {
			SnowSEO_Perf_Render::revert();
			self::note($fix, false, $actor);
			return true;
		}

		$option = self::option_for($fix);
		if ('' !== $option) {
			update_option($option, '0');
			self::note($fix, false, $actor);
			return true;
		}

		$block = self::block_for($fix);
		if ('' === $block) {
			return new WP_Error('perf_unknown_fix', __('Unknown performance fix.', 'snowseo'), array('status' => 400));
		}
		// Clear the flag even when the block is already gone, so a hand-deleted
		// block cannot leave the option claiming the fix is still on.
		if (! SnowSEO_Perf_Htaccess::installed($block)) {
			update_option(self::mirror_option_for($fix), '0');
			self::note($fix, false, $actor);
			return true;
		}

		$result = SnowSEO_Perf_Htaccess::remove($block);
		if (is_wp_error($result)) {
			return $result;
		}
		update_option(self::mirror_option_for($fix), '0');
		self::note($fix, false, $actor);

		return true;
	}

	/**
	 * The option that mirrors a .htaccess block's presence.
	 *
	 * The file is authoritative - installed() reads it - but the option is what
	 * the Settings > Reading checkbox binds to, so it has to track.
	 *
	 * @param string $fix
	 * @return string
	 */
	private static function mirror_option_for($fix)
	{
		return self::FIX_CACHE_HEADERS === $fix
			? self::OPTION_CACHE_HEADERS
			: self::OPTION_COMPRESSION;
	}

	/** What a .htaccess fix controls, for messages that name it. */
	private static function concern_label($fix)
	{
		return self::FIX_CACHE_HEADERS === $fix
			? __('browser caching', 'snowseo')
			: __('compression', 'snowseo');
	}

	// ─── Payload ──────────────────────────────────────────────────────────────

	/**
	 * The one response shape every route returns, so the React page can do
	 * `.then(setSettings)` after any of them.
	 *
	 * @param bool $probe Run loopback self-probes. GET only - a write path must
	 *                    never be able to hang on a loopback request.
	 */
	public static function perf_payload($probe = false)
	{
		return array(
			'remoteEnabled'   => self::remote_allowed(),
			'robotsRepair'    => SnowSEO_Perf_Robots::is_enabled(),
			'fileModsAllowed' => SnowSEO_FS::file_mods_allowed(),
			'multisite'       => is_multisite(),
			'server'          => array(
				'label'            => SnowSEO_Perf_Htaccess::server_label(),
				'type'             => SnowSEO_Perf_Htaccess::server_type(),
				'readsHtaccess'    => SnowSEO_Perf_Htaccess::reads_htaccess(),
				'htaccessPath'     => SnowSEO_Perf_Htaccess::path(),
				'htaccessExists'   => '' !== SnowSEO_Perf_Htaccess::path() && file_exists(SnowSEO_Perf_Htaccess::path()),
				'htaccessWritable' => self::htaccess_writable(),
			),
			'caches'      => function_exists('snowseo_page_caches_active') ? snowseo_page_caches_active() : array(),
			'fixes'       => array(
				self::FIX_COMPRESSION   => self::htaccess_status(self::FIX_COMPRESSION, $probe),
				self::FIX_ROBOTS        => self::robots_status($probe),
				self::FIX_CACHE_HEADERS => self::htaccess_status(self::FIX_CACHE_HEADERS, $probe),
				self::FIX_FONT_DISPLAY  => self::filter_fix_status(self::FIX_FONT_DISPLAY),
				self::FIX_PRECONNECT    => self::filter_fix_status(self::FIX_PRECONNECT),
				self::FIX_RENDER_BLOCKING => SnowSEO_Perf_Render::status(),
			),
			'lastActions' => self::recent_actions(),
		);
	}

	private static function htaccess_writable()
	{
		$path = SnowSEO_Perf_Htaccess::path();
		if ('' === $path) {
			return false;
		}
		if (file_exists($path)) {
			return wp_is_writable($path);
		}
		$home = SnowSEO_FS::home_path();

		return '' !== $home && wp_is_writable($home);
	}

	/**
	 * Status vocabulary, and what each value means to the SnowSEO backend:
	 *
	 *  applied         - done, on this site, now. Mark the issue fixed.
	 *  available       - we can do it. Offer the button.
	 *  already-handled - someone else owns this. Not a failure, not applicable.
	 *  unsupported     - impossible from PHP here (nginx, IIS). Never retry.
	 *  manual          - a human must act; `snippet` says exactly what to paste.
	 *  blocked         - possible in principle, prevented by something the owner
	 *                    can change (DISALLOW_FILE_MODS, permissions). Retryable.
	 */
	/**
	 * Status of one .htaccess-writing fix.
	 *
	 * Shared by compression and cache headers: every branch below is a fact about
	 * whether this site can be written to at all, which is identical for both. The
	 * only per-fix parts are the block, the words, and whether there is a probe.
	 *
	 * @param string $fix
	 * @param bool   $probe
	 * @return array
	 */
	private static function htaccess_status($fix, $probe)
	{
		$block   = self::block_for($fix);
		$concern = self::concern_label($fix);
		$state   = self::state_for($fix);
		$out = array(
			'id'         => $fix,
			'appliedAt'  => isset($state['at']) ? (int) $state['at'] : 0,
			'appliedBy'  => isset($state['by']) ? (string) $state['by'] : '',
			'snippet'    => SnowSEO_Perf_Htaccess::snippet($block),
			'handler'    => '',
			'revertable' => SnowSEO_Perf_Htaccess::installed($block),
			// Only compression has a self-probe: an outside request can see whether
			// a response came back compressed, but it cannot tell a cache header we
			// set from one the host already sent.
			'probe'      => ($probe && self::FIX_COMPRESSION === $fix) ? self::probe_compression() : null,
		);

		if (SnowSEO_Perf_Htaccess::installed($block)) {
			return array_merge($out, array(
				'status'  => 'applied',
				'reason'  => 'ok',
				'message' => self::FIX_CACHE_HEADERS === $fix
					? __('SnowSEO added browser cache rules to this site\'s .htaccess.', 'snowseo')
					: __('SnowSEO added a compression block to this site\'s .htaccess.', 'snowseo'),
				'remedy'  => '',
			));
		}
		if (! SnowSEO_Perf_Htaccess::reads_htaccess()) {
			$label = SnowSEO_Perf_Htaccess::server_label();
			return array_merge($out, array(
				'status'  => 'unsupported',
				'reason'  => 'no_htaccess_server',
				'message' => sprintf(
					/* translators: 1: web server name, 2: what the fix controls. */
					__('This site runs on %1$s, which does not read .htaccess, so SnowSEO cannot set up %2$s for you.', 'snowseo'),
					$label,
					$concern
				),
				'remedy'  => self::FIX_CACHE_HEADERS === $fix
					? __('Ask your host to set long Cache-Control lifetimes for images, fonts and static assets.', 'snowseo')
					: __('Ask your host to enable gzip or brotli compression (nginx: `gzip on;` with `gzip_types`; Caddy: `encode gzip zstd`).', 'snowseo'),
			));
		}
		$handler = SnowSEO_Perf_Htaccess::foreign_handler();
		if ('' !== $handler) {
			return array_merge($out, array(
				'status'  => 'already-handled',
				'reason'  => 'handled_by_plugin',
				'handler' => $handler,
				'message' => sprintf(
					/* translators: 1: plugin or host name, 2: what it manages. */
					__('%1$s already manages %2$s on this site.', 'snowseo'),
					$handler,
					$concern
				),
				'remedy'  => sprintf(
					/* translators: 1: plugin or host name, 2: what it manages. */
					__('Set %2$s up in %1$s. A block added by SnowSEO would be overwritten.', 'snowseo'),
					$handler,
					$concern
				),
			));
		}
		if (SnowSEO_Perf_Htaccess::has_foreign_directives($block)) {
			return array_merge($out, array(
				'status'  => 'already-handled',
				'reason'  => 'existing_directives',
				'message' => sprintf(
					/* translators: %s: what the existing rules control. */
					__('This site\'s .htaccess already has %s rules that SnowSEO did not add.', 'snowseo'),
					$concern
				),
				'remedy'  => __('Those rules were left untouched. Check them if PageSpeed still reports a problem.', 'snowseo'),
			));
		}
		if (! SnowSEO_FS::file_mods_allowed()) {
			return array_merge($out, array(
				'status'  => 'blocked',
				'reason'  => 'file_mods',
				'message' => __('File changes are disabled on this site (DISALLOW_FILE_MODS).', 'snowseo'),
				'remedy'  => __('Remove the DISALLOW_FILE_MODS constant from wp-config.php, or add the block below by hand.', 'snowseo'),
			));
		}
		if ('' === SnowSEO_Perf_Htaccess::path()) {
			return array_merge($out, array(
				'status'  => 'blocked',
				'reason'  => 'home_path',
				'message' => __('SnowSEO could not determine this site\'s root directory.', 'snowseo'),
				'remedy'  => __('Add the block below to your site\'s .htaccess by hand.', 'snowseo'),
			));
		}
		if (! self::htaccess_writable()) {
			return array_merge($out, array(
				'status'  => 'blocked',
				'reason'  => 'htaccess_unwritable',
				'message' => __('This site\'s .htaccess is not writable by WordPress.', 'snowseo'),
				'remedy'  => __('Add the block below by hand, or make .htaccess writable and try again.', 'snowseo'),
			));
		}
		if (is_multisite()) {
			return array_merge($out, array(
				'status'  => 'unsupported',
				'reason'  => 'multisite',
				'message' => __('On a multisite network, .htaccess is shared across every site.', 'snowseo'),
				'remedy'  => __('A network administrator should add the block below once, by hand.', 'snowseo'),
			));
		}

		return array_merge($out, array(
			'status'  => 'available',
			'reason'  => 'ok',
			'message' => self::FIX_CACHE_HEADERS === $fix
				? __('SnowSEO can add browser cache rules to this site\'s .htaccess.', 'snowseo')
				: __('SnowSEO can add a compression block to this site\'s .htaccess.', 'snowseo'),
			'remedy'  => '',
		));
	}

	/**
	 * Status of a filter-based fix.
	 *
	 * These are never `unsupported` or `blocked`, and that is not optimism: they
	 * write nothing, need no server feature, and cannot collide with another
	 * plugin. Whether they have anything to do depends on what the theme enqueues,
	 * which is unknowable here - a REST request never fires wp_enqueue_scripts, so
	 * wp_styles() is empty at this point and claiming otherwise would be a guess.
	 * A fix with nothing to change is simply inert.
	 *
	 * @param string $fix
	 * @return array
	 */
	private static function filter_fix_status($fix)
	{
		$state = self::state_for($fix);
		$on = '1' === (string) get_option(self::option_for($fix), '0');
		$applied_message = self::FIX_FONT_DISPLAY === $fix
			? __('Text stays visible while web fonts load.', 'snowseo')
			: __('The browser connects early to the font and script hosts this site uses.', 'snowseo');
		$available_message = self::FIX_FONT_DISPLAY === $fix
			? __('SnowSEO can keep text visible while web fonts load, instead of leaving it blank.', 'snowseo')
			: __('SnowSEO can tell the browser to connect early to the font and script hosts this site already uses.', 'snowseo');

		return array(
			'id'         => $fix,
			'appliedAt'  => isset($state['at']) ? (int) $state['at'] : 0,
			'appliedBy'  => isset($state['by']) ? (string) $state['by'] : '',
			'snippet'    => '',
			'handler'    => '',
			'revertable' => $on,
			'probe'      => null,
			'status'     => $on ? 'applied' : 'available',
			'reason'     => 'ok',
			'message'    => $on ? $applied_message : $available_message,
			'remedy'     => '',
		);
	}

	private static function robots_status($probe)
	{
		$state = self::state_for(self::FIX_ROBOTS);
		$physical = SnowSEO_Perf_Robots::physical_path();
		$probe_result = $probe ? self::probe_robots() : null;
		$out = array(
			'id'         => self::FIX_ROBOTS,
			'appliedAt'  => isset($state['at']) ? (int) $state['at'] : 0,
			'appliedBy'  => isset($state['by']) ? (string) $state['by'] : '',
			'handler'    => '',
			'revertable' => SnowSEO_Perf_Robots::is_enabled(),
			'probe'      => $probe_result,
			'snippet'    => '',
		);

		if ('' !== $physical) {
			// Offer, never apply: hand back what is there and what it should be.
			$current = (string) @file_get_contents($physical, false, null, 0, self::MAX_ROBOTS_BYTES);
			return array_merge($out, array(
				'status'         => 'manual',
				'reason'         => 'physical_robots',
				'message'        => __('This site serves a robots.txt file from disk. WordPress never sees the request, so SnowSEO cannot repair it automatically.', 'snowseo'),
				'remedy'         => __('Replace that file\'s contents with the corrected text below.', 'snowseo'),
				'physicalPath'   => $physical,
				'currentContent' => $current,
				'snippet'        => SnowSEO_Perf_Robots::normalize($current),
			));
		}
		if (! (int) get_option('blog_public', 1)) {
			return array_merge($out, array(
				'status'  => 'unsupported',
				'reason'  => 'site_not_public',
				'message' => __('This site is set to discourage search engines, so its robots.txt intentionally blocks crawling.', 'snowseo'),
				'remedy'  => __('Change "Search engine visibility" under Settings > Reading if that is not intended.', 'snowseo'),
			));
		}
		if (is_array($probe_result) && isset($probe_result['status']) && (int) $probe_result['status'] >= 400) {
			return array_merge($out, array(
				'status'  => 'manual',
				'reason'  => 'robots_unreachable',
				'message' => sprintf(
					/* translators: %d: HTTP status code returned by /robots.txt. */
					__('Requesting /robots.txt on this site returns HTTP %d, so search engines cannot read it and SnowSEO\'s repair would never run.', 'snowseo'),
					(int) $probe_result['status']
				),
				'remedy'  => __('A security plugin, firewall, or server rule is blocking /robots.txt. Allow it, then re-check.', 'snowseo'),
			));
		}
		if (SnowSEO_Perf_Robots::is_enabled()) {
			return array_merge($out, array(
				'status'  => 'applied',
				'reason'  => 'ok',
				'message' => __('SnowSEO removes invalid lines from this site\'s generated robots.txt.', 'snowseo'),
				'remedy'  => '',
			));
		}

		return array_merge($out, array(
			'status'  => 'available',
			'reason'  => 'ok',
			'message' => __('SnowSEO can clean up invalid lines in this site\'s generated robots.txt.', 'snowseo'),
			'remedy'  => '',
		));
	}

	// ─── Self-probes ──────────────────────────────────────────────────────────

	/**
	 * Measured, not guessed.
	 *
	 * Constant sniffing tells us which plugins are loaded. It cannot tell us that
	 * /robots.txt returns 403 behind a firewall plugin, or that nginx is already
	 * gzipping everything. One loopback request answers both, which is the
	 * difference between a useful answer and "not possible here".
	 *
	 * Cached in a transient so a chatty backend cannot loopback-hammer the site,
	 * with a short timeout because a host with a single PHP worker can deadlock
	 * on a loopback (WordPress Site Health has the same exposure and handles it
	 * the same way). A failure is recorded, never fatal.
	 */
	private static function probe_robots()
	{
		$cached = get_transient('snowseo_perf_probe_robots');
		if (is_array($cached)) {
			return $cached;
		}
		$response = wp_remote_get(home_url('/robots.txt'), array(
			'timeout'     => 5,
			'redirection' => 2,
			'headers'     => array('Accept' => 'text/plain'),
		));
		$result = array('checkedAt' => time(), 'status' => 0, 'contentType' => '', 'error' => '');
		if (is_wp_error($response)) {
			$result['error'] = $response->get_error_message();
		} else {
			$result['status'] = (int) wp_remote_retrieve_response_code($response);
			$result['contentType'] = (string) wp_remote_retrieve_header($response, 'content-type');
		}
		set_transient('snowseo_perf_probe_robots', $result, self::PROBE_ROBOTS_TTL);

		return $result;
	}

	private static function probe_compression()
	{
		$cached = get_transient('snowseo_perf_probe_compression');
		if (is_array($cached)) {
			return $cached;
		}
		$response = wp_remote_get(home_url('/'), array(
			'timeout'     => 5,
			'redirection' => 2,
			'decompress'  => false,
			'headers'     => array('Accept-Encoding' => 'gzip, br'),
		));
		$result = array('checkedAt' => time(), 'status' => 0, 'encoding' => '', 'error' => '');
		if (is_wp_error($response)) {
			$result['error'] = $response->get_error_message();
		} else {
			$result['status'] = (int) wp_remote_retrieve_response_code($response);
			$result['encoding'] = (string) wp_remote_retrieve_header($response, 'content-encoding');
		}
		set_transient('snowseo_perf_probe_compression', $result, self::PROBE_COMPRESSION_TTL);

		return $result;
	}

	// ─── State, audit trail, lock ─────────────────────────────────────────────

	private static function state_for($fix)
	{
		$state = get_option(self::OPTION_STATE, array());
		if (! is_array($state) || ! isset($state[$fix]) || ! is_array($state[$fix])) {
			return array();
		}

		return $state[$fix];
	}

	private static function note($fix, $applied, $actor)
	{
		$state = get_option(self::OPTION_STATE, array());
		if (! is_array($state)) {
			$state = array();
		}
		$state[$fix] = array(
			'at'         => time(),
			'by'         => $actor,
			'applied'    => (bool) $applied,
			'generation' => SnowSEO_Perf_Htaccess::GENERATION,
		);
		update_option(self::OPTION_STATE, $state, false);

		SnowSEO_Log::write(
			'success',
			sprintf(
				'Performance fix %s %s by %s',
				$fix,
				$applied ? 'applied' : 'reverted',
				$actor
			)
		);
	}

	/** Recent perf entries from the shared activity log. */
	private static function recent_actions()
	{
		$logs = get_option('snowseo_activity_logs', array());
		if (! is_array($logs)) {
			return array();
		}
		$out = array();
		foreach ($logs as $entry) {
			if (is_array($entry) && isset($entry['message']) && 0 === strpos((string) $entry['message'], 'Performance fix ')) {
				$out[] = $entry;
			}
		}

		return array_slice($out, 0, 10);
	}

	/**
	 * Test-and-set lock.
	 *
	 * write_atomic()'s LOCK_EX is on the TEMP file and is useless as a mutex, so
	 * two concurrent applies could both miss the marker and both append. add_option
	 * is genuinely atomic (the UNIQUE index on wp_options.option_name enforces it),
	 * which makes it a usable lock primitive.
	 */
	private static function acquire_lock()
	{
		if (add_option(self::OPTION_LOCK, time(), '', false)) {
			return true;
		}
		$held = (int) get_option(self::OPTION_LOCK, 0);
		if ($held > 0 && (time() - $held) < self::LOCK_TTL) {
			return false;
		}
		// Steal a lock a crashed request left behind.
		update_option(self::OPTION_LOCK, time(), false);

		return true;
	}

	private static function release_lock()
	{
		delete_option(self::OPTION_LOCK);
	}

	// ─── Settings > Reading escape hatch ──────────────────────────────────────

	public static function register_settings()
	{
		$options = array(
			self::OPTION_REMOTE,
			SnowSEO_Perf_Robots::OPTION_REPAIR,
			self::OPTION_COMPRESSION,
			self::OPTION_CACHE_HEADERS,
			SnowSEO_Perf_Assets::OPTION_FONT_DISPLAY,
			SnowSEO_Perf_Assets::OPTION_PRECONNECT,
		);
		foreach ($options as $option) {
			register_setting('reading', $option, array(
				'type'              => 'string',
				'sanitize_callback' => array(__CLASS__, 'sanitize_flag'),
				'default'           => '0',
				'show_in_rest'      => false,
			));
		}

		add_settings_field(
			'snowseo_perf',
			__('SnowSEO performance fixes', 'snowseo'),
			array(__CLASS__, 'render_settings_field'),
			'reading',
			'default'
		);

		// The compression checkbox writes a FILE. Do the write before the option
		// is persisted and return the old value on failure, so the stored flag can
		// never claim a state that is not actually on disk. pre_update_option_ is
		// the right hook precisely because whatever it returns is what gets stored;
		// doing this in sanitize_callback would put a side effect in a validator,
		// and doing it in update_option_ would re-enter update_option from its own
		// hook.
		add_filter('pre_update_option_' . self::OPTION_COMPRESSION, array(__CLASS__, 'pre_update_compression'), 10, 2);
		add_filter('pre_add_option_' . self::OPTION_COMPRESSION, array(__CLASS__, 'pre_add_compression'), 10, 2);
		add_filter('pre_update_option_' . self::OPTION_CACHE_HEADERS, array(__CLASS__, 'pre_update_cache_headers'), 10, 2);
		add_filter('pre_add_option_' . self::OPTION_CACHE_HEADERS, array(__CLASS__, 'pre_add_cache_headers'), 10, 2);
	}

	public static function sanitize_flag($value)
	{
		return ('1' === (string) $value || 'on' === $value || true === $value) ? '1' : '0';
	}

	public static function pre_update_compression($value, $old)
	{
		return self::write_block_for_option(self::FIX_COMPRESSION, $value, $old);
	}

	public static function pre_add_compression($value, $option)
	{
		unset($option);

		return self::write_block_for_option(self::FIX_COMPRESSION, $value, '0');
	}

	public static function pre_update_cache_headers($value, $old)
	{
		return self::write_block_for_option(self::FIX_CACHE_HEADERS, $value, $old);
	}

	/**
	 * pre_add_option_ passes the DEFAULT as its second argument, not a stored
	 * value, so routing it through the same handler as pre_update_option_ would
	 * compare against the wrong thing. An option being added for the first time
	 * has no previous value, so '0' is the only correct baseline.
	 */
	public static function pre_add_cache_headers($value, $option)
	{
		unset($option);

		return self::write_block_for_option(self::FIX_CACHE_HEADERS, $value, '0');
	}

	/**
	 * Write the file BEFORE the option is persisted, and return the old value on
	 * failure, so the stored flag can never claim a state that is not actually on
	 * disk. pre_update_option_ is the right hook precisely because whatever it
	 * returns is what gets stored; doing this in sanitize_callback would put a
	 * side effect in a validator, and doing it in update_option_ would re-enter
	 * update_option from its own hook.
	 *
	 * @param string $fix
	 * @param mixed  $value
	 * @param mixed  $old
	 * @return mixed
	 */
	private static function write_block_for_option($fix, $value, $old)
	{
		if ((string) $value === (string) $old) {
			return $value;
		}

		// Same lock the REST path takes. Without it an admin saving this screen
		// and a remote /perf/apply can both read .htaccess before either writes,
		// and the second write silently drops the first one's block.
		if (! self::acquire_lock()) {
			add_settings_error(
				'snowseo_perf',
				'snowseo_perf_busy',
				__('Another performance change is already in progress. Please try again in a moment.', 'snowseo'),
				'error'
			);
			return $old;
		}

		$block = self::block_for($fix);
		try {
			$result = ('1' === (string) $value)
				? SnowSEO_Perf_Htaccess::install_verified($block)
				: SnowSEO_Perf_Htaccess::remove($block);
		} finally {
			self::release_lock();
		}

		if (is_wp_error($result)) {
			add_settings_error('snowseo_perf', 'snowseo_perf_htaccess', $result->get_error_message(), 'error');
			// The block was already rolled back, so the toggle must fall back to
			// its old value - otherwise the screen would claim a fix is on while
			// .htaccess no longer has it.
			return $old;
		}
		self::note($fix, '1' === (string) $value, 'admin');

		return $value;
	}

	public static function render_settings_field()
	{
		$path = SnowSEO_Perf_Htaccess::path();
		?>
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php esc_html_e('SnowSEO performance fixes', 'snowseo'); ?></span>
			</legend>

			<input type="hidden" name="<?php echo esc_attr(self::OPTION_REMOTE); ?>" value="0" />
			<label for="snowseo_perf_remote">
				<input type="checkbox" id="snowseo_perf_remote"
					name="<?php echo esc_attr(self::OPTION_REMOTE); ?>" value="1"
					<?php checked('1', (string) get_option(self::OPTION_REMOTE, '0')); ?> />
				<?php esc_html_e('Allow SnowSEO to apply performance fixes to this site', 'snowseo'); ?>
			</label>
			<p class="description">
				<?php esc_html_e('Off by default. SnowSEO can only apply fixes from a fixed list built into this plugin; it can never send its own file contents or server directives.', 'snowseo'); ?>
			</p>

			<br />
			<input type="hidden" name="<?php echo esc_attr(SnowSEO_Perf_Robots::OPTION_REPAIR); ?>" value="0" />
			<label for="snowseo_perf_robots">
				<input type="checkbox" id="snowseo_perf_robots"
					name="<?php echo esc_attr(SnowSEO_Perf_Robots::OPTION_REPAIR); ?>" value="1"
					<?php checked('1', (string) get_option(SnowSEO_Perf_Robots::OPTION_REPAIR, '0')); ?> />
				<?php esc_html_e('Remove invalid lines from the robots.txt WordPress generates', 'snowseo'); ?>
			</label>

			<br />
			<input type="hidden" name="<?php echo esc_attr(self::OPTION_COMPRESSION); ?>" value="0" />
			<label for="snowseo_perf_compression">
				<input type="checkbox" id="snowseo_perf_compression"
					name="<?php echo esc_attr(self::OPTION_COMPRESSION); ?>" value="1"
					<?php checked('1', (string) get_option(self::OPTION_COMPRESSION, '0')); ?> />
				<?php esc_html_e('Enable text compression (gzip / brotli) via .htaccess', 'snowseo'); ?>
			</label>
			<br />
			<input type="hidden" name="<?php echo esc_attr(self::OPTION_CACHE_HEADERS); ?>" value="0" />
			<label for="snowseo_perf_cache_headers">
				<input type="checkbox" id="snowseo_perf_cache_headers"
					name="<?php echo esc_attr(self::OPTION_CACHE_HEADERS); ?>" value="1"
					<?php checked('1', (string) get_option(self::OPTION_CACHE_HEADERS, '0')); ?> />
				<?php esc_html_e('Set browser cache lifetimes for images, fonts and static files via .htaccess', 'snowseo'); ?>
			</label>

			<br />
			<input type="hidden" name="<?php echo esc_attr(SnowSEO_Perf_Assets::OPTION_FONT_DISPLAY); ?>" value="0" />
			<label for="snowseo_perf_font_display">
				<input type="checkbox" id="snowseo_perf_font_display"
					name="<?php echo esc_attr(SnowSEO_Perf_Assets::OPTION_FONT_DISPLAY); ?>" value="1"
					<?php checked('1', (string) get_option(SnowSEO_Perf_Assets::OPTION_FONT_DISPLAY, '0')); ?> />
				<?php esc_html_e('Keep text visible while web fonts load', 'snowseo'); ?>
			</label>

			<br />
			<input type="hidden" name="<?php echo esc_attr(SnowSEO_Perf_Assets::OPTION_PRECONNECT); ?>" value="0" />
			<label for="snowseo_perf_preconnect">
				<input type="checkbox" id="snowseo_perf_preconnect"
					name="<?php echo esc_attr(SnowSEO_Perf_Assets::OPTION_PRECONNECT); ?>" value="1"
					<?php checked('1', (string) get_option(SnowSEO_Perf_Assets::OPTION_PRECONNECT, '0')); ?> />
				<?php esc_html_e('Connect early to the font and script hosts this site already uses', 'snowseo'); ?>
			</label>

			<?php if ('' !== $path) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: path to .htaccess, 2: the marker comment that delimits the block. */
						esc_html__('Adds a block marked %2$s to %1$s. Delete that block to undo it by hand.', 'snowseo'),
						'<code>' . esc_html($path) . '</code>',
						'<code>' . esc_html(SnowSEO_Perf_Htaccess::MARKER_BEGIN) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>
		</fieldset>
		<?php
	}
}
