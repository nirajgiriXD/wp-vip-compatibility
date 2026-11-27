<?php
/**
 * PHPUnit bootstrap.
 *
 * The suite runs without WordPress or a database. WordPress is replaced by the
 * fakes in wp-functions.php, and the filesystem the scanner reads is a sandbox
 * under the system temp directory that each test fills in as it needs.
 *
 * @package switchrail-audit
 */

// phpcs:disable -- Test bootstrap.

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Doubles.php holds several small classes, so PSR-4 cannot find them.
require __DIR__ . '/Support/Doubles.php';

// Loaded after Composer so Patchwork can redefine these functions per test.
require __DIR__ . '/wp-functions.php';

$switchrail_audit_sandbox = str_replace( '\\', '/', sys_get_temp_dir() ) . '/switchrail-audit-tests-' . getmypid();

define( 'SWITCHRAIL_AUDIT_TESTS_SANDBOX', $switchrail_audit_sandbox );
define( 'ABSPATH', $switchrail_audit_sandbox . '/' );
define( 'WP_CONTENT_DIR', $switchrail_audit_sandbox . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

define( 'SWITCHRAIL_AUDIT_FILE', dirname( __DIR__, 2 ) . '/switchrail-audit.php' );
define( 'SWITCHRAIL_AUDIT_DIR', dirname( __DIR__, 2 ) );
define( 'SWITCHRAIL_AUDIT_URL', 'https://example.test/wp-content/plugins/switchrail-audit/' );
define( 'SWITCHRAIL_AUDIT_VERSION', '1.0.0' );

require_once SWITCHRAIL_AUDIT_DIR . '/includes/helpers/class-autoloader.php';
require_once SWITCHRAIL_AUDIT_DIR . '/functions.php';

register_shutdown_function(
	static function () use ( $switchrail_audit_sandbox ) {
		SWITCHRAIL_AUDIT\Tests\Support\Sandbox::remove( $switchrail_audit_sandbox );
	}
);
