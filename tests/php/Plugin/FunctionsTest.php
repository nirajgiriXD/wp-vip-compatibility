<?php
/**
 * Tests for the public helper functions.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Tests\Plugin;

use SWITCHRAIL_AUDIT\Includes\Scanner\Results_Store;
use SWITCHRAIL_AUDIT\Tests\Support\Sandbox;
use SWITCHRAIL_AUDIT\Tests\TestCase;

/**
 * @covers ::switchrail_audit_check_compatibility
 * @covers ::switchrail_audit_resolve_target
 * @covers ::switchrail_audit_get_json_data
 * @covers ::switchrail_audit_get_target_chart_data
 * @covers ::switchrail_audit_get_plugins_chart_data
 * @covers ::switchrail_audit_get_mu_plugins_chart_data
 * @covers ::switchrail_audit_get_themes_chart_data
 * @covers ::switchrail_audit_get_database_chart_data
 * @covers ::switchrail_audit_get_directories_chart_data
 */
class FunctionsTest extends TestCase {

	const GUARD = "<?php\ndefined( 'ABSPATH' ) || exit;\n";

	/**
	 * @dataProvider verdict_cases
	 */
	public function test_check_compatibility_returns_a_word_for_the_verdict( $code, $expected ) {
		$this->add_plugin( 'p', array( 'p.php' => self::GUARD . $code ) );

		$this->assertSame( $expected, switchrail_audit_check_compatibility( 'plugin:p' ) );
	}

	public function verdict_cases() {
		return array(
			'clean'    => array( '', 'Compatible' ),
			'security' => array( "exec( 'ls' );", 'Needs review' ),
			'blocked'  => array( "file_put_contents( __DIR__ . '/x', 1 );", 'Incompatible' ),
		);
	}

	public function test_check_compatibility_stores_the_result() {
		$this->add_plugin( 'p' );

		switchrail_audit_check_compatibility( 'plugin:p' );

		$this->assertNotNull( Results_Store::get( 'plugin:p' ) );
	}

	public function test_check_compatibility_rejects_an_unknown_target() {
		$this->assertSame( 'Error: Unknown scan target', switchrail_audit_check_compatibility( 'plugin:nope' ) );
		$this->assertSame( 'Error: Unknown scan target', switchrail_audit_check_compatibility( '' ) );
	}

	public function test_a_legacy_path_resolves_only_to_a_known_target() {
		$this->add_plugin( 'p' );

		$this->assertSame( 'plugin:p', switchrail_audit_resolve_target( WP_PLUGIN_DIR . '/p' )['key'] );
		$this->assertSame( 'plugin:p', switchrail_audit_resolve_target( WP_PLUGIN_DIR . '/p/' )['key'] );
		$this->assertSame( 'plugin:p', switchrail_audit_resolve_target( str_replace( '/', '\\', WP_PLUGIN_DIR . '/p' ) )['key'] );

		Sandbox::mkdir( 'secret' );

		$this->assertNull( switchrail_audit_resolve_target( WP_CONTENT_DIR . '/secret' ) );
		$this->assertNull( switchrail_audit_resolve_target( '/etc' ) );
		$this->assertNull( switchrail_audit_resolve_target( null ) );
	}

	public function test_get_json_data_includes_the_reference_lists_and_tables() {
		$data = switchrail_audit_get_json_data();

		$this->assertArrayHasKey( 'directories', $data );
		$this->assertArrayHasKey( 'known_plugins', $data );
		$this->assertArrayHasKey( 'core_tables', $data['tables'] );
	}

	public function test_chart_data_counts_unscanned_targets_as_needing_review() {
		$this->store_result( $this->add_plugin( 'ready' ), array() );
		$this->add_plugin( 'wp-rocket' );
		$this->add_plugin( 'unscanned' );

		$this->assertSame(
			array(
				'compatible'     => 1,
				'needs_review'   => 1,
				'not_compatible' => 1,
			),
			switchrail_audit_get_plugins_chart_data()
		);
	}

	public function test_each_chart_helper_reads_its_own_area() {
		$this->store_result( $this->add_theme( 't' ), array() );
		$this->add_mu_plugin( 'loader.php' );
		$this->wpdb->add_table( 'wp_posts' );

		$this->assertSame( 1, switchrail_audit_get_themes_chart_data()['compatible'] );
		$this->assertSame( 1, switchrail_audit_get_mu_plugins_chart_data()['needs_review'] );
		$this->assertSame( 1, switchrail_audit_get_database_chart_data()['compatible'] );
		// plugins/ and themes/ are supported; mu-plugins/ is not.
		$this->assertSame( 2, switchrail_audit_get_directories_chart_data()['compatible'] );
		$this->assertSame( 1, switchrail_audit_get_directories_chart_data()['not_compatible'] );
	}
}
