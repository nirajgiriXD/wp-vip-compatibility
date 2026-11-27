<?php
/**
 * The fake wp-content directory the scanner reads.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Tests\Support;

/**
 * Creates and empties the sandbox wp-content tree.
 */
final class Sandbox {

	/**
	 * Empties wp-content and recreates plugins/ and mu-plugins/.
	 *
	 * @return void
	 */
	public static function reset() {
		self::remove( WP_CONTENT_DIR );

		foreach ( array( WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR ) as $directory ) {
			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0777, true );
			}
		}
	}

	/**
	 * Writes a file, creating its directories.
	 *
	 * @param string $path     Path relative to wp-content.
	 * @param string $contents File contents.
	 * @return string The absolute path.
	 */
	public static function write( $path, $contents ) {
		$absolute = WP_CONTENT_DIR . '/' . ltrim( $path, '/' );

		if ( ! is_dir( dirname( $absolute ) ) ) {
			mkdir( dirname( $absolute ), 0777, true );
		}

		file_put_contents( $absolute, $contents );

		return $absolute;
	}

	/**
	 * Creates a directory under wp-content.
	 *
	 * @param string $path Path relative to wp-content.
	 * @return string The absolute path.
	 */
	public static function mkdir( $path ) {
		$absolute = WP_CONTENT_DIR . '/' . ltrim( $path, '/' );

		if ( ! is_dir( $absolute ) ) {
			mkdir( $absolute, 0777, true );
		}

		return $absolute;
	}

	/**
	 * Deletes a directory tree.
	 *
	 * @param string $path Absolute path.
	 * @return void
	 */
	public static function remove( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$child = $path . '/' . $entry;

			// A directory symlink on Windows has to be removed with rmdir().
			if ( is_link( $child ) && is_dir( $child ) && '\\' === DIRECTORY_SEPARATOR ) {
				rmdir( $child );
				continue;
			}

			self::remove( $child );
		}

		rmdir( $path );
	}
}
