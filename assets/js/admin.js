/**
 * WordPress VIP Compatibility — admin interactions.
 *
 * Behaviour is grouped into small controllers, each owning one concern:
 *
 *   - menus     : single-open disclosure menus that close on outside click and Escape
 *   - confirm   : asking before a full rescan
 *   - shortcuts : "/" to jump to the screen's search field
 *   - findings  : expanding and collapsing every entry in the fix list at once
 *   - clipboard : copying SQL, both single statements and whole groups
 *   - tableView : filtering, searching, sorting, paging and live counts
 *   - rows      : the per-row detail drawers
 *   - scanner   : queued async compatibility checks and progress
 *
 * Two contracts matter across controllers. Filtering reads declarative
 * `data-<group>` attributes on each row rather than sniffing class names on
 * cells, so a row can carry several independent filters at once. And a data row
 * may be followed by its own detail row, so everything that moves or hides a row
 * — filtering, searching, sorting — has to carry that pair together, which is
 * why rows are resolved through `pairOf()` rather than by index.
 */
jQuery(document).ready(function ($) {
	"use strict";

	var settings = window._WPVC_ || {};
	var i18n = settings.i18n || {};

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Minimal sprintf supporting `%s` and positional `%1$s` placeholders.
	 *
	 * @param {string} template Localized template.
	 * @returns {string} The interpolated string.
	 */
	function format(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var cursor = 0;

		return String(template || "")
			.replace(/%(\d+)\$s/g, function (match, position) {
				var value = args[parseInt(position, 10) - 1];
				return typeof value === "undefined" ? "" : value;
			})
			.replace(/%s/g, function () {
				var value = args[cursor];
				cursor += 1;
				return typeof value === "undefined" ? "" : value;
			});
	}

	var ICON_PATHS = {
		check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		refresh:
			'<path d="M20 6v5h-5"/><path d="M4 18v-5h5"/>' +
			'<path d="M19.5 11a7.5 7.5 0 0 0-13-3.5L4 10"/><path d="M4.5 13a7.5 7.5 0 0 0 13 3.5L20 14"/>',
		copy:
			'<rect x="9" y="9" width="11" height="11" rx="2"/>' +
			'<path d="M5 15H4.5A1.5 1.5 0 0 1 3 13.5V5A1.5 1.5 0 0 1 4.5 3.5H13A1.5 1.5 0 0 1 14.5 5v.5"/>',
		"chevron-left": '<path d="m15 5-7 7 7 7"/>',
		"chevron-right": '<path d="m9 5 7 7-7 7"/>'
	};

	/**
	 * Builds an inline icon that matches the icons rendered by PHP.
	 *
	 * @param {string} name         Icon name.
	 * @param {string} [size]       Size modifier: `sm` or `xs`.
	 * @param {string} [extraClass] Additional class names.
	 * @returns {string} SVG markup.
	 */
	function icon(name, size, extraClass) {
		if (!ICON_PATHS[name]) {
			return "";
		}

		var classes = "wvc-icon";

		if (size) {
			classes += " wvc-icon--" + size;
		}

		if (extraClass) {
			classes += " " + extraClass;
		}

		return (
			'<svg class="' +
			classes +
			'" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" ' +
			'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
			ICON_PATHS[name] +
			"</svg>"
		);
	}

	/**
	 * Builds a resolved verdict tag, matching UI::get_status_pill().
	 *
	 * The glyph is drawn by the stylesheet from the modifier, so a tag is only
	 * ever a class and a word.
	 *
	 * @param {string} state One of `compatible`, `review`, `not-compatible`.
	 * @param {string} label Visible text.
	 * @returns {jQuery} The tag element.
	 */
	function buildPill(state, label) {
		var modifier = state === "compatible" ? "ok" : state === "review" ? "warn" : "bad";

		return $("<span/>", { class: "wvc-status wvc-status--" + modifier })
			.append($("<span/>", { class: "wvc-status__glyph", "aria-hidden": "true" }))
			.append($("<span/>").text(label));
	}

	/**
	 * Builds an in-progress status pill.
	 *
	 * @param {string} label Visible text.
	 * @returns {jQuery} The pill element.
	 */
	function buildPendingPill(label) {
		return $("<span/>", { class: "wvc-status wvc-status--pending" })
			.append($("<span/>", { class: "wvc-spinner wvc-spinner--xs", "aria-hidden": "true" }))
			.append($("<span/>").text(label));
	}

	/**
	 * Returns a row together with the detail row that belongs to it.
	 *
	 * @param {jQuery} $row A data row.
	 * @returns {jQuery} The row and, when present, its detail row.
	 */
	function pairOf($row) {
		var $next = $row.next(".wvc-row-detail");

		return $next.length ? $row.add($next) : $row;
	}

	/* ---------------------------------------------------------------------
	 * Disclosure menus
	 * ------------------------------------------------------------------ */

	(function menus() {
		var $menus = $(".wvc-menu");

		if (!$menus.length) {
			return;
		}

		$(document).on("click", function (event) {
			$menus.each(function () {
				if (this.open && !this.contains(event.target)) {
					this.open = false;
				}
			});
		});

		$(document).on("keydown", function (event) {
			if (event.key !== "Escape") {
				return;
			}

			$menus.each(function () {
				if (this.open) {
					this.open = false;
					$(this).children("summary").trigger("focus");
				}
			});
		});
	})();

	/* ---------------------------------------------------------------------
	 * Help: the bar's button drives the WordPress Help panel
	 * ------------------------------------------------------------------ */

	(function help() {
		var $toggle = $("[data-role='help-toggle']");
		var $link = $("#contextual-help-link");

		if (!$toggle.length || !$link.length) {
			return;
		}

		$toggle.on("click", function () {
			// WordPress updates its own link only after the slide animation, so
			// read the panel's state before toggling it.
			var opening = !$("#contextual-help-wrap").is(":visible");

			$link.trigger("click");
			$toggle.attr("aria-expanded", opening ? "true" : "false");
		});
	})();

	/* ---------------------------------------------------------------------
	 * Keyboard shortcut: "/" focuses search
	 * ------------------------------------------------------------------ */

	(function shortcuts() {
		$(document).on("keydown", function (event) {
			if (event.key !== "/" || event.ctrlKey || event.metaKey || event.altKey) {
				return;
			}

			// Typing a slash into a field is typing, not a shortcut.
			if ($(event.target).closest("input, textarea, select, [contenteditable='true']").length) {
				return;
			}

			var $search = $(".wvc .wvc-search__input:visible").first();

			if ($search.length) {
				event.preventDefault();
				$search.trigger("focus").trigger("select");
			}
		});
	})();

	/* ---------------------------------------------------------------------
	 * Findings: expand or collapse every entry
	 * ------------------------------------------------------------------ */

	(function findings() {
		var $toggle = $("[data-role='expand-all']");
		var $entries = $(".wvc-fixlist details.wvc-fix");

		if (!$toggle.length || !$entries.length) {
			return;
		}

		var $label = $toggle.children("span").first();

		/**
		 * Brings the button in line with the entries, however they were opened.
		 */
		function sync() {
			var allOpen = $entries.filter(":not([open])").length === 0;

			$toggle.attr("aria-pressed", allOpen ? "true" : "false");
			$label.text(allOpen ? i18n.collapseAll || "" : i18n.expandAll || "");
		}

		// The button only works with the script, so it only appears with it.
		$toggle.removeAttr("hidden");

		$toggle.on("click", function () {
			var open = $toggle.attr("aria-pressed") !== "true";

			$entries.prop("open", open);
			sync();
		});

		// `toggle` does not bubble, so it is listened for on each entry.
		$entries.on("toggle", sync);

		sync();
	})();

	/* ---------------------------------------------------------------------
	 * Submitting state
	 * ------------------------------------------------------------------ */

	(function confirmRescan() {
		/*
		 * A full rescan replaces every result and can run for a while, so it
		 * asks first. Registered before the submitting controller so a cancelled
		 * submit never reaches it and the button never shows as busy.
		 */
		var $dialog = null;
		var $pending = null;

		function getDialog() {
			if ($dialog) {
				return $dialog;
			}

			// Inside the plugin's wrapper so the screen's styles reach it.
			$dialog = $('<dialog class="wvc-dialog" aria-labelledby="wvc-dialog-title"></dialog>')
				.append($('<h2 class="wvc-dialog__title" id="wvc-dialog-title"></h2>').text(i18n.confirmTitle || ""))
				.append($('<p class="wvc-dialog__body"></p>').text(i18n.confirmBody || ""))
				.append(
					$('<div class="wvc-dialog__actions"></div>')
						.append(
							$('<button type="button" class="wvc-btn wvc-btn--ghost wvc-btn--sm" data-dialog="cancel"></button>').text(
								i18n.cancel || ""
							)
						)
						.append(
							$('<button type="button" class="wvc-btn wvc-btn--primary wvc-btn--sm" data-dialog="confirm"></button>')
								.append(icon("refresh", "sm"))
								.append($("<span/>").text(i18n.confirmAction || ""))
						)
				)
				.appendTo($(".wrap.wvc").first().length ? $(".wrap.wvc").first() : document.body);

			$dialog.on("click", "[data-dialog='cancel']", function () {
				$dialog[0].close();
			});

			// A click on the backdrop lands on the dialog element itself.
			$dialog.on("click", function (event) {
				if (event.target === this) {
					this.close();
				}
			});

			$dialog.on("click", "[data-dialog='confirm']", function () {
				var form = $pending && $pending[0];

				$dialog[0].close();

				if (!form) {
					return;
				}

				form.setAttribute("data-confirmed", "true");

				// requestSubmit() fires the submit event, so the button shows as
				// busy exactly as it does for an unconfirmed submit.
				if (typeof form.requestSubmit === "function") {
					form.requestSubmit();
				} else {
					$(form).find("button[type='submit']").addClass("is-busy");
					form.submit();
				}
			});

			$dialog.on("close", function () {
				if ($pending && !$pending.attr("data-confirmed")) {
					$pending.find("button[type='submit']").trigger("focus");
				}

				$pending = null;
			});

			return $dialog;
		}

		$(document).on("submit", "form[data-confirm='rescan-all']", function (event) {
			var $form = $(this);

			if ($form.attr("data-confirmed")) {
				return;
			}

			var dialog = getDialog()[0];

			// Without <dialog> support, fall back to the browser's own prompt.
			if (typeof dialog.showModal !== "function") {
				if (!window.confirm((i18n.confirmTitle || "") + "\n\n" + (i18n.confirmBody || ""))) {
					event.preventDefault();
					event.stopImmediatePropagation();
				}

				return;
			}

			event.preventDefault();
			event.stopImmediatePropagation();

			$pending = $form;
			dialog.showModal();
			$(dialog).find("[data-dialog='confirm']").trigger("focus");
		});
	})();

	(function submitting() {
		/*
		 * A rescan is a normal form post that can take several seconds before the
		 * browser starts painting the new page, and until now nothing
		 * acknowledged the click — so the button looked ignored and invited a
		 * second press.
		 *
		 * The busy class rather than the `disabled` attribute: disabling a
		 * submit button during its own submit event stops some browsers from
		 * sending it at all, and a disabled button's `name`/`value` is not
		 * submitted, which would drop the scope the rescan menu carries. The
		 * class shows a spinner and takes the control out of the pointer's
		 * reach, which is all that is needed.
		 *
		 * The scope menu is one form with a button per area, so the spinner has
		 * to land on the button that was pressed rather than on all of them.
		 * `submitter` gives that directly where it is supported; the click
		 * handler below is the fallback for older browsers.
		 */
		// Both shapes a posting form takes: one button on its own, and the menu
		// of scoped rescans.
		var FORMS = ".wvc-inline-form, .wvc-menu__form";
		var SUBMITS = ".wvc-inline-form button[type='submit'], .wvc-menu__form button[type='submit']";
		var $pressed = null;

		$(document).on("click", SUBMITS, function () {
			$pressed = $(this);
		});

		$(document).on("submit", FORMS, function (event) {
			var $form = $(this);
			var submitter = event.originalEvent && event.originalEvent.submitter;
			var $button = submitter ? $(submitter) : $pressed;

			if (!$button || !$button.length || !$.contains(this, $button[0])) {
				$button = $form.find("button[type='submit']").first();
			}

			$button.addClass("is-busy");
			$form.addClass("is-submitting");
			$pressed = null;
		});
	})();

	/* ---------------------------------------------------------------------
	 * Clipboard
	 * ------------------------------------------------------------------ */

	(function clipboard() {
		/**
		 * Copies text, falling back to a hidden textarea on insecure origins.
		 *
		 * @param {string} text The text to copy.
		 * @returns {Promise|jQuery.Promise} Resolves when the copy succeeded.
		 */
		function writeText(text) {
			if (window.navigator && window.navigator.clipboard && window.isSecureContext) {
				return window.navigator.clipboard.writeText(text);
			}

			var deferred = $.Deferred();
			var $helper = $("<textarea/>")
				.val(text)
				.attr("readonly", "readonly")
				.css({ position: "fixed", top: "-1000px", opacity: 0 })
				.appendTo(document.body);

			$helper[0].select();

			try {
				if (document.execCommand("copy")) {
					deferred.resolve();
				} else {
					deferred.reject();
				}
			} catch (error) {
				deferred.reject();
			}

			$helper.remove();

			return deferred.promise();
		}

		$(document).on("click", "[data-role='copy']", function () {
			var $button = $(this);
			// A labelled button keeps its label and swaps the word; an icon-only
			// button has nothing to say, so the icon itself becomes the feedback.
			var $label = $button.children("span").not(".screen-reader-text").first();
			var original = $label.length ? $label.text() : "";
			var timer = $button.data("wvcResetTimer");

			window.clearTimeout(timer);

			$.when(writeText($button.attr("data-clipboard") || ""))
				.done(function () {
					$button.addClass("is-copied");

					if ($label.length) {
						$label.text(i18n.copied || "");
					} else {
						$button.html(icon("check", "xs")).attr({ "aria-label": i18n.copied, title: i18n.copied });
					}
				})
				.fail(function () {
					if ($label.length) {
						$label.text(i18n.copyFailed || "");
					} else {
						$button.attr({ "aria-label": i18n.copyFailed, title: i18n.copyFailed });
					}
				})
				.always(function () {
					$button.data(
						"wvcResetTimer",
						window.setTimeout(function () {
							$button.removeClass("is-copied");

							if ($label.length) {
								$label.text(original);
							} else {
								$button
									.html(icon("copy", "xs"))
									.attr({ "aria-label": i18n.copy, title: i18n.copy });
							}
						}, 1800)
					);
				});
		});
	})();

	/* ---------------------------------------------------------------------
	 * Row detail drawers
	 * ------------------------------------------------------------------ */

	(function rows() {
		/**
		 * Opens or closes the drawer belonging to one row.
		 *
		 * @param {jQuery} $button The row's toggle button.
		 */
		function toggle($button) {
			var $row = $button.closest("tr");
			var $detail = $row.next(".wvc-row-detail");
			var open = $button.attr("aria-expanded") !== "true";

			if (!$detail.length) {
				return;
			}

			$button.attr("aria-expanded", open ? "true" : "false");
			$row.toggleClass("is-open", open);

			// `hidden` is the state; the inline display keeps it in step with
			// whatever filtering has done to the row above it, which would
			// otherwise leave a `display: none` behind on the way back open.
			$detail.prop("hidden", !open).toggle(open);
		}

		$(document).on("click", "[data-role='row-toggle']", function () {
			toggle($(this));
		});

		/*
		 * The whole row is a target too, because a 22px chevron is a small
		 * thing to ask someone to hit for every plugin on the list.
		 *
		 * The button is still the control: it owns `aria-expanded` and
		 * `aria-controls` and it is what the keyboard reaches. This is a
		 * pointer convenience layered on top, which is why the row itself gets
		 * no tabindex — a second tab stop onto the row would make every list
		 * twice as long to walk through for no extra reach.
		 *
		 * Two things have to keep working inside a clickable row: the links in
		 * it, and selecting text out of it.
		 */
		$(document).on("click", ".wvc-table--expandable tbody tr.wvc-row", function (event) {
			// A click that landed on its own control belongs to that control.
			// This also covers the toggle button, whose own handler already ran.
			if ($(event.target).closest("a, button, input, select, textarea, label, summary").length) {
				return;
			}

			// Finishing a drag-selection inside the row is not a click on it.
			var selection = window.getSelection ? String(window.getSelection()) : "";

			if (selection.length) {
				return;
			}

			var $button = $(this).find("[data-role='row-toggle']").first();

			if ($button.length) {
				toggle($button);
			}
		});
	})();

	/* ---------------------------------------------------------------------
	 * Table view: filter + search + sort + paging + counts
	 * ------------------------------------------------------------------ */

	/**
	 * Rows per page. Every row is still rendered and searched; paging only
	 * limits how many are drawn at once, so a schema with thousands of tables
	 * stays quick to scroll and to filter.
	 */
	var PAGE_SIZE = 20;

	/**
	 * Wires up one table and the toolbar that precedes it.
	 *
	 * @param {HTMLElement} container The `[data-role="table-view"]` element.
	 * @returns {Object|null} The view controller, or null when there is no table.
	 */
	function createTableView(container) {
		var $container = $(container);
		var $table = $container.find(".wvc-table").first();

		if (!$table.length) {
			return null;
		}

		// The toolbar sits before the table wrapper, so the closest preceding one
		// belongs to this view even when the screen renders several.
		var $toolbar = $container.prevAll(".wvc-toolbar").first();
		var $scan = $container.prevAll(".wvc-scan").first();
		var $tbody = $table.children("tbody");
		var $rows = $tbody.children("tr.wvc-row");

		if (!$rows.length) {
			$rows = $tbody.children("tr").not(".wvc-row-detail").not("[data-empty]");
		}

		var $groups = $toolbar.find("[data-filter-group]");
		var $search = $toolbar.find("[data-role='table-search']");
		var $resultCount = $toolbar.find("[data-role='result-count']");
		var columnCount = $table.find("thead th").length || 1;

		var state = { query: "", filters: {}, countsPending: false, page: 1 };
		var $noResults = null;
		var $pager = null;

		// Seed each group from whichever option the server marked active, so a
		// link such as ?status=not-compatible lands on a filtered view.
		$groups.each(function () {
			var $group = $(this);
			var name = $group.data("filter-group");
			var $active = $group.find("button.active").first();

			state.filters[name] = $active.length ? String($active.data("filter-value")) : "all";
		});

		function getNoResultsRow() {
			if (!$noResults) {
				$noResults = $('<tr class="wvc-no-results"><td></td></tr>');
				$noResults
					.children("td")
					.attr("colspan", columnCount)
					.append($("<strong/>").text(i18n.noResults || ""))
					.append("<br>")
					.append($("<span/>").text(i18n.noResultsHint || ""));
			}

			return $noResults;
		}

		/**
		 * Whether a row satisfies every active filter group.
		 *
		 * @param {jQuery} $row The row.
		 * @returns {boolean} True when the row should be visible.
		 */
		function rowMatchesFilters($row) {
			var matches = true;

			$.each(state.filters, function (name, value) {
				if (value === "all") {
					return true;
				}

				if (String($row.attr("data-" + name) || "") !== value) {
					matches = false;
					return false;
				}

				return true;
			});

			return matches;
		}

		/**
		 * Whether a row matches the free-text query.
		 *
		 * The detail drawer is searched alongside the row, so a match on an
		 * author or a path that only appears when expanded still finds the row.
		 *
		 * @param {jQuery} $row The row.
		 * @returns {boolean} True when the row should be visible.
		 */
		function rowMatchesQuery($row) {
			if (!state.query) {
				return true;
			}

			return pairOf($row).text().toLowerCase().indexOf(state.query) !== -1;
		}

		/**
		 * Recomputes the count shown on every filter option.
		 *
		 * A group's counts are measured against the *other* groups' filters, so
		 * "Blocked (2)" means two of the rows currently in view, not two of every
		 * row on the screen.
		 */
		function updateCounts() {
			$groups.each(function () {
				var $group = $(this);
				var name = $group.data("filter-group");

				var $candidates = $rows.filter(function () {
					var $row = $(this);
					var matches = true;

					$.each(state.filters, function (other, value) {
						if (other === name || value === "all") {
							return true;
						}

						if (String($row.attr("data-" + other) || "") !== value) {
							matches = false;
							return false;
						}

						return true;
					});

					return matches && rowMatchesQuery($row);
				});

				$group.find("button").each(function () {
					var $button = $(this);
					var value = String($button.data("filter-value"));
					var count =
						value === "all"
							? $candidates.length
							: $candidates.filter("[data-" + name + "='" + value + "']").length;

					// While an async scan is running the verdicts are not known
					// yet, so a number would be a guess.
					var unknown = state.countsPending && name === "status" && value !== "all";

					$button.find(".wvc-segmented__count").text(unknown ? "" : String(count));
				});
			});
		}

		/**
		 * Builds the pager once, directly after the table.
		 *
		 * @returns {jQuery} The pager.
		 */
		function getPager() {
			if (!$pager) {
				$pager = $('<nav class="wvc-pager" hidden></nav>').attr("aria-label", i18n.pager || "");

				$pager
					.append($('<p class="wvc-pager__range" aria-live="polite"></p>'))
					.append(
						$('<div class="wvc-pager__controls"></div>')
							.append(
								$('<button type="button" class="wvc-btn wvc-btn--ghost wvc-btn--sm" data-page="prev"></button>')
									.append(icon("chevron-left", "sm"))
									.append($("<span/>").text(i18n.previous || ""))
							)
							.append($('<span class="wvc-pager__page"></span>'))
							.append(
								$('<button type="button" class="wvc-btn wvc-btn--ghost wvc-btn--sm" data-page="next"></button>')
									.append($("<span/>").text(i18n.next || ""))
									.append(icon("chevron-right", "sm"))
							)
					);

				$pager.on("click", "[data-page]", function () {
					state.page += $(this).data("page") === "next" ? 1 : -1;
					apply();

					// The controls sit under the rows, so bring the top of the
					// new page into view rather than leaving the reader at its end.
					var top = ($toolbar.length ? $toolbar : $container).offset().top - 48;

					if ($(window).scrollTop() > top) {
						$("html, body").scrollTop(top);
					}
				});

				$container.after($pager);
			}

			return $pager;
		}

		/**
		 * Brings the pager in line with the matching rows and the current page.
		 *
		 * @param {number} matched Rows that pass the filters and the search.
		 * @param {number} pages   Number of pages.
		 */
		function updatePager(matched, pages) {
			var $nav = getPager();

			if (pages <= 1) {
				$nav.prop("hidden", true);
				return;
			}

			var first = (state.page - 1) * PAGE_SIZE + 1;
			var last = Math.min(state.page * PAGE_SIZE, matched);

			$nav.prop("hidden", false);
			$nav.find(".wvc-pager__range").text(format(i18n.pageRange, first, last, matched));
			$nav.find(".wvc-pager__page").text(format(i18n.pageOf, state.page, pages));
			$nav.find("[data-page='prev']").prop("disabled", state.page <= 1);
			$nav.find("[data-page='next']").prop("disabled", state.page >= pages);
		}

		function apply() {
			var visible = 0;
			var matches = [];

			// `$rows` is kept in display order (sorting reorders it), so the
			// position among matching rows is the position on the page.
			$rows.each(function (index) {
				var $row = $(this);

				matches[index] = rowMatchesFilters($row) && rowMatchesQuery($row);

				if (matches[index]) {
					visible += 1;
				}
			});

			var pages = Math.max(1, Math.ceil(visible / PAGE_SIZE));

			// A refresh from the scanner can shrink the match; never strand the
			// reader past the last page.
			state.page = Math.min(Math.max(1, state.page), pages);

			var start = (state.page - 1) * PAGE_SIZE;
			var end = start + PAGE_SIZE;
			var position = 0;

			$rows.each(function (index) {
				var $row = $(this);
				var show = false;

				if (matches[index]) {
					show = position >= start && position < end;
					position += 1;
				}

				$row.toggle(show);

				// A hidden row must not leave its drawer behind. `is-open` is the
				// reader's choice and survives filtering, so a row that comes back
				// into view comes back expanded if that is how they left it.
				var $detail = $row.next(".wvc-row-detail");

				if ($detail.length) {
					$detail.toggle(show && $row.hasClass("is-open"));
				}
			});

			updatePager(visible, pages);

			if (!visible && $rows.length) {
				getNoResultsRow().appendTo($tbody).show();
			} else if ($noResults) {
				$noResults.hide();
			}

			if ($resultCount.length) {
				if (!$rows.length) {
					$resultCount.text("");
				} else if (visible === $rows.length) {
					$resultCount.text(format(i18n.showingAll, $rows.length));
				} else {
					$resultCount.text(format(i18n.showingFiltered, visible, $rows.length));
				}
			}

			updateCounts();
		}

		/* Filtering. */
		$groups.on("click", "button", function () {
			var $button = $(this);
			var $group = $button.closest("[data-filter-group]");

			state.filters[$group.data("filter-group")] = String($button.data("filter-value"));
			state.page = 1;

			$group.find("button").removeClass("active").attr("aria-pressed", "false");
			$button.addClass("active").attr("aria-pressed", "true");

			apply();
		});

		/* Searching. */
		if ($search.length) {
			var searchTimer = null;

			$search.on("input search", function () {
				var value = $(this).val();

				window.clearTimeout(searchTimer);
				searchTimer = window.setTimeout(function () {
					state.query = $.trim(String(value)).toLowerCase();
					state.page = 1;
					apply();
				}, 140);
			});

			$search.on("keydown", function (event) {
				if (event.key === "Escape" && $(this).val()) {
					event.stopPropagation();
					$(this).val("");
					state.query = "";
					state.page = 1;
					apply();
				}
			});
		}

		/* Sorting. */
		$table.find("thead th.is-sortable .wvc-th__sort").on("click", function () {
			var $th = $(this).closest("th");
			var index = $th.index();
			var type = $th.data("sort-type") || "text";
			var ascending = $th.attr("aria-sort") !== "ascending";

			var sorted = $rows.toArray().sort(function (a, b) {
				var left = $(a).children("td").eq(index).text().trim();
				var right = $(b).children("td").eq(index).text().trim();

				if (type === "number") {
					var leftNumber = parseFloat(left.replace(/[^\d.-]/g, "")) || 0;
					var rightNumber = parseFloat(right.replace(/[^\d.-]/g, "")) || 0;

					return ascending ? leftNumber - rightNumber : rightNumber - leftNumber;
				}

				return ascending
					? left.localeCompare(right, undefined, { numeric: true, sensitivity: "base" })
					: right.localeCompare(left, undefined, { numeric: true, sensitivity: "base" });
			});

			$table.find("thead th").attr("aria-sort", "none");
			$th.attr("aria-sort", ascending ? "ascending" : "descending");

			// Each row is reinserted with its own drawer, or the two drift apart.
			$.each(sorted, function (position, row) {
				$tbody.append(pairOf($(row)));
			});

			// Paging walks `$rows`, so it has to follow the new order.
			$rows = $(sorted);

			// Keep the empty-result row last.
			if ($noResults) {
				$noResults.appendTo($tbody);
			}

			state.page = 1;
			apply();
		});

		apply();

		return {
			table: $table,
			scan: $scan,
			groups: $groups,
			setCountsPending: function (pending) {
				state.countsPending = !!pending;
				updateCounts();
			},
			refresh: apply
		};
	}

	var views = [];

	$("[data-role='table-view']").each(function () {
		var view = createTableView(this);

		if (view) {
			views.push(view);
		}
	});

	/* ---------------------------------------------------------------------
	 * Scanner: queued compatibility checks
	 * ------------------------------------------------------------------ */

	(function scanner() {
		var $statusCells = $("td.wvc-col-status.is-pending[data-target]");
		var total = $statusCells.length;

		// Present only when this page was reached by choosing an area from the
		// rescan menu. Its scope is what the completion call reports.
		var $report = $("[data-role='scan-report']");

		/**
		 * Ends a rescan that was started from the menu.
		 *
		 * The tiles above the table, the count on the admin menu and the
		 * "scanned N minutes ago" chip are all rendered from the stored results,
		 * and none of them is touched by resolving a row. Rather than patch four
		 * things from the client and risk them drifting, the page is rendered
		 * again from the server once the queue is empty — by which point the
		 * results are cached, so it costs a fast render and gives a screen where
		 * every number agrees with every other one.
		 */
		function finish() {
			if (!$report.length) {
				return;
			}

			$.ajax({
				url: settings.ajax_url,
				type: "POST",
				data: {
					_ajax_nonce: settings.nonce,
					action: "wvc_scan_complete",
					scope: $report.data("scope")
				}
			}).always(function () {
				// Same URL with the parameter switched from the in-progress name
				// to the finished one, so the reload lands on the confirmation.
				window.location.replace(
					window.location.href.replace(/([?&])wvc-scanning=/, "$1wvc-scanned=")
				);
			});
		}

		if (!total) {
			// An area with nothing in it still finishes: choosing "Themes" on a
			// site with no themes should confirm that, not hang on a bare page.
			finish();
			return;
		}

		// Only the view that actually owns pending rows is held during the scan.
		var view = null;

		$.each(views, function (index, candidate) {
			if (candidate.table.find("td.wvc-col-status.is-pending[data-target]").length) {
				view = candidate;
				return false;
			}

			return true;
		});

		var $scan = view ? view.scan : $("[data-role='scan']").first();
		var $scanLabel = $scan.find("[data-role='scan-label']");
		var $scanBar = $scan.find("[data-role='scan-bar']");

		var queue = $statusCells.toArray();
		var completed = 0;
		var active = 0;

		/*
		 * One request at a time, deliberately.
		 *
		 * Each scan writes its result into a shared summary index, which is a
		 * read-modify-write on a single option. Running three of them in
		 * parallel meant two requests could read the same index and write back
		 * over each other, dropping a verdict that had just been computed — the
		 * row would come back "Not scanned yet" on the next load and be scanned
		 * again. Serialising the queue removes the window entirely, and also
		 * stops three simultaneous tokenising runs from spiking CPU on a shared
		 * host. The progress bar is what covers the wait.
		 */
		var CONCURRENCY = 1;

		function updateProgress() {
			if (!$scan.length) {
				return;
			}

			$scanLabel.text(format(i18n.scanning, completed, total));
			$scanBar.css("width", total ? (completed / total) * 100 + "%" : "0%");
		}

		function complete() {
			if (view) {
				view.groups.find("button").prop("disabled", false);
				view.setCountsPending(false);
				view.refresh();
			}

			if ($scan.length) {
				$scanBar.css("width", "100%");
			}

			// A rescan started from the menu re-renders the page here, so the
			// progress bar is left showing rather than hidden into a page that is
			// about to be replaced anyway.
			if ($report.length) {
				finish();
				return;
			}

			if ($scan.length) {
				$scan.attr("hidden", "hidden");
			}
		}

		/**
		 * Replaces a pending cell with its verdict.
		 *
		 * The row's `data-status` is what the filters read, so it is updated
		 * alongside the cell's own state class.
		 *
		 * @param {jQuery} $cell  The status cell.
		 * @param {Object} result The AJAX payload.
		 */
		function resolveCell($cell, result) {
			$cell
				.removeClass("is-pending is-compatible is-review is-not-compatible")
				.addClass("is-" + result.state);

			$cell.closest("tr").attr("data-status", result.state);

			$cell.empty().append(buildPill(result.state, result.label));

			if (result.total > 0 && result.url) {
				$cell.append(
					$("<a/>", { class: "wvc-status__link", href: result.url, title: result.summary }).text(
						format(i18n.findingCount, result.total)
					)
				);
			}
		}

		function failCell($cell, message) {
			$cell
				.removeClass("is-pending")
				.addClass("is-review")
				.empty()
				.append(buildPill("review", message));

			$cell.closest("tr").attr("data-status", "review");
		}

		function onSettled() {
			active -= 1;
			completed += 1;
			updateProgress();

			if (queue.length) {
				pump();
			} else if (completed === total) {
				complete();
			}
		}

		function checkCell(cell) {
			var $cell = $(cell);

			return $.ajax({
				url: settings.ajax_url,
				type: "POST",
				data: {
					_ajax_nonce: settings.nonce,
					action: "wvc_scan_target",
					target: $cell.data("target")
				},
				beforeSend: function () {
					$cell.empty().append(buildPendingPill(i18n.checking));
				},
				success: function (response) {
					if (response && response.success) {
						resolveCell($cell, response.data);
					} else {
						failCell($cell, (response && response.data && response.data.message) || i18n.error);
					}
				},
				error: function () {
					failCell($cell, i18n.error);
				}
			}).always(onSettled);
		}

		function pump() {
			while (active < CONCURRENCY && queue.length) {
				active += 1;
				checkCell(queue.shift());
			}
		}

		// Filtering mid-scan would report partial results, so hold the controls.
		if (view) {
			view.groups.find("button").prop("disabled", true);
			view.setCountsPending(true);
		}

		if ($scan.length) {
			$scan.removeAttr("hidden");
			updateProgress();
		}

		pump();
	})();
});
