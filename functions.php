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
	// Check if path is a file or directory.
	if ( is_file( $directory_path ) ) {
		$php_files = [ realpath( $directory_path ) ];
	} elseif ( is_dir( $directory_path ) && is_readable( $directory_path ) ) {
		// Initialize iterator to recursively get all PHP files.
		$php_files = [];
		$directory_iterator = new RecursiveDirectoryIterator( $directory_path, RecursiveDirectoryIterator::SKIP_DOTS );
		$iterator = new RecursiveIteratorIterator( $directory_iterator );

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() === 'php' ) {
				$php_files[] = $file->getRealPath();
			}
		}
	} else {
		return esc_html__( 'Error: File/Directory not found or unreadable', 'wp-vip-compatibility' );
	}

	if ( empty( $php_files ) ) {
		return esc_html__( 'No PHP files found', 'wp-vip-compatibility' );
	}

	// Define the log directory inside wp-content/uploads.
	$upload_dir   = wp_upload_dir();
	$log_base_dir = $upload_dir['basedir'] . '/wvc-logs';

	// Create the log directory if it doesn't exist.
	if ( ! file_exists( $log_base_dir ) ) {
		wp_mkdir_p( $log_base_dir );
	}

	// Determine the log file path based on type.
	if ( is_file( $directory_path ) ) {
		$log_file_path = $log_base_dir . '/mu-plugins.txt'; // MU plugins are single files
	} elseif ( strpos( $directory_path, WP_CONTENT_DIR . '/plugins' ) !== false ) {
		$log_file_path = $log_base_dir . '/plugins.txt';
	} elseif ( strpos( $directory_path, WP_CONTENT_DIR . '/themes' ) !== false ) {
		$log_file_path = $log_base_dir . '/themes.txt';
	} else {
		$log_file_path = $log_base_dir . '/general.txt';
	}

	// Scan for violations
	$issues = [];
	foreach ( $php_files as $file_path ) {
		// Skip vendor directory
		if ( strpos( $file_path, '/vendor/' ) !== false || strpos( $file_path, '\\vendor\\' ) !== false ) {
			continue;
		}

		// Run PHPCS check
		$phpcs_output = shell_exec( escapeshellcmd( "vendor/bin/phpcs --standard=WordPress-VIP-Go --sniffs=WordPress.Filesystem " . escapeshellarg( $file_path ) ) );

		if ( null === $phpcs_output || false === $phpcs_output || strpos( $phpcs_output, 'WordPress.Filesystem' ) !== false ) {
			// Read file contents
			$file_contents = file_get_contents( $file_path );

			// Detect filesystem writes
			if ( preg_match( '/\b(fopen|file_put_contents|fwrite|rename|unlink)\(/', $file_contents ) ) {
				if ( strpos( $file_contents, 'wp-content/uploads' ) === false ) {
					$issues[] = "Write operation outside uploads detected in: $file_path";
				}
			}

			// Detect shell execution functions
			if ( preg_match( '/\b(exec|shell_exec|system|passthru|popen)\(/', $file_contents ) ) {
				$issues[] = "Command execution function detected in: $file_path";
			}
		}
	}

	// Write issues to log file
	if ( ! empty( $issues ) ) {
		$timestamp = date( 'Y-m-d H:i:s' );
		file_put_contents( $log_file_path, "###### Log Generated: $timestamp ######" . PHP_EOL . implode( PHP_EOL, $issues ) );
		return esc_html__( 'Incompatible', 'wp-vip-compatibility' );
	}

	return esc_html__( 'Compatible', 'wp-vip-compatibility' );
}
