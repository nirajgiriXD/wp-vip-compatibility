<?php
/**
 * Submenu page for displaying the settings overview.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * This class handles the settings overview.
 */
class Overview_Settings {

	use Singleton;

	/**
	 * Constructor method is used to initialize the fields.
	 */
	public function __construct() {}

	/**
	 * Render the settings page html.
	 *
	 * @return void
	 */
	public function render_settings_page() {

		?>
		<h1><?php esc_html_e( 'WordPress VIP Compatibility (WVC) Plugin Overview', 'wp-vip-compatibility' ); ?></h1>

		<p>
			<?php esc_html_e( 'This plugin is a great starting point for analyzing the compatibility of a standard WordPress site with the WordPress VIP platform. It scans your site for potential issues by identifying unsupported plugins, directories, database configurations, and other incompatibilities with VIP requirements.', 'wp-vip-compatibility' ) ?>
		</p>

		<p>
			<?php esc_html_e( "In addition to highlighting compatibility issues, the plugin also provides several options to address and fix known problems. However, it's important to note that this plugin is not a complete solution for making a WordPress site fully VIP-compatible. It serves as a tool for identifying and resolving common issues, but further manual adjustments and optimizations may be required to meet the platform's strict standards.", 'wp-vip-compatibility' ) ?>
		</p>

		<p>
			<?php esc_html_e( 'While this plugin can fix certain issues, it should be seen as an initial tool to help assess and prepare your site for VIP migration. For more advanced optimizations and compliance, a thorough manual review may still be required.', 'wp-vip-compatibility' ) ?>
		</p>

		<p>
			<?php echo esc_html__( "For more detailed guidelines and in-depth explanations on how to make your WordPress site fully compatible with the VIP platform, please visit the official ", 'wp-vip-compatibility' ) 
			. '<a href="https://docs.wpvip.com/" target="_blank" rel="noopener noreferrer">' 
			. esc_html__( 'WordPress VIP Documentation', 'wp-vip-compatibility' ) 
			. '</a>' 
			. esc_html__( ". You'll find comprehensive resources, best practices, and coding standards to help ensure your site's smooth migration to VIP.", 'wp-vip-compatibility' ); ?>
		</p>
		<?php
	}

}