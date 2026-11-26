<?php
/**
 * Tests for the admin menu and screen routing.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Classes;

use PREFLIGHT_AUDIT\Includes\Classes\Settings;
use PREFLIGHT_AUDIT\Tests\Support\Fake_Screen;
use PREFLIGHT_AUDIT\Tests\Support\Redirect;
use PREFLIGHT_AUDIT\Tests\Support\WP;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Settings
 */
class SettingsTest extends TestCase {

	/**
	 * A fresh Settings instance, with its hooks registered.
	 *
	 * @return Settings The instance.
	 */
	private function settings() {
		$settings = $this->instance( Settings::class );
		$this->call_private( $settings, 'setup_hooks' );

		return $settings;
	}

	public function test_hooks() {
		$settings = $this->settings();

		$this->assertSame( 10, has_action( 'admin_menu', array( $settings, 'add_plugin_menus' ) ) );
		$this->assertSame( 0, has_action( 'admin_menu', array( $settings, 'redirect_retired_screens' ) ) );
	}

	public function test_the_menu_registers_every_screen_behind_manage_options() {
		$this->settings()->add_plugin_menus();

		$this->assertCount( 1, WP::$menu );
		$this->assertSame( 'preflight-audit', WP::$menu[0]['menu_slug'] );
		$this->assertSame( 'manage_options', WP::$menu[0]['capability'] );
		$this->assertSame( 'Preflight Audit', WP::$menu[0]['page_title'] );
		$this->assertSame( Settings::MENU_ICON, WP::$menu[0]['icon_url'] );

		$this->assertSame(
			array( 'preflight-audit', 'pfa-findings', 'pfa-plugins', 'pfa-themes', 'pfa-mu-plugins', 'pfa-database', 'pfa-directories' ),
			array_column( WP::$submenu, 'menu_slug' )
		);

		foreach ( WP::$submenu as $entry ) {
			$this->assertSame( 'manage_options', $entry['capability'] );
			$this->assertSame( 'preflight-audit', $entry['parent_slug'] );
		}
	}

	public function test_the_overview_submenu_has_no_callback_of_its_own() {
		$this->settings()->add_plugin_menus();

		// It shares the top-level hook; a second callback renders the page twice.
		$this->assertNull( WP::$submenu[0]['callback'] );
		$this->assertTrue( is_callable( WP::$submenu[1]['callback'] ) );
		$this->assertTrue( is_callable( WP::$menu[0]['callback'] ) );
	}

	public function test_each_group_is_headed_once_and_the_first_heading_has_no_separator() {
		$this->settings()->add_plugin_menus();

		$titles = array_column( WP::$submenu, 'menu_title' );

		$this->assertStringContainsString( 'pfa-menu-group pfa-menu-group--first" aria-hidden="true">Readiness</span>Overview', $titles[0] );
		$this->assertStringNotContainsString( 'pfa-menu-group', $titles[1] );
		$this->assertStringContainsString( '<span class="pfa-menu-group" aria-hidden="true">Code you ship</span>Plugins', $titles[2] );
		$this->assertStringContainsString( '<span class="pfa-menu-group" aria-hidden="true">Site infrastructure</span>Database', $titles[5] );
	}

	public function test_the_menu_icon_is_a_recolourable_svg() {
		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', Settings::MENU_ICON );

		$svg = base64_decode( substr( Settings::MENU_ICON, strlen( 'data:image/svg+xml;base64,' ) ) );

		$this->assertStringStartsWith( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">', $svg );
		$this->assertNotFalse( simplexml_load_string( $svg ), 'well-formed SVG' );
		$this->assertStringNotContainsString( 'stroke', $svg, 'WordPress only recolours fills' );
		$this->assertSame( substr_count( $svg, '<path' ) + substr_count( $svg, '<circle' ), substr_count( $svg, 'fill=' ) );
	}

	public function test_the_menu_badge_counts_only_must_fix_findings() {
		$this->store_result(
			$this->add_plugin( 'p' ),
			array(
				$this->finding( 'security.command-execution' ),
				$this->finding( 'security.dynamic-code', array( 'line' => 2 ) ),
				$this->finding( 'caching.cache-flush', array( 'line' => 3 ) ),
			)
		);

		$this->settings()->add_plugin_menus();

		$this->assertStringContainsString( '<span class="update-plugins count-2">', WP::$menu[0]['menu_title'] );
		$this->assertStringContainsString( '2 findings must be fixed before migrating', WP::$menu[0]['menu_title'] );
	}

	public function test_no_badge_when_nothing_must_be_fixed() {
		$this->store_result( $this->add_plugin( 'p' ), array( $this->finding( 'caching.cache-flush' ) ) );

		$this->settings()->add_plugin_menus();

		$this->assertSame( 'Preflight Audit', WP::$menu[0]['menu_title'] );
	}

	public function test_every_screen_hooks_its_help_tab_to_its_load_action() {
		$this->settings()->add_plugin_menus();

		$this->assertTrue( has_action( 'load-toplevel_page_preflight-audit' ) );

		foreach ( array( 'pfa-findings', 'pfa-plugins', 'pfa-themes', 'pfa-mu-plugins', 'pfa-database', 'pfa-directories' ) as $slug ) {
			$this->assertTrue( has_action( 'load-preflight-audit_page_' . $slug ), $slug );
		}
	}

	public function test_loading_a_screen_adds_its_help_tab() {
		WP::$screen = new Fake_Screen();

		$this->call_private( $this->settings(), 'add_help_tab', array( 'plugins' ) );

		$this->assertCount( 1, WP::$screen->help_tabs );
		$this->assertSame( 'pfa-how-to-read', WP::$screen->help_tabs[0]['id'] );
		$this->assertSame( 'How to read this list', WP::$screen->help_tabs[0]['title'] );
		$this->assertStringContainsString( '<div class="pfa-help__notes"><p>', WP::$screen->help_tabs[0]['content'] );
	}

	public function test_the_overview_help_tab_is_titled_as_a_report() {
		WP::$screen = new Fake_Screen();

		$this->call_private( $this->settings(), 'add_help_tab', array( 'overview' ) );

		$this->assertSame( 'About this report', WP::$screen->help_tabs[0]['title'] );
	}

	public function test_no_help_tab_without_a_screen_or_an_unknown_key() {
		$settings = $this->settings();

		$this->call_private( $settings, 'add_help_tab', array( 'database' ) );

		WP::$screen = new Fake_Screen();
		$this->call_private( $settings, 'add_help_tab', array( 'nope' ) );

		$this->assertSame( array(), WP::$screen->help_tabs );
	}

	public function test_rendering_a_screen_wraps_it_in_the_shell() {
		$settings = $this->settings();

		$output = $this->render(
			function () use ( $settings ) {
				$settings->render_settings_page( 'database' );
			}
		);

		$this->assertStringStartsWith( '<div class="wrap pfa">', trim( $output ) );
		$this->assertStringContainsString( '<h1 class="pfa-head__title">Database</h1>', $output );
		$this->assertStringEndsWith( '</div></div>', trim( $output ) );
	}

	public function test_rendering_an_unknown_screen_prints_nothing() {
		$settings = $this->settings();

		$this->assertSame(
			'',
			$this->render(
				function () use ( $settings ) {
					$settings->render_settings_page( 'nope' );
				}
			)
		);
	}

	/**
	 * @dataProvider retired_screens
	 */
	public function test_retired_screens_redirect_to_their_successor( array $query, $location ) {
		$_GET = $query;

		try {
			$this->settings()->redirect_retired_screens();
			$this->fail( 'Expected a redirect' );
		} catch ( Redirect $redirect ) {
			$this->assertSame( $location, $redirect->location );
		}
	}

	public function retired_screens() {
		$admin = 'https://example.test/wp-admin/admin.php?page=';

		return array(
			'inventory'                => array( array( 'page' => 'pfa-inventory' ), $admin . 'pfa-plugins' ),
			'inventory, themes'        => array( array( 'page' => 'pfa-inventory', 'kind' => 'theme' ), $admin . 'pfa-themes' ),
			'inventory, mu, filtered'  => array( array( 'page' => 'pfa-inventory', 'kind' => 'mu-plugin', 'status' => 'review' ), $admin . 'pfa-mu-plugins&status=review' ),
			'inventory, unknown kind'  => array( array( 'page' => 'pfa-inventory', 'kind' => 'widgets' ), $admin . 'pfa-plugins' ),
			'site'                     => array( array( 'page' => 'pfa-site' ), $admin . 'pfa-database' ),
			'site, directories'        => array( array( 'page' => 'pfa-site', 'section' => 'directories' ), $admin . 'pfa-directories' ),
		);
	}

	public function test_current_screens_are_not_redirected() {
		$_GET['page'] = 'pfa-plugins';
		$this->settings()->redirect_retired_screens();

		unset( $_GET['page'] );
		$this->settings()->redirect_retired_screens();

		$this->addToAssertionCount( 1 );
	}
}
