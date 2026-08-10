<?php
/**
 * Submenu page for displaying the directories settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Classes\Plugin;

/**
 * Handles the directories submenu settings.
 */
class Directories_Settings {

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
	 * Renders the directories settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		// Path to the wp-content directory.
		$wp_content_dir = WP_CONTENT_DIR;

		// List of supported directories and files as an associative array.
		$supported_items = $this->json_data['directories'] ?? [];

		// Scan the wp-content directory, excluding "." and ".."
		$items = array_diff( scandir( $wp_content_dir ), [ '.', '..' ] );

		if ( empty( $items ) ) {
			UI::render_empty_state(
				__( 'No files or directories found in wp-content.', 'wp-vip-compatibility' ),
				__( 'There is nothing to check on this screen yet.', 'wp-vip-compatibility' )
			);
			return;
		}

		// Render the filter tabs.
		$this->render_filter_tabs();

		// Output settings table.
		echo '<div class="wvc-table-wrap">';
		echo '<table class="wvc-table" data-target-entity="directories">';

		UI::render_table_head(
			array(
				array(
					'label' => __( 'SN', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-sn',
				),
				array(
					'label'    => __( 'Files and Folders', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Description', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array(
					'label'    => __( 'WP VIP Compatibility', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-status',
					'sortable' => true,
				),
			)
		);

		echo '<tbody>';

		foreach ( $items as $item ) {
			$is_compatible = false;
			$description   = __( 'Not Supported', 'wp-vip-compatibility' );

			// Check if the item is listed in supported items.
			if ( array_key_exists( $item, $supported_items ) ) {
				$description   = $supported_items[ $item ]['description'] ?? '';
				$is_compatible = ! empty( $supported_items[ $item ]['is_supported'] );
			}

			// Output table row.
			echo '<tr>';
			echo '<td class="wvc-col-sn"></td>';
			echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Files and Folders', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $item ) . '</code></td>';
			echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Description', 'wp-vip-compatibility' ) . '">' . esc_html( $description ) . '</td>';
			echo UI::get_status_cell( $is_compatible ? 'compatible' : 'not-compatible' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html__( 'Anything the VIP platform does not recognise inside wp-content has to be removed or relocated before migration. Uploads are served from VIP File System, so the local uploads folder is not carried over.', 'wp-vip-compatibility' ),
			'info',
			esc_html__( 'About this list', 'wp-vip-compatibility' )
		);
	}

	/**
	 * Renders the tabs for filtering the compatible and incompatible directories.
	 *
	 * @return void
	 */
	private function render_filter_tabs() {
		UI::render_toolbar(
			array(
				'search_label' => __( 'Search files and folders', 'wp-vip-compatibility' ),
			)
		);
	}
}
