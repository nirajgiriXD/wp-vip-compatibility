<?php
/**
 * Submenu page for displaying the database settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Database_Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the database audit.
 */
class Database_Settings {

	use Singleton;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$audit = Database_Audit::run();

		if ( empty( $audit['tables'] ) ) {
			UI::render_empty_state(
				__( 'No tables found in the database.', 'wp-vip-compatibility' ),
				__( 'There is nothing to check on this screen yet.', 'wp-vip-compatibility' )
			);
			return;
		}

		$this->render_summary( $audit['summary'] );

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html__( 'Run these statements against a backup first, and convert collations before the final export rather than after the import. Table prefixes are the exception: report a non-standard prefix to VIP and only rename tables if VIP confirms it, because the prefix is also embedded in option names and user meta keys that control roles and capabilities.', 'wp-vip-compatibility' ),
			'warning',
			esc_html__( 'Before you run any SQL', 'wp-vip-compatibility' )
		);

		UI::render_toolbar( array( 'search_label' => __( 'Search database tables', 'wp-vip-compatibility' ) ) );

		echo '<div class="wvc-table-wrap">';
		echo '<table class="wvc-table" data-target-entity="database">';

		UI::render_table_head(
			array(
				array(
					'label' => __( 'SN', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-sn',
				),
				array(
					'label'    => __( 'Table Name', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Engine', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array(
					'label'    => __( 'Collation', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array(
					'label'    => __( 'Size', 'wp-vip-compatibility' ),
					'sortable' => true,
					'sort'     => 'number',
				),
				array(
					'label'    => __( 'Source', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array(
					'label'    => __( 'WP VIP Compatibility', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-status',
					'sortable' => true,
				),
				array(
					'label' => __( 'Notes', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-notes',
				),
			)
		);

		echo '<tbody>';

		foreach ( $audit['tables'] as $table ) {
			$this->render_row( $table );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders the headline counts.
	 *
	 * @param array<string, mixed> $summary The audit summary.
	 * @return void
	 */
	private function render_summary( array $summary ) {
		?>
		<ul class="wvc-stats wvc-stats--inline">
			<li>
				<span class="wvc-stats__value"><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></span>
				<span class="wvc-stats__label"><?php esc_html_e( 'Tables', 'wp-vip-compatibility' ); ?></span>
			</li>
			<li class="is-ok">
				<span class="wvc-stats__value"><?php echo esc_html( number_format_i18n( $summary['compatible'] ) ); ?></span>
				<span class="wvc-stats__label"><?php esc_html_e( 'Ready', 'wp-vip-compatibility' ); ?></span>
			</li>
			<li class="is-bad">
				<span class="wvc-stats__value"><?php echo esc_html( number_format_i18n( $summary['incompatible'] ) ); ?></span>
				<span class="wvc-stats__label"><?php esc_html_e( 'Need changes', 'wp-vip-compatibility' ); ?></span>
			</li>
			<li>
				<span class="wvc-stats__value"><?php echo esc_html( size_format( $summary['bytes'], 1 ) ); ?></span>
				<span class="wvc-stats__label"><?php esc_html_e( 'Total size', 'wp-vip-compatibility' ); ?></span>
			</li>
		</ul>

		<?php if ( $summary['issues']['engine'] > 0 || $summary['issues']['collation'] > 0 || $summary['issues']['prefix'] > 0 ) : ?>
			<p class="wvc-result-count">
				<?php
				$breakdown = array();

				if ( $summary['issues']['engine'] > 0 ) {
					/* translators: %d: Number of tables. */
					$breakdown[] = sprintf( _n( '%d wrong storage engine', '%d wrong storage engines', $summary['issues']['engine'], 'wp-vip-compatibility' ), $summary['issues']['engine'] );
				}

				if ( $summary['issues']['collation'] > 0 ) {
					/* translators: %d: Number of tables. */
					$breakdown[] = sprintf( _n( '%d unsupported collation', '%d unsupported collations', $summary['issues']['collation'], 'wp-vip-compatibility' ), $summary['issues']['collation'] );
				}

				if ( $summary['issues']['prefix'] > 0 ) {
					/* translators: %d: Number of tables. */
					$breakdown[] = sprintf( _n( '%d non-standard prefix', '%d non-standard prefixes', $summary['issues']['prefix'], 'wp-vip-compatibility' ), $summary['issues']['prefix'] );
				}

				echo esc_html( implode( ' · ', $breakdown ) );
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders one table row.
	 *
	 * @param array<string, mixed> $table The examined table.
	 * @return void
	 */
	private function render_row( array $table ) {
		$is_compatible = empty( $table['findings'] );

		echo '<tr>';
		echo '<td class="wvc-col-sn"></td>';
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Table Name', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $table['name'] ) . '</code></td>';
		echo '<td data-label="' . esc_attr__( 'Engine', 'wp-vip-compatibility' ) . '">' . esc_html( $table['engine'] ) . '</td>';
		echo '<td class="wvc-col-mono" data-label="' . esc_attr__( 'Collation', 'wp-vip-compatibility' ) . '">' . esc_html( $table['collation'] ) . '</td>';
		echo '<td data-label="' . esc_attr__( 'Size', 'wp-vip-compatibility' ) . '">' . esc_html( size_format( $table['bytes'], 1 ) ) . '</td>';
		echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Source', 'wp-vip-compatibility' ) . '">' . esc_html( $table['source'] ) . '</td>';

		$status_cell = UI::get_status_cell(
			$is_compatible ? 'compatible' : 'not-compatible',
			$is_compatible ? __( 'Compatible', 'wp-vip-compatibility' ) : __( 'Needs changes', 'wp-vip-compatibility' )
		);

		echo $status_cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_status_cell().

		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'Notes', 'wp-vip-compatibility' ) . '">';

		if ( $is_compatible ) {
			echo '<span class="wvc-dash" aria-hidden="true">—</span>';
		} else {
			echo '<ul class="wvc-notes">';

			foreach ( $table['findings'] as $finding ) {
				echo '<li>';
				echo '<span class="wvc-note__label">' . esc_html( $finding['label'] ) . '</span> ';
				echo esc_html( $finding['detail'] ) . ' ';
				echo esc_html( $finding['remediation'] );

				if ( '' !== $finding['sql'] ) {
					echo UI::get_code_snippet( $finding['sql'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				}

				echo '</li>';
			}

			echo '</ul>';
		}

		echo '</td>';
		echo '</tr>';
	}
}
