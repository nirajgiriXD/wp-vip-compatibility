<?php
/**
 * Class to handle plugin settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu and routes each screen through the shared shell.
 */
class Settings {

	use Singleton;

	/**
	 * Holds registered settings classes.
	 *
	 * @var array
	 */
	private $settings_classes = array();

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Sets up necessary WordPress hooks.
	 *
	 * @return void
	 */
	private function setup_hooks() {
		add_action( 'admin_menu', array( $this, 'add_plugin_menus' ) );
		add_action( 'admin_init', array( $this, 'redirect_retired_screens' ) );
	}

	/**
	 * Sends the merged screens back to the section they were split into.
	 *
	 * Inventory and Site briefly folded five subjects into two screens. Anyone
	 * holding a bookmark, or a link in a migration ticket, lands on the screen
	 * that now owns the subject rather than on "page not found".
	 *
	 * @return void
	 */
	public function redirect_retired_screens() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection on a GET request.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( ! in_array( $page, array( 'wvc-inventory', 'wvc-site' ), true ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only translation of a bookmarked filter.
		$kind    = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$targets = array(
			'plugin'      => 'plugins',
			'theme'       => 'themes',
			'mu-plugin'   => 'mu-plugins',
			'directories' => 'directories',
			'database'    => 'database',
		);

		if ( 'wvc-inventory' === $page ) {
			$key = $targets[ $kind ] ?? 'plugins';
		} else {
			$key = $targets[ $section ] ?? 'database';
		}

		$args = ( '' === $status ) ? array() : array( 'status' => $status );

		wp_safe_redirect( UI::get_screen_url( $key, $args ), 301 );
		exit;
	}

	/**
	 * Registers and loads the settings classes on demand.
	 *
	 * @param string $key The settings key.
	 * @return object|null The settings class instance, or null when unknown.
	 */
	private function get_settings_class( $key ) {
		if ( ! isset( $this->settings_classes[ $key ] ) ) {
			$class_name = __NAMESPACE__ . '\\' . ucfirst( str_replace( '-', '_', $key ) ) . '_Settings';

			if ( class_exists( $class_name ) ) {
				$this->settings_classes[ $key ] = $class_name::get_instance();
			}
		}

		return $this->settings_classes[ $key ] ?? null;
	}

	/**
	 * Renders one screen inside the shared application shell.
	 *
	 * @param string $key The settings key.
	 * @return void
	 */
	public function render_settings_page( $key ) {
		$settings = $this->get_settings_class( $key );

		if ( ! $settings ) {
			return;
		}

		UI::render_shell_open();
		UI::render_page_head( $key );

		echo '<div class="wvc-container" id="' . esc_attr( $key ) . '">';
		UI::render_scan_notice();
		$settings->render_settings_page();
		echo '</div>';

		UI::render_shell_close();
	}

	/**
	 * Adds the plugin menus in the WordPress admin panel.
	 *
	 * The admin menu is the plugin's only navigation, so it has to carry what a
	 * flat list of seven links cannot: how much work is outstanding, and which
	 * part of the job each screen belongs to. The first gets the same count
	 * bubble WordPress uses for pending updates — a number there is read without
	 * being explained. The second is a heading rendered above the first entry of
	 * each group, which turns seven equal-looking links into three short lists.
	 *
	 * @return void
	 */
	public function add_plugin_menus() {
		$screens = UI::get_screens();
		$groups  = UI::get_groups();

		// The overview is also the first submenu entry, so the menu never shows a
		// duplicate of the parent under a different name.
		add_menu_page(
			__( 'WordPress VIP Compatibility', 'wp-vip-compatibility' ),
			__( 'WordPress VIP Compatibility', 'wp-vip-compatibility' ) . $this->get_menu_badge(),
			'manage_options',
			$screens['overview']['slug'],
			fn() => $this->render_settings_page( 'overview' ),
			'dashicons-shield-alt'
		);

		$headed = array();

		foreach ( $screens as $key => $screen ) {
			$is_parent = ( $screen['slug'] === $screens['overview']['slug'] );
			$group     = $screen['group'];
			$label     = esc_html( $screen['label'] );

			// WordPress renders submenu titles through wptexturize() alone, so
			// this is markup rather than a hack around escaping. The heading is
			// hidden from assistive technology because it sits inside the link,
			// where it would otherwise become part of the link's name.
			if ( ! isset( $headed[ $group ] ) && isset( $groups[ $group ] ) ) {
				// The first heading sits directly under the menu title, where a
				// separator above it would divide nothing.
				$modifier         = empty( $headed ) ? ' wvc-menu-group--first' : '';
				$headed[ $group ] = true;
				$label            = '<span class="wvc-menu-group' . $modifier . '" aria-hidden="true">' . esc_html( $groups[ $group ] ) . '</span>' . $label;
			}

			add_submenu_page(
				$screens['overview']['slug'],
				/* translators: %s: Submenu title */
				sprintf( __( 'WordPress VIP Compatibility — %s', 'wp-vip-compatibility' ), $screen['title'] ),
				$label,
				'manage_options',
				$screen['slug'],
				/*
				 * The overview is listed as its own first submenu entry so the
				 * menu does not show the plugin name twice under itself. It must be
				 * registered without a callback: add_menu_page() already hooked
				 * one, and because the slugs match, add_submenu_page() resolves
				 * to the same `toplevel_page_*` hook — passing a callback here
				 * hooks it a second time and renders the whole screen twice.
				 */
				$is_parent ? null : fn() => $this->render_settings_page( $key )
			);
		}
	}

	/**
	 * Builds the count bubble shown on the top-level menu entry.
	 *
	 * It counts the findings in the must-fix tier and nothing else. A bubble is a
	 * claim that something needs doing, so counting anything advisory in it would
	 * leave a site that is ready to migrate wearing a permanent red badge.
	 *
	 * @return string The bubble markup, or an empty string when there is nothing to flag.
	 */
	private function get_menu_badge() {
		$aggregate = Report::aggregate();
		$count     = 0;

		foreach ( Taxonomy::get_tier_severities( Taxonomy::TIER_BLOCKING ) as $severity ) {
			$count += (int) ( $aggregate['by_severity'][ $severity ] ?? 0 );
		}

		if ( $count < 1 ) {
			return '';
		}

		return sprintf(
			' <span class="update-plugins count-%1$d"><span class="plugin-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			$count,
			esc_html( number_format_i18n( $count ) ),
			esc_html(
				sprintf(
					/* translators: %s: Number of findings. */
					_n( '%s finding must be fixed before migrating', '%s findings must be fixed before migrating', $count, 'wp-vip-compatibility' ),
					number_format_i18n( $count )
				)
			)
		);
	}
}
