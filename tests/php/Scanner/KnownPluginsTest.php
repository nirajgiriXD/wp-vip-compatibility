<?php
/**
 * Tests for the curated plugin lists.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Tests\Scanner;

use SWITCHRAIL_AUDIT\Includes\Scanner\Known_Plugins;
use SWITCHRAIL_AUDIT\Includes\Scanner\Taxonomy;
use SWITCHRAIL_AUDIT\Tests\TestCase;

/**
 * @covers \SWITCHRAIL_AUDIT\Includes\Scanner\Known_Plugins
 */
class KnownPluginsTest extends TestCase {

	/**
	 * @dataProvider classification_cases
	 */
	public function test_classify( $slug, $classification, $severity, $doc ) {
		$known = Known_Plugins::classify( $slug );

		$this->assertSame( $classification, $known['classification'] );
		$this->assertSame( $severity, $known['severity'] );
		$this->assertStringContainsString( $doc, $known['doc'] );
		$this->assertNotSame( '', $known['reason'] );
		$this->assertSame( Known_Plugins::label( $classification ), $known['label'] );
	}

	public function classification_cases() {
		return array(
			'incompatible' => array( 'wp-super-cache', Known_Plugins::INCOMPATIBLE, Taxonomy::SEVERITY_HIGH, 'incompatibilities' ),
			'caution'      => array( 'contact-form-7', Known_Plugins::CAUTION, Taxonomy::SEVERITY_MEDIUM, 'incompatibilities' ),
			'redundant'    => array( 'jetpack', Known_Plugins::REDUNDANT, Taxonomy::SEVERITY_LOW, 'vip-go-mu-plugins' ),
		);
	}

	public function test_an_unlisted_plugin_is_unknown() {
		$this->assertNull( Known_Plugins::classify( 'my-custom-plugin' ) );
	}

	public function test_must_use_plugins_match_with_or_without_the_php_extension() {
		$this->assertNotNull( Known_Plugins::mu_plugin( 'akismet' ) );
		$this->assertSame( Known_Plugins::mu_plugin( 'wpe-cache-plugin' ), Known_Plugins::mu_plugin( 'wpe-cache-plugin.php' ) );
		$this->assertSame( Known_Plugins::mu_plugin( 'mu-plugin.php' ), Known_Plugins::mu_plugin( 'mu-plugin' ) );
		$this->assertNull( Known_Plugins::mu_plugin( 'my-loader.php' ) );
	}

	public function test_labels() {
		$this->assertSame( 'Listed incompatible by WordPress VIP', Known_Plugins::label( Known_Plugins::INCOMPATIBLE ) );
		$this->assertSame( 'Needs testing on WordPress VIP', Known_Plugins::label( Known_Plugins::CAUTION ) );
		$this->assertSame( 'Provided by the WordPress VIP platform', Known_Plugins::label( Known_Plugins::REDUNDANT ) );
	}
}
