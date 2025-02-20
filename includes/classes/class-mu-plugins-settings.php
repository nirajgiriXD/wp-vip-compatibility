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
	 * Render the settings page HTML.
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
					<th><?php esc_html_e( 'Plugin Name', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Path', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Plugin Directory', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Version', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Author', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Compatibility', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'wp-vip-compatibility' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $mu_plugins ) ) : ?>
					<tr>
						<td colspan="7" style="text-align: center;"><?php esc_html_e( 'No MU plugins are present.', 'wp-vip-compatibility' ); ?></td>
					</tr>
				<?php else : ?>
					<?php $counter = 1; ?>
					<?php foreach ( $mu_plugins as $plugin_file => $plugin_data ) : ?>
						<?php 
							$plugin_path = WP_CONTENT_DIR . '/mu-plugins/' . $plugin_file;
							$vip_status = wvc_check_vip_compatibility( $plugin_path );
							$compatibility_class = 'Compatible' === $vip_status ? 'compatible' : 'not-compatible';
						?>
						<tr>
							<td><?php echo esc_html( $counter++ ); ?></td>
							<td><?php echo esc_html( $plugin_data['Name'] ); ?></td>
							<td><?php echo esc_html( $plugin_data['PluginURI'] ); ?></td>
							<td><?php echo esc_html( $plugin_file ); ?></td>
							<td><?php echo isset( $plugin_data['Version'] ) ? esc_html( $plugin_data['Version'] ) : 'N/A'; ?></td>
							<td><?php echo esc_html( $plugin_data['Author'] ); ?></td>
							<td class="<?php echo esc_attr( $compatibility_class ); ?>">
								<?php echo esc_html( $vip_status ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php
		$log_file_path = WP_CONTENT_DIR . '/uploads/wvc-logs/mu-plugins.txt';

		if ( file_exists( $log_file_path ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Note:', 'wp-vip-compatibility' ); ?></strong> <?php esc_html_e( 'The log file containing all the details is available for download at ', 'wp-vip-compatibility' ); ?>
				<a href="<?php echo esc_url( WP_CONTENT_URL . '/uploads/wvc-logs/mu-plugins.txt' ); ?>" download>
					<?php esc_html_e( 'wp-content/uploads/wvc-logs/mu-plugins.txt', 'wp-vip-compatibility' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p><strong><?php esc_html_e( 'Note:', 'wp-vip-compatibility' ); ?></strong> <?php esc_html_e( 'No incompatibility logs were generated.', 'wp-vip-compatibility' ); ?></p>
		<?php endif; ?>

		<?php
	}
}
