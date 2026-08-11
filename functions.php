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
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
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
 * @since 2.0.0 Delegates to Report::area_counts(), which is the single place the
 *              verdict for a target is decided. This function used to resolve it
 *              a second time, with its own rules, so a plugin could be counted
 *              one way here and displayed another way on screen.
 *
 * @param string $type One of `plugin`, `theme`, `mu-plugin`, `database`, `directories`.
 * @return array<string, int> Counts keyed `compatible`, `needs_review`, `not_compatible`.
 */
function wvc_get_target_chart_data( $type ) {
	$counts = Report::area_counts( $type );

	return array(
		'compatible'     => $counts['ready'],
		// An unscanned target is not a verdict, but a caller of this legacy
		// signature has nowhere to put one, so it stays on the cautious side.
		'needs_review'   => $counts['review'] + $counts['pending'],
		'not_compatible' => $counts['blocked'],
	);
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
	return wvc_get_target_chart_data( 'database' );
}

/**
 * Returns the compatibility counts for the wp-content directory.
 *
 * @return array<string, int> The chart data.
 */
function wvc_get_directories_chart_data() {
	return wvc_get_target_chart_data( 'directories' );
}
