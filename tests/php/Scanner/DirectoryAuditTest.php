<?php
/**
 * Tests for the wp-content audit.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Tests\Scanner;

use CUTOVER_AUDIT\Includes\Classes\Plugin;
use CUTOVER_AUDIT\Includes\Scanner\Directory_Audit;
use CUTOVER_AUDIT\Tests\Support\Sandbox;
use CUTOVER_AUDIT\Tests\TestCase;

/**
 * @covers \CUTOVER_AUDIT\Includes\Scanner\Directory_Audit
 */
class DirectoryAuditTest extends TestCase {

	/**
	 * Returns the audited entries keyed by name.
	 *
	 * @return array<string, array<string, mixed>> Entries.
	 */
	private function entries() {
		return array_column( Directory_Audit::run( true )['entries'], null, 'name' );
	}

	public function test_known_entries_take_their_status_from_the_reference_data() {
		Sandbox::mkdir( 'themes' );
		Sandbox::mkdir( 'uploads' );
		Sandbox::mkdir( 'blogs.dir' );

		$entries = $this->entries();

		$this->assertSame( Directory_Audit::STATUS_SUPPORTED, $entries['themes']['status'] );
		$this->assertSame( Directory_Audit::STATUS_SUPPORTED, $entries['plugins']['status'] );
		$this->assertSame( Directory_Audit::STATUS_INFORMATIONAL, $entries['uploads']['status'], 'uploads is not deployed, not incompatible' );
		$this->assertSame( Directory_Audit::STATUS_UNSUPPORTED, $entries['mu-plugins']['status'] );
		$this->assertSame( Directory_Audit::STATUS_UNSUPPORTED, $entries['blogs.dir']['status'] );
		$this->assertTrue( $entries['themes']['is_dir'] );
		$this->assertNotSame( '', $entries['themes']['description'] );
	}

	public function test_an_unknown_drop_in_is_unsupported() {
		Sandbox::write( 'sunrise.php', '<?php' );
		Sandbox::write( 'maintenance.php', '<?php' );

		$entries = $this->entries();

		$this->assertSame( Directory_Audit::STATUS_UNSUPPORTED, $entries['sunrise.php']['status'] );
		$this->assertSame( Directory_Audit::STATUS_UNSUPPORTED, $entries['maintenance.php']['status'] );
		$this->assertStringContainsString( 'drop-ins', $entries['sunrise.php']['doc'] );
	}

	public function test_anything_else_needs_review() {
		Sandbox::mkdir( 'my-cache' );
		Sandbox::write( 'notes.txt', 'x' );
		Sandbox::mkdir( 'sunrise.php' );

		$entries = $this->entries();

		$this->assertSame( Directory_Audit::STATUS_REVIEW, $entries['my-cache']['status'] );
		$this->assertStringStartsWith( 'Directory', $entries['my-cache']['description'] );
		$this->assertSame( Directory_Audit::STATUS_REVIEW, $entries['notes.txt']['status'] );
		$this->assertStringStartsWith( 'File', $entries['notes.txt']['description'] );
		$this->assertSame( Directory_Audit::STATUS_REVIEW, $entries['sunrise.php']['status'], 'A directory named like a drop-in is not a drop-in' );
	}

	public function test_entries_are_sorted_naturally_and_case_insensitively() {
		Sandbox::mkdir( 'b10' );
		Sandbox::mkdir( 'B2' );
		Sandbox::mkdir( 'a' );

		$names = array_column( Directory_Audit::run( true )['entries'], 'name' );

		$this->assertSame( array( 'a', 'B2', 'b10', 'mu-plugins', 'plugins' ), $names );
	}

	public function test_the_summary_counts_every_status() {
		Sandbox::mkdir( 'uploads' );
		Sandbox::mkdir( 'odd' );

		$summary = Directory_Audit::run( true )['summary'];

		$this->assertSame( 4, $summary['total'] );
		$this->assertSame( 1, $summary['supported'] );
		$this->assertSame( 1, $summary['unsupported'] );
		$this->assertSame( 1, $summary['informational'] );
		$this->assertSame( 1, $summary['review'] );
	}

	public function test_the_result_is_held_for_the_request_until_flushed() {
		$first = Directory_Audit::run();

		Sandbox::mkdir( 'later' );

		$this->assertSame( $first, Directory_Audit::run() );

		Directory_Audit::flush();

		$this->assertSame( 3, Directory_Audit::run()['summary']['total'] );
	}

	public function test_a_missing_wp_content_is_empty() {
		Sandbox::remove( WP_CONTENT_DIR );

		$this->assertSame( 0, Directory_Audit::run( true )['summary']['total'] );
	}

	/**
	 * @dataProvider legacy_status_cases
	 */
	public function test_reference_records_in_the_older_format_still_work( array $record, $status ) {
		$this->assertSame( $status, $this->call_private( Directory_Audit::class, 'normalise_status', array( $record ) ) );
	}

	public function legacy_status_cases() {
		return array(
			'is_supported true'  => array( array( 'is_supported' => true ), Directory_Audit::STATUS_SUPPORTED ),
			'is_supported false' => array( array( 'is_supported' => false ), Directory_Audit::STATUS_UNSUPPORTED ),
			'unknown status'     => array( array( 'status' => 'maybe' ), Directory_Audit::STATUS_REVIEW ),
			'no status at all'   => array( array(), Directory_Audit::STATUS_REVIEW ),
		);
	}

	/**
	 * @dataProvider status_labels
	 */
	public function test_status_labels( $status, $label ) {
		$this->assertSame( $label, Directory_Audit::status_label( $status ) );
	}

	public function status_labels() {
		return array(
			array( Directory_Audit::STATUS_SUPPORTED, 'Supported' ),
			array( Directory_Audit::STATUS_UNSUPPORTED, 'Remove or relocate' ),
			array( Directory_Audit::STATUS_INFORMATIONAL, 'Not deployed' ),
			array( Directory_Audit::STATUS_REVIEW, 'Needs review' ),
		);
	}

	public function test_every_reference_entry_has_a_valid_status() {
		$valid = array( 'supported', 'unsupported', 'informational', 'review' );

		foreach ( Plugin::get_instance()->get_reference_list( 'directories' ) as $name => $record ) {
			$this->assertContains( $record['status'], $valid, $name );
		}
	}
}
