<?php
/**
 * Uninstall routine.
 *
 * Scan results are stored in the options table, so uninstalling has to clean
 * them up. Deactivation deliberately does not: keeping the results means
 * re-activating the plugin does not force a full re-scan.
 *
 * @package switchrail-audit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/helpers/class-autoloader.php';

if ( ! defined( 'SWITCHRAIL_AUDIT_DIR' ) ) {
	define( 'SWITCHRAIL_AUDIT_DIR', __DIR__ );
}

use SWITCHRAIL_AUDIT\Includes\Scanner\Database_Audit;
use SWITCHRAIL_AUDIT\Includes\Scanner\Results_Store;

/**
 * Removes the plugin's stored data from one site.
 *
 * @return void
 */
function switchrail_audit_uninstall_site() {
	Results_Store::delete_all();
	Database_Audit::flush();
}

if ( is_multisite() ) {
	$switchrail_audit_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $switchrail_audit_site_ids as $switchrail_audit_site_id ) {
		switch_to_blog( $switchrail_audit_site_id );
		switchrail_audit_uninstall_site();
		restore_current_blog();
	}
} else {
	switchrail_audit_uninstall_site();
}
