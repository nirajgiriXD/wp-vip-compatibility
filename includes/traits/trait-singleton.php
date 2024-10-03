<?php
/**
 * Singleton Trait
 *
 * This trait implements the Singleton design pattern.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Traits;

trait Singleton {
	/**
	 * Holds the singleton instance of the class.
	 *
	 * @var object|null
	 */
	private static $instance = null;

	/**
	 * Contructor.
	 *
	 * Private to prevent direct creation of object.
	 */
	private function __construct() {
		// Initialize any necessary resources.
	}

	/**
	 * Retrieves the singleton instance of the class, if the instance does not exist, it is created.
	 *
	 * @return object The singleton instance of the class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new static();
		}
		return self::$instance;
	}
}