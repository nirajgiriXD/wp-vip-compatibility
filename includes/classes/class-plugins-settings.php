<?php
/**
 * The plugins screen.
 *
 * A plugin is the unit most migration decisions are actually made about — keep
 * it, replace it, or drop it — so it gets a screen where that decision can be
 * made from one row: what it is, whether the site runs it, whether WordPress VIP has
 * already ruled on it, how serious the findings are, and the one action to take.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Includes\Classes;

use CUTOVER_AUDIT\Includes\Traits\Singleton;
use CUTOVER_AUDIT\Includes\Scanner\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every installed plugin with its WordPress VIP verdict.
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
		return __( 'Search plugins by name, path or author', 'cutover-audit' );
	}

	/**
	 * Returns the accessible name of the table.
	 *
	 * @return string The title.
	 */
	protected function get_table_title() {
		return __( 'Installed plugins', 'cutover-audit' );
	}

	/**
	 * Returns the empty-state copy.
	 *
	 * @return array<int, string> Title and message.
	 */
	protected function get_empty_state() {
		return array(
			__( 'No plugins are installed', 'cutover-audit' ),
			__( 'There is nothing here to check against the WordPress VIP Platform.', 'cutover-audit' ),
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
				__( '<strong>Verdicts.</strong> "Needs work" means at least one finding is likely to cause problems on WordPress VIP. "Review" means there are findings worth a look that are unlikely to break anything. %s for the detail behind every verdict.', 'cutover-audit' ),
				'<a href="' . esc_url( UI::get_findings_url() ) . '">' . esc_html__( 'Open the findings report', 'cutover-audit' ) . '</a>'
			),
			__( '<strong>Inactive plugins still count.</strong> They ship in the repository and are still read by the WordPress VIP Code Analysis Bot. Removing the ones you do not use is the quickest way to shorten this list.', 'cutover-audit' ),
			__( '<strong>Updates.</strong> An arrow next to a version means WordPress has a newer release. Update first, so the scan reads the code you will actually ship.', 'cutover-audit' ),
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
				'label'    => __( 'Plugin', 'cutover-audit' ),
				'class'    => 'cutover-audit-col-name',
				'sortable' => true,
			),
			array(
				'label' => __( 'Version', 'cutover-audit' ),
				'class' => 'cutover-audit-col-version',
			),
			array(
				'label'    => __( 'State', 'cutover-audit' ),
				'class'    => 'cutover-audit-col-state',
				'sortable' => true,
			),
			array(
				'label'    => __( 'Verdict', 'cutover-audit' ),
				'class'    => 'cutover-audit-col-status',
				'sortable' => true,
			),
			array(
				'label' => __( 'Findings', 'cutover-audit' ),
				'class' => 'cutover-audit-col-findings',
				'hint'  => __( 'Findings grouped by what to do about them: must fix, should fix, worth checking, FYI.', 'cutover-audit' ),
			),
			array(
				'label' => __( 'What to do', 'cutover-audit' ),
				'class' => 'cutover-audit-col-notes',
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
				'label'   => __( 'Filter by activation', 'cutover-audit' ),
				'active'  => in_array( $state, array( 'active', 'inactive' ), true ) ? $state : 'all',
				'options' => array(
					array(
						'value' => 'all',
						'label' => __( 'All plugins', 'cutover-audit' ),
					),
					array(
						'value' => 'active',
						'label' => __( 'Active', 'cutover-audit' ),
					),
					array(
						'value' => 'inactive',
						'label' => __( 'Inactive', 'cutover-audit' ),
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

		echo '<td class="cutover-audit-col-state" data-label="' . esc_attr__( 'State', 'cutover-audit' ) . '">';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		echo UI::get_badge(
			$active ? __( 'Active', 'cutover-audit' ) : __( 'Inactive', 'cutover-audit' ),
			$active ? 'accent' : 'neutral',
			$active
				? __( 'WordPress loads this plugin on every request.', 'cutover-audit' )
				: __( 'Not loaded, but still shipped in the repository and still reviewed by WordPress VIP.', 'cutover-audit' )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
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
