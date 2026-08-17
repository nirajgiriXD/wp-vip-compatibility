<?php
/**
 * The wp-content layout screen.
 *
 * WordPress VIP's application structure is narrower than a standard WordPress install:
 * plugins, themes and client-mu-plugins are committed, uploads is imported
 * separately, and nothing else has a home. The point of this screen is to make
 * clear which of those three an entry falls into — because "not part of the WordPress VIP
 * structure" and "incompatible with WordPress VIP" are different things, and treating them
 * the same is what made this audit misleading before.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Directory_Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the wp-content audit.
 */
class Directories_Settings {

	use Singleton;

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$audit = Directory_Audit::run();

		if ( empty( $audit['entries'] ) ) {
			UI::render_empty_state(
				__( 'No files or directories found in wp-content', 'wp-vip-compatibility' ),
				__( 'There is nothing to check here yet.', 'wp-vip-compatibility' )
			);

			return;
		}

		$this->render_stats( $audit );
		$this->render_guidance();
		$this->render_table( $audit );
	}

	/**
	 * Renders the headline statistics.
	 *
	 * @param array<string, mixed> $audit The audit result.
	 * @return void
	 */
	private function render_stats( array $audit ) {
		$summary = $audit['summary'];

		UI::render_stats(
			array(
				array(
					'label' => __( 'Remove or relocate', 'wp-vip-compatibility' ),
					'value' => number_format_i18n( (int) $summary['unsupported'] ),
					'meta'  => __( 'Conflicts with the WordPress VIP structure', 'wp-vip-compatibility' ),
					'tone'  => $summary['unsupported'] > 0 ? 'bad' : 'ok',
					'url'   => $summary['unsupported'] > 0
						? UI::get_screen_url( 'directories', array( 'status' => 'not-compatible' ) )
						: '',
				),
				array(
					'label' => __( 'Needs review', 'wp-vip-compatibility' ),
					'value' => number_format_i18n( (int) $summary['review'] ),
					'meta'  => __( 'Unrecognised — work out what created it', 'wp-vip-compatibility' ),
					'tone'  => $summary['review'] > 0 ? 'warn' : 'neutral',
					'url'   => $summary['review'] > 0
						? UI::get_screen_url( 'directories', array( 'status' => 'review' ) )
						: '',
				),
				array(
					'label' => __( 'Part of the structure', 'wp-vip-compatibility' ),
					'value' => number_format_i18n( (int) $summary['supported'] ),
					'meta'  => __( 'Committed to the WordPress VIP repository', 'wp-vip-compatibility' ),
					'tone'  => 'ok',
				),
				array(
					'label' => __( 'Not deployed', 'wp-vip-compatibility' ),
					'value' => number_format_i18n( (int) $summary['informational'] ),
					'meta'  => __( 'Imported or generated, not committed', 'wp-vip-compatibility' ),
					'tone'  => 'neutral',
				),
			)
		);
	}

	/**
	 * Renders the reference notes for this screen.
	 *
	 * @return void
	 */
	private function render_guidance() {
		$notes = array(
			__( '<strong>"Not deployed" is not a problem.</strong> It marks what WordPress or the local environment maintains — <code>uploads/</code> above all, which is the one path under wp-content that application code may write to on WordPress VIP. It is imported with the WordPress VIP CLI rather than committed.', 'wp-vip-compatibility' ),
			__( '<strong>"Remove or relocate" is.</strong> Those entries either conflict with the WordPress VIP application structure or duplicate a drop-in the platform installs itself, where a shipped copy is at best ignored and at worst fights the platform.', 'wp-vip-compatibility' ),
			__( '<strong>"Needs review" means unrecognised.</strong> Work out what created it: if the codebase reads from it, move the contents into <code>uploads/</code> and update the stored paths; if nothing uses it, leave it out of the repository.', 'wp-vip-compatibility' ),
		);

		echo UI::get_guidance( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			'<p>' . implode( '</p><p>', $notes ) . '</p>',
			__( 'How to read this list', 'wp-vip-compatibility' )
		);
	}

	/**
	 * Renders the entry table.
	 *
	 * @param array<string, mixed> $audit The audit result.
	 * @return void
	 */
	private function render_table( array $audit ) {
		UI::render_panel_open(
			array(
				'title' => __( 'Everything in wp-content', 'wp-vip-compatibility' ),
				'count' => number_format_i18n( (int) $audit['summary']['total'] ),
				'flush' => true,
			)
		);

		UI::render_toolbar(
			array(
				'search_label' => __( 'Search files and folders', 'wp-vip-compatibility' ),
				'placeholder'  => __( 'Search paths…', 'wp-vip-compatibility' ),
				'groups'       => array(
					array(
						'name'    => 'status',
						'label'   => __( 'Filter by verdict', 'wp-vip-compatibility' ),
						'active'  => $this->read_status(),
						'options' => array(
							array(
								'value' => 'all',
								'label' => __( 'Everything', 'wp-vip-compatibility' ),
							),
							array(
								'value' => 'not-compatible',
								'label' => __( 'Remove or relocate', 'wp-vip-compatibility' ),
								'dot'   => 'bad',
							),
							array(
								'value' => 'review',
								'label' => __( 'Needs review', 'wp-vip-compatibility' ),
								'dot'   => 'warn',
							),
							array(
								'value' => 'compatible',
								'label' => __( 'No action needed', 'wp-vip-compatibility' ),
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
					'label'    => __( 'Path', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
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

		foreach ( $audit['entries'] as $entry ) {
			$this->render_row( $entry );
		}

		echo '</tbody></table>';
		echo '</div>';

		UI::render_panel_close();
	}

	/**
	 * Renders one wp-content entry.
	 *
	 * @param array<string, mixed> $entry The classified entry.
	 * @return void
	 */
	private function render_row( array $entry ) {
		$states = array(
			Directory_Audit::STATUS_SUPPORTED     => 'compatible',
			Directory_Audit::STATUS_UNSUPPORTED   => 'not-compatible',
			Directory_Audit::STATUS_INFORMATIONAL => 'compatible',
			Directory_Audit::STATUS_REVIEW        => 'review',
		);

		$state = $states[ $entry['status'] ] ?? 'review';

		printf( '<tr class="wvc-row" data-status="%s">', esc_attr( $state ) );

		// The description was a column of its own, repeating "Directory that is
		// not part of the WordPress VIP application structure" down the page. It belongs
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

	/**
	 * Reads the verdict filter preselected through the query string.
	 *
	 * @return string The verdict state, or `all`.
	 */
	private function read_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection of a client-side filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';

		return in_array( $status, array( 'not-compatible', 'review', 'compatible' ), true ) ? $status : 'all';
	}
}
