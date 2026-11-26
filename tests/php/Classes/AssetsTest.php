<?php
/**
 * Tests for script and style loading.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Classes;

use PREFLIGHT_AUDIT\Includes\Classes\Assets;
use PREFLIGHT_AUDIT\Tests\Support\WP;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Assets
 */
class AssetsTest extends TestCase {

	public function test_hooks() {
		$assets = new Assets();

		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $assets, 'admin_enqueue_scripts' ) ) );
		$this->assertSame( 10, has_action( 'admin_head', array( $assets, 'print_menu_styles' ) ) );
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

		$this->assertSame( array( 'preflight-audit-admin-styles' ), WP::$assets['enqueued_styles'] );
		$this->assertSame( array( 'preflight-audit-admin-script' ), WP::$assets['enqueued_scripts'] );
	}

	public function plugin_pages() {
		return array(
			array( 'preflight-audit' ),
			array( 'pfa-findings' ),
			array( 'pfa-plugins' ),
			array( 'pfa-themes' ),
			array( 'pfa-mu-plugins' ),
			array( 'pfa-database' ),
			array( 'pfa-directories' ),
		);
	}

	public function test_files_are_versioned_by_modification_time_and_the_script_loads_in_the_footer() {
		$_GET['page'] = 'pfa-plugins';

		( new Assets() )->admin_enqueue_scripts();

		$style  = WP::$assets['registered_styles']['preflight-audit-admin-styles'];
		$script = WP::$assets['registered_scripts']['preflight-audit-admin-script'];

		$this->assertStringEndsWith( '/assets/css/admin.css', $style['src'] );
		$this->assertSame( filemtime( PREFLIGHT_AUDIT_DIR . '/assets/css/admin.css' ), $style['ver'] );
		$this->assertSame( array( 'jquery' ), $script['deps'] );
		$this->assertTrue( $script['args'] );
	}

	public function test_the_script_gets_the_ajax_url_a_nonce_and_its_strings() {
		$_GET['page'] = 'pfa-plugins';

		( new Assets() )->admin_enqueue_scripts();

		$localized = WP::$assets['localized']['preflight-audit-admin-script'];

		$this->assertSame( '_PREFLIGHT_AUDIT_', $localized['name'] );
		$this->assertSame( 'https://example.test/wp-admin/admin-ajax.php', $localized['data']['ajax_url'] );
		$this->assertSame( wp_create_nonce( 'preflight_audit_ajax_nonce' ), $localized['data']['nonce'] );

		foreach ( array( 'checking', 'error', 'scanning', 'findingCount', 'showingFiltered', 'showingAll', 'noResults', 'confirmTitle', 'confirmAction', 'cancel', 'pageRange', 'copied', 'expandAll' ) as $key ) {
			$this->assertNotSame( '', $localized['data']['i18n'][ $key ] ?? '', $key );
		}
	}

	public function test_body_class_marks_only_the_plugin_screens() {
		$assets = new Assets();

		$this->assertSame( 'folded', $assets->add_body_class( 'folded' ) );

		$_GET['page'] = 'pfa-findings';

		$this->assertSame( 'folded pfa-screen', $assets->add_body_class( 'folded' ) );
	}

	public function test_menu_styles_use_the_admin_colour_scheme() {
		$GLOBALS['_wp_admin_css_colors'] = array(
			'fresh'    => (object) array( 'icon_colors' => array( 'base' => '#a7aaad' ) ),
			'midnight' => (object) array( 'icon_colors' => array( 'base' => '#f1f2f3' ) ),
		);

		$output = $this->render( array( new Assets(), 'print_menu_styles' ) );

		$this->assertStringContainsString( '#toplevel_page_preflight-audit .pfa-menu-group', $output );
		$this->assertStringContainsString( 'color: #a7aaad;', $output, 'falls back to the default scheme' );

		WP::$user_options['admin_color'] = 'midnight';

		$this->assertStringContainsString( 'color: #f1f2f3;', $this->render( array( new Assets(), 'print_menu_styles' ) ) );
	}

	public function test_menu_styles_without_a_known_colour_leave_the_colour_out() {
		$GLOBALS['_wp_admin_css_colors'] = array();

		$output = $this->render( array( new Assets(), 'print_menu_styles' ) );

		$this->assertStringNotContainsString( 'color:', preg_replace( '/border-top:[^;]+;/', '', $output ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wp_admin_css_colors'] );
		parent::tearDown();
	}
}
