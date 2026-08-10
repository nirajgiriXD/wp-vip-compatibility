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
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

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
				'label'       => __( 'Incompatible', 'wp-vip-compatibility' ),
				'description' => __( 'Expected to fail or behave incorrectly on the VIP Platform.', 'wp-vip-compatibility' ),
				'blocking'    => true,
			),
			self::TYPE_POTENTIALLY_INCOMPATIBLE => array(
				'label'       => __( 'Potentially incompatible', 'wp-vip-compatibility' ),
				'description' => __( 'Depends on runtime conditions static analysis cannot resolve. Needs a human check.', 'wp-vip-compatibility' ),
				'blocking'    => true,
			),
			self::TYPE_PERFORMANCE              => array(
				'label'       => __( 'Performance concern', 'wp-vip-compatibility' ),
				'description' => __( 'Works on VIP, but can create unacceptable performance characteristics at scale.', 'wp-vip-compatibility' ),
				'blocking'    => false,
			),
			self::TYPE_SECURITY                 => array(
				'label'       => __( 'Security concern', 'wp-vip-compatibility' ),
				'description' => __( 'Flagged by the VIP code review process as a security risk.', 'wp-vip-compatibility' ),
				'blocking'    => false,
			),
			self::TYPE_REDUNDANT                => array(
				'label'       => __( 'Redundant on VIP', 'wp-vip-compatibility' ),
				'description' => __( 'The platform already provides this capability; the local implementation can conflict with it.', 'wp-vip-compatibility' ),
				'blocking'    => false,
			),
			self::TYPE_STANDARDS                => array(
				'label'       => __( 'Coding-standard violation', 'wp-vip-compatibility' ),
				'description' => __( 'Reported by the WordPress-VIP-Go PHPCS standard used in VIP code review.', 'wp-vip-compatibility' ),
				'blocking'    => false,
			),
			self::TYPE_RECOMMENDATION           => array(
				'label'       => __( 'Recommendation', 'wp-vip-compatibility' ),
				'description' => __( 'Not a blocker, but worth changing before migrating.', 'wp-vip-compatibility' ),
				'blocking'    => false,
			),
			self::TYPE_INFORMATIONAL            => array(
				'label'       => __( 'Informational', 'wp-vip-compatibility' ),
				'description' => __( 'Context that helps plan the migration. No action necessarily required.', 'wp-vip-compatibility' ),
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
				'label'  => __( 'Critical', 'wp-vip-compatibility' ),
				'weight' => 5,
			),
			self::SEVERITY_HIGH     => array(
				'label'  => __( 'High', 'wp-vip-compatibility' ),
				'weight' => 4,
			),
			self::SEVERITY_MEDIUM   => array(
				'label'  => __( 'Medium', 'wp-vip-compatibility' ),
				'weight' => 3,
			),
			self::SEVERITY_LOW      => array(
				'label'  => __( 'Low', 'wp-vip-compatibility' ),
				'weight' => 2,
			),
			self::SEVERITY_INFO     => array(
				'label'  => __( 'Info', 'wp-vip-compatibility' ),
				'weight' => 1,
			),
		);
	}

	/**
	 * Returns the confidence definitions.
	 *
	 * @return array<string, array<string, mixed>> Confidence definitions.
	 */
	public static function get_confidences() {
		return array(
			self::CONFIDENCE_DEFINITIVE => array(
				'label'       => __( 'Definitive', 'wp-vip-compatibility' ),
				'description' => __( 'The pattern itself is the problem; no further context can make it acceptable.', 'wp-vip-compatibility' ),
			),
			self::CONFIDENCE_HIGH       => array(
				'label'       => __( 'High', 'wp-vip-compatibility' ),
				'description' => __( 'Surrounding code was analysed and supports the finding.', 'wp-vip-compatibility' ),
			),
			self::CONFIDENCE_MEDIUM     => array(
				'label'       => __( 'Medium', 'wp-vip-compatibility' ),
				'description' => __( 'Heuristic match. Review the code before acting on it.', 'wp-vip-compatibility' ),
			),
			self::CONFIDENCE_LOW        => array(
				'label'       => __( 'Low', 'wp-vip-compatibility' ),
				'description' => __( 'Weak signal, reported for completeness. Expect false positives.', 'wp-vip-compatibility' ),
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
				'label'       => __( 'Automatically fixable', 'wp-vip-compatibility' ),
				'description' => __( 'A mechanical substitution resolves it.', 'wp-vip-compatibility' ),
			),
			self::FIX_MANUAL        => array(
				'label'       => __( 'Manually fixable', 'wp-vip-compatibility' ),
				'description' => __( 'A developer must rewrite the affected code.', 'wp-vip-compatibility' ),
			),
			self::FIX_CONFIGURATION => array(
				'label'       => __( 'Configurable', 'wp-vip-compatibility' ),
				'description' => __( 'Resolved through configuration, a constant, or a filter rather than a code change.', 'wp-vip-compatibility' ),
			),
			self::FIX_ARCHITECTURAL => array(
				'label'       => __( 'Requires architectural change', 'wp-vip-compatibility' ),
				'description' => __( 'The feature has to be redesigned around the platform.', 'wp-vip-compatibility' ),
			),
		);
	}

	/**
	 * Returns the finding categories used to group the report.
	 *
	 * @return array<string, string> Category labels keyed by category slug.
	 */
	public static function get_categories() {
		return array(
			'filesystem'  => __( 'Filesystem and media', 'wp-vip-compatibility' ),
			'database'    => __( 'Database', 'wp-vip-compatibility' ),
			'caching'     => __( 'Caching', 'wp-vip-compatibility' ),
			'performance' => __( 'Performance', 'wp-vip-compatibility' ),
			'security'    => __( 'Security', 'wp-vip-compatibility' ),
			'cron'        => __( 'Cron and scheduling', 'wp-vip-compatibility' ),
			'http'        => __( 'External requests', 'wp-vip-compatibility' ),
			'environment' => __( 'Environment and configuration', 'wp-vip-compatibility' ),
			'platform'    => __( 'Platform overlap', 'wp-vip-compatibility' ),
			'standards'   => __( 'Coding standards', 'wp-vip-compatibility' ),
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
