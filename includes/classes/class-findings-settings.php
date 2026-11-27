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
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Includes\Classes;

use CUTOVER_AUDIT\Includes\Traits\Singleton;
use CUTOVER_AUDIT\Includes\Scanner\Report;
use CUTOVER_AUDIT\Includes\Scanner\Results_Store;
use CUTOVER_AUDIT\Includes\Scanner\Scanner;
use CUTOVER_AUDIT\Includes\Scanner\Targets;
use CUTOVER_AUDIT\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the fix list.
 */
class Findings_Settings {

	use Singleton;

	/**
	 * Nonce action for the rescan button.
	 */
	const RESCAN_ACTION = 'cutover_audit_rescan_all';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_cutover_audit_rescan', array( $this, 'handle_rescan' ) );
	}

	/**
	 * Returns the notes shown in the WordPress Help tab.
	 *
	 * @return array<int, string> Note paragraphs, already containing limited HTML.
	 */
	public function get_page_guidance() {
		return array(
			__( '<strong>Worst first.</strong> The list is ranked by what to do — must fix, should fix, worth checking, FYI. The same issue in several places is one entry, with every place listed under it.', 'cutover-audit' ),
			__( '<strong>What each entry shows.</strong> The file and line, the code, why it matters on WordPress VIP, and the recommended fix. Where they exist, also an alternative approach and a link to the documentation.', 'cutover-audit' ),
			__( '<strong>Filters and sharing.</strong> The column of links narrows the list in one click, and each filter is part of the address, so a view can be bookmarked or shared. Press <code>/</code> to search. Export from the top bar as JSON, CSV or Markdown.', 'cutover-audit' ),
			__( '<strong>Unsure, or already reviewed?</strong> The scan reads code without running it, so when it cannot be sure, it says so under the code instead of guessing. To accept a finding, add <code>// cutover-audit:ignore &lt;rule-id&gt; -- reason</code> to the line, or <code>cutover-audit:ignore-next-line</code> above it, and rescan. Security findings cannot be silenced.', 'cutover-audit' ),
		);
	}

	/**
	 * Runs a full rescan and returns to the screen the request came from.
	 *
	 * @return void
	 */
	public function handle_rescan() {
		if ( ! current_user_can( Plugin::get_capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to run compatibility scans.', 'cutover-audit' ),
				esc_html__( 'Forbidden', 'cutover-audit' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::RESCAN_ACTION );

		// An unknown scope resolves to the whole site rather than being rejected:
		// the worst a bad value can do is more work than was asked for.
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : Report::SCOPE_ALL; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran above.
		$scope = Report::resolve_scope( $scope );

		$definition = Report::get_scope( $scope );

		/*
		 * An area made of scannable rows is rescanned in the browser. Nothing is
		 * read here: the stored results are discarded, which makes every row in
		 * that area read as "not scanned yet", and the screen we redirect to
		 * already knows how to resolve those one at a time behind a progress bar.
		 *
		 * That turns the wait from a blank admin-post.php into the screen you
		 * asked about, filling in. It also means an interrupted rescan is not a
		 * lost one — the rows that never resolved are still marked unscanned, so
		 * the next visit to that screen picks up exactly where this one stopped.
		 */
		if ( ! empty( $definition['async'] ) ) {
			Report::clear_scope( $scope );

			wp_safe_redirect( $this->get_redirect_url( $definition['screen'], 'cutover-audit-scanning', $scope ) );
			exit;
		}

		/*
		 * A full rescan tokenises every PHP file in every plugin, theme and
		 * must-use plugin. On a site with a few dozen plugins that is minutes of
		 * work. Narrowing the scope is the real answer to that; raising the
		 * limits is the safety net.
		 */
		Scanner::prepare_long_request();

		Report::run_full_scan( true, $scope );

		// The scope rides back on the redirect so the confirmation can report
		// what was actually read rather than the site-wide totals.
		wp_safe_redirect( $this->get_redirect_url( $definition['screen'], 'cutover-audit-scanned', $scope ) );
		exit;
	}

	/**
	 * Resolves where a rescan should send the browser.
	 *
	 * A form posted to admin-post.php has to redirect somewhere — there is no
	 * page there to render — so the question is never "redirect or not" but
	 * "where to". The answer is: as close to where you already were as the scope
	 * allows.
	 *
	 * - Rescanning everything is not about any one screen, so it goes back to the
	 *   screen it was started from.
	 * - Rescanning one area goes to the screen that owns that area, because that
	 *   is the thing you asked about — unless you are already on it, in which
	 *   case there is nowhere to go.
	 *
	 * Either way, when the destination is the screen the request came from, the
	 * referring URL is reused rather than rebuilt. Rebuilding it dropped the
	 * query string, so rescanning from a filtered or searched view silently reset
	 * it — you pressed a button about scanning and lost your place.
	 *
	 * @param string $screen The screen key the scope owns, or '' for the whole site.
	 * @param string $param  The query parameter marking the redirect.
	 * @param string $scope  The scope slug the parameter carries.
	 * @return string The admin URL.
	 */
	private function get_redirect_url( $screen, $param, $scope ) {
		$referer = $this->get_referring_view();

		// A full rescan has no screen of its own: it belongs wherever it started.
		// With no usable referrer, the fix list is where the work is.
		if ( '' === $screen ) {
			$screen = ( null === $referer ) ? 'findings' : $referer['screen'];
		}

		// Already looking at the screen this rescan is about: keep the view
		// exactly as it is — filters, search, sort — and only mark it.
		$args = ( null !== $referer && $referer['screen'] === $screen ) ? $referer['args'] : array();

		$args[ $param ] = $scope;

		return UI::get_screen_url( $screen, $args );
	}

	/**
	 * Returns the plugin screen the request came from, and how it was filtered.
	 *
	 * @return array{screen: string, args: array<string, string>}|null The view, or null when the referrer is not one of ours.
	 */
	private function get_referring_view() {
		$referer = wp_get_referer();

		if ( false === $referer ) {
			return null;
		}

		$query = array();
		parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );

		$page = isset( $query['page'] ) ? sanitize_key( $query['page'] ) : '';

		foreach ( UI::get_screens() as $key => $definition ) {
			if ( $definition['slug'] !== $page ) {
				continue;
			}

			/*
			 * `page` is added back by get_screen_url(), and the two scan markers
			 * belong to the request that set them: carrying one forward would
			 * replay a finished scan's confirmation, or restart a rescan, on the
			 * next press. Everything else is the reader's view and is kept.
			 */
			unset( $query['page'], $query['cutover-audit-scanned'], $query['cutover-audit-scanning'] );

			$args = array();

			foreach ( $query as $name => $value ) {
				// Only flat values: these came off a URL and are going back onto
				// one, and every screen validates its own parameters on arrival.
				if ( is_scalar( $value ) ) {
					$args[ sanitize_key( $name ) ] = sanitize_text_field( (string) $value );
				}
			}

			return array(
				'screen' => $key,
				'args'   => $args,
			);
		}

		return null;
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
				__( 'No scan results yet', 'cutover-audit' ),
				__( 'Run a scan to analyse every plugin, theme and must-use plugin against the WordPress VIP platform guidelines.', 'cutover-audit' ),
				array(
					'label'   => __( 'Run the first scan', 'cutover-audit' ),
					'submit'  => 'cutover_audit_rescan',
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
		 * leaves on screen, counted against everything the item, type and search
		 * allow.
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

		// Every tier is rendered, and the selected one is shown, so switching
		// tiers is instant and needs no reload. The tier in the URL only decides
		// which card is visible first.
		$findings = $this->filter_by_category( $scope, $filters['category'] );

		// Category, item, type and search are properties of a code finding. While
		// one of them is narrowing the page, work that cannot have such a property
		// is out of scope entirely rather than filtered to nothing — so it leaves
		// the counts as well as the list.
		$other = $this->other_work_applies( $filters ) ? Report::other_work() : array();
		$shown = $other;

		$base = UI::get_screen_url( 'findings' );

		// The filters are a card of their own, and each tier below is a card, so
		// the "Everything" view reads as a few clear blocks rather than one list.
		echo '<div class="cutover-audit-findings">';
		echo '<div class="cutover-audit-findings__controls cutover-audit-sheet">';

		$this->render_toolbar( $filters, $scope, $other, $findings, $shown, $base );
		$this->render_active_filters( $filters, $base );

		echo '</div>';
		echo '<div class="cutover-audit-findings__main">';

		if ( empty( $findings ) && empty( $shown ) ) {
			UI::render_empty_state(
				__( 'Nothing matches these filters', 'cutover-audit' ),
				__( 'Clear them to see everything the last scan reported.', 'cutover-audit' ),
				array(
					'label' => __( 'Clear filters', 'cutover-audit' ),
					'url'   => $base,
				)
			);
		} else {
			$this->render_truncation_notice( $findings );
			$this->render_fix_list( Report::fix_list( $findings ), $shown, $filters['tier'] );
		}

		echo '</div></div>';
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

	/*
	 * ---------------------------------------------------------------------
	 * Filters
	 * ------------------------------------------------------------------
	 */

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

	/*
	 * ---------------------------------------------------------------------
	 * Controls
	 * ------------------------------------------------------------------
	 */

	/**
	 * Renders the card's toolbar: what to do, search, count.
	 *
	 * The tiers stay an open row rather than a dropdown, because on this screen
	 * the counts are the information: "Must fix 3 · Should fix 12" is the shape
	 * of the job. Every option is a link, so a filtered view can be bookmarked
	 * or pasted into a migration ticket.
	 *
	 * @param array<string, string>            $filters     The active filters.
	 * @param array<int, array<string, mixed>> $scope       Findings before tier and category.
	 * @param array<int, array<string, mixed>> $other       Non-finding work in scope.
	 * @param array<int, array<string, mixed>> $findings    The findings on screen.
	 * @param array<int, array<string, mixed>> $shown       The non-finding work on screen.
	 * @param string                           $base        The screen URL.
	 * @return void
	 */
	private function render_toolbar( array $filters, array $scope, array $other, array $findings, array $shown, $base ) {
		$options = $this->get_tier_options( $filters, $scope, $other, $base );
		$summary = '';

		foreach ( $options as $index => $option ) {
			// The line the count shows while this option is selected, worded and
			// pluralised here so the script never has to.
			$options[ $index ]['summary'] = sprintf(
				/* translators: %s: Number of issues on screen. */
				_n( '%s issue', '%s issues', (int) $option['count'], 'cutover-audit' ),
				number_format_i18n( (int) $option['count'] )
			);

			if ( $option['active'] ) {
				$summary = $options[ $index ]['summary'];
			}
		}
		?>
		<div class="cutover-audit-toolbar cutover-audit-findings__toolbar">
			<div class="cutover-audit-toolbar__filters">
				<nav class="cutover-audit-segmented" aria-label="<?php esc_attr_e( 'Filter by what to do', 'cutover-audit' ); ?>">
					<?php foreach ( $options as $option ) : ?>
						<?php
						$classes = 'cutover-audit-segmented__option' . ( $option['active'] ? ' active' : '' ) . ( ! empty( $option['empty'] ) ? ' is-empty' : '' );
						$data    = sprintf( ' data-tier="%1$s" data-summary="%2$s"', esc_attr( $option['tier'] ?? '' ), esc_attr( $option['summary'] ) );
						$inner   = ( empty( $option['tier'] ) ? '' : UI::get_mark( $option['tier'] ) )
							. '<span>' . esc_html( $option['label'] ) . '</span>'
							. '<span class="cutover-audit-segmented__count">' . esc_html( number_format_i18n( (int) $option['count'] ) ) . '</span>';
						?>
						<?php if ( ! empty( $option['empty'] ) ) : ?>
							<span class="<?php echo esc_attr( $classes ); ?>" title="<?php echo esc_attr( $option['title'] ?? '' ); ?>"<?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>><?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></span>
						<?php else : ?>
							<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $option['url'] ); ?>" title="<?php echo esc_attr( $option['title'] ?? '' ); ?>"<?php echo $option['active'] ? ' aria-current="true"' : ''; ?><?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>><?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></a>
						<?php endif; ?>
					<?php endforeach; ?>
				</nav>

			</div>

			<div class="cutover-audit-toolbar__tools">
				<p class="cutover-audit-result-count" data-role="findings-count" aria-live="polite"><?php echo esc_html( $summary ); ?></p>

				<?php $this->render_search( $filters ); ?>

				<?php if ( ! empty( $findings ) ) : ?>
					<?php // Revealed by the script, which is what makes it work. ?>
					<button type="button" class="cutover-audit-btn cutover-audit-btn--ghost cutover-audit-btn--sm cutover-audit-findings__expand" data-role="expand-all" aria-pressed="false" hidden>
						<?php echo UI::get_icon( 'expand', array( 'class' => 'cutover-audit-icon cutover-audit-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<span><?php esc_html_e( 'Expand all', 'cutover-audit' ); ?></span>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
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
		<?php // The slug comes from the screen map rather than a literal, so it cannot drift away from the page it posts to. ?>
		<form class="cutover-audit-search cutover-audit-search--form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="<?php echo esc_attr( UI::get_screens()['findings']['slug'] ); ?>" />
			<?php foreach ( $this->query_args( $filters, 'search' ) as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>

			<?php echo UI::get_icon( 'search', array( 'class' => 'cutover-audit-icon cutover-audit-icon--sm cutover-audit-search__icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
			<label class="screen-reader-text" for="cutover-audit-findings-search"><?php esc_html_e( 'Search findings by rule, file or function', 'cutover-audit' ); ?></label>
			<input
				type="search"
				id="cutover-audit-findings-search"
				class="cutover-audit-search__input"
				name="s"
				value="<?php echo esc_attr( $filters['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Search findings…', 'cutover-audit' ); ?>"
				autocomplete="off"
				spellcheck="false"
			/>
			<kbd class="cutover-audit-search__key" aria-hidden="true">/</kbd>
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
				'label'  => __( 'Everything', 'cutover-audit' ),
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
	 * Renders the removable filter tags under the toolbar.
	 *
	 * A target filter arrives by following "Review N findings" from a plugin or a
	 * theme rather than by being chosen here, so it is shown as something you were
	 * given, and its only control is the one that takes it off again. The row is
	 * only drawn while one of these tags is in play; the tiers and areas show
	 * their own selection in the toolbar.
	 *
	 * @param array<string, string> $filters The active filters.
	 * @param string                $base    The screen URL.
	 * @return void
	 */
	private function render_active_filters( array $filters, $base ) {
		$pills = array();

		if ( '' !== $filters['target'] ) {
			$target = Targets::get( $filters['target'] );

			$pills[] = array(
				'label' => $target['label'] ?? $filters['target'],
				'url'   => add_query_arg( $this->query_args( $filters, 'target' ), $base ),
			);
		}

		if ( '' !== $filters['category'] ) {
			$pills[] = array(
				'label' => Taxonomy::get_label( 'category', $filters['category'] ),
				'url'   => add_query_arg( $this->query_args( $filters, 'category' ), $base ),
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
					__( 'Matching “%s”', 'cutover-audit' ),
					$filters['search']
				),
				'url'   => add_query_arg( $this->query_args( $filters, 'search' ), $base ),
			);
		}

		if ( empty( $pills ) ) {
			return;
		}
		?>
		<div class="cutover-audit-findings__active">
			<ul class="cutover-audit-activefilters">
				<?php foreach ( $pills as $pill ) : ?>
					<li>
						<a class="cutover-audit-activefilter" href="<?php echo esc_url( $pill['url'] ); ?>">
							<span><?php echo esc_html( $pill['label'] ); ?></span>
							<span class="cutover-audit-activefilter__remove" aria-hidden="true"><?php echo UI::get_icon( 'close', array( 'class' => 'cutover-audit-icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Remove this filter', 'cutover-audit' ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
				<li>
					<?php // Back to the whole list, the same place the empty state's "Clear filters" goes. ?>
					<a class="cutover-audit-clearfilters" href="<?php echo esc_url( $base ); ?>"><?php echo UI::get_icon( 'filter-off', array( 'class' => 'cutover-audit-icon cutover-audit-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?><span><?php esc_html_e( 'Clear filters', 'cutover-audit' ); ?></span></a>
				</li>
			</ul>
		</div>
		<?php
	}

	/*
	 * ---------------------------------------------------------------------
	 * The list
	 * ------------------------------------------------------------------
	 */

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

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		echo UI::get_notice(
			esc_html(
				sprintf(
					/* translators: %s: Comma-separated item names. */
					__( 'The scan stopped early on %s because of their size, so this list is not complete for them. Fix what is here and rescan to see the rest.', 'cutover-audit' ),
					implode( ', ', $truncated )
				)
			),
			'info',
			esc_html__( 'Partial results', 'cutover-audit' )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
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
	 * Every tier is rendered and only the selected one is shown, so the tier
	 * buttons switch between them in the browser without a reload.
	 *
	 * @param array<int, array<string, mixed>> $fixes    Rule groups from Report::fix_list().
	 * @param array<int, array<string, mixed>> $tasks    Actions from Report::other_work().
	 * @param string                           $selected The selected tier, or an empty string for all.
	 * @return void
	 */
	private function render_fix_list( array $fixes, array $tasks, $selected = '' ) {
		$fixes_by_tier = array();

		foreach ( $fixes as $fix ) {
			$fixes_by_tier[ UI::get_tier( $fix['severity'] ) ][] = $fix;
		}

		$tasks_by_tier = array();

		foreach ( $tasks as $task ) {
			$tasks_by_tier[ $task['tier'] ][] = $task;
		}

		echo '<div class="cutover-audit-fixlist">';

		$visible = 0;

		foreach ( array_keys( UI::get_tiers() ) as $tier ) {
			if ( empty( $tasks_by_tier[ $tier ] ) && empty( $fixes_by_tier[ $tier ] ) ) {
				continue;
			}

			// One card per tier, opened by its heading, so a long list can be
			// skimmed — and walked by heading with a screen reader — one tier at
			// a time. The heading carries no count: an entry takes the tier of its
			// worst occurrence, so a count here could never agree with the
			// filter's count of findings.
			$show     = ( '' === $selected || $selected === $tier );
			$visible += $show ? 1 : 0;

			printf(
				'<section class="cutover-audit-tiergroup cutover-audit-tiergroup--%1$s" data-tier="%1$s"%2$s>',
				esc_attr( $tier ),
				$show ? '' : ' hidden'
			);

			UI::render_tier_heading( $tier );

			foreach ( $tasks_by_tier[ $tier ] ?? array() as $task ) {
				UI::render_task_card( $task );
			}

			foreach ( $fixes_by_tier[ $tier ] ?? array() as $fix ) {
				UI::render_fix_card( $fix );
			}

			echo '</section>';
		}

		echo '</div>';

		// Shown by the script when the selected tier has nothing in it.
		printf( '<div data-role="tier-empty"%s>', $visible ? ' hidden' : '' );
		UI::render_empty_state(
			__( 'Nothing in this tier', 'cutover-audit' ),
			__( 'Choose another tier above, or Everything to see the whole list.', 'cutover-audit' )
		);
		echo '</div>';
	}
}
