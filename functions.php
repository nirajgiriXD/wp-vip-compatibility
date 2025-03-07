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

	// Determine the directory type and extract the slug.
	if ( is_file( $directory_path ) ) {
		$directory_type = 'mu-plugins';
		$slug           = basename( dirname( $directory_path ) );
	} elseif ( false !== strpos( $directory_path, WP_CONTENT_DIR . '/plugins' ) ) {
		$directory_type = 'plugins';
		$slug           = basename( $directory_path );
	} elseif ( false !== strpos( $directory_path, WP_CONTENT_DIR . '/themes' ) ) {
		$directory_type = 'themes';
		$slug           = basename( $directory_path );
	} else {
		$directory_type = 'general';
		$slug           = basename( $directory_path );
	}

	// Define the log file path.
	$log_file_path = $log_base_dir . '/' . $directory_type . '.json';

	// Read existing JSON data if file exists.
	$log_data = [];
	if ( file_exists( $log_file_path ) ) {
		$existing_data = file_get_contents( $log_file_path );
		$log_data      = json_decode( $existing_data, true ) ?: array();
	}

	// Scan for violations.
	$issues = [];
	foreach ( $php_files as $file_path ) {
		// Skip vendor directory.
		if ( false !== strpos( $file_path, '/vendor/' ) || false !== strpos( $file_path, '\\vendor\\' ) ) {
			continue;
		}

		// Open file and scan line by line.
		$file_handle = fopen( $file_path, 'r' );
		if ( $file_handle ) {
			$line_number = 0;
			
			while ( ( $line = fgets( $file_handle ) ) !== false ) {
				$line_number++;

				// Detect filesystem operations outside uploads directory.
				if ( preg_match( '/\b(fopen|file_put_contents|fwrite|rename|unlink)\(/', $line ) ) {
					if ( strpos( $line, 'wp-content/uploads' ) === false ) {
						$issues[] = array(
							'file'  => $file_path,
							'line'  => $line_number,
							'issue' => esc_html__( 'Filesystem operation outside uploads directory', 'wp-vip-compatibility' ),
						);
					}
				}

				// Detect shell execution functions.
				if ( preg_match( '/\b(exec|shell_exec|system|passthru|popen)\(/', $line ) ) {
					$issues[] = array(
						'file'  => $file_path,
						'line'  => $line_number,
						'issue' => esc_html__( 'Command execution function detected', 'wp-vip-compatibility' ),
					);
				}
			}
			fclose( $file_handle );
		}
	}

	// Update JSON data with new issues.
	if ( ! empty( $issues ) ) {
		$log_data[ $slug ] = $issues;
		file_put_contents( $log_file_path, json_encode( $log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		return esc_html__( 'Incompatible', 'wp-vip-compatibility' );
	}

	return esc_html__( 'Compatible', 'wp-vip-compatibility' );
}
