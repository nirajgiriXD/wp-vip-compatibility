<?php
/**
 * The findings report screen.
 *
 * The other screens answer "is this plugin ready?". This one answers "what
 * exactly is wrong, and what do I do about it?" — which is the question the
 * original plugin left to a JSON file in the uploads directory.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Rules;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Scanner;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the findings report.
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
	 * Runs a full rescan and returns to the findings screen.
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

		wp_safe_redirect( add_query_arg( 'wvc-scanned', '1', UI::get_findings_url() ) );
		exit;
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$filters   = $this->read_filters();
		$aggregate = Report::aggregate();
		$findings  = Report::findings( $filters );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation only; the scan itself was nonce-checked in handle_rescan().
		if ( isset( $_GET['wvc-scanned'] ) ) {
			echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_notice().
				esc_html(
					sprintf(
						/* translators: 1: Number of targets. 2: Number of PHP files. */
						__( 'Scanned %1$d targets and %2$d PHP files.', 'wp-vip-compatibility' ),
						(int) $aggregate['targets'],
						(int) $aggregate['totals']['files']
					)
				),
				'success',
				esc_html__( 'Scan complete', 'wp-vip-compatibility' )
			);
		}

		$this->render_scan_state( $aggregate );

		if ( 0 === $aggregate['targets'] ) {
			$this->render_empty_scan_state();
			return;
		}

		$this->render_filters( $filters, $aggregate );

		if ( empty( $findings ) ) {
			UI::render_empty_state(
				__( 'No findings match these filters', 'wp-vip-compatibility' ),
				__( 'Clear the filters to see everything the last scan reported.', 'wp-vip-compatibility' )
			);
			return;
		}

		$this->render_findings( $findings );
	}

	/**
	 * Reads and validates the filter parameters from the query string.
	 *
	 * @return array<string, string> The filters.
	 */
	private function read_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filtering of a report the caller can already see.
		$filters = array(
			'severity' => isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '',
			'type'     => isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '',
			'category' => isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '',
			'target'   => isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Reject anything that is not a value we actually produce.
		if ( ! isset( Taxonomy::get_severities()[ $filters['severity'] ] ) ) {
			$filters['severity'] = '';
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
	 * Renders the scan state header: when it ran, and what changed since.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @return void
	 */
	private function render_scan_state( array $aggregate ) {
		$delta = Results_Store::get_delta();
		?>
		<section class="wvc-scanbar">
			<div class="wvc-scanbar__state">
				<?php if ( $aggregate['scanned_at'] > 0 ) : ?>
					<p class="wvc-scanbar__when">
						<?php echo UI::get_icon( 'clock', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<?php
						printf(
							/* translators: 1: Human-readable time difference. 2: Number of targets. 3: Number of PHP files. */
							esc_html__( 'Last scanned %1$s ago — %2$d plugins, themes and must-use plugins, %3$d PHP files.', 'wp-vip-compatibility' ),
							esc_html( human_time_diff( $aggregate['scanned_at'] ) ),
							(int) $aggregate['targets'],
							(int) $aggregate['totals']['files']
						);
						?>
					</p>
				<?php else : ?>
					<p class="wvc-scanbar__when"><?php esc_html_e( 'Nothing has been scanned yet.', 'wp-vip-compatibility' ); ?></p>
				<?php endif; ?>

				<?php if ( null !== $delta ) : ?>
					<p class="wvc-scanbar__delta wvc-scanbar__delta--<?php echo esc_attr( $delta['total'] > 0 ? 'worse' : ( $delta['total'] < 0 ? 'better' : 'same' ) ); ?>">
						<?php echo UI::get_icon( 'trend', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<?php
						if ( 0 === $delta['total'] ) {
							esc_html_e( 'No change in the total number of findings since the previous scan.', 'wp-vip-compatibility' );
						} else {
							printf(
								/* translators: 1: Signed change in findings. 2: Signed change in blockers. */
								esc_html__( '%1$s findings and %2$s blockers since the previous scan.', 'wp-vip-compatibility' ),
								esc_html( sprintf( '%+d', $delta['total'] ) ),
								esc_html( sprintf( '%+d', $delta['blocking'] ) )
							);
						}
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="wvc-scanbar__actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wvc_rescan" />
					<?php wp_nonce_field( self::RESCAN_ACTION ); ?>
					<button type="submit" class="wvc-btn wvc-btn--primary">
						<?php echo UI::get_icon( 'refresh', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<?php esc_html_e( 'Rescan everything', 'wp-vip-compatibility' ); ?>
					</button>
				</form>

				<?php foreach ( Export::get_formats() as $slug => $format ) : ?>
					<a class="wvc-btn wvc-btn--ghost" href="<?php echo esc_url( Export::get_url( $slug ) ); ?>" title="<?php echo esc_attr( $format['description'] ); ?>">
						<?php echo UI::get_icon( 'download', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
						<?php echo esc_html( $format['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders the prompt shown before the first scan.
	 *
	 * @return void
	 */
	private function render_empty_scan_state() {
		UI::render_empty_state(
			__( 'No scan results yet', 'wp-vip-compatibility' ),
			__( 'Run a scan to analyse every plugin, theme and must-use plugin against the VIP Platform requirements.', 'wp-vip-compatibility' )
		);
	}

	/**
	 * Renders the filter controls and the headline counts.
	 *
	 * @param array<string, string> $filters   The active filters.
	 * @param array<string, mixed>  $aggregate The aggregate.
	 * @return void
	 */
	private function render_filters( array $filters, array $aggregate ) {
		$base = UI::get_screen_url( 'findings' );
		?>
		<div class="wvc-severity-row">
			<?php
			foreach ( Taxonomy::get_severities() as $severity => $definition ) :
				$count = (int) ( $aggregate['by_severity'][ $severity ] ?? 0 );

				if ( 0 === $count ) {
					continue;
				}

				$is_active = ( $filters['severity'] === $severity );
				$url       = $is_active
					? remove_query_arg( 'severity', add_query_arg( $this->query_args( $filters, 'severity' ), $base ) )
					: add_query_arg( array_merge( $this->query_args( $filters, 'severity' ), array( 'severity' => $severity ) ), $base );
				?>
				<a class="wvc-sevcard wvc-sevcard--<?php echo esc_attr( $severity ); ?><?php echo $is_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
					<span class="wvc-sevcard__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					<span class="wvc-sevcard__label"><?php echo esc_html( $definition['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<form class="wvc-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="wvc-findings" />

			<label class="wvc-filters__field">
				<span><?php esc_html_e( 'Type', 'wp-vip-compatibility' ); ?></span>
				<select name="type">
					<option value=""><?php esc_html_e( 'All types', 'wp-vip-compatibility' ); ?></option>
					<?php foreach ( Taxonomy::get_types() as $type => $definition ) : ?>
						<?php if ( ! empty( $aggregate['by_type'][ $type ] ) ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['type'], $type ); ?>>
								<?php echo esc_html( sprintf( '%s (%d)', $definition['label'], $aggregate['by_type'][ $type ] ) ); ?>
							</option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="wvc-filters__field">
				<span><?php esc_html_e( 'Category', 'wp-vip-compatibility' ); ?></span>
				<select name="category">
					<option value=""><?php esc_html_e( 'All categories', 'wp-vip-compatibility' ); ?></option>
					<?php foreach ( Taxonomy::get_categories() as $category => $label ) : ?>
						<?php if ( ! empty( $aggregate['by_category'][ $category ] ) ) : ?>
							<option value="<?php echo esc_attr( $category ); ?>" <?php selected( $filters['category'], $category ); ?>>
								<?php echo esc_html( sprintf( '%s (%d)', $label, $aggregate['by_category'][ $category ] ) ); ?>
							</option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="wvc-filters__field">
				<span><?php esc_html_e( 'Target', 'wp-vip-compatibility' ); ?></span>
				<select name="target">
					<option value=""><?php esc_html_e( 'Everything', 'wp-vip-compatibility' ); ?></option>
					<?php foreach ( Results_Store::get_index() as $key => $entry ) : ?>
						<?php if ( ! empty( $entry['summary']['total'] ) ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['target'], $key ); ?>>
								<?php echo esc_html( sprintf( '%s (%d)', $entry['label'], $entry['summary']['total'] ) ); ?>
							</option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="wvc-filters__field wvc-filters__field--grow">
				<span><?php esc_html_e( 'Search', 'wp-vip-compatibility' ); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Rule, file, function…', 'wp-vip-compatibility' ); ?>" />
			</label>

			<?php if ( '' !== $filters['severity'] ) : ?>
				<input type="hidden" name="severity" value="<?php echo esc_attr( $filters['severity'] ); ?>" />
			<?php endif; ?>

			<button type="submit" class="wvc-btn wvc-btn--primary"><?php esc_html_e( 'Apply', 'wp-vip-compatibility' ); ?></button>
			<a class="wvc-btn wvc-btn--ghost" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Reset', 'wp-vip-compatibility' ); ?></a>
		</form>
		<?php
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
			'type'     => 'type',
			'category' => 'category',
			'target'   => 'target',
			'search'   => 's',
			'severity' => 'severity',
		);

		$args = array();

		foreach ( $map as $filter => $parameter ) {
			if ( $filter !== $exclude && '' !== $filters[ $filter ] ) {
				$args[ $parameter ] = $filters[ $filter ];
			}
		}

		return $args;
	}

	/**
	 * Renders the findings, grouped by target.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @return void
	 */
	private function render_findings( array $findings ) {
		$grouped = Report::group( $findings, 'target_key' );
		$index   = Results_Store::get_index();

		printf(
			'<p class="wvc-result-count">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: Number of findings. 2: Number of targets. */
					_n( '%1$d finding across %2$d target.', '%1$d findings across %2$d targets.', count( $findings ), 'wp-vip-compatibility' ),
					count( $findings ),
					count( $grouped )
				)
			)
		);

		foreach ( $grouped as $target_key => $target_findings ) {
			$entry  = $index[ $target_key ] ?? array();
			$status = $entry['status'] ?? Scanner::STATUS_REVIEW;
			?>
			<section class="wvc-group">
				<header class="wvc-group__head">
					<h3 class="wvc-group__title">
						<?php echo esc_html( $target_findings[0]['target_label'] ); ?>
						<span class="wvc-group__kind"><?php echo esc_html( $target_findings[0]['target_type'] ); ?></span>
					</h3>
					<?php
					$pill = UI::get_status_pill(
						Scanner::STATUS_PASS === $status ? 'compatible' : ( Scanner::STATUS_REVIEW === $status ? 'review' : 'not-compatible' ),
						Scanner::status_label( $status )
					);

					echo $pill; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_status_pill().
					?>
					<span class="wvc-group__count">
						<?php
						printf(
							/* translators: %d: Number of findings. */
							esc_html( _n( '%d finding', '%d findings', count( $target_findings ), 'wp-vip-compatibility' ) ),
							count( $target_findings )
						);
						?>
					</span>
				</header>

				<?php if ( ! empty( $entry['truncated'] ) ) : ?>
					<?php
					echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
						esc_html__( 'This target is large enough that the scan stopped early. Fix what is listed and rescan to see the rest.', 'wp-vip-compatibility' ),
						'info'
					);
					?>
				<?php endif; ?>

				<div class="wvc-findings">
					<?php foreach ( $target_findings as $finding ) : ?>
						<?php UI::render_finding( $finding ); ?>
					<?php endforeach; ?>
				</div>
			</section>
			<?php
		}

		printf(
			'<p class="wvc-ruleref">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: Rule set version. 2: Number of rules. */
					__( 'Rule set %1$s — %2$d rules, each mapped to the VIP requirement it comes from.', 'wp-vip-compatibility' ),
					Rules::VERSION,
					count( Rules::all() )
				)
			)
		);
	}
}
