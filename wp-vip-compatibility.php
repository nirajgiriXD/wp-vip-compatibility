<?php
/**
 * Plugin Name:       WordPress VIP Compatibility
 * Description:       Plugin to check and fix WordPress VIP platform compatibility, ensuring migration readiness.
 * Plugin URI:        https://github.com/nirajgiriXD/wp-vip-compatibility
 * Version:           1.0.0
 * Author:            Niraj Giri
 * Author URI:        https://github.com/nirajgiriXD/
 * Text Domain:       wp-vip-compatibility
 * Domain Path:       /languages/
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.en.html
 * Requires at least: 6.0
 * Tested up to:      6.7.0
 * Requires PHP:      7.0
 *
 * @package wp-vip-compatibility
 */

defined( 'ABSPATH' ) || exit;

/**
 * Define plugin constants.
 */
if ( ! defined( 'WP_VIP_COMPATIBILITY_DIR' ) ) {
	define( 'WP_VIP_COMPATIBILITY_DIR', __DIR__ );
}

if ( ! defined( 'WP_VIP_COMPATIBILITY_URL' ) ) {
	define( 'WP_VIP_COMPATIBILITY_URL', plugin_dir_url( __FILE__ ) );
}

// Require necessary files.
require_once WP_VIP_COMPATIBILITY_DIR . '/includes/helpers/class-autoloader.php';
require_once WP_VIP_COMPATIBILITY_DIR . '/functions.php';

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Classes\Plugin;

/**
 * Main class for WordPress VIP Compatibility.
 */
class WP_VIP_Compatibility {

	use Singleton;

	/**
	 * Constructor.
	 */
	public function __construct() {

		$this->plugin_loader();
		$this->setup_hooks();
	}

	/**
	 * Creates an instance of the plugin class.
	 *
	 * @return void
	 */
	public function plugin_loader() {

		Plugin::get_instance();
	}

	/**
	 * Sets up hooks for activation and deactivation.
	 *
	 * @return void
	 */
	public function setup_hooks() {

		register_activation_hook( __FILE__, array( $this, 'handle_plugin_activation' ) );
		register_deactivation_hook( __FILE__, array( $this, 'handle_plugin_deactivation' ) );
	}

	/**
	 * Handles plugin activation.
	 *
	 * @return void
	 */
	public function handle_plugin_activation() {
		// Actions to perform on plugin activation.
	}

	/**
	 * Handles plugin deactivation.
	 *
	 * @return void
	 */
	public function handle_plugin_deactivation() {
		// Actions to perform on plugin deactivation.
	}
}

// Initialize the main plugin class.
WP_VIP_Compatibility::get_instance();
