# Preflight Audit

Check a site's code against the [WordPress VIP platform guidelines](https://docs.wpvip.com/) and coding standard, with an actionable fix for every issue found.

> Not affiliated with or endorsed by Automattic, WordPress VIP, or the WordPress Foundation.

## What it does

The plugin scans every plugin, theme and must-use plugin on the site against the publicly documented WordPress VIP platform guidelines and the `WordPress-VIP-Go` coding standard, and reports what breaks them — and what to change it to.

Use it two ways:

- **As an everyday code check.** The guidelines are a strict, well-documented bar for performance, security and scale, and most of them are good practice on any host.
- **Before a move to the platform.** The same report doubles as a checklist of what has to change first.

Analysis is done with the PHP tokeniser rather than by matching patterns against raw text. That distinction matters: a function name in a comment, a docblock or a string literal is not a call, `$pdo->exec()` is not a shell command, and `fopen( $path, 'r' )` is not a filesystem write. Where a value cannot be resolved statically, the finding records its confidence rather than asserting an incompatibility it cannot prove.

## How findings are classified

Four independent axes, because collapsing them is what makes a deprecated function look as serious as a shell command:

| Axis | Values |
| --- | --- |
| **Type** | Incompatible · Potentially incompatible · Performance · Security · Redundant on WordPress VIP · Coding standard · Recommendation · Informational |
| **Severity** | Critical · High · Medium · Low · Info |
| **Confidence** | Definitive · High · Medium · Low |
| **Fixability** | Automatic · Manual · Configuration · Architectural |

Those four are the scanner's reasoning. They drive the ranking and are in every export, but stay off the screen. The interface itself uses one vocabulary and one only, derived from severity and phrased as an instruction — **must fix**, **should fix**, **worth checking**, **FYI** — so nobody has to learn that "critical", "incompatible" and "needs work" are three different scales.

A target ends up in one of three states: **passing**, **needs review** (findings worth a look that are unlikely to break anything), or **needs work** (something is likely to cause problems on the platform).

## What every finding tells you

- The issue title and what was detected
- Why it matters **on WordPress VIP specifically**, not as generic WordPress advice
- File, line and enclosing function, with the line of code as evidence
- The recommended fix, and an alternative approach where one exists
- A link to the WordPress VIP documentation page the rule was derived from
- The rule id, copyable, for silencing it with `pfa:ignore` once reviewed

The JSON, CSV and Markdown exports also carry the severity, confidence, fixability and matching `WordPress-VIP-Go` PHPCS sniff of every finding.

## Coverage

| Area | Examples |
| --- | --- |
| **Filesystem and media** | Writes outside `/tmp/` and uploads, directory traversal over the WordPress VIP File System object store, hard-coded upload paths, generated PHP/CSS/JS, `.htaccess` and Apache assumptions, local image processing |
| **Database** | Storage engine, collation and prefix, unprepared SQL, uncached queries, unbounded result sets, runtime schema changes |
| **Caching** | Cache-busting headers, full object-cache flushes, custom caching layers |
| **Cron** | WP-Cron constants and manual invocation that conflict with Cron Control |
| **External requests** | Raw cURL, sockets, remote URLs through filesystem functions, uncached or untimed HTTP calls |
| **Security** | Shell execution, dynamic code, unserialisation, unescaped request data, dynamic includes |
| **Environment** | PHP sessions, runtime `ini_set()`, server-layout assumptions, redefined core constants |
| **Platform overlap** | Plugins WordPress VIP documents as incompatible, plugins needing testing, plugins duplicating platform capabilities |

`wp-content` and the database schema are audited separately against the WordPress VIP application structure and the WordPress VIP collation and engine requirements.

## Screens

The screens are designed as a manifest: a ruled document on a paper ground rather than a dashboard of cards. Colour is spent on urgency only, and every urgency level also has its own shape, so the scale reads without colour.

The admin menu carries a count of what must be fixed, split under headings into three groups — summary, the code you ship, and the site's own infrastructure.

- **Overview** — score, the outstanding work by tier, the first three things to do, a map of every item on the site by verdict, the environment the site runs on, and how the report has moved over recent scans.
- **Findings** — one flat list of fixes, worst first, each entry carrying the recommended fix on its face. Filters sit in a sticky column of links — what to do about it, then what it is about — applied on click and reflected in the URL. Press `/` to jump to search.
- **Plugins** — every installed plugin with its verdict, activation state, available update and severity split; each row expands to its evidence.
- **Themes** — the active theme in detail, plus every other theme that still ships in the repository.
- **Must-use** — everything in `wp-content/mu-plugins`, separated into what to relocate and what to drop.
- **Database** — every table's engine, collation, rows, size and owner, with the tables that need a change listed first and their SQL copyable in one go.
- **wp-content** — every entry measured against the WordPress VIP application structure, with what needs action listed first.

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
$path = '/wp-content/uploads'; // pfa:ignore filesystem.hardcoded-uploads-path -- Documentation string, not a path we use.

// pfa:ignore-next-line environment.runtime-ini-change -- CLI-only script, never runs on a web request.
ini_set( 'memory_limit', '512M' );
```

A reason after `--` is required, so a suppression always records why.

Security findings cannot be suppressed. The annotation lives in the code being scanned, so honouring it for those would let a third-party plugin hide its own shell calls or unescaped output.

## Extending

```php
// Add, change or remove rules.
add_filter( 'preflight_audit_scanner_rules', function ( array $rules ) {
    $rules['acme.custom-rule'] = array( /* … */ );
    unset( $rules['environment.debug-output'] );

    return $rules;
} );

// Change who can use the plugin. Default: manage_options, or
// manage_network_options on multisite.
add_filter( 'preflight_audit_capability', function () {
    return 'manage_options';
} );

// Change which directories are skipped.
add_filter( 'preflight_audit_scanner_excluded_directories', function ( array $excluded ) {
    $excluded[] = 'third-party';

    return $excluded;
} );
```

## Development

```bash
# Tests. Neither suite needs WordPress or a database.
composer install && composer test   # PHP: PHPUnit with Brain Monkey
npm install && npm test             # admin.js: Jest with jsdom

# Coding standards. The plugin's own source passes with no violations.
composer lint

# Translations.
wp i18n make-pot . languages/preflight-audit.pot
```

### Releasing

```bash
npm run bump -- patch   # or minor, major, or an exact version such as 2.3.0
npm run zip             # writes dist/preflight-audit-<version>.zip
```

`bump` updates the plugin header, `PREFLIGHT_AUDIT_VERSION`, the readme.txt `Stable tag`, the `.pot` file and the test bootstrap. Add the changelog entry to readme.txt yourself.

`zip` packs the files git tracks, minus anything in `.distignore`, under a `preflight-audit/` folder. Add `--list` (`npm run zip -- --list`) to print what went in.

Contribution workflow is in [WORKFLOW.md](WORKFLOW.md).

## Requirements

WordPress 6.0+, PHP 7.4+.

## Limitations

This reads code without running it. It cannot see behaviour that only appears under real traffic, real data, or a real WordPress VIP environment, and it does not replace WordPress VIP code review. Treat it as the thing that tells you where to look.
