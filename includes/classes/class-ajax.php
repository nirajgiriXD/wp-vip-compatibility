<?php
/**
 * This file contains the class and methods to handle ajax requests.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * This class will handle ajax requests.
 */
class Ajax {

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

		add_action( 'wp_ajax_wvc_check_vip_compatibility', array( $this, 'wvc_ajax_check_vip_compatibility' ) );
		add_action( 'wp_ajax_wvc_render_log_note', array( $this, 'wvc_ajax_render_log_note' ) );
	}

	/**
	 * Handle the AJAX request for VIP compatibility check.
	 */
	public function wvc_ajax_check_vip_compatibility() {
		// Verify nonce for security.
		if ( ! isset( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( $_POST['_ajax_nonce'], 'wvc_ajax_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wp-vip-compatibility' ) ] );
		}

		// Validate input.
		if ( ! isset( $_POST['directory_path'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'wp-vip-compatibility' ) ] );
		}

		$directory_path = sanitize_text_field( wp_unslash( $_POST['directory_path'] ) );

		// Check compatibility using your function.
		$status = wvc_check_vip_compatibility( $directory_path );

		// Determine CSS class for styling.
		$class = ( 'Compatible' === $status ) ? 'compatible' : 'not-compatible';

		// Send response.
		wp_send_json_success( [
			'message' => $status,
			'class'   => $class
		] );
	}

	/**
	 * Handle the AJAX request to render log note.
	 */
	public function wvc_ajax_render_log_note() {
		// Verify nonce for security.
		if ( empty( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( $_POST['_ajax_nonce'], 'wvc_ajax_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wp-vip-compatibility' ) ] );
		}
	
		// Validate input.
		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( $_POST['filename'] ) : '';
		if ( empty( $filename ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'wp-vip-compatibility' ) ] );
		}
	
		// Construct log file path.
		$log_file_path = WP_CONTENT_DIR . "/uploads/wvc-logs/{$filename}.txt";
		$log_file_url  = WP_CONTENT_URL . "/uploads/wvc-logs/{$filename}.txt";
	
		// Check if log file exists.
		if ( file_exists( $log_file_path ) ) {
			wp_send_json_success( [
				'message' => sprintf(
					'<p><strong>%s</strong> %s <a href="%s" download>%s</a></p>',
					esc_html__( 'Note:', 'wp-vip-compatibility' ),
					esc_html__( 'The log file containing all the details is available for download at ', 'wp-vip-compatibility' ),
					esc_url( $log_file_url ),
					esc_html( "wp-content/uploads/wvc-logs/{$filename}.txt" )
				)
			] );
		} else {
			wp_send_json_success( [ 
				'message' => sprintf(
					'<p><strong>%s</strong> %s</p>',
					esc_html__( 'Note:', 'wp-vip-compatibility' ),
					esc_html__( 'No incompatibility logs were generated.', 'wp-vip-compatibility' )
				) 
			] );
		}
	}
}