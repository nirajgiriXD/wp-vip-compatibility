=== Switchrail Audit ===
Contributors: nirajgirixd, mi5t4n
Tags: coding standards, compatibility, code analysis, code review, phpcs
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.en.html

Check a site's code against the WordPress VIP platform guidelines and coding standard, with an actionable fix for every issue found.

== Description ==

This plugin checks a site's plugins, themes, database and file layout against the publicly documented WordPress VIP platform guidelines and the WordPress-VIP-Go coding standard. It reports what breaks those guidelines, and what to change it to.

Use it as an everyday coding-standard and compatibility check, since the guidelines are a strict, well-documented bar for performance, security and scale on any host. Or use it as a checklist before moving a site to the platform.

It scans every plugin, theme and must-use plugin with a PHP tokeniser rather than pattern-matching raw text, so a function name in a comment, a docblock or a string literal is not mistaken for a call, and `$pdo->exec()` is not mistaken for a shell command. Where a value cannot be resolved at analysis time, the finding says so and records its confidence instead of asserting an incompatibility it cannot prove.

= Findings are separated by what they actually are =

* **Incompatible** — likely to fail on the platform.
* **Potentially incompatible** — depends on runtime conditions; needs a human check.
* **Performance concern** — works, but does not scale.
* **Security concern** — the kind of issue the platform's code-review guidelines call out.
* **Redundant on WordPress VIP** — the platform already provides it.
* **Coding-standard violation** — reported by the WordPress-VIP-Go PHPCS standard.
* **Recommendation** and **Informational**.

Each is scored independently for severity, detection confidence, and the kind of work the fix needs (automatic, manual, configuration, or architectural).

That is the scanner's reasoning: it drives the ranking and is in every export. What the interface leads with is a single instruction derived from it — **must fix**, **should fix**, **worth checking** or **FYI** — used on every screen, so there is no vocabulary to learn before the report can be read.

= What every finding tells you =

The issue title, what was detected, why it matters on the WordPress VIP Platform specifically, the file, line and function, the line of code as evidence, the recommended fix, an alternative approach where one exists, a link to the WordPress VIP documentation the rule came from, and the rule id for a `switchrail-audit:ignore` annotation. Exports also carry the severity, confidence and matching WordPress-VIP-Go PHPCS sniff.

= What gets checked =

* **Filesystem and media** — writes outside `/tmp/` and the uploads directory, directory traversal over the WordPress VIP File System object store, hard-coded upload paths, generated PHP/CSS/JS files, `.htaccess` and Apache assumptions, and local image processing the platform already performs.
* **Database** — storage engines, collations and table prefixes, plus unprepared SQL, uncached queries, unbounded result sets and runtime schema changes.
* **Caching** — cache-busting headers, full object-cache flushes, and custom caching layers that duplicate the platform edge cache.
* **Cron** — WP-Cron constants and manual cron invocation that conflict with Cron Control.
* **External requests** — raw cURL, sockets, remote URLs fetched through filesystem functions, and uncached or untimed HTTP calls.
* **Security** — shell execution, dynamic code execution, unserialisation, unescaped request data and dynamic includes.
* **Environment** — PHP sessions, runtime `ini_set()`, server-layout assumptions, and redefined core constants.
* **Platform overlap** — plugins WordPress VIP documents as incompatible, plugins that need testing, and plugins duplicating something WordPress VIP already provides.

= Reporting =

An overall score and the first three things to do; a map of every plugin, theme, table and path on the site, one square each, by verdict; one ranked list of fixes with the recommended change on the face of every entry, filtered from a column of one-click links; per-plugin, per-theme and per-table breakdowns; scan history with a change summary between runs; and export to JSON, CSV or Markdown for CI, an analysis sheet or a pull request.

The admin menu carries a count of what must be fixed, so the work is visible from anywhere in wp-admin without opening the plugin.

= Accuracy =

Results are only as good as their false-positive rate, so the scanner resolves local variable assignments before judging a path, checks the `fopen()` mode so a file opened only for reading is not reported as a write, checks whether a query is cached before calling it uncached, and recognises that an admin-only response is not part of the edge cache. Where it still cannot be sure, it lowers the confidence and says why. Findings can be acknowledged in the source with a `// switchrail-audit:ignore <rule-id> -- reason` annotation.

The plugin holds itself to the same standard: its own source passes `phpcs --standard=WordPress-VIP-Go`.

= Disclaimer =

Not affiliated with or endorsed by Automattic, WordPress VIP, or the WordPress Foundation.

== Installation ==

1. Download the plugin ZIP file.
2. Go to your WordPress admin dashboard.
3. Navigate to **Plugins > Add New** and click on **Upload Plugin**.
4. Choose the downloaded ZIP file and click **Install Now**.
5. After installation, click **Activate** to enable the plugin.

== Usage ==

Open **Switchrail Audit** in the admin menu and run a scan from the header. Results are stored, so screens load without re-analysing the codebase. A plugin or theme updated through WordPress, and everything after a rule-set update, is scanned again the next time its screen opens. Code changed any other way, such as a deploy or an edit over SFTP, needs a **Rescan**.

**Rescan** re-reads everything. The caret beside it narrows the rescan to one area — plugins, themes, must-use plugins, the database schema or wp-content — which is what you want after updating a single plugin or converting a few tables, and avoids re-tokenising a codebase that has not changed.

Choosing an area takes you straight to that screen and reads its rows one at a time behind a progress bar, so you watch the work happen instead of waiting on a blank page. Stopping partway is safe: rows that never resolved stay marked unscanned and are picked up the next time you open that screen.

**Overview** answers how the site measures up and what to do first. **Findings** is one ranked list of fixes, worst first, with the recommended change visible on every card. **Plugins**, **Themes** and **Must-use** give a per-target verdict, with each row expanding to the evidence behind it. **Database** and **wp-content** check the schema and the application structure against what WordPress VIP requires.

Scan results are stored in the options table and are only readable by users who can `manage_options`, or on multisite by network administrators, since the code and the database are shared by every site. The `switchrail_audit_capability` filter changes this. Results are removed when the plugin is uninstalled.

== Frequently Asked Questions ==

= Do I need to host on WordPress VIP to use this? =

No. The guidelines it checks against are public, and most describe good practice on any host. A few findings, such as "Redundant on WordPress VIP" or the wp-content layout, only matter if you host there or plan to.

= Does a "Needs work" verdict mean the plugin cannot be used? =

No. It means at least one finding is likely to cause problems on the WordPress VIP platform as written. Most are fixable — open Findings for the specific change required.

= Why is `uploads/` not reported as incompatible? =

Because it is not. `/wp-content/uploads/` is the one path under `wp-content` that application code may write to on WordPress VIP. It is imported with the WordPress VIP CLI rather than committed to the repository, which is why it is listed as "Not deployed" rather than as a problem.

= Can I suppress a finding I have reviewed? =

Yes. Add `// switchrail-audit:ignore <rule-id> -- your reason` to the line, or `// switchrail-audit:ignore-next-line <rule-id> -- your reason` above it. A reason is required. Security findings cannot be suppressed, so a plugin cannot hide its own shell calls or unescaped output.

= Can I add or change rules? =

Yes. Filter `switchrail_audit_scanner_rules` to add, modify or remove rules, and `switchrail_audit_scanner_excluded_directories` to change which directories are skipped.

= Does this replace WordPress VIP code review? =

No. It reads code without running it, so it cannot see behaviour that only appears under real traffic or real data. Test on a WordPress VIP environment before relying on the result.

== Changelog ==

= 1.0.0 =

* Initial release.
* Scans every plugin, theme and must-use plugin with a PHP tokeniser against 47 rules drawn from the WordPress VIP platform guidelines, each with a recommended fix and a documentation link.
* Audits the database schema (engine, collation and prefix) and the wp-content layout.
* Ranks every finding as must fix, should fix, worth checking or FYI, and gives each plugin, theme, table and path a verdict.
* An overview with a score, where to start and scan history; a findings list with filters and search; export to JSON, CSV or Markdown.
* Rescans can be narrowed to one area: plugins, themes, must-use plugins, the database or wp-content.
* Findings can be acknowledged in the source with a `switchrail-audit:ignore` annotation.

== Screenshots ==

1. Overview with the score, the outstanding work by tier, and where to start.
2. Findings, filtered to one tier, with a finding expanded to its evidence, rationale and remediation.
3. Plugins, with a row expanded to its author, location and findings.
4. Themes, with the active theme expanded.
5. Must-use plugins, each with where it belongs on WordPress VIP.
6. The database audit with per-table verdicts and the SQL to fix them.
7. The wp-content layout measured against the WordPress VIP application structure.
