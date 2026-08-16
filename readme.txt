=== WordPress VIP Compatibility ===
Contributors: nirajgirixd, mi5t4n
Tags: WordPress VIP, compatibility, migration, code analysis, phpcs
Requires at least: 6.0
Tested up to: 6.7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.en.html

Analyse a WordPress site against the WordPress VIP Platform requirements, and get an actionable fix for every issue found.

== Description ==

This plugin reports what has to change before a WordPress site can move to the WordPress VIP Platform, and what to change it to.

It scans every plugin, theme and must-use plugin with a PHP tokeniser rather than pattern-matching raw text, so a function name in a comment, a docblock or a string literal is not mistaken for a call, and `$pdo->exec()` is not mistaken for a shell command. Where a value cannot be resolved at analysis time, the finding says so and records its confidence instead of asserting an incompatibility it cannot prove.

= Findings are separated by what they actually are =

* **Incompatible** — expected to fail on the platform.
* **Potentially incompatible** — depends on runtime conditions; needs a human check.
* **Performance concern** — works, but does not scale.
* **Security concern** — flagged by WordPress VIP code review.
* **Redundant on WordPress VIP** — the platform already provides it.
* **Coding-standard violation** — reported by the WordPress-VIP-Go PHPCS standard.
* **Recommendation** and **Informational**.

Each is scored independently for severity, detection confidence, and the kind of work the fix needs (automatic, manual, configuration, or architectural).

That is the scanner's reasoning, and it stays one click away on each finding. What the interface leads with is a single instruction derived from it — **must fix**, **should fix**, **worth checking** or **FYI** — used on every screen, so there is no vocabulary to learn before the report can be read.

= What every finding tells you =

The issue title, what was detected, why it matters on the WordPress VIP Platform specifically, the file, line and function, the line of code as evidence, the recommended fix, an alternative approach where one exists, the matching WordPress-VIP-Go PHPCS sniff, and a link to the WordPress VIP documentation the rule came from.

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

An overall readiness score and a ranked list of what to do next; one flat list of fixes with the recommended change on the face of every card, filtered by one-click chips; per-plugin, per-theme and per-table breakdowns; scan history with a change summary between runs; and export to JSON, CSV or Markdown for CI, an analysis sheet or a pull request.

The admin menu carries a count of what must be fixed before migrating, so the work is visible from anywhere in wp-admin without opening the plugin.

= Accuracy =

Results are only as good as their false-positive rate, so the scanner resolves local variable assignments before judging a path, reads `fopen()` modes before calling a read a write, checks whether a query is cached before calling it uncached, and recognises that an admin-only response is not part of the edge cache. Where it still cannot be sure, it lowers the confidence and says why. Findings can be acknowledged in the source with a `// wvc:ignore <rule-id> -- reason` annotation.

The plugin holds itself to the same standard: its own source passes `phpcs --standard=WordPress-VIP-Go`.

== Installation ==

1. Download the plugin ZIP file.
2. Go to your WordPress admin dashboard.
3. Navigate to **Plugins > Add New** and click on **Upload Plugin**.
4. Choose the downloaded ZIP file and click **Install Now**.
5. After installation, click **Activate** to enable the plugin.

== Usage ==

Open **WordPress VIP Compatibility** in the admin menu and run a scan from the header. Results are stored, so screens load without re-analysing the codebase; a target is only re-scanned when its files change or the rule set is updated.

**Overview** answers how ready the site is and what to do first. **Findings** is one ranked list of fixes, worst first, with the recommended change visible on every card. **Plugins**, **Themes** and **Must-use** give a per-target verdict, with each row expanding to the evidence behind it. **Database** and **wp-content** check the schema and the application structure against what WordPress VIP requires.

Scan results are stored in the options table and are only readable by users who can `manage_options`. They are removed when the plugin is uninstalled.

== Frequently Asked Questions ==

= Does a "Blocked" verdict mean the plugin cannot be migrated? =

It means at least one finding is expected to fail on the platform as written. Most are fixable — open Findings for the specific change required.

= Why is `uploads/` not reported as incompatible? =

Because it is not. `/wp-content/uploads/` is the one path under `wp-content` that application code may write to on WordPress VIP. It is imported with the WordPress VIP CLI rather than committed to the repository, which is why it is listed as "Not deployed" rather than as a problem.

= Can I suppress a finding I have reviewed? =

Yes. Add `// wvc:ignore <rule-id> -- your reason` to the line, or `// wvc:ignore-next-line <rule-id> -- your reason` above it. A reason is required.

= Can I add or change rules? =

Yes. Filter `wvc_scanner_rules` to add, modify or remove rules, and `wvc_scanner_excluded_directories` to change which directories are skipped.

= Does this replace WordPress VIP code review? =

No. It reads code without running it, so it cannot see behaviour that only appears under real traffic or real data. Test on a WordPress VIP environment before relying on the result.

== Changelog ==

= 2.0.0 =

* Replaced line-based regular-expression matching with a PHP tokeniser, removing the false positives from comments, docblocks, string literals and method calls.
* Added a rule engine of 47 rules, each carrying its WordPress VIP justification, remediation, alternative approach, PHPCS sniff and documentation link.
* Added independent severity, confidence and fixability ratings, and a three-state verdict (ready / needs review / blocked) in place of a binary compatible/incompatible flag.
* Added a findings report with filters, search, grouping, scan history and JSON/CSV/Markdown export.
* Security: added capability checks to every AJAX endpoint, which previously required only a logged-in user.
* Security: scan requests now name a validated target instead of an arbitrary filesystem path, closing a path-disclosure and traversal surface.
* Security: removed the report files written to `wp-content/uploads/wvc-logs/`, which were publicly downloadable; reports are now stored privately and exported through an authenticated download.
* Performance: reference data is loaded lazily and split, so a 1.1 MB JSON file is no longer parsed on every request including the front end.
* Performance: scan results are fingerprinted and cached, so screens no longer re-scan the whole codebase on every load.
* Performance: removed the remote update check that ran on every render of the plugins screen.
* Fixed a verdict comparison against a translated string, which broke the compatibility status on non-English sites.
* Corrected `uploads/`, `upgrade/` and `index.php` being reported as WordPress VIP-incompatible.
* Corrected the database audit: cached, with per-table size and source, and prefix guidance that matches WordPress VIP's own instruction not to rename tables unprompted.
* Expanded the reference data with WordPress VIP's documented incompatible plugins, plugins needing testing, plugins the platform already provides, and the full WP Engine must-use plugin list.
* The plugin's own source now passes `phpcs --standard=WordPress-VIP-Go` with no violations.

= 1.0.0 =

* Initial release.

== Screenshots ==

1. Overview with the migration readiness score and per-category breakdown.
2. The fix list, ranked worst first, with one-click filters.
3. An expanded finding showing evidence, rationale and remediation.
4. The database audit with per-table verdicts and the SQL to fix them.
