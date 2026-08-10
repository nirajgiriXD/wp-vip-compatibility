<?php
/**
 * Shared presentation helpers for the plugin admin screens.
 *
 * This class owns the reusable chrome (masthead, sub navigation, page heads,
 * toolbars, tables, status pills, notices and empty states) so that every
 * screen renders from a single, consistent design system.
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
	 * @return array<string, array<string, string>> Screen definitions keyed by settings key.
	 */
	public static function get_screens() {
		return array(
			'overview'    => array(
				'slug'        => 'wp-vip-compatibility',
				'label'       => __( 'Overview', 'wp-vip-compatibility' ),
				'title'       => __( 'Overview', 'wp-vip-compatibility' ),
				'description' => __( 'A snapshot of how ready this site is for the WordPress VIP platform.', 'wp-vip-compatibility' ),
				'icon'        => 'gauge',
			),
			'database'    => array(
				'slug'        => 'wvc-database',
				'label'       => __( 'Database', 'wp-vip-compatibility' ),
				'title'       => __( 'Database', 'wp-vip-compatibility' ),
				'description' => __( 'Storage engines, collations and table prefixes checked against VIP requirements.', 'wp-vip-compatibility' ),
				'icon'        => 'database',
			),
			'directories' => array(
				'slug'        => 'wvc-directories',
				'label'       => __( 'Directories', 'wp-vip-compatibility' ),
				'title'       => __( 'Directories', 'wp-vip-compatibility' ),
				'description' => __( 'Everything inside wp-content measured against the VIP file structure.', 'wp-vip-compatibility' ),
				'icon'        => 'folder',
			),
			'mu-plugins'  => array(
				'slug'        => 'wvc-mu-plugins',
				'label'       => __( 'MU Plugins', 'wp-vip-compatibility' ),
				'title'       => __( 'Must-use plugins', 'wp-vip-compatibility' ),
				'description' => __( 'Must-use plugins scanned for code patterns the VIP platform does not allow.', 'wp-vip-compatibility' ),
				'icon'        => 'bolt',
			),
			'plugins'     => array(
				'slug'        => 'wvc-plugins',
				'label'       => __( 'Plugins', 'wp-vip-compatibility' ),
				'title'       => __( 'Plugins', 'wp-vip-compatibility' ),
				'description' => __( 'Installed plugins checked against the VIP incompatibility list and scanned for risky code.', 'wp-vip-compatibility' ),
				'icon'        => 'plug',
			),
			'themes'      => array(
				'slug'        => 'wvc-themes',
				'label'       => __( 'Themes', 'wp-vip-compatibility' ),
				'title'       => __( 'Themes', 'wp-vip-compatibility' ),
				'description' => __( 'Installed themes scanned for code patterns the VIP platform does not allow.', 'wp-vip-compatibility' ),
				'icon'        => 'brush',
			),
			'findings'    => array(
				'slug'        => 'wvc-findings',
				'label'       => __( 'Findings', 'wp-vip-compatibility' ),
				'title'       => __( 'Findings', 'wp-vip-compatibility' ),
				'description' => __( 'Every issue the scanner found, with why it matters on VIP and how to resolve it.', 'wp-vip-compatibility' ),
				'icon'        => 'list',
			),
		);
	}

	/**
	 * Returns the URL of the findings report, optionally filtered to a target.
	 *
	 * @param string $target_key Optional target key to filter by.
	 * @return string The admin URL.
	 */
	public static function get_findings_url( $target_key = '' ) {
		$url = self::get_screen_url( 'findings' );

		return ( '' === $target_key ) ? $url : add_query_arg( 'target', rawurlencode( $target_key ), $url );
	}

	/**
	 * Returns the admin URL of a plugin screen.
	 *
	 * @param string $key The settings key.
	 * @return string The admin URL.
	 */
	public static function get_screen_url( $key ) {
		$screens = self::get_screens();
		$slug    = $screens[ $key ]['slug'] ?? 'wp-vip-compatibility';

		return admin_url( 'admin.php?page=' . $slug );
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
			'gauge'      => '<path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="m13.4 10.6 4.1-4.1"/><path d="M4 19a9 9 0 1 1 16 0"/>',
			'database'   => '<ellipse cx="12" cy="5.5" rx="7.5" ry="3"/><path d="M4.5 5.5v13c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-13"/><path d="M19.5 12c0 1.7-3.4 3-7.5 3s-7.5-1.3-7.5-3"/>',
			'folder'     => '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6h4l2 2.5h7A1.5 1.5 0 0 1 19 10v7.5a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 3 17.5Z"/>',
			'bolt'       => '<path d="M13 2 4.5 13.5H11L10 22l8.5-11.5H12Z"/>',
			'plug'       => '<path d="M9 2v6"/><path d="M15 2v6"/><path d="M6 8h12v3a6 6 0 0 1-6 6 6 6 0 0 1-6-6Z"/><path d="M12 17v5"/>',
			'brush'      => '<path d="M4 20c0-2 1-3 3-3 1.6 0 2.5 1 2.5 2.2C9.5 20.6 8 22 5.5 22 4.7 22 4 21.3 4 20Z"/><path d="M9.5 17.5 19 8a2.1 2.1 0 0 0-3-3l-9.5 9.5"/>',
			'search'     => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
			'check'      => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
			'alert'      => '<path d="M12 8.5v5"/><path d="M12 17h.01"/><path d="M10.3 3.9 2.6 17.4A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3.1L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
			'info'       => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
			'external'   => '<path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14.5V19a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 19V7.5A1.5 1.5 0 0 1 5 6h4.5"/>',
			'copy'       => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4.5A1.5 1.5 0 0 1 3 13.5V5A1.5 1.5 0 0 1 4.5 3.5H13A1.5 1.5 0 0 1 14.5 5v.5"/>',
			'arrow-right' => '<path d="M4 12h15"/><path d="m13 6 6 6-6 6"/>',
			'sort'       => '<path d="m8 9 4-4 4 4"/><path d="m8 15 4 4 4-4"/>',
			'inbox'      => '<path d="M3 13h4l1.5 3h7L17 13h4"/><path d="M5.5 5h13l2.5 8v5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18v-5Z"/>',
			'shield'     => '<path d="M12 3 5 6v6c0 4.3 2.9 7.8 7 9 4.1-1.2 7-4.7 7-9V6Z"/><path d="m9 12 2 2 4-4"/>',
			'list'       => '<path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
			'download'   => '<path d="M12 3v12"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4 19.5h16"/>',
			'refresh'    => '<path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M19.5 11a7.5 7.5 0 0 0-13-3.5L4 10"/><path d="M4.5 13a7.5 7.5 0 0 0 13 3.5L20 14"/>',
			'book'       => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H5.5A1.5 1.5 0 0 1 4 16.5Z"/><path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H14a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h4.5a1.5 1.5 0 0 0 1.5-1.5Z"/>',
			'wrench'     => '<path d="M15.5 3.5a5 5 0 0 0-6 6.6L3.6 16a2 2 0 0 0 2.8 2.8l5.9-5.9a5 5 0 0 0 6.6-6l-3 3-2.8-2.8Z"/>',
			'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
			'trend'      => '<path d="m4 16 5-5 3.5 3.5L20 7"/><path d="M15 7h5v5"/>',
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
	 * @param string $current The current settings key.
	 * @return void
	 */
	public static function render_masthead( $current ) {
		$screens = self::get_screens();
		$version = defined( 'WP_VIP_COMPATIBILITY_VERSION' ) ? WP_VIP_COMPATIBILITY_VERSION : '';
		?>
		<header class="wvc-masthead">
			<div class="wvc-masthead__identity">
				<span class="wvc-masthead__mark" aria-hidden="true">
					<?php echo self::get_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
				</span>
				<div class="wvc-masthead__text">
					<h1 class="wvc-masthead__title">
						<?php esc_html_e( 'WordPress VIP Compatibility', 'wp-vip-compatibility' ); ?>
					</h1>
					<p class="wvc-masthead__tagline">
						<?php esc_html_e( 'Audit this site against the WordPress VIP platform requirements before you migrate.', 'wp-vip-compatibility' ); ?>
					</p>
				</div>
			</div>

			<a class="wvc-btn wvc-btn--ghost" href="https://docs.wpvip.com/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'VIP documentation', 'wp-vip-compatibility' ); ?>
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
	 * Renders the table toolbar: compatibility filters, search and live result count.
	 *
	 * The markup keeps the original `#wvc-filter-tabs` container and its
	 * `data-filter` buttons so the existing filtering contract is untouched.
	 *
	 * @param array $args {
	 *     Optional. Toolbar arguments.
	 *
	 *     @type string $search_label Accessible label for the search field.
	 *     @type bool   $show_search  Whether to render the search field.
	 *     @type bool   $show_progress Whether to render the scan progress bar.
	 * }
	 * @return void
	 */
	public static function render_toolbar( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'search_label'  => __( 'Search results', 'wp-vip-compatibility' ),
				'show_search'   => true,
				'show_progress' => false,
			)
		);

		$search_id = 'wvc-search-' . wp_rand( 1000, 9999 );
		?>
		<div class="wvc-toolbar">
			<div id="wvc-filter-tabs" class="wvc-segmented" role="group" aria-label="<?php esc_attr_e( 'Filter by compatibility', 'wp-vip-compatibility' ); ?>">
				<button type="button" class="active" data-filter="all" aria-pressed="true">
					<span><?php esc_html_e( 'All', 'wp-vip-compatibility' ); ?></span>
					<span class="wvc-segmented__count" data-count="all"></span>
				</button>
				<button type="button" data-filter="compatible" aria-pressed="false">
					<span class="wvc-dot wvc-dot--ok" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Compatible', 'wp-vip-compatibility' ); ?></span>
					<span class="wvc-segmented__count" data-count="compatible"></span>
				</button>
				<button type="button" data-filter="incompatible" aria-pressed="false">
					<span class="wvc-dot wvc-dot--bad" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Incompatible', 'wp-vip-compatibility' ); ?></span>
					<span class="wvc-segmented__count" data-count="incompatible"></span>
				</button>
			</div>

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
		<?php endif; ?>

		<p class="wvc-result-count" data-role="result-count" role="status" aria-live="polite"></p>
		<?php
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
	 * Builds a compatibility status cell.
	 *
	 * The `compatible` / `not-compatible` classes stay on the `<td>` itself so the
	 * existing filtering and chart logic keeps working unchanged.
	 *
	 * @param string $state One of `compatible`, `not-compatible` or `pending`.
	 * @param string $label Visible label. Defaults to the state label.
	 * @param array  $attrs Optional extra attributes for the cell.
	 * @return string The cell markup.
	 */
	public static function get_status_cell( $state, $label = '', $attrs = array() ) {
		$classes = array();

		if ( 'compatible' === $state ) {
			$classes[] = 'compatible';
			$label     = $label ? $label : __( 'Ready', 'wp-vip-compatibility' );
		} elseif ( 'review' === $state ) {
			// "Needs review" belongs in the needs-attention filter, so it keeps
			// the long-standing `not-compatible` class while carrying its own
			// label and colour.
			$classes[] = 'not-compatible';
			$classes[] = 'is-review';
			$label     = $label ? $label : __( 'Needs review', 'wp-vip-compatibility' );
		} elseif ( 'not-compatible' === $state ) {
			$classes[] = 'not-compatible';
			$label     = $label ? $label : __( 'Blocked', 'wp-vip-compatibility' );
		} else {
			$classes[] = 'vip-compatibility-status';
			$label     = $label ? $label : __( 'Not scanned yet', 'wp-vip-compatibility' );
		}

		$attr_string = ' data-label="' . esc_attr__( 'WP VIP Compatibility', 'wp-vip-compatibility' ) . '"';
		foreach ( $attrs as $attr => $value ) {
			$attr_string .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $value ) );
		}

		if ( 'pending' === $state ) {
			return sprintf(
				'<td class="%1$s wvc-col-status"%2$s><span class="wvc-status wvc-status--pending"><span class="wvc-spinner wvc-spinner--xs" aria-hidden="true"></span>%3$s</span></td>',
				esc_attr( implode( ' ', $classes ) ),
				$attr_string,
				esc_html( $label )
			);
		}

		return sprintf(
			'<td class="%1$s wvc-col-status"%2$s>%3$s</td>',
			esc_attr( implode( ' ', $classes ) ),
			$attr_string,
			self::get_status_pill( $state, $label )
		);
	}

	/**
	 * Builds a status pill.
	 *
	 * @param string $state One of `compatible`, `not-compatible` or `pending`.
	 * @param string $label Visible label.
	 * @return string The pill markup.
	 */
	public static function get_status_pill( $state, $label ) {
		$modifiers = array(
			'compatible'     => 'ok',
			'review'         => 'warn',
			'not-compatible' => 'bad',
		);

		$modifier = $modifiers[ $state ] ?? 'pending';
		$icon     = 'compatible' === $state ? 'check' : ( 'review' === $state ? 'info' : 'alert' );

		return sprintf(
			'<span class="wvc-status wvc-status--%1$s">%2$s%3$s</span>',
			esc_attr( $modifier ),
			self::get_icon( $icon, array( 'class' => 'wvc-icon wvc-icon--xs' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
			esc_html( $label )
		);
	}

	/**
	 * Builds a severity pill.
	 *
	 * @param string $severity A Taxonomy severity slug.
	 * @return string The pill markup.
	 */
	public static function get_severity_pill( $severity ) {
		return sprintf(
			'<span class="wvc-sev wvc-sev--%1$s">%2$s</span>',
			esc_attr( $severity ),
			esc_html( Taxonomy::get_label( 'severity', $severity ) )
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
	 * Renders one finding as an expandable card.
	 *
	 * Each card answers the questions the brief asks a finding to answer: what
	 * was detected, where, why it matters on VIP, how to fix it, what the
	 * alternative is, and how much to trust the detection.
	 *
	 * @param array<string, mixed> $finding A finding row from Report::findings().
	 * @return void
	 */
	public static function render_finding( array $finding ) {
		$confidences = Taxonomy::get_confidences();
		$fixes       = Taxonomy::get_fixabilities();
		?>
		<details class="wvc-finding wvc-finding--<?php echo esc_attr( $finding['severity'] ); ?>"
			data-severity="<?php echo esc_attr( $finding['severity'] ); ?>"
			data-type="<?php echo esc_attr( $finding['type'] ); ?>"
			data-category="<?php echo esc_attr( $finding['category'] ); ?>"
			data-target="<?php echo esc_attr( $finding['target_key'] ); ?>">

			<summary class="wvc-finding__summary">
				<span class="wvc-finding__pills">
					<?php
					echo self::get_severity_pill( $finding['severity'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					echo self::get_type_pill( $finding['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					?>
				</span>
				<span class="wvc-finding__headline">
					<strong class="wvc-finding__title"><?php echo esc_html( $finding['title'] ); ?></strong>
					<span class="wvc-finding__location">
						<code><?php echo esc_html( $finding['file'] . ':' . $finding['line'] ); ?></code>
						<?php if ( '' !== $finding['scope'] ) : ?>
							<span class="wvc-finding__scope">
								<?php
								printf(
									/* translators: %s: Function or method name. */
									esc_html__( 'in %s()', 'wp-vip-compatibility' ),
									esc_html( $finding['scope'] )
								);
								?>
							</span>
						<?php endif; ?>
					</span>
				</span>
				<span class="wvc-finding__target"><?php echo esc_html( $finding['target_label'] ); ?></span>
			</summary>

			<div class="wvc-finding__body">
				<?php if ( '' !== $finding['evidence'] ) : ?>
					<pre class="wvc-finding__evidence"><code><?php echo esc_html( $finding['evidence'] ); ?></code></pre>
				<?php endif; ?>

				<?php if ( '' !== $finding['note'] ) : ?>
					<p class="wvc-finding__note"><?php echo esc_html( $finding['note'] ); ?></p>
				<?php endif; ?>

				<dl class="wvc-finding__detail">
					<dt><?php esc_html_e( 'What was detected', 'wp-vip-compatibility' ); ?></dt>
					<dd><?php echo esc_html( $finding['detected'] ); ?></dd>

					<dt><?php esc_html_e( 'Why it matters on VIP', 'wp-vip-compatibility' ); ?></dt>
					<dd><?php echo esc_html( $finding['why'] ); ?></dd>

					<dt><?php esc_html_e( 'Recommended fix', 'wp-vip-compatibility' ); ?></dt>
					<dd><?php echo esc_html( $finding['remediation'] ); ?></dd>

					<?php if ( '' !== $finding['alternative'] ) : ?>
						<dt><?php esc_html_e( 'Alternative approach', 'wp-vip-compatibility' ); ?></dt>
						<dd><?php echo esc_html( $finding['alternative'] ); ?></dd>
					<?php endif; ?>
				</dl>

				<div class="wvc-finding__meta">
					<?php
					$chips = array(
						self::get_meta_chip(
							__( 'Confidence', 'wp-vip-compatibility' ),
							Taxonomy::get_label( 'confidence', $finding['confidence'] ),
							$confidences[ $finding['confidence'] ]['description'] ?? ''
						),
						self::get_meta_chip(
							__( 'Fix', 'wp-vip-compatibility' ),
							Taxonomy::get_label( 'fixability', $finding['fixability'] ),
							$fixes[ $finding['fixability'] ]['description'] ?? ''
						),
						self::get_meta_chip(
							__( 'Category', 'wp-vip-compatibility' ),
							Taxonomy::get_label( 'category', $finding['category'] )
						),
						self::get_meta_chip(
							__( 'Rule', 'wp-vip-compatibility' ),
							$finding['rule']
						),
					);

					if ( '' !== $finding['phpcs'] ) {
						$chips[] = self::get_meta_chip(
							__( 'PHPCS', 'wp-vip-compatibility' ),
							$finding['phpcs'],
							__( 'The WordPress-VIP-Go sniff that reports the same pattern.', 'wp-vip-compatibility' )
						);
					}

					foreach ( $chips as $chip ) {
						echo $chip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value is escaped inside get_meta_chip().
					}
					?>
				</div>

				<?php if ( ! empty( $finding['also_matched'] ) ) : ?>
					<p class="wvc-finding__also">
						<?php
						printf(
							/* translators: %s: Comma-separated list of rule identifiers. */
							esc_html__( 'Also matched on this line: %s', 'wp-vip-compatibility' ),
							esc_html( implode( ', ', $finding['also_matched'] ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( '' !== $finding['doc'] ) : ?>
					<p class="wvc-finding__doc">
						<a class="wvc-link" href="<?php echo esc_url( $finding['doc'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'VIP documentation for this rule', 'wp-vip-compatibility' ); ?>
							<?php echo self::get_icon( 'external', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						</a>
					</p>
				<?php endif; ?>
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
	 * Renders an empty state block.
	 *
	 * @param string $title   Headline.
	 * @param string $message Supporting copy.
	 * @return void
	 */
	public static function render_empty_state( $title, $message = '' ) {
		?>
		<div class="wvc-empty">
			<span class="wvc-empty__icon" aria-hidden="true">
				<?php echo self::get_icon( 'inbox' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
			</span>
			<p class="wvc-empty__title"><?php echo esc_html( $title ); ?></p>
			<?php if ( $message ) : ?>
				<p class="wvc-empty__message"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
