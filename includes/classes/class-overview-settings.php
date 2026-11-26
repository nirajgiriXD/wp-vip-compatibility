<?php
/**
 * The overview screen.
 *
 * The overview answers, in this order and nothing else: how ready is this site,
 * how much work is left, what to do first, where the work is, and what the
 * platform underneath it looks like. Each answer is one ruled section, and each
 * links into the screen that owns the detail.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Database_Audit;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Directory_Audit;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Rules;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Scanner;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the overview.
 */
class Overview_Settings {

	use Singleton;

	/**
	 * How many entries the "start here" list shows.
	 */
	const NEXT_LIMIT = 3;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$aggregate = Report::aggregate();
		$scanned   = $aggregate['targets'] > 0;

		echo '<div class="wvc-overview">';

		$this->render_verdict( $aggregate, $scanned );

		if ( $scanned ) {
			UI::render_work_ledger( $this->get_facts( $aggregate ) );
			$this->render_next();
			$this->render_areas();
		} else {
			$this->render_getting_started();
		}

		$this->render_environment();

		if ( $scanned ) {
			$this->render_history();
		}

		$this->render_about();

		echo '</div>';
	}

	/**
	 * Explains the loop, on a site that has never been scanned.
	 *
	 * @return void
	 */
	private function render_getting_started() {
		UI::render_panel_open(
			array(
				'title'   => __( 'How this works', 'wp-vip-compatibility' ),
				'summary' => __( 'Three steps, repeated until the list is empty.', 'wp-vip-compatibility' ),
			)
		);

		UI::render_steps(
			array(
				array(
					'title' => __( 'Scan', 'wp-vip-compatibility' ),
					'body'  => __( 'Every plugin, theme and must-use plugin is read against the WordPress VIP Platform requirements, along with the database schema and the wp-content layout. The code is read, never run, so this is safe on a live site.', 'wp-vip-compatibility' ),
				),
				array(
					'title' => __( 'Work through the findings', 'wp-vip-compatibility' ),
					'body'  => __( 'Findings are ranked, must-fix first. Each one names the file and line, says why it matters on WordPress VIP specifically, and gives you the change to make.', 'wp-vip-compatibility' ),
				),
				array(
					'title' => __( 'Rescan', 'wp-vip-compatibility' ),
					'body'  => __( 'Run it again to see the list shrink and the readiness score climb. Export it as JSON, CSV or Markdown whenever the work needs to move into a ticket or a pull request.', 'wp-vip-compatibility' ),
				),
			)
		);

		UI::render_panel_close();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Verdict
	 * ------------------------------------------------------------------
	 */

	/**
	 * Renders the verdict.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @param bool                 $scanned   Whether anything has been scanned.
	 * @return void
	 */
	private function render_verdict( array $aggregate, $scanned ) {
		$verdict = $this->resolve_verdict( $aggregate, $scanned );

		UI::render_verdict(
			array(
				'score'    => $scanned ? (int) $aggregate['score'] : -1,
				'tier'     => $verdict['tier'],
				'headline' => $verdict['headline'],
				'summary'  => $verdict['summary'],
				'meta'     => $this->scan_meta( $aggregate, $scanned ),
				'actions'  => $verdict['actions'],
			)
		);
	}

	/**
	 * Builds the four counted tiers of outstanding work.
	 *
	 * They count everything outstanding, not just the findings: the database,
	 * must-use and wp-content audits produce migration work that has no file and
	 * line, and it appears in the findings list under these same four labels.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @return array<int, array<string, mixed>> Facts.
	 */
	private function get_facts( array $aggregate ) {
		$facts = array();

		foreach ( UI::get_tiers() as $tier => $definition ) {
			$count = Report::other_work_in_tier( $tier );

			foreach ( $definition['severities'] as $severity ) {
				$count += (int) ( $aggregate['by_severity'][ $severity ] ?? 0 );
			}

			$facts[] = array(
				'tier'    => $tier,
				'label'   => $definition['label'],
				'value'   => number_format_i18n( $count ),
				'summary' => $definition['summary'],
				'url'     => ( 0 === $count ) ? '' : UI::get_findings_url( '', $tier ),
			);
		}

		return $facts;
	}

	/**
	 * Resolves the headline verdict, its tone and its calls to action.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @param bool                 $scanned   Whether anything has been scanned.
	 * @return array<string, mixed> The verdict.
	 */
	private function resolve_verdict( array $aggregate, $scanned ) {
		$rescan = array(
			'label'   => __( 'Rescan everything', 'wp-vip-compatibility' ),
			'submit'  => 'wvc_rescan',
			'nonce'   => Findings_Settings::RESCAN_ACTION,
			'icon'    => 'refresh',
			'primary' => false,
		);

		if ( ! $scanned ) {
			return array(
				'tier'     => 'ready',
				'headline' => __( 'Nothing has been scanned yet', 'wp-vip-compatibility' ),
				'summary'  => __( 'Run a scan to check every plugin, theme and must-use plugin against the WordPress VIP Platform requirements. It reads code without running it, so it is safe to run on a live site.', 'wp-vip-compatibility' ),
				'actions'  => array(
					array_merge(
						$rescan,
						array(
							'label'   => __( 'Run the first scan', 'wp-vip-compatibility' ),
							'primary' => true,
						)
					),
				),
			);
		}

		$blocked = (int) $aggregate['statuses'][ Scanner::STATUS_BLOCKED ];
		$review  = (int) $aggregate['statuses'][ Scanner::STATUS_REVIEW ];
		$total   = (int) $aggregate['targets'];

		if ( $blocked > 0 ) {
			return array(
				'tier'     => Taxonomy::TIER_BLOCKING,
				'headline' => __( 'Not ready to migrate', 'wp-vip-compatibility' ),
				'summary'  => sprintf(
					/* translators: 1: Number of blocked targets. 2: Total number of targets. */
					_n(
						'%1$d of %2$d scanned items is expected to fail on the WordPress VIP Platform and has to be resolved first.',
						'%1$d of %2$d scanned items are expected to fail on the WordPress VIP Platform and have to be resolved first.',
						$blocked,
						'wp-vip-compatibility'
					),
					$blocked,
					$total
				),
				'actions'  => array(
					array(
						'label'   => __( 'Start with the blockers', 'wp-vip-compatibility' ),
						'url'     => UI::get_findings_url( '', Taxonomy::TIER_BLOCKING ),
						'icon'    => 'arrow-right',
						'primary' => true,
					),
					$rescan,
				),
			);
		}

		if ( $review > 0 ) {
			return array(
				'tier'     => Taxonomy::TIER_WARNING,
				'headline' => __( 'Nothing will fail outright', 'wp-vip-compatibility' ),
				'summary'  => sprintf(
					/* translators: %d: Number of targets needing review. */
					_n(
						'%d scanned item needs a decision before you migrate, but none is expected to break on the platform.',
						'%d scanned items need a decision before you migrate, but none is expected to break on the platform.',
						$review,
						'wp-vip-compatibility'
					),
					$review
				),
				'actions'  => array(
					array(
						'label'   => __( 'Review the findings', 'wp-vip-compatibility' ),
						'url'     => UI::get_findings_url(),
						'icon'    => 'arrow-right',
						'primary' => true,
					),
					$rescan,
				),
			);
		}

		return array(
			'tier'     => 'ready',
			'headline' => __( 'Ready to migrate', 'wp-vip-compatibility' ),
			'summary'  => __( 'Every scanned plugin, theme and must-use plugin passed. Check the database and wp-content audits before you export.', 'wp-vip-compatibility' ),
			'actions'  => array(
				array(
					'label'   => __( 'Check the database', 'wp-vip-compatibility' ),
					'url'     => UI::get_screen_url( 'database' ),
					'icon'    => 'database',
					'primary' => true,
				),
				$rescan,
			),
		);
	}

	/**
	 * Builds the single supporting line: how much was read.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @param bool                 $scanned   Whether anything has been scanned.
	 * @return string The line.
	 */
	private function scan_meta( array $aggregate, $scanned ) {
		if ( ! $scanned || $aggregate['scanned_at'] <= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: 1: What was scanned, e.g. "3 plugins, 1 theme and 1 must-use plugin". 2: Number of PHP files. */
			__( '%1$s scanned across %2$s PHP files', 'wp-vip-compatibility' ),
			$this->scanned_kinds( $aggregate ),
			number_format_i18n( (int) $aggregate['totals']['files'] )
		);
	}

	/**
	 * Names what was scanned, counted by kind: "3 plugins, 1 theme and 1 must-use plugin".
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @return string The list, or an empty string when nothing was scanned.
	 */
	private function scanned_kinds( array $aggregate ) {
		$items = array();

		foreach ( array( 'plugin', 'theme', 'mu-plugin' ) as $kind ) {
			$count = (int) array_sum( $aggregate['by_kind'][ $kind ] ?? array() );

			if ( $count <= 0 ) {
				continue;
			}

			$number = number_format_i18n( $count );

			switch ( $kind ) {
				case 'theme':
					/* translators: %s: Number of themes. */
					$items[] = sprintf( _n( '%s theme', '%s themes', $count, 'wp-vip-compatibility' ), $number );
					break;

				case 'mu-plugin':
					/* translators: %s: Number of must-use plugins. */
					$items[] = sprintf( _n( '%s must-use plugin', '%s must-use plugins', $count, 'wp-vip-compatibility' ), $number );
					break;

				default:
					/* translators: %s: Number of plugins. */
					$items[] = sprintf( _n( '%s plugin', '%s plugins', $count, 'wp-vip-compatibility' ), $number );
			}
		}

		return wp_sprintf( '%l', $items );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Start here
	 * ------------------------------------------------------------------
	 */

	/**
	 * Renders the first few things to do, taken from the top of the fix list.
	 *
	 * The ledger says how much there is; this says where to begin. It is the
	 * findings list's own ranking cut short, so the order here can never
	 * disagree with the order there.
	 *
	 * @return void
	 */
	private function render_next() {
		$tasks = Report::other_work();
		$fixes = Report::fix_list( Report::findings() );
		$queue = array();

		foreach ( array( Taxonomy::TIER_BLOCKING, Taxonomy::TIER_IMPORTANT ) as $tier ) {
			foreach ( $tasks as $task ) {
				if ( $task['tier'] === $tier ) {
					$queue[] = array(
						'tier'  => $tier,
						'title' => $task['title'],
						'where' => $task['source'] ?? '',
						'url'   => $task['url'],
					);
				}
			}

			foreach ( $fixes as $fix ) {
				if ( UI::get_tier( $fix['severity'] ) === $tier ) {
					$count   = count( $fix['occurrences'] );
					$queue[] = array(
						'tier'  => $tier,
						'title' => $fix['title'],
						'where' => $fix['target_label'] . ' · ' . ( ( 1 === $count )
							? $fix['occurrences'][0]['file'] . ':' . $fix['occurrences'][0]['line']
							/* translators: %d: Number of places in the code. */
							: sprintf( _n( '%d place', '%d places', $count, 'wp-vip-compatibility' ), $count ) ),
						'url'   => UI::get_findings_url( $fix['target_key'], $tier ),
					);
				}
			}
		}

		if ( empty( $queue ) ) {
			return;
		}

		$total = count( $queue );
		$queue = array_slice( $queue, 0, self::NEXT_LIMIT );

		UI::render_panel_open(
			array(
				'title'   => __( 'Start here', 'wp-vip-compatibility' ),
				'summary' => __( 'The top of the fix list: the work most likely to hold up the migration.', 'wp-vip-compatibility' ),
			)
		);
		?>
		<ol class="wvc-next">
			<?php foreach ( $queue as $position => $item ) : ?>
				<li class="wvc-next__item wvc-next__item--<?php echo esc_attr( $item['tier'] ); ?>">
					<a class="wvc-next__link" href="<?php echo esc_url( $item['url'] ); ?>">
						<span class="wvc-next__rank" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $position + 1 ) ); ?></span>
						<span class="wvc-next__tier"><?php echo UI::get_tier_pill( $item['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?></span>
						<span class="wvc-next__text">
							<span class="wvc-next__title"><?php echo esc_html( $item['title'] ); ?></span>
							<?php if ( '' !== $item['where'] ) : ?>
								<span class="wvc-next__where"><?php echo esc_html( $item['where'] ); ?></span>
							<?php endif; ?>
						</span>
						<?php echo UI::get_icon( 'arrow-right', array( 'class' => 'wvc-icon wvc-icon--sm wvc-next__arrow' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php if ( $total > self::NEXT_LIMIT ) : ?>
			<p class="wvc-next__more">
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				echo UI::get_arrow_link(
					UI::get_findings_url(),
					sprintf(
						/* translators: %s: Number of further items. */
						_n( '%s more to fix or check', '%s more to fix or check', $total - self::NEXT_LIMIT, 'wp-vip-compatibility' ),
						number_format_i18n( $total - self::NEXT_LIMIT )
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</p>
		<?php endif; ?>
		<?php
		UI::render_panel_close();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Where the work is
	 * ------------------------------------------------------------------
	 */

	/**
	 * Renders the manifest: every item on the site, by area and verdict.
	 *
	 * @return void
	 */
	private function render_areas() {
		$areas = Report::areas();

		foreach ( $areas as $position => $area ) {
			$areas[ $position ]['items'] = $this->get_area_items( $area['key'] );
		}

		UI::render_panel_open(
			array(
				'title'   => __( 'Where the work is', 'wp-vip-compatibility' ),
				'summary' => __( 'One square for every plugin, theme, table and path on the site, worst first. Hover a square to see which one it is.', 'wp-vip-compatibility' ),
			)
		);

		UI::render_manifest( $areas );

		UI::render_panel_close();
	}

	/**
	 * Lists the items in one area with the verdict bucket each falls into.
	 *
	 * @param string $area One of `plugin`, `theme`, `mu-plugin`, `database`, `directories`.
	 * @return array<int, array<string, string>> Items with `name` and `bucket`, worst first.
	 */
	private function get_area_items( $area ) {
		$items = array();

		if ( 'database' === $area ) {
			foreach ( Database_Audit::run()['tables'] as $table ) {
				$items[] = array(
					'name'   => $table['name'],
					'bucket' => empty( $table['findings'] ) ? 'ready' : 'blocked',
				);
			}
		} elseif ( 'directories' === $area ) {
			$map = array(
				Directory_Audit::STATUS_UNSUPPORTED   => 'blocked',
				Directory_Audit::STATUS_REVIEW        => 'review',
				Directory_Audit::STATUS_SUPPORTED     => 'ready',
				Directory_Audit::STATUS_INFORMATIONAL => 'ready',
			);

			foreach ( Directory_Audit::run()['entries'] as $entry ) {
				$items[] = array(
					'name'   => $entry['name'] . ( $entry['is_dir'] ? '/' : '' ),
					'bucket' => $map[ $entry['status'] ] ?? 'review',
				);
			}
		} else {
			$index = Results_Store::get_index();
			$map   = array(
				Scanner::STATUS_PASS    => 'ready',
				Scanner::STATUS_REVIEW  => 'review',
				Scanner::STATUS_BLOCKED => 'blocked',
			);

			foreach ( Targets::of_type( $area ) as $target ) {
				$verdict = Report::verdict( $target, $index );

				$items[] = array(
					'name'   => $target['label'],
					'bucket' => ( 'pending' === $verdict['state'] ) ? 'pending' : ( $map[ $verdict['status'] ] ?? 'review' ),
				);
			}
		}

		$order = array_flip( array_keys( UI::get_buckets() ) );

		usort(
			$items,
			static function ( $a, $b ) use ( $order ) {
				$rank = ( $order[ $a['bucket'] ] ?? 9 ) <=> ( $order[ $b['bucket'] ] ?? 9 );

				return ( 0 !== $rank ) ? $rank : strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $items;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Environment, history and about
	 * ------------------------------------------------------------------
	 */

	/**
	 * Renders the environment as a specification sheet.
	 *
	 * @return void
	 */
	private function render_environment() {
		UI::render_panel_open(
			array(
				'title'   => __( 'Environment', 'wp-vip-compatibility' ),
				'summary' => __( 'What this site runs on today, measured against what it will run on at WordPress VIP. None of this is visible to a code scan.', 'wp-vip-compatibility' ),
			)
		);

		echo UI::get_defs( Report::environment(), 'wvc-defs--spec' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		UI::render_panel_close();
	}

	/**
	 * Renders the scan history: the trend, then the log.
	 *
	 * @return void
	 */
	private function render_history() {
		$history = Results_Store::get_history();

		if ( count( $history ) < 2 ) {
			return;
		}

		$recent = array_slice( $history, -12 );
		$scores = array();

		foreach ( $recent as $snapshot ) {
			$scores[] = (float) $snapshot['score'];
		}

		$first = reset( $recent );
		$last  = end( $recent );
		$shift = (int) $last['score'] - (int) $first['score'];

		UI::render_panel_open(
			array(
				'title'   => __( 'Recent scans', 'wp-vip-compatibility' ),
				'summary' => __( 'How the report has moved over the last few scans.', 'wp-vip-compatibility' ),
			)
		);
		?>
		<div class="wvc-trend">
			<div class="wvc-trend__chart">
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				echo UI::get_sparkline(
					$scores,
					100,
					sprintf(
						/* translators: 1: Number of scans. 2: First score. 3: Latest score. */
						__( 'Readiness over the last %1$d scans, from %2$d%% to %3$d%%.', 'wp-vip-compatibility' ),
						count( $scores ),
						(int) $first['score'],
						(int) $last['score']
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
			<p class="wvc-trend__text">
				<span class="wvc-trend__value"><?php echo esc_html( sprintf( '%d%%', (int) $last['score'] ) ); ?></span>
				<span class="wvc-trend__delta wvc-trend__delta--<?php echo esc_attr( $shift > 0 ? 'up' : ( $shift < 0 ? 'down' : 'flat' ) ); ?>">
					<?php
					if ( 0 === $shift ) {
						esc_html_e( 'No change in readiness', 'wp-vip-compatibility' );
					} else {
						printf(
							/* translators: 1: Signed change in points, e.g. "+12". 2: Number of scans. */
							esc_html__( '%1$s points over %2$d scans', 'wp-vip-compatibility' ),
							esc_html( sprintf( '%+d', $shift ) ),
							count( $recent )
						);
					}
					?>
				</span>
			</p>
		</div>
		<?php
		$rows = array_reverse( array_slice( $history, -8 ) );

		echo '<div class="wvc-table-wrap wvc-sheet">';
		echo '<table class="wvc-table wvc-table--compact">';

		UI::render_table_head(
			array(
				array(
					'label' => __( 'When', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-name',
				),
				array(
					'label' => __( 'Readiness', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-number',
				),
				array(
					'label' => __( 'Findings', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-number',
				),
				array(
					'label' => __( 'Must fix', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-number',
				),
				array(
					'label' => __( 'Blocked items', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-number',
				),
				array(
					'label' => __( 'Change', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-number',
				),
			)
		);

		echo '<tbody>';

		foreach ( $rows as $position => $snapshot ) {
			$previous = $rows[ $position + 1 ] ?? null;
			$delta    = ( null === $previous ) ? null : (int) $snapshot['total'] - (int) $previous['total'];

			echo '<tr class="wvc-row">';

			echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'When', 'wp-vip-compatibility' ) . '">'
				. esc_html(
					sprintf(
						/* translators: %s: Human-readable time difference. */
						__( '%s ago', 'wp-vip-compatibility' ),
						human_time_diff( (int) $snapshot['recorded_at'] )
					)
				)
				. '</td>';

			echo '<td class="wvc-col-number" data-label="' . esc_attr__( 'Readiness', 'wp-vip-compatibility' ) . '">'
				. esc_html( sprintf( '%d%%', (int) $snapshot['score'] ) )
				. '</td>';

			echo '<td class="wvc-col-number" data-label="' . esc_attr__( 'Findings', 'wp-vip-compatibility' ) . '">'
				. esc_html( number_format_i18n( (int) $snapshot['total'] ) )
				. '</td>';

			echo '<td class="wvc-col-number" data-label="' . esc_attr__( 'Must fix', 'wp-vip-compatibility' ) . '">'
				. esc_html( number_format_i18n( (int) $snapshot['blocking'] ) )
				. '</td>';

			echo '<td class="wvc-col-number" data-label="' . esc_attr__( 'Blocked items', 'wp-vip-compatibility' ) . '">'
				. esc_html( number_format_i18n( (int) $snapshot['blocked'] ) )
				. '</td>';

			echo '<td class="wvc-col-number" data-label="' . esc_attr__( 'Change', 'wp-vip-compatibility' ) . '">';

			if ( null === $delta || 0 === $delta ) {
				echo '<span class="wvc-dash" aria-hidden="true">—</span>';
			} else {
				// Fewer findings is the good direction, so a negative delta is "down" and green.
				printf(
					'<span class="wvc-delta wvc-delta--%1$s">%2$s</span>',
					esc_attr( $delta < 0 ? 'down' : 'up' ),
					esc_html( sprintf( '%+d', $delta ) )
				);
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		UI::render_panel_close();
	}

	/**
	 * Renders what the plugin checks and what its answer is worth.
	 *
	 * What the scan covers, and the fact that a clean report is not a guarantee,
	 * are the two things that qualify every number above it.
	 *
	 * @return void
	 */
	private function render_about() {
		$checks = array(
			array(
				'label' => __( 'Filesystem and media', 'wp-vip-compatibility' ),
				'value' => __( 'Writes outside /tmp/ and uploads, traversal over the object store, generated PHP/CSS/JS, .htaccess assumptions and local image processing.', 'wp-vip-compatibility' ),
			),
			array(
				'label' => __( 'Database', 'wp-vip-compatibility' ),
				'value' => __( 'Storage engines, collations and prefixes, plus unprepared SQL, uncached queries, unbounded result sets and runtime schema changes.', 'wp-vip-compatibility' ),
			),
			array(
				'label' => __( 'Caching, cron and requests', 'wp-vip-compatibility' ),
				'value' => __( 'Cache-busting headers, full object-cache flushes, custom cache layers, Cron Control conflicts and uncached or untimed outbound requests.', 'wp-vip-compatibility' ),
			),
			array(
				'label' => __( 'Security and environment', 'wp-vip-compatibility' ),
				'value' => __( 'Shell execution, dynamic code, unescaped request data, PHP sessions, runtime ini changes and redefined core constants.', 'wp-vip-compatibility' ),
			),
			array(
				'label' => __( 'Platform overlap', 'wp-vip-compatibility' ),
				'value' => __( 'Plugins WordPress VIP lists as incompatible, plugins that need testing, and plugins duplicating something the platform already provides.', 'wp-vip-compatibility' ),
			),
		);

		UI::render_panel_open(
			array(
				'title'    => __( 'About this report', 'wp-vip-compatibility' ),
				'summary'  => sprintf(
					/* translators: 1: Rule set version. 2: Number of rules. */
					__( 'Rule set %1$s — %2$d rules, each mapped to the WordPress VIP requirement it comes from.', 'wp-vip-compatibility' ),
					Rules::VERSION,
					count( Rules::all() )
				),
				'modifier' => 'wvc-section--quiet',
			)
		);
		?>
		<div class="wvc-about">
			<div class="wvc-about__prose">
				<p><?php esc_html_e( 'This plugin analyses a standard WordPress site against the requirements of the WordPress VIP Platform, so that the work needed to migrate is known before the migration starts rather than discovered during it.', 'wp-vip-compatibility' ); ?></p>
				<p><?php esc_html_e( 'It is a starting point, not a certificate. It reads code without running it, so it cannot see behaviour that only appears under real traffic or real data. Where static analysis cannot resolve a value, a finding records its confidence rather than asserting an incompatibility it cannot prove.', 'wp-vip-compatibility' ); ?></p>
			</div>

			<div class="wvc-about__checks">
				<h3 class="wvc-about__title"><?php esc_html_e( 'What gets checked', 'wp-vip-compatibility' ); ?></h3>
				<ol class="wvc-checks">
					<?php foreach ( $checks as $position => $check ) : ?>
						<li class="wvc-checks__item">
							<span class="wvc-checks__rank" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $position + 1 ) ); ?></span>
							<strong class="wvc-checks__label"><?php echo esc_html( $check['label'] ); ?></strong>
							<span class="wvc-checks__text"><?php echo esc_html( $check['value'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>
		</div>
		<?php
		UI::render_panel_close();
	}
}
