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

		$mu_plugins = get_mu_plugins();
		$mu_plugin_folders = $this->get_mu_plugin_folders();
		$is_mu_plugins_empty = empty( $mu_plugins ) && empty( $mu_plugin_folders );

		// Render the filter tabs if there are MU plugins.
		if ( ! $is_mu_plugins_empty ) {
			$this->render_filter_tabs();
		}

		// Render the table.
		echo '<table class="wvc-table" data-target-entity="mu-plugins">';
		$this->render_table_header();
		echo '<tbody>';

		if ( $is_mu_plugins_empty ) {
			echo '<tr><td colspan="7" style="text-align: center;">' . esc_html__( 'No MU plugins are present.', 'wp-vip-compatibility' ) . '</td></tr>';
		} else {
			$counter = 1;
			foreach ( $mu_plugins as $plugin_file => $plugin_data ) {
				$this->render_plugin_row( $counter++, $plugin_file, $plugin_data );
			}
			foreach ( $mu_plugin_folders as $plugin_folder ) {
				$this->render_folder_row( $counter++, $plugin_folder );
			}
		}

		echo '</tbody></table>';

		?>
		<div>
			<p>
				<?php
				esc_html_e(
					'The compatibility status of MU plugins is checked differently from regular plugins or themes because of how MU plugins are loaded. The main PHP file of the MU plugin and the contents of its folder are shown in separate rows since there is no standard way to link the main MU plugin file to its folder when the folder exists.', 'wp-vip-compatibility'
				);
				?>
			</p>
		</div>
		<?php

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
		$mu_plugins_path = WPMU_PLUGIN_DIR;

		if ( is_dir( $mu_plugins_path ) ) {
			foreach ( scandir( $mu_plugins_path ) as $folder ) {
				$folder_path = $mu_plugins_path . '/' . $folder;
				if ( $folder !== '.' && $folder !== '..' && is_dir( $folder_path ) ) {
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
		?>
		<div id="wvc-filter-tabs">
			<button data-filter="all" class="active">
				<?php esc_html_e( 'All', 'wp-vip-compatibility' ); ?>
			</button>
			<button data-filter="compatible">
				<?php esc_html_e( 'Compatible', 'wp-vip-compatibility' ); ?>
			</button>
			<button data-filter="incompatible">
				<?php esc_html_e( 'Incompatible', 'wp-vip-compatibility' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Renders the table header.
	 *
	 * @return void
	 */
	private function render_table_header() {
		$headers = [
			'SN', 'MU Plugin Name', 'MU Plugin Directory', 'Author', 'Version', 'WP VIP Compatibility', 'Note'
		];

		echo '<thead><tr>';
		foreach ( $headers as $header ) {
			echo '<th>' . esc_html__( $header, 'wp-vip-compatibility' ) . '</th>';
		}
		echo '</tr></thead>';
	}

	/**
	 * Renders a single plugin row.
	 *
	 * @param int    $counter     Plugin serial number.
	 * @param string $plugin_file Plugin file path.
	 * @param array  $plugin_data Plugin metadata.
	 *
	 * @return void
	 */
	private function render_plugin_row( $counter, $plugin_file, $plugin_data ) {
		$plugin_slug = dirname( $plugin_file );
		$plugin_path = WPMU_PLUGIN_DIR . '/' . $plugin_file;

		// Get MU Plugin details from JSON data.
		$mu_plugin_info = isset( $this->json_data['known_mu_plugins'][ $plugin_slug ] )
			? $this->json_data['known_mu_plugins'][ $plugin_slug ]
			: null;

		// Determine compatibility and note.
		$note = $this->get_plugin_note( $mu_plugin_info );

		echo '<tr>';
		echo '<td>' . esc_html( $counter ) . '</td>';
		echo '<td>' . esc_html( $plugin_data['Name'] ) . '</td>';
		echo '<td>' . esc_html( $plugin_file ) . '</td>';
		echo '<td>' . esc_html( $plugin_data['Author'] ) . '</td>';
		echo '<td>' . ( ! empty( $plugin_data['Version'] ) ? esc_html( $plugin_data['Version'] ) : '-' ) . '</td>';
		if ( $mu_plugin_info && $mu_plugin_info['compatible'] ) {
			echo '<td class="compatible">' . esc_html__( 'Compatible', 'wp-vip-compatibility' ) . '</td>';
		} else if ( $mu_plugin_info && ! $mu_plugin_info['compatible'] ) {
			echo '<td class="not-compatible">' . esc_html__( 'Incompatible', 'wp-vip-compatibility' ) . '</td>';
		} else {
			echo '<td class="vip-compatibility-status" data-directory-path="' . esc_attr( $plugin_path ) . '">' . esc_html__( 'Loading...', 'wp-vip-compatibility' ) . '</td>';
		}
		echo '<td>' . wp_kses_post( $note ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Renders a folder row.
	 *
	 * @param int    $counter  Plugin serial number.
	 * @param string $folder   Plugin folder name.
	 *
	 * @return void
	 */
	private function render_folder_row( $counter, $folder ) {
		$plugin_path = WPMU_PLUGIN_DIR . '/' . $folder;

		// Get MU Plugin details from JSON data.
		$mu_plugin_info = isset( $this->json_data['known_mu_plugins'][ $folder ] )
			? $this->json_data['known_mu_plugins'][ $folder ]
			: null;

		// Determine compatibility and note.
		$note = $this->get_plugin_note( $mu_plugin_info );

		echo '<tr>';
		echo '<td>' . esc_html( $counter ) . '</td>';
		echo '<td>' . esc_html( $folder ) . '</td>';
		echo '<td>' . esc_html( $folder ) . '</td>';
		echo '<td>-</td>';
		echo '<td>-</td>';
		if ( $mu_plugin_info && $mu_plugin_info['compatible'] ) {
			echo '<td class="compatible">' . esc_html__( 'Compatible', 'wp-vip-compatibility' ) . '</td>';
		} else if ( $mu_plugin_info && ! $mu_plugin_info['compatible'] ) {
			echo '<td class="not-compatible">' . esc_html__( 'Incompatible', 'wp-vip-compatibility' ) . '</td>';
		} else {
			echo '<td class="vip-compatibility-status" data-directory-path="' . esc_attr( $plugin_path ) . '">' . esc_html__( 'Loading...', 'wp-vip-compatibility' ) . '</td>';
		}
		echo '<td>' . wp_kses_post( $note ) . '</td>';
		echo '</tr>';
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
			return '-';
		}

		$is_compatible = $mu_plugin_info['compatible'] ?? false;
		$source        = $mu_plugin_info['source'] ?? '';
		$note          = '-';

		if ( 'automattic' === $source ) {
			$note = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://docs.wpvip.com/vip-go-mu-plugins/' ),
				esc_html__( 'Plugin will be preinstalled in VIP platform', 'wp-vip-compatibility' )
			);
		} elseif ( 'wp-engine' === $source ) {
			$note = esc_html__( 'WP Engine plugins are not required on VIP platform.', 'wp-vip-compatibility' );
		} else {
			$note = $is_compatible 
				? esc_html__( 'Tested and verified VIP-compatible', 'wp-vip-compatibility' )
				: esc_html__( 'Tested and verified VIP-incompatible', 'wp-vip-compatibility' );
		}

		return $note;
	}
}
