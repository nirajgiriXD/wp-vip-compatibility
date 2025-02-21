<?php
/**
 * This file contains the class and methods to register and enqueue the required scripts and styles.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

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
	 * To enqueue scripts and styles in admin.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {

		if ( file_exists( WP_VIP_COMPATIBILITY_DIR . '/assets/js/admin.js' ) ) {
			wp_register_script(
				'wp-vip-compatibility-admin-script',
				WP_VIP_COMPATIBILITY_URL . '/assets/js/admin.js',
				array(),
				filemtime( WP_VIP_COMPATIBILITY_DIR . '/assets/js/admin.js' ),
				true
			);
			wp_enqueue_script( 'wp-vip-compatibility-admin-script' );

			wp_localize_script( 'wp-vip-compatibility-admin-script', 'wvcAjax', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wvc_ajax_nonce' ),
			] );
		}

		if ( file_exists( WP_VIP_COMPATIBILITY_DIR . '/assets/css/admin.css' ) ) {
			wp_register_style(
				'wp-vip-compatibility-admin-styles',
				WP_VIP_COMPATIBILITY_URL . '/assets/css/admin.css',
				array(),
				filemtime( WP_VIP_COMPATIBILITY_DIR . '/assets/css/admin.css' )
			);
			wp_enqueue_style( 'wp-vip-compatibility-admin-styles' );
		}
	}
}