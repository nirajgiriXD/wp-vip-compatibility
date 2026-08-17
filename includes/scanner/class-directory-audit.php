<?php
/**
 * wp-content directory audit.
 *
 * Compares what is in wp-content against the WordPress VIP application structure.
 *
 * The important correction over the original implementation is that "not part
 * of the WordPress VIP repository structure" and "incompatible with WordPress VIP" are not the same
 * thing. `uploads/` was previously reported as incompatible, which is exactly
 * backwards: it is the one directory under wp-content that WordPress VIP *does* let
 * application code write to. It is simply imported separately rather than
 * committed. `upgrade/` and `index.php` were flagged the same way, and are
 * ordinary WordPress artefacts.
 *
 * Anything genuinely unknown is now reported as "needs review" rather than
 * asserted to be incompatible.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

use WP_VIP_COMPATIBILITY\Includes\Classes\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Audits the contents of wp-content.
 */
class Directory_Audit {

	/**
	 * Entry statuses.
	 */
	const STATUS_SUPPORTED     = 'supported';
	const STATUS_UNSUPPORTED   = 'unsupported';
	const STATUS_INFORMATIONAL = 'informational';
	const STATUS_REVIEW        = 'review';

	/**
	 * Per-request copy of the audit result.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $result = null;

	/**
	 * Runs the audit.
	 *
	 * The overview asks for this three times over — once for the area card, once
	 * for the plan, and once for the tier counts beside the gauge — and each run
	 * reads wp-content and stats every entry in it. It is held for the request
	 * rather than cached across requests, because the answer changes the moment
	 * someone uploads a file and a stale "everything is fine" would be worse than
	 * the directory read it saves.
	 *
	 * @param bool $force Whether to rebuild rather than reuse this request's copy.
	 * @return array<string, mixed> The audit result.
	 */
	public static function run( $force = false ) {
		if ( ! $force && null !== self::$result ) {
			return self::$result;
		}

		$known   = (array) Plugin::get_instance()->get_reference_list( 'directories' );
		$entries = self::read_wp_content();

		$rows    = array();
		$summary = array(
			'total'         => 0,
			'supported'     => 0,
			'unsupported'   => 0,
			'informational' => 0,
			'review'        => 0,
		);

		foreach ( $entries as $entry ) {
			$row = self::classify( $entry, $known );

			$rows[] = $row;
			++$summary['total'];
			++$summary[ $row['status'] ];
		}

		self::$result = array(
			'entries'    => $rows,
			'summary'    => $summary,
			'checked_at' => time(),
		);

		return self::$result;
	}

	/**
	 * Drops this request's copy of the audit.
	 *
	 * Nothing survives the request, so unlike the database audit there is no
	 * stored reading to invalidate. It exists so that a rescan scoped to
	 * wp-content is a real instruction rather than a no-op, and so that a caller
	 * which changes the directory mid-request sees the change.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$result = null;
	}

	/**
	 * Lists the entries directly inside wp-content.
	 *
	 * @return string[] Entry names.
	 */
	private static function read_wp_content() {
		if ( ! is_dir( WP_CONTENT_DIR ) || ! is_readable( WP_CONTENT_DIR ) ) {
			return array();
		}

		$entries = array_diff( (array) scandir( WP_CONTENT_DIR ), array( '.', '..' ) );

		natcasesort( $entries );

		return array_values( $entries );
	}

	/**
	 * Classifies one wp-content entry.
	 *
	 * @param string                              $entry The entry name.
	 * @param array<string, array<string, mixed>> $known The reference list.
	 * @return array<string, mixed> The classified entry.
	 */
	private static function classify( $entry, array $known ) {
		$path     = WP_CONTENT_DIR . '/' . $entry;
		$is_dir   = is_dir( $path );
		$defaults = array(
			'name'        => $entry,
			'is_dir'      => $is_dir,
			'status'      => self::STATUS_REVIEW,
			'description' => '',
			'guidance'    => '',
			'doc'         => '',
		);

		if ( isset( $known[ $entry ] ) ) {
			$record = $known[ $entry ];

			return array_merge(
				$defaults,
				array(
					'status'      => self::normalise_status( $record ),
					'description' => $record['description'] ?? '',
					'guidance'    => $record['guidance'] ?? '',
					'doc'         => $record['doc'] ?? '',
				)
			);
		}

		// A drop-in is always worth calling out, even an unrecognised one.
		if ( ! $is_dir && preg_match( '/^(advanced-cache|object-cache|db|db-error|maintenance|install|sunrise|php-error|fatal-error-handler)\.php$/', $entry ) ) {
			return array_merge(
				$defaults,
				array(
					'status'      => self::STATUS_UNSUPPORTED,
					'description' => __( 'WordPress drop-in.', 'wp-vip-compatibility' ),
					'guidance'    => __( 'WordPress VIP installs its own drop-ins. A drop-in shipped with the application either has no effect or conflicts with the platform.', 'wp-vip-compatibility' ),
					'doc'         => 'https://docs.wpvip.com/technical-references/wordpress-on-vip/',
				)
			);
		}

		return array_merge(
			$defaults,
			array(
				'description' => $is_dir
					? __( 'Directory that is not part of the WordPress VIP application structure.', 'wp-vip-compatibility' )
					: __( 'File that is not part of the WordPress VIP application structure.', 'wp-vip-compatibility' ),
				'guidance'    => __( 'Work out what created it. If the codebase reads from it, move the contents into uploads/ and update the stored paths; if nothing uses it, leave it out of the repository.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/wordpress-skeleton/',
			)
		);
	}

	/**
	 * Reads the status from a reference record.
	 *
	 * Accepts the older `is_supported` boolean so that a customised data file
	 * from a previous version keeps working.
	 *
	 * @param array<string, mixed> $record The reference record.
	 * @return string One of the STATUS_* constants.
	 */
	private static function normalise_status( array $record ) {
		if ( isset( $record['status'] ) ) {
			$valid = array( self::STATUS_SUPPORTED, self::STATUS_UNSUPPORTED, self::STATUS_INFORMATIONAL, self::STATUS_REVIEW );

			return in_array( $record['status'], $valid, true ) ? $record['status'] : self::STATUS_REVIEW;
		}

		if ( isset( $record['is_supported'] ) ) {
			return $record['is_supported'] ? self::STATUS_SUPPORTED : self::STATUS_UNSUPPORTED;
		}

		return self::STATUS_REVIEW;
	}

	/**
	 * Returns the label for an entry status.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @return string The label.
	 */
	public static function status_label( $status ) {
		switch ( $status ) {
			case self::STATUS_SUPPORTED:
				return __( 'Supported', 'wp-vip-compatibility' );
			case self::STATUS_UNSUPPORTED:
				return __( 'Remove or relocate', 'wp-vip-compatibility' );
			case self::STATUS_INFORMATIONAL:
				return __( 'Not deployed', 'wp-vip-compatibility' );
			default:
				return __( 'Needs review', 'wp-vip-compatibility' );
		}
	}
}
