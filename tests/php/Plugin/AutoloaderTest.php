<?php
/**
 * Tests for the class autoloader.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Tests\Plugin;

use SWITCHRAIL_AUDIT\Includes\Helpers\Autoloader;
use SWITCHRAIL_AUDIT\Tests\TestCase;

/**
 * @covers \SWITCHRAIL_AUDIT\Includes\Helpers\Autoloader
 */
class AutoloaderTest extends TestCase {

	/**
	 * Maps every file in includes/ to the class name the autoloader expects.
	 *
	 * @return array<string, array{0: string, 1: string}> File => [class, kind].
	 */
	public function include_files() {
		$cases = array();

		foreach ( glob( dirname( __DIR__, 3 ) . '/includes/*/*.php' ) as $file ) {
			$directory = basename( dirname( $file ) );
			$name      = basename( $file, '.php' );
			$kind      = ( 0 === strpos( $name, 'trait-' ) ) ? 'trait' : 'class';
			$short     = substr( $name, strlen( $kind ) + 1 );
			$short     = implode( '_', array_map( 'ucfirst', explode( '-', $short ) ) );

			$cases[ $directory . '/' . basename( $file ) ] = array(
				'SWITCHRAIL_AUDIT\\Includes\\' . ucfirst( $directory ) . '\\' . $short,
				$kind,
			);
		}

		return $cases;
	}

	/**
	 * @dataProvider include_files
	 */
	public function test_every_file_in_includes_can_be_autoloaded( $name, $kind ) {
		$exists = ( 'trait' === $kind ) ? trait_exists( $name ) : ( class_exists( $name ) || interface_exists( $name ) );

		$this->assertTrue( $exists, $name );
	}

	public function test_the_provider_found_the_files() {
		$this->assertGreaterThan( 20, count( $this->include_files() ) );
	}

	public function test_other_namespaces_are_left_to_other_autoloaders() {
		$this->assertFalse( class_exists( 'Some\\Other\\Thing' ) );
		$this->assertFalse( class_exists( 'SWITCHRAIL_AUDIT\\Elsewhere\\Thing' ) );
		$this->assertFalse( class_exists( 'SWITCHRAIL_AUDIT\\Includes\\Classes\\Does_Not_Exist' ) );
	}

	public function test_the_autoloader_is_registered() {
		$this->assertContains( array( Autoloader::class, 'run_autoloader' ), spl_autoload_functions() );
	}
}
