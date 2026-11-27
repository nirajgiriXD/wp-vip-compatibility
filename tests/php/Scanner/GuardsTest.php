<?php
/**
 * Tests for the context-aware rule guards.
 *
 * Each guard gets an occurrence as the code scanner builds it, and answers
 * false (not a finding), true (a finding as the rule describes it), or an
 * array of overrides. The providers below list the shapes of real code the
 * guards were written for.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Scanner;

use PREFLIGHT_AUDIT\Includes\Scanner\Guards;
use PREFLIGHT_AUDIT\Includes\Scanner\Taxonomy;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Scanner\Guards
 */
class GuardsTest extends TestCase {

	/**
	 * Builds an occurrence.
	 *
	 * @param string               $name      The function or method name.
	 * @param string[]             $args      Split arguments.
	 * @param array<string, mixed> $overrides Other occurrence keys.
	 * @return array<string, mixed> The occurrence.
	 */
	private function occurrence( $name, array $args = array(), array $overrides = array() ) {
		return array_merge(
			array(
				'kind'         => 'function',
				'name'         => $name,
				'line'         => 1,
				'args'         => $args,
				'args_raw'     => implode( ',', $args ),
				'scope'        => null,
				'scope_name'   => '',
				'scope_source' => '',
				'file_flags'   => array(),
				'key'          => '',
			),
			$overrides
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------
	 */

	/**
	 * @dataProvider writable_targets
	 */
	public function test_writes_to_writable_paths_are_not_reported( $name, array $args, $scope = '' ) {
		$this->assertFalse(
			Guards::write_target_is_not_writable( $this->occurrence( $name, $args, array( 'scope_source' => $scope ) ) )
		);
	}

	public function writable_targets() {
		return array(
			'uploads dir helper'           => array( 'file_put_contents', array( "wp_upload_dir()['basedir'] . '/x.txt'", '$data' ) ),
			'temp dir helper'              => array( 'file_put_contents', array( "get_temp_dir() . 'x'", '$data' ) ),
			'literal /tmp path'            => array( 'unlink', array( "'/tmp/cache.txt'" ) ),
			'variable named after uploads' => array( 'mkdir', array( '$uploads_path' ) ),
			'variable named tmp'           => array( 'touch', array( '$tmp_file' ) ),
			'resolved through one hop'     => array( 'file_put_contents', array( '$path', '$data' ), '$path = wp_get_upload_dir ( ) [ \'basedir\' ] . \'/x\' ;' ),
			'resolved through two hops'    => array( 'file_put_contents', array( '$file', '$data' ), '$dir = wp_upload_dir ( ) ; $file = $dir [ \'basedir\' ] . \'/x\' ;' ),
			'destination is the 2nd arg'   => array( 'copy', array( 'WP_PLUGIN_DIR . "/a"', 'get_temp_dir() . "b"' ) ),
			'handle writes are attributed to fopen' => array( 'fwrite', array( '$handle', '$data' ) ),
			'no argument at all'           => array( 'unlink', array() ),
		);
	}

	public function test_a_write_into_the_application_directory_is_reported_with_high_confidence() {
		$verdict = Guards::write_target_is_not_writable(
			$this->occurrence( 'file_put_contents', array( "plugin_dir_path( __FILE__ ) . 'cache.json'", '$data' ) )
		);

		$this->assertSame( Taxonomy::CONFIDENCE_HIGH, $verdict['confidence'] );
		$this->assertStringContainsString( 'read-only', $verdict['note'] );
	}

	public function test_an_unresolvable_write_is_reported_softly() {
		$verdict = Guards::write_target_is_not_writable( $this->occurrence( 'unlink', array( '$somewhere' ) ) );

		$this->assertSame( Taxonomy::CONFIDENCE_LOW, $verdict['confidence'] );
		$this->assertSame( Taxonomy::SEVERITY_MEDIUM, $verdict['severity'] );
	}

	public function test_rename_is_judged_by_its_destination() {
		$verdict = Guards::write_target_is_not_writable(
			$this->occurrence( 'rename', array( '$tmp_source', 'WP_CONTENT_DIR . "/moved"' ) )
		);

		$this->assertSame( Taxonomy::CONFIDENCE_HIGH, $verdict['confidence'] );
	}

	/**
	 * @dataProvider fopen_cases
	 */
	public function test_fopen( array $args, $expected ) {
		$verdict = Guards::fopen_writes_outside_writable( $this->occurrence( 'fopen', $args ) );

		if ( false === $expected ) {
			$this->assertFalse( $verdict );
			return;
		}

		$this->assertSame( $expected, $verdict['confidence'] );
	}

	public function fopen_cases() {
		return array(
			'read mode is fine'                   => array( array( '__DIR__ . "/data.csv"', "'r'" ), false ),
			'read binary mode is fine'            => array( array( '__DIR__ . "/data.csv"', "'rb'" ), false ),
			'write into the plugin'               => array( array( '__DIR__ . "/out.csv"', "'w'" ), Taxonomy::CONFIDENCE_HIGH ),
			'append into the plugin'              => array( array( '__DIR__ . "/out.log"', "'a+'" ), Taxonomy::CONFIDENCE_HIGH ),
			'write into uploads'                  => array( array( 'wp_upload_dir()["basedir"] . "/out.csv"', "'w'" ), false ),
			'unknown mode is only a guess'        => array( array( '__DIR__ . "/out.csv"', '$mode' ), Taxonomy::CONFIDENCE_LOW ),
			'unknown mode on a writable path'     => array( array( '"/tmp/x"', '$mode' ), false ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Directory traversal and generated assets
	 * ------------------------------------------------------------------
	 */

	public function test_iterating_uploads_is_reported_with_high_confidence() {
		$verdict = Guards::iterates_uploads( $this->occurrence( 'scandir', array( "wp_upload_dir()['basedir']" ) ) );
		$this->assertSame( Taxonomy::CONFIDENCE_HIGH, $verdict['confidence'] );

		$verdict = Guards::iterates_uploads( $this->occurrence( 'glob', array( "'/var/www/wp-content/uploads/*.jpg'" ) ) );
		$this->assertSame( Taxonomy::CONFIDENCE_HIGH, $verdict['confidence'] );
	}

	public function test_iterating_the_application_directory_is_fine() {
		$this->assertFalse( Guards::iterates_uploads( $this->occurrence( 'scandir', array( '__DIR__ . "/templates"' ) ) ) );
		$this->assertFalse( Guards::iterates_uploads( $this->occurrence( 'opendir', array() ) ) );
	}

	public function test_iterating_an_unknown_directory_is_a_weak_signal() {
		$verdict = Guards::iterates_uploads( $this->occurrence( 'glob', array( '$pattern' ) ) );

		$this->assertSame( Taxonomy::CONFIDENCE_LOW, $verdict['confidence'] );
		$this->assertSame( Taxonomy::SEVERITY_LOW, $verdict['severity'] );
	}

	/**
	 * @dataProvider code_asset_cases
	 */
	public function test_generated_code_assets( $name, array $args, $severity ) {
		$verdict = Guards::writes_code_asset( $this->occurrence( $name, $args ) );

		if ( false === $severity ) {
			$this->assertFalse( $verdict );
			return;
		}

		$this->assertSame( $severity, $verdict['severity'] );
		$this->assertSame( Taxonomy::CONFIDENCE_HIGH, $verdict['confidence'] );
	}

	public function code_asset_cases() {
		return array(
			'generated php is critical'     => array( 'file_put_contents', array( '$dir . "/cache.php"', '$code' ), Taxonomy::SEVERITY_CRITICAL ),
			'generated phtml is critical'   => array( 'file_put_contents', array( '"/x/view.phtml"', '$code' ), Taxonomy::SEVERITY_CRITICAL ),
			'generated css is high'         => array( 'file_put_contents', array( '$dir . "/style.css"', '$css' ), Taxonomy::SEVERITY_HIGH ),
			'generated json is high'        => array( 'copy', array( '$a', '$dir . "/data.json"' ), Taxonomy::SEVERITY_HIGH ),
			'a text file is not an asset'   => array( 'file_put_contents', array( '$dir . "/notes.txt"', '$text' ), false ),
			'fopen for reading a php file'  => array( 'fopen', array( '__DIR__ . "/a.php"', "'r'" ), false ),
			'fopen for writing a js file'   => array( 'fopen', array( '__DIR__ . "/a.js"', "'w'" ), Taxonomy::SEVERITY_HIGH ),
			'handle based write is skipped' => array( 'fwrite', array( '$fh', '"x.php"' ), false ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------
	 */

	/**
	 * @dataProvider sql_cases
	 */
	public function test_unprepared_sql( $sql, $scope, $expected ) {
		$verdict = Guards::sql_is_unprepared(
			$this->occurrence( 'wpdb->query', array( $sql ), array( 'kind' => 'method', 'scope_source' => $scope ) )
		);

		if ( false === $expected ) {
			$this->assertFalse( $verdict );
			return;
		}

		$this->assertSame( $expected, $verdict['confidence'] );
	}

	public function sql_cases() {
		return array(
			'prepared inline'                      => array( '$wpdb->prepare( "SELECT * FROM t WHERE id = %d", $id )', '', false ),
			'static literal'                       => array( '"SELECT COUNT(*) FROM wp_posts"', '', false ),
			'interpolated double quotes'           => array( '"SELECT * FROM t WHERE id = $id"', '', Taxonomy::CONFIDENCE_HIGH ),
			'heredoc'                              => array( '<<<SQL', '', Taxonomy::CONFIDENCE_HIGH ),
			'literal concatenated with a variable' => array( '"SELECT * FROM t WHERE id = " . $id', '', Taxonomy::CONFIDENCE_HIGH ),
			'variable concatenated with a literal' => array( '$base . " WHERE 1"', '', Taxonomy::CONFIDENCE_HIGH ),
			'bare variable, prepared in scope'     => array( '$sql', '$sql = $wpdb -> prepare ( "..." , $id ) ;', false ),
			'bare variable, never prepared'        => array( '$sql', '$sql = "SELECT 1" ;', Taxonomy::CONFIDENCE_MEDIUM ),
			'no argument'                          => array( '', '', false ),
		);
	}

	public function test_direct_writes_are_reported_as_low_severity() {
		$verdict = Guards::query_is_uncached( $this->occurrence( 'wpdb->insert', array(), array( 'kind' => 'method' ) ) );

		$this->assertSame( Taxonomy::SEVERITY_LOW, $verdict['severity'] );
	}

	public function test_a_query_cached_in_its_own_function_is_fine() {
		$occurrence = $this->occurrence(
			'wpdb->get_results',
			array( '"SELECT 1"' ),
			array(
				'kind'         => 'method',
				'scope_source' => '$rows = wp_cache_get ( "k" ) ; $rows = $wpdb -> get_results ( "SELECT 1" ) ;',
			)
		);

		$this->assertFalse( Guards::query_is_uncached( $occurrence ) );
	}

	public function test_a_query_in_a_file_that_caches_elsewhere_is_a_weak_signal() {
		$occurrence = $this->occurrence(
			'wpdb->get_results',
			array( '"SELECT 1"' ),
			array(
				'kind'       => 'method',
				'file_flags' => array( 'cache' => true ),
			)
		);

		$verdict = Guards::query_is_uncached( $occurrence );

		$this->assertSame( Taxonomy::CONFIDENCE_LOW, $verdict['confidence'] );
	}

	public function test_an_uncached_query_is_reported_as_the_rule_describes() {
		$this->assertTrue( Guards::query_is_uncached( $this->occurrence( 'wpdb->get_var', array( '"SELECT 1"' ) ) ) );
	}

	/**
	 * @dataProvider unbounded_cases
	 */
	public function test_unbounded_queries( $args_raw, $reported ) {
		$verdict = Guards::query_is_unbounded( $this->occurrence( 'get_posts', array(), array( 'args_raw' => $args_raw ) ) );

		if ( $reported ) {
			$this->assertIsArray( $verdict );
			$this->assertNotSame( '', $verdict['note'] );
		} else {
			$this->assertFalse( $verdict );
		}
	}

	public function unbounded_cases() {
		return array(
			'posts_per_page -1'      => array( "array( 'posts_per_page' => -1 )", true ),
			'numberposts - 1'        => array( "array( 'numberposts' => - 1 )", true ),
			'number -1 for users'    => array( 'array( "number" => -1 )', true ),
			'nopaging'               => array( "array( 'nopaging' => true )", true ),
			'bounded'                => array( "array( 'posts_per_page' => 10 )", false ),
			'nopaging false'         => array( "array( 'nopaging' => false )", false ),
		);
	}

	public function test_expensive_query_arguments_are_listed() {
		$verdict = Guards::query_uses_expensive_args(
			$this->occurrence(
				'get_posts',
				array(),
				array( 'args_raw' => "array( 'post__not_in' => \$ids, 'orderby' => 'rand', 'meta_query' => array() )" )
			)
		);

		$this->assertStringContainsString( 'post__not_in', $verdict['note'] );
		$this->assertStringContainsString( 'orderby => rand', $verdict['note'] );
		$this->assertStringContainsString( 'meta_query', $verdict['note'] );

		$this->assertFalse(
			Guards::query_uses_expensive_args( $this->occurrence( 'get_posts', array(), array( 'args_raw' => "array( 'orderby' => 'date' )" ) ) )
		);
	}

	/**
	 * @dataProvider autoload_cases
	 */
	public function test_autoloaded_options( $name, array $args, $expected ) {
		$this->assertSame( $expected, Guards::option_is_autoloaded( $this->occurrence( $name, $args ) ) );
	}

	public function autoload_cases() {
		return array(
			'update_option autoload true'     => array( 'update_option', array( "'k'", '$v', 'true' ), true ),
			'update_option autoload TRUE'     => array( 'update_option', array( "'k'", '$v', 'TRUE' ), true ),
			'update_option autoload "yes"'    => array( 'update_option', array( "'k'", '$v', "'yes'" ), true ),
			'update_option autoload false'    => array( 'update_option', array( "'k'", '$v', 'false' ), false ),
			'update_option autoload "no"'     => array( 'update_option', array( "'k'", '$v', "'no'" ), false ),
			'update_option default autoload'  => array( 'update_option', array( "'k'", '$v' ), false ),
			'add_option reads the 4th arg'    => array( 'add_option', array( "'k'", '$v', "''", "'yes'" ), true ),
			'add_option 3rd arg is ignored'   => array( 'add_option', array( "'k'", '$v', "'yes'" ), false ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * HTTP and caching
	 * ------------------------------------------------------------------
	 */

	/**
	 * @dataProvider remote_url_cases
	 */
	public function test_remote_urls( $argument, $expected ) {
		$verdict = Guards::argument_is_remote_url( $this->occurrence( 'file_get_contents', array( $argument ) ) );

		if ( false === $expected ) {
			$this->assertFalse( $verdict );
			return;
		}

		$this->assertSame( $expected, $verdict['confidence'] );
	}

	public function remote_url_cases() {
		return array(
			'literal https url'    => array( "'https://api.example.com/feed'", Taxonomy::CONFIDENCE_DEFINITIVE ),
			'literal http url'     => array( '"http://example.com"', Taxonomy::CONFIDENCE_DEFINITIVE ),
			'url helper'           => array( "home_url( '/feed' )", Taxonomy::CONFIDENCE_HIGH ),
			'local file'           => array( '__DIR__ . "/data.json"', false ),
			'unknown variable'     => array( '$source', false ),
		);
	}

	/**
	 * @dataProvider remote_request_cases
	 */
	public function test_remote_requests( $scope, $args_raw, $expected ) {
		$verdict = Guards::remote_request_is_uncached(
			$this->occurrence( 'wp_remote_get', array(), array( 'scope_source' => $scope, 'args_raw' => $args_raw ) )
		);

		if ( is_bool( $expected ) ) {
			$this->assertSame( $expected, $verdict );
			return;
		}

		$this->assertSame( $expected, $verdict['severity'] ?? 'rule default' );
	}

	public function remote_request_cases() {
		$cached = '$body = get_transient ( "k" ) ;';

		return array(
			'cached with a timeout'      => array( $cached, "\$url, array( 'timeout' => 3 )", false ),
			'cached without a timeout'   => array( $cached, '$url', Taxonomy::SEVERITY_LOW ),
			'uncached without a timeout' => array( '', '$url', 'rule default' ),
			'uncached with a timeout'    => array( '', "\$url, array( 'timeout' => 3 )", true ),
		);
	}

	/**
	 * @dataProvider header_cases
	 */
	public function test_cache_busting_headers( $name, $args_raw, array $flags, $expected ) {
		$verdict = Guards::sends_cache_busting_header(
			$this->occurrence( $name, array(), array( 'args_raw' => $args_raw, 'file_flags' => $flags ) )
		);

		if ( false === $expected ) {
			$this->assertFalse( $verdict );
			return;
		}

		$this->assertSame( $expected, $verdict['confidence'] );
	}

	public function header_cases() {
		return array(
			'nocache_headers on the front end'    => array( 'nocache_headers', '', array(), Taxonomy::CONFIDENCE_HIGH ),
			'nocache_headers in admin code'       => array( 'nocache_headers', '', array( 'admin' => true ), false ),
			'no-store header'                     => array( 'header', "'Cache-Control: no-store'", array(), Taxonomy::CONFIDENCE_HIGH ),
			'max-age=0 header'                    => array( 'header', "'Cache-Control: max-age=0'", array(), Taxonomy::CONFIDENCE_HIGH ),
			'hand-set cache header'               => array( 'header', "'Cache-Control: public, max-age=600'", array(), Taxonomy::CONFIDENCE_LOW ),
			'unrelated header'                    => array( 'header', "'Content-Type: text/plain'", array(), false ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Strings, schema, includes, debug, multisite
	 * ------------------------------------------------------------------
	 */

	/**
	 * @dataProvider literal_cases
	 */
	public function test_string_is_literal_value( $value, $expected ) {
		$this->assertSame( $expected, Guards::string_is_literal_value( $this->occurrence( $value, array(), array( 'kind' => 'string' ) ) ) );
	}

	public function literal_cases() {
		return array(
			'real path'                  => array( '/var/www/wp-content/uploads/2024/photo.jpg', true ),
			'escaped regex'              => array( 'blogs\.dir', false ),
			'delimited regex'            => array( '#wp-content/uploads#i', false ),
			'alternation'                => array( 'advanced-cache|object-cache', false ),
			'character class'            => array( 'wp-[a-z]+', false ),
			'prose mentioning a path'    => array( 'Files live in wp-content/uploads on the server', false ),
			'short phrase with a path'   => array( 'see wp-content/uploads', true ),
			'slash-delimited regex'      => array( '/uploads/i', false ),
			'regex with escaped slashes' => array( '/wp-content\/uploads/', false ),
		);
	}

	/**
	 * A path that starts and ends with a slash is a path, not a `/.../` regex.
	 */
	public function test_a_path_wrapped_in_slashes_is_a_literal_value() {
		$this->assertTrue(
			Guards::string_is_literal_value( $this->occurrence( '/wp-content/uploads/2024/', array(), array( 'kind' => 'string' ) ) )
		);
	}

	public function test_schema_changes() {
		$dbdelta = Guards::issues_schema_change( $this->occurrence( 'dbDelta', array( '$sql' ) ) );
		$this->assertSame( Taxonomy::CONFIDENCE_DEFINITIVE, $dbdelta['confidence'] );

		$alter = Guards::issues_schema_change(
			$this->occurrence( 'wpdb->query', array( '"ALTER  TABLE t ADD c INT"' ), array( 'args_raw' => '"ALTER  TABLE t ADD c INT"' ) )
		);
		$this->assertSame( Taxonomy::CONFIDENCE_HIGH, $alter['confidence'] );
		$this->assertStringContainsString( 'ALTER TABLE', $alter['note'] );

		$in_scope = Guards::issues_schema_change(
			$this->occurrence( 'wpdb->query', array( '$sql' ), array( 'args_raw' => '$sql', 'scope_source' => '$sql = "DROP TABLE x" ;' ) )
		);
		$this->assertSame( Taxonomy::CONFIDENCE_MEDIUM, $in_scope['confidence'] );

		$this->assertFalse(
			Guards::issues_schema_change( $this->occurrence( 'wpdb->query', array( '"SELECT 1"' ), array( 'args_raw' => '"SELECT 1"' ) ) )
		);
	}

	public function test_includes() {
		$anchored = Guards::include_path_is_unanchored(
			$this->occurrence( 'require', array(), array( 'args_raw' => ' __DIR__ . "/" . $file' ) )
		);
		$this->assertSame( Taxonomy::SEVERITY_LOW, $anchored['severity'] );

		$constant = Guards::include_path_is_unanchored(
			$this->occurrence( 'include', array(), array( 'args_raw' => ' MY_PLUGIN_DIR . $file' ) )
		);
		$this->assertSame( Taxonomy::CONFIDENCE_LOW, $constant['confidence'] );

		$this->assertTrue(
			Guards::include_path_is_unanchored( $this->occurrence( 'include', array(), array( 'args_raw' => ' $_GET["page"] . ".php"' ) ) )
		);
	}

	/**
	 * @dataProvider debug_cases
	 */
	public function test_debug_output( $name, array $args, $expected ) {
		$this->assertSame( $expected, Guards::is_debug_output( $this->occurrence( $name, $args ) ) );
	}

	public function debug_cases() {
		return array(
			'var_dump always prints'      => array( 'var_dump', array( '$x' ), true ),
			'print_r prints'              => array( 'print_r', array( '$x' ), true ),
			'print_r returning'           => array( 'print_r', array( '$x', 'true' ), false ),
			'var_export returning with 1' => array( 'var_export', array( '$x', '1' ), false ),
			'var_export printing'         => array( 'var_export', array( '$x', 'false' ), true ),
		);
	}

	public function test_switch_to_blog() {
		$this->assertTrue( Guards::switch_to_blog_unrestored( $this->occurrence( 'switch_to_blog', array( '$id' ) ) ) );

		$this->assertFalse(
			Guards::switch_to_blog_unrestored(
				$this->occurrence( 'switch_to_blog', array( '$id' ), array( 'scope_source' => 'switch_to_blog ( $id ) ; restore_current_blog ( ) ;' ) )
			)
		);

		// At file scope the whole file is the scope.
		$this->assertFalse(
			Guards::switch_to_blog_unrestored(
				$this->occurrence( 'switch_to_blog', array( '$id' ), array( 'file_flags' => array( 'restore' => true ) ) )
			)
		);

		// A restore elsewhere in the file does not count for a switch inside a function.
		$this->assertTrue(
			Guards::switch_to_blog_unrestored(
				$this->occurrence(
					'switch_to_blog',
					array( '$id' ),
					array(
						'scope_source' => 'switch_to_blog ( $id ) ;',
						'file_flags'   => array( 'restore' => true ),
					)
				)
			)
		);
	}
}
