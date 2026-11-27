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
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Includes\Classes;

use PREFLIGHT_AUDIT\Includes\Traits\Singleton;
use PREFLIGHT_AUDIT\Includes\Scanner\Report;
use PREFLIGHT_AUDIT\Includes\Scanner\Scanner;
use PREFLIGHT_AUDIT\Includes\Scanner\Targets;
use PREFLIGHT_AUDIT\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the plugin's AJAX requests.
 */
class Ajax {

	use Singleton;

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
		add_action( 'wp_ajax_preflight_audit_scan_target', array( $this, 'scan_target' ) );
		add_action( 'wp_ajax_preflight_audit_scan_complete', array( $this, 'scan_complete' ) );
	}

	/**
	 * Verifies the nonce and the caller's capability.
	 *
	 * Sends an error response and exits when either check fails.
	 *
	 * @return void
	 */
	private function authorize() {
		if ( ! current_user_can( Plugin::get_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to run compatibility scans.', 'preflight-audit' ) ),
				403
			);
		}

		// check_ajax_referer() handles unslashing and dies on failure.
		check_ajax_referer( 'preflight_audit_ajax_nonce', '_ajax_nonce' );
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
			wp_send_json_error( array( 'message' => __( 'No scan target was supplied.', 'preflight-audit' ) ), 400 );
		}

		$target = Targets::get( $key );

		if ( null === $target ) {
			wp_send_json_error( array( 'message' => __( 'Unknown scan target.', 'preflight-audit' ) ), 404 );
		}

		$force = ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by authorize() at the top of this handler.

		// One very large plugin is minutes of tokenising in this one request.
		Scanner::prepare_long_request();

		$result = ( new Scanner() )->get_result( $target, $force );
		$total  = (int) $result['summary']['total'];
		$files  = (int) $result['files_scanned'];

		/* translators: %s: Number of findings. */
		$count_label = sprintf( _n( '%s finding', '%s findings', $total, 'preflight-audit' ), number_format_i18n( $total ) );

		/* translators: %s: Number of PHP files scanned. */
		$files_label = sprintf( _n( '%s PHP file', '%s PHP files', $files, 'preflight-audit' ), number_format_i18n( $files ) );

		wp_send_json_success(
			array(
				'target'   => $result['key'],
				'status'   => $result['status'],
				'label'    => Scanner::status_label( $result['status'] ),
				'state'    => $this->status_state( $result['status'] ),
				'total'    => $total,
				'count'    => $count_label,
				'blocking' => (int) $result['summary']['blocking'],
				'severity' => $this->highest_severity( $result['summary']['by_severity'] ),
				'files'    => $files,
				'url'      => UI::get_findings_url( $result['key'] ),
				// Each count picks its own plural form; one _n() cannot do both.
				'summary'  => sprintf(
					/* translators: 1: Number of findings, already counted, e.g. "2 findings". 2: Number of PHP files, already counted, e.g. "1 PHP file". */
					__( '%1$s across %2$s.', 'preflight-audit' ),
					$count_label,
					$files_label
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

		$scope = Report::resolve_scope( $scope );

		Report::record_snapshot( $scope );

		wp_send_json_success( array( 'scope' => $scope ) );
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
