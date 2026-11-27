<?php
/**
 * Tests for the plugin, theme and must-use plugin screens.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Classes;

use PREFLIGHT_AUDIT\Includes\Classes\Mu_Plugins_Settings;
use PREFLIGHT_AUDIT\Includes\Classes\Plugins_Settings;
use PREFLIGHT_AUDIT\Includes\Classes\Themes_Settings;
use PREFLIGHT_AUDIT\Tests\Support\Sandbox;
use PREFLIGHT_AUDIT\Tests\Support\WP;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Inventory_Screen
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Plugins_Settings
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Themes_Settings
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Mu_Plugins_Settings
 */
class InventoryScreensTest extends TestCase {

	/**
	 * Renders a screen.
	 *
	 * @param string $class The screen class.
	 * @return string The output.
	 */
	private function screen( $class ) {
		$screen = $this->instance( $class );

		return $this->render(
			static function () use ( $screen ) {
				$screen->render_settings_page();
			}
		);
	}

	/**
	 * Returns the markup of the row for a target key.
	 *
	 * @param string $output The screen output.
	 * @param string $key    The target key.
	 * @return string The row and its detail drawer.
	 */
	private function row( $output, $key ) {
		$start = strpos( $output, 'data-target="' . $key . '"' );

		$this->assertNotFalse( $start, "No row for {$key}" );

		$open  = strrpos( substr( $output, 0, $start ), '<tr class="pfa-row"' );
		$close = strpos( $output, '</tr>', strpos( $output, 'pfa-row-detail', $start ) );

		return substr( $output, $open, $close - $open );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shared behaviour
	 * ------------------------------------------------------------------
	 */

	/**
	 * @dataProvider empty_screens
	 */
	public function test_an_empty_list_says_so( $class, $text ) {
		Sandbox::remove( WPMU_PLUGIN_DIR );

		$this->assertStringContainsString( $text, $this->screen( $class ) );
	}

	public function empty_screens() {
		return array(
			'plugins'  => array( Plugins_Settings::class, 'pfa-empty' ),
			'themes'   => array( Themes_Settings::class, 'pfa-empty' ),
			'must-use' => array( Mu_Plugins_Settings::class, 'pfa-empty' ),
		);
	}

	/**
	 * @dataProvider guidance_screens
	 */
	public function test_every_screen_has_help_notes( $class ) {
		$this->assertNotEmpty( $this->instance( $class )->get_page_guidance() );
	}

	public function guidance_screens() {
		return array(
			array( Plugins_Settings::class ),
			array( Themes_Settings::class ),
			array( Mu_Plugins_Settings::class ),
		);
	}

	public function test_rows_carry_the_verdict_and_an_unscanned_row_is_pending() {
		$this->store_result( $this->add_plugin( 'scanned' ), array( $this->finding( 'security.command-execution' ) ) );
		$this->add_plugin( 'fresh' );

		$output = $this->screen( Plugins_Settings::class );

		$scanned = $this->row( $output, 'plugin:scanned' );
		$fresh   = $this->row( $output, 'plugin:fresh' );

		$this->assertStringContainsString( 'data-status="review"', $scanned );
		$this->assertStringContainsString( 'Review 1 finding', $scanned );
		$this->assertStringContainsString( '1 finding: must fix', $scanned );
		$this->assertStringContainsString( 'Code read', $scanned );

		$this->assertStringContainsString( 'data-status="pending"', $fresh );
		$this->assertStringContainsString( 'Not scanned yet', $fresh );
		$this->assertStringNotContainsString( 'Code read', $fresh );
	}

	public function test_a_clean_scanned_row_says_none() {
		$this->store_result( $this->add_plugin( 'clean' ), array() );

		$this->assertStringContainsString( '<span class="pfa-clear">None</span>', $this->row( $this->screen( Plugins_Settings::class ), 'plugin:clean' ) );
	}

	public function test_the_verdict_filter_reads_the_query_string() {
		$this->add_plugin( 'a' );

		$_GET['status'] = 'review';
		$this->assertMatchesRegularExpression( '#class="active"\s+data-filter-value="review"#', $this->screen( Plugins_Settings::class ) );

		$_GET['status'] = 'nonsense';
		$this->assertMatchesRegularExpression( '#class="active"\s+data-filter-value="all"#', $this->screen( Plugins_Settings::class ) );
	}

	public function test_names_authors_and_versions_from_headers_are_escaped() {
		$this->add_plugin(
			'evil',
			array(),
			array(
				'Name'    => '<script>alert(1)</script>',
				'Author'  => '<b onmouseover=alert(2)>me</b>',
				'Version' => '"><img src=x>',
			)
		);

		$output = $this->screen( Plugins_Settings::class );

		$this->assertStringNotContainsString( '<script>alert', $output );
		$this->assertStringNotContainsString( 'onmouseover', $output );
		$this->assertStringNotContainsString( '<img src=x>', $output );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Plugins
	 * ------------------------------------------------------------------
	 */

	public function test_plugins_are_marked_active_or_inactive() {
		$this->add_plugin( 'on' );
		$this->add_plugin( 'off' );
		WP::$options[1]['active_plugins'] = array( 'on/on.php' );

		$output = $this->screen( Plugins_Settings::class );

		$this->assertStringContainsString( 'data-status="pending" data-state="active"', $this->row( $output, 'plugin:on' ) );
		$this->assertStringContainsString( 'data-state="inactive"', $this->row( $output, 'plugin:off' ) );
		$this->assertStringContainsString( 'data-filter-group="state"', $output );
	}

	public function test_network_activated_plugins_count_as_active_on_multisite() {
		$this->add_plugin( 'network' );
		WP::$multisite                              = true;
		WP::$site_options['active_sitewide_plugins'] = array( 'network/network.php' => time() );

		$this->assertStringContainsString( 'data-state="active"', $this->row( $this->screen( Plugins_Settings::class ), 'plugin:network' ) );
	}

	public function test_the_activation_filter_reads_the_query_string() {
		$this->add_plugin( 'a' );
		$_GET['state'] = 'inactive';

		$this->assertMatchesRegularExpression( '#class="active"\s+data-filter-value="inactive"#', $this->screen( Plugins_Settings::class ) );
	}

	public function test_an_available_update_is_shown_next_to_the_version() {
		$this->add_plugin( 'old', array(), array( 'Version' => '1.0.0' ) );
		WP::$site_transients['update_plugins'] = (object) array(
			'response' => array( 'old/old.php' => (object) array( 'new_version' => '2.0.0' ) ),
		);

		$this->assertStringContainsString( '→ 2.0.0', $this->row( $this->screen( Plugins_Settings::class ), 'plugin:old' ) );
	}

	public function test_a_listed_incompatible_plugin_explains_itself() {
		$this->add_plugin( 'wp-super-cache' );

		$row = $this->row( $this->screen( Plugins_Settings::class ), 'plugin:wp-super-cache' );

		$this->assertStringContainsString( 'data-status="not-compatible"', $row );
		$this->assertStringContainsString( 'Listed incompatible by WordPress VIP', $row );
		$this->assertStringContainsString( 'href="https://docs.wpvip.com/plugins/incompatibilities/"', $row );
		$this->assertStringContainsString( 'pfa-def--bad', $row );
	}

	public function test_a_redundant_plugin_does_not_repeat_its_label() {
		$this->add_plugin( 'jetpack' );

		$row = $this->row( $this->screen( Plugins_Settings::class ), 'plugin:jetpack' );

		$this->assertStringNotContainsString( 'Provided by the WordPress VIP platform — Provided', $row );
	}

	public function test_the_location_is_shown_relative_to_wp_content() {
		$this->add_plugin( 'p' );

		$this->assertStringContainsString( '<code>wp-content/plugins/p</code>', $this->screen( Plugins_Settings::class ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Themes
	 * ------------------------------------------------------------------
	 */

	public function test_the_active_theme_and_its_parent_are_marked() {
		$this->add_theme( 'child' );
		$this->add_theme( 'parent' );
		$this->add_theme( 'spare' );
		WP::$active_theme = array(
			'stylesheet' => 'child',
			'template'   => 'parent',
		);

		$output = $this->screen( Themes_Settings::class );

		$this->assertStringContainsString( 'data-role="live"', $this->row( $output, 'theme:child' ) );
		$this->assertStringContainsString( '>Active</span>', $this->row( $output, 'theme:child' ) );
		$this->assertStringContainsString( '>Parent</span>', $this->row( $output, 'theme:parent' ) );
		$this->assertStringContainsString( 'data-role="inactive"', $this->row( $output, 'theme:spare' ) );
		$this->assertStringNotContainsString( 'pfa-badge', $this->row( $output, 'theme:spare' ) );
	}

	public function test_a_theme_update_is_shown() {
		$this->add_theme( 'old', array(), array( 'Version' => '1.0' ) );
		WP::$site_transients['update_themes'] = (object) array(
			'response' => array( 'old' => array( 'new_version' => '1.1' ) ),
		);

		$this->assertStringContainsString( '→ 1.1', $this->screen( Themes_Settings::class ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Must-use plugins
	 * ------------------------------------------------------------------
	 */

	public function test_must_use_screen_explains_why_nothing_migrates_as_is() {
		$this->add_mu_plugin( 'loader.php' );

		$output = $this->screen( Mu_Plugins_Settings::class );

		$this->assertStringContainsString( 'None of this ships as-is', $output );
		$this->assertStringContainsString( '<code>client-mu-plugins/</code>', $output );
	}

	public function test_must_use_files_and_directories_are_told_apart() {
		$this->add_mu_plugin( 'loader.php' );
		Sandbox::write( 'mu-plugins/platform/platform.php', '<?php' );
		\PREFLIGHT_AUDIT\Includes\Scanner\Targets::reset();

		$output = $this->screen( Mu_Plugins_Settings::class );

		$this->assertStringContainsString( 'data-kind="file"', $this->row( $output, 'mu-plugin:loader.php' ) );
		$this->assertStringContainsString( 'data-kind="directory"', $this->row( $output, 'mu-plugin:platform' ) );
		$this->assertStringContainsString( 'wp-content/mu-plugins/loader.php', $output );
		$this->assertStringContainsString( 'Move it into client-mu-plugins/', $this->row( $output, 'mu-plugin:loader.php' ) );
	}

	public function test_a_preinstalled_must_use_plugin_shows_its_note() {
		$this->add_mu_plugin( 'akismet.php' );

		$row = $this->row( $this->screen( Mu_Plugins_Settings::class ), 'mu-plugin:akismet.php' );

		$this->assertStringContainsString( 'Do not ship', $row );
		$this->assertStringContainsString( 'Preinstalled by the WordPress VIP platform', $row );
		$this->assertStringContainsString( 'On the WordPress VIP list', $row );
		$this->assertStringNotContainsString( 'Move it into client-mu-plugins/', $row );
	}
}
