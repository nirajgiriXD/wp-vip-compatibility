<?php
/**
 * Tests for the overview screen.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Tests\Classes;

use SWITCHRAIL_AUDIT\Includes\Classes\Overview_Settings;
use SWITCHRAIL_AUDIT\Includes\Scanner\Report;
use SWITCHRAIL_AUDIT\Includes\Scanner\Results_Store;
use SWITCHRAIL_AUDIT\Includes\Scanner\Rules;
use SWITCHRAIL_AUDIT\Tests\TestCase;

/**
 * @covers \SWITCHRAIL_AUDIT\Includes\Classes\Overview_Settings
 */
class OverviewSettingsTest extends TestCase {

	/**
	 * Renders the screen.
	 *
	 * @return string The output.
	 */
	private function screen() {
		return $this->render(
			static function () {
				( new Overview_Settings() )->render_settings_page();
			}
		);
	}

	public function test_before_any_scan_it_explains_the_loop() {
		$output = $this->screen();

		$this->assertStringContainsString( 'Nothing has been scanned yet', $output );
		$this->assertStringContainsString( 'Run the first scan', $output );
		$this->assertStringContainsString( 'How this works', $output );
		$this->assertStringContainsString( 'aria-valuetext="Not scanned yet"', $output );
		$this->assertStringContainsString( 'Environment', $output );
		$this->assertStringNotContainsString( 'Where the work is', $output );
		$this->assertStringNotContainsString( 'switchrail-audit-verdict__tiers', $output );
	}

	public function test_a_blocked_item_means_not_ready() {
		$this->store_result( $this->add_plugin( 'ready' ), array() );
		$this->store_result( $this->add_plugin( 'blocked' ), array( $this->finding( 'filesystem.write-outside-writable-paths' ) ) );

		$output = $this->screen();

		$this->assertStringContainsString( 'switchrail-audit-verdict--blocking', $output );
		$this->assertStringContainsString( 'A few things need attention first', $output );
		$this->assertStringContainsString( '1 of 2 scanned items has issues likely to cause problems', $output );
		$this->assertStringContainsString( 'Start with the must-fix items', $output );
		$this->assertStringContainsString( 'page=switchrail-audit-findings&amp;tier=blocking', $output );
		$this->assertStringContainsString( '<span class="switchrail-audit-verdict__number">50</span>', $output );
	}

	public function test_review_only_means_good_shape_with_things_to_review() {
		$this->store_result( $this->add_plugin( 'a' ), array( $this->finding( 'security.extract' ) ) );
		$this->store_result( $this->add_plugin( 'b' ), array( $this->finding( 'security.extract' ) ) );

		$output = $this->screen();

		$this->assertStringContainsString( 'switchrail-audit-verdict--warning', $output );
		$this->assertStringContainsString( 'In good shape, with a few things to review', $output );
		$this->assertStringContainsString( '2 scanned items have findings worth a look', $output );
		$this->assertStringContainsString( 'Review the findings', $output );
	}

	public function test_everything_passing_is_ready() {
		$this->store_result( $this->add_plugin( 'a' ), array() );

		$output = $this->screen();

		$this->assertStringContainsString( 'Everything looks good', $output );
		$this->assertStringContainsString( 'Check the database', $output );
		$this->assertStringContainsString( '<span class="switchrail-audit-verdict__number">100</span>', $output );
	}

	public function test_the_environment_names_what_was_scanned() {
		$this->store_result( $this->add_plugin( 'a' ), array(), array( 'files_scanned' => 5 ) );
		$this->store_result( $this->add_plugin( 'b' ), array(), array( 'files_scanned' => 5 ) );
		$this->store_result( $this->add_theme( 't' ), array(), array( 'files_scanned' => 2 ) );
		$this->store_result( $this->add_mu_plugin( 'm.php' ), array(), array( 'files_scanned' => 1 ) );

		$output = $this->screen();

		$this->assertStringNotContainsString( 'switchrail-audit-verdict__meta', $output );
		$this->assertStringContainsString( '2 plugins, 1 theme, and 1 must-use plugin', $output );
		$this->assertStringContainsString( '13 PHP files read.', $output );
	}

	public function test_the_tier_counts_include_audit_work() {
		$this->store_result( $this->add_plugin( 'a' ), array( $this->finding( 'security.command-execution' ) ) );
		$this->wpdb->add_table( 'wp_old', 'MyISAM' );

		$output = $this->screen();

		// One critical finding and one table to convert are both "must fix".
		$this->assertMatchesRegularExpression( '#Must fix</span>\s*<span class="switchrail-audit-verdict__tiercount">2</span>#', $output );
		$this->assertMatchesRegularExpression( '#Worth checking</span>\s*<span class="switchrail-audit-verdict__tiercount">0</span>#', $output );
	}

	public function test_start_here_lists_the_first_jobs_worst_first() {
		$this->store_result(
			$this->add_plugin( 'a', array(), array( 'Name' => 'Alpha' ) ),
			array(
				$this->finding( 'security.command-execution' ),
				$this->finding( 'caching.cache-flush', array( 'line' => 2 ) ),
				$this->finding( 'environment.debug-output', array( 'line' => 3 ) ),
			)
		);
		$this->wpdb->add_table( 'wp_old', 'MyISAM' );

		$output = $this->screen();

		$convert = strpos( $output, 'Convert 1 table to InnoDB' );
		$exec    = strpos( $output, Rules::get( 'security.command-execution' )['title'] );

		$this->assertNotFalse( $convert );
		$this->assertNotFalse( $exec );
		$this->assertLessThan( $exec, $convert, 'audit work leads its tier' );
		$this->assertStringNotContainsString( Rules::get( 'environment.debug-output' )['title'], $output, 'FYI work is not a first step' );
	}

	public function test_where_the_work_is_has_one_row_per_area() {
		$this->store_result( $this->add_plugin( 'a' ), array() );

		$output = $this->screen();

		foreach ( Report::areas() as $area ) {
			$this->assertStringContainsString( '>' . $area['label'] . '</a>', $output );
		}
	}

	public function test_history_appears_after_two_scans() {
		$this->store_result( $this->add_plugin( 'a' ), array( $this->finding( 'security.extract' ) ) );
		Report::record_snapshot();

		$this->store_result( $this->add_plugin( 'b' ), array( $this->finding( 'security.extract' ) ) );
		Report::record_snapshot( 'theme' );

		$this->assertCount( 2, Results_Store::get_history() );

		$output = $this->screen();

		$this->assertStringContainsString( 'switchrail-audit-spark', $output );
		$this->assertStringContainsString( 'data-label="Scanned">Themes</td>', $output );
		$this->assertStringContainsString( 'data-label="Scanned">Everything</td>', $output );
	}

	public function test_history_saved_before_scopes_shows_a_dash() {
		update_option(
			Results_Store::HISTORY_OPTION,
			array(
				array( 'total' => 2, 'score' => 50, 'recorded_at' => time() - 60 ),
				array( 'total' => 1, 'score' => 75, 'recorded_at' => time() ),
			)
		);
		$this->store_result( $this->add_plugin( 'a' ), array() );

		$this->assertStringContainsString( 'data-label="Scanned"><span class="switchrail-audit-dash" aria-hidden="true">—</span></td>', $this->screen() );
	}

	public function test_names_from_scanned_code_are_escaped() {
		$this->store_result(
			$this->add_plugin( 'evil', array(), array( 'Name' => '<script>alert(1)</script>' ) ),
			array( $this->finding( 'security.command-execution' ) )
		);

		$this->assertStringNotContainsString( '<script>alert', $this->screen() );
	}

	public function test_guidance_mentions_the_rule_set() {
		$notes = ( new Overview_Settings() )->get_page_guidance();

		$this->assertCount( 5, $notes );
		$this->assertStringContainsString( 'Rule set ' . Rules::VERSION . ' has ' . count( Rules::all() ) . ' rules', $notes[4] );
	}
}
