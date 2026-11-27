<?php
/**
 * Plugin Name:       Cutover Audit
 * Description:       Check a site's code against the WordPress VIP platform guidelines and coding standard, with an actionable fix for every issue found.
 * Plugin URI:        https://github.com/nirajgiriXD/cutover-audit
 * Version:           1.0.0
 * Author:            Niraj Giri
 * Author URI:        https://github.com/nirajgiriXD/
 * Text Domain:       cutover-audit
 * Domain Path:       /languages/
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.en.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package cutover-audit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Define plugin constants.
 */
if ( ! defined( 'CUTOVER_AUDIT_FILE' ) ) {
	define( 'CUTOVER_AUDIT_FILE', __FILE__ );
}

if ( ! defined( 'CUTOVER_AUDIT_DIR' ) ) {
	define( 'CUTOVER_AUDIT_DIR', __DIR__ );
}

if ( ! defined( 'CUTOVER_AUDIT_URL' ) ) {
	define( 'CUTOVER_AUDIT_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'CUTOVER_AUDIT_VERSION' ) ) {
	define( 'CUTOVER_AUDIT_VERSION', '1.0.0' );
}

// Require necessary files.
require_once CUTOVER_AUDIT_DIR . '/includes/helpers/class-autoloader.php';
require_once CUTOVER_AUDIT_DIR . '/functions.php';

use CUTOVER_AUDIT\Includes\Traits\Singleton;
use CUTOVER_AUDIT\Includes\Classes\Plugin;

/**
 * Main class for Cutover Audit.
 */
class Cutover_Audit {

	use Singleton;

	/**
	 * Constructor.
	 *
	 * There are no activation or deactivation hooks: nothing has to be set up
	 * before the first scan, and results are deliberately kept on deactivation
	 * so re-activating does not force a full rescan. uninstall.php removes them.
	 */
	public function __construct() {

		$this->plugin_loader();
	}

	/**
	 * Creates an instance of the plugin class.
	 *
	 * @return void
	 */
	public function plugin_loader() {

		Plugin::get_instance();
	}
}

// Initialize the main plugin class.
Cutover_Audit::get_instance();
