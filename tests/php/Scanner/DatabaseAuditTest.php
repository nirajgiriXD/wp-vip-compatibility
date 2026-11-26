<?php
/**
 * Tests for the database schema audit.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Scanner;

use PREFLIGHT_AUDIT\Includes\Scanner\Database_Audit;
use PREFLIGHT_AUDIT\Includes\Scanner\Taxonomy;
use PREFLIGHT_AUDIT\Tests\Support\WP;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Scanner\Database_Audit
 */
class DatabaseAuditTest extends TestCase {

	/**
	 * Returns the audited row for a table.
	 *
	 * @param array<string, mixed> $audit The audit.
	 * @param string               $name  Table name.
	 * @return array<string, mixed> The row.
	 */
	private function row( array $audit, $name ) {
		foreach ( $audit['tables'] as $row ) {
			if ( $row['name'] === $name ) {
				return $row;
			}
		}

		$this->fail( "No row for {$name}" );
	}

	public function test_a_compliant_table_has_no_findings() {
		$this->wpdb->add_table( 'wp_posts', 'InnoDB', 'utf8mb4_unicode_520_ci', 12, 1000, 24 );

		$audit = Database_Audit::run( true );
		$row   = $this->row( $audit, 'wp_posts' );

		$this->assertSame( array(), $row['findings'] );
		$this->assertSame( 1024, $row['bytes'] );
		$this->assertSame( 12, $row['rows'] );
		$this->assertSame( 'WordPress core', $row['source'] );
		$this->assertSame( 1, $audit['summary']['compatible'] );
		$this->assertSame( 0, $audit['summary']['incompatible'] );
		$this->assertSame( 1024, $audit['summary']['bytes'] );
	}

	public function test_the_query_reads_the_current_schema_through_database() {
		Database_Audit::run( true );

		$this->assertStringContainsString( 'TABLE_SCHEMA = DATABASE()', $this->wpdb->queries[0] );
		$this->assertStringContainsString( "TABLE_TYPE = 'BASE TABLE'", $this->wpdb->queries[0] );
	}

	public function test_a_myisam_table_needs_converting() {
		$this->wpdb->add_table( 'wp_options', 'MyISAM' );

		$finding = $this->row( Database_Audit::run( true ), 'wp_options' )['findings'][0];

		$this->assertSame( 'engine', $finding['kind'] );
		$this->assertSame( Taxonomy::SEVERITY_HIGH, $finding['severity'] );
		$this->assertSame( 'ALTER TABLE `wp_options` ENGINE = InnoDB;', $finding['sql'] );
		$this->assertStringContainsString( 'MyISAM', $finding['detail'] );
	}

	public function test_innodb_is_matched_case_insensitively_and_a_view_has_no_engine() {
		$this->wpdb->add_table( 'wp_a', 'innodb' )->add_table( 'wp_b', '' );

		$audit = Database_Audit::run( true );

		$this->assertSame( array(), $this->row( $audit, 'wp_a' )['findings'] );
		$this->assertSame( array(), $this->row( $audit, 'wp_b' )['findings'] );
	}

	/**
	 * @dataProvider collation_cases
	 */
	public function test_unsupported_collations_get_a_suggestion( $collation, $sql ) {
		$this->wpdb->add_table( 'wp_t', 'InnoDB', $collation );

		$finding = $this->row( Database_Audit::run( true ), 'wp_t' )['findings'][0];

		$this->assertSame( 'collation', $finding['kind'] );
		$this->assertSame( $sql, $finding['sql'] );
	}

	public function collation_cases() {
		return array(
			'direct utf8mb4 equivalent' => array( 'utf8_unicode_ci', 'ALTER TABLE `wp_t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' ),
			'swedish has its own'       => array( 'latin1_swedish_ci', 'ALTER TABLE `wp_t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_swedish_ci;' ),
			'no equivalent falls back'  => array( 'latin1_german1_ci', 'ALTER TABLE `wp_t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' ),
			'no underscore at all'      => array( 'binary', '' ),
		);
	}

	public function test_a_table_without_the_wp_prefix_is_flagged_for_review() {
		$this->wpdb->add_table( 'custom_log' );

		$row = $this->row( Database_Audit::run( true ), 'custom_log' );

		$this->assertSame( 'prefix', $row['findings'][0]['kind'] );
		$this->assertSame( Taxonomy::SEVERITY_MEDIUM, $row['findings'][0]['severity'] );
		$this->assertSame( 'ALTER TABLE `custom_log` RENAME TO `wp_custom_log`;', $row['findings'][0]['sql'] );
		$this->assertSame( 'Unknown', $row['source'] );
	}

	public function test_the_summary_counts_tables_and_issues() {
		$this->wpdb
			->add_table( 'wp_posts' )
			->add_table( 'wp_a', 'MyISAM', 'latin1_swedish_ci' )
			->add_table( 'b_table', 'MyISAM' );

		$summary = Database_Audit::run( true )['summary'];

		$this->assertSame( 3, $summary['total'] );
		$this->assertSame( 1, $summary['compatible'] );
		$this->assertSame( 2, $summary['incompatible'] );
		$this->assertSame(
			array(
				'engine'    => 2,
				'collation' => 1,
				'prefix'    => 1,
			),
			$summary['issues']
		);
	}

	/**
	 * @dataProvider source_cases
	 */
	public function test_identifies_who_owns_a_table( $name, $source ) {
		$this->wpdb->add_table( $name );

		$this->assertSame( $source, $this->row( Database_Audit::run( true ), $name )['source'] );
	}

	public function source_cases() {
		return array(
			'core table'                   => array( 'wp_options', 'WordPress core' ),
			'core table on a subsite'      => array( 'wp_3_options', 'WordPress core' ),
			'one owner'                    => array( 'wp_wc_product_meta_lookup', 'woocommerce' ),
			'two owners'                   => array( 'wp_woocommerce_api_keys', 'woocommerce, lazyeater' ),
			'many owners are summarised'   => array( 'wp_actionscheduler_groups', 'woocommerce (and 70 others)' ),
			'unknown prefixed table'       => array( 'wp_my_table', 'Custom' ),
		);
	}

	public function test_a_custom_site_prefix_is_stripped_before_lookup() {
		$this->wpdb->prefix      = 'site_';
		$this->wpdb->base_prefix = 'site_';
		$this->wpdb->add_table( 'site_options' );

		$this->assertSame( 'WordPress core', $this->row( Database_Audit::run( true ), 'site_options' )['source'] );
	}

	public function test_the_result_is_cached_for_five_minutes() {
		$this->wpdb->add_table( 'wp_posts' );

		$first = Database_Audit::run();

		$this->assertSame( 300, WP::$transients[1][ Database_Audit::CACHE_KEY ]['ttl'] );

		$this->wpdb->add_table( 'wp_new' );

		$this->assertSame( $first, Database_Audit::run() );
		$this->assertCount( 1, $this->wpdb->queries );
		$this->assertSame( 2, Database_Audit::run( true )['summary']['total'] );
	}

	public function test_flush_drops_the_cached_result() {
		Database_Audit::run();
		Database_Audit::flush();

		$this->assertArrayNotHasKey( Database_Audit::CACHE_KEY, WP::$transients[1] );
	}

	public function test_an_empty_schema() {
		$summary = Database_Audit::run( true )['summary'];

		$this->assertSame( 0, $summary['total'] );
		$this->assertSame( 0, $summary['bytes'] );
	}
}
