<?php
/**
 * Database audit.
 *
 * Checks every table in the site's schema against the three requirements VIP
 * states for an imported database: the InnoDB storage engine, a supported
 * utf8mb4 collation, and the standard `wp_` table prefix.
 *
 * The result is cached. The previous implementation ran this query on every
 * admin page render *and* again for each overview chart request, uncached, on
 * `information_schema` — which is the single most expensive query a WordPress
 * site can make on a large database.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

use WP_VIP_COMPATIBILITY\Includes\Classes\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Audits the database schema against the VIP requirements.
 */
class Database_Audit {

	/**
	 * Cache key for the audit result.
	 */
	const CACHE_KEY = 'wvc_database_audit';

	/**
	 * How long the audit result stays cached.
	 */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Runs the audit, using the cached result when one is available.
	 *
	 * @param bool $force Whether to bypass the cache.
	 * @return array<string, mixed> The audit result.
	 */
	public static function run( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = self::audit();

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Clears the cached audit result.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Performs the audit.
	 *
	 * @return array<string, mixed> The audit result.
	 */
	private static function audit() {
		global $wpdb;

		$collations = (array) Plugin::get_instance()->get_reference_list( 'vip_supported_collations' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema metadata has no core API; the result is cached in a transient by run().
		$tables = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, TABLE_COLLATION, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s
				ORDER BY TABLE_NAME',
				DB_NAME,
				'BASE TABLE'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows = array();

		$summary = array(
			'total'        => 0,
			'compatible'   => 0,
			'incompatible' => 0,
			'bytes'        => 0,
			'issues'       => array(
				'engine'    => 0,
				'collation' => 0,
				'prefix'    => 0,
			),
		);

		foreach ( (array) $tables as $table ) {
			$row = self::examine( $table, $collations );

			$rows[] = $row;
			++$summary['total'];
			$summary['bytes'] += $row['bytes'];

			if ( empty( $row['findings'] ) ) {
				++$summary['compatible'];
				continue;
			}

			++$summary['incompatible'];

			foreach ( $row['findings'] as $finding ) {
				if ( isset( $summary['issues'][ $finding['kind'] ] ) ) {
					++$summary['issues'][ $finding['kind'] ];
				}
			}
		}

		return array(
			'tables'     => $rows,
			'summary'    => $summary,
			'checked_at' => time(),
		);
	}

	/**
	 * Examines one table.
	 *
	 * @param object   $table      Row from information_schema.
	 * @param string[] $collations VIP-supported collations.
	 * @return array<string, mixed> The examined table.
	 */
	private static function examine( $table, array $collations ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Column names come from information_schema.
		$name      = (string) $table->TABLE_NAME;
		$engine    = (string) $table->ENGINE;
		$collation = (string) $table->TABLE_COLLATION;
		$rows      = (int) $table->TABLE_ROWS;
		$bytes     = (int) $table->DATA_LENGTH + (int) $table->INDEX_LENGTH;
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$findings = array();

		if ( '' !== $engine && 0 !== strcasecmp( 'InnoDB', $engine ) ) {
			$findings[] = array(
				'kind'        => 'engine',
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'label'       => __( 'Unsupported storage engine', 'wp-vip-compatibility' ),
				/* translators: %s: Current storage engine, for example "MyISAM". */
				'detail'      => sprintf( __( 'The table uses %s. VIP requires InnoDB.', 'wp-vip-compatibility' ), $engine ),
				'remediation' => __( 'Convert the table before exporting the database for import.', 'wp-vip-compatibility' ),
				'sql'         => sprintf( 'ALTER TABLE `%s` ENGINE = InnoDB;', $name ),
			);
		}

		if ( '' !== $collation && ! in_array( $collation, $collations, true ) ) {
			$suggestion = self::suggest_collation( $collation, $collations );

			$findings[] = array(
				'kind'        => 'collation',
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'label'       => __( 'Unsupported collation', 'wp-vip-compatibility' ),
				/* translators: %s: Current collation. */
				'detail'      => sprintf( __( '%s is not on the VIP supported collation list. VIP expects a utf8mb4 collation.', 'wp-vip-compatibility' ), $collation ),
				'remediation' => ( '' === $suggestion )
					? __( 'No direct utf8mb4 equivalent exists for this collation. Pick the closest supported one and verify the data after converting.', 'wp-vip-compatibility' )
					: __( 'Convert the table to the equivalent utf8mb4 collation. Run it against a backup first and check any columns holding non-ASCII text.', 'wp-vip-compatibility' ),
				'sql'         => ( '' === $suggestion )
					? ''
					: sprintf( 'ALTER TABLE `%1$s` CONVERT TO CHARACTER SET utf8mb4 COLLATE %2$s;', $name, $suggestion ),
			);
		}

		if ( 0 !== strpos( $name, 'wp_' ) ) {
			$findings[] = array(
				'kind'        => 'prefix',
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'label'       => __( 'Non-standard table prefix', 'wp-vip-compatibility' ),
				'detail'      => __( 'VIP expects every table to use the wp_ prefix.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Report the prefix to VIP and only rename tables once VIP confirms it is required. Renaming also means updating option names and user meta keys that embed the prefix — wp_user_roles, wp_capabilities, wp_user_level and wp_user-settings — or users lose their roles.', 'wp-vip-compatibility' ),
				'sql'         => sprintf( 'ALTER TABLE `%1$s` RENAME TO `wp_%1$s`;', $name ),
			);
		}

		return array(
			'name'      => $name,
			'engine'    => $engine,
			'collation' => $collation,
			'rows'      => $rows,
			'bytes'     => $bytes,
			'source'    => self::identify_source( $name ),
			'findings'  => $findings,
		);
	}

	/**
	 * Suggests the utf8mb4 equivalent of an unsupported collation.
	 *
	 * @param string   $collation  The current collation.
	 * @param string[] $collations VIP-supported collations.
	 * @return string The suggested collation, or an empty string.
	 */
	private static function suggest_collation( $collation, array $collations ) {
		$parts = explode( '_', $collation, 2 );

		if ( ! isset( $parts[1] ) ) {
			return '';
		}

		$candidate = 'utf8mb4_' . $parts[1];

		if ( in_array( $candidate, $collations, true ) ) {
			return $candidate;
		}

		// latin1_swedish_ci has no utf8mb4_swedish_ci in some MySQL builds, so
		// fall back to the general collation rather than suggesting nothing.
		return in_array( 'utf8mb4_unicode_ci', $collations, true ) ? 'utf8mb4_unicode_ci' : '';
	}

	/**
	 * Identifies which plugin or component owns a table.
	 *
	 * @param string $name The table name.
	 * @return string A human-readable source.
	 */
	private static function identify_source( $name ) {
		global $wpdb;

		static $core = null;
		static $vendor = null;

		if ( null === $core ) {
			$sources = Plugin::get_instance()->get_table_sources();
			$core    = (array) ( $sources['core_tables'] ?? array() );
			$vendor  = (array) ( $sources['vendor_tables'] ?? array() );
		}

		$unprefixed = $name;

		// Strip the site prefix, and the per-site segment on multisite.
		foreach ( array( $wpdb->prefix, $wpdb->base_prefix, 'wp_' ) as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $unprefixed, $prefix ) ) {
				$unprefixed = substr( $unprefixed, strlen( $prefix ) );
				break;
			}
		}

		$unprefixed = preg_replace( '/^\d+_/', '', $unprefixed );

		if ( isset( $core[ $unprefixed ] ) ) {
			return __( 'WordPress core', 'wp-vip-compatibility' );
		}

		if ( isset( $vendor[ $unprefixed ] ) ) {
			$owners = (array) $vendor[ $unprefixed ];

			// The map lists every plugin known to create the table; the first is
			// the usual owner and the rest are add-ons that reuse it.
			return ( count( $owners ) > 3 )
				/* translators: 1: Plugin slug. 2: Number of additional plugins. */
				? sprintf( __( '%1$s (and %2$d others)', 'wp-vip-compatibility' ), $owners[0], count( $owners ) - 1 )
				: implode( ', ', $owners );
		}

		return ( 0 === strpos( $name, 'wp_' ) )
			? __( 'Custom', 'wp-vip-compatibility' )
			: __( 'Unknown', 'wp-vip-compatibility' );
	}
}
