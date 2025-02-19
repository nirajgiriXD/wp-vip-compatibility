<?php
/**
 * Class to handle plugin settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Classes\Overview_Settings;
use WP_VIP_COMPATIBILITY\Includes\Classes\Database_Settings;
use WP_VIP_COMPATIBILITY\Includes\Classes\MU_Plugins_Settings;
use WP_VIP_COMPATIBILITY\Includes\Classes\Plugins_Settings;
use WP_VIP_COMPATIBILITY\Includes\Classes\Themes_Settings;
use WP_VIP_COMPATIBILITY\Includes\Classes\Directories_Settings;

/**
 * Class to handle plugin settings.
 */
class Settings {

	use Singleton;

	/**
	 * Constructor.
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
		Overview_Settings::get_instance();
	}
	
	/**
	 * This function is used to setup hooks.
	 *
	 * @return void
	 */
	private function setup_hooks() {
		// Actions.
		add_action( 'admin_menu', array( $this, 'add_plugin_menus' ) );
	}

	/**
	 * Render the settings page html.
	 *
	 * @param object $settings The settings object.
	 * @param string $id The div container id.
	 * @return void
	 */
	public function render_settings_page( $settings, $id ) {

		printf(
			'<div class="wvc-container wrap"><div id="%s" class="wvc-tab-content">',
			esc_attr( $id )
		);

		$settings->render_settings_page();

		echo '</div></div>';
	}

	/**
	 * Adds the plugin menus.
	 *
	 * @return void
	 */
	public function add_plugin_menus() {

		$overview_settings    = Overview_Settings::get_instance();
		$database_settings    = Database_Settings::get_instance();
		$mu_plugins_settings  = MU_Plugins_Settings::get_instance();
		$plugins_settings     = Plugins_Settings::get_instance();
		$themes_settings      = Themes_Settings::get_instance();
		$directories_settings = Directories_Settings::get_instance();

		// Menu: WVC (WordPress VIP Compatibility).
		add_menu_page(
			__( 'WordPress VIP Compatibility', 'wp-vip-compatibility' ),
			__( 'WVC', 'wp-vip-compatibility' ),
			'manage_options',
			'wp-vip-compatibility',
			function() use ( $overview_settings ) {
				$this->render_settings_page( $overview_settings, 'overview' );
			},
			'dashicons-admin-generic'
		);

		// Submenu: Database.
		add_submenu_page(
			'wp-vip-compatibility',
			__( 'Database - WVC', 'wp-vip-compatibility' ),
			__( 'Database', 'wp-vip-compatibility' ),
			'manage_options',
			'wvc-database',
			function() use ( $database_settings ) {
				$this->render_settings_page( $database_settings, 'database' );
			},
		);

		// Submenu: MU Plugins.
		add_submenu_page(
			'wp-vip-compatibility',
			__( 'MU Plugins - WVC', 'wp-vip-compatibility' ),
			__( 'MU Plugins', 'wp-vip-compatibility' ),
			'manage_options',
			'wvc-mu-plugins',
			function() use ( $mu_plugins_settings ) {
				$this->render_settings_page( $mu_plugins_settings, 'mu-plugins' );
			},
		);

		// Submenu: Plugins.
		add_submenu_page(
			'wp-vip-compatibility',
			__( 'Plugins - WVC', 'wp-vip-compatibility' ),
			__( 'Plugins', 'wp-vip-compatibility' ),
			'manage_options',
			'wvc-plugins',
			function() use ( $plugins_settings ) {
				$this->render_settings_page( $plugins_settings, 'plugins' );
			},
		);

		// Submenu: Themes.
		add_submenu_page(
			'wp-vip-compatibility',
			__( 'Themes - WVC', 'wp-vip-compatibility' ),
			__( 'Themes', 'wp-vip-compatibility' ),
			'manage_options',
			'wvc-themes',
			function() use ( $themes_settings ) {
				$this->render_settings_page( $themes_settings, 'themes' );
			},
		);

		// Submenu: Directories.
		add_submenu_page(
			'wp-vip-compatibility',
			__( 'Directories - WVC', 'wp-vip-compatibility' ),
			__( 'Directories', 'wp-vip-compatibility' ),
			'manage_options',
			'wvc-directories',
			function() use ( $directories_settings ) {
				$this->render_settings_page( $directories_settings, 'directories' );
			},
		);
	}

}