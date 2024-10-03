<?php
/**
 * Class to handle plugin settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;

/**
 * Class to handle plugin settings.
 */
class Settings {

	use Singleton;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->setup_hooks();
	}
	
	/**
	 * This function is used to setup hooks.
	 *
	 * @return void
	 */
	private function setup_hooks() {
		// Actions.
		add_action( 'admin_menu', array( $this, 'add_plugin_menus' ) );
	}

	/**
	 * Adds the plugin menus.
	 *
	 * @return void
	 */
	public function add_plugin_menus() {
		add_menu_page(
			__( 'WordPress VIP Compatibility', 'wp-vip-compatibility' ),
			__( 'WVC', 'wp-vip-compatibility' ),
			'manage_options',
			'wp-vip-compatibility',
			array( $this, 'render_settings_page' ),
			'dashicons-admin-generic'
		);
	}

	/**
	 * Renders the plugin overview page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<div class="wvc-container">
				<!-- Tabs -->
				<div class="wvc-tabs">
					<button class="tab-link active" data-tab="overview"><?php esc_html_e( 'Overview', 'wp-vip-compatibility' ) ?></button>
					<button class="tab-link" data-tab="database"><?php esc_html_e( 'Database', 'wp-vip-compatibility' ) ?></button>
					<button class="tab-link" data-tab="plugins"><?php esc_html_e( 'Plugins', 'wp-vip-compatibility' ) ?></button>
					<button class="tab-link" data-tab="themes"><?php esc_html_e( 'Themes', 'wp-vip-compatibility' ) ?></button>
					<button class="tab-link" data-tab="directories"><?php esc_html_e( 'Directories', 'wp-vip-compatibility' ) ?></button>
					<button class="tab-link" data-tab="faq"><?php esc_html_e( 'FAQ', 'wp-vip-compatibility' ) ?></button>
				</div>

				<!-- Tab Contents -->
				<div id="overview" class="wvc-tab-content active">
					<?php $this->render_overview_page(); ?>
				</div>

				<div id="database" class="wvc-tab-content">
					<?php $this->render_database_page(); ?>
				</div>

				<div id="plugins" class="wvc-tab-content">
					<?php $this->render_plugins_page(); ?>
				</div>

				<div id="themes" class="wvc-tab-content">
					<?php $this->render_themes_page(); ?>
				</div>

				<div id="directories" class="wvc-tab-content">
					<?php $this->render_directories_page(); ?>
				</div>

				<div id="faq" class="wvc-tab-content">
					<?php $this->render_faq_page() ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_overview_page() {
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

	public function render_database_page() {
		global $wpdb;

		// Supported collations for WordPress VIP.
		$vip_supported_collations = array(
			'utf8mb4_general_ci',
			'utf8mb4_bin',
			'utf8mb4_unicode_ci',
			'utf8mb4_icelandic_ci',
			'utf8mb4_latvian_ci',
			'utf8mb4_romanian_ci',
			'utf8mb4_slovenian_ci',
			'utf8mb4_polish_ci',
			'utf8mb4_estonian_ci',
			'utf8mb4_spanish_ci',
			'utf8mb4_swedish_ci',
			'utf8mb4_turkish_ci',
			'utf8mb4_czech_ci',
			'utf8mb4_danish_ci',
			'utf8mb4_lithuanian_ci',
			'utf8mb4_slovak_ci',
			'utf8mb4_spanish2_ci',
			'utf8mb4_roman_ci',
			'utf8mb4_persian_ci',
			'utf8mb4_esperanto_ci',
			'utf8mb4_hungarian_ci',
			'utf8mb4_sinhala_ci',
			'utf8mb4_german2_ci',
			'utf8mb4_croatian_mysql561_ci',
			'utf8mb4_unicode_520_ci',
			'utf8mb4_vietnamese_ci',
			'utf8mb4_croatian_ci',
			'utf8mb4_myanmar_ci',
			'utf8mb4_thai_520_w2',
			'utf8mb4_general_nopad_ci',
			'utf8mb4_nopad_bin',
			'utf8mb4_unicode_nopad_ci',
			'utf8mb4_unicode_520_nopad_ci',
		);

		// Query to get tables with their collation and engine.
		$tables = $wpdb->get_results("
			SELECT 
				TABLE_NAME, 
				TABLE_COLLATION, 
				ENGINE 
			FROM information_schema.TABLES 
			WHERE TABLE_SCHEMA = '" . DB_NAME . "'"
		);

		if ( empty( $tables ) ) {
			echo '<p>' . esc_html__( 'No tables found in the database.', 'wp-vip-compatibility' ) . '</p>';
			return;
		}
		
		// Start HTML table
		echo '<table class="wvc-table">';
		echo '<thead><tr>
				<th>' . esc_html__( 'SN','wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Table Name','wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Engine','wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Collation','wp-vip-compatibility' ) . '</th>
				<th>' . esc_html__( 'Additional Notes','wp-vip-compatibility' ) . '</th>
			  </tr></thead>';
		echo '<tbody>';
	
		// Loop through the tables and check for compatibility
		$counter = 1;
		foreach ( $tables as $table ) {
			$engine                = $table->ENGINE;
			$vip_supported         = in_array( $table->TABLE_COLLATION, $vip_supported_collations );
			$has_supported_prefix  = strpos( $table->TABLE_NAME, 'wp_' ) === 0;
			$additional_note_class = 'not-compatible';
	
			// Determine additional notes.
			$notes = array();
			if ( ! $vip_supported ) {
				$notes[] = esc_html__( 'Unsupported Collation', 'wp-vip-compatibility' );
			} 
			if ( $engine != 'InnoDB' ) {
				$notes[] = 'Incompatible Engine';
			}
			if ( ! $has_supported_prefix ) {
				$notes[] = esc_html__( 'Unsupported Prefix', 'wp-vip-compatibility' );
			}
			if ( empty( $notes ) ) {
				$notes[] = esc_html__( 'Compatible', 'wp-vip-compatibility' );
				$additional_note_class = 'compatible';
			}
	
			// Echo row
			echo '<tr>';
			echo '<td>' . esc_html( $counter++ ) . '</td>';
			echo '<td>' . esc_html( $table->TABLE_NAME ) . '</td>';
			echo '<td>' . esc_html( $table->ENGINE ) . '</td>';
			echo '<td>' . esc_html( $table->TABLE_COLLATION ) . '</td>';
			echo '<td class="' . $additional_note_class . '">' . esc_html( implode( ', ', $notes ) ) . '</td>';
			echo '</tr>';
		}
	
		echo '</tbody></table>';
	}

	public function render_plugins_page() {
		$vip_mu_plugins = array(
			'advanced-post-cache',
			'akismet',
			'block-api-data',
			'cron-control',
			'elasticpress',
			'elasticpress-next',
			'es-wp-query',
			'debug-bar-elasticpress',
			'jetpack',
			'lightweight-term-count-update',
			'nginx-http-concat',
			'query-monitor',
			'rewrite-rules-inspector',
			'two-factor',
			'wp-parsely',
		);

		$vip_disallowed_plugins = array(
			'all-in-one-wp-migration',
			'wp-all-import',
			'wp-all-import-pro',
			'wp-all-export',
			'wp-all-export-pro',
			'w3-total-cache',
			'wp-fastest-cache',
			'wp-rocket',
			'wp-super-cache',
			'wp-smushit',
			'sucuri-scanner',
			'wordfence',
		);

		$known_plugins = array(
			'aryo-activity-log'                  => array(
				'2.10.1' => 'Yes',
			),
			'memberpress-importer'               => array(
				'1.6.18' => 'Yes',
			),
			'memberpress-pdf-invoice'            => array(
				'1.1.23' => 'Yes',
			),
			'memberpress'                        => array(
				'1.11.28' => 'Yes',
			),
			'miniorange-2-factor-authentication' => array(
				'5.8.3' => 'Yes',
			),
			'miniorange-saml-20-single-sign-on'  => array(
				'5.1.4' => 'Yes',
			),
			'post-smtp'                          => array(
				'2.9.1' => 'Yes',
			),
			'cc-post-to-pdf'                     => array(
				'2.0' => 'Yes',
			),
			'tiny-compress-images'               => array(
				'3.4.3' => 'Yes',
			),
			'wpdatatables'                       => array(
				'3.4.2.11' => 'Yes',
			),
			'wp-file-manager'                    => array(
				'7.2.6' => 'Yes',
			),
			'wpforms'                            => array(
				'1.8.7.2' => 'Yes',
			),
			'zoom-meeting'                       => array(
				'1.0' => 'Yes',
			),
		);

		$wp_engine_mu_plugins = array(
			'force-strong-passwords'         => 'No',
			'mu-plugin.php'                  => 'No',
			'slt-force-strong-passwords.php' => 'No',
			'wpe-cache-plugin'               => 'No',
			'wpe-cache-plugin.php'           => 'No',
			'wpe-wp-sign-on-plugin'          => 'No',
			'wpe-wp-sign-on-plugin.php'      => 'No',
			'wpengine-common'                => 'No',
			'wpengine-security-auditor.php'  => 'No',
		);

		$already_tested_plugins = array(
			'advanced-custom-fields-pro',
			'advanced-custom-fields',
		);


	}

	public function render_themes_page() {
		?>
		<h1><?php esc_html_e( 'Themes', 'wp-vip-compatibility' ); ?></h1>
		<p><?php esc_html_e( 'Detailed instructions on how to use the plugin will be placed here.', 'wp-vip-compatibility' ); ?></p>
		<?php
	}

	public function render_directories_page() {
		?>
		<h1><?php esc_html_e( 'Directories', 'wp-vip-compatibility' ); ?></h1>
		<p><?php esc_html_e( 'Detailed instructions on how to use the plugin will be placed here.', 'wp-vip-compatibility' ); ?></p>
		<?php
	}

	public function render_faq_page() {
		?>
		<h1><?php esc_html_e( 'Frequently Asked Questions', 'wp-vip-compatibility' ); ?></h1>
		<p><?php esc_html_e( 'Answers to the most common questions about the plugin.', 'wp-vip-compatibility' ); ?></p>
		<?php
	}

	/**
	 * Scan the directory using PHPCS.
	 *
	 * @param string $directory The directory to scan.
	 * @param string $standards The PHPCS standards to use. Default is 'WordPress'.
	 * @param int    $test_version The PHPCS test version. Default is 8.
	 * @param string $report The PHPCS report format. Default is 'csv'.
	 * @param int    $severity The PHPCS severity level. Default is 6.
	 * @param string $memory The memory limit for PHPCS. Default is '2048M'.
	 * @return string|null The output of the PHPCS command.
	 */
	public function phpcs_scan( $directory, $standards = 'WordPress', $test_version = 8, $report = 'csv', $severity = 6, $memory = '2048M' ) {

		$command = sprintf( 'phpcs --standard=%s --severity=%d --ignore="*/vendor/*" --runtime-set testVersion %d --extensions=php --report=%s -d memory_limit=%s "%s"', $standards, $severity, $test_version, $report, $memory, $directory );

		$this->log( 'Using command: ' . $command );

		if ( $this->dry_run ) {
			return;
		}

		return shell_exec( $command );
	}
}