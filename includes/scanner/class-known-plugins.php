<?php
/**
 * Lookups against the curated plugin and must-use plugin lists.
 *
 * A plugin can be known to us in four ways, and they call for different
 * actions, so they are kept apart rather than collapsed into one
 * "incompatible" flag:
 *
 * - incompatible : VIP documents it as incompatible. Remove or replace it.
 * - caution      : works, but has a documented failure mode to test for.
 * - redundant    : the platform already provides it. Shipping it duplicates
 *                  or fights platform code.
 * - verified     : checked against VIP and found to behave.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

use WP_VIP_COMPATIBILITY\Includes\Classes\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies plugins and must-use plugins against the curated lists.
 */
class Known_Plugins {

	/**
	 * Classifications a plugin can carry.
	 */
	const INCOMPATIBLE = 'incompatible';
	const CAUTION      = 'caution';
	const REDUNDANT    = 'redundant';
	const VERIFIED     = 'verified';

	/**
	 * Classifies a plugin slug.
	 *
	 * @param string $slug The plugin directory slug.
	 * @return array<string, mixed>|null The classification, or null when unknown.
	 */
	public static function classify( $slug ) {
		$lists = (array) Plugin::get_instance()->get_reference_list( 'known_plugins' );

		foreach ( array( self::INCOMPATIBLE, self::CAUTION, self::REDUNDANT ) as $classification ) {
			$entries = (array) ( $lists[ $classification ] ?? array() );

			if ( isset( $entries[ $slug ] ) ) {
				return array(
					'classification' => $classification,
					'reason'         => (string) $entries[ $slug ],
					'label'          => self::label( $classification ),
					'severity'       => self::severity( $classification ),
					'doc'            => self::doc( $classification ),
				);
			}
		}

		$verified = (array) ( $lists['verified'] ?? $lists['tested_compatible_plugins'] ?? array() );

		if ( in_array( $slug, $verified, true ) ) {
			return array(
				'classification' => self::VERIFIED,
				'reason'         => __( 'Tested against the VIP Platform and found to behave correctly.', 'wp-vip-compatibility' ),
				'label'          => self::label( self::VERIFIED ),
				'severity'       => Taxonomy::SEVERITY_INFO,
				'doc'            => '',
			);
		}

		return null;
	}

	/**
	 * Returns the must-use plugin record for a slug, if there is one.
	 *
	 * @param string $slug The must-use plugin file or directory name.
	 * @return array<string, mixed>|null The record, or null when unknown.
	 */
	public static function mu_plugin( $slug ) {
		$known = (array) Plugin::get_instance()->get_reference_list( 'known_mu_plugins' );

		if ( isset( $known[ $slug ] ) ) {
			return $known[ $slug ];
		}

		// Loose files are listed with their extension; directories without.
		$alternate = self::has_php_extension( $slug ) ? substr( $slug, 0, -4 ) : $slug . '.php';

		return $known[ $alternate ] ?? null;
	}

	/**
	 * Whether a name ends in the PHP extension.
	 *
	 * @param string $slug The name.
	 * @return bool True when the name ends in `.php`.
	 */
	private static function has_php_extension( $slug ) {
		return '.php' === strtolower( substr( $slug, -4 ) );
	}

	/**
	 * Returns the label for a classification.
	 *
	 * @param string $classification One of the class constants.
	 * @return string The label.
	 */
	public static function label( $classification ) {
		switch ( $classification ) {
			case self::INCOMPATIBLE:
				return __( 'Listed incompatible by VIP', 'wp-vip-compatibility' );
			case self::CAUTION:
				return __( 'Needs testing on VIP', 'wp-vip-compatibility' );
			case self::REDUNDANT:
				return __( 'Provided by the VIP platform', 'wp-vip-compatibility' );
			default:
				return __( 'Verified on VIP', 'wp-vip-compatibility' );
		}
	}

	/**
	 * Returns the severity a classification implies.
	 *
	 * @param string $classification One of the class constants.
	 * @return string A Taxonomy severity.
	 */
	private static function severity( $classification ) {
		switch ( $classification ) {
			case self::INCOMPATIBLE:
				return Taxonomy::SEVERITY_HIGH;
			case self::CAUTION:
				return Taxonomy::SEVERITY_MEDIUM;
			case self::REDUNDANT:
				return Taxonomy::SEVERITY_LOW;
			default:
				return Taxonomy::SEVERITY_INFO;
		}
	}

	/**
	 * Returns the documentation link for a classification.
	 *
	 * @param string $classification One of the class constants.
	 * @return string The documentation URL.
	 */
	private static function doc( $classification ) {
		switch ( $classification ) {
			case self::REDUNDANT:
				return 'https://docs.wpvip.com/vip-go-mu-plugins/';
			default:
				return 'https://docs.wpvip.com/plugins/incompatibilities/';
		}
	}
}
