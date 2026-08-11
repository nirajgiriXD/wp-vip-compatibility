<?php
/**
 * Plugin manifest class.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Instantiates the plugin's services and owns the reference data.
 */
class Plugin {

	use Singleton;

	/**
	 * Reference data from data/data.json.
	 *
	 * Loaded on first use rather than in the constructor. The previous version
	 * decoded a 1.1 MB JSON document on every single request, front end
	 * included, purely so the admin screens could read a handful of lists.
	 *
	 * @var array<string, mixed>|null
	 */
	private $reference = null;

	/**
	 * The database table-source map from data/table-sources.json.
	 *
	 * Held separately because it is by far the largest part of the reference
	 * data and only the database screen ever needs it.
	 *
	 * @var array<string, mixed>|null
	 */
	private $table_sources = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load_classes();
		$this->setup_hooks();
	}

	/**
	 * Instantiates the plugin's services.
	 *
	 * @return void
	 */
	private function load_classes() {
		Assets::get_instance();
		Ajax::get_instance();
		Settings::get_instance();
		Export::get_instance();

		// Registers an admin-post handler, so it has to exist on every admin
		// request rather than only when its own screen renders.
		Findings_Settings::get_instance();
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function setup_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-vip-compatibility', false, dirname( plugin_basename( WP_VIP_COMPATIBILITY_FILE ) ) . '/languages' );
	}

	/**
	 * Returns the reference data.
	 *
	 * The `tables` key is filled in on demand so that callers which only need
	 * the small lists never pay for decoding the table-source map.
	 *
	 * @return array<string, mixed> The reference data.
	 */
	public function get_json_data() {
		$reference = $this->get_reference();

		$reference['tables'] = $this->get_table_sources();

		return $reference;
	}

	/**
	 * Returns the small reference lists: directories, collations, known plugins.
	 *
	 * @return array<string, mixed> The reference data.
	 */
	public function get_reference() {
		if ( null === $this->reference ) {
			$this->reference = $this->read_json( WP_VIP_COMPATIBILITY_DIR . '/data/data.json' );
		}

		return $this->reference;
	}

	/**
	 * Returns a single top-level reference list.
	 *
	 * @param string $key     The list key.
	 * @param mixed  $default_value Value returned when the key is absent.
	 * @return mixed The list, or the default.
	 */
	public function get_reference_list( $key, $default_value = array() ) {
		$reference = $this->get_reference();

		return $reference[ $key ] ?? $default_value;
	}

	/**
	 * Returns the database table-source map.
	 *
	 * @return array<string, mixed> Core and vendor table maps.
	 */
	public function get_table_sources() {
		if ( null === $this->table_sources ) {
			$this->table_sources = $this->read_json( WP_VIP_COMPATIBILITY_DIR . '/data/table-sources.json' );
		}

		return $this->table_sources;
	}

	/**
	 * Reads and decodes a JSON file that ships with the plugin.
	 *
	 * @param string $path Absolute path to the file.
	 * @return array<string, mixed> The decoded data, or an empty array on failure.
	 */
	private function read_json( $path ) {
		if ( ! is_readable( $path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a static data file bundled with the plugin; WP_Filesystem would add a credentials round trip for no benefit.
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			return array();
		}

		$decoded = json_decode( $contents, true );

		return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) ? $decoded : array();
	}
}
