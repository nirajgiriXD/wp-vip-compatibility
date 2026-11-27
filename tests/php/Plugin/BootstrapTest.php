<?php
/**
 * Tests for the plugin's main file.
 *
 * Loading the main file defines a class and starts the plugin, which can only
 * happen once per PHP process, so each test runs in a process of its own.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Tests\Plugin;

use CUTOVER_AUDIT\Tests\Support\WP;
use CUTOVER_AUDIT\Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BootstrapTest extends TestCase {

	public function test_the_header_names_the_plugin_and_its_text_domain() {
		$header = file_get_contents( CUTOVER_AUDIT_FILE, false, null, 0, 2048 );

		$this->assertMatchesRegularExpression( '/Plugin Name:\s+Cutover Audit$/m', $header );
		$this->assertMatchesRegularExpression( '/Text Domain:\s+cutover-audit$/m', $header );
		$this->assertMatchesRegularExpression( '/Requires PHP:\s+7\.4$/m', $header );
		$this->assertStringNotContainsString( 'WordPress VIP Compatibility', $header );
	}

	public function test_the_version_constant_matches_the_header() {
		$source = file_get_contents( CUTOVER_AUDIT_FILE );

		preg_match( '/^\s*\*\s*Version:\s+(\S+)/m', $source, $header );
		preg_match( "/define\( 'CUTOVER_AUDIT_VERSION', '([^']+)' \)/", $source, $constant );

		$this->assertSame( $header[1], $constant[1] );

		preg_match( '/^Stable tag:\s+(\S+)/m', file_get_contents( CUTOVER_AUDIT_DIR . '/readme.txt' ), $stable );

		$this->assertSame( $header[1], $stable[1], 'readme.txt Stable tag' );
	}

	public function test_loading_the_main_file_starts_the_plugin() {
		require CUTOVER_AUDIT_FILE;

		$this->assertTrue( class_exists( 'Cutover_Audit', false ) );
		$this->assertInstanceOf( \Cutover_Audit::class, \Cutover_Audit::get_instance() );

		// The services are hooked up.
		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_cutover_audit_scan_target' ) );
		$this->assertNotFalse( has_action( 'admin_post_cutover_audit_export' ) );
		$this->assertNotFalse( has_action( 'admin_post_cutover_audit_rescan' ) );

		// Nothing runs on activation or deactivation: results survive it.
		$this->assertEmpty( WP::$lifecycle['activation'] ?? array() );
		$this->assertEmpty( WP::$lifecycle['deactivation'] ?? array() );
		$this->assertSame( array(), WP::blog_options() );
	}

	public function test_the_plugin_does_nothing_outside_wordpress() {
		$php    = escapeshellarg( PHP_BINARY );
		$script = escapeshellarg( CUTOVER_AUDIT_FILE );
		$output = shell_exec( "{$php} {$script} 2>&1" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

		$this->assertSame( '', (string) $output, 'The ABSPATH guard exits silently' );
	}
}
