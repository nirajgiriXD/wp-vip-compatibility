<?php
/**
 * Shared presentation layer for the plugin admin screens.
 *
 * The interface is an application shell rather than a stack of WordPress
 * settings pages: a masthead that carries identity, scan state and the two
 * global actions; a persistent section rail that says where you are and where
 * the work is; and a content column that every screen fills through the same
 * primitives — stat rows, panels, tables, disclosures.
 *
 * Four rules hold the whole thing together:
 *
 * - One vocabulary for consequence. The four tiers — must fix, should fix,
 *   worth checking, FYI — are the only words used for it, on every screen. The
 *   axes they are derived from (severity, type, confidence) are the plugin's
 *   reasoning, so they live inside a finding's technical disclosure and nowhere
 *   else. A reader should never have to learn that "critical", "incompatible"
 *   and "blocked" are three different scales.
 * - The words are instructions. A label that says what to do needs no legend,
 *   which is why the tiers are verbs rather than classifications.
 * - One place per number. A count is rendered where it is acted on — in the
 *   filter that selects it, or the tile that links to it — never three times.
 *   Where a filter shows a count, that count is what clicking it yields.
 * - Depth through disclosure, not through deletion. What to do is always
 *   visible, the evidence for it sits one interaction away, and how the scanner
 *   reached it sits two.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Rules;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Scanner;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the shared admin interface building blocks.
 */
class UI {

	/**
	 * Incrementing id source for disclosure controls.
	 *
	 * @var int
	 */
	private static $sequence = 0;

	/* ---------------------------------------------------------------------
	 * Screens and routing
	 * ------------------------------------------------------------------ */

	/**
	 * Returns the section groups the rail is organised by.
	 *
	 * @return array<string, string> Group labels keyed by group slug.
	 */
	public static function get_groups() {
		return array(
			'summary' => __( 'Readiness', 'wp-vip-compatibility' ),
			'code'    => __( 'Code you ship', 'wp-vip-compatibility' ),
			'site'    => __( 'Site infrastructure', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Returns the map of plugin screens.
	 *
	 * Each subject gets its own screen again. Plugins, themes and must-use
	 * plugins share a table shape but not a workflow — you decide about a plugin
	 * by replacing it, about a theme by rewriting it, and about a must-use plugin
	 * by moving it — and a single merged list made all three harder to work
	 * through than any of them had been alone.
	 *
	 * @return array<string, array<string, string>> Screen definitions keyed by settings key.
	 */
	public static function get_screens() {
		return array(
			'overview'    => array(
				'slug'        => 'wp-vip-compatibility',
				'label'       => __( 'Overview', 'wp-vip-compatibility' ),
				'title'       => __( 'Overview', 'wp-vip-compatibility' ),
				'description' => __( 'How ready this site is for the WordPress VIP Platform, where the work is, and what to do first.', 'wp-vip-compatibility' ),
				'icon'        => 'gauge',
				'group'       => 'summary',
			),
			'findings'    => array(
				'slug'        => 'wvc-findings',
				'label'       => __( 'Findings', 'wp-vip-compatibility' ),
				'title'       => __( 'Findings', 'wp-vip-compatibility' ),
				'description' => __( 'Everything that needs doing before this site moves, worst first, with why it matters and how to fix it.', 'wp-vip-compatibility' ),
				'icon'        => 'list',
				'group'       => 'summary',
			),
			'plugins'     => array(
				'slug'        => 'wvc-plugins',
				'label'       => __( 'Plugins', 'wp-vip-compatibility' ),
				'title'       => __( 'Plugins', 'wp-vip-compatibility' ),
				'description' => __( 'Every installed plugin with its WordPress VIP verdict, update state and the work it implies.', 'wp-vip-compatibility' ),
				'icon'        => 'plug',
				'group'       => 'code',
			),
			'themes'      => array(
				'slug'        => 'wvc-themes',
				'label'       => __( 'Themes', 'wp-vip-compatibility' ),
				'title'       => __( 'Themes', 'wp-vip-compatibility' ),
				'description' => __( 'The active theme in detail, and every other theme that still ships in the repository.', 'wp-vip-compatibility' ),
				'icon'        => 'brush',
				'group'       => 'code',
			),
			'mu-plugins'  => array(
				'slug'        => 'wvc-mu-plugins',
				'label'       => __( 'Must-use', 'wp-vip-compatibility' ),
				'title'       => __( 'Must-use plugins', 'wp-vip-compatibility' ),
				'description' => __( 'WordPress VIP reserves wp-content/mu-plugins for platform code, so everything here needs a decision.', 'wp-vip-compatibility' ),
				'icon'        => 'bolt',
				'group'       => 'code',
			),
			'database'    => array(
				'slug'        => 'wvc-database',
				'label'       => __( 'Database', 'wp-vip-compatibility' ),
				'title'       => __( 'Database', 'wp-vip-compatibility' ),
				'description' => __( 'The schema measured against the storage engine, collation and prefix WordPress VIP requires on import.', 'wp-vip-compatibility' ),
				'icon'        => 'database',
				'group'       => 'site',
			),
			'directories' => array(
				'slug'        => 'wvc-directories',
				'label'       => __( 'wp-content', 'wp-vip-compatibility' ),
				'title'       => __( 'wp-content layout', 'wp-vip-compatibility' ),
				'description' => __( 'What sits in wp-content today, and how each entry maps onto the WordPress VIP application structure.', 'wp-vip-compatibility' ),
				'icon'        => 'folder',
				'group'       => 'site',
			),
		);
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
	 * Returns the screen key that lists a given target type.
	 *
	 * @param string $type One of `plugin`, `theme`, `mu-plugin`.
	 * @return string The settings key.
	 */
	public static function get_screen_for_type( $type ) {
		$map = array(
			'plugin'    => 'plugins',
			'theme'     => 'themes',
			'mu-plugin' => 'mu-plugins',
		);

		return $map[ $type ] ?? 'plugins';
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

		return self::get_screen_url( 'findings', $args );
	}

	/* ---------------------------------------------------------------------
	 * Severity vocabulary
	 * ------------------------------------------------------------------ */

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
				'tone' => 'bad',
			),
			Taxonomy::TIER_IMPORTANT => array(
				'icon' => 'alert',
				'open' => true,
				'tone' => 'warn',
			),
			Taxonomy::TIER_WARNING   => array(
				'icon' => 'info',
				'open' => false,
				'tone' => 'notice',
			),
			Taxonomy::TIER_INFO      => array(
				'icon' => 'info',
				'open' => false,
				'tone' => 'muted',
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

	/* ---------------------------------------------------------------------
	 * Icons
	 * ------------------------------------------------------------------ */

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
			'chevron'     => '<path d="m8 5 7 7-7 7"/>',
			'chevron-down' => '<path d="m5 9 7 7 7-7"/>',
			'sort'        => '<path d="m8 9 4-4 4 4"/><path d="m8 15 4 4 4-4"/>',
			'inbox'       => '<path d="M3 13h4l1.5 3h7L17 13h4"/><path d="M5.5 5h13l2.5 8v5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18v-5Z"/>',
			'shield'      => '<path d="M12 3 5 6v6c0 4.3 2.9 7.8 7 9 4.1-1.2 7-4.7 7-9V6Z"/><path d="m9 12 2 2 4-4"/>',
			'list'        => '<path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
			'download'    => '<path d="M12 3v12"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4 19.5h16"/>',
			'refresh'     => '<path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M19.5 11a7.5 7.5 0 0 0-13-3.5L4 10"/><path d="M4.5 13a7.5 7.5 0 0 0 13 3.5L20 14"/>',
			'book'        => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H5.5A1.5 1.5 0 0 1 4 16.5Z"/><path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H14a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h4.5a1.5 1.5 0 0 0 1.5-1.5Z"/>',
			'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
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

	/* ---------------------------------------------------------------------
	 * Application shell
	 * ------------------------------------------------------------------ */

	/**
	 * Opens the application shell: masthead and content column.
	 *
	 * There is no section navigation of our own. WordPress already lists every
	 * screen in the admin menu, and a second list of the same seven links beside
	 * it was one navigation too many — it cost a fifth of the width on every
	 * screen to repeat what was already on the left.
	 *
	 * @return void
	 */
	public static function render_shell_open() {
		echo '<div class="wrap wvc-wrap">';
		echo '<div class="wvc-app">';

		self::render_masthead();

		echo '<main class="wvc-main" id="wvc-main">';
	}

	/**
	 * Closes the application shell.
	 *
	 * @return void
	 */
	public static function render_shell_close() {
		echo '</main></div></div>';
	}

	/**
	 * Renders the masthead: identity, scan state and the two global actions.
	 *
	 * Rescanning and exporting are properties of the whole report rather than of
	 * any one screen, so they live here and are reachable from all of them,
	 * instead of being duplicated into every page header.
	 *
	 * @return void
	 */
	private static function render_masthead() {
		$aggregate = Report::aggregate();
		$scanned   = $aggregate['targets'] > 0 && $aggregate['scanned_at'] > 0;
		?>
		<header class="wvc-masthead">
			<div class="wvc-masthead__identity">
				<span class="wvc-masthead__mark" aria-hidden="true">
					<?php echo self::get_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
				</span>
				<span class="wvc-masthead__text">
					<h1 class="wvc-masthead__title"><?php esc_html_e( 'WordPress VIP Compatibility', 'wp-vip-compatibility' ); ?></h1>
					<span class="wvc-masthead__meta">
						<?php
						printf(
							/* translators: 1: Rule set version. 2: Number of rules. */
							esc_html__( 'Rule set %1$s · %2$d rules', 'wp-vip-compatibility' ),
							esc_html( Rules::VERSION ),
							count( Rules::all() )
						);
						?>
					</span>
				</span>
			</div>

			<div class="wvc-masthead__actions">
				<span class="wvc-scanstate<?php echo $scanned ? '' : ' is-empty'; ?>">
					<?php echo self::get_icon( 'clock', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					<span>
						<?php
						if ( $scanned ) {
							printf(
								/* translators: %s: Human-readable time difference. */
								esc_html__( 'Scanned %s ago', 'wp-vip-compatibility' ),
								esc_html( human_time_diff( $aggregate['scanned_at'] ) )
							);
						} else {
							esc_html_e( 'Never scanned', 'wp-vip-compatibility' );
						}
						?>
					</span>
				</span>

				<?php self::render_rescan_control( $scanned ); ?>

				<details class="wvc-menu">
					<summary class="wvc-btn wvc-btn--ghost wvc-btn--sm">
						<?php echo self::get_icon( 'download', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<span><?php esc_html_e( 'Export', 'wp-vip-compatibility' ); ?></span>
					</summary>
					<div class="wvc-menu__panel">
						<p class="wvc-menu__title"><?php esc_html_e( 'Download the full report', 'wp-vip-compatibility' ); ?></p>
						<?php foreach ( Export::get_formats() as $slug => $format ) : ?>
							<a class="wvc-menu__item" href="<?php echo esc_url( Export::get_url( $slug ) ); ?>">
								<span class="wvc-menu__label"><?php echo esc_html( $format['label'] ); ?></span>
								<span class="wvc-menu__hint"><?php echo esc_html( $format['description'] ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</details>

				<a class="wvc-btn wvc-btn--ghost wvc-btn--sm" href="https://docs.wpvip.com/" target="_blank" rel="noopener noreferrer">
					<?php echo self::get_icon( 'book', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					<span><?php esc_html_e( 'WordPress VIP docs', 'wp-vip-compatibility' ); ?></span>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'wp-vip-compatibility' ); ?></span>
				</a>
			</div>
		</header>
		<?php
	}

	/**
	 * Renders the rescan control: one press for everything, a caret for one area.
	 *
	 * A full rescan re-reads every PHP file the site ships, and on a large
	 * codebase that is minutes of waiting to confirm a change in one plugin. The
	 * areas are therefore offered individually — but behind a caret rather than
	 * as six equal buttons, because "rescan everything" is what is wanted almost
	 * every time and it should stay a single press.
	 *
	 * The options are buttons in a form rather than the links the export menu
	 * uses. Exporting reads; rescanning writes, and a write does not belong on a
	 * link that a prefetcher or a bookmark can fire.
	 *
	 * @param bool $scanned Whether anything has been scanned yet.
	 * @return void
	 */
	private static function render_rescan_control( $scanned ) {
		$button = self::get_action_button(
			array(
				'label'   => $scanned ? __( 'Rescan', 'wp-vip-compatibility' ) : __( 'Run a scan', 'wp-vip-compatibility' ),
				'submit'  => 'wvc_rescan',
				'nonce'   => Findings_Settings::RESCAN_ACTION,
				'fields'  => array( 'scope' => Report::SCOPE_ALL ),
				'icon'    => 'refresh',
				'primary' => ! $scanned,
			)
		);

		// Before the first scan every area is empty, so there is nothing to
		// narrow to and the control is the plain button it has always been.
		if ( ! $scanned ) {
			echo $button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

			return;
		}

		// "Everything" is the button beside this menu, not a row inside it.
		$areas = Report::get_scopes();
		unset( $areas[ Report::SCOPE_ALL ] );
		?>
		<div class="wvc-split">
			<?php echo $button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>

			<details class="wvc-menu wvc-split__more">
				<summary class="wvc-btn wvc-btn--ghost wvc-btn--sm wvc-split__caret">
					<?php echo self::get_icon( 'chevron-down', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					<span class="screen-reader-text"><?php esc_html_e( 'Rescan one area only', 'wp-vip-compatibility' ); ?></span>
				</summary>

				<div class="wvc-menu__panel">
					<p class="wvc-menu__title"><?php esc_html_e( 'Rescan one area only', 'wp-vip-compatibility' ); ?></p>

					<?php // Not wvc-inline-form: that class lays a form out as one inline row, which is right for a single button and turns a list of them into a row. ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wvc-menu__form">
						<input type="hidden" name="action" value="wvc_rescan" />
						<?php wp_nonce_field( Findings_Settings::RESCAN_ACTION ); ?>

						<?php foreach ( $areas as $slug => $scope ) : ?>
							<button type="submit" class="wvc-menu__item" name="scope" value="<?php echo esc_attr( $slug ); ?>">
								<span class="wvc-menu__label"><?php echo esc_html( $scope['label'] ); ?></span>
								<span class="wvc-menu__hint"><?php echo esc_html( $scope['hint'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</form>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Renders the page heading.
	 *
	 * The group name above the title is what the admin menu cannot say: the menu
	 * lists seven flat entries, and this places the one you are on inside the part
	 * of the job it belongs to.
	 *
	 * @param string $current The current settings key.
	 * @return void
	 */
	public static function render_page_head( $current ) {
		$screens = self::get_screens();

		if ( ! isset( $screens[ $current ] ) ) {
			return;
		}

		$screen = $screens[ $current ];
		$groups = self::get_groups();
		?>
		<div class="wvc-page-head">
			<div class="wvc-page-head__text">
				<p class="wvc-page-head__eyebrow"><?php echo esc_html( $groups[ $screen['group'] ] ?? '' ); ?></p>
				<h2 class="wvc-page-head__title"><?php echo esc_html( $screen['title'] ); ?></h2>
				<p class="wvc-page-head__description"><?php echo esc_html( $screen['description'] ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Confirms a completed rescan.
	 *
	 * Rescanning is reachable from every screen and returns you to the one you
	 * were on, so the confirmation belongs to the shell rather than to any one
	 * screen — otherwise a scan started from the database audit would finish
	 * without ever saying so.
	 *
	 * @return void
	 */
	public static function render_scan_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Presentation only; the scan itself was nonce-checked in Findings_Settings::handle_rescan().
		$scanned  = isset( $_GET['wvc-scanned'] ) ? sanitize_key( wp_unslash( $_GET['wvc-scanned'] ) ) : '';
		$scanning = isset( $_GET['wvc-scanning'] ) ? sanitize_key( wp_unslash( $_GET['wvc-scanning'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/*
		 * A rescan that runs in the browser arrives here with nothing scanned
		 * yet, so there is nothing to confirm. The screen marks itself as mid-
		 * rescan instead: the rows below are already unscanned, the queue on the
		 * page resolves them behind the progress bar, and the page reloads onto
		 * `wvc-scanned` when it is done — which is the branch below, and which is
		 * also what makes the tiles, the menu badge and the masthead timestamp
		 * agree with the rows again.
		 */
		if ( '' !== $scanning ) {
			$scanning = Report::resolve_scope( $scanning );

			// The wrapper is what the queue on the page looks for; the notice
			// inside it is what says so to the reader. The second sentence is
			// there because the tiles above this read zero until the reload, and
			// a zero nobody explained looks like a result rather than a wait.
			printf(
				'<div data-role="scan-report" data-scope="%1$s">%2$s</div>',
				esc_attr( $scanning ),
				self::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					esc_html__( 'Each row below is being read in turn. The totals above them fill in once it finishes.', 'wp-vip-compatibility' ),
					'info',
					esc_html(
						sprintf(
							/* translators: %s: The area being rescanned, e.g. "Plugins". */
							__( 'Rescanning %s', 'wp-vip-compatibility' ),
							Report::get_scope( $scanning )['label']
						)
					)
				)
			);

			return;
		}

		if ( '' === $scanned ) {
			return;
		}

		/*
		 * The parameter carries the scope that was rescanned, so the confirmation
		 * reports what was read rather than the site-wide totals. A link from an
		 * earlier version carries `1`, which is not a scope and resolves to the
		 * whole site — the message it used to produce.
		 */
		echo self::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html( Report::scope_outcome( Report::resolve_scope( $scanned ) ) ),
			'success',
			esc_html__( 'Scan complete', 'wp-vip-compatibility' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Buttons and links
	 * ------------------------------------------------------------------ */

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
	 *     @type array  $fields  Extra hidden fields, as name => value, for a form button.
	 *     @type string $icon    Optional icon name.
	 *     @type bool   $primary Whether it is the primary action.
	 *     @type bool   $small   Whether to render the compact size.
	 *     @type bool   $external Whether the link opens in a new tab.
	 * }
	 * @return string The button markup.
	 */
	public static function get_action_button( array $action ) {
		$action = wp_parse_args(
			$action,
			array(
				'label'    => '',
				'url'      => '',
				'submit'   => '',
				'nonce'    => '',
				'fields'   => array(),
				'icon'     => '',
				'primary'  => false,
				'small'    => true,
				'external' => false,
			)
		);

		$classes = 'wvc-btn ' . ( $action['primary'] ? 'wvc-btn--primary' : 'wvc-btn--ghost' );

		if ( $action['small'] ) {
			$classes .= ' wvc-btn--sm';
		}

		$icon = '' === $action['icon'] ? '' : self::get_icon( $action['icon'], array( 'class' => 'wvc-icon wvc-icon--sm' ) );

		if ( '' !== $action['submit'] ) {
			$fields = '';

			foreach ( (array) $action['fields'] as $name => $value ) {
				$fields .= sprintf(
					'<input type="hidden" name="%1$s" value="%2$s" />',
					esc_attr( $name ),
					esc_attr( $value )
				);
			}

			return sprintf(
				'<form method="post" action="%1$s" class="wvc-inline-form"><input type="hidden" name="action" value="%2$s" />%3$s%4$s<button type="submit" class="%5$s">%6$s<span>%7$s</span></button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $action['submit'] ),
				$fields,
				wp_nonce_field( $action['nonce'], '_wpnonce', true, false ),
				esc_attr( $classes ),
				$icon,
				esc_html( $action['label'] )
			);
		}

		return sprintf(
			'<a class="%1$s" href="%2$s"%5$s>%3$s<span>%4$s</span></a>',
			esc_attr( $classes ),
			esc_url( $action['url'] ),
			$icon,
			esc_html( $action['label'] ),
			$action['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''
		);
	}

	/* ---------------------------------------------------------------------
	 * Layout primitives
	 * ------------------------------------------------------------------ */

	/**
	 * Renders a row of headline statistics.
	 *
	 * A tile is the top of the hierarchy on every screen: one number, one label,
	 * one line of context, and — where the number stands for work — the link that
	 * takes you to it. Tiles are never used for values nobody acts on.
	 *
	 * @param array<int, array<string, mixed>> $stats {
	 *     Tiles to render.
	 *
	 *     @type string $label Short label.
	 *     @type string $value The headline value.
	 *     @type string $meta  Optional supporting line.
	 *     @type string $tone  One of `ok`, `warn`, `bad`, `accent`, `neutral`.
	 *     @type string $url   Optional destination.
	 * }
	 * @param string                           $modifier Optional extra class, e.g. `wvc-stats--compact`.
	 * @return void
	 */
	public static function render_stats( array $stats, $modifier = '' ) {
		if ( empty( $stats ) ) {
			return;
		}
		?>
		<ul class="<?php echo esc_attr( trim( 'wvc-stats ' . $modifier ) ); ?>">
			<?php foreach ( $stats as $stat ) : ?>
				<?php
				$stat = wp_parse_args(
					$stat,
					array(
						'label' => '',
						'value' => '',
						'meta'  => '',
						'tone'  => 'neutral',
						'url'   => '',
					)
				);

				$tag        = ( '' === $stat['url'] ) ? 'div' : 'a';
				$attributes = ( '' === $stat['url'] ) ? '' : ' href="' . esc_url( $stat['url'] ) . '"';
				?>
				<li class="wvc-stat wvc-stat--<?php echo esc_attr( $stat['tone'] ); ?><?php echo ( '' === $stat['url'] ) ? '' : ' is-linked'; ?>">
					<<?php echo esc_html( $tag ); ?> class="wvc-stat__inner"<?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>>
						<span class="wvc-stat__label"><?php echo esc_html( $stat['label'] ); ?></span>
						<span class="wvc-stat__value"><?php echo esc_html( $stat['value'] ); ?></span>
						<?php if ( '' !== $stat['meta'] ) : ?>
							<span class="wvc-stat__meta"><?php echo esc_html( $stat['meta'] ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $stat['url'] ) : ?>
							<?php echo self::get_icon( 'arrow-right', array( 'class' => 'wvc-icon wvc-icon--xs wvc-stat__chevron' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<?php endif; ?>
					</<?php echo esc_html( $tag ); ?>>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Opens a titled panel.
	 *
	 * @param array $args {
	 *     Panel arguments.
	 *
	 *     @type string $title    The heading.
	 *     @type string $count    Optional count rendered next to the heading.
	 *     @type string $summary  Optional one-line explanation.
	 *     @type array  $actions  Optional actions, as accepted by get_action_button().
	 *     @type bool   $flush    Whether the body should have no padding (tables).
	 *     @type string $modifier Optional extra class.
	 * }
	 * @return void
	 */
	public static function render_panel_open( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'    => '',
				'count'    => '',
				'summary'  => '',
				'actions'  => array(),
				'flush'    => false,
				'modifier' => '',
			)
		);
		?>
		<section class="<?php echo esc_attr( trim( 'wvc-panel ' . $args['modifier'] ) ); ?>">
			<?php if ( '' !== $args['title'] ) : ?>
				<header class="wvc-panel__head">
					<div class="wvc-panel__heading">
						<h3 class="wvc-panel__title">
							<?php echo esc_html( $args['title'] ); ?>
							<?php if ( '' !== $args['count'] ) : ?>
								<span class="wvc-panel__count"><?php echo esc_html( $args['count'] ); ?></span>
							<?php endif; ?>
						</h3>
						<?php if ( '' !== $args['summary'] ) : ?>
							<p class="wvc-panel__summary"><?php echo esc_html( $args['summary'] ); ?></p>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $args['actions'] ) ) : ?>
						<div class="wvc-panel__actions">
							<?php
							foreach ( $args['actions'] as $action ) {
								echo self::get_action_button( $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
							}
							?>
						</div>
					<?php endif; ?>
				</header>
			<?php endif; ?>

			<div class="wvc-panel__body<?php echo $args['flush'] ? ' wvc-panel__body--flush' : ''; ?>">
		<?php
	}

	/**
	 * Closes a panel.
	 *
	 * @return void
	 */
	public static function render_panel_close() {
		echo '</div></section>';
	}

	/**
	 * Builds a definition grid.
	 *
	 * Used wherever a set of labelled values belongs together — the environment
	 * summary, a row's expanded detail — and is read rather than scanned.
	 *
	 * @param array<int, array<string, string>> $rows     Each with `label`, `value` and optional `hint` and `tone`.
	 * @param string                            $modifier Optional extra class.
	 * @return string The grid markup.
	 */
	public static function get_defs( array $rows, $modifier = '' ) {
		if ( empty( $rows ) ) {
			return '';
		}

		$items = '';

		foreach ( $rows as $row ) {
			$tone  = empty( $row['tone'] ) ? '' : ' wvc-def--' . $row['tone'];
			$hint  = empty( $row['hint'] ) ? '' : '<span class="wvc-def__hint">' . esc_html( $row['hint'] ) . '</span>';
			$value = empty( $row['html'] ) ? esc_html( (string) ( $row['value'] ?? '' ) ) : $row['html'];

			$items .= sprintf(
				'<div class="wvc-def%1$s"><dt class="wvc-def__label">%2$s</dt><dd class="wvc-def__value">%3$s%4$s</dd></div>',
				esc_attr( $tone ),
				esc_html( (string) ( $row['label'] ?? '' ) ),
				$value,
				$hint
			);
		}

		return '<dl class="' . esc_attr( trim( 'wvc-defs ' . $modifier ) ) . '">' . $items . '</dl>';
	}

	/* ---------------------------------------------------------------------
	 * Verdict, actions and proportion bars
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the verdict hero: the one thing the overview leads with.
	 *
	 * @param array $args {
	 *     Verdict arguments.
	 *
	 *     @type int    $score    Readiness percentage, or -1 when nothing is scanned.
	 *     @type string $tier     Tier driving the accent colour.
	 *     @type string $headline Short verdict, e.g. "Not ready to migrate".
	 *     @type string $summary  One sentence saying what that means.
	 *     @type string $meta     Optional supporting line.
	 *     @type array  $actions  List of links or forms.
	 *     @type array  $facts    Optional list of `label`/`value` pairs shown beside the gauge.
	 * }
	 * @return void
	 */
	public static function render_verdict( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'score'    => -1,
				// `ready` rather than a tier slug: the accent is the hero's own
				// four-way scale, and `info` has no colour defined for it here.
				'tier'     => 'ready',
				'headline' => '',
				'summary'  => '',
				'meta'     => '',
				'actions'  => array(),
				'facts'    => array(),
			)
		);

		$scored      = $args['score'] >= 0;
		$radius      = 54;
		$circumfence = 2 * M_PI * $radius;
		?>
		<section class="wvc-verdict wvc-verdict--<?php echo esc_attr( $args['tier'] ); ?>" aria-labelledby="wvc-verdict-title">
			<div class="wvc-verdict__gauge">
				<div class="wvc-gauge" role="img" aria-label="<?php echo esc_attr( $scored ? sprintf( /* translators: %d: Percentage. */ __( '%d%% ready', 'wp-vip-compatibility' ), (int) $args['score'] ) : __( 'Not scanned yet', 'wp-vip-compatibility' ) ); ?>">
					<svg viewBox="0 0 128 128" aria-hidden="true" focusable="false">
						<circle class="wvc-gauge__track" cx="64" cy="64" r="<?php echo esc_attr( (string) $radius ); ?>"></circle>
						<?php if ( $scored ) : ?>
							<circle
								class="wvc-gauge__value"
								cx="64" cy="64" r="<?php echo esc_attr( (string) $radius ); ?>"
								style="stroke-dasharray: <?php echo esc_attr( ( ( (int) $args['score'] / 100 ) * $circumfence ) . ' ' . $circumfence ); ?>"
							></circle>
						<?php endif; ?>
					</svg>
					<span class="wvc-gauge__readout">
						<span class="wvc-gauge__score"><?php echo $scored ? esc_html( (string) (int) $args['score'] ) : '–'; ?><?php echo $scored ? '<span class="wvc-gauge__unit">%</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
						<span class="wvc-gauge__caption"><?php esc_html_e( 'Ready', 'wp-vip-compatibility' ); ?></span>
					</span>
				</div>
			</div>

			<div class="wvc-verdict__body">
				<h3 class="wvc-verdict__headline" id="wvc-verdict-title"><?php echo esc_html( $args['headline'] ); ?></h3>
				<p class="wvc-verdict__summary"><?php echo esc_html( $args['summary'] ); ?></p>

				<?php if ( ! empty( $args['actions'] ) ) : ?>
					<div class="wvc-verdict__actions">
						<?php
						foreach ( $args['actions'] as $action ) {
							echo self::get_action_button( array_merge( $action, array( 'small' => false ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
						}
						?>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $args['meta'] ) : ?>
					<p class="wvc-verdict__meta"><?php echo esc_html( $args['meta'] ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $args['facts'] ) ) : ?>
				<?php
				/*
				 * These four are the plugin's headline numbers, and they mean the
				 * same thing everywhere they appear: all outstanding work, by what
				 * to do about it. The caption names the unit so they can never be
				 * read as a count of plugins, themes or files.
				 */
				?>
				<div class="wvc-verdict__factsblock">
				<p class="wvc-verdict__facts-caption"><?php esc_html_e( 'Outstanding work', 'wp-vip-compatibility' ); ?></p>
				<ul class="wvc-verdict__facts">
					<?php foreach ( $args['facts'] as $fact ) : ?>
						<li class="wvc-verdict__fact wvc-verdict__fact--<?php echo esc_attr( $fact['tone'] ?? 'neutral' ); ?>">
							<?php if ( ! empty( $fact['url'] ) ) : ?>
								<a href="<?php echo esc_url( $fact['url'] ); ?>">
									<span class="wvc-verdict__fact-value"><?php echo esc_html( $fact['value'] ); ?></span>
									<span class="wvc-verdict__fact-label"><?php echo esc_html( $fact['label'] ); ?></span>
								</a>
							<?php else : ?>
								<span class="wvc-verdict__fact-value"><?php echo esc_html( $fact['value'] ); ?></span>
								<span class="wvc-verdict__fact-label"><?php echo esc_html( $fact['label'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Renders a numbered strip of steps.
	 *
	 * Used once, on a site that has never been scanned, where the interface has
	 * no data to explain itself with. Three sentences about how the plugin is
	 * meant to be used are worth more at that moment than any amount of empty
	 * chrome, and they disappear the moment there is a real report to show.
	 *
	 * @param array<int, array<string, string>> $steps Each with `title` and `body`.
	 * @return void
	 */
	public static function render_steps( array $steps ) {
		if ( empty( $steps ) ) {
			return;
		}
		?>
		<ol class="wvc-steps">
			<?php foreach ( $steps as $position => $step ) : ?>
				<li class="wvc-step">
					<span class="wvc-step__rank" aria-hidden="true"><?php echo esc_html( (string) ( $position + 1 ) ); ?></span>
					<span class="wvc-step__text">
						<strong class="wvc-step__title"><?php echo esc_html( $step['title'] ); ?></strong>
						<span class="wvc-step__body"><?php echo esc_html( $step['body'] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}


	/**
	 * Builds a stacked proportion bar.
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
	 * Builds the legend of a meter as a set of counted chips.
	 *
	 * @param array<string, int> $counts Counts keyed `ready`, `review`, `blocked`, `pending`.
	 * @param string             $url    Optional base URL; chips link to it filtered by status.
	 * @return string The legend markup.
	 */
	public static function get_meter_legend( array $counts, $url = '' ) {
		$labels = array(
			'blocked' => array( __( 'Blocked', 'wp-vip-compatibility' ), 'bad', 'not-compatible' ),
			'review'  => array( __( 'Review', 'wp-vip-compatibility' ), 'warn', 'review' ),
			'pending' => array( __( 'Not scanned', 'wp-vip-compatibility' ), 'pending', 'pending' ),
			'ready'   => array( __( 'Ready', 'wp-vip-compatibility' ), 'ok', 'compatible' ),
		);

		$parts = '';

		foreach ( $labels as $key => $definition ) {
			$value = (int) ( $counts[ $key ] ?? 0 );

			if ( 0 === $value ) {
				continue;
			}

			$body = sprintf(
				'<span class="wvc-dot wvc-dot--%1$s" aria-hidden="true"></span><span class="wvc-legend__value">%2$s</span><span class="wvc-legend__label">%3$s</span>',
				esc_attr( $definition[1] ),
				esc_html( number_format_i18n( $value ) ),
				esc_html( $definition[0] )
			);

			$parts .= ( '' === $url )
				? '<li class="wvc-legend__item">' . $body . '</li>'
				: sprintf(
					'<li class="wvc-legend__item"><a href="%1$s">%2$s</a></li>',
					esc_url( add_query_arg( 'status', $definition[2], $url ) ),
					$body
				);
		}

		if ( '' === $parts ) {
			return '<p class="wvc-legend wvc-legend--empty">' . esc_html__( 'Nothing to check', 'wp-vip-compatibility' ) . '</p>';
		}

		return '<ul class="wvc-legend">' . $parts . '</ul>';
	}

	/**
	 * Renders the per-area cards used by the overview.
	 *
	 * @param array<int, array<string, mixed>> $areas Areas from Report::areas().
	 * @return void
	 */
	public static function render_area_cards( array $areas ) {
		?>
		<ul class="wvc-areas">
			<?php foreach ( $areas as $area ) : ?>
				<?php
				$counts    = $area['counts'];
				$url       = self::get_screen_url( $area['screen'] );
				$attention = (int) $counts['blocked'] + (int) $counts['review'];
				$tone      = ( $counts['blocked'] > 0 ) ? 'bad' : ( ( $counts['review'] > 0 ) ? 'warn' : 'ok' );
				?>
				<li class="wvc-area wvc-area--<?php echo esc_attr( $tone ); ?>">
					<div class="wvc-area__head">
						<span class="wvc-area__icon" aria-hidden="true">
							<?php echo self::get_icon( $area['icon'], array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						</span>
						<h4 class="wvc-area__title">
							<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $area['label'] ); ?></a>
						</h4>
						<span class="wvc-area__total">
							<?php
							printf(
								/* translators: %s: Number of items. */
								esc_html( _n( '%s item', '%s items', (int) $counts['total'], 'wp-vip-compatibility' ) ),
								esc_html( number_format_i18n( (int) $counts['total'] ) )
							);
							?>
						</span>
					</div>

					<?php echo self::get_meter( $counts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
					<?php echo self::get_meter_legend( $counts, $url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>

					<p class="wvc-area__verdict">
						<?php if ( $attention > 0 ) : ?>
							<a class="wvc-link" href="<?php echo esc_url( $url ); ?>">
								<?php
								printf(
									/* translators: %s: Number of items. */
									esc_html( _n( 'Work through %s item', 'Work through %s items', $attention, 'wp-vip-compatibility' ) ),
									esc_html( number_format_i18n( $attention ) )
								);
								?>
								<?php echo self::get_icon( 'arrow-right', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
							</a>
						<?php else : ?>
							<span class="wvc-area__clear">
								<?php echo self::get_icon( 'check', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
								<?php esc_html_e( 'Nothing outstanding', 'wp-vip-compatibility' ); ?>
							</span>
						<?php endif; ?>
					</p>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Toolbar and tables
	 * ------------------------------------------------------------------ */

	/**
	 * Renders a table toolbar: filter groups, search and a live result count.
	 *
	 * Filter groups are declared rather than hard-coded, and their counts are
	 * derived by the browser from the rows themselves — so a count in a filter
	 * always agrees with the rows behind it, including while an async scan is
	 * still resolving verdicts.
	 *
	 * @param array $args {
	 *     Optional. Toolbar arguments.
	 *
	 *     @type array  $groups        Filter groups: `name`, `label`, `active` and `options`.
	 *     @type string $search_label  Accessible label for the search field.
	 *     @type string $placeholder   Search placeholder.
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
				'placeholder'   => __( 'Search…', 'wp-vip-compatibility' ),
				'show_search'   => true,
				'show_progress' => false,
			)
		);

		++self::$sequence;
		$search_id = 'wvc-search-' . self::$sequence;
		?>
		<div class="wvc-toolbar">
			<div class="wvc-toolbar__filters">
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
			</div>

			<div class="wvc-toolbar__tools">
				<?php if ( $args['show_search'] ) : ?>
					<div class="wvc-search">
						<?php echo self::get_icon( 'search', array( 'class' => 'wvc-icon wvc-icon--sm wvc-search__icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<label class="screen-reader-text" for="<?php echo esc_attr( $search_id ); ?>"><?php echo esc_html( $args['search_label'] ); ?></label>
						<input
							type="search"
							id="<?php echo esc_attr( $search_id ); ?>"
							class="wvc-search__input"
							data-role="table-search"
							placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
							autocomplete="off"
							spellcheck="false"
						/>
					</div>
				<?php endif; ?>

				<p class="wvc-result-count" data-role="result-count" role="status" aria-live="polite"></p>
			</div>
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
	 * Renders a filter as a dropdown of links.
	 *
	 * The options were a row of chips, which cost a row of the toolbar per facet
	 * and grew with the taxonomy: eleven categories would have wrapped to three
	 * lines to offer a choice the reader makes once. Collapsed, a facet costs one
	 * control whatever it holds, and the closed trigger carries the selection, so
	 * the answer to "what am I looking at" is readable without opening anything.
	 *
	 * `<details>` rather than a scripted popover: it opens, closes, takes focus
	 * and responds to the keyboard with no JavaScript at all, and the options
	 * stay ordinary links — so a filter is still one click, still bookmarkable,
	 * and still works if the script never loads. The shared menu behaviour
	 * (click-away, Escape) comes free with the `wvc-menu` class.
	 *
	 * @param array $args {
	 *     Filter menu arguments.
	 *
	 *     @type string $label   Accessible label for the control.
	 *     @type string $caption Short facet name shown on the trigger.
	 *     @type array  $options Each with `label`, `url`, and optional `count`,
	 *                           `active`, `dot`, `tier`, `title`, `empty` and
	 *                           `reset`. The `reset` option is the unfiltered
	 *                           state and is expected first.
	 * }
	 * @return void
	 */
	public static function render_filter_menu( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'label'   => '',
				'caption' => '',
				'options' => array(),
			)
		);

		if ( empty( $args['options'] ) ) {
			return;
		}

		/*
		 * The trigger shows the selection, and is marked when that selection is
		 * narrowing the list. Only then: a control reading "All areas" is the
		 * resting state of the screen, and lighting it up would say a filter is
		 * applied when none is.
		 */
		$current  = $args['options'][0];
		$narrowed = false;

		foreach ( $args['options'] as $option ) {
			if ( ! empty( $option['active'] ) ) {
				$current  = $option;
				$narrowed = empty( $option['reset'] );
				break;
			}
		}
		?>
		<details class="wvc-menu wvc-filter<?php echo $narrowed ? ' is-narrowed' : ''; ?>">
			<summary class="wvc-filter__trigger">
				<span class="wvc-filter__caption"><?php echo esc_html( $args['caption'] ); ?></span>

				<?php if ( ! empty( $current['dot'] ) ) : ?>
					<span class="wvc-tierdot wvc-tierdot--<?php echo esc_attr( $current['dot'] ); ?>" aria-hidden="true"></span>
				<?php endif; ?>

				<span class="wvc-filter__value"><?php echo esc_html( $current['label'] ); ?></span>
				<?php echo self::get_icon( 'chevron-down', array( 'class' => 'wvc-icon wvc-icon--xs wvc-filter__chevron' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
			</summary>

			<div class="wvc-menu__panel wvc-filter__panel">
				<p class="wvc-menu__title"><?php echo esc_html( $args['label'] ); ?></p>

				<?php foreach ( $args['options'] as $option ) : ?>
					<?php
					$option = wp_parse_args(
						$option,
						array(
							'label'  => '',
							'url'    => '',
							'count'  => null,
							'active' => false,
							'dot'    => '',
							'tier'   => '',
							'title'  => '',
							'empty'  => false,
							'reset'  => false,
						)
					);

					$classes = 'wvc-filter__option';

					if ( $option['active'] ) {
						$classes .= ' is-active';
					}

					if ( $option['empty'] ) {
						$classes .= ' is-empty';
					}

					// An option with nothing behind it stays in place but stops
					// being a control: it is reporting a zero, and a filter that
					// leads to an empty list is a dead end rather than a choice.
					$tag        = $option['empty'] ? 'span' : 'a';
					$attributes = $option['empty'] ? '' : ' href="' . esc_url( $option['url'] ) . '"';

					if ( $option['active'] ) {
						$attributes .= ' aria-current="true"';
					}

					if ( '' !== $option['title'] ) {
						$attributes .= ' title="' . esc_attr( $option['title'] ) . '"';
					}
					?>
					<<?php echo esc_html( $tag ); ?> class="<?php echo esc_attr( $classes ); ?>"<?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>>
						<?php
						/*
						 * The tick, not colour, is what says "this one". It holds
						 * its width on every row so the labels stay on one left
						 * edge instead of stepping in and out as the selection
						 * moves down the list.
						 */
						?>
						<span class="wvc-filter__tick" aria-hidden="true">
							<?php if ( $option['active'] ) : ?>
								<?php echo self::get_icon( 'check', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
							<?php endif; ?>
						</span>

						<?php if ( '' !== $option['dot'] ) : ?>
							<span class="wvc-tierdot wvc-tierdot--<?php echo esc_attr( $option['dot'] ); ?>" aria-hidden="true"></span>
						<?php endif; ?>

						<span class="wvc-filter__option-label"><?php echo esc_html( $option['label'] ); ?></span>

						<?php if ( null !== $option['count'] ) : ?>
							<span class="wvc-filter__count"><?php echo esc_html( number_format_i18n( (int) $option['count'] ) ); ?></span>
						<?php endif; ?>
					</<?php echo esc_html( $tag ); ?>>
				<?php endforeach; ?>
			</div>
		</details>
		<?php
	}

	/**
	 * Renders a table head from a column definition list.
	 *
	 * @param array $columns List of columns. Each column accepts `label`, `class`,
	 *                       `sortable`, `sort` (text|number) and `hint` keys.
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
				'<th scope="col" class="%1$s"%2$s%3$s%4$s>',
				esc_attr( implode( ' ', $classes ) ),
				$sortable ? ' aria-sort="none"' : '',
				$sortable ? ' data-sort-type="' . esc_attr( $column['sort'] ?? 'text' ) . '" data-sort-index="' . esc_attr( (string) $index ) . '"' : '',
				empty( $column['hint'] ) ? '' : ' title="' . esc_attr( $column['hint'] ) . '"'
			);

			if ( '' === ( $column['label'] ?? '' ) ) {
				echo '<span class="screen-reader-text">' . esc_html__( 'Details', 'wp-vip-compatibility' ) . '</span>';
			} elseif ( $sortable ) {
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
	 * Returns the next unique disclosure id.
	 *
	 * @param string $prefix An identifier prefix.
	 * @return string The id.
	 */
	public static function next_id( $prefix = 'wvc' ) {
		++self::$sequence;

		return $prefix . '-' . self::$sequence;
	}

	/**
	 * Builds the cell holding a row's expand control.
	 *
	 * @param string $target_id The id of the detail row it controls.
	 * @param string $label     Accessible label.
	 * @return string The cell markup.
	 */
	public static function get_row_toggle( $target_id, $label ) {
		return sprintf(
			'<td class="wvc-col-toggle"><button type="button" class="wvc-rowtoggle" data-role="row-toggle" aria-expanded="false" aria-controls="%1$s"><span class="screen-reader-text">%2$s</span>%3$s</button></td>',
			esc_attr( $target_id ),
			esc_html( $label ),
			self::get_icon( 'chevron', array( 'class' => 'wvc-icon wvc-icon--xs' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
		);
	}

	/* ---------------------------------------------------------------------
	 * Status vocabulary
	 * ------------------------------------------------------------------ */

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
				'<span class="wvc-status wvc-status--pending"><span class="wvc-spinner wvc-spinner--xs" aria-hidden="true"></span><span>%s</span></span>',
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
			'<span class="wvc-status wvc-status--%1$s">%2$s<span>%3$s</span></span>',
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
			esc_attr__( 'WordPress VIP verdict', 'wp-vip-compatibility' ),
			self::get_status_pill( $state, $label )
		);
	}

	/**
	 * Builds the verdict cell for a scanned target.
	 *
	 * @param array<string, mixed> $verdict    A verdict from Report::verdict().
	 * @param string               $target_key The target key, for the async scanner.
	 * @return string The cell markup.
	 */
	public static function get_verdict_cell( array $verdict, $target_key ) {
		return self::get_status_cell( $verdict['state'], $verdict['label'], array( 'data-target' => $target_key ) );
	}

	/**
	 * Builds the severity breakdown shown next to a verdict.
	 *
	 * The tier dots are the same vocabulary as the findings report, so a row can
	 * be read as "two blocking, five important" without opening anything.
	 *
	 * @param array<string, int> $by_severity Counts keyed by severity.
	 * @param string             $target_key  Optional target key, to link each tier.
	 * @return string The markup, or an empty string when there is nothing to show.
	 */
	public static function get_severity_bar( array $by_severity, $target_key = '' ) {
		$parts = '';

		foreach ( self::get_tiers() as $tier => $definition ) {
			$count = 0;

			foreach ( $definition['severities'] as $severity ) {
				$count += (int) ( $by_severity[ $severity ] ?? 0 );
			}

			if ( 0 === $count ) {
				continue;
			}

			$body = sprintf(
				'<span class="wvc-tierdot wvc-tierdot--%1$s" aria-hidden="true"></span>%2$s',
				esc_attr( $tier ),
				esc_html( number_format_i18n( $count ) )
			);

			$title = sprintf(
				/* translators: 1: Number of findings. 2: Tier label, e.g. "blocking". */
				_n( '%1$s %2$s finding', '%1$s %2$s findings', $count, 'wp-vip-compatibility' ),
				number_format_i18n( $count ),
				strtolower( self::get_tier_label( $tier ) )
			);

			$parts .= ( '' === $target_key )
				? sprintf( '<span class="wvc-sev" title="%1$s">%2$s</span>', esc_attr( $title ), $body )
				: sprintf(
					'<a class="wvc-sev" href="%1$s" title="%2$s">%3$s</a>',
					esc_url( self::get_findings_url( $target_key, $tier ) ),
					esc_attr( $title ),
					$body
				);
		}

		return ( '' === $parts ) ? '' : '<span class="wvc-sevbar">' . $parts . '</span>';
	}

	/**
	 * Builds the link into the fix list for one target.
	 *
	 * The verb comes from the verdict rather than being "Review" in every row.
	 * A column headed "What to do" that says "Review 12 findings" against a
	 * blocked plugin and against a passing one is not telling anyone what to do;
	 * "Fix 12 findings" and "Review 12 findings" are different instructions and
	 * the row already knows which one applies.
	 *
	 * @param string               $target_key The target key.
	 * @param array<string, mixed> $verdict    A verdict from Report::verdict().
	 * @return string The link markup, or an empty string when there is nothing to open.
	 */
	public static function get_findings_link( $target_key, array $verdict ) {
		$total = (int) $verdict['total'];

		if ( $total <= 0 ) {
			return '';
		}

		if ( Scanner::STATUS_BLOCKED === ( $verdict['status'] ?? '' ) ) {
			$label = sprintf(
				/* translators: %d: Number of findings. */
				_n( 'Fix %d finding', 'Fix %d findings', $total, 'wp-vip-compatibility' ),
				$total
			);
		} else {
			$label = sprintf(
				/* translators: %d: Number of findings. */
				_n( 'Review %d finding', 'Review %d findings', $total, 'wp-vip-compatibility' ),
				$total
			);
		}

		return sprintf(
			'<a class="wvc-link" href="%1$s">%2$s%3$s</a>',
			esc_url( self::get_findings_url( $target_key ) ),
			esc_html( $label ),
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
	 * Builds a plain badge.
	 *
	 * @param string $label The label.
	 * @param string $tone  One of `neutral`, `ok`, `warn`, `bad`, `accent`.
	 * @param string $title Optional tooltip.
	 * @return string The badge markup.
	 */
	public static function get_badge( $label, $tone = 'neutral', $title = '' ) {
		return sprintf(
			'<span class="wvc-badge wvc-badge--%1$s"%3$s>%2$s</span>',
			esc_attr( $tone ),
			esc_html( $label ),
			'' === $title ? '' : ' title="' . esc_attr( $title ) . '"'
		);
	}

	/* ---------------------------------------------------------------------
	 * Findings
	 * ------------------------------------------------------------------ */

	/**
	 * Renders one piece of non-code work as a card.
	 *
	 * The database engine, the contents of mu-plugins and the shape of wp-content
	 * are migration work exactly as much as a shell call in a plugin is, and they
	 * carry the same four tiers. They used to sit in a separate panel above the
	 * list, which meant a reader scanning for what to do first had two places to
	 * look and no way to interleave them by urgency. So they are cards in the
	 * same list, in the same shape, ranked with everything else.
	 *
	 * What differs is what a card can honestly offer. There is no file and line
	 * behind "convert two tables", and the SQL to do it belongs on the database
	 * screen where it can be copied in one go — so the card does not expand, and
	 * carries a link to the screen that owns the job instead.
	 *
	 * @param array<string, mixed> $action An action from Report::other_work().
	 * @return void
	 */
	public static function render_task_card( array $action ) {
		$tier = $action['tier'];
		?>
		<div class="wvc-fix wvc-fix--task wvc-fix--<?php echo esc_attr( $tier ); ?>" data-tier="<?php echo esc_attr( $tier ); ?>">
			<div class="wvc-fix__summary">
				<span class="wvc-fix__tier">
					<?php echo self::get_tier_pill( $tier ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
				</span>

				<span class="wvc-fix__main">
					<strong class="wvc-fix__title"><?php echo esc_html( $action['title'] ); ?></strong>

					<span class="wvc-fix__where">
						<?php if ( ! empty( $action['source'] ) ) : ?>
							<span class="wvc-fix__target"><?php echo esc_html( $action['source'] ); ?></span>
						<?php endif; ?>
						<span class="wvc-fix__effort" title="<?php esc_attr_e( 'Found by auditing the site rather than by reading code, so there is no file and line to show.', 'wp-vip-compatibility' ); ?>">
							<?php esc_html_e( 'Outside the code', 'wp-vip-compatibility' ); ?>
						</span>
					</span>

					<span class="wvc-fix__lead">
						<span class="wvc-fix__lead-label wvc-fix__lead-label--why"><?php esc_html_e( 'Why', 'wp-vip-compatibility' ); ?></span>
						<span class="wvc-fix__lead-text"><?php echo esc_html( $action['detail'] ); ?></span>
					</span>
				</span>

				<a class="wvc-btn wvc-btn--ghost wvc-btn--sm wvc-fix__cta" href="<?php echo esc_url( $action['url'] ); ?>">
					<span><?php echo esc_html( $action['action'] ); ?></span>
					<?php echo self::get_icon( 'arrow-right', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders one rule's findings in one target as a single card.
	 *
	 * A rule that fires twenty times in a plugin is one decision with twenty
	 * locations, so the card is built around the decision. What separates it from
	 * the list it replaced is that the fix is on the outside: the collapsed card
	 * already answers "what is wrong" and "what do I do about it", which is what
	 * the reader came for. Opening it answers "why" and "where", and a second
	 * disclosure answers "prove it" — the classification, the confidence and the
	 * sniff, which are the plugin's reasoning rather than the reader's work.
	 *
	 * @param array<string, mixed> $group A rule group from Report::fix_list().
	 * @return void
	 */
	public static function render_fix_card( array $group ) {
		$confidences = Taxonomy::get_confidences();
		$fixes       = Taxonomy::get_fixabilities();
		$tier        = self::get_tier( $group['severity'] );
		$occurrences = $group['occurrences'];
		$count       = count( $occurrences );
		$effort      = Taxonomy::get_fixability_short( $group['fixability'] );
		?>
		<details class="wvc-fix wvc-fix--<?php echo esc_attr( $tier ); ?>"
			data-tier="<?php echo esc_attr( $tier ); ?>"
			data-severity="<?php echo esc_attr( $group['severity'] ); ?>"
			data-type="<?php echo esc_attr( $group['type'] ); ?>"
			data-category="<?php echo esc_attr( $group['category'] ); ?>"
			data-target="<?php echo esc_attr( $group['target_key'] ); ?>">

			<summary class="wvc-fix__summary">
				<span class="wvc-fix__tier">
					<?php echo self::get_tier_pill( $tier ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
				</span>

				<span class="wvc-fix__main">
					<strong class="wvc-fix__title"><?php echo esc_html( $group['title'] ); ?></strong>

					<span class="wvc-fix__where">
						<span class="wvc-fix__target"><?php echo esc_html( $group['target_label'] ); ?></span>

						<span class="wvc-fix__places">
							<?php if ( 1 === $count ) : ?>
								<code><?php echo esc_html( $occurrences[0]['file'] . ':' . $occurrences[0]['line'] ); ?></code>
							<?php else : ?>
								<?php
								printf(
									/* translators: %d: Number of places in the code. */
									esc_html( _n( '%d place', '%d places', $count, 'wp-vip-compatibility' ) ),
									$count
								);
								?>
							<?php endif; ?>
						</span>

						<?php if ( '' !== $effort ) : ?>
							<span class="wvc-fix__effort" title="<?php echo esc_attr( $fixes[ $group['fixability'] ]['description'] ?? '' ); ?>">
								<?php echo esc_html( $effort ); ?>
							</span>
						<?php endif; ?>
					</span>

					<span class="wvc-fix__lead">
						<span class="wvc-fix__lead-label"><?php esc_html_e( 'Fix', 'wp-vip-compatibility' ); ?></span>
						<span class="wvc-fix__lead-text"><?php echo esc_html( $group['remediation'] ); ?></span>
					</span>
				</span>

				<?php echo self::get_icon( 'chevron-down', array( 'class' => 'wvc-icon wvc-icon--xs wvc-fix__caret' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
			</summary>

			<div class="wvc-fix__body">
				<dl class="wvc-fix__answer">
					<dt><?php esc_html_e( 'Why it matters on WordPress VIP', 'wp-vip-compatibility' ); ?></dt>
					<dd><?php echo esc_html( $group['why'] ); ?></dd>

					<?php if ( '' !== $group['alternative'] ) : ?>
						<dt><?php esc_html_e( 'Another way to do it', 'wp-vip-compatibility' ); ?></dt>
						<dd><?php echo esc_html( $group['alternative'] ); ?></dd>
					<?php endif; ?>
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

				<?php if ( '' !== $group['doc'] ) : ?>
					<p class="wvc-fix__doc">
						<a class="wvc-link" href="<?php echo esc_url( $group['doc'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Read the WordPress VIP documentation for this', 'wp-vip-compatibility' ); ?>
							<?php echo self::get_icon( 'external', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'wp-vip-compatibility' ); ?></span>
						</a>
					</p>
				<?php endif; ?>

				<?php
				/*
				 * Every disclosure in the interface opens the same way and says so
				 * the same way: a caret that rotates. This one used to be the
				 * exception — bare text that gave no sign it was a control at all.
				 */
				?>
				<details class="wvc-subdetails">
					<summary class="wvc-subdetails__summary">
						<?php echo self::get_icon( 'chevron-down', array( 'class' => 'wvc-icon wvc-icon--xs wvc-subdetails__caret' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<span><?php esc_html_e( 'How this was detected', 'wp-vip-compatibility' ); ?></span>
					</summary>

					<div class="wvc-subdetails__body">
						<dl class="wvc-fix__answer">
							<dt><?php esc_html_e( 'What was detected', 'wp-vip-compatibility' ); ?></dt>
							<dd><?php echo esc_html( $group['detected'] ); ?></dd>
						</dl>

						<div class="wvc-fix__meta">
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
							<p class="wvc-fix__also">
								<?php
								printf(
									/* translators: %s: Comma-separated list of rule identifiers. */
									esc_html__( 'Also matched on these lines: %s', 'wp-vip-compatibility' ),
									esc_html( implode( ', ', $group['also_matched'] ) )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</details>
			</div>
		</details>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Snippets, notices and empty states
	 * ------------------------------------------------------------------ */

	/**
	 * Builds a copyable code snippet.
	 *
	 * @param string $code  The snippet contents.
	 * @param bool   $block Whether to render it as a block rather than inline.
	 * @return string The snippet markup.
	 */
	public static function get_code_snippet( $code, $block = false ) {
		return sprintf(
			'<span class="wvc-snippet%5$s"><code>%1$s</code><button type="button" class="wvc-snippet__copy" data-role="copy" data-clipboard="%2$s" aria-label="%3$s" title="%3$s">%4$s</button></span>',
			esc_html( $code ),
			esc_attr( $code ),
			esc_attr__( 'Copy to clipboard', 'wp-vip-compatibility' ),
			self::get_icon( 'copy', array( 'class' => 'wvc-icon wvc-icon--xs' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
			$block ? ' wvc-snippet--block' : ''
		);
	}

	/**
	 * Renders an inline notice.
	 *
	 * @param string $message Notice body (may contain limited HTML).
	 * @param string $type    One of `info`, `success`, `warning`, `error`.
	 * @param string $title   Optional bold lead-in.
	 * @return string The notice markup.
	 */
	public static function get_notice( $message, $type = 'info', $title = '' ) {
		$icons = array(
			'info'    => 'info',
			'success' => 'check',
			'warning' => 'alert',
			'error'   => 'alert',
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
			'<details class="wvc-guidance"><summary class="wvc-guidance__summary">%1$s<span class="wvc-guidance__title">%2$s</span>%4$s</summary><div class="wvc-guidance__body">%3$s</div></details>',
			self::get_icon( 'book', array( 'class' => 'wvc-icon wvc-guidance__icon' ) ),
			esc_html( $title ),
			wp_kses_post( $message ),
			self::get_icon( 'chevron-down', array( 'class' => 'wvc-icon wvc-icon--sm wvc-guidance__caret' ) )
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
					<?php echo self::get_action_button( array_merge( $action, array( 'small' => false ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
