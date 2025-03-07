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
 * Handles the database settings submenu.
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
	 * Constructor.
	 */
	public function __construct() {
		$this->json_data = Plugin::get_instance()->get_json_data();
	}

	/**
	 * Renders the database settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		global $wpdb;

		$core_tables              = $this->json_data['tables']['core_tables'] ?? [];
		$vendor_tables            = $this->json_data['tables']['vendor_tables'] ?? [];
		$vip_supported_collations = $this->json_data['vip_supported_collations'] ?? [];

		// Fetch database tables with collation and engine.
		$tables = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME, TABLE_COLLATION, ENGINE 
				FROM information_schema.TABLES 
				WHERE TABLE_SCHEMA = %s",
				DB_NAME
			)
		);

		if ( empty( $tables ) ) {
			echo '<p>' . esc_html__( 'No tables found in the database.', 'wp-vip-compatibility' ) . '</p>';
			return;
		}

		// Render the tabs.
		$this->render_tabs();

		// Output the settings table.
		echo '<table class="wvc-table" data-target-entity="database">';
		echo '<thead><tr>
				<th>' . esc_html__( 'SN', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Table Name', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Engine', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Collation', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Source', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'WP VIP Compatibility', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Notes', 'wp-vip-compatibility' ) . '</th>
			</tr></thead>';
		echo '<tbody>';

		$counter = 1;
		foreach ( $tables as $table ) {
			$table_name           = $table->TABLE_NAME;
			$engine               = $table->ENGINE;
			$collation            = $table->TABLE_COLLATION;
			$vip_supported        = in_array( $collation, $vip_supported_collations, true );
			$has_supported_prefix = strpos( $table_name, 'wp_' ) === 0;

			// Determine table source.
			$source = $this->get_table_source( $table_name, $core_tables, $vendor_tables, $has_supported_prefix );

			// Determine compatibility.
			$compatibility_class = 'compatible';
			$compatibility       = esc_html__( 'Compatible', 'wp-vip-compatibility' );
			$notes               = [];

			// Check collation compatibility.
			if ( ! $vip_supported ) {
				$charset             = explode( '_', $collation, 2 )[0] ?? '';
				$suggested_collation = $this->get_suggested_collation( $collation, $vip_supported_collations );

				if ( $suggested_collation !== 'Not Supported' ) {
					$notes[] = esc_html__( 'Unsupported collation. Recommended fix:', 'wp-vip-compatibility' ) .
						'<br><code>ALTER TABLE ' . esc_html( $table_name ) . ' CONVERT TO CHARACTER SET ' . esc_html( $charset ) . ' COLLATE ' . esc_html( $suggested_collation ) . ';</code>';
				} else {
					$notes[] = esc_html__( 'The collation is unsupported.', 'wp-vip-compatibility' );
				}

				$compatibility_class = 'not-compatible';
				$compatibility       = esc_html__( 'Not Compatible', 'wp-vip-compatibility' );
			}

			// Check engine compatibility.
			if ( 'InnoDB' !== $engine ) {
				$notes[] = esc_html__( 'Unsupported storage engine. Recommended fix:', 'wp-vip-compatibility' ) .
					'<br><code>ALTER TABLE ' . esc_html( $table_name ) . ' ENGINE = InnoDB;</code>';

				$compatibility_class = 'not-compatible';
				$compatibility       = esc_html__( 'Not Compatible', 'wp-vip-compatibility' );
			}

			// Check prefix compatibility.
			if ( ! $has_supported_prefix ) {
				$notes[] = esc_html__( 'Non-standard table prefix. Recommended fix:', 'wp-vip-compatibility' ) .
					'<br><code>ALTER TABLE ' . esc_html( $table_name ) . ' RENAME TO ' . esc_html( 'wp_' . $table_name ) . ';</code>';

				$compatibility_class = 'not-compatible';
				$compatibility       = esc_html__( 'Not Compatible', 'wp-vip-compatibility' );
			}

			// Display notes.
			$notes_display = empty( $notes ) ? '-' : '<ul><li>' . implode( '</li><li>', $notes ) . '</li></ul>';

			// Output table row.
			echo '<tr>';
			echo '<td>' . esc_html( $counter++ ) . '</td>';
			echo '<td>' . esc_html( $table_name ) . '</td>';
			echo '<td>' . esc_html( $engine ) . '</td>';
			echo '<td>' . esc_html( $collation ) . '</td>';
			echo '<td>' . esc_html( $source ) . '</td>';
			echo '<td class="' . esc_attr( $compatibility_class ) . '">' . esc_html( $compatibility ) . '</td>';
			echo '<td>' . $notes_display . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the tabs for filtering the compatible and incompatible plugins.
	 *
	 * @return void
	 */
	private function render_tabs() {
		?>
		<div id="wvc-filter-tabs">
			<button data-filter="all" class="active">
				<?php esc_html_e( 'All', 'wp-vip-compatibility' ); ?>
			</button>
			<button data-filter="compatible">
				<?php esc_html_e( 'Compatible', 'wp-vip-compatibility' ); ?>
			</button>
			<button data-filter="incompatible">
				<?php esc_html_e( 'Incompatible', 'wp-vip-compatibility' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Determine the source of a given database table.
	 *
	 * @param string $table_name The table name.
	 * @param array  $core_tables List of core WordPress tables.
	 * @param array  $vendor_tables List of vendor/plugin tables.
	 * @param bool   $has_supported_prefix Whether the table has a "wp_" prefix.
	 *
	 * @return string Table source (Core, Plugin, Custom, Unknown).
	 */
	private function get_table_source( $table_name, $core_tables, $vendor_tables, $has_supported_prefix ) {
		global $wpdb;

		// Remove the prefix.
		$table_name = substr( $table_name, strlen( $wpdb->prefix ) );

		if ( isset( $core_tables[ $table_name ] ) ) {
			return implode( ', ', $core_tables[ $table_name ] );
		}

		if ( isset( $vendor_tables[ $table_name ] ) ) {
			return implode( ', ', $vendor_tables[ $table_name ] );
		}

		return $has_supported_prefix ? esc_html__( 'Custom', 'wp-vip-compatibility' ) : esc_html__( 'Unknown (Non-standard Prefix)', 'wp-vip-compatibility' );
	}

	/**
	 * Suggests the closest VIP-supported collation.
	 *
	 * @param string $current_collation The current collation.
	 * @param array  $vip_collations List of VIP-supported collations.
	 *
	 * @return string Suggested collation or 'Not Supported'.
	 */
	private function get_suggested_collation( $current_collation, $vip_collations ) {
		foreach ( $vip_collations as $vip_collation ) {
			if ( str_contains( $vip_collation, explode( '_', $current_collation, 2 )[1] ?? '' ) ) {
				return $vip_collation;
			}
		}
		return __( 'Not Supported', 'wp-vip-compatibility' );
	}
}
