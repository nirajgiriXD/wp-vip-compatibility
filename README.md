# WordPress VIP Compatibility

Analyse a WordPress site against the [WordPress VIP Platform](https://docs.wpvip.com/) requirements, and get an actionable fix for every issue found.

## What it does

The plugin scans every plugin, theme and must-use plugin on the site and reports what has to change before the site can move to VIP — and what to change it to.

Analysis is done with the PHP tokeniser rather than by matching patterns against raw text. That distinction matters: a function name in a comment, a docblock or a string literal is not a call, `$pdo->exec()` is not a shell command, and `fopen( $path, 'r' )` is not a filesystem write. Where a value cannot be resolved statically, the finding records its confidence rather than asserting an incompatibility it cannot prove.

## How findings are classified

Four independent axes, because collapsing them is what makes a deprecated function look as serious as a shell command:

| Axis | Values |
| --- | --- |
| **Type** | Incompatible · Potentially incompatible · Performance · Security · Redundant on VIP · Coding standard · Recommendation · Informational |
| **Severity** | Critical · High · Medium · Low · Info |
| **Confidence** | Definitive · High · Medium · Low |
| **Fixability** | Automatic · Manual · Configuration · Architectural |

A target ends up in one of three states: **ready**, **needs review** (work to do that will not by itself stop a migration), or **blocked** (something is expected to fail on the platform).

## What every finding tells you

- The issue title and what was detected
- Why it matters **on VIP specifically**, not as generic WordPress advice
- File, line and enclosing function, with the line of code as evidence
- The recommended fix, and an alternative approach where one exists
- The matching `WordPress-VIP-Go` PHPCS sniff, where there is one
- A link to the VIP documentation page the rule was derived from

## Coverage

| Area | Examples |
| --- | --- |
| **Filesystem and media** | Writes outside `/tmp/` and uploads, directory traversal over the VIP File System object store, hard-coded upload paths, generated PHP/CSS/JS, `.htaccess` and Apache assumptions, local image processing |
| **Database** | Storage engine, collation and prefix, unprepared SQL, uncached queries, unbounded result sets, runtime schema changes |
| **Caching** | Cache-busting headers, full object-cache flushes, custom caching layers |
| **Cron** | WP-Cron constants and manual invocation that conflict with Cron Control |
| **External requests** | Raw cURL, sockets, remote URLs through filesystem functions, uncached or untimed HTTP calls |
| **Security** | Shell execution, dynamic code, unserialisation, unescaped request data, dynamic includes |
| **Environment** | PHP sessions, runtime `ini_set()`, server-layout assumptions, redefined core constants |
| **Platform overlap** | Plugins VIP documents as incompatible, plugins needing testing, plugins duplicating platform capabilities |

`wp-content` and the database schema are audited separately against the VIP application structure and the VIP collation and engine requirements.

## Screens

- **Overview** — readiness score, per-category breakdown, change since the last scan.
- **Findings** — every issue, grouped by target, with severity/type/category filters, search, and export.
- **Plugins / Themes / MU Plugins** — per-target verdicts with curated notes for known plugins.
- **Database** — per-table engine, collation, prefix, size and source, with the SQL to fix each problem.
- **Directories** — everything in `wp-content` measured against the VIP application structure.

## Export

JSON (full report, for CI and tooling), CSV (one row per finding, for an analysis sheet) and Markdown (grouped by target, for a ticket or pull request). Exports are streamed through an authenticated download rather than written to a public file.

## Accuracy

False positives are the thing that makes an analyser get ignored, so the scanner:

- Resolves local variable assignments before judging a path, so `$path = wp_get_upload_dir() . '/x'; file_put_contents( $path, … )` is recognised as a legal write.
- Reads `fopen()` modes, so read-only handles are not reported as writes.
- Checks whether a query or remote call is cached before calling it uncached, and looks at the whole file when the caching wrapper is a different function.
- Knows an admin-only response is never in the edge cache, so `nocache_headers()` there is correct.
- Distinguishes a regular expression or a sentence from a real path, so rule data and documentation do not match their own rules.

Where it still cannot be sure, it lowers the confidence and explains why in the finding.

### Suppressing a reviewed finding

```php
$path = '/wp-content/uploads'; // wvc:ignore filesystem.hardcoded-uploads-path -- Documentation string, not a path we use.

// wvc:ignore-next-line security.command-execution -- Fixture data, never executed.
exec( $command );
```

A reason after `--` is required, so a suppression always records why.

## Extending

```php
// Add, change or remove rules.
add_filter( 'wvc_scanner_rules', function ( array $rules ) {
    $rules['acme.custom-rule'] = array( /* … */ );
    unset( $rules['environment.debug-output'] );

    return $rules;
} );

// Change which directories are skipped.
add_filter( 'wvc_scanner_excluded_directories', function ( array $excluded ) {
    $excluded[] = 'third-party';

    return $excluded;
} );
```

## Development

```bash
# Coding standards. The plugin's own source passes with no violations.
composer require --dev automattic/vipwpcs phpcompatibility/phpcompatibility-wp
phpcs --standard=phpcs.xml .

# Translations.
wp i18n make-pot . languages/wp-vip-compatibility.pot
```

Contribution workflow is in [WORKFLOW.md](WORKFLOW.md).

## Requirements

WordPress 6.0+, PHP 7.4+.

## Limitations

This reads code without running it. It cannot see behaviour that only appears under real traffic, real data, or a real VIP environment, and it does not replace VIP code review. Treat it as the thing that tells you where to look.
