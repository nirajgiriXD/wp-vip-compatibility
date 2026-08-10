<?php
/**
 * Report export.
 *
 * Replaces the old log files. Those were written to
 * `wp-content/uploads/wvc-logs/*.json`, which is a publicly reachable URL: the
 * report — file paths, line numbers and a list of the site's weakest code —
 * was downloadable by anyone who guessed the name. Exports are now streamed
 * through admin-post behind a nonce and a capability check, so the report never
 * exists as a public file.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Streams the compatibility report in machine- and human-readable formats.
 */
class Export {

	use Singleton;

	/**
	 * Capability required to export.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'wvc_export_report';

	/**
	 * admin-post action name.
	 */
	const ACTION = 'wvc_export';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Returns the formats the report can be exported in.
	 *
	 * @return array<string, array<string, string>> Format definitions keyed by slug.
	 */
	public static function get_formats() {
		return array(
			'json' => array(
				'label'       => __( 'JSON', 'wp-vip-compatibility' ),
				'description' => __( 'Full report with every finding. Suitable for CI and tooling.', 'wp-vip-compatibility' ),
				'extension'   => 'json',
				'mime'        => 'application/json',
			),
			'csv'  => array(
				'label'       => __( 'CSV', 'wp-vip-compatibility' ),
				'description' => __( 'One row per finding, for a spreadsheet or an analysis sheet.', 'wp-vip-compatibility' ),
				'extension'   => 'csv',
				'mime'        => 'text/csv',
			),
			'md'   => array(
				'label'       => __( 'Markdown', 'wp-vip-compatibility' ),
				'description' => __( 'Grouped by target, for pasting into a ticket or a pull request.', 'wp-vip-compatibility' ),
				'extension'   => 'md',
				'mime'        => 'text/markdown',
			),
		);
	}

	/**
	 * Returns the URL that exports the report in a given format.
	 *
	 * @param string $format One of the format slugs.
	 * @return string The export URL.
	 */
	public static function get_url( $format ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'format' => $format,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
		);
	}

	/**
	 * Streams the export.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to export the compatibility report.', 'wp-vip-compatibility' ),
				esc_html__( 'Forbidden', 'wp-vip-compatibility' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE_ACTION );

		$formats = self::get_formats();
		$format  = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'json';

		if ( ! isset( $formats[ $format ] ) ) {
			$format = 'json';
		}

		switch ( $format ) {
			case 'csv':
				$body = Report::to_csv();
				break;
			case 'md':
				$body = Report::to_markdown();
				break;
			default:
				$body = (string) wp_json_encode( Report::to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				break;
		}

		$filename = sprintf(
			'vip-compatibility-%1$s-%2$s.%3$s',
			sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			gmdate( 'Ymd-His' ),
			$formats[ $format ]['extension']
		);

		nocache_headers();
		header( 'Content-Type: ' . $formats[ $format ]['mime'] . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A downloaded JSON/CSV/Markdown document; HTML escaping would corrupt it. Values are encoded by the format writers.
		echo $body;
		exit;
	}
}
