<?php
/**
 * In-memory state behind the fake WordPress functions.
 *
 * @package cutover-audit
 */

namespace CUTOVER_AUDIT\Tests\Support;

/**
 * Holds everything the fake WordPress layer reads and writes.
 *
 * Options are kept per blog so that multisite code paths, which switch blogs
 * and expect each site to have its own options table, behave as they would in
 * WordPress. Every value is reset between tests by TestCase.
 */
final class WP {

	/**
	 * Options, keyed by blog id and then option name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static $options = array();

	/**
	 * Autoload flag passed to the last update_option() of each option.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static $autoload = array();

	/**
	 * Network-wide options.
	 *
	 * @var array<string, mixed>
	 */
	public static $site_options = array();

	/**
	 * Transients, keyed by blog id and then transient name.
	 *
	 * @var array<int, array<string, array{value: mixed, ttl: int}>>
	 */
	public static $transients = array();

	/**
	 * Network-wide transients.
	 *
	 * @var array<string, mixed>
	 */
	public static $site_transients = array();

	/**
	 * Per-user options.
	 *
	 * @var array<string, mixed>
	 */
	public static $user_options = array();

	/**
	 * Whether the current user holds every capability.
	 *
	 * @var bool
	 */
	public static $can = true;

	/**
	 * Capabilities checked so far.
	 *
	 * @var string[]
	 */
	public static $checked_caps = array();

	/**
	 * Whether nonce checks pass.
	 *
	 * @var bool
	 */
	public static $nonce_valid = true;

	/**
	 * Nonce actions checked so far.
	 *
	 * @var string[]
	 */
	public static $checked_nonces = array();

	/**
	 * Installed plugins, as get_plugins() returns them.
	 *
	 * @var array<string, array<string, string>>
	 */
	public static $plugins = array();

	/**
	 * Installed must-use plugins, as get_mu_plugins() returns them.
	 *
	 * @var array<string, array<string, string>>
	 */
	public static $mu_plugins = array();

	/**
	 * Installed themes, as wp_get_themes() returns them.
	 *
	 * @var array<string, Fake_Theme>
	 */
	public static $themes = array();

	/**
	 * Stylesheet and template of the active theme.
	 *
	 * @var string[]
	 */
	public static $active_theme = array(
		'stylesheet' => '',
		'template'   => '',
	);

	/**
	 * Whether this is a multisite install.
	 *
	 * @var bool
	 */
	public static $multisite = false;

	/**
	 * Site ids on the network.
	 *
	 * @var int[]
	 */
	public static $sites = array( 1 );

	/**
	 * The blog currently switched to.
	 *
	 * @var int
	 */
	public static $blog_id = 1;

	/**
	 * Blog stack for switch_to_blog() / restore_current_blog().
	 *
	 * @var int[]
	 */
	public static $blog_stack = array();

	/**
	 * Whether a persistent object cache is in use.
	 *
	 * @var bool
	 */
	public static $ext_object_cache = false;

	/**
	 * The HTTP referrer wp_get_referer() reports, or false.
	 *
	 * @var string|false
	 */
	public static $referer = false;

	/**
	 * The current admin screen.
	 *
	 * @var Fake_Screen|null
	 */
	public static $screen = null;

	/**
	 * Registered admin menu pages.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static $menu = array();

	/**
	 * Registered admin submenu pages.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static $submenu = array();

	/**
	 * Registered and enqueued scripts and styles.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public static $assets = array(
		'registered_styles'  => array(),
		'registered_scripts' => array(),
		'enqueued_styles'    => array(),
		'enqueued_scripts'   => array(),
		'localized'          => array(),
	);

	/**
	 * Activation and deactivation hooks registered.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	public static $lifecycle = array(
		'activation'   => array(),
		'deactivation' => array(),
	);

	/**
	 * Calls to nocache_headers().
	 *
	 * @var int
	 */
	public static $nocache_calls = 0;

	/**
	 * Calls to wp_raise_memory_limit().
	 *
	 * @var string[]
	 */
	public static $memory_raises = array();

	/**
	 * Resets every value to a clean single-site install.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$options          = array( 1 => array() );
		self::$autoload         = array( 1 => array() );
		self::$site_options     = array();
		self::$transients       = array( 1 => array() );
		self::$site_transients  = array();
		self::$user_options     = array();
		self::$can              = true;
		self::$checked_caps     = array();
		self::$nonce_valid      = true;
		self::$checked_nonces   = array();
		self::$plugins          = array();
		self::$mu_plugins       = array();
		self::$themes           = array();
		self::$active_theme     = array(
			'stylesheet' => '',
			'template'   => '',
		);
		self::$multisite        = false;
		self::$sites            = array( 1 );
		self::$blog_id          = 1;
		self::$blog_stack       = array();
		self::$ext_object_cache = false;
		self::$referer          = false;
		self::$screen           = null;
		self::$menu             = array();
		self::$submenu          = array();
		self::$assets           = array(
			'registered_styles'  => array(),
			'registered_scripts' => array(),
			'enqueued_styles'    => array(),
			'enqueued_scripts'   => array(),
			'localized'          => array(),
		);
		self::$lifecycle        = array(
			'activation'   => array(),
			'deactivation' => array(),
		);
		self::$nocache_calls    = 0;
		self::$memory_raises    = array();
	}

	/**
	 * Returns the options of the current blog.
	 *
	 * @return array<string, mixed> Options keyed by name.
	 */
	public static function blog_options() {
		return self::$options[ self::$blog_id ] ?? array();
	}
}
