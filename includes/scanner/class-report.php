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
	 * Resolves the verdict for a single scan target.
	 *
	 * This is the one place that decides what a plugin, theme or must-use plugin
	 * is called on screen. It used to be answered separately by each entity
	 * screen and again by the chart helpers, which is how the same plugin could
	 * be "Incompatible" in a table and "needs review" in a total.
	 *
	 * @param array<string, mixed>                $target The target.
	 * @param array<string, array<string, mixed>> $index  The stored result index.
	 * @return array<string, mixed> The verdict: `status`, `state`, `label`, `total`, `known`, `scanned`.
	 */
	public static function verdict( array $target, array $index ) {
		$entry = $index[ $target['key'] ] ?? null;
		$total = (int) ( $entry['summary']['total'] ?? 0 );
		$known = ( 'mu-plugin' === $target['type'] )
			? Known_Plugins::mu_plugin( $target['slug'] )
			: Known_Plugins::classify( $target['slug'] );

		$verdict = array(
			'total'   => $total,
			'known'   => $known,
			'scanned' => null !== $entry,
		);

		// A plugin WordPress VIP documents as incompatible is settled without a scan.
		if ( 'mu-plugin' !== $target['type'] && is_array( $known ) && Known_Plugins::INCOMPATIBLE === $known['classification'] ) {
			return array_merge(
				$verdict,
				array(
					'status' => Scanner::STATUS_BLOCKED,
					'state'  => 'not-compatible',
					'label'  => __( 'Incompatible', 'wp-vip-compatibility' ),
				)
			);
		}

		// Neither a VIP-preinstalled nor a previous host's must-use plugin is
		// "incompatible code" — both are "do not ship this", which is review work.
		if ( 'mu-plugin' === $target['type'] && is_array( $known ) ) {
			return array_merge(
				$verdict,
				array(
					'status' => Scanner::STATUS_REVIEW,
					'state'  => 'review',
					'label'  => __( 'Do not migrate', 'wp-vip-compatibility' ),
				)
			);
		}

		if ( null === $entry ) {
			return array_merge(
				$verdict,
				array(
					'status' => '',
					'state'  => 'pending',
					'label'  => __( 'Not scanned yet', 'wp-vip-compatibility' ),
				)
			);
		}

		$status = $entry['status'] ?? Scanner::STATUS_PASS;
		$states = array(
			Scanner::STATUS_PASS    => 'compatible',
			Scanner::STATUS_REVIEW  => 'review',
			Scanner::STATUS_BLOCKED => 'not-compatible',
		);

		return array_merge(
			$verdict,
			array(
				'status' => $status,
				'state'  => $states[ $status ] ?? 'review',
				'label'  => Scanner::status_label( $status ),
			)
		);
	}

	/**
	 * Counts the verdicts within one area of the site.
	 *
	 * `pending` is reported separately rather than folded into "needs review",
	 * so a breakdown never claims a verdict for something that has not been
	 * scanned yet.
	 *
	 * @param string $area One of `plugin`, `theme`, `mu-plugin`, `database`, `directories`.
	 * @return array<string, int> Counts keyed `ready`, `review`, `blocked`, `pending`, `total`.
	 */
	public static function area_counts( $area ) {
		$counts = array(
			'ready'   => 0,
			'review'  => 0,
			'blocked' => 0,
			'pending' => 0,
			'total'   => 0,
		);

		if ( 'database' === $area ) {
			$summary = Database_Audit::run()['summary'];

			$counts['ready']   = (int) $summary['compatible'];
			$counts['blocked'] = (int) $summary['incompatible'];
			$counts['total']   = (int) $summary['total'];

			return $counts;
		}

		if ( 'directories' === $area ) {
			$summary = Directory_Audit::run()['summary'];

			// "Not deployed" entries such as uploads/ are neither a pass nor a
			// failure: they are imported separately rather than committed.
			$counts['ready']   = (int) $summary['supported'] + (int) $summary['informational'];
			$counts['review']  = (int) $summary['review'];
			$counts['blocked'] = (int) $summary['unsupported'];
			$counts['total']   = (int) $summary['total'];

			return $counts;
		}

		$index = Results_Store::get_index();
		$map   = array(
			Scanner::STATUS_PASS    => 'ready',
			Scanner::STATUS_REVIEW  => 'review',
			Scanner::STATUS_BLOCKED => 'blocked',
		);

		foreach ( Targets::of_type( $area ) as $target ) {
			$verdict = self::verdict( $target, $index );
			$bucket  = ( 'pending' === $verdict['state'] ) ? 'pending' : ( $map[ $verdict['status'] ] ?? 'review' );

			++$counts[ $bucket ];
			++$counts['total'];
		}

		return $counts;
	}

	/**
	 * Returns the per-area breakdown the overview shows.
	 *
	 * Each area maps onto the screen that owns it, so a card on the overview and
	 * the entry in the section rail lead to the same place.
	 *
	 * @return array<int, array<string, mixed>> Areas, each with `key`, `label`, `screen`, `icon` and `counts`.
	 */
	public static function areas() {
		$areas = array(
			'plugin'      => array( __( 'Plugins', 'wp-vip-compatibility' ), 'plugins', 'plug' ),
			'theme'       => array( __( 'Themes', 'wp-vip-compatibility' ), 'themes', 'brush' ),
			'mu-plugin'   => array( __( 'Must-use plugins', 'wp-vip-compatibility' ), 'mu-plugins', 'bolt' ),
			'database'    => array( __( 'Database tables', 'wp-vip-compatibility' ), 'database', 'database' ),
			'directories' => array( __( 'wp-content layout', 'wp-vip-compatibility' ), 'directories', 'folder' ),
		);

		$rows = array();

		foreach ( $areas as $key => $area ) {
			$rows[] = array(
				'key'    => $key,
				'label'  => $area[0],
				'screen' => $area[1],
				'icon'   => $area[2],
				'counts' => self::area_counts( $key ),
			);
		}

		return $rows;
	}

	/**
	 * Describes the environment the site runs in today.
	 *
	 * The overview used to say nothing about the platform underneath the code,
	 * which is half of what "is this ready" means: a site on PHP 7.4 with no
	 * persistent object cache has migration work that no code scan reports.
	 *
	 * @return array<int, array<string, mixed>> Rows, each with `label`, `value`, `hint` and `tone`.
	 */
	public static function environment() {
		$database = Database_Audit::run()['summary'];
		$php_ok   = version_compare( PHP_VERSION, '8.0', '>=' );
		$cache_ok = wp_using_ext_object_cache();

		return array(
			array(
				'label' => __( 'PHP', 'wp-vip-compatibility' ),
				'value' => PHP_VERSION,
				'hint'  => $php_ok
					? __( 'Within the range VIP runs.', 'wp-vip-compatibility' )
					: __( 'VIP runs PHP 8.0 and above. Test on a supported version first.', 'wp-vip-compatibility' ),
				'tone'  => $php_ok ? 'ok' : 'warn',
			),
			array(
				'label' => __( 'WordPress', 'wp-vip-compatibility' ),
				'value' => get_bloginfo( 'version' ),
				'hint'  => __( 'VIP tracks the latest release closely.', 'wp-vip-compatibility' ),
			),
			array(
				'label' => __( 'Object cache', 'wp-vip-compatibility' ),
				'value' => $cache_ok
					? __( 'Persistent', 'wp-vip-compatibility' )
					: __( 'Not persistent', 'wp-vip-compatibility' ),
				'hint'  => $cache_ok
					? __( 'Matches the VIP environment, where the object cache is always persistent.', 'wp-vip-compatibility' )
					: __( 'VIP always has a persistent object cache. Uncached queries behave differently there.', 'wp-vip-compatibility' ),
				'tone'  => $cache_ok ? 'ok' : 'warn',
			),
			array(
				'label' => __( 'Install type', 'wp-vip-compatibility' ),
				'value' => is_multisite()
					? __( 'Multisite', 'wp-vip-compatibility' )
					: __( 'Single site', 'wp-vip-compatibility' ),
				'hint'  => is_multisite()
					? __( 'A multisite migration needs the network layout agreed with VIP up front.', 'wp-vip-compatibility' )
					: '',
			),
			array(
				'label' => __( 'Database size', 'wp-vip-compatibility' ),
				'value' => size_format( (int) $database['bytes'], 1 ),
				'hint'  => sprintf(
					/* translators: %s: Number of tables. */
					_n( '%s table', '%s tables', (int) $database['total'], 'wp-vip-compatibility' ),
					number_format_i18n( (int) $database['total'] )
				),
			),
			array(
				'label' => __( 'Rule set', 'wp-vip-compatibility' ),
				'value' => Rules::VERSION,
				'hint'  => sprintf(
					/* translators: %s: Number of rules. */
					_n( '%s rule, each mapped to a VIP requirement.', '%s rules, each mapped to a VIP requirement.', count( Rules::all() ), 'wp-vip-compatibility' ),
					number_format_i18n( count( Rules::all() ) )
				),
			),
		);
	}

	/**
	 * Returns the outstanding work that did not come from reading code.
	 *
	 * The schema's storage engine, the contents of mu-plugins and the shape of
	 * wp-content are all migration blockers, and none of them is a finding: they
	 * come from three audits that inspect the site rather than its source. That
	 * distinction is invisible on the overview, where they sit in the same plan
	 * under the same tier labels as everything else — so the fix list has to be
	 * able to name them too, or it silently contradicts the plan that sent you
	 * there.
	 *
	 * @return array<int, array<string, mixed>> Actions, worst first.
	 */
	public static function other_work() {
		// Both the plan and the tier counts ask for this within one request, and
		// building it walks wp-content and the schema. Once per request is enough.
		static $work = null;

		if ( null === $work ) {
			$work = self::sort_by_tier( array_merge( self::inventory_actions(), self::site_actions() ) );
		}

		return $work;
	}

	/**
	 * Counts the non-finding work in one tier.
	 *
	 * @param string $tier A tier slug.
	 * @return int How many actions sit in it.
	 */
	public static function other_work_in_tier( $tier ) {
		$count = 0;

		foreach ( self::other_work() as $action ) {
			if ( $action['tier'] === $tier ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Orders actions worst tier first.
	 *
	 * @param array<int, array<string, mixed>> $actions The actions.
	 * @return array<int, array<string, mixed>> The ordered actions.
	 */
	private static function sort_by_tier( array $actions ) {
		$order = array(
			Taxonomy::TIER_BLOCKING  => 0,
			Taxonomy::TIER_IMPORTANT => 1,
			Taxonomy::TIER_WARNING   => 2,
			Taxonomy::TIER_INFO      => 3,
		);

		usort(
			$actions,
			static function ( $a, $b ) use ( $order ) {
				return ( $order[ $a['tier'] ] ?? 9 ) <=> ( $order[ $b['tier'] ] ?? 9 );
			}
		);

		return $actions;
	}


	/**
	 * Builds the actions that come from the plugin, theme and must-use inventory.
	 *
	 * @return array<int, array<string, mixed>> Actions.
	 */
	private static function inventory_actions() {
		$index      = Results_Store::get_index();
		$actions    = array();
		$listed     = 0;
		$mu_to_move = 0;

		foreach ( Targets::all() as $target ) {
			$verdict = self::verdict( $target, $index );

			if ( 'plugin' === $target['type'] && is_array( $verdict['known'] ) && Known_Plugins::INCOMPATIBLE === $verdict['known']['classification'] ) {
				++$listed;
			}

			if ( 'mu-plugin' === $target['type'] ) {
				++$mu_to_move;
			}
		}

		if ( $listed > 0 ) {
			$actions[] = array(
				'tier'   => 'blocking',
				'title'  => sprintf(
					/* translators: %d: Number of plugins. */
					_n( 'Replace %d plugin VIP lists as incompatible', 'Replace %d plugins VIP lists as incompatible', $listed, 'wp-vip-compatibility' ),
					$listed
				),
				'detail' => __( 'WordPress VIP documents these as incompatible with the platform. No code change makes them work.', 'wp-vip-compatibility' ),
				'url'    => add_query_arg( 'status', 'not-compatible', admin_url( 'admin.php?page=wvc-plugins' ) ),
				'action' => __( 'Open plugins', 'wp-vip-compatibility' ),
				'source' => __( 'Plugins', 'wp-vip-compatibility' ),
			);
		}

		if ( $mu_to_move > 0 ) {
			$actions[] = array(
				'tier'   => 'important',
				'title'  => sprintf(
					/* translators: %d: Number of must-use plugins. */
					_n( 'Relocate %d must-use plugin', 'Relocate %d must-use plugins', $mu_to_move, 'wp-vip-compatibility' ),
					$mu_to_move
				),
				'detail' => __( 'VIP reserves wp-content/mu-plugins for platform code. Anything you ship belongs in client-mu-plugins/.', 'wp-vip-compatibility' ),
				'url'    => admin_url( 'admin.php?page=wvc-mu-plugins' ),
				'action' => __( 'Open must-use', 'wp-vip-compatibility' ),
				'source' => __( 'Must-use plugins', 'wp-vip-compatibility' ),
			);
		}

		return $actions;
	}

	/**
	 * Builds the actions that come from the database and wp-content audits.
	 *
	 * @return array<int, array<string, mixed>> Actions.
	 */
	private static function site_actions() {
		$actions  = array();
		$database = Database_Audit::run()['summary'];
		$content  = Directory_Audit::run()['summary'];
		$schema   = (int) $database['issues']['engine'] + (int) $database['issues']['collation'];

		if ( $schema > 0 ) {
			$actions[] = array(
				'tier'   => 'blocking',
				'title'  => sprintf(
					/* translators: %d: Number of tables. */
					_n( 'Convert %d table to InnoDB and utf8mb4', 'Convert %d tables to InnoDB and utf8mb4', $schema, 'wp-vip-compatibility' ),
					$schema
				),
				'detail' => __( 'VIP will not import a database with an unsupported storage engine or collation.', 'wp-vip-compatibility' ),
				'url'    => add_query_arg( 'status', 'not-compatible', admin_url( 'admin.php?page=wvc-database' ) ),
				'action' => __( 'Show the SQL', 'wp-vip-compatibility' ),
				'source' => __( 'Database', 'wp-vip-compatibility' ),
			);
		}

		if ( (int) $database['issues']['prefix'] > 0 ) {
			$actions[] = array(
				'tier'   => 'warning',
				'title'  => sprintf(
					/* translators: %d: Number of tables. */
					_n( 'Report %d non-standard table prefix to VIP', 'Report %d non-standard table prefixes to VIP', (int) $database['issues']['prefix'], 'wp-vip-compatibility' ),
					(int) $database['issues']['prefix']
				),
				'detail' => __( 'The prefix is embedded in option names and user meta keys, so renaming tables without VIP confirming it breaks roles and capabilities.', 'wp-vip-compatibility' ),
				'url'    => admin_url( 'admin.php?page=wvc-database' ),
				'action' => __( 'Open the audit', 'wp-vip-compatibility' ),
				'source' => __( 'Database', 'wp-vip-compatibility' ),
			);
		}

		if ( (int) $content['unsupported'] > 0 ) {
			$actions[] = array(
				'tier'   => 'important',
				'title'  => sprintf(
					/* translators: %d: Number of paths. */
					_n( 'Remove or relocate %d path in wp-content', 'Remove or relocate %d paths in wp-content', (int) $content['unsupported'], 'wp-vip-compatibility' ),
					(int) $content['unsupported']
				),
				'detail' => __( 'These conflict with the VIP application structure, or with drop-ins the platform installs itself.', 'wp-vip-compatibility' ),
				'url'    => add_query_arg( 'status', 'not-compatible', admin_url( 'admin.php?page=wvc-directories' ) ),
				'action' => __( 'Open the audit', 'wp-vip-compatibility' ),
				'source' => __( 'wp-content', 'wp-vip-compatibility' ),
			);
		}

		return $actions;
	}

	/**
	 * Collapses findings that come from the same rule into one entry.
	 *
	 * A rule that fires twenty times in one plugin is one decision with twenty
	 * locations, not twenty decisions. Repeating the whole explanation per hit
	 * is what made the report unreadable on a real codebase.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings for a single target.
	 * @return array<int, array<string, mixed>> Rule groups, worst severity first.
	 */
	public static function group_by_rule( array $findings ) {
		$groups = array();

		foreach ( $findings as $finding ) {
			$key = $finding['rule'];

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array_merge(
					$finding,
					array(
						'occurrences' => array(),
						'also_matched' => array(),
					)
				);
			}

			// The most serious hit sets the tone for the whole group.
			if ( Taxonomy::get_severity_weight( $finding['severity'] ) > Taxonomy::get_severity_weight( $groups[ $key ]['severity'] ) ) {
				$groups[ $key ]['severity']   = $finding['severity'];
				$groups[ $key ]['confidence'] = $finding['confidence'];
			}

			$groups[ $key ]['occurrences'][] = array(
				'file'     => $finding['file'],
				'line'     => $finding['line'],
				'scope'    => $finding['scope'],
				'symbol'   => $finding['symbol'],
				'evidence' => $finding['evidence'],
				'note'     => $finding['note'],
			);

			$groups[ $key ]['also_matched'] = array_values(
				array_unique( array_merge( $groups[ $key ]['also_matched'], (array) ( $finding['also_matched'] ?? array() ) ) )
			);
		}

		$groups = array_values( $groups );

		usort(
			$groups,
			static function ( $a, $b ) {
				$weight = Taxonomy::get_severity_weight( $b['severity'] ) <=> Taxonomy::get_severity_weight( $a['severity'] );

				return ( 0 !== $weight ) ? $weight : ( count( $b['occurrences'] ) <=> count( $a['occurrences'] ) );
			}
		);

		return $groups;
	}

	/**
	 * Builds the flat, ranked list of fixes the findings screen is made of.
	 *
	 * The unit is one rule in one target, because that is the unit of work: the
	 * same rule firing in two plugins is two jobs for two owners, while the same
	 * rule firing twenty times in one plugin is one job with twenty locations.
	 *
	 * The list is then ordered the way it should be worked through — worst first,
	 * and within a tier all of one target's jobs together, so a reader who opens
	 * a plugin's cards is not sent back and forth between plugins.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings from findings().
	 * @return array<int, array<string, mixed>> Rule groups, worst first.
	 */
	public static function fix_list( array $findings ) {
		$list = array();

		foreach ( self::group( $findings, 'target_key' ) as $target_findings ) {
			$list = array_merge( $list, self::group_by_rule( $target_findings ) );
		}

		usort(
			$list,
			static function ( $a, $b ) {
				// Worst tier first.
				$severity = Taxonomy::get_severity_weight( $b['severity'] ) <=> Taxonomy::get_severity_weight( $a['severity'] );

				if ( 0 !== $severity ) {
					return $severity;
				}

				// Then keep one target's work together.
				$target = strcasecmp( (string) $a['target_label'], (string) $b['target_label'] );

				if ( 0 !== $target ) {
					return $target;
				}

				// Then the biggest job in that target first.
				return count( $b['occurrences'] ) <=> count( $a['occurrences'] );
			}
		);

		return $list;
	}

	/**
	 * Returns every stored finding, flattened and enriched with its rule.
	 *
	 * @param array<string, mixed> $filters Optional filters: `type`, `severity`,
	 *                                      `tier`, `category`, `target`, `search`.
	 * @return array<int, array<string, mixed>> The findings.
	 */
	public static function findings( array $filters = array() ) {
		$filters = wp_parse_args(
			$filters,
			array(
				'type'     => '',
				'severity' => '',
				'tier'     => '',
				'category' => '',
				'target'   => '',
				'search'   => '',
			)
		);

		// A tier is a set of severities, so it resolves to the same comparison.
		$tier_severities = ( '' === $filters['tier'] ) ? array() : Taxonomy::get_tier_severities( $filters['tier'] );

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

				if ( ! empty( $tier_severities ) && ! in_array( $finding['severity'], $tier_severities, true ) ) {
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
