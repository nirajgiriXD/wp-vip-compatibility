<?php
/**
 * Shared vocabulary for scanner findings.
 *
 * Every finding is described along four independent axes so that the report can
 * separate "this will break" from "this might break" from "this is merely a
 * house-style violation":
 *
 * - Type       what kind of problem it is (incompatible, performance, security...).
 * - Severity   how much it matters (critical -> info).
 * - Confidence how sure static analysis can be (definitive -> low).
 * - Fixability what kind of work the fix needs (automatic -> architectural).
 *
 * Keeping these separate is deliberate: a `security` finding can be low
 * severity, and a `critical` finding can still be low confidence. Collapsing
 * them into a single "incompatible" flag is what produced the false positives
 * in the original implementation.
 *
 * @package switchrail-audit
 */

namespace SWITCHRAIL_AUDIT\Includes\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Enumerates and labels the finding classification axes.
 */
class Taxonomy {

	/**
	 * Finding types.
	 */
	const TYPE_INCOMPATIBLE           = 'incompatible';
	const TYPE_POTENTIALLY_INCOMPATIBLE = 'potentially-incompatible';
	const TYPE_PERFORMANCE            = 'performance';
	const TYPE_SECURITY               = 'security';
	const TYPE_REDUNDANT              = 'redundant';
	const TYPE_STANDARDS              = 'standards';
	const TYPE_RECOMMENDATION         = 'recommendation';
	const TYPE_INFORMATIONAL          = 'informational';

	/**
	 * Severities, ordered most to least serious.
	 */
	const SEVERITY_CRITICAL = 'critical';
	const SEVERITY_HIGH     = 'high';
	const SEVERITY_MEDIUM   = 'medium';
	const SEVERITY_LOW      = 'low';
	const SEVERITY_INFO     = 'info';

	/**
	 * Detection confidence.
	 */
	const CONFIDENCE_DEFINITIVE = 'definitive';
	const CONFIDENCE_HIGH       = 'high';
	const CONFIDENCE_MEDIUM     = 'medium';
	const CONFIDENCE_LOW        = 'low';

	/**
	 * How a finding can be resolved.
	 */
	const FIX_AUTOMATIC     = 'automatic';
	const FIX_MANUAL        = 'manual';
	const FIX_CONFIGURATION = 'configuration';
	const FIX_ARCHITECTURAL = 'architectural';

	/**
	 * Consequence tiers.
	 *
	 * A severity says how bad a rule hit is; a tier says what the reader should
	 * do about it, which is what the interface leads with. Every screen uses
	 * these four labels and only these four, so "critical", "blocker", "needs
	 * attention" and "warning" cannot drift apart between screens.
	 *
	 * The labels are instructions rather than classifications — "Must fix", not
	 * "Blocking" — because a label that says what to do needs no legend, and the
	 * classification it came from is still one disclosure away on every finding.
	 * The slugs stay as they were: they are in stored results, bookmarked URLs
	 * and CSS, none of which should move because the wording did.
	 */
	const TIER_BLOCKING  = 'blocking';
	const TIER_IMPORTANT = 'important';
	const TIER_WARNING   = 'warning';
	const TIER_INFO      = 'info';

	/**
	 * Returns the type definitions, keyed by type.
	 *
	 * `blocking` marks the types that stop a migration rather than merely
	 * degrade it; the readiness score weights them accordingly.
	 *
	 * @return array<string, array<string, mixed>> Type definitions.
	 */
	public static function get_types() {
		return array(
			self::TYPE_INCOMPATIBLE             => array(
				'label'       => __( 'Incompatible', 'switchrail-audit' ),
				'description' => __( 'Likely to fail or behave unexpectedly on the WordPress VIP platform.', 'switchrail-audit' ),
				'blocking'    => true,
			),
			self::TYPE_POTENTIALLY_INCOMPATIBLE => array(
				'label'       => __( 'Potentially incompatible', 'switchrail-audit' ),
				'description' => __( 'Depends on runtime conditions static analysis cannot resolve. Needs a human check.', 'switchrail-audit' ),
				'blocking'    => true,
			),
			self::TYPE_PERFORMANCE              => array(
				'label'       => __( 'Performance concern', 'switchrail-audit' ),
				'description' => __( 'Works on WordPress VIP, but can create unacceptable performance characteristics at scale.', 'switchrail-audit' ),
				'blocking'    => false,
			),
			self::TYPE_SECURITY                 => array(
				'label'       => __( 'Security concern', 'switchrail-audit' ),
				'description' => __( 'Flagged by the WordPress VIP code review process as a security risk.', 'switchrail-audit' ),
				'blocking'    => false,
			),
			self::TYPE_REDUNDANT                => array(
				'label'       => __( 'Redundant on WordPress VIP', 'switchrail-audit' ),
				'description' => __( 'The platform already provides this capability; the local implementation can conflict with it.', 'switchrail-audit' ),
				'blocking'    => false,
			),
			self::TYPE_STANDARDS                => array(
				'label'       => __( 'Coding-standard violation', 'switchrail-audit' ),
				'description' => __( 'Reported by the WordPress-VIP-Go PHPCS standard used in WordPress VIP code review.', 'switchrail-audit' ),
				'blocking'    => false,
			),
			self::TYPE_RECOMMENDATION           => array(
				'label'       => __( 'Recommendation', 'switchrail-audit' ),
				'description' => __( 'A small improvement worth considering.', 'switchrail-audit' ),
				'blocking'    => false,
			),
			self::TYPE_INFORMATIONAL            => array(
				'label'       => __( 'Informational', 'switchrail-audit' ),
				'description' => __( 'Useful context. No action necessarily required.', 'switchrail-audit' ),
				'blocking'    => false,
			),
		);
	}

	/**
	 * Returns the severity definitions, ordered most to least serious.
	 *
	 * @return array<string, array<string, mixed>> Severity definitions.
	 */
	public static function get_severities() {
		return array(
			self::SEVERITY_CRITICAL => array(
				'label'  => __( 'Critical', 'switchrail-audit' ),
				'weight' => 5,
			),
			self::SEVERITY_HIGH     => array(
				'label'  => __( 'High', 'switchrail-audit' ),
				'weight' => 4,
			),
			self::SEVERITY_MEDIUM   => array(
				'label'  => __( 'Medium', 'switchrail-audit' ),
				'weight' => 3,
			),
			self::SEVERITY_LOW      => array(
				'label'  => __( 'Low', 'switchrail-audit' ),
				'weight' => 2,
			),
			self::SEVERITY_INFO     => array(
				'label'  => __( 'Info', 'switchrail-audit' ),
				'weight' => 1,
			),
		);
	}

	/**
	 * Returns the tier definitions, most serious first.
	 *
	 * @return array<string, array<string, mixed>> Tier definitions keyed by tier.
	 */
	public static function get_tiers() {
		return array(
			self::TIER_BLOCKING  => array(
				'label'      => __( 'Must fix', 'switchrail-audit' ),
				'summary'    => __( 'Likely to cause problems on the WordPress VIP platform. Best fixed first.', 'switchrail-audit' ),
				'severities' => array( self::SEVERITY_CRITICAL ),
			),
			self::TIER_IMPORTANT => array(
				'label'      => __( 'Should fix', 'switchrail-audit' ),
				'summary'    => __( 'Unlikely to break anything, but worth fixing soon.', 'switchrail-audit' ),
				'severities' => array( self::SEVERITY_HIGH ),
			),
			self::TIER_WARNING   => array(
				'label'      => __( 'Worth checking', 'switchrail-audit' ),
				'summary'    => __( 'A possible concern. Read it, decide, and move on.', 'switchrail-audit' ),
				'severities' => array( self::SEVERITY_MEDIUM ),
			),
			self::TIER_INFO      => array(
				'label'      => __( 'FYI', 'switchrail-audit' ),
				'summary'    => __( 'For context. No action needed.', 'switchrail-audit' ),
				'severities' => array( self::SEVERITY_LOW, self::SEVERITY_INFO ),
			),
		);
	}

	/**
	 * Returns the tier a severity belongs to.
	 *
	 * @param string $severity A severity slug.
	 * @return string The tier slug.
	 */
	public static function get_tier( $severity ) {
		foreach ( self::get_tiers() as $tier => $definition ) {
			if ( in_array( $severity, $definition['severities'], true ) ) {
				return $tier;
			}
		}

		return self::TIER_INFO;
	}

	/**
	 * Returns the severities a tier covers.
	 *
	 * @param string $tier A tier slug.
	 * @return string[] Severity slugs, empty when the tier is unknown.
	 */
	public static function get_tier_severities( $tier ) {
		$tiers = self::get_tiers();

		return $tiers[ $tier ]['severities'] ?? array();
	}

	/**
	 * Returns the confidence definitions.
	 *
	 * @return array<string, array<string, mixed>> Confidence definitions.
	 */
	public static function get_confidences() {
		return array(
			self::CONFIDENCE_DEFINITIVE => array(
				'label'       => __( 'Definitive', 'switchrail-audit' ),
				'description' => __( 'The pattern itself is the problem; no further context can make it acceptable.', 'switchrail-audit' ),
			),
			self::CONFIDENCE_HIGH       => array(
				'label'       => __( 'High', 'switchrail-audit' ),
				'description' => __( 'Surrounding code was analysed and supports the finding.', 'switchrail-audit' ),
			),
			self::CONFIDENCE_MEDIUM     => array(
				'label'       => __( 'Medium', 'switchrail-audit' ),
				'description' => __( 'Heuristic match. Review the code before acting on it.', 'switchrail-audit' ),
			),
			self::CONFIDENCE_LOW        => array(
				'label'       => __( 'Low', 'switchrail-audit' ),
				'description' => __( 'Weak signal, reported for completeness. Expect false positives.', 'switchrail-audit' ),
			),
		);
	}

	/**
	 * Returns the fixability definitions.
	 *
	 * @return array<string, array<string, mixed>> Fixability definitions.
	 */
	public static function get_fixabilities() {
		return array(
			self::FIX_AUTOMATIC     => array(
				'label'       => __( 'Automatically fixable', 'switchrail-audit' ),
				'short'       => __( 'Quick fix', 'switchrail-audit' ),
				'description' => __( 'A mechanical substitution resolves it.', 'switchrail-audit' ),
			),
			self::FIX_MANUAL        => array(
				'label'       => __( 'Manually fixable', 'switchrail-audit' ),
				'short'       => __( 'Code change', 'switchrail-audit' ),
				'description' => __( 'A developer must rewrite the affected code.', 'switchrail-audit' ),
			),
			self::FIX_CONFIGURATION => array(
				'label'       => __( 'Configurable', 'switchrail-audit' ),
				'short'       => __( 'Config change', 'switchrail-audit' ),
				'description' => __( 'Resolved through configuration, a constant, or a filter rather than a code change.', 'switchrail-audit' ),
			),
			self::FIX_ARCHITECTURAL => array(
				'label'       => __( 'Requires architectural change', 'switchrail-audit' ),
				'short'       => __( 'Redesign', 'switchrail-audit' ),
				'description' => __( 'The feature has to be redesigned around the platform.', 'switchrail-audit' ),
			),
		);
	}

	/**
	 * Returns the short form of a fixability, for use as a chip.
	 *
	 * The full labels are sentences — "Requires architectural change" — which is
	 * right in a definition list and wrong on a card that already carries a title
	 * and a target. This is the two-word answer to "how much work is this?".
	 *
	 * @param string $fixability A fixability slug.
	 * @return string The short label, or an empty string when unknown.
	 */
	public static function get_fixability_short( $fixability ) {
		$fixabilities = self::get_fixabilities();

		return $fixabilities[ $fixability ]['short'] ?? '';
	}

	/**
	 * Returns the finding categories used to group the report.
	 *
	 * @return array<string, string> Category labels keyed by category slug.
	 */
	public static function get_categories() {
		return array(
			'filesystem'  => __( 'Filesystem and media', 'switchrail-audit' ),
			'database'    => __( 'Database', 'switchrail-audit' ),
			'caching'     => __( 'Caching', 'switchrail-audit' ),
			'performance' => __( 'Performance', 'switchrail-audit' ),
			'security'    => __( 'Security', 'switchrail-audit' ),
			'cron'        => __( 'Cron and scheduling', 'switchrail-audit' ),
			'http'        => __( 'External requests', 'switchrail-audit' ),
			'environment' => __( 'Environment and configuration', 'switchrail-audit' ),
			'platform'    => __( 'Platform overlap', 'switchrail-audit' ),
			'standards'   => __( 'Coding standards', 'switchrail-audit' ),
		);
	}

	/**
	 * Returns the numeric weight of a severity, for sorting and scoring.
	 *
	 * @param string $severity Severity slug.
	 * @return int The weight, or 0 when unknown.
	 */
	public static function get_severity_weight( $severity ) {
		$severities = self::get_severities();

		return isset( $severities[ $severity ] ) ? (int) $severities[ $severity ]['weight'] : 0;
	}

	/**
	 * Whether a finding type blocks a migration.
	 *
	 * @param string $type Type slug.
	 * @return bool True when the type is a migration blocker.
	 */
	public static function is_blocking_type( $type ) {
		$types = self::get_types();

		return ! empty( $types[ $type ]['blocking'] );
	}

	/**
	 * Returns the label for a value on one of the axes.
	 *
	 * @param string $axis  One of `type`, `severity`, `confidence`, `fixability`, `category`.
	 * @param string $value The value to label.
	 * @return string The human-readable label, or the raw value when unknown.
	 */
	public static function get_label( $axis, $value ) {
		switch ( $axis ) {
			case 'type':
				$map = self::get_types();
				break;
			case 'severity':
				$map = self::get_severities();
				break;
			case 'confidence':
				$map = self::get_confidences();
				break;
			case 'fixability':
				$map = self::get_fixabilities();
				break;
			case 'category':
				$categories = self::get_categories();
				return $categories[ $value ] ?? $value;
			default:
				return $value;
		}

		return isset( $map[ $value ]['label'] ) ? $map[ $value ]['label'] : $value;
	}
}
