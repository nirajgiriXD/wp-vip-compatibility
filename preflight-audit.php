<?php
/**
 * Plugin Name:       Preflight Audit
 * Description:       Check a site's code against the WordPress VIP platform guidelines and coding standard, with an actionable fix for every issue found.
 * Plugin URI:        https://github.com/nirajgiriXD/preflight-audit
 * Version:           1.0.0
 * Author:            Niraj Giri
 * Author URI:        https://github.com/nirajgiriXD/
 * Text Domain:       preflight-audit
 * Domain Path:       /languages/
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.en.html
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 *
 * @package preflight-audit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Define plugin constants.
 */
if ( ! defined( 'PREFLIGHT_AUDIT_FILE' ) ) {
	define( 'PREFLIGHT_AUDIT_FILE', __FILE__ );
}

if ( ! defined( 'PREFLIGHT_AUDIT_DIR' ) ) {
	define( 'PREFLIGHT_AUDIT_DIR', __DIR__ );
}

if ( ! defined( 'PREFLIGHT_AUDIT_URL' ) ) {
	define( 'PREFLIGHT_AUDIT_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'PREFLIGHT_AUDIT_VERSION' ) ) {
	define( 'PREFLIGHT_AUDIT_VERSION', '1.0.0' );
}

// Require necessary files.
require_once PREFLIGHT_AUDIT_DIR . '/includes/helpers/class-autoloader.php';
require_once PREFLIGHT_AUDIT_DIR . '/functions.php';

use PREFLIGHT_AUDIT\Includes\Traits\Singleton;
use PREFLIGHT_AUDIT\Includes\Classes\Plugin;

/**
 * Main class for Preflight Audit.
 */
class Preflight_Audit {

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
Preflight_Audit::get_instance();
