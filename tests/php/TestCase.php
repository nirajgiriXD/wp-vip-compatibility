<?php
/**
 * Base test case.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use CUTOVER_AUDIT\Includes\Scanner\Code_Scanner;
use CUTOVER_AUDIT\Includes\Scanner\Directory_Audit;
use CUTOVER_AUDIT\Includes\Scanner\Results_Store;
use CUTOVER_AUDIT\Includes\Scanner\Rules;
use CUTOVER_AUDIT\Includes\Scanner\Scanner;
use CUTOVER_AUDIT\Includes\Scanner\Targets;
use CUTOVER_AUDIT\Tests\Support\Fake_Theme;
use CUTOVER_AUDIT\Tests\Support\Fake_Wpdb;
use CUTOVER_AUDIT\Tests\Support\Sandbox;
use CUTOVER_AUDIT\Tests\Support\WP;

/**
 * Resets WordPress, the sandbox and the plugin's memoised state around each test.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The fake database.
	 *
	 * @var Fake_Wpdb
	 */
	protected $wpdb;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		WP::reset();
		Sandbox::reset();

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$this->wpdb      = new Fake_Wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		Rules::reset();
		Targets::reset();
		Results_Store::flush_index();
		Directory_Audit::flush();
	}

	/**
	 * Tears down the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Installing things into the sandbox
	 * ------------------------------------------------------------------
	 */

	/**
	 * Installs a plugin directory.
	 *
	 * @param string                $slug    Directory name.
	 * @param array<string, string> $files   File contents keyed by path inside the plugin.
	 * @param array<string, string> $headers Plugin headers.
	 * @return array<string, mixed> The target WordPress would report for it.
	 */
	protected function add_plugin( $slug, array $files = array(), array $headers = array() ) {
		if ( empty( $files ) ) {
			$files = array( $slug . '.php' => "<?php\n// Plugin Name: {$slug}\n" );
		}

		foreach ( $files as $path => $contents ) {
			Sandbox::write( 'plugins/' . $slug . '/' . $path, $contents );
		}

		$main = $slug . '/' . array_key_first( $files );

		WP::$plugins[ $main ] = array_merge(
			array(
				'Name'    => ucfirst( $slug ),
				'Version' => '1.0.0',
				'Author'  => 'Someone',
			),
			$headers
		);

		Targets::reset();

		return Targets::get( 'plugin:' . $slug );
	}

	/**
	 * Installs a single-file plugin directly in the plugins directory.
	 *
	 * @param string $file     File name.
	 * @param string $contents File contents.
	 * @return array<string, mixed> The target.
	 */
	protected function add_single_file_plugin( $file, $contents ) {
		Sandbox::write( 'plugins/' . $file, $contents );

		WP::$plugins[ $file ] = array(
			'Name'    => $file,
			'Version' => '1.0.0',
			'Author'  => 'Someone',
		);

		Targets::reset();

		return Targets::get( 'plugin:' . $file );
	}

	/**
	 * Installs a theme.
	 *
	 * @param string                $slug    Directory name.
	 * @param array<string, string> $files   File contents keyed by path inside the theme.
	 * @param array<string, string> $headers Theme headers.
	 * @return array<string, mixed> The target.
	 */
	protected function add_theme( $slug, array $files = array(), array $headers = array() ) {
		if ( empty( $files ) ) {
			$files = array( 'functions.php' => "<?php\n" );
		}

		foreach ( $files as $path => $contents ) {
			Sandbox::write( 'themes/' . $slug . '/' . $path, $contents );
		}

		WP::$themes[ $slug ] = new Fake_Theme(
			array_merge(
				array(
					'Name'    => ucfirst( $slug ),
					'Version' => '1.0.0',
					'Author'  => 'Someone',
				),
				$headers
			),
			WP_CONTENT_DIR . '/themes/' . $slug
		);

		Targets::reset();

		return Targets::get( 'theme:' . $slug );
	}

	/**
	 * Installs a loose must-use plugin file.
	 *
	 * @param string                $file     File name.
	 * @param string                $contents File contents.
	 * @param array<string, string> $headers  Plugin headers.
	 * @return array<string, mixed> The target.
	 */
	protected function add_mu_plugin( $file, $contents = "<?php\n", array $headers = array() ) {
		Sandbox::write( 'mu-plugins/' . $file, $contents );

		WP::$mu_plugins[ $file ] = array_merge(
			array(
				'Name'    => '',
				'Version' => '',
				'Author'  => '',
			),
			$headers
		);

		Targets::reset();

		return Targets::get( 'mu-plugin:' . $file );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scanning
	 * ------------------------------------------------------------------
	 */

	/**
	 * Scans a snippet of PHP and returns the findings.
	 *
	 * @param string $source PHP source, including the open tag.
	 * @param string $name   File name shown in the findings.
	 * @return array<int, array<string, mixed>> The findings.
	 */
	protected function scan_source( $source, $name = 'snippet.php' ) {
		$path = Sandbox::write( 'scratch/' . $name, $source );

		return ( new Code_Scanner() )->scan_file( $path, $name );
	}

	/**
	 * Scans a snippet and returns the ids of the rules it triggered.
	 *
	 * @param string $source PHP source, including the open tag.
	 * @return string[] Rule ids, in finding order.
	 */
	protected function rules_in( $source ) {
		return array_column( $this->scan_source( $source ), 'rule' );
	}

	/**
	 * Returns the findings of one rule from a snippet.
	 *
	 * @param string $source  PHP source.
	 * @param string $rule_id The rule.
	 * @return array<int, array<string, mixed>> The matching findings.
	 */
	protected function findings_of( $source, $rule_id ) {
		return array_values(
			array_filter(
				$this->scan_source( $source ),
				static function ( $finding ) use ( $rule_id ) {
					return $finding['rule'] === $rule_id;
				}
			)
		);
	}

	/**
	 * Builds a finding as the code scanner would.
	 *
	 * @param string               $rule      Rule id.
	 * @param array<string, mixed> $overrides Values to replace.
	 * @return array<string, mixed> The finding.
	 */
	protected function finding( $rule, array $overrides = array() ) {
		$definition = Rules::get( $rule );

		return array_merge(
			array(
				'rule'         => $rule,
				'file'         => 'plugin.php',
				'line'         => 10,
				'symbol'       => 'symbol',
				'scope'        => '',
				'evidence'     => 'evidence();',
				'note'         => '',
				'severity'     => $definition['severity'],
				'confidence'   => $definition['confidence'],
				'also_matched' => array(),
			),
			$overrides
		);
	}

	/**
	 * Stores a result for a target, summarised the way the scanner does it.
	 *
	 * @param array<string, mixed>             $target   The target.
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @param array<string, mixed>             $extra    Values to replace in the result.
	 * @return array<string, mixed> The stored result.
	 */
	protected function store_result( array $target, array $findings, array $extra = array() ) {
		$scanner = new Scanner();

		$result = array_merge(
			array(
				'key'           => $target['key'],
				'type'          => $target['type'],
				'slug'          => $target['slug'],
				'label'         => $target['label'],
				'findings'      => $findings,
				'summary'       => $this->call_private( $scanner, 'summarise', array( $findings ) ),
				'status'        => $this->call_private( $scanner, 'status', array( $findings ) ),
				'files_scanned' => 3,
				'truncated'     => false,
				'scanned_at'    => time() - 120,
				'rules_version' => Rules::VERSION,
				'fingerprint'   => 'fingerprint',
			),
			$extra
		);

		Results_Store::save( $target['key'], $result );

		return $result;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Calls a private or protected method.
	 *
	 * @param object|string $object A class instance, or a class name for static methods.
	 * @param string        $method The method.
	 * @param array<int, mixed> $args The arguments.
	 * @return mixed The return value.
	 */
	protected function call_private( $object, $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( is_object( $object ) ? $object : null, $args );
	}

	/**
	 * Creates an instance without running its constructor.
	 *
	 * The plugin's classes are singletons, and several hook themselves up in a
	 * private constructor. A fresh instance per test keeps one test's hooks out
	 * of the next.
	 *
	 * @param string $class The class name.
	 * @return object The instance.
	 */
	protected function instance( $class ) {
		return ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Captures what a callback prints.
	 *
	 * @param callable $callback The callback.
	 * @return string The output.
	 */
	protected function render( callable $callback ) {
		ob_start();

		try {
			$callback();
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}
}
