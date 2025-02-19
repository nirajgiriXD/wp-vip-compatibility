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
	 * Render the settings page html.
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

		// Get information about theme updates (must be done in the admin area).
		wp_update_themes(); // This checks for theme updates.

		// Get the list of available theme updates.
		$theme_updates = get_site_transient( 'update_themes' );

		?>
		<table class="wvc-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Theme Name', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Version', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Author', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Theme Directory', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'New Version Available', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'VIP Compatibility', 'wp-vip-compatibility' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $all_themes as $theme_slug => $theme_data ) : ?>
					<?php
					// Get the theme directory path.
					$theme_path = get_theme_root() . '/' . $theme_slug;

					// Check for VIP compatibility.
					$vip_compatibility = function_exists( 'check_vip_compatibility' ) 
						? check_vip_compatibility( $theme_path ) 
						: 'Unknown';
					?>
					<tr>
						<td><?php echo esc_html( $theme_data->get( 'Name' ) ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Version' ) ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Author' ) ); ?></td>
						<td><?php echo esc_html( $theme_slug ); ?></td>
						<td>
							<?php
							// Check if an update is available for this theme.
							if ( isset( $theme_updates->response[ $theme_slug ] ) ) {
								$new_version = $theme_updates->response[ $theme_slug ]['new_version'];
								echo esc_html( $new_version );
							} else {
								echo 'Up to date';
							}
							?>
						</td>
						<td><?php echo esc_html( $vip_compatibility ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

}