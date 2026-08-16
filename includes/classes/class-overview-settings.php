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
			$this->render_next_steps();
			$this->render_areas();
		}

		$this->render_environment();

		if ( $scanned ) {
			$this->render_history();
		}

		$this->render_about();
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
	 * the report filtered to exactly that band — so the top of the screen is also
	 * the fastest way into it.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @return array<int, array<string, mixed>> Facts.
	 */
	private function get_facts( array $aggregate ) {
		$facts = array();

		foreach ( UI::get_tiers() as $tier => $definition ) {
			$count = 0;

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

		$facts[] = array(
			'label' => __( 'Passed', 'wp-vip-compatibility' ),
			'value' => number_format_i18n( (int) $aggregate['statuses'][ Scanner::STATUS_PASS ] ),
			'tone'  => 'ok',
		);

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
				'summary'  => __( 'Run a scan to check every plugin, theme and must-use plugin against the VIP Platform requirements. It reads code without running it, so it is safe to run on a live site.', 'wp-vip-compatibility' ),
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
						'%1$d of %2$d scanned items is expected to fail on the VIP Platform and has to be resolved first.',
						'%1$d of %2$d scanned items are expected to fail on the VIP Platform and have to be resolved first.',
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
	 * Renders the ranked list of next steps.
	 *
	 * @return void
	 */
	private function render_next_steps() {
		$actions = Report::next_actions();

		if ( empty( $actions ) ) {
			echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				esc_html__( 'The scanner found nothing that needs doing before this site moves to VIP. Test on a VIP environment before you rely on that: static analysis cannot see behaviour that only appears under real traffic or real data.', 'wp-vip-compatibility' ),
				'success',
				esc_html__( 'Nothing outstanding', 'wp-vip-compatibility' )
			);

			return;
		}

		UI::render_panel_open(
			array(
				'title'   => __( 'Do this next', 'wp-vip-compatibility' ),
				'summary' => __( 'Ordered by how much each item stands between this site and the platform.', 'wp-vip-compatibility' ),
			)
		);

		UI::render_action_list( $actions );

		UI::render_panel_close();
	}

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
				'summary' => __( 'What this site runs on today, measured against what it will run on at VIP. None of this is visible to a code scan.', 'wp-vip-compatibility' ),
			)
		);

		echo UI::get_defs( Report::environment(), 'wvc-defs--grid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

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
					'label' => __( 'Blocking', 'wp-vip-compatibility' ),
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

			echo '<td class="wvc-col-number" data-label="' . esc_attr__( 'Blocking', 'wp-vip-compatibility' ) . '">'
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
	 * Renders the collapsed "about" disclosure.
	 *
	 * @return void
	 */
	private function render_about() {
		$checks = array(
			__( 'Filesystem and media — writes outside /tmp/ and uploads, traversal over the object store, generated PHP/CSS/JS, .htaccess assumptions and local image processing.', 'wp-vip-compatibility' ),
			__( 'Database — storage engines, collations and prefixes, plus unprepared SQL, uncached queries, unbounded result sets and runtime schema changes.', 'wp-vip-compatibility' ),
			__( 'Caching, cron and requests — cache-busting headers, full object-cache flushes, custom cache layers, Cron Control conflicts and uncached or untimed outbound requests.', 'wp-vip-compatibility' ),
			__( 'Security and environment — shell execution, dynamic code, unescaped request data, PHP sessions, runtime ini changes and redefined core constants.', 'wp-vip-compatibility' ),
			__( 'Platform overlap — plugins VIP lists as incompatible, plugins that need testing, and plugins duplicating something the platform already provides.', 'wp-vip-compatibility' ),
		);

		$body  = '<p>' . esc_html__( 'This plugin analyses a standard WordPress site against the requirements of the WordPress VIP Platform, so that the work needed to migrate is known before the migration starts rather than discovered during it.', 'wp-vip-compatibility' ) . '</p>';
		$body .= '<p>' . esc_html__( 'It is a starting point, not a certificate. It reads code without running it, so it cannot see behaviour that only appears under real traffic or real data. Where static analysis cannot resolve a value, a finding records its confidence rather than asserting an incompatibility it cannot prove.', 'wp-vip-compatibility' ) . '</p>';
		$body .= '<p>' . esc_html__( 'What gets checked:', 'wp-vip-compatibility' ) . '</p><ul>';

		foreach ( $checks as $check ) {
			$body .= '<li>' . esc_html( $check ) . '</li>';
		}

		$body .= '</ul>';
		$body .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: Rule set version. 2: Number of rules. */
				__( 'Rule set %1$s — %2$d rules, each mapped to the VIP requirement it comes from.', 'wp-vip-compatibility' ),
				Rules::VERSION,
				count( Rules::all() )
			)
		) . '</p>';

		echo UI::get_guidance( $body, __( 'About this plugin and what it checks', 'wp-vip-compatibility' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
	}
}
