<?php
/**
 * The plugins screen.
 *
 * A plugin is the unit most migration decisions are actually made about — keep
 * it, replace it, or drop it — so it gets a screen where that decision can be
 * made from one row: what it is, whether the site runs it, whether WordPress VIP has
 * already ruled on it, how serious the findings are, and the one action to take.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Includes\Classes;

use PREFLIGHT_AUDIT\Includes\Traits\Singleton;
use PREFLIGHT_AUDIT\Includes\Scanner\Targets;

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
		return __( 'Search plugins by name, path or author', 'preflight-audit' );
	}

	/**
	 * Returns the accessible name of the table.
	 *
	 * @return string The title.
	 */
	protected function get_table_title() {
		return __( 'Installed plugins', 'preflight-audit' );
	}

	/**
	 * Returns the empty-state copy.
	 *
	 * @return array<int, string> Title and message.
	 */
	protected function get_empty_state() {
		return array(
			__( 'No plugins are installed', 'preflight-audit' ),
			__( 'There is nothing here to check against the WordPress VIP Platform.', 'preflight-audit' ),
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
				__( '<strong>Verdicts.</strong> "Needs work" means at least one finding is likely to cause problems on WordPress VIP. "Review" means there are findings worth a look that are unlikely to break anything. %s for the detail behind every verdict.', 'preflight-audit' ),
				'<a href="' . esc_url( UI::get_findings_url() ) . '">' . esc_html__( 'Open the findings report', 'preflight-audit' ) . '</a>'
			),
			__( '<strong>Inactive plugins still count.</strong> They ship in the repository and are still read by the WordPress VIP Code Analysis Bot. Removing the ones you do not use is the quickest way to shorten this list.', 'preflight-audit' ),
			__( '<strong>Updates.</strong> An arrow next to a version means WordPress has a newer release. Update first, so the scan reads the code you will actually ship.', 'preflight-audit' ),
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
				'label'    => __( 'Plugin', 'preflight-audit' ),
				'class'    => 'pfa-col-name',
				'sortable' => true,
			),
			array(
				'label' => __( 'Version', 'preflight-audit' ),
				'class' => 'pfa-col-version',
			),
			array(
				'label'    => __( 'State', 'preflight-audit' ),
				'class'    => 'pfa-col-state',
				'sortable' => true,
			),
			array(
				'label'    => __( 'Verdict', 'preflight-audit' ),
				'class'    => 'pfa-col-status',
				'sortable' => true,
			),
			array(
				'label' => __( 'Findings', 'preflight-audit' ),
				'class' => 'pfa-col-findings',
				'hint'  => __( 'Findings grouped by what to do about them: must fix, should fix, worth checking, FYI.', 'preflight-audit' ),
			),
			array(
				'label' => __( 'What to do', 'preflight-audit' ),
				'class' => 'pfa-col-notes',
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
				'label'   => __( 'Filter by activation', 'preflight-audit' ),
				'active'  => in_array( $state, array( 'active', 'inactive' ), true ) ? $state : 'all',
				'options' => array(
					array(
						'value' => 'all',
						'label' => __( 'All plugins', 'preflight-audit' ),
					),
					array(
						'value' => 'active',
						'label' => __( 'Active', 'preflight-audit' ),
					),
					array(
						'value' => 'inactive',
						'label' => __( 'Inactive', 'preflight-audit' ),
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

		echo '<td class="pfa-col-state" data-label="' . esc_attr__( 'State', 'preflight-audit' ) . '">';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		echo UI::get_badge(
			$active ? __( 'Active', 'preflight-audit' ) : __( 'Inactive', 'preflight-audit' ),
			$active ? 'accent' : 'neutral',
			$active
				? __( 'WordPress loads this plugin on every request.', 'preflight-audit' )
				: __( 'Not loaded, but still shipped in the repository and still reviewed by WordPress VIP.', 'preflight-audit' )
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
