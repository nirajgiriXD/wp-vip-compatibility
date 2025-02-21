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
		$items = array_diff(scandir($wp_content_dir), ['.', '..']);

		if (empty($items)) {
			echo '<p>' . esc_html__('No files or directories found in wp-content.', 'wp-vip-compatibility') . '</p>';
			return;
		}

		// Output settings table.
		echo '<table class="wvc-table">';
		echo '<thead><tr>
				<th>' . esc_html__('SN', 'wp-vip-compatibility') . '</th>
				<th>' . esc_html__('Files and Folders', 'wp-vip-compatibility') . '</th>
				<th>' . esc_html__('Description', 'wp-vip-compatibility') . '</th>
				<th>' . esc_html__('WP VIP Compatibility', 'wp-vip-compatibility') . '</th>
			</tr></thead>';
		echo '<tbody>';

		$counter = 1;
		foreach ($items as $item) {
			$description   = esc_html__('Not Supported', 'wp-vip-compatibility');
			$compatibility = '<span class="not-compatible">' . esc_html__('Not Compatible', 'wp-vip-compatibility') . '</span>';

			// Check if the item is listed in supported items.
			if (array_key_exists($item, $supported_items)) {
				$description   = esc_html($supported_items[$item]['description'] ?? '');
				$is_supported  = !empty($supported_items[$item]['is_supported']);

				$compatibility = $is_supported 
					? '<span class="compatible">' . esc_html__('Compatible', 'wp-vip-compatibility') . '</span>' 
					: '<span class="not-compatible">' . esc_html__('Not Compatible', 'wp-vip-compatibility') . '</span>';
			}

			// Output table row.
			echo '<tr>';
			echo '<td>' . esc_html($counter++) . '</td>';
			echo '<td>' . esc_html($item) . '</td>';
			echo '<td>' . esc_html($description) . '</td>';
			echo '<td>' . $compatibility . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
