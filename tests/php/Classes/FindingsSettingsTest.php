<?php
/**
 * Tests for the findings screen and the rescan handler.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Classes;

use PREFLIGHT_AUDIT\Includes\Classes\Findings_Settings;
use PREFLIGHT_AUDIT\Includes\Scanner\Database_Audit;
use PREFLIGHT_AUDIT\Includes\Scanner\Results_Store;
use PREFLIGHT_AUDIT\Tests\Support\Redirect;
use PREFLIGHT_AUDIT\Tests\Support\WP;
use PREFLIGHT_AUDIT\Tests\Support\Wp_Die;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\Findings_Settings
 */
class FindingsSettingsTest extends TestCase {

	const GUARD = "<?php\ndefined( 'ABSPATH' ) || exit;\n";

	const ADMIN = 'https://example.test/wp-admin/admin.php?page=';

	/**
	 * Posts the rescan form and returns where it redirected.
	 *
	 * @param string|null $scope The posted scope, or null for none.
	 * @return string The redirect target.
	 */
	private function rescan( $scope = null ) {
		if ( null !== $scope ) {
			$_POST['scope'] = $scope;
		}

		try {
			( new Findings_Settings() )->handle_rescan();
		} catch ( Redirect $redirect ) {
			return $redirect->location;
		}

		$this->fail( 'The rescan did not redirect' );
	}

	/**
	 * Renders the screen.
	 *
	 * @return string The output.
	 */
	private function screen() {
		return $this->render(
			function () {
				( new Findings_Settings() )->render_settings_page();
			}
		);
	}

	/**
	 * Stores findings in two plugins and gives the site one piece of audit work.
	 *
	 * @return void
	 */
	private function seed() {
		$this->store_result(
			$this->add_plugin( 'alpha', array(), array( 'Name' => 'Alpha' ) ),
			array(
				$this->finding( 'security.command-execution', array( 'file' => 'a.php', 'line' => 3 ) ),
				$this->finding( 'caching.cache-flush', array( 'file' => 'a.php', 'line' => 9 ) ),
			)
		);

		$this->store_result(
			$this->add_plugin( 'beta', array(), array( 'Name' => 'Beta' ) ),
			array( $this->finding( 'environment.debug-output', array( 'file' => 'b.php', 'line' => 1 ) ) )
		);

		// The sandbox always has wp-content/mu-plugins/, which the wp-content
		// audit reports as one path to relocate: the one piece of audit work.
	}

	/*
	 * ---------------------------------------------------------------------
	 * Rescan
	 * ------------------------------------------------------------------
	 */

	public function test_the_rescan_handler_is_registered() {
		$settings = new Findings_Settings();

		$this->assertSame( 10, has_action( 'admin_post_preflight_audit_rescan', array( $settings, 'handle_rescan' ) ) );
	}

	public function test_a_user_without_the_capability_cannot_rescan() {
		WP::$can = false;

		try {
			( new Findings_Settings() )->handle_rescan();
			$this->fail( 'Expected wp_die()' );
		} catch ( Wp_Die $die ) {
			$this->assertSame( 403, $die->args['response'] );
		}
	}

	public function test_a_bad_nonce_cannot_rescan() {
		WP::$nonce_valid = false;

		$this->expectException( Wp_Die::class );

		( new Findings_Settings() )->handle_rescan();
	}

	public function test_a_full_rescan_scans_everything_and_returns_to_the_fix_list() {
		$this->add_plugin( 'p', array( 'p.php' => self::GUARD . "exec( 'ls' );\n" ) );
		$this->wpdb->add_table( 'wp_posts' );
		Database_Audit::run();
		$this->wpdb->add_table( 'wp_new' );

		$location = $this->rescan();

		$this->assertSame( self::ADMIN . 'pfa-findings&pfa-scanned=all', $location );
		$this->assertSame( array( Findings_Settings::RESCAN_ACTION ), WP::$checked_nonces );
		$this->assertNotNull( Results_Store::get( 'plugin:p' ) );
		$this->assertCount( 1, Results_Store::get_history() );
		$this->assertSame( 2, Database_Audit::run()['summary']['total'], 'the database audit was refreshed' );
		$this->assertSame( array( 'admin' ), WP::$memory_raises );
	}

	public function test_a_full_rescan_returns_to_the_screen_it_started_from_with_its_filters() {
		WP::$referer = self::ADMIN . 'pfa-plugins&status=review&s=foo&pfa-scanned=plugin&pfa-scanning=theme';

		$this->assertSame( self::ADMIN . 'pfa-plugins&status=review&s=foo&pfa-scanned=all', $this->rescan( 'all' ) );
	}

	public function test_a_referrer_from_another_plugin_is_ignored() {
		WP::$referer = self::ADMIN . 'other-plugin&x=1';

		$this->assertSame( self::ADMIN . 'pfa-findings&pfa-scanned=all', $this->rescan() );
	}

	public function test_nested_referrer_parameters_are_dropped() {
		WP::$referer = self::ADMIN . 'pfa-findings&tier=blocking&evil[]=1';

		$this->assertSame( self::ADMIN . 'pfa-findings&tier=blocking&pfa-scanned=all', $this->rescan() );
	}

	/**
	 * @dataProvider async_scopes
	 */
	public function test_an_area_rescan_forgets_that_area_and_hands_off_to_its_screen( $scope, $screen ) {
		$this->store_result( $this->add_plugin( 'p' ), array() );
		$this->store_result( $this->add_theme( 't' ), array() );

		$location = $this->rescan( $scope );

		$this->assertSame( self::ADMIN . $screen . '&pfa-scanning=' . $scope, $location );
		$this->assertSame( array(), WP::$memory_raises, 'nothing is scanned on the server' );
	}

	public function async_scopes() {
		return array(
			'plugins'    => array( 'plugin', 'pfa-plugins' ),
			'themes'     => array( 'theme', 'pfa-themes' ),
			'must-use'   => array( 'mu-plugin', 'pfa-mu-plugins' ),
		);
	}

	public function test_an_area_rescan_only_forgets_its_own_results() {
		$this->store_result( $this->add_plugin( 'p' ), array() );
		$this->store_result( $this->add_theme( 't' ), array() );

		$this->rescan( 'plugin' );

		$this->assertNull( Results_Store::get( 'plugin:p' ) );
		$this->assertNotNull( Results_Store::get( 'theme:t' ) );
	}

	public function test_an_area_rescan_started_on_its_own_screen_keeps_the_view() {
		WP::$referer = self::ADMIN . 'pfa-themes&role=live';

		$this->assertSame( self::ADMIN . 'pfa-themes&role=live&pfa-scanning=theme', $this->rescan( 'theme' ) );
	}

	public function test_an_area_rescan_started_elsewhere_goes_to_the_area_screen() {
		WP::$referer = self::ADMIN . 'pfa-findings&tier=blocking';

		$this->assertSame( self::ADMIN . 'pfa-plugins&pfa-scanning=plugin', $this->rescan( 'plugin' ) );
	}

	public function test_an_audit_rescan_runs_on_the_server_and_goes_to_its_screen() {
		$this->assertSame( self::ADMIN . 'pfa-database&pfa-scanned=database', $this->rescan( 'database' ) );
		$this->assertSame( self::ADMIN . 'pfa-directories&pfa-scanned=directories', $this->rescan( 'directories' ) );
	}

	public function test_an_unknown_scope_rescans_everything() {
		$this->assertSame( self::ADMIN . 'pfa-findings&pfa-scanned=all', $this->rescan( '"><script>' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The screen
	 * ------------------------------------------------------------------
	 */

	public function test_before_any_scan_it_offers_the_first_scan() {
		$output = $this->screen();

		$this->assertStringContainsString( 'No scan results yet', $output );
		$this->assertStringContainsString( 'Run the first scan', $output );
		$this->assertStringContainsString( 'value="preflight_audit_rescan"', $output );
	}

	public function test_the_list_shows_every_tier_with_counts_that_include_audit_work() {
		$this->seed();

		$output = $this->screen();

		// Findings: critical (must fix), high (should fix), low (FYI).
		// Audit work: mu-plugins/ in wp-content (should fix).
		$this->assertMatchesRegularExpression( '#<span>Everything</span><span class="pfa-segmented__count">4</span>#', $output );
		$this->assertMatchesRegularExpression( '#<span>Must fix</span><span class="pfa-segmented__count">1</span>#', $output );
		$this->assertMatchesRegularExpression( '#<span>Should fix</span><span class="pfa-segmented__count">2</span>#', $output );
		$this->assertMatchesRegularExpression( '#<span>Worth checking</span><span class="pfa-segmented__count">0</span>#', $output );
		$this->assertMatchesRegularExpression( '#<span>FYI</span><span class="pfa-segmented__count">1</span>#', $output );

		$this->assertStringContainsString( '<p class="pfa-result-count" data-role="findings-count" aria-live="polite">4 issues</p>', $output );
		$this->assertStringContainsString( 'pfa-tiergroup--blocking', $output );
		$this->assertStringNotContainsString( 'pfa-tiergroup--warning', $output, 'an empty tier has no card' );
		$this->assertStringContainsString( 'Remove or relocate 1 path in wp-content', $output );
		$this->assertStringContainsString( 'data-role="tier-empty" hidden', $output );
		$this->assertStringContainsString( 'data-role="expand-all"', $output );
	}

	public function test_an_empty_tier_is_a_count_rather_than_a_link() {
		$this->seed();

		$output = $this->screen();

		$this->assertMatchesRegularExpression( '#<span class="pfa-segmented__option is-empty"[^>]*data-tier="warning"#', $output );
		$this->assertMatchesRegularExpression( '#<a class="pfa-segmented__option" href="[^"]*tier=blocking"#', $output );
	}

	public function test_a_selected_tier_shows_only_that_tier_and_links_back_out() {
		$this->seed();
		$_GET['tier'] = 'blocking';

		$output = $this->screen();

		$this->assertMatchesRegularExpression( '#<section class="pfa-tiergroup pfa-tiergroup--blocking" data-tier="blocking">#', $output );
		$this->assertMatchesRegularExpression( '#<section class="pfa-tiergroup pfa-tiergroup--important" data-tier="important" hidden>#', $output );
		$this->assertMatchesRegularExpression( '#<a class="pfa-segmented__option active" href="' . preg_quote( self::ADMIN . 'pfa-findings', '#' ) . '" [^>]*aria-current="true"#', $output );
		$this->assertStringContainsString( '1 issue</p>', $output );
	}

	public function test_a_selected_tier_with_nothing_in_it_shows_the_empty_message() {
		$this->seed();
		$_GET['tier'] = 'warning';

		$output = $this->screen();

		$this->assertStringContainsString( '<div data-role="tier-empty">', $output );
		$this->assertMatchesRegularExpression( '#<a class="pfa-segmented__option active"[^>]*data-tier="warning"#', $output, 'still a link, so it can be cleared' );
	}

	public function test_a_target_filter_leaves_out_the_audit_work_and_can_be_removed() {
		$this->seed();
		$_GET['target'] = 'plugin:beta';

		$output = $this->screen();

		$this->assertStringContainsString( '1 issue</p>', $output );
		$this->assertStringNotContainsString( 'Site audit', $output );
		$this->assertStringContainsString( '<a class="pfa-activefilter" href="' . self::ADMIN . 'pfa-findings">', $output );
		$this->assertStringContainsString( '<span>Beta</span>', $output );
		$this->assertStringContainsString( '<input type="hidden" name="target" value="plugin:beta" />', $output, 'search keeps the filter' );
	}

	public function test_category_type_and_search_filters() {
		$this->seed();
		$_GET['category'] = 'security';
		$_GET['type']     = 'security';
		$_GET['s']        = 'a.php';

		$output = $this->screen();

		$this->assertStringContainsString( '<span>Security</span>', $output );
		$this->assertStringContainsString( '<span>Security concern</span>', $output );
		$this->assertStringContainsString( 'Matching “a.php”', $output );
		$this->assertStringContainsString( 'value="a.php"', $output );
		$this->assertStringContainsString( '1 issue</p>', $output );
	}

	public function test_invalid_filters_are_ignored() {
		$this->seed();
		$_GET['tier']     = 'urgent';
		$_GET['type']     = 'nope';
		$_GET['category'] = 'nope';
		$_GET['target']   = 'plugin:not-installed';

		$output = $this->screen();

		$this->assertStringContainsString( '4 issues</p>', $output );
		$this->assertStringNotContainsString( 'pfa-findings__active', $output );
	}

	public function test_a_search_that_matches_nothing_says_so() {
		$this->seed();
		$_GET['s'] = 'zzz';

		$output = $this->screen();

		$this->assertStringContainsString( 'Nothing matches these filters', $output );
		$this->assertStringContainsString( 'Clear filters', $output );
	}

	public function test_a_partial_scan_is_called_out() {
		$this->store_result(
			$this->add_plugin( 'huge', array(), array( 'Name' => 'Huge' ) ),
			array( $this->finding( 'security.extract' ) ),
			array( 'truncated' => true )
		);

		$output = $this->screen();

		$this->assertStringContainsString( 'Partial results', $output );
		$this->assertStringContainsString( 'stopped early on Huge', $output );
	}

	public function test_the_screen_escapes_names_from_scanned_code() {
		$this->store_result(
			$this->add_plugin( 'evil', array(), array( 'Name' => '<img src=x onerror=alert(1)>' ) ),
			array( $this->finding( 'security.extract', array( 'evidence' => '<script>alert(1)</script>' ) ) )
		);
		$_GET['target'] = 'plugin:evil';
		$_GET['s']      = '"><script>alert(2)</script>';

		$output = $this->screen();

		$this->assertStringNotContainsString( '<img src=x', $output );
		$this->assertStringNotContainsString( '<script>alert', $output );
	}

	public function test_the_screen_has_no_help_tab_of_its_own() {
		$this->assertFalse( method_exists( Findings_Settings::class, 'get_page_guidance' ) );
	}
}
