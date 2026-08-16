<?php
/**
 * The plugins screen.
 *
 * A plugin is the unit most migration decisions are actually made about — keep
 * it, replace it, or drop it — so it gets a screen where that decision can be
 * made from one row: what it is, whether the site runs it, whether VIP has
 * already ruled on it, how serious the findings are, and the one action to take.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every installed plugin with its VIP verdict.
 */
class Plugins_Settings extends Inventory_Screen {

	use Singleton;

	/**
	 * Returns the target type this screen lists.
	 *
	 * @return string The target type.
	 */
	protected function get_type() {
		return 'plugin';
	}

	/**
	 * Returns the label for the search field.
	 *
	 * @return string The label.
	 */
	protected function get_search_label() {
		return __( 'Search plugins by name, path or author', 'wp-vip-compatibility' );
	}

	/**
	 * Returns the panel title for the table.
	 *
	 * @return string The title.
	 */
	protected function get_table_title() {
		return __( 'Installed plugins', 'wp-vip-compatibility' );
	}

	/**
	 * Returns the empty-state copy.
	 *
	 * @return array<int, string> Title and message.
	 */
	protected function get_empty_state() {
		return array(
			__( 'No plugins are installed', 'wp-vip-compatibility' ),
			__( 'There is nothing here to check against the VIP Platform.', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Returns the reference notes shown above the table.
	 *
	 * @return array<int, string> Note paragraphs.
	 */
	protected function get_guidance_notes() {
		return array(
			sprintf(
				/* translators: %s: Link to the findings report. */
				__( '<strong>Verdicts.</strong> "Blocked" means at least one finding is expected to fail on VIP. "Review" means there is work to do that will not by itself stop a migration. %s for the detail behind every verdict.', 'wp-vip-compatibility' ),
				'<a href="' . esc_url( UI::get_findings_url() ) . '">' . esc_html__( 'Open the findings report', 'wp-vip-compatibility' ) . '</a>'
			),
			__( '<strong>Inactive plugins still count.</strong> They ship in the repository and are still read by the VIP Code Analysis Bot. Removing the ones you do not use is the quickest way to shorten this list.', 'wp-vip-compatibility' ),
			__( '<strong>Updates.</strong> An arrow next to a version means WordPress has a newer release. Update before you migrate, so VIP reviews the code you will actually ship.', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Returns the table columns.
	 *
	 * @return array<int, array<string, mixed>> Column definitions.
	 */
	protected function get_columns() {
		return array(
			array(
				'label'    => __( 'Plugin', 'wp-vip-compatibility' ),
				'class'    => 'wvc-col-name',
				'sortable' => true,
			),
			array(
				'label' => __( 'Version', 'wp-vip-compatibility' ),
				'class' => 'wvc-col-version',
			),
			array(
				'label'    => __( 'State', 'wp-vip-compatibility' ),
				'class'    => 'wvc-col-state',
				'sortable' => true,
			),
			array(
				'label'    => __( 'VIP verdict', 'wp-vip-compatibility' ),
				'class'    => 'wvc-col-status',
				'sortable' => true,
			),
			array(
				'label' => __( 'Findings', 'wp-vip-compatibility' ),
				'class' => 'wvc-col-findings',
				'hint'  => __( 'Findings grouped by what to do about them: must fix, should fix, worth checking, FYI.', 'wp-vip-compatibility' ),
			),
			array(
				'label' => __( 'What to do', 'wp-vip-compatibility' ),
				'class' => 'wvc-col-notes',
			),
		);
	}

	/**
	 * Adds the activation filter alongside the shared verdict filter.
	 *
	 * @param array<string, mixed> $context The context.
	 * @return array<int, array<string, mixed>> Filter groups.
	 */
	protected function get_extra_filters( array $context ) {
		unset( $context );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection of a client-side filter.
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : 'all';

		return array(
			array(
				'name'    => 'state',
				'label'   => __( 'Filter by activation', 'wp-vip-compatibility' ),
				'active'  => in_array( $state, array( 'active', 'inactive' ), true ) ? $state : 'all',
				'options' => array(
					array(
						'value' => 'all',
						'label' => __( 'All plugins', 'wp-vip-compatibility' ),
					),
					array(
						'value' => 'active',
						'label' => __( 'Active', 'wp-vip-compatibility' ),
					),
					array(
						'value' => 'inactive',
						'label' => __( 'Inactive', 'wp-vip-compatibility' ),
					),
				),
			),
		);
	}

	/**
	 * Tags each row with its activation state so the filter can read it.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $context The context.
	 * @return array<string, string> Row attributes.
	 */
	protected function get_row_filters( array $target, array $context ) {
		return array( 'state' => $this->is_active( $target, $context ) ? 'active' : 'inactive' );
	}

	/**
	 * Adds the update count to the shared statistics.
	 *
	 * @param array<string, mixed> $context The context.
	 * @return array<int, array<string, mixed>> Stat tiles.
	 */
	protected function get_stats( array $context ) {
		$stats   = parent::get_stats( $context );
		$updates = 0;
		$active  = 0;

		foreach ( Targets::of_type( 'plugin' ) as $target ) {
			if ( '' !== $this->get_available_version( $target, $context ) ) {
				++$updates;
			}

			if ( $this->is_active( $target, $context ) ) {
				++$active;
			}
		}

		array_unshift(
			$stats,
			array(
				'label' => __( 'Installed', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( (int) $context['counts']['total'] ),
				'meta'  => sprintf(
					/* translators: %s: Number of active plugins. */
					__( '%s active', 'wp-vip-compatibility' ),
					number_format_i18n( $active )
				),
				'tone'  => 'neutral',
			)
		);

		if ( $updates > 0 ) {
			$stats[] = array(
				'label' => __( 'Updates available', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( $updates ),
				'meta'  => __( 'Update before VIP reviews the code', 'wp-vip-compatibility' ),
				'tone'  => 'accent',
				'url'   => admin_url( 'plugins.php?plugin_status=upgrade' ),
			);
		}

		return $stats;
	}

	/**
	 * Renders the cells of one row.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $verdict The resolved verdict.
	 * @param array<string, mixed> $context The context.
	 * @return void
	 */
	protected function render_cells( array $target, array $verdict, array $context ) {
		$this->render_name_cell( $target );
		$this->render_version_cell( $target, $context );

		$active = $this->is_active( $target, $context );

		echo '<td class="wvc-col-state" data-label="' . esc_attr__( 'State', 'wp-vip-compatibility' ) . '">';
		echo UI::get_badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			$active ? __( 'Active', 'wp-vip-compatibility' ) : __( 'Inactive', 'wp-vip-compatibility' ),
			$active ? 'accent' : 'neutral',
			$active
				? __( 'WordPress loads this plugin on every request.', 'wp-vip-compatibility' )
				: __( 'Not loaded, but still shipped in the repository and still reviewed by VIP.', 'wp-vip-compatibility' )
		);
		echo '</td>';

		echo UI::get_verdict_cell( $verdict, $target['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		$this->render_findings_cell( $target, $verdict, $context );
		$this->render_note_cell( $target, $verdict );
	}

	/**
	 * Whether WordPress currently loads a plugin.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $context The context.
	 * @return bool True when the plugin is active.
	 */
	private function is_active( array $target, array $context ) {
		return in_array( $target['file'], (array) $context['active_plugins'], true );
	}
}
