/**
 * WordPress VIP Compatibility — admin interactions.
 *
 * Behaviour is grouped into small controllers, each owning one concern:
 *
 *   - menus     : single-open disclosure menus that close on outside click and Escape
 *   - clipboard : copying SQL, both single statements and whole groups
 *   - tableView : filtering, searching, sorting and live counts
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
		alert:
			'<path d="M12 8.5v5"/><path d="M12 17h.01"/>' +
			'<path d="M10.3 3.9 2.6 17.4A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3.1L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
		copy:
			'<rect x="9" y="9" width="11" height="11" rx="2"/>' +
			'<path d="M5 15H4.5A1.5 1.5 0 0 1 3 13.5V5A1.5 1.5 0 0 1 4.5 3.5H13A1.5 1.5 0 0 1 14.5 5v.5"/>'
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
	 * Builds a resolved status pill.
	 *
	 * @param {string} state One of `compatible`, `review`, `not-compatible`.
	 * @param {string} label Visible text.
	 * @returns {jQuery} The pill element.
	 */
	function buildPill(state, label) {
		var modifier = state === "compatible" ? "ok" : state === "review" ? "warn" : "bad";
		var glyph = state === "compatible" ? "check" : state === "review" ? "info" : "alert";

		return $("<span/>", { class: "wvc-status wvc-status--" + modifier })
			.append(icon(glyph, "xs"))
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
		$(document).on("click", "[data-role='row-toggle']", function () {
			var $button = $(this);
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
		});
	})();

	/* ---------------------------------------------------------------------
	 * Table view: filter + search + sort + counts
	 * ------------------------------------------------------------------ */

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

		var state = { query: "", filters: {}, countsPending: false };
		var $noResults = null;

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

		function apply() {
			var visible = 0;

			$rows.each(function () {
				var $row = $(this);
				var show = rowMatchesFilters($row) && rowMatchesQuery($row);

				$row.toggle(show);

				// A hidden row must not leave its drawer behind. `is-open` is the
				// reader's choice and survives filtering, so a row that comes back
				// into view comes back expanded if that is how they left it.
				var $detail = $row.next(".wvc-row-detail");

				if ($detail.length) {
					$detail.toggle(show && $row.hasClass("is-open"));
				}

				if (show) {
					visible += 1;
				}
			});

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
					apply();
				}, 140);
			});

			$search.on("keydown", function (event) {
				if (event.key === "Escape" && $(this).val()) {
					event.stopPropagation();
					$(this).val("");
					state.query = "";
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

			// Keep the empty-result row last.
			if ($noResults) {
				$noResults.appendTo($tbody);
			}
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

		if (!total) {
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

		// Tokenising a plugin is CPU heavy on the server; a small pool keeps the
		// admin responsive instead of firing one request per row at once.
		var CONCURRENCY = 3;

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
