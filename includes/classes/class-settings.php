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
					<button class="tab-link" data-tab="mu-plugins"><?php esc_html_e( 'MU Plugins', 'wp-vip-compatibility' ) ?></button>
					<button class="tab-link" data-tab="plugins"><?php esc_html_e( 'Plugins', 'wp-vip-compatibility' ) ?></button>
					<button class="tab-link" data-tab="themes"><?php esc_html_e( 'Themes', 'wp-vip-compatibility' ) ?></button>
					<button class="tab-link" data-tab="directories"><?php esc_html_e( 'Directories', 'wp-vip-compatibility' ) ?></button>
				</div>

				<!-- Tab Contents -->
				<div id="overview" class="wvc-tab-content active">
					<?php $this->render_overview_page(); ?>
				</div>

				<div id="database" class="wvc-tab-content">
					<?php $this->render_database_page(); ?>
				</div>

				<div id="mu-plugins" class="wvc-tab-content">
					<?php $this->render_mu_plugins_page() ?>
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

		// Path to the JSON file containing table source data.
		$table_source_path = WP_VIP_COMPATIBILITY_DIR . '/data/plugins-tables-list.json';

		// Read the JSON file contents
		$table_source_data = file_get_contents( $table_source_path );

		// Decode the JSON data into an associative array
		$table_source_data_array = json_decode( $table_source_data, true );

		// Check if decoding was successful
		if ( null === $table_source_data_array && json_last_error() !== JSON_ERROR_NONE) {
			$table_source_data_array = array();
		}

		// Core tables that are not prefixed with 'wp_'.
		$core_tables = array(
			'commentmeta'        => array( 'wordpress-core' ),
			'comments'           => array( 'wordpress-core' ),
			'links'              => array( 'wordpress-core' ),
			'options'            => array( 'wordpress-core' ),
			'postmeta'           => array( 'wordpress-core' ),
			'posts'              => array( 'wordpress-core' ),
			'termmeta'           => array( 'wordpress-core' ),
			'terms'              => array( 'wordpress-core' ),
			'term_relationships' => array( 'wordpress-core' ),
			'term_taxonomy'      => array( 'wordpress-core' ),
			'usermeta'           => array( 'wordpress-core' ),
			'users'              => array( 'wordpress-core' ),
		);

		// Merge core tables with the JSON data.
		$table_source_data_array = array_merge( $table_source_data_array, $core_tables );

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
				<th>' . esc_html__( 'Source','wp-vip-compatibility' ) . '</th>
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
			$table_name            = $table->TABLE_NAME;

			// First, check if the table is in the JSON file as it is.
			$source = isset( $table_source_data_array[ $table_name ] ) ? implode( ', ', $table_source_data_array[ $table_name ] ) : null;

			// If not found, try removing the 'wp_' prefix and check again.
			if ( ! $source && $has_supported_prefix ) {
				$prefix_removed_table_name = preg_replace( '/^wp_/', '', $table_name );
				$source = isset( $table_source_data_array[ $prefix_removed_table_name ] ) ? implode( ', ', $table_source_data_array[ $prefix_removed_table_name ] ) : '-';
			} else {
				$source = $source ?: '-';
			}

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
			echo '<td>' . esc_html( $table_name ) . '</td>';
			echo '<td>' . esc_html( $table->ENGINE ) . '</td>';
			echo '<td>' . esc_html( $table->TABLE_COLLATION ) . '</td>';
			echo '<td>' . esc_html( $source ) . '</td>';
			echo '<td class="' . $additional_note_class . '">' . esc_html( implode( ', ', $notes ) ) . '</td>';
			echo '</tr>';
		}
	
		echo '</tbody></table>';
	}

	public function render_mu_plugins_page() {
		// Load necessary WordPress functions.
        if ( ! function_exists( 'get_mu_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Get all installed MU plugins
        $mu_plugins = get_mu_plugins();

        ?>
        <table class="wvc-table">
            <thead>
                <tr>
                    <th>Plugin Name</th>
                    <th>Path</th>
                    <th>Plugin File Path</th>
                    <th>Plugin Version</th>
                    <th>Author</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $mu_plugins as $plugin_file => $plugin_data ) : ?>
                    <tr>
                        <td><?php echo esc_html( $plugin_data['Name'] ); ?></td>
                        <td><?php echo esc_html( $plugin_data['PluginURI'] ); ?></td>
                        <td><?php echo esc_html( $plugin_file ); ?></td>
                        <td><?php echo isset( $plugin_data['Version'] ) ? esc_html( $plugin_data['Version'] ) : 'N/A'; ?></td>
                        <td><?php echo esc_html( $plugin_data['Author'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
		<?php
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

		// Load the necessary WordPress functions.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Get the list of all installed plugins.
		$all_plugins = get_plugins();

		// Get information about plugin updates (must be done in the admin area)
		wp_update_plugins();

		// Get the list of available plugin updates
		$plugin_updates = get_site_transient( 'update_plugins' );
		?>
		<table class="wvc-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plugin Name', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Version', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Author', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'Plugin File Path', 'wp-vip-compatibility' ); ?></th>
					<th><?php esc_html_e( 'New Version Available', 'wp-vip-compatibility' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $all_plugins as $plugin_file => $plugin_data ) : ?>
					<tr>
						<td><?php echo esc_html( $plugin_data['Name'] ); ?></td>
						<td><?php echo esc_html( $plugin_data['Version'] ); ?></td>
						<td><?php echo esc_html( $plugin_data['Author'] ); ?></td>
						<td><?php echo esc_html( $plugin_file ); ?></td>
						<td>
							<?php
							// Check if an update is available for this plugin
							if ( isset( $plugin_updates->response[ $plugin_file ] ) ) {
								$new_version = $plugin_updates->response[ $plugin_file ]->new_version;
								echo esc_html( $new_version );
							} else {
								echo 'Up to date';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_themes_page() {
		// Load necessary WordPress functions for themes.
		if ( ! function_exists( 'wp_get_themes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		// Get all installed themes
		$all_themes = wp_get_themes();

		// Get information about theme updates (must be done in the admin area)
		wp_update_themes(); // This checks for theme updates

		// Get the list of available theme updates
		$theme_updates = get_site_transient( 'update_themes' );

		?>
		<table class="wvc-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Theme Name', 'wp-vip-compatibility' ) ?></th>
					<th><?php esc_html_e( 'Version', 'wp-vip-compatibility' ) ?></th>
					<th><?php esc_html_e( 'Author', 'wp-vip-compatibility' ) ?></th>
					<th><?php esc_html_e( 'Theme Directory', 'wp-vip-compatibility' ) ?></th>
					<th><?php esc_html_e( 'New Version Available', 'wp-vip-compatibility' ) ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $all_themes as $theme_slug => $theme_data ) : ?>
					<tr>
						<td><?php echo esc_html( $theme_data->get( 'Name' ) ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Version' ) ); ?></td>
						<td><?php echo esc_html( $theme_data->get( 'Author' ) ); ?></td>
						<td><?php echo esc_html( $theme_slug ); ?></td>
						<td>
							<?php
							// Check if an update is available for this theme
							if ( isset( $theme_updates->response[ $theme_slug ] ) ) {
								$new_version = $theme_updates->response[ $theme_slug ]['new_version'];
								echo esc_html( $new_version );
							} else {
								echo 'Up to date';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_directories_page() {
		// Path to the wp-content directory
		$wp_content_dir = WP_CONTENT_DIR;

		// List of supported directories and files as associative array
		$supported_items = array(
			'client-mu-plugins' => array(
				'description' => 'Client-specific must-use plugins.',
				'is_supported' => true
			),
			'docs' => array(
				'description' => 'Documentation related to the site.',
				'is_supported' => true
			),
			'images' => array(
				'description' => 'Images and media files.',
				'is_supported' => true
			),
			'languages' => array(
				'description' => 'Language files for translations.',
				'is_supported' => true
			),
			'plugins' => array(
				'description' => 'Installed plugins directory.',
				'is_supported' => true
			),
			'private' => array(
				'description' => 'Private files, restricted access.',
				'is_supported' => true
			),
			'themes' => array(
				'description' => 'Installed themes directory.',
				'is_supported' => true
			),
			'uploads' => array(
				'description' => 'WordPress uploads directory.',
				'is_supported' => false
			),
			'vip-config' => array(
				'description' => 'VIP platform configuration files.',
				'is_supported' => true
			),
			'.' => array(
				'description' => 'Current directory placeholder.',
				'is_supported' => false
			),
			'..' => array(
				'description' => 'Parent directory placeholder.',
				'is_supported' => false
			),
			'.editorconfig' => array(
				'description' => 'Code style configuration file.',
				'is_supported' => true
			),
			'.gitignore' => array(
				'description' => 'Git ignore file, lists files to ignore in version control.',
				'is_supported' => true
			),
			'.phpcs.xml.dist' => array(
				'description' => 'PHP CodeSniffer configuration file.',
				'is_supported' => true
			),
			'README.md' => array(
				'description' => 'Readme file, documentation overview.',
				'is_supported' => true
			),
			'composer.json' => array(
				'description' => 'Composer file for PHP dependencies.',
				'is_supported' => true
			),
			'composer.lock' => array(
				'description' => 'Composer lock file for dependency versions.',
				'is_supported' => true
			),
			'index.php' => array(
				'description' => 'Directory index file to prevent directory listing.',
				'is_supported' => false
			),
		);

		// Scan the wp-content directory
		$files_and_dirs = scandir($wp_content_dir);

		// Start table
		echo '<table class="wvc-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>Files and Folders</th>';
		echo '<th>Description</th>';
		echo '<th>Compatibility</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		// Loop through the files and directories
		foreach ( $files_and_dirs as $item ) {
			// Check if the current item is in the supported items array
			if ( isset( $supported_items[ $item ] ) ) {
				$description = $supported_items[ $item ]['description'];
				$is_supported = $supported_items[ $item ]['is_supported'];
				
				$additional_notes = $is_supported ? '<span style="color:green;">Compatible</span>' : '<span style="color:red;">Not Compatible</span>';
			} else {
				$description = 'Not Supported';
				$additional_notes = '<span style="color:red;">Not Compatible</span>';
			}
		
			// Output row
			echo '<tr>';
			echo '<td>' . esc_html( $item ) . '</td>';
			echo '<td>' . esc_html( $description ) . '</td>';
			echo '<td>' . $additional_notes . '</td>';
			echo '</tr>';
		}

		// End table
		echo '</tbody>';
		echo '</table>';
	}

}