<?php
/**
 * Tests for the AJAX endpoints.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Classes;

use PREFLIGHT_AUDIT\Includes\Classes\Ajax;
use PREFLIGHT_AUDIT\Includes\Scanner\Results_Store;
use PREFLIGHT_AUDIT\Tests\Support\Json_Response;
use PREFLIGHT_AUDIT\Tests\Support\WP;
use PREFLIGHT_AUDIT\Tests\Support\Wp_Die;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Ajax
 */
class AjaxTest extends TestCase {

	const GUARD = "<?php\ndefined( 'ABSPATH' ) || exit;\n";

	/**
	 * Calls an endpoint and returns the JSON response it sent.
	 *
	 * @param string $method The handler.
	 * @return Json_Response The response.
	 */
	private function call( $method ) {
		try {
			( new Ajax() )->$method();
		} catch ( Json_Response $response ) {
			return $response;
		}

		$this->fail( 'The endpoint did not send a response' );
	}

	public function test_endpoints_are_registered_for_logged_in_users_only() {
		$ajax = new Ajax();

		$this->assertSame( 10, has_action( 'wp_ajax_preflight_audit_scan_target', array( $ajax, 'scan_target' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_preflight_audit_scan_complete', array( $ajax, 'scan_complete' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_preflight_audit_scan_target' ) );
	}

	/**
	 * @dataProvider endpoints
	 */
	public function test_a_user_without_the_capability_is_refused( $method ) {
		WP::$can = false;

		$response = $this->call( $method );

		$this->assertFalse( $response->success );
		$this->assertSame( 403, $response->status );
		$this->assertSame( array( 'manage_options' ), WP::$checked_caps );
	}

	/**
	 * @dataProvider endpoints
	 */
	public function test_a_bad_nonce_is_refused( $method ) {
		WP::$nonce_valid = false;

		try {
			( new Ajax() )->$method();
			$this->fail( 'Expected the nonce check to stop the request' );
		} catch ( Wp_Die $die ) {
			$this->assertSame( array( 'preflight_audit_ajax_nonce' ), WP::$checked_nonces );
		}
	}

	public function endpoints() {
		return array( array( 'scan_target' ), array( 'scan_complete' ) );
	}

	public function test_a_missing_target_is_a_bad_request() {
		$response = $this->call( 'scan_target' );

		$this->assertFalse( $response->success );
		$this->assertSame( 400, $response->status );
	}

	/**
	 * @dataProvider unknown_targets
	 */
	public function test_an_unknown_target_is_not_found( $target ) {
		$this->add_plugin( 'real' );
		$_POST['target'] = $target;

		$response = $this->call( 'scan_target' );

		$this->assertFalse( $response->success );
		$this->assertSame( 404, $response->status );
	}

	public function unknown_targets() {
		return array(
			'unknown key'          => array( 'plugin:nope' ),
			'filesystem path'      => array( WP_PLUGIN_DIR . '/real' ),
			'traversal'            => array( 'plugin:../../../etc' ),
		);
	}

	public function test_scanning_a_target_returns_its_verdict() {
		$this->add_plugin( 'writer', array( 'writer.php' => self::GUARD . "file_put_contents( __DIR__ . '/x', 1 );\nexec( 'ls' );\n" ) );
		$_POST['target'] = 'plugin:writer';

		$response = $this->call( 'scan_target' );
		$data     = $response->data;

		$this->assertTrue( $response->success );
		$this->assertSame( 'plugin:writer', $data['target'] );
		$this->assertSame( 'blocked', $data['status'] );
		$this->assertSame( 'Needs work', $data['label'] );
		$this->assertSame( 'not-compatible', $data['state'] );
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 1, $data['blocking'] );
		$this->assertSame( 'critical', $data['severity'] );
		$this->assertSame( 1, $data['files'] );
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=pfa-findings&target=plugin:writer', $data['url'] );
		$this->assertNotNull( Results_Store::get( 'plugin:writer' ) );
	}

	public function test_a_clean_target_has_no_severity() {
		$this->add_plugin( 'clean', array( 'clean.php' => self::GUARD ) );
		$_POST['target'] = 'plugin:clean';

		$data = $this->call( 'scan_target' )->data;

		$this->assertSame( 'compatible', $data['state'] );
		$this->assertSame( '', $data['severity'] );
	}

	public function test_the_summary_counts_findings_and_files() {
		$this->add_plugin( 'p', array( 'a.php' => self::GUARD . "exec( 'ls' );\nexec( 'pwd' );\n", 'b.php' => self::GUARD ) );
		$_POST['target'] = 'plugin:p';

		$this->assertSame( '2 findings across 2 PHP files.', $this->call( 'scan_target' )->data['summary'] );
	}

	/**
	 * The findings count and the file count each pick their own plural form.
	 *
	 * @dataProvider plural_cases
	 */
	public function test_the_file_count_has_its_own_plural( array $files, $summary ) {
		$this->add_plugin( 'p', $files );
		$_POST['target'] = 'plugin:p';

		$this->assertSame( $summary, $this->call( 'scan_target' )->data['summary'] );
	}

	public function test_the_count_label_has_its_own_plural() {
		$this->add_plugin( 'p', array( 'a.php' => self::GUARD . "exec( 'ls' );\n" ) );
		$_POST['target'] = 'plugin:p';

		$this->assertSame( '1 finding', $this->call( 'scan_target' )->data['count'] );
	}

	public function plural_cases() {
		return array(
			'many findings, one file' => array( array( 'a.php' => self::GUARD . "exec( 'ls' );\nexec( 'pwd' );\n" ), '2 findings across 1 PHP file.' ),
			'no findings, one file'   => array( array( 'a.php' => self::GUARD ), '0 findings across 1 PHP file.' ),
			'one finding, two files'  => array( array( 'a.php' => self::GUARD . "exec( 'ls' );\n", 'b.php' => self::GUARD ), '1 finding across 2 PHP files.' ),
		);
	}

	public function test_a_review_verdict_maps_to_the_review_state() {
		$this->add_plugin( 'shell', array( 'shell.php' => self::GUARD . "exec( 'ls' );\n" ) );
		$_POST['target'] = 'plugin:shell';

		$this->assertSame( 'review', $this->call( 'scan_target' )->data['state'] );
	}

	public function test_force_bypasses_the_stored_result() {
		$target = $this->add_plugin( 'p', array( 'p.php' => self::GUARD ) );
		$this->store_result( $target, array( $this->finding( 'security.extract' ) ), array( 'fingerprint' => ( new \PREFLIGHT_AUDIT\Includes\Scanner\Scanner() )->fingerprint( $target['path'] ) ) );
		$_POST['target'] = 'plugin:p';

		$this->assertSame( 1, $this->call( 'scan_target' )->data['total'], 'served from the store' );

		$_POST['force'] = '1';

		$this->assertSame( 0, $this->call( 'scan_target' )->data['total'], 'rescanned' );
	}

	public function test_the_target_is_unslashed_and_sanitised() {
		$this->add_plugin( 'p' );
		$_POST['target'] = "  plugin:p\n";

		$this->assertTrue( $this->call( 'scan_target' )->success );
	}

	public function test_scan_complete_records_a_snapshot() {
		$this->store_result( $this->add_plugin( 'p' ), array( $this->finding( 'security.extract' ) ) );
		$_POST['scope'] = 'plugin';

		$response = $this->call( 'scan_complete' );

		$this->assertTrue( $response->success );
		$this->assertSame( array( 'scope' => 'plugin' ), $response->data );
		$this->assertCount( 1, Results_Store::get_history() );
		$this->assertSame( 1, Results_Store::get_history()[0]['total'] );
	}

	public function test_scan_complete_resolves_an_unknown_scope_to_everything() {
		$_POST['scope'] = 'nonsense';

		$this->assertSame( array( 'scope' => 'all' ), $this->call( 'scan_complete' )->data );

		unset( $_POST['scope'] );

		$this->assertSame( array( 'scope' => 'all' ), $this->call( 'scan_complete' )->data );
	}
}
