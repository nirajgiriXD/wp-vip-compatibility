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
 * Registers the admin menu and routes each screen through the shared shell.
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
	 * Sends the merged screens back to the section they were split into.
	 *
	 * Inventory and Site briefly folded five subjects into two screens. Anyone
	 * holding a bookmark, or a link in a migration ticket, lands on the screen
	 * that now owns the subject rather than on "page not found".
	 *
	 * @return void
	 */
	public function redirect_retired_screens() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection on a GET request.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( ! in_array( $page, array( 'wvc-inventory', 'wvc-site' ), true ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only translation of a bookmarked filter.
		$kind    = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$targets = array(
			'plugin'      => 'plugins',
			'theme'       => 'themes',
			'mu-plugin'   => 'mu-plugins',
			'directories' => 'directories',
			'database'    => 'database',
		);

		if ( 'wvc-inventory' === $page ) {
			$key = $targets[ $kind ] ?? 'plugins';
		} else {
			$key = $targets[ $section ] ?? 'database';
		}

		$args = ( '' === $status ) ? array() : array( 'status' => $status );

		wp_safe_redirect( UI::get_screen_url( $key, $args ), 301 );
		exit;
	}

	/**
	 * Registers and loads the settings classes on demand.
	 *
	 * @param string $key The settings key.
	 * @return object|null The settings class instance, or null when unknown.
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
	 * Renders one screen inside the shared application shell.
	 *
	 * @param string $key The settings key.
	 * @return void
	 */
	public function render_settings_page( $key ) {
		$settings = $this->get_settings_class( $key );

		if ( ! $settings ) {
			return;
		}

		UI::render_shell_open();
		UI::render_page_head( $key );

		echo '<div class="wvc-container" id="' . esc_attr( $key ) . '">';
		UI::render_scan_notice();
		$settings->render_settings_page();
		echo '</div>';

		UI::render_shell_close();
	}

	/**
	 * Adds the plugin menus in the WordPress admin panel.
	 *
	 * @return void
	 */
	public function add_plugin_menus() {
		$screens = UI::get_screens();

		// The overview is also the first submenu entry, so the menu never shows a
		// duplicate of the parent under a different name.
		add_menu_page(
			__( 'VIP Compatibility', 'wp-vip-compatibility' ),
			__( 'VIP Compatibility', 'wp-vip-compatibility' ),
			'manage_options',
			$screens['overview']['slug'],
			fn() => $this->render_settings_page( 'overview' ),
			'dashicons-shield-alt'
		);

		foreach ( $screens as $key => $screen ) {
			$is_parent = ( $screen['slug'] === $screens['overview']['slug'] );

			add_submenu_page(
				$screens['overview']['slug'],
				/* translators: %s: Submenu title */
				sprintf( __( 'VIP Compatibility — %s', 'wp-vip-compatibility' ), $screen['title'] ),
				$screen['label'],
				'manage_options',
				$screen['slug'],
				/*
				 * The overview is listed as its own first submenu entry so the
				 * menu does not show the plugin name twice under itself. It must be
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
