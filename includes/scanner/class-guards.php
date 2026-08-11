<?php
/**
 * Context-aware guards for the rule set.
 *
 * A guard is the difference between "this file calls file_put_contents" and
 * "this file writes to a path that cannot exist on VIP". Rules describe what to
 * look for; guards decide whether a particular occurrence is actually a
 * problem, and how confident the report should sound about it.
 *
 * Every guard receives the occurrence produced by the code scanner and returns
 * either:
 *
 * - `false`                to discard the occurrence entirely,
 * - `true`                 to report it with the rule's own defaults, or
 * - an array of overrides  (`confidence`, `severity`, `note`) to report it with
 *                          the accuracy the evidence actually supports.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a matched occurrence is a genuine finding.
 */
class Guards {

	/**
	 * Functions whose first argument is a stream handle rather than a path.
	 *
	 * These are judged through the fopen() that produced the handle, so
	 * reporting them separately would double-count a single write.
	 *
	 * @var string[]
	 */
	private static $handle_first = array( 'fwrite', 'fputs', 'fputcsv', 'fprintf', 'vfprintf', 'ftruncate' );

	/**
	 * Functions whose write destination is the second argument.
	 *
	 * @var string[]
	 */
	private static $destination_second = array( 'rename', 'copy', 'move_uploaded_file', 'symlink', 'link' );

	/**
	 * Expressions that resolve to a path VIP allows writes to.
	 *
	 * @var string[]
	 */
	private static $writable_markers = array(
		'wp_upload_dir',
		'wp_get_upload_dir',
		'get_temp_dir',
		'sys_get_temp_dir',
		'wp_tempnam',
		'tempnam',
		'upload_basedir',
		'uploads_dir',
		'upload_dir',
		'upload_path',
		'uploadpath',
	);

	/**
	 * Expressions that resolve to a path VIP does not allow writes to.
	 *
	 * @var string[]
	 */
	private static $unwritable_markers = array(
		'plugin_dir_path',
		'plugin_basename',
		'WP_PLUGIN_DIR',
		'WPMU_PLUGIN_DIR',
		'WP_CONTENT_DIR',
		'get_template_directory',
		'get_stylesheet_directory',
		'get_theme_root',
		'ABSPATH',
		'__DIR__',
		'dirname(__FILE__)',
		'dirname( __FILE__ )',
	);

	/**
	 * Cache reads and writes that make a surrounding call acceptable.
	 *
	 * @var string[]
	 */
	private static $cache_markers = array(
		'wp_cache_get',
		'wp_cache_set',
		'wp_cache_add',
		'wp_cache_remember',
		'get_transient',
		'set_transient',
		'get_site_transient',
		'set_site_transient',
		'wp_cache_get_multiple',
	);

	/**
	 * Reports a write whose destination is not one of the writable paths.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function write_target_is_not_writable( $occurrence ) {
		$name = $occurrence['name'];

		// Handle-based writes are attributed to the fopen() that opened them.
		if ( in_array( $name, self::$handle_first, true ) ) {
			return false;
		}

		$index  = in_array( $name, self::$destination_second, true ) ? 1 : 0;
		$target = $occurrence['args'][ $index ] ?? '';

		if ( '' === $target ) {
			return false;
		}

		return self::classify_write_target( self::resolve( $target, $occurrence['scope_source'] ) );
	}

	/**
	 * Reports fopen() calls that open a file for writing outside writable paths.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function fopen_writes_outside_writable( $occurrence ) {
		$mode = self::literal( $occurrence['args'][1] ?? '' );

		// A read-only handle is perfectly fine on the VIP filesystem.
		if ( null !== $mode && ! preg_match( '/[waxc+]/i', $mode ) ) {
			return false;
		}

		$result = self::classify_write_target( self::resolve( $occurrence['args'][0] ?? '', $occurrence['scope_source'] ) );

		if ( false === $result ) {
			return false;
		}

		// An unresolvable mode means we are guessing that this is a write at all.
		if ( null === $mode && is_array( $result ) ) {
			$result['confidence'] = Taxonomy::CONFIDENCE_LOW;
		}

		return $result;
	}

	/**
	 * Reports directory traversal aimed at the uploads directory.
	 *
	 * Listing a plugin's own directory is fine on VIP: application files are
	 * present and readable, they are just not writable. Only the uploads object
	 * store lacks a real directory tree, so only that case is reported.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function iterates_uploads( $occurrence ) {
		$target = self::resolve( $occurrence['args'][0] ?? '', $occurrence['scope_source'] );

		if ( '' === $target ) {
			return false;
		}

		if ( self::mentions( $target, self::$writable_markers ) || false !== stripos( $target, 'uploads' ) ) {
			return array(
				'confidence' => Taxonomy::CONFIDENCE_HIGH,
				'note'       => __( 'The traversal target resolves to the uploads directory, which has no real directory structure on VIP.', 'wp-vip-compatibility' ),
			);
		}

		// A path anchored to the application directory lists normally on VIP.
		if ( self::mentions( $target, self::$unwritable_markers ) ) {
			return false;
		}

		return array(
			'confidence' => Taxonomy::CONFIDENCE_LOW,
			'severity'   => Taxonomy::SEVERITY_LOW,
			'note'       => __( 'The traversal target could not be resolved statically. Confirm it never points at the uploads directory.', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Reports writes that generate a PHP, CSS, JS, HTML or JSON file.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function writes_code_asset( $occurrence ) {
		$name = $occurrence['name'];

		if ( in_array( $name, self::$handle_first, true ) ) {
			return false;
		}

		$index  = in_array( $name, self::$destination_second, true ) ? 1 : 0;
		$target = self::resolve( $occurrence['args'][ $index ] ?? '', $occurrence['scope_source'] );

		if ( ! preg_match( '/\.(php|phtml|css|js|mjs|html|htm|json)\b/i', $target, $matches ) ) {
			return false;
		}

		// fopen() only generates a file when it is opened for writing.
		if ( 'fopen' === $name ) {
			$mode = self::literal( $occurrence['args'][1] ?? '' );

			if ( null !== $mode && ! preg_match( '/[waxc+]/i', $mode ) ) {
				return false;
			}
		}

		$extension = strtolower( $matches[1] );
		$executable = in_array( $extension, array( 'php', 'phtml' ), true );

		return array(
			'severity'   => $executable ? Taxonomy::SEVERITY_CRITICAL : Taxonomy::SEVERITY_HIGH,
			'confidence' => Taxonomy::CONFIDENCE_HIGH,
			/* translators: %s: File extension, for example "css". */
			'note'       => sprintf( __( 'The write target is a .%s file.', 'wp-vip-compatibility' ), $extension ),
		);
	}

	/**
	 * Reports $wpdb queries whose SQL is interpolated rather than prepared.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function sql_is_unprepared( $occurrence ) {
		$sql = $occurrence['args'][0] ?? '';

		if ( '' === $sql ) {
			return false;
		}

		// The query is prepared inline: the safe, idiomatic form.
		if ( false !== stripos( $sql, 'prepare' ) ) {
			return false;
		}

		// A double-quoted or heredoc string carrying a variable.
		if ( preg_match( '/"[^"]*\$[A-Za-z_]/', $sql ) || preg_match( '/<<</', $sql ) ) {
			return array( 'confidence' => Taxonomy::CONFIDENCE_HIGH );
		}

		// Concatenation of a literal with a variable.
		if ( preg_match( '/[\'"]\s*\.\s*\$/', $sql ) || preg_match( '/\$[A-Za-z_][A-Za-z0-9_]*\s*\.\s*[\'"]/', $sql ) ) {
			return array( 'confidence' => Taxonomy::CONFIDENCE_HIGH );
		}

		// A bare variable: safe only if the surrounding function prepared it.
		if ( preg_match( '/^\s*\$[A-Za-z_][A-Za-z0-9_]*\s*$/', $sql ) ) {
			if ( false !== stripos( $occurrence['scope_source'], 'prepare' ) ) {
				return false;
			}

			return array(
				'confidence' => Taxonomy::CONFIDENCE_MEDIUM,
				'note'       => __( 'The SQL is held in a variable and no $wpdb->prepare() call appears in the same function.', 'wp-vip-compatibility' ),
			);
		}

		// Anything else is a static literal with no interpolation.
		return false;
	}

	/**
	 * Reports direct database queries that are not wrapped in a cache.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function query_is_uncached( $occurrence ) {
		// Writes are not expected to be cached; only reads are reported here.
		$writes = array( 'wpdb->insert', 'wpdb->update', 'wpdb->replace', 'wpdb->delete' );

		if ( in_array( $occurrence['name'], $writes, true ) ) {
			return array(
				'severity'   => Taxonomy::SEVERITY_LOW,
				'confidence' => Taxonomy::CONFIDENCE_HIGH,
				'note'       => __( 'Direct write to the database. Confirm the affected cache keys are invalidated afterwards.', 'wp-vip-compatibility' ),
			);
		}

		if ( self::mentions( $occurrence['scope_source'], self::$cache_markers ) ) {
			return false;
		}

		// The caching wrapper is frequently a different function from the one
		// holding the query. Report it, but say plainly that the evidence is
		// weaker rather than claiming the query is definitely uncached.
		if ( ! empty( $occurrence['file_flags']['cache'] ) ) {
			return array(
				'severity'   => Taxonomy::SEVERITY_LOW,
				'confidence' => Taxonomy::CONFIDENCE_LOW,
				'note'       => __( 'This file does cache elsewhere, but not inside the function holding the query. Confirm this particular result is cached.', 'wp-vip-compatibility' ),
			);
		}

		return true;
	}

	/**
	 * Reports queries that ask for an unbounded number of rows.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function query_is_unbounded( $occurrence ) {
		$args = $occurrence['args_raw'];

		if ( preg_match( '/[\'"](posts_per_page|numberposts|number|posts_per_archive_page)[\'"]\s*=>\s*-\s*1/', $args, $matches ) ) {
			return array(
				/* translators: %s: Query argument name, for example "posts_per_page". */
				'note' => sprintf( __( '%s is set to -1, so the query is bounded only by how much content the site holds.', 'wp-vip-compatibility' ), $matches[1] ),
			);
		}

		if ( preg_match( '/[\'"]nopaging[\'"]\s*=>\s*true/i', $args ) ) {
			return array(
				'note' => __( 'nopaging is true, which removes the result limit entirely.', 'wp-vip-compatibility' ),
			);
		}

		return false;
	}

	/**
	 * Reports queries using arguments the database cannot serve from an index.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function query_uses_expensive_args( $occurrence ) {
		$args  = $occurrence['args_raw'];
		$found = array();

		if ( preg_match( '/[\'"]post__not_in[\'"]/', $args ) ) {
			$found[] = 'post__not_in';
		}

		if ( preg_match( '/[\'"]orderby[\'"]\s*=>\s*[\'"]rand[\'"]/i', $args ) ) {
			$found[] = 'orderby => rand';
		}

		if ( preg_match( '/[\'"]meta_query[\'"]/', $args ) ) {
			$found[] = 'meta_query';
		}

		if ( empty( $found ) ) {
			return false;
		}

		return array(
			/* translators: %s: Comma-separated list of query arguments. */
			'note' => sprintf( __( 'Expensive query arguments in use: %s.', 'wp-vip-compatibility' ), implode( ', ', $found ) ),
		);
	}

	/**
	 * Reports options that are explicitly marked as autoloaded.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function option_is_autoloaded( $occurrence ) {
		$index = ( 'add_option' === $occurrence['name'] ) ? 3 : 2;
		$value = self::literal( $occurrence['args'][ $index ] ?? '' );

		if ( null === $value ) {
			$raw = trim( $occurrence['args'][ $index ] ?? '' );

			return ( 'true' === strtolower( $raw ) ) ? true : false;
		}

		return ( 'yes' === strtolower( $value ) );
	}

	/**
	 * Reports filesystem functions handed an http(s) URL.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function argument_is_remote_url( $occurrence ) {
		$target = $occurrence['args'][0] ?? '';

		if ( preg_match( '#[\'"]https?://#i', $target ) ) {
			return array( 'confidence' => Taxonomy::CONFIDENCE_DEFINITIVE );
		}

		// A URL-producing helper is as good as a literal URL.
		if ( preg_match( '/\b(home_url|site_url|get_permalink|esc_url_raw|admin_url|rest_url|plugins_url)\s*\(/', $target ) ) {
			return array( 'confidence' => Taxonomy::CONFIDENCE_HIGH );
		}

		return false;
	}

	/**
	 * Reports WordPress HTTP API calls with no caching around them.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function remote_request_is_uncached( $occurrence ) {
		$cached      = self::mentions( $occurrence['scope_source'], self::$cache_markers );
		$has_timeout = (bool) preg_match( '/[\'"]timeout[\'"]\s*=>/', $occurrence['args_raw'] );

		if ( $cached && $has_timeout ) {
			return false;
		}

		if ( $cached && ! $has_timeout ) {
			return array(
				'severity'   => Taxonomy::SEVERITY_LOW,
				'confidence' => Taxonomy::CONFIDENCE_HIGH,
				'note'       => __( 'The response is cached, but no explicit timeout is set. A slow endpoint still blocks the request that refreshes the cache.', 'wp-vip-compatibility' ),
			);
		}

		if ( ! $has_timeout ) {
			return array(
				'note' => __( 'No caching and no explicit timeout: every cache miss waits on the remote service for the PHP default timeout.', 'wp-vip-compatibility' ),
			);
		}

		return true;
	}

	/**
	 * Reports headers that take a response out of the page cache.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function sends_cache_busting_header( $occurrence ) {
		// Admin screens, admin-post handlers and AJAX responses are never in
		// the edge cache to begin with, so a no-cache header there is correct
		// rather than a problem.
		if ( ! empty( $occurrence['file_flags']['admin'] ) ) {
			return false;
		}

		if ( 'nocache_headers' === $occurrence['name'] ) {
			return array( 'confidence' => Taxonomy::CONFIDENCE_HIGH );
		}

		$args = $occurrence['args_raw'];

		if ( ! preg_match( '/(Cache-Control|Pragma|Expires)/i', $args ) ) {
			return false;
		}

		if ( preg_match( '/(no-cache|no-store|max-age\s*=\s*0|private)/i', $args ) ) {
			return array( 'confidence' => Taxonomy::CONFIDENCE_HIGH );
		}

		return array(
			'severity'   => Taxonomy::SEVERITY_LOW,
			'confidence' => Taxonomy::CONFIDENCE_LOW,
			'note'       => __( 'A cache header is set by hand. Confirm the value matches what the VIP edge cache should do with this response.', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Rejects string matches that are patterns rather than real values.
	 *
	 * String-literal rules are the weakest kind, because a rule engine, a
	 * validation routine or a piece of documentation can contain the very text
	 * it is looking for. This plugin scanning its own rule definitions is the
	 * clearest example: `'#blogs\.dir#i'` is a regular expression, not a media
	 * path, and reporting it as a legacy multisite media directory is wrong.
	 *
	 * A real path or SQL fragment never contains a regex escape, so requiring
	 * the literal to be free of them separates the two cleanly.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool True when the literal looks like a real value.
	 */
	public static function string_is_literal_value( $occurrence ) {
		$value = $occurrence['name'];

		// Regex escapes and grouping: `\.`, `\b`, `(?i)`, `[a-z]`, `a|b`.
		if ( preg_match( '/\\\\[.bdswAZ]|\(\?|\[[^\]]*\]|\|/', $value ) ) {
			return false;
		}

		// A delimited pattern such as `#...#i` or `/.../`.
		if ( preg_match( '{^([#~/%!]).*\1[imsuxADSUXJn]*$}', $value ) ) {
			return false;
		}

		// Prose. A sentence that happens to mention a path is documentation,
		// an error message or a rule description — not a path the code uses.
		if ( substr_count( trim( $value ), ' ' ) >= 3 ) {
			return false;
		}

		return true;
	}

	/**
	 * Reports schema changes issued through a query call.
	 *
	 * Attaching this to the query rather than to any DDL-shaped string in the
	 * file is the difference between "this code alters the schema" and "this
	 * code contains the words ALTER TABLE" — the latter is true of any tool
	 * that reports on the schema, including this one.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function issues_schema_change( $occurrence ) {
		if ( 'dbDelta' === $occurrence['name'] ) {
			return array( 'confidence' => Taxonomy::CONFIDENCE_DEFINITIVE );
		}

		$sql = $occurrence['args_raw'];

		if ( preg_match( '/\b(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i', $sql, $matches ) ) {
			return array(
				'confidence' => Taxonomy::CONFIDENCE_HIGH,
				/* translators: %s: The SQL statement type, for example "CREATE TABLE". */
				'note'       => sprintf( __( 'The query issues a %s statement.', 'wp-vip-compatibility' ), strtoupper( preg_replace( '/\s+/', ' ', $matches[1] ) ) ),
			);
		}

		// The SQL is in a variable; fall back to looking at the whole function.
		if ( preg_match( '/^\s*\$[A-Za-z_][A-Za-z0-9_]*\s*$/', $occurrence['args'][0] ?? '' )
			&& preg_match( '/\b(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i', $occurrence['scope_source'] ) ) {
			return array(
				'confidence' => Taxonomy::CONFIDENCE_MEDIUM,
				'note'       => __( 'DDL appears in the same function as this query.', 'wp-vip-compatibility' ),
			);
		}

		return false;
	}

	/**
	 * Reports includes whose path is not anchored to a known base directory.
	 *
	 * A path such as `$base . '/' . $class . '.php'` in an autoloader is the normal
	 * shape of PHP autoloading, not a local file inclusion vulnerability. What
	 * matters is whether the path is anchored somewhere the caller controls.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function include_path_is_unanchored( $occurrence ) {
		$statement = self::resolve( $occurrence['args_raw'], $occurrence['scope_source'] );

		$anchors = array( '__DIR__', 'ABSPATH', 'plugin_dir_path', 'get_template_directory', 'get_stylesheet_directory', 'dirname', 'WPINC' );

		// Any *_DIR or *_PATH constant is a base directory by convention, which
		// covers a plugin's own WP_MYPLUGIN_DIR as well as the core constants.
		$anchored = self::mentions( $statement, $anchors ) || preg_match( '/\b[A-Z][A-Z0-9_]*_(DIR|PATH|ROOT)\b/', $statement );

		if ( $anchored ) {
			return array(
				'severity'   => Taxonomy::SEVERITY_LOW,
				'confidence' => Taxonomy::CONFIDENCE_LOW,
				'note'       => __( 'The path is anchored to a known base directory, so the risk depends entirely on how the variable part is validated.', 'wp-vip-compatibility' ),
			);
		}

		return true;
	}

	/**
	 * Reports debug helpers that actually produce output.
	 *
	 * Both var_export() and print_r() take a second argument that makes them
	 * return a string instead of printing it. In that form they are ordinary
	 * formatting calls, and flagging them as leftover debugging is wrong.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function is_debug_output( $occurrence ) {
		if ( ! in_array( $occurrence['name'], array( 'var_export', 'print_r' ), true ) ) {
			return true;
		}

		$returns = strtolower( trim( $occurrence['args'][1] ?? '' ) );

		return ( 'true' === $returns || '1' === $returns ) ? false : true;
	}

	/**
	 * Reports switch_to_blog() with no restore in the same function.
	 *
	 * @param array<string, mixed> $occurrence The matched occurrence.
	 * @return bool|array<string, mixed> False to discard, or reporting overrides.
	 */
	public static function switch_to_blog_unrestored( $occurrence ) {
		if ( false !== stripos( $occurrence['scope_source'], 'restore_current_blog' ) ) {
			return false;
		}

		// A switch at file scope has no enclosing function to inspect, so the
		// file is the only scope there is.
		if ( '' === $occurrence['scope_source'] && ! empty( $occurrence['file_flags']['restore'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Classifies a write target expression against the VIP writable paths.
	 *
	 * @param string $target The raw argument source.
	 * @return bool|array<string, mixed> False when the target is writable, or reporting overrides.
	 */
	private static function classify_write_target( $target ) {
		if ( '' === trim( $target ) ) {
			return false;
		}

		// Explicitly writable: the uploads directory or the temp directory.
		if ( self::mentions( $target, self::$writable_markers ) ) {
			return false;
		}

		// A variable named after uploads or the temp directory, such as
		// $uploads['basedir'] or $tmp_dir, is taken at its word.
		if ( preg_match( '/\$[A-Za-z0-9_]*(upload|tmp|temp)[A-Za-z0-9_]*/i', $target ) ) {
			return false;
		}

		$literal = self::literal( $target );

		if ( null !== $literal && ( 0 === strpos( $literal, '/tmp' ) || false !== strpos( $literal, '/tmp/' ) ) ) {
			return false;
		}

		// Explicitly not writable: anchored to the application directory.
		if ( self::mentions( $target, self::$unwritable_markers ) ) {
			return array(
				'confidence' => Taxonomy::CONFIDENCE_HIGH,
				'note'       => __( 'The path is built from the application directory, which is read-only on VIP.', 'wp-vip-compatibility' ),
			);
		}

		// An unresolved expression: worth reviewing, but do not overstate it.
		return array(
			'severity'   => Taxonomy::SEVERITY_MEDIUM,
			'confidence' => Taxonomy::CONFIDENCE_LOW,
			'note'       => __( 'The destination could not be resolved statically. Confirm it resolves to the uploads directory or /tmp/ at runtime.', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Expands the variables in an expression using assignments from its scope.
	 *
	 * Real code rarely writes to a literal path. It writes to `$path`, which was
	 * assigned two lines earlier from `wp_get_upload_dir()`. Without following
	 * that one hop, every such write looks unresolvable and the report fills up
	 * with low-confidence noise about code that is already correct.
	 *
	 * Two hops is deliberate: it covers the common
	 * `$dir = wp_upload_dir(); $path = $dir['basedir'] . '/x';` shape without
	 * turning into a general-purpose interpreter.
	 *
	 * @param string $expression   The raw argument source.
	 * @param string $scope_source Source of the enclosing function.
	 * @param int    $hops         Remaining expansion hops.
	 * @return string The expression with local variables expanded where possible.
	 */
	private static function resolve( $expression, $scope_source, $hops = 2 ) {
		if ( $hops < 1 || '' === trim( $expression ) || '' === $scope_source ) {
			return $expression;
		}

		if ( ! preg_match_all( '/\$([A-Za-z_][A-Za-z0-9_]*)/', $expression, $matches ) ) {
			return $expression;
		}

		$resolved = $expression;

		foreach ( array_unique( $matches[1] ) as $variable ) {
			// The tokeniser joins tokens with single spaces, so match loosely.
			$pattern = '/\$' . preg_quote( $variable, '/' ) . '\s*=\s*([^;]{1,400});/';

			if ( ! preg_match( $pattern, $scope_source, $assignment ) ) {
				continue;
			}

			$value = self::resolve( $assignment[1], $scope_source, $hops - 1 );

			// Append rather than replace: the original expression may add a
			// sub-path that matters (".../uploads" vs ".../uploads/../cache").
			$resolved .= ' ' . $value;
		}

		return $resolved;
	}

	/**
	 * Extracts the value of a single-token string literal.
	 *
	 * @param string $expression The raw argument source.
	 * @return string|null The literal value, or null when the argument is not a plain literal.
	 */
	private static function literal( $expression ) {
		$expression = trim( $expression );

		if ( preg_match( '/^([\'"])(.*)\1$/s', $expression, $matches ) ) {
			return $matches[2];
		}

		return null;
	}

	/**
	 * Whether a source fragment mentions any of the given markers.
	 *
	 * @param string   $haystack Source fragment.
	 * @param string[] $markers  Markers to look for.
	 * @return bool True when at least one marker is present.
	 */
	private static function mentions( $haystack, array $markers ) {
		foreach ( $markers as $marker ) {
			if ( false !== stripos( $haystack, $marker ) ) {
				return true;
			}
		}

		return false;
	}
}
