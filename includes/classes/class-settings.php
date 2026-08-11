<?php
/**
 * Class to handle plugin settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

defined( 'ABSPATH' ) || exit;

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
	private $settings_classes = array();

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
		add_action( 'admin_menu', array( $this, 'add_plugin_menus' ) );
		add_action( 'admin_init', array( $this, 'redirect_retired_screens' ) );
	}

	/**
	 * Sends the retired per-entity screens to their replacement.
	 *
	 * The plugins, themes, must-use, database and directories screens were merged
	 * into Inventory and Site. Anyone holding a bookmark or a link in a migration
	 * ticket lands on the merged screen with the relevant filter already applied,
	 * rather than on a "page not found".
	 *
	 * @return void
	 */
	public function redirect_retired_screens() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection on a GET request.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$retired = array(
			'wvc-plugins'     => array( 'inventory', array( 'kind' => 'plugin' ) ),
			'wvc-themes'      => array( 'inventory', array( 'kind' => 'theme' ) ),
			'wvc-mu-plugins'  => array( 'inventory', array( 'kind' => 'mu-plugin' ) ),
			'wvc-database'    => array( 'site', array( 'section' => 'database' ) ),
			'wvc-directories' => array( 'site', array( 'section' => 'directories' ) ),
		);

		if ( ! isset( $retired[ $page ] ) ) {
			return;
		}

		wp_safe_redirect( UI::get_screen_url( $retired[ $page ][0], $retired[ $page ][1] ), 301 );
		exit;
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

		if ( ! $settings ) {
			return;
		}

		echo '<div class="wrap wvc-wrap">';

		// Shared chrome: brand masthead, cross-screen navigation and page heading.
		UI::render_masthead( $key );
		UI::render_page_head( $key );

		echo '<div class="wvc-container" id="' . esc_attr( $key ) . '">';
		$settings->render_settings_page();
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Adds the plugin menus in the WordPress admin panel.
	 *
	 * @return void
	 */
	public function add_plugin_menus() {
		$screens = UI::get_screens();

		// Add main menu. The overview is also the first submenu entry, so the
		// menu never shows a duplicate of the parent under a different name.
		add_menu_page(
			__( 'WVC - Overview', 'wp-vip-compatibility' ),
			__( 'WVC', 'wp-vip-compatibility' ),
			'manage_options',
			$screens['overview']['slug'],
			fn() => $this->render_settings_page( 'overview' ),
			'dashicons-feedback'
		);

		foreach ( $screens as $key => $screen ) {
			$is_parent = ( $screen['slug'] === $screens['overview']['slug'] );

			add_submenu_page(
				$screens['overview']['slug'],
				/* translators: %s: Submenu title */
				sprintf( __( 'WVC - %s', 'wp-vip-compatibility' ), $screen['title'] ),
				$screen['label'],
				'manage_options',
				$screen['slug'],
				/*
				 * The overview is listed as its own first submenu entry so the
				 * menu does not show "WVC" twice under itself. It must be
				 * registered without a callback: add_menu_page() already hooked
				 * one, and because the slugs match, add_submenu_page() resolves
				 * to the same `toplevel_page_*` hook — passing a callback here
				 * hooks it a second time and renders the whole screen twice.
				 */
				$is_parent ? null : fn() => $this->render_settings_page( $key )
			);
		}
	}
}
