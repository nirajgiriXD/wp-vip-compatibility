<?php
/**
 * Persistence for scan results and scan history.
 *
 * Results used to be written to `wp-content/uploads/wvc-logs/*.json`, which is
 * a publicly reachable URL. That published the site's file paths and a map of
 * its weakest code to anyone who guessed the filename. Results now live in the
 * options table, behind the same capability check as the screens that show them.
 *
 * Each target gets its own non-autoloaded option so that a large report never
 * ends up in the autoloaded set, and a lightweight index option carries the
 * summaries the overview needs without loading every finding.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes persisted scan results.
 */
class Results_Store {

	/**
	 * Option holding the per-target summaries.
	 */
	const INDEX_OPTION = 'wvc_scan_index';

	/**
	 * Option holding the scan history.
	 */
	const HISTORY_OPTION = 'wvc_scan_history';

	/**
	 * Prefix for the per-target result options.
	 */
	const RESULT_PREFIX = 'wvc_result_';

	/**
	 * How many historical snapshots to keep.
	 */
	const HISTORY_LIMIT = 20;

	/**
	 * Per-request copy of the summary index.
	 *
	 * The index is read by the masthead, the menu badge and every screen, which
	 * is three or four reads of the same option on a single admin page. It is
	 * kept in step with every write below rather than simply invalidated, so a
	 * caller never sees a stale copy.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $index = null;

	/**
	 * Incremented on every write, so derived caches can tell they are stale.
	 *
	 * @var int
	 */
	private static $generation = 0;

	/**
	 * Returns a token that changes whenever the stored results change.
	 *
	 * @return string The token.
	 */
	public static function generation() {
		return (string) self::$generation;
	}

	/**
	 * Returns the stored result for a target.
	 *
	 * @param string $target_key The target key.
	 * @return array<string, mixed>|null The result, or null when nothing is stored.
	 */
	public static function get( $target_key ) {
		$result = get_option( self::option_name( $target_key ), null );

		if ( ! is_array( $result ) || empty( $result['fingerprint'] ) ) {
			return null;
		}

		// A result produced by an older rule set is not comparable to a new one.
		if ( ( $result['rules_version'] ?? '' ) !== Rules::VERSION ) {
			return null;
		}

		return $result;
	}

	/**
	 * Stores the result for a target and refreshes the index entry.
	 *
	 * @param string               $target_key The target key.
	 * @param array<string, mixed> $result     The scan result.
	 * @return void
	 */
	public static function save( $target_key, array $result ) {
		update_option( self::option_name( $target_key ), $result, false );

		/*
		 * Read the index from storage rather than from the per-request copy. The
		 * browser resolves unscanned rows one at a time, but a second admin
		 * request — another tab, a second author — can have written an entry
		 * since this process first read the index, and a blind write back would
		 * drop it.
		 */
		self::flush_index();

		$index = self::get_index();

		$index[ $target_key ] = array(
			'key'           => $result['key'],
			'type'          => $result['type'],
			'slug'          => $result['slug'],
			'label'         => $result['label'],
			'status'        => $result['status'],
			'summary'       => $result['summary'],
			'files_scanned' => $result['files_scanned'],
			'truncated'     => $result['truncated'],
			'scanned_at'    => $result['scanned_at'],
			'fingerprint'   => $result['fingerprint'],
		);

		update_option( self::INDEX_OPTION, $index, false );

		self::$index = $index;
		++self::$generation;
	}

	/**
	 * Returns the per-target summary index.
	 *
	 * @return array<string, array<string, mixed>> Summaries keyed by target key.
	 */
	public static function get_index() {
		if ( null === self::$index ) {
			$index = get_option( self::INDEX_OPTION, array() );

			self::$index = is_array( $index ) ? $index : array();
		}

		return self::$index;
	}

	/**
	 * Drops the per-request copy of the index.
	 *
	 * Intended for tests and long-running processes, where the option can change
	 * underneath a process that has already read it.
	 *
	 * @return void
	 */
	public static function flush_index() {
		self::$index = null;
		++self::$generation;
	}

	/**
	 * Returns every stored result, loading each target's findings.
	 *
	 * @return array<string, array<string, mixed>> Results keyed by target key.
	 */
	public static function get_all() {
		$results = array();

		foreach ( array_keys( self::get_index() ) as $target_key ) {
			$result = self::get( $target_key );

			if ( null !== $result ) {
				$results[ $target_key ] = $result;
			}
		}

		return $results;
	}

	/**
	 * Removes stored results for targets that no longer exist.
	 *
	 * @param string[] $valid_keys Keys that should be retained.
	 * @return int The number of entries removed.
	 */
	public static function prune( array $valid_keys ) {
		$index   = self::get_index();
		$removed = 0;

		foreach ( array_keys( $index ) as $target_key ) {
			if ( in_array( $target_key, $valid_keys, true ) ) {
				continue;
			}

			delete_option( self::option_name( $target_key ) );
			unset( $index[ $target_key ] );
			++$removed;
		}

		if ( $removed > 0 ) {
			update_option( self::INDEX_OPTION, $index, false );

			self::$index = $index;
			++self::$generation;
		}

		return $removed;
	}

	/**
	 * Discards the stored results for specific targets.
	 *
	 * Unlike prune(), which removes what no longer exists, this removes results
	 * for targets that are still installed — so they read as unscanned and get
	 * scanned again. The history is deliberately untouched: forgetting a reading
	 * is not the same as forgetting that it was ever taken.
	 *
	 * @param string[] $target_keys Keys to discard.
	 * @return int The number of results discarded.
	 */
	public static function forget( array $target_keys ) {
		$index   = self::get_index();
		$removed = 0;

		foreach ( $target_keys as $target_key ) {
			delete_option( self::option_name( $target_key ) );

			if ( isset( $index[ $target_key ] ) ) {
				unset( $index[ $target_key ] );
				++$removed;
			}
		}

		if ( $removed > 0 ) {
			update_option( self::INDEX_OPTION, $index, false );

			self::$index = $index;
			++self::$generation;
		}

		return $removed;
	}

	/**
	 * Deletes every stored result, the index and the history.
	 *
	 * @return void
	 */
	public static function delete_all() {
		foreach ( array_keys( self::get_index() ) as $target_key ) {
			delete_option( self::option_name( $target_key ) );
		}

		delete_option( self::INDEX_OPTION );
		delete_option( self::HISTORY_OPTION );

		self::$index = array();
		++self::$generation;
	}

	/**
	 * Appends a snapshot to the scan history.
	 *
	 * Only aggregate counts are kept. The point of the history is to answer
	 * "did this get better or worse since last time", which does not need every
	 * individual finding retained forever.
	 *
	 * @param array<string, mixed> $snapshot Aggregate counts for the whole site.
	 * @return void
	 */
	public static function record_history( array $snapshot ) {
		$history = self::get_history();
		$last    = end( $history );

		$snapshot['recorded_at'] = time();

		// Do not record an identical snapshot twice in a row: a repeated scan
		// with no code change is not a data point.
		if ( is_array( $last ) && self::same_totals( $last, $snapshot ) ) {
			return;
		}

		$history[] = $snapshot;

		if ( count( $history ) > self::HISTORY_LIMIT ) {
			$history = array_slice( $history, -self::HISTORY_LIMIT );
		}

		update_option( self::HISTORY_OPTION, array_values( $history ), false );
	}

	/**
	 * Returns the scan history, oldest first.
	 *
	 * @return array<int, array<string, mixed>> Snapshots.
	 */
	public static function get_history() {
		$history = get_option( self::HISTORY_OPTION, array() );

		return is_array( $history ) ? $history : array();
	}

	/**
	 * Returns the change between the two most recent snapshots.
	 *
	 * @return array<string, int>|null The deltas, or null when there is nothing to compare.
	 */
	public static function get_delta() {
		$history = self::get_history();

		if ( count( $history ) < 2 ) {
			return null;
		}

		$previous = $history[ count( $history ) - 2 ];
		$current  = $history[ count( $history ) - 1 ];

		return array(
			'total'    => (int) $current['total'] - (int) $previous['total'],
			'blocking' => (int) $current['blocking'] - (int) $previous['blocking'],
			'blocked'  => (int) $current['blocked'] - (int) $previous['blocked'],
			'since'    => (int) $previous['recorded_at'],
		);
	}

	/**
	 * Whether two snapshots carry the same headline totals.
	 *
	 * @param array<string, mixed> $a First snapshot.
	 * @param array<string, mixed> $b Second snapshot.
	 * @return bool True when the totals match.
	 */
	private static function same_totals( array $a, array $b ) {
		foreach ( array( 'total', 'blocking', 'blocked', 'review', 'pass' ) as $key ) {
			if ( ( $a[ $key ] ?? null ) !== ( $b[ $key ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Builds the option name for a target.
	 *
	 * The key is hashed because target slugs come from directory names and can
	 * exceed the length allowed for an option name.
	 *
	 * @param string $target_key The target key.
	 * @return string The option name.
	 */
	private static function option_name( $target_key ) {
		return self::RESULT_PREFIX . md5( $target_key );
	}
}
