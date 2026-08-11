<?php
/**
 * The overview screen.
 *
 * This screen answers three questions in order, and nothing else: how ready is
 * this site, what should I do next, and where is the work. Everything that
 * explains the plugin rather than the site sits in a disclosure at the bottom.
 *
 * It used to lead with a readiness gauge, then repeat the same three counts in a
 * stats strip, then repeat them again in five doughnut charts — each of which
 * cost an AJAX round trip and a charting library — and then hide an "About"
 * page behind a tab bar as though documentation were a peer workflow.
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
	 * Renders the settings page.
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

		$this->render_about();
	}

	/**
	 * Renders the verdict card.
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
						'url'     => UI::get_findings_url(),
						'icon'    => 'list',
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
			'summary'  => __( 'Every scanned plugin, theme and must-use plugin passed. Check the database and wp-content audits on the Site screen before you export.', 'wp-vip-compatibility' ),
			'actions'  => array(
				array(
					'label'   => __( 'Check the site audits', 'wp-vip-compatibility' ),
					'url'     => UI::get_screen_url( 'site' ),
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
	 * This is the only place in the plugin that reports the change since the
	 * previous scan. It used to appear here and again, word for word, on the
	 * findings screen.
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
				/* translators: %s: Human-readable time difference. */
				__( 'Last scanned %s ago', 'wp-vip-compatibility' ),
				human_time_diff( $aggregate['scanned_at'] )
			),
			sprintf(
				/* translators: 1: Number of targets. 2: Number of PHP files. */
				__( '%1$d items, %2$s PHP files', 'wp-vip-compatibility' ),
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

		UI::render_section_head(
			__( 'What to do next', 'wp-vip-compatibility' ),
			'',
			__( 'Ordered by how much each item stands between this site and the platform.', 'wp-vip-compatibility' )
		);

		UI::render_action_list( $actions );
	}

	/**
	 * Renders the per-area breakdown.
	 *
	 * @return void
	 */
	private function render_areas() {
		UI::render_section_head( __( 'Where the work is', 'wp-vip-compatibility' ) );
		?>
		<ul class="wvc-areas">
			<?php foreach ( Report::areas() as $area ) : ?>
				<?php
				$counts = $area['counts'];
				$url    = ( 'inventory' === $area['screen'] )
					? UI::get_screen_url( 'inventory', array( 'kind' => $area['filter'] ) )
					: UI::get_screen_url( 'site', array( 'section' => $area['filter'] ) );
				?>
				<li class="wvc-areas__item">
					<a class="wvc-areas__link" href="<?php echo esc_url( $url ); ?>">
						<span class="wvc-areas__label"><?php echo esc_html( $area['label'] ); ?></span>
						<?php echo UI::get_meter( $counts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
						<span class="wvc-areas__legend"><?php echo esc_html( UI::get_meter_legend( $counts ) ); ?></span>
						<?php echo UI::get_icon( 'arrow-right', array( 'class' => 'wvc-icon wvc-icon--xs wvc-areas__chevron' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
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
