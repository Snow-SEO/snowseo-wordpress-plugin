<?php

/**
 * Shared filesystem primitives.
 *
 * Kept in one place so every performance fix that touches disk shares the same
 * discipline rather than growing its own drifting copy: one global
 * DISALLOW_FILE_MODS gate, one atomic writer, one home-path resolver.
 *
 * @package SnowSEO
 */

if (! defined('ABSPATH')) {
	exit;
}

class SnowSEO_FS
{
	/**
	 * Whether this site permits the plugin to touch files at all.
	 *
	 * Checked FIRST by every write path. A site that sets DISALLOW_FILE_MODS has
	 * made a deliberate decision and we never work around it.
	 *
	 * @return bool
	 */
	public static function file_mods_allowed()
	{
		return ! (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS);
	}

	/**
	 * Write a file atomically: temp file, matching permissions, then rename.
	 *
	 * The rename is what matters. A half-written site-root .htaccess makes Apache
	 * return 500 for every request on the site, so the file must never be
	 * observable in a partial state. rename(2) is atomic within a filesystem, so
	 * a reader sees either the old file or the new one.
	 *
	 * @param string $path
	 * @param string $contents
	 * @return bool
	 */
	public static function write_atomic($path, $contents)
	{
		$temp = $path . '.snowseo-tmp';
		if (false === file_put_contents($temp, $contents, LOCK_EX)) {
			return false;
		}
		$perms = @fileperms($path);
		if (false !== $perms) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Carries the original file's mode onto the temp file so the rename below cannot change it. WP_Filesystem has no equivalent that keeps the swap atomic.
			@chmod($temp, $perms & 0777);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- rename(2) is the atomic swap this function exists for. WP_Filesystem::move() is not atomic on every transport, and a torn site-root .htaccess takes the whole site down.
		if (! @rename($temp, $path)) {
			wp_delete_file($temp);
			return false;
		}
		return true;
	}

	/**
	 * Filesystem path of the site root, or '' when it cannot be resolved.
	 *
	 * get_home_path() lives in an admin-only include, and it falls back to string
	 * arithmetic over home_url()/site_url(), so on a symlinked or unusually mapped
	 * docroot it can return a path that does not exist. Never trust it blind.
	 *
	 * @return string
	 */
	public static function home_path()
	{
		if (! function_exists('get_home_path')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$home = get_home_path();

		return (is_string($home) && '' !== $home && is_dir($home)) ? $home : '';
	}
}
