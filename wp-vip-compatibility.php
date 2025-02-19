<?php
/**
 * Plugin Name: WordPress VIP Compatibility
 * Description: Plugin to check and fix WordPress VIP platform compatibility, ensuring migration readiness.
 * Plugin URI:  https://github.com/nirajgiriXD/wp-vip-compatibility
 * Version:     1.0.0
 * Author:      Niraj Giri
 * Author URI:  https://github.com/nirajgiriXD/
 * Text Domain: wp-vip-compatibility
 * Domain Path: /languages/
 *
 * @package wp-vip-compatibility
 */

defined( 'ABSPATH' ) || wp_die();

if ( ! defined( 'WP_VIP_COMPATIBILITY_DIR' ) ) {
    define( 'WP_VIP_COMPATIBILITY_DIR', __DIR__ );
}

if ( ! defined( 'WP_VIP_COMPATIBILITY_URL' ) ) {
    define( 'WP_VIP_COMPATIBILITY_URL', plugin_dir_url( __FILE__ ) );
}

require_once WP_VIP_COMPATIBILITY_DIR . '/includes/helpers/class-autoloader.php';
require_once WP_VIP_COMPATIBILITY_DIR . '/functions.php';

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Classes\Plugin;

/**
* Main Class for WordPress VIP Compatibility
*/
class WP_VIP_COMPATIBILITY {

   use Singleton;

   /**
    * Constructor.
    */
   public function __construct() {
       $this->plugin_loader();
       $this->setup_hooks();
   }

   /**
    * Creates object of class from files loaded by autoloader.
    *
    * @return void
    */
   public function plugin_loader() {
       Plugin::get_instance();
   }


   /**
    * Sets up common hooks.
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
WP_VIP_COMPATIBILITY::get_instance();