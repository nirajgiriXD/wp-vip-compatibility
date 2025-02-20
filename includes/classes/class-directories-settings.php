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
 * This class handles the directories submenu settings.
 */
class Directories_Settings {

	use Singleton;

	/**
	 * Stores plugin data from JSON file.
	 * @var array
	 */
	private $json_data = [];

	/**
	 * Constructor method is used to initialize the fields.
	 */
	public function __construct() {

		$this->json_data = Plugin::get_instance()->get_json_data();
	}

	/**
	 * Render the settings page html.
	 *
	 * @return void
	 */
	public function render_settings_page() {

		// Path to the wp-content directory.
		$wp_content_dir = WP_CONTENT_DIR;

		// List of supported directories and files as associative array.
		$directories = $this->json_data['directories'];

		// Scan the wp-content directory.
		$files_and_dirs = scandir($wp_content_dir);

		// Start table
		echo '<table class="wvc-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>SN</th>';
		echo '<th>Files and Folders</th>';
		echo '<th>Description</th>';
		echo '<th>Compatibility</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		// Loop through the files and directories.
		$counter = 1;
		foreach ( $files_and_dirs as $item ) {
			// Check if the current item is in the supported items array.
			if ( isset( $directories[ $item ] ) ) {
				$description = $directories[ $item ]['description'];
				$is_supported = $directories[ $item ]['is_supported'];
				
				$additional_notes = $is_supported ? '<span class="compatible">Compatible</span>' : '<span class="not-compatible">Not Compatible</span>';
			} else {
				$description = 'Not Supported';
				$additional_notes = '<span class="not-compatible">Not Compatible</span>';
			}
		
			// Output row.
			echo '<tr>';
			echo '<td>' . esc_html( $counter++ ) . '</td>';
			echo '<td>' . esc_html( $item ) . '</td>';
			echo '<td>' . esc_html( $description ) . '</td>';
			echo '<td>' . $additional_notes . '</td>';
			echo '</tr>';
		}

		// End table.
		echo '</tbody>';
		echo '</table>';
	}

}