<?php
/**
 * Submenu page for displaying the settings overview.
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
 * Renders the overview dashboard.
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
		$this->render_tabs();
		$this->render_tab_contents();
	}

	/**
	 * Renders the tab navigation.
	 *
	 * @return void
	 */
	private function render_tabs() {
		?>
		<div id="wvc-navigation-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Overview sections', 'wp-vip-compatibility' ); ?>">
			<button
				type="button"
				class="active"
				data-tab="compatibility-status"
				role="tab"
				id="wvc-tab-compatibility-status"
				aria-controls="compatibility-status"
				aria-selected="true"
			>
				<?php esc_html_e( 'Compatibility Status', 'wp-vip-compatibility' ); ?>
			</button>
			<button
				type="button"
				data-tab="plugin-details"
				role="tab"
				id="wvc-tab-plugin-details"
				aria-controls="plugin-details"
				aria-selected="false"
				tabindex="-1"
			>
				<?php esc_html_e( 'Plugin Details', 'wp-vip-compatibility' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Renders the tab panels.
	 *
	 * @return void
	 */
	private function render_tab_contents() {
		?>
		<div id="compatibility-status" class="wvc-navigation-tab-content active" role="tabpanel" aria-labelledby="wvc-tab-compatibility-status" tabindex="0">
			<?php
			$this->render_readiness_summary();
			$this->render_chart();
			?>
		</div>

		<div id="plugin-details" class="wvc-navigation-tab-content" role="tabpanel" aria-labelledby="wvc-tab-plugin-details" tabindex="0">
			<?php $this->render_plugin_description(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the readiness summary.
	 *
	 * The headline numbers come from the stored scan results rather than from
	 * five AJAX round trips, so the page is meaningful before any JavaScript
	 * runs and does not re-scan the codebase to draw a gauge.
	 *
	 * @return void
	 */
	private function render_readiness_summary() {
		$aggregate = Report::aggregate();
		$delta     = Results_Store::get_delta();
		$scanned   = $aggregate['targets'] > 0;
		$score     = (int) $aggregate['score'];
		$tone      = $score >= 90 ? 'is-good' : ( $score >= 70 ? 'is-fair' : 'is-poor' );
		$dasharray = ( $score / 100 ) * ( 2 * M_PI * 52 );
		?>
		<section class="wvc-readiness" data-role="readiness" aria-labelledby="wvc-readiness-title">
			<div class="wvc-gauge <?php echo $scanned ? esc_attr( $tone ) : ''; ?>" data-role="gauge">
				<svg viewBox="0 0 120 120" role="img" aria-hidden="true" focusable="false">
					<circle class="wvc-gauge__track" cx="60" cy="60" r="52"></circle>
					<circle
						class="wvc-gauge__value"
						data-role="gauge-value"
						cx="60" cy="60" r="52"
						<?php if ( $scanned ) : ?>
							style="stroke-dasharray: <?php echo esc_attr( $dasharray . ' ' . ( 2 * M_PI * 52 ) ); ?>"
						<?php endif; ?>
					></circle>
				</svg>
				<div class="wvc-gauge__readout">
					<div>
						<span class="wvc-gauge__score" data-role="score"><?php echo $scanned ? esc_html( (string) $score ) : '–'; ?></span><span class="wvc-gauge__unit" data-role="score-unit"><?php echo $scanned ? '%' : ''; ?></span>
					</div>
					<span class="wvc-gauge__caption"><?php esc_html_e( 'Ready', 'wp-vip-compatibility' ); ?></span>
				</div>
			</div>

			<div class="wvc-readiness__body">
				<h3 class="wvc-readiness__title" id="wvc-readiness-title">
					<?php esc_html_e( 'Migration readiness', 'wp-vip-compatibility' ); ?>
				</h3>

				<p class="wvc-readiness__summary" data-role="readiness-summary" role="status" aria-live="polite">
					<?php echo esc_html( $this->readiness_sentence( $aggregate, $scanned ) ); ?>
				</p>

				<ul class="wvc-stats">
					<li class="is-ok">
						<span class="wvc-stats__value"><?php echo $scanned ? esc_html( number_format_i18n( $aggregate['statuses'][ Scanner::STATUS_PASS ] ) ) : '–'; ?></span>
						<span class="wvc-stats__label"><?php esc_html_e( 'Ready', 'wp-vip-compatibility' ); ?></span>
					</li>
					<li class="is-warn">
						<span class="wvc-stats__value"><?php echo $scanned ? esc_html( number_format_i18n( $aggregate['statuses'][ Scanner::STATUS_REVIEW ] ) ) : '–'; ?></span>
						<span class="wvc-stats__label"><?php esc_html_e( 'Needs review', 'wp-vip-compatibility' ); ?></span>
					</li>
					<li class="is-bad">
						<span class="wvc-stats__value"><?php echo $scanned ? esc_html( number_format_i18n( $aggregate['statuses'][ Scanner::STATUS_BLOCKED ] ) ) : '–'; ?></span>
						<span class="wvc-stats__label"><?php esc_html_e( 'Blocked', 'wp-vip-compatibility' ); ?></span>
					</li>
					<li>
						<span class="wvc-stats__value"><?php echo $scanned ? esc_html( number_format_i18n( $aggregate['totals']['findings'] ) ) : '–'; ?></span>
						<span class="wvc-stats__label"><?php esc_html_e( 'Findings', 'wp-vip-compatibility' ); ?></span>
					</li>
				</ul>

				<?php if ( $scanned && null !== $delta && 0 !== $delta['total'] ) : ?>
					<p class="wvc-readiness__delta">
						<?php
						printf(
							/* translators: 1: Signed change in findings. 2: Signed change in blockers. */
							esc_html__( '%1$s findings and %2$s blockers since the previous scan.', 'wp-vip-compatibility' ),
							esc_html( sprintf( '%+d', $delta['total'] ) ),
							esc_html( sprintf( '%+d', $delta['blocking'] ) )
						);
						?>
					</p>
				<?php endif; ?>

				<p class="wvc-readiness__actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wvc_rescan" />
						<?php wp_nonce_field( Findings_Settings::RESCAN_ACTION ); ?>
						<button type="submit" class="wvc-btn wvc-btn--primary">
							<?php echo UI::get_icon( 'refresh', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
							<?php echo esc_html( $scanned ? __( 'Rescan everything', 'wp-vip-compatibility' ) : __( 'Run the first scan', 'wp-vip-compatibility' ) ); ?>
						</button>
					</form>

					<?php if ( $scanned ) : ?>
						<a class="wvc-btn wvc-btn--ghost" href="<?php echo esc_url( UI::get_findings_url() ); ?>">
							<?php echo UI::get_icon( 'list', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
							<?php esc_html_e( 'Open the findings report', 'wp-vip-compatibility' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</div>
		</section>
		<?php
	}

	/**
	 * Builds the one-line readiness verdict.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @param bool                 $scanned   Whether anything has been scanned.
	 * @return string The sentence.
	 */
	private function readiness_sentence( array $aggregate, $scanned ) {
		if ( ! $scanned ) {
			return __( 'Nothing has been scanned yet. Run a scan to check every plugin, theme and must-use plugin against the VIP Platform requirements.', 'wp-vip-compatibility' );
		}

		$blocked  = (int) $aggregate['statuses'][ Scanner::STATUS_BLOCKED ];
		$review   = (int) $aggregate['statuses'][ Scanner::STATUS_REVIEW ];
		$critical = (int) ( $aggregate['by_severity'][ Taxonomy::SEVERITY_CRITICAL ] ?? 0 );

		if ( 0 === $blocked && 0 === $review ) {
			return __( 'Every scanned plugin, theme and must-use plugin is ready for the VIP Platform. Check the database and directories tabs as well before migrating.', 'wp-vip-compatibility' );
		}

		if ( $blocked > 0 ) {
			return sprintf(
				/* translators: 1: Number of blocked targets. 2: Number of critical findings. */
				_n(
					'%1$d target is expected to fail on VIP and has to be resolved before migrating, including %2$d critical finding. Start with the blockers in the findings report.',
					'%1$d targets are expected to fail on VIP and have to be resolved before migrating, including %2$d critical findings. Start with the blockers in the findings report.',
					$blocked,
					'wp-vip-compatibility'
				),
				$blocked,
				$critical
			);
		}

		return sprintf(
			/* translators: %d: Number of targets needing review. */
			_n(
				'Nothing is expected to fail outright, but %d target needs review before migrating.',
				'Nothing is expected to fail outright, but %d targets need review before migrating.',
				$review,
				'wp-vip-compatibility'
			),
			$review
		);
	}

	/**
	 * Renders the "about" panel.
	 *
	 * @return void
	 */
	private function render_plugin_description() {
		$paragraphs = array(
			__( 'This plugin analyses a standard WordPress site against the requirements of the WordPress VIP Platform, so that the work needed to migrate is known before the migration starts rather than discovered during it.', 'wp-vip-compatibility' ),
			__( 'Findings are separated by what they actually are. Something expected to fail on the platform is not shown the same way as a performance risk, a coding-standard warning, or a capability the platform already provides. Where static analysis cannot resolve a value at runtime, the finding says so and records its confidence rather than asserting an incompatibility it cannot prove.', 'wp-vip-compatibility' ),
			__( 'It is a starting point, not a certificate. It reads code without running it, so it cannot see behaviour that only appears under real traffic or real data. Test on a VIP environment before you rely on the result.', 'wp-vip-compatibility' ),
		);

		$doc_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://docs.wpvip.com/' ),
			esc_html__( 'WordPress VIP Documentation', 'wp-vip-compatibility' )
		);

		$checks = array(
			array(
				'icon'        => 'folder',
				'title'       => __( 'Filesystem and media', 'wp-vip-compatibility' ),
				'description' => __( 'Writes outside /tmp/ and uploads, directory traversal over the object store, generated PHP/CSS/JS, .htaccess assumptions, and local image processing.', 'wp-vip-compatibility' ),
			),
			array(
				'icon'        => 'database',
				'title'       => __( 'Database', 'wp-vip-compatibility' ),
				'description' => __( 'Storage engines, collations and prefixes, plus unprepared SQL, uncached queries, unbounded result sets and runtime schema changes.', 'wp-vip-compatibility' ),
			),
			array(
				'icon'        => 'bolt',
				'title'       => __( 'Caching, cron and requests', 'wp-vip-compatibility' ),
				'description' => __( 'Cache-busting headers, full object-cache flushes, custom cache layers, Cron Control conflicts, and uncached or untimed outbound requests.', 'wp-vip-compatibility' ),
			),
			array(
				'icon'        => 'shield',
				'title'       => __( 'Security and environment', 'wp-vip-compatibility' ),
				'description' => __( 'Shell execution, dynamic code, unescaped request data, PHP sessions, runtime ini changes and redefined core constants.', 'wp-vip-compatibility' ),
			),
			array(
				'icon'        => 'plug',
				'title'       => __( 'Platform overlap', 'wp-vip-compatibility' ),
				'description' => __( 'Plugins VIP lists as incompatible, plugins that need testing, and plugins duplicating something the platform already provides.', 'wp-vip-compatibility' ),
			),
		);
		?>
		<div class="wvc-about">
			<div class="wvc-panel">
				<div class="wvc-panel__head">
					<h3 class="wvc-panel__title"><?php esc_html_e( 'About this plugin', 'wp-vip-compatibility' ); ?></h3>
				</div>

				<div class="wvc-prose">
					<?php foreach ( $paragraphs as $paragraph ) : ?>
						<p><?php echo esc_html( $paragraph ); ?></p>
					<?php endforeach; ?>

					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Link to the VIP documentation. */
								__( 'Every rule records the VIP requirement it comes from and links to the relevant page of the %s.', 'wp-vip-compatibility' ),
								$doc_link
							)
						);
						?>
					</p>

					<p class="wvc-ruleref">
						<?php
						printf(
							/* translators: 1: Rule set version. 2: Number of rules. */
							esc_html__( 'Rule set %1$s — %2$d rules.', 'wp-vip-compatibility' ),
							esc_html( Rules::VERSION ),
							count( Rules::all() )
						);
						?>
					</p>
				</div>
			</div>

			<div class="wvc-panel">
				<div class="wvc-panel__head">
					<h3 class="wvc-panel__title"><?php esc_html_e( 'What gets checked', 'wp-vip-compatibility' ); ?></h3>
				</div>

				<ul class="wvc-feature-list">
					<?php foreach ( $checks as $check ) : ?>
						<li>
							<?php echo UI::get_icon( $check['icon'], array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
							<span>
								<strong><?php echo esc_html( $check['title'] ); ?></strong>
								<?php echo esc_html( $check['description'] ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the per-category doughnut charts.
	 *
	 * @return void
	 */
	private function render_chart() {
		$categories = array( 'plugins', 'themes', 'mu-plugins', 'database', 'directories' );
		$screens    = UI::get_screens();

		$labels = array(
			'plugins'     => __( 'Plugins', 'wp-vip-compatibility' ),
			'themes'      => __( 'Themes', 'wp-vip-compatibility' ),
			'mu-plugins'  => __( 'MU Plugins', 'wp-vip-compatibility' ),
			'database'    => __( 'Database', 'wp-vip-compatibility' ),
			'directories' => __( 'Directories', 'wp-vip-compatibility' ),
		);

		echo '<div id="wvc-chart-container" data-categories="' . esc_attr( wp_json_encode( $categories ) ) . '">';

		foreach ( $categories as $category ) {
			$icon  = $screens[ $category ]['icon'] ?? 'info';
			$label = $labels[ $category ] ?? $category;
			?>
			<article class="wvc-chart-card" data-category="<?php echo esc_attr( $category ); ?>">
				<header class="wvc-chart-card__head">
					<span class="wvc-chart-card__icon" aria-hidden="true">
						<?php echo UI::get_icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					</span>
					<h3 class="wvc-chart-card__title"><?php echo esc_html( $label ); ?></h3>
				</header>

				<div class="wvc-chart-card__figure">
					<canvas id="chart-<?php echo esc_attr( $category ); ?>" height="200" width="200" aria-hidden="true"></canvas>

					<div class="wvc-chart-card__readout" data-role="readout" hidden>
						<span class="wvc-chart-card__percent" data-role="percent">–</span>
						<span class="wvc-chart-card__percent-label"><?php esc_html_e( 'Ready', 'wp-vip-compatibility' ); ?></span>
					</div>

					<div class="wvc-chart-card__state" data-role="state">
						<span class="wvc-chart-card__ring-skeleton" aria-hidden="true"></span>
					</div>
				</div>

				<ul class="wvc-legend">
					<li>
						<span class="wvc-dot wvc-dot--ok" aria-hidden="true"></span>
						<span class="wvc-legend__label"><?php esc_html_e( 'Ready', 'wp-vip-compatibility' ); ?></span>
						<span class="wvc-legend__value" data-role="compatible">–</span>
					</li>
					<li>
						<span class="wvc-dot wvc-dot--warn" aria-hidden="true"></span>
						<span class="wvc-legend__label"><?php esc_html_e( 'Needs review', 'wp-vip-compatibility' ); ?></span>
						<span class="wvc-legend__value" data-role="needs-review">–</span>
					</li>
					<li>
						<span class="wvc-dot wvc-dot--bad" aria-hidden="true"></span>
						<span class="wvc-legend__label"><?php esc_html_e( 'Blocked', 'wp-vip-compatibility' ); ?></span>
						<span class="wvc-legend__value" data-role="incompatible">–</span>
					</li>
				</ul>

				<footer class="wvc-chart-card__foot">
					<a class="wvc-link" href="<?php echo esc_url( UI::get_screen_url( $category ) ); ?>">
						<?php
						printf(
							/* translators: %s: Section name, e.g. "Plugins". */
							esc_html__( 'Review %s', 'wp-vip-compatibility' ),
							esc_html( $label )
						);
						?>
						<?php echo UI::get_icon( 'arrow-right', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					</a>
				</footer>
			</article>
			<?php
		}

		echo '</div>';
	}
}
