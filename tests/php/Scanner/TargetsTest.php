<?php
/**
 * Tests for the scan target registry.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Tests\Scanner;

use SWITCHRAIL_AUDIT\Includes\Scanner\Targets;
use SWITCHRAIL_AUDIT\Tests\Support\Sandbox;
use SWITCHRAIL_AUDIT\Tests\Support\WP;
use SWITCHRAIL_AUDIT\Tests\TestCase;

/**
 * @covers \SWITCHRAIL_AUDIT\Includes\Scanner\Targets
 */
class TargetsTest extends TestCase {

	public function test_plugins_are_listed_by_directory() {
		$this->add_plugin( 'akismet', array( 'akismet.php' => "<?php\n" ), array( 'Name' => 'Akismet', 'Author' => '<a href="#">Automattic</a>' ) );

		$target = Targets::get( 'plugin:akismet' );

		$this->assertSame( 'plugin', $target['type'] );
		$this->assertSame( 'akismet', $target['slug'] );
		$this->assertSame( 'Akismet', $target['label'] );
		$this->assertSame( 'Automattic', $target['author'], 'Author markup is stripped' );
		$this->assertSame( WP_PLUGIN_DIR . '/akismet', $target['path'] );
		$this->assertSame( 'akismet/akismet.php', $target['file'] );
	}

	public function test_a_single_file_plugin_is_keyed_by_its_file() {
		$this->add_single_file_plugin( 'hello.php', "<?php\n" );

		$target = Targets::get( 'plugin:hello.php' );

		$this->assertSame( WP_PLUGIN_DIR . '/hello.php', $target['path'] );
		$this->assertSame( 'hello.php', $target['slug'] );
	}

	public function test_themes_are_listed() {
		$this->add_theme( 'twentytwentyfive', array(), array( 'Name' => 'Twenty Twenty-Five' ) );

		$target = Targets::get( 'theme:twentytwentyfive' );

		$this->assertSame( 'theme', $target['type'] );
		$this->assertSame( 'Twenty Twenty-Five', $target['label'] );
		$this->assertSame( WP_CONTENT_DIR . '/themes/twentytwentyfive', $target['path'] );
	}

	public function test_must_use_files_and_directories_are_both_listed() {
		$this->add_mu_plugin( 'loader.php', "<?php\n", array( 'Name' => 'Loader' ) );
		$this->add_mu_plugin( 'unnamed.php' );
		Sandbox::write( 'mu-plugins/platform/platform.php', "<?php\n" );
		Sandbox::write( 'mu-plugins/assets/readme.txt', 'no php here' );
		Targets::reset();

		$keys = array_keys( Targets::of_type( 'mu-plugin' ) );
		sort( $keys );

		$this->assertSame( array( 'mu-plugin:loader.php', 'mu-plugin:platform', 'mu-plugin:unnamed.php' ), $keys );
		$this->assertSame( 'Loader', Targets::get( 'mu-plugin:loader.php' )['label'] );
		$this->assertSame( 'unnamed.php', Targets::get( 'mu-plugin:unnamed.php' )['label'], 'A nameless file is labelled by its file name' );
		$this->assertSame( WPMU_PLUGIN_DIR . '/platform', Targets::get( 'mu-plugin:platform' )['path'] );
	}

	public function test_a_mu_plugin_file_and_a_directory_with_the_same_key_are_not_duplicated() {
		// A reported must-use plugin whose key matches a directory name.
		WP::$mu_plugins['shared'] = array(
			'Name'    => 'Shared file',
			'Version' => '',
			'Author'  => '',
		);
		Sandbox::write( 'mu-plugins/shared/index.php', "<?php\n" );
		Targets::reset();

		$this->assertSame( 'Shared file', Targets::get( 'mu-plugin:shared' )['label'] );
		$this->assertCount( 1, Targets::of_type( 'mu-plugin' ) );
	}

	public function test_a_missing_mu_plugins_directory_lists_only_reported_files() {
		Sandbox::remove( WPMU_PLUGIN_DIR );

		$this->assertSame( array(), Targets::of_type( 'mu-plugin' ) );
	}

	public function test_an_unknown_key_resolves_to_nothing() {
		$this->add_plugin( 'akismet' );

		$this->assertNull( Targets::get( 'plugin:../../etc' ) );
		$this->assertNull( Targets::get( WP_PLUGIN_DIR . '/akismet' ) );
		$this->assertNull( Targets::get( '' ) );
	}

	public function test_of_type_filters_and_all_merges() {
		$this->add_plugin( 'one' );
		$this->add_theme( 'two' );
		$this->add_mu_plugin( 'three.php' );

		$this->assertSame( array( 'plugin:one' ), array_keys( Targets::of_type( 'plugin' ) ) );
		$this->assertSame( array( 'theme:two' ), array_keys( Targets::of_type( 'theme' ) ) );
		$this->assertSame( array( 'plugin:one', 'theme:two', 'mu-plugin:three.php' ), array_keys( Targets::all() ) );
	}

	public function test_the_list_is_memoised_until_reset() {
		$this->add_plugin( 'first' );
		Targets::all();

		WP::$plugins = array();

		$this->assertNotNull( Targets::get( 'plugin:first' ) );

		Targets::reset();

		$this->assertNull( Targets::get( 'plugin:first' ) );
	}

	public function test_key() {
		$this->assertSame( 'theme:twentytwentyfive', Targets::key( 'theme', 'twentytwentyfive' ) );
	}
}
