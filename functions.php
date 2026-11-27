<?php
/**
 * Public helper functions.
 *
 * These wrap the scanner classes so that the signatures the plugin has always
 * exposed keep working. New code should use the classes in
 * `CUTOVER_AUDIT\Includes\Scanner` directly.
 *
 * @package cutover-audit
 */

use CUTOVER_AUDIT\Includes\Classes\Plugin;
use CUTOVER_AUDIT\Includes\Scanner\Report;
use CUTOVER_AUDIT\Includes\Scanner\Scanner;
use CUTOVER_AUDIT\Includes\Scanner\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the compatibility verdict for a scan target.
 *
 * Accepts a target key such as `plugin:akismet`. A filesystem path is also
 * accepted and resolved against the known targets, but a path that does not
 * belong to a known plugin, theme or must-use plugin is rejected rather than
 * scanned.
 *
 * @since 1.0.0
 *
 * @param string $target The target key, or a legacy filesystem path.
 * @return string One of `Compatible`, `Needs review`, `Incompatible`, or an error message.
 */
function cutover_audit_check_compatibility( $target ) {
	$resolved = cutover_audit_resolve_target( $target );

	if ( null === $resolved ) {
		return esc_html__( 'Error: Unknown scan target', 'cutover-audit' );
	}

	$result = ( new Scanner() )->get_result( $resolved );

	switch ( $result['status'] ) {
		case Scanner::STATUS_BLOCKED:
			return esc_html__( 'Incompatible', 'cutover-audit' );
		case Scanner::STATUS_REVIEW:
			return esc_html__( 'Needs review', 'cutover-audit' );
		default:
			return esc_html__( 'Compatible', 'cutover-audit' );
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
function cutover_audit_resolve_target( $target ) {
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
function cutover_audit_get_json_data() {
	return Plugin::get_instance()->get_json_data();
}

/**
 * Returns the compatibility counts for one target type.
 *
 * Delegates to Report::area_counts(), the single place the verdict for a
 * target is decided, so a target is counted here exactly as it is shown on
 * screen.
 *
 * @since 1.0.0
 *
 * @param string $type One of `plugin`, `theme`, `mu-plugin`, `database`, `directories`.
 * @return array<string, int> Counts keyed `compatible`, `needs_review`, `not_compatible`.
 */
function cutover_audit_get_target_chart_data( $type ) {
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
function cutover_audit_get_plugins_chart_data() {
	return cutover_audit_get_target_chart_data( 'plugin' );
}

/**
 * Returns the compatibility counts for must-use plugins.
 *
 * @return array<string, int> The chart data.
 */
function cutover_audit_get_mu_plugins_chart_data() {
	return cutover_audit_get_target_chart_data( 'mu-plugin' );
}

/**
 * Returns the compatibility counts for themes.
 *
 * @return array<string, int> The chart data.
 */
function cutover_audit_get_themes_chart_data() {
	return cutover_audit_get_target_chart_data( 'theme' );
}

/**
 * Returns the compatibility counts for database tables.
 *
 * @return array<string, int> The chart data.
 */
function cutover_audit_get_database_chart_data() {
	return cutover_audit_get_target_chart_data( 'database' );
}

/**
 * Returns the compatibility counts for the wp-content directory.
 *
 * @return array<string, int> The chart data.
 */
function cutover_audit_get_directories_chart_data() {
	return cutover_audit_get_target_chart_data( 'directories' );
}
