<?php
/**
 * Class to handle plugin settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * Class to manage all settings pages dynamically.
 */
class Settings {

	use Singleton;

	/**
	 * Holds registered settings classes.
	 *
	 * @var array
	 */
	private $settings_classes = [];

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Sets up necessary WordPress hooks.
	 *
	 * @return void
	 */
	private function setup_hooks() {
		add_action( 'admin_menu', [ $this, 'add_plugin_menus' ] );
	}

	/**
	 * Registers and loads the settings classes on demand.
	 *
	 * @param string $key The settings key.
	 * @return object The settings class instance.
	 */
	private function get_settings_class( $key ) {
		if ( ! isset( $this->settings_classes[ $key ] ) ) {
			$class_name = __NAMESPACE__ . '\\' . ucfirst( str_replace( '-', '_', $key ) ) . '_Settings';

			if ( class_exists( $class_name ) ) {
				$this->settings_classes[ $key ] = $class_name::get_instance();
			}
		}
		return $this->settings_classes[ $key ] ?? null;
	}

	/**
	 * Renders the settings page HTML.
	 *
	 * @param string $key The settings key.
	 * @return void
	 */
	public function render_settings_page( $key ) {
		$settings = $this->get_settings_class( $key );
		if ( $settings ) {
			echo '<div class="wrap"><div class="wvc-container" id="' . esc_attr( $key ) . '">';
			$settings->render_settings_page();
			echo '</div></div>';
		}
	}

	/**
	 * Adds the plugin menus in the WordPress admin panel.
	 *
	 * @return void
	 */
	public function add_plugin_menus() {
		// Define menu structure.
		$menus = [
			'database'    => __( 'Database', 'wp-vip-compatibility' ),
			'directories' => __( 'Directories', 'wp-vip-compatibility' ),
			'mu-plugins'  => __( 'MU Plugins', 'wp-vip-compatibility' ),
			'plugins'     => __( 'Plugins', 'wp-vip-compatibility' ),
			'themes'      => __( 'Themes', 'wp-vip-compatibility' ),
		];

		// Add main menu.
		add_menu_page(
			__( 'WVC - Overview', 'wp-vip-compatibility' ),
			__( 'WVC', 'wp-vip-compatibility' ),
			'manage_options',
			'wp-vip-compatibility',
			fn() => $this->render_settings_page( 'overview' ),
			'dashicons-admin-generic'
		);

		// Add submenus.
		foreach ( $menus as $key => $title ) {
			add_submenu_page(
				'wp-vip-compatibility',
				sprintf( __( 'WVC - %s', 'wp-vip-compatibility' ), $title ),
				$title,
				'manage_options',
				'wvc-' . $key,
				fn() => $this->render_settings_page( $key )
			);
		}
	}
}
