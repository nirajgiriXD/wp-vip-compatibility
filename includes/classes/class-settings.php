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
	 * Loads and instantiates all required classes.
	 *
	 * @return void
	 */
	private function load_classes() {

		Overview_Settings::get_instance();
	}

	/**
	 * Sets up necessary WordPress hooks.
	 *
	 * @return void
	 */
	private function setup_hooks() {

		add_action( 'admin_menu', array( $this, 'add_plugin_menus' ) );
	}

	/**
	 * Renders the settings page HTML.
	 *
	 * @param object $settings The settings object.
	 * @param string $id       The div container ID.
	 *
	 * @return void
	 */
	public function render_settings_page( $settings, $id ) {

		printf(
			'<div class="wrap"><div class="wvc-container" id="%s">',
			esc_attr( $id )
		);

		$settings->render_settings_page();

		echo '</div></div>';
	}

	/**
	 * Adds the plugin menus in the WordPress admin panel.
	 *
	 * @return void
	 */
	public function add_plugin_menus() {

		$settings_classes = array(
			'overview'    => Overview_Settings::get_instance(),
			'database'    => Database_Settings::get_instance(),
			'mu-plugins'  => MU_Plugins_Settings::get_instance(),
			'plugins'     => Plugins_Settings::get_instance(),
			'themes'      => Themes_Settings::get_instance(),
			'directories' => Directories_Settings::get_instance(),
		);

		// Add main menu.
		add_menu_page(
			__( 'WordPress VIP Compatibility', 'wp-vip-compatibility' ),
			__( 'WVC', 'wp-vip-compatibility' ),
			'manage_options',
			'wp-vip-compatibility',
			function() use ( $settings_classes ) {
				$this->render_settings_page( $settings_classes['overview'], 'overview' );
			},
			'dashicons-admin-generic'
		);

		// Define submenus.
		$submenus = array(
			'database'    => __( 'Database', 'wp-vip-compatibility' ),
			'mu-plugins'  => __( 'MU Plugins', 'wp-vip-compatibility' ),
			'plugins'     => __( 'Plugins', 'wp-vip-compatibility' ),
			'themes'      => __( 'Themes', 'wp-vip-compatibility' ),
			'directories' => __( 'Directories', 'wp-vip-compatibility' ),
		);

		// Add submenus dynamically.
		foreach ( $submenus as $key => $title ) {
			add_submenu_page(
				'wp-vip-compatibility',
				sprintf( __( '%s - WVC', 'wp-vip-compatibility' ), $title ),
				$title,
				'manage_options',
				'wvc-' . $key,
				function() use ( $settings_classes, $key ) {
					$this->render_settings_page( $settings_classes[ $key ], $key );
				}
			);
		}
	}
}
