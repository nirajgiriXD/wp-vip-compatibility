<?php
/**
 * Tests for the admin menu and screen routing.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Tests\Classes;

use CUTOVER_AUDIT\Includes\Classes\Settings;
use CUTOVER_AUDIT\Tests\Support\Fake_Screen;
use CUTOVER_AUDIT\Tests\Support\Redirect;
use CUTOVER_AUDIT\Tests\Support\WP;
use CUTOVER_AUDIT\Tests\TestCase;

/**
 * @covers \CUTOVER_AUDIT\Includes\Classes\Settings
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
		$this->assertSame( 'cutover-audit', WP::$menu[0]['menu_slug'] );
		$this->assertSame( 'manage_options', WP::$menu[0]['capability'] );
		$this->assertSame( 'Cutover Audit', WP::$menu[0]['page_title'] );
		$this->assertSame( CUTOVER_AUDIT_URL . 'assets/menu-icon.svg', WP::$menu[0]['icon_url'] );

		$this->assertSame(
			array( 'cutover-audit', 'cutover-audit-findings', 'cutover-audit-plugins', 'cutover-audit-themes', 'cutover-audit-mu-plugins', 'cutover-audit-database', 'cutover-audit-directories' ),
			array_column( WP::$submenu, 'menu_slug' )
		);

		foreach ( WP::$submenu as $entry ) {
			$this->assertSame( 'manage_options', $entry['capability'] );
			$this->assertSame( 'cutover-audit', $entry['parent_slug'] );
		}
	}

	public function test_on_multisite_the_menu_needs_a_network_administrator() {
		WP::$multisite = true;

		$this->settings()->add_plugin_menus();

		$this->assertSame( 'manage_network_options', WP::$menu[0]['capability'] );

		foreach ( WP::$submenu as $entry ) {
			$this->assertSame( 'manage_network_options', $entry['capability'] );
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

		$this->assertStringContainsString( 'cutover-audit-menu-group cutover-audit-menu-group--first" aria-hidden="true">Summary</span>Overview', $titles[0] );
		$this->assertStringNotContainsString( 'cutover-audit-menu-group', $titles[1] );
		$this->assertStringContainsString( '<span class="cutover-audit-menu-group" aria-hidden="true">Code you ship</span>Plugins', $titles[2] );
		$this->assertStringContainsString( '<span class="cutover-audit-menu-group" aria-hidden="true">Site infrastructure</span>Database', $titles[5] );
	}

	public function test_the_menu_icon_is_the_full_colour_logo_file() {
		$svg = file_get_contents( CUTOVER_AUDIT_DIR . '/' . Settings::MENU_ICON );

		// A URL, not a data URI, so WordPress does not repaint it in one colour.
		$this->assertStringStartsNotWith( 'data:', Settings::get_menu_icon_url() );
		$this->assertNotFalse( simplexml_load_string( $svg ), 'well-formed SVG' );
		$this->assertStringContainsString( 'width="20" height="20"', $svg );
		$this->assertStringContainsString( '#34d399', $svg, 'the logo green' );
	}

	public function test_the_menu_title_has_no_count_even_with_must_fix_findings() {
		$this->store_result( $this->add_plugin( 'p' ), array( $this->finding( 'security.command-execution' ) ) );

		$this->settings()->add_plugin_menus();

		$this->assertSame( 'Cutover Audit', WP::$menu[0]['menu_title'] );
	}

	public function test_every_screen_hooks_its_help_tab_to_its_load_action() {
		$this->settings()->add_plugin_menus();

		$this->assertTrue( has_action( 'load-toplevel_page_cutover-audit' ) );

		foreach ( array( 'cutover-audit-findings', 'cutover-audit-plugins', 'cutover-audit-themes', 'cutover-audit-mu-plugins', 'cutover-audit-database', 'cutover-audit-directories' ) as $slug ) {
			$this->assertTrue( has_action( 'load-cutover-audit_page_' . $slug ), $slug );
		}
	}

	public function test_loading_a_screen_adds_its_help_tab() {
		WP::$screen = new Fake_Screen();

		$this->call_private( $this->settings(), 'add_help_tab', array( 'plugins' ) );

		$this->assertCount( 1, WP::$screen->help_tabs );
		$this->assertSame( 'cutover-audit-how-to-read', WP::$screen->help_tabs[0]['id'] );
		$this->assertSame( 'How to read this list', WP::$screen->help_tabs[0]['title'] );
		$this->assertStringContainsString( '<div class="cutover-audit-help__notes"><p>', WP::$screen->help_tabs[0]['content'] );
		$this->assertStringContainsString( '<p class="cutover-audit-help__about">Cutover Audit is an independent tool', WP::$screen->help_tabs[0]['content'] );
		$this->assertStringContainsString( 'not affiliated with or endorsed by Automattic or WordPress VIP', WP::$screen->help_tabs[0]['content'] );
		$this->assertStringContainsString( '<a href="https://docs.wpvip.com/" target="_blank" rel="noopener noreferrer">WordPress VIP documentation', WP::$screen->help_tabs[0]['content'] );
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

		$this->assertStringStartsWith( '<div class="wrap cutover-audit">', trim( $output ) );
		$this->assertStringContainsString( '<h1 class="cutover-audit-head__title">Database</h1>', $output );
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
			'inventory'                => array( array( 'page' => 'cutover-audit-inventory' ), $admin . 'cutover-audit-plugins' ),
			'inventory, themes'        => array( array( 'page' => 'cutover-audit-inventory', 'kind' => 'theme' ), $admin . 'cutover-audit-themes' ),
			'inventory, mu, filtered'  => array( array( 'page' => 'cutover-audit-inventory', 'kind' => 'mu-plugin', 'status' => 'review' ), $admin . 'cutover-audit-mu-plugins&status=review' ),
			'inventory, unknown kind'  => array( array( 'page' => 'cutover-audit-inventory', 'kind' => 'widgets' ), $admin . 'cutover-audit-plugins' ),
			'site'                     => array( array( 'page' => 'cutover-audit-site' ), $admin . 'cutover-audit-database' ),
			'site, directories'        => array( array( 'page' => 'cutover-audit-site', 'section' => 'directories' ), $admin . 'cutover-audit-directories' ),
		);
	}

	public function test_current_screens_are_not_redirected() {
		$_GET['page'] = 'cutover-audit-plugins';
		$this->settings()->redirect_retired_screens();

		unset( $_GET['page'] );
		$this->settings()->redirect_retired_screens();

		$this->addToAssertionCount( 1 );
	}
}
