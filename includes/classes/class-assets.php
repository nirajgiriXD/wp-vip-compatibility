<?php
/**
 * This file contains the class and methods to register and enqueue the required scripts and styles.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * This class is used to register and enqueue the required scripts and styles.
 */
class Assets {

	use Singleton;

	/**
	 * Constructor method is used to initialize the fields.
	 */
	public function __construct() {

		$this->setup_hooks();
	}

	/**
	 * To setup actions and filters.
	 *
	 * @return void
	 */
	private function setup_hooks() {

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	/**
	 * Returns the plugin screen currently being viewed, if any.
	 *
	 * Assets are scoped to the plugin's own screens so they never interfere with
	 * WordPress core styles or with other plugins' admin pages.
	 *
	 * @return string The settings key, or an empty string when off-screen.
	 */
	private function get_current_screen_key() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( '' === $page ) {
			return '';
		}

		foreach ( UI::get_screens() as $key => $screen ) {
			if ( $screen['slug'] === $page ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * To enqueue scripts and styles in admin.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {

		$screen_key = $this->get_current_screen_key();

		// Bail out on every screen that does not belong to this plugin.
		if ( '' === $screen_key ) {
			return;
		}

		// Enqueue custom style.
		if ( file_exists( WP_VIP_COMPATIBILITY_DIR . '/assets/css/admin.css' ) ) {
			wp_register_style(
				'wp-vip-compatibility-admin-styles',
				WP_VIP_COMPATIBILITY_URL . '/assets/css/admin.css',
				array(),
				filemtime( WP_VIP_COMPATIBILITY_DIR . '/assets/css/admin.css' )
			);
			wp_enqueue_style( 'wp-vip-compatibility-admin-styles' );
		}

		// Enqueue custom script. The overview used to also load a 200 KB charting
		// library to draw five doughnuts of three numbers each; those numbers are
		// rendered server-side now, as proportion bars, so nothing is fetched.
		if ( file_exists( WP_VIP_COMPATIBILITY_DIR . '/assets/js/admin.js' ) ) {
			wp_register_script(
				'wp-vip-compatibility-admin-script',
				WP_VIP_COMPATIBILITY_URL . '/assets/js/admin.js',
				array( 'jquery' ),
				filemtime( WP_VIP_COMPATIBILITY_DIR . '/assets/js/admin.js' ),
				true
			);
			wp_enqueue_script( 'wp-vip-compatibility-admin-script' );

			wp_localize_script(
				'wp-vip-compatibility-admin-script',
				'_WPVC_',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'wvc_ajax_nonce' ),
					'i18n'     => array(
						'checking'        => esc_html__( 'Scanning…', 'wp-vip-compatibility' ),
						'error'           => esc_html__( 'Scan failed', 'wp-vip-compatibility' ),

						/* translators: 1: Number of completed checks. 2: Total number of checks. */
						'scanning'        => esc_html__( 'Scanning %1$s of %2$s…', 'wp-vip-compatibility' ),
						/* translators: %s: Number of findings. */
						'findingCount'    => esc_html__( '%s findings', 'wp-vip-compatibility' ),

						/* translators: 1: Number of visible rows. 2: Total number of rows. */
						'showingFiltered' => esc_html__( 'Showing %1$s of %2$s', 'wp-vip-compatibility' ),
						/* translators: %s: Total number of rows. */
						'showingAll'      => esc_html__( 'Showing all %s', 'wp-vip-compatibility' ),
						'noResults'       => esc_html__( 'No matching results', 'wp-vip-compatibility' ),
						'noResultsHint'   => esc_html__( 'Try a different search term, or widen the filters above.', 'wp-vip-compatibility' ),

						'copy'            => esc_html__( 'Copy to clipboard', 'wp-vip-compatibility' ),
						'copied'          => esc_html__( 'Copied', 'wp-vip-compatibility' ),
						'copyFailed'      => esc_html__( 'Press Ctrl+C to copy', 'wp-vip-compatibility' ),
					),
				)
			);
		}
	}
}
