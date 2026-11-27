<?php
/**
 * Tests for aggregation, verdicts and exports.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Tests\Scanner;

use CUTOVER_AUDIT\Includes\Scanner\Database_Audit;
use CUTOVER_AUDIT\Includes\Scanner\Report;
use CUTOVER_AUDIT\Includes\Scanner\Results_Store;
use CUTOVER_AUDIT\Includes\Scanner\Rules;
use CUTOVER_AUDIT\Includes\Scanner\Scanner;
use CUTOVER_AUDIT\Includes\Scanner\Targets;
use CUTOVER_AUDIT\Includes\Scanner\Taxonomy;
use CUTOVER_AUDIT\Tests\Support\Sandbox;
use CUTOVER_AUDIT\Tests\Support\WP;
use CUTOVER_AUDIT\Tests\TestCase;

/**
 * @covers \CUTOVER_AUDIT\Includes\Scanner\Report
 */
class ReportTest extends TestCase {

	const GUARD = "<?php\ndefined( 'ABSPATH' ) || exit;\n";

	/*
	 * ---------------------------------------------------------------------
	 * Scopes
	 * ------------------------------------------------------------------
	 */

	public function test_scopes_cover_everything_and_the_five_areas() {
		$this->assertSame(
			array( 'all', 'plugin', 'theme', 'mu-plugin', 'database', 'directories' ),
			array_keys( Report::get_scopes() )
		);

		foreach ( Report::get_scopes() as $scope => $definition ) {
			$this->assertSame( '' !== $definition['type'], $definition['async'], "{$scope}: only target areas scan in the browser" );
		}
	}

	public function test_an_unknown_scope_resolves_to_everything() {
		$this->assertSame( 'theme', Report::resolve_scope( 'theme' ) );
		$this->assertSame( Report::SCOPE_ALL, Report::resolve_scope( 'nonsense' ) );
		$this->assertSame( Report::SCOPE_ALL, Report::resolve_scope( '' ) );
		$this->assertSame( 'Everything', Report::get_scope( 'nonsense' )['label'] );
	}

	public function test_clear_scope_forgets_results_of_that_type_only() {
		$plugin = $this->add_plugin( 'p' );
		$theme  = $this->add_theme( 't' );
		$this->store_result( $plugin, array() );
		$this->store_result( $theme, array() );

		$this->assertSame( 1, Report::clear_scope( 'plugin' ) );
		$this->assertNull( Results_Store::get( 'plugin:p' ) );
		$this->assertNotNull( Results_Store::get( 'theme:t' ) );
	}

	public function test_an_updated_plugin_or_theme_is_scanned_again() {
		$this->store_result( $this->add_plugin( 'p' ), array() );
		$this->store_result( $this->add_plugin( 'q' ), array() );
		$this->store_result( $this->add_theme( 't' ), array() );

		$this->assertSame( 1, Report::forget_upgraded( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'p/p.php' ) ) ) );
		$this->assertSame( 1, Report::forget_upgraded( null, array( 'type' => 'theme', 'action' => 'update', 'theme' => 't' ) ) );

		$this->assertNull( Results_Store::get( 'plugin:p' ) );
		$this->assertNull( Results_Store::get( 'theme:t' ) );
		$this->assertNotNull( Results_Store::get( 'plugin:q' ) );
	}

	public function test_a_single_file_plugin_update_uses_the_file_as_its_slug() {
		$this->store_result( array( 'key' => 'plugin:hello.php', 'type' => 'plugin', 'slug' => 'hello.php', 'label' => 'Hello', 'path' => __FILE__ ), array() );

		$this->assertSame( 1, Report::forget_upgraded( null, array( 'type' => 'plugin', 'plugin' => 'hello.php' ) ) );
	}

	public function test_a_translation_update_forgets_nothing() {
		$this->store_result( $this->add_plugin( 'p' ), array() );

		$this->assertSame( 0, Report::forget_upgraded( null, array( 'type' => 'translation', 'translations' => array( array( 'slug' => 'p' ) ) ) ) );
		$this->assertNotNull( Results_Store::get( 'plugin:p' ) );
	}

	public function test_clear_scope_does_nothing_for_an_audit_area() {
		$this->store_result( $this->add_plugin( 'p' ), array() );

		$this->assertSame( 0, Report::clear_scope( 'database' ) );
		$this->assertSame( 0, Report::clear_scope( 'all' ) );
		$this->assertNotNull( Results_Store::get( 'plugin:p' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Full scans
	 * ------------------------------------------------------------------
	 */

	public function test_a_full_scan_scans_every_target_and_records_history() {
		$this->add_plugin( 'shell', array( 'shell.php' => self::GUARD . "exec( 'ls' );\n" ) );
		$this->add_theme( 'clean', array( 'functions.php' => self::GUARD ) );
		$this->add_mu_plugin( 'loader.php', self::GUARD );

		$aggregate = Report::run_full_scan();

		$this->assertSame( 3, $aggregate['targets'] );
		$this->assertSame( 1, $aggregate['totals']['findings'] );
		$this->assertCount( 1, Results_Store::get_history() );
		$this->assertSame( 1, Results_Store::get_history()[0]['total'] );
	}

	public function test_a_scoped_scan_only_scans_that_type_but_prunes_against_everything() {
		$this->add_plugin( 'p', array( 'p.php' => self::GUARD ) );
		$this->add_theme( 't', array( 'functions.php' => self::GUARD ) );
		$this->store_result( $this->add_plugin( 'uninstalled' ), array() );

		// The plugin is deleted after its last scan.
		unset( WP::$plugins['uninstalled/uninstalled.php'] );
		Targets::reset();

		Report::run_full_scan( false, 'theme' );

		$index = Results_Store::get_index();

		$this->assertArrayHasKey( 'theme:t', $index );
		$this->assertArrayNotHasKey( 'plugin:p', $index, 'plugins were not in scope' );
		$this->assertArrayNotHasKey( 'plugin:uninstalled', $index, 'pruned although out of scope' );

		$history = Results_Store::get_history();
		$this->assertSame( 'theme', end( $history )['scope'], 'the history remembers what was rescanned' );
	}

	public function test_a_database_scope_refreshes_the_database_audit() {
		$this->wpdb->add_table( 'wp_posts' );
		Database_Audit::run();
		$this->wpdb->add_table( 'wp_new' );

		Report::run_full_scan( false, 'database' );

		$this->assertSame( 2, Database_Audit::run()['summary']['total'] );
	}

	public function test_a_plugin_scope_leaves_the_database_audit_cached() {
		$this->wpdb->add_table( 'wp_posts' );
		Database_Audit::run();
		$this->wpdb->add_table( 'wp_new' );

		Report::run_full_scan( false, 'plugin' );

		$this->assertSame( 1, Database_Audit::run()['summary']['total'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scope outcome sentences
	 * ------------------------------------------------------------------
	 */

	public function test_scope_outcome_for_targets() {
		$this->store_result( $this->add_plugin( 'a' ), array(), array( 'files_scanned' => 4 ) );
		$this->store_result( $this->add_plugin( 'b' ), array(), array( 'files_scanned' => 6 ) );
		$this->store_result( $this->add_theme( 't' ), array(), array( 'files_scanned' => 1 ) );

		$this->assertSame( 'Scanned 2 plugins across 10 PHP files.', Report::scope_outcome( 'plugin' ) );
		$this->assertSame( 'Scanned 1 theme across 1 PHP file.', Report::scope_outcome( 'theme' ) );
		$this->assertSame( 'Scanned 0 must-use plugins across 0 PHP files.', Report::scope_outcome( 'mu-plugin' ) );
		$this->assertSame( 'Scanned 3 items across 11 PHP files.', Report::scope_outcome( 'all' ) );
	}

	public function test_scope_outcome_for_the_audits() {
		$this->wpdb->add_table( 'wp_posts' );
		Sandbox::mkdir( 'themes' );

		$this->assertSame( 'Measured the schema again. 1 table checked.', Report::scope_outcome( 'database' ) );
		$this->assertSame( 'Looked at wp-content again. 3 entries checked.', Report::scope_outcome( 'directories' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Aggregate and score
	 * ------------------------------------------------------------------
	 */

	public function test_an_empty_site_scores_100() {
		$aggregate = Report::aggregate();

		$this->assertSame( 0, $aggregate['targets'] );
		$this->assertSame( 100, $aggregate['score'] );
	}

	public function test_the_score_counts_ready_in_full_review_at_60_percent_and_blocked_as_nothing() {
		$this->store_result( $this->add_plugin( 'ready' ), array() );
		$this->store_result( $this->add_plugin( 'review' ), array( $this->finding( 'security.extract' ) ) );
		$this->store_result(
			$this->add_plugin( 'blocked' ),
			array( $this->finding( 'filesystem.write-outside-writable-paths' ) )
		);

		$aggregate = Report::aggregate();

		$this->assertSame(
			array(
				Scanner::STATUS_PASS    => 1,
				Scanner::STATUS_REVIEW  => 1,
				Scanner::STATUS_BLOCKED => 1,
			),
			$aggregate['statuses']
		);
		$this->assertSame( 53, $aggregate['score'] ); // ( 1 + 0.6 ) / 3.
		$this->assertSame( 2, $aggregate['totals']['findings'] );
		$this->assertSame( 1, $aggregate['totals']['blocking'] );
		$this->assertSame( 9, $aggregate['totals']['files'] );
		$this->assertSame( 1, $aggregate['by_type'][ Taxonomy::TYPE_SECURITY ] );
		$this->assertSame( 1, $aggregate['by_category']['filesystem'] );
		$this->assertSame( 1, $aggregate['by_kind']['plugin'][ Scanner::STATUS_BLOCKED ] );
	}

	public function test_the_aggregate_is_memoised_until_the_store_changes() {
		$this->store_result( $this->add_plugin( 'a' ), array() );
		$this->assertSame( 1, Report::aggregate()['targets'] );

		$this->store_result( $this->add_plugin( 'b' ), array() );
		$this->assertSame( 2, Report::aggregate()['targets'] );
	}

	public function test_the_aggregate_tolerates_entries_from_an_older_version() {
		update_option(
			Results_Store::INDEX_OPTION,
			array(
				'plugin:old' => array(
					'summary' => array(
						'by_severity' => array(
							'critical'  => 2,
							'legendary' => 9,
						),
					),
				),
			)
		);
		Results_Store::flush_index();

		$aggregate = Report::aggregate();

		$this->assertSame( 1, $aggregate['statuses'][ Scanner::STATUS_PASS ] );
		$this->assertSame( 2, $aggregate['by_severity']['critical'] );
		$this->assertArrayNotHasKey( 'legendary', $aggregate['by_severity'] );
		$this->assertArrayHasKey( 'plugin', $aggregate['by_kind'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Verdicts
	 * ------------------------------------------------------------------
	 */

	public function test_an_unscanned_target_is_pending() {
		$verdict = Report::verdict( $this->add_plugin( 'new' ), array() );

		$this->assertSame( 'pending', $verdict['state'] );
		$this->assertSame( '', $verdict['status'] );
		$this->assertFalse( $verdict['scanned'] );
		$this->assertSame( 'Not scanned yet', $verdict['label'] );
	}

	public function test_a_plugin_listed_as_incompatible_is_blocked_without_a_scan() {
		$verdict = Report::verdict( $this->add_plugin( 'wp-super-cache' ), array() );

		$this->assertSame( Scanner::STATUS_BLOCKED, $verdict['status'] );
		$this->assertSame( 'not-compatible', $verdict['state'] );
		$this->assertSame( 'Incompatible', $verdict['label'] );
	}

	public function test_a_known_must_use_plugin_should_not_be_migrated() {
		$verdict = Report::verdict( $this->add_mu_plugin( 'akismet.php' ), array() );

		$this->assertSame( Scanner::STATUS_REVIEW, $verdict['status'] );
		$this->assertSame( 'Do not ship', $verdict['label'] );
	}

	public function test_a_caution_plugin_still_gets_its_scanned_verdict() {
		$target = $this->add_plugin( 'contact-form-7' );
		$this->store_result( $target, array() );

		$verdict = Report::verdict( $target, Results_Store::get_index() );

		$this->assertSame( Scanner::STATUS_PASS, $verdict['status'] );
		$this->assertSame( 'compatible', $verdict['state'] );
		$this->assertSame( 'caution', $verdict['known']['classification'] );
	}

	public function test_a_scanned_verdict_follows_the_stored_status() {
		$target = $this->add_plugin( 'p' );
		$this->store_result( $target, array( $this->finding( 'security.extract' ) ) );

		$verdict = Report::verdict( $target, Results_Store::get_index() );

		$this->assertSame( 'review', $verdict['state'] );
		$this->assertSame( 'Needs review', $verdict['label'] );
		$this->assertSame( 1, $verdict['total'] );
		$this->assertTrue( $verdict['scanned'] );
	}

	public function test_an_unknown_stored_status_reads_as_review() {
		$target = $this->add_plugin( 'p' );
		$index  = array( 'plugin:p' => array( 'status' => 'weird' ) );

		$this->assertSame( 'review', Report::verdict( $target, $index )['state'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Areas
	 * ------------------------------------------------------------------
	 */

	public function test_area_counts_for_targets_keep_pending_separate() {
		$this->store_result( $this->add_plugin( 'ready' ), array() );
		$this->store_result( $this->add_plugin( 'review' ), array( $this->finding( 'security.extract' ) ) );
		$this->add_plugin( 'wp-rocket' );
		$this->add_plugin( 'unscanned' );

		$this->assertSame(
			array(
				'ready'   => 1,
				'review'  => 1,
				'blocked' => 1,
				'pending' => 1,
				'total'   => 4,
			),
			Report::area_counts( 'plugin' )
		);
	}

	public function test_area_counts_for_the_database() {
		$this->wpdb->add_table( 'wp_posts' )->add_table( 'wp_old', 'MyISAM' );

		$counts = Report::area_counts( 'database' );

		$this->assertSame( 1, $counts['ready'] );
		$this->assertSame( 1, $counts['blocked'] );
		$this->assertSame( 2, $counts['total'] );
	}

	public function test_area_counts_for_wp_content_treat_not_deployed_as_ready() {
		Sandbox::mkdir( 'uploads' );
		Sandbox::mkdir( 'odd' );

		$counts = Report::area_counts( 'directories' );

		// plugins (supported) + uploads (informational), odd (review), mu-plugins (unsupported).
		$this->assertSame( 2, $counts['ready'] );
		$this->assertSame( 1, $counts['review'] );
		$this->assertSame( 1, $counts['blocked'] );
		$this->assertSame( 4, $counts['total'] );
	}

	public function test_areas_map_onto_their_screens() {
		$areas = Report::areas();

		$this->assertSame( array( 'plugin', 'theme', 'mu-plugin', 'database', 'directories' ), array_column( $areas, 'key' ) );
		$this->assertSame( array( 'plugins', 'themes', 'mu-plugins', 'database', 'directories' ), array_column( $areas, 'screen' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Environment
	 * ------------------------------------------------------------------
	 */

	public function test_environment_rows() {
		$this->wpdb->add_table( 'wp_posts', 'InnoDB', 'utf8mb4_unicode_ci', 1, 2048, 0 );

		$rows = array_column( Report::environment(), null, 'label' );

		$this->assertSame( PHP_VERSION, $rows['PHP']['value'] );
		$this->assertSame( version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : 'warn', $rows['PHP']['tone'] );
		$this->assertSame( '6.7', $rows['WordPress']['value'] );
		$this->assertSame( 'Not persistent', $rows['Object cache']['value'] );
		$this->assertSame( 'warn', $rows['Object cache']['tone'] );
		$this->assertSame( 'Single site', $rows['Install type']['value'] );
		$this->assertSame( '2.0 KB', $rows['Database size']['value'] );
		$this->assertSame( '1 table', $rows['Database size']['hint'] );
		$this->assertSame( Rules::VERSION, $rows['Rule set']['value'] );
	}

	public function test_environment_with_a_persistent_cache_on_multisite() {
		WP::$ext_object_cache = true;
		WP::$multisite        = true;

		$rows = array_column( Report::environment(), null, 'label' );

		$this->assertSame( 'Persistent', $rows['Object cache']['value'] );
		$this->assertSame( 'ok', $rows['Object cache']['tone'] );
		$this->assertSame( 'Multisite', $rows['Install type']['value'] );
		$this->assertNotSame( '', $rows['Install type']['hint'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Work that does not come from code
	 * ------------------------------------------------------------------
	 */

	public function test_other_work_lists_audit_actions_worst_first() {
		$this->add_plugin( 'wp-rocket' );
		$this->add_mu_plugin( 'loader.php' );
		$this->wpdb->add_table( 'wp_a', 'MyISAM' )->add_table( 'custom_b' );
		Sandbox::mkdir( 'wflogs' );

		$work = Report::other_work();

		$this->assertSame(
			array( 'blocking', 'blocking', 'important', 'important', 'warning' ),
			array_column( $work, 'tier' )
		);

		$titles = implode( "\n", array_column( $work, 'title' ) );

		$this->assertStringContainsString( 'Replace 1 plugin WordPress VIP lists as incompatible', $titles );
		$this->assertStringContainsString( 'Convert 1 table to InnoDB and utf8mb4', $titles );
		$this->assertStringContainsString( 'Relocate 1 must-use plugin', $titles );
		$this->assertStringContainsString( 'Remove or relocate 2 paths in wp-content', $titles );
		$this->assertStringContainsString( 'Report 1 non-standard table prefix', $titles );

		$this->assertSame( 2, Report::other_work_in_tier( 'blocking' ) );
		$this->assertSame( 1, Report::other_work_in_tier( 'warning' ) );
		$this->assertSame( 0, Report::other_work_in_tier( 'info' ) );
	}

	public function test_a_clean_site_has_no_other_work_beyond_mu_plugins_dir() {
		Sandbox::remove( WPMU_PLUGIN_DIR );

		$this->assertSame( array(), Report::other_work() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Findings, grouping and the fix list
	 * ------------------------------------------------------------------
	 */

	/**
	 * Stores two plugins with a spread of findings.
	 *
	 * @return void
	 */
	private function seed_findings() {
		$this->store_result(
			$this->add_plugin( 'alpha', array(), array( 'Name' => 'Alpha' ) ),
			array(
				$this->finding( 'security.command-execution', array( 'file' => 'a.php', 'line' => 3, 'symbol' => 'exec' ) ),
				$this->finding( 'security.command-execution', array( 'file' => 'b.php', 'line' => 9, 'symbol' => 'exec' ) ),
				$this->finding( 'environment.debug-output', array( 'file' => 'a.php', 'line' => 5, 'symbol' => 'var_dump' ) ),
			)
		);

		$this->store_result(
			$this->add_plugin( 'beta', array(), array( 'Name' => 'Beta' ) ),
			array(
				$this->finding( 'security.command-execution', array( 'file' => 'x.php', 'line' => 1, 'symbol' => 'system' ) ),
				$this->finding( 'caching.cache-flush', array( 'file' => 'y.php', 'line' => 2, 'symbol' => 'wp_cache_flush' ) ),
			)
		);
	}

	public function test_findings_are_flattened_and_enriched_with_their_rule() {
		$this->seed_findings();

		$findings = Report::findings();
		$first    = $findings[0];

		$this->assertCount( 5, $findings );
		$this->assertSame( 'plugin:alpha', $first['target_key'] );
		$this->assertSame( 'Alpha', $first['target_label'] );
		$this->assertSame( Rules::get( 'security.command-execution' )['title'], $first['title'] );
		$this->assertSame( 'security', $first['category'] );
		$this->assertNotSame( '', $first['remediation'] );
	}

	/**
	 * @dataProvider filter_cases
	 */
	public function test_findings_filters( array $filters, $count ) {
		$this->seed_findings();

		$this->assertCount( $count, Report::findings( $filters ) );
	}

	public function filter_cases() {
		return array(
			'no filters'           => array( array(), 5 ),
			'by target'            => array( array( 'target' => 'plugin:beta' ), 2 ),
			'by type'              => array( array( 'type' => Taxonomy::TYPE_SECURITY ), 3 ),
			'by severity'          => array( array( 'severity' => Taxonomy::SEVERITY_HIGH ), 1 ),
			'by tier'              => array( array( 'tier' => Taxonomy::TIER_BLOCKING ), 3 ),
			'by FYI tier'          => array( array( 'tier' => Taxonomy::TIER_INFO ), 1 ),
			'by category'          => array( array( 'category' => 'caching' ), 1 ),
			'search by symbol'     => array( array( 'search' => 'VAR_DUMP' ), 1 ),
			'search by file'       => array( array( 'search' => 'x.php' ), 1 ),
			'search by target'     => array( array( 'search' => 'beta' ), 2 ),
			'search with no match' => array( array( 'search' => 'zzz' ), 0 ),
			'combined'             => array( array( 'target' => 'plugin:alpha', 'type' => Taxonomy::TYPE_SECURITY ), 2 ),
		);
	}

	public function test_findings_from_a_removed_rule_are_skipped() {
		$this->store_result( $this->add_plugin( 'p' ), array( $this->finding( 'security.extract' ), array_merge( $this->finding( 'security.extract' ), array( 'rule' => 'removed.rule' ) ) ) );

		$this->assertCount( 1, Report::findings() );
	}

	public function test_group_by_rule_collapses_occurrences_and_takes_the_worst_severity() {
		$findings = array(
			$this->finding( 'filesystem.directory-iteration', array( 'severity' => 'low', 'confidence' => 'low', 'line' => 1 ) ),
			$this->finding( 'filesystem.directory-iteration', array( 'severity' => 'high', 'confidence' => 'high', 'line' => 2, 'also_matched' => array( 'x' ) ) ),
			$this->finding( 'security.extract', array( 'line' => 3 ) ),
		);

		$groups = Report::group_by_rule( $findings );

		$this->assertSame( array( 'filesystem.directory-iteration', 'security.extract' ), array_column( $groups, 'rule' ) );
		$this->assertSame( 'high', $groups[0]['severity'] );
		$this->assertSame( 'high', $groups[0]['confidence'] );
		$this->assertSame( array( 1, 2 ), array_column( $groups[0]['occurrences'], 'line' ) );
		$this->assertSame( array( 'x' ), $groups[0]['also_matched'] );
	}

	public function test_group_by_rule_orders_equal_severities_by_size() {
		$findings = array(
			$this->finding( 'security.extract', array( 'line' => 1 ) ),
			$this->finding( 'caching.frontend-cookie', array( 'line' => 2 ) ),
			$this->finding( 'caching.frontend-cookie', array( 'line' => 3 ) ),
		);

		$this->assertSame( array( 'caching.frontend-cookie', 'security.extract' ), array_column( Report::group_by_rule( $findings ), 'rule' ) );
	}

	public function test_the_fix_list_is_one_job_per_rule_per_target_worst_first() {
		$this->seed_findings();

		$list = Report::fix_list( Report::findings() );
		$jobs = array_map(
			static function ( $job ) {
				return $job['target_label'] . ' ' . $job['rule'] . ' x' . count( $job['occurrences'] );
			},
			$list
		);

		$this->assertSame(
			array(
				'Alpha security.command-execution x2',
				'Beta security.command-execution x1',
				'Beta caching.cache-flush x1',
				'Alpha environment.debug-output x1',
			),
			$jobs
		);
	}

	public function test_group() {
		$grouped = Report::group(
			array(
				array( 'category' => 'a' ),
				array( 'category' => 'b' ),
				array( 'category' => 'a' ),
				array(),
			),
			'category'
		);

		$this->assertSame( array( 'a', 'b', 'unknown' ), array_keys( $grouped ) );
		$this->assertCount( 2, $grouped['a'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Exports
	 * ------------------------------------------------------------------
	 */

	public function test_to_array_is_a_complete_versioned_payload() {
		$this->seed_findings();
		Report::record_snapshot();

		$payload = Report::to_array();

		$this->assertSame( Report::SCHEMA_VERSION, $payload['schema'] );
		$this->assertSame( 'cutover-audit', $payload['generator'] );
		$this->assertSame( CUTOVER_AUDIT_VERSION, $payload['plugin_version'] );
		$this->assertSame( Rules::VERSION, $payload['rules_version'] );
		$this->assertSame( 'https://example.test', $payload['site']['url'] );
		$this->assertFalse( $payload['site']['multisite'] );
		$this->assertSame( array( 'plugin:alpha', 'plugin:beta' ), array_column( $payload['targets'], 'key' ) );
		$this->assertArrayNotHasKey( 'findings', $payload['targets'][0] );
		$this->assertCount( 5, $payload['findings'] );
		$this->assertCount( 1, $payload['history'] );
		$this->assertNotFalse( strtotime( $payload['generated_at'] ) );
		$this->assertNotFalse( json_encode( $payload ) );
	}

	public function test_to_csv_has_a_header_and_one_row_per_finding() {
		$this->seed_findings();

		$csv  = Report::to_csv();
		$rows = array_map(
			static function ( $line ) {
				return str_getcsv( $line, ',', '"', '' );
			},
			explode( "\r\n", trim( $csv ) )
		);

		$this->assertStringEndsWith( "\r\n", $csv );
		$this->assertSame( 'target_type', $rows[0][0] );
		$this->assertCount( 19, $rows[0] );
		$this->assertCount( 6, $rows );
		$this->assertSame( array( 'plugin', 'Alpha', 'critical' ), array_slice( $rows[1], 0, 3 ) );
	}

	public function test_to_csv_neutralises_spreadsheet_formulas_and_escapes_quotes() {
		$this->store_result(
			$this->add_plugin( 'evil', array(), array( 'Name' => '=HYPERLINK("http://x","y")' ) ),
			array(
				$this->finding( 'security.extract', array( 'evidence' => '+cmd|" /C calc"!A0' ) ),
				$this->finding( 'security.extract', array( 'evidence' => '@SUM(1)', 'line' => 11 ) ),
				$this->finding( 'security.extract', array( 'evidence' => '-2+3', 'line' => 12 ) ),
				$this->finding( 'security.extract', array( 'evidence' => "\tx", 'line' => 13 ) ),
			)
		);

		$csv = Report::to_csv();

		$this->assertStringContainsString( '"\'=HYPERLINK(""http://x"",""y"")"', $csv );
		$this->assertStringContainsString( '"\'+cmd|"" /C calc""!A0"', $csv );
		$this->assertStringContainsString( '"\'@SUM(1)"', $csv );
		$this->assertStringContainsString( '"\'-2+3"', $csv );
		$this->assertStringContainsString( "\"'\tx\"", $csv );
	}

	public function test_to_markdown_lists_findings_by_target() {
		$this->seed_findings();

		$markdown = Report::to_markdown();

		$this->assertStringStartsWith( '# WordPress VIP compatibility report', $markdown );
		$this->assertStringContainsString( '- Site: https://example.test', $markdown );
		$this->assertStringContainsString( '- Targets: 0 ready, 2 need review, 0 blocked', $markdown );
		$this->assertLessThan( strpos( $markdown, '## Beta' ), strpos( $markdown, '## Alpha' ) );
		$this->assertStringContainsString( '(`security.command-execution`) at `a.php:3`', $markdown );
		$this->assertStringContainsString( '  - Reference: https://docs.wpvip.com/', $markdown );
	}

	public function test_to_markdown_escapes_names_and_paths_from_scanned_code() {
		$this->store_result(
			$this->add_plugin( 'evil', array(), array( 'Name' => "![x](https://evil.test/a.png)\n# Owned" ) ),
			array( $this->finding( 'security.extract', array( 'file' => 'a`b.php' ) ) )
		);

		$markdown = Report::to_markdown();

		$this->assertStringContainsString( '## \\!\\[x\\](https://evil.test/a.png) # Owned', $markdown );
		$this->assertStringNotContainsString( "\n# Owned", $markdown );
		$this->assertStringContainsString( "at `a'b.php:10`", $markdown );
	}
}
