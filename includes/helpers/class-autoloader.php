<?php
/**
 * Autoloader to load classes
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Helpers;

/**
 * Abstract Class Autoloader
 *
 * This class is responsible for loading the required files for the plugin's classes.
 */
abstract class Autoloader {

	/**
	 * Initializes the autoloader.
	 * 
	 * @return void
	 */
	public static function init() {
		spl_autoload_register( array( __CLASS__, 'run_autoloader' ) );
	}

	/**
	 * Handles the autoloading of classes.
	 *
	 * Converts the class name to a file path and includes the file if it exists.
	 *
	 * @param string $classname The fully-qualified name of the class to load.
	 * @return void
	 */
	public static function run_autoloader( $classname ) {

		$namespace_prefix = 'WP_VIP_COMPATIBILITY\\';

		// Does the class use the namespace prefix?
		$namespace_prefix_length = strlen( $namespace_prefix );
		if ( 0 !== strncmp( $namespace_prefix, $classname, $namespace_prefix_length ) ) {
			// If not, move to the next registered autoloader.
			return;
		}

		// Get the relative class name.
		$relative_class = substr( $classname, $namespace_prefix_length );

		$paths = explode(
			'\\',
			str_replace( '_', '-', strtolower( $relative_class ) )
		);

		if ( 'includes' !== $paths[0] ) {
			return;
		}

		$length_of_path = count( $paths );

		$file_name = null;
		$directories = null;

		switch ( $paths[1] ) {

			case 'traits':
				$directories = 'traits';
				$file_name = sprintf( 'trait-%s', trim( $paths[2] ) );
				break;
			
			default:
				$directories = implode( '/', array_slice( $paths, 1, ( $length_of_path - 2 ) ) );
				$file_name = sprintf( 'class-%s', trim( $paths[ $length_of_path - 1 ] ) );
				break;
		}

		// Construct the full file path.
		$file_path = sprintf( '%s/includes/%s/%s.php', untrailingslashit( WP_VIP_COMPATIBILITY_DIR ), $directories, $file_name );

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
}

// Initialize the autoloader.
Autoloader::init();