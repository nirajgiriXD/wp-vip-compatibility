<?php
/**
 * Tests for result persistence.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Tests\Scanner;

use CUTOVER_AUDIT\Includes\Scanner\Results_Store;
use CUTOVER_AUDIT\Includes\Scanner\Rules;
use CUTOVER_AUDIT\Tests\Support\WP;
use CUTOVER_AUDIT\Tests\TestCase;

/**
 * @covers \CUTOVER_AUDIT\Includes\Scanner\Results_Store
 */
class ResultsStoreTest extends TestCase {

	/**
	 * Builds a minimal stored result.
	 *
	 * @param string               $key   Target key.
	 * @param array<string, mixed> $extra Values to replace.
	 * @return array<string, mixed> The result.
	 */
	private function result( $key, array $extra = array() ) {
		list( $type, $slug ) = explode( ':', $key, 2 );

		return array_merge(
			array(
				'key'           => $key,
				'type'          => $type,
				'slug'          => $slug,
				'label'         => ucfirst( $slug ),
				'findings'      => array( $this->finding( 'security.extract' ) ),
				'summary'       => array( 'total' => 1 ),
				'status'        => 'review',
				'files_scanned' => 2,
				'truncated'     => false,
				'scanned_at'    => 1000,
				'rules_version' => Rules::VERSION,
				'fingerprint'   => 'abc',
			),
			$extra
		);
	}

	public function test_a_saved_result_can_be_read_back() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );

		$this->assertSame( $this->result( 'plugin:a' ), Results_Store::get( 'plugin:a' ) );
	}

	public function test_results_are_stored_per_target_and_never_autoloaded() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );

		$name = Results_Store::RESULT_PREFIX . md5( 'plugin:a' );

		$this->assertArrayHasKey( $name, WP::blog_options() );
		$this->assertFalse( WP::$autoload[1][ $name ] );
		$this->assertFalse( WP::$autoload[1][ Results_Store::INDEX_OPTION ] );
		$this->assertLessThanOrEqual( 191, strlen( Results_Store::RESULT_PREFIX . md5( str_repeat( 'x', 500 ) ) ) );
	}

	public function test_the_index_carries_the_summary_but_not_the_findings() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );

		$entry = Results_Store::get_index()['plugin:a'];

		$this->assertSame( 'review', $entry['status'] );
		$this->assertSame( array( 'total' => 1 ), $entry['summary'] );
		$this->assertArrayNotHasKey( 'findings', $entry );
	}

	public function test_nothing_stored_reads_as_null() {
		$this->assertNull( Results_Store::get( 'plugin:none' ) );
	}

	public function test_a_result_from_an_older_rule_set_is_ignored() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a', array( 'rules_version' => '0.1.0' ) ) );

		$this->assertNull( Results_Store::get( 'plugin:a' ) );
	}

	public function test_the_index_leaves_out_entries_from_an_older_rule_set() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );
		Results_Store::save( 'plugin:old', $this->result( 'plugin:old', array( 'rules_version' => '0.1.0' ) ) );

		$this->assertSame( array( 'plugin:a' ), array_keys( Results_Store::get_index() ) );
		$this->assertSame( Rules::VERSION, Results_Store::get_index()['plugin:a']['rules_version'] );
	}

	public function test_forgetting_reaches_an_entry_from_an_older_rule_set() {
		Results_Store::save( 'plugin:old', $this->result( 'plugin:old', array( 'rules_version' => '0.1.0' ) ) );

		$this->assertSame( 1, Results_Store::forget( array( 'plugin:old' ) ) );
		$this->assertSame( array(), get_option( Results_Store::INDEX_OPTION ) );
	}

	public function test_a_corrupt_or_unfingerprinted_result_is_ignored() {
		update_option( Results_Store::RESULT_PREFIX . md5( 'plugin:a' ), 'not an array' );
		$this->assertNull( Results_Store::get( 'plugin:a' ) );

		Results_Store::save( 'plugin:b', $this->result( 'plugin:b', array( 'fingerprint' => '' ) ) );
		$this->assertNull( Results_Store::get( 'plugin:b' ) );
	}

	public function test_saving_does_not_drop_an_entry_another_request_wrote() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );
		Results_Store::get_index();

		// Another request writes an entry behind this process's back.
		$index               = get_option( Results_Store::INDEX_OPTION );
		$index['plugin:b']   = array( 'key' => 'plugin:b' );
		update_option( Results_Store::INDEX_OPTION, $index, false );

		Results_Store::save( 'plugin:c', $this->result( 'plugin:c' ) );

		$this->assertSame( array( 'plugin:a', 'plugin:b', 'plugin:c' ), array_keys( Results_Store::get_index() ) );
	}

	public function test_a_corrupt_index_reads_as_empty() {
		update_option( Results_Store::INDEX_OPTION, 'garbage' );
		Results_Store::flush_index();

		$this->assertSame( array(), Results_Store::get_index() );
	}

	public function test_every_write_changes_the_generation() {
		$generations = array( Results_Store::generation() );

		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );
		$generations[] = Results_Store::generation();

		Results_Store::forget( array( 'plugin:a' ) );
		$generations[] = Results_Store::generation();

		Results_Store::delete_all();
		$generations[] = Results_Store::generation();

		$this->assertCount( 4, array_unique( $generations ) );
	}

	public function test_get_all_loads_every_indexed_result() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );
		Results_Store::save( 'theme:b', $this->result( 'theme:b' ) );
		Results_Store::save( 'plugin:old', $this->result( 'plugin:old', array( 'rules_version' => '0.0.1' ) ) );

		$this->assertSame( array( 'plugin:a', 'theme:b' ), array_keys( Results_Store::get_all() ) );
	}

	public function test_prune_removes_targets_that_no_longer_exist() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );
		Results_Store::save( 'plugin:gone', $this->result( 'plugin:gone' ) );

		$this->assertSame( 1, Results_Store::prune( array( 'plugin:a' ) ) );
		$this->assertSame( array( 'plugin:a' ), array_keys( Results_Store::get_index() ) );
		$this->assertNull( Results_Store::get( 'plugin:gone' ) );
		$this->assertSame( 0, Results_Store::prune( array( 'plugin:a' ) ) );
	}

	public function test_forget_discards_results_but_keeps_history() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );
		Results_Store::save( 'plugin:b', $this->result( 'plugin:b' ) );
		Results_Store::record_history( array( 'total' => 1 ) );

		$this->assertSame( 1, Results_Store::forget( array( 'plugin:a', 'plugin:never-scanned' ) ) );
		$this->assertNull( Results_Store::get( 'plugin:a' ) );
		$this->assertNotNull( Results_Store::get( 'plugin:b' ) );
		$this->assertCount( 1, Results_Store::get_history() );
		$this->assertSame( 0, Results_Store::forget( array() ) );
	}

	public function test_delete_all_removes_results_index_and_history() {
		Results_Store::save( 'plugin:a', $this->result( 'plugin:a' ) );
		Results_Store::record_history( array( 'total' => 1 ) );

		Results_Store::delete_all();

		$this->assertSame( array(), WP::blog_options() );
		$this->assertSame( array(), Results_Store::get_index() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * History
	 * ------------------------------------------------------------------
	 */

	private function snapshot( $total, $blocking = 0, $blocked = 0 ) {
		return array(
			'total'    => $total,
			'blocking' => $blocking,
			'blocked'  => $blocked,
			'review'   => 0,
			'pass'     => 0,
		);
	}

	public function test_history_records_snapshots_oldest_first() {
		Results_Store::record_history( $this->snapshot( 5 ) );
		Results_Store::record_history( $this->snapshot( 3 ) );

		$history = Results_Store::get_history();

		$this->assertSame( array( 5, 3 ), array_column( $history, 'total' ) );
		$this->assertArrayHasKey( 'recorded_at', $history[0] );
	}

	public function test_an_identical_snapshot_in_a_row_is_not_recorded() {
		Results_Store::record_history( $this->snapshot( 5 ) );
		Results_Store::record_history( $this->snapshot( 5 ) );
		Results_Store::record_history( $this->snapshot( 4 ) );
		Results_Store::record_history( $this->snapshot( 5 ) );

		$this->assertSame( array( 5, 4, 5 ), array_column( Results_Store::get_history(), 'total' ) );
	}

	public function test_history_keeps_only_the_most_recent_snapshots() {
		for ( $i = 1; $i <= Results_Store::HISTORY_LIMIT + 5; $i++ ) {
			Results_Store::record_history( $this->snapshot( $i ) );
		}

		$history = Results_Store::get_history();

		$this->assertCount( Results_Store::HISTORY_LIMIT, $history );
		$this->assertSame( 6, $history[0]['total'] );
		$this->assertSame( range( 0, Results_Store::HISTORY_LIMIT - 1 ), array_keys( $history ) );
	}

	public function test_a_corrupt_history_reads_as_empty() {
		update_option( Results_Store::HISTORY_OPTION, 'garbage' );

		$this->assertSame( array(), Results_Store::get_history() );
	}

	public function test_delta_compares_the_last_two_snapshots() {
		$this->assertNull( Results_Store::get_delta() );

		Results_Store::record_history( $this->snapshot( 10, 4, 2 ) );
		$this->assertNull( Results_Store::get_delta() );

		Results_Store::record_history( $this->snapshot( 7, 5, 1 ) );

		$delta = Results_Store::get_delta();

		$this->assertSame( -3, $delta['total'] );
		$this->assertSame( 1, $delta['blocking'] );
		$this->assertSame( -1, $delta['blocked'] );
		$this->assertSame( Results_Store::get_history()[0]['recorded_at'], $delta['since'] );
	}
}
