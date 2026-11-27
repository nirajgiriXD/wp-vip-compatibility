<?php
/**
 * Tests for the finding vocabulary.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Tests\Scanner;

use CUTOVER_AUDIT\Includes\Scanner\Taxonomy;
use CUTOVER_AUDIT\Tests\TestCase;

/**
 * @covers \CUTOVER_AUDIT\Includes\Scanner\Taxonomy
 */
class TaxonomyTest extends TestCase {

	public function test_severities_are_ordered_most_serious_first_with_falling_weights() {
		$severities = Taxonomy::get_severities();

		$this->assertSame(
			array( 'critical', 'high', 'medium', 'low', 'info' ),
			array_keys( $severities )
		);

		$weights = array_column( $severities, 'weight' );
		$sorted  = $weights;
		rsort( $sorted );

		$this->assertSame( $sorted, $weights );
	}

	public function test_severity_weight_is_zero_for_an_unknown_severity() {
		$this->assertSame( 5, Taxonomy::get_severity_weight( Taxonomy::SEVERITY_CRITICAL ) );
		$this->assertSame( 1, Taxonomy::get_severity_weight( Taxonomy::SEVERITY_INFO ) );
		$this->assertSame( 0, Taxonomy::get_severity_weight( 'catastrophic' ) );
	}

	/**
	 * @dataProvider tier_provider
	 */
	public function test_every_severity_belongs_to_exactly_one_tier( $severity, $tier ) {
		$this->assertSame( $tier, Taxonomy::get_tier( $severity ) );
	}

	public function tier_provider() {
		return array(
			'critical is must fix'     => array( 'critical', 'blocking' ),
			'high is should fix'       => array( 'high', 'important' ),
			'medium is worth checking' => array( 'medium', 'warning' ),
			'low is FYI'               => array( 'low', 'info' ),
			'info is FYI'              => array( 'info', 'info' ),
			'unknown falls back to FYI' => array( 'nonsense', 'info' ),
		);
	}

	public function test_tiers_cover_every_severity_once() {
		$covered = array();

		foreach ( Taxonomy::get_tiers() as $definition ) {
			$covered = array_merge( $covered, $definition['severities'] );
		}

		sort( $covered );
		$expected = array_keys( Taxonomy::get_severities() );
		sort( $expected );

		$this->assertSame( $expected, $covered );
	}

	public function test_tier_severities_for_an_unknown_tier_are_empty() {
		$this->assertSame( array( 'low', 'info' ), Taxonomy::get_tier_severities( Taxonomy::TIER_INFO ) );
		$this->assertSame( array(), Taxonomy::get_tier_severities( 'nope' ) );
	}

	public function test_tier_labels_are_instructions() {
		$labels = array_column( Taxonomy::get_tiers(), 'label' );

		$this->assertSame( array( 'Must fix', 'Should fix', 'Worth checking', 'FYI' ), $labels );
	}

	public function test_only_incompatible_types_block_a_migration() {
		$blocking = array_keys( array_filter( array_column( Taxonomy::get_types(), 'blocking', null ) ) );

		$this->assertTrue( Taxonomy::is_blocking_type( Taxonomy::TYPE_INCOMPATIBLE ) );
		$this->assertTrue( Taxonomy::is_blocking_type( Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE ) );
		$this->assertFalse( Taxonomy::is_blocking_type( Taxonomy::TYPE_SECURITY ) );
		$this->assertFalse( Taxonomy::is_blocking_type( Taxonomy::TYPE_PERFORMANCE ) );
		$this->assertFalse( Taxonomy::is_blocking_type( 'unknown' ) );
		$this->assertCount( 2, $blocking );
	}

	public function test_fixability_short_labels() {
		$this->assertSame( 'Quick fix', Taxonomy::get_fixability_short( Taxonomy::FIX_AUTOMATIC ) );
		$this->assertSame( 'Redesign', Taxonomy::get_fixability_short( Taxonomy::FIX_ARCHITECTURAL ) );
		$this->assertSame( '', Taxonomy::get_fixability_short( 'unknown' ) );
	}

	/**
	 * @dataProvider label_provider
	 */
	public function test_get_label( $axis, $value, $expected ) {
		$this->assertSame( $expected, Taxonomy::get_label( $axis, $value ) );
	}

	public function label_provider() {
		return array(
			'type'                => array( 'type', 'incompatible', 'Incompatible' ),
			'severity'            => array( 'severity', 'critical', 'Critical' ),
			'confidence'          => array( 'confidence', 'definitive', 'Definitive' ),
			'fixability'          => array( 'fixability', 'manual', 'Manually fixable' ),
			'category'            => array( 'category', 'http', 'External requests' ),
			'unknown value'       => array( 'type', 'mystery', 'mystery' ),
			'unknown category'    => array( 'category', 'mystery', 'mystery' ),
			'unknown axis'        => array( 'colour', 'red', 'red' ),
		);
	}

	public function test_confidences_are_ordered_strongest_first() {
		$this->assertSame(
			array( 'definitive', 'high', 'medium', 'low' ),
			array_keys( Taxonomy::get_confidences() )
		);
	}
}
