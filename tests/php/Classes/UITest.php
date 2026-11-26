<?php
/**
 * Tests for the shared admin UI building blocks.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Tests\Classes;

use PREFLIGHT_AUDIT\Includes\Classes\UI;
use PREFLIGHT_AUDIT\Includes\Scanner\Report;
use PREFLIGHT_AUDIT\Includes\Scanner\Rules;
use PREFLIGHT_AUDIT\Tests\Support\Fake_Screen;
use PREFLIGHT_AUDIT\Tests\Support\WP;
use PREFLIGHT_AUDIT\Tests\TestCase;

/**
 * @covers \PREFLIGHT_AUDIT\Includes\Classes\UI
 */
class UITest extends TestCase {

	const XSS = '<script>alert(1)</script>';

	/*
	 * ---------------------------------------------------------------------
	 * Routing
	 * ------------------------------------------------------------------
	 */

	public function test_every_screen_has_a_unique_slug_and_a_known_group() {
		$screens = UI::get_screens();
		$slugs   = array_column( $screens, 'slug' );

		$this->assertSame( array_unique( $slugs ), $slugs );

		foreach ( $screens as $key => $screen ) {
			$this->assertArrayHasKey( $screen['group'], UI::get_groups(), $key );
			$this->assertNotSame( '', UI::get_icon( $screen['icon'] ), "{$key} icon" );
		}
	}

	public function test_screen_urls() {
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=pfa-database', UI::get_screen_url( 'database' ) );
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=pfa-plugins&status=review', UI::get_screen_url( 'plugins', array( 'status' => 'review' ) ) );
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=preflight-audit', UI::get_screen_url( 'unknown' ) );
	}

	public function test_screen_for_type() {
		$this->assertSame( 'themes', UI::get_screen_for_type( 'theme' ) );
		$this->assertSame( 'mu-plugins', UI::get_screen_for_type( 'mu-plugin' ) );
		$this->assertSame( 'plugins', UI::get_screen_for_type( 'anything' ) );
	}

	public function test_findings_urls() {
		$base = 'https://example.test/wp-admin/admin.php?page=pfa-findings';

		$this->assertSame( $base, UI::get_findings_url() );
		$this->assertSame( $base . '&target=plugin:a', UI::get_findings_url( 'plugin:a' ) );
		$this->assertSame( $base . '&tier=blocking', UI::get_findings_url( '', 'blocking' ) );
		$this->assertSame( $base . '&target=plugin:a&tier=info', UI::get_findings_url( 'plugin:a', 'info' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Tiers and marks
	 * ------------------------------------------------------------------
	 */

	public function test_tiers_add_presentation_to_the_taxonomy() {
		$tiers = UI::get_tiers();

		$this->assertSame( array( 'blocking', 'important', 'warning', 'info' ), array_keys( $tiers ) );
		$this->assertTrue( $tiers['blocking']['open'] );
		$this->assertFalse( $tiers['info']['open'] );
		$this->assertSame( 'bad', $tiers['blocking']['tone'] );
		$this->assertSame( 'Must fix', $tiers['blocking']['label'] );
	}

	public function test_tier_helpers() {
		$this->assertSame( 'important', UI::get_tier( 'high' ) );
		$this->assertSame( 'Worth checking', UI::get_tier_label( 'warning' ) );
		$this->assertSame( 'mystery', UI::get_tier_label( 'mystery' ) );
	}

	public function test_marks() {
		$this->assertSame( '<span class="pfa-mark pfa-mark--warning" aria-hidden="true"></span>', UI::get_mark( 'warning' ) );

		$labelled = UI::get_mark( 'blocking', true );

		$this->assertStringContainsString( 'aria-hidden="false"', $labelled );
		$this->assertStringContainsString( '<span class="screen-reader-text">Must fix</span>', $labelled );
	}

	public function test_tier_pill() {
		$this->assertStringContainsString( 'pfa-tier--important', UI::get_tier_pill( 'important' ) );
		$this->assertStringContainsString( 'Should fix', UI::get_tier_pill( 'important' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Icons
	 * ------------------------------------------------------------------
	 */

	public function test_icons_are_inline_svg_that_inherit_the_text_colour() {
		$icon = UI::get_icon( 'check' );

		$this->assertStringStartsWith( '<svg class="pfa-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"', $icon );
		$this->assertStringContainsString( 'stroke="currentColor"', $icon );
		$this->assertNotFalse( simplexml_load_string( $icon ) );
	}

	public function test_icon_attributes_are_escaped_and_unknown_icons_are_empty() {
		$icon = UI::get_icon( 'logo', array( 'class' => '"><script>' ) );

		$this->assertStringNotContainsString( '<script>', $icon );
		$this->assertSame( '', UI::get_icon( 'nope' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Buttons, links and small pieces
	 * ------------------------------------------------------------------
	 */

	public function test_a_link_button() {
		$button = UI::get_action_button(
			array(
				'label' => 'Open',
				'url'   => 'https://example.test/x',
				'icon'  => 'arrow-right',
			)
		);

		$this->assertStringStartsWith( '<a class="pfa-btn pfa-btn--ghost pfa-btn--sm" href="https://example.test/x">', $button );
		$this->assertStringContainsString( '<span>Open</span>', $button );
		$this->assertStringContainsString( '<svg', $button );
	}

	public function test_an_external_primary_full_size_link_button() {
		$button = UI::get_action_button(
			array(
				'label'    => 'Docs',
				'url'      => 'https://docs.wpvip.com/',
				'primary'  => true,
				'small'    => false,
				'external' => true,
			)
		);

		$this->assertStringContainsString( 'class="pfa-btn pfa-btn--primary"', $button );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $button );
		$this->assertStringContainsString( '(opens in a new tab)', $button );
	}

	public function test_a_form_button_posts_to_admin_post_with_a_nonce() {
		$button = UI::get_action_button(
			array(
				'label'   => 'Rescan',
				'submit'  => 'preflight_audit_rescan',
				'nonce'   => 'preflight_audit_rescan_all',
				'fields'  => array( 'scope' => '"><x>' ),
				'confirm' => true,
			)
		);

		$this->assertStringStartsWith( '<form method="post" action="https://example.test/wp-admin/admin-post.php" class="pfa-inline-form" data-confirm="rescan-all">', $button );
		$this->assertStringContainsString( '<input type="hidden" name="action" value="preflight_audit_rescan" />', $button );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( 'preflight_audit_rescan_all' ) . '"', $button );
		$this->assertStringContainsString( 'name="scope" value="&quot;&gt;&lt;x&gt;"', $button );
		$this->assertStringContainsString( '<button type="submit"', $button );
	}

	public function test_a_form_button_without_confirm_does_not_ask() {
		$button = UI::get_action_button(
			array(
				'label'  => 'Go',
				'submit' => 'x',
				'nonce'  => 'y',
			)
		);

		$this->assertStringNotContainsString( 'data-confirm', $button );
	}

	public function test_a_dangerous_url_is_dropped() {
		$button = UI::get_action_button(
			array(
				'label' => self::XSS,
				'url'   => 'javascript:alert(1)',
			)
		);

		$this->assertStringContainsString( 'href=""', $button );
		$this->assertStringNotContainsString( '<script>', $button );
	}

	public function test_arrow_link() {
		$link = UI::get_arrow_link( 'https://example.test/', self::XSS );

		$this->assertStringStartsWith( '<a class="pfa-link" href="https://example.test/">', $link );
		$this->assertStringContainsString( '&lt;script&gt;', $link );
	}

	public function test_badges_and_chips_escape_their_text() {
		$badge = UI::get_badge( self::XSS, 'bad', '"title"' );
		$chip  = UI::get_meta_chip( 'Rule', self::XSS, 'tip' );

		$this->assertStringContainsString( 'pfa-badge--bad', $badge );
		$this->assertStringContainsString( 'title="&quot;title&quot;"', $badge );
		$this->assertStringNotContainsString( '<script>', $badge . $chip );
		$this->assertStringContainsString( 'title="tip"', $chip );
		$this->assertStringNotContainsString( 'title=', UI::get_badge( 'x' ) );
	}

	public function test_code_snippet() {
		$snippet = UI::get_code_snippet( 'ALTER TABLE `a` ENGINE = InnoDB;', true );

		$this->assertStringContainsString( 'pfa-snippet--block', $snippet );
		$this->assertStringContainsString( 'data-clipboard="ALTER TABLE `a` ENGINE = InnoDB;"', $snippet );
		$this->assertStringNotContainsString( 'pfa-snippet--block', UI::get_code_snippet( 'x' ) );
	}

	public function test_notices_allow_limited_html_but_not_scripts() {
		$notice = UI::get_notice( 'Use <code>x</code>' . self::XSS, 'success', 'Done' );

		$this->assertStringContainsString( 'pfa-notice--success', $notice );
		$this->assertStringContainsString( 'role="status"', $notice );
		$this->assertStringContainsString( '<strong class="pfa-notice__title">Done</strong>', $notice );
		$this->assertStringContainsString( '<code>x</code>', $notice );
		$this->assertStringNotContainsString( '<script>', $notice );
		$this->assertStringNotContainsString( 'role="status"', UI::get_notice( 'x' ) );
		$this->assertStringContainsString( 'pfa-notice--odd', UI::get_notice( 'x', 'odd' ) );
	}

	public function test_notes_list() {
		$this->assertStringContainsString( 'pfa-dash', UI::get_notes_list( array( '', '' ) ) );
		$this->assertSame( '<ul class="pfa-notes"><li>a</li><li>b</li></ul>', UI::get_notes_list( array( 'a', '', 'b' ) ) );
	}

	public function test_ids_are_unique() {
		$this->assertNotSame( UI::next_id(), UI::next_id() );
		$this->assertStringStartsWith( 'pfa-detail-', UI::next_id( 'pfa-detail' ) );
	}

	public function test_row_toggle() {
		$toggle = UI::get_row_toggle( 'pfa-detail-9', 'Show details for ' . self::XSS );

		$this->assertStringContainsString( 'aria-controls="pfa-detail-9"', $toggle );
		$this->assertStringContainsString( 'aria-expanded="false"', $toggle );
		$this->assertStringNotContainsString( '<script>', $toggle );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Status vocabulary
	 * ------------------------------------------------------------------
	 */

	/**
	 * @dataProvider pill_cases
	 */
	public function test_status_pills( $state, $class ) {
		$this->assertStringContainsString( $class, UI::get_status_pill( $state, 'Label' ) );
	}

	public function pill_cases() {
		return array(
			array( 'compatible', 'pfa-status--ok' ),
			array( 'review', 'pfa-status--warn' ),
			array( 'not-compatible', 'pfa-status--bad' ),
			array( 'pending', 'pfa-spinner' ),
			array( 'unknown', 'pfa-status--warn' ),
		);
	}

	public function test_verdict_cell_carries_the_target_for_the_async_scanner() {
		$cell = UI::get_verdict_cell(
			array(
				'state' => 'pending',
				'label' => 'Not scanned yet',
			),
			'plugin:a"b'
		);

		$this->assertStringStartsWith( '<td class="pfa-col-status is-pending" data-target="plugin:a&quot;b"', $cell );
	}

	public function test_severity_bar_groups_counts_by_tier() {
		$bar = UI::get_severity_bar(
			array(
				'critical' => 2,
				'low'      => 1,
				'info'     => 3,
			),
			'plugin:a'
		);

		$this->assertStringContainsString( '2 findings: must fix', $bar );
		$this->assertStringContainsString( '4 findings: fyi', $bar );
		$this->assertStringNotContainsString( 'should fix', $bar );
		$this->assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=pfa-findings&amp;target=plugin:a&amp;tier=blocking"', $bar );

		$unlinked = UI::get_severity_bar( array( 'high' => 1 ) );

		$this->assertStringContainsString( '<span class="pfa-sev"', $unlinked );
		$this->assertStringContainsString( '1 finding: should fix', $unlinked );
		$this->assertSame( '', UI::get_severity_bar( array() ) );
	}

	public function test_findings_link_wording_follows_the_verdict() {
		$this->assertStringContainsString( 'Fix 3 findings', UI::get_findings_link( 'plugin:a', array( 'total' => 3, 'status' => 'blocked' ) ) );
		$this->assertStringContainsString( 'Review 1 finding', UI::get_findings_link( 'plugin:a', array( 'total' => 1, 'status' => 'review' ) ) );
		$this->assertSame( '', UI::get_findings_link( 'plugin:a', array( 'total' => 0 ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Layout
	 * ------------------------------------------------------------------
	 */

	public function test_definition_list() {
		$defs = UI::get_defs(
			array(
				array(
					'label' => 'PHP',
					'value' => self::XSS,
					'hint'  => 'hint',
					'tone'  => 'ok',
				),
				array(
					'label' => 'Path',
					'html'  => '<code>raw</code>',
				),
			),
			'pfa-defs--env'
		);

		$this->assertStringStartsWith( '<dl class="pfa-defs pfa-defs--env">', $defs );
		$this->assertStringContainsString( 'pfa-def--ok', $defs );
		$this->assertStringContainsString( '<span class="pfa-def__hint">hint</span>', $defs );
		$this->assertStringContainsString( '<code>raw</code>', $defs );
		$this->assertStringNotContainsString( '<script>', $defs );
		$this->assertSame( '', UI::get_defs( array() ) );
	}

	public function test_panels() {
		$output = $this->render(
			static function () {
				UI::render_panel_open(
					array(
						'title'   => 'Tables',
						'count'   => '12',
						'summary' => 'All of them',
						'actions' => array(
							array(
								'label' => 'Copy',
								'url'   => '#',
							),
						),
						'flush'   => true,
					)
				);
				UI::render_panel_close();
			}
		);

		$this->assertStringContainsString( 'class="pfa-section pfa-section--sheet"', $output );
		$this->assertStringContainsString( '<span class="pfa-section__count">12</span>', $output );
		$this->assertStringContainsString( 'All of them', $output );
		$this->assertStringContainsString( 'pfa-section__actions', $output );
		$this->assertStringEndsWith( '</div></section>', trim( $output ) );

		$bare = $this->render(
			static function () {
				UI::render_panel_open( array( 'modifier' => 'x' ) );
			}
		);

		$this->assertStringNotContainsString( 'aria-labelledby', $bare );
		$this->assertStringContainsString( 'class="pfa-section x"', $bare );
	}

	public function test_cards() {
		$output = $this->render(
			static function () {
				UI::render_card_open(
					array(
						'title' => 'Environment',
						'aside' => '<em>aside</em>',
						'flush' => true,
					)
				);
				UI::render_card_close();
			}
		);

		$this->assertStringContainsString( '<h2 class="pfa-card__title"', $output );
		$this->assertStringContainsString( '<div class="pfa-card__aside"><em>aside</em></div>', $output );
		$this->assertStringContainsString( 'pfa-card__body--flush', $output );
	}

	public function test_steps() {
		$output = $this->render(
			static function () {
				UI::render_steps(
					array(
						array(
							'title' => 'Scan',
							'body'  => 'Read it',
						),
						array(
							'title' => 'Fix',
							'body'  => 'Change it',
						),
					)
				);
			}
		);

		$this->assertStringContainsString( '>01<', $output );
		$this->assertStringContainsString( '>02<', $output );
		$this->assertSame(
			'',
			$this->render(
				static function () {
					UI::render_steps( array() );
				}
			)
		);
	}

	/**
	 * @dataProvider verdict_scores
	 */
	public function test_verdict_ruler( $score, $filled, $text ) {
		$output = $this->render(
			static function () use ( $score ) {
				UI::render_verdict(
					array(
						'score'    => $score,
						'headline' => 'Headline',
					)
				);
			}
		);

		$this->assertSame( 20, substr_count( $output, 'pfa-ruler__seg' ) );
		$this->assertSame( $filled, substr_count( $output, ' is-on' ) );
		$this->assertStringContainsString( $text, $output );
	}

	public function verdict_scores() {
		return array(
			'not scanned'   => array( -1, 0, 'aria-valuetext="Not scanned yet"' ),
			'zero'          => array( 0, 0, '<span class="pfa-verdict__number">0</span>' ),
			'half'          => array( 53, 11, '<span class="pfa-verdict__number">53</span>' ),
			'full'          => array( 100, 20, 'aria-valuenow="100"' ),
			'clamped above' => array( 250, 20, 'aria-valuenow="100"' ),
		);
	}

	public function test_verdict_tiers_link_only_when_there_is_work() {
		$output = $this->render(
			static function () {
				UI::render_verdict(
					array(
						'score'   => 50,
						'tier'    => 'blocking',
						'meta'    => 'Scanned things',
						'actions' => array(
							array(
								'label' => 'Go',
								'url'   => '#',
							),
						),
						'tiers'   => array(
							array(
								'tier'    => 'blocking',
								'label'   => 'Must fix',
								'value'   => '3',
								'summary' => 's',
								'url'     => 'https://example.test/x',
							),
							array(
								'tier'    => 'info',
								'label'   => 'FYI',
								'value'   => '0',
								'summary' => 's',
								'url'     => '',
							),
						),
					)
				);
			}
		);

		$this->assertStringContainsString( 'pfa-verdict--blocking', $output );
		$this->assertStringContainsString( '<a class="pfa-verdict__tierlink" title="s" href="https://example.test/x">', $output );
		$this->assertStringContainsString( '<span class="pfa-verdict__tierlink" title="s">', $output );
		$this->assertStringContainsString( 'pfa-verdict__tier--info is-empty', $output );
		$this->assertStringContainsString( 'Scanned things', $output );
		$this->assertStringContainsString( 'class="pfa-btn pfa-btn--ghost"', $output, 'verdict actions are full size' );
	}

	public function test_manifest() {
		$output = $this->render(
			static function () {
				UI::render_manifest(
					array(
						array(
							'label'  => 'Plugins',
							'screen' => 'plugins',
							'icon'   => 'plug',
							'counts' => array( 'ready' => 1, 'review' => 1, 'blocked' => 1, 'pending' => 0, 'total' => 3 ),
							'items'  => array(
								array( 'name' => self::XSS, 'bucket' => 'blocked' ),
								array( 'name' => 'B', 'bucket' => 'review' ),
								array( 'name' => 'C', 'bucket' => 'ready' ),
							),
						),
						array(
							'label'  => 'Themes',
							'screen' => 'themes',
							'icon'   => 'brush',
							'counts' => array( 'ready' => 0, 'review' => 0, 'blocked' => 0, 'pending' => 2, 'total' => 2 ),
							'items'  => array(
								array( 'name' => 'T', 'bucket' => 'pending' ),
								array( 'name' => 'U', 'bucket' => 'pending' ),
							),
						),
						array(
							'label'  => 'Database',
							'screen' => 'database',
							'icon'   => 'database',
							'counts' => array( 'ready' => 4, 'review' => 0, 'blocked' => 0, 'pending' => 0, 'total' => 4 ),
							'items'  => array( array( 'name' => 'wp_posts', 'bucket' => 'ready' ) ),
						),
						array(
							'label'  => 'Must-use',
							'screen' => 'mu-plugins',
							'icon'   => 'bolt',
							'counts' => array( 'ready' => 0, 'review' => 0, 'blocked' => 0, 'pending' => 0, 'total' => 0 ),
							'items'  => array(),
						),
					)
				);
			}
		);

		$this->assertStringContainsString( '1 blocked, 1 review, 1 ready', $output );
		$this->assertStringContainsString( 'Work through 2', $output );
		$this->assertStringContainsString( 'page=pfa-plugins&amp;status=not-compatible', $output );
		$this->assertStringContainsString( 'Awaiting a scan', $output );
		$this->assertStringContainsString( 'Clear', $output );
		$this->assertStringContainsString( 'Nothing here', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( 'pfa-cell--bad', $output );
	}

	public function test_manifest_legend() {
		$legend = UI::get_manifest_legend();

		$this->assertSame( 4, substr_count( $legend, '<li>' ) );
		$this->assertStringContainsString( 'aria-hidden="true"', $legend );
	}

	public function test_sparkline() {
		$this->assertSame( '', UI::get_sparkline( array( 5 ), 10, 'x' ) );
		$this->assertSame( '', UI::get_sparkline( array( 1, 2 ), 0, 'x' ) );

		$svg = UI::get_sparkline( array( 0, 10, 20 ), 10, 'Findings "over" time' );

		$this->assertNotFalse( simplexml_load_string( $svg ) );
		$this->assertStringContainsString( 'points="4,52 120,4 236,4"', $svg, 'values above the maximum are clamped' );
		$this->assertStringContainsString( 'cx="236" cy="4"', $svg );
		$this->assertStringContainsString( 'aria-label="Findings &quot;over&quot; time"', $svg );
	}

	public function test_toolbar() {
		$output = $this->render(
			static function () {
				UI::render_toolbar(
					array(
						'groups'        => array(
							array(
								'name'    => 'status',
								'label'   => 'Filter',
								'active'  => 'review',
								'options' => array(
									array( 'value' => 'all', 'label' => 'All' ),
									array( 'value' => 'review', 'label' => 'Review', 'dot' => 'warn' ),
								),
							),
						),
						'show_progress' => true,
						'actions'       => '<button>extra</button>',
					)
				);
			}
		);

		$this->assertStringContainsString( 'data-filter-group="status"', $output );
		$this->assertMatchesRegularExpression( '/class="active"\s+data-filter-value="review"\s+aria-pressed="true"/', $output );
		$this->assertMatchesRegularExpression( '/class=""\s+data-filter-value="all"\s+aria-pressed="false"/', $output );
		$this->assertStringContainsString( 'pfa-dot--warn', $output );
		$this->assertStringContainsString( 'data-role="table-search"', $output );
		$this->assertStringContainsString( 'data-role="scan" hidden', $output );
		$this->assertStringContainsString( '<button>extra</button>', $output );

		$plain = $this->render(
			static function () {
				UI::render_toolbar( array( 'show_search' => false ) );
			}
		);

		$this->assertStringNotContainsString( 'table-search', $plain );
		$this->assertStringNotContainsString( 'data-role="scan"', $plain );
	}

	public function test_table_head() {
		$output = $this->render(
			static function () {
				UI::render_table_head(
					array(
						array( 'label' => '' ),
						array( 'label' => 'Name', 'sortable' => true, 'class' => 'pfa-col-name' ),
						array( 'label' => 'Rows', 'sortable' => true, 'sort' => 'number', 'hint' => 'Approximate' ),
						array( 'label' => 'Notes' ),
					)
				);
			}
		);

		$this->assertStringContainsString( '<span class="screen-reader-text">Details</span>', $output );
		$this->assertStringContainsString( 'class="pfa-th pfa-col-name is-sortable" aria-sort="none" data-sort-type="text" data-sort-index="1"', $output );
		$this->assertStringContainsString( 'data-sort-type="number" data-sort-index="2" title="Approximate"', $output );
		$this->assertStringContainsString( '<span>Notes</span>', $output );
	}

	public function test_empty_state() {
		$output = $this->render(
			static function () {
				UI::render_empty_state( 'Nothing', 'Really nothing', array( 'label' => 'Go', 'url' => '#' ) );
			}
		);

		$this->assertStringContainsString( '<p class="pfa-empty__title">Nothing</p>', $output );
		$this->assertStringContainsString( 'Really nothing', $output );
		$this->assertStringContainsString( 'pfa-empty__action', $output );

		$bare = $this->render(
			static function () {
				UI::render_empty_state( 'Nothing' );
			}
		);

		$this->assertStringNotContainsString( 'pfa-empty__message', $bare );
		$this->assertStringNotContainsString( 'pfa-empty__action', $bare );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Fix and task cards
	 * ------------------------------------------------------------------
	 */

	/**
	 * Builds a fix-list group for one rule.
	 *
	 * @param int $occurrences How many places.
	 * @return array<string, mixed> The group.
	 */
	private function fix_group( $occurrences ) {
		$findings = array();

		for ( $i = 1; $i <= $occurrences; $i++ ) {
			$findings[] = $this->finding(
				'database.unprepared-query',
				array(
					'file'     => 'inc/db.php',
					'line'     => $i * 10,
					'scope'    => 1 === $i ? 'run_query' : '',
					'evidence' => 1 === $i ? '$wpdb->query( "' . self::XSS . '" );' : '',
					'note'     => 1 === $i ? 'A note' : '',
				)
			);
		}

		$this->store_result( $this->add_plugin( 'p', array(), array( 'Name' => 'Plugin ' . self::XSS ) ), $findings );

		return Report::fix_list( Report::findings() )[0];
	}

	public function test_fix_card_for_one_place() {
		$group  = $this->fix_group( 1 );
		$output = $this->render(
			static function () use ( $group ) {
				UI::render_fix_card( $group );
			}
		);

		$this->assertStringContainsString( '<details class="pfa-fix pfa-fix--blocking"', $output );
		$this->assertStringContainsString( 'data-target="plugin:p"', $output );
		$this->assertStringContainsString( '<code>inc/db.php:10</code>', $output );
		$this->assertStringContainsString( 'in run_query()', $output );
		$this->assertStringContainsString( 'A note', $output );
		$this->assertStringContainsString( Rules::get( 'database.unprepared-query' )['phpcs'], $output );
		$this->assertStringContainsString( 'href="https://docs.wpvip.com/', $output );
		$this->assertStringContainsString( '>Code change</span>', $output );
		$this->assertStringNotContainsString( '<script>', $output, 'target names and evidence come from scanned code' );
	}

	public function test_fix_card_for_several_places() {
		$group  = $this->fix_group( 3 );
		$output = $this->render(
			static function () use ( $group ) {
				UI::render_fix_card( $group );
			}
		);

		$this->assertStringContainsString( '3 places', $output );
		$this->assertStringContainsString( '3 locations', $output );
		$this->assertSame( 1, substr_count( $output, '<pre class="pfa-code">' ), 'only occurrences with evidence show code' );
	}

	public function test_fix_card_mentions_rules_that_also_matched() {
		$group                 = $this->fix_group( 1 );
		$group['also_matched'] = array( 'database.direct-query' );
		$group['alternative']  = '';
		$group['doc']          = '';
		$group['phpcs']        = '';

		$output = $this->render(
			static function () use ( $group ) {
				UI::render_fix_card( $group );
			}
		);

		$this->assertStringContainsString( 'Also matched on these lines: database.direct-query', $output );
		$this->assertStringNotContainsString( 'Another way to do it', $output );
		$this->assertStringNotContainsString( 'WordPress VIP documentation', $output );
		$this->assertStringNotContainsString( 'PHPCS', $output );
	}

	public function test_task_card() {
		$output = $this->render(
			static function () {
				UI::render_task_card(
					array(
						'tier'   => 'important',
						'title'  => 'Relocate 1 must-use plugin',
						'detail' => 'Why ' . self::XSS,
						'url'    => 'https://example.test/x',
						'action' => 'Open',
						'source' => 'Must-use plugins',
					)
				);
			}
		);

		$this->assertStringContainsString( 'pfa-fix--task pfa-fix--important', $output );
		$this->assertStringContainsString( 'Site audit', $output );
		$this->assertStringContainsString( 'href="https://example.test/x"', $output );
		$this->assertStringNotContainsString( '<script>', $output );
	}

	public function test_tier_heading() {
		$output = $this->render(
			static function () {
				UI::render_tier_heading( 'warning' );
			}
		);

		$this->assertStringContainsString( 'pfa-tierhead--warning', $output );
		$this->assertStringContainsString( 'Worth checking', $output );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shell
	 * ------------------------------------------------------------------
	 */

	public function test_shell_before_the_first_scan() {
		$output = $this->render(
			static function () {
				UI::render_shell_open( 'overview' );
				UI::render_shell_close();
			}
		);

		$this->assertStringContainsString( 'Never scanned', $output );
		$this->assertStringContainsString( '<span>Run a scan</span>', $output );
		$this->assertStringNotContainsString( 'Rescan one area only', $output, 'nothing to narrow before a first scan' );
		$this->assertStringNotContainsString( 'data-confirm', $output, 'a first scan has nothing to replace' );
		$this->assertStringContainsString( 'Rule set ' . Rules::VERSION . ' · ' . count( Rules::all() ) . ' rules', $output );
		$this->assertStringContainsString( '<h1 class="pfa-head__title">Migration readiness</h1>', $output );
		$this->assertStringContainsString( '<hr class="wp-header-end" />', $output );
		$this->assertSame( 3, substr_count( $output, 'action=preflight_audit_export' ) );
		$this->assertStringNotContainsString( 'data-role="help-toggle"', $output );
	}

	public function test_shell_after_a_scan_offers_a_scoped_rescan() {
		$this->store_result( $this->add_plugin( 'p' ), array() );

		$output = $this->render(
			static function () {
				UI::render_shell_open( 'database' );
			}
		);

		$this->assertStringContainsString( 'Scanned 2 mins ago', $output );
		$this->assertStringContainsString( '<span>Rescan</span>', $output );
		$this->assertStringContainsString( 'data-confirm="rescan-all"', $output );
		$this->assertStringContainsString( 'Rescan one area only', $output );

		foreach ( array( 'plugin', 'theme', 'mu-plugin', 'database', 'directories' ) as $scope ) {
			$this->assertStringContainsString( 'name="scope" value="' . $scope . '"', $output );
		}

		$this->assertStringContainsString( 'aria-label="Code you ship"', $output );
		$this->assertSame( 1, substr_count( $output, 'This page' ), 'the database is marked as this page' );
	}

	public function test_shell_shows_the_help_toggle_when_there_is_help() {
		WP::$screen = new Fake_Screen();
		WP::$screen->add_help_tab( array( 'id' => 'x' ) );

		$output = $this->render(
			static function () {
				UI::render_shell_open( 'plugins' );
			}
		);

		$this->assertStringContainsString( 'data-role="help-toggle"', $output );
	}

	public function test_page_head_for_an_unknown_screen_is_empty() {
		$this->assertSame(
			'',
			$this->render(
				static function () {
					UI::render_page_head( 'nope' );
				}
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scan notices
	 * ------------------------------------------------------------------
	 */

	public function test_no_scan_notice_by_default() {
		$this->assertSame( '', $this->render( array( UI::class, 'render_scan_notice' ) ) );
	}

	public function test_scan_complete_notice_reports_what_was_read() {
		$this->store_result( $this->add_plugin( 'p' ), array(), array( 'files_scanned' => 4 ) );
		$_GET['pfa-scanned'] = 'plugin';

		$output = $this->render( array( UI::class, 'render_scan_notice' ) );

		$this->assertStringContainsString( 'Scan complete', $output );
		$this->assertStringContainsString( 'Scanned 1 plugin across 4 PHP files.', $output );
	}

	public function test_an_old_scan_complete_link_reports_the_whole_site() {
		$_GET['pfa-scanned'] = '1';

		$this->assertStringContainsString( 'Scanned 0 items', $this->render( array( UI::class, 'render_scan_notice' ) ) );
	}

	public function test_a_browser_rescan_marks_the_page_for_the_queue() {
		$_GET['pfa-scanning'] = 'theme';

		$output = $this->render( array( UI::class, 'render_scan_notice' ) );

		$this->assertStringStartsWith( '<div data-role="scan-report" data-scope="theme">', $output );
		$this->assertStringContainsString( 'Rescanning Themes', $output );
	}

	public function test_a_browser_rescan_with_a_bad_scope_covers_everything() {
		$_GET['pfa-scanning'] = '<x>';

		$this->assertStringContainsString( 'data-scope="all"', $this->render( array( UI::class, 'render_scan_notice' ) ) );
	}
}
