<?php
/**
 * The themes screen.
 *
 * One theme runs the site and the rest are inventory, so the screen is shaped
 * that way: the active theme gets a panel of its own with everything a migration
 * needs to know about it, and every installed theme — including that one — stays
 * in the table below, because inactive themes still ship in the repository and
 * are still read by WordPress VIP's code review.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;

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
		return __( 'Search themes by name or author', 'wp-vip-compatibility' );
	}

	/**
	 * Returns the panel title for the table.
	 *
	 * @return string The title.
	 */
	protected function get_table_title() {
		return __( 'Installed themes', 'wp-vip-compatibility' );
	}

	/**
	 * Returns the empty-state copy.
	 *
	 * @return array<int, string> Title and message.
	 */
	protected function get_empty_state() {
		return array(
			__( 'No themes are installed', 'wp-vip-compatibility' ),
			__( 'There is nothing here to check against the WordPress VIP Platform.', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Returns the reference notes shown above the table.
	 *
	 * @return array<int, string> Note paragraphs.
	 */
	protected function get_guidance_notes() {
		return array(
			__( '<strong>Inactive themes.</strong> They are still committed to the WordPress VIP repository and still scanned by the Code Analysis Bot. Unless a theme is a live parent or a planned redesign, leaving it out of the repository removes its findings entirely.', 'wp-vip-compatibility' ),
			__( '<strong>Parent themes.</strong> A child theme is only as portable as the parent it inherits from, so a parent is listed and scanned in its own right even when it is never activated directly.', 'wp-vip-compatibility' ),
			__( '<strong>Themes are where most filesystem findings come from.</strong> Writing generated CSS, caching markup to disk or resizing images at request time all work locally and none of them work on WordPress VIP.', 'wp-vip-compatibility' ),
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
				'label'    => __( 'Theme', 'wp-vip-compatibility' ),
				'class'    => 'wvc-col-name',
				'sortable' => true,
			),
			array(
				'label' => __( 'Version', 'wp-vip-compatibility' ),
				'class' => 'wvc-col-version',
			),
			array(
				'label'    => __( 'Role', 'wp-vip-compatibility' ),
				'class'    => 'wvc-col-state',
				'sortable' => true,
			),
			array(
				'label'    => __( 'WordPress VIP verdict', 'wp-vip-compatibility' ),
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
				'label'   => __( 'Filter by role', 'wp-vip-compatibility' ),
				'active'  => in_array( $role, array( 'live', 'inactive' ), true ) ? $role : 'all',
				'options' => array(
					array(
						'value' => 'all',
						'label' => __( 'All themes', 'wp-vip-compatibility' ),
					),
					array(
						'value' => 'live',
						'label' => __( 'In use', 'wp-vip-compatibility' ),
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
	 * Leads with the active theme before the full list.
	 *
	 * @param array<string, mixed> $context The context.
	 * @return void
	 */
	protected function render_intro( array $context ) {
		$key    = Targets::key( 'theme', $context['active_theme'] );
		$target = Targets::get( $key );

		if ( null === $target ) {
			return;
		}

		$verdict = $context['verdicts'][ $key ] ?? array();
		$theme   = wp_get_theme( $context['active_theme'] );
		$parent  = $theme->parent();

		UI::render_panel_open(
			array(
				'title'   => __( 'Active theme', 'wp-vip-compatibility' ),
				'summary' => __( 'The theme this site renders through today. Its findings are the ones your visitors are exposed to.', 'wp-vip-compatibility' ),
				'actions' => ( empty( $verdict['total'] ) ) ? array() : array(
					array(
						'label' => __( 'Review its findings', 'wp-vip-compatibility' ),
						'url'   => UI::get_findings_url( $key ),
						'icon'  => 'list',
					),
				),
			)
		);
		?>
		<div class="wvc-spotlight">
			<?php $screenshot = $theme->get_screenshot(); ?>
			<?php if ( $screenshot ) : ?>
				<img class="wvc-spotlight__shot" src="<?php echo esc_url( $screenshot ); ?>" alt="" loading="lazy" width="200" height="150" />
			<?php endif; ?>

			<div class="wvc-spotlight__body">
				<h4 class="wvc-spotlight__name">
					<?php echo esc_html( $theme->get( 'Name' ) ); ?>
					<?php if ( ! empty( $verdict['state'] ) ) : ?>
						<?php echo UI::get_status_pill( $verdict['state'], $verdict['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
					<?php endif; ?>
				</h4>

				<?php if ( $theme->get( 'Description' ) ) : ?>
					<p class="wvc-spotlight__description"><?php echo esc_html( wp_strip_all_tags( $theme->get( 'Description' ) ) ); ?></p>
				<?php endif; ?>

				<?php
				$rows = array(
					array(
						'label' => __( 'Version', 'wp-vip-compatibility' ),
						'value' => $theme->get( 'Version' ),
					),
					array(
						'label' => __( 'Author', 'wp-vip-compatibility' ),
						'value' => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
					),
					array(
						'label' => __( 'Directory', 'wp-vip-compatibility' ),
						'html'  => '<code>' . esc_html( $this->get_relative_path( $target ) ) . '</code>',
					),
				);

				if ( $parent ) {
					$rows[] = array(
						'label' => __( 'Parent theme', 'wp-vip-compatibility' ),
						'value' => $parent->get( 'Name' ),
						'hint'  => __( 'The parent ships with the site and is scanned in its own right.', 'wp-vip-compatibility' ),
					);
				}

				$bar = UI::get_severity_bar(
					(array) ( $context['index'][ $key ]['summary']['by_severity'] ?? array() ),
					$key
				);

				if ( '' !== $bar ) {
					$rows[] = array(
						'label' => __( 'Findings by importance', 'wp-vip-compatibility' ),
						'html'  => $bar,
					);
				}

				echo UI::get_defs( $rows, 'wvc-defs--tight' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				?>
			</div>
		</div>
		<?php
		UI::render_panel_close();
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

		$role   = $this->get_role( $target, $context );
		$labels = array(
			'active'   => array( __( 'Active', 'wp-vip-compatibility' ), 'accent', __( 'The site renders through this theme.', 'wp-vip-compatibility' ) ),
			'parent'   => array( __( 'Parent', 'wp-vip-compatibility' ), 'accent', __( 'The active theme inherits from this one.', 'wp-vip-compatibility' ) ),
			'inactive' => array( __( 'Inactive', 'wp-vip-compatibility' ), 'neutral', __( 'Not in use, but still committed to the repository and still reviewed by WordPress VIP.', 'wp-vip-compatibility' ) ),
		);

		echo '<td class="wvc-col-state" data-label="' . esc_attr__( 'Role', 'wp-vip-compatibility' ) . '">';
		echo UI::get_badge( $labels[ $role ][0], $labels[ $role ][1], $labels[ $role ][2] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		echo '</td>';

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

		return ( $target['slug'] === ( $context['active_template'] ?? '' ) ) ? 'parent' : 'inactive';
	}
}
