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
				<th>' . esc_html__( 'Compatibility', 'wp-vip-compatibility' ) . '</th>
			</tr></thead>';
		echo '<tbody>';

		if ( empty( $mu_plugins ) ) {
			echo '<tr><td colspan="7" style="text-align: center;">' . esc_html__( 'No MU plugins are present.', 'wp-vip-compatibility' ) . '</td></tr>';
		} else {
			$counter = 1;
			foreach ( $mu_plugins as $plugin_file => $plugin_data ) {
				$plugin_path = WP_CONTENT_DIR . '/mu-plugins/' . $plugin_file;
				$vip_status = wvc_check_vip_compatibility( $plugin_path );
				$compatibility_class = ( 'Compatible' === $vip_status ) ? 'compatible' : 'not-compatible';

				echo '<tr>';
				echo '<td>' . esc_html( $counter++ ) . '</td>';
				echo '<td>' . esc_html( $plugin_data['Name'] ) . '</td>';
				echo '<td>' . esc_html( $plugin_file ) . '</td>';
				echo '<td>' . esc_html( $plugin_data['Author'] ) . '</td>';
				echo '<td>' . ( ! empty( $plugin_data['Version'] ) ? esc_html( $plugin_data['Version'] ) : '-' ) . '</td>';
				echo '<td class="' . esc_attr( $compatibility_class ) . '">' . esc_html( $vip_status ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		// Display log file information
		$this->render_log_file_info();
	}

	/**
	 * Renders the log file information section.
	 *
	 * @return void
	 */
	private function render_log_file_info() {
		$log_file_path = WP_CONTENT_DIR . '/uploads/wvc-logs/mu-plugins.txt';

		if ( file_exists( $log_file_path ) ) {
			echo '<p><strong>' . esc_html__( 'Note:', 'wp-vip-compatibility' ) . '</strong> ';
			echo esc_html__( 'The log file containing all the details is available for download at ', 'wp-vip-compatibility' );
			echo '<a href="' . esc_url( WP_CONTENT_URL . '/uploads/wvc-logs/mu-plugins.txt' ) . '" download>';
			echo esc_html__( 'wp-content/uploads/wvc-logs/mu-plugins.txt', 'wp-vip-compatibility' ) . '</a></p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Note:', 'wp-vip-compatibility' ) . '</strong> ';
			echo esc_html__( 'No incompatibility logs were generated.', 'wp-vip-compatibility' ) . '</p>';
		}
	}
}
