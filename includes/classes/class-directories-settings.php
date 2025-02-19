<?php
/**
 * Submenu page for displaying the directories settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * This class handles the directories submenu settings.
 */
class Directories_Settings {

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

		// Path to the wp-content directory
		$wp_content_dir = WP_CONTENT_DIR;

		// List of supported directories and files as associative array
		$supported_items = array(
			'client-mu-plugins' => array(
				'description' => 'Client-specific must-use plugins.',
				'is_supported' => true
			),
			'docs' => array(
				'description' => 'Documentation related to the site.',
				'is_supported' => true
			),
			'images' => array(
				'description' => 'Images and media files.',
				'is_supported' => true
			),
			'languages' => array(
				'description' => 'Language files for translations.',
				'is_supported' => true
			),
			'plugins' => array(
				'description' => 'Installed plugins directory.',
				'is_supported' => true
			),
			'private' => array(
				'description' => 'Private files, restricted access.',
				'is_supported' => true
			),
			'themes' => array(
				'description' => 'Installed themes directory.',
				'is_supported' => true
			),
			'uploads' => array(
				'description' => 'WordPress uploads directory.',
				'is_supported' => false
			),
			'vip-config' => array(
				'description' => 'VIP platform configuration files.',
				'is_supported' => true
			),
			'.' => array(
				'description' => 'Current directory placeholder.',
				'is_supported' => false
			),
			'..' => array(
				'description' => 'Parent directory placeholder.',
				'is_supported' => false
			),
			'.editorconfig' => array(
				'description' => 'Code style configuration file.',
				'is_supported' => true
			),
			'.gitignore' => array(
				'description' => 'Git ignore file, lists files to ignore in version control.',
				'is_supported' => true
			),
			'.phpcs.xml.dist' => array(
				'description' => 'PHP CodeSniffer configuration file.',
				'is_supported' => true
			),
			'README.md' => array(
				'description' => 'Readme file, documentation overview.',
				'is_supported' => true
			),
			'composer.json' => array(
				'description' => 'Composer file for PHP dependencies.',
				'is_supported' => true
			),
			'composer.lock' => array(
				'description' => 'Composer lock file for dependency versions.',
				'is_supported' => true
			),
			'index.php' => array(
				'description' => 'Directory index file to prevent directory listing.',
				'is_supported' => false
			),
		);

		// Scan the wp-content directory
		$files_and_dirs = scandir($wp_content_dir);

		// Start table
		echo '<table class="wvc-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>Files and Folders</th>';
		echo '<th>Description</th>';
		echo '<th>Compatibility</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		// Loop through the files and directories
		foreach ( $files_and_dirs as $item ) {
			// Check if the current item is in the supported items array
			if ( isset( $supported_items[ $item ] ) ) {
				$description = $supported_items[ $item ]['description'];
				$is_supported = $supported_items[ $item ]['is_supported'];
				
				$additional_notes = $is_supported ? '<span style="color:green;">Compatible</span>' : '<span style="color:red;">Not Compatible</span>';
			} else {
				$description = 'Not Supported';
				$additional_notes = '<span style="color:red;">Not Compatible</span>';
			}
		
			// Output row
			echo '<tr>';
			echo '<td>' . esc_html( $item ) . '</td>';
			echo '<td>' . esc_html( $description ) . '</td>';
			echo '<td>' . $additional_notes . '</td>';
			echo '</tr>';
		}

		// End table
		echo '</tbody>';
		echo '</table>';
	}

}