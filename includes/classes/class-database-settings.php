<?php
/**
 * Submenu page for displaying the database settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * This class handles the database submenu settings.
 */
class Database_Settings {

	use Singleton;

	/**
	 * Constructor method is used to initialize the fields.
	 */
	public function __construct() {}

	/**
	 * Render the settings page html.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		global $wpdb;

		// Path to the JSON file containing table source data.
		$table_source_path = WP_VIP_COMPATIBILITY_DIR . '/data/plugins-tables-list.json';

		// Read the JSON file contents
		$table_source_data = file_get_contents( $table_source_path );

		// Decode the JSON data into an associative array
		$table_source_data_array = json_decode( $table_source_data, true );

		// Check if decoding was successful
		if ( null === $table_source_data_array && json_last_error() !== JSON_ERROR_NONE) {
			$table_source_data_array = array();
		}

		// Core tables that are not prefixed with 'wp_'.
		$core_tables = array(
			'commentmeta'        => array( 'wordpress-core' ),
			'comments'           => array( 'wordpress-core' ),
			'links'              => array( 'wordpress-core' ),
			'options'            => array( 'wordpress-core' ),
			'postmeta'           => array( 'wordpress-core' ),
			'posts'              => array( 'wordpress-core' ),
			'termmeta'           => array( 'wordpress-core' ),
			'terms'              => array( 'wordpress-core' ),
			'term_relationships' => array( 'wordpress-core' ),
			'term_taxonomy'      => array( 'wordpress-core' ),
			'usermeta'           => array( 'wordpress-core' ),
			'users'              => array( 'wordpress-core' ),
		);

		// Merge core tables with the JSON data.
		$table_source_data_array = array_merge( $table_source_data_array, $core_tables );

		// Supported collations for WordPress VIP.
		$vip_supported_collations = array(
			'utf8mb4_general_ci',
			'utf8mb4_bin',
			'utf8mb4_unicode_ci',
			'utf8mb4_icelandic_ci',
			'utf8mb4_latvian_ci',
			'utf8mb4_romanian_ci',
			'utf8mb4_slovenian_ci',
			'utf8mb4_polish_ci',
			'utf8mb4_estonian_ci',
			'utf8mb4_spanish_ci',
			'utf8mb4_swedish_ci',
			'utf8mb4_turkish_ci',
			'utf8mb4_czech_ci',
			'utf8mb4_danish_ci',
			'utf8mb4_lithuanian_ci',
			'utf8mb4_slovak_ci',
			'utf8mb4_spanish2_ci',
			'utf8mb4_roman_ci',
			'utf8mb4_persian_ci',
			'utf8mb4_esperanto_ci',
			'utf8mb4_hungarian_ci',
			'utf8mb4_sinhala_ci',
			'utf8mb4_german2_ci',
			'utf8mb4_croatian_mysql561_ci',
			'utf8mb4_unicode_520_ci',
			'utf8mb4_vietnamese_ci',
			'utf8mb4_croatian_ci',
			'utf8mb4_myanmar_ci',
			'utf8mb4_thai_520_w2',
			'utf8mb4_general_nopad_ci',
			'utf8mb4_nopad_bin',
			'utf8mb4_unicode_nopad_ci',
			'utf8mb4_unicode_520_nopad_ci',
		);

		// Query to get tables with their collation and engine.
		$tables = $wpdb->get_results("
			SELECT 
				TABLE_NAME, 
				TABLE_COLLATION, 
				ENGINE 
			FROM information_schema.TABLES 
			WHERE TABLE_SCHEMA = '" . DB_NAME . "'"
		);

		if ( empty( $tables ) ) {
			echo '<p>' . esc_html__( 'No tables found in the database.', 'wp-vip-compatibility' ) . '</p>';
			return;
		}
		
		// Start HTML table
		echo '<table class="wvc-table">';
		echo '<thead><tr>
				<th>' . esc_html__( 'SN','wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Table Name','wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Engine','wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Collation','wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Source','wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Additional Notes','wp-vip-compatibility' ) . '</th>
			  </tr></thead>';
		echo '<tbody>';
	
		// Loop through the tables and check for compatibility
		$counter = 1;
		foreach ( $tables as $table ) {
			$engine                = $table->ENGINE;
			$vip_supported         = in_array( $table->TABLE_COLLATION, $vip_supported_collations );
			$has_supported_prefix  = strpos( $table->TABLE_NAME, 'wp_' ) === 0;
			$additional_note_class = 'not-compatible';
			$table_name            = $table->TABLE_NAME;

			// First, check if the table is in the JSON file as it is.
			$source = isset( $table_source_data_array[ $table_name ] ) ? implode( ', ', $table_source_data_array[ $table_name ] ) : null;

			// If not found, try removing the 'wp_' prefix and check again.
			if ( ! $source && $has_supported_prefix ) {
				$prefix_removed_table_name = preg_replace( '/^wp_/', '', $table_name );
				$source = isset( $table_source_data_array[ $prefix_removed_table_name ] ) ? implode( ', ', $table_source_data_array[ $prefix_removed_table_name ] ) : '-';
			} else {
				$source = $source ?: '-';
			}

			// Determine additional notes.
			$notes = array();
			if ( ! $vip_supported ) {
				$notes[] = esc_html__( 'Unsupported Collation', 'wp-vip-compatibility' );
			} 
			if ( $engine != 'InnoDB' ) {
				$notes[] = 'Incompatible Engine';
			}
			if ( ! $has_supported_prefix ) {
				$notes[] = esc_html__( 'Unsupported Prefix', 'wp-vip-compatibility' );
			}
			if ( empty( $notes ) ) {
				$notes[] = esc_html__( 'Compatible', 'wp-vip-compatibility' );
				$additional_note_class = 'compatible';
			}
	
			// Echo row
			echo '<tr>';
			echo '<td>' . esc_html( $counter++ ) . '</td>';
			echo '<td>' . esc_html( $table_name ) . '</td>';
			echo '<td>' . esc_html( $table->ENGINE ) . '</td>';
			echo '<td>' . esc_html( $table->TABLE_COLLATION ) . '</td>';
			echo '<td>' . esc_html( $source ) . '</td>';
			echo '<td class="' . $additional_note_class . '">' . esc_html( implode( ', ', $notes ) ) . '</td>';
			echo '</tr>';
		}
	
		echo '</tbody></table>';
	}

}