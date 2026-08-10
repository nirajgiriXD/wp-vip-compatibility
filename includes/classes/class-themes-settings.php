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
	 * @param string      $theme_slug    Theme slug.
	 * @param object|null $theme_updates Theme update transient data.
	 * @return string Available version or "Up to date".
	 */
	private function get_theme_update_version( $theme_slug, $theme_updates = null ) {
		if ( null === $theme_updates ) {
			$theme_updates = get_site_transient( 'update_themes' );
		}

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
		$all_themes    = $this->get_all_themes();
		$theme_updates = get_site_transient( 'update_themes' );

		if ( empty( $all_themes ) ) {
			UI::render_empty_state(
				__( 'No themes installed', 'wp-vip-compatibility' ),
				__( 'There is nothing to check on this screen yet.', 'wp-vip-compatibility' )
			);
			return;
		}

		UI::render_toolbar(
			array(
				'search_label'  => __( 'Search themes', 'wp-vip-compatibility' ),
				'show_progress' => true,
			)
		);
		?>

		<div class="wvc-table-wrap">
			<table class="wvc-table" data-target-entity="themes">
				<?php
				UI::render_table_head(
					array(
						array(
							'label' => __( 'SN', 'wp-vip-compatibility' ),
							'class' => 'wvc-col-sn',
						),
						array(
							'label'    => __( 'Theme Name', 'wp-vip-compatibility' ),
							'class'    => 'wvc-col-name',
							'sortable' => true,
						),
						array(
							'label'    => __( 'Theme Directory', 'wp-vip-compatibility' ),
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
					)
				);
				?>
				<tbody>
					<?php
					foreach ( $all_themes as $theme_slug => $theme_data ) :
						$update_version = $this->get_theme_update_version( $theme_slug, $theme_updates );
						$theme_path     = get_theme_root() . '/' . $theme_slug;
						$is_up_to_date  = ! isset( $theme_updates->response[ $theme_slug ] );
						?>
						<tr>
							<td class="wvc-col-sn"></td>
							<td class="wvc-col-name" data-label="<?php esc_attr_e( 'Theme Name', 'wp-vip-compatibility' ); ?>">
								<?php echo esc_html( $theme_data->get( 'Name' ) ); ?>
							</td>
							<td class="wvc-col-path" data-label="<?php esc_attr_e( 'Theme Directory', 'wp-vip-compatibility' ); ?>">
								<code><?php echo esc_html( $theme_slug ); ?></code>
							</td>
							<td class="wvc-col-muted" data-label="<?php esc_attr_e( 'Author', 'wp-vip-compatibility' ); ?>">
								<?php echo esc_html( wp_strip_all_tags( $theme_data->get( 'Author' ) ) ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Current Version', 'wp-vip-compatibility' ); ?>">
								<span class="wvc-version"><?php echo esc_html( $theme_data->get( 'Version' ) ); ?></span>
							</td>
							<td data-label="<?php esc_attr_e( 'Available Version', 'wp-vip-compatibility' ); ?>">
								<span class="wvc-version<?php echo $is_up_to_date ? ' wvc-version--current' : ''; ?>"><?php echo esc_html( $update_version ); ?></span>
							</td>
							<?php echo UI::get_status_cell( 'pending', '', array( 'data-directory-path' => $theme_path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Placeholder for log file information (will be updated via AJAX) -->
		<div id="wvc-log-note-container" data-filename="themes"></div>

		<?php
	}
}
