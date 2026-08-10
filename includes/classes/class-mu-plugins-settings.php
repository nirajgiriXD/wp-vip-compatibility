<?php
/**
 * Submenu page for displaying the MU-Plugins settings.
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
 * Lists must-use plugins with their VIP verdict.
 */
class MU_Plugins_Settings {

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
		$targets = Targets::of_type( 'mu-plugin' );

		if ( empty( $targets ) ) {
			UI::render_empty_state(
				__( 'No must-use plugins are present', 'wp-vip-compatibility' ),
				__( 'Nothing in wp-content/mu-plugins needs to be relocated.', 'wp-vip-compatibility' )
			);
			return;
		}

		$index = Results_Store::get_index();

		UI::render_toolbar(
			array(
				'search_label'  => __( 'Search must-use plugins', 'wp-vip-compatibility' ),
				'show_progress' => true,
			)
		);

		echo '<div class="wvc-table-wrap">';
		echo '<table class="wvc-table" data-target-entity="mu-plugins">';
		$this->render_table_header();
		echo '<tbody>';

		foreach ( $targets as $target ) {
			$this->render_row( $target, $index );
		}

		echo '</tbody></table>';
		echo '</div>';

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html__( 'VIP reserves wp-content/mu-plugins for platform code, so everything here has to move to client-mu-plugins/ — except the plugins VIP already preinstalls, and anything a previous host added, which should be dropped instead. Loose PHP files and directories are listed separately because WordPress only auto-loads files at the root of mu-plugins, and there is no reliable way to tell which directory belongs to which loader file.', 'wp-vip-compatibility' ),
			'info',
			esc_html__( 'How must-use plugins migrate', 'wp-vip-compatibility' )
		);
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
					'label'    => __( 'MU Plugin Name', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'MU Plugin Directory', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-path',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Author', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array( 'label' => __( 'Version', 'wp-vip-compatibility' ) ),
				array(
					'label'    => __( 'WP VIP Compatibility', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-status',
					'sortable' => true,
				),
				array(
					'label' => __( 'Note', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-notes',
				),
			)
		);
	}

	/**
	 * Renders one must-use plugin row.
	 *
	 * @param array<string, mixed>                $target The must-use plugin target.
	 * @param array<string, array<string, mixed>> $index  The stored result index.
	 * @return void
	 */
	private function render_row( array $target, array $index ) {
		$known = Known_Plugins::mu_plugin( $target['slug'] );
		$dash  = '<span class="wvc-dash" aria-hidden="true">—</span>';

		echo '<tr>';
		echo '<td class="wvc-col-sn"></td>';
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'MU Plugin Name', 'wp-vip-compatibility' ) . '">' . esc_html( $target['label'] ) . '</td>';
		echo '<td class="wvc-col-path" data-label="' . esc_attr__( 'MU Plugin Directory', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $target['slug'] ) . '</code></td>';
		echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Author', 'wp-vip-compatibility' ) . '">' . ( '' === $target['author'] ? $dash : esc_html( $target['author'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup or escaped above.
		echo '<td data-label="' . esc_attr__( 'Version', 'wp-vip-compatibility' ) . '">' . ( '' === $target['version'] ? $dash : '<span class="wvc-version">' . esc_html( $target['version'] ) . '</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup or escaped above.

		echo $this->status_cell( $target, $known, $index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'Note', 'wp-vip-compatibility' ) . '">' . wp_kses_post( $this->note( $target, $known, $index ) ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Builds the compatibility cell.
	 *
	 * @param array<string, mixed>                $target The must-use plugin target.
	 * @param array<string, mixed>|null           $known  The curated record.
	 * @param array<string, array<string, mixed>> $index  The stored result index.
	 * @return string The cell markup.
	 */
	private function status_cell( array $target, $known, array $index ) {
		if ( is_array( $known ) ) {
			// Neither of these is "incompatible code" — both are "do not ship
			// this to VIP", which is a review action rather than a blocker.
			return UI::get_status_cell( 'review', __( 'Do not migrate', 'wp-vip-compatibility' ) );
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
	 * @param array<string, mixed>                $target The must-use plugin target.
	 * @param array<string, mixed>|null           $known  The curated record.
	 * @param array<string, array<string, mixed>> $index  The stored result index.
	 * @return string The note markup.
	 */
	private function note( array $target, $known, array $index ) {
		$parts = array();

		if ( is_array( $known ) ) {
			$note = esc_html( $known['note'] ?? '' );

			if ( ! empty( $known['doc'] ) ) {
				$note = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $known['doc'] ),
					$note
				);
			}

			$parts[] = $note;
		} else {
			$parts[] = esc_html__( 'Move it into client-mu-plugins/ in the VIP repository.', 'wp-vip-compatibility' );
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

		return '<ul class="wvc-notes"><li>' . implode( '</li><li>', $parts ) . '</li></ul>';
	}
}
