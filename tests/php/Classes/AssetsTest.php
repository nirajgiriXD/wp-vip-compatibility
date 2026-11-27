<?php
/**
 * Tests for script and style loading.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Tests\Classes;

use SWITCHRAIL_AUDIT\Includes\Classes\Assets;
use SWITCHRAIL_AUDIT\Tests\Support\WP;
use SWITCHRAIL_AUDIT\Tests\TestCase;

/**
 * @covers \SWITCHRAIL_AUDIT\Includes\Classes\Assets
 */
class AssetsTest extends TestCase {

	public function test_hooks() {
		$assets = new Assets();

		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $assets, 'admin_enqueue_scripts' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $assets, 'enqueue_menu_styles' ) ) );
		$this->assertFalse( has_action( 'admin_head', array( $assets, 'print_menu_styles' ) ) );
		$this->assertSame( 10, has_filter( 'admin_body_class', array( $assets, 'add_body_class' ) ) );
	}

	public function test_nothing_is_loaded_outside_the_plugin_screens() {
		( new Assets() )->admin_enqueue_scripts();

		$_GET['page'] = 'some-other-plugin';
		( new Assets() )->admin_enqueue_scripts();

		$this->assertSame( array(), WP::$assets['enqueued_styles'] );
		$this->assertSame( array(), WP::$assets['enqueued_scripts'] );
	}

	/**
	 * @dataProvider plugin_pages
	 */
	public function test_every_plugin_screen_loads_the_script_and_stylesheet( $page ) {
		$_GET['page'] = $page;

		( new Assets() )->admin_enqueue_scripts();

		$this->assertSame( array( 'switchrail-audit-admin-styles' ), WP::$assets['enqueued_styles'] );
		$this->assertSame( array( 'switchrail-audit-admin-script' ), WP::$assets['enqueued_scripts'] );
	}

	public function plugin_pages() {
		return array(
			array( 'switchrail-audit' ),
			array( 'switchrail-audit-findings' ),
			array( 'switchrail-audit-plugins' ),
			array( 'switchrail-audit-themes' ),
			array( 'switchrail-audit-mu-plugins' ),
			array( 'switchrail-audit-database' ),
			array( 'switchrail-audit-directories' ),
		);
	}

	public function test_files_are_versioned_by_modification_time_and_the_script_loads_in_the_footer() {
		$_GET['page'] = 'switchrail-audit-plugins';

		( new Assets() )->admin_enqueue_scripts();

		$style  = WP::$assets['registered_styles']['switchrail-audit-admin-styles'];
		$script = WP::$assets['registered_scripts']['switchrail-audit-admin-script'];

		$this->assertSame( SWITCHRAIL_AUDIT_URL . 'assets/css/admin.css', $style['src'] );
		$this->assertSame( SWITCHRAIL_AUDIT_URL . 'assets/js/admin.js', $script['src'] );
		$this->assertSame( filemtime( SWITCHRAIL_AUDIT_DIR . '/assets/css/admin.css' ), $style['ver'] );
		$this->assertSame( array( 'jquery' ), $script['deps'] );
		$this->assertTrue( $script['args'] );
	}

	public function test_the_script_gets_the_ajax_url_a_nonce_and_its_strings() {
		$_GET['page'] = 'switchrail-audit-plugins';

		( new Assets() )->admin_enqueue_scripts();

		$localized = WP::$assets['localized']['switchrail-audit-admin-script'];

		$this->assertSame( '_SWITCHRAIL_AUDIT_', $localized['name'] );
		$this->assertSame( 'https://example.test/wp-admin/admin-ajax.php', $localized['data']['ajax_url'] );
		$this->assertSame( wp_create_nonce( 'switchrail_audit_ajax_nonce' ), $localized['data']['nonce'] );

		foreach ( array( 'checking', 'error', 'scanning', 'findingCount', 'showingFiltered', 'showingAll', 'noResults', 'confirmTitle', 'confirmAction', 'cancel', 'pageRange', 'copied', 'expandAll' ) as $key ) {
			$this->assertNotSame( '', $localized['data']['i18n'][ $key ] ?? '', $key );
		}
	}

	public function test_body_class_marks_only_the_plugin_screens() {
		$assets = new Assets();

		$this->assertSame( 'folded', $assets->add_body_class( 'folded' ) );

		$_GET['page'] = 'switchrail-audit-findings';

		$this->assertSame( 'folded switchrail-audit-screen', $assets->add_body_class( 'folded' ) );
	}

	/**
	 * Returns the menu CSS attached by enqueue_menu_styles().
	 *
	 * @return string
	 */
	private function menu_styles() {
		WP::$assets['inline_styles'] = array();

		( new Assets() )->enqueue_menu_styles();

		return implode( '', WP::$assets['inline_styles']['switchrail-audit-menu'] ?? array() );
	}

	public function test_menu_styles_load_on_every_admin_screen_without_a_file_or_a_style_tag() {
		$_GET['page'] = 'some-other-plugin';

		$css = $this->menu_styles();

		$this->assertSame( array( 'switchrail-audit-menu' ), WP::$assets['enqueued_styles'] );
		$this->assertFalse( WP::$assets['registered_styles']['switchrail-audit-menu']['src'] );
		$this->assertNotSame( '', $css );
		$this->assertStringNotContainsString( '<style', $css );
	}

	public function test_the_menu_icon_is_shown_at_full_opacity() {
		$this->assertStringContainsString( '#toplevel_page_switchrail-audit .wp-menu-image img{opacity:1', $this->menu_styles() );
	}

	public function test_menu_styles_use_the_admin_colour_scheme() {
		$GLOBALS['_wp_admin_css_colors'] = array(
			'fresh'    => (object) array( 'icon_colors' => array( 'base' => '#a7aaad' ) ),
			'midnight' => (object) array( 'icon_colors' => array( 'base' => '#f1f2f3' ) ),
		);

		$css = $this->menu_styles();

		$this->assertStringContainsString( '#toplevel_page_switchrail-audit .switchrail-audit-menu-group{', $css );
		$this->assertStringContainsString( 'color:#a7aaad;', $css, 'falls back to the default scheme' );

		WP::$user_options['admin_color'] = 'midnight';

		$this->assertStringContainsString( 'color:#f1f2f3;', $this->menu_styles() );
	}

	public function test_menu_styles_without_a_known_colour_leave_the_colour_out() {
		$GLOBALS['_wp_admin_css_colors'] = array();

		$this->assertStringNotContainsString( 'color:', preg_replace( '/border-top:[^;]+;/', '', $this->menu_styles() ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wp_admin_css_colors'] );
		parent::tearDown();
	}
}
