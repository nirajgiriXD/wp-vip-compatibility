<?php
/**
 * The WordPress VIP compatibility rule registry.
 *
 * Every rule in here has to justify itself against the WordPress VIP Platform, not
 * against generic WordPress best practice. A rule earns its place when at
 * least one of the following is true:
 *
 * - The pattern fails or misbehaves on WordPress VIP because of a platform constraint
 *   (read-only filesystem, no real directory tree in uploads, NGINX instead of Apache, the
 *   object cache being shared, cron running through Cron Control...).
 * - The platform already provides the capability, so shipping a local
 *   implementation duplicates or fights the platform.
 * - The WordPress-VIP-Go PHPCS standard reports it, which means it will show
 *   up in WordPress VIP code review anyway.
 *
 * Each rule records the reference documentation it was derived from so the
 * report can link a finding straight to the rule that produced it, and so the
 * next person to touch this file can re-verify the claim.
 *
 * Rules are data. To add, change or remove one from another plugin, filter
 * `preflight_audit_scanner_rules`.
 *
 * @package preflight-audit
 */

namespace PREFLIGHT_AUDIT\Includes\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the rule definitions used by the code scanner.
 */
class Rules {

	/**
	 * Bumped whenever the rule set changes in a way that invalidates results.
	 *
	 * Cached scan results embed this value so that upgrading the plugin
	 * re-scans instead of showing findings from an older rule set.
	 *
	 * @var string
	 */
	const VERSION = '2.1.0';

	/**
	 * Memoised rule definitions.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $rules = null;

	/**
	 * Returns every rule, keyed by rule id.
	 *
	 * Deliberately lazy: the definitions contain translated strings, so they
	 * must not be built before the text domain is available.
	 *
	 * @return array<string, array<string, mixed>> Rule definitions.
	 */
	public static function all() {
		if ( null !== self::$rules ) {
			return self::$rules;
		}

		$rules = array_merge(
			self::filesystem_rules(),
			self::database_rules(),
			self::http_rules(),
			self::caching_rules(),
			self::cron_rules(),
			self::security_rules(),
			self::environment_rules(),
			self::standards_rules()
		);

		/**
		 * Filters the WordPress VIP compatibility rule set.
		 *
		 * @param array<string, array<string, mixed>> $rules Rule definitions keyed by rule id.
		 */
		$rules = apply_filters( 'preflight_audit_scanner_rules', $rules );

		self::$rules = array_map( array( __CLASS__, 'normalise' ), $rules );

		return self::$rules;
	}

	/**
	 * Returns a single rule definition.
	 *
	 * @param string $rule_id Rule id.
	 * @return array<string, mixed>|null The rule, or null when unknown.
	 */
	public static function get( $rule_id ) {
		$rules = self::all();

		return $rules[ $rule_id ] ?? null;
	}

	/**
	 * Clears the memoised rules. Intended for tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$rules = null;
	}

	/**
	 * Fills in the optional keys of a rule definition.
	 *
	 * @param array<string, mixed> $rule Raw rule definition.
	 * @return array<string, mixed> The normalised rule.
	 */
	private static function normalise( $rule ) {
		return wp_parse_args(
			$rule,
			array(
				'title'       => '',
				'category'    => 'standards',
				'type'        => Taxonomy::TYPE_RECOMMENDATION,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => '',
				'why'         => '',
				'remediation' => '',
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/',
				'phpcs'       => '',
				'match'       => array(),
				'guard'       => null,
			)
		);
	}

	/**
	 * Filesystem, uploads and media rules.
	 *
	 * The WordPress VIP filesystem is read-only apart from `/tmp/` and the uploads
	 * directory, and uploads are an object store reached through a PHP stream
	 * wrapper rather than a real directory tree.
	 *
	 * @return array<string, array<string, mixed>> Rule definitions.
	 */
	private static function filesystem_rules() {
		$fs_doc      = 'https://docs.wpvip.com/vip-file-system/';
		$uploads_doc = 'https://docs.wpvip.com/vip-file-system/media-uploads/';

		return array(
			'filesystem.write-outside-writable-paths' => array(
				'title'       => __( 'Filesystem write outside the writable paths', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A write, delete, move or permission-change call whose target does not resolve to the uploads directory or /tmp/.', 'preflight-audit' ),
				'why'         => __( 'Application code on WordPress VIP is deployed from Git onto a read-only filesystem. Only /tmp/ and /wp-content/uploads/ accept writes. Writing anywhere else — including into a plugin or theme directory — fails at runtime.', 'preflight-audit' ),
				'remediation' => __( 'Point the write at the uploads directory using wp_get_upload_dir(), or at get_temp_dir() for scratch data that does not need to survive the request. Never build the path by concatenating WP_CONTENT_DIR, plugin_dir_path() or __DIR__.', 'preflight-audit' ),
				'alternative' => __( 'If the data is state rather than a file, store it in an option, post meta, a custom table or the object cache instead of on disk.', 'preflight-audit' ),
				'doc'         => $fs_doc,
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops',
				'match'       => array(
					'functions' => array(
						'file_put_contents',
						'fwrite',
						'fputs',
						'fputcsv',
						'fprintf',
						'vfprintf',
						'ftruncate',
						'mkdir',
						'rmdir',
						'unlink',
						'rename',
						'copy',
						'touch',
						'chmod',
						'chgrp',
						'chown',
						'symlink',
						'link',
						'move_uploaded_file',
					),
				),
				'guard'       => array( Guards::class, 'write_target_is_not_writable' ),
			),

			'filesystem.fopen-write-mode'             => array(
				'title'       => __( 'File opened for writing outside the writable paths', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'fopen() called with a write, append or truncate mode on a path that does not resolve to uploads or /tmp/.', 'preflight-audit' ),
				'why'         => __( 'Opening a file for reading is fine on WordPress VIP. Opening one for writing outside /tmp/ and the uploads directory fails, because the application filesystem is read-only.', 'preflight-audit' ),
				'remediation' => __( 'Write to a path built from wp_get_upload_dir(), or to get_temp_dir() for transient scratch files. The WordPress VIP stream wrapper makes fopen(), fwrite() and fclose() work normally inside /wp-content/uploads/.', 'preflight-audit' ),
				'alternative' => __( 'For read-only access to a bundled data file, keep the current path and the "r" mode — that is supported.', 'preflight-audit' ),
				'doc'         => $uploads_doc,
				'match'       => array(
					'functions' => array( 'fopen' ),
				),
				'guard'       => array( Guards::class, 'fopen_writes_outside_writable' ),
			),

			'filesystem.hardcoded-uploads-path'       => array(
				'title'       => __( 'Hard-coded uploads path', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A literal "wp-content/uploads" path in the source.', 'preflight-audit' ),
				'why'         => __( 'On WordPress VIP the uploads directory is mapped onto an external object store, and on multisite each subsite writes under its own prefix. A hard-coded path resolves to the wrong location, or to nothing at all.', 'preflight-audit' ),
				'remediation' => __( 'Replace the literal with wp_get_upload_dir() (paths) or wp_upload_dir()["baseurl"] (URLs) and append the sub-path to the returned value.', 'preflight-audit' ),
				'alternative' => __( 'For a static asset that ships with the code, reference it with plugins_url() or get_theme_file_uri() instead of putting it in uploads.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/vip-file-system/modify-uploads-directory/',
				'match'       => array(
					'strings'  => array( '#wp-content/uploads#i' ),
					'contains' => 'wp-content/uploads', // pfa:ignore filesystem.hardcoded-uploads-path -- The needle this rule searches for, not a path this plugin uses.
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'filesystem.blogs-dir'                    => array(
				'title'       => __( 'Legacy blogs.dir media path', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A reference to the pre-3.5 multisite blogs.dir media directory.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP only serves and accepts media under /wp-content/uploads/. Media stored in wp-content/blogs.dir is neither importable nor reachable, so anything that reads or writes there breaks.', 'preflight-audit' ),
				'remediation' => __( 'Move the media into the uploads directory, update the stored paths in the database with a targeted search-and-replace, and add redirects for the old URLs.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => $uploads_doc,
				'match'       => array(
					'strings'  => array( '#blogs\.dir#i' ),
					'contains' => 'blogs.dir', // pfa:ignore filesystem.blogs-dir -- The needle this rule searches for, not a path this plugin uses.
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'filesystem.directory-iteration'          => array(
				'title'       => __( 'Directory iteration over uploads', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A directory listing or traversal call pointed at the uploads directory.', 'preflight-audit' ),
				'why'         => __( 'The WordPress VIP File System is an object store presented through a stream wrapper, with no real directory tree. On the uploads directory, scandir(), glob(), opendir() and similar calls return an empty array or false and raise a PHP warning. Directory traversal only works in /tmp.', 'preflight-audit' ),
				'remediation' => __( 'Stop discovering files by listing a directory. Record the files you write — in post meta, user meta, an option or a custom table — and read that index back instead.', 'preflight-audit' ),
				'alternative' => __( 'For attachments, query the media library with WP_Query on the "attachment" post type rather than walking the uploads folder.', 'preflight-audit' ),
				'doc'         => $uploads_doc,
				'match'       => array(
					'functions' => array( 'opendir', 'readdir', 'closedir', 'rewinddir', 'scandir', 'glob' ),
					'classes'   => array( 'DirectoryIterator', 'RecursiveDirectoryIterator', 'FilesystemIterator' ),
				),
				'guard'       => array( Guards::class, 'iterates_uploads' ),
			),

			'filesystem.apache-assumption'            => array(
				'title'       => __( 'Apache or .htaccess assumption', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'Code that reads, writes or depends on .htaccess or the Apache API.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP serves requests through NGINX. There is no .htaccess file, the Apache functions do not exist, and the web root is not writable, so rewrite rules, deny rules and header rules written this way are silently ignored.', 'preflight-audit' ),
				'remediation' => __( 'Express redirects in PHP — wp_safe_redirect() on the "template_redirect" hook, or the Safe Redirect Manager plugin. Domain-level redirects belong in vip-config.php. Access rules belong in application code.', 'preflight-audit' ),
				'alternative' => __( 'Send headers with the WordPress HTTP API and the "wp_headers" filter rather than through server configuration.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/technical-references/wordpress-on-vip/',
				'match'       => array(
					'functions' => array( 'insert_with_markers', 'got_mod_rewrite', 'apache_get_modules', 'apache_setenv', 'apache_response_headers', 'apache_request_headers' ),
					'strings'   => array( '#\.htaccess#i' ),
					'contains'  => '.htaccess', // pfa:ignore filesystem.apache-assumption -- The needle this rule searches for, not a file this plugin touches.
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'filesystem.generated-code-asset'         => array(
				'title'       => __( 'Dynamically generated PHP, CSS or JS file', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A write whose target filename ends in .php, .css, .js, .html or .json.', 'preflight-audit' ),
				'why'         => __( 'Generating code or asset files at runtime assumes a writable application directory, which WordPress VIP does not provide. Even when the write lands in uploads, generated PHP is never executed there, and generated CSS or JS bypasses the platform asset pipeline.', 'preflight-audit' ),
				'remediation' => __( 'Build the asset at deploy time and commit it, or render it through a WordPress endpoint (wp_add_inline_style(), wp_add_inline_script(), or a registered REST route) instead of writing a file.', 'preflight-audit' ),
				'alternative' => __( 'For configuration that used to be written to a PHP file, store it in an option and read it back through get_option().', 'preflight-audit' ),
				'doc'         => $fs_doc,
				'match'       => array(
					'functions' => array( 'file_put_contents', 'fwrite', 'fputs', 'fopen', 'rename', 'copy' ),
				),
				'guard'       => array( Guards::class, 'writes_code_asset' ),
			),

			'filesystem.wp-filesystem-credentials'    => array(
				'title'       => __( 'WP_Filesystem credentials request', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'Use of the WP_Filesystem abstraction or a request for filesystem credentials.', 'preflight-audit' ),
				'why'         => __( 'WP_Filesystem can still read plugin and theme files on WordPress VIP, but the web servers are read-only, so anything it writes outside /tmp fails, and its directory operations do not work as expected on the uploads file system.', 'preflight-audit' ),
				'remediation' => __( 'Use WP_Filesystem only to read. Write scratch files with wp_tempnam() under get_temp_dir(), and store media through the WordPress upload APIs, which the WordPress VIP stream wrapper handles.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => $uploads_doc,
				'match'       => array(
					'functions' => array( 'request_filesystem_credentials', 'WP_Filesystem', 'get_filesystem_method' ),
				),
			),

			'filesystem.upload-dir-filter'            => array(
				'title'       => __( 'upload_dir filter changes the media location', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A callback attached to the upload_dir filter.', 'preflight-audit' ),
				'why'         => __( 'Media on WordPress VIP can only be written to paths beginning /wp-content/uploads/. A filter that redirects uploads elsewhere — a plugin folder, a sibling directory, a third-party bucket — produces failed uploads or missing thumbnails.', 'preflight-audit' ),
				'remediation' => __( 'Keep the base directory as WordPress VIP supplies it and only vary the sub-path underneath it. Confirm the filter still returns a path inside the uploads base directory.', 'preflight-audit' ),
				'alternative' => __( 'Organise media with taxonomy or post meta instead of custom folder layouts.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/vip-file-system/modify-uploads-directory/',
				'match'       => array(
					'hooks' => array( 'upload_dir' ),
				),
			),

			'filesystem.image-manipulation'           => array(
				'title'       => __( 'Local image processing', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_REDUNDANT,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'Direct use of GD or Imagick to resize, crop or re-encode images.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP resizes, crops and re-encodes images on the fly at the CDN edge from the original upload. Doing the same work in PHP spends request time on something the platform already provides, and writes extra derivative files into the file system.', 'preflight-audit' ),
				'remediation' => __( 'Request the size you need through the image URL and let the platform generate it, or use wp_get_attachment_image() with a registered size.', 'preflight-audit' ),
				'alternative' => __( 'Where bespoke processing is genuinely required, run it once on upload rather than on every page render.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/vip-file-system/image-files/',
				'match'       => array(
					'functions' => array(
						'imagecreatefromjpeg',
						'imagecreatefrompng',
						'imagecreatefromgif',
						'imagecreatefromwebp',
						'imagejpeg',
						'imagepng',
						'imagegif',
						'imagewebp',
						'imagecopyresampled',
						'imagecopyresized',
						'wp_crop_image',
					),
					'classes'   => array( 'Imagick' ),
				),
			),

			'filesystem.intermediate-image-sizes'     => array(
				'title'       => __( 'Registered intermediate image sizes', 'preflight-audit' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INFORMATIONAL,
				'severity'    => Taxonomy::SEVERITY_INFO,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'A call to add_image_size().', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP does not create a file for each registered size. Every size is generated on request from the original through URL parameters, and get_intermediate_image_sizes() returns an empty array. Code that expects a resized file on disk, or loops over the size list, will not find what it expects.', 'preflight-audit' ),
				'remediation' => __( 'Keep registered sizes for wp_get_attachment_image() and srcset, but never read a resized file from disk. Request one-off dimensions with URL parameters such as ?w=300&h=200&crop=1.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/vip-file-system/image-files/intermediate-images/',
				'match'       => array(
					'functions' => array( 'add_image_size' ),
				),
			),
		);
	}

	/**
	 * Database rules.
	 *
	 * @return array<string, array<string, mixed>> Rule definitions.
	 */
	private static function database_rules() {
		$query_doc = 'https://docs.wpvip.com/databases/optimize-queries/';

		return array(
			'database.unprepared-query'   => array(
				'title'       => __( 'SQL built by interpolation instead of $wpdb->prepare()', 'preflight-audit' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_CRITICAL,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A $wpdb query whose SQL contains an interpolated or concatenated variable, with no $wpdb->prepare() call.', 'preflight-audit' ),
				'why'         => __( 'This is a SQL injection vector. WordPress VIP code review blocks unprepared queries, and the WordPress VIP Code Analysis Bot reports them on every pull request.', 'preflight-audit' ),
				// The placeholder tokens are named rather than written out: this
				// is a description, not a format string, and printf-style tokens
				// inside a translatable string confuse both PHPCS and translators.
				'remediation' => __( 'Wrap the SQL in $wpdb->prepare() using its string, integer and float placeholders, and pass the values as arguments. Table names cannot be placeholders — build them from $wpdb->prefix and validate against a known list.', 'preflight-audit' ),
				'alternative' => __( 'Prefer a core API — WP_Query, get_posts(), get_terms() — over hand-written SQL wherever one exists.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/databases/optimize-queries/database-queries/',
				'phpcs'       => 'WordPress.DB.PreparedSQL.InterpolatedNotPrepared',
				'match'       => array(
					'methods' => array( 'wpdb->query', 'wpdb->get_results', 'wpdb->get_row', 'wpdb->get_var', 'wpdb->get_col' ),
				),
				'guard'       => array( Guards::class, 'sql_is_unprepared' ),
			),

			'database.direct-query'       => array(
				'title'       => __( 'Direct database query', 'preflight-audit' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A $wpdb query that bypasses the WordPress data APIs.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP runs behind HyperDB, which splits reads across replicas. Hand-written queries skip the object cache entirely, so each one is a round trip on every request, and they are the usual cause of slow pages at traffic.', 'preflight-audit' ),
				'remediation' => __( 'Use a core API where one exists. Where a direct query is genuinely needed, wrap the result in wp_cache_get()/wp_cache_set() with an explicit group and expiry.', 'preflight-audit' ),
				'alternative' => __( 'For search and heavy filtering, use WordPress VIP Search (Enterprise Search) rather than SQL.', 'preflight-audit' ),
				'doc'         => $query_doc,
				'phpcs'       => 'WordPress.DB.DirectDatabaseQuery.DirectQuery',
				'match'       => array(
					'methods' => array( 'wpdb->query', 'wpdb->get_results', 'wpdb->get_row', 'wpdb->get_var', 'wpdb->get_col', 'wpdb->insert', 'wpdb->update', 'wpdb->replace', 'wpdb->delete' ),
				),
				'guard'       => array( Guards::class, 'query_is_uncached' ),
			),

			'database.schema-change'      => array(
				'title'       => __( 'Runtime database schema change', 'preflight-audit' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'CREATE TABLE, ALTER TABLE, DROP TABLE, TRUNCATE TABLE or dbDelta() in application code.', 'preflight-audit' ),
				'why'         => __( 'Custom tables are allowed on WordPress VIP, but they must use the $wpdb->prefix table prefix, and a schema change issued on every web request can lock tables on a production database.', 'preflight-audit' ),
				'remediation' => __( 'Run schema changes through dbDelta() from a versioned upgrade routine that runs once, such as an option-version check or a WP-CLI command, never on every request. Use the $wpdb->prefix prefix and a WordPress VIP-supported utf8mb4 collation.', 'preflight-audit' ),
				'alternative' => __( 'Consider whether a custom post type, taxonomy or meta table can replace the custom table before adding one.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/databases/custom-tables/',
				// Attached to the query rather than to any DDL-shaped string in
				// the file: otherwise every tool that reports on the schema,
				// this one included, looks like it alters the schema.
				'match'       => array(
					'functions' => array( 'dbDelta' ),
					'methods'   => array( 'wpdb->query' ),
				),
				'guard'       => array( Guards::class, 'issues_schema_change' ),
			),

			'database.uncached-function'  => array(
				'title'       => __( 'Uncached lookup function', 'preflight-audit' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A core function that WordPress VIP documents as running an uncached database query.', 'preflight-audit' ),
				'why'         => __( 'These functions query the database directly on every call with no object-cache layer in front of them. On a high-traffic site they turn into an uncached query per page view.', 'preflight-audit' ),
				'remediation' => __( 'Use the cached wrapper WordPress VIP provides: wpcom_vip_url_to_postid(), wpcom_vip_attachment_url_to_postid(), wpcom_vip_count_user_posts(), wpcom_vip_get_adjacent_post(), wpcom_vip_wp_oembed_get() or wpcom_vip_old_slug_redirect(). Otherwise cache the result yourself with wp_cache_get() and wp_cache_set().', 'preflight-audit' ),
				'alternative' => __( 'Store the resolved ID alongside the data that needed it so the lookup only happens once.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/databases/optimize-queries/uncached-functions/',
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions',
				'match'       => array(
					'functions' => array(
						'url_to_postid',
						'attachment_url_to_postid',
						'wp_old_slug_redirect',
						'count_user_posts',
						'get_adjacent_post',
						'get_previous_post',
						'get_next_post',
						'previous_post_link',
						'next_post_link',
						'wp_oembed_get',
					),
				),
			),

			'database.unbounded-query'    => array(
				'title'       => __( 'Query with no result limit', 'preflight-audit' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A query asking for every matching row: posts_per_page => -1, numberposts => -1 or nopaging => true.', 'preflight-audit' ),
				'why'         => __( 'The query is bounded by however much content the site has. It is fine on a development database and times out or exhausts memory once the site has real volume, which is exactly the scale WordPress VIP sites run at.', 'preflight-audit' ),
				'remediation' => __( 'Set an explicit posts_per_page. If you genuinely need every row, page through the results in batches inside a WP-CLI command rather than in a web request.', 'preflight-audit' ),
				'alternative' => __( 'Add "fields" => "ids" when you only need identifiers — it avoids hydrating every post object.', 'preflight-audit' ),
				'doc'         => $query_doc,
				'phpcs'       => 'WordPress.WP.PostsPerPage.posts_per_page_posts_per_page',
				'match'       => array(
					'functions' => array( 'get_posts', 'query_posts', 'wp_get_recent_posts', 'get_users', 'get_terms' ),
					'classes'   => array( 'WP_Query', 'WP_User_Query', 'WP_Term_Query' ),
				),
				'guard'       => array( Guards::class, 'query_is_unbounded' ),
			),

			'database.expensive-query-arg' => array(
				'title'       => __( 'Query argument known to be expensive', 'preflight-audit' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A query using post__not_in, an unindexed meta_query, or orderby => rand.', 'preflight-audit' ),
				'why'         => __( 'These arguments generate SQL the database cannot serve from an index. Random ordering also defeats caching entirely, because every response differs.', 'preflight-audit' ),
				'remediation' => __( 'Replace post__not_in by over-fetching slightly and filtering in PHP. Replace random ordering by selecting a random slice from a cached ID list. Where meta filtering drives the query, move it to WordPress VIP Search.', 'preflight-audit' ),
				'alternative' => __( 'Model the filter as a taxonomy term instead of post meta — taxonomy queries are indexed.', 'preflight-audit' ),
				'doc'         => $query_doc,
				'phpcs'       => 'WordPressVIPMinimum.Performance.WPQueryParams',
				'match'       => array(
					'functions' => array( 'get_posts', 'query_posts', 'wp_get_recent_posts' ),
					'classes'   => array( 'WP_Query' ),
				),
				'guard'       => array( Guards::class, 'query_uses_expensive_args' ),
			),

			'database.autoloaded-option'  => array(
				'title'       => __( 'Explicitly autoloaded option', 'preflight-audit' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_INFORMATIONAL,
				'severity'    => Taxonomy::SEVERITY_INFO,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'add_option() or update_option() called with autoload set to "yes".', 'preflight-audit' ),
				'why'         => __( 'Autoloaded options are read into memory on every single request, including cache misses on the front end. A large autoloaded value is a fixed tax on every page render.', 'preflight-audit' ),
				'remediation' => __( 'Pass "no" for autoload unless the option is genuinely needed on most requests, and keep autoloaded values small.', 'preflight-audit' ),
				'alternative' => __( 'For data read on a handful of screens, a transient or a custom table is a better fit than an autoloaded option.', 'preflight-audit' ),
				'doc'         => $query_doc,
				'match'       => array(
					'functions' => array( 'add_option', 'update_option' ),
				),
				'guard'       => array( Guards::class, 'option_is_autoloaded' ),
			),
		);
	}

	/**
	 * External HTTP request rules.
	 *
	 * @return array<string, array<string, mixed>> Rule definitions.
	 */
	private static function http_rules() {
		$remote_doc = 'https://docs.wpvip.com/databases/optimize-queries/retrieving-remote-data/';

		return array(
			'http.raw-curl'               => array(
				'title'       => __( 'Raw cURL instead of the WordPress HTTP API', 'preflight-audit' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A direct cURL call.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP expects outbound traffic to go through the WordPress HTTP API so that platform-level timeouts, retries and monitoring apply. Raw cURL sidesteps all of it, and typically ships with no timeout at all, so a slow endpoint stalls the whole request.', 'preflight-audit' ),
				'remediation' => __( 'Replace the cURL handle with the WordPress HTTP API. For front-end GET requests use wpcom_vip_file_get_contents(), which caches the response; otherwise use vip_safe_wp_remote_get() or wp_safe_remote_post() with a timeout of 3 seconds or less.', 'preflight-audit' ),
				'alternative' => __( 'Use vip_safe_wp_remote_get(), which adds a circuit breaker that stops calling an endpoint that has started failing.', 'preflight-audit' ),
				'doc'         => $remote_doc,
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions.curl_curl_init',
				'match'       => array(
					'functions' => array( 'curl_init', 'curl_exec', 'curl_setopt', 'curl_setopt_array', 'curl_multi_init', 'curl_multi_exec' ),
				),
			),

			'http.remote-file-read'       => array(
				'title'       => __( 'Remote URL fetched with a filesystem function', 'preflight-audit' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A filesystem function called with an http:// or https:// URL.', 'preflight-audit' ),
				'why'         => __( 'Fetching over HTTP with file_get_contents() and friends relies on allow_url_fopen, has no timeout, ignores the platform HTTP layer, and is explicitly reported by the WordPress VIP PHPCS standard.', 'preflight-audit' ),
				'remediation' => __( 'Use wpcom_vip_file_get_contents(), which caches the response and serves the cached copy if a fetch fails, or wp_safe_remote_get() with an explicit short timeout and your own caching.', 'preflight-audit' ),
				'alternative' => __( 'For feeds, fetch_feed() adds caching for free.', 'preflight-audit' ),
				'doc'         => $remote_doc,
				'phpcs'       => 'WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown',
				'match'       => array(
					'functions' => array( 'file_get_contents', 'readfile', 'file', 'fopen', 'simplexml_load_file', 'get_headers', 'getimagesize', 'copy' ),
				),
				'guard'       => array( Guards::class, 'argument_is_remote_url' ),
			),

			'http.socket-connection'      => array(
				'title'       => __( 'Direct socket or external service connection', 'preflight-audit' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A raw socket, FTP, SSH, LDAP or third-party database connection.', 'preflight-audit' ),
				'why'         => __( 'Outbound requests on WordPress VIP are documented for HTTP through the WordPress HTTP API only. Raw sockets, FTP, SSH, LDAP and external database connections depend on network access and PHP extensions the platform does not document, so they may fail on WordPress VIP even though they work locally.', 'preflight-audit' ),
				'remediation' => __( 'Move the integration behind an HTTPS API reached with the WordPress HTTP API. If that is not possible, confirm the connection with WordPress VIP support first.', 'preflight-audit' ),
				'alternative' => __( 'Where the remote system cannot expose HTTP, run the transfer outside the application and push the results in.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/technical-references/wordpress-on-vip/',
				'match'       => array(
					'functions' => array(
						'fsockopen',
						'pfsockopen',
						'stream_socket_client',
						'socket_create',
						'socket_connect',
						'ftp_connect',
						'ftp_ssl_connect',
						'ssh2_connect',
						'ldap_connect',
						'mysqli_connect',
						'mysql_connect',
						'pg_connect',
					),
					'classes'   => array( 'mysqli', 'PDO' ),
				),
			),

			'http.uncached-remote-request' => array(
				'title'       => __( 'Remote request with no caching', 'preflight-audit' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A WordPress HTTP API call with no transient or object-cache call anywhere in the same function.', 'preflight-audit' ),
				'why'         => __( 'An uncached outbound request runs on every cache miss and blocks page generation until it returns. WordPress VIP says uncached remote requests should never run on the front end of a site.', 'preflight-audit' ),
				'remediation' => __( 'Use wpcom_vip_file_get_contents(), which caches for 15 minutes by default, or wrap the call in a transient or object-cache read. Keep the timeout at 3 seconds or less.', 'preflight-audit' ),
				'alternative' => __( 'Move the fetch into a scheduled job that refreshes a cached copy, so no visitor request ever waits on the remote service.', 'preflight-audit' ),
				'doc'         => $remote_doc,
				'match'       => array(
					'functions' => array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_head', 'wp_safe_remote_request' ),
				),
				'guard'       => array( Guards::class, 'remote_request_is_uncached' ),
			),

			'http.unsafe-remote-request'  => array(
				'title'       => __( 'Remote request not protected against SSRF', 'preflight-audit' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'wp_remote_* used where the wp_safe_remote_* variant exists.', 'preflight-audit' ),
				'why'         => __( 'The unsafe variants will follow a URL to a private or internal address. When any part of the URL comes from stored or user input, that is a server-side request forgery vector.', 'preflight-audit' ),
				'remediation' => __( 'Swap wp_remote_get() for wp_safe_remote_get() (and the same for post/head/request). The signatures are identical.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/security/',
				'match'       => array(
					'functions' => array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_remote_request' ),
				),
			),
		);
	}

	/**
	 * Caching rules.
	 *
	 * @return array<string, array<string, mixed>> Rule definitions.
	 */
	private static function caching_rules() {
		return array(
			'caching.custom-page-cache'   => array(
				'title'       => __( 'Custom page-cache implementation', 'preflight-audit' ),
				'category'    => 'caching',
				'type'        => Taxonomy::TYPE_REDUNDANT,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'References to the advanced-cache.php or object-cache.php drop-ins, or to the WP_CACHE constant.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP provides a globally distributed edge page cache and a managed object cache, and installs its own drop-ins. A second caching layer either has no effect or actively conflicts with the platform, which is why the well-known caching plugins are on the WordPress VIP incompatibility list.', 'preflight-audit' ),
				'remediation' => __( 'Remove the custom caching layer and rely on the platform. Tune behaviour with cache-control headers and the WordPress VIP Cache API instead.', 'preflight-audit' ),
				'alternative' => __( 'For fragment caching inside a page, use the WordPress object cache API (wp_cache_get/wp_cache_set), which WordPress VIP backs with Memcached.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/wordpress-on-vip/drop-ins/',
				'match'       => array(
					'strings'   => array( '#(advanced-cache|object-cache)\.php#i' ),
					'constants' => array( 'WP_CACHE' ),
					'contains'  => 'cache',
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'caching.cache-flush'         => array(
				'title'       => __( 'Full object-cache flush', 'preflight-audit' ),
				'category'    => 'caching',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to wp_cache_flush().', 'preflight-audit' ),
				'why'         => __( 'Flushing the object cache evicts every cached value on the site at once and sends a burst of uncached traffic straight to the database. WordPress VIP code review asks for the wp_cache_* functions to be used with great caution.', 'preflight-audit' ),
				'remediation' => __( 'Delete only the keys you invalidated with wp_cache_delete(), or move the data into its own cache group and version the group key.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/caching/object-cache/',
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions.wp_cache_flush',
				'match'       => array(
					'functions' => array( 'wp_cache_flush' ),
				),
			),

			'caching.cache-bypass-headers' => array(
				'title'       => __( 'Code that disables the page cache', 'preflight-audit' ),
				'category'    => 'caching',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'nocache_headers(), or a Cache-Control / Pragma / Expires header sent by hand.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP respects no-cache headers, and marks a response with a private Cache-Control value as uncacheable. Applied broadly, that takes the site off the edge cache and puts every visitor onto the origin.', 'preflight-audit' ),
				'remediation' => __( 'Scope the header to genuinely per-user responses. Where the page is mostly static with a small dynamic part, cache the page and load the dynamic fragment separately.', 'preflight-audit' ),
				'alternative' => __( 'Send a Cache-Control header with a shorter max-age instead of disabling caching outright.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/caching/page-cache/default-responses/',
				'match'       => array(
					'functions' => array( 'nocache_headers', 'header' ),
				),
				'guard'       => array( Guards::class, 'sends_cache_busting_header' ),
			),

			'caching.frontend-cookie'     => array(
				'title'       => __( 'Cookie set on a front-end response', 'preflight-audit' ),
				'category'    => 'caching',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_LOW,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to setcookie() or setrawcookie().', 'preflight-audit' ),
				'why'         => __( 'A response that sends a Set-Cookie header through setcookie() is marked uncacheable by the WordPress VIP page cache. Set on every front-end page, it takes the site off the edge cache.', 'preflight-audit' ),
				'remediation' => __( 'Set the cookie only on the responses that genuinely need it, or in JavaScript after the cached page loads, so the HTML itself stays cacheable.', 'preflight-audit' ),
				'alternative' => __( 'To serve different cached versions to groups of visitors, use the WordPress VIP cache personalization API (the Vary_Cache class) instead of a custom cookie.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/caching/page-cache/cookies/',
				'match'       => array(
					'functions' => array( 'setcookie', 'setrawcookie' ),
				),
			),
		);
	}

	/**
	 * Cron and scheduling rules.
	 *
	 * @return array<string, array<string, mixed>> Rule definitions.
	 */
	private static function cron_rules() {
		$cron_doc = 'https://docs.wpvip.com/wordpress-on-vip/cron-control/';

		return array(
			'cron.core-constant-override' => array(
				'title'       => __( 'WP-Cron constant defined in application code', 'preflight-audit' ),
				'category'    => 'cron',
				'type'        => Taxonomy::TYPE_REDUNDANT,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'DISABLE_WP_CRON, ALTERNATE_WP_CRON or WP_CRON_LOCK_TIMEOUT defined by a plugin or theme.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP replaces WP-Cron with Cron Control, which runs events from a managed runner rather than from page loads. Redefining these constants either does nothing or interferes with the platform runner.', 'preflight-audit' ),
				'remediation' => __( 'Remove the constant. Cron Control already guarantees that scheduled events run without depending on site traffic.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => $cron_doc,
				'match'       => array(
					'constants' => array( 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'WP_CRON_LOCK_TIMEOUT' ),
				),
			),

			'cron.manual-invocation'      => array(
				'title'       => __( 'Manual cron invocation or cron array manipulation', 'preflight-audit' ),
				'category'    => 'cron',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'spawn_cron(), a direct call into wp-cron.php, or direct reads and writes of the cron array.', 'preflight-audit' ),
				'why'         => __( 'Cron Control owns the event queue on WordPress VIP, and the /wp-cron.php endpoint is disabled. Code that spawns its own cron run or rewrites the cron array fights the platform runner and can drop or duplicate events.', 'preflight-audit' ),
				'remediation' => __( 'Schedule work with wp_schedule_event() and wp_schedule_single_event() and let Cron Control run it.', 'preflight-audit' ),
				'alternative' => __( 'For work that must run on a precise schedule or take a long time, use a WP-CLI command triggered by Cron Control.', 'preflight-audit' ),
				'doc'         => $cron_doc,
				'match'       => array(
					'functions' => array( 'spawn_cron', '_get_cron_array', '_set_cron_array' ),
					'strings'   => array( '#wp-cron\.php#i' ),
					'contains'  => 'cron',
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'cron.custom-schedule'        => array(
				'title'       => __( 'Custom cron schedule', 'preflight-audit' ),
				'category'    => 'cron',
				'type'        => Taxonomy::TYPE_INFORMATIONAL,
				'severity'    => Taxonomy::SEVERITY_INFO,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'A callback registered on the cron_schedules filter.', 'preflight-audit' ),
				'why'         => __( 'By default Cron Control runs only one event with the same action at a time. If the interval is shorter than the job takes, runs queue up rather than happening more often.', 'preflight-audit' ),
				'remediation' => __( 'Check that the job finishes well inside the interval, and split long jobs into batches. Schedule recurring work with wp_schedule_event(), not by calling wp_schedule_single_event() again on every run.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => $cron_doc,
				'match'       => array(
					'hooks' => array( 'cron_schedules' ),
				),
			),
		);
	}

	/**
	 * Security rules.
	 *
	 * @return array<string, array<string, mixed>> Rule definitions.
	 */
	private static function security_rules() {
		$security_doc = 'https://docs.wpvip.com/security/';

		return array(
			'security.command-execution'   => array(
				'title'       => __( 'Shell command execution', 'preflight-audit' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_CRITICAL,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A call that shells out to the operating system.', 'preflight-audit' ),
				'why'         => __( 'Shelling out is a command-injection risk whenever any part of the command is built from input, and the WordPress coding standards that the WordPress VIP standard builds on flag these calls. The binaries a command relies on are not documented as available on the read-only WordPress VIP web servers.', 'preflight-audit' ),
				'remediation' => __( 'Replace the external binary with a PHP library or a WordPress API. Where the work is genuinely a batch job, implement it as a WP-CLI command.', 'preflight-audit' ),
				'alternative' => __( 'For media processing specifically, the platform already handles resizing and format conversion — no external binary is needed.', 'preflight-audit' ),
				'doc'         => $security_doc,
				'phpcs'       => 'WordPress.PHP.DiscouragedPHPFunctions',
				'match'       => array(
					'functions' => array( 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec', 'pcntl_fork' ),
					'shell'     => true,
				),
			),

			'security.dynamic-code'        => array(
				'title'       => __( 'Dynamic code execution', 'preflight-audit' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_CRITICAL,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'eval(), create_function(), or assert() called with a string.', 'preflight-audit' ),
				'why'         => __( 'Executing code assembled at runtime is a remote-code-execution risk and cannot be reviewed statically. WordPress VIP code review rejects it, and create_function() was removed in PHP 8.', 'preflight-audit' ),
				'remediation' => __( 'Rewrite the dynamic code as a real function, a closure, or a lookup table of allowed behaviours.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/warnings/',
				'phpcs'       => 'Squiz.PHP.Eval.Discouraged',
				'match'       => array(
					'functions' => array( 'eval', 'create_function', 'assert' ),
				),
			),

			'security.unserialize'         => array(
				'title'       => __( 'Unserialising possibly untrusted data', 'preflight-audit' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to unserialize().', 'preflight-audit' ),
				'why'         => __( 'PHP object injection through unserialize() can lead to remote code execution when the payload is attacker-controlled. It is on the WordPress VIP restricted-function list.', 'preflight-audit' ),
				'remediation' => __( 'Use JSON for data interchange, and maybe_unserialize() only for values WordPress itself serialised.', 'preflight-audit' ),
				'alternative' => __( 'If unserialize() is unavoidable, pass ["allowed_classes" => false].', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/warnings/',
				'phpcs'       => 'WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize',
				'match'       => array(
					'functions' => array( 'unserialize' ),
				),
			),

			'security.unescaped-superglobal-output' => array(
				'title'       => __( 'Request data echoed without escaping', 'preflight-audit' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A superglobal printed directly by echo or print.', 'preflight-audit' ),
				'why'         => __( 'This is a reflected cross-site scripting vector. WordPress VIP code review treats unescaped output as a blocker.', 'preflight-audit' ),
				'remediation' => __( 'Sanitise on input with sanitize_text_field( wp_unslash( ... ) ) and escape on output with esc_html(), esc_attr() or esc_url() as appropriate.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/security/validating-sanitizing-and-escaping/',
				'phpcs'       => 'WordPress.Security.EscapeOutput.OutputNotEscaped',
				'match'       => array(
					'echoed_superglobals' => true,
				),
			),

			'security.extract'             => array(
				'title'       => __( 'extract() imports variables from an array', 'preflight-audit' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to extract().', 'preflight-audit' ),
				'why'         => __( 'extract() creates variables whose names come from data, so it can overwrite existing variables and makes the surrounding code impossible to reason about or review.', 'preflight-audit' ),
				'remediation' => __( 'Read the array keys explicitly, or destructure with list()/[] so the variable names are visible in the source.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/warnings/',
				'phpcs'       => 'WordPress.PHP.DontExtract.extract_extract',
				'match'       => array(
					'functions' => array( 'extract' ),
				),
			),

			'security.dynamic-include'     => array(
				'title'       => __( 'File included from a variable path', 'preflight-audit' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'include or require whose path is built from a variable.', 'preflight-audit' ),
				'why'         => __( 'If any part of the path can be influenced by input, this is a local file inclusion vulnerability.', 'preflight-audit' ),
				'remediation' => __( 'Validate the variable against an explicit allow-list of filenames before including it, and anchor the path to a known base directory.', 'preflight-audit' ),
				'alternative' => __( 'For template loading, use locate_template() or get_template_part(), which resolve against the theme only.', 'preflight-audit' ),
				'doc'         => $security_doc,
				'match'       => array(
					'dynamic_include' => true,
				),
				'guard'       => array( Guards::class, 'include_path_is_unanchored' ),
			),

			'security.missing-abspath-guard' => array(
				'title'       => __( 'PHP file with no direct-access guard', 'preflight-audit' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_RECOMMENDATION,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A PHP file that declares functions or classes without checking for ABSPATH first.', 'preflight-audit' ),
				'why'         => __( 'Without the guard the file executes if it is requested directly, outside the WordPress bootstrap, which can expose errors or run code without any of the usual context.', 'preflight-audit' ),
				'remediation' => __( "Add defined( 'ABSPATH' ) || exit; near the top of the file, below the file docblock.", 'preflight-audit' ),
				'alternative' => '',
				'doc'         => $security_doc,
				'match'       => array(
					'missing_abspath_guard' => true,
				),
			),
		);
	}

	/**
	 * Environment and configuration rules.
	 *
	 * @return array<string, array<string, mixed>> Rule definitions.
	 */
	private static function environment_rules() {
		$platform_doc = 'https://docs.wpvip.com/technical-references/wordpress-on-vip/';

		return array(
			'environment.php-sessions'      => array(
				'title'       => __( 'PHP session used', 'preflight-audit' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'session_start(), another session function, or $_SESSION.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP supports PHP sessions for short-lived data, backed by Memcached, but every request from a user with a session bypasses the page cache. Starting a session for every visitor takes the site off the edge cache.', 'preflight-audit' ),
				'remediation' => __( 'Start a session only when it is needed, scope it with the path argument of session_set_cookie_params(), and destroy it as soon as the flow is done.', 'preflight-audit' ),
				'alternative' => __( 'For logged-in users, user meta is often a better home for per-user state, and it is already cached.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/wordpress-on-vip/php-sessions/',
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions.session_session_start',
				'match'       => array(
					'functions'    => array( 'session_start', 'session_id', 'session_regenerate_id', 'session_destroy', 'session_name', 'session_set_cookie_params' ),
					'superglobals' => array( '_SESSION' ),
				),
			),

			'environment.runtime-ini-change' => array(
				'title'       => __( 'PHP runtime setting changed at request time', 'preflight-audit' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'ini_set(), set_time_limit(), error_reporting(), putenv(), dl() or date_default_timezone_set().', 'preflight-audit' ),
				'why'         => __( 'PHP configuration on WordPress VIP is managed by the platform. These calls are either ignored or overridden, so code that depends on the new value behaves differently in production than it does locally.', 'preflight-audit' ),
				'remediation' => __( 'Remove the call and make the code work within the platform limits. If a limit genuinely blocks the workload, raise it with WordPress VIP support rather than trying to change it from PHP.', 'preflight-audit' ),
				'alternative' => __( 'Work that needs a long execution time belongs in a WP-CLI command or a batched cron job, not a web request.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/errors/',
				'phpcs'       => 'WordPress.PHP.IniSet',
				'match'       => array(
					'functions' => array( 'ini_set', 'ini_alter', 'set_time_limit', 'error_reporting', 'putenv', 'dl', 'setlocale', 'date_default_timezone_set' ),
				),
			),

			'environment.server-introspection' => array(
				'title'       => __( 'Assumption about the server or filesystem layout', 'preflight-audit' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'Code reading the document root, host name, process details or disk statistics.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP runs the application in ephemeral containers behind a proxy. The document root, host name and process identity are not stable and not meaningful, and disk statistics describe a container rather than the site.', 'preflight-audit' ),
				'remediation' => __( 'Use ABSPATH and the WordPress path helpers for locations, home_url()/site_url() for addresses, and the WordPress VIP environment constants to detect the environment.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => $platform_doc,
				'match'       => array(
					'functions'         => array( 'php_uname', 'gethostname', 'getmypid', 'sys_getloadavg', 'disk_free_space', 'disk_total_space', 'get_current_user', 'getcwd', 'chdir' ),
					'server_index_keys' => array( 'DOCUMENT_ROOT', 'SERVER_ADDR', 'SERVER_SOFTWARE', 'PATH_TRANSLATED', 'SCRIPT_FILENAME' ),
				),
			),

			'environment.core-constant-override' => array(
				'title'       => __( 'Core path or behaviour constant redefined', 'preflight-audit' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'A plugin or theme calling define() for a core WordPress constant.', 'preflight-audit' ),
				'why'         => __( 'These constants are set by the platform bootstrap on WordPress VIP. Redefining them from a plugin either has no effect, because the constant is already defined, or points WordPress at a path that does not exist on the platform.', 'preflight-audit' ),
				'remediation' => __( 'Remove the define(). Environment-specific configuration belongs in vip-config.php, which the platform loads before WordPress.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/wordpress-skeleton/',
				'match'       => array(
					'defines' => array(
						'WP_CONTENT_DIR',
						'WP_CONTENT_URL',
						'WP_PLUGIN_DIR',
						'WP_PLUGIN_URL',
						'WPMU_PLUGIN_DIR',
						'UPLOADS',
						'WP_MEMORY_LIMIT',
						'WP_MAX_MEMORY_LIMIT',
						'DISALLOW_FILE_MODS',
						'DISALLOW_FILE_EDIT',
						'FS_METHOD',
						'WP_CACHE',
						'SAVEQUERIES',
						'WP_DEBUG',
						'WP_DEBUG_LOG',
						'AUTOMATIC_UPDATER_DISABLED',
					),
				),
			),

			'environment.debug-output'      => array(
				'title'       => __( 'Debug output left in the code', 'preflight-audit' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_STANDARDS,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'error_log(), var_dump(), print_r(), phpinfo() or similar.', 'preflight-audit' ),
				'why'         => __( 'Debug helpers can leak internal detail into a response and add noise to the platform logs. WordPress VIP code review flags them, and phpinfo() in particular discloses the environment.', 'preflight-audit' ),
				'remediation' => __( 'Remove the call. Where logging is genuinely wanted, guard it behind WP_DEBUG and use the platform log rather than printing to the response.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/warnings/',
				'phpcs'       => 'WordPress.PHP.DevelopmentFunctions',
				'match'       => array(
					'functions' => array( 'var_dump', 'var_export', 'print_r', 'phpinfo', 'error_log', 'debug_print_backtrace', 'debug_zval_dump' ),
				),
				'guard'       => array( Guards::class, 'is_debug_output' ),
			),

			'environment.direct-mail'       => array(
				'title'       => __( 'mail() used instead of wp_mail()', 'preflight-audit' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( "A call to PHP's mail() function.", 'preflight-audit' ),
				'why'         => __( 'WordPress VIP code review flags application code that generates email, and expects any mail that must be sent to go through wp_mail(). PHP mail() bypasses the hooks WordPress and its plugins use to deliver, filter and log mail.', 'preflight-audit' ),
				'remediation' => __( 'Replace mail() with wp_mail(). The arguments map directly.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/warnings/',
				'phpcs'       => 'WordPress.PHP.DiscouragedPHPFunctions',
				'match'       => array(
					'functions' => array( 'mail' ),
				),
			),

			'environment.unrestored-switch-to-blog' => array(
				'title'       => __( 'switch_to_blog() without a matching restore', 'preflight-audit' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'switch_to_blog() with no restore_current_blog() in the same function.', 'preflight-audit' ),
				'why'         => __( 'Leaving the site switched corrupts every subsequent query and cache read in the request, and the bug surfaces far from its cause. Switching is also expensive, so doing it inside a loop is a common source of slow pages on multisite.', 'preflight-audit' ),
				'remediation' => __( 'Pair every switch_to_blog() with restore_current_blog(), including on early-return and exception paths.', 'preflight-audit' ),
				'alternative' => __( 'Where the data is read repeatedly across sites, collect it once and cache it rather than switching per item.', 'preflight-audit' ),
				'doc'         => 'https://docs.wpvip.com/technical-references/code-quality-and-best-practices/',
				'match'       => array(
					'functions' => array( 'switch_to_blog' ),
				),
				'guard'       => array( Guards::class, 'switch_to_blog_unrestored' ),
			),
		);
	}

	/**
	 * Coding-standard rules mapped to the WordPress-VIP-Go PHPCS standard.
	 *
	 * @return array<string, array<string, mixed>> Rule definitions.
	 */
	private static function standards_rules() {
		return array(
			'standards.flush-rewrite-rules' => array(
				'title'       => __( 'flush_rewrite_rules() called at runtime', 'preflight-audit' ),
				'category'    => 'standards',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to flush_rewrite_rules().', 'preflight-audit' ),
				'why'         => __( 'Regenerating the rewrite rules is expensive and writes a large autoloaded option. Called on a hook such as "init" it runs on every request, which WordPress VIP treats as a serious performance defect.', 'preflight-audit' ),
				'remediation' => __( 'Call it only from an activation hook or a WP-CLI command, never on a request-time hook.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/warnings/',
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules',
				'match'       => array(
					'functions' => array( 'flush_rewrite_rules' ),
				),
			),

			'standards.unsafe-redirect'     => array(
				'title'       => __( 'wp_redirect() instead of wp_safe_redirect()', 'preflight-audit' ),
				'category'    => 'standards',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A call to wp_redirect().', 'preflight-audit' ),
				'why'         => __( 'wp_redirect() will send a visitor to any host. When the destination comes from a request parameter that is an open-redirect vulnerability.', 'preflight-audit' ),
				'remediation' => __( 'Use wp_safe_redirect(), which restricts the destination to allowed hosts, and add external hosts with the "allowed_redirect_hosts" filter when you need them.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/warnings/',
				'phpcs'       => 'WordPressVIPMinimum.Security.ExitAfterRedirect',
				'match'       => array(
					'functions' => array( 'wp_redirect' ),
				),
			),

			'standards.deprecated-function' => array(
				'title'       => __( 'Deprecated or removed function', 'preflight-audit' ),
				'category'    => 'standards',
				'type'        => Taxonomy::TYPE_STANDARDS,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A WordPress or PHP function that has been deprecated or removed.', 'preflight-audit' ),
				'why'         => __( 'WordPress VIP tracks current WordPress and PHP versions closely. Deprecated calls emit notices, and the removed PHP extensions in this list cause fatal errors on PHP 8.', 'preflight-audit' ),
				'remediation' => __( 'Replace the call with its current equivalent.', 'preflight-audit' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/technical-references/code-quality-and-best-practices/',
				'phpcs'       => 'WordPress.WP.DeprecatedFunctions',
				'match'       => array(
					'functions' => array(
						'create_function',
						'get_page_by_title',
						'get_currentuserinfo',
						'get_userdatabylogin',
						'get_settings',
						'screen_icon',
						'attribute_escape',
						'wp_specialchars',
						'is_taxonomy',
						'wp_get_sites',
						'get_the_author_description',
						'mysql_query',
						'mysql_connect',
						'mysql_fetch_array',
						'each',
						'ereg',
						'eregi',
						'split',
						'money_format',
					),
				),
			),
		);
	}
}
