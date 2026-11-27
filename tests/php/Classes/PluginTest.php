<?php
/**
 * Tests for the plugin service container and reference data.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Tests\Classes;

use CUTOVER_AUDIT\Includes\Classes\Plugin;
use CUTOVER_AUDIT\Tests\Support\Sandbox;
use CUTOVER_AUDIT\Tests\TestCase;

/**
 * @covers \CUTOVER_AUDIT\Includes\Classes\Plugin
 * @covers \CUTOVER_AUDIT\Includes\Traits\Singleton
 */
class PluginTest extends TestCase {

	public function test_the_constructor_starts_every_service() {
		// Earlier tests may already have created the services, and a singleton
		// that exists is not constructed again. Forget them so their hooks are
		// registered in this test.
		foreach ( array( 'Assets', 'Ajax', 'Settings', 'Export', 'Findings_Settings' ) as $service ) {
			$property = new \ReflectionProperty( 'CUTOVER_AUDIT\\Includes\\Classes\\' . $service, 'instance' );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}

		new Plugin();

		$this->assertNotFalse( has_action( 'admin_enqueue_scripts' ) );
		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_cutover_audit_scan_target' ) );
		$this->assertNotFalse( has_action( 'admin_post_cutover_audit_export' ) );
		$this->assertNotFalse( has_action( 'upgrader_process_complete' ) );
	}

	public function test_get_instance_is_a_singleton() {
		$this->assertSame( Plugin::get_instance(), Plugin::get_instance() );
	}

	public function test_reference_lists() {
		$plugin = Plugin::get_instance();

		$this->assertContains( 'utf8mb4_unicode_ci', $plugin->get_reference_list( 'vip_supported_collations' ) );
		$this->assertSame( 'fallback', $plugin->get_reference_list( 'does_not_exist', 'fallback' ) );
		$this->assertSame( array(), $plugin->get_reference_list( 'does_not_exist' ) );
		$this->assertArrayNotHasKey( 'tables', $plugin->get_reference() );
	}

	public function test_json_data_adds_the_table_sources() {
		$data = Plugin::get_instance()->get_json_data();

		$this->assertArrayHasKey( 'core_tables', $data['tables'] );
		$this->assertArrayHasKey( 'vendor_tables', $data['tables'] );
	}

	public function test_reference_data_is_read_once() {
		$plugin = new Plugin();

		$this->assertSame( $plugin->get_reference(), $plugin->get_reference() );
		$this->assertSame( $plugin->get_table_sources(), $plugin->get_table_sources() );
	}

	public function test_a_missing_or_broken_data_file_reads_as_empty() {
		$plugin = Plugin::get_instance();

		$this->assertSame( array(), $this->call_private( $plugin, 'read_json', array( WP_CONTENT_DIR . '/missing.json' ) ) );

		$broken = Sandbox::write( 'broken.json', '{"a":' );
		$this->assertSame( array(), $this->call_private( $plugin, 'read_json', array( $broken ) ) );

		$scalar = Sandbox::write( 'scalar.json', '"just a string"' );
		$this->assertSame( array(), $this->call_private( $plugin, 'read_json', array( $scalar ) ) );

		$valid = Sandbox::write( 'valid.json', '{"a":1}' );
		$this->assertSame( array( 'a' => 1 ), $this->call_private( $plugin, 'read_json', array( $valid ) ) );
	}

	public function test_the_bundled_data_files_are_valid_json() {
		foreach ( array( 'data.json', 'table-sources.json' ) as $file ) {
			json_decode( file_get_contents( CUTOVER_AUDIT_DIR . '/data/' . $file ), true );

			$this->assertSame( JSON_ERROR_NONE, json_last_error(), $file );
		}
	}
}
