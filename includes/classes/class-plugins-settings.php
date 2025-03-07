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
		$all_plugins   = $this->get_installed_plugins();
		$plugin_updates = $this->get_plugin_updates();

		// Render the filter tabs.
		$this->render_filter_tabs();

		// Render the table.
		echo '<table class="wvc-table" data-target-entity="plugins">';
		$this->render_table_header();
		echo '<tbody>';

		$counter = 1;
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$this->render_plugin_row( $counter++, $plugin_file, $plugin_data, $plugin_updates );
		}

		echo '</tbody></table>';

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
	 * Renders the table header.
	 *
	 * @return void
	 */
	private function render_table_header() {
		$headers = [
			esc_html__( 'SN', 'wp-vip-compatibility' ),
			esc_html__( 'Plugin Name', 'wp-vip-compatibility' ),
			esc_html__( 'Plugin Directory', 'wp-vip-compatibility' ),
			esc_html__( 'Author', 'wp-vip-compatibility' ),
			esc_html__( 'Current Version', 'wp-vip-compatibility' ),
			esc_html__( 'Available Version', 'wp-vip-compatibility' ),
			esc_html__( 'WP VIP Compatibility', 'wp-vip-compatibility' ),
			esc_html__( 'Notes', 'wp-vip-compatibility' ),
		];

		echo '<thead><tr>';
		foreach ( $headers as $header ) {
			echo '<th>' . $header . '</th>';
		}
		echo '</tr></thead>';
	}

	/**
	 * Renders a plugin row.
	 *
	 * @param int    $counter        Plugin serial number.
	 * @param string $plugin_file    Plugin file path.
	 * @param array  $plugin_data    Plugin metadata.
	 * @param object $plugin_updates Plugin update transient data.
	 *
	 * @return void
	 */
	private function render_plugin_row( $counter, $plugin_file, $plugin_data, $plugin_updates ) {
		$plugin_slug                  = dirname( $plugin_file );
		$plugin_version               = $plugin_data['Version'];
		$plugin_path                  = WP_PLUGIN_DIR . '/' . $plugin_slug;
		$is_vip_disallowed_plugins    = isset( $this->json_data['known_plugins']['vip_disallowed_plugins'] ) && in_array( $plugin_slug, $this->json_data['known_plugins']['vip_disallowed_plugins'], true );
		$is_tested_compatible_plugins = isset( $this->json_data['known_plugins']['tested_compatible_plugins'] ) && in_array( $plugin_slug, $this->json_data['known_plugins']['tested_compatible_plugins'], true );
		$is_vip_mu_plugin = isset( $this->json_data['known_mu_plugins'][ $plugin_slug ] ) && 'automattic' === $this->json_data['known_mu_plugins'][ $plugin_slug ]['source'];

		$note = '-';

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
		$new_version = isset( $plugin_updates->response[ $plugin_file ] ) 
			? esc_html( $plugin_updates->response[ $plugin_file ]->new_version ) 
			: esc_html__( 'Up to date', 'wp-vip-compatibility' );

		echo '<tr>';
		echo '<td>' . esc_html( $counter ) . '</td>';
		echo '<td>' . esc_html( $plugin_data['Name'] ) . '</td>';
		echo '<td>' . esc_html( $plugin_file ) . '</td>';
		echo '<td>' . esc_html( $plugin_data['Author'] ) . '</td>';
		echo '<td>' . esc_html( $plugin_version ) . '</td>';
		echo '<td>' . esc_html( $new_version ) . '</td>';

		if ( $is_vip_disallowed_plugins || $is_vip_mu_plugin ) {
			echo '<td class="not-compatible">' . esc_html__( 'Incompatible', 'wp-vip-compatibility' ) . '</td>';
		} elseif ( $is_tested_compatible_plugins ) {
			echo '<td class="compatible">' . esc_html__( 'Compatible', 'wp-vip-compatibility' ) . '</td>';
		} else {
			echo '<td class="vip-compatibility-status" data-directory-path="' . esc_attr( $plugin_path ) . '">' . esc_html__( 'Loading...', 'wp-vip-compatibility' ) . '</td>';
		}

		echo '<td>' . wp_kses_post( $note ) . '</td>';
		echo '</tr>';
	}
}
