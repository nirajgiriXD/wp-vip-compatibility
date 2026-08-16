<?php
/**
 * The WordPress VIP compatibility rule registry.
 *
 * Every rule in here has to justify itself against the WordPress VIP Platform, not
 * against generic WordPress best practice. A rule earns its place when at
 * least one of the following is true:
 *
 * - The pattern fails or misbehaves on WordPress VIP because of a platform constraint
 *   (read-only filesystem, no PHP sessions, NGINX instead of Apache, the
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
 * `wvc_scanner_rules`.
 *
 * @package wp-vip-compatibility
 */

namespace WP_VIP_COMPATIBILITY\Includes\Scanner;

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
	const VERSION = '2.0.0';

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
		$rules = apply_filters( 'wvc_scanner_rules', $rules );

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
				'doc'         => 'https://docs.wpvip.com/technical-references/code-review/',
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
				'title'       => __( 'Filesystem write outside the writable paths', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A write, delete, move or permission-change call whose target does not resolve to the uploads directory or /tmp/.', 'wp-vip-compatibility' ),
				'why'         => __( 'Application code on WordPress VIP is deployed from Git onto a read-only filesystem. Only /tmp/ and /wp-content/uploads/ accept writes. Writing anywhere else — including into a plugin or theme directory — fails at runtime.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Point the write at the uploads directory using wp_get_upload_dir(), or at get_temp_dir() for scratch data that does not need to survive the request. Never build the path by concatenating WP_CONTENT_DIR, plugin_dir_path() or __DIR__.', 'wp-vip-compatibility' ),
				'alternative' => __( 'If the data is state rather than a file, store it in an option, post meta, a custom table or the object cache instead of on disk.', 'wp-vip-compatibility' ),
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
				'title'       => __( 'File opened for writing outside the writable paths', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'fopen() called with a write, append or truncate mode on a path that does not resolve to uploads or /tmp/.', 'wp-vip-compatibility' ),
				'why'         => __( 'Opening a file for reading is fine on WordPress VIP. Opening one for writing outside /tmp/ and the uploads directory fails, because the application filesystem is read-only.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Write to a path built from wp_get_upload_dir(), or to get_temp_dir() for transient scratch files. The WordPress VIP stream wrapper makes fopen(), fwrite() and fclose() work normally inside /wp-content/uploads/.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For read-only access to a bundled data file, keep the current path and the "r" mode — that is supported.', 'wp-vip-compatibility' ),
				'doc'         => $uploads_doc,
				'match'       => array(
					'functions' => array( 'fopen' ),
				),
				'guard'       => array( Guards::class, 'fopen_writes_outside_writable' ),
			),

			'filesystem.hardcoded-uploads-path'       => array(
				'title'       => __( 'Hard-coded uploads path', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A literal "wp-content/uploads" path in the source.', 'wp-vip-compatibility' ),
				'why'         => __( 'On WordPress VIP the uploads directory is mapped onto an external object store, and on multisite each subsite writes under its own prefix. A hard-coded path resolves to the wrong location, or to nothing at all.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Replace the literal with wp_get_upload_dir() (paths) or wp_upload_dir()["baseurl"] (URLs) and append the sub-path to the returned value.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For a static asset that ships with the code, reference it with plugins_url() or get_theme_file_uri() instead of putting it in uploads.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/vip-file-system/modify-uploads-directory/',
				'match'       => array(
					'strings'  => array( '#wp-content/uploads#i' ),
					'contains' => 'wp-content/uploads', // wvc:ignore filesystem.hardcoded-uploads-path -- The needle this rule searches for, not a path this plugin uses.
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'filesystem.blogs-dir'                    => array(
				'title'       => __( 'Legacy blogs.dir media path', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A reference to the pre-3.5 multisite blogs.dir media directory.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP only serves and accepts media under /wp-content/uploads/. Media stored in wp-content/blogs.dir is neither importable nor reachable, so anything that reads or writes there breaks.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Move the media into the uploads directory, update the stored paths in the database with a targeted search-and-replace, and add redirects for the old URLs.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => $uploads_doc,
				'match'       => array(
					'strings'  => array( '#blogs\.dir#i' ),
					'contains' => 'blogs.dir', // wvc:ignore filesystem.blogs-dir -- The needle this rule searches for, not a path this plugin uses.
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'filesystem.directory-iteration'          => array(
				'title'       => __( 'Directory iteration over uploads', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A directory listing or traversal call pointed at the uploads directory.', 'wp-vip-compatibility' ),
				'why'         => __( 'The WordPress VIP File System is an object store presented through a stream wrapper. It has no real directory tree, so listing a directory returns nothing useful and some traversal calls raise a fatal error. This is the failure mode behind the known BuddyPress avatar incompatibility.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Stop discovering files by listing a directory. Record the files you write — in post meta, user meta, an option or a custom table — and read that index back instead.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For attachments, query the media library with WP_Query on the "attachment" post type rather than walking the uploads folder.', 'wp-vip-compatibility' ),
				'doc'         => $fs_doc,
				'match'       => array(
					'functions' => array( 'opendir', 'readdir', 'closedir', 'rewinddir', 'scandir', 'glob' ),
					'classes'   => array( 'DirectoryIterator', 'RecursiveDirectoryIterator', 'FilesystemIterator' ),
				),
				'guard'       => array( Guards::class, 'iterates_uploads' ),
			),

			'filesystem.apache-assumption'            => array(
				'title'       => __( 'Apache or .htaccess assumption', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'Code that reads, writes or depends on .htaccess or the Apache API.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP serves requests through NGINX. There is no .htaccess file, the Apache functions do not exist, and the web root is not writable, so rewrite rules, deny rules and header rules written this way are silently ignored.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Express redirects in PHP — wp_safe_redirect() on the "template_redirect" hook, or the Safe Redirect Manager plugin. Domain-level redirects belong in vip-config.php. Access rules belong in application code.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Send headers with the WordPress HTTP API and the "wp_headers" filter rather than through server configuration.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/technical-references/wordpress-on-vip/',
				'match'       => array(
					'functions' => array( 'insert_with_markers', 'got_mod_rewrite', 'apache_get_modules', 'apache_setenv', 'apache_response_headers', 'apache_request_headers' ),
					'strings'   => array( '#\.htaccess#i' ),
					'contains'  => '.htaccess', // wvc:ignore filesystem.apache-assumption -- The needle this rule searches for, not a file this plugin touches.
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'filesystem.generated-code-asset'         => array(
				'title'       => __( 'Dynamically generated PHP, CSS or JS file', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A write whose target filename ends in .php, .css, .js, .html or .json.', 'wp-vip-compatibility' ),
				'why'         => __( 'Generating code or asset files at runtime assumes a writable application directory, which WordPress VIP does not provide. Even when the write lands in uploads, generated PHP is never executed there, and generated CSS or JS bypasses the platform asset pipeline.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Build the asset at deploy time and commit it, or render it through a WordPress endpoint (wp_add_inline_style(), wp_add_inline_script(), or a registered REST route) instead of writing a file.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For configuration that used to be written to a PHP file, store it in an option and read it back through get_option().', 'wp-vip-compatibility' ),
				'doc'         => $fs_doc,
				'match'       => array(
					'functions' => array( 'file_put_contents', 'fwrite', 'fputs', 'fopen', 'rename', 'copy' ),
				),
				'guard'       => array( Guards::class, 'writes_code_asset' ),
			),

			'filesystem.wp-filesystem-credentials'    => array(
				'title'       => __( 'WP_Filesystem credentials request', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'Use of the WP_Filesystem abstraction or a request for filesystem credentials.', 'wp-vip-compatibility' ),
				'why'         => __( 'WP_Filesystem exists to write into the WordPress install. On WordPress VIP those paths are read-only and file modifications are disabled, so the credentials prompt cannot succeed and the write will not land.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Drop the WP_Filesystem layer and write directly into the uploads directory with the standard PHP file functions — the WordPress VIP stream wrapper handles them.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => $fs_doc,
				'match'       => array(
					'functions' => array( 'request_filesystem_credentials', 'WP_Filesystem', 'get_filesystem_method' ),
				),
			),

			'filesystem.upload-dir-filter'            => array(
				'title'       => __( 'upload_dir filter changes the media location', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A callback attached to the upload_dir filter.', 'wp-vip-compatibility' ),
				'why'         => __( 'Media on WordPress VIP can only be written to paths beginning /wp-content/uploads/. A filter that redirects uploads elsewhere — a plugin folder, a sibling directory, a third-party bucket — produces failed uploads or missing thumbnails.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Keep the base directory as WordPress VIP supplies it and only vary the sub-path underneath it. Confirm the filter still returns a path inside the uploads base directory.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Organise media with taxonomy or post meta instead of custom folder layouts.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/vip-file-system/modify-uploads-directory/',
				'match'       => array(
					'hooks' => array( 'upload_dir' ),
				),
			),

			'filesystem.image-manipulation'           => array(
				'title'       => __( 'Local image processing', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_REDUNDANT,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'Direct use of GD or Imagick to resize, crop or re-encode images.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP resizes, crops and re-encodes images on the fly at the CDN edge from the original upload. Doing the same work in PHP spends request time on something the platform already provides, and writes extra derivative files into the file system.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Request the size you need through the image URL and let the platform generate it, or use wp_get_attachment_image() with a registered size.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Where bespoke processing is genuinely required, run it once on upload rather than on every page render.', 'wp-vip-compatibility' ),
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
				'title'       => __( 'Registered intermediate image sizes', 'wp-vip-compatibility' ),
				'category'    => 'filesystem',
				'type'        => Taxonomy::TYPE_INFORMATIONAL,
				'severity'    => Taxonomy::SEVERITY_INFO,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'A call to add_image_size().', 'wp-vip-compatibility' ),
				'why'         => __( 'Every registered size multiplies the derivative files created per upload, which inflates the media payload that has to be migrated and stored. WordPress VIP can generate sizes on demand from the original, so most registered sizes are avoidable.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Review whether each registered size is used. Request one-off dimensions through the image URL rather than registering a size for them.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/vip-file-system/image-files/',
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
				'title'       => __( 'SQL built by interpolation instead of $wpdb->prepare()', 'wp-vip-compatibility' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_CRITICAL,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A $wpdb query whose SQL contains an interpolated or concatenated variable, with no $wpdb->prepare() call.', 'wp-vip-compatibility' ),
				'why'         => __( 'This is a SQL injection vector. WordPress VIP code review blocks unprepared queries, and the WordPress VIP Code Analysis Bot reports them on every pull request.', 'wp-vip-compatibility' ),
				// The placeholder tokens are named rather than written out: this
				// is a description, not a format string, and printf-style tokens
				// inside a translatable string confuse both PHPCS and translators.
				'remediation' => __( 'Wrap the SQL in $wpdb->prepare() using its string, integer and float placeholders, and pass the values as arguments. Table names cannot be placeholders — build them from $wpdb->prefix and validate against a known list.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Prefer a core API — WP_Query, get_posts(), get_terms() — over hand-written SQL wherever one exists.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/security/',
				'phpcs'       => 'WordPress.DB.PreparedSQL.InterpolatedNotPrepared',
				'match'       => array(
					'methods' => array( 'wpdb->query', 'wpdb->get_results', 'wpdb->get_row', 'wpdb->get_var', 'wpdb->get_col' ),
				),
				'guard'       => array( Guards::class, 'sql_is_unprepared' ),
			),

			'database.direct-query'       => array(
				'title'       => __( 'Direct database query', 'wp-vip-compatibility' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A $wpdb query that bypasses the WordPress data APIs.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP runs behind HyperDB, which splits reads across replicas. Hand-written queries skip the object cache entirely, so each one is a round trip on every request, and they are the usual cause of slow pages at traffic.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Use a core API where one exists. Where a direct query is genuinely needed, wrap the result in wp_cache_get()/wp_cache_set() with an explicit group and expiry.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For search and heavy filtering, use WordPress VIP Search (Enterprise Search) rather than SQL.', 'wp-vip-compatibility' ),
				'doc'         => $query_doc,
				'phpcs'       => 'WordPress.DB.DirectDatabaseQuery.DirectQuery',
				'match'       => array(
					'methods' => array( 'wpdb->query', 'wpdb->get_results', 'wpdb->get_row', 'wpdb->get_var', 'wpdb->get_col', 'wpdb->insert', 'wpdb->update', 'wpdb->replace', 'wpdb->delete' ),
				),
				'guard'       => array( Guards::class, 'query_is_uncached' ),
			),

			'database.schema-change'      => array(
				'title'       => __( 'Runtime database schema change', 'wp-vip-compatibility' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'CREATE TABLE, ALTER TABLE, DROP TABLE, TRUNCATE TABLE or dbDelta() in application code.', 'wp-vip-compatibility' ),
				'why'         => __( 'Custom tables are allowed on WordPress VIP but are expected to be reviewed, and a schema change issued during a web request can lock tables on a production database. Tables created this way also need the InnoDB engine, a supported collation and the wp_ prefix, which ad-hoc SQL frequently gets wrong.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Move schema changes out of the request path into an idempotent WP-CLI command, and specify ENGINE=InnoDB with a WordPress VIP-supported utf8mb4 collation and the $wpdb->prefix prefix.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Consider whether a custom post type, taxonomy or meta table can replace the custom table before adding one.', 'wp-vip-compatibility' ),
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
				'title'       => __( 'Uncached lookup function', 'wp-vip-compatibility' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A core function that WordPress VIP documents as running an uncached database query.', 'wp-vip-compatibility' ),
				'why'         => __( 'These functions query the database directly on every call with no object-cache layer in front of them. On a high-traffic site they turn into an uncached query per page view.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Cache the result yourself with wp_cache_get()/wp_cache_set(), or replace the lookup with one that is cached — get_post(), get_term(), or a WP_Query with the identifier you already hold.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Store the resolved ID alongside the data that needed it so the lookup only happens once.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/databases/optimize-queries/uncached-functions/',
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions',
				'match'       => array(
					'functions' => array(
						'get_page_by_path',
						'get_page_by_title',
						'url_to_postid',
						'attachment_url_to_postid',
						'get_term_by',
						'wp_old_slug_redirect',
						'count_user_posts',
						'get_adjacent_post',
						'get_children',
					),
				),
			),

			'database.unbounded-query'    => array(
				'title'       => __( 'Query with no result limit', 'wp-vip-compatibility' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A query asking for every matching row: posts_per_page => -1, numberposts => -1 or nopaging => true.', 'wp-vip-compatibility' ),
				'why'         => __( 'The query is bounded by however much content the site has. It is fine on a development database and times out or exhausts memory once the site has real volume, which is exactly the scale WordPress VIP sites run at.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Set an explicit posts_per_page. If you genuinely need every row, page through the results in batches inside a WP-CLI command rather than in a web request.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Add "fields" => "ids" when you only need identifiers — it avoids hydrating every post object.', 'wp-vip-compatibility' ),
				'doc'         => $query_doc,
				'phpcs'       => 'WordPress.WP.PostsPerPage.posts_per_page_posts_per_page',
				'match'       => array(
					'functions' => array( 'get_posts', 'query_posts', 'wp_get_recent_posts', 'get_users', 'get_terms' ),
					'classes'   => array( 'WP_Query', 'WP_User_Query', 'WP_Term_Query' ),
				),
				'guard'       => array( Guards::class, 'query_is_unbounded' ),
			),

			'database.expensive-query-arg' => array(
				'title'       => __( 'Query argument known to be expensive', 'wp-vip-compatibility' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A query using post__not_in, an unindexed meta_query, or orderby => rand.', 'wp-vip-compatibility' ),
				'why'         => __( 'These arguments generate SQL the database cannot serve from an index. Random ordering also defeats caching entirely, because every response differs.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Replace post__not_in by over-fetching slightly and filtering in PHP. Replace random ordering by selecting a random slice from a cached ID list. Where meta filtering drives the query, move it to WordPress VIP Search.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Model the filter as a taxonomy term instead of post meta — taxonomy queries are indexed.', 'wp-vip-compatibility' ),
				'doc'         => $query_doc,
				'phpcs'       => 'WordPressVIPMinimum.Performance.WPQueryParams',
				'match'       => array(
					'functions' => array( 'get_posts', 'query_posts', 'wp_get_recent_posts' ),
					'classes'   => array( 'WP_Query' ),
				),
				'guard'       => array( Guards::class, 'query_uses_expensive_args' ),
			),

			'database.autoloaded-option'  => array(
				'title'       => __( 'Explicitly autoloaded option', 'wp-vip-compatibility' ),
				'category'    => 'database',
				'type'        => Taxonomy::TYPE_INFORMATIONAL,
				'severity'    => Taxonomy::SEVERITY_INFO,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'add_option() or update_option() called with autoload set to "yes".', 'wp-vip-compatibility' ),
				'why'         => __( 'Autoloaded options are read into memory on every single request, including cache misses on the front end. A large autoloaded value is a fixed tax on every page render.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Pass "no" for autoload unless the option is genuinely needed on most requests, and keep autoloaded values small.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For data read on a handful of screens, a transient or a custom table is a better fit than an autoloaded option.', 'wp-vip-compatibility' ),
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
				'title'       => __( 'Raw cURL instead of the WordPress HTTP API', 'wp-vip-compatibility' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A direct cURL call.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP expects outbound traffic to go through the WordPress HTTP API so that platform-level timeouts, retries and monitoring apply. Raw cURL sidesteps all of it, and typically ships with no timeout at all, so a slow endpoint stalls the whole request.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Replace the cURL handle with wp_safe_remote_get() or wp_safe_remote_post(), passing an explicit "timeout" of 3 seconds or less.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Use vip_safe_wp_remote_get(), which adds a circuit breaker that stops calling an endpoint that has started failing.', 'wp-vip-compatibility' ),
				'doc'         => $remote_doc,
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions.curl_curl_init',
				'match'       => array(
					'functions' => array( 'curl_init', 'curl_exec', 'curl_setopt', 'curl_setopt_array', 'curl_multi_init', 'curl_multi_exec' ),
				),
			),

			'http.remote-file-read'       => array(
				'title'       => __( 'Remote URL fetched with a filesystem function', 'wp-vip-compatibility' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A filesystem function called with an http:// or https:// URL.', 'wp-vip-compatibility' ),
				'why'         => __( 'Fetching over HTTP with file_get_contents() and friends relies on allow_url_fopen, has no timeout, ignores the platform HTTP layer, and is explicitly reported by the WordPress VIP PHPCS standard.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Use wp_safe_remote_get() and read the body with wp_remote_retrieve_body(), passing an explicit short timeout.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For feeds, fetch_feed() adds caching for free.', 'wp-vip-compatibility' ),
				'doc'         => $remote_doc,
				'phpcs'       => 'WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown',
				'match'       => array(
					'functions' => array( 'file_get_contents', 'readfile', 'file', 'fopen', 'simplexml_load_file', 'get_headers', 'getimagesize', 'copy' ),
				),
				'guard'       => array( Guards::class, 'argument_is_remote_url' ),
			),

			'http.socket-connection'      => array(
				'title'       => __( 'Direct socket or external service connection', 'wp-vip-compatibility' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A raw socket, FTP, SSH, LDAP or third-party database connection.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP containers restrict outbound traffic and do not ship every client extension. Connections on arbitrary ports and protocols are blocked, so this code fails on the platform even though it works locally.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Move the integration behind an HTTPS API reached with the WordPress HTTP API, and raise a WordPress VIP support request to allow-list the host if outbound access is refused.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Where the remote system cannot expose HTTP, run the transfer outside the application and push the results in.', 'wp-vip-compatibility' ),
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
				'title'       => __( 'Remote request with no caching', 'wp-vip-compatibility' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A WordPress HTTP API call with no transient or object-cache call anywhere in the same function.', 'wp-vip-compatibility' ),
				'why'         => __( 'An uncached outbound request runs on every cache miss and blocks page generation until it returns. WordPress VIP treats remote calls on the front end as a primary cause of slow page generation.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Wrap the call in a transient or object-cache read, and pass an explicit "timeout" of 3 seconds or less.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Move the fetch into a scheduled job that refreshes a cached copy, so no visitor request ever waits on the remote service.', 'wp-vip-compatibility' ),
				'doc'         => $remote_doc,
				'match'       => array(
					'functions' => array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_head', 'wp_safe_remote_request' ),
				),
				'guard'       => array( Guards::class, 'remote_request_is_uncached' ),
			),

			'http.unsafe-remote-request'  => array(
				'title'       => __( 'Remote request not protected against SSRF', 'wp-vip-compatibility' ),
				'category'    => 'http',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'wp_remote_* used where the wp_safe_remote_* variant exists.', 'wp-vip-compatibility' ),
				'why'         => __( 'The unsafe variants will follow a URL to a private or internal address. When any part of the URL comes from stored or user input, that is a server-side request forgery vector.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Swap wp_remote_get() for wp_safe_remote_get() (and the same for post/head/request). The signatures are identical.', 'wp-vip-compatibility' ),
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
		$cache_doc = 'https://docs.wpvip.com/caching/page-cache/';

		return array(
			'caching.custom-page-cache'   => array(
				'title'       => __( 'Custom page-cache implementation', 'wp-vip-compatibility' ),
				'category'    => 'caching',
				'type'        => Taxonomy::TYPE_REDUNDANT,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'References to the advanced-cache.php or object-cache.php drop-ins, or to the WP_CACHE constant.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP provides a globally distributed edge page cache and a managed object cache, and installs its own drop-ins. A second caching layer either has no effect or actively conflicts with the platform, which is why the well-known caching plugins are on the WordPress VIP incompatibility list.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Remove the custom caching layer and rely on the platform. Tune behaviour with cache-control headers and the WordPress VIP Cache API instead.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For fragment caching inside a page, use the WordPress object cache API (wp_cache_get/wp_cache_set), which WordPress VIP backs with Memcached.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/caching/',
				'match'       => array(
					'strings'   => array( '#(advanced-cache|object-cache)\.php#i' ),
					'constants' => array( 'WP_CACHE' ),
					'contains'  => 'cache',
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'caching.cache-flush'         => array(
				'title'       => __( 'Full object-cache flush', 'wp-vip-compatibility' ),
				'category'    => 'caching',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to wp_cache_flush().', 'wp-vip-compatibility' ),
				'why'         => __( 'The object cache on WordPress VIP is shared infrastructure. Flushing all of it evicts every other cached value on the site at once and produces a burst of uncached traffic straight onto the database.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Delete only the keys you invalidated with wp_cache_delete(), or move the data into its own cache group and version the group key.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/technical-references/cache-api/',
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions.wp_cache_flush',
				'match'       => array(
					'functions' => array( 'wp_cache_flush' ),
				),
			),

			'caching.cache-bypass-headers' => array(
				'title'       => __( 'Code that disables the page cache', 'wp-vip-compatibility' ),
				'category'    => 'caching',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'nocache_headers(), or a Cache-Control / Pragma / Expires header sent by hand.', 'wp-vip-compatibility' ),
				'why'         => __( 'Sending no-cache headers on a front-end response tells the WordPress VIP edge cache not to store the page. Applied broadly, it takes the site off the CDN and puts every visitor onto the origin.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Scope the header to genuinely per-user responses. Where the page is mostly static with a small dynamic part, cache the page and load the dynamic fragment separately.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Use the WordPress VIP Cache API to set an explicit max-age instead of disabling caching outright.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/caching/page-cache/cache-control-headers/',
				'match'       => array(
					'functions' => array( 'nocache_headers', 'header' ),
				),
				'guard'       => array( Guards::class, 'sends_cache_busting_header' ),
			),

			'caching.frontend-cookie'     => array(
				'title'       => __( 'Cookie set on a front-end response', 'wp-vip-compatibility' ),
				'category'    => 'caching',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_LOW,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to setcookie() or setrawcookie().', 'wp-vip-compatibility' ),
				'why'         => __( 'Cookies interact with the edge cache: the wrong cookie name on a front-end response can make every visitor a cache miss. Only a defined set of cookie prefixes is safe to vary on.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Check the cookie against the WordPress VIP cache-personalisation rules, and set it only on the responses that genuinely need it rather than on every page load.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Read the value in JavaScript after the cached page loads, so the HTML itself stays cacheable.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/technical-references/caching/vip-cache-personalization/',
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
				'title'       => __( 'WP-Cron constant defined in application code', 'wp-vip-compatibility' ),
				'category'    => 'cron',
				'type'        => Taxonomy::TYPE_REDUNDANT,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'DISABLE_WP_CRON, ALTERNATE_WP_CRON or WP_CRON_LOCK_TIMEOUT defined by a plugin or theme.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP replaces WP-Cron with Cron Control, which runs events from a managed runner rather than from page loads. Redefining these constants either does nothing or interferes with the platform runner.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Remove the constant. Cron Control already guarantees that scheduled events run without depending on site traffic.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => $cron_doc,
				'match'       => array(
					'constants' => array( 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'WP_CRON_LOCK_TIMEOUT' ),
				),
			),

			'cron.manual-invocation'      => array(
				'title'       => __( 'Manual cron invocation or cron array manipulation', 'wp-vip-compatibility' ),
				'category'    => 'cron',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'spawn_cron(), a direct call into wp-cron.php, or direct reads and writes of the cron array.', 'wp-vip-compatibility' ),
				'why'         => __( 'Cron Control owns the event queue on WordPress VIP. Code that spawns its own cron run or rewrites the cron array fights the platform runner and can drop or duplicate events.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Schedule work with wp_schedule_event() and wp_schedule_single_event() and let Cron Control run it.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For work that must run on a precise schedule or take a long time, use a WP-CLI command triggered by Cron Control.', 'wp-vip-compatibility' ),
				'doc'         => $cron_doc,
				'match'       => array(
					'functions' => array( 'spawn_cron', '_get_cron_array', '_set_cron_array' ),
					'strings'   => array( '#wp-cron\.php#i' ),
					'contains'  => 'cron',
				),
				'guard'       => array( Guards::class, 'string_is_literal_value' ),
			),

			'cron.custom-schedule'        => array(
				'title'       => __( 'Custom cron schedule', 'wp-vip-compatibility' ),
				'category'    => 'cron',
				'type'        => Taxonomy::TYPE_INFORMATIONAL,
				'severity'    => Taxonomy::SEVERITY_INFO,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'A callback registered on the cron_schedules filter.', 'wp-vip-compatibility' ),
				'why'         => __( 'Cron Control executes events at a bounded concurrency. Very short intervals produce a backlog rather than more frequent execution, so a custom schedule is worth checking against how long the job actually takes.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Confirm the interval is at least a minute and that the job finishes well inside it. Long jobs should be split into batches.', 'wp-vip-compatibility' ),
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
				'title'       => __( 'Shell command execution', 'wp-vip-compatibility' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_CRITICAL,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'A call that shells out to the operating system.', 'wp-vip-compatibility' ),
				'why'         => __( 'Process execution is disabled in WordPress VIP application containers, so these calls fail. They are also a command-injection risk whenever any part of the command is built from input.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Replace the external binary with a PHP library or a WordPress API. Where the work is genuinely a batch job, implement it as a WP-CLI command.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For media processing specifically, the platform already handles resizing and format conversion — no external binary is needed.', 'wp-vip-compatibility' ),
				'doc'         => $security_doc,
				'phpcs'       => 'WordPress.PHP.DiscouragedPHPFunctions',
				'match'       => array(
					'functions' => array( 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec', 'pcntl_fork' ),
					'shell'     => true,
				),
			),

			'security.dynamic-code'        => array(
				'title'       => __( 'Dynamic code execution', 'wp-vip-compatibility' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_CRITICAL,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'eval(), create_function(), or assert() called with a string.', 'wp-vip-compatibility' ),
				'why'         => __( 'Executing code assembled at runtime is a remote-code-execution risk and cannot be reviewed statically. WordPress VIP code review rejects it, and create_function() was removed in PHP 8.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Rewrite the dynamic code as a real function, a closure, or a lookup table of allowed behaviours.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => $security_doc,
				'phpcs'       => 'Squiz.PHP.Eval.Discouraged',
				'match'       => array(
					'functions' => array( 'eval', 'create_function', 'assert' ),
				),
			),

			'security.unserialize'         => array(
				'title'       => __( 'Unserialising possibly untrusted data', 'wp-vip-compatibility' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to unserialize().', 'wp-vip-compatibility' ),
				'why'         => __( 'PHP object injection through unserialize() can lead to remote code execution when the payload is attacker-controlled. It is on the WordPress VIP restricted-function list.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Use JSON for data interchange, and maybe_unserialize() only for values WordPress itself serialised.', 'wp-vip-compatibility' ),
				'alternative' => __( 'If unserialize() is unavoidable, pass ["allowed_classes" => false].', 'wp-vip-compatibility' ),
				'doc'         => $security_doc,
				'phpcs'       => 'WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize',
				'match'       => array(
					'functions' => array( 'unserialize' ),
				),
			),

			'security.unescaped-superglobal-output' => array(
				'title'       => __( 'Request data echoed without escaping', 'wp-vip-compatibility' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A superglobal printed directly by echo or print.', 'wp-vip-compatibility' ),
				'why'         => __( 'This is a reflected cross-site scripting vector. WordPress VIP code review treats unescaped output as a blocker.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Sanitise on input with sanitize_text_field( wp_unslash( ... ) ) and escape on output with esc_html(), esc_attr() or esc_url() as appropriate.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => $security_doc,
				'phpcs'       => 'WordPress.Security.EscapeOutput.OutputNotEscaped',
				'match'       => array(
					'echoed_superglobals' => true,
				),
			),

			'security.extract'             => array(
				'title'       => __( 'extract() imports variables from an array', 'wp-vip-compatibility' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to extract().', 'wp-vip-compatibility' ),
				'why'         => __( 'extract() creates variables whose names come from data, so it can overwrite existing variables and makes the surrounding code impossible to reason about or review.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Read the array keys explicitly, or destructure with list()/[] so the variable names are visible in the source.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => $security_doc,
				'phpcs'       => 'WordPress.PHP.DontExtract.extract_extract',
				'match'       => array(
					'functions' => array( 'extract' ),
				),
			),

			'security.dynamic-include'     => array(
				'title'       => __( 'File included from a variable path', 'wp-vip-compatibility' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'include or require whose path is built from a variable.', 'wp-vip-compatibility' ),
				'why'         => __( 'If any part of the path can be influenced by input, this is a local file inclusion vulnerability.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Validate the variable against an explicit allow-list of filenames before including it, and anchor the path to a known base directory.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For template loading, use locate_template() or get_template_part(), which resolve against the theme only.', 'wp-vip-compatibility' ),
				'doc'         => $security_doc,
				'match'       => array(
					'dynamic_include' => true,
				),
				'guard'       => array( Guards::class, 'include_path_is_unanchored' ),
			),

			'security.missing-abspath-guard' => array(
				'title'       => __( 'PHP file with no direct-access guard', 'wp-vip-compatibility' ),
				'category'    => 'security',
				'type'        => Taxonomy::TYPE_RECOMMENDATION,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A PHP file that declares functions or classes without checking for ABSPATH first.', 'wp-vip-compatibility' ),
				'why'         => __( 'Without the guard the file executes if it is requested directly, outside the WordPress bootstrap, which can expose errors or run code without any of the usual context.', 'wp-vip-compatibility' ),
				'remediation' => __( "Add defined( 'ABSPATH' ) || exit; near the top of the file, below the file docblock.", 'wp-vip-compatibility' ),
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
				'title'       => __( 'PHP session used', 'wp-vip-compatibility' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_ARCHITECTURAL,
				'detected'    => __( 'session_start(), another session function, or $_SESSION.', 'wp-vip-compatibility' ),
				'why'         => __( 'PHP sessions are not supported on WordPress VIP. Requests are served by many containers with no shared session store, so a session written on one request is missing on the next, and starting a session sends headers that take the response out of the page cache.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Move per-user state into a cookie, user meta, or the object cache keyed by a value the client sends. Move short-lived flow state into a signed token in the URL.', 'wp-vip-compatibility' ),
				'alternative' => __( 'For logged-in users, user meta is the direct replacement and is already cached.', 'wp-vip-compatibility' ),
				'doc'         => 'https://docs.wpvip.com/wordpress-on-vip/php-sessions/',
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions.session_session_start',
				'match'       => array(
					'functions'    => array( 'session_start', 'session_id', 'session_regenerate_id', 'session_destroy', 'session_name', 'session_set_cookie_params' ),
					'superglobals' => array( '_SESSION' ),
				),
			),

			'environment.runtime-ini-change' => array(
				'title'       => __( 'PHP runtime setting changed at request time', 'wp-vip-compatibility' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'ini_set(), set_time_limit(), error_reporting(), putenv() or dl().', 'wp-vip-compatibility' ),
				'why'         => __( 'PHP configuration on WordPress VIP is managed by the platform. These calls are either ignored or overridden, so code that depends on the new value behaves differently in production than it does locally.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Remove the call and make the code work within the platform limits. If a limit genuinely blocks the workload, raise it with WordPress VIP support rather than trying to change it from PHP.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Work that needs a long execution time belongs in a WP-CLI command or a batched cron job, not a web request.', 'wp-vip-compatibility' ),
				'doc'         => $platform_doc,
				'phpcs'       => 'WordPress.PHP.IniSet',
				'match'       => array(
					'functions' => array( 'ini_set', 'ini_alter', 'set_time_limit', 'error_reporting', 'putenv', 'dl', 'setlocale' ),
				),
			),

			'environment.server-introspection' => array(
				'title'       => __( 'Assumption about the server or filesystem layout', 'wp-vip-compatibility' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'Code reading the document root, host name, process details or disk statistics.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP runs the application in ephemeral containers behind a proxy. The document root, host name and process identity are not stable and not meaningful, and disk statistics describe a container rather than the site.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Use ABSPATH and the WordPress path helpers for locations, home_url()/site_url() for addresses, and the WordPress VIP environment constants to detect the environment.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => $platform_doc,
				'match'       => array(
					'functions'         => array( 'php_uname', 'gethostname', 'getmypid', 'sys_getloadavg', 'disk_free_space', 'disk_total_space', 'get_current_user', 'getcwd', 'chdir' ),
					'server_index_keys' => array( 'DOCUMENT_ROOT', 'SERVER_ADDR', 'SERVER_SOFTWARE', 'PATH_TRANSLATED', 'SCRIPT_FILENAME' ),
				),
			),

			'environment.core-constant-override' => array(
				'title'       => __( 'Core path or behaviour constant redefined', 'wp-vip-compatibility' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_CONFIGURATION,
				'detected'    => __( 'A plugin or theme calling define() for a core WordPress constant.', 'wp-vip-compatibility' ),
				'why'         => __( 'These constants are set by the platform bootstrap on WordPress VIP. Redefining them from a plugin either has no effect, because the constant is already defined, or points WordPress at a path that does not exist on the platform.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Remove the define(). Environment-specific configuration belongs in vip-config.php, which the platform loads before WordPress.', 'wp-vip-compatibility' ),
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
				'title'       => __( 'Debug output left in the code', 'wp-vip-compatibility' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_STANDARDS,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'error_log(), var_dump(), print_r(), phpinfo() or similar.', 'wp-vip-compatibility' ),
				'why'         => __( 'Debug helpers can leak internal detail into a response and add noise to the platform logs. WordPress VIP code review flags them, and phpinfo() in particular discloses the environment.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Remove the call. Where logging is genuinely wanted, guard it behind WP_DEBUG and use the platform log rather than printing to the response.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/warnings/',
				'phpcs'       => 'WordPress.PHP.DevelopmentFunctions',
				'match'       => array(
					'functions' => array( 'var_dump', 'var_export', 'print_r', 'phpinfo', 'error_log', 'debug_print_backtrace', 'debug_zval_dump' ),
				),
				'guard'       => array( Guards::class, 'is_debug_output' ),
			),

			'environment.direct-mail'       => array(
				'title'       => __( 'mail() used instead of wp_mail()', 'wp-vip-compatibility' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( "A call to PHP's mail() function.", 'wp-vip-compatibility' ),
				'why'         => __( 'Outbound mail on WordPress VIP is delivered through the platform mail service, which hooks wp_mail(). PHP mail() bypasses that entirely, so the message is not sent and not logged.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Replace mail() with wp_mail(). The arguments map directly.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => $platform_doc,
				'phpcs'       => 'WordPress.PHP.DiscouragedPHPFunctions',
				'match'       => array(
					'functions' => array( 'mail' ),
				),
			),

			'environment.unrestored-switch-to-blog' => array(
				'title'       => __( 'switch_to_blog() without a matching restore', 'wp-vip-compatibility' ),
				'category'    => 'environment',
				'type'        => Taxonomy::TYPE_POTENTIALLY_INCOMPATIBLE,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_MEDIUM,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'switch_to_blog() with no restore_current_blog() in the same function.', 'wp-vip-compatibility' ),
				'why'         => __( 'Leaving the site switched corrupts every subsequent query and cache read in the request, and the bug surfaces far from its cause. Switching is also expensive, so doing it inside a loop is a common source of slow pages on multisite.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Pair every switch_to_blog() with restore_current_blog(), including on early-return and exception paths.', 'wp-vip-compatibility' ),
				'alternative' => __( 'Where the data is read repeatedly across sites, collect it once and cache it rather than switching per item.', 'wp-vip-compatibility' ),
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
				'title'       => __( 'flush_rewrite_rules() called at runtime', 'wp-vip-compatibility' ),
				'category'    => 'standards',
				'type'        => Taxonomy::TYPE_PERFORMANCE,
				'severity'    => Taxonomy::SEVERITY_HIGH,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_MANUAL,
				'detected'    => __( 'A call to flush_rewrite_rules().', 'wp-vip-compatibility' ),
				'why'         => __( 'Regenerating the rewrite rules is expensive and writes a large autoloaded option. Called on a hook such as "init" it runs on every request, which WordPress VIP treats as a serious performance defect.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Call it only from an activation hook or a WP-CLI command, never on a request-time hook.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/php_codesniffer/warnings/',
				'phpcs'       => 'WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules',
				'match'       => array(
					'functions' => array( 'flush_rewrite_rules' ),
				),
			),

			'standards.unsafe-redirect'     => array(
				'title'       => __( 'wp_redirect() instead of wp_safe_redirect()', 'wp-vip-compatibility' ),
				'category'    => 'standards',
				'type'        => Taxonomy::TYPE_SECURITY,
				'severity'    => Taxonomy::SEVERITY_LOW,
				'confidence'  => Taxonomy::CONFIDENCE_HIGH,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A call to wp_redirect().', 'wp-vip-compatibility' ),
				'why'         => __( 'wp_redirect() will send a visitor to any host. When the destination comes from a request parameter that is an open-redirect vulnerability.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Use wp_safe_redirect(), which restricts the destination to allowed hosts, and add external hosts with the "allowed_redirect_hosts" filter when you need them.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/security/',
				'phpcs'       => 'WordPressVIPMinimum.Security.ExitAfterRedirect',
				'match'       => array(
					'functions' => array( 'wp_redirect' ),
				),
			),

			'standards.deprecated-function' => array(
				'title'       => __( 'Deprecated or removed function', 'wp-vip-compatibility' ),
				'category'    => 'standards',
				'type'        => Taxonomy::TYPE_STANDARDS,
				'severity'    => Taxonomy::SEVERITY_MEDIUM,
				'confidence'  => Taxonomy::CONFIDENCE_DEFINITIVE,
				'fixability'  => Taxonomy::FIX_AUTOMATIC,
				'detected'    => __( 'A WordPress or PHP function that has been deprecated or removed.', 'wp-vip-compatibility' ),
				'why'         => __( 'WordPress VIP tracks current WordPress and PHP versions closely. Deprecated calls emit notices, and the removed PHP extensions in this list cause fatal errors on PHP 8.', 'wp-vip-compatibility' ),
				'remediation' => __( 'Replace the call with its current equivalent before migrating.', 'wp-vip-compatibility' ),
				'alternative' => '',
				'doc'         => 'https://docs.wpvip.com/technical-references/code-quality-and-best-practices/',
				'phpcs'       => 'WordPress.WP.DeprecatedFunctions',
				'match'       => array(
					'functions' => array(
						'create_function',
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
