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
	 * Checks the VIP compatibility of a theme.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return array Compatibility status and class.
	 */
	private function get_theme_vip_status( $theme_slug ) {
		$theme_path = get_theme_root() . '/' . $theme_slug;
		$vip_status = wvc_check_vip_compatibility( $theme_path );
		$compatibility_class = ( 'Compatible' === $vip_status ) ? 'compatible' : 'not-compatible';

		return [
			'status' => esc_html( $vip_status ),
			'class'  => esc_attr( $compatibility_class ),
		];
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
					<th><?php esc_html_e( 'VIP Compatibility', 'wp-vip-compatibility' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$counter = 1;
				foreach ( $all_themes as $theme_slug => $theme_data ) :
					$update_version = $this->get_theme_update_version( $theme_slug );
					$vip_info = $this->get_theme_vip_status( $theme_slug );
				?>
					<tr>
						<td><?php echo esc_html( $counter++ ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Name' ) ); ?></td>
						<td><?php echo esc_html( $theme_slug ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Author' ) ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Version' ) ); ?></td>
						<td><?php echo esc_html( $update_version ); ?></td>
						<td class="<?php echo $vip_info['class']; ?>">
							<?php echo $vip_info['status']; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( file_exists( $log_file_path ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Note:', 'wp-vip-compatibility' ); ?></strong> 
				<?php esc_html_e( 'The log file is available at ', 'wp-vip-compatibility' ); ?>
				<a href="<?php echo esc_url( WP_CONTENT_URL . '/uploads/wvc-logs/themes.txt' ); ?>" download>
					<?php esc_html_e( 'wp-content/uploads/wvc-logs/themes.txt', 'wp-vip-compatibility' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p><strong><?php esc_html_e( 'Note:', 'wp-vip-compatibility' ); ?></strong> 
				<?php esc_html_e( 'No incompatibility logs were generated.', 'wp-vip-compatibility' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}
}
