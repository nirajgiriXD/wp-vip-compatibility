<?php
/**
 * The registry of things that can be scanned.
 *
 * This class exists for a security reason as much as an organisational one. The
 * previous AJAX endpoint accepted an arbitrary filesystem path from the browser
 * and scanned whatever it pointed at, which let any authenticated caller probe
 * the server's filesystem and disclose paths through the response.
 *
 * Scan requests now name a *target key* — `plugin:akismet`, `theme:twentytwentyfive`
 * — which is resolved against the set of plugins, themes and must-use plugins
 * that WordPress itself reports. A key that does not resolve is simply rejected,
 * so no caller-supplied path ever reaches the filesystem layer.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and enumerates scan targets.
 */
class Targets {

	/**
	 * Memoised target list, keyed by target key.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $targets = null;

	/**
	 * Returns every scannable target, keyed by target key.
	 *
	 * @return array<string, array<string, mixed>> Targets keyed by target key.
	 */
	public static function all() {
		if ( null !== self::$targets ) {
			return self::$targets;
		}

		self::$targets = array_merge( self::plugins(), self::themes(), self::mu_plugins() );

		return self::$targets;
	}

	/**
	 * Returns the targets of one type.
	 *
	 * @param string $type One of `plugin`, `theme`, `mu-plugin`.
	 * @return array<string, array<string, mixed>> Matching targets.
	 */
	public static function of_type( $type ) {
		return array_filter(
			self::all(),
			static function ( $target ) use ( $type ) {
				return $target['type'] === $type;
			}
		);
	}

	/**
	 * Resolves a target key supplied by a request.
	 *
	 * @param string $key The target key.
	 * @return array<string, mixed>|null The target, or null when the key is unknown.
	 */
	public static function get( $key ) {
		$targets = self::all();

		return $targets[ $key ] ?? null;
	}

	/**
	 * Clears the memoised list. Intended for tests and long-running processes.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$targets = null;
	}

	/**
	 * Builds a target key.
	 *
	 * @param string $type Target type.
	 * @param string $slug Target slug.
	 * @return string The target key.
	 */
	public static function key( $type, $slug ) {
		return $type . ':' . $slug;
	}

	/**
	 * Enumerates installed plugins.
	 *
	 * @return array<string, array<string, mixed>> Plugin targets.
	 */
	private static function plugins() {
		self::load_plugin_api();

		$targets = array();

		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( $file );

			// A single-file plugin sits directly in the plugins directory.
			$path = ( '.' === $slug )
				? WP_PLUGIN_DIR . '/' . $file
				: WP_PLUGIN_DIR . '/' . $slug;

			$slug = ( '.' === $slug ) ? $file : $slug;
			$key  = self::key( 'plugin', $slug );

			$targets[ $key ] = array(
				'key'     => $key,
				'type'    => 'plugin',
				'slug'    => $slug,
				'label'   => $data['Name'],
				'version' => $data['Version'],
				'author'  => wp_strip_all_tags( $data['Author'] ),
				'path'    => $path,
				'file'    => $file,
			);
		}

		return $targets;
	}

	/**
	 * Enumerates installed themes.
	 *
	 * @return array<string, array<string, mixed>> Theme targets.
	 */
	private static function themes() {
		$targets = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$key = self::key( 'theme', $slug );

			$targets[ $key ] = array(
				'key'     => $key,
				'type'    => 'theme',
				'slug'    => $slug,
				'label'   => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'author'  => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'path'    => $theme->get_stylesheet_directory(),
				'file'    => $slug,
			);
		}

		return $targets;
	}

	/**
	 * Enumerates must-use plugins, both loose files and directories.
	 *
	 * WordPress only auto-loads PHP files at the root of mu-plugins, but a
	 * directory alongside them is still code that ships with the site, so both
	 * are offered as targets.
	 *
	 * @return array<string, array<string, mixed>> Must-use plugin targets.
	 */
	private static function mu_plugins() {
		self::load_plugin_api();

		$targets = array();

		foreach ( get_mu_plugins() as $file => $data ) {
			$key = self::key( 'mu-plugin', $file );

			$targets[ $key ] = array(
				'key'     => $key,
				'type'    => 'mu-plugin',
				'slug'    => $file,
				'label'   => $data['Name'] ? $data['Name'] : $file,
				'version' => $data['Version'],
				'author'  => wp_strip_all_tags( $data['Author'] ),
				'path'    => WPMU_PLUGIN_DIR . '/' . $file,
				'file'    => $file,
			);
		}

		foreach ( self::mu_plugin_directories() as $directory ) {
			$key = self::key( 'mu-plugin', $directory );

			if ( isset( $targets[ $key ] ) ) {
				continue;
			}

			$targets[ $key ] = array(
				'key'     => $key,
				'type'    => 'mu-plugin',
				'slug'    => $directory,
				'label'   => $directory,
				'version' => '',
				'author'  => '',
				'path'    => WPMU_PLUGIN_DIR . '/' . $directory,
				'file'    => $directory,
			);
		}

		return $targets;
	}

	/**
	 * Lists directories inside mu-plugins that contain at least one PHP file.
	 *
	 * @return string[] Directory names.
	 */
	private static function mu_plugin_directories() {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) || ! is_readable( WPMU_PLUGIN_DIR ) ) {
			return array();
		}

		$directories = array();

		foreach ( (array) scandir( WPMU_PLUGIN_DIR ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = WPMU_PLUGIN_DIR . '/' . $entry;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			if ( ! empty( glob( $path . '/*.php' ) ) ) {
				$directories[] = $entry;
			}
		}

		return $directories;
	}

	/**
	 * Loads the admin plugin API when it is not already available.
	 *
	 * @return void
	 */
	private static function load_plugin_api() {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
