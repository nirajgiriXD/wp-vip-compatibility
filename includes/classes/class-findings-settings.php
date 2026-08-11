<?php
/**
 * The findings report screen.
 *
 * The other screens answer "is this ready?". This one answers "what exactly is
 * wrong, and what do I do about it?".
 *
 * The report is organised by consequence rather than by inventory. Blocking and
 * important findings are laid out first and open; warnings and informational
 * findings are present but folded away, because a hundred coding-standard notes
 * should never be what a migration lead reads first. Within a target, findings
 * from the same rule are collapsed into one entry with its locations attached:
 * a rule that fires twenty times is one decision, not twenty.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
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

		wp_safe_redirect( add_query_arg( 'wvc-scanned', '1', UI::get_findings_url() ) );
		exit;
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$aggregate = Report::aggregate();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation only; the scan itself was nonce-checked in handle_rescan().
		if ( isset( $_GET['wvc-scanned'] ) ) {
			echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_notice().
				esc_html(
					sprintf(
						/* translators: 1: Number of targets. 2: Number of PHP files. */
						__( 'Scanned %1$d items and %2$s PHP files.', 'wp-vip-compatibility' ),
						(int) $aggregate['targets'],
						number_format_i18n( (int) $aggregate['totals']['files'] )
					)
				),
				'success',
				esc_html__( 'Scan complete', 'wp-vip-compatibility' )
			);
		}

		if ( 0 === $aggregate['targets'] ) {
			UI::render_empty_state(
				__( 'No scan results yet', 'wp-vip-compatibility' ),
				__( 'Run a scan to analyse every plugin, theme and must-use plugin against the VIP Platform requirements.', 'wp-vip-compatibility' ),
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

		$this->render_scan_bar( $aggregate );
		$this->render_filters( $filters, $aggregate );

		$findings = Report::findings( $filters );

		if ( empty( $findings ) ) {
			UI::render_empty_state(
				__( 'No findings match these filters', 'wp-vip-compatibility' ),
				__( 'Clear the filters to see everything the last scan reported.', 'wp-vip-compatibility' ),
				array(
					'label' => __( 'Clear filters', 'wp-vip-compatibility' ),
					'url'   => UI::get_screen_url( 'findings' ),
				)
			);

			return;
		}

		$this->render_tiers( $findings, $filters );
	}

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
	 * Renders the slim bar carrying the scan state and the report-level actions.
	 *
	 * What changed since the previous scan is deliberately not repeated here: the
	 * overview owns that, and this screen owns the findings themselves.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @return void
	 */
	private function render_scan_bar( array $aggregate ) {
		?>
		<div class="wvc-scanbar">
			<p class="wvc-scanbar__state">
				<?php echo UI::get_icon( 'clock', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
				<?php
				printf(
					/* translators: 1: Human-readable time difference. 2: Number of findings. */
					esc_html__( 'Scanned %1$s ago · %2$s findings', 'wp-vip-compatibility' ),
					esc_html( human_time_diff( max( 1, (int) $aggregate['scanned_at'] ) ) ),
					esc_html( number_format_i18n( (int) $aggregate['totals']['findings'] ) )
				);
				?>
			</p>

			<div class="wvc-scanbar__actions">
				<?php
				echo UI::get_action_button( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					array(
						'label'  => __( 'Rescan', 'wp-vip-compatibility' ),
						'submit' => 'wvc_rescan',
						'nonce'  => self::RESCAN_ACTION,
						'icon'   => 'refresh',
					)
				);
				?>

				<span class="wvc-exports">
					<span class="wvc-exports__label"><?php esc_html_e( 'Export', 'wp-vip-compatibility' ); ?></span>
					<?php foreach ( Export::get_formats() as $slug => $format ) : ?>
						<a class="wvc-exports__link" href="<?php echo esc_url( Export::get_url( $slug ) ); ?>" title="<?php echo esc_attr( $format['description'] ); ?>">
							<?php echo esc_html( $format['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the filter bar.
	 *
	 * The tier control is the primary filter and is always visible. Type,
	 * category, target and free text are secondary and stay folded away until
	 * they are needed — they used to occupy a four-field form above the report on
	 * every visit, alongside a separate row of severity cards that did the same
	 * job as the control below.
	 *
	 * @param array<string, string> $filters   The active filters.
	 * @param array<string, mixed>  $aggregate The aggregate.
	 * @return void
	 */
	private function render_filters( array $filters, array $aggregate ) {
		$base      = UI::get_screen_url( 'findings' );
		$secondary = (int) ( '' !== $filters['type'] ) + (int) ( '' !== $filters['category'] ) + (int) ( '' !== $filters['target'] ) + (int) ( '' !== $filters['search'] );
		$total     = (int) $aggregate['totals']['findings'];
		?>
		<div class="wvc-filterbar">
			<div class="wvc-segmented wvc-segmented--links" role="group" aria-label="<?php esc_attr_e( 'Filter findings by importance', 'wp-vip-compatibility' ); ?>">
				<a
					class="<?php echo ( '' === $filters['tier'] ) ? 'active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( $this->query_args( $filters, 'tier' ), $base ) ); ?>"
					<?php echo ( '' === $filters['tier'] ) ? 'aria-current="true"' : ''; ?>
				>
					<span><?php esc_html_e( 'All', 'wp-vip-compatibility' ); ?></span>
					<span class="wvc-segmented__count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				</a>

				<?php foreach ( UI::get_tiers() as $tier => $definition ) : ?>
					<?php
					$count = 0;

					foreach ( $definition['severities'] as $severity ) {
						$count += (int) ( $aggregate['by_severity'][ $severity ] ?? 0 );
					}

					if ( 0 === $count ) {
						continue;
					}

					$is_active = ( $filters['tier'] === $tier );
					$url       = $is_active
						? add_query_arg( $this->query_args( $filters, 'tier' ), $base )
						: add_query_arg( array_merge( $this->query_args( $filters, 'tier' ), array( 'tier' => $tier ) ), $base );
					?>
					<a
						class="<?php echo $is_active ? 'active' : ''; ?>"
						href="<?php echo esc_url( $url ); ?>"
						title="<?php echo esc_attr( $definition['summary'] ); ?>"
						<?php echo $is_active ? 'aria-current="true"' : ''; ?>
					>
						<span class="wvc-tierdot wvc-tierdot--<?php echo esc_attr( $tier ); ?>" aria-hidden="true"></span>
						<span><?php echo esc_html( $definition['label'] ); ?></span>
						<span class="wvc-segmented__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<details class="wvc-morefilters"<?php echo ( $secondary > 0 ) ? ' open' : ''; ?>>
				<summary class="wvc-morefilters__summary">
					<?php echo UI::get_icon( 'filter', array( 'class' => 'wvc-icon wvc-icon--xs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					<span>
						<?php
						if ( $secondary > 0 ) {
							printf(
								/* translators: %d: Number of active filters. */
								esc_html( _n( '%d more filter', '%d more filters', $secondary, 'wp-vip-compatibility' ) ),
								$secondary
							);
						} else {
							esc_html_e( 'More filters', 'wp-vip-compatibility' );
						}
						?>
					</span>
				</summary>

				<form class="wvc-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="wvc-findings" />

					<?php if ( '' !== $filters['tier'] ) : ?>
						<input type="hidden" name="tier" value="<?php echo esc_attr( $filters['tier'] ); ?>" />
					<?php endif; ?>

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
						<span><?php esc_html_e( 'Item', 'wp-vip-compatibility' ); ?></span>
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

					<button type="submit" class="wvc-btn wvc-btn--primary wvc-btn--sm"><?php esc_html_e( 'Apply', 'wp-vip-compatibility' ); ?></button>
					<a class="wvc-btn wvc-btn--ghost wvc-btn--sm" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Reset all', 'wp-vip-compatibility' ); ?></a>
				</form>
			</details>
		</div>
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

	/**
	 * Renders the findings, banded by tier and grouped by item within each band.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @param array<string, string>            $filters  The active filters.
	 * @return void
	 */
	private function render_tiers( array $findings, array $filters ) {
		$banded = array();

		foreach ( $findings as $finding ) {
			$banded[ UI::get_tier( $finding['severity'] ) ][] = $finding;
		}

		foreach ( UI::get_tiers() as $tier => $definition ) {
			if ( empty( $banded[ $tier ] ) ) {
				continue;
			}

			// A band the user asked for is never folded away, and neither are the
			// two that represent work. The rest open on demand.
			$open = $definition['open'] || ( $filters['tier'] === $tier );

			$this->render_tier( $tier, $definition, $banded[ $tier ], $open );
		}
	}

	/**
	 * Renders one tier band.
	 *
	 * @param string                           $tier       The tier slug.
	 * @param array<string, mixed>             $definition The tier definition.
	 * @param array<int, array<string, mixed>> $findings   The findings in this tier.
	 * @param bool                             $open       Whether the band starts open.
	 * @return void
	 */
	private function render_tier( $tier, array $definition, array $findings, $open ) {
		$by_target = Report::group( $findings, 'target_key' );
		$count     = count( $findings );
		?>
		<details class="wvc-band wvc-band--<?php echo esc_attr( $tier ); ?>"<?php echo $open ? ' open' : ''; ?>>
			<summary class="wvc-band__summary">
				<span class="wvc-tierdot wvc-tierdot--<?php echo esc_attr( $tier ); ?>" aria-hidden="true"></span>
				<span class="wvc-band__title"><?php echo esc_html( $definition['label'] ); ?></span>
				<span class="wvc-band__count">
					<?php
					printf(
						/* translators: 1: Number of findings. 2: Number of items. */
						esc_html( _n( '%1$s finding in %2$s item', '%1$s findings in %2$s items', $count, 'wp-vip-compatibility' ) ),
						esc_html( number_format_i18n( $count ) ),
						esc_html( number_format_i18n( count( $by_target ) ) )
					);
					?>
				</span>
				<span class="wvc-band__summary-text"><?php echo esc_html( $definition['summary'] ); ?></span>
			</summary>

			<div class="wvc-band__body">
				<?php
				// Worst-affected item first, so the biggest job is at the top.
				uasort(
					$by_target,
					static function ( $a, $b ) {
						return count( $b ) <=> count( $a );
					}
				);

				$index = Results_Store::get_index();

				foreach ( $by_target as $target_key => $target_findings ) {
					$this->render_target_group( $target_key, $target_findings, $index );
				}
				?>
			</div>
		</details>
		<?php
	}

	/**
	 * Renders the findings of one item within a tier band.
	 *
	 * @param string                              $target_key      The target key.
	 * @param array<int, array<string, mixed>>    $target_findings The findings.
	 * @param array<string, array<string, mixed>> $index           The stored result index.
	 * @return void
	 */
	private function render_target_group( $target_key, array $target_findings, array $index ) {
		$entry  = $index[ $target_key ] ?? array();
		$status = $entry['status'] ?? Scanner::STATUS_REVIEW;
		$states = array(
			Scanner::STATUS_PASS    => 'compatible',
			Scanner::STATUS_REVIEW  => 'review',
			Scanner::STATUS_BLOCKED => 'not-compatible',
		);
		$groups = Report::group_by_rule( $target_findings );
		?>
		<section class="wvc-group">
			<header class="wvc-group__head">
				<h4 class="wvc-group__title"><?php echo esc_html( $target_findings[0]['target_label'] ); ?></h4>
				<span class="wvc-group__kind"><?php echo esc_html( $target_findings[0]['target_type'] ); ?></span>
				<?php echo UI::get_status_pill( $states[ $status ] ?? 'review', Scanner::status_label( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
				<span class="wvc-group__count">
					<?php
					printf(
						/* translators: %d: Number of distinct issues. */
						esc_html( _n( '%d issue', '%d issues', count( $groups ), 'wp-vip-compatibility' ) ),
						count( $groups )
					);
					?>
				</span>
			</header>

			<?php if ( ! empty( $entry['truncated'] ) ) : ?>
				<?php
				echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					esc_html__( 'This item is large enough that the scan stopped early. Fix what is listed and rescan to see the rest.', 'wp-vip-compatibility' ),
					'info'
				);
				?>
			<?php endif; ?>

			<div class="wvc-findings">
				<?php foreach ( $groups as $group ) : ?>
					<?php UI::render_finding_group( $group ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}
}
