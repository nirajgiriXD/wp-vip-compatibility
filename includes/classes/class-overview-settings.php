<?php
/**
 * Submenu page for displaying the settings overview.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * Handles the settings overview.
 */
class Overview_Settings {

	use Singleton;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Renders the settings page HTML.
	 *
	 * @return void
	 */
	public function render_settings_page() {

		// Render tab navigation.
		$this->render_tabs();

		// Render tab contents.
		$this->render_tab_contents();
	}

	/**
	 * Renders the tabs.
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
	 * Renders the tab contents.
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
	 * Renders the aggregated readiness summary.
	 *
	 * The values are filled in by the admin script once every category has
	 * reported its counts, so the markup ships with an explicit loading state.
	 *
	 * @return void
	 */
	private function render_readiness_summary() {
		?>
		<section class="wvc-readiness" data-role="readiness" aria-labelledby="wvc-readiness-title">
			<div class="wvc-gauge" data-role="gauge">
				<svg viewBox="0 0 120 120" role="img" aria-hidden="true" focusable="false">
					<circle class="wvc-gauge__track" cx="60" cy="60" r="52"></circle>
					<circle class="wvc-gauge__value" data-role="gauge-value" cx="60" cy="60" r="52"></circle>
				</svg>
				<div class="wvc-gauge__readout">
					<div>
						<span class="wvc-gauge__score" data-role="score">–</span><span class="wvc-gauge__unit" data-role="score-unit"></span>
					</div>
					<span class="wvc-gauge__caption"><?php esc_html_e( 'Ready', 'wp-vip-compatibility' ); ?></span>
				</div>
			</div>

			<div class="wvc-readiness__body">
				<h3 class="wvc-readiness__title" id="wvc-readiness-title">
					<?php esc_html_e( 'Migration readiness', 'wp-vip-compatibility' ); ?>
				</h3>
				<p class="wvc-readiness__summary" data-role="readiness-summary" role="status" aria-live="polite">
					<?php esc_html_e( 'Analysing plugins, themes, must-use plugins, database tables and directories…', 'wp-vip-compatibility' ); ?>
				</p>

				<ul class="wvc-stats">
					<li class="is-ok">
						<span class="wvc-stats__value" data-role="total-compatible">–</span>
						<span class="wvc-stats__label"><?php esc_html_e( 'Compatible', 'wp-vip-compatibility' ); ?></span>
					</li>
					<li class="is-bad">
						<span class="wvc-stats__value" data-role="total-incompatible">–</span>
						<span class="wvc-stats__label"><?php esc_html_e( 'Needs attention', 'wp-vip-compatibility' ); ?></span>
					</li>
					<li>
						<span class="wvc-stats__value" data-role="total-items">–</span>
						<span class="wvc-stats__label"><?php esc_html_e( 'Items checked', 'wp-vip-compatibility' ); ?></span>
					</li>
				</ul>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders the plugin description.
	 *
	 * @return void
	 */
	private function render_plugin_description() {
		// Paragraphs to display on the settings page.
		$paragraphs = array(
			__(
				'This plugin is a great starting point for analyzing the compatibility of a standard WordPress site with the WordPress VIP platform. It scans your site for potential issues by identifying unsupported plugins, directories, database configurations, and other incompatibilities with VIP requirements.',
				'wp-vip-compatibility'
			),
			__(
				"In addition to highlighting compatibility issues, the plugin also provides several options to address and fix known problems. However, it's important to note that this plugin is not a complete solution for making a WordPress site fully VIP-compatible. It serves as a tool for identifying and resolving common issues, but further manual adjustments and optimizations may be required to meet the platform's strict standards.",
				'wp-vip-compatibility'
			),
			__(
				'While this plugin can fix certain issues, it should be seen as an initial tool to help assess and prepare your site for VIP migration. For more advanced optimizations and compliance, a thorough manual review may still be required.',
				'wp-vip-compatibility'
			),
		);

		// Documentation link.
		$doc_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://docs.wpvip.com/' ),
			esc_html__( 'WordPress VIP Documentation', 'wp-vip-compatibility' )
		);

		// What each section checks, mirroring the navigation order.
		$checks = array(
			array(
				'icon'        => 'database',
				'title'       => __( 'Database', 'wp-vip-compatibility' ),
				'description' => __( 'Storage engines, collations and table prefixes.', 'wp-vip-compatibility' ),
			),
			array(
				'icon'        => 'folder',
				'title'       => __( 'Directories', 'wp-vip-compatibility' ),
				'description' => __( 'Files and folders in wp-content that VIP does not support.', 'wp-vip-compatibility' ),
			),
			array(
				'icon'        => 'plug',
				'title'       => __( 'Plugins & themes', 'wp-vip-compatibility' ),
				'description' => __( 'Known incompatible plugins plus a scan for filesystem writes and shell execution.', 'wp-vip-compatibility' ),
			),
			array(
				'icon'        => 'bolt',
				'title'       => __( 'Must-use plugins', 'wp-vip-compatibility' ),
				'description' => __( 'MU plugins that VIP preinstalls, replaces or rejects.', 'wp-vip-compatibility' ),
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
								/* translators: %s: URL to the documentation */
								__( 'For more detailed guidelines and in-depth explanations on how to make your WordPress site fully compatible with the VIP platform, please visit the official %s.', 'wp-vip-compatibility' ),
								$doc_link
							)
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
	 * Renders the per-category compatibility charts.
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
						<span class="wvc-chart-card__percent-label"><?php esc_html_e( 'Compatible', 'wp-vip-compatibility' ); ?></span>
					</div>

					<div class="wvc-chart-card__state" data-role="state">
						<span class="wvc-chart-card__ring-skeleton" aria-hidden="true"></span>
					</div>
				</div>

				<ul class="wvc-legend">
					<li>
						<span class="wvc-dot wvc-dot--ok" aria-hidden="true"></span>
						<span class="wvc-legend__label"><?php esc_html_e( 'Compatible', 'wp-vip-compatibility' ); ?></span>
						<span class="wvc-legend__value" data-role="compatible">–</span>
					</li>
					<li>
						<span class="wvc-dot wvc-dot--bad" aria-hidden="true"></span>
						<span class="wvc-legend__label"><?php esc_html_e( 'Incompatible', 'wp-vip-compatibility' ); ?></span>
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
