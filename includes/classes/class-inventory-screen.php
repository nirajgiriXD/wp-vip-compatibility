<?php
/**
 * Shared behaviour for the three screens that list scan targets.
 *
 * Plugins, themes and must-use plugins are separate screens because the decision
 * each one leads to is different, but they read the same stored results and use
 * the same three-level hierarchy:
 *
 * - The table row carries what a decision needs: name, version, state, verdict,
 *   how serious the findings are, and the single next action.
 * - The expanded row carries the evidence: the severity split, the categories
 *   involved, how much code was read, where it lives on disk.
 * - The findings report carries the diagnostics.
 *
 * Subclasses supply the target type, the columns and the row body; everything
 * that is genuinely identical — the toolbar, the async scan hooks, the detail
 * drawer, the verdict resolution — lives here once.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Scanner\Known_Plugins;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Report;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Results_Store;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Scanner;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the plugins, themes and must-use plugin screens.
 */
abstract class Inventory_Screen {

	/**
	 * Returns the target type this screen lists.
	 *
	 * @return string One of `plugin`, `theme`, `mu-plugin`.
	 */
	abstract protected function get_type();

	/**
	 * Returns the table columns.
	 *
	 * @return array<int, array<string, mixed>> Column definitions.
	 */
	abstract protected function get_columns();

	/**
	 * Renders the cells of one row, after the toggle cell.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $verdict The resolved verdict.
	 * @param array<string, mixed> $context Shared per-request lookups.
	 * @return void
	 */
	abstract protected function render_cells( array $target, array $verdict, array $context );

	/**
	 * Returns the row's filter attributes beyond the verdict.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $context Shared per-request lookups.
	 * @return array<string, string> Attribute name => value, without the `data-` prefix.
	 */
	protected function get_row_filters( array $target, array $context ) {
		unset( $target, $context );

		return array();
	}

	/**
	 * Returns the empty-state copy for a site with none of this target type.
	 *
	 * @return array<int, string> Title and message.
	 */
	abstract protected function get_empty_state();

	/**
	 * Returns the reference notes shown above the table.
	 *
	 * @return array<int, string> Note paragraphs, already containing limited HTML.
	 */
	protected function get_guidance_notes() {
		return array();
	}

	/**
	 * Returns extra filter groups for the toolbar, beyond the verdict filter.
	 *
	 * @param array<string, mixed> $context Shared per-request lookups.
	 * @return array<int, array<string, mixed>> Filter groups.
	 */
	protected function get_extra_filters( array $context ) {
		unset( $context );

		return array();
	}

	/**
	 * Renders anything that belongs above the table, after the statistics.
	 *
	 * @param array<string, mixed> $context Shared per-request lookups.
	 * @return void
	 */
	protected function render_intro( array $context ) {
		unset( $context );
	}

	/**
	 * Returns the label for the search field.
	 *
	 * @return string The label.
	 */
	abstract protected function get_search_label();

	/**
	 * Returns the panel title for the table.
	 *
	 * @return string The title.
	 */
	abstract protected function get_table_title();

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$targets = Targets::of_type( $this->get_type() );

		if ( empty( $targets ) ) {
			$empty = $this->get_empty_state();

			UI::render_empty_state( $empty[0], $empty[1] ?? '' );

			return;
		}

		$context = $this->build_context( $targets );

		UI::render_stats( $this->get_stats( $context ) );

		$this->render_intro( $context );

		$notes = $this->get_guidance_notes();

		if ( ! empty( $notes ) ) {
			echo UI::get_guidance( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				'<p>' . implode( '</p><p>', $notes ) . '</p>',
				__( 'How to read this list', 'wp-vip-compatibility' )
			);
		}

		UI::render_panel_open(
			array(
				'title' => $this->get_table_title(),
				'count' => number_format_i18n( count( $targets ) ),
				'flush' => true,
			)
		);

		UI::render_toolbar(
			array(
				'search_label'  => $this->get_search_label(),
				'show_progress' => true,
				'groups'        => array_merge( $this->get_extra_filters( $context ), array( $this->get_status_filter() ) ),
			)
		);

		echo '<div class="wvc-table-wrap" data-role="table-view">';
		echo '<table class="wvc-table wvc-table--expandable">';

		UI::render_table_head(
			array_merge(
				array(
					array(
						'label' => '',
						'class' => 'wvc-col-toggle',
					),
				),
				$this->get_columns()
			)
		);

		echo '<tbody>';

		foreach ( $targets as $target ) {
			$this->render_row( $target, $context );
		}

		echo '</tbody></table>';
		echo '</div>';

		UI::render_panel_close();
	}

	/**
	 * Builds the lookups every row needs, once per request.
	 *
	 * @param array<string, array<string, mixed>> $targets The targets on this screen.
	 * @return array<string, mixed> The context.
	 */
	protected function build_context( array $targets ) {
		$index  = Results_Store::get_index();
		$counts = array(
			'total'   => 0,
			'ready'   => 0,
			'review'  => 0,
			'blocked' => 0,
			'pending' => 0,
			'issues'  => 0,
		);

		$verdicts = array();

		foreach ( $targets as $key => $target ) {
			$verdict         = Report::verdict( $target, $index );
			$verdicts[ $key ] = $verdict;

			$bucket = 'review';

			if ( 'pending' === $verdict['state'] ) {
				$bucket = 'pending';
			} elseif ( Scanner::STATUS_PASS === $verdict['status'] ) {
				$bucket = 'ready';
			} elseif ( Scanner::STATUS_BLOCKED === $verdict['status'] ) {
				$bucket = 'blocked';
			}

			++$counts[ $bucket ];
			++$counts['total'];
			$counts['issues'] += (int) $verdict['total'];
		}

		return array(
			'index'          => $index,
			'verdicts'       => $verdicts,
			'counts'         => $counts,
			'plugin_updates' => get_site_transient( 'update_plugins' ),
			'theme_updates'  => get_site_transient( 'update_themes' ),
			'active_theme'   => get_stylesheet(),
			'active_plugins' => $this->get_active_plugins(),
		);
	}

	/**
	 * Returns the plugin files WordPress currently loads.
	 *
	 * @return string[] Plugin files, network-activated ones included.
	 */
	private function get_active_plugins() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return $active;
	}

	/**
	 * Builds the headline statistics for the screen.
	 *
	 * @param array<string, mixed> $context The context.
	 * @return array<int, array<string, mixed>> Stat tiles.
	 */
	protected function get_stats( array $context ) {
		$counts = $context['counts'];
		$screen = UI::get_screen_for_type( $this->get_type() );
		$url    = UI::get_screen_url( $screen );

		$stats = array(
			array(
				'label' => __( 'Blocked', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( $counts['blocked'] ),
				'meta'  => __( 'Expected to fail on VIP', 'wp-vip-compatibility' ),
				'tone'  => $counts['blocked'] > 0 ? 'bad' : 'neutral',
				'url'   => $counts['blocked'] > 0 ? add_query_arg( 'status', 'not-compatible', $url ) : '',
			),
			array(
				'label' => __( 'Needs review', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( $counts['review'] ),
				'meta'  => __( 'A decision before you migrate', 'wp-vip-compatibility' ),
				'tone'  => $counts['review'] > 0 ? 'warn' : 'neutral',
				'url'   => $counts['review'] > 0 ? add_query_arg( 'status', 'review', $url ) : '',
			),
			array(
				'label' => __( 'Ready', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( $counts['ready'] ),
				'meta'  => __( 'Nothing outstanding', 'wp-vip-compatibility' ),
				'tone'  => 'ok',
				'url'   => $counts['ready'] > 0 ? add_query_arg( 'status', 'compatible', $url ) : '',
			),
			array(
				'label' => __( 'Findings', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( $counts['issues'] ),
				'meta'  => __( 'Across everything listed here', 'wp-vip-compatibility' ),
				'tone'  => 'accent',
				'url'   => $counts['issues'] > 0 ? UI::get_findings_url() : '',
			),
		);

		return $stats;
	}

	/**
	 * Reads the verdict filter preselected through the query string.
	 *
	 * @return string The verdict state, or `all`.
	 */
	protected function read_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection of a client-side filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';

		return in_array( $status, array( 'not-compatible', 'review', 'compatible', 'pending' ), true ) ? $status : 'all';
	}

	/**
	 * Builds the verdict filter group.
	 *
	 * @return array<string, mixed> The filter group.
	 */
	protected function get_status_filter() {
		return array(
			'name'    => 'status',
			'label'   => __( 'Filter by verdict', 'wp-vip-compatibility' ),
			'active'  => $this->read_status(),
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
					'label' => __( 'Review', 'wp-vip-compatibility' ),
					'dot'   => 'warn',
				),
				array(
					'value' => 'compatible',
					'label' => __( 'Ready', 'wp-vip-compatibility' ),
					'dot'   => 'ok',
				),
			),
		);
	}

	/**
	 * Renders one row and the detail drawer beneath it.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $context The context.
	 * @return void
	 */
	protected function render_row( array $target, array $context ) {
		$verdict = $context['verdicts'][ $target['key'] ] ?? Report::verdict( $target, $context['index'] );
		$detail  = UI::next_id( 'wvc-detail' );

		$attributes = ' data-status="' . esc_attr( $verdict['state'] ) . '"';

		foreach ( $this->get_row_filters( $target, $context ) as $name => $value ) {
			$attributes .= sprintf( ' data-%s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		echo '<tr class="wvc-row"' . $attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		echo UI::get_row_toggle( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			$detail,
			sprintf(
				/* translators: %s: Item name. */
				__( 'Show details for %s', 'wp-vip-compatibility' ),
				$target['label']
			)
		);

		$this->render_cells( $target, $verdict, $context );

		echo '</tr>';

		$this->render_detail_row( $target, $verdict, $context, $detail );
	}

	/**
	 * Renders the expandable detail row.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $verdict The resolved verdict.
	 * @param array<string, mixed> $context The context.
	 * @param string               $id      The row id.
	 * @return void
	 */
	protected function render_detail_row( array $target, array $verdict, array $context, $id ) {
		$entry   = $context['index'][ $target['key'] ] ?? array();
		$summary = $entry['summary'] ?? array();
		$columns = count( $this->get_columns() ) + 1;

		printf(
			'<tr class="wvc-row-detail" id="%1$s" hidden><td colspan="%2$d">',
			esc_attr( $id ),
			(int) $columns
		);

		echo '<div class="wvc-detail">';

		echo UI::get_defs( $this->get_detail_rows( $target, $verdict, $context, $entry ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		$categories = array_filter( (array) ( $summary['by_category'] ?? array() ) );

		if ( ! empty( $categories ) ) {
			arsort( $categories );

			echo '<div class="wvc-detail__block">';
			echo '<h5 class="wvc-detail__title">' . esc_html__( 'What the findings are about', 'wp-vip-compatibility' ) . '</h5>';
			echo '<div class="wvc-chips">';

			foreach ( $categories as $category => $count ) {
				echo UI::get_meta_chip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					Taxonomy::get_label( 'category', $category ),
					number_format_i18n( (int) $count )
				);
			}

			echo '</div></div>';
		}

		if ( ! empty( $entry['truncated'] ) ) {
			echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				esc_html__( 'This item is large enough that the scan stopped early. Fix what is listed and rescan to see the rest.', 'wp-vip-compatibility' ),
				'info'
			);
		}

		$actions = array();

		if ( $verdict['total'] > 0 ) {
			$actions[] = array(
				'label'   => __( 'Open the findings', 'wp-vip-compatibility' ),
				'url'     => UI::get_findings_url( $target['key'] ),
				'icon'    => 'list',
				'primary' => true,
			);
		}

		if ( is_array( $verdict['known'] ) && ! empty( $verdict['known']['doc'] ) ) {
			$actions[] = array(
				'label'    => __( 'VIP documentation', 'wp-vip-compatibility' ),
				'url'      => $verdict['known']['doc'],
				'icon'     => 'external',
				'external' => true,
			);
		}

		if ( ! empty( $actions ) ) {
			echo '<div class="wvc-detail__actions">';

			foreach ( $actions as $action ) {
				echo UI::get_action_button( $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			}

			echo '</div>';
		}

		echo '</div>';
		echo '</td></tr>';
	}

	/**
	 * Builds the labelled values shown inside the detail drawer.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $verdict The resolved verdict.
	 * @param array<string, mixed> $context The context.
	 * @param array<string, mixed> $entry   The stored index entry.
	 * @return array<int, array<string, string>> Definition rows.
	 */
	protected function get_detail_rows( array $target, array $verdict, array $context, array $entry ) {
		unset( $context );

		$summary = $entry['summary'] ?? array();
		$rows    = array();

		if ( '' !== $target['author'] ) {
			$rows[] = array(
				'label' => __( 'Author', 'wp-vip-compatibility' ),
				'value' => $target['author'],
			);
		}

		$rows[] = array(
			'label' => __( 'Location', 'wp-vip-compatibility' ),
			'html'  => '<code>' . esc_html( $this->get_relative_path( $target ) ) . '</code>',
		);

		if ( ! empty( $entry ) ) {
			$rows[] = array(
				'label' => __( 'Code read', 'wp-vip-compatibility' ),
				'value' => sprintf(
					/* translators: %s: Number of PHP files. */
					_n( '%s PHP file', '%s PHP files', (int) ( $entry['files_scanned'] ?? 0 ), 'wp-vip-compatibility' ),
					number_format_i18n( (int) ( $entry['files_scanned'] ?? 0 ) )
				),
				'hint'  => empty( $entry['scanned_at'] ) ? '' : sprintf(
					/* translators: %s: Human-readable time difference. */
					__( 'Scanned %s ago', 'wp-vip-compatibility' ),
					human_time_diff( (int) $entry['scanned_at'] )
				),
			);
		}

		$severity_bar = UI::get_severity_bar( (array) ( $summary['by_severity'] ?? array() ), $target['key'] );

		if ( '' !== $severity_bar ) {
			$rows[] = array(
				'label' => __( 'Findings by importance', 'wp-vip-compatibility' ),
				'html'  => $severity_bar,
			);
		}

		if ( is_array( $verdict['known'] ) ) {
			$rows[] = array(
				'label' => __( 'On the VIP list', 'wp-vip-compatibility' ),
				'value' => $verdict['known']['label'] ?? '',
				'hint'  => $verdict['known']['reason'] ?? ( $verdict['known']['note'] ?? '' ),
				'tone'  => ( Known_Plugins::INCOMPATIBLE === ( $verdict['known']['classification'] ?? '' ) ) ? 'bad' : 'warn',
			);
		}

		return $rows;
	}

	/**
	 * Returns a target's path relative to the WordPress content directory.
	 *
	 * @param array<string, mixed> $target The target.
	 * @return string The relative path.
	 */
	protected function get_relative_path( array $target ) {
		$path    = str_replace( '\\', '/', (string) $target['path'] );
		$content = str_replace( '\\', '/', WP_CONTENT_DIR );

		if ( 0 === strpos( $path, $content ) ) {
			return 'wp-content' . substr( $path, strlen( $content ) );
		}

		return $path;
	}

	/**
	 * Builds the name cell: label, badges and the path it lives at.
	 *
	 * @param array<string, mixed> $target The target.
	 * @param array<int, string>   $badges Pre-built, already-escaped badge markup.
	 * @param string               $path   Optional path override.
	 * @return void
	 */
	protected function render_name_cell( array $target, array $badges = array(), $path = '' ) {
		echo '<td class="wvc-col-name" data-label="' . esc_attr__( 'Item', 'wp-vip-compatibility' ) . '">';
		echo '<span class="wvc-item">';
		echo '<span class="wvc-item__name">' . esc_html( $target['label'] );

		foreach ( $badges as $badge ) {
			echo ' ' . $badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller through UI::get_badge().
		}

		echo '</span>';
		echo '<code class="wvc-item__path">' . esc_html( '' === $path ? $target['file'] : $path ) . '</code>';
		echo '</span>';
		echo '</td>';
	}

	/**
	 * Builds the version cell.
	 *
	 * An available update is a marker on the current version rather than a column
	 * of its own: "up to date" repeated down a whole column is not information.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $context The context.
	 * @return void
	 */
	protected function render_version_cell( array $target, array $context ) {
		echo '<td class="wvc-col-version" data-label="' . esc_attr__( 'Version', 'wp-vip-compatibility' ) . '">';

		if ( '' === $target['version'] ) {
			echo '<span class="wvc-dash" aria-hidden="true">—</span>';
			echo '</td>';

			return;
		}

		echo '<span class="wvc-version">' . esc_html( $target['version'] ) . '</span>';

		$available = $this->get_available_version( $target, $context );

		if ( '' !== $available ) {
			printf(
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

		echo '</td>';
	}

	/**
	 * Returns the version WordPress has an update to, if any.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $context The context.
	 * @return string The available version, or an empty string.
	 */
	protected function get_available_version( array $target, array $context ) {
		if ( 'plugin' === $target['type'] && isset( $context['plugin_updates']->response[ $target['file'] ] ) ) {
			return (string) $context['plugin_updates']->response[ $target['file'] ]->new_version;
		}

		if ( 'theme' === $target['type'] && isset( $context['theme_updates']->response[ $target['slug'] ] ) ) {
			return (string) $context['theme_updates']->response[ $target['slug'] ]['new_version'];
		}

		return '';
	}

	/**
	 * Builds the findings cell: the severity split, or a dash.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $verdict The resolved verdict.
	 * @param array<string, mixed> $context The context.
	 * @return void
	 */
	protected function render_findings_cell( array $target, array $verdict, array $context ) {
		$entry   = $context['index'][ $target['key'] ] ?? array();
		$summary = (array) ( $entry['summary']['by_severity'] ?? array() );
		$bar     = UI::get_severity_bar( $summary, $target['key'] );

		echo '<td class="wvc-col-findings" data-label="' . esc_attr__( 'Findings', 'wp-vip-compatibility' ) . '">';

		if ( '' === $bar ) {
			echo ( 'pending' === $verdict['state'] )
				? '<span class="wvc-dash" aria-hidden="true">—</span>'
				: '<span class="wvc-clear">' . esc_html__( 'None', 'wp-vip-compatibility' ) . '</span>';
		} else {
			echo $bar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		}

		echo '</td>';
	}

	/**
	 * Builds the "what to do" cell.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $verdict The resolved verdict.
	 * @return void
	 */
	protected function render_note_cell( array $target, array $verdict ) {
		echo '<td class="wvc-col-notes" data-label="' . esc_attr__( 'What to do', 'wp-vip-compatibility' ) . '">'
			. wp_kses_post( UI::get_notes_list( $this->get_notes( $target, $verdict ) ) )
			. '</td>';
	}

	/**
	 * Builds the note fragments for one target.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $verdict The resolved verdict.
	 * @return array<int, string> Escaped note fragments.
	 */
	protected function get_notes( array $target, array $verdict ) {
		$parts = array();
		$known = $verdict['known'];

		if ( is_array( $known ) ) {
			$parts[] = $this->get_known_note(
				$this->join_known( $known['label'] ?? '', $known['reason'] ?? '' ),
				$known['doc'] ?? ''
			);
		}

		$parts[] = UI::get_findings_link( $target['key'], $verdict );

		return $parts;
	}

	/**
	 * Joins a curated classification to its reason without repeating it.
	 *
	 * Several entries in the curated list spell the classification out again in
	 * their reason — "Provided by the VIP platform — Provided by the VIP platform
	 * as a must-use plugin" — so the label is dropped whenever the reason already
	 * carries it.
	 *
	 * @param string $label  The classification label.
	 * @param string $reason The curated reason.
	 * @return string The note text.
	 */
	private function join_known( $label, $reason ) {
		if ( '' === $reason ) {
			return $label;
		}

		if ( '' === $label || false !== stripos( $reason, $label ) ) {
			return $reason;
		}

		return $label . ' — ' . $reason;
	}

	/**
	 * Builds a curated-list note, linked to its documentation when there is one.
	 *
	 * @param string $note The note text.
	 * @param string $doc  Optional documentation URL.
	 * @return string The escaped note markup.
	 */
	protected function get_known_note( $note, $doc ) {
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
