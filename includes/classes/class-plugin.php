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
	 * Constructor method is used to initialize the fields.
	 */
	public function __construct() {
		$this->load_classes();
		$this->setup_hooks();
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
		// Actions.
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