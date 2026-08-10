<?php
/**
 * This file contains the class and methods to register and enqueue the required scripts and styles.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * This class is used to register and enqueue the required scripts and styles.
 */
class Assets {

	use Singleton;

	/**
	 * Constructor method is used to initialize the fields.
	 */
	public function __construct() {

		$this->setup_hooks();
	}

	/**
	 * To setup actions and filters.
	 *
	 * @return void
	 */
	private function setup_hooks() {

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	/**
	 * Returns the plugin screen currently being viewed, if any.
	 *
	 * Assets are scoped to the plugin's own screens so they never interfere with
	 * WordPress core styles or with other plugins' admin pages.
	 *
	 * @return string The settings key, or an empty string when off-screen.
	 */
	private function get_current_screen_key() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( '' === $page ) {
			return '';
		}

		foreach ( UI::get_screens() as $key => $screen ) {
			if ( $screen['slug'] === $page ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * To enqueue scripts and styles in admin.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {

		$screen_key = $this->get_current_screen_key();

		// Bail out on every screen that does not belong to this plugin.
		if ( '' === $screen_key ) {
			return;
		}

		// Enqueue custom style.
		if ( file_exists( WP_VIP_COMPATIBILITY_DIR . '/assets/css/admin.css' ) ) {
			wp_register_style(
				'wp-vip-compatibility-admin-styles',
				WP_VIP_COMPATIBILITY_URL . '/assets/css/admin.css',
				array(),
				filemtime( WP_VIP_COMPATIBILITY_DIR . '/assets/css/admin.css' )
			);
			wp_enqueue_style( 'wp-vip-compatibility-admin-styles' );
		}

		// Chart.js is only needed by the overview dashboard.
		$script_dependencies = array( 'jquery' );

		if ( 'overview' === $screen_key && file_exists( WP_VIP_COMPATIBILITY_DIR . '/assets/js/chart.js' ) ) {
			wp_enqueue_script(
				'wp-vip-compatibility-chart-js',
				WP_VIP_COMPATIBILITY_URL . '/assets/js/chart.js',
				array(),
				filemtime( WP_VIP_COMPATIBILITY_DIR . '/assets/js/chart.js' ),
				true
			);

			$script_dependencies[] = 'wp-vip-compatibility-chart-js';
		}

		// Enqueue custom script.
		if ( file_exists( WP_VIP_COMPATIBILITY_DIR . '/assets/js/admin.js' ) ) {
			wp_register_script(
				'wp-vip-compatibility-admin-script',
				WP_VIP_COMPATIBILITY_URL . '/assets/js/admin.js',
				$script_dependencies,
				filemtime( WP_VIP_COMPATIBILITY_DIR . '/assets/js/admin.js' ),
				true
			);
			wp_enqueue_script( 'wp-vip-compatibility-admin-script' );

			wp_localize_script(
				'wp-vip-compatibility-admin-script',
				'_WPVC_',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'wvc_ajax_nonce' ),
					'i18n'     => array(
						'checking'                => esc_html__( 'Checking...', 'wp-vip-compatibility' ),
						'error'                   => esc_html__( 'Error', 'wp-vip-compatibility' ),
						'unableToFetchData'       => esc_html__( 'Unable to fetch data.', 'wp-vip-compatibility' ),
						'unableToFetchLogDetails' => esc_html__( 'Unable to fetch log details.', 'wp-vip-compatibility' ),
						'noDataAvailable'         => esc_html__( 'No data available.', 'wp-vip-compatibility' ),
						'noChartData'             => esc_html__( 'No chart data available.', 'wp-vip-compatibility' ),

						/* New strings used by the redesigned interface. */
						'compatible'              => esc_html__( 'Compatible', 'wp-vip-compatibility' ),
						'incompatible'            => esc_html__( 'Incompatible', 'wp-vip-compatibility' ),
						/* translators: 1: Number of completed checks. 2: Total number of checks. */
						'scanning'                => esc_html__( 'Scanning %1$s of %2$s…', 'wp-vip-compatibility' ),
						'scanComplete'            => esc_html__( 'Scan complete.', 'wp-vip-compatibility' ),
						/* translators: 1: Number of visible rows. 2: Total number of rows. */
						'showingFiltered'         => esc_html__( 'Showing %1$s of %2$s', 'wp-vip-compatibility' ),
						/* translators: %s: Total number of rows. */
						'showingAll'              => esc_html__( 'Showing all %s', 'wp-vip-compatibility' ),
						'noResults'               => esc_html__( 'No matching results', 'wp-vip-compatibility' ),
						'noResultsHint'           => esc_html__( 'Try a different search term or switch the filter back to All.', 'wp-vip-compatibility' ),
						'copy'                    => esc_html__( 'Copy to clipboard', 'wp-vip-compatibility' ),
						'copied'                  => esc_html__( 'Copied', 'wp-vip-compatibility' ),
						'copyFailed'              => esc_html__( 'Press Ctrl+C to copy', 'wp-vip-compatibility' ),
						'nothingToCheck'          => esc_html__( 'Nothing to check', 'wp-vip-compatibility' ),
						/* translators: %s: Percentage of compatible items. */
						'readinessSummary'        => esc_html__( '%s of the items checked are ready for the VIP platform.', 'wp-vip-compatibility' ),
						'readinessPerfect'        => esc_html__( 'Everything checked so far is ready for the VIP platform.', 'wp-vip-compatibility' ),
						'readinessAttention'      => esc_html__( 'Review the highlighted sections below and resolve each incompatibility before migrating.', 'wp-vip-compatibility' ),
						'readinessUnavailable'    => esc_html__( 'Compatibility data could not be loaded for every section.', 'wp-vip-compatibility' ),
						'sortedAscending'         => esc_html__( 'Sorted ascending', 'wp-vip-compatibility' ),
						'sortedDescending'        => esc_html__( 'Sorted descending', 'wp-vip-compatibility' ),
					),
				)
			);
		}
	}
}
