<?php
/**
 * Submenu page for displaying the themes settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * This class handles the themes submenu settings.
 */
class Themes_Settings {

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
		// Load necessary WordPress functions for themes.
		if ( ! function_exists( 'wp_get_themes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		// Get all installed themes.
		$all_themes = wp_get_themes();

		// Get available theme updates.
		$theme_updates = get_site_transient( 'update_themes' );

		?>
		<table class="wvc-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'SN', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Theme Name', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Theme Directory', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Author', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Current Version', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Available Version', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'VIP Compatibility', 'wp-vip-compatibility' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php $counter = 1; ?>
				<?php foreach ( $all_themes as $theme_slug => $theme_data ) : ?>
					<?php
					// Get the theme directory path.
					$theme_path = get_theme_root() . '/' . $theme_slug;

					// Check for VIP compatibility.
					$vip_status = wvc_check_vip_compatibility( $theme_path );

					// Set the compatibility class.
					$compatibility_class = 'Compatible' === $vip_status ? 'compatible' : 'not-compatible';

					// Check if an update is available for this theme.
					$new_version = isset( $theme_updates->response[ $theme_slug ] ) 
						? $theme_updates->response[ $theme_slug ]['new_version'] 
						: esc_html__( 'Up to date', 'wp-vip-compatibility' );
					?>
					<tr>
						<td><?php echo esc_html( $counter++ ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Name' ) ); ?></td>
						<td><?php echo esc_html( $theme_slug ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Author' ) ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Version' ) ); ?></td>
						<td><?php echo esc_html( $new_version ); ?></td>
						<td class="<?php echo esc_attr( $compatibility_class ); ?>">
							<?php echo esc_html( $vip_status ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$log_file_path = WP_CONTENT_DIR . '/uploads/wvc-logs/themes.txt';

		if ( file_exists( $log_file_path ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Note:', 'wp-vip-compatibility' ); ?></strong> <?php esc_html_e( 'The log file containing all the details is available for download at ', 'wp-vip-compatibility' ); ?>
				<a href="<?php echo esc_url( WP_CONTENT_URL . '/uploads/wvc-logs/themes.txt' ); ?>" download>
					<?php esc_html_e( 'wp-content/uploads/wvc-logs/themes.txt', 'wp-vip-compatibility' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p><strong><?php esc_html_e( 'Note:', 'wp-vip-compatibility' ); ?></strong> <?php esc_html_e( 'No incompatibility logs were generated.', 'wp-vip-compatibility' ); ?></p>
		<?php endif; ?>

		<?php
	}

}
