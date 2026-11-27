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
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Includes\Classes;

use SWITCHRAIL_AUDIT\Includes\Scanner\Known_Plugins;
use SWITCHRAIL_AUDIT\Includes\Scanner\Report;
use SWITCHRAIL_AUDIT\Includes\Scanner\Results_Store;
use SWITCHRAIL_AUDIT\Includes\Scanner\Scanner;
use SWITCHRAIL_AUDIT\Includes\Scanner\Targets;
use SWITCHRAIL_AUDIT\Includes\Scanner\Taxonomy;

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
	 * Returns the reference notes shown in the WordPress Help tab.
	 *
	 * @return array<int, string> Note paragraphs, already containing limited HTML.
	 */
	protected function get_guidance_notes() {
		return array();
	}

	/**
	 * Returns the notes shown in the WordPress Help tab.
	 *
	 * @return array<int, string> Note paragraphs, already containing limited HTML.
	 */
	public function get_page_guidance() {
		return $this->get_guidance_notes();
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
	 * Renders anything that belongs above the table.
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
	 * Returns the accessible name of the table.
	 *
	 * @return string The title.
	 */
	abstract protected function get_table_title();

	/*
	 * ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------
	 */

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

		$this->render_intro( $context );

		// No heading: the page title already names the list. The title still
		// names the table for assistive technology.
		UI::render_panel_open( array( 'flush' => true ) );

		UI::render_toolbar(
			array(
				'search_label'  => $this->get_search_label(),
				'show_progress' => true,
				'groups'        => array_merge( $this->get_extra_filters( $context ), array( $this->get_status_filter() ) ),
			)
		);

		echo '<div class="switchrail-audit-table-wrap" data-role="table-view">';
		echo '<table class="switchrail-audit-table switchrail-audit-table--expandable" aria-label="' . esc_attr( $this->get_table_title() ) . '">';

		UI::render_table_head(
			array_merge(
				array(
					array(
						'label' => '',
						'class' => 'switchrail-audit-col-toggle',
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
		$index    = Results_Store::get_index();
		$verdicts = array();

		foreach ( $targets as $key => $target ) {
			$verdicts[ $key ] = Report::verdict( $target, $index );
		}

		return array(
			'index'          => $index,
			'verdicts'       => $verdicts,
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
			'label'   => __( 'Filter by verdict', 'switchrail-audit' ),
			'active'  => $this->read_status(),
			'options' => array(
				array(
					'value' => 'all',
					'label' => __( 'All verdicts', 'switchrail-audit' ),
				),
				array(
					'value' => 'not-compatible',
					'label' => __( 'Needs work', 'switchrail-audit' ),
					'dot'   => 'bad',
				),
				array(
					'value' => 'review',
					'label' => __( 'Review', 'switchrail-audit' ),
					'dot'   => 'warn',
				),
				array(
					'value' => 'compatible',
					'label' => __( 'Passing', 'switchrail-audit' ),
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
		$detail  = UI::next_id( 'switchrail-audit-detail' );

		$attributes = ' data-status="' . esc_attr( $verdict['state'] ) . '"';

		foreach ( $this->get_row_filters( $target, $context ) as $name => $value ) {
			$attributes .= sprintf( ' data-%s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		echo '<tr class="switchrail-audit-row"' . $attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		echo UI::get_row_toggle(
			$detail,
			sprintf(
				/* translators: %s: Item name. */
				__( 'Show details for %s', 'switchrail-audit' ),
				$target['label']
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

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
			'<tr class="switchrail-audit-row-detail" id="%1$s" hidden><td colspan="%2$d">',
			esc_attr( $id ),
			(int) $columns
		);

		/*
		 * A card of its own, so the drawer reads as a separate thing from the
		 * row above it: the facts on the left, what the findings are about on
		 * the right.
		 */
		echo '<div class="switchrail-audit-detail"><div class="switchrail-audit-detail__card">';

		echo '<section class="switchrail-audit-detail__section">';
		echo '<h3 class="switchrail-audit-detail__title">' . esc_html__( 'Details', 'switchrail-audit' ) . '</h3>';
		echo UI::get_defs( $this->get_detail_rows( $target, $verdict, $context, $entry ), 'switchrail-audit-defs--detail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		// No findings button here: the row's own "Review N findings" link already goes there.
		if ( is_array( $verdict['known'] ) && ! empty( $verdict['known']['doc'] ) ) {
			echo '<div class="switchrail-audit-detail__actions">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			echo UI::get_action_button(
				array(
					'label'    => __( 'WordPress VIP documentation', 'switchrail-audit' ),
					'url'      => $verdict['known']['doc'],
					'icon'     => 'external',
					'external' => true,
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		}

		echo '</section>';

		echo '<section class="switchrail-audit-detail__section">';
		echo '<h3 class="switchrail-audit-detail__title">' . esc_html__( 'What the findings are about', 'switchrail-audit' ) . '</h3>';

		$this->render_detail_topics( $target, $entry, (array) ( $summary['by_category'] ?? array() ) );

		if ( ! empty( $entry['truncated'] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			echo UI::get_notice(
				esc_html__( 'This item is large enough that the scan stopped early. Fix what is listed and rescan to see the rest.', 'switchrail-audit' ),
				'info'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</section>';

		echo '</div></div>';
		echo '</td></tr>';
	}

	/**
	 * Renders the findings by topic: one line each, biggest first, with a bar
	 * scaled to the largest and a link to just those findings.
	 *
	 * @param array<string, mixed> $target     The target.
	 * @param array<string, mixed> $entry      The stored index entry.
	 * @param array<string, int>   $categories Finding counts keyed by category.
	 * @return void
	 */
	protected function render_detail_topics( array $target, array $entry, array $categories ) {
		$categories = array_filter( array_map( 'intval', $categories ) );

		if ( empty( $categories ) ) {
			$message = empty( $entry )
				? __( 'Not scanned yet.', 'switchrail-audit' )
				: __( 'No findings. Nothing to do here.', 'switchrail-audit' );

			printf(
				'<p class="switchrail-audit-detail__empty%1$s">%2$s%3$s</p>',
				empty( $entry ) ? '' : ' is-clear',
				empty( $entry ) ? '' : UI::get_icon( 'check', array( 'class' => 'switchrail-audit-icon switchrail-audit-icon--xs' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
				esc_html( $message )
			);

			return;
		}

		arsort( $categories );

		$largest = max( $categories );
		$base    = UI::get_findings_url( $target['key'] );

		echo '<ul class="switchrail-audit-topics">';

		foreach ( $categories as $category => $count ) {
			printf(
				'<li><a class="switchrail-audit-topics__link" href="%1$s"><span class="switchrail-audit-topics__name">%2$s</span><span class="switchrail-audit-topics__bar" aria-hidden="true"><span style="width:%3$d%%"></span></span><span class="switchrail-audit-topics__count">%4$s</span></a></li>',
				esc_url( add_query_arg( 'category', $category, $base ) ),
				esc_html( Taxonomy::get_label( 'category', $category ) ),
				(int) max( 6, round( $count / $largest * 100 ) ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		echo '</ul>';
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

		$rows = array();

		if ( '' !== $target['author'] ) {
			$rows[] = array(
				'label' => __( 'Author', 'switchrail-audit' ),
				'value' => $target['author'],
			);
		}

		// A long path may only wrap after a slash, never mid-name.
		$rows[] = array(
			'label' => __( 'Location', 'switchrail-audit' ),
			'html'  => '<code>' . implode( '/<wbr>', array_map( 'esc_html', explode( '/', $this->get_relative_path( $target ) ) ) ) . '</code>',
		);

		if ( ! empty( $entry ) ) {
			$rows[] = array(
				'label' => __( 'Code read', 'switchrail-audit' ),
				'value' => sprintf(
					/* translators: %s: Number of PHP files. */
					_n( '%s PHP file', '%s PHP files', (int) ( $entry['files_scanned'] ?? 0 ), 'switchrail-audit' ),
					number_format_i18n( (int) ( $entry['files_scanned'] ?? 0 ) )
				),
				'hint'  => empty( $entry['scanned_at'] ) ? '' : sprintf(
					/* translators: %s: Human-readable time difference. */
					__( 'Scanned %s ago', 'switchrail-audit' ),
					human_time_diff( (int) $entry['scanned_at'] )
				),
			);
		}

		// No findings-by-importance row: the row above already shows those counts.

		if ( is_array( $verdict['known'] ) ) {
			/*
			 * The curated plugin list carries a classification and a reason; the
			 * must-use list carries only a note. Reading the pair blindly left the
			 * must-use drawer showing this label above an empty value, so the note
			 * becomes the value when there is no classification to name.
			 */
			$known  = $verdict['known'];
			$reason = $known['reason'] ?? ( $known['note'] ?? '' );
			$label  = $known['label'] ?? '';

			$rows[] = array(
				'label' => __( 'On the WordPress VIP list', 'switchrail-audit' ),
				'value' => ( '' === $label ) ? $reason : $label,
				'hint'  => ( '' === $label ) ? '' : $reason,
				'tone'  => ( Known_Plugins::INCOMPATIBLE === ( $known['classification'] ?? '' ) ) ? 'bad' : 'warn',
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
		echo '<td class="switchrail-audit-col-name" data-label="' . esc_attr__( 'Item', 'switchrail-audit' ) . '">';
		echo '<span class="switchrail-audit-item">';
		echo '<span class="switchrail-audit-item__name">' . esc_html( $target['label'] );

		foreach ( $badges as $badge ) {
			echo ' ' . $badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller through UI::get_badge().
		}

		echo '</span>';
		echo '<code class="switchrail-audit-item__path">' . esc_html( '' === $path ? $target['file'] : $path ) . '</code>';
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
		echo '<td class="switchrail-audit-col-version" data-label="' . esc_attr__( 'Version', 'switchrail-audit' ) . '">';

		if ( '' === $target['version'] ) {
			echo '<span class="switchrail-audit-dash" aria-hidden="true">—</span>';
			echo '</td>';

			return;
		}

		echo '<span class="switchrail-audit-version">' . esc_html( $target['version'] ) . '</span>';

		$available = $this->get_available_version( $target, $context );

		if ( '' !== $available ) {
			printf(
				'<span class="switchrail-audit-version__update" title="%1$s">%2$s</span>',
				esc_attr__( 'An update is available. Update first, so the scan reads the code you will actually ship.', 'switchrail-audit' ),
				esc_html(
					sprintf(
						/* translators: %s: Version number. */
						__( '→ %s', 'switchrail-audit' ),
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

		echo '<td class="switchrail-audit-col-findings" data-label="' . esc_attr__( 'Findings', 'switchrail-audit' ) . '">';

		if ( '' === $bar ) {
			echo ( 'pending' === $verdict['state'] )
				? '<span class="switchrail-audit-dash" aria-hidden="true">—</span>'
				: '<span class="switchrail-audit-clear">' . esc_html__( 'None', 'switchrail-audit' ) . '</span>';
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
		// Not run through wp_kses_post(): the fragments are already escaped by the
		// helpers that build them, and kses strips the inline <svg> arrow out of
		// the "Fix N findings" link, leaving the one call to action without its
		// affordance on every row.
		echo '<td class="switchrail-audit-col-notes" data-label="' . esc_attr__( 'What to do', 'switchrail-audit' ) . '">'
			. UI::get_notes_list( $this->get_notes( $target, $verdict ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every fragment is escaped by get_known_note(), UI::get_findings_link() or esc_html() before it reaches here.
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
	 * their reason — "Provided by the WordPress VIP platform — Provided by the WordPress VIP platform
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
			'<a class="switchrail-audit-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $doc ),
			esc_html( $note )
		);
	}
}
