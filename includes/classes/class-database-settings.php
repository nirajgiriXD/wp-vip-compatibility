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
			UI::render_empty_state(
				__( 'No tables found in the database.', 'wp-vip-compatibility' ),
				__( 'There is nothing to check on this screen yet.', 'wp-vip-compatibility' )
			);
			return;
		}

		// Render the filter tabs.
		$this->render_filter_tabs();

		// Output the settings table.
		echo '<div class="wvc-table-wrap">';
		echo '<table class="wvc-table" data-target-entity="database">';

		UI::render_table_head(
			array(
				array(
					'label' => __( 'SN', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-sn',
				),
				array(
					'label'    => __( 'Table Name', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Engine', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array(
					'label'    => __( 'Collation', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array(
					'label'    => __( 'Source', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array(
					'label'    => __( 'WP VIP Compatibility', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-status',
					'sortable' => true,
				),
				array(
					'label' => __( 'Notes', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-notes',
				),
			)
		);

		echo '<tbody>';

		foreach ( $tables as $table ) {
			$table_name           = $table->TABLE_NAME; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Column name from information_schema.
			$engine               = $table->ENGINE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Column name from information_schema.
			$collation            = $table->TABLE_COLLATION; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Column name from information_schema.
			$vip_supported        = in_array( $collation, $vip_supported_collations, true );
			$has_supported_prefix = strpos( $table_name, 'wp_' ) === 0;

			// Determine table source.
			$source = $this->get_table_source( $table_name, $core_tables, $vendor_tables, $has_supported_prefix );

			// Determine compatibility.
			$is_compatible = true;
			$notes         = [];

			// Check collation compatibility.
			if ( ! $vip_supported ) {
				$charset             = explode( '_', $collation, 2 )[0] ?? '';
				$suggested_collation = $this->get_suggested_collation( $collation, $vip_supported_collations );

				if ( __( 'Not Supported', 'wp-vip-compatibility' ) !== $suggested_collation ) {
					$notes[] = '<span class="wvc-note__label">' . esc_html__( 'Unsupported collation. Recommended fix:', 'wp-vip-compatibility' ) . '</span>' .
						UI::get_code_snippet( sprintf( 'ALTER TABLE %s CONVERT TO CHARACTER SET %s COLLATE %s;', $table_name, $charset, $suggested_collation ) );
				} else {
					$notes[] = '<span class="wvc-note__label">' . esc_html__( 'The collation is unsupported.', 'wp-vip-compatibility' ) . '</span>';
				}

				$is_compatible = false;
			}

			// Check engine compatibility.
			if ( 'InnoDB' !== $engine ) {
				$notes[] = '<span class="wvc-note__label">' . esc_html__( 'Unsupported storage engine. Recommended fix:', 'wp-vip-compatibility' ) . '</span>' .
					UI::get_code_snippet( sprintf( 'ALTER TABLE %s ENGINE = InnoDB;', $table_name ) );

				$is_compatible = false;
			}

			// Check prefix compatibility.
			if ( ! $has_supported_prefix ) {
				$notes[] = '<span class="wvc-note__label">' . esc_html__( 'Non-standard table prefix. Recommended fix:', 'wp-vip-compatibility' ) . '</span>' .
					UI::get_code_snippet( sprintf( 'ALTER TABLE %1$s RENAME TO wp_%1$s;', $table_name ) );

				$is_compatible = false;
			}

			// Display notes.
			$notes_display = empty( $notes )
				? '<span class="wvc-dash" aria-hidden="true">—</span>'
				: '<ul class="wvc-notes"><li>' . implode( '</li><li>', $notes ) . '</li></ul>';

			// Output table row.
			echo '<tr>';
			echo '<td class="wvc-col-sn"></td>';
			echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Table Name', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $table_name ) . '</code></td>';
			echo '<td data-label="' . esc_attr__( 'Engine', 'wp-vip-compatibility' ) . '">' . esc_html( $engine ) . '</td>';
			echo '<td class="wvc-col-mono" data-label="' . esc_attr__( 'Collation', 'wp-vip-compatibility' ) . '">' . esc_html( $collation ) . '</td>';
			echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Source', 'wp-vip-compatibility' ) . '">' . esc_html( $source ) . '</td>';
			echo UI::get_status_cell( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				$is_compatible ? 'compatible' : 'not-compatible',
				$is_compatible ? __( 'Compatible', 'wp-vip-compatibility' ) : __( 'Not Compatible', 'wp-vip-compatibility' )
			);
			// Every dynamic value inside the notes is escaped as it is assembled above.
			echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'Notes', 'wp-vip-compatibility' ) . '">' . $notes_display . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html__( 'Run the suggested statements against a backup first. VIP requires the InnoDB storage engine, a supported collation and the standard wp_ table prefix.', 'wp-vip-compatibility' ),
			'warning',
			esc_html__( 'Before you run any SQL', 'wp-vip-compatibility' )
		);
	}

	/**
	 * Renders the tabs for filtering the compatible and incompatible tables.
	 *
	 * @return void
	 */
	private function render_filter_tabs() {
		UI::render_toolbar(
			array(
				'search_label' => __( 'Search database tables', 'wp-vip-compatibility' ),
			)
		);
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
