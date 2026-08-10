<?php
/**
 * Submenu page for displaying the themes settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Scanner;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Lists installed themes with their VIP verdict.
 */
class Themes_Settings {

	use Singleton;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$targets = Targets::of_type( 'theme' );

		if ( empty( $targets ) ) {
			UI::render_empty_state(
				__( 'No themes installed', 'wp-vip-compatibility' ),
				__( 'There is nothing to check on this screen yet.', 'wp-vip-compatibility' )
			);
			return;
		}

		$updates = get_site_transient( 'update_themes' );
		$index   = Results_Store::get_index();
		$active  = get_stylesheet();

		UI::render_toolbar(
			array(
				'search_label'  => __( 'Search themes', 'wp-vip-compatibility' ),
				'show_progress' => true,
			)
		);

		echo '<div class="wvc-table-wrap">';
		echo '<table class="wvc-table" data-target-entity="themes">';

		UI::render_table_head(
			array(
				array(
					'label' => __( 'SN', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-sn',
				),
				array(
					'label'    => __( 'Theme Name', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Theme Directory', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-path',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Author', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array( 'label' => __( 'Current Version', 'wp-vip-compatibility' ) ),
				array( 'label' => __( 'Available Version', 'wp-vip-compatibility' ) ),
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

		foreach ( $targets as $target ) {
			$this->render_row( $target, $updates, $index, $active );
		}

		echo '</tbody></table>';
		echo '</div>';

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html__( 'Inactive themes still ship in the repository and are still scanned by the VIP Code Analysis Bot. Removing the ones you do not use is the quickest way to shrink this list.', 'wp-vip-compatibility' ),
			'info',
			esc_html__( 'Before you migrate', 'wp-vip-compatibility' )
		);
	}

	/**
	 * Renders one theme row.
	 *
	 * @param array<string, mixed>                $target  The theme target.
	 * @param object|false                        $updates The update_themes transient.
	 * @param array<string, array<string, mixed>> $index   The stored result index.
	 * @param string                              $active  The active stylesheet.
	 * @return void
	 */
	private function render_row( array $target, $updates, array $index, $active ) {
		$has_update  = isset( $updates->response[ $target['slug'] ] );
		$new_version = $has_update
			? $updates->response[ $target['slug'] ]['new_version']
			: __( 'Up to date', 'wp-vip-compatibility' );

		echo '<tr>';
		echo '<td class="wvc-col-sn"></td>';
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Theme Name', 'wp-vip-compatibility' ) . '">' . esc_html( $target['label'] );

		if ( $target['slug'] === $active ) {
			echo ' <span class="wvc-badge wvc-badge--neutral">' . esc_html__( 'Active', 'wp-vip-compatibility' ) . '</span>';
		}

		echo '</td>';
		echo '<td class="wvc-col-path" data-label="' . esc_attr__( 'Theme Directory', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $target['slug'] ) . '</code></td>';
		echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Author', 'wp-vip-compatibility' ) . '">' . esc_html( $target['author'] ) . '</td>';
		echo '<td data-label="' . esc_attr__( 'Current Version', 'wp-vip-compatibility' ) . '"><span class="wvc-version">' . esc_html( $target['version'] ) . '</span></td>';
		echo '<td data-label="' . esc_attr__( 'Available Version', 'wp-vip-compatibility' ) . '"><span class="wvc-version' . ( $has_update ? '' : ' wvc-version--current' ) . '">' . esc_html( $new_version ) . '</span></td>';

		$status = $index[ $target['key'] ]['status'] ?? null;
		$total  = (int) ( $index[ $target['key'] ]['summary']['total'] ?? 0 );

		if ( null === $status ) {
			echo UI::get_status_cell( 'pending', '', array( 'data-target' => $target['key'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		} else {
			$state = ( Scanner::STATUS_PASS === $status )
				? 'compatible'
				: ( ( Scanner::STATUS_REVIEW === $status ) ? 'review' : 'not-compatible' );

			$label = Scanner::status_label( $status );

			if ( $total > 0 ) {
				/* translators: 1: Status label. 2: Number of findings. */
				$label = sprintf( __( '%1$s (%2$d)', 'wp-vip-compatibility' ), $label, $total );
			}

			echo UI::get_status_cell( $state, $label, array( 'data-target' => $target['key'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		}

		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'Notes', 'wp-vip-compatibility' ) . '">';

		if ( $total > 0 ) {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( UI::get_findings_url( $target['key'] ) ),
				esc_html(
					sprintf(
						/* translators: %d: Number of findings. */
						_n( 'Review %d finding', 'Review %d findings', $total, 'wp-vip-compatibility' ),
						$total
					)
				)
			);
		} else {
			echo '<span class="wvc-dash" aria-hidden="true">—</span>';
		}

		echo '</td>';
		echo '</tr>';
	}
}
