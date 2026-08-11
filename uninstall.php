<?php
/**
 * Uninstall routine.
 *
 * Scan results are stored in the options table, so uninstalling has to clean
 * them up. Deactivation deliberately does not: keeping the results means
 * re-activating the plugin does not force a full re-scan.
 *
 * @package wp-vip-compatibility
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/helpers/class-autoloader.php';

if ( ! defined( 'WP_VIP_COMPATIBILITY_DIR' ) ) {
	define( 'WP_VIP_COMPATIBILITY_DIR', __DIR__ );
}

use WP_VIP_COMPATIBILITY\Includes\Scanner\Database_Audit;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;

/**
 * Removes the plugin's stored data from one site.
 *
 * @return void
 */
function wvc_uninstall_site() {
	Results_Store::delete_all();
	Database_Audit::flush();
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		wvc_uninstall_site();
		restore_current_blog();
	}
} else {
	wvc_uninstall_site();
}
