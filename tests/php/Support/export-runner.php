<?php
/**
 * Runs Export::handle() in a process of its own.
 *
 * The handler ends with `exit`, which would end the test run too. ExportTest
 * starts this script instead and reads what it prints: the download body on
 * standard output, and the headers it sent as JSON on standard error.
 *
 * Usage: php export-runner.php <format>
 *
 * @package switchrail-audit
 */

// phpcs:disable -- Test support script.

require dirname( __DIR__ ) . '/bootstrap.php';

use SWITCHRAIL_AUDIT\Includes\Classes\Export;
use SWITCHRAIL_AUDIT\Includes\Scanner\Scanner;
use SWITCHRAIL_AUDIT\Includes\Scanner\Targets;
use SWITCHRAIL_AUDIT\Tests\Support\Fake_Wpdb;
use SWITCHRAIL_AUDIT\Tests\Support\Sandbox;
use SWITCHRAIL_AUDIT\Tests\Support\WP;

Brain\Monkey\setUp();
WP::reset();
Sandbox::reset();
$GLOBALS['wpdb'] = new Fake_Wpdb();

$switchrail_audit_headers = array();

Patchwork\redefine(
	'header',
	static function ( $header ) use ( &$switchrail_audit_headers ) {
		$switchrail_audit_headers[] = $header;
	}
);

register_shutdown_function(
	static function () use ( &$switchrail_audit_headers ) {
		fwrite( STDERR, json_encode( array( 'headers' => $switchrail_audit_headers, 'nocache' => WP::$nocache_calls ) ) );
	}
);

// One plugin with one finding.
Sandbox::write( 'plugins/shell/shell.php', "<?php\ndefined( 'ABSPATH' ) || exit;\nexec( 'ls' );\n" );
WP::$plugins['shell/shell.php'] = array(
	'Name'    => 'Shell',
	'Version' => '1.0.0',
	'Author'  => 'Someone',
);
( new Scanner() )->get_result( Targets::get( 'plugin:shell' ) );

if ( isset( $argv[1] ) && '' !== $argv[1] ) {
	$_GET['format'] = $argv[1];
}

// Output a theme or another plugin left in the buffer must not reach the file.
ob_start();
echo 'STRAY OUTPUT';

( new Export() )->handle();
