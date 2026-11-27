<?php
/**
 * Tests for the uninstall routine.
 *
 * uninstall.php runs top-level code and declares a function, so every test
 * loads it in a process of its own.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Tests\Plugin;

use SWITCHRAIL_AUDIT\Includes\Scanner\Database_Audit;
use SWITCHRAIL_AUDIT\Includes\Scanner\Results_Store;
use SWITCHRAIL_AUDIT\Tests\Support\WP;
use SWITCHRAIL_AUDIT\Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallTest extends TestCase {

	/**
	 * Stores a result, history and a cached database audit on the current blog.
	 *
	 * @return void
	 */
	private function seed_current_blog() {
		Results_Store::flush_index();
		$this->store_result( $this->add_plugin( 'p' ), array( $this->finding( 'security.extract' ) ) );
		Results_Store::record_history( array( 'total' => 1 ) );
		Database_Audit::run();
	}

	public function test_uninstall_removes_everything_the_plugin_stored() {
		$this->seed_current_blog();
		update_option( 'unrelated_option', 'keep me' );

		define( 'WP_UNINSTALL_PLUGIN', 'switchrail-audit/switchrail-audit.php' );
		require SWITCHRAIL_AUDIT_DIR . '/uninstall.php';

		$this->assertSame( array( 'unrelated_option' => 'keep me' ), WP::blog_options() );
		$this->assertArrayNotHasKey( Database_Audit::CACHE_KEY, WP::$transients[1] );
	}

	public function test_uninstall_refuses_to_run_unless_wordpress_is_uninstalling() {
		$php    = escapeshellarg( PHP_BINARY );
		$script = escapeshellarg( SWITCHRAIL_AUDIT_DIR . '/uninstall.php' );
		$output = shell_exec( "{$php} {$script} 2>&1" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

		$this->assertSame( '', (string) $output );
	}

	/**
	 * Every site on the network keeps its own results, so every site has to be
	 * cleaned, even though the results store keeps a per-request copy of the
	 * index that does not change when the blog switches.
	 */
	public function test_uninstall_on_multisite_cleans_every_site() {
		WP::$multisite = true;
		WP::$sites     = array( 1, 2 );

		$this->seed_current_blog();

		switch_to_blog( 2 );
		$this->seed_current_blog();
		restore_current_blog();

		define( 'WP_UNINSTALL_PLUGIN', 'switchrail-audit/switchrail-audit.php' );
		require SWITCHRAIL_AUDIT_DIR . '/uninstall.php';

		$this->assertSame( array(), WP::$options[1], 'site 1' );
		$this->assertSame( array(), WP::$options[2], 'site 2' );
		$this->assertSame( 1, WP::$blog_id, 'restored to the original blog' );
	}

	public function test_uninstall_on_multisite_visits_every_site_and_restores() {
		WP::$multisite = true;
		WP::$sites     = array( 1, 2, 3 );

		update_option( Results_Store::HISTORY_OPTION, array( 1 ) );
		switch_to_blog( 3 );
		update_option( Results_Store::HISTORY_OPTION, array( 1 ) );
		restore_current_blog();

		define( 'WP_UNINSTALL_PLUGIN', 'switchrail-audit/switchrail-audit.php' );
		require SWITCHRAIL_AUDIT_DIR . '/uninstall.php';

		$this->assertArrayNotHasKey( Results_Store::HISTORY_OPTION, WP::$options[1] );
		$this->assertArrayNotHasKey( Results_Store::HISTORY_OPTION, WP::$options[3] );
		$this->assertSame( 1, WP::$blog_id );
	}
}
