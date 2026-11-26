<?php
/**
 * Test doubles for WordPress objects and for the calls that end a request.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Support;

/**
 * Thrown by wp_send_json_success() and wp_send_json_error().
 *
 * WordPress sends the response and dies. Throwing keeps the test process alive
 * and lets a test read what would have been sent, while still proving that
 * nothing after the call ran.
 */
class Json_Response extends \RuntimeException {

	/**
	 * Whether the response reported success.
	 *
	 * @var bool
	 */
	public $success;

	/**
	 * The response payload.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * The HTTP status code.
	 *
	 * @var int|null
	 */
	public $status;

	/**
	 * Constructor.
	 *
	 * @param bool     $success Whether the response reported success.
	 * @param mixed    $data    The payload.
	 * @param int|null $status  The HTTP status code.
	 */
	public function __construct( $success, $data, $status ) {
		parent::__construct( $success ? 'json success' : 'json error' );

		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;
	}
}

/**
 * Thrown by wp_die() and by failed nonce checks.
 */
class Wp_Die extends \RuntimeException {

	/**
	 * The title passed to wp_die().
	 *
	 * @var string
	 */
	public $title;

	/**
	 * The arguments passed to wp_die().
	 *
	 * @var array<string, mixed>
	 */
	public $args;

	/**
	 * Constructor.
	 *
	 * @param string               $message The message.
	 * @param string               $title   The title.
	 * @param array<string, mixed> $args    The arguments.
	 */
	public function __construct( $message, $title = '', $args = array() ) {
		parent::__construct( (string) $message );

		$this->title = (string) $title;
		$this->args  = (array) $args;
	}
}

/**
 * Thrown by wp_safe_redirect(), which callers follow with exit.
 */
class Redirect extends \RuntimeException {

	/**
	 * The redirect target.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * Constructor.
	 *
	 * @param string $location The redirect target.
	 */
	public function __construct( $location ) {
		parent::__construct( 'redirect to ' . $location );

		$this->location = $location;
	}
}

/**
 * A theme, as wp_get_themes() returns it.
 */
class Fake_Theme {

	/**
	 * Header values.
	 *
	 * @var array<string, string>
	 */
	private $headers;

	/**
	 * Stylesheet directory.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $headers   Header values: Name, Version, Author, Template.
	 * @param string                $directory Stylesheet directory.
	 */
	public function __construct( array $headers, $directory ) {
		$this->headers   = $headers;
		$this->directory = $directory;
	}

	/**
	 * Returns a header value.
	 *
	 * @param string $header The header name.
	 * @return string|false The value, or false when unset.
	 */
	public function get( $header ) {
		return $this->headers[ $header ] ?? false;
	}

	/**
	 * Returns the stylesheet directory.
	 *
	 * @return string The directory.
	 */
	public function get_stylesheet_directory() {
		return $this->directory;
	}
}

/**
 * The admin screen, as get_current_screen() returns it.
 */
class Fake_Screen {

	/**
	 * Help tabs added to the screen.
	 *
	 * @var array<int, array<string, string>>
	 */
	public $help_tabs = array();

	/**
	 * Adds a help tab.
	 *
	 * @param array<string, string> $tab The tab.
	 * @return void
	 */
	public function add_help_tab( $tab ) {
		$this->help_tabs[] = $tab;
	}

	/**
	 * Returns the help tabs.
	 *
	 * @return array<int, array<string, string>> The tabs.
	 */
	public function get_help_tabs() {
		return $this->help_tabs;
	}
}

/**
 * Just enough of $wpdb for the database audit.
 */
class Fake_Wpdb {

	/**
	 * The site table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * The network table prefix.
	 *
	 * @var string
	 */
	public $base_prefix = 'wp_';

	/**
	 * Rows get_results() returns.
	 *
	 * @var array<int, object>
	 */
	public $tables = array();

	/**
	 * Queries run so far.
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Adds a table to the schema.
	 *
	 * @param string $name      Table name.
	 * @param string $engine    Storage engine.
	 * @param string $collation Table collation.
	 * @param int    $rows      Row count.
	 * @param int    $data      Data length in bytes.
	 * @param int    $index     Index length in bytes.
	 * @return self
	 */
	public function add_table( $name, $engine = 'InnoDB', $collation = 'utf8mb4_unicode_ci', $rows = 0, $data = 0, $index = 0 ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- information_schema column names.
		$this->tables[] = (object) array(
			'TABLE_NAME'      => $name,
			'TABLE_COLLATION' => $collation,
			'ENGINE'          => $engine,
			'TABLE_ROWS'      => $rows,
			'DATA_LENGTH'     => $data,
			'INDEX_LENGTH'    => $index,
		);
		// phpcs:enable

		return $this;
	}

	/**
	 * Prepares a query. Only `%s` placeholders are needed here.
	 *
	 * @param string $query The query.
	 * @param mixed  ...$args The values.
	 * @return string The prepared query.
	 */
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}

		return $query;
	}

	/**
	 * Runs a query and returns the configured tables.
	 *
	 * @param string $query The query.
	 * @return array<int, object> The rows.
	 */
	public function get_results( $query ) {
		$this->queries[] = $query;

		return $this->tables;
	}
}
