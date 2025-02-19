<?php
/**
 * Submenu page for displaying the plugins settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * This class handles the plugins submenu settings.
 */
class Plugins_Settings {

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
		$vip_mu_plugins = array(
			'advanced-post-cache',
			'akismet',
			'block-api-data',
			'cron-control',
			'elasticpress',
			'elasticpress-next',
			'es-wp-query',
			'debug-bar-elasticpress',
			'jetpack',
			'lightweight-term-count-update',
			'nginx-http-concat',
			'query-monitor',
			'rewrite-rules-inspector',
			'two-factor',
			'wp-parsely',
		);

		$vip_disallowed_plugins = array(
			'all-in-one-wp-migration',
			'wp-all-import',
			'wp-all-import-pro',
			'wp-all-export',
			'wp-all-export-pro',
			'w3-total-cache',
			'wp-fastest-cache',
			'wp-rocket',
			'wp-super-cache',
			'wp-smushit',
			'sucuri-scanner',
			'wordfence',
		);

		$known_plugins = array(
			'aryo-activity-log'                  => array( '2.10.1' => 'Yes' ),
			'memberpress-importer'               => array( '1.6.18' => 'Yes' ),
			'memberpress-pdf-invoice'            => array( '1.1.23' => 'Yes' ),
			'memberpress'                        => array( '1.11.28' => 'Yes' ),
			'miniorange-2-factor-authentication' => array( '5.8.3' => 'Yes' ),
			'miniorange-saml-20-single-sign-on'  => array( '5.1.4' => 'Yes' ),
			'post-smtp'                          => array( '2.9.1' => 'Yes' ),
			'cc-post-to-pdf'                     => array( '2.0' => 'Yes' ),
			'tiny-compress-images'               => array( '3.4.3' => 'Yes' ),
			'wpdatatables'                       => array( '3.4.2.11' => 'Yes' ),
			'wp-file-manager'                    => array( '7.2.6' => 'Yes' ),
			'wpforms'                            => array( '1.8.7.2' => 'Yes' ),
			'zoom-meeting'                       => array( '1.0' => 'Yes' ),
		);

		$already_tested_plugins = array(
			'advanced-custom-fields-pro',
			'advanced-custom-fields',
		);

		// Load necessary WordPress functions
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Get all installed plugins
		$all_plugins = get_plugins();

		// Get plugin update information (only works in admin area)
		wp_update_plugins();
		$plugin_updates = get_site_transient( 'update_plugins' );

		?>

		<table class="wvc-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plugin Name', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Version', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Author', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Plugin File Path', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'New Version Available', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'VIP Compatibility', 'wp-vip-compatibility' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $all_plugins as $plugin_file => $plugin_data ) : ?>
					<?php
					$plugin_slug = dirname( $plugin_file );
					$plugin_version = $plugin_data['Version'];
					$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;

					// 1️⃣ Check if plugin is in known plugins list with specific VIP status
					if ( isset( $known_plugins[ $plugin_slug ] ) && isset( $known_plugins[ $plugin_slug ][ $plugin_version ] ) ) {
						$vip_status = $known_plugins[ $plugin_slug ][ $plugin_version ];
					}
					// 2️⃣ Check if plugin is VIP-recommended
					elseif ( in_array( $plugin_slug, $vip_mu_plugins, true ) ) {
						$vip_status = 'Recommended';
					}
					// 3️⃣ Check if plugin is disallowed on VIP
					elseif ( in_array( $plugin_slug, $vip_disallowed_plugins, true ) ) {
						$vip_status = 'Disallowed on VIP';
					}
					// 4️⃣ Check if plugin is already tested
					elseif ( in_array( $plugin_slug, $already_tested_plugins, true ) ) {
						$vip_status = 'Already Tested';
					}
					// 5️⃣ Run PHPCS scan for filesystem writes
					else {
						$vip_status = check_vip_compatibility( $plugin_path );
					}

					?>
					<tr>
						<td><?php echo esc_html( $plugin_data['Name'] ); ?></td>
						<td><?php echo esc_html( $plugin_version ); ?></td>
						<td><?php echo esc_html( $plugin_data['Author'] ); ?></td>
						<td><?php echo esc_html( $plugin_file ); ?></td>
						<td>
							<?php
							if ( isset( $plugin_updates->response[ $plugin_file ] ) ) {
								$new_version = $plugin_updates->response[ $plugin_file ]->new_version;
								echo esc_html( $new_version );
							} else {
								echo 'Up to date';
							}
							?>
						</td>
						<td><?php echo esc_html( $vip_status ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

}