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
	 * Renders the settings page HTML.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$all_plugins    = $this->get_installed_plugins();
		$plugin_updates = $this->get_plugin_updates();

		if ( empty( $all_plugins ) ) {
			UI::render_empty_state(
				__( 'No plugins installed', 'wp-vip-compatibility' ),
				__( 'There is nothing to check on this screen yet.', 'wp-vip-compatibility' )
			);
			return;
		}

		// Render the filter tabs.
		$this->render_filter_tabs();

		// Render the table.
		echo '<div class="wvc-table-wrap">';
		echo '<table class="wvc-table" data-target-entity="plugins">';
		$this->render_table_header();
		echo '<tbody>';

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$this->render_plugin_row( $plugin_file, $plugin_data, $plugin_updates );
		}

		echo '</tbody></table>';
		echo '</div>';

		// Placeholder for log file information (updated via AJAX).
		echo '<div id="wvc-log-note-container" data-filename="plugins"></div>';
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
	 * Renders the tabs for filtering the compatible and incompatible plugins.
	 *
	 * @return void
	 */
	private function render_filter_tabs() {
		UI::render_toolbar(
			array(
				'search_label'  => __( 'Search plugins', 'wp-vip-compatibility' ),
				'show_progress' => true,
			)
		);
	}

	/**
	 * Renders the table header.
	 *
	 * @return void
	 */
	private function render_table_header() {
		UI::render_table_head(
			array(
				array(
					'label' => __( 'SN', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-sn',
				),
				array(
					'label'    => __( 'Plugin Name', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Plugin Directory', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-path',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Author', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array( 'label' => __( 'Current Version', 'wp-vip-compatibility' ) ),
				array( 'label' => __( 'Available Version', 'wp-vip-compatibility' ) ),
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
	}

	/**
	 * Renders a plugin row.
	 *
	 * @param string $plugin_file    Plugin file path.
	 * @param array  $plugin_data    Plugin metadata.
	 * @param object $plugin_updates Plugin update transient data.
	 *
	 * @return void
	 */
	private function render_plugin_row( $plugin_file, $plugin_data, $plugin_updates ) {
		$plugin_slug                  = dirname( $plugin_file );
		$plugin_version               = $plugin_data['Version'];
		$plugin_path                  = WP_PLUGIN_DIR . '/' . $plugin_slug;
		$is_vip_disallowed_plugins    = isset( $this->json_data['known_plugins']['vip_disallowed_plugins'] ) && in_array( $plugin_slug, $this->json_data['known_plugins']['vip_disallowed_plugins'], true );
		$is_tested_compatible_plugins = isset( $this->json_data['known_plugins']['tested_compatible_plugins'] ) && in_array( $plugin_slug, $this->json_data['known_plugins']['tested_compatible_plugins'], true );
		$is_vip_mu_plugin             = isset( $this->json_data['known_mu_plugins'][ $plugin_slug ] ) && 'automattic' === $this->json_data['known_mu_plugins'][ $plugin_slug ]['source'];

		$note = '<span class="wvc-dash" aria-hidden="true">—</span>';

		if ( $is_vip_disallowed_plugins ) {
			$note = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://docs.wpvip.com/plugins/incompatibilities/' ),
				esc_html__( 'VIP listed incompatible plugin', 'wp-vip-compatibility' )
			);
		} elseif ( $is_tested_compatible_plugins ) {
			$note = esc_html__( 'Tested and verified VIP-compatible', 'wp-vip-compatibility' );
		} elseif ( $is_vip_mu_plugin ) {
			$note = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://docs.wpvip.com/vip-go-mu-plugins/' ),
				esc_html__( 'Plugin will be preinstalled in VIP platform', 'wp-vip-compatibility' )
			);
		}

		// Get the new version if available.
		$has_update  = isset( $plugin_updates->response[ $plugin_file ] );
		$new_version = $has_update
			? esc_html( $plugin_updates->response[ $plugin_file ]->new_version )
			: esc_html__( 'Up to date', 'wp-vip-compatibility' );

		echo '<tr>';
		echo '<td class="wvc-col-sn"></td>';
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Plugin Name', 'wp-vip-compatibility' ) . '">' . esc_html( $plugin_data['Name'] ) . '</td>';
		echo '<td class="wvc-col-path" data-label="' . esc_attr__( 'Plugin Directory', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $plugin_file ) . '</code></td>';
		echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Author', 'wp-vip-compatibility' ) . '">' . esc_html( wp_strip_all_tags( $plugin_data['Author'] ) ) . '</td>';
		echo '<td data-label="' . esc_attr__( 'Current Version', 'wp-vip-compatibility' ) . '"><span class="wvc-version">' . esc_html( $plugin_version ) . '</span></td>';
		echo '<td data-label="' . esc_attr__( 'Available Version', 'wp-vip-compatibility' ) . '"><span class="wvc-version' . ( $has_update ? '' : ' wvc-version--current' ) . '">' . esc_html( $new_version ) . '</span></td>';

		if ( $is_vip_disallowed_plugins || $is_vip_mu_plugin ) {
			echo UI::get_status_cell( 'not-compatible' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		} elseif ( $is_tested_compatible_plugins ) {
			echo UI::get_status_cell( 'compatible' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		} else {
			echo UI::get_status_cell( 'pending', '', array( 'data-directory-path' => $plugin_path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		}

		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'Notes', 'wp-vip-compatibility' ) . '">' . wp_kses_post( $note ) . '</td>';
		echo '</tr>';
	}
}
