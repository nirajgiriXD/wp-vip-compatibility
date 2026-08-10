<?php
/**
 * Aggregation and export of scan results.
 *
 * Turns the per-target results into the numbers the overview needs, the
 * filtered list the findings screen needs, and the machine-readable exports a
 * developer or a CI job needs.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Builds aggregates and exports from stored scan results.
 */
class Report {

	/**
	 * Version of the export payload shape, for consumers that parse it.
	 */
	const SCHEMA_VERSION = '1.0';

	/**
	 * Scans every target and records a history snapshot.
	 *
	 * @param bool $force Whether to bypass cached per-target results.
	 * @return array<string, mixed> The aggregate.
	 */
	public static function run_full_scan( $force = false ) {
		$scanner = new Scanner();
		$targets = Targets::all();

		foreach ( $targets as $target ) {
			$scanner->get_result( $target, $force );
		}

		Results_Store::prune( array_keys( $targets ) );

		$aggregate = self::aggregate();

		Results_Store::record_history(
			array(
				'total'    => $aggregate['totals']['findings'],
				'blocking' => $aggregate['totals']['blocking'],
				'blocked'  => $aggregate['statuses'][ Scanner::STATUS_BLOCKED ],
				'review'   => $aggregate['statuses'][ Scanner::STATUS_REVIEW ],
				'pass'     => $aggregate['statuses'][ Scanner::STATUS_PASS ],
				'score'    => $aggregate['score'],
			)
		);

		return $aggregate;
	}

	/**
	 * Builds the aggregate from the stored index, without scanning.
	 *
	 * @return array<string, mixed> The aggregate.
	 */
	public static function aggregate() {
		$index = Results_Store::get_index();

		$aggregate = array(
			'targets'     => count( $index ),
			'scanned_at'  => 0,
			'statuses'    => array(
				Scanner::STATUS_PASS    => 0,
				Scanner::STATUS_REVIEW  => 0,
				Scanner::STATUS_BLOCKED => 0,
			),
			'by_severity' => array_fill_keys( array_keys( Taxonomy::get_severities() ), 0 ),
			'by_type'     => array_fill_keys( array_keys( Taxonomy::get_types() ), 0 ),
			'by_category' => array_fill_keys( array_keys( Taxonomy::get_categories() ), 0 ),
			'by_kind'     => array(),
			'totals'      => array(
				'findings' => 0,
				'blocking' => 0,
				'files'    => 0,
			),
			'score'       => 100,
		);

		foreach ( $index as $entry ) {
			$status = $entry['status'] ?? Scanner::STATUS_PASS;

			if ( isset( $aggregate['statuses'][ $status ] ) ) {
				++$aggregate['statuses'][ $status ];
			}

			$aggregate['scanned_at']        = max( $aggregate['scanned_at'], (int) ( $entry['scanned_at'] ?? 0 ) );
			$aggregate['totals']['findings'] += (int) ( $entry['summary']['total'] ?? 0 );
			$aggregate['totals']['blocking'] += (int) ( $entry['summary']['blocking'] ?? 0 );
			$aggregate['totals']['files']    += (int) ( $entry['files_scanned'] ?? 0 );

			foreach ( array( 'by_severity', 'by_type', 'by_category' ) as $axis ) {
				foreach ( (array) ( $entry['summary'][ $axis ] ?? array() ) as $key => $count ) {
					if ( isset( $aggregate[ $axis ][ $key ] ) ) {
						$aggregate[ $axis ][ $key ] += (int) $count;
					}
				}
			}

			$kind = $entry['type'] ?? 'plugin';

			if ( ! isset( $aggregate['by_kind'][ $kind ] ) ) {
				$aggregate['by_kind'][ $kind ] = array(
					Scanner::STATUS_PASS    => 0,
					Scanner::STATUS_REVIEW  => 0,
					Scanner::STATUS_BLOCKED => 0,
				);
			}

			if ( isset( $aggregate['by_kind'][ $kind ][ $status ] ) ) {
				++$aggregate['by_kind'][ $kind ][ $status ];
			}
		}

		$aggregate['score'] = self::score( $aggregate['statuses'] );

		return $aggregate;
	}

	/**
	 * Computes a readiness score from the target verdicts.
	 *
	 * A target that is ready counts in full, one that needs review counts for
	 * part of its weight, and one that is blocked counts for nothing. The score
	 * is therefore "how much of this codebase could move today", which is a
	 * question a migration lead actually asks — unlike a raw finding count,
	 * which mostly measures how large the codebase is.
	 *
	 * @param array<string, int> $statuses Counts per verdict.
	 * @return int A score between 0 and 100.
	 */
	private static function score( array $statuses ) {
		$total = array_sum( $statuses );

		if ( 0 === $total ) {
			return 100;
		}

		$weighted = $statuses[ Scanner::STATUS_PASS ] + ( $statuses[ Scanner::STATUS_REVIEW ] * 0.6 );

		return (int) round( ( $weighted / $total ) * 100 );
	}

	/**
	 * Returns every stored finding, flattened and enriched with its rule.
	 *
	 * @param array<string, mixed> $filters Optional filters: `type`, `severity`,
	 *                                      `category`, `target`, `search`.
	 * @return array<int, array<string, mixed>> The findings.
	 */
	public static function findings( array $filters = array() ) {
		$filters = wp_parse_args(
			$filters,
			array(
				'type'     => '',
				'severity' => '',
				'category' => '',
				'target'   => '',
				'search'   => '',
			)
		);

		$rows = array();

		foreach ( Results_Store::get_all() as $result ) {
			if ( '' !== $filters['target'] && $result['key'] !== $filters['target'] ) {
				continue;
			}

			foreach ( $result['findings'] as $finding ) {
				$rule = Rules::get( $finding['rule'] );

				if ( null === $rule ) {
					continue;
				}

				if ( '' !== $filters['type'] && $rule['type'] !== $filters['type'] ) {
					continue;
				}

				if ( '' !== $filters['severity'] && $finding['severity'] !== $filters['severity'] ) {
					continue;
				}

				if ( '' !== $filters['category'] && $rule['category'] !== $filters['category'] ) {
					continue;
				}

				$row = array_merge(
					$finding,
					array(
						'target_key'   => $result['key'],
						'target_label' => $result['label'],
						'target_type'  => $result['type'],
						'target_slug'  => $result['slug'],
						'title'        => $rule['title'],
						'type'         => $rule['type'],
						'category'     => $rule['category'],
						'fixability'   => $rule['fixability'],
						'detected'     => $rule['detected'],
						'why'          => $rule['why'],
						'remediation'  => $rule['remediation'],
						'alternative'  => $rule['alternative'],
						'doc'          => $rule['doc'],
						'phpcs'        => $rule['phpcs'],
					)
				);

				if ( '' !== $filters['search'] && ! self::matches_search( $row, $filters['search'] ) ) {
					continue;
				}

				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Whether a finding matches a free-text search.
	 *
	 * @param array<string, mixed> $row    The finding row.
	 * @param string               $search The search term.
	 * @return bool True when the row matches.
	 */
	private static function matches_search( array $row, $search ) {
		$haystack = strtolower(
			implode(
				' ',
				array( $row['target_label'], $row['title'], $row['file'], $row['symbol'], $row['rule'], $row['evidence'] )
			)
		);

		return false !== strpos( $haystack, strtolower( $search ) );
	}

	/**
	 * Groups findings by a rule axis.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @param string                           $axis     One of `category`, `type`, `severity`, `target_key`.
	 * @return array<string, array<int, array<string, mixed>>> Findings grouped by the axis value.
	 */
	public static function group( array $findings, $axis ) {
		$grouped = array();

		foreach ( $findings as $finding ) {
			$grouped[ $finding[ $axis ] ?? 'unknown' ][] = $finding;
		}

		return $grouped;
	}

	/**
	 * Builds the machine-readable export payload.
	 *
	 * @return array<string, mixed> The payload.
	 */
	public static function to_array() {
		$aggregate = self::aggregate();

		return array(
			'schema'        => self::SCHEMA_VERSION,
			'generator'     => 'wp-vip-compatibility',
			'plugin_version' => defined( 'WP_VIP_COMPATIBILITY_VERSION' ) ? WP_VIP_COMPATIBILITY_VERSION : '',
			'rules_version' => Rules::VERSION,
			'generated_at'  => gmdate( 'c' ),
			'site'          => array(
				'url'         => home_url(),
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'multisite'   => is_multisite(),
			),
			'summary'       => $aggregate,
			'targets'       => array_values(
				array_map(
					static function ( $result ) {
						return array(
							'key'           => $result['key'],
							'type'          => $result['type'],
							'slug'          => $result['slug'],
							'label'         => $result['label'],
							'status'        => $result['status'],
							'files_scanned' => $result['files_scanned'],
							'truncated'     => $result['truncated'],
							'summary'       => $result['summary'],
						);
					},
					Results_Store::get_all()
				)
			),
			'findings'      => self::findings(),
			'history'       => Results_Store::get_history(),
		);
	}

	/**
	 * Renders the findings as CSV.
	 *
	 * @return string The CSV document.
	 */
	public static function to_csv() {
		$columns = array(
			'target_type',
			'target',
			'severity',
			'type',
			'confidence',
			'category',
			'rule',
			'title',
			'file',
			'line',
			'scope',
			'evidence',
			'note',
			'why',
			'remediation',
			'alternative',
			'fixability',
			'phpcs_sniff',
			'documentation',
		);

		$rows = array( self::csv_line( $columns ) );

		foreach ( self::findings() as $finding ) {
			$rows[] = self::csv_line(
				array(
					$finding['target_type'],
					$finding['target_label'],
					$finding['severity'],
					$finding['type'],
					$finding['confidence'],
					$finding['category'],
					$finding['rule'],
					$finding['title'],
					$finding['file'],
					$finding['line'],
					$finding['scope'],
					$finding['evidence'],
					$finding['note'],
					$finding['why'],
					$finding['remediation'],
					$finding['alternative'],
					$finding['fixability'],
					$finding['phpcs'],
					$finding['doc'],
				)
			);
		}

		return implode( "\r\n", $rows ) . "\r\n";
	}

	/**
	 * Encodes one CSV row.
	 *
	 * @param array<int, string|int> $values The cell values.
	 * @return string The encoded row.
	 */
	private static function csv_line( array $values ) {
		$cells = array();

		foreach ( $values as $value ) {
			$cells[] = '"' . str_replace( '"', '""', (string) $value ) . '"';
		}

		return implode( ',', $cells );
	}

	/**
	 * Renders the report as Markdown, for pasting into a ticket or a PR.
	 *
	 * @return string The Markdown document.
	 */
	public static function to_markdown() {
		$aggregate = self::aggregate();
		$lines     = array();

		$lines[] = '# WordPress VIP compatibility report';
		$lines[] = '';
		$lines[] = sprintf( '- Site: %s', home_url() );
		$lines[] = sprintf( '- Generated: %s', gmdate( 'Y-m-d H:i' ) . ' UTC' );
		$lines[] = sprintf( '- Rule set: %s', Rules::VERSION );
		$lines[] = sprintf( '- Readiness: %d%%', $aggregate['score'] );
		$lines[] = sprintf(
			'- Targets: %d ready, %d need review, %d blocked',
			$aggregate['statuses'][ Scanner::STATUS_PASS ],
			$aggregate['statuses'][ Scanner::STATUS_REVIEW ],
			$aggregate['statuses'][ Scanner::STATUS_BLOCKED ]
		);
		$lines[] = '';

		$grouped = self::group( self::findings(), 'target_label' );
		ksort( $grouped );

		foreach ( $grouped as $label => $findings ) {
			$lines[] = sprintf( '## %s', $label );
			$lines[] = '';

			foreach ( $findings as $finding ) {
				$lines[] = sprintf(
					'- **%s** — %s (`%s`) at `%s:%d`',
					ucfirst( $finding['severity'] ),
					$finding['title'],
					$finding['rule'],
					$finding['file'],
					$finding['line']
				);
				$lines[] = sprintf( '  - Why it matters: %s', $finding['why'] );
				$lines[] = sprintf( '  - Fix: %s', $finding['remediation'] );

				if ( '' !== $finding['doc'] ) {
					$lines[] = sprintf( '  - Reference: %s', $finding['doc'] );
				}
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
