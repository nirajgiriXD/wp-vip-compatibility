<?php
/**
 * Submenu page for displaying the mu-plugins settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * This class handles the mu-plugins submenu settings.
 */
class MU_Plugins_Settings {

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
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	
		$mu_plugins = get_mu_plugins();
		?>
	
		<table class="wvc-table">
			<thead>
				<tr>
					<th>Plugin Name</th>
					<th>Path</th>
					<th>Plugin File Path</th>
					<th>Plugin Version</th>
					<th>Author</th>
					<th>VIP Compatibility</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $mu_plugins ) ) : ?>
					<tr>
						<td colspan="6" style="text-align: center;">No MU plugins are present.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $mu_plugins as $plugin_file => $plugin_data ) : ?>
						<?php 
							$plugin_path = WP_CONTENT_DIR . '/mu-plugins/' . $plugin_file;
							$vip_compatibility = check_vip_compatibility( $plugin_path );
						?>
						<tr>
							<td><?php echo esc_html( $plugin_data['Name'] ); ?></td>
							<td><?php echo esc_html( $plugin_data['PluginURI'] ); ?></td>
							<td><?php echo esc_html( $plugin_file ); ?></td>
							<td><?php echo isset( $plugin_data['Version'] ) ? esc_html( $plugin_data['Version'] ) : 'N/A'; ?></td>
							<td><?php echo esc_html( $plugin_data['Author'] ); ?></td>
							<td><?php echo esc_html( $vip_compatibility ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

}