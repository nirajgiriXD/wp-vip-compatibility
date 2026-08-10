<?php
/**
 * Submenu page for displaying the directories settings.
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
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$audit = Directory_Audit::run();

		if ( empty( $audit['entries'] ) ) {
			UI::render_empty_state(
				__( 'No files or directories found in wp-content.', 'wp-vip-compatibility' ),
				__( 'There is nothing to check on this screen yet.', 'wp-vip-compatibility' )
			);
			return;
		}

		$this->render_summary( $audit['summary'] );

		UI::render_toolbar( array( 'search_label' => __( 'Search files and folders', 'wp-vip-compatibility' ) ) );

		echo '<div class="wvc-table-wrap">';
		echo '<table class="wvc-table" data-target-entity="directories">';

		UI::render_table_head(
			array(
				array(
					'label' => __( 'SN', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-sn',
				),
				array(
					'label'    => __( 'Files and Folders', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Description', 'wp-vip-compatibility' ),
					'sortable' => true,
				),
				array(
					'label'    => __( 'WP VIP Compatibility', 'wp-vip-compatibility' ),
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

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			esc_html__( 'Not everything here has to be removed. "Not deployed" marks the directories WordPress or the local environment maintains — uploads/ above all, which is the one path under wp-content that application code may write to on VIP. It is imported with the VIP CLI rather than committed. Only the entries marked "Remove or relocate" actually conflict with the platform.', 'wp-vip-compatibility' ),
			'info',
			esc_html__( 'How to read this list', 'wp-vip-compatibility' )
		);
	}

	/**
	 * Renders the headline counts.
	 *
	 * @param array<string, int> $summary The audit summary.
	 * @return void
	 */
	private function render_summary( array $summary ) {
		?>
		<ul class="wvc-stats wvc-stats--inline">
			<li class="is-ok">
				<span class="wvc-stats__value"><?php echo esc_html( number_format_i18n( $summary['supported'] ) ); ?></span>
				<span class="wvc-stats__label"><?php esc_html_e( 'Part of the VIP structure', 'wp-vip-compatibility' ); ?></span>
			</li>
			<li class="is-bad">
				<span class="wvc-stats__value"><?php echo esc_html( number_format_i18n( $summary['unsupported'] ) ); ?></span>
				<span class="wvc-stats__label"><?php esc_html_e( 'Remove or relocate', 'wp-vip-compatibility' ); ?></span>
			</li>
			<li>
				<span class="wvc-stats__value"><?php echo esc_html( number_format_i18n( $summary['review'] ) ); ?></span>
				<span class="wvc-stats__label"><?php esc_html_e( 'Needs review', 'wp-vip-compatibility' ); ?></span>
			</li>
			<li>
				<span class="wvc-stats__value"><?php echo esc_html( number_format_i18n( $summary['informational'] ) ); ?></span>
				<span class="wvc-stats__label"><?php esc_html_e( 'Not deployed', 'wp-vip-compatibility' ); ?></span>
			</li>
		</ul>
		<?php
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
			Directory_Audit::STATUS_INFORMATIONAL => 'review',
			Directory_Audit::STATUS_REVIEW        => 'review',
		);

		echo '<tr>';
		echo '<td class="wvc-col-sn"></td>';
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Files and Folders', 'wp-vip-compatibility' ) . '"><code>' . esc_html( $entry['name'] ) . ( $entry['is_dir'] ? '/' : '' ) . '</code></td>';
		echo '<td class="wvc-col-muted" data-label="' . esc_attr__( 'Description', 'wp-vip-compatibility' ) . '">' . esc_html( $entry['description'] ) . '</td>';

		$status_cell = UI::get_status_cell(
			$states[ $entry['status'] ] ?? 'review',
			Directory_Audit::status_label( $entry['status'] )
		);

		echo $status_cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_status_cell().

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
