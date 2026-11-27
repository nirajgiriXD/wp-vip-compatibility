<?php
/**
 * A fake of the WordPress functions the plugin calls.
 *
 * Each one is small, behaves like its WordPress namesake for the inputs the
 * plugin passes, and keeps its state in Support\WP so a test can set it up and
 * read it back. Hook functions (add_action, apply_filters...) come from Brain
 * Monkey instead, which also lets a test replace any function here with
 * Functions\when() or Functions\expect() for a single case.
 *
 * This file must be loaded after Composer's autoloader: Brain Monkey can only
 * redefine functions declared in files Patchwork has seen being loaded.
 *
 * @package switchrail-audit
 */

// phpcs:disable -- Test support code that mirrors WordPress signatures.

use SWITCHRAIL_AUDIT\Tests\Support\Json_Response;
use SWITCHRAIL_AUDIT\Tests\Support\Redirect;
use SWITCHRAIL_AUDIT\Tests\Support\WP;
use SWITCHRAIL_AUDIT\Tests\Support\Wp_Die;

/*
 * ---------------------------------------------------------------------
 * Translation and escaping
 * ------------------------------------------------------------------
 */

function __( $text, $domain = 'default' ) {
	return $text;
}

function _x( $text, $context, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function _e( $text, $domain = 'default' ) {
	echo $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_js( $text ) {
	return addslashes( (string) $text );
}

function esc_url( $url ) {
	$url = (string) $url;

	// Like WordPress, refuse anything that is not a web or relative URL.
	if ( preg_match( '#^\s*(javascript|data|vbscript):#i', $url ) ) {
		return '';
	}

	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( $text );
}

function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( $text );
}

function esc_attr_e( $text, $domain = 'default' ) {
	echo esc_attr( $text );
}

function wp_kses_post( $html ) {
	$html = preg_replace( '#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', (string) $html );

	return preg_replace( '#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html );
}

function wp_strip_all_tags( $text ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );

	return trim( strip_tags( $text ) );
}

/*
 * ---------------------------------------------------------------------
 * Sanitising and formatting
 * ------------------------------------------------------------------
 */

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	$text = wp_strip_all_tags( (string) $text );

	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $text ) );
}

function sanitize_title( $title ) {
	$title = strtolower( (string) $title );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );

	return trim( $title, '-' );
}

function sanitize_html_class( $class ) {
	$class = preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $class );

	return preg_replace( '/[^A-Za-z0-9_-]/', '', $class );
}

function sanitize_hex_color( $color ) {
	if ( '' === $color ) {
		return '';
	}

	return preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color ) ? $color : null;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function size_format( $bytes, $decimals = 0 ) {
	$units = array(
		'TB' => 1024 ** 4,
		'GB' => 1024 ** 3,
		'MB' => 1024 ** 2,
		'KB' => 1024,
		'B'  => 1,
	);

	if ( 0 === (int) $bytes ) {
		return number_format_i18n( 0, $decimals ) . ' B';
	}

	foreach ( $units as $unit => $size ) {
		if ( (float) $bytes >= $size ) {
			return number_format_i18n( $bytes / $size, $decimals ) . ' ' . $unit;
		}
	}

	return false;
}

function human_time_diff( $from, $to = 0 ) {
	$to   = $to ? $to : time();
	$diff = (int) abs( $to - $from );

	if ( $diff < 3600 ) {
		$minutes = max( 1, (int) round( $diff / 60 ) );

		return $minutes . ( 1 === $minutes ? ' min' : ' mins' );
	}

	if ( $diff < 86400 ) {
		$hours = max( 1, (int) round( $diff / 3600 ) );

		return $hours . ( 1 === $hours ? ' hour' : ' hours' );
	}

	$days = max( 1, (int) round( $diff / 86400 ) );

	return $days . ( 1 === $days ? ' day' : ' days' );
}

function wp_sprintf( $pattern, ...$args ) {
	if ( '%l' === $pattern ) {
		$items = array_values( (array) ( $args[0] ?? array() ) );

		switch ( count( $items ) ) {
			case 0:
				return '';
			case 1:
				return (string) $items[0];
			case 2:
				return $items[0] . ' and ' . $items[1];
			default:
				$last = array_pop( $items );

				return implode( ', ', $items ) . ', and ' . $last;
		}
	}

	return vsprintf( $pattern, $args );
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$parsed = get_object_vars( $args );
	} elseif ( is_array( $args ) ) {
		$parsed = $args;
	} else {
		parse_str( (string) $args, $parsed );
	}

	return is_array( $defaults ) && $defaults ? array_merge( $defaults, $parsed ) : $parsed;
}

/*
 * ---------------------------------------------------------------------
 * URLs and nonces
 * ------------------------------------------------------------------
 */

function home_url( $path = '' ) {
	return 'https://example.test' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

/**
 * Adds query arguments to a URL.
 *
 * Accepts both add_query_arg( array, url ) and add_query_arg( key, value, url ).
 * Like WordPress, values are not URL-encoded, and a false value removes the key.
 */
function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) ) {
		$params = $args[0];
		$url    = $args[1] ?? '';
	} else {
		$params = array( $args[0] => $args[1] );
		$url    = $args[2] ?? '';
	}

	$fragment = '';

	if ( false !== strpos( $url, '#' ) ) {
		list( $url, $fragment ) = explode( '#', $url, 2 );
		$fragment               = '#' . $fragment;
	}

	$query = array();
	$base  = $url;

	if ( false !== strpos( $url, '?' ) ) {
		list( $base, $existing ) = explode( '?', $url, 2 );
		parse_str( $existing, $query );
	}

	foreach ( $params as $key => $value ) {
		if ( false === $value ) {
			unset( $query[ $key ] );
			continue;
		}

		$query[ $key ] = $value;
	}

	$pairs = array();

	foreach ( $query as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}

	return $base . ( $pairs ? '?' . implode( '&', $pairs ) : '' ) . $fragment;
}

function wp_create_nonce( $action = -1 ) {
	return substr( md5( 'nonce|' . $action ), 0, 10 );
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	return add_query_arg( $name, wp_create_nonce( $action ), $url );
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . wp_create_nonce( $action ) . '" />';

	if ( $display ) {
		echo $field;
	}

	return $field;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	WP::$checked_nonces[] = $action;

	if ( ! WP::$nonce_valid ) {
		throw new Wp_Die( 'The link you followed has expired.' );
	}

	return 1;
}

function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	WP::$checked_nonces[] = $action;

	if ( ! WP::$nonce_valid ) {
		throw new Wp_Die( '-1', '', array( 'response' => 403 ) );
	}

	return 1;
}

function wp_get_referer() {
	return WP::$referer;
}

/*
 * ---------------------------------------------------------------------
 * Users and capabilities
 * ------------------------------------------------------------------
 */

function current_user_can( $capability, ...$args ) {
	WP::$checked_caps[] = $capability;

	return WP::$can;
}

function get_user_option( $option ) {
	return WP::$user_options[ $option ] ?? false;
}

/*
 * ---------------------------------------------------------------------
 * Options and transients
 * ------------------------------------------------------------------
 */

function get_option( $option, $default_value = false ) {
	$options = WP::blog_options();

	return array_key_exists( $option, $options ) ? $options[ $option ] : $default_value;
}

function update_option( $option, $value, $autoload = null ) {
	WP::$options[ WP::$blog_id ][ $option ]  = $value;
	WP::$autoload[ WP::$blog_id ][ $option ] = $autoload;

	return true;
}

function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $option, WP::blog_options() ) ) {
		return false;
	}

	return update_option( $option, $value, $autoload );
}

function delete_option( $option ) {
	$existed = array_key_exists( $option, WP::blog_options() );

	unset( WP::$options[ WP::$blog_id ][ $option ], WP::$autoload[ WP::$blog_id ][ $option ] );

	return $existed;
}

function get_site_option( $option, $default_value = false ) {
	return array_key_exists( $option, WP::$site_options ) ? WP::$site_options[ $option ] : $default_value;
}

function get_transient( $transient ) {
	return WP::$transients[ WP::$blog_id ][ $transient ]['value'] ?? false;
}

function set_transient( $transient, $value, $expiration = 0 ) {
	WP::$transients[ WP::$blog_id ][ $transient ] = array(
		'value' => $value,
		'ttl'   => (int) $expiration,
	);

	return true;
}

function delete_transient( $transient ) {
	$existed = isset( WP::$transients[ WP::$blog_id ][ $transient ] );

	unset( WP::$transients[ WP::$blog_id ][ $transient ] );

	return $existed;
}

function get_site_transient( $transient ) {
	return WP::$site_transients[ $transient ] ?? false;
}

/*
 * ---------------------------------------------------------------------
 * Multisite
 * ------------------------------------------------------------------
 */

function is_multisite() {
	return WP::$multisite;
}

function get_sites( $args = array() ) {
	return WP::$sites;
}

function switch_to_blog( $blog_id ) {
	WP::$blog_stack[] = WP::$blog_id;
	WP::$blog_id      = (int) $blog_id;

	if ( ! isset( WP::$options[ WP::$blog_id ] ) ) {
		WP::$options[ WP::$blog_id ] = array();
	}

	return true;
}

function restore_current_blog() {
	if ( empty( WP::$blog_stack ) ) {
		return false;
	}

	WP::$blog_id = array_pop( WP::$blog_stack );

	return true;
}

/*
 * ---------------------------------------------------------------------
 * Plugins, themes and environment
 * ------------------------------------------------------------------
 */

function get_plugins( $plugin_folder = '' ) {
	return WP::$plugins;
}

function get_mu_plugins() {
	return WP::$mu_plugins;
}

function wp_get_themes( $args = array() ) {
	return WP::$themes;
}

function get_stylesheet() {
	return WP::$active_theme['stylesheet'];
}

function get_template() {
	return WP::$active_theme['template'];
}

function get_bloginfo( $show = '', $filter = 'raw' ) {
	return 'version' === $show ? '6.7' : 'Test site';
}

function wp_using_ext_object_cache( $using = null ) {
	return WP::$ext_object_cache;
}

function register_activation_hook( $file, $callback ) {
	WP::$lifecycle['activation'][] = array( $file, $callback );
}

function register_deactivation_hook( $file, $callback ) {
	WP::$lifecycle['deactivation'][] = array( $file, $callback );
}

function wp_raise_memory_limit( $context = 'admin' ) {
	WP::$memory_raises[] = $context;

	return '256M';
}

/*
 * ---------------------------------------------------------------------
 * Admin screens, menus and assets
 * ------------------------------------------------------------------
 */

function get_current_screen() {
	return WP::$screen;
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	WP::$menu[] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'icon_url', 'position' );

	return 'toplevel_page_' . $menu_slug;
}

function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
	WP::$submenu[] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'position' );

	return ( $parent_slug === $menu_slug ? 'toplevel_page_' : 'switchrail-audit_page_' ) . $menu_slug;
}

function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) {
	WP::$assets['registered_styles'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );

	return true;
}

function wp_enqueue_style( $handle ) {
	WP::$assets['enqueued_styles'][] = $handle;
}

function wp_add_inline_style( $handle, $data ) {
	WP::$assets['inline_styles'][ $handle ][] = $data;

	return true;
}

function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
	WP::$assets['registered_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'args' );

	return true;
}

function wp_enqueue_script( $handle ) {
	WP::$assets['enqueued_scripts'][] = $handle;
}

function wp_localize_script( $handle, $object_name, $l10n ) {
	WP::$assets['localized'][ $handle ] = array(
		'name' => $object_name,
		'data' => $l10n,
	);

	return true;
}

/*
 * ---------------------------------------------------------------------
 * Responses that end the request
 * ------------------------------------------------------------------
 */

function wp_send_json_success( $data = null, $status_code = null ) {
	throw new Json_Response( true, $data, $status_code );
}

function wp_send_json_error( $data = null, $status_code = null ) {
	throw new Json_Response( false, $data, $status_code );
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Wp_Die( $message, $title, $args );
}

function wp_safe_redirect( $location, $status = 302 ) {
	throw new Redirect( $location );
}

function nocache_headers() {
	++WP::$nocache_calls;
}
