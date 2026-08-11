<?php
/**
 * The inventory screen.
 *
 * Plugins, themes and must-use plugins used to be three screens. They asked the
 * same question of the same kind of thing, resolved it from the same stored scan
 * result, and rendered near-identical tables — so the split forced anyone
 * auditing a site to visit three places and hold three sets of counts in their
 * head to answer "what still needs work". They are one list with a kind filter.
 *
 * The columns are deliberately fewer than the data available. Author and
 * "available version" were columns of their own; neither changes what anyone
 * does about a verdict, so the path folds under the name and an update becomes a
 * marker on the version. Everything the scanner knows is still in the exports.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every scannable item with its VIP verdict.
 */
class Inventory_Settings {

	use Singleton;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Returns the item kinds, in the order they are listed.
	 *
	 * @return array<string, string> Labels keyed by target type.
	 */
	private function get_kinds() {
		return array(
			'plugin'    => __( 'Plugins', 'wp-vip-compatibility' ),
			'theme'     => __( 'Themes', 'wp-vip-compatibility' ),
			'mu-plugin' => __( 'Must-use', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$targets = Targets::all();

		if ( empty( $targets ) ) {
			UI::render_empty_state(
				__( 'Nothing is installed', 'wp-vip-compatibility' ),
				__( 'There are no plugins, themes or must-use plugins to check.', 'wp-vip-compatibility' )
			);

			return;
		}

		$this->render_guidance();
		$this->render_toolbar();

		echo '<div class="wvc-table-wrap" data-role="table-view">';
		echo '<table class="wvc-table wvc-table--inventory">';

		UI::render_table_head(
			array(
				array(
					'label'    => __( 'Item', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-name',
					'sortable' => true,
				),
				array(
					'label'    => __( 'Kind', 'wp-vip-compatibility' ),
					'class'    => 'wvc-col-kind',
					'sortable' => true,
				),
				array(
					'label' => __( 'Version', 'wp-vip-compatibility' ),
					'class' => 'wvc-col-version',
				),
				array(
					'label'    => __( 'VIP verdict', 'wp-vip-compatibility' ),
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

		$index          = Results_Store::get_index();
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$active_theme   = get_stylesheet();

		foreach ( $this->get_kinds() as $kind => $label ) {
			foreach ( Targets::of_type( $kind ) as $target ) {
				$this->render_row( $target, $index, $plugin_updates, $theme_updates, $active_theme );
			}
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders the reference notes for this screen.
	 *
	 * One disclosure carries what used to be a separate collapsed notice on each
	 * of the three screens this replaces. Three panels explaining three halves of
	 * the same rule is exactly the noise the reader has to wade through to reach
	 * the table; folded together they are read once and then ignored.
	 *
	 * @return void
	 */
	private function render_guidance() {
		$notes = array(
			sprintf(
				/* translators: %s: Link to the findings report. */
				__( '<strong>Verdicts.</strong> "Blocked" means at least one finding is expected to fail on VIP. "Needs review" means there is work to do that will not by itself stop a migration. %s for the detail behind every verdict.', 'wp-vip-compatibility' ),
				'<a href="' . esc_url( UI::get_findings_url() ) . '">' . esc_html__( 'Open the findings report', 'wp-vip-compatibility' ) . '</a>'
			),
			__( '<strong>Must-use plugins.</strong> VIP reserves wp-content/mu-plugins for platform code, so everything there has to move to client-mu-plugins/ — except the plugins VIP already preinstalls, and anything a previous host added, which should be dropped instead. Loose PHP files and directories are listed separately because WordPress only auto-loads files at the root of mu-plugins.', 'wp-vip-compatibility' ),
			__( '<strong>Inactive themes and plugins.</strong> They still ship in the repository and are still scanned by the VIP Code Analysis Bot. Removing the ones you do not use is the quickest way to shorten this list.', 'wp-vip-compatibility' ),
		);

		echo UI::get_guidance( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			'<p>' . implode( '</p><p>', $notes ) . '</p>',
			__( 'How to read this list', 'wp-vip-compatibility' )
		);
	}

	/**
	 * Renders the toolbar.
	 *
	 * Both filter groups carry their own counts, derived in the browser from the
	 * rows themselves. Each screen used to print a four-item stats strip above a
	 * three-item filter bar above a result count — three renderings of the same
	 * numbers, which then disagreed with each other while a scan was running.
	 *
	 * @return void
	 */
	private function render_toolbar() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection of a client-side filter.
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'all';

		if ( ! isset( $this->get_kinds()[ $kind ] ) ) {
			$kind = 'all';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection of a client-side filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';

		if ( ! in_array( $status, array( 'not-compatible', 'review', 'compatible' ), true ) ) {
			$status = 'all';
		}

		$kind_options = array(
			array(
				'value' => 'all',
				'label' => __( 'Everything', 'wp-vip-compatibility' ),
			),
		);

		foreach ( $this->get_kinds() as $value => $label ) {
			$kind_options[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		UI::render_toolbar(
			array(
				'search_label'  => __( 'Search plugins, themes and must-use plugins', 'wp-vip-compatibility' ),
				'show_progress' => true,
				'groups'        => array(
					array(
						'name'    => 'kind',
						'label'   => __( 'Filter by kind', 'wp-vip-compatibility' ),
						'active'  => $kind,
						'options' => $kind_options,
					),
					array(
						'name'    => 'status',
						'label'   => __( 'Filter by verdict', 'wp-vip-compatibility' ),
						'active'  => $status,
						'options' => array(
							array(
								'value' => 'all',
								'label' => __( 'All verdicts', 'wp-vip-compatibility' ),
							),
							array(
								'value' => 'not-compatible',
								'label' => __( 'Blocked', 'wp-vip-compatibility' ),
								'dot'   => 'bad',
							),
							array(
								'value' => 'review',
								'label' => __( 'Needs review', 'wp-vip-compatibility' ),
								'dot'   => 'warn',
							),
							array(
								'value' => 'compatible',
								'label' => __( 'Ready', 'wp-vip-compatibility' ),
								'dot'   => 'ok',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Renders one row.
	 *
	 * @param array<string, mixed>                $target         The target.
	 * @param array<string, array<string, mixed>> $index          The stored result index.
	 * @param object|false                        $plugin_updates The update_plugins transient.
	 * @param object|false                        $theme_updates  The update_themes transient.
	 * @param string                              $active_theme   The active stylesheet.
	 * @return void
	 */
	private function render_row( array $target, array $index, $plugin_updates, $theme_updates, $active_theme ) {
		$verdict = Report::verdict( $target, $index );
		$kinds   = $this->get_kinds();

		printf(
			'<tr data-kind="%1$s" data-status="%2$s">',
			esc_attr( $target['type'] ),
			esc_attr( $verdict['state'] )
		);

		// Name, with the path it lives at and the "active" marker folded in.
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Item', 'wp-vip-compatibility' ) . '">';
		echo '<span class="wvc-item">';
		echo '<span class="wvc-item__name">' . esc_html( $target['label'] );

		if ( 'theme' === $target['type'] && $target['slug'] === $active_theme ) {
			echo ' <span class="wvc-badge wvc-badge--neutral">' . esc_html__( 'Active', 'wp-vip-compatibility' ) . '</span>';
		}

		echo '</span>';
		echo '<code class="wvc-item__path">' . esc_html( $target['file'] ) . '</code>';
		echo '</span>';
		echo '</td>';

		echo '<td class="wvc-col-kind" data-label="' . esc_attr__( 'Kind', 'wp-vip-compatibility' ) . '">'
			. esc_html( $kinds[ $target['type'] ] ?? $target['type'] )
			. '</td>';

		echo '<td class="wvc-col-version" data-label="' . esc_attr__( 'Version', 'wp-vip-compatibility' ) . '">'
			. wp_kses_post( $this->version_cell( $target, $plugin_updates, $theme_updates ) )
			. '</td>';

		echo UI::get_verdict_cell( $verdict, $target['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'What to do', 'wp-vip-compatibility' ) . '">'
			. wp_kses_post( $this->note( $target, $verdict ) )
			. '</td>';

		echo '</tr>';
	}

	/**
	 * Builds the version cell.
	 *
	 * An available update is a marker on the current version rather than a column
	 * of its own: "up to date" repeated down a whole column is not information.
	 *
	 * @param array<string, mixed> $target         The target.
	 * @param object|false         $plugin_updates The update_plugins transient.
	 * @param object|false         $theme_updates  The update_themes transient.
	 * @return string The cell contents.
	 */
	private function version_cell( array $target, $plugin_updates, $theme_updates ) {
		if ( '' === $target['version'] ) {
			return '<span class="wvc-dash" aria-hidden="true">—</span>';
		}

		$available = '';

		if ( 'plugin' === $target['type'] && isset( $plugin_updates->response[ $target['file'] ] ) ) {
			$available = (string) $plugin_updates->response[ $target['file'] ]->new_version;
		} elseif ( 'theme' === $target['type'] && isset( $theme_updates->response[ $target['slug'] ] ) ) {
			$available = (string) $theme_updates->response[ $target['slug'] ]['new_version'];
		}

		$cell = '<span class="wvc-version">' . esc_html( $target['version'] ) . '</span>';

		if ( '' !== $available ) {
			$cell .= sprintf(
				'<span class="wvc-version__update" title="%1$s">%2$s</span>',
				esc_attr__( 'An update is available. Update before you migrate, so VIP reviews the code you will actually ship.', 'wp-vip-compatibility' ),
				esc_html(
					sprintf(
						/* translators: %s: Version number. */
						__( '→ %s', 'wp-vip-compatibility' ),
						$available
					)
				)
			);
		}

		return $cell;
	}

	/**
	 * Builds the "what to do" cell.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $verdict The resolved verdict.
	 * @return string The cell contents.
	 */
	private function note( array $target, array $verdict ) {
		$parts = array();
		$known = $verdict['known'];

		if ( 'mu-plugin' === $target['type'] ) {
			$parts[] = is_array( $known )
				? $this->known_note( $known['note'] ?? '', $known['doc'] ?? '' )
				: esc_html__( 'Move it into client-mu-plugins/ in the VIP repository.', 'wp-vip-compatibility' );
		} elseif ( is_array( $known ) ) {
			$parts[] = $this->known_note(
				trim( $known['label'] . ' — ' . $known['reason'] ),
				$known['doc']
			);
		}

		$parts[] = UI::get_findings_link( $target['key'], $verdict['total'] );

		return UI::get_notes_list( $parts );
	}

	/**
	 * Builds a curated-list note, linked to its documentation when there is one.
	 *
	 * @param string $note The note text.
	 * @param string $doc  Optional documentation URL.
	 * @return string The escaped note markup.
	 */
	private function known_note( $note, $doc ) {
		if ( '' === $note ) {
			return '';
		}

		if ( '' === $doc ) {
			return esc_html( $note );
		}

		return sprintf(
			'<a class="wvc-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $doc ),
			esc_html( $note )
		);
	}
}
