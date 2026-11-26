<?php
/**
 * Tests for the rule registry.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Scanner;

use Brain\Monkey\Filters;
use PREFLIGHT_AUDIT\Includes\Scanner\Rules;
use PREFLIGHT_AUDIT\Includes\Scanner\Taxonomy;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Scanner\Rules
 */
class RulesTest extends TestCase {

	public function test_every_rule_is_fully_described() {
		$types        = array_keys( Taxonomy::get_types() );
		$severities   = array_keys( Taxonomy::get_severities() );
		$confidences  = array_keys( Taxonomy::get_confidences() );
		$fixabilities = array_keys( Taxonomy::get_fixabilities() );
		$categories   = array_keys( Taxonomy::get_categories() );

		$this->assertNotEmpty( Rules::all() );

		foreach ( Rules::all() as $id => $rule ) {
			$this->assertMatchesRegularExpression( '/^[a-z]+\.[a-z0-9-]+$/', $id, "Rule id {$id}" );
			$this->assertSame( strtok( $id, '.' ), $rule['category'], "{$id} is filed under its own prefix" );
			$this->assertContains( $rule['type'], $types, "{$id} type" );
			$this->assertContains( $rule['severity'], $severities, "{$id} severity" );
			$this->assertContains( $rule['confidence'], $confidences, "{$id} confidence" );
			$this->assertContains( $rule['fixability'], $fixabilities, "{$id} fixability" );
			$this->assertContains( $rule['category'], $categories, "{$id} category" );
			$this->assertNotSame( '', $rule['title'], "{$id} title" );
			$this->assertNotSame( '', $rule['why'], "{$id} why" );
			$this->assertNotSame( '', $rule['remediation'], "{$id} remediation" );
			$this->assertStringStartsWith( 'https://docs.wpvip.com/', $rule['doc'], "{$id} doc" );
			$this->assertNotEmpty( $rule['match'], "{$id} matches something" );

			if ( null !== $rule['guard'] ) {
				$this->assertTrue( is_callable( $rule['guard'] ), "{$id} guard is callable" );
			}
		}
	}

	public function test_string_rules_use_valid_patterns() {
		foreach ( Rules::all() as $id => $rule ) {
			foreach ( $rule['match']['strings'] ?? array() as $pattern ) {
				$this->assertNotFalse( @preg_match( $pattern, '' ), "{$id} pattern {$pattern}" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	public function test_get_returns_null_for_an_unknown_rule() {
		$this->assertNull( Rules::get( 'filesystem.does-not-exist' ) );
		$this->assertSame( 'filesystem', Rules::get( 'filesystem.blogs-dir' )['category'] );
	}

	public function test_rules_are_memoised_until_reset() {
		$first = Rules::all();

		Filters\expectApplied( 'preflight_audit_scanner_rules' )->never();

		$this->assertSame( $first, Rules::all() );
	}

	public function test_filter_can_add_change_and_remove_rules() {
		Filters\expectApplied( 'preflight_audit_scanner_rules' )
			->once()
			->andReturnUsing(
				static function ( array $rules ) {
					unset( $rules['standards.unsafe-redirect'] );
					$rules['caching.cache-flush']['severity'] = Taxonomy::SEVERITY_LOW;
					$rules['custom.no-sleep']                 = array(
						'title' => 'No sleeping',
						'match' => array( 'functions' => array( 'sleep' ) ),
					);

					return $rules;
				}
			);

		$rules = Rules::all();

		$this->assertArrayNotHasKey( 'standards.unsafe-redirect', $rules );
		$this->assertSame( Taxonomy::SEVERITY_LOW, $rules['caching.cache-flush']['severity'] );
	}

	public function test_a_filtered_rule_gets_defaults_for_missing_keys() {
		Filters\expectApplied( 'preflight_audit_scanner_rules' )
			->andReturnUsing(
				static function ( array $rules ) {
					$rules['custom.no-sleep'] = array(
						'title' => 'No sleeping',
						'match' => array( 'functions' => array( 'sleep' ) ),
					);

					return $rules;
				}
			);

		$rule = Rules::get( 'custom.no-sleep' );

		$this->assertSame( 'standards', $rule['category'] );
		$this->assertSame( Taxonomy::TYPE_RECOMMENDATION, $rule['type'] );
		$this->assertSame( Taxonomy::SEVERITY_LOW, $rule['severity'] );
		$this->assertSame( Taxonomy::CONFIDENCE_MEDIUM, $rule['confidence'] );
		$this->assertSame( Taxonomy::FIX_MANUAL, $rule['fixability'] );
		$this->assertNull( $rule['guard'] );
	}

	public function test_a_filtered_rule_is_used_by_the_scanner() {
		Filters\expectApplied( 'preflight_audit_scanner_rules' )
			->andReturnUsing(
				static function ( array $rules ) {
					$rules['custom.no-sleep'] = array(
						'title' => 'No sleeping',
						'match' => array( 'functions' => array( 'sleep' ) ),
					);

					return $rules;
				}
			);

		$this->assertContains( 'custom.no-sleep', $this->rules_in( "<?php\nsleep( 5 );\n" ) );
	}
}
