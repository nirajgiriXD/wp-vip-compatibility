<?php
/**
 * The database screen.
 *
 * WordPress VIP states three requirements for a database it will import — the InnoDB
 * storage engine, a supported utf8mb4 collation, and the standard wp_ prefix —
 * and every one of them is fixed by running a statement. The screen is the table
 * listing, with the tables that need a change sorted to the top and every
 * statement copyable in one go.
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
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$audit = Database_Audit::run();

		if ( empty( $audit['tables'] ) ) {
			UI::render_empty_state(
				__( 'No tables found in the database', 'wp-vip-compatibility' ),
				__( 'There is nothing to check here yet.', 'wp-vip-compatibility' )
			);

			return;
		}

		$this->render_tables( $audit );
	}

	/**
	 * Returns the notes shown in the WordPress Help tab.
	 *
	 * @return array<int, string> Note paragraphs, already containing limited HTML.
	 */
	public function get_page_guidance() {
		return array(
			__( '<strong>Run the SQL against a backup first.</strong> Convert engines and collations before the final export rather than after the import. The statements are ordered engine first, then collation, then prefix.', 'wp-vip-compatibility' ),
			__( '<strong>Prefixes are the exception.</strong> Report a non-standard prefix to WordPress VIP and only rename tables if WordPress VIP confirms it, because the prefix is also embedded in option names and user meta keys that control roles and capabilities.', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Returns every statement the audit proposes, in the order they should run.
	 *
	 * @param array<int, array<string, mixed>> $tables The examined tables.
	 * @return string[] SQL statements: engine first, then collation, then prefix.
	 */
	private function get_all_sql( array $tables ) {
		$sql = array(
			'engine'    => array(),
			'collation' => array(),
			'prefix'    => array(),
			'other'     => array(),
		);

		foreach ( $tables as $table ) {
			foreach ( $table['findings'] as $finding ) {
				if ( '' === $finding['sql'] ) {
					continue;
				}

				$kind           = isset( $sql[ $finding['kind'] ] ) ? $finding['kind'] : 'other';
				$sql[ $kind ][] = $finding['sql'];
			}
		}

		return array_merge( $sql['engine'], $sql['collation'], $sql['prefix'], $sql['other'] );
	}

	/**
	 * Renders the full table listing.
	 *
	 * @param array<string, mixed> $audit The audit result.
	 * @return void
	 */
	private function render_tables( array $audit ) {
		$tables = $audit['tables'];
		$sql    = $this->get_all_sql( $tables );

		// Tables that need a change lead, so the work is at the top of the list.
		// PHP sorts are only stable from 8.0, so ties fall back to the original
		// position to keep the audit's order within each group.
		$order = array_flip( array_keys( $tables ) );
		uksort(
			$tables,
			static function ( $a, $b ) use ( $tables, $order ) {
				$diff = (int) empty( $tables[ $a ]['findings'] ) <=> (int) empty( $tables[ $b ]['findings'] );

				return 0 !== $diff ? $diff : $order[ $a ] <=> $order[ $b ];
			}
		);

		$actions = '';

		if ( ! empty( $sql ) ) {
			$actions = sprintf(
				'<button type="button" class="wvc-btn wvc-btn--ghost wvc-btn--sm" data-role="copy" data-clipboard="%1$s" title="%2$s">%3$s<span>%4$s</span></button>',
				esc_attr( implode( "\n", $sql ) ),
				esc_attr__( 'Engine first, then collation, then prefix. Run them against a backup, and see Help before renaming tables for a prefix.', 'wp-vip-compatibility' ),
				UI::get_icon( 'copy', array( 'class' => 'wvc-icon wvc-icon--sm' ) ),
				esc_html(
					sprintf(
						/* translators: %s: Number of SQL statements. */
						_n( 'Copy %s SQL statement', 'Copy all %s SQL statements', count( $sql ), 'wp-vip-compatibility' ),
						number_format_i18n( count( $sql ) )
					)
				)
			);
		}

		// No heading: the page title already names the list.
		UI::render_panel_open( array( 'flush' => true ) );

		UI::render_toolbar(
			array(
				'search_label' => __( 'Search database tables by name or owner', 'wp-vip-compatibility' ),
				'placeholder'  => __( 'Search tables…', 'wp-vip-compatibility' ),
				'actions'      => $actions,
				'groups'       => array(
					array(
						'name'    => 'status',
						'label'   => __( 'Filter by verdict', 'wp-vip-compatibility' ),
						'active'  => $this->read_status(),
						'options' => array(
							array(
								'value' => 'all',
								'label' => __( 'All tables', 'wp-vip-compatibility' ),
							),
							array(
								'value' => 'not-compatible',
								'label' => __( 'Needs changes', 'wp-vip-compatibility' ),
								'dot'   => 'bad',
							),
							array(
								'value' => 'compatible',
								'label' => __( 'Ready', 'wp-vip-compatibility' ),
								'dot'   => 'ok',
							),
						),
					),
				),
			)
		);

		echo '<div class="wvc-table-wrap" data-role="table-view">';
		echo '<table class="wvc-table" aria-label="' . esc_attr__( 'Every table', 'wp-vip-compatibility' ) . '">';

		UI::render_table_head(
			array(
				array(
					'label'    => __( 'Table', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Engine', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array(
					'label'    => __( 'Collation', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-mono',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Rows', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-number',
					'sortable' => true,
					'sort'     => 'number',
					'hint'     => __( 'An estimate reported by the storage engine, not an exact count.', 'wp-vip-compatibility' ),
				),
				array(
					'label'    => __( 'Size', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-number',
					'sortable' => true,
					'sort'     => 'number',
				),
				array(
					'label'    => __( 'WordPress VIP verdict', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-status',
					'sortable' => true,
				),
				array(
					'label' => __( 'What to do', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-notes',
				),
			)
		);

		echo '<tbody>';

		foreach ( $tables as $table ) {
			$this->render_row( $table );
		}

		echo '</tbody></table>';
		echo '</div>';

		UI::render_panel_close();
	}

	/**
	 * Renders one database table row.
	 *
	 * @param array<string, mixed> $table The examined table.
	 * @return void
	 */
	private function render_row( array $table ) {
		$compatible = empty( $table['findings'] );
		$state      = $compatible ? 'compatible' : 'not-compatible';

		printf( '<tr class="wvc-row" data-status="%s">', esc_attr( $state ) );

		// The owner used to be a column. It only ever matters alongside a
		// verdict, so it rides with the name instead.
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Table', 'wp-vip-compatibility' ) . '">';
		echo '<span class="wvc-item">';
		echo '<code class="wvc-item__name">' . esc_html( $table['name'] ) . '</code>';
		echo '<span class="wvc-item__meta">' . esc_html( $table['source'] ) . '</span>';
		echo '</span>';
		echo '</td>';

		echo '<td data-label="' . esc_attr__( 'Engine', 'wp-vip-compatibility' ) . '">' . esc_html( $table['engine'] ) . '</td>';
		echo '<td class="wvc-col-mono" data-label="' . esc_attr__( 'Collation', 'wp-vip-compatibility' ) . '">' . esc_html( $table['collation'] ) . '</td>';
		echo '<td class="wvc-col-number" data-label="' . esc_attr__( 'Rows', 'wp-vip-compatibility' ) . '">' . esc_html( number_format_i18n( (int) $table['rows'] ) ) . '</td>';
		echo '<td class="wvc-col-number" data-label="' . esc_attr__( 'Size', 'wp-vip-compatibility' ) . '">' . esc_html( size_format( (int) $table['bytes'], 1 ) ) . '</td>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		echo UI::get_status_cell(
			$state,
			$compatible ? __( 'Ready', 'wp-vip-compatibility' ) : __( 'Needs changes', 'wp-vip-compatibility' )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'What to do', 'wp-vip-compatibility' ) . '">';

		if ( $compatible ) {
			echo '<span class="wvc-dash" aria-hidden="true">—</span>';
		} else {
			echo '<ul class="wvc-notes">';

			foreach ( $table['findings'] as $finding ) {
				echo '<li>';
				echo '<span class="wvc-note__label">' . esc_html( $finding['label'] ) . '</span> ';
				echo esc_html( $finding['detail'] );

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

	/**
	 * Reads the verdict filter preselected through the query string.
	 *
	 * @return string The verdict state, or `all`.
	 */
	private function read_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection of a client-side filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';

		return in_array( $status, array( 'not-compatible', 'compatible' ), true ) ? $status : 'all';
	}
}
