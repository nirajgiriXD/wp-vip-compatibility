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
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
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
		add_action( 'wp_ajax_wvc_get_chart_data', array( $this, 'get_chart_data' ) );
		add_action( 'wp_ajax_wvc_get_scan_summary', array( $this, 'get_scan_summary' ) );
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
				'class'    => $this->status_class( $result['status'] ),
				'total'    => (int) $result['summary']['total'],
				'blocking' => (int) $result['summary']['blocking'],
				'severity' => $this->highest_severity( $result['summary']['by_severity'] ),
				'files'    => (int) $result['files_scanned'],
				'url'      => UI::get_findings_url( $result['key'] ),
				/* translators: 1: Number of findings. 2: Number of files scanned. */
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
	 * Returns the compatibility counts for one overview category.
	 *
	 * @return void
	 */
	public function get_chart_data() {
		$this->authorize();

		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by authorize() at the top of this handler.

		$callbacks = array(
			'plugins'     => 'wvc_get_plugins_chart_data',
			'themes'      => 'wvc_get_themes_chart_data',
			'mu-plugins'  => 'wvc_get_mu_plugins_chart_data',
			'database'    => 'wvc_get_database_chart_data',
			'directories' => 'wvc_get_directories_chart_data',
		);

		if ( ! isset( $callbacks[ $category ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown category.', 'wp-vip-compatibility' ) ), 400 );
		}

		wp_send_json_success( call_user_func( $callbacks[ $category ] ) );
	}

	/**
	 * Returns the aggregate readiness summary.
	 *
	 * @return void
	 */
	public function get_scan_summary() {
		$this->authorize();

		$aggregate = Report::aggregate();
		$delta     = Results_Store::get_delta();

		wp_send_json_success(
			array(
				'score'      => (int) $aggregate['score'],
				'targets'    => (int) $aggregate['targets'],
				'statuses'   => $aggregate['statuses'],
				'findings'   => (int) $aggregate['totals']['findings'],
				'blocking'   => (int) $aggregate['totals']['blocking'],
				'files'      => (int) $aggregate['totals']['files'],
				'severities' => $aggregate['by_severity'],
				'delta'      => $delta,
				'url'        => UI::get_findings_url(),
			)
		);
	}

	/**
	 * Maps a verdict onto the CSS class the table filters use.
	 *
	 * The `compatible` / `not-compatible` classes are the long-standing DOM
	 * contract for filtering, so "needs review" reuses `not-compatible` to stay
	 * in the "needs attention" filter while carrying its own label.
	 *
	 * @param string $status One of the Scanner STATUS_* constants.
	 * @return string The CSS class.
	 */
	private function status_class( $status ) {
		return ( Scanner::STATUS_PASS === $status ) ? 'compatible' : 'not-compatible';
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
