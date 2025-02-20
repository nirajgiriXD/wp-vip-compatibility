<?php
/**
 * Plugin manifest class.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Classes\Assets;
use WP_VIP_COMPATIBILITY\Includes\Classes\Ajax;
use WP_VIP_COMPATIBILITY\Includes\Classes\Settings;

/**
 * This class will instantiate all the other class objects.
 */
class Plugin {

	use Singleton;

	/**
	 * Stores plugin data from JSON file.
	 * @var array
	 */
	private $json_data = array();

	/**
	 * Constructor method is used to initialize the fields.
	 */
	public function __construct() {

		$this->load_classes();
		$this->setup_hooks();
		$this->load_json_data();
	}

	/**
	 * Load plugin data from JSON file.
	 *
	 * @return void
	 */
	private function load_json_data() {

		// Path to the JSON file containing table source data.
		$json_path = WP_VIP_COMPATIBILITY_DIR . '/data/data.json';

		if ( file_exists( $json_path ) ) {
			// Read the JSON file contents.
			$json_content = file_get_contents( $json_path );

			// Decode the JSON data into an associative array.
			$this->json_data = json_decode( $json_content, true ) ?: [];
		}

		// Check if decoding was successful.
		if ( null === $this->json_data && json_last_error() !== JSON_ERROR_NONE) {
			$this->json_data = array();
		}
	}

	/**
	 * Get the plugin data from JSON file.
	 *
	 * @return array
	 */
	public function get_json_data() {
		return $this->json_data;
	}
	
	/**
	 * This method is responsible for loading and instantiating all the required classes.
	 *
	 * @return void
	 */
	private function load_classes() {
	
		Assets::get_instance();
		Ajax::get_instance();
		Settings::get_instance();
	}

	/**
	 * Setup hooks.
	 *
	 * @return void
	 */
	public function setup_hooks() {

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads the plugin text domain for translation.
	 *
	 * @return void
	 */
	public function load_textdomain() {

		load_plugin_textdomain( 'wp-vip-compatibility', false, WP_VIP_COMPATIBILITY_DIR . '/languages' );
	}
}
