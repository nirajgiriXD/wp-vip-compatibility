<?php
/**
 * Submenu page for displaying the plugins settings.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Known_Plugins;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Scanner;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Lists installed plugins with their VIP verdict.
 */
class Plugins_Settings {

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
		$targets = Targets::of_type( 'plugin' );

		if ( empty( $targets ) ) {
			UI::render_empty_state(
				__( 'No plugins installed', 'wp-vip-compatibility' ),
				__( 'There is nothing to check on this screen yet.', 'wp-vip-compatibility' )
			);
			return;
		}

		$updates = get_site_transient( 'update_plugins' );
		$index   = Results_Store::get_index();

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			wp_kses_post(
				sprintf(
					/* translators: %s: Link to the findings screen. */
					__( 'A verdict of "Blocked" means at least one finding is expected to fail on VIP; "Needs review" means there is work to do that will not by itself stop a migration. %s for the detail behind every verdict.', 'wp-vip-compatibility' ),
					'<a href="' . esc_url( UI::get_findings_url() ) . '">' . esc_html__( 'Open the findings report', 'wp-vip-compatibility' ) . '</a>'
				)
			),
			'info',
			esc_html__( 'How to read this column', 'wp-vip-compatibility' )
		);

		UI::render_toolbar(
			array(
				'search_label'  => __( 'Search plugins', 'wp-vip-compatibility' ),
				'show_progress' => true,
			)
		);

		echo '<div class="wvc-table-wrap">';
		echo '<table class="wvc-table" data-target-entity="plugins">';
		$this->render_table_header();
		echo '<tbody>';

		foreach ( $targets as $target ) {
			$this->render_row( $target, $updates, $index );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders the table header.
	 *
	 * @return void
	 */
	private function render_table_header() {
		UI::render_table_head(
			array(
				array(
					'label' => __( 'SN', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-sn',
				),
				array(
					'label'    => __( 'Plugin Name', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Plugin Directory', 'wp-vip-compatibility' ),
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
	}

	/**
	 * Renders one plugin row.
	 *
	 * @param array<string, mixed>                $target  The plugin target.
	 * @param object|false                        $updates The update_plugins transient.
	 * @param array<string, array<string, mixed>> $index The stored result index.
	 * @return void
	 */
	private function render_row( array $target, $updates, array $index ) {
		$known       = Known_Plugins::classify( $target['slug'] );
		$has_update  = isset( $updates->response[ $target['file'] ] );
		$new_version = $has_update
			? $updates->response[ $target['file'] ]->new_version
			: __( 'Up to date', 'wp-vip-compatibility' );

		echo '<tr>';
		echo '<td class="wvc-col-sn"></td>';
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Plugin Name', 'wp-vip-compatibility' ) . '">' . esc_html( $target['label'] ) . '</td>';
		echo '<td class="wvc-col-path" data-label="' . esc_attr__( 'Plugin Directory', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $target['file'] ) . '</code></td>';
		echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Author', 'wp-vip-compatibility' ) . '">' . esc_html( $target['author'] ) . '</td>';
		echo '<td data-label="' . esc_attr__( 'Current Version', 'wp-vip-compatibility' ) . '"><span class="wvc-version">' . esc_html( $target['version'] ) . '</span></td>';
		echo '<td data-label="' . esc_attr__( 'Available Version', 'wp-vip-compatibility' ) . '"><span class="wvc-version' . ( $has_update ? '' : ' wvc-version--current' ) . '">' . esc_html( $new_version ) . '</span></td>';

		echo $this->status_cell( $target, $known, $index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'Notes', 'wp-vip-compatibility' ) . '">' . wp_kses_post( $this->note( $target, $known, $index ) ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Builds the compatibility cell.
	 *
	 * A plugin VIP explicitly lists as incompatible is settled without a scan.
	 * Everything else resolves from the stored result, or is queued for an
	 * asynchronous scan when nothing is stored yet.
	 *
	 * @param array<string, mixed>                $target The plugin target.
	 * @param array<string, mixed>|null           $known  The curated classification.
	 * @param array<string, array<string, mixed>> $index  The stored result index.
	 * @return string The cell markup.
	 */
	private function status_cell( array $target, $known, array $index ) {
		if ( is_array( $known ) && Known_Plugins::INCOMPATIBLE === $known['classification'] ) {
			return UI::get_status_cell( 'not-compatible', __( 'Incompatible', 'wp-vip-compatibility' ) );
		}

		$status = $index[ $target['key'] ]['status'] ?? null;

		if ( null === $status ) {
			return UI::get_status_cell( 'pending', '', array( 'data-target' => $target['key'] ) );
		}

		$state = ( Scanner::STATUS_PASS === $status )
			? 'compatible'
			: ( ( Scanner::STATUS_REVIEW === $status ) ? 'review' : 'not-compatible' );

		$total = (int) ( $index[ $target['key'] ]['summary']['total'] ?? 0 );
		$label = Scanner::status_label( $status );

		if ( $total > 0 ) {
			/* translators: 1: Status label. 2: Number of findings. */
			$label = sprintf( __( '%1$s (%2$d)', 'wp-vip-compatibility' ), $label, $total );
		}

		return UI::get_status_cell( $state, $label, array( 'data-target' => $target['key'] ) );
	}

	/**
	 * Builds the note cell contents.
	 *
	 * @param array<string, mixed>                $target The plugin target.
	 * @param array<string, mixed>|null           $known  The curated classification.
	 * @param array<string, array<string, mixed>> $index  The stored result index.
	 * @return string The note markup.
	 */
	private function note( array $target, $known, array $index ) {
		$parts = array();

		if ( is_array( $known ) ) {
			$label = '<strong>' . esc_html( $known['label'] ) . '</strong>';

			if ( '' !== $known['doc'] ) {
				$label = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $known['doc'] ),
					$label
				);
			}

			$parts[] = $label . ' ' . esc_html( $known['reason'] );
		}

		$total = (int) ( $index[ $target['key'] ]['summary']['total'] ?? 0 );

		if ( $total > 0 ) {
			$parts[] = sprintf(
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
		}

		if ( empty( $parts ) ) {
			return '<span class="wvc-dash" aria-hidden="true">—</span>';
		}

		return '<ul class="wvc-notes"><li>' . implode( '</li><li>', $parts ) . '</li></ul>';
	}
}
