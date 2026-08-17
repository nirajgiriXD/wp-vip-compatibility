<?php
/**
 * The overview screen.
 *
 * The overview answers, in this order and nothing else: how ready is this site,
 * what is wrong, what should I do first, where is the work, and what is the
 * platform underneath it. Each answer is one section, and each section links
 * into the screen that owns the detail — so the path from a summary to a fix is
 * summary, problem, detail, action, without ever going through a search box.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Rules;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Scanner;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the overview.
 */
class Overview_Settings {

	use Singleton;

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

		$this->render_verdict( $aggregate, $scanned );

		if ( $scanned ) {
			$this->render_areas();
		} else {
			$this->render_getting_started();
		}

		$this->render_environment();

		if ( $scanned ) {
			$this->render_history();
		}

		$this->render_about();
	}

	/**
	 * Explains the loop, on a site that has never been scanned.
	 *
	 * At that point every screen in the plugin is empty, so this is the only
	 * moment where the interface has to describe itself instead of showing
	 * something. It says what the three things are that this plugin is for, and
	 * then never appears again.
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

	/* ---------------------------------------------------------------------
	 * Verdict
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the verdict hero.
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
				'facts'    => $scanned ? $this->get_facts( $aggregate ) : array(),
			)
		);
	}

	/**
	 * Builds the counted facts shown beside the gauge.
	 *
	 * These are the four numbers the whole report reduces to, each linking into
	 * the fix list filtered to exactly that tier — so the top of the screen is
	 * also the fastest way into it.
	 *
	 * They count everything outstanding, not just the findings: the database,
	 * must-use and wp-content audits produce migration work that has no file and
	 * line, and it appears in the plan directly below under these same four
	 * labels. Counting only findings here would put "Must fix 0" immediately
	 * above a step badged "Must fix", and would disagree with the identical row
	 * of chips on the fix list.
	 *
	 * Every tier is listed even at zero, because "nothing must be fixed" is the
	 * answer this screen exists to give, and it can only be read off a row that
	 * always has four entries in it.
	 *
	 * There was a fifth entry here, "Passed", which counted plugins and themes
	 * rather than work. One row mixing two units, with nothing naming either,
	 * made all five ambiguous — and the number it carried is already in the
	 * gauge, in the headline beside it, and in the per-area cards below.
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
				'label' => $definition['label'],
				'value' => number_format_i18n( $count ),
				'tone'  => ( 0 === $count ) ? 'muted' : $definition['tone'],
				'url'   => ( 0 === $count ) ? '' : UI::get_findings_url( '', $tier ),
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
						'icon'    => 'alert',
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
						'icon'    => 'list',
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
	 * Builds the single supporting line: when the scan ran and what changed.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @param bool                 $scanned   Whether anything has been scanned.
	 * @return string The line.
	 */
	private function scan_meta( array $aggregate, $scanned ) {
		if ( ! $scanned || $aggregate['scanned_at'] <= 0 ) {
			return '';
		}

		$parts = array(
			sprintf(
				/* translators: 1: Number of targets. 2: Number of PHP files. */
				__( '%1$d items scanned across %2$s PHP files', 'wp-vip-compatibility' ),
				(int) $aggregate['targets'],
				number_format_i18n( (int) $aggregate['totals']['files'] )
			),
		);

		$delta = Results_Store::get_delta();

		if ( null !== $delta && 0 !== $delta['total'] ) {
			$parts[] = sprintf(
				/* translators: %s: Signed change in findings, e.g. "+12". */
				__( '%s findings since the previous scan', 'wp-vip-compatibility' ),
				sprintf( '%+d', $delta['total'] )
			);
		}

		return implode( ' · ', $parts );
	}

	/* ---------------------------------------------------------------------
	 * Sections
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the per-area breakdown.
	 *
	 * @return void
	 */
	private function render_areas() {
		UI::render_panel_open(
			array(
				'title'   => __( 'Where the work is', 'wp-vip-compatibility' ),
				'summary' => __( 'Every area of the site, with the split between what is ready and what is not.', 'wp-vip-compatibility' ),
			)
		);

		UI::render_area_cards( Report::areas() );

		UI::render_panel_close();
	}

	/**
	 * Renders the environment summary.
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

		// Boxed, not the default left rule: six of these in a row read as one long
		// indented quotation rather than six separate facts about the platform.
		// `--grid` stays on for the four-column cap on wide screens.
		echo UI::get_defs( Report::environment(), 'wvc-defs--grid wvc-defs--boxed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		UI::render_panel_close();
	}

	/**
	 * Renders the scan history.
	 *
	 * Only aggregate snapshots are kept, which is enough to answer the question
	 * this section exists for: is the migration work getting smaller?
	 *
	 * @return void
	 */
	private function render_history() {
		$history = Results_Store::get_history();

		if ( count( $history ) < 2 ) {
			return;
		}

		$history = array_reverse( array_slice( $history, -8 ) );

		UI::render_panel_open(
			array(
				'title'   => __( 'Recent scans', 'wp-vip-compatibility' ),
				'summary' => __( 'How the report has moved over the last few scans.', 'wp-vip-compatibility' ),
				'flush'   => true,
			)
		);

		echo '<div class="wvc-table-wrap">';
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
				array( 'label' => __( 'Change', 'wp-vip-compatibility' ) ),
			)
		);

		echo '<tbody>';

		foreach ( $history as $position => $snapshot ) {
			$previous = $history[ $position + 1 ] ?? null;
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

			echo '<td data-label="' . esc_attr__( 'Change', 'wp-vip-compatibility' ) . '">';

			if ( null === $delta || 0 === $delta ) {
				echo '<span class="wvc-dash" aria-hidden="true">—</span>';
			} else {
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
	 * This is a panel rather than the collapsed disclosure it used to be. What
	 * the scan covers, and the fact that a clean report is not a guarantee, are
	 * the two things that qualify every number above it — and a reader who has
	 * to open something to find that out is a reader who never finds it.
	 *
	 * The five areas are a definition grid for the same reason the environment
	 * summary is: as a bullet list they were five long sentences stacked down
	 * the left edge of a very wide column, and as labelled pairs they lay out
	 * across it and can be scanned for the one that matters.
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
				'title'   => __( 'About this plugin and what it checks', 'wp-vip-compatibility' ),
				'summary' => sprintf(
					/* translators: 1: Rule set version. 2: Number of rules. */
					__( 'Rule set %1$s — %2$d rules, each mapped to the WordPress VIP requirement it comes from.', 'wp-vip-compatibility' ),
					Rules::VERSION,
					count( Rules::all() )
				),
			)
		);

		echo '<p class="wvc-about__lead">' . esc_html__( 'This plugin analyses a standard WordPress site against the requirements of the WordPress VIP Platform, so that the work needed to migrate is known before the migration starts rather than discovered during it.', 'wp-vip-compatibility' ) . '</p>';
		echo '<p class="wvc-about__lead">' . esc_html__( 'It is a starting point, not a certificate. It reads code without running it, so it cannot see behaviour that only appears under real traffic or real data. Where static analysis cannot resolve a value, a finding records its confidence rather than asserting an incompatibility it cannot prove.', 'wp-vip-compatibility' ) . '</p>';

		echo '<div class="wvc-detail__block">';
		echo '<h4 class="wvc-detail__title">' . esc_html__( 'What gets checked', 'wp-vip-compatibility' ) . '</h4>';
		echo UI::get_defs( $checks, 'wvc-defs--cards' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		echo '</div>';

		UI::render_panel_close();
	}
}
