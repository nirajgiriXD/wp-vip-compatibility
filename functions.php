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
	if ( is_file( $directory_path ) || false !== strpos( $directory_path, WP_CONTENT_DIR . '/mu-plugins' ) ) {
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

/**
 * Load the JSON data from the data.json file.
 *
 * @return void
 */
function wvc_get_json_data() {

	// Path to the JSON file containing table source data.
	$json_path = WP_VIP_COMPATIBILITY_DIR . '/data/data.json';

	if ( file_exists( $json_path ) ) {
		// Read the JSON file contents.
		$json_content = file_get_contents( $json_path );

		// Decode the JSON data into an associative array.
		return json_decode( $json_content, true ) ?: array();
	}

	return array();
}

/**
 * Get the VIP compatibility chart data for plugins.
 *
 * @return array The chart data.
 */
function wvc_get_plugins_chart_data() {
	// Ensure the get_plugins function is available.
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// Retrieve known plugin compatibility data from JSON.
	$json_data = wvc_get_json_data();

	// Fetch all installed plugins.
	$all_plugins = get_plugins();

	$compatible_count = 0;
	$not_compatible_count = 0;

	foreach ( $all_plugins as $plugin_file => $plugin_data ) {
		$plugin_slug = dirname( $plugin_file );
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;

		// Check if the plugin is in the known disallowed list.
		$is_vip_disallowed = ! empty( $json_data['known_plugins']['vip_disallowed_plugins'] ) && 
							in_array( $plugin_slug, $json_data['known_plugins']['vip_disallowed_plugins'], true );

		// Check if the plugin is in the tested compatible list.
		$is_tested_compatible = ! empty( $json_data['known_plugins']['tested_compatible_plugins'] ) && 
								in_array( $plugin_slug, $json_data['known_plugins']['tested_compatible_plugins'], true );

		// Check if the plugin is a VIP MU plugin from Automattic.
		$is_vip_mu_plugin = ! empty( $json_data['known_mu_plugins'][ $plugin_slug ] ) && 
							'automattic' === $json_data['known_mu_plugins'][ $plugin_slug ]['source'];

		// Determine compatibility status.
		if ( $is_vip_disallowed || $is_vip_mu_plugin ) {
			$not_compatible_count++;
		} elseif ( $is_tested_compatible ) {
			$compatible_count++;
		} else {
			// Perform a compatibility check for unlisted plugins.
			$status = wvc_check_vip_compatibility( $plugin_path );
			if ( 'Compatible' === $status ) {
				$compatible_count++;
			} else {
				$not_compatible_count++;
			}
		}
	}

	// Return the final compatibility count.
	return [
		'compatible'     => $compatible_count,
		'not_compatible' => $not_compatible_count,
	];
}

/**
 * Get the VIP compatibility chart data for mu-plugins.
 *
 * @return array The chart data.
 */
function wvc_get_mu_plugins_chart_data() {
	// Ensure the get_mu_plugins function is available.
	if ( ! function_exists( 'get_mu_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// Retrieve all must-use (MU) plugins.
	$mu_plugins = get_mu_plugins();

	// Return early if there are no MU plugins.
	if ( empty( $mu_plugins ) ) {
		return [ 'compatible' => 0, 'not_compatible' => 0 ];
	}

	// Retrieve known MU plugin compatibility data from JSON.
	$json_data = wvc_get_json_data();

	$compatible_count = 0;
	$not_compatible_count = 0;

	foreach ( $mu_plugins as $plugin_file => $plugin_data ) {
		$plugin_slug = dirname( $plugin_file );
		$plugin_path = WPMU_PLUGIN_DIR . '/' . $plugin_file;

		// Retrieve compatibility information from JSON data.
		$mu_plugin_info = $json_data['known_mu_plugins'][$plugin_slug] ?? null;

		if ( $mu_plugin_info ) {
			if ( ! empty( $mu_plugin_info['compatible'] ) ) {
				$compatible_count++;
			} else {
				$not_compatible_count++;
			}
		} else {
			// Perform a compatibility check if the plugin is not listed.
			$status = wvc_check_vip_compatibility( $plugin_path );
			if ( 'Compatible' === $status ) {
				$compatible_count++;
			} else {
				$not_compatible_count++;
			}
		}
	}

	// Return the final compatibility count.
	return [
		'compatible'     => $compatible_count,
		'not_compatible' => $not_compatible_count,
	];
}

/**
 * Get the VIP compatibility chart data for themes.
 *
 * @return array The chart data.
 */
function wvc_get_themes_chart_data() {
	// Ensure the wp_get_themes function is available.
	if ( ! function_exists( 'wp_get_themes' ) ) {
		require_once ABSPATH . 'wp-admin/includes/theme.php';
	}

	// Retrieve all installed themes.
	$all_themes = wp_get_themes();

	$compatible_count = 0;
	$not_compatible_count = 0;

	foreach ( $all_themes as $theme_slug => $theme_data ) {
		$theme_path = get_theme_root() . '/' . $theme_slug;

		// Check the theme's VIP compatibility.
		$status = wvc_check_vip_compatibility( $theme_path );
		if ( 'Compatible' === $status ) {
			$compatible_count++;
		} else {
			$not_compatible_count++;
		}
	}

	// Return the final compatibility count.
	return [
		'compatible'     => $compatible_count,
		'not_compatible' => $not_compatible_count,
	];
}

/**
 * Get the VIP compatibility chart data for database.
 *
 * @return array The chart data.
 */
function wvc_get_database_chart_data() {
	global $wpdb;

	// Retrieve supported collations from JSON data.
	$json_data = wvc_get_json_data();
	$vip_supported_collations = $json_data['vip_supported_collations'] ?? [];

	// Fetch database tables along with collation and engine details.
	$tables = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT TABLE_NAME, TABLE_COLLATION, ENGINE 
			FROM information_schema.TABLES 
			WHERE TABLE_SCHEMA = %s",
			DB_NAME
		)
	);

	// Return default values if no tables are found.
	if ( empty( $tables ) ) {
		return [ 'compatible' => 80, 'not_compatible' => 20 ];
	}

	$compatible_count = 0;
	$not_compatible_count = 0;

	foreach ( $tables as $table ) {
		$table_name = $table->TABLE_NAME;
		$engine = $table->ENGINE;
		$collation = $table->TABLE_COLLATION;
		
		// Check collation compatibility.
		if ( ! in_array( $collation, $vip_supported_collations, true ) ) {
			$not_compatible_count++;
			continue;
		}

		// Check engine compatibility.
		if ( 'InnoDB' !== $engine ) {
			$not_compatible_count++;
			continue;
		}

		// Check table prefix compatibility.
		if ( strpos( $table_name, 'wp_' ) !== 0 ) {
			$not_compatible_count++;
			continue;
		}

		// If all checks pass, count as compatible.
		$compatible_count++;
	}

	// Return compatibility results.
	return [
		'compatible'     => $compatible_count,
		'not_compatible' => $not_compatible_count,
	];
}

/**
 * Get the VIP compatibility chart data for directories.
 *
 * @return array The chart data.
 */
function wvc_get_directories_chart_data() {
	// Retrieve directory compatibility data from JSON.
	$json_data = wvc_get_json_data();
	$directories = $json_data['directories'] ?? [];

	// Scan the wp-content directory, excluding "." and ".."
	$items = array_diff( scandir( WP_CONTENT_DIR ), ['.', '..'] );

	// Return default values if no items are found.
	if ( empty( $items ) ) {
		return ['compatible' => 0, 'not_compatible' => 0];
	}

	$compatible_count = 0;
	$incompatible_count = 0;

	foreach ( $items as $item ) {
		// Check if the item is listed in the known directories and is marked as supported.
		$is_compatible = isset( $directories[$item] ) && !empty( $directories[$item]['is_supported'] );

		// Update the count based on compatibility.
		$is_compatible ? $compatible_count++ : $incompatible_count++;
	}

	// Return compatibility results.
	return [
		'compatible'     => $compatible_count,
		'not_compatible' => $incompatible_count,
	];
}