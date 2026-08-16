<?php
/**
 * The database screen.
 *
 * VIP states three requirements for a database it will import — the InnoDB
 * storage engine, a supported utf8mb4 collation, and the standard wp_ prefix —
 * and every one of them is fixed by running a statement. The screen is built
 * around that: the shape of the schema first, then the work grouped by the
 * requirement it belongs to with the SQL attached, then the full table listing
 * for anyone who needs to look up a specific table.
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

		$this->render_stats( $audit );
		$this->render_issues( $audit );
		$this->render_shape( $audit );
		$this->render_tables( $audit );
	}

	/**
	 * Renders the headline statistics.
	 *
	 * @param array<string, mixed> $audit The audit result.
	 * @return void
	 */
	private function render_stats( array $audit ) {
		$summary = $audit['summary'];
		$rows    = 0;

		foreach ( $audit['tables'] as $table ) {
			$rows += (int) $table['rows'];
		}

		UI::render_stats(
			array(
				array(
					'label' => __( 'Tables', 'wp-vip-compatibility' ),
					'value' => number_format_i18n( (int) $summary['total'] ),
					'meta'  => sprintf(
						/* translators: %s: Approximate number of rows. */
						__( '≈ %s rows in total', 'wp-vip-compatibility' ),
						number_format_i18n( $rows )
					),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'Size on disk', 'wp-vip-compatibility' ),
					'value' => size_format( (int) $summary['bytes'], 1 ),
					'meta'  => __( 'Data and indexes together', 'wp-vip-compatibility' ),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'Needs changes', 'wp-vip-compatibility' ),
					'value' => number_format_i18n( (int) $summary['incompatible'] ),
					'meta'  => __( 'Tables VIP will not import as they are', 'wp-vip-compatibility' ),
					'tone'  => $summary['incompatible'] > 0 ? 'bad' : 'ok',
					'url'   => $summary['incompatible'] > 0
						? UI::get_screen_url( 'database', array( 'status' => 'not-compatible' ) )
						: '',
				),
				array(
					'label' => __( 'Ready', 'wp-vip-compatibility' ),
					'value' => number_format_i18n( (int) $summary['compatible'] ),
					'meta'  => __( 'Engine, collation and prefix all match', 'wp-vip-compatibility' ),
					'tone'  => 'ok',
				),
			)
		);
	}

	/**
	 * Renders the work, grouped by the requirement it belongs to.
	 *
	 * Twenty tables with the wrong collation are one job, not twenty. Grouping by
	 * requirement means the SQL for all of them can be copied in one go, which is
	 * how the change is actually made.
	 *
	 * @param array<string, mixed> $audit The audit result.
	 * @return void
	 */
	private function render_issues( array $audit ) {
		$summary = $audit['summary'];
		$issues  = (int) $summary['issues']['engine'] + (int) $summary['issues']['collation'] + (int) $summary['issues']['prefix'];

		if ( 0 === $issues ) {
			echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				esc_html__( 'Every table uses InnoDB, a supported utf8mb4 collation and the standard wp_ prefix. Nothing has to change before the export.', 'wp-vip-compatibility' ),
				'success',
				esc_html__( 'The schema is ready to import', 'wp-vip-compatibility' )
			);

			return;
		}

		$groups = $this->group_findings( $audit['tables'] );
		$all    = array();

		foreach ( $groups as $group ) {
			$all = array_merge( $all, $group['sql'] );
		}

		UI::render_panel_open(
			array(
				'title'   => __( 'What has to change', 'wp-vip-compatibility' ),
				'count'   => number_format_i18n( $issues ),
				'summary' => __( 'Grouped by requirement, because one requirement is one job however many tables it covers.', 'wp-vip-compatibility' ),
			)
		);

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html__( 'Run these against a backup first, and convert collations before the final export rather than after the import. Table prefixes are the exception: report a non-standard prefix to VIP and only rename tables if VIP confirms it, because the prefix is also embedded in option names and user meta keys that control roles and capabilities.', 'wp-vip-compatibility' ),
			'warning',
			esc_html__( 'Before you run any SQL', 'wp-vip-compatibility' )
		);

		echo '<ul class="wvc-issues">';

		foreach ( $groups as $kind => $group ) {
			$id = UI::next_id( 'wvc-sql' );
			?>
			<li class="wvc-issue wvc-issue--<?php echo esc_attr( $group['tone'] ); ?>">
				<div class="wvc-issue__head">
					<h4 class="wvc-issue__title"><?php echo esc_html( $group['label'] ); ?></h4>
					<span class="wvc-issue__count">
						<?php
						printf(
							/* translators: %s: Number of tables. */
							esc_html( _n( '%s table', '%s tables', count( $group['tables'] ), 'wp-vip-compatibility' ) ),
							esc_html( number_format_i18n( count( $group['tables'] ) ) )
						);
						?>
					</span>
				</div>

				<p class="wvc-issue__detail"><?php echo esc_html( $group['remediation'] ); ?></p>

				<p class="wvc-issue__tables">
					<?php foreach ( array_slice( $group['tables'], 0, 8 ) as $name ) : ?>
						<code><?php echo esc_html( $name ); ?></code>
					<?php endforeach; ?>
					<?php if ( count( $group['tables'] ) > 8 ) : ?>
						<span class="wvc-issue__more">
							<?php
							printf(
								/* translators: %s: Number of further tables. */
								esc_html__( 'and %s more', 'wp-vip-compatibility' ),
								esc_html( number_format_i18n( count( $group['tables'] ) - 8 ) )
							);
							?>
						</span>
					<?php endif; ?>
				</p>

				<?php if ( ! empty( $group['sql'] ) ) : ?>
					<details class="wvc-sql" id="<?php echo esc_attr( $id ); ?>">
						<summary class="wvc-sql__summary">
							<?php echo UI::get_icon( 'chevron', array( 'class' => 'wvc-icon wvc-icon--xs wvc-sql__caret' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
							<span>
								<?php
								printf(
									/* translators: %s: Number of statements. */
									esc_html( _n( 'Show the %s statement', 'Show the %s statements', count( $group['sql'] ), 'wp-vip-compatibility' ) ),
									esc_html( number_format_i18n( count( $group['sql'] ) ) )
								);
								?>
							</span>
						</summary>

						<div class="wvc-sql__body">
							<button
								type="button"
								class="wvc-btn wvc-btn--ghost wvc-btn--sm"
								data-role="copy"
								data-clipboard="<?php echo esc_attr( implode( "\n", $group['sql'] ) ); ?>"
							>
								<?php echo UI::get_icon( 'copy', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
								<span><?php esc_html_e( 'Copy this group', 'wp-vip-compatibility' ); ?></span>
							</button>

							<pre class="wvc-sql__code"><code><?php echo esc_html( implode( "\n", $group['sql'] ) ); ?></code></pre>
						</div>
					</details>
				<?php endif; ?>
			</li>
			<?php
			unset( $kind );
		}

		echo '</ul>';

		if ( ! empty( $all ) ) {
			?>
			<div class="wvc-issues__footer">
				<button
					type="button"
					class="wvc-btn wvc-btn--primary wvc-btn--sm"
					data-role="copy"
					data-clipboard="<?php echo esc_attr( implode( "\n", $all ) ); ?>"
				>
					<?php echo UI::get_icon( 'copy', array( 'class' => 'wvc-icon wvc-icon--sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
					<span>
						<?php
						printf(
							/* translators: %s: Number of statements. */
							esc_html( _n( 'Copy all %s statements', 'Copy all %s statements', count( $all ), 'wp-vip-compatibility' ) ),
							esc_html( number_format_i18n( count( $all ) ) )
						);
						?>
					</span>
				</button>
				<p class="wvc-issues__hint"><?php esc_html_e( 'Statements are ordered engine first, then collation, then prefix.', 'wp-vip-compatibility' ); ?></p>
			</div>
			<?php
		}

		UI::render_panel_close();
	}

	/**
	 * Collapses per-table findings into one entry per requirement.
	 *
	 * @param array<int, array<string, mixed>> $tables The examined tables.
	 * @return array<string, array<string, mixed>> Groups keyed by finding kind.
	 */
	private function group_findings( array $tables ) {
		$order = array(
			'engine'    => 'bad',
			'collation' => 'bad',
			'prefix'    => 'warn',
		);

		$groups = array();

		foreach ( $tables as $table ) {
			foreach ( $table['findings'] as $finding ) {
				$kind = $finding['kind'];

				if ( ! isset( $groups[ $kind ] ) ) {
					$groups[ $kind ] = array(
						'label'       => $finding['label'],
						'remediation' => $finding['remediation'],
						'tone'        => $order[ $kind ] ?? 'warn',
						'tables'      => array(),
						'sql'         => array(),
					);
				}

				$groups[ $kind ]['tables'][] = $table['name'];

				if ( '' !== $finding['sql'] ) {
					$groups[ $kind ]['sql'][] = $finding['sql'];
				}
			}
		}

		// Engine, then collation, then prefix — the order the statements run in.
		$sorted = array();

		foreach ( array_keys( $order ) as $kind ) {
			if ( isset( $groups[ $kind ] ) ) {
				$sorted[ $kind ] = $groups[ $kind ];
			}
		}

		return $sorted;
	}

	/**
	 * Renders the shape of the schema: which engines and collations are in use.
	 *
	 * @param array<string, mixed> $audit The audit result.
	 * @return void
	 */
	private function render_shape( array $audit ) {
		$engines    = array();
		$collations = array();
		$largest    = array();

		foreach ( $audit['tables'] as $table ) {
			$engine    = '' === $table['engine'] ? __( 'Unknown', 'wp-vip-compatibility' ) : $table['engine'];
			$collation = '' === $table['collation'] ? __( 'Unknown', 'wp-vip-compatibility' ) : $table['collation'];

			$engines[ $engine ]       = ( $engines[ $engine ] ?? 0 ) + 1;
			$collations[ $collation ] = ( $collations[ $collation ] ?? 0 ) + 1;
			$largest[]                = $table;
		}

		arsort( $engines );
		arsort( $collations );

		usort(
			$largest,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		UI::render_panel_open(
			array(
				'title'   => __( 'Schema at a glance', 'wp-vip-compatibility' ),
				'summary' => __( 'What the schema is made of today, and where its weight sits.', 'wp-vip-compatibility' ),
			)
		);
		?>
		<div class="wvc-columns">
			<div class="wvc-columns__col">
				<h5 class="wvc-detail__title"><?php esc_html_e( 'Storage engines', 'wp-vip-compatibility' ); ?></h5>
				<div class="wvc-chips">
					<?php foreach ( $engines as $engine => $count ) : ?>
						<?php echo UI::get_meta_chip( $engine, number_format_i18n( $count ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="wvc-columns__col">
				<h5 class="wvc-detail__title"><?php esc_html_e( 'Collations', 'wp-vip-compatibility' ); ?></h5>
				<div class="wvc-chips">
					<?php foreach ( $collations as $collation => $count ) : ?>
						<?php echo UI::get_meta_chip( $collation, number_format_i18n( $count ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="wvc-columns__col">
				<h5 class="wvc-detail__title"><?php esc_html_e( 'Largest tables', 'wp-vip-compatibility' ); ?></h5>
				<ul class="wvc-ranklist">
					<?php foreach ( array_slice( $largest, 0, 5 ) as $table ) : ?>
						<li>
							<code><?php echo esc_html( $table['name'] ); ?></code>
							<span><?php echo esc_html( size_format( (int) $table['bytes'], 1 ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
		UI::render_panel_close();
	}

	/**
	 * Renders the full table listing.
	 *
	 * @param array<string, mixed> $audit The audit result.
	 * @return void
	 */
	private function render_tables( array $audit ) {
		UI::render_panel_open(
			array(
				'title' => __( 'Every table', 'wp-vip-compatibility' ),
				'count' => number_format_i18n( (int) $audit['summary']['total'] ),
				'flush' => true,
			)
		);

		UI::render_toolbar(
			array(
				'search_label' => __( 'Search database tables by name or owner', 'wp-vip-compatibility' ),
				'placeholder'  => __( 'Search tables…', 'wp-vip-compatibility' ),
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
		echo '<table class="wvc-table">';

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
					'label'    => __( 'VIP verdict', 'wp-vip-compatibility' ),
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

		foreach ( $audit['tables'] as $table ) {
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

		echo UI::get_status_cell( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			$state,
			$compatible ? __( 'Ready', 'wp-vip-compatibility' ) : __( 'Needs changes', 'wp-vip-compatibility' )
		);

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
