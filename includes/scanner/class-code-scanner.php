<?php
/**
 * Token-based PHP scanner.
 *
 * The previous implementation matched regular expressions against raw lines,
 * which meant a function name inside a comment, a docblock or a string literal
 * counted as a call, and `$pdo->exec()` counted as a shell command. This
 * scanner tokenises each file instead, so it knows the difference between a
 * function call, a method call, a declaration and a mention in prose, and it
 * can read the arguments of a call to decide whether it is actually a problem.
 *
 * The output of a scan is a flat list of occurrences — calls, class
 * instantiations, string literals, constants, hooks and a few special shapes —
 * which the rule set is then matched against.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Scans a single PHP file and returns the findings it produces.
 */
class Code_Scanner {

	/**
	 * Files larger than this are skipped: they are almost always minified
	 * bundles or generated data rather than reviewable source.
	 */
	const MAX_FILE_BYTES = 1048576;

	/**
	 * Escaping and casting helpers that make echoed input safe.
	 *
	 * @var string[]
	 */
	private static $escapers = array( 'esc_html', 'esc_attr', 'esc_url', 'esc_js', 'esc_textarea', 'esc_xml', 'sanitize_', 'absint', 'intval', 'wp_kses', 'number_format', '(int)', '(float)' );

	/**
	 * Rule lookup indexes, built once per scanner instance.
	 *
	 * @var array<string, mixed>
	 */
	private $index;

	/**
	 * Constructor. Builds the rule lookup indexes.
	 */
	public function __construct() {
		$this->index = $this->build_index( Rules::all() );
	}

	/**
	 * Scans one PHP file.
	 *
	 * @param string $absolute_path Absolute path to the file.
	 * @param string $relative_path Path shown in the report.
	 * @return array<int, array<string, mixed>> Findings produced by the file.
	 */
	public function scan_file( $absolute_path, $relative_path ) {
		$source = $this->read( $absolute_path );

		if ( '' === $source ) {
			return array();
		}

		$occurrences = $this->collect_occurrences( $source );
		$findings    = $this->match_rules( $occurrences, $source, $relative_path );
		$findings    = $this->apply_suppressions( $findings, $source );

		return $this->deduplicate( $findings );
	}

	/**
	 * Drops findings the author has explicitly acknowledged in the source.
	 *
	 * Any analyser that cannot be told "yes, I know, and it is fine here" ends
	 * up either lying or being ignored. The annotation mirrors the PHPCS one
	 * VIP developers already use:
	 *
	 *     $path = '/wp-content/uploads'; // wvc:ignore filesystem.hardcoded-uploads-path -- documentation string.
	 *     // wvc:ignore-next-line * -- fixture data, not executed.
	 *
	 * A reason after `--` is required, so a suppression always records why.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @param string                           $source   The file source.
	 * @return array<int, array<string, mixed>> The surviving findings.
	 */
	private function apply_suppressions( array $findings, $source ) {
		if ( empty( $findings ) || false === stripos( $source, 'wvc:ignore' ) ) {
			return $findings;
		}

		$lines       = explode( "\n", $source );
		$suppressed  = array();

		foreach ( $lines as $number => $line ) {
			// Rule ids contain hyphens, so the reason separator has to be a
			// spaced `--` rather than any hyphen pair.
			if ( ! preg_match( '/wvc:(ignore-next-line|ignore)\s+(.+?)\s+--\s+\S/i', $line, $matches ) ) {
				continue;
			}

			$target = ( 'ignore-next-line' === strtolower( $matches[1] ) ) ? $number + 2 : $number + 1;
			$rules  = array_map( 'trim', explode( ',', $matches[2] ) );

			foreach ( $rules as $rule ) {
				$suppressed[ $target ][] = $rule;
			}
		}

		if ( empty( $suppressed ) ) {
			return $findings;
		}

		return array_values(
			array_filter(
				$findings,
				static function ( $finding ) use ( $suppressed ) {
					$rules = $suppressed[ $finding['line'] ] ?? array();

					return ! in_array( '*', $rules, true ) && ! in_array( $finding['rule'], $rules, true );
				}
			)
		);
	}

	/**
	 * Reads a file, skipping anything too large to be reviewable source.
	 *
	 * @param string $absolute_path Absolute path to the file.
	 * @return string The file contents, or an empty string.
	 */
	private function read( $absolute_path ) {
		$size = @filesize( $absolute_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable file is skipped, not reported.

		if ( false === $size || $size > self::MAX_FILE_BYTES ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file for static analysis; WP_Filesystem adds no value and cannot read outside the install.
		$source = @file_get_contents( $absolute_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable file is skipped, not reported.

		return is_string( $source ) ? $source : '';
	}

	/**
	 * Builds lookup tables so each occurrence only consults relevant rules.
	 *
	 * @param array<string, array<string, mixed>> $rules Rule definitions.
	 * @return array<string, mixed> The lookup index.
	 */
	private function build_index( array $rules ) {
		$index = array(
			'functions'    => array(),
			'methods'      => array(),
			'classes'      => array(),
			'constants'    => array(),
			'defines'      => array(),
			'hooks'        => array(),
			'superglobals' => array(),
			'server_keys'  => array(),
			'strings'      => array(),
			'flags'        => array(),
		);

		$simple = array(
			'functions'    => 'functions',
			'methods'      => 'methods',
			'classes'      => 'classes',
			'constants'    => 'constants',
			'defines'      => 'defines',
			'hooks'        => 'hooks',
			'superglobals' => 'superglobals',
		);

		foreach ( $rules as $rule_id => $rule ) {
			$match = $rule['match'];

			foreach ( $simple as $key => $bucket ) {
				foreach ( $match[ $key ] ?? array() as $needle ) {
					$index[ $bucket ][ strtolower( $needle ) ][] = $rule_id;
				}
			}

			foreach ( $match['server_index_keys'] ?? array() as $needle ) {
				$index['server_keys'][ strtoupper( $needle ) ][] = $rule_id;
			}

			if ( ! empty( $match['strings'] ) ) {
				$index['strings'][] = array(
					'rule'     => $rule_id,
					'patterns' => $match['strings'],
					'contains' => $match['contains'] ?? '',
				);
			}

			foreach ( array( 'shell', 'echoed_superglobals', 'dynamic_include', 'missing_abspath_guard' ) as $flag ) {
				if ( ! empty( $match[ $flag ] ) ) {
					$index['flags'][ $flag ][] = $rule_id;
				}
			}
		}

		return $index;
	}

	/**
	 * Tokenises the source and extracts every occurrence a rule can match.
	 *
	 * @param string $source The PHP source.
	 * @return array<int, array<string, mixed>> The occurrences.
	 */
	private function collect_occurrences( $source ) {
		$tokens = @token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A parse warning on third-party code must not surface as a PHP notice.

		if ( empty( $tokens ) ) {
			return array();
		}

		// PHP 8 emits a single token for a qualified name instead of the
		// T_STRING / T_NS_SEPARATOR sequence PHP 7 produced.
		$fully_qualified = defined( 'T_NAME_FULLY_QUALIFIED' ) ? T_NAME_FULLY_QUALIFIED : -101;

		// Normalise into parallel arrays: comments and whitespace are dropped so
		// that "the previous token" means the previous meaningful token.
		$ids       = array();
		$texts     = array();
		$lines     = array();
		$last_line = 1;

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$id   = $token[0];
				$text = $token[1];

				// `\strlen()` is the global function, so unwrap it to a plain name.
				// `\Foo\Bar()` stays qualified and is deliberately not matched.
				if ( $fully_qualified === $id && 1 === substr_count( $text, '\\' ) ) {
					$id   = T_STRING;
					$text = ltrim( $text, '\\' );
				}

				$ids[]     = $id;
				$texts[]   = $text;
				$lines[]   = $token[2];
				$last_line = $token[2];
				continue;
			}

			$ids[]   = null;
			$texts[] = $token;
			$lines[] = $last_line;
		}

		$count        = count( $ids );
		$occurrences  = array();
		$scopes       = array();
		$stack        = array();
		$depth        = 0;
		$pending      = null;
		$declares     = false;
		$in_backticks = false;

		for ( $i = 0; $i < $count; $i++ ) {
			$id   = $ids[ $i ];
			$text = $texts[ $i ];

			// Track function scopes so guards can inspect the surrounding code.
			if ( T_FUNCTION === $id || T_CLASS === $id ) {
				$declares = true;

				if ( T_FUNCTION === $id ) {
					$pending = ( isset( $ids[ $i + 1 ] ) && T_STRING === $ids[ $i + 1 ] ) ? $texts[ $i + 1 ] : '{closure}';
				}
			}

			if ( '{' === $text ) {
				++$depth;

				if ( null !== $pending ) {
					$scopes[] = array(
						'name'  => $pending,
						'start' => $i,
						'end'   => $count - 1,
						'depth' => $depth,
					);
					$stack[]  = count( $scopes ) - 1;
					$pending  = null;
				}
				continue;
			}

			if ( '}' === $text ) {
				$top = end( $stack );

				if ( false !== $top && $scopes[ $top ]['depth'] === $depth ) {
					$scopes[ $top ]['end'] = $i;
					array_pop( $stack );
				}

				--$depth;
				continue;
			}

			$scope = empty( $stack ) ? null : end( $stack );

			// eval() is a language construct with its own token, not a T_STRING.
			if ( T_EVAL === $id ) {
				$open          = $this->find_open_paren( $i, $ids, $texts );
				$args          = ( null === $open ) ? array( array(), '' ) : $this->read_arguments( $open, $ids, $texts );
				$occurrences[] = $this->make_occurrence( 'function', 'eval', $lines[ $i ], $args, $scope );
				continue;
			}

			if ( T_STRING === $id ) {
				$occurrence = $this->read_identifier( $i, $ids, $texts, $lines, $scope );

				if ( null !== $occurrence ) {
					$occurrences[] = $occurrence;

					// define() and add_filter() also register a name-shaped match.
					$extra = $this->derive_named_occurrence( $occurrence );

					if ( null !== $extra ) {
						$occurrences[] = $extra;
					}
				}
				continue;
			}

			if ( T_NEW === $id ) {
				$class = $this->read_class_name( $i, $ids, $texts );

				if ( '' !== $class ) {
					$open          = $this->find_open_paren( $i, $ids, $texts );
					$args          = ( null === $open ) ? array( array(), '' ) : $this->read_arguments( $open, $ids, $texts );
					$occurrences[] = $this->make_occurrence( 'class', $class, $lines[ $i ], $args, $scope );
				}
				continue;
			}

			if ( T_VARIABLE === $id && 0 === strpos( $text, '$_' ) ) {
				$occurrence        = $this->make_occurrence( 'superglobal', ltrim( $text, '$' ), $lines[ $i ], array( array(), '' ), $scope );
				$occurrence['key'] = $this->read_index_key( $i, $ids, $texts );
				$occurrences[]     = $occurrence;
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
				$occurrences[] = $this->make_occurrence( 'string', trim( $text, '\'"' ), $lines[ $i ], array( array(), '' ), $scope );
				continue;
			}

			// The backtick operator has no dedicated token: it arrives as a bare
			// character, once to open and once to close, so only the open counts.
			if ( '`' === $text ) {
				$in_backticks = ! $in_backticks;

				if ( $in_backticks ) {
					$occurrences[] = $this->make_occurrence( 'shell', 'backtick-operator', $lines[ $i ], array( array(), '' ), $scope );
				}
				continue;
			}

			if ( in_array( $id, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				$statement = $this->read_statement( $i, $ids, $texts );

				if ( false !== strpos( $statement, '$' ) ) {
					$occurrences[] = $this->make_occurrence( 'dynamic_include', strtolower( $text ), $lines[ $i ], array( array(), $statement ), $scope );
				}
				continue;
			}

			if ( T_ECHO === $id || T_PRINT === $id ) {
				$statement = $this->read_statement( $i, $ids, $texts );

				if ( preg_match( '/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/', $statement ) && ! $this->is_escaped( $statement ) ) {
					$occurrences[] = $this->make_occurrence( 'echoed_superglobals', strtolower( $text ), $lines[ $i ], array( array(), $statement ), $scope );
				}
				continue;
			}
		}

		return $this->attach_scope_sources( $occurrences, $scopes, $texts, $declares, $source );
	}

	/**
	 * Interprets a T_STRING token as a call, a constant, or nothing at all.
	 *
	 * @param int                $i     Token index.
	 * @param array<int, ?int>   $ids   Token ids.
	 * @param array<int, string> $texts Token texts.
	 * @param array<int, int>    $lines Token lines.
	 * @param int|null           $scope Enclosing scope index.
	 * @return array<string, mixed>|null The occurrence, or null when the token is not interesting.
	 */
	private function read_identifier( $i, array $ids, array $texts, array $lines, $scope ) {
		$prev_id   = $ids[ $i - 1 ] ?? null;
		$next_text = $texts[ $i + 1 ] ?? '';

		// Declarations and property/constant fetches are not calls.
		if ( in_array( $prev_id, array( T_FUNCTION, T_CLASS, T_NEW, T_INTERFACE, T_TRAIT, T_CONST ), true ) ) {
			return null;
		}

		if ( '(' !== $next_text ) {
			// A bare uppercase identifier is a constant reference.
			if ( T_DOUBLE_COLON !== $prev_id && T_OBJECT_OPERATOR !== $prev_id && preg_match( '/^[A-Z][A-Z0-9_]*$/', $texts[ $i ] ) ) {
				return $this->make_occurrence( 'constant', $texts[ $i ], $lines[ $i ], array( array(), '' ), $scope );
			}

			return null;
		}

		$args = $this->read_arguments( $i + 1, $ids, $texts );

		// Method call: pick up the receiver so `wpdb->query` can be targeted.
		if ( T_OBJECT_OPERATOR === $prev_id || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $prev_id ) ) {
			$receiver = $texts[ $i - 2 ] ?? '';
			$receiver = ltrim( $receiver, '$' );

			return $this->make_occurrence( 'method', $receiver . '->' . $texts[ $i ], $lines[ $i ], $args, $scope );
		}

		if ( T_DOUBLE_COLON === $prev_id ) {
			$receiver = $texts[ $i - 2 ] ?? '';

			return $this->make_occurrence( 'method', $receiver . '::' . $texts[ $i ], $lines[ $i ], $args, $scope );
		}

		// A namespaced call is not the global function of the same name.
		if ( T_NS_SEPARATOR === $prev_id && isset( $ids[ $i - 2 ] ) && T_STRING === $ids[ $i - 2 ] ) {
			return null;
		}

		return $this->make_occurrence( 'function', $texts[ $i ], $lines[ $i ], $args, $scope );
	}

	/**
	 * Produces the secondary occurrence implied by define() and add_filter().
	 *
	 * `define( 'WP_CACHE', true )` is both a call to define() and a declaration
	 * of the WP_CACHE constant; rules want to target the latter.
	 *
	 * @param array<string, mixed> $occurrence The call occurrence.
	 * @return array<string, mixed>|null The derived occurrence, or null.
	 */
	private function derive_named_occurrence( array $occurrence ) {
		if ( 'function' !== $occurrence['kind'] ) {
			return null;
		}

		$name = strtolower( $occurrence['name'] );
		$first = $occurrence['args'][0] ?? '';

		if ( ! preg_match( '/^([\'"])(.*)\1$/s', trim( $first ), $matches ) ) {
			return null;
		}

		$literal = $matches[2];

		if ( 'define' === $name ) {
			$derived         = $occurrence;
			$derived['kind'] = 'define';
			$derived['name'] = $literal;

			return $derived;
		}

		if ( 'defined' === $name ) {
			$derived         = $occurrence;
			$derived['kind'] = 'constant';
			$derived['name'] = $literal;

			return $derived;
		}

		if ( in_array( $name, array( 'add_filter', 'add_action' ), true ) ) {
			$derived         = $occurrence;
			$derived['kind'] = 'hook';
			$derived['name'] = $literal;

			return $derived;
		}

		return null;
	}

	/**
	 * Reads the class name that follows a `new` keyword.
	 *
	 * @param int                $i     Index of the T_NEW token.
	 * @param array<int, ?int>   $ids   Token ids.
	 * @param array<int, string> $texts Token texts.
	 * @return string The class name, or an empty string.
	 */
	private function read_class_name( $i, array $ids, array $texts ) {
		$count     = count( $ids );
		$qualified = array();

		foreach ( array( 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE' ) as $constant ) {
			if ( defined( $constant ) ) {
				$qualified[] = constant( $constant );
			}
		}

		$name = '';

		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( T_NS_SEPARATOR === $ids[ $j ] ) {
				$name = '';
				continue;
			}

			if ( T_STRING === $ids[ $j ] ) {
				$name = $texts[ $j ];
				continue;
			}

			// PHP 8 delivers `\Foo\Bar` as one token; the class is the last segment.
			if ( in_array( $ids[ $j ], $qualified, true ) ) {
				$segments = explode( '\\', $texts[ $j ] );
				$name     = (string) end( $segments );
				continue;
			}

			break;
		}

		return $name;
	}

	/**
	 * Finds the opening parenthesis that follows a token, if any.
	 *
	 * @param int                $i     Start index.
	 * @param array<int, ?int>   $ids   Token ids.
	 * @param array<int, string> $texts Token texts.
	 * @return int|null Index of the opening parenthesis, or null.
	 */
	private function find_open_paren( $i, array $ids, array $texts ) {
		$count = min( count( $texts ), $i + 8 );

		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( '(' === $texts[ $j ] ) {
				return $j;
			}

			// A statement boundary means the constructor took no arguments.
			if ( in_array( $texts[ $j ], array( ';', ',', ')', '}' ), true ) ) {
				return null;
			}
		}

		unset( $ids );

		return null;
	}

	/**
	 * Reads a balanced argument list starting at an opening parenthesis.
	 *
	 * @param int                $open  Index of the opening parenthesis.
	 * @param array<int, ?int>   $ids   Token ids.
	 * @param array<int, string> $texts Token texts.
	 * @return array{0: string[], 1: string} The split arguments and the raw argument text.
	 */
	private function read_arguments( $open, array $ids, array $texts ) {
		$count   = count( $texts );
		$depth   = 0;
		$raw     = '';
		$current = '';
		$args    = array();

		for ( $j = $open; $j < $count; $j++ ) {
			$text = $texts[ $j ];

			if ( '(' === $text || '[' === $text ) {
				++$depth;

				if ( 1 === $depth && '(' === $text ) {
					continue;
				}
			} elseif ( ')' === $text || ']' === $text ) {
				--$depth;

				if ( 0 === $depth ) {
					break;
				}
			}

			if ( 1 === $depth && ',' === $text ) {
				$args[]  = trim( $current );
				$current = '';
				$raw    .= $text;
				continue;
			}

			$current .= $text;
			$raw     .= $text;
		}

		unset( $ids );

		if ( '' !== trim( $current ) ) {
			$args[] = trim( $current );
		}

		return array( $args, $raw );
	}

	/**
	 * Reads the array index key immediately following a variable.
	 *
	 * @param int                $i     Index of the variable token.
	 * @param array<int, ?int>   $ids   Token ids.
	 * @param array<int, string> $texts Token texts.
	 * @return string The key, or an empty string.
	 */
	private function read_index_key( $i, array $ids, array $texts ) {
		if ( ( $texts[ $i + 1 ] ?? '' ) !== '[' ) {
			return '';
		}

		if ( T_CONSTANT_ENCAPSED_STRING !== ( $ids[ $i + 2 ] ?? null ) ) {
			return '';
		}

		return trim( $texts[ $i + 2 ], '\'"' );
	}

	/**
	 * Reads the source of the statement starting at a token, up to its semicolon.
	 *
	 * @param int                $i     Start index.
	 * @param array<int, ?int>   $ids   Token ids.
	 * @param array<int, string> $texts Token texts.
	 * @return string The statement source.
	 */
	private function read_statement( $i, array $ids, array $texts ) {
		$count     = count( $texts );
		$statement = '';

		for ( $j = $i + 1; $j < $count && $j < $i + 200; $j++ ) {
			if ( ';' === $texts[ $j ] || T_CLOSE_TAG === $ids[ $j ] ) {
				break;
			}

			$statement .= $texts[ $j ];
		}

		return $statement;
	}

	/**
	 * Whether a statement passes its input through an escaping helper.
	 *
	 * @param string $statement The statement source.
	 * @return bool True when an escaping or casting helper is present.
	 */
	private function is_escaped( $statement ) {
		foreach ( self::$escapers as $escaper ) {
			if ( false !== stripos( $statement, $escaper ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds an occurrence record.
	 *
	 * @param string                        $kind  Occurrence kind.
	 * @param string                        $name  Occurrence name.
	 * @param int                           $line  Source line.
	 * @param array{0: string[], 1: string} $args Split arguments and raw argument text.
	 * @param int|null                      $scope Enclosing scope index.
	 * @return array<string, mixed> The occurrence.
	 */
	private function make_occurrence( $kind, $name, $line, array $args, $scope ) {
		return array(
			'kind'         => $kind,
			'name'         => $name,
			'line'         => (int) $line,
			'args'         => $args[0],
			'args_raw'     => $args[1],
			'scope'        => $scope,
			'scope_name'   => '',
			'scope_source' => '',
			'file_flags'   => array(),
			'key'          => '',
		);
	}

	/**
	 * Resolves each occurrence's enclosing function name and source.
	 *
	 * @param array<int, array<string, mixed>> $occurrences The occurrences.
	 * @param array<int, array<string, mixed>> $scopes      The recorded scopes.
	 * @param array<int, string>               $texts       Token texts.
	 * @param bool                             $declares    Whether the file declares functions or classes.
	 * @param string                           $source      The full source.
	 * @return array<int, array<string, mixed>> The occurrences, with scope data filled in.
	 */
	private function attach_scope_sources( array $occurrences, array $scopes, array $texts, $declares, $source ) {
		$cache = array();

		// File-level facts a guard may need when the relevant evidence lives in
		// a different function from the call being judged — a query cached by
		// its wrapper, or a header sent from an admin-only handler.
		$flags = array(
			'cache'   => (bool) preg_match( '/\b(wp_cache_(get|set|add)|get_transient|set_transient|get_site_transient|set_site_transient)\s*\(/', $source ),
			'admin'   => (bool) preg_match( '/\b(admin_post_|admin_url|is_admin|current_user_can|check_admin_referer|check_ajax_referer|wp_die|add_menu_page|add_submenu_page)\b/', $source ),
			'restore' => (bool) preg_match( '/\brestore_current_blog\s*\(/', $source ),
		);

		foreach ( $occurrences as $position => $occurrence ) {
			$occurrences[ $position ]['file_flags'] = $flags;

			$scope = $occurrence['scope'];

			if ( null === $scope || ! isset( $scopes[ $scope ] ) ) {
				continue;
			}

			if ( ! isset( $cache[ $scope ] ) ) {
				$slice           = array_slice( $texts, $scopes[ $scope ]['start'], $scopes[ $scope ]['end'] - $scopes[ $scope ]['start'] + 1 );
				$cache[ $scope ] = implode( ' ', $slice );
			}

			$occurrences[ $position ]['scope_name']   = $scopes[ $scope ]['name'];
			$occurrences[ $position ]['scope_source'] = $cache[ $scope ];
		}

		// A file that declares code but never checks that it was loaded through
		// WordPress runs if requested directly. WP_UNINSTALL_PLUGIN counts: it is
		// the correct guard for an uninstall handler.
		if ( $declares && ! preg_match( '/defined\s*\(\s*[\'"](ABSPATH|WPINC|WP_UNINSTALL_PLUGIN)[\'"]\s*\)/', $source ) ) {
			$occurrences[] = $this->make_occurrence( 'missing_abspath_guard', 'ABSPATH', 1, array( array(), '' ), null );
		}

		return $occurrences;
	}

	/**
	 * Matches occurrences against the rule set.
	 *
	 * @param array<int, array<string, mixed>> $occurrences   The occurrences.
	 * @param string                           $source        The full source.
	 * @param string                           $relative_path Path shown in the report.
	 * @return array<int, array<string, mixed>> The findings.
	 */
	private function match_rules( array $occurrences, $source, $relative_path ) {
		$source_lines = explode( "\n", $source );
		$findings     = array();

		foreach ( $occurrences as $occurrence ) {
			foreach ( $this->rules_for( $occurrence, $source ) as $rule_id ) {
				$rule = Rules::get( $rule_id );

				if ( null === $rule ) {
					continue;
				}

				$overrides = array();

				if ( is_callable( $rule['guard'] ) ) {
					$verdict = call_user_func( $rule['guard'], $occurrence );

					if ( false === $verdict ) {
						continue;
					}

					if ( is_array( $verdict ) ) {
						$overrides = $verdict;
					}
				}

				$findings[] = $this->make_finding( $rule_id, $rule, $occurrence, $overrides, $relative_path, $source_lines );
			}
		}

		return $findings;
	}

	/**
	 * Returns the rule ids an occurrence could match.
	 *
	 * @param array<string, mixed> $occurrence The occurrence.
	 * @param string               $source     The full source, for the string prefilter.
	 * @return string[] Rule ids.
	 */
	private function rules_for( array $occurrence, $source ) {
		$name = strtolower( $occurrence['name'] );

		switch ( $occurrence['kind'] ) {
			case 'function':
				return $this->index['functions'][ $name ] ?? array();

			case 'method':
				return $this->index['methods'][ $name ] ?? array();

			case 'class':
				return $this->index['classes'][ $name ] ?? array();

			case 'constant':
				return $this->index['constants'][ $name ] ?? array();

			case 'define':
				// Defining a constant also counts as referencing it, so rules
				// that target the constant match a define() of it too.
				return array_merge(
					$this->index['defines'][ $name ] ?? array(),
					$this->index['constants'][ $name ] ?? array()
				);

			case 'hook':
				return $this->index['hooks'][ $name ] ?? array();

			case 'superglobal':
				$rules = $this->index['superglobals'][ $name ] ?? array();

				if ( '_server' === $name && '' !== $occurrence['key'] ) {
					$rules = array_merge( $rules, $this->index['server_keys'][ strtoupper( $occurrence['key'] ) ] ?? array() );
				}

				return $rules;

			case 'string':
				return $this->string_rules_for( $occurrence['name'], $source );

			case 'shell':
			case 'echoed_superglobals':
			case 'dynamic_include':
			case 'missing_abspath_guard':
				return $this->index['flags'][ $occurrence['kind'] ] ?? array();
		}

		return array();
	}

	/**
	 * Matches a string literal against the string-based rules.
	 *
	 * @param string $value  The literal value.
	 * @param string $source The full source, used as a cheap prefilter.
	 * @return string[] Rule ids.
	 */
	private function string_rules_for( $value, $source ) {
		$matched = array();

		foreach ( $this->index['strings'] as $entry ) {
			if ( '' !== $entry['contains'] && false === stripos( $source, $entry['contains'] ) ) {
				continue;
			}

			foreach ( $entry['patterns'] as $pattern ) {
				if ( preg_match( $pattern, $value ) ) {
					$matched[] = $entry['rule'];
					break;
				}
			}
		}

		return $matched;
	}

	/**
	 * Assembles a finding from a rule, an occurrence and any guard overrides.
	 *
	 * @param string               $rule_id       Rule id.
	 * @param array<string, mixed> $rule          Rule definition.
	 * @param array<string, mixed> $occurrence    The occurrence.
	 * @param array<string, mixed> $overrides     Guard overrides.
	 * @param string               $relative_path Path shown in the report.
	 * @param array<int, string>   $source_lines  The source split into lines.
	 * @return array<string, mixed> The finding.
	 */
	private function make_finding( $rule_id, array $rule, array $occurrence, array $overrides, $relative_path, array $source_lines ) {
		$line    = $occurrence['line'];
		$snippet = trim( $source_lines[ $line - 1 ] ?? '' );

		if ( strlen( $snippet ) > 240 ) {
			$snippet = substr( $snippet, 0, 237 ) . '...';
		}

		return array(
			'rule'         => $rule_id,
			'file'         => $relative_path,
			'line'         => $line,
			'symbol'       => $occurrence['name'],
			'scope'        => $occurrence['scope_name'],
			'evidence'     => $snippet,
			'note'         => $overrides['note'] ?? '',
			'severity'     => $overrides['severity'] ?? $rule['severity'],
			'confidence'   => $overrides['confidence'] ?? $rule['confidence'],
			'also_matched' => array(),
		);
	}

	/**
	 * Collapses findings that describe the same underlying problem.
	 *
	 * Two rules firing on the same line within the same category are almost
	 * always two views of one defect — a write that is both outside the
	 * writable paths and generating a PHP file, for instance. The most severe
	 * finding is kept and the others are recorded against it, so the report
	 * shows one actionable item without losing the detail.
	 *
	 * @param array<int, array<string, mixed>> $findings The raw findings.
	 * @return array<int, array<string, mixed>> The deduplicated findings.
	 */
	private function deduplicate( array $findings ) {
		$kept = array();

		foreach ( $findings as $finding ) {
			$rule     = Rules::get( $finding['rule'] );
			$category = $rule['category'] ?? 'standards';
			$key      = $category . ':' . $finding['line'];

			if ( ! isset( $kept[ $key ] ) ) {
				$kept[ $key ] = $finding;
				continue;
			}

			$existing = $kept[ $key ];

			if ( $existing['rule'] === $finding['rule'] ) {
				continue;
			}

			$incoming_weight = Taxonomy::get_severity_weight( $finding['severity'] );
			$existing_weight = Taxonomy::get_severity_weight( $existing['severity'] );

			if ( $incoming_weight > $existing_weight ) {
				$finding['also_matched'] = array_merge( $existing['also_matched'], array( $existing['rule'] ) );
				$kept[ $key ]            = $finding;
				continue;
			}

			$kept[ $key ]['also_matched'][] = $finding['rule'];
		}

		return array_values( $kept );
	}
}
