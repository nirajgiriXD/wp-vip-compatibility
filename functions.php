<?php
/**
 * File that contains functions for the WordPress VIP Compatibility.
 *
 * @package wp-vip-compatibility
 */

/**
 * Check the compatibility of a directory with VIP Go and store logs for incompatible files.
 *
 * @param string $directory_path The directory path to check.
 * @return string The compatibility status: 'Compatible' or 'Incompatible'.
 */
function wvc_check_vip_compatibility( $directory_path ) {
	// Ensure the directory exists and is readable.
	if ( ! is_dir( $directory_path ) || ! is_readable( $directory_path ) ) {
		return esc_html__( 'Error: Directory not found or unreadable', 'wp-vip-compatibility' );
	}

	// Initialize the iterator to recursively get all PHP files.
	$php_files = [];
	$directory_iterator = new RecursiveDirectoryIterator( $directory_path, RecursiveDirectoryIterator::SKIP_DOTS );
	$iterator = new RecursiveIteratorIterator( $directory_iterator );

	foreach ( $iterator as $file ) {
		if ( $file->getExtension() === 'php' ) {
			$php_files[] = $file->getRealPath();
		}
	}

	if ( empty( $php_files ) ) {
		return esc_html__( 'No PHP files found', 'wp-vip-compatibility' );
	}

	// Define the log directory inside wp-content/uploads
	$upload_dir   = wp_upload_dir();
	$log_base_dir = $upload_dir['basedir'] . '/wvc-logs';

	if ( ! file_exists( $log_base_dir ) ) {
		wp_mkdir_p( $log_base_dir );
	}

	// Determine the log file name based on the path.
	$directory_path = str_replace( "/", "\\", $directory_path );
	$mu_plugin_dir  = str_replace( '/', '\\', WPMU_PLUGIN_DIR );
	$plugin_dir     = str_replace( '/', '\\', WP_CONTENT_DIR . '/plugins' );
	$theme_dir      = str_replace( '/', '\\', WP_CONTENT_DIR . '/themes' );

	if ( strpos( $directory_path, $plugin_dir ) !== false ) {
		$log_file_path = $log_base_dir . '/plugins.txt';
	} elseif ( strpos( $directory_path, $theme_dir ) !== false ) {
		$log_file_path = $log_base_dir . '/themes.txt';
	} elseif ( strpos( $directory_path, $mu_plugin_dir ) !== false ) {
		$log_file_path = $log_base_dir . '/mu-plugins.txt';
	} else {
		$log_file_path = $log_base_dir . '/general.txt';
	}

	// Initialize the array to store issues.
	$issues = [];

	// Scan each PHP file for violations.
	foreach ( $php_files as $file_path ) {
		// Skip files inside the vendor directory.
		if ( strpos( $file_path, '/vendor/' ) !== false || strpos( $file_path, '\\vendor\\' ) !== false ) {
			continue;
		}

		// Run PHPCS to check for filesystem writes.
		$phpcs_output = shell_exec( escapeshellcmd( "vendor/bin/phpcs --standard=WordPress-VIP-Go --sniffs=WordPress.Filesystem " . escapeshellarg( $file_path ) ) );

		if ( null === $phpcs_output || false === $phpcs_output || strpos( $phpcs_output, 'WordPress.Filesystem' ) !== false ) {
			// Read file contents.
			$file_contents = file_get_contents( $file_path );

			// Detect write operations in directories other than wp-content/uploads.
			if ( preg_match( '/\b(fopen|file_put_contents|fwrite|rename|unlink)\(/', $file_contents ) ) {
				if ( strpos( $file_contents, 'wp-content/uploads' ) === false ) {
					$issues[] = "Write operation outside uploads detected in: $file_path";
				}
			}

			// Detect shell execution functions.
			if ( preg_match( '/\b(exec|shell_exec|system|passthru|popen)\(/', $file_contents ) ) {
				$issues[] = "Command execution function detected in: $file_path";
			}
		}
	}

	// Write to log file if there are any issues.
	if ( ! empty( $issues ) ) {
		$timestamp = date( 'Y-m-d H:i:s' );
		$log_content = "###### Log Generated: $timestamp ######" . PHP_EOL;
		$log_content .= implode( PHP_EOL, $issues );
		file_put_contents( $log_file_path, $log_content );
		return esc_html__( 'Incompatible', 'wp-vip-compatibility' );
	}

	return esc_html__( 'Compatible', 'wp-vip-compatibility' );
}
