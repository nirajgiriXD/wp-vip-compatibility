<?php
/**
 * The themes screen.
 *
 * Every installed theme is listed, with the active theme and its parent marked
 * by a badge beside the name. Inactive themes stay in the list because they
 * still ship in the repository and are still read by WordPress VIP's code review.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Includes\Classes;

use CUTOVER_AUDIT\Includes\Traits\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every installed theme with its WordPress VIP verdict.
 */
class Themes_Settings extends Inventory_Screen {

	use Singleton;

	/**
	 * Returns the target type this screen lists.
	 *
	 * @return string The target type.
	 */
	protected function get_type() {
		return 'theme';
	}

	/**
	 * Returns the label for the search field.
	 *
	 * @return string The label.
	 */
	protected function get_search_label() {
		return __( 'Search themes by name or author', 'cutover-audit' );
	}

	/**
	 * Returns the accessible name of the table.
	 *
	 * @return string The title.
	 */
	protected function get_table_title() {
		return __( 'Installed themes', 'cutover-audit' );
	}

	/**
	 * Returns the empty-state copy.
	 *
	 * @return array<int, string> Title and message.
	 */
	protected function get_empty_state() {
		return array(
			__( 'No themes are installed', 'cutover-audit' ),
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
			__( '<strong>Inactive themes.</strong> They are still committed to the WordPress VIP repository and still scanned by the Code Analysis Bot. Unless a theme is a live parent or a planned redesign, leaving it out of the repository removes its findings entirely.', 'cutover-audit' ),
			__( '<strong>Parent themes.</strong> A child theme is only as portable as the parent it inherits from, so a parent is listed and scanned in its own right even when it is never activated directly.', 'cutover-audit' ),
			__( '<strong>Themes are where most filesystem findings come from.</strong> Writing generated CSS, caching markup to disk or resizing images at request time all work locally and none of them work on WordPress VIP.', 'cutover-audit' ),
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
				'label'    => __( 'Theme', 'cutover-audit' ),
				'class'    => 'cutover-audit-col-name',
				'sortable' => true,
			),
			array(
				'label' => __( 'Version', 'cutover-audit' ),
				'class' => 'cutover-audit-col-version',
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
	 * Adds the role filter alongside the shared verdict filter.
	 *
	 * @param array<string, mixed> $context The context.
	 * @return array<int, array<string, mixed>> Filter groups.
	 */
	protected function get_extra_filters( array $context ) {
		unset( $context );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection of a client-side filter.
		$role = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : 'all';

		return array(
			array(
				'name'    => 'role',
				'label'   => __( 'Filter by role', 'cutover-audit' ),
				'active'  => in_array( $role, array( 'live', 'inactive' ), true ) ? $role : 'all',
				'options' => array(
					array(
						'value' => 'all',
						'label' => __( 'All themes', 'cutover-audit' ),
					),
					array(
						'value' => 'live',
						'label' => __( 'In use', 'cutover-audit' ),
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
	 * Tags each row with whether the site actually renders through it.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $context The context.
	 * @return array<string, string> Row attributes.
	 */
	protected function get_row_filters( array $target, array $context ) {
		$role = $this->get_role( $target, $context );

		return array( 'role' => ( 'inactive' === $role ) ? 'inactive' : 'live' );
	}

	/**
	 * Adds the theme-specific context: which slug is the active parent.
	 *
	 * @param array<string, array<string, mixed>> $targets The targets on this screen.
	 * @return array<string, mixed> The context.
	 */
	protected function build_context( array $targets ) {
		$context = parent::build_context( $targets );

		$context['active_template'] = get_template();

		return $context;
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
		// Only a theme the site actually renders through is marked; an inactive
		// theme is the default and needs no label.
		$role   = $this->get_role( $target, $context );
		$labels = array(
			'active' => array( __( 'Active', 'cutover-audit' ), 'accent', __( 'The site renders through this theme.', 'cutover-audit' ) ),
			'parent' => array( __( 'Parent', 'cutover-audit' ), 'neutral', __( 'The active theme inherits from this one.', 'cutover-audit' ) ),
		);
		$badges = isset( $labels[ $role ] ) ? array( UI::get_badge( ...$labels[ $role ] ) ) : array();

		$this->render_name_cell( $target, $badges );
		$this->render_version_cell( $target, $context );

		echo UI::get_verdict_cell( $verdict, $target['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		$this->render_findings_cell( $target, $verdict, $context );
		$this->render_note_cell( $target, $verdict );
	}

	/**
	 * Returns the part a theme plays on this site.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $context The context.
	 * @return string One of `active`, `parent`, `inactive`.
	 */
	private function get_role( array $target, array $context ) {
		if ( $target['slug'] === $context['active_theme'] ) {
			return 'active';
		}

		return ( ( $context['active_template'] ?? '' ) === $target['slug'] ) ? 'parent' : 'inactive';
	}
}
