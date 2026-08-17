<?php
/**
 * Scan orchestration.
 *
 * Walks a target's PHP files, runs the code scanner over each one, and folds
 * the findings into a summary. Results are fingerprinted against the files on
 * disk and the rule-set version, so a target is only re-tokenised when its code
 * or the rules have actually changed. That matters: the previous implementation
 * re-scanned every plugin on every page load and on every one of the five
 * overview AJAX calls.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Scans targets and produces persisted, summarised results.
 */
class Scanner {

	/**
	 * Upper bound on files examined per target.
	 *
	 * A target this large is a monorepo or a bundled framework; scanning all of
	 * it would time out the request without changing the verdict.
	 */
	const MAX_FILES = 4000;

	/**
	 * Upper bound on findings retained per target.
	 */
	const MAX_FINDINGS = 400;

	/**
	 * Verdicts a target can receive.
	 */
	const STATUS_PASS    = 'pass';
	const STATUS_REVIEW  = 'review';
	const STATUS_BLOCKED = 'blocked';

	/**
	 * The code scanner, created lazily so listing targets stays cheap.
	 *
	 * @var Code_Scanner|null
	 */
	private $code_scanner = null;

	/**
	 * Returns the result for a target, scanning only when the cache is stale.
	 *
	 * @param array<string, mixed> $target The target, from Targets.
	 * @param bool                 $force  Whether to bypass the cached result.
	 * @return array<string, mixed> The scan result.
	 */
	public function get_result( array $target, $force = false ) {
		$fingerprint = $this->fingerprint( $target['path'] );
		$cached      = Results_Store::get( $target['key'] );

		if ( ! $force && null !== $cached && ( $cached['fingerprint'] ?? '' ) === $fingerprint ) {
			return $cached;
		}

		$result = $this->scan( $target, $fingerprint );

		Results_Store::save( $target['key'], $result );

		return $result;
	}

	/**
	 * Scans a target from scratch.
	 *
	 * @param array<string, mixed> $target      The target, from Targets.
	 * @param string               $fingerprint Precomputed fingerprint, if available.
	 * @return array<string, mixed> The scan result.
	 */
	public function scan( array $target, $fingerprint = '' ) {
		if ( '' === $fingerprint ) {
			$fingerprint = $this->fingerprint( $target['path'] );
		}

		if ( null === $this->code_scanner ) {
			$this->code_scanner = new Code_Scanner();
		}

		$findings  = array();
		$files     = $this->php_files( $target['path'] );
		$scanned   = 0;
		$truncated = false;

		foreach ( $files as $absolute => $relative ) {
			if ( $scanned >= self::MAX_FILES || count( $findings ) >= self::MAX_FINDINGS ) {
				$truncated = true;
				break;
			}

			$findings = array_merge( $findings, $this->code_scanner->scan_file( $absolute, $relative ) );
			++$scanned;
		}

		if ( count( $findings ) > self::MAX_FINDINGS ) {
			$findings  = array_slice( $findings, 0, self::MAX_FINDINGS );
			$truncated = true;
		}

		$findings = $this->sort( $findings );

		return array(
			'key'           => $target['key'],
			'type'          => $target['type'],
			'slug'          => $target['slug'],
			'label'         => $target['label'],
			'findings'      => $findings,
			'summary'       => $this->summarise( $findings ),
			'status'        => $this->status( $findings ),
			'files_scanned' => $scanned,
			'truncated'     => $truncated,
			'scanned_at'    => time(),
			'rules_version' => Rules::VERSION,
			'fingerprint'   => $fingerprint,
		);
	}

	/**
	 * Lists the PHP files belonging to a target.
	 *
	 * @param string $path Absolute path to a file or directory.
	 * @return array<string, string> Absolute path => path relative to the target.
	 */
	public function php_files( $path ) {
		if ( is_file( $path ) ) {
			return array( $path => basename( $path ) );
		}

		if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
			return array();
		}

		$excluded = $this->excluded_directories();
		$files    = array();
		$base     = rtrim( str_replace( '\\', '/', $path ), '/' );

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS ),
					static function ( $current ) use ( $excluded ) {
						if ( $current->isDir() ) {
							return ! in_array( strtolower( $current->getFilename() ), $excluded, true );
						}

						return 'php' === strtolower( $current->getExtension() );
					}
				),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				$absolute = $file->getPathname();
				$relative = ltrim( substr( str_replace( '\\', '/', $absolute ), strlen( $base ) ), '/' );

				$files[ $absolute ] = $relative;
			}
		} catch ( \Exception $exception ) {
			// An unreadable subtree yields whatever was collected before it.
			unset( $exception );
		}

		return $files;
	}

	/**
	 * Returns the directory names skipped during a scan.
	 *
	 * These hold third-party or generated code that the site owner does not
	 * maintain, which is the same set WordPress VIP lets you exclude from its own PHPCS
	 * scans through `.vipgoci_phpcs_skip_folders`.
	 *
	 * @return string[] Lower-case directory names.
	 */
	private function excluded_directories() {
		/**
		 * Filters the directory names excluded from code scans.
		 *
		 * @param string[] $excluded Lower-case directory names.
		 */
		return (array) apply_filters(
			'wvc_scanner_excluded_directories',
			array( 'vendor', 'vendor_prefixed', 'node_modules', 'bower_components', '.git', '.svn', 'dist', 'build' )
		);
	}

	/**
	 * Fingerprints a target so unchanged code is not re-tokenised.
	 *
	 * @param string $path Absolute path to a file or directory.
	 * @return string The fingerprint.
	 */
	public function fingerprint( $path ) {
		if ( is_file( $path ) ) {
			$parts = array( filemtime( $path ), filesize( $path ), 1 );

			return md5( Rules::VERSION . '|' . implode( '|', $parts ) );
		}

		$count   = 0;
		$latest  = 0;
		$bytes   = 0;

		foreach ( $this->php_files( $path ) as $absolute => $relative ) {
			++$count;
			$latest = max( $latest, (int) filemtime( $absolute ) );
			$bytes += (int) filesize( $absolute );

			unset( $relative );
		}

		return md5( Rules::VERSION . '|' . $count . '|' . $latest . '|' . $bytes );
	}

	/**
	 * Orders findings by severity, then confidence, then location.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @return array<int, array<string, mixed>> The sorted findings.
	 */
	private function sort( array $findings ) {
		$confidence_order = array_keys( Taxonomy::get_confidences() );

		usort(
			$findings,
			static function ( $a, $b ) use ( $confidence_order ) {
				$severity = Taxonomy::get_severity_weight( $b['severity'] ) <=> Taxonomy::get_severity_weight( $a['severity'] );

				if ( 0 !== $severity ) {
					return $severity;
				}

				$confidence = array_search( $a['confidence'], $confidence_order, true ) <=> array_search( $b['confidence'], $confidence_order, true );

				if ( 0 !== $confidence ) {
					return $confidence;
				}

				return array( $a['file'], $a['line'] ) <=> array( $b['file'], $b['line'] );
			}
		);

		return $findings;
	}

	/**
	 * Counts findings by severity, type and category.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @return array<string, mixed> The summary.
	 */
	private function summarise( array $findings ) {
		$summary = array(
			'total'       => count( $findings ),
			'blocking'    => 0,
			'by_severity' => array_fill_keys( array_keys( Taxonomy::get_severities() ), 0 ),
			'by_type'     => array_fill_keys( array_keys( Taxonomy::get_types() ), 0 ),
			'by_category' => array_fill_keys( array_keys( Taxonomy::get_categories() ), 0 ),
		);

		foreach ( $findings as $finding ) {
			$rule = Rules::get( $finding['rule'] );

			if ( null === $rule ) {
				continue;
			}

			++$summary['by_severity'][ $finding['severity'] ];
			++$summary['by_type'][ $rule['type'] ];

			if ( isset( $summary['by_category'][ $rule['category'] ] ) ) {
				++$summary['by_category'][ $rule['category'] ];
			}

			if ( Taxonomy::is_blocking_type( $rule['type'] ) ) {
				++$summary['blocking'];
			}
		}

		return $summary;
	}

	/**
	 * Derives a target's verdict from its findings.
	 *
	 * The three states are deliberate. "Blocked" means something is expected to
	 * fail on the platform; "review" means there is work to do that will not by
	 * itself stop the migration. Collapsing those two into one "incompatible"
	 * flag, as the original implementation did, made a deprecated function look
	 * as serious as a shell command.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @return string One of the STATUS_* constants.
	 */
	private function status( array $findings ) {
		if ( empty( $findings ) ) {
			return self::STATUS_PASS;
		}

		foreach ( $findings as $finding ) {
			$rule = Rules::get( $finding['rule'] );

			if ( null === $rule || ! Taxonomy::is_blocking_type( $rule['type'] ) ) {
				continue;
			}

			if ( in_array( $finding['severity'], array( Taxonomy::SEVERITY_CRITICAL, Taxonomy::SEVERITY_HIGH ), true ) ) {
				return self::STATUS_BLOCKED;
			}
		}

		return self::STATUS_REVIEW;
	}

	/**
	 * Returns the label for a verdict.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @return string The label.
	 */
	public static function status_label( $status ) {
		switch ( $status ) {
			case self::STATUS_BLOCKED:
				return __( 'Blocked', 'wp-vip-compatibility' );
			case self::STATUS_REVIEW:
				return __( 'Needs review', 'wp-vip-compatibility' );
			default:
				return __( 'Ready', 'wp-vip-compatibility' );
		}
	}
}
