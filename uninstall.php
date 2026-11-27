<?php
/**
 * Uninstall routine.
 *
 * Scan results are stored in the options table, so uninstalling has to clean
 * them up. Deactivation deliberately does not: keeping the results means
 * re-activating the plugin does not force a full re-scan.
 *
 * @package cutover-audit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/helpers/class-autoloader.php';

if ( ! defined( 'CUTOVER_AUDIT_DIR' ) ) {
	define( 'CUTOVER_AUDIT_DIR', __DIR__ );
}

use CUTOVER_AUDIT\Includes\Scanner\Database_Audit;
use CUTOVER_AUDIT\Includes\Scanner\Results_Store;

/**
 * Removes the plugin's stored data from one site.
 *
 * @return void
 */
function cutover_audit_uninstall_site() {
	Results_Store::delete_all();
	Database_Audit::flush();
}

if ( is_multisite() ) {
	$cutover_audit_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $cutover_audit_site_ids as $cutover_audit_site_id ) {
		switch_to_blog( $cutover_audit_site_id );
		cutover_audit_uninstall_site();
		restore_current_blog();
	}
} else {
	cutover_audit_uninstall_site();
}
