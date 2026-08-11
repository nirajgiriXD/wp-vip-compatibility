<?php
/**
 * The site screen.
 *
 * The database schema and the wp-content layout are different data but the same
 * job: infrastructure that has to be changed before or during the migration,
 * audited rather than scanned, and each finding fixed by a statement to run or a
 * path to move. They were two screens; they are two sections of one, with a
 * switch between them, so the top-level navigation reflects workflows rather
 * than tables.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Database_Audit;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Directory_Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the database and wp-content audits.
 */
class Site_Settings {

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
		$section = $this->read_section();

		$this->render_switcher( $section );

		if ( 'directories' === $section ) {
			$this->render_directories();
			return;
		}

		$this->render_database();
	}

	/**
	 * Reads the requested section from the query string.
	 *
	 * @return string Either `database` or `directories`.
	 */
	private function read_section() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only section selection.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'database';

		return ( 'directories' === $section ) ? 'directories' : 'database';
	}

	/**
	 * Reads the requested verdict filter from the query string.
	 *
	 * @return string The verdict state, or `all`.
	 */
	private function read_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection of a client-side filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';

		return in_array( $status, array( 'not-compatible', 'review', 'compatible' ), true ) ? $status : 'all';
	}

	/**
	 * Renders the section switcher.
	 *
	 * Two genuinely different subjects, so this is a real category switch rather
	 * than a way of spreading one table across two tabs.
	 *
	 * @param string $current The active section.
	 * @return void
	 */
	private function render_switcher( $current ) {
		$sections = array(
			'database'    => __( 'Database tables', 'wp-vip-compatibility' ),
			'directories' => __( 'wp-content layout', 'wp-vip-compatibility' ),
		);
		?>
		<div class="wvc-segmented wvc-segmented--links wvc-segmented--sections" role="group" aria-label="<?php esc_attr_e( 'Choose an audit', 'wp-vip-compatibility' ); ?>">
			<?php foreach ( $sections as $key => $label ) : ?>
				<a
					class="<?php echo ( $key === $current ) ? 'active' : ''; ?>"
					href="<?php echo esc_url( UI::get_screen_url( 'site', array( 'section' => $key ) ) ); ?>"
					<?php echo ( $key === $current ) ? 'aria-current="true"' : ''; ?>
				>
					<span><?php echo esc_html( $label ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Builds the shared verdict filter group.
	 *
	 * @param string $ready_label   Label for the compliant option.
	 * @param string $blocked_label Label for the non-compliant option.
	 * @param bool   $with_review   Whether the data can produce a review verdict.
	 * @return array<int, array<string, mixed>> A single filter group.
	 */
	private function status_group( $ready_label, $blocked_label, $with_review ) {
		$options = array(
			array(
				'value' => 'all',
				'label' => __( 'All', 'wp-vip-compatibility' ),
			),
			array(
				'value' => 'not-compatible',
				'label' => $blocked_label,
				'dot'   => 'bad',
			),
		);

		if ( $with_review ) {
			$options[] = array(
				'value' => 'review',
				'label' => __( 'Needs review', 'wp-vip-compatibility' ),
				'dot'   => 'warn',
			);
		}

		$options[] = array(
			'value' => 'compatible',
			'label' => $ready_label,
			'dot'   => 'ok',
		);

		return array(
			array(
				'name'    => 'status',
				'label'   => __( 'Filter by verdict', 'wp-vip-compatibility' ),
				'active'  => $this->read_status(),
				'options' => $options,
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the database audit.
	 *
	 * @return void
	 */
	private function render_database() {
		$audit = Database_Audit::run();

		if ( empty( $audit['tables'] ) ) {
			UI::render_empty_state(
				__( 'No tables found in the database', 'wp-vip-compatibility' ),
				__( 'There is nothing to check here yet.', 'wp-vip-compatibility' )
			);

			return;
		}

		$summary = $audit['summary'];
		$issues  = (int) $summary['issues']['engine'] + (int) $summary['issues']['collation'] + (int) $summary['issues']['prefix'];

		// The warning only appears when there is SQL to run. It used to be shown
		// on every visit, including on a schema with nothing to change.
		if ( $issues > 0 ) {
			echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				esc_html__( 'Run these statements against a backup first, and convert collations before the final export rather than after the import. Table prefixes are the exception: report a non-standard prefix to VIP and only rename tables if VIP confirms it, because the prefix is also embedded in option names and user meta keys that control roles and capabilities.', 'wp-vip-compatibility' ),
				'warning',
				esc_html__( 'Before you run any SQL', 'wp-vip-compatibility' )
			);
		}

		UI::render_toolbar(
			array(
				'search_label' => __( 'Search database tables', 'wp-vip-compatibility' ),
				'groups'       => $this->status_group(
					__( 'Ready', 'wp-vip-compatibility' ),
					__( 'Needs changes', 'wp-vip-compatibility' ),
					false
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
					'sortable' => true,
				),
				array(
					'label'    => __( 'Size', 'wp-vip-compatibility' ),
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
			$this->render_table_row( $table );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders one database table row.
	 *
	 * @param array<string, mixed> $table The examined table.
	 * @return void
	 */
	private function render_table_row( array $table ) {
		$compatible = empty( $table['findings'] );
		$state      = $compatible ? 'compatible' : 'not-compatible';

		printf( '<tr data-status="%s">', esc_attr( $state ) );

		// The source used to be a column. It only ever matters alongside a
		// verdict, so it rides with the name instead.
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Table', 'wp-vip-compatibility' ) . '">';
		echo '<span class="wvc-item">';
		echo '<code class="wvc-item__name">' . esc_html( $table['name'] ) . '</code>';
		echo '<span class="wvc-item__meta">' . esc_html( $table['source'] ) . '</span>';
		echo '</span>';
		echo '</td>';

		echo '<td data-label="' . esc_attr__( 'Engine', 'wp-vip-compatibility' ) . '">' . esc_html( $table['engine'] ) . '</td>';
		echo '<td class="wvc-col-mono" data-label="' . esc_attr__( 'Collation', 'wp-vip-compatibility' ) . '">' . esc_html( $table['collation'] ) . '</td>';
		echo '<td data-label="' . esc_attr__( 'Size', 'wp-vip-compatibility' ) . '">' . esc_html( size_format( $table['bytes'], 1 ) ) . '</td>';

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

	/* ---------------------------------------------------------------------
	 * wp-content
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the wp-content audit.
	 *
	 * @return void
	 */
	private function render_directories() {
		$audit = Directory_Audit::run();

		if ( empty( $audit['entries'] ) ) {
			UI::render_empty_state(
				__( 'No files or directories found in wp-content', 'wp-vip-compatibility' ),
				__( 'There is nothing to check here yet.', 'wp-vip-compatibility' )
			);

			return;
		}

		echo UI::get_guidance( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html__( 'Not everything here has to be removed. "Not deployed" marks the directories WordPress or the local environment maintains — uploads/ above all, which is the one path under wp-content that application code may write to on VIP. It is imported with the VIP CLI rather than committed. Only the entries marked "Remove or relocate" actually conflict with the platform.', 'wp-vip-compatibility' ),
			esc_html__( 'How to read this list', 'wp-vip-compatibility' )
		);

		UI::render_toolbar(
			array(
				'search_label' => __( 'Search files and folders', 'wp-vip-compatibility' ),
				// "No action needed" rather than "Part of the VIP structure",
				// because this filter also holds the entries whose verdict is
				// "Not deployed" — uploads/ is not part of the repository, and is
				// not a problem either.
				'groups'       => $this->status_group(
					__( 'No action needed', 'wp-vip-compatibility' ),
					__( 'Remove or relocate', 'wp-vip-compatibility' ),
					true
				),
			)
		);

		echo '<div class="wvc-table-wrap" data-role="table-view">';
		echo '<table class="wvc-table">';

		UI::render_table_head(
			array(
				array(
					'label'    => __( 'Path', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
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

		foreach ( $audit['entries'] as $entry ) {
			$this->render_directory_row( $entry );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders one wp-content entry.
	 *
	 * @param array<string, mixed> $entry The classified entry.
	 * @return void
	 */
	private function render_directory_row( array $entry ) {
		$states = array(
			Directory_Audit::STATUS_SUPPORTED     => 'compatible',
			Directory_Audit::STATUS_UNSUPPORTED   => 'not-compatible',
			Directory_Audit::STATUS_INFORMATIONAL => 'compatible',
			Directory_Audit::STATUS_REVIEW        => 'review',
		);

		$state = $states[ $entry['status'] ] ?? 'review';

		printf( '<tr data-status="%s">', esc_attr( $state ) );

		// The description was a column of its own, repeating "Directory that is
		// not part of the VIP application structure" down the page. It belongs
		// with the path it describes.
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Path', 'wp-vip-compatibility' ) . '">';
		echo '<span class="wvc-item">';
		echo '<code class="wvc-item__name">' . esc_html( $entry['name'] ) . ( $entry['is_dir'] ? '/' : '' ) . '</code>';

		if ( '' !== $entry['description'] ) {
			echo '<span class="wvc-item__meta">' . esc_html( $entry['description'] ) . '</span>';
		}

		echo '</span>';
		echo '</td>';

		echo UI::get_status_cell( $state, Directory_Audit::status_label( $entry['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'What to do', 'wp-vip-compatibility' ) . '">';

		if ( '' === $entry['guidance'] ) {
			echo '<span class="wvc-dash" aria-hidden="true">—</span>';
		} else {
			echo esc_html( $entry['guidance'] );

			if ( '' !== $entry['doc'] ) {
				printf(
					' <a class="wvc-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $entry['doc'] ),
					esc_html__( 'Reference', 'wp-vip-compatibility' )
				);
			}
		}

		echo '</td>';
		echo '</tr>';
	}
}
