<?php
/**
 * AJAX endpoints.
 *
 * Three things were wrong with the previous implementation and are fixed here:
 *
 * - No capability check. `wp_ajax_*` only requires a logged-in user, so every
 *   handler was reachable by any authenticated account, including subscribers.
 * - `directory_path` was taken from the request and passed to the filesystem,
 *   which let a caller scan and enumerate arbitrary server paths. Requests now
 *   name a target key that is resolved against the plugins, themes and must-use
 *   plugins WordPress reports; anything else is rejected.
 * - `filename` was interpolated into a path with no traversal guard.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Scanner;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the plugin's AJAX requests.
 */
class Ajax {

	use Singleton;

	/**
	 * Capability required for every endpoint.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Registers the endpoints.
	 *
	 * @return void
	 */
	private function setup_hooks() {
		add_action( 'wp_ajax_wvc_scan_target', array( $this, 'scan_target' ) );
		add_action( 'wp_ajax_wvc_scan_complete', array( $this, 'scan_complete' ) );
	}

	/**
	 * Verifies the nonce and the caller's capability.
	 *
	 * Sends an error response and exits when either check fails.
	 *
	 * @return void
	 */
	private function authorize() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to run compatibility scans.', 'wp-vip-compatibility' ) ),
				403
			);
		}

		// check_ajax_referer() handles unslashing and dies on failure.
		check_ajax_referer( 'wvc_ajax_nonce', '_ajax_nonce' );
	}

	/**
	 * Scans a single target and returns its verdict.
	 *
	 * @return void
	 */
	public function scan_target() {
		$this->authorize();

		$key = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by authorize() at the top of this handler.

		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'No scan target was supplied.', 'wp-vip-compatibility' ) ), 400 );
		}

		$target = Targets::get( $key );

		if ( null === $target ) {
			wp_send_json_error( array( 'message' => __( 'Unknown scan target.', 'wp-vip-compatibility' ) ), 404 );
		}

		$force  = ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by authorize() at the top of this handler.
		$result = ( new Scanner() )->get_result( $target, $force );

		wp_send_json_success(
			array(
				'target'   => $result['key'],
				'status'   => $result['status'],
				'label'    => Scanner::status_label( $result['status'] ),
				'state'    => $this->status_state( $result['status'] ),
				'total'    => (int) $result['summary']['total'],
				'blocking' => (int) $result['summary']['blocking'],
				'severity' => $this->highest_severity( $result['summary']['by_severity'] ),
				'files'    => (int) $result['files_scanned'],
				'url'      => UI::get_findings_url( $result['key'] ),
				'summary'  => sprintf(
					/* translators: 1: Number of findings. 2: Number of PHP files scanned. */
					_n( '%1$d finding across %2$d PHP file.', '%1$d findings across %2$d PHP files.', (int) $result['summary']['total'], 'wp-vip-compatibility' ),
					(int) $result['summary']['total'],
					(int) $result['files_scanned']
				),
			)
		);
	}

	/**
	 * Closes out a rescan that ran in the browser.
	 *
	 * A row-by-row rescan has no moment on the server where it is finished: the
	 * last target to resolve looks exactly like the first. The page reports
	 * completion once, and that is what records the history snapshot — otherwise
	 * a rescan started from the menu would never appear under "Recent scans".
	 *
	 * @return void
	 */
	public function scan_complete() {
		$this->authorize();

		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : Report::SCOPE_ALL; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by authorize() at the top of this handler.

		Report::record_snapshot();

		wp_send_json_success( array( 'scope' => Report::resolve_scope( $scope ) ) );
	}

	/**
	 * Maps a verdict onto the state the tables filter and style by.
	 *
	 * "Needs review" used to be reported as `not-compatible` so that it landed in
	 * a two-way "ready / needs attention" filter. The filters now read a
	 * `data-status` attribute with one value per verdict, so a review no longer
	 * has to impersonate a failure to be findable.
	 *
	 * @param string $status One of the Scanner STATUS_* constants.
	 * @return string The verdict state.
	 */
	private function status_state( $status ) {
		$states = array(
			Scanner::STATUS_PASS    => 'compatible',
			Scanner::STATUS_REVIEW  => 'review',
			Scanner::STATUS_BLOCKED => 'not-compatible',
		);

		return $states[ $status ] ?? 'review';
	}

	/**
	 * Returns the most serious severity present in a summary.
	 *
	 * @param array<string, int> $by_severity Counts keyed by severity.
	 * @return string The severity slug, or an empty string when there are none.
	 */
	private function highest_severity( array $by_severity ) {
		foreach ( array_keys( Taxonomy::get_severities() ) as $severity ) {
			if ( ! empty( $by_severity[ $severity ] ) ) {
				return $severity;
			}
		}

		return '';
	}
}
