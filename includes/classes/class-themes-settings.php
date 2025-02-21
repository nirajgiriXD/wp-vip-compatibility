<?php
/**
 * Submenu page for displaying the themes settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * Handles the themes submenu settings.
 */
class Themes_Settings {

	use Singleton;

	/**
	 * Constructor method.
	 */
	private function __construct() {}

	/**
	 * Retrieves all installed themes.
	 *
	 * @return array List of themes.
	 */
	private function get_all_themes() {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		return wp_get_themes();
	}

	/**
	 * Retrieves the update version of a theme.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string Available version or "Up to date".
	 */
	private function get_theme_update_version( $theme_slug ) {
		$theme_updates = get_site_transient( 'update_themes' );

		return isset( $theme_updates->response[ $theme_slug ] ) 
			? esc_html( $theme_updates->response[ $theme_slug ]['new_version'] ) 
			: esc_html__( 'Up to date', 'wp-vip-compatibility' );
	}

	/**
	 * Renders the settings page HTML.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$all_themes = $this->get_all_themes();
		$log_file_path = WP_CONTENT_DIR . '/uploads/wvc-logs/themes.txt';
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
					<th><?php esc_html_e( 'WP VIP Compatibility', 'wp-vip-compatibility' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$counter = 1;
				foreach ( $all_themes as $theme_slug => $theme_data ) :
					$update_version = $this->get_theme_update_version( $theme_slug );
					$theme_path     = get_theme_root() . '/' . $theme_slug;
				?>
					<tr>
						<td><?php echo esc_html( $counter++ ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Name' ) ); ?></td>
						<td><?php echo esc_html( $theme_slug ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Author' ) ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Version' ) ); ?></td>
						<td><?php echo esc_html( $update_version ); ?></td>
						<td class="vip-compatibility-status" data-directory-path="<?php echo esc_attr( $theme_path ); ?>">
							<?php esc_html_e( 'Loading...', 'wp-vip-compatibility' ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Placeholder for log file information (will be updated via AJAX) -->
		<div id="wvc-log-note-container" data-filename="themes"></div>

		<?php
	}
}
