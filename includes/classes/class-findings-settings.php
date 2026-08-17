<?php
/**
 * The fix list.
 *
 * The other screens answer "is this ready?". This one answers "what exactly is
 * wrong, and what do I do about it?" — and it is the screen the whole plugin
 * points at, so it is built to be read top to bottom with no instructions.
 *
 * It is one flat list, ranked. It used to be three levels of nesting — a tier
 * band containing a target section containing a rule card — which meant the
 * reader had to open two things before reaching a sentence they could act on,
 * and had to hold the tier they were inside in their head while reading it.
 * Ranking the same cards and putting the tier on each one says everything the
 * bands said, without asking anyone to navigate a hierarchy to get at it.
 *
 * Filtering is menus of links: one click, no Apply button, and the result is a
 * URL that can be bookmarked or pasted into a migration ticket. Every count on
 * an option is measured against the filters that would still apply after
 * clicking it, so an option that says 4 always yields 4 cards.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the fix list.
 */
class Findings_Settings {

	use Singleton;

	/**
	 * Nonce action for the rescan button.
	 */
	const RESCAN_ACTION = 'wvc_rescan_all';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_wvc_rescan', array( $this, 'handle_rescan' ) );
	}

	/**
	 * Runs a full rescan and returns to the screen the request came from.
	 *
	 * @return void
	 */
	public function handle_rescan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to run compatibility scans.', 'wp-vip-compatibility' ),
				esc_html__( 'Forbidden', 'wp-vip-compatibility' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::RESCAN_ACTION );

		Report::run_full_scan( true );

		wp_safe_redirect( add_query_arg( 'wvc-scanned', '1', $this->get_return_url() ) );
		exit;
	}

	/**
	 * Resolves the screen a rescan should return to.
	 *
	 * Rescanning is a masthead action available from every screen, so sending
	 * everyone to the fix list would take a reader looking at the database audit
	 * somewhere they did not ask to go. The referring screen is used when it is
	 * one of ours, and the fix list is the fallback.
	 *
	 * @return string The admin URL.
	 */
	private function get_return_url() {
		$referer = wp_get_referer();

		if ( false === $referer ) {
			return UI::get_findings_url();
		}

		$query = array();
		parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );

		$page = isset( $query['page'] ) ? sanitize_key( $query['page'] ) : '';

		foreach ( UI::get_screens() as $key => $screen ) {
			if ( $screen['slug'] === $page ) {
				return UI::get_screen_url( $key );
			}
		}

		return UI::get_findings_url();
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		// The "scan complete" confirmation belongs to the shell, because a rescan
		// can be started from — and returns to — any screen.
		$aggregate = Report::aggregate();

		if ( 0 === $aggregate['targets'] ) {
			UI::render_empty_state(
				__( 'No scan results yet', 'wp-vip-compatibility' ),
				__( 'Run a scan to analyse every plugin, theme and must-use plugin against the WordPress VIP Platform requirements.', 'wp-vip-compatibility' ),
				array(
					'label'   => __( 'Run the first scan', 'wp-vip-compatibility' ),
					'submit'  => 'wvc_rescan',
					'nonce'   => self::RESCAN_ACTION,
					'icon'    => 'refresh',
					'primary' => true,
				)
			);

			return;
		}

		$filters = $this->read_filters();

		/*
		 * This screen shows two kinds of work: findings, which come from reading
		 * code, and the database, must-use and wp-content audits, which do not.
		 * Both are real migration work and both carry a tier, so the tier options
		 * count both — a counter that ignored half of what the page lists would
		 * read "Must fix 0" directly above a row badged "Must fix".
		 *
		 * Each option's count therefore equals the number of items clicking it
		 * leaves on screen. The two menus are counted against progressively
		 * narrower sets: tiers against everything the item, type and search allow;
		 * categories against that set once the tier is applied.
		 */
		$scope = Report::findings(
			array_merge(
				$filters,
				array(
					'tier'     => '',
					'category' => '',
				)
			)
		);

		$tier_scoped = $this->filter_by_tier( $scope, $filters['tier'] );
		$findings    = $this->filter_by_category( $tier_scoped, $filters['category'] );

		// Category, item, type and search are properties of a code finding. While
		// one of them is narrowing the page, work that cannot have such a property
		// is out of scope entirely rather than filtered to nothing — so it leaves
		// the counts as well as the list.
		$other = $this->other_work_applies( $filters ) ? Report::other_work() : array();
		$shown = $this->filter_other_by_tier( $other, $filters['tier'] );

		$this->render_controls( $filters, $scope, $tier_scoped, $findings, $other, $shown );

		if ( empty( $findings ) && empty( $shown ) ) {
			UI::render_empty_state(
				__( 'Nothing matches these filters', 'wp-vip-compatibility' ),
				__( 'Clear them to see everything the last scan reported.', 'wp-vip-compatibility' ),
				array(
					'label' => __( 'Clear filters', 'wp-vip-compatibility' ),
					'url'   => UI::get_screen_url( 'findings' ),
				)
			);

			return;
		}

		$this->render_truncation_notice( $findings );
		$this->render_fix_list( Report::fix_list( $findings ), $shown );
	}

	/**
	 * Whether the non-finding work belongs on screen under the active filters.
	 *
	 * @param array<string, string> $filters The active filters.
	 * @return bool True when it is in scope.
	 */
	private function other_work_applies( array $filters ) {
		return '' === $filters['category']
			&& '' === $filters['target']
			&& '' === $filters['search']
			&& '' === $filters['type'];
	}

	/**
	 * Narrows the non-finding work to one tier.
	 *
	 * @param array<int, array<string, mixed>> $work The actions.
	 * @param string                           $tier The tier slug, or an empty string.
	 * @return array<int, array<string, mixed>> The matching actions.
	 */
	private function filter_other_by_tier( array $work, $tier ) {
		if ( '' === $tier ) {
			return $work;
		}

		return array_values(
			array_filter(
				$work,
				static function ( $action ) use ( $tier ) {
					return $action['tier'] === $tier;
				}
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Filters
	 * ------------------------------------------------------------------ */

	/**
	 * Reads and validates the filter parameters from the query string.
	 *
	 * @return array<string, string> The filters.
	 */
	private function read_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filtering of a report the caller can already see.
		$filters = array(
			'tier'     => isset( $_GET['tier'] ) ? sanitize_key( wp_unslash( $_GET['tier'] ) ) : '',
			'type'     => isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '',
			'category' => isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '',
			'target'   => isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Reject anything that is not a value we actually produce.
		if ( ! isset( UI::get_tiers()[ $filters['tier'] ] ) ) {
			$filters['tier'] = '';
		}

		if ( ! isset( Taxonomy::get_types()[ $filters['type'] ] ) ) {
			$filters['type'] = '';
		}

		if ( ! isset( Taxonomy::get_categories()[ $filters['category'] ] ) ) {
			$filters['category'] = '';
		}

		if ( '' !== $filters['target'] && null === Targets::get( $filters['target'] ) ) {
			$filters['target'] = '';
		}

		return $filters;
	}

	/**
	 * Narrows findings to one tier.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @param string                           $tier     The tier slug, or an empty string.
	 * @return array<int, array<string, mixed>> The matching findings.
	 */
	private function filter_by_tier( array $findings, $tier ) {
		if ( '' === $tier ) {
			return $findings;
		}

		$severities = Taxonomy::get_tier_severities( $tier );

		return array_values(
			array_filter(
				$findings,
				static function ( $finding ) use ( $severities ) {
					return in_array( $finding['severity'], $severities, true );
				}
			)
		);
	}

	/**
	 * Narrows findings to one category.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @param string                           $category The category slug, or an empty string.
	 * @return array<int, array<string, mixed>> The matching findings.
	 */
	private function filter_by_category( array $findings, $category ) {
		if ( '' === $category ) {
			return $findings;
		}

		return array_values(
			array_filter(
				$findings,
				static function ( $finding ) use ( $category ) {
					return $finding['category'] === $category;
				}
			)
		);
	}

	/**
	 * Builds the query arguments that should survive a filter link.
	 *
	 * @param array<string, string> $filters The active filters.
	 * @param string                $exclude The filter being toggled.
	 * @return array<string, string> Query arguments.
	 */
	private function query_args( array $filters, $exclude ) {
		$map = array(
			'tier'     => 'tier',
			'type'     => 'type',
			'category' => 'category',
			'target'   => 'target',
			'search'   => 's',
		);

		$args = array();

		foreach ( $map as $filter => $parameter ) {
			if ( $filter !== $exclude && '' !== $filters[ $filter ] ) {
				$args[ $parameter ] = $filters[ $filter ];
			}
		}

		return $args;
	}

	/* ---------------------------------------------------------------------
	 * Controls
	 * ------------------------------------------------------------------ */

	/**
	 * Renders everything above the list: the count, the removable pills, the two
	 * filter menus and the search field.
	 *
	 * One row, read left to right as result then controls. The left half is what
	 * the view currently *is* — how many issues, which filters arrived from
	 * another screen, and the way out. The right half is everything that
	 * *changes* it, gathered into one cluster so that the two facets and search
	 * are found in one place rather than in three stacked rows.
	 *
	 * The facets are dropdowns rather than chip rows because a chip row costs a
	 * row of the toolbar whatever is in it, and grows with the taxonomy: the
	 * category row already wrapped on a laptop. Collapsed, each facet is one
	 * control that still names its own selection.
	 *
	 * @param array<string, string>            $filters     The active filters.
	 * @param array<int, array<string, mixed>> $scope       Findings before tier and category.
	 * @param array<int, array<string, mixed>> $tier_scoped Findings after tier, before category.
	 * @param array<int, array<string, mixed>> $findings    Findings after everything.
	 * @param array<int, array<string, mixed>> $other       Non-finding work in scope.
	 * @param array<int, array<string, mixed>> $shown       Non-finding work on screen.
	 * @return void
	 */
	private function render_controls( array $filters, array $scope, array $tier_scoped, array $findings, array $other, array $shown ) {
		$base = UI::get_screen_url( 'findings' );

		// Sticky, so the controls and the count of what they are showing stay on
		// screen while the list scrolls under them.
		echo '<div class="wvc-controls">';

		$this->render_list_bar( $filters, $findings, $shown, $base );

		echo '<div class="wvc-controls__tools">';

		UI::render_filter_menu(
			array(
				'label'   => __( 'Filter by what to do about it', 'wp-vip-compatibility' ),
				'caption' => __( 'Pending tasks', 'wp-vip-compatibility' ),
				'options' => $this->get_tier_options( $filters, $scope, $other, $base ),
			)
		);

		UI::render_filter_menu(
			array(
				'label'   => __( 'Filter by what the issue is about', 'wp-vip-compatibility' ),
				'caption' => __( 'Area', 'wp-vip-compatibility' ),
				'options' => $this->get_category_options( $filters, $tier_scoped, $base ),
			)
		);

		$this->render_search( $filters );

		echo '</div>';

		echo '</div>';
	}

	/**
	 * Renders the search field.
	 *
	 * A one-field GET form: every other filter on the screen is a link that
	 * produces a bookmarkable URL, and search carries the existing filters
	 * forward as hidden fields so submitting it narrows the current view rather
	 * than replacing it.
	 *
	 * @param array<string, string> $filters The active filters.
	 * @return void
	 */
	private function render_search( array $filters ) {
		?>
		<form class="wvc-search wvc-search--form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="wvc-findings" />
			<?php foreach ( $this->query_args( $filters, 'search' ) as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>

			<?php echo UI::get_icon( 'search', array( 'class' => 'wvc-icon wvc-icon--sm wvc-search__icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
			<label class="screen-reader-text" for="wvc-findings-search"><?php esc_html_e( 'Search findings by rule, file or function', 'wp-vip-compatibility' ); ?></label>
			<input
				type="search"
				id="wvc-findings-search"
				class="wvc-search__input"
				name="s"
				value="<?php echo esc_attr( $filters['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Search rules, files, or functions…', 'wp-vip-compatibility' ); ?>"
				autocomplete="off"
				spellcheck="false"
			/>
		</form>
		<?php
	}

	/**
	 * Builds the primary filter menu: what to do about it.
	 *
	 * The counts cover both populations the page lists — findings, and the audit
	 * work that has no file and line — because an option filters the page, so it
	 * has to count the page. Counting only findings is what produced a menu
	 * reading "Must fix 0" directly above a row badged "Must fix".
	 *
	 * Every tier is offered, including the ones with nothing in them. A zero here
	 * is the answer to the question the whole plugin exists for — "is there
	 * anything that must be fixed?" — so hiding it would be hiding good news. An
	 * empty tier renders as a count rather than as a link, because a filter that
	 * leads nowhere is not a choice worth offering.
	 *
	 * @param array<string, string>            $filters The active filters.
	 * @param array<int, array<string, mixed>> $scope   Findings the tiers are counted against.
	 * @param array<int, array<string, mixed>> $other   Non-finding work in scope, or empty.
	 * @param string                           $base    The screen URL.
	 * @return array<int, array<string, mixed>> Options.
	 */
	private function get_tier_options( array $filters, array $scope, array $other, $base ) {
		$counts = array();

		foreach ( $scope as $finding ) {
			$tier            = UI::get_tier( $finding['severity'] );
			$counts[ $tier ] = ( $counts[ $tier ] ?? 0 ) + 1;
		}

		foreach ( $other as $action ) {
			$counts[ $action['tier'] ] = ( $counts[ $action['tier'] ] ?? 0 ) + 1;
		}

		$total = count( $scope ) + count( $other );

		$options = array(
			array(
				'label'  => __( 'Everything', 'wp-vip-compatibility' ),
				'url'    => add_query_arg( $this->query_args( $filters, 'tier' ), $base ),
				'count'  => $total,
				'active' => ( '' === $filters['tier'] ),
				'empty'  => ( 0 === $total ),
				'reset'  => true,
			),
		);

		foreach ( UI::get_tiers() as $tier => $definition ) {
			$count = (int) ( $counts[ $tier ] ?? 0 );

			// A tier that is empty but currently selected keeps its link, so the
			// reason the list below is empty stays visible and undoable.
			$is_active = ( $filters['tier'] === $tier );
			$is_empty  = ( 0 === $count && ! $is_active );

			$options[] = array(
				'label'  => $definition['label'],
				// Clicking the tier you are already in clears it, so every option
				// is its own off switch and nothing needs a separate reset.
				'url'    => $is_active
					? add_query_arg( $this->query_args( $filters, 'tier' ), $base )
					: add_query_arg( array_merge( $this->query_args( $filters, 'tier' ), array( 'tier' => $tier ) ), $base ),
				'count'  => $count,
				'active' => $is_active,
				'dot'    => $tier,
				'tier'   => $tier,
				'title'  => $definition['summary'],
				'empty'  => $is_empty,
			);
		}

		return $options;
	}

	/**
	 * Builds the secondary filter menu: what the issue is about.
	 *
	 * Only categories with something in them are offered, so the menu is a map of
	 * this site's problems rather than a list of everything the scanner knows how
	 * to look for.
	 *
	 * @param array<string, string>            $filters The active filters.
	 * @param array<int, array<string, mixed>> $scope   Findings the categories are counted against.
	 * @param string                           $base    The screen URL.
	 * @return array<int, array<string, mixed>> Options.
	 */
	private function get_category_options( array $filters, array $scope, $base ) {
		$counts = array();

		foreach ( $scope as $finding ) {
			$counts[ $finding['category'] ] = ( $counts[ $finding['category'] ] ?? 0 ) + 1;
		}

		// One category holding everything is not a choice worth rendering.
		if ( count( $counts ) < 2 ) {
			return array();
		}

		$options = array(
			array(
				'label'  => __( 'All areas', 'wp-vip-compatibility' ),
				'url'    => add_query_arg( $this->query_args( $filters, 'category' ), $base ),
				// Counted now that the options sit in a column: a menu whose rows
				// all carry a number except the first reads as a number missing.
				'count'  => count( $scope ),
				'active' => ( '' === $filters['category'] ),
				'reset'  => true,
			),
		);

		foreach ( Taxonomy::get_categories() as $category => $label ) {
			if ( empty( $counts[ $category ] ) ) {
				continue;
			}

			$is_active = ( $filters['category'] === $category );

			$options[] = array(
				'label'  => $label,
				'url'    => $is_active
					? add_query_arg( $this->query_args( $filters, 'category' ), $base )
					: add_query_arg( array_merge( $this->query_args( $filters, 'category' ), array( 'category' => $category ) ), $base ),
				'count'  => $counts[ $category ],
				'active' => $is_active,
			);
		}

		return $options;
	}

	/**
	 * Renders the count, the removable filter pills and the way out.
	 *
	 * A target filter arrives by following "Review N findings" from a plugin or a
	 * theme rather than by being chosen here, so it is shown as something you were
	 * given, and its only control is the one that takes it off again. That is also
	 * why there is no item dropdown: the inventory screens are the place where you
	 * pick an item, and they already link here.
	 *
	 * @param array<string, string>            $filters  The active filters.
	 * @param array<int, array<string, mixed>> $findings The findings on screen.
	 * @param array<int, array<string, mixed>> $shown    The non-finding work on screen.
	 * @param string                           $base     The screen URL.
	 * @return void
	 */
	private function render_list_bar( array $filters, array $findings, array $shown, $base ) {
		$pills = array();

		if ( '' !== $filters['target'] ) {
			$target = Targets::get( $filters['target'] );

			$pills[] = array(
				'label' => $target['label'] ?? $filters['target'],
				'url'   => add_query_arg( $this->query_args( $filters, 'target' ), $base ),
			);
		}

		if ( '' !== $filters['type'] ) {
			$pills[] = array(
				'label' => Taxonomy::get_label( 'type', $filters['type'] ),
				'url'   => add_query_arg( $this->query_args( $filters, 'type' ), $base ),
			);
		}

		if ( '' !== $filters['search'] ) {
			$pills[] = array(
				'label' => sprintf(
					/* translators: %s: The search term. */
					__( 'Matching “%s”', 'wp-vip-compatibility' ),
					$filters['search']
				),
				'url'   => add_query_arg( $this->query_args( $filters, 'search' ), $base ),
			);
		}

		// The list holds findings and site-audit work together, so the count
		// names neither and simply counts what is on screen.
		$total = count( $findings ) + count( $shown );

		/*
		 * One control that undoes everything. The menus each toggle their own
		 * selection off and every pill has its own cross, but a reader four
		 * filters deep had to find and click four separate things to get back to
		 * the whole list.
		 */
		$narrowed = ( '' !== $filters['tier'] ) || ( '' !== $filters['category'] ) || ! empty( $pills );
		?>
		<div class="wvc-listbar">
			<p class="wvc-listbar__count">
				<span class="wvc-listbar__total"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				<?php
				echo esc_html(
					_n( 'issue', 'issues', $total, 'wp-vip-compatibility' )
				);
				?>
			</p>

			<?php if ( ! empty( $pills ) ) : ?>
				<ul class="wvc-activefilters">
					<?php foreach ( $pills as $pill ) : ?>
						<li>
							<a class="wvc-activefilter" href="<?php echo esc_url( $pill['url'] ); ?>">
								<span><?php echo esc_html( $pill['label'] ); ?></span>
								<span class="wvc-activefilter__remove" aria-hidden="true">&times;</span>
								<span class="screen-reader-text"><?php esc_html_e( 'Remove this filter', 'wp-vip-compatibility' ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $narrowed ) : ?>
				<a class="wvc-listbar__clear" href="<?php echo esc_url( $base ); ?>">
					<?php esc_html_e( 'Clear all', 'wp-vip-compatibility' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * The list
	 * ------------------------------------------------------------------ */

	/**
	 * Warns when a target on screen was too large to finish scanning.
	 *
	 * This used to sit inside each target's section. With the sections gone it is
	 * hoisted to the top of the list, because it qualifies everything below it:
	 * an incomplete scan means the absence of a finding proves nothing.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings on screen.
	 * @return void
	 */
	private function render_truncation_notice( array $findings ) {
		$index     = Results_Store::get_index();
		$truncated = array();

		foreach ( $findings as $finding ) {
			if ( ! empty( $index[ $finding['target_key'] ]['truncated'] ) ) {
				$truncated[ $finding['target_key'] ] = $finding['target_label'];
			}
		}

		if ( empty( $truncated ) ) {
			return;
		}

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html(
				sprintf(
					/* translators: %s: Comma-separated item names. */
					__( 'The scan stopped early on %s because of their size, so this list is not complete for them. Fix what is here and rescan to see the rest.', 'wp-vip-compatibility' ),
					implode( ', ', $truncated )
				)
			),
			'info',
			esc_html__( 'Partial results', 'wp-vip-compatibility' )
		);
	}

	/**
	 * Renders the ranked list of everything that needs doing.
	 *
	 * Code findings and site-audit work are interleaved by tier rather than kept
	 * in separate sections, because the reader's question is "what do I do first",
	 * and that question does not care which half of the plugin found the answer.
	 * Walking the tiers in order rather than sorting a merged array keeps the
	 * within-tier order Report::fix_list() already established.
	 *
	 * Within a tier the audit work comes first. It is site-wide — the schema, the
	 * layout of wp-content — so it tends to be a precondition for the code work
	 * beside it rather than the other way round.
	 *
	 * @param array<int, array<string, mixed>> $fixes Rule groups from Report::fix_list().
	 * @param array<int, array<string, mixed>> $tasks Actions from Report::other_work().
	 * @return void
	 */
	private function render_fix_list( array $fixes, array $tasks ) {
		$fixes_by_tier = array();

		foreach ( $fixes as $fix ) {
			$fixes_by_tier[ UI::get_tier( $fix['severity'] ) ][] = $fix;
		}

		$tasks_by_tier = array();

		foreach ( $tasks as $task ) {
			$tasks_by_tier[ $task['tier'] ][] = $task;
		}

		echo '<div class="wvc-fixlist">';

		foreach ( array_keys( UI::get_tiers() ) as $tier ) {
			foreach ( $tasks_by_tier[ $tier ] ?? array() as $task ) {
				UI::render_task_card( $task );
			}

			foreach ( $fixes_by_tier[ $tier ] ?? array() as $fix ) {
				UI::render_fix_card( $fix );
			}
		}

		echo '</div>';
	}
}
