<?php

/**
 * Marker-delimited directive blocks in the site-root .htaccess.
 *
 * Two blocks today - compression and browser cache headers - each written and
 * removed independently under its own `# BEGIN` / `# END` pair, so undoing one
 * never disturbs the other. The compression block keeps its original marker
 * text: sites already running it have that string on disk, and changing it
 * would orphan their block where neither this class nor uninstall.php could
 * find it again.
 *
 * Deliberately NOT using core's insert_with_markers(). That helper opens the
 * file r+, seeks, and ftruncates in place, so a killed worker or a full disk can
 * leave a truncated site-root .htaccess - which makes Apache return 500 for
 * every request on the site. This is the single worst failure available in this
 * feature, so we write through SnowSEO_FS::write_atomic() instead, while keeping
 * insert_with_markers-COMPATIBLE `# BEGIN` / `# END` markers so core's
 * extract_from_markers(), other tooling, and humans all still recognise it.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Perf_Htaccess
{
	const MARKER_BEGIN = '# BEGIN SnowSEO Performance';
	const MARKER_END   = '# END SnowSEO Performance';

	const BLOCK_COMPRESSION = 'compression';
	const BLOCK_EXPIRES     = 'expires';

	/** Bumped when an emitted block changes, so an upgrade rewrites it. */
	const GENERATION = '1';

	/** Every block this class knows how to write. */
	public static function blocks()
	{
		return array(self::BLOCK_COMPRESSION, self::BLOCK_EXPIRES);
	}

	/**
	 * Opening marker for a block.
	 *
	 * `# BEGIN SnowSEO Performance` is NOT a prefix of `# BEGIN SnowSEO Cache
	 * Headers`, so a plain strpos for either one can never match the other.
	 *
	 * @param string $block
	 * @return string
	 */
	private static function marker_begin($block)
	{
		return self::BLOCK_EXPIRES === $block
			? '# BEGIN SnowSEO Cache Headers'
			: self::MARKER_BEGIN;
	}

	/**
	 * @param string $block
	 * @return string
	 */
	private static function marker_end($block)
	{
		return self::BLOCK_EXPIRES === $block
			? '# END SnowSEO Cache Headers'
			: self::MARKER_END;
	}

	/**
	 * Human label for the web server. Parsed only for display; the decision of
	 * whether .htaccess is read at all is reads_htaccess().
	 *
	 * @return string
	 */
	public static function server_label()
	{
		$software = isset($_SERVER['SERVER_SOFTWARE'])
			? strtolower((string) $_SERVER['SERVER_SOFTWARE'])
			: '';

		// LiteSpeed must be tested before Apache: LSWS and OpenLiteSpeed both
		// report "LiteSpeed" and WordPress treats them as Apache-compatible.
		if (false !== strpos($software, 'litespeed')) {
			return 'LiteSpeed';
		}
		if (false !== strpos($software, 'apache')) {
			return 'Apache';
		}
		if (false !== strpos($software, 'nginx')) {
			return 'nginx';
		}
		if (false !== strpos($software, 'iis') || false !== strpos($software, 'microsoft')) {
			return 'IIS';
		}

		return 'unknown';
	}

	/**
	 * Machine value for the capability payload.
	 *
	 * @return string apache|litespeed|nginx|iis|unknown
	 */
	public static function server_type()
	{
		$label = self::server_label();

		return 'unknown' === $label ? 'unknown' : strtolower($label);
	}

	/**
	 * Whether this server reads .htaccess at all.
	 *
	 * $GLOBALS['is_apache'] is set by wp-includes/vars.php from SERVER_SOFTWARE
	 * for BOTH Apache and LiteSpeed, which is exactly the question being asked,
	 * and it is populated long before any REST request runs.
	 *
	 * @return bool
	 */
	public static function reads_htaccess()
	{
		return ! empty($GLOBALS['is_apache']);
	}

	/**
	 * Path to the site-root .htaccess, or '' when the home path is unresolvable.
	 *
	 * @return string
	 */
	public static function path()
	{
		$home = SnowSEO_FS::home_path();

		return '' === $home ? '' : $home . '.htaccess';
	}

	/**
	 * The exact directive block written to disk, for one block id.
	 *
	 * Every choice here is deliberate:
	 * - Everything is wrapped in <IfModule>. An absent module makes the block
	 *   silently inert rather than an error, which is why we do NOT probe with
	 *   apache_get_modules() - that function only exists under mod_php, so it
	 *   would report "no compression" on most modern PHP-FPM hosts.
	 * - Brotli first: Apache applies the first matching filter, brotli compresses
	 *   better, and clients without it negotiate down via Accept-Encoding.
	 * - No woff2/jpeg/png/webp/zip: already compressed, so re-compressing burns
	 *   CPU for roughly nothing.
	 * - No `SetOutputFilter DEFLATE`: that would compress binaries too.
	 * - Vary: Accept-Encoding is mandatory or a shared cache can hand a gzipped
	 *   body to a client that never asked for one.
	 *
	 * The filter is PHP-level (already equivalent to code execution) and the REST
	 * layer never reaches it with request input. Never wire a request parameter
	 * into it: that would turn the site's API key into a shell.
	 *
	 * @param string $block
	 * @return string
	 */
	public static function snippet($block = self::BLOCK_COMPRESSION)
	{
		$body = self::BLOCK_EXPIRES === $block
			? self::expires_body()
			: self::compression_body();

		$out = self::marker_begin($block) . "\n"
			. "# Added by SnowSEO (generation " . self::GENERATION . "). Delete this whole\n"
			. "# block, or use SnowSEO > Page Speed in wp-admin, to undo it.\n"
			. $body
			. self::marker_end($block);

		/**
		 * Filter a directive block written to .htaccess.
		 *
		 * PHP-level only. Never pass request input through this.
		 *
		 * @param string $out
		 * @param string $block
		 */
		return (string) apply_filters('snowseo_perf_htaccess_snippet', $out, $block);
	}

	/** @return string */
	private static function compression_body()
	{
		return "<IfModule mod_brotli.c>\n"
			. "\tAddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/xml text/css\n"
			. "\tAddOutputFilterByType BROTLI_COMPRESS text/javascript application/javascript application/x-javascript\n"
			. "\tAddOutputFilterByType BROTLI_COMPRESS application/json application/ld+json application/xml application/rss+xml\n"
			. "\tAddOutputFilterByType BROTLI_COMPRESS image/svg+xml application/vnd.ms-fontobject font/ttf font/otf\n"
			. "</IfModule>\n"
			. "<IfModule mod_deflate.c>\n"
			. "\tAddOutputFilterByType DEFLATE text/html text/plain text/xml text/css\n"
			. "\tAddOutputFilterByType DEFLATE text/javascript application/javascript application/x-javascript\n"
			. "\tAddOutputFilterByType DEFLATE application/json application/ld+json application/xml application/rss+xml\n"
			. "\tAddOutputFilterByType DEFLATE image/svg+xml application/vnd.ms-fontobject font/ttf font/otf\n"
			. "\t<IfModule mod_headers.c>\n"
			. "\t\tHeader append Vary Accept-Encoding\n"
			. "\t</IfModule>\n"
			. "</IfModule>\n";
	}

	/**
	 * Browser cache lifetimes.
	 *
	 * The split between one year and thirty days is the one real correctness call
	 * in this block. Images, fonts and media get a year and `immutable`, because a
	 * changed image gets a new upload filename in WordPress and an unchanged one
	 * is genuinely unchanged.
	 *
	 * CSS and JavaScript get thirty days and NOT `immutable`, even though every
	 * performance guide says otherwise. WordPress appends `?ver=` to enqueued
	 * assets, which busts the cache correctly - but a theme that enqueues without
	 * a version, or hardcodes a stylesheet in the template, would serve a stale
	 * file for a year with no recovery path an ordinary site owner could find.
	 * Thirty days keeps almost all of the benefit and bounds the worst case to
	 * something survivable.
	 *
	 * @return string
	 */
	private static function expires_body()
	{
		return "<IfModule mod_expires.c>\n"
			. "\tExpiresActive On\n"
			. "\tExpiresByType image/jpeg \"access plus 1 year\"\n"
			. "\tExpiresByType image/png \"access plus 1 year\"\n"
			. "\tExpiresByType image/gif \"access plus 1 year\"\n"
			. "\tExpiresByType image/webp \"access plus 1 year\"\n"
			. "\tExpiresByType image/avif \"access plus 1 year\"\n"
			. "\tExpiresByType image/svg+xml \"access plus 1 year\"\n"
			. "\tExpiresByType image/x-icon \"access plus 1 year\"\n"
			. "\tExpiresByType font/woff2 \"access plus 1 year\"\n"
			. "\tExpiresByType font/woff \"access plus 1 year\"\n"
			. "\tExpiresByType font/ttf \"access plus 1 year\"\n"
			. "\tExpiresByType font/otf \"access plus 1 year\"\n"
			. "\tExpiresByType video/mp4 \"access plus 1 year\"\n"
			. "\tExpiresByType video/webm \"access plus 1 year\"\n"
			. "\tExpiresByType text/css \"access plus 30 days\"\n"
			. "\tExpiresByType text/javascript \"access plus 30 days\"\n"
			. "\tExpiresByType application/javascript \"access plus 30 days\"\n"
			. "</IfModule>\n"
			. "<IfModule mod_headers.c>\n"
			. "\t<FilesMatch \"\\.(jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm)$\">\n"
			. "\t\tHeader set Cache-Control \"public, max-age=31536000, immutable\"\n"
			. "\t</FilesMatch>\n"
			. "\t<FilesMatch \"\\.(css|js)$\">\n"
			. "\t\tHeader set Cache-Control \"public, max-age=2592000\"\n"
			. "\t</FilesMatch>\n"
			. "</IfModule>\n";
	}

	/** Current .htaccess contents, or null when it cannot be read. */
	private static function read()
	{
		$path = self::path();
		if ('' === $path || ! file_exists($path)) {
			return '';
		}
		$contents = file_get_contents($path);

		return is_string($contents) ? $contents : null;
	}

	/**
	 * Whether OUR block is present. Reads the file rather than an option, so a
	 * block someone deleted by hand is reported as gone.
	 *
	 * @param string $block
	 * @return bool
	 */
	public static function installed($block = self::BLOCK_COMPRESSION)
	{
		$contents = self::read();

		return is_string($contents)
			&& false !== strpos($contents, self::marker_begin($block));
	}

	/** Directives of this kind that we did NOT write, matched per block. */
	private static function foreign_pattern($block)
	{
		if (self::BLOCK_EXPIRES === $block) {
			return '/mod_expires|ExpiresActive|ExpiresByType|ExpiresDefault|Cache-Control/i';
		}

		return '/mod_deflate|mod_brotli|AddOutputFilterByType|SetOutputFilter\s+DEFLATE|mod_gzip/i';
	}

	/**
	 * Directives in the file that are NOT ours, if any.
	 *
	 * This is the "never clobber a deliberate config" check. Both SnowSEO blocks
	 * are stripped before matching, not just the one being asked about, so the
	 * cache-headers block's own `Cache-Control` lines can never be mistaken for
	 * somebody else's work.
	 *
	 * @param string $block
	 * @return bool
	 */
	public static function has_foreign_directives($block = self::BLOCK_COMPRESSION)
	{
		$contents = self::read();
		if (! is_string($contents) || '' === $contents) {
			return false;
		}
		$without_ours = $contents;
		foreach (self::blocks() as $known) {
			$stripped = preg_replace(self::block_pattern($known), '', $without_ours);
			if (is_string($stripped)) {
				$without_ours = $stripped;
			}
		}

		return 1 === preg_match(self::foreign_pattern($block), $without_ours);
	}

	/**
	 * Which other plugin or host already owns this concern, or ''.
	 *
	 * Constant sniffing, on the principle that we detect who owns a concern and
	 * defer to them rather than fight them. On
	 * LiteSpeed our block WOULD work, but LiteSpeed Cache compiles its own
	 * settings into .htaccess and regenerates them, so we would be overwritten.
	 * Naming the remedy that actually works is more useful.
	 *
	 * The list is the same for both blocks because every one of these plugins
	 * manages compression AND browser cache headers together.
	 *
	 * @return string
	 */
	public static function foreign_handler()
	{
		if (defined('WP_ROCKET_VERSION'))       return 'WP Rocket';
		if (defined('LSCWP_V'))                 return 'LiteSpeed Cache';
		if (defined('W3TC'))                    return 'W3 Total Cache';
		if (defined('WPCACHEHOME'))             return 'WP Super Cache';
		if (defined('WPO_VERSION'))             return 'WP-Optimize';
		if (defined('SG_CACHEPRESS_VERSION'))   return 'SiteGround Optimizer';
		if (class_exists('WpeCommon'))          return 'WP Engine';
		if (defined('KINSTAMU_VERSION'))        return 'Kinsta';
		if (defined('WPCOMSH_VERSION'))         return 'WordPress.com';

		return '';
	}

	/** Regex matching one whole marker block, including a trailing newline. */
	private static function block_pattern($block)
	{
		return '/^[ \t]*' . preg_quote(self::marker_begin($block), '/') . '.*?'
			. preg_quote(self::marker_end($block), '/') . '[ \t]*\r?\n?/ms';
	}

	/**
	 * Insert one block, after `# END WordPress` when present.
	 *
	 * Placement matters: save_mod_rewrite_rules() rewrites only what sits between
	 * `# BEGIN WordPress` and `# END WordPress`, so a block outside that range
	 * survives every permalink save. Compression filters and expiry headers are
	 * both order-independent with respect to rewrite rules, so nothing is lost by
	 * sitting last.
	 *
	 * @param string $block
	 * @return true|WP_Error
	 */
	public static function install($block = self::BLOCK_COMPRESSION)
	{
		if (! in_array($block, self::blocks(), true)) {
			return new WP_Error('unknown_block', __('Unknown .htaccess block.', 'snowseo'));
		}
		if (! SnowSEO_FS::file_mods_allowed()) {
			return new WP_Error('file_mods', __('File changes are disabled on this site (DISALLOW_FILE_MODS).', 'snowseo'));
		}
		if (! self::reads_htaccess()) {
			return new WP_Error('no_htaccess_server', __('This server does not read .htaccess, so this cannot be enabled from WordPress.', 'snowseo'));
		}

		$home = SnowSEO_FS::home_path();
		if ('' === $home) {
			return new WP_Error('home_path', __('Could not determine this site\'s root directory.', 'snowseo'));
		}
		$path = self::path();

		// Remove any previous (or duplicated) copy of THIS block first, so install
		// is a true upgrade rather than an append. Other blocks are untouched.
		$removed = self::remove($block);
		if (is_wp_error($removed)) {
			return $removed;
		}

		$exists = file_exists($path);
		if ($exists && ! is_writable($path)) {
			return new WP_Error('htaccess_unwritable', __('.htaccess is not writable. Add the block by hand instead.', 'snowseo'));
		}
		if (! $exists && ! is_writable($home)) {
			return new WP_Error('htaccess_dir_unwritable', __('The site root is not writable, so .htaccess cannot be created.', 'snowseo'));
		}

		$contents = $exists ? self::read() : '';
		if (null === $contents) {
			return new WP_Error('read', __('Could not read .htaccess.', 'snowseo'));
		}

		$snippet = self::snippet($block);
		$end_wp = '# END WordPress';
		$position = strpos($contents, $end_wp);
		if (false !== $position) {
			$insert_at = $position + strlen($end_wp);
			$updated = substr($contents, 0, $insert_at)
				. "\n\n" . $snippet . "\n"
				. substr($contents, $insert_at);
		} else {
			$updated = rtrim($contents, "\n");
			$updated = ('' === $updated ? '' : $updated . "\n\n") . $snippet . "\n";
		}

		if (! SnowSEO_FS::write_atomic($path, $updated)) {
			return new WP_Error('write', __('Could not write to .htaccess.', 'snowseo'));
		}

		return true;
	}

	/**
	 * Fetch the home page over loopback and return its HTTP status, or 0 when the
	 * request could not be made at all.
	 *
	 * Cache-busted, because a cached copy would answer 200 for a site that is
	 * now 500ing. Redirects are followed: a home URL that 301s to www is normal
	 * and not a failure.
	 *
	 * @return int
	 */
	private static function probe_home()
	{
		$url = add_query_arg('snowseo_probe', (string) time(), home_url('/'));
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				// Same filter core uses for loopback requests (see spawn_cron()):
				// a self-signed or mismatched cert on the site's own hostname must
				// not make this probe fail, and a site owner can still force
				// verification on.
				'sslverify'   => apply_filters('https_local_ssl_verify', false),
				'headers'     => array('Cache-Control' => 'no-cache'),
			)
		);
		if (is_wp_error($response)) {
			return 0;
		}
		return (int) wp_remote_retrieve_response_code($response);
	}

	/**
	 * Install a block, then prove the site still serves. Reverts if it does not.
	 *
	 * Directives here are all <IfModule>-guarded, so an absent module is ignored
	 * rather than fatal - but a host that allows mod_headers while forbidding
	 * `Header` in .htaccess (AllowOverride without FileInfo) answers 500, and
	 * nothing else would put that back. The site owner would be looking at a dead
	 * site with no idea which switch did it.
	 *
	 * A BASELINE is taken first, so this can only ever blame itself: when loopback
	 * is unusable (many hosts block it) the baseline fails too, and the block is
	 * left alone rather than being reverted over an unrelated network condition.
	 *
	 * @param string $block
	 * @return true|WP_Error
	 */
	public static function install_verified($block = self::BLOCK_COMPRESSION)
	{
		$baseline = self::probe_home();
		$can_verify = $baseline >= 200 && $baseline < 400;

		$result = self::install($block);
		if (is_wp_error($result)) {
			return $result;
		}
		if (! $can_verify) {
			return true;
		}

		$after = self::probe_home();
		// 0 = the request stopped working at all; >= 500 = the server is erroring.
		// Both mean the site got worse between two requests seconds apart, with
		// this block as the only change.
		if (0 !== $after && $after < 500) {
			return true;
		}

		self::remove($block);
		return new WP_Error(
			'htaccess_verify_failed',
			sprintf(
				/* translators: %d: HTTP status code returned after the change. */
				__('This change was undone: your site stopped responding normally (HTTP %d) right after it was applied, so it has been removed and your site restored. Your server most likely does not allow these directives in .htaccess.', 'snowseo'),
				$after
			),
			array('status' => 409)
		);
	}

	/**
	 * Remove one SnowSEO block from .htaccess.
	 *
	 * No match limit on the replace: if a duplicate ever slipped through, one
	 * revert cleans up all of them.
	 *
	 * @param string $block
	 * @return true|WP_Error
	 */
	public static function remove($block = self::BLOCK_COMPRESSION)
	{
		if (! in_array($block, self::blocks(), true)) {
			return new WP_Error('unknown_block', __('Unknown .htaccess block.', 'snowseo'));
		}
		if (! SnowSEO_FS::file_mods_allowed()) {
			return new WP_Error('file_mods', __('File changes are disabled on this site (DISALLOW_FILE_MODS).', 'snowseo'));
		}
		$path = self::path();
		if ('' === $path || ! file_exists($path)) {
			return true;
		}
		$contents = self::read();
		if (null === $contents) {
			return new WP_Error('read', __('Could not read .htaccess.', 'snowseo'));
		}
		if (false === strpos($contents, self::marker_begin($block))) {
			return true;
		}
		if (! is_writable($path)) {
			return new WP_Error('htaccess_unwritable', __('.htaccess is not writable. Delete the SnowSEO block by hand instead.', 'snowseo'));
		}

		$updated = preg_replace(self::block_pattern($block), '', $contents);
		if (! is_string($updated)) {
			return new WP_Error('write', __('Could not update .htaccess.', 'snowseo'));
		}
		if (! SnowSEO_FS::write_atomic($path, $updated)) {
			return new WP_Error('write', __('Could not write to .htaccess.', 'snowseo'));
		}

		return true;
	}

	/**
	 * Remove every SnowSEO block. Used on uninstall, where leaving even one
	 * behind would mean the plugin edited a server config file and never took it
	 * back.
	 *
	 * @return void
	 */
	public static function remove_all()
	{
		foreach (self::blocks() as $block) {
			self::remove($block);
		}
	}
}
