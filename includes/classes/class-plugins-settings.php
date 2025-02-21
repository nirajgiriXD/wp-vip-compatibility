<?php
/**
 * Submenu page for displaying the plugins settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Classes\Plugin;

/**
 * Handles the plugins submenu settings.
 */
class Plugins_Settings {

	use Singleton;

	/**
	 * Stores plugin data from JSON file.
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
	 * Renders the settings page HTML.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$all_plugins = $this->get_installed_plugins();
		$plugin_updates = $this->get_plugin_updates();

		echo '<table class="wvc-table">';
		$this->render_table_header();
		echo '<tbody>';

		$counter = 1;
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$this->render_plugin_row( $counter++, $plugin_file, $plugin_data, $plugin_updates );
		}

		echo '</tbody></table>';

		$this->render_log_file_section();
	}

	/**
	 * Retrieves installed plugins.
	 *
	 * @return array List of installed plugins.
	 */
	private function get_installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugins();
	}

	/**
	 * Retrieves plugin update information.
	 *
	 * @return object|null Plugin update transient data.
	 */
	private function get_plugin_updates() {
		if ( is_admin() ) {
			wp_update_plugins();
		}
		return get_site_transient( 'update_plugins' );
	}

	/**
	 * Renders the table header.
	 */
	private function render_table_header() {
		$headers = [
			'SN', 'Plugin Name', 'Plugin Directory', 'Author', 'Current Version', 'Available Version', 'VIP Compatibility'
		];
		echo '<thead><tr>';
		foreach ( $headers as $header ) {
			echo '<th>' . esc_html__( $header, 'wp-vip-compatibility' ) . '</th>';
		}
		echo '</tr></thead>';
	}

	/**
	 * Renders a plugin row.
	 *
	 * @param int    $counter Plugin serial number.
	 * @param string $plugin_file Plugin file path.
	 * @param array  $plugin_data Plugin metadata.
	 * @param object $plugin_updates Plugin update transient data.
	 */
	private function render_plugin_row( $counter, $plugin_file, $plugin_data, $plugin_updates ) {
		$plugin_slug = dirname( $plugin_file );
		$plugin_version = $plugin_data['Version'];
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;

		$vip_status_info = $this->get_vip_compatibility_status( $plugin_slug, $plugin_version, $plugin_path );
		$new_version = isset( $plugin_updates->response[ $plugin_file ] ) ? $plugin_updates->response[ $plugin_file ]->new_version : esc_html__( 'Up to date', 'wp-vip-compatibility' );

		echo '<tr>';
		echo '<td>' . esc_html( $counter ) . '</td>';
		echo '<td>' . esc_html( $plugin_data['Name'] ) . '</td>';
		echo '<td>' . esc_html( $plugin_file ) . '</td>';
		echo '<td>' . esc_html( $plugin_data['Author'] ) . '</td>';
		echo '<td>' . esc_html( $plugin_version ) . '</td>';
		echo '<td>' . esc_html( $new_version ) . '</td>';
		echo '<td class="' . esc_attr( $vip_status_info['class'] ) . '">' . esc_html( $vip_status_info['message'] ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Determines VIP compatibility status.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string $plugin_version Plugin version.
	 * @param string $plugin_path Plugin full path.
	 * @return array Compatibility status message and class.
	 */
	private function get_vip_compatibility_status( $plugin_slug, $plugin_version, $plugin_path ) {
		if ( isset( $this->json_data['known_plugins'][ $plugin_slug ][ $plugin_version ] ) ) {
			return [
				'message' => __( 'Compatible: Plugin has a predefined VIP compatibility status.', 'wp-vip-compatibility' ),
				'class'   => 'compatible'
			];
		}

		if ( in_array( $plugin_slug, $this->json_data['vip_mu_plugins'], true ) ) {
			return [
				'message' => __( 'Already Present: Plugin is already present on WP VIP.', 'wp-vip-compatibility' ),
				'class'   => 'not-compatible'
			];
		}

		if ( in_array( $plugin_slug, $this->json_data['vip_disallowed_plugins'], true ) ) {
			return [
				'message' => __( 'Incompatible: Plugin is not allowed on WP VIP.', 'wp-vip-compatibility' ),
				'class'   => 'not-compatible'
			];
		}

		if ( in_array( $plugin_slug, $this->json_data['already_tested_plugins'], true ) ) {
			return [
				'message' => __( 'Compatible: Plugin already tested for WP VIP.', 'wp-vip-compatibility' ),
				'class'   => 'compatible'
			];
		}

		$status = wvc_check_vip_compatibility( $plugin_path );
		return [
			'message' => $status,
			'class'   => 'Compatible' === $status ? 'compatible' : 'not-compatible'
		];
	}

	/**
	 * Renders the log file download section.
	 */
	private function render_log_file_section() {
		$log_file_path = WP_CONTENT_DIR . '/uploads/wvc-logs/plugins.txt';

		if ( file_exists( $log_file_path ) ) {
			echo '<p><strong>' . esc_html__( 'Note:', 'wp-vip-compatibility' ) . '</strong> ';
			printf(
				esc_html__( 'The log file containing all the details is available for download at %s.', 'wp-vip-compatibility' ),
				'<a href="' . esc_url( WP_CONTENT_URL . '/uploads/wvc-logs/plugins.txt' ) . '" download>' .
				esc_html__( 'wp-content/uploads/wvc-logs/plugins.txt', 'wp-vip-compatibility' ) . '</a>'
			);
			echo '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Note:', 'wp-vip-compatibility' ) . '</strong> ' .
				esc_html__( 'No incompatibility logs were generated.', 'wp-vip-compatibility' ) . '</p>';
		}
	}
}
