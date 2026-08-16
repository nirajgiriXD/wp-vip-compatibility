<?php
/**
 * The must-use plugins screen.
 *
 * This is the one inventory screen where the verdict is not really about the
 * code. VIP reserves wp-content/mu-plugins for its own platform code, so every
 * entry here needs a decision regardless of what the scanner finds in it: move
 * it to client-mu-plugins/, or drop it because VIP already provides it.
 *
 * Loose PHP files and directories are distinguished because WordPress only
 * auto-loads files at the root of mu-plugins — a directory sitting there is
 * usually loaded by one of those files, or not loaded at all.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Classes;

use WP_VIP_COMPATIBILITY\Includes\Traits\Singleton;
use WP_VIP_COMPATIBILITY\Includes\Scanner\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every must-use plugin with its VIP verdict.
 */
class Mu_Plugins_Settings extends Inventory_Screen {

	use Singleton;

	/**
	 * Returns the target type this screen lists.
	 *
	 * @return string The target type.
	 */
	protected function get_type() {
		return 'mu-plugin';
	}

	/**
	 * Returns the label for the search field.
	 *
	 * @return string The label.
	 */
	protected function get_search_label() {
		return __( 'Search must-use plugins', 'wp-vip-compatibility' );
	}

	/**
	 * Returns the panel title for the table.
	 *
	 * @return string The title.
	 */
	protected function get_table_title() {
		return __( 'Everything in wp-content/mu-plugins', 'wp-vip-compatibility' );
	}

	/**
	 * Returns the empty-state copy.
	 *
	 * @return array<int, string> Title and message.
	 */
	protected function get_empty_state() {
		return array(
			__( 'No must-use plugins', 'wp-vip-compatibility' ),
			__( 'wp-content/mu-plugins is empty, which is exactly what the VIP Platform expects.', 'wp-vip-compatibility' ),
		);
	}

	/**
	 * Explains the one rule the whole screen turns on.
	 *
	 * @param array<string, mixed> $context The context.
	 * @return void
	 */
	protected function render_intro( array $context ) {
		unset( $context );

		echo UI::get_notice( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			wp_kses_post(
				sprintf(
					/* translators: %s: Link to the VIP documentation. */
					__( 'On the VIP Platform, <code>wp-content/mu-plugins</code> holds platform code and is overwritten on every deploy. Everything listed here belongs in <code>client-mu-plugins/</code> instead — except the plugins VIP preinstalls, which should simply be dropped. %s', 'wp-vip-compatibility' ),
					'<a href="https://docs.wpvip.com/vip-go-mu-plugins/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'How VIP loads must-use code', 'wp-vip-compatibility' ) . '</a>'
				)
			),
			'warning',
			esc_html__( 'None of this migrates as-is', 'wp-vip-compatibility' )
		);
	}

	/**
	 * Returns the reference notes shown above the table.
	 *
	 * @return array<int, string> Note paragraphs.
	 */
	protected function get_guidance_notes() {
		return array(
			__( '<strong>Files and directories are listed separately.</strong> WordPress only auto-loads PHP files sitting at the root of mu-plugins. A directory here is either loaded by one of those files or not loaded at all — worth confirming before you move it.', 'wp-vip-compatibility' ),
			__( '<strong>"Do not migrate" is not a code verdict.</strong> It marks entries VIP already provides, or that a previous host installed. Copying them into client-mu-plugins/ duplicates platform behaviour rather than preserving it.', 'wp-vip-compatibility' ),
			__( '<strong>Load order changes.</strong> Files in client-mu-plugins/ load alphabetically after the platform\'s own must-use plugins, so code that assumed it ran first may need an explicit hook priority.', 'wp-vip-compatibility' ),
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
				'label'    => __( 'Entry', 'wp-vip-compatibility' ),
				'class'    => 'wvc-col-name',
				'sortable' => true,
			),
			array(
				'label'    => __( 'Kind', 'wp-vip-compatibility' ),
				'class'    => 'wvc-col-state',
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
	 * Adds the file/directory filter alongside the shared verdict filter.
	 *
	 * @param array<string, mixed> $context The context.
	 * @return array<int, array<string, mixed>> Filter groups.
	 */
	protected function get_extra_filters( array $context ) {
		unset( $context );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection of a client-side filter.
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'all';

		return array(
			array(
				'name'    => 'kind',
				'label'   => __( 'Filter by kind', 'wp-vip-compatibility' ),
				'active'  => in_array( $kind, array( 'file', 'directory' ), true ) ? $kind : 'all',
				'options' => array(
					array(
						'value' => 'all',
						'label' => __( 'Everything', 'wp-vip-compatibility' ),
					),
					array(
						'value' => 'file',
						'label' => __( 'Loaded files', 'wp-vip-compatibility' ),
					),
					array(
						'value' => 'directory',
						'label' => __( 'Directories', 'wp-vip-compatibility' ),
					),
				),
			),
		);
	}

	/**
	 * Tags each row with whether it is a loose file or a directory.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $context The context.
	 * @return array<string, string> Row attributes.
	 */
	protected function get_row_filters( array $target, array $context ) {
		unset( $context );

		return array( 'kind' => $this->is_file( $target ) ? 'file' : 'directory' );
	}

	/**
	 * Replaces the shared statistics with ones that fit the actual decision.
	 *
	 * Everything here has to move, so "ready / blocked" is the wrong split. What
	 * matters is how much there is, how much of it VIP already provides, and how
	 * much code comes with it.
	 *
	 * @param array<string, mixed> $context The context.
	 * @return array<int, array<string, mixed>> Stat tiles.
	 */
	protected function get_stats( array $context ) {
		$files       = 0;
		$directories = 0;
		$known       = 0;

		foreach ( $context['verdicts'] as $key => $verdict ) {
			$target = Targets::get( $key );

			if ( null === $target ) {
				continue;
			}

			if ( $this->is_file( $target ) ) {
				++$files;
			} else {
				++$directories;
			}

			if ( is_array( $verdict['known'] ) ) {
				++$known;
			}
		}

		return array(
			array(
				'label' => __( 'To relocate', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( (int) $context['counts']['total'] - $known ),
				'meta'  => __( 'Move into client-mu-plugins/', 'wp-vip-compatibility' ),
				'tone'  => ( $context['counts']['total'] - $known ) > 0 ? 'warn' : 'ok',
			),
			array(
				'label' => __( 'Drop instead', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( $known ),
				'meta'  => __( 'Preinstalled by VIP or left by a host', 'wp-vip-compatibility' ),
				'tone'  => 'neutral',
			),
			array(
				'label' => __( 'Auto-loaded files', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( $files ),
				'meta'  => sprintf(
					/* translators: %s: Number of directories. */
					_n( '%s directory alongside them', '%s directories alongside them', $directories, 'wp-vip-compatibility' ),
					number_format_i18n( $directories )
				),
				'tone'  => 'neutral',
			),
			array(
				'label' => __( 'Findings', 'wp-vip-compatibility' ),
				'value' => number_format_i18n( (int) $context['counts']['issues'] ),
				'meta'  => __( 'To resolve as part of the move', 'wp-vip-compatibility' ),
				'tone'  => 'accent',
				'url'   => $context['counts']['issues'] > 0 ? UI::get_findings_url() : '',
			),
		);
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
		$is_file = $this->is_file( $target );

		$this->render_name_cell( $target, array(), 'wp-content/mu-plugins/' . $target['file'] );

		echo '<td class="wvc-col-state" data-label="' . esc_attr__( 'Kind', 'wp-vip-compatibility' ) . '">';
		echo UI::get_badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			$is_file ? __( 'File', 'wp-vip-compatibility' ) : __( 'Directory', 'wp-vip-compatibility' ),
			$is_file ? 'accent' : 'neutral',
			$is_file
				? __( 'WordPress auto-loads this file on every request.', 'wp-vip-compatibility' )
				: __( 'Not auto-loaded. Something else has to require it, or nothing does.', 'wp-vip-compatibility' )
		);
		echo '</td>';

		$this->render_version_cell( $target, $context );

		echo UI::get_verdict_cell( $verdict, $target['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.

		$this->render_findings_cell( $target, $verdict, $context );
		$this->render_note_cell( $target, $verdict );
	}

	/**
	 * Builds the note fragments for one entry.
	 *
	 * The curated must-use list stores a plain note rather than the
	 * classification-and-reason pair the plugin list uses, so this overrides the
	 * shared version rather than reshaping the data to fit it.
	 *
	 * @param array<string, mixed> $target  The target.
	 * @param array<string, mixed> $verdict The resolved verdict.
	 * @return array<int, string> Escaped note fragments.
	 */
	protected function get_notes( array $target, array $verdict ) {
		$known = $verdict['known'];

		$parts = array(
			is_array( $known )
				? $this->get_known_note( $known['note'] ?? '', $known['doc'] ?? '' )
				: esc_html__( 'Move it into client-mu-plugins/ in the VIP repository.', 'wp-vip-compatibility' ),
			UI::get_findings_link( $target['key'], $verdict ),
		);

		return $parts;
	}

	/**
	 * Whether an entry is a loose PHP file rather than a directory.
	 *
	 * @param array<string, mixed> $target The target.
	 * @return bool True when the entry is a file.
	 */
	private function is_file( array $target ) {
		return '.php' === strtolower( substr( (string) $target['file'], -4 ) );
	}
}
