<?php
/**
 * Tests for the database and wp-content screens.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Classes;

use PREFLIGHT_AUDIT\Includes\Classes\Database_Settings;
use PREFLIGHT_AUDIT\Includes\Classes\Directories_Settings;
use PREFLIGHT_AUDIT\Tests\Support\Sandbox;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Database_Settings
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Directories_Settings
 */
class SiteScreensTest extends TestCase {

	/**
	 * Renders a screen.
	 *
	 * @param string $class The screen class.
	 * @return string The output.
	 */
	private function screen( $class ) {
		$screen = $this->instance( $class );

		return $this->render(
			static function () use ( $screen ) {
				$screen->render_settings_page();
			}
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------
	 */

	public function test_an_empty_schema_says_so() {
		$this->assertStringContainsString( 'No tables found in the database', $this->screen( Database_Settings::class ) );
	}

	public function test_tables_that_need_changes_come_first() {
		$this->wpdb
			->add_table( 'wp_a_ok' )
			->add_table( 'wp_b_bad', 'MyISAM' )
			->add_table( 'wp_c_ok' );

		$output = $this->screen( Database_Settings::class );

		$this->assertLessThan( strpos( $output, 'wp_a_ok' ), strpos( $output, 'wp_b_bad' ) );
		$this->assertLessThan( strpos( $output, 'wp_c_ok' ), strpos( $output, 'wp_a_ok' ), 'ties keep the audit order' );
		$this->assertStringContainsString( 'data-status="not-compatible"', $output );
		$this->assertStringContainsString( 'Needs changes', $output );
	}

	public function test_copy_all_orders_the_sql_engine_then_collation_then_prefix() {
		$this->wpdb
			->add_table( 'custom_prefix' )
			->add_table( 'wp_collation', 'InnoDB', 'utf8_general_ci' )
			->add_table( 'wp_engine', 'MyISAM' );

		$output = $this->screen( Database_Settings::class );

		preg_match( '#data-role="copy" data-clipboard="([^"]*)" title=#', $output, $match );

		$this->assertSame(
			array(
				'ALTER TABLE `wp_engine` ENGINE = InnoDB;',
				'ALTER TABLE `wp_collation` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;',
				'ALTER TABLE `custom_prefix` RENAME TO `wp_custom_prefix`;',
			),
			explode( "\n", html_entity_decode( $match[1], ENT_QUOTES ) )
		);
		$this->assertStringContainsString( 'Copy all 3 SQL statements', $output );
	}

	public function test_no_copy_button_when_nothing_needs_changing() {
		$this->wpdb->add_table( 'wp_posts', 'InnoDB', 'utf8mb4_unicode_ci', 1234, 2048 );

		$output = $this->screen( Database_Settings::class );

		$this->assertStringNotContainsString( 'SQL statement', $output );
		$this->assertStringContainsString( '1,234', $output );
		$this->assertStringContainsString( '2.0 KB', $output );
		$this->assertStringContainsString( 'WordPress core', $output );
	}

	public function test_the_database_verdict_filter_reads_the_query_string() {
		$this->wpdb->add_table( 'wp_posts' );

		$_GET['status'] = 'compatible';
		$this->assertMatchesRegularExpression( '#class="active"\s+data-filter-value="compatible"#', $this->screen( Database_Settings::class ) );

		$_GET['status'] = 'review';
		$this->assertMatchesRegularExpression( '#class="active"\s+data-filter-value="all"#', $this->screen( Database_Settings::class ), 'tables are never "review"' );
	}

	public function test_table_names_are_escaped() {
		$this->wpdb->add_table( 'wp_<script>x</script>', 'MyISAM' );

		$this->assertStringNotContainsString( '<script>x', $this->screen( Database_Settings::class ) );
	}

	public function test_database_guidance() {
		$this->assertCount( 2, $this->instance( Database_Settings::class )->get_page_guidance() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * wp-content
	 * ------------------------------------------------------------------
	 */

	public function test_an_empty_wp_content_says_so() {
		Sandbox::remove( WP_CONTENT_DIR );

		$this->assertStringContainsString( 'No files or directories found in wp-content', $this->screen( Directories_Settings::class ) );
	}

	public function test_entries_that_need_work_come_first() {
		Sandbox::mkdir( 'themes' );
		Sandbox::mkdir( 'odd' );
		Sandbox::mkdir( 'uploads' );

		$output = $this->screen( Directories_Settings::class );

		$mu      = strpos( $output, 'mu-plugins' );
		$odd     = strpos( $output, '>odd' );
		$themes  = strpos( $output, '>themes' );
		$uploads = strpos( $output, '>uploads' );

		$this->assertLessThan( $odd, $mu, 'remove or relocate first' );
		$this->assertLessThan( $themes, $odd, 'then needs review' );
		$this->assertNotFalse( $uploads );

		$this->assertStringContainsString( 'Remove or relocate', $output );
		$this->assertStringContainsString( 'Not deployed', $output );
		$this->assertStringContainsString( 'data-status="review"', $output );
	}

	public function test_entries_link_to_their_reference() {
		Sandbox::mkdir( 'themes' );

		$this->assertStringContainsString( 'href="https://docs.wpvip.com/', $this->screen( Directories_Settings::class ) );
	}

	public function test_the_wp_content_filter_reads_the_query_string() {
		$_GET['status'] = 'review';
		$this->assertMatchesRegularExpression( '#class="active"\s+data-filter-value="review"#', $this->screen( Directories_Settings::class ) );

		$_GET['status'] = 'pending';
		$this->assertMatchesRegularExpression( '#class="active"\s+data-filter-value="all"#', $this->screen( Directories_Settings::class ) );
	}

	public function test_entry_names_are_escaped() {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Windows does not allow < and > in file names.' );
		}

		Sandbox::mkdir( '<b>x</b>' );

		$this->assertStringNotContainsString( '<b>x</b>', $this->screen( Directories_Settings::class ) );
	}

	public function test_wp_content_guidance() {
		$this->assertCount( 3, $this->instance( Directories_Settings::class )->get_page_guidance() );
	}
}
