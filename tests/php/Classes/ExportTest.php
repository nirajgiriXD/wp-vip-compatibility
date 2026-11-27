<?php
/**
 * Tests for the report download.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Tests\Classes;

use SWITCHRAIL_AUDIT\Includes\Classes\Export;
use SWITCHRAIL_AUDIT\Tests\Support\WP;
use SWITCHRAIL_AUDIT\Tests\Support\Wp_Die;
use SWITCHRAIL_AUDIT\Tests\TestCase;

/**
 * @covers \SWITCHRAIL_AUDIT\Includes\Classes\Export
 */
class ExportTest extends TestCase {

	/**
	 * Runs the export handler in a separate process.
	 *
	 * @param string $format The requested format.
	 * @return array{body: string, headers: string[], nocache: int} What the request sent.
	 */
	private function download( $format ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/Support/export-runner.php' ) . ' ' . escapeshellarg( $format );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );

		$body   = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		$sent = json_decode( $errors, true );

		$this->assertIsArray( $sent, 'The runner reported its headers: ' . $errors );

		return array(
			'body'    => $body,
			'headers' => $sent['headers'],
			'nocache' => $sent['nocache'],
		);
	}

	public function test_formats() {
		$formats = Export::get_formats();

		$this->assertSame( array( 'json', 'csv', 'md' ), array_keys( $formats ) );
		$this->assertSame( 'text/csv', $formats['csv']['mime'] );
	}

	public function test_the_url_is_a_nonce_protected_admin_post_request() {
		$url = Export::get_url( 'csv' );

		$this->assertStringStartsWith( 'https://example.test/wp-admin/admin-post.php?', $url );
		$this->assertStringContainsString( 'action=switchrail_audit_export', $url );
		$this->assertStringContainsString( 'format=csv', $url );
		$this->assertStringContainsString( '_wpnonce=' . wp_create_nonce( Export::NONCE_ACTION ), $url );
	}

	public function test_the_handler_is_registered_on_admin_post() {
		$export = new Export();

		$this->assertSame( 10, has_action( 'admin_post_switchrail_audit_export', array( $export, 'handle' ) ) );
	}

	public function test_a_user_without_the_capability_is_refused() {
		WP::$can = false;

		try {
			( new Export() )->handle();
			$this->fail( 'Expected wp_die()' );
		} catch ( Wp_Die $die ) {
			$this->assertSame( 403, $die->args['response'] );
			$this->assertSame( array( 'manage_options' ), WP::$checked_caps );
			$this->assertSame( array(), WP::$checked_nonces, 'The nonce is not even looked at' );
		}
	}

	public function test_a_bad_nonce_is_refused() {
		WP::$nonce_valid = false;

		$this->expectException( Wp_Die::class );

		( new Export() )->handle();
	}

	public function test_json_download() {
		$sent = $this->download( 'json' );

		$payload = json_decode( $sent['body'], true );

		$this->assertIsArray( $payload, 'The body is clean JSON, with the buffered output discarded' );
		$this->assertSame( 'switchrail-audit', $payload['generator'] );
		$this->assertCount( 1, $payload['findings'] );
		$this->assertStringContainsString( "\n    ", $sent['body'], 'pretty-printed' );
		$this->assertStringContainsString( 'https://docs.wpvip.com/', $sent['body'], 'slashes are not escaped' );

		$this->assertContains( 'Content-Type: application/json; charset=utf-8', $sent['headers'] );
		$this->assertContains( 'X-Content-Type-Options: nosniff', $sent['headers'] );
		$this->assertSame( 1, $sent['nocache'] );

		$disposition = preg_grep( '/^Content-Disposition:/', $sent['headers'] );
		$this->assertMatchesRegularExpression( '/attachment; filename="switchrail-audit-example-test-\d{8}-\d{6}\.json"/', reset( $disposition ) );
		$this->assertEmpty( preg_grep( '/^Content-Length:/i', $sent['headers'] ), 'No length: compression would make it wrong' );
	}

	public function test_csv_download() {
		$sent = $this->download( 'csv' );

		$this->assertStringStartsWith( '"target_type","target"', $sent['body'] );
		$this->assertStringNotContainsString( 'STRAY OUTPUT', $sent['body'] );
		$this->assertContains( 'Content-Type: text/csv; charset=utf-8', $sent['headers'] );
		$this->assertNotEmpty( preg_grep( '/filename="switchrail-audit-.*\.csv"/', $sent['headers'] ) );
	}

	public function test_markdown_download() {
		$sent = $this->download( 'md' );

		$this->assertStringStartsWith( '# WordPress VIP compatibility report', $sent['body'] );
		$this->assertContains( 'Content-Type: text/markdown; charset=utf-8', $sent['headers'] );
	}

	public function test_an_unknown_format_falls_back_to_json() {
		$sent = $this->download( '../../etc/passwd' );

		$this->assertIsArray( json_decode( $sent['body'], true ) );
		$this->assertNotEmpty( preg_grep( '/filename="switchrail-audit-.*\.json"/', $sent['headers'] ) );
	}

	public function test_no_format_means_json() {
		$sent = $this->download( '' );

		$this->assertIsArray( json_decode( $sent['body'], true ) );
	}
}
