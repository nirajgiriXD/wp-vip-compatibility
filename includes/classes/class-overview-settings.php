<?php
/**
 * Submenu page for displaying the settings overview.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * Handles the settings overview.
 */
class Overview_Settings {

	use Singleton;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Renders the settings page HTML.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		echo '<h1>' . esc_html__( 'WordPress VIP Compatibility (WVC)', 'wp-vip-compatibility' ) . '</h1>';

		// Paragraphs to display on the settings page.
		$paragraphs = [
			esc_html__(
				'This plugin is a great starting point for analyzing the compatibility of a standard WordPress site with the WordPress VIP platform. It scans your site for potential issues by identifying unsupported plugins, directories, database configurations, and other incompatibilities with VIP requirements.',
				'wp-vip-compatibility'
			),
			esc_html__(
				"In addition to highlighting compatibility issues, the plugin also provides several options to address and fix known problems. However, it's important to note that this plugin is not a complete solution for making a WordPress site fully VIP-compatible. It serves as a tool for identifying and resolving common issues, but further manual adjustments and optimizations may be required to meet the platform's strict standards.",
				'wp-vip-compatibility'
			),
			esc_html__(
				'While this plugin can fix certain issues, it should be seen as an initial tool to help assess and prepare your site for VIP migration. For more advanced optimizations and compliance, a thorough manual review may still be required.',
				'wp-vip-compatibility'
			),
		];

		// Render each paragraph.
		foreach ( $paragraphs as $paragraph ) {
			$this->render_paragraph( $paragraph );
		}

		// Render documentation link.
		$this->render_documentation_link();
	}

	/**
	 * Renders a paragraph.
	 *
	 * @param string $content The paragraph content.
	 */
	private function render_paragraph( $content ) {
		echo '<p>' . esc_html( $content ) . '</p>';
	}

	/**
	 * Renders the documentation link section.
	 *
	 * @return void
	 */
	private function render_documentation_link() {
		$doc_url = 'https://docs.wpvip.com/';
		$doc_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $doc_url ),
			esc_html__( 'WordPress VIP Documentation', 'wp-vip-compatibility' )
		);

		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: URL to the documentation */
				esc_html__( 'For more detailed guidelines and in-depth explanations on how to make your WordPress site fully compatible with the VIP platform, please visit the official %s.', 'wp-vip-compatibility' ),
				$doc_link
			)
		) . '</p>';
	}
}
