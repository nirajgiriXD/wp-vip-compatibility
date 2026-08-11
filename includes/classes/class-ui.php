<?php
/**
 * Shared presentation helpers for the plugin admin screens.
 *
 * This class owns the reusable chrome so that every screen renders from a single
 * design system, and — more importantly — from a single information hierarchy:
 *
 * - A screen leads with one verdict, not a wall of counts.
 * - Severity is expressed through one vocabulary (the tiers below) everywhere it
 *   appears: the overview's action list, the findings report, and the tables.
 * - Counts are rendered once, in the control that filters by them, instead of
 *   once in a stats strip, again in a chart legend and again in a filter tab.
 * - Anything that is evidence rather than a decision lives behind a disclosure.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the shared admin interface building blocks.
 */
class UI {

	/**
	 * Returns the map of plugin screens.
	 *
	 * Four screens, each a genuinely different question: what is the state, what
	 * exactly is wrong, what is installed, and what does the site itself look
	 * like. Plugins, themes and must-use plugins used to be three screens with
	 * the same columns and the same workflow, and the database and wp-content
	 * audits two more; splitting them spread one job across five tabs.
	 *
	 * @return array<string, array<string, string>> Screen definitions keyed by settings key.
	 */
	public static function get_screens() {
		return array(
			'overview'  => array(
				'slug'        => 'wp-vip-compatibility',
				'label'       => __( 'Overview', 'wp-vip-compatibility' ),
				'title'       => __( 'Overview', 'wp-vip-compatibility' ),
				'description' => __( 'How ready this site is for the VIP Platform, and what to do next.', 'wp-vip-compatibility' ),
				'icon'        => 'gauge',
			),
			'findings'  => array(
				'slug'        => 'wvc-findings',
				'label'       => __( 'Findings', 'wp-vip-compatibility' ),
				'title'       => __( 'Findings', 'wp-vip-compatibility' ),
				'description' => __( 'Every issue the scanner found, worst first, with why it matters and how to fix it.', 'wp-vip-compatibility' ),
				'icon'        => 'list',
			),
			'inventory' => array(
				'slug'        => 'wvc-inventory',
				'label'       => __( 'Inventory', 'wp-vip-compatibility' ),
				'title'       => __( 'Inventory', 'wp-vip-compatibility' ),
				'description' => __( 'Every plugin, theme and must-use plugin with its VIP verdict.', 'wp-vip-compatibility' ),
				'icon'        => 'plug',
			),
			'site'      => array(
				'slug'        => 'wvc-site',
				'label'       => __( 'Site', 'wp-vip-compatibility' ),
				'title'       => __( 'Site', 'wp-vip-compatibility' ),
				'description' => __( 'The database schema and the wp-content layout, measured against the VIP structure.', 'wp-vip-compatibility' ),
				'icon'        => 'database',
			),
		);
	}

	/**
	 * Returns the tier definitions with their presentation defaults.
	 *
	 * The vocabulary itself lives in Taxonomy, next to the severities it groups.
	 * All this adds is how each tier behaves on screen: the two tiers that
	 * represent work open by default, and the two that represent context do not.
	 *
	 * @return array<string, array<string, mixed>> Tier definitions keyed by tier.
	 */
	public static function get_tiers() {
		$presentation = array(
			Taxonomy::TIER_BLOCKING  => array(
				'icon' => 'alert',
				'open' => true,
			),
			Taxonomy::TIER_IMPORTANT => array(
				'icon' => 'alert',
				'open' => true,
			),
			Taxonomy::TIER_WARNING   => array(
				'icon' => 'info',
				'open' => false,
			),
			Taxonomy::TIER_INFO      => array(
				'icon' => 'info',
				'open' => false,
			),
		);

		$tiers = array();

		foreach ( Taxonomy::get_tiers() as $tier => $definition ) {
			$tiers[ $tier ] = array_merge( $definition, $presentation[ $tier ] ?? array() );
		}

		return $tiers;
	}

	/**
	 * Returns the tier a severity belongs to.
	 *
	 * @param string $severity A Taxonomy severity slug.
	 * @return string The tier slug.
	 */
	public static function get_tier( $severity ) {
		return Taxonomy::get_tier( $severity );
	}

	/**
	 * Returns the label of a tier.
	 *
	 * @param string $tier The tier slug.
	 * @return string The label.
	 */
	public static function get_tier_label( $tier ) {
		$tiers = Taxonomy::get_tiers();

		return $tiers[ $tier ]['label'] ?? $tier;
	}

	/**
	 * Returns the URL of the findings report, optionally filtered.
	 *
	 * @param string $target_key Optional target key to filter by.
	 * @param string $tier       Optional tier to filter by.
	 * @return string The admin URL.
	 */
	public static function get_findings_url( $target_key = '', $tier = '' ) {
		$args = array();

		if ( '' !== $target_key ) {
			$args['target'] = $target_key;
		}

		if ( '' !== $tier ) {
			$args['tier'] = $tier;
		}

		$url = self::get_screen_url( 'findings' );

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Returns the admin URL of a plugin screen.
	 *
	 * @param string $key  The settings key.
	 * @param array  $args Optional query arguments.
	 * @return string The admin URL.
	 */
	public static function get_screen_url( $key, $args = array() ) {
		$screens = self::get_screens();
		$slug    = $screens[ $key ]['slug'] ?? 'wp-vip-compatibility';
		$url     = admin_url( 'admin.php?page=' . $slug );

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Returns an inline SVG icon.
	 *
	 * Icons are stroked with `currentColor` so they inherit the surrounding text
	 * colour and stay legible in every admin colour scheme.
	 *
	 * @param string $name  Icon name.
	 * @param array  $attrs Optional extra attributes (class, aria-hidden...).
	 * @return string The SVG markup, or an empty string when unknown.
	 */
	public static function get_icon( $name, $attrs = array() ) {
		$paths = array(
			'gauge'       => '<path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="m13.4 10.6 4.1-4.1"/><path d="M4 19a9 9 0 1 1 16 0"/>',
			'database'    => '<ellipse cx="12" cy="5.5" rx="7.5" ry="3"/><path d="M4.5 5.5v13c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-13"/><path d="M19.5 12c0 1.7-3.4 3-7.5 3s-7.5-1.3-7.5-3"/>',
			'folder'      => '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6h4l2 2.5h7A1.5 1.5 0 0 1 19 10v7.5a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 3 17.5Z"/>',
			'bolt'        => '<path d="M13 2 4.5 13.5H11L10 22l8.5-11.5H12Z"/>',
			'plug'        => '<path d="M9 2v6"/><path d="M15 2v6"/><path d="M6 8h12v3a6 6 0 0 1-6 6 6 6 0 0 1-6-6Z"/><path d="M12 17v5"/>',
			'brush'       => '<path d="M4 20c0-2 1-3 3-3 1.6 0 2.5 1 2.5 2.2C9.5 20.6 8 22 5.5 22 4.7 22 4 21.3 4 20Z"/><path d="M9.5 17.5 19 8a2.1 2.1 0 0 0-3-3l-9.5 9.5"/>',
			'search'      => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
			'check'       => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
			'alert'       => '<path d="M12 8.5v5"/><path d="M12 17h.01"/><path d="M10.3 3.9 2.6 17.4A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3.1L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
			'info'        => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
			'external'    => '<path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14.5V19a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 19V7.5A1.5 1.5 0 0 1 5 6h4.5"/>',
			'copy'        => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4.5A1.5 1.5 0 0 1 3 13.5V5A1.5 1.5 0 0 1 4.5 3.5H13A1.5 1.5 0 0 1 14.5 5v.5"/>',
			'arrow-right' => '<path d="M4 12h15"/><path d="m13 6 6 6-6 6"/>',
			'sort'        => '<path d="m8 9 4-4 4 4"/><path d="m8 15 4 4 4-4"/>',
			'inbox'       => '<path d="M3 13h4l1.5 3h7L17 13h4"/><path d="M5.5 5h13l2.5 8v5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18v-5Z"/>',
			'shield'      => '<path d="M12 3 5 6v6c0 4.3 2.9 7.8 7 9 4.1-1.2 7-4.7 7-9V6Z"/><path d="m9 12 2 2 4-4"/>',
			'list'        => '<path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
			'download'    => '<path d="M12 3v12"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4 19.5h16"/>',
			'refresh'     => '<path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M19.5 11a7.5 7.5 0 0 0-13-3.5L4 10"/><path d="M4.5 13a7.5 7.5 0 0 0 13 3.5L20 14"/>',
			'book'        => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H5.5A1.5 1.5 0 0 1 4 16.5Z"/><path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H14a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h4.5a1.5 1.5 0 0 0 1.5-1.5Z"/>',
			'wrench'      => '<path d="M15.5 3.5a5 5 0 0 0-6 6.6L3.6 16a2 2 0 0 0 2.8 2.8l5.9-5.9a5 5 0 0 0 6.6-6l-3 3-2.8-2.8Z"/>',
			'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
			'trend'       => '<path d="m4 16 5-5 3.5 3.5L20 7"/><path d="M15 7h5v5"/>',
			'filter'      => '<path d="M3 5h18"/><path d="M6.5 12h11"/><path d="M10 19h4"/>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		$attrs = wp_parse_args(
			$attrs,
			array(
				'class'       => 'wvc-icon',
				'aria-hidden' => 'true',
				'focusable'   => 'false',
			)
		);

		$attr_string = '';
		foreach ( $attrs as $attr => $value ) {
			$attr_string .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $value ) );
		}

		return sprintf(
			'<svg%s viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">%s</svg>',
			$attr_string,
			$paths[ $name ]
		);
	}

	/**
	 * Renders the plugin masthead and the screen navigation.
	 *
	 * The masthead carries identity and one link out. It used to also carry a
	 * tagline explaining what the plugin does, immediately above a page heading
	 * that explained what the screen does — two sentences of chrome before any
	 * of the site's own data. The plugin's purpose now lives in the overview's
	 * "About" disclosure, where it is read once.
	 *
	 * @param string $current The current settings key.
	 * @return void
	 */
	public static function render_masthead( $current ) {
		$screens = self::get_screens();
		?>
		<header class="wvc-masthead">
			<div class="wvc-masthead__identity">
				<span class="wvc-masthead__mark" aria-hidden="true">
					<?php echo self::get_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
				</span>
				<h1 class="wvc-masthead__title">
					<?php esc_html_e( 'WordPress VIP Compatibility', 'wp-vip-compatibility' ); ?>
				</h1>
			</div>

			<a class="wvc-btn wvc-btn--ghost wvc-btn--sm" href="https://docs.wpvip.com/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'WordPress VIP documentation', 'wp-vip-compatibility' ); ?>
				<?php echo self::get_icon( 'external', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'wp-vip-compatibility' ); ?></span>
			</a>
		</header>

		<nav class="wvc-subnav" aria-label="<?php esc_attr_e( 'Compatibility sections', 'wp-vip-compatibility' ); ?>">
			<ul class="wvc-subnav__list">
				<?php foreach ( $screens as $key => $screen ) : ?>
					<li>
						<a
							class="wvc-subnav__item<?php echo ( $key === $current ) ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( self::get_screen_url( $key ) ); ?>"
							<?php echo ( $key === $current ) ? 'aria-current="page"' : ''; ?>
						>
							<?php echo self::get_icon( $screen['icon'], array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
							<span><?php echo esc_html( $screen['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Renders the page heading for a screen.
	 *
	 * @param string $current The current settings key.
	 * @return void
	 */
	public static function render_page_head( $current ) {
		$screens = self::get_screens();

		if ( ! isset( $screens[ $current ] ) ) {
			return;
		}
		?>
		<div class="wvc-page-head">
			<h2 class="wvc-page-head__title"><?php echo esc_html( $screens[ $current ]['title'] ); ?></h2>
			<p class="wvc-page-head__description"><?php echo esc_html( $screens[ $current ]['description'] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders a section heading.
	 *
	 * @param string $title   The heading.
	 * @param string $meta    Optional short count or status to the right of it.
	 * @param string $summary Optional one-line explanation below it.
	 * @return void
	 */
	public static function render_section_head( $title, $meta = '', $summary = '' ) {
		?>
		<div class="wvc-section-head">
			<h3 class="wvc-section-head__title">
				<?php echo esc_html( $title ); ?>
				<?php if ( '' !== $meta ) : ?>
					<span class="wvc-section-head__meta"><?php echo esc_html( $meta ); ?></span>
				<?php endif; ?>
			</h3>
			<?php if ( '' !== $summary ) : ?>
				<p class="wvc-section-head__summary"><?php echo esc_html( $summary ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the verdict card: the one thing a screen leads with.
	 *
	 * @param array $args {
	 *     Verdict arguments.
	 *
	 *     @type int    $score      Readiness percentage, or -1 when nothing is scanned.
	 *     @type string $tier       Tier driving the accent colour.
	 *     @type string $headline   Short verdict, e.g. "Not ready to migrate".
	 *     @type string $summary    One sentence saying what that means.
	 *     @type string $meta       Optional supporting line (when it ran, what changed).
	 *     @type array  $actions    List of `label`, `url`, `primary`, `icon` links or forms.
	 * }
	 * @return void
	 */
	public static function render_verdict( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'score'    => -1,
				'tier'     => Taxonomy::TIER_INFO,
				'headline' => '',
				'summary'  => '',
				'meta'     => '',
				'actions'  => array(),
			)
		);

		$scored      = $args['score'] >= 0;
		$radius      = 52;
		$circumfence = 2 * M_PI * $radius;
		?>
		<section class="wvc-verdict wvc-verdict--<?php echo esc_attr( $args['tier'] ); ?>" aria-labelledby="wvc-verdict-title">
			<div class="wvc-gauge" role="img" aria-label="<?php echo esc_attr( $scored ? sprintf( /* translators: %d: Percentage. */ __( '%d%% ready', 'wp-vip-compatibility' ), (int) $args['score'] ) : __( 'Not scanned yet', 'wp-vip-compatibility' ) ); ?>">
				<svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
					<circle class="wvc-gauge__track" cx="60" cy="60" r="<?php echo esc_attr( (string) $radius ); ?>"></circle>
					<?php if ( $scored ) : ?>
						<circle
							class="wvc-gauge__value"
							cx="60" cy="60" r="<?php echo esc_attr( (string) $radius ); ?>"
							style="stroke-dasharray: <?php echo esc_attr( ( ( (int) $args['score'] / 100 ) * $circumfence ) . ' ' . $circumfence ); ?>"
						></circle>
					<?php endif; ?>
				</svg>
				<span class="wvc-gauge__readout">
					<span class="wvc-gauge__score"><?php echo $scored ? esc_html( (string) (int) $args['score'] ) : '–'; ?><?php echo $scored ? '<span class="wvc-gauge__unit">%</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
					<span class="wvc-gauge__caption"><?php esc_html_e( 'Ready', 'wp-vip-compatibility' ); ?></span>
				</span>
			</div>

			<div class="wvc-verdict__body">
				<h3 class="wvc-verdict__headline" id="wvc-verdict-title"><?php echo esc_html( $args['headline'] ); ?></h3>
				<p class="wvc-verdict__summary"><?php echo esc_html( $args['summary'] ); ?></p>

				<?php if ( ! empty( $args['actions'] ) ) : ?>
					<div class="wvc-verdict__actions">
						<?php
						foreach ( $args['actions'] as $action ) {
							echo self::get_action_button( $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
						}
						?>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $args['meta'] ) : ?>
					<p class="wvc-verdict__meta">
						<?php echo self::get_icon( 'clock', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<?php echo esc_html( $args['meta'] ); ?>
					</p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Builds one button, either a link or a nonce-protected form submission.
	 *
	 * @param array $action {
	 *     Button arguments.
	 *
	 *     @type string $label   Visible label.
	 *     @type string $url     Destination, for a link button.
	 *     @type string $submit  admin-post action name, for a form button.
	 *     @type string $nonce   Nonce action, required with `submit`.
	 *     @type string $icon    Optional icon name.
	 *     @type bool   $primary Whether it is the primary action.
	 * }
	 * @return string The button markup.
	 */
	public static function get_action_button( array $action ) {
		$action = wp_parse_args(
			$action,
			array(
				'label'   => '',
				'url'     => '',
				'submit'  => '',
				'nonce'   => '',
				'icon'    => '',
				'primary' => false,
			)
		);

		$classes = 'wvc-btn ' . ( $action['primary'] ? 'wvc-btn--primary' : 'wvc-btn--ghost' );
		$icon    = '' === $action['icon'] ? '' : self::get_icon( $action['icon'], array( 'class' => 'wvc-icon wvc-icon--sm' ) );

		if ( '' !== $action['submit'] ) {
			return sprintf(
				'<form method="post" action="%1$s" class="wvc-inline-form"><input type="hidden" name="action" value="%2$s" />%3$s<button type="submit" class="%4$s">%5$s%6$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $action['submit'] ),
				wp_nonce_field( $action['nonce'], '_wpnonce', true, false ),
				esc_attr( $classes ),
				$icon,
				esc_html( $action['label'] )
			);
		}

		return sprintf(
			'<a class="%1$s" href="%2$s">%3$s%4$s</a>',
			esc_attr( $classes ),
			esc_url( $action['url'] ),
			$icon,
			esc_html( $action['label'] )
		);
	}

	/**
	 * Renders the ranked "what to do next" list.
	 *
	 * Totals describe a site; this describes a plan. Each row states the work,
	 * how much of it there is, why it matters in one line, and carries the link
	 * to the place it gets done — which is the whole reason the overview exists.
	 *
	 * @param array<int, array<string, mixed>> $actions Actions from Report::next_actions().
	 * @return void
	 */
	public static function render_action_list( array $actions ) {
		if ( empty( $actions ) ) {
			return;
		}
		?>
		<ol class="wvc-actions">
			<?php foreach ( $actions as $action ) : ?>
				<li class="wvc-actions__item wvc-actions__item--<?php echo esc_attr( $action['tier'] ); ?>">
					<span class="wvc-actions__tier" title="<?php echo esc_attr( self::get_tier_label( $action['tier'] ) ); ?>">
						<span class="wvc-tierdot wvc-tierdot--<?php echo esc_attr( $action['tier'] ); ?>" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html( self::get_tier_label( $action['tier'] ) ); ?></span>
					</span>

					<span class="wvc-actions__text">
						<strong class="wvc-actions__title"><?php echo esc_html( $action['title'] ); ?></strong>
						<span class="wvc-actions__detail"><?php echo esc_html( $action['detail'] ); ?></span>
					</span>

					<a class="wvc-btn wvc-btn--ghost wvc-btn--sm wvc-actions__cta" href="<?php echo esc_url( $action['url'] ); ?>">
						<?php echo esc_html( $action['action'] ); ?>
						<?php echo self::get_icon( 'arrow-right', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Builds a stacked proportion bar with an accessible text equivalent.
	 *
	 * This replaces the five doughnut charts the overview used to draw. Each one
	 * cost an AJAX round trip and a 200 KB charting library to display three
	 * numbers that were already on the page in three other places.
	 *
	 * @param array<string, int> $counts Counts keyed `ready`, `review`, `blocked`, `pending`.
	 * @return string The bar markup.
	 */
	public static function get_meter( array $counts ) {
		$segments = array(
			'blocked' => 'bad',
			'review'  => 'warn',
			'pending' => 'pending',
			'ready'   => 'ok',
		);

		$total = 0;
		foreach ( array_keys( $segments ) as $key ) {
			$total += (int) ( $counts[ $key ] ?? 0 );
		}

		if ( 0 === $total ) {
			return '<span class="wvc-meter wvc-meter--empty" aria-hidden="true"></span>';
		}

		$bars = '';
		foreach ( $segments as $key => $tone ) {
			$value = (int) ( $counts[ $key ] ?? 0 );

			if ( 0 === $value ) {
				continue;
			}

			$bars .= sprintf(
				'<span class="wvc-meter__part wvc-meter__part--%1$s" style="width:%2$s%%"></span>',
				esc_attr( $tone ),
				esc_attr( (string) round( ( $value / $total ) * 100, 2 ) )
			);
		}

		return '<span class="wvc-meter" aria-hidden="true">' . $bars . '</span>';
	}

	/**
	 * Builds the text equivalent of a meter, e.g. "2 blocked · 5 review · 31 ready".
	 *
	 * @param array<string, int> $counts Counts keyed `ready`, `review`, `blocked`, `pending`.
	 * @return string The sentence.
	 */
	public static function get_meter_legend( array $counts ) {
		$labels = array(
			/* translators: %s: Number of items. */
			'blocked' => __( '%s blocked', 'wp-vip-compatibility' ),
			/* translators: %s: Number of items. */
			'review'  => __( '%s to review', 'wp-vip-compatibility' ),
			/* translators: %s: Number of items. */
			'pending' => __( '%s not scanned', 'wp-vip-compatibility' ),
			/* translators: %s: Number of items. */
			'ready'   => __( '%s ready', 'wp-vip-compatibility' ),
		);

		$parts = array();

		foreach ( $labels as $key => $template ) {
			$value = (int) ( $counts[ $key ] ?? 0 );

			if ( $value > 0 ) {
				$parts[] = sprintf( $template, number_format_i18n( $value ) );
			}
		}

		return empty( $parts ) ? __( 'Nothing to check', 'wp-vip-compatibility' ) : implode( ' · ', $parts );
	}

	/**
	 * Renders a table toolbar: filter groups, search and a live result count.
	 *
	 * Filter groups are declared rather than hard-coded, and their counts are
	 * derived by the browser from the rows themselves. That is deliberate: the
	 * screens used to print a stats strip, a chart legend and a filter tab with
	 * the same three numbers, which then disagreed with each other whenever an
	 * asynchronous scan was still running.
	 *
	 * @param array $args {
	 *     Optional. Toolbar arguments.
	 *
	 *     @type array  $groups        Filter groups: `name`, `label`, `active` and `options`.
	 *     @type string $search_label  Accessible label for the search field.
	 *     @type string $scope         Selector-free identifier tying the toolbar to its table.
	 *     @type bool   $show_search   Whether to render the search field.
	 *     @type bool   $show_progress Whether to render the scan progress bar.
	 * }
	 * @return void
	 */
	public static function render_toolbar( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'groups'        => array(),
				'search_label'  => __( 'Search results', 'wp-vip-compatibility' ),
				'show_search'   => true,
				'show_progress' => false,
			)
		);

		$search_id = 'wvc-search-' . wp_rand( 1000, 9999 );
		?>
		<div class="wvc-toolbar">
			<?php foreach ( $args['groups'] as $group ) : ?>
				<div
					class="wvc-segmented"
					role="group"
					data-filter-group="<?php echo esc_attr( $group['name'] ); ?>"
					aria-label="<?php echo esc_attr( $group['label'] ); ?>"
				>
					<?php foreach ( $group['options'] as $option ) : ?>
						<?php $is_active = ( ( $group['active'] ?? 'all' ) === $option['value'] ); ?>
						<button
							type="button"
							class="<?php echo $is_active ? 'active' : ''; ?>"
							data-filter-value="<?php echo esc_attr( $option['value'] ); ?>"
							aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
						>
							<?php if ( ! empty( $option['dot'] ) ) : ?>
								<span class="wvc-dot wvc-dot--<?php echo esc_attr( $option['dot'] ); ?>" aria-hidden="true"></span>
							<?php endif; ?>
							<span><?php echo esc_html( $option['label'] ); ?></span>
							<span class="wvc-segmented__count" data-count="<?php echo esc_attr( $option['value'] ); ?>"></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( $args['show_search'] ) : ?>
				<div class="wvc-search">
					<?php echo self::get_icon( 'search', array( 'class' => 'wvc-icon wvc-icon--sm wvc-search__icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					<label class="screen-reader-text" for="<?php echo esc_attr( $search_id ); ?>"><?php echo esc_html( $args['search_label'] ); ?></label>
					<input
						type="search"
						id="<?php echo esc_attr( $search_id ); ?>"
						class="wvc-search__input"
						data-role="table-search"
						placeholder="<?php esc_attr_e( 'Search…', 'wp-vip-compatibility' ); ?>"
						autocomplete="off"
						spellcheck="false"
					/>
				</div>
			<?php endif; ?>

			<p class="wvc-result-count" data-role="result-count" role="status" aria-live="polite"></p>
		</div>

		<?php if ( $args['show_progress'] ) : ?>
			<div class="wvc-scan" data-role="scan" hidden>
				<div class="wvc-scan__head">
					<span class="wvc-spinner" aria-hidden="true"></span>
					<span class="wvc-scan__label" data-role="scan-label"></span>
				</div>
				<div class="wvc-scan__track">
					<div class="wvc-scan__bar" data-role="scan-bar" style="width:0%"></div>
				</div>
			</div>
			<?php
		endif;
	}

	/**
	 * Renders a table head from a column definition list.
	 *
	 * @param array $columns List of columns. Each column accepts `label`, `class`,
	 *                       `sortable` and `sort` (text|number) keys.
	 * @return void
	 */
	public static function render_table_head( $columns ) {
		echo '<thead><tr>';

		foreach ( $columns as $index => $column ) {
			$classes = array( 'wvc-th' );

			if ( ! empty( $column['class'] ) ) {
				$classes[] = $column['class'];
			}

			$sortable = ! empty( $column['sortable'] );

			if ( $sortable ) {
				$classes[] = 'is-sortable';
			}

			printf(
				'<th scope="col" class="%1$s"%2$s%3$s>',
				esc_attr( implode( ' ', $classes ) ),
				$sortable ? ' aria-sort="none"' : '',
				$sortable ? ' data-sort-type="' . esc_attr( $column['sort'] ?? 'text' ) . '" data-sort-index="' . esc_attr( (string) $index ) . '"' : ''
			);

			if ( $sortable ) {
				printf(
					'<button type="button" class="wvc-th__sort"><span>%1$s</span>%2$s</button>',
					esc_html( $column['label'] ),
					self::get_icon( 'sort', array( 'class' => 'wvc-icon wvc-icon--xs wvc-th__icon' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
				);
			} else {
				echo '<span>' . esc_html( $column['label'] ) . '</span>';
			}

			echo '</th>';
		}

		echo '</tr></thead>';
	}

	/**
	 * Builds a status pill.
	 *
	 * @param string $state One of `compatible`, `review`, `not-compatible`, `pending`.
	 * @param string $label Visible label.
	 * @return string The pill markup.
	 */
	public static function get_status_pill( $state, $label ) {
		if ( 'pending' === $state ) {
			return sprintf(
				'<span class="wvc-status wvc-status--pending"><span class="wvc-spinner wvc-spinner--xs" aria-hidden="true"></span>%s</span>',
				esc_html( $label )
			);
		}

		$modifiers = array(
			'compatible'     => 'ok',
			'review'         => 'warn',
			'not-compatible' => 'bad',
		);

		$icons = array(
			'compatible'     => 'check',
			'review'         => 'info',
			'not-compatible' => 'alert',
		);

		return sprintf(
			'<span class="wvc-status wvc-status--%1$s">%2$s%3$s</span>',
			esc_attr( $modifiers[ $state ] ?? 'warn' ),
			self::get_icon( $icons[ $state ] ?? 'info', array( 'class' => 'wvc-icon wvc-icon--xs' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
			esc_html( $label )
		);
	}

	/**
	 * Builds a status table cell.
	 *
	 * @param string $state One of `compatible`, `review`, `not-compatible`, `pending`.
	 * @param string $label Visible label.
	 * @param array  $attrs Optional extra attributes for the cell.
	 * @return string The cell markup.
	 */
	public static function get_status_cell( $state, $label, $attrs = array() ) {
		$attr_string = '';

		foreach ( $attrs as $attr => $value ) {
			$attr_string .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $value ) );
		}

		return sprintf(
			'<td class="wvc-col-status is-%1$s"%2$s data-label="%3$s">%4$s</td>',
			esc_attr( $state ),
			$attr_string,
			esc_attr__( 'VIP verdict', 'wp-vip-compatibility' ),
			self::get_status_pill( $state, $label )
		);
	}

	/**
	 * Builds the verdict cell for a scanned target.
	 *
	 * @param array<string, mixed> $verdict A verdict from Report::verdict().
	 * @param string               $target_key The target key, for the async scanner.
	 * @return string The cell markup.
	 */
	public static function get_verdict_cell( array $verdict, $target_key ) {
		$label = $verdict['label'];

		if ( 'pending' !== $verdict['state'] && $verdict['total'] > 0 ) {
			/* translators: 1: Verdict label. 2: Number of findings. */
			$label = sprintf( __( '%1$s (%2$d)', 'wp-vip-compatibility' ), $label, $verdict['total'] );
		}

		return self::get_status_cell( $verdict['state'], $label, array( 'data-target' => $target_key ) );
	}

	/**
	 * Builds a "Review N findings" link to the findings screen.
	 *
	 * @param string $target_key The target key.
	 * @param int    $total      The number of findings.
	 * @return string The link markup, or an empty string when there is nothing to review.
	 */
	public static function get_findings_link( $target_key, $total ) {
		if ( $total <= 0 ) {
			return '';
		}

		return sprintf(
			'<a class="wvc-link" href="%1$s">%2$s%3$s</a>',
			esc_url( self::get_findings_url( $target_key ) ),
			esc_html(
				sprintf(
					/* translators: %d: Number of findings. */
					_n( 'Review %d finding', 'Review %d findings', $total, 'wp-vip-compatibility' ),
					$total
				)
			),
			self::get_icon( 'arrow-right', array( 'class' => 'wvc-icon wvc-icon--xs' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
		);
	}

	/**
	 * Wraps note fragments in the shared notes-list markup.
	 *
	 * @param array<int, string> $parts Pre-built, already-escaped note fragments.
	 * @return string The notes cell contents, or a dash when there is nothing to show.
	 */
	public static function get_notes_list( array $parts ) {
		$parts = array_filter(
			$parts,
			static function ( $part ) {
				return '' !== $part;
			}
		);

		if ( empty( $parts ) ) {
			return '<span class="wvc-dash" aria-hidden="true">—</span>';
		}

		return '<ul class="wvc-notes"><li>' . implode( '</li><li>', $parts ) . '</li></ul>';
	}

	/**
	 * Builds a tier pill.
	 *
	 * @param string $tier A tier slug.
	 * @return string The pill markup.
	 */
	public static function get_tier_pill( $tier ) {
		return sprintf(
			'<span class="wvc-tier wvc-tier--%1$s"><span class="wvc-tierdot wvc-tierdot--%1$s" aria-hidden="true"></span>%2$s</span>',
			esc_attr( $tier ),
			esc_html( self::get_tier_label( $tier ) )
		);
	}

	/**
	 * Builds a finding-type pill.
	 *
	 * @param string $type A Taxonomy type slug.
	 * @return string The pill markup.
	 */
	public static function get_type_pill( $type ) {
		$blocking = Taxonomy::is_blocking_type( $type );

		return sprintf(
			'<span class="wvc-tag wvc-tag--%1$s" title="%3$s">%2$s</span>',
			esc_attr( $blocking ? 'blocking' : 'advisory' ),
			esc_html( Taxonomy::get_label( 'type', $type ) ),
			esc_attr( Taxonomy::get_types()[ $type ]['description'] ?? '' )
		);
	}

	/**
	 * Builds a small labelled metadata chip.
	 *
	 * @param string $label The chip label.
	 * @param string $value The chip value.
	 * @param string $title Optional tooltip.
	 * @return string The chip markup.
	 */
	public static function get_meta_chip( $label, $value, $title = '' ) {
		return sprintf(
			'<span class="wvc-chip"%3$s><span class="wvc-chip__label">%1$s</span><span class="wvc-chip__value">%2$s</span></span>',
			esc_html( $label ),
			esc_html( $value ),
			'' === $title ? '' : ' title="' . esc_attr( $title ) . '"'
		);
	}

	/**
	 * Renders one rule's findings as a single expandable card.
	 *
	 * A rule that fires twenty times in a plugin is one decision with twenty
	 * locations. The card therefore leads with the decision — what this is, how
	 * serious it is, and what to do — and keeps the locations, the code, and the
	 * detection metadata behind a second disclosure, because those answer "prove
	 * it" rather than "what do I do".
	 *
	 * @param array<string, mixed> $group A rule group from Report::group_by_rule().
	 * @return void
	 */
	public static function render_finding_group( array $group ) {
		$confidences = Taxonomy::get_confidences();
		$fixes       = Taxonomy::get_fixabilities();
		$tier        = self::get_tier( $group['severity'] );
		$occurrences = $group['occurrences'];
		$count       = count( $occurrences );
		?>
		<details class="wvc-finding wvc-finding--<?php echo esc_attr( $tier ); ?>"
			data-tier="<?php echo esc_attr( $tier ); ?>"
			data-severity="<?php echo esc_attr( $group['severity'] ); ?>"
			data-type="<?php echo esc_attr( $group['type'] ); ?>"
			data-category="<?php echo esc_attr( $group['category'] ); ?>"
			data-target="<?php echo esc_attr( $group['target_key'] ); ?>">

			<summary class="wvc-finding__summary">
				<span class="wvc-tierdot wvc-tierdot--<?php echo esc_attr( $tier ); ?>" aria-hidden="true"></span>

				<span class="wvc-finding__headline">
					<strong class="wvc-finding__title"><?php echo esc_html( $group['title'] ); ?></strong>
					<span class="wvc-finding__where">
						<?php if ( 1 === $count ) : ?>
							<code><?php echo esc_html( $occurrences[0]['file'] . ':' . $occurrences[0]['line'] ); ?></code>
						<?php else : ?>
							<?php
							printf(
								/* translators: %d: Number of occurrences. */
								esc_html( _n( '%d occurrence', '%d occurrences', $count, 'wp-vip-compatibility' ) ),
								$count
							);
							?>
						<?php endif; ?>
					</span>
				</span>

				<span class="wvc-finding__pills">
					<?php echo self::get_type_pill( $group['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
					<span class="screen-reader-text"><?php echo esc_html( self::get_tier_label( $tier ) ); ?></span>
				</span>
			</summary>

			<div class="wvc-finding__body">
				<dl class="wvc-finding__answer">
					<dt><?php esc_html_e( 'Why it matters on VIP', 'wp-vip-compatibility' ); ?></dt>
					<dd><?php echo esc_html( $group['why'] ); ?></dd>

					<dt><?php esc_html_e( 'Recommended fix', 'wp-vip-compatibility' ); ?></dt>
					<dd><?php echo esc_html( $group['remediation'] ); ?></dd>
				</dl>

				<div class="wvc-occurrences">
					<h4 class="wvc-occurrences__title">
						<?php
						printf(
							/* translators: %d: Number of occurrences. */
							esc_html( _n( '%d location', '%d locations', $count, 'wp-vip-compatibility' ) ),
							$count
						);
						?>
					</h4>

					<ul class="wvc-occurrences__list">
						<?php foreach ( $occurrences as $occurrence ) : ?>
							<li class="wvc-occurrence">
								<p class="wvc-occurrence__where">
									<code><?php echo esc_html( $occurrence['file'] . ':' . $occurrence['line'] ); ?></code>
									<?php if ( '' !== $occurrence['scope'] ) : ?>
										<span class="wvc-occurrence__scope">
											<?php
											printf(
												/* translators: %s: Function or method name. */
												esc_html__( 'in %s()', 'wp-vip-compatibility' ),
												esc_html( $occurrence['scope'] )
											);
											?>
										</span>
									<?php endif; ?>
								</p>

								<?php if ( '' !== $occurrence['evidence'] ) : ?>
									<pre class="wvc-occurrence__evidence"><code><?php echo esc_html( $occurrence['evidence'] ); ?></code></pre>
								<?php endif; ?>

								<?php if ( '' !== $occurrence['note'] ) : ?>
									<p class="wvc-occurrence__note"><?php echo esc_html( $occurrence['note'] ); ?></p>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<details class="wvc-subdetails">
					<summary class="wvc-subdetails__summary"><?php esc_html_e( 'Technical details', 'wp-vip-compatibility' ); ?></summary>

					<div class="wvc-subdetails__body">
						<dl class="wvc-finding__answer">
							<dt><?php esc_html_e( 'What was detected', 'wp-vip-compatibility' ); ?></dt>
							<dd><?php echo esc_html( $group['detected'] ); ?></dd>

							<?php if ( '' !== $group['alternative'] ) : ?>
								<dt><?php esc_html_e( 'Alternative approach', 'wp-vip-compatibility' ); ?></dt>
								<dd><?php echo esc_html( $group['alternative'] ); ?></dd>
							<?php endif; ?>
						</dl>

						<div class="wvc-finding__meta">
							<?php
							$chips = array(
								self::get_meta_chip(
									__( 'Severity', 'wp-vip-compatibility' ),
									Taxonomy::get_label( 'severity', $group['severity'] )
								),
								self::get_meta_chip(
									__( 'Confidence', 'wp-vip-compatibility' ),
									Taxonomy::get_label( 'confidence', $group['confidence'] ),
									$confidences[ $group['confidence'] ]['description'] ?? ''
								),
								self::get_meta_chip(
									__( 'Fix', 'wp-vip-compatibility' ),
									Taxonomy::get_label( 'fixability', $group['fixability'] ),
									$fixes[ $group['fixability'] ]['description'] ?? ''
								),
								self::get_meta_chip(
									__( 'Category', 'wp-vip-compatibility' ),
									Taxonomy::get_label( 'category', $group['category'] )
								),
								self::get_meta_chip( __( 'Rule', 'wp-vip-compatibility' ), $group['rule'] ),
							);

							if ( '' !== $group['phpcs'] ) {
								$chips[] = self::get_meta_chip(
									__( 'PHPCS', 'wp-vip-compatibility' ),
									$group['phpcs'],
									__( 'The WordPress-VIP-Go sniff that reports the same pattern.', 'wp-vip-compatibility' )
								);
							}

							foreach ( $chips as $chip ) {
								echo $chip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value is escaped inside get_meta_chip().
							}
							?>
						</div>

						<?php if ( ! empty( $group['also_matched'] ) ) : ?>
							<p class="wvc-finding__also">
								<?php
								printf(
									/* translators: %s: Comma-separated list of rule identifiers. */
									esc_html__( 'Also matched on these lines: %s', 'wp-vip-compatibility' ),
									esc_html( implode( ', ', $group['also_matched'] ) )
								);
								?>
							</p>
						<?php endif; ?>

						<?php if ( '' !== $group['doc'] ) : ?>
							<p class="wvc-finding__doc">
								<a class="wvc-link" href="<?php echo esc_url( $group['doc'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'WordPress VIP documentation for this rule', 'wp-vip-compatibility' ); ?>
									<?php echo self::get_icon( 'external', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
								</a>
							</p>
						<?php endif; ?>
					</div>
				</details>
			</div>
		</details>
		<?php
	}

	/**
	 * Builds a copyable code snippet.
	 *
	 * @param string $code The snippet contents.
	 * @return string The snippet markup.
	 */
	public static function get_code_snippet( $code ) {
		return sprintf(
			'<span class="wvc-snippet"><code>%1$s</code><button type="button" class="wvc-snippet__copy" data-role="copy" data-clipboard="%2$s" aria-label="%3$s" title="%3$s">%4$s</button></span>',
			esc_html( $code ),
			esc_attr( $code ),
			esc_attr__( 'Copy to clipboard', 'wp-vip-compatibility' ),
			self::get_icon( 'copy', array( 'class' => 'wvc-icon wvc-icon--xs' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
		);
	}

	/**
	 * Renders an inline notice.
	 *
	 * @param string $message Notice body (may contain limited HTML).
	 * @param string $type    One of `info`, `success`, `warning`.
	 * @param string $title   Optional bold lead-in.
	 * @return string The notice markup.
	 */
	public static function get_notice( $message, $type = 'info', $title = '' ) {
		$icons = array(
			'info'    => 'info',
			'success' => 'check',
			'warning' => 'alert',
		);

		return sprintf(
			'<div class="wvc-notice wvc-notice--%1$s">%2$s<div class="wvc-notice__body">%3$s%4$s</div></div>',
			esc_attr( $type ),
			self::get_icon( $icons[ $type ] ?? 'info', array( 'class' => 'wvc-icon wvc-icon--sm wvc-notice__icon' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
			$title ? '<strong class="wvc-notice__title">' . esc_html( $title ) . '</strong>' : '',
			wp_kses_post( $message )
		);
	}

	/**
	 * Builds a reference notice that starts collapsed.
	 *
	 * Unlike get_notice(), which is for live status, this is for background
	 * reading — "how to read this list", "how this migrates" — that shouldn't
	 * compete with the screen's actual data on every visit, but should stay one
	 * click away.
	 *
	 * @param string $message The notice body (may contain limited HTML).
	 * @param string $title   The summary label.
	 * @return string The notice markup.
	 */
	public static function get_guidance( $message, $title ) {
		return sprintf(
			'<details class="wvc-notice wvc-notice--info wvc-notice--collapsible"><summary class="wvc-notice__summary">%1$s<span class="wvc-notice__title">%2$s</span></summary><div class="wvc-notice__body">%3$s</div></details>',
			self::get_icon( 'info', array( 'class' => 'wvc-icon wvc-icon--sm wvc-notice__icon' ) ),
			esc_html( $title ),
			wp_kses_post( $message )
		);
	}

	/**
	 * Renders an empty state block.
	 *
	 * @param string $title   Headline.
	 * @param string $message Supporting copy.
	 * @param array  $action  Optional call to action, as accepted by get_action_button().
	 * @return void
	 */
	public static function render_empty_state( $title, $message = '', $action = array() ) {
		?>
		<div class="wvc-empty">
			<span class="wvc-empty__icon" aria-hidden="true">
				<?php echo self::get_icon( 'inbox' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
			</span>
			<p class="wvc-empty__title"><?php echo esc_html( $title ); ?></p>
			<?php if ( $message ) : ?>
				<p class="wvc-empty__message"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $action ) ) : ?>
				<p class="wvc-empty__action">
					<?php echo self::get_action_button( $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
