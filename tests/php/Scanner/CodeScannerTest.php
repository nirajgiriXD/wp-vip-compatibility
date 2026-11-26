<?php
/**
 * Tests for the token-based PHP scanner.
 *
 * Every case is real PHP source run through the tokeniser and the full rule
 * set, so these tests cover the scanner, the rule index and the guards working
 * together.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Scanner;

use PREFLIGHT_AUDIT\Includes\Scanner\Code_Scanner;
use PREFLIGHT_AUDIT\Includes\Scanner\Taxonomy;
use PREFLIGHT_AUDIT\Tests\Support\Sandbox;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Scanner\Code_Scanner
 */
class CodeScannerTest extends TestCase {

	/**
	 * Wraps code in a file that is properly guarded, so the missing-guard rule
	 * does not add noise to tests about something else.
	 *
	 * @param string $code PHP statements, without the open tag.
	 * @return string The file source.
	 */
	private function guarded( $code ) {
		return "<?php\ndefined( 'ABSPATH' ) || exit;\n" . $code . "\n";
	}

	/*
	 * ---------------------------------------------------------------------
	 * Telling code apart from prose
	 * ------------------------------------------------------------------
	 */

	public function test_function_names_in_comments_and_strings_are_not_calls() {
		$source = $this->guarded(
			"// exec( 'rm -rf /' );\n"
			. "/* shell_exec( \$cmd ); */\n"
			. "/** Calls eval() somewhere. */\n"
			. "\$doc = 'Never call exec() here.';\n"
		);

		$this->assertSame( array(), $this->rules_in( $source ) );
	}

	public function test_a_method_with_the_same_name_as_a_function_is_not_that_function() {
		$this->assertSame( array(), $this->rules_in( $this->guarded( "\$pdo->exec( 'SELECT 1' );\nFoo::system();" ) ) );
	}

	public function test_function_declarations_are_not_calls() {
		$this->assertNotContains( 'security.command-execution', $this->rules_in( $this->guarded( 'function exec() {}' ) ) );
	}

	public function test_a_namespaced_call_is_not_the_global_function() {
		$this->assertSame( array(), $this->rules_in( $this->guarded( "Vendor\\Shell\\exec( 'ls' );" ) ) );
	}

	public function test_a_fully_qualified_global_call_still_matches() {
		$this->assertContains( 'security.command-execution', $this->rules_in( $this->guarded( "\\exec( 'ls' );" ) ) );
	}

	public function test_function_names_are_matched_case_insensitively() {
		$this->assertContains( 'security.command-execution', $this->rules_in( $this->guarded( "Shell_Exec( 'ls' );" ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * One case per kind of occurrence
	 * ------------------------------------------------------------------
	 */

	/**
	 * @dataProvider detection_cases
	 */
	public function test_detects( $code, $rule_id ) {
		$this->assertContains( $rule_id, $this->rules_in( $this->guarded( $code ) ) );
	}

	public function detection_cases() {
		$cases = array(
			'function call'                 => array( "shell_exec( 'ls' );", 'security.command-execution' ),
			'backtick operator'             => array( '$out = `ls -la`;', 'security.command-execution' ),
			'eval construct'                => array( 'eval( $code );', 'security.dynamic-code' ),
			'unserialize'                   => array( 'unserialize( $data );', 'security.unserialize' ),
			'extract'                       => array( 'extract( $args );', 'security.extract' ),
			'class instantiation'           => array( '$it = new DirectoryIterator( wp_upload_dir()["basedir"] );', 'filesystem.directory-iteration' ),
			'namespaced class instantiation' => array( '$img = new \\Imagick( $file );', 'filesystem.image-manipulation' ),
			'method on $wpdb'               => array( '$wpdb->query( "DELETE FROM t WHERE id = $id" );', 'database.unprepared-query' ),
			'constant reference'            => array( 'if ( DISABLE_WP_CRON ) {}', 'cron.core-constant-override' ),
			'defined() check'               => array( "if ( defined( 'WP_CACHE' ) ) {}", 'caching.custom-page-cache' ),
			'define() of a core constant'   => array( "define( 'WP_CONTENT_DIR', '/srv/content' );", 'environment.core-constant-override' ),
			'hook registration'             => array( "add_filter( 'upload_dir', 'my_dirs' );", 'filesystem.upload-dir-filter' ),
			'action registration'           => array( "add_action( 'cron_schedules', 'x' );", 'cron.custom-schedule' ),
			'superglobal'                   => array( 'session_id(); $_SESSION["a"] = 1;', 'environment.php-sessions' ),
			'$_SERVER key'                  => array( '$root = $_SERVER["DOCUMENT_ROOT"];', 'environment.server-introspection' ),
			'string literal'                => array( "\$f = 'wp-content/blogs.dir/1/files';", 'filesystem.blogs-dir' ),
			'echoed superglobal'            => array( 'echo $_GET["q"];', 'security.unescaped-superglobal-output' ),
			'printed superglobal'           => array( 'print $_POST["q"];', 'security.unescaped-superglobal-output' ),
			'dynamic include'               => array( 'include $_GET["page"] . ".php";', 'security.dynamic-include' ),
			'raw curl'                      => array( '$ch = curl_init( $url );', 'http.raw-curl' ),
			'remote file read'              => array( "file_get_contents( 'https://example.com/feed' );", 'http.remote-file-read' ),
			'socket class'                  => array( '$db = new PDO( $dsn );', 'http.socket-connection' ),
			'cache flush'                   => array( 'wp_cache_flush();', 'caching.cache-flush' ),
			'cookie'                        => array( "setcookie( 'a', 'b' );", 'caching.frontend-cookie' ),
			'deprecated function'           => array( "get_page_by_title( 'About' );", 'standards.deprecated-function' ),
			'rewrite flush'                 => array( 'flush_rewrite_rules();', 'standards.flush-rewrite-rules' ),
			'unsafe redirect'               => array( 'wp_redirect( $url );', 'standards.unsafe-redirect' ),
			'debug output'                  => array( 'var_dump( $x );', 'environment.debug-output' ),
			'direct mail'                   => array( "mail( 'a@b.c', 's', 'm' );", 'environment.direct-mail' ),
			'ini change'                    => array( "ini_set( 'memory_limit', '1G' );", 'environment.runtime-ini-change' ),
			'unrestored switch'             => array( 'function f() { switch_to_blog( 2 ); }', 'environment.unrestored-switch-to-blog' ),
			'uncached core function'        => array( 'url_to_postid( $url );', 'database.uncached-function' ),
			'unbounded query'               => array( "get_posts( array( 'posts_per_page' => -1 ) );", 'database.unbounded-query' ),
			'expensive query'               => array( "new WP_Query( array( 'orderby' => 'rand' ) );", 'database.expensive-query-arg' ),
			'autoloaded option'             => array( "update_option( 'k', \$v, true );", 'database.autoloaded-option' ),
			'schema change'                 => array( 'dbDelta( $sql );', 'database.schema-change' ),
			'filesystem credentials'        => array( 'WP_Filesystem();', 'filesystem.wp-filesystem-credentials' ),
			'image size'                    => array( "add_image_size( 'hero', 1600, 900 );", 'filesystem.intermediate-image-sizes' ),
			'htaccess string'               => array( "\$rules = '.htaccess';", 'filesystem.apache-assumption' ),
			'page cache drop-in string'     => array( "\$f = 'advanced-cache.php';", 'caching.custom-page-cache' ),
			'manual cron'                   => array( 'spawn_cron();', 'cron.manual-invocation' ),
			'uncached remote request'       => array( 'wp_remote_get( $url );', 'http.uncached-remote-request' ),
		);

		// `?->` only exists from PHP 8; older tokenisers split it into `?` and `->`.
		if ( PHP_VERSION_ID >= 80000 ) {
			$cases['nullsafe method on $wpdb'] = array( '$wpdb?->get_var( "SELECT 1" );', 'database.direct-query' );
		}

		return $cases;
	}

	/**
	 * @dataProvider safe_cases
	 */
	public function test_does_not_report_safe_code( $code, $rule_id ) {
		$this->assertNotContains( $rule_id, $this->rules_in( $this->guarded( $code ) ) );
	}

	public function safe_cases() {
		return array(
			'escaped echo'                => array( 'echo esc_html( $_GET["q"] );', 'security.unescaped-superglobal-output' ),
			'cast echo'                   => array( 'echo (int) $_GET["page"];', 'security.unescaped-superglobal-output' ),
			'static include'              => array( "require __DIR__ . '/inc/a.php';", 'security.dynamic-include' ),
			'prepared query'              => array( '$wpdb->query( $wpdb->prepare( "DELETE FROM t WHERE id = %d", $id ) );', 'database.unprepared-query' ),
			'read-only fopen'             => array( "fopen( __DIR__ . '/data.csv', 'r' );", 'filesystem.fopen-write-mode' ),
			'write to uploads'            => array( "file_put_contents( wp_upload_dir()['basedir'] . '/a.txt', \$d );", 'filesystem.write-outside-writable-paths' ),
			'print_r returning'           => array( '$s = print_r( $x, true );', 'environment.debug-output' ),
			'restored switch'             => array( 'function f() { switch_to_blog( 2 ); restore_current_blog(); }', 'environment.unrestored-switch-to-blog' ),
			'unrelated server key'        => array( '$m = $_SERVER["REQUEST_METHOD"];', 'environment.server-introspection' ),
			'a regex mentioning blogs.dir' => array( "preg_match( '#blogs\\.dir#', \$p );", 'filesystem.blogs-dir' ),
			'bounded query'               => array( "get_posts( array( 'posts_per_page' => 10 ) );", 'database.unbounded-query' ),
			'a bare constant in lower case' => array( '$x = disable_wp_cron;', 'cron.core-constant-override' ),
			'class constant fetch'        => array( '$x = Foo::DISABLE_WP_CRON;', 'cron.core-constant-override' ),
			'property fetch'              => array( '$x = $this->DISABLE_WP_CRON;', 'cron.core-constant-override' ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * The ABSPATH guard rule
	 * ------------------------------------------------------------------
	 */

	public function test_a_file_that_declares_code_without_a_guard_is_reported() {
		$this->assertContains( 'security.missing-abspath-guard', $this->rules_in( "<?php\nfunction my_func() {}\n" ) );
		$this->assertContains( 'security.missing-abspath-guard', $this->rules_in( "<?php\nclass My_Class {}\n" ) );
	}

	/**
	 * @dataProvider guard_constants
	 */
	public function test_any_recognised_guard_satisfies_the_rule( $constant ) {
		$source = "<?php\nif ( ! defined( \"{$constant}\" ) ) { exit; }\nfunction my_func() {}\n";

		$this->assertNotContains( 'security.missing-abspath-guard', $this->rules_in( $source ) );
	}

	public function guard_constants() {
		return array( array( 'ABSPATH' ), array( 'WPINC' ), array( 'WP_UNINSTALL_PLUGIN' ) );
	}

	public function test_a_file_that_declares_nothing_needs_no_guard() {
		$this->assertNotContains( 'security.missing-abspath-guard', $this->rules_in( "<?php\nreturn array( 'a' => 1 );\n" ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Findings
	 * ------------------------------------------------------------------
	 */

	public function test_a_finding_records_where_and_what() {
		$source = $this->guarded( "function my_runner( \$cmd ) {\n\treturn shell_exec( \$cmd );\n}" );

		$finding = $this->findings_of( $source, 'security.command-execution' )[0];

		$this->assertSame( 'snippet.php', $finding['file'] );
		$this->assertSame( 4, $finding['line'] );
		$this->assertSame( 'shell_exec', $finding['symbol'] );
		$this->assertSame( 'my_runner', $finding['scope'] );
		$this->assertSame( 'return shell_exec( $cmd );', $finding['evidence'] );
		$this->assertSame( Taxonomy::SEVERITY_CRITICAL, $finding['severity'] );
		$this->assertSame( array(), $finding['also_matched'] );
	}

	public function test_a_closure_is_its_own_scope() {
		$finding = $this->findings_of( $this->guarded( '$f = function () { exec( "ls" ); };' ), 'security.command-execution' )[0];

		$this->assertSame( '{closure}', $finding['scope'] );
	}

	public function test_a_method_is_its_own_scope() {
		$source = $this->guarded( "class Runner {\n\tpublic function go() {\n\t\texec( 'ls' );\n\t}\n}" );

		$this->assertSame( 'go', $this->findings_of( $source, 'security.command-execution' )[0]['scope'] );
	}

	public function test_guard_overrides_replace_the_rule_defaults() {
		$finding = $this->findings_of( $this->guarded( "\$fh = fopen( __DIR__ . '/out.log', \$mode );" ), 'filesystem.fopen-write-mode' )[0];

		$this->assertSame( Taxonomy::CONFIDENCE_LOW, $finding['confidence'] );
		$this->assertNotSame( '', $finding['note'] );
	}

	public function test_long_evidence_lines_are_truncated() {
		$long    = str_repeat( 'a', 300 );
		$finding = $this->findings_of( $this->guarded( "exec( '{$long}' );" ), 'security.command-execution' )[0];

		$this->assertSame( 240, strlen( $finding['evidence'] ) );
		$this->assertStringEndsWith( '...', $finding['evidence'] );
	}

	public function test_two_rules_on_one_line_in_one_category_collapse_into_the_worst() {
		// Both a write outside the writable paths (high) and a generated PHP
		// file (critical): one defect, reported once.
		$source   = $this->guarded( "file_put_contents( __DIR__ . '/cache.php', \$code );" );
		$findings = array_values(
			array_filter(
				$this->scan_source( $source ),
				static function ( $finding ) {
					return 0 === strpos( $finding['rule'], 'filesystem.' );
				}
			)
		);

		$this->assertCount( 1, $findings );
		$this->assertSame( 'filesystem.generated-code-asset', $findings[0]['rule'] );
		$this->assertSame( array( 'filesystem.write-outside-writable-paths' ), $findings[0]['also_matched'] );
	}

	public function test_a_less_severe_rule_on_the_same_line_is_recorded_against_the_kept_one() {
		// The unprepared query is critical; the direct query is medium.
		$source   = $this->guarded( '$wpdb->query( "DELETE FROM t WHERE id = $id" );' );
		$findings = $this->scan_source( $source );
		$rules    = array_column( $findings, 'rule' );

		$this->assertContains( 'database.unprepared-query', $rules );
		$this->assertNotContains( 'database.direct-query', $rules );

		$kept = $findings[ array_search( 'database.unprepared-query', $rules, true ) ];
		$this->assertContains( 'database.direct-query', $kept['also_matched'] );
	}

	public function test_the_same_rule_twice_on_one_line_is_reported_once() {
		$findings = $this->findings_of( $this->guarded( "exec( 'a' ); exec( 'b' );" ), 'security.command-execution' );

		$this->assertCount( 1, $findings );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Suppression annotations
	 * ------------------------------------------------------------------
	 */

	public function test_an_annotation_on_the_line_suppresses_that_rule() {
		$source = $this->guarded( "\$x = file_get_contents( 'https://example.com' ); // pfa:ignore http.remote-file-read -- fixture URL" );

		$this->assertNotContains( 'http.remote-file-read', $this->rules_in( $source ) );
	}

	public function test_an_annotation_on_the_previous_line_suppresses_the_next_line() {
		$source = $this->guarded( "// pfa:ignore-next-line http.remote-file-read -- fixture URL\n\$x = file_get_contents( 'https://example.com' );" );

		$this->assertNotContains( 'http.remote-file-read', $this->rules_in( $source ) );
	}

	public function test_a_wildcard_annotation_suppresses_every_rule() {
		$source = $this->guarded( "\$x = curl_init( 'https://example.com' ); // pfa:ignore * -- not executed" );

		$this->assertNotContains( 'http.raw-curl', $this->rules_in( $source ) );
	}

	public function test_an_annotation_can_list_several_rules() {
		$source = $this->guarded( "update_option( 'k', \$v, true ); flush_rewrite_rules(); // pfa:ignore database.autoloaded-option, standards.flush-rewrite-rules -- activation only" );
		$rules  = $this->rules_in( $source );

		$this->assertNotContains( 'database.autoloaded-option', $rules );
		$this->assertNotContains( 'standards.flush-rewrite-rules', $rules );
	}

	public function test_an_annotation_without_a_reason_is_ignored() {
		$source = $this->guarded( "curl_init( \$url ); // pfa:ignore http.raw-curl" );

		$this->assertContains( 'http.raw-curl', $this->rules_in( $source ) );
	}

	public function test_an_annotation_only_covers_the_rule_it_names() {
		$source = $this->guarded( "curl_init( \$url ); // pfa:ignore http.remote-file-read -- wrong rule" );

		$this->assertContains( 'http.raw-curl', $this->rules_in( $source ) );
	}

	public function test_an_annotation_on_another_line_does_not_apply() {
		$source = $this->guarded( "// pfa:ignore http.raw-curl -- this line only\ncurl_init( \$url );" );

		$this->assertContains( 'http.raw-curl', $this->rules_in( $source ) );
	}

	public function test_security_findings_cannot_be_suppressed() {
		$source = $this->guarded( "exec( \$cmd ); // pfa:ignore * -- trust me" );

		$this->assertContains( 'security.command-execution', $this->rules_in( $source ) );
	}

	public function test_the_annotation_is_case_insensitive() {
		$source = $this->guarded( "curl_init( \$url ); // PFA:IGNORE http.raw-curl -- upper case" );

		$this->assertNotContains( 'http.raw-curl', $this->rules_in( $source ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Files the scanner refuses to read
	 * ------------------------------------------------------------------
	 */

	public function test_a_missing_file_produces_nothing() {
		$this->assertSame( array(), ( new Code_Scanner() )->scan_file( WP_CONTENT_DIR . '/nope.php', 'nope.php' ) );
	}

	public function test_an_empty_file_produces_nothing() {
		$this->assertSame( array(), $this->scan_source( '' ) );
	}

	public function test_a_file_larger_than_the_limit_is_skipped() {
		$source = "<?php\nexec( 'ls' );\n" . str_repeat( '//', Code_Scanner::MAX_FILE_BYTES );

		$this->assertSame( array(), $this->scan_source( $source ) );
	}

	public function test_a_file_that_does_not_parse_does_not_raise_a_notice() {
		$findings = $this->scan_source( "<?php\nfunction ( { exec( 'ls' ); \n\"unterminated" );

		$this->assertIsArray( $findings );
	}

	public function test_html_outside_php_tags_is_not_code() {
		$source = "<p>Run shell_exec( 'ls' ) to list files.</p>\n<?php echo esc_html( \$title ); ?>\n";

		$this->assertNotContains( 'security.command-execution', $this->rules_in( $source ) );
	}

	public function test_a_scan_reads_the_file_from_disk_under_its_reported_name() {
		$path = Sandbox::write( 'plugins/x/inc/a.php', $this->guarded( 'exec( "ls" );' ) );

		$findings = ( new Code_Scanner() )->scan_file( $path, 'inc/a.php' );

		$this->assertSame( 'inc/a.php', $findings[0]['file'] );
	}
}
