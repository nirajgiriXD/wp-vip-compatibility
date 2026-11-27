<?php
/**
 * The overview screen.
 *
 * The overview answers, in this order and nothing else: how ready is this site
 * and how much work is left, what to do first, where the work is, and what the
 * platform underneath it looks like. Each answer is one card, and each links
 * into the screen that owns the detail. What the report is and how to read it
 * lives in the Help panel rather than on the page.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Includes\Classes;

use SWITCHRAIL_AUDIT\Includes\Traits\Singleton;
use SWITCHRAIL_AUDIT\Includes\Scanner\Database_Audit;
use SWITCHRAIL_AUDIT\Includes\Scanner\Directory_Audit;
use SWITCHRAIL_AUDIT\Includes\Scanner\Report;
use SWITCHRAIL_AUDIT\Includes\Scanner\Results_Store;
use SWITCHRAIL_AUDIT\Includes\Scanner\Rules;
use SWITCHRAIL_AUDIT\Includes\Scanner\Scanner;
use SWITCHRAIL_AUDIT\Includes\Scanner\Targets;
use SWITCHRAIL_AUDIT\Includes\Scanner\Taxonomy;

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

		echo '<div class="switchrail-audit-overview">';

		$this->render_verdict( $aggregate, $scanned );

		if ( $scanned ) {
			$this->render_next();
			$this->render_areas();
		} else {
			$this->render_getting_started();
		}

		// Reference rather than work, so the two sit side by side below it.
		echo '<div class="switchrail-audit-overview__pair">';
		$this->render_environment( $aggregate, $scanned );

		if ( $scanned ) {
			$this->render_history();
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Returns the notes shown in the WordPress Help tab.
	 *
	 * @return array<int, string> Note paragraphs, already containing limited HTML.
	 */
	public function get_page_guidance() {
		return array(
			__( '<strong>The score</strong> comes from the verdict on every scanned plugin, theme and must-use plugin: a passing item counts in full, one that needs review counts for a little over half, and one that needs work does not count yet.', 'switchrail-audit' ),
			__( '<strong>Where the work is</strong> has one square for every plugin, theme, table and path on the site, worst first. Hover a square to see which one it is.', 'switchrail-audit' ),
			__( '<strong>Environment</strong> is what this site runs on today, compared with the WordPress VIP platform, plus how much the last scan read.', 'switchrail-audit' ),
			__( '<strong>A starting point, not a certificate.</strong> The scan reads code without running it, so it cannot see behaviour that only appears under real traffic or real data. Where it cannot resolve a value, a finding records its confidence rather than asserting an incompatibility it cannot prove.', 'switchrail-audit' ),
			sprintf(
				/* translators: 1: Rule set version. 2: Number of rules. */
				__( '<strong>What gets checked.</strong> The filesystem and media, the database, caching, cron and outbound requests, security and the environment, and plugins that overlap with what the platform provides. Rule set %1$s has %2$d rules, each mapped to the WordPress VIP guideline it comes from.', 'switchrail-audit' ),
				Rules::VERSION,
				count( Rules::all() )
			),
		);
	}

	/**
	 * Explains the loop, on a site that has never been scanned.
	 *
	 * @return void
	 */
	private function render_getting_started() {
		UI::render_card_open(
			array(
				'title' => __( 'How this works', 'switchrail-audit' ),
				'aside' => '<span class="switchrail-audit-card__note">' . esc_html__( 'Three steps, repeated until the list is empty.', 'switchrail-audit' ) . '</span>',
			)
		);

		UI::render_steps(
			array(
				array(
					'title' => __( 'Scan', 'switchrail-audit' ),
					'body'  => __( 'Every plugin, theme and must-use plugin is read against the WordPress VIP platform guidelines, along with the database schema and the wp-content layout. The code is read, never run, so this is safe on a live site.', 'switchrail-audit' ),
				),
				array(
					'title' => __( 'Work through the findings', 'switchrail-audit' ),
					'body'  => __( 'Findings are ranked, must-fix first. Each one names the file and line, says why it matters on WordPress VIP specifically, and gives you the change to make.', 'switchrail-audit' ),
				),
				array(
					'title' => __( 'Rescan', 'switchrail-audit' ),
					'body'  => __( 'Run it again to see the list shrink and the score climb. Export it as JSON, CSV or Markdown whenever the work needs to move into a ticket or a pull request.', 'switchrail-audit' ),
				),
			)
		);

		UI::render_card_close();
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
				'actions'  => $verdict['actions'],
				'tiers'    => $scanned ? $this->get_facts( $aggregate ) : array(),
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
			'label'   => __( 'Rescan everything', 'switchrail-audit' ),
			'submit'  => 'switchrail_audit_rescan',
			'nonce'   => Findings_Settings::RESCAN_ACTION,
			'icon'    => 'refresh',
			'primary' => false,
			'confirm' => true,
		);

		if ( ! $scanned ) {
			return array(
				'tier'     => 'ready',
				'headline' => __( 'Nothing has been scanned yet', 'switchrail-audit' ),
				'summary'  => __( 'Run a scan to check every plugin, theme and must-use plugin against the WordPress VIP platform guidelines. It reads code without running it, so it is safe to run on a live site.', 'switchrail-audit' ),
				'actions'  => array(
					array_merge(
						$rescan,
						array(
							'label'   => __( 'Run the first scan', 'switchrail-audit' ),
							'primary' => true,
							'confirm' => false,
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
				'headline' => __( 'A few things need attention first', 'switchrail-audit' ),
				'summary'  => sprintf(
					/* translators: 1: Number of blocked targets. 2: Total number of targets. */
					_n(
						'%1$d of %2$d scanned items has issues likely to cause problems on the WordPress VIP platform. They are worth fixing first.',
						'%1$d of %2$d scanned items have issues likely to cause problems on the WordPress VIP platform. They are worth fixing first.',
						$blocked,
						'switchrail-audit'
					),
					$blocked,
					$total
				),
				'actions'  => array(
					array(
						'label'   => __( 'Start with the must-fix items', 'switchrail-audit' ),
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
				'headline' => __( 'In good shape, with a few things to review', 'switchrail-audit' ),
				'summary'  => sprintf(
					/* translators: %d: Number of targets needing review. */
					_n(
						'%d scanned item has findings worth a look. None of them is likely to cause problems on the platform.',
						'%d scanned items have findings worth a look. None of them is likely to cause problems on the platform.',
						$review,
						'switchrail-audit'
					),
					$review
				),
				'actions'  => array(
					array(
						'label'   => __( 'Review the findings', 'switchrail-audit' ),
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
			'headline' => __( 'Everything looks good', 'switchrail-audit' ),
			'summary'  => __( 'Every scanned plugin, theme and must-use plugin passed. The database and wp-content checks are worth a look too.', 'switchrail-audit' ),
			'actions'  => array(
				array(
					'label'   => __( 'Check the database', 'switchrail-audit' ),
					'url'     => UI::get_screen_url( 'database' ),
					'icon'    => 'database',
					'primary' => true,
				),
				$rescan,
			),
		);
	}

	/**
	 * Builds the environment row that says how much was read.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @param bool                 $scanned   Whether anything has been scanned.
	 * @return array<string, string>|null The row, or null before the first scan.
	 */
	private function scan_row( array $aggregate, $scanned ) {
		if ( ! $scanned || $aggregate['scanned_at'] <= 0 ) {
			return null;
		}

		return array(
			'label' => __( 'Scanned', 'switchrail-audit' ),
			'value' => $this->scanned_kinds( $aggregate ),
			'hint'  => sprintf(
				/* translators: %s: Number of PHP files. */
				_n( '%s PHP file read.', '%s PHP files read.', (int) $aggregate['totals']['files'], 'switchrail-audit' ),
				number_format_i18n( (int) $aggregate['totals']['files'] )
			),
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
					$items[] = sprintf( _n( '%s theme', '%s themes', $count, 'switchrail-audit' ), $number );
					break;

				case 'mu-plugin':
					/* translators: %s: Number of must-use plugins. */
					$items[] = sprintf( _n( '%s must-use plugin', '%s must-use plugins', $count, 'switchrail-audit' ), $number );
					break;

				default:
					/* translators: %s: Number of plugins. */
					$items[] = sprintf( _n( '%s plugin', '%s plugins', $count, 'switchrail-audit' ), $number );
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
							: sprintf( _n( '%d place', '%d places', $count, 'switchrail-audit' ), $count ) ),
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

		UI::render_card_open(
			array(
				'title' => __( 'Start here', 'switchrail-audit' ),
				'aside' => UI::get_arrow_link(
					UI::get_findings_url(),
					$total > self::NEXT_LIMIT
						? sprintf(
							/* translators: %s: Number of further items. */
							_n( '%s more in Findings', '%s more in Findings', $total - self::NEXT_LIMIT, 'switchrail-audit' ),
							number_format_i18n( $total - self::NEXT_LIMIT )
						)
						: __( 'Open Findings', 'switchrail-audit' )
				),
				'flush' => true,
			)
		);
		?>
		<ol class="switchrail-audit-next">
			<?php foreach ( $queue as $position => $item ) : ?>
				<li class="switchrail-audit-next__item switchrail-audit-next__item--<?php echo esc_attr( $item['tier'] ); ?>">
					<a class="switchrail-audit-next__link" href="<?php echo esc_url( $item['url'] ); ?>">
						<span class="switchrail-audit-next__rank" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $position + 1 ) ); ?></span>
						<span class="switchrail-audit-next__tier"><?php echo UI::get_tier_pill( $item['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?></span>
						<span class="switchrail-audit-next__text">
							<span class="switchrail-audit-next__title"><?php echo esc_html( $item['title'] ); ?></span>
							<?php if ( '' !== $item['where'] ) : ?>
								<span class="switchrail-audit-next__where"><?php echo esc_html( $item['where'] ); ?></span>
							<?php endif; ?>
						</span>
						<?php echo UI::get_icon( 'arrow-right', array( 'class' => 'switchrail-audit-icon switchrail-audit-icon--sm switchrail-audit-next__arrow' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		UI::render_card_close();
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

		UI::render_card_open(
			array(
				'title' => __( 'Where the work is', 'switchrail-audit' ),
				'aside' => UI::get_manifest_legend(),
				'flush' => true,
			)
		);

		UI::render_manifest( $areas );

		UI::render_card_close();
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
	 * What the last scan read sits here rather than under the verdict: it is
	 * reference, not something to act on.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @param bool                 $scanned   Whether anything has been scanned.
	 * @return void
	 */
	private function render_environment( array $aggregate, $scanned ) {
		$rows = Report::environment();
		$scan = $this->scan_row( $aggregate, $scanned );

		if ( null !== $scan ) {
			$rows[] = $scan;
		}

		UI::render_card_open(
			array(
				'title' => __( 'Environment', 'switchrail-audit' ),
				'flush' => true,
			)
		);

		echo UI::get_defs( $rows, 'switchrail-audit-defs--spec' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		UI::render_card_close();
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

		UI::render_card_open(
			array(
				'title' => __( 'Recent scans', 'switchrail-audit' ),
				'flush' => true,
			)
		);
		?>
		<div class="switchrail-audit-trend">
			<div class="switchrail-audit-trend__chart">
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				echo UI::get_sparkline(
					$scores,
					100,
					sprintf(
						/* translators: 1: Number of scans. 2: First score. 3: Latest score. */
						__( 'Score over the last %1$d scans, from %2$d%% to %3$d%%.', 'switchrail-audit' ),
						count( $scores ),
						(int) $first['score'],
						(int) $last['score']
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
			<p class="switchrail-audit-trend__text">
				<span class="switchrail-audit-trend__value"><?php echo esc_html( sprintf( '%d%%', (int) $last['score'] ) ); ?></span>
				<span class="switchrail-audit-trend__delta switchrail-audit-trend__delta--<?php echo esc_attr( $shift > 0 ? 'up' : ( $shift < 0 ? 'down' : 'flat' ) ); ?>">
					<?php
					if ( 0 === $shift ) {
						esc_html_e( 'No change in score', 'switchrail-audit' );
					} else {
						printf(
							/* translators: 1: Signed change in points, e.g. "+12". 2: Number of scans. */
							esc_html__( '%1$s points over %2$d scans', 'switchrail-audit' ),
							esc_html( sprintf( '%+d', $shift ) ),
							count( $recent )
						);
					}
					?>
				</span>
			</p>
		</div>
		<?php
		$rows = array_reverse( array_slice( $history, -5 ) );

		echo '<div class="switchrail-audit-table-wrap">';
		echo '<table class="switchrail-audit-table switchrail-audit-table--compact">';

		UI::render_table_head(
			array(
				array(
					'label' => __( 'When', 'switchrail-audit' ),
					'class' => 'switchrail-audit-col-name',
				),
				array(
					'label' => __( 'Scanned', 'switchrail-audit' ),
				),
				array(
					'label' => __( 'Score', 'switchrail-audit' ),
					'class' => 'switchrail-audit-col-number',
				),
				array(
					'label' => __( 'Findings', 'switchrail-audit' ),
					'class' => 'switchrail-audit-col-number',
				),
				array(
					'label' => __( 'Change', 'switchrail-audit' ),
					'class' => 'switchrail-audit-col-number',
				),
			)
		);

		echo '<tbody>';

		foreach ( $rows as $position => $snapshot ) {
			$previous = $rows[ $position + 1 ] ?? null;
			$delta    = ( null === $previous ) ? null : (int) $snapshot['total'] - (int) $previous['total'];

			echo '<tr class="switchrail-audit-row">';

			echo '<td class="switchrail-audit-col-name" data-label="' . esc_attr__( 'When', 'switchrail-audit' ) . '">'
				. esc_html(
					sprintf(
						/* translators: %s: Human-readable time difference. */
						__( '%s ago', 'switchrail-audit' ),
						human_time_diff( (int) $snapshot['recorded_at'] )
					)
				)
				. '</td>';

			// Entries saved before the scope was recorded show a dash, not a guess.
			echo '<td data-label="' . esc_attr__( 'Scanned', 'switchrail-audit' ) . '">'
				. ( isset( $snapshot['scope'] )
					? esc_html( Report::get_scope( (string) $snapshot['scope'] )['label'] )
					: '<span class="switchrail-audit-dash" aria-hidden="true">—</span>' )
				. '</td>';

			echo '<td class="switchrail-audit-col-number" data-label="' . esc_attr__( 'Score', 'switchrail-audit' ) . '">'
				. esc_html( sprintf( '%d%%', (int) $snapshot['score'] ) )
				. '</td>';

			echo '<td class="switchrail-audit-col-number" data-label="' . esc_attr__( 'Findings', 'switchrail-audit' ) . '">'
				. esc_html( number_format_i18n( (int) $snapshot['total'] ) )
				. '</td>';

			echo '<td class="switchrail-audit-col-number" data-label="' . esc_attr__( 'Change', 'switchrail-audit' ) . '">';

			if ( null === $delta || 0 === $delta ) {
				echo '<span class="switchrail-audit-dash" aria-hidden="true">—</span>';
			} else {
				// Fewer findings is the good direction, so a negative delta is "down" and green.
				printf(
					'<span class="switchrail-audit-delta switchrail-audit-delta--%1$s">%2$s</span>',
					esc_attr( $delta < 0 ? 'down' : 'up' ),
					esc_html( sprintf( '%+d', $delta ) )
				);
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		UI::render_card_close();
	}
}
