<?php
/**
 * Public helper functions.
 *
 * These wrap the scanner classes so that the signatures the plugin has always
 * exposed keep working. New code should use the classes in
 * `WP_VIP_COMPATIBILITY\Includes\Scanner` directly.
 *
 * @package wp-vip-compatibility
 */

use WP_VIP_COMPATIBILITY\Includes\Classes\Plugin;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Database_Audit;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Directory_Audit;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Known_Plugins;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Scanner;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the compatibility verdict for a scan target.
 *
 * @since 1.0.0
 * @since 2.0.0 Accepts a target key such as `plugin:akismet`. A filesystem path
 *              is still accepted and resolved against the known targets, but a
 *              path that does not belong to a known plugin, theme or must-use
 *              plugin is rejected rather than scanned.
 *
 * @param string $target The target key, or a legacy filesystem path.
 * @return string One of `Compatible`, `Needs review`, `Incompatible`, or an error message.
 */
function wvc_check_vip_compatibility( $target ) {
	$resolved = wvc_resolve_target( $target );

	if ( null === $resolved ) {
		return esc_html__( 'Error: Unknown scan target', 'wp-vip-compatibility' );
	}

	$result = ( new Scanner() )->get_result( $resolved );

	switch ( $result['status'] ) {
		case Scanner::STATUS_BLOCKED:
			return esc_html__( 'Incompatible', 'wp-vip-compatibility' );
		case Scanner::STATUS_REVIEW:
			return esc_html__( 'Needs review', 'wp-vip-compatibility' );
		default:
			return esc_html__( 'Compatible', 'wp-vip-compatibility' );
	}
}

/**
 * Resolves a target key, or a legacy filesystem path, to a known target.
 *
 * Accepting a raw path used to mean any authenticated caller could point the
 * scanner at any directory on the server. A path is now only honoured when it
 * matches a plugin, theme or must-use plugin that WordPress already knows about.
 *
 * @param string $target The target key or filesystem path.
 * @return array<string, mixed>|null The target, or null when it cannot be resolved.
 */
function wvc_resolve_target( $target ) {
	$target = (string) $target;

	if ( '' === $target ) {
		return null;
	}

	$resolved = Targets::get( $target );

	if ( null !== $resolved ) {
		return $resolved;
	}

	// Legacy callers passed an absolute path. Match it against known targets.
	$normalised = rtrim( str_replace( '\\', '/', $target ), '/' );

	foreach ( Targets::all() as $candidate ) {
		if ( rtrim( str_replace( '\\', '/', $candidate['path'] ), '/' ) === $normalised ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Returns the plugin's reference data.
 *
 * @return array<string, mixed> The reference data.
 */
function wvc_get_json_data() {
	return Plugin::get_instance()->get_json_data();
}

/**
 * Returns the compatibility counts for one target type.
 *
 * @param string $type One of `plugin`, `theme`, `mu-plugin`.
 * @return array<string, int> Counts keyed `compatible`, `needs_review`, `not_compatible`.
 */
function wvc_get_target_chart_data( $type ) {
	$counts = array(
		'compatible'     => 0,
		'needs_review'   => 0,
		'not_compatible' => 0,
	);

	$index = Results_Store::get_index();

	foreach ( Targets::of_type( $type ) as $key => $target ) {
		$known = ( 'mu-plugin' === $type )
			? Known_Plugins::mu_plugin( $target['slug'] )
			: Known_Plugins::classify( $target['slug'] );

		// A plugin VIP lists as incompatible is settled without scanning it.
		if ( is_array( $known ) && ( $known['classification'] ?? '' ) === Known_Plugins::INCOMPATIBLE ) {
			++$counts['not_compatible'];
			continue;
		}

		$status = $index[ $key ]['status'] ?? null;

		if ( null === $status ) {
			++$counts['needs_review'];
			continue;
		}

		if ( Scanner::STATUS_BLOCKED === $status ) {
			++$counts['not_compatible'];
		} elseif ( Scanner::STATUS_REVIEW === $status ) {
			++$counts['needs_review'];
		} else {
			++$counts['compatible'];
		}
	}

	return $counts;
}

/**
 * Returns the compatibility counts for plugins.
 *
 * @return array<string, int> The chart data.
 */
function wvc_get_plugins_chart_data() {
	return wvc_get_target_chart_data( 'plugin' );
}

/**
 * Returns the compatibility counts for must-use plugins.
 *
 * @return array<string, int> The chart data.
 */
function wvc_get_mu_plugins_chart_data() {
	return wvc_get_target_chart_data( 'mu-plugin' );
}

/**
 * Returns the compatibility counts for themes.
 *
 * @return array<string, int> The chart data.
 */
function wvc_get_themes_chart_data() {
	return wvc_get_target_chart_data( 'theme' );
}

/**
 * Returns the compatibility counts for database tables.
 *
 * @return array<string, int> The chart data.
 */
function wvc_get_database_chart_data() {
	$summary = Database_Audit::run()['summary'];

	return array(
		'compatible'     => (int) $summary['compatible'],
		'needs_review'   => 0,
		'not_compatible' => (int) $summary['incompatible'],
	);
}

/**
 * Returns the compatibility counts for the wp-content directory.
 *
 * @return array<string, int> The chart data.
 */
function wvc_get_directories_chart_data() {
	$summary = Directory_Audit::run()['summary'];

	// "Not deployed" entries such as uploads/ and upgrade/ are neither a pass
	// nor a failure, so they are counted as informational rather than folded
	// into the incompatible total the way the original implementation did.
	return array(
		'compatible'     => (int) $summary['supported'],
		'needs_review'   => (int) $summary['review'] + (int) $summary['informational'],
		'not_compatible' => (int) $summary['unsupported'],
	);
}
