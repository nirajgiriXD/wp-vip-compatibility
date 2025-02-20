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
 * This class handles the plugins submenu settings.
 */
class Plugins_Settings {

	use Singleton;

	/**
	 * Stores plugin data from JSON file.
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
	 * Render the settings page HTML.
	 *
	 * @return void
	 */
	public function render_settings_page() {

		// Load necessary WordPress functions.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Get all installed plugins.
		$all_plugins = get_plugins();

		// Get plugin update information (only works in admin area).
		wp_update_plugins();
		$plugin_updates = get_site_transient( 'update_plugins' );

		?>

		<table class="wvc-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'SN', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Plugin Name', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Plugin Directory', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Author', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Current Version', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Available Version', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'VIP Compatibility', 'wp-vip-compatibility' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php $counter = 1; ?>
				<?php foreach ( $all_plugins as $plugin_file => $plugin_data ) : ?>
					<?php
					$plugin_slug         = dirname( $plugin_file );
					$plugin_version      = $plugin_data['Version'];
					$plugin_path         = WP_PLUGIN_DIR . '/' . $plugin_slug;
					$compatibility_class = 'compatible';

					// Determine VIP compatibility status.
					if ( isset( $this->json_data['known_plugins'][ $plugin_slug ] ) && isset( $this->json_data['known_plugins'][ $plugin_slug ][ $plugin_version ] ) ) {
						$vip_status = __( 'Compatible: Plugin has a predefined VIP compatibility status.', 'wp-vip-compatibility' );
					} elseif ( in_array( $plugin_slug, $this->json_data['vip_mu_plugins'], true ) ) {
						$vip_status = __( 'Already Present: Plugin is already present on WP VIP.', 'wp-vip-compatibility' );
						$compatibility_class = 'not-compatible';
					} elseif ( in_array( $plugin_slug, $this->json_data['vip_disallowed_plugins'], true ) ) {
						$vip_status = __( 'Incompatible: Plugin is not allowed on WP VIP.', 'wp-vip-compatibility' );
						$compatibility_class = 'not-compatible';
					} elseif ( in_array( $plugin_slug, $this->json_data['already_tested_plugins'], true ) ) {
						$vip_status = __( 'Compatible: Plugin already tested for WP VIP.', 'wp-vip-compatibility' );
					} else {
						$vip_status = wvc_check_vip_compatibility( $plugin_path );
						$compatibility_class = 'Compatible' === $vip_status ? 'compatible' : 'not-compatible';
					}

					// Check if an update is available for this plugin.
					$new_version = isset( $plugin_updates->response[ $plugin_file ] ) 
						? $plugin_updates->response[ $plugin_file ]->new_version 
						: 'Up to date';

					?>
					<tr>
						<td><?php echo esc_html( $counter++ ); ?></td>
						<td><?php echo esc_html( $plugin_data['Name'] ); ?></td>
						<td><?php echo esc_html( $plugin_file ); ?></td>
						<td><?php echo esc_html( $plugin_data['Author'] ); ?></td>
						<td><?php echo esc_html( $plugin_version ); ?></td>
						<td><?php echo esc_html( $new_version ); ?></td>
						<td class="<?php echo esc_attr( $compatibility_class ); ?>">
							<?php echo esc_html( $vip_status ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
