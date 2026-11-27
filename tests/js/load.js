/**
 * Loads assets/js/admin.js into the jsdom page, the way WordPress would.
 *
 * The script is one `jQuery(document).ready()` block with nothing exported, so
 * the tests drive it the way a reader does: build the page markup, load the
 * script, then click, type and submit.
 */
const fs = require("fs");
const path = require("path");
const jQuery = require("jquery");

const SOURCE = fs.readFileSync(path.join(__dirname, "../../assets/js/admin.js"), "utf8");

const I18N = {
	checking: "Scanning…",
	error: "Scan failed",
	scanning: "Scanning %1$s of %2$s…",
	findingCount: "%s findings",
	showingFiltered: "Showing %1$s of %2$s",
	showingAll: "Showing all %s",
	noResults: "No matching results",
	noResultsHint: "Try a different search term.",
	confirmTitle: "Rescan everything?",
	confirmBody: "This re-reads everything.",
	confirmAction: "Rescan everything",
	cancel: "Cancel",
	pager: "Table pages",
	previous: "Previous",
	next: "Next",
	pageRange: "%1$s–%2$s of %3$s",
	pageOf: "Page %1$s of %2$s",
	copy: "Copy to clipboard",
	copied: "Copied",
	copyFailed: "Press Ctrl+C to copy",
	expandAll: "Expand all",
	collapseAll: "Collapse all"
};

/*
 * jsdom does no layout, so every element reports a zero size and jQuery's
 * `:visible` is always false. Size an element by what would hide it instead:
 * the `hidden` attribute or `display: none` on it or on an ancestor.
 */
function isRendered(element) {
	for (let node = element; node && node.nodeType === 1; node = node.parentElement) {
		if (node.hidden || window.getComputedStyle(node).display === "none") {
			return false;
		}
	}

	return element.isConnected;
}

["offsetWidth", "offsetHeight"].forEach((property) => {
	Object.defineProperty(window.HTMLElement.prototype, property, {
		configurable: true,
		get() {
			return isRendered(this) ? 10 : 0;
		}
	});
});

/**
 * Renders markup into the page and runs the script against it.
 *
 * @param {string} html The page body.
 * @returns {jQuery} jQuery, bound to the page.
 */
function load(html) {
	document.body.innerHTML = html;

	window.jQuery = window.$ = jQuery;
	window._CUTOVER_AUDIT_ = {
		ajax_url: "https://example.test/wp-admin/admin-ajax.php",
		nonce: "abc123",
		i18n: I18N
	};

	// eslint-disable-next-line no-eval -- Runs the shipped file unmodified.
	window.eval(SOURCE);

	// jQuery schedules its own "ready" on a real timer when it is first
	// required, which a synchronous test never reaches. Fire it directly; the
	// callbacks then run on the next (fake) tick.
	if (!jQuery.isReady) {
		jQuery.ready();
	}

	jest.runOnlyPendingTimers();

	return jQuery;
}

/**
 * Removes every handler the script bound to the document and window.
 */
function unload() {
	jQuery(document).off();
	jQuery(window).off();
	document.body.innerHTML = "";
}

module.exports = { load, unload, I18N };
