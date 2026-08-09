<?php

/**
 * robots.txt repair.
 *
 * Lighthouse's `robots-txt` audit fails for three quite different reasons, and
 * only one of them is something PHP can fix:
 *
 *  1. /robots.txt returns >= 400 or a non-text body, because a security plugin,
 *     a rewrite rule, or a CDN is in the way. Our filter never runs. Nothing we
 *     do here helps - the honest report IS the fix, and the loopback probe in
 *     SnowSEO_Perf is what detects it.
 *  2. A physical robots.txt on disk is broken. We refuse to touch it (see below)
 *     and hand the owner the corrected text to paste.
 *  3. Another plugin's `robots_txt` filter injected lines Lighthouse cannot
 *     parse. This is the case we can actually repair, by sanitising whatever
 *     every other contributor produced.
 *
 * A physical robots.txt is NEVER written by this plugin. One stray `Disallow: /`
 * deindexes an entire site and there is no revision history to undo it, and a
 * file on disk is almost always deliberate.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Perf_Robots
{
	const OPTION_REPAIR = 'snowseo_perf_robots_repair';

	/** Directive keys robots.txt consumers actually understand. */
	const KNOWN_KEYS = array(
		'user-agent',
		'allow',
		'disallow',
		'sitemap',
		'crawl-delay',
		'host',
		'clean-param',
		'noindex',
		'request-rate',
		'visit-time',
	);

	/** Keys that belong to a User-agent group and imply one when missing. */
	const GROUPED_KEYS = array('allow', 'disallow', 'crawl-delay', 'noindex');

	/** Canonical casing for output. */
	const KEY_CASING = array(
		'user-agent'   => 'User-agent',
		'allow'        => 'Allow',
		'disallow'     => 'Disallow',
		'sitemap'      => 'Sitemap',
		'crawl-delay'  => 'Crawl-delay',
		'host'         => 'Host',
		'clean-param'  => 'Clean-param',
		'noindex'      => 'Noindex',
		'request-rate' => 'Request-rate',
		'visit-time'   => 'Visit-time',
	);

	public static function init()
	{
		// PHP_INT_MAX - 10 so we run after every other contributor (a Sitemap line
		// is typically added at priority 10) while leaving a site room to hook
		// after us. This ordering is load-bearing: repairing before another plugin
		// appends would leave its invalid lines in place.
		add_filter('robots_txt', array(__CLASS__, 'repair'), PHP_INT_MAX - 10, 2);
	}

	/** Whether repair is switched on for this site. */
	public static function is_enabled()
	{
		$on = '1' === (string) get_option(self::OPTION_REPAIR, '0');

		/**
		 * Filter whether SnowSEO repairs the generated robots.txt.
		 *
		 * @param bool $on
		 */
		return (bool) apply_filters('snowseo_robots_repair_enabled', $on);
	}

	/**
	 * Is a real robots.txt being served from the webroot? A physical file is
	 * matched before WordPress runs, so the robots_txt filter never fires.
	 */
	public static function shadowed()
	{
		$home = SnowSEO_FS::home_path();

		return '' !== $home && file_exists($home . 'robots.txt');
	}

	/** Path of the physical robots.txt, or '' when there isn't one. */
	public static function physical_path()
	{
		$home = SnowSEO_FS::home_path();
		$path = '' === $home ? '' : $home . 'robots.txt';

		return ('' !== $path && file_exists($path)) ? $path : '';
	}

	/**
	 * The robots_txt filter.
	 *
	 * @param string $output
	 * @param bool   $public
	 * @return string
	 */
	public static function repair($output, $public)
	{
		// blog_public = 0 means the owner deliberately hid the site and WordPress
		// emits `Disallow: /`. "Repairing" that would be catastrophic.
		if (! $public) {
			return $output;
		}
		if (! self::is_enabled()) {
			return $output;
		}

		return self::normalize((string) $output);
	}

	/**
	 * Sanitise robots.txt text.
	 *
	 * Pure and WordPress-free so it can be reasoned about (and tested) on its own.
	 *
	 * Two invariants this must never break:
	 *  1. It NEVER changes crawl policy. It drops syntactically invalid lines and
	 *     inserts missing structure. It never invents a Disallow, never removes an
	 *     existing one, and never reorders - allow/disallow semantics are
	 *     positional within a group.
	 *  2. If normalising a non-empty input produced no directives at all, the
	 *     input is returned untouched. Under no circumstances is this plugin the
	 *     thing that emptied a site's robots.txt.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function normalize($text)
	{
		$lines = preg_split('/\r\n|\r|\n/', (string) $text);
		if (! is_array($lines)) {
			return $text;
		}

		$out = array();
		$input_directives = 0;
		$kept_directives = 0;
		$group_open = false;
		$blank_pending = false;

		foreach ($lines as $line) {
			$trimmed = trim($line);

			// Preserve blank lines, but collapse runs of them.
			if ('' === $trimmed) {
				$blank_pending = true;
				continue;
			}

			// Preserve comments verbatim.
			if ('#' === $trimmed[0]) {
				if ($blank_pending && ! empty($out)) {
					$out[] = '';
				}
				$blank_pending = false;
				$out[] = $trimmed;
				continue;
			}

			$colon = strpos($trimmed, ':');
			if (false === $colon) {
				// Not a directive at all - this is what Lighthouse reports as
				// syntax it cannot understand.
				$input_directives++;
				continue;
			}

			$input_directives++;
			$key = strtolower(trim(substr($trimmed, 0, $colon)));
			$value = trim(substr($trimmed, $colon + 1));

			if (! in_array($key, self::KNOWN_KEYS, true)) {
				continue;
			}
			// A User-agent with no value selects nothing.
			if ('user-agent' === $key && '' === $value) {
				continue;
			}
			// Crawl-delay must be numeric.
			if ('crawl-delay' === $key && ! is_numeric($value)) {
				continue;
			}
			// Sitemap is group-independent and must be an absolute URL. It must
			// NOT open or belong to a User-agent group.
			if ('sitemap' === $key && 1 !== preg_match('#^https?://#i', $value)) {
				continue;
			}

			if ($blank_pending && ! empty($out)) {
				$out[] = '';
			}
			$blank_pending = false;

			// A grouped directive before any User-agent is the one genuine
			// structural error the WordPress plugin ecosystem produces.
			if (! $group_open && in_array($key, self::GROUPED_KEYS, true)) {
				$out[] = 'User-agent: *';
				$group_open = true;
			}
			if ('user-agent' === $key) {
				$group_open = true;
			}

			$out[] = self::KEY_CASING[$key] . ': ' . $value;
			$kept_directives++;
		}

		// Safety valve: never hand back an empty policy for a non-empty input.
		if ($input_directives > 0 && 0 === $kept_directives) {
			return $text;
		}

		return implode("\n", $out) . "\n";
	}
}
