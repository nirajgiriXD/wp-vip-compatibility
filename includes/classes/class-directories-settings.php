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
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Includes\Classes;

use PREFLIGHT_AUDIT\Includes\Traits\Singleton;
use PREFLIGHT_AUDIT\Includes\Scanner\Directory_Audit;

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
				__( 'No files or directories found in wp-content', 'preflight-audit' ),
				__( 'There is nothing to check here yet.', 'preflight-audit' )
			);

			return;
		}

		$this->render_table( $audit );
	}

	/**
	 * Returns the notes shown in the WordPress Help tab.
	 *
	 * @return array<int, string> Note paragraphs, already containing limited HTML.
	 */
	public function get_page_guidance() {
		return array(
			__( '<strong>"Not deployed" is not a problem.</strong> It marks what WordPress or the local environment maintains — <code>uploads/</code> above all, which is the one path under wp-content that application code may write to on WordPress VIP. It is imported with the WordPress VIP CLI rather than committed.', 'preflight-audit' ),
			__( '<strong>"Remove or relocate" is.</strong> Those entries either conflict with the WordPress VIP application structure or duplicate a drop-in the platform installs itself, where a shipped copy is at best ignored and at worst fights the platform.', 'preflight-audit' ),
			__( '<strong>"Needs review" means unrecognised.</strong> Work out what created it: if the codebase reads from it, move the contents into <code>uploads/</code> and update the stored paths; if nothing uses it, leave it out of the repository.', 'preflight-audit' ),
		);
	}

	/**
	 * Renders the entry table.
	 *
	 * @param array<string, mixed> $audit The audit result.
	 * @return void
	 */
	private function render_table( array $audit ) {
		$entries = $audit['entries'];

		// Entries that need action lead: remove or relocate, then needs review,
		// then everything that is fine as it is. Ties keep the audit's order,
		// because PHP sorts are only stable from 8.0.
		$rank  = array(
			Directory_Audit::STATUS_UNSUPPORTED => 0,
			Directory_Audit::STATUS_REVIEW      => 1,
		);
		$order = array_flip( array_keys( $entries ) );
		uksort(
			$entries,
			static function ( $a, $b ) use ( $entries, $rank, $order ) {
				$diff = ( $rank[ $entries[ $a ]['status'] ] ?? 2 ) <=> ( $rank[ $entries[ $b ]['status'] ] ?? 2 );

				return 0 !== $diff ? $diff : $order[ $a ] <=> $order[ $b ];
			}
		);

		// No heading: the page title already names the list.
		UI::render_panel_open( array( 'flush' => true ) );

		UI::render_toolbar(
			array(
				'search_label' => __( 'Search files and folders', 'preflight-audit' ),
				'placeholder'  => __( 'Search paths…', 'preflight-audit' ),
				'groups'       => array(
					array(
						'name'    => 'status',
						'label'   => __( 'Filter by verdict', 'preflight-audit' ),
						'active'  => $this->read_status(),
						'options' => array(
							array(
								'value' => 'all',
								'label' => __( 'Everything', 'preflight-audit' ),
							),
							array(
								'value' => 'not-compatible',
								'label' => __( 'Remove or relocate', 'preflight-audit' ),
								'dot'   => 'bad',
							),
							array(
								'value' => 'review',
								'label' => __( 'Needs review', 'preflight-audit' ),
								'dot'   => 'warn',
							),
							array(
								'value' => 'compatible',
								'label' => __( 'No action needed', 'preflight-audit' ),
								'dot'   => 'ok',
							),
						),
					),
				),
			)
		);

		echo '<div class="pfa-table-wrap" data-role="table-view">';
		echo '<table class="pfa-table" aria-label="' . esc_attr__( 'Everything in wp-content', 'preflight-audit' ) . '">';

		UI::render_table_head(
			array(
				array(
					'label'    => __( 'Path', 'preflight-audit' ),
					'class'    => 'pfa-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'WordPress VIP verdict', 'preflight-audit' ),
					'class'    => 'pfa-col-status',
					'sortable' => true,
				),
				array(
					'label' => __( 'What to do', 'preflight-audit' ),
					'class' => 'pfa-col-notes',
				),
			)
		);

		echo '<tbody>';

		foreach ( $entries as $entry ) {
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

		printf( '<tr class="pfa-row" data-status="%s">', esc_attr( $state ) );

		// The description was a column of its own, repeating "Directory that is
		// not part of the WordPress VIP application structure" down the page. It belongs
		// with the path it describes.
		echo '<td class="pfa-col-name" data-label="' . esc_attr__( 'Path', 'preflight-audit' ) . '">';
		echo '<span class="pfa-item">';
		echo '<code class="pfa-item__name">' . esc_html( $entry['name'] ) . ( $entry['is_dir'] ? '/' : '' ) . '</code>';

		if ( '' !== $entry['description'] ) {
			echo '<span class="pfa-item__meta">' . esc_html( $entry['description'] ) . '</span>';
		}

		echo '</span>';
		echo '</td>';

		echo UI::get_status_cell( $state, Directory_Audit::status_label( $entry['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		echo '<td class="pfa-col-notes" data-label="' . esc_attr__( 'What to do', 'preflight-audit' ) . '">';

		if ( '' === $entry['guidance'] ) {
			echo '<span class="pfa-dash" aria-hidden="true">—</span>';
		} else {
			echo esc_html( $entry['guidance'] );

			if ( '' !== $entry['doc'] ) {
				printf(
					' <a class="pfa-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $entry['doc'] ),
					esc_html__( 'Reference', 'preflight-audit' )
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
