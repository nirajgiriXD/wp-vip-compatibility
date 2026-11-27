<?php
/**
 * Class to handle plugin settings.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Includes\Classes;

use SWITCHRAIL_AUDIT\Includes\Traits\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu and routes each screen through the shared shell.
 */
class Settings {

	use Singleton;

	/**
	 * The admin menu icon, relative to the plugin URL.
	 *
	 * A file rather than a base64 data URI, because WordPress repaints a data
	 * URI's fills in one colour and this is the full-colour logo. WordPress
	 * renders a URL as an <img>, which it dims until hover; see
	 * Assets::get_menu_styles().
	 *
	 * @var string
	 */
	const MENU_ICON = 'assets/menu-icon.svg';

	/**
	 * Returns the URL of the admin menu icon.
	 *
	 * @return string
	 */
	public static function get_menu_icon_url() {
		return SWITCHRAIL_AUDIT_URL . self::MENU_ICON;
	}

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
		// Not admin_init: WordPress refuses an unregistered page slug in
		// wp-admin/menu.php, which runs before admin_init, so a redirect hooked
		// there never fires. admin_menu runs before that check.
		add_action( 'admin_menu', array( $this, 'redirect_retired_screens' ), 0 );
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

		if ( ! in_array( $page, array( 'switchrail-audit-inventory', 'switchrail-audit-site' ), true ) ) {
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

		if ( 'switchrail-audit-inventory' === $page ) {
			$key = $targets[ $kind ] ?? 'plugins';
		} else {
			$key = $targets[ $section ] ?? 'database';
		}

		$args = ( '' === $status ) ? array() : array( 'status' => $status );

		// 302, not 301: a permanent redirect on an admin route is cached by the
		// browser indefinitely, which would strand anyone here if these slugs are
		// ever rearranged again.
		wp_safe_redirect( UI::get_screen_url( $key, $args ) );
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

		UI::render_shell_open( $key );
		UI::render_scan_notice();
		$settings->render_settings_page();
		UI::render_shell_close();
	}

	/**
	 * Adds the plugin menus in the WordPress admin panel.
	 *
	 * The admin menu is the plugin's only navigation, so it has to show which
	 * part of the job each screen belongs to. A heading is rendered above the
	 * first entry of each group, which turns seven equal-looking links into
	 * three short lists.
	 *
	 * @return void
	 */
	public function add_plugin_menus() {
		$screens = UI::get_screens();
		$groups  = UI::get_groups();

		// The overview is also the first submenu entry, so the menu never shows a
		// duplicate of the parent under a different name.
		add_menu_page(
			__( 'Switchrail Audit', 'switchrail-audit' ),
			__( 'Switchrail Audit', 'switchrail-audit' ),
			Plugin::get_capability(),
			$screens['overview']['slug'],
			fn() => $this->render_settings_page( 'overview' ),
			self::get_menu_icon_url()
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
				$modifier         = empty( $headed ) ? ' switchrail-audit-menu-group--first' : '';
				$headed[ $group ] = true;
				$label            = '<span class="switchrail-audit-menu-group' . $modifier . '" aria-hidden="true">' . esc_html( $groups[ $group ] ) . '</span>' . $label;
			}

			$hook = add_submenu_page(
				$screens['overview']['slug'],
				/* translators: %s: Submenu title */
				sprintf( __( 'Switchrail Audit — %s', 'switchrail-audit' ), $screen['title'] ),
				$label,
				Plugin::get_capability(),
				$screen['slug'],
				// The overview is listed as its own first submenu entry so the
				// menu does not show the plugin name twice under itself. It must be
				// registered without a callback: add_menu_page() already hooked
				// one, and because the slugs match, add_submenu_page() resolves
				// to the same `toplevel_page_*` hook — passing a callback here
				// hooks it a second time and renders the whole screen twice.
				$is_parent ? null : fn() => $this->render_settings_page( $key )
			);

			if ( $hook ) {
				add_action( 'load-' . $hook, fn() => $this->add_help_tab( $key ) );
			}
		}
	}

	/**
	 * Puts a screen's reading notes in the WordPress Help tab.
	 *
	 * They are background reading, so they live where WordPress keeps help for
	 * every admin screen instead of taking space on the page itself.
	 *
	 * @param string $key The settings key.
	 * @return void
	 */
	private function add_help_tab( $key ) {
		$settings = $this->get_settings_class( $key );
		$screen   = get_current_screen();

		if ( ! $settings || ! $screen || ! method_exists( $settings, 'get_page_guidance' ) ) {
			return;
		}

		$notes = $settings->get_page_guidance();

		if ( empty( $notes ) ) {
			return;
		}

		// The overview is a report rather than a list.
		$title = ( 'overview' === $key )
			? __( 'About this report', 'switchrail-audit' )
			: __( 'How to read this list', 'switchrail-audit' );

		$screen->add_help_tab(
			array(
				'id'      => 'switchrail-audit-how-to-read',
				'title'   => $title,
				'content' => '<p class="switchrail-audit-help__title">' . esc_html( $title ) . '</p>'
					. '<div class="switchrail-audit-help__notes">' . wp_kses_post( '<p>' . implode( '</p><p>', $notes ) . '</p>' ) . '</div>'
					. '<p class="switchrail-audit-help__about">' . sprintf(
						/* translators: %s: Link to the WordPress VIP documentation. */
						esc_html__( 'Switchrail Audit is an independent tool, not affiliated with or endorsed by Automattic or WordPress VIP. Its rules are written from the public %s.', 'switchrail-audit' ),
						'<a href="https://docs.wpvip.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WordPress VIP documentation', 'switchrail-audit' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'switchrail-audit' ) . '</span></a>'
					) . '</p>',
			)
		);
	}
}
