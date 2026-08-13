<?php
/**
 * Activity log.
 *
 * A capped, newest-first list of what SnowSEO did on this site, surfaced by the
 * plugin's admin screen and the /logs endpoints. Stored in one non-autoloaded
 * option so reading it never costs anything on a normal page request.
 *
 * Static and standalone: the REST handlers, the media importer and the
 * performance fixes all write here, and none of them should have to construct
 * another subsystem to record a line.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_Log
{
	const OPTION = 'snowseo_activity_logs';

	/**
	 * Record one entry, newest first, capped at 100.
	 *
	 * @param string $status  Short machine-ish state: connected, error, published…
	 * @param string $message Human-readable line shown in the admin log.
	 * @param array  $details Optional structured context.
	 * @return array The stored entry.
	 */
	public static function write($status, $message, $details = array())
	{
		$logs = get_option(self::OPTION, array());

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

		update_option(self::OPTION, $logs, false); // Don't autoload - avoid loading logs on every page request.

		return $entry;
	}
}
