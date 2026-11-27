<?php
/**
 * Tests for scan orchestration.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Scanner;

use Brain\Monkey\Filters;
use PREFLIGHT_AUDIT\Includes\Scanner\Results_Store;
use PREFLIGHT_AUDIT\Includes\Scanner\Rules;
use PREFLIGHT_AUDIT\Includes\Scanner\Scanner;
use PREFLIGHT_AUDIT\Includes\Scanner\Taxonomy;
use PREFLIGHT_AUDIT\Tests\Support\Sandbox;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Scanner\Scanner
 */
class ScannerTest extends TestCase {

	const GUARD = "<?php\ndefined( 'ABSPATH' ) || exit;\n";

	public function test_a_clean_plugin_passes() {
		$target = $this->add_plugin( 'clean', array( 'clean.php' => self::GUARD . "echo 'hello';\n" ) );

		$result = ( new Scanner() )->scan( $target );

		$this->assertSame( Scanner::STATUS_PASS, $result['status'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( 0, $result['summary']['total'] );
		$this->assertSame( 1, $result['files_scanned'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( Rules::VERSION, $result['rules_version'] );
		$this->assertSame( 'plugin:clean', $result['key'] );
		$this->assertSame( 'Clean', $result['label'] );
	}

	public function test_a_blocking_high_severity_finding_blocks_the_target() {
		// A write into the plugin directory: incompatible, high.
		$target = $this->add_plugin( 'writer', array( 'writer.php' => self::GUARD . "file_put_contents( __DIR__ . '/log.txt', 'x' );\n" ) );

		$result = ( new Scanner() )->scan( $target );

		$this->assertSame( Scanner::STATUS_BLOCKED, $result['status'] );
		$this->assertSame( 1, $result['summary']['blocking'] );
		$this->assertSame( 1, $result['summary']['by_type'][ Taxonomy::TYPE_INCOMPATIBLE ] );
		$this->assertSame( 1, $result['summary']['by_category']['filesystem'] );
	}

	public function test_a_critical_non_blocking_finding_only_needs_review() {
		// Command execution is a security finding: critical, but not a migration blocker.
		$target = $this->add_plugin( 'shell', array( 'shell.php' => self::GUARD . "exec( 'ls' );\n" ) );

		$result = ( new Scanner() )->scan( $target );

		$this->assertSame( Scanner::STATUS_REVIEW, $result['status'] );
		$this->assertSame( 0, $result['summary']['blocking'] );
		$this->assertSame( 1, $result['summary']['by_severity'][ Taxonomy::SEVERITY_CRITICAL ] );
	}

	public function test_a_blocking_type_at_low_severity_only_needs_review() {
		// An unresolved directory listing is potentially incompatible, but the guard lowers it to low.
		$target = $this->add_plugin( 'lister', array( 'lister.php' => self::GUARD . "glob( \$pattern );\n" ) );

		$result = ( new Scanner() )->scan( $target );

		$this->assertSame( Scanner::STATUS_REVIEW, $result['status'] );
		$this->assertSame( 1, $result['summary']['blocking'] );
	}

	public function test_findings_are_sorted_by_severity_then_confidence_then_location() {
		$target = $this->add_plugin(
			'mixed',
			array(
				'b.php' => self::GUARD . "var_dump( \$x );\nexec( 'ls' );\n",
				'a.php' => self::GUARD . "exec( 'ls' );\nflush_rewrite_rules();\n",
			)
		);

		$findings = ( new Scanner() )->scan( $target )['findings'];
		$order    = array_map(
			static function ( $finding ) {
				return $finding['severity'] . ' ' . $finding['file'] . ':' . $finding['line'];
			},
			$findings
		);

		$this->assertSame( array( 'critical a.php:3', 'critical b.php:4', 'high a.php:4', 'low b.php:3' ), $order );
	}

	public function test_only_php_files_are_scanned_and_relative_paths_are_reported() {
		$target = $this->add_plugin(
			'paths',
			array(
				'paths.php'       => self::GUARD,
				'inc/deep/a.php'  => self::GUARD . "exec( 'ls' );\n",
				'inc/UPPER.PHP'   => self::GUARD,
				'readme.txt'      => "exec( 'ls' );",
				'assets/app.js'   => "exec( 'ls' );",
			)
		);

		$scanner = new Scanner();
		$files   = array_values( $scanner->php_files( $target['path'] ) );
		sort( $files );

		$this->assertSame( array( 'inc/UPPER.PHP', 'inc/deep/a.php', 'paths.php' ), $files );
		$this->assertSame( 'inc/deep/a.php', $scanner->scan( $target )['findings'][0]['file'] );
	}

	public function test_third_party_directories_are_skipped() {
		$files = array( 'main.php' => self::GUARD );

		foreach ( array( 'vendor', 'node_modules', 'Vendor_Prefixed', 'bower_components', 'dist', 'build', '.git', '.svn' ) as $directory ) {
			$files[ $directory . '/lib.php' ] = self::GUARD . "exec( 'ls' );\n";
		}

		$target = $this->add_plugin( 'deps', $files );

		$this->assertSame( array( 'main.php' ), array_values( ( new Scanner() )->php_files( $target['path'] ) ) );
	}

	public function test_the_excluded_directories_can_be_filtered() {
		$target = $this->add_plugin(
			'filtered',
			array(
				'main.php'       => self::GUARD,
				'legacy/old.php' => self::GUARD,
				'vendor/lib.php' => self::GUARD,
			)
		);

		Filters\expectApplied( 'preflight_audit_scanner_excluded_directories' )->andReturn( array( 'legacy' ) );

		$files = array_values( ( new Scanner() )->php_files( $target['path'] ) );
		sort( $files );

		$this->assertSame( array( 'main.php', 'vendor/lib.php' ), $files );
	}

	public function test_a_single_file_target_scans_that_file() {
		$target = $this->add_single_file_plugin( 'hello.php', self::GUARD . "exec( 'ls' );\n" );

		$result = ( new Scanner() )->scan( $target );

		$this->assertSame( 1, $result['files_scanned'] );
		$this->assertSame( 'hello.php', $result['findings'][0]['file'] );
	}

	public function test_a_missing_path_scans_nothing() {
		$scanner = new Scanner();

		$this->assertSame( array(), $scanner->php_files( WP_CONTENT_DIR . '/does-not-exist' ) );

		$result = $scanner->scan(
			array(
				'key'   => 'plugin:gone',
				'type'  => 'plugin',
				'slug'  => 'gone',
				'label' => 'Gone',
				'path'  => WP_CONTENT_DIR . '/does-not-exist',
			)
		);

		$this->assertSame( 0, $result['files_scanned'] );
		$this->assertSame( Scanner::STATUS_PASS, $result['status'] );
	}

	public function test_symbolic_links_inside_a_target_are_not_followed() {
		$outside = Sandbox::write( 'outside/secret.php', self::GUARD . "exec( 'ls' );\n" );
		$target  = $this->add_plugin( 'linked', array( 'main.php' => self::GUARD ) );

		$link = $target['path'] . '/linked-dir';

		if ( ! @symlink( dirname( $outside ), $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->markTestSkipped( 'This system does not allow creating symbolic links.' );
		}

		$this->assertSame( array( 'main.php' ), array_values( ( new Scanner() )->php_files( $target['path'] ) ) );
	}

	public function test_findings_are_capped_and_the_result_marked_truncated() {
		$lines = str_repeat( "exec( 'ls' );\n", 150 );
		$files = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$files[ "f{$i}.php" ] = self::GUARD . $lines;
		}

		$target = $this->add_plugin( 'noisy', $files );
		$result = ( new Scanner() )->scan( $target );

		$this->assertCount( Scanner::MAX_FINDINGS, $result['findings'] );
		$this->assertTrue( $result['truncated'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Caching
	 * ------------------------------------------------------------------
	 */

	public function test_get_result_scans_once_then_serves_the_stored_result() {
		$target  = $this->add_plugin( 'cached', array( 'cached.php' => self::GUARD . "exec( 'ls' );\n" ) );
		$scanner = new Scanner();

		$first = $scanner->get_result( $target );

		$this->assertSame( $first, Results_Store::get( 'plugin:cached' ) );

		// Change the stored copy: if the second call re-scanned, the marker would be gone.
		Results_Store::save( 'plugin:cached', array_merge( $first, array( 'marker' => true ) ) );

		$this->assertTrue( $scanner->get_result( $target )['marker'] );
	}

	public function test_get_result_rescans_when_the_code_changes() {
		$target  = $this->add_plugin( 'changing', array( 'changing.php' => self::GUARD ) );
		$scanner = new Scanner();

		$this->assertSame( 0, $scanner->get_result( $target )['summary']['total'] );

		Sandbox::write( 'plugins/changing/new.php', self::GUARD . "exec( 'ls' );\n" );

		$this->assertSame( 1, $scanner->get_result( $target )['summary']['total'] );
	}

	public function test_get_result_rescans_when_forced() {
		$target  = $this->add_plugin( 'forced', array( 'forced.php' => self::GUARD ) );
		$scanner = new Scanner();
		$first   = $scanner->get_result( $target );

		Results_Store::save( 'plugin:forced', array_merge( $first, array( 'marker' => true ) ) );

		$this->assertArrayNotHasKey( 'marker', $scanner->get_result( $target, true ) );
	}

	public function test_the_fingerprint_follows_file_count_size_and_rule_version() {
		$target  = $this->add_plugin( 'prints', array( 'a.php' => self::GUARD ) );
		$scanner = new Scanner();
		$before  = $scanner->fingerprint( $target['path'] );

		$this->assertSame( $before, $scanner->fingerprint( $target['path'] ) );

		Sandbox::write( 'plugins/prints/a.php', self::GUARD . "// longer\n" );
		$this->assertNotSame( $before, $scanner->fingerprint( $target['path'] ) );

		$file = Sandbox::write( 'plugins/single.php', self::GUARD );
		$this->assertSame( 32, strlen( $scanner->fingerprint( $file ) ) );
	}

	/**
	 * @dataProvider status_labels
	 */
	public function test_status_labels( $status, $label ) {
		$this->assertSame( $label, Scanner::status_label( $status ) );
	}

	public function status_labels() {
		return array(
			array( Scanner::STATUS_BLOCKED, 'Needs work' ),
			array( Scanner::STATUS_REVIEW, 'Needs review' ),
			array( Scanner::STATUS_PASS, 'Passing' ),
			array( 'unknown', 'Passing' ),
		);
	}

	public function test_findings_from_a_rule_removed_since_are_ignored_in_the_summary() {
		$summary = $this->call_private( new Scanner(), 'summarise', array( array( $this->finding( 'security.extract' ), array( 'rule' => 'gone.rule', 'severity' => 'high' ) ) ) );

		$this->assertSame( 2, $summary['total'] );
		$this->assertSame( 1, array_sum( $summary['by_severity'] ) );
	}
}
