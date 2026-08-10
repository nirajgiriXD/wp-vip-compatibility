<?php
/**
 * Submenu page for displaying the MU-Plugins settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * Handles the MU-Plugins submenu settings.
 */
class MU_Plugins_Settings {

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
	 * Renders the MU-Plugins settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$mu_plugins          = get_mu_plugins();
		$mu_plugin_folders   = $this->get_mu_plugin_folders();
		$is_mu_plugins_empty = empty( $mu_plugins ) && empty( $mu_plugin_folders );

		// Render the filter tabs if there are MU plugins.
		if ( ! $is_mu_plugins_empty ) {
			$this->render_filter_tabs();
		}

		// Render the table.
		echo '<div class="wvc-table-wrap">';
		echo '<table class="wvc-table" data-target-entity="mu-plugins">';
		$this->render_table_header();
		echo '<tbody>';

		if ( $is_mu_plugins_empty ) {
			echo '<tr class="wvc-table__empty" data-empty="1"><td colspan="7">' . esc_html__( 'No MU plugins are present.', 'wp-vip-compatibility' ) . '</td></tr>';
		} else {
			foreach ( $mu_plugins as $plugin_file => $plugin_data ) {
				$this->render_plugin_row( $plugin_file, $plugin_data );
			}
			foreach ( $mu_plugin_folders as $plugin_folder ) {
				$this->render_folder_row( $plugin_folder );
			}
		}

		echo '</tbody></table>';
		echo '</div>';

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html__(
				'The compatibility status of MU plugins is checked differently from regular plugins or themes because of how MU plugins are loaded. The main PHP file of the MU plugin and the contents of its folder are shown in separate rows since there is no standard way to link the main MU plugin file to its folder when the folder exists.',
				'wp-vip-compatibility'
			),
			'info',
			esc_html__( 'How MU plugins are checked', 'wp-vip-compatibility' )
		);

		// Placeholder for log file information (will be updated via AJAX).
		echo '<div id="wvc-log-note-container" data-filename="mu-plugins"></div>';
	}

	/**
	 * Retrieves directories inside mu-plugins that contain at least one PHP file.
	 *
	 * @return array List of valid MU plugin folders.
	 */
	private function get_mu_plugin_folders() {
		$mu_plugin_folders = [];
		$mu_plugins_path   = WPMU_PLUGIN_DIR;

		if ( is_dir( $mu_plugins_path ) ) {
			foreach ( scandir( $mu_plugins_path ) as $folder ) {
				$folder_path = $mu_plugins_path . '/' . $folder;
				if ( '.' !== $folder && '..' !== $folder && is_dir( $folder_path ) ) {
					// Check if at least one PHP file exists inside the folder.
					$php_files = glob( $folder_path . '/*.php' );
					if ( ! empty( $php_files ) ) {
						$mu_plugin_folders[] = $folder;
					}
				}
			}
		}

		return $mu_plugin_folders;
	}

	/**
	 * Renders the tabs for filtering the compatible and incompatible mu-plugins.
	 *
	 * @return void
	 */
	private function render_filter_tabs() {
		UI::render_toolbar(
			array(
				'search_label'  => __( 'Search must-use plugins', 'wp-vip-compatibility' ),
				'show_progress' => true,
			)
		);
	}

	/**
	 * Renders the table header.
	 *
	 * @return void
	 */
	private function render_table_header() {
		UI::render_table_head(
			array(
				array(
					'label' => __( 'SN', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-sn',
				),
				array(
					'label'    => __( 'MU Plugin Name', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'MU Plugin Directory', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-path',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Author', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array( 'label' => __( 'Version', 'wp-vip-compatibility' ) ),
				array(
					'label'    => __( 'WP VIP Compatibility', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-status',
					'sortable' => true,
				),
				array(
					'label' => __( 'Note', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-notes',
				),
			)
		);
	}

	/**
	 * Renders a single plugin row.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param array  $plugin_data Plugin metadata.
	 *
	 * @return void
	 */
	private function render_plugin_row( $plugin_file, $plugin_data ) {
		$plugin_slug = dirname( $plugin_file );
		$plugin_path = WPMU_PLUGIN_DIR . '/' . $plugin_file;

		// Get MU Plugin details from JSON data.
		$mu_plugin_info = isset( $this->json_data['known_mu_plugins'][ $plugin_slug ] )
			? $this->json_data['known_mu_plugins'][ $plugin_slug ]
			: null;

		// Determine compatibility and note.
		$note = $this->get_plugin_note( $mu_plugin_info );

		echo '<tr>';
		echo '<td class="wvc-col-sn"></td>';
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'MU Plugin Name', 'wp-vip-compatibility' ) . '">' . esc_html( $plugin_data['Name'] ) . '</td>';
		echo '<td class="wvc-col-path" data-label="' . esc_attr__( 'MU Plugin Directory', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $plugin_file ) . '</code></td>';
		echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Author', 'wp-vip-compatibility' ) . '">' . esc_html( wp_strip_all_tags( $plugin_data['Author'] ) ) . '</td>';
		echo '<td data-label="' . esc_attr__( 'Version', 'wp-vip-compatibility' ) . '">' . ( ! empty( $plugin_data['Version'] ) ? '<span class="wvc-version">' . esc_html( $plugin_data['Version'] ) . '</span>' : '<span class="wvc-dash" aria-hidden="true">—</span>' ) . '</td>';

		echo $this->get_compatibility_cell( $mu_plugin_info, $plugin_path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'Note', 'wp-vip-compatibility' ) . '">' . wp_kses_post( $note ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Renders a folder row.
	 *
	 * @param string $folder Plugin folder name.
	 *
	 * @return void
	 */
	private function render_folder_row( $folder ) {
		$plugin_path = WPMU_PLUGIN_DIR . '/' . $folder;

		// Get MU Plugin details from JSON data.
		$mu_plugin_info = isset( $this->json_data['known_mu_plugins'][ $folder ] )
			? $this->json_data['known_mu_plugins'][ $folder ]
			: null;

		// Determine compatibility and note.
		$note = $this->get_plugin_note( $mu_plugin_info );
		$dash = '<span class="wvc-dash" aria-hidden="true">—</span>';

		echo '<tr>';
		echo '<td class="wvc-col-sn"></td>';
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'MU Plugin Name', 'wp-vip-compatibility' ) . '">' . esc_html( $folder ) . '</td>';
		echo '<td class="wvc-col-path" data-label="' . esc_attr__( 'MU Plugin Directory', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $folder ) . '</code></td>';
		echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Author', 'wp-vip-compatibility' ) . '">' . $dash . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
		echo '<td data-label="' . esc_attr__( 'Version', 'wp-vip-compatibility' ) . '">' . $dash . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.

		echo $this->get_compatibility_cell( $mu_plugin_info, $plugin_path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'Note', 'wp-vip-compatibility' ) . '">' . wp_kses_post( $note ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Builds the compatibility cell for an MU plugin entry.
	 *
	 * Known entries resolve immediately; everything else is checked over AJAX.
	 *
	 * @param array|null $mu_plugin_info MU plugin details from known_mu_plugins.
	 * @param string     $plugin_path    Absolute path used for the async check.
	 *
	 * @return string The cell markup.
	 */
	private function get_compatibility_cell( $mu_plugin_info, $plugin_path ) {
		if ( $mu_plugin_info && $mu_plugin_info['compatible'] ) {
			return UI::get_status_cell( 'compatible', __( 'Compatible', 'wp-vip-compatibility' ) );
		}

		if ( $mu_plugin_info && ! $mu_plugin_info['compatible'] ) {
			return UI::get_status_cell( 'not-compatible', __( 'Incompatible', 'wp-vip-compatibility' ) );
		}

		return UI::get_status_cell( 'pending', '', array( 'data-directory-path' => $plugin_path ) );
	}

	/**
	 * Returns the note for the given MU plugin.
	 *
	 * @param array|null $mu_plugin_info MU plugin details from known_mu_plugins.
	 *
	 * @return string Note message.
	 */
	private function get_plugin_note( $mu_plugin_info ) {
		if ( ! $mu_plugin_info ) {
			return '<span class="wvc-dash" aria-hidden="true">—</span>';
		}

		$is_compatible = $mu_plugin_info['compatible'] ?? false;
		$source        = $mu_plugin_info['source'] ?? '';

		if ( 'automattic' === $source ) {
			return sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://docs.wpvip.com/vip-go-mu-plugins/' ),
				esc_html__( 'Plugin will be preinstalled in VIP platform', 'wp-vip-compatibility' )
			);
		}

		if ( 'wp-engine' === $source ) {
			return esc_html__( 'WP Engine plugins are not required on VIP platform.', 'wp-vip-compatibility' );
		}

		return $is_compatible
			? esc_html__( 'Tested and verified VIP-compatible', 'wp-vip-compatibility' )
			: esc_html__( 'Tested and verified VIP-incompatible', 'wp-vip-compatibility' );
	}
}
