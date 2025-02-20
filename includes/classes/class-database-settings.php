<?php
/**
 * Submenu page for displaying the database settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Classes\Plugin;

/**
 * This class handles the database submenu settings.
 */
class Database_Settings {

	use Singleton;

	/**
	 * Stores plugin data from JSON file.
	 *
	 * @var array
	 */
	private $json_data = [];

	/**
	 * Constructor method is used to initialize the fields.
	 */
	public function __construct() {
		$this->json_data = Plugin::get_instance()->get_json_data();
	}

	/**
	 * Retrieve the core tables and VIP supported collations from the JSON file.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		global $wpdb;

		// Extract core tables and VIP supported collations from the JSON data.
		$core_tables              = $this->json_data['core_tables'] ?? [];
		$vendor_tables            = $this->json_data['vendor_tables'] ?? [];
		$vip_supported_collations = $this->json_data['vip_supported_collations'] ?? [];

		// Query to get tables with their collation and engine.
		$tables = $wpdb->get_results("SELECT TABLE_NAME, TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'");

		if ( empty( $tables ) ) {
			echo '<p>' . esc_html__( 'No tables found in the database.', 'wp-vip-compatibility' ) . '</p>';
			return;
		}

		// Start HTML table.
		echo '<table class="wvc-table">';
		echo '<thead><tr>
				<th>' . esc_html__( 'SN', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Table Name', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Engine', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Collation', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Source', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'VIP Compatibility', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Notes', 'wp-vip-compatibility' ) . '</th>
			  </tr></thead>';
		echo '<tbody>';

		$counter = 1;
		foreach ( $tables as $table ) {
			$table_name           = $table->TABLE_NAME;
			$engine               = $table->ENGINE;
			$collation            = $table->TABLE_COLLATION;
			$vip_supported        = in_array( $collation, $vip_supported_collations );
			$has_supported_prefix = strpos( $table_name, 'wp_' ) === 0;

			// Determine table source.
			$source = $this->get_table_source( $table_name, $core_tables, $vendor_tables, $has_supported_prefix );

			// Determine compatibility.
			$compatibility_class = 'compatible';
			$compatibility       = esc_html__( 'Compatible', 'wp-vip-compatibility' );
			$notes               = [];

			// Check collation compatibility.
			if ( ! $vip_supported ) {
				$suggested_collation = $this->get_suggested_collation( $collation, $vip_supported_collations );
				$notes[] = esc_html__( 'The collation is unsupported, consider using', 'wp-vip-compatibility' ) . 
					' <code>' . esc_html( $suggested_collation ) . '</code> ' . esc_html__( 'for VIP compatibility', 'wp-vip-compatibility' );
				$compatibility_class = 'not-compatible';
				$compatibility       = esc_html__( 'Not Compatible', 'wp-vip-compatibility' );
			}

			// Check engine compatibility.
			if ( $engine !== 'InnoDB' ) {
				$notes[] = esc_html__( 'The engine is unsupported, consider using', 'wp-vip-compatibility' ) . 
					' <code>InnoDB</code> ' . esc_html__( 'for VIP compatibility', 'wp-vip-compatibility' );
				$compatibility_class = 'not-compatible';
				$compatibility       = esc_html__( 'Not Compatible', 'wp-vip-compatibility' );
			}

			// Check prefix compatibility.
			if ( ! $has_supported_prefix ) {
				$notes[] = esc_html__( 'The table prefix is unsupported, consider using', 'wp-vip-compatibility' ) . 
					' <code>wp_</code> ' . esc_html__( 'prefix for VIP compatibility', 'wp-vip-compatibility' );
				$compatibility_class = 'not-compatible';
				$compatibility       = esc_html__( 'Not Compatible', 'wp-vip-compatibility' );
			}

			// If no issues, set the notes to '-'.
			$notes = empty( $notes ) ? ['-'] : $notes;

			// Output table row.
			echo '<tr>';
			echo '<td>' . esc_html( $counter++ ) . '</td>';
			echo '<td>' . esc_html( $table_name ) . '</td>';
			echo '<td>' . esc_html( $engine ) . '</td>';
			echo '<td>' . esc_html( $collation ) . '</td>';
			echo '<td>' . esc_html( $source ) . '</td>';
			echo '<td class="' . esc_attr( $compatibility_class ) . '">' . esc_html( $compatibility ) . '</td>';
			echo '<td><ul><li>' . implode( '</li><li>', $notes ) . '</li></ul></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Get table source from core tables or vendor tables.
	 *
	 * @param string $table_name          The database table name.
	 * @param array  $core_tables         List of core tables.
	 * @param array  $vendor_tables       List of vendor tables.
	 * @param bool   $has_supported_prefix Whether the table has a supported prefix.
	 *
	 * @return string
	 */
	private function get_table_source( $table_name, $core_tables, $vendor_tables, $has_supported_prefix ) {
		// Check core tables.
		if ( isset( $core_tables[ $table_name ] ) ) {
			return implode( ', ', $core_tables[ $table_name ] );
		}

		// Check vendor tables.
		if ( isset( $vendor_tables[ $table_name ] ) ) {
			return implode( ', ', $vendor_tables[ $table_name ] );
		}

		// If the table starts with "wp_", try checking without the prefix.
		if ( $has_supported_prefix ) {
			$trimmed_table_name = preg_replace( '/^wp_/', '', $table_name );

			if ( isset( $core_tables[ $trimmed_table_name ] ) ) {
				return implode( ', ', $core_tables[ $trimmed_table_name ] );
			}

			if ( isset( $vendor_tables[ $trimmed_table_name ] ) ) {
				return implode( ', ', $vendor_tables[ $trimmed_table_name ] );
			}
		}

		return '-';
	}

	/**
	 * Get the closest VIP-supported collation suggestion.
	 *
	 * @param string $current_collation   The table's current collation.
	 * @param array  $vip_collations      List of VIP-supported collations.
	 *
	 * @return string
	 */
	private function get_suggested_collation( $current_collation, $vip_collations ) {
		foreach ( $vip_collations as $vip_collation ) {
			if ( strpos( $vip_collation, explode('_', $current_collation)[0] ) === 0 ) {
				return $vip_collation;
			}
		}
		return 'Not Supported';
	}
}
