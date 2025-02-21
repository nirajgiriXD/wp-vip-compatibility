<?php
/**
 * Submenu page for displaying the MU-Plugins settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * Handles the MU-Plugins submenu settings.
 */
class MU_Plugins_Settings {

	use Singleton;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Renders the MU-Plugins settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$mu_plugins = get_mu_plugins();

		echo '<table class="wvc-table">';
		echo '<thead><tr>
				<th>' . esc_html__( 'SN', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Plugin Name', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Plugin Directory', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Author', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Version', 'wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'WP VIP Compatibility', 'wp-vip-compatibility' ) . '</th>
			</tr></thead>';
		echo '<tbody>';

		if ( empty( $mu_plugins ) ) {
			echo '<tr><td colspan="7" style="text-align: center;">' . esc_html__( 'No MU plugins are present.', 'wp-vip-compatibility' ) . '</td></tr>';
		} else {
			$counter = 1;
			foreach ( $mu_plugins as $plugin_file => $plugin_data ) {
				$plugin_path = WPMU_PLUGIN_DIR . '/' . $plugin_file;

				echo '<tr>';
				echo '<td>' . esc_html( $counter++ ) . '</td>';
				echo '<td>' . esc_html( $plugin_data['Name'] ) . '</td>';
				echo '<td>' . esc_html( $plugin_file ) . '</td>';
				echo '<td>' . esc_html( $plugin_data['Author'] ) . '</td>';
				echo '<td>' . ( ! empty( $plugin_data['Version'] ) ? esc_html( $plugin_data['Version'] ) : '-' ) . '</td>';
				echo '<td class="vip-compatibility-status" data-directory-path="' . esc_attr( $plugin_path ) . '">' . esc_html__( 'Loading...', 'wp-vip-compatibility' ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		// Placeholder for log file information (will be updated via AJAX)
		echo '<div id="wvc-log-note-container" data-filename="mu-plugins"></div>';
	}
}
