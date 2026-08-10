/**
 * WordPress VIP Compatibility — admin interactions.
 *
 * Behaviour is grouped into small controllers:
 *   - clipboard : copy SQL snippets
 *   - tableView : filtering, searching, sorting and live counts
 *   - scanner   : queued async compatibility checks, progress, log note
 *   - tabs      : overview tab navigation
 *   - dashboard : per-category doughnuts and the aggregated readiness gauge
 *
 * The DOM contract used by the PHP side is intentionally preserved: status
 * cells keep their `compatible` / `not-compatible` classes, the filter buttons
 * keep their `data-filter` values, and the chart canvases keep their ids.
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
	 * @param {string} name        Icon name.
	 * @param {string} [size]      Size modifier: `sm` or `xs`.
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
	 * @param {string} statusClass `compatible` or `not-compatible`.
	 * @param {string} label       Visible text.
	 * @returns {jQuery} The pill element.
	 */
	function buildPill(statusClass, label) {
		var isOk = statusClass === "compatible";

		return $("<span/>", { class: "wvc-status wvc-status--" + (isOk ? "ok" : "bad") })
			.append(icon(isOk ? "check" : "alert", "xs"))
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
			var timer = $button.data("wvcResetTimer");

			window.clearTimeout(timer);

			$.when(writeText($button.attr("data-clipboard") || ""))
				.done(function () {
					$button
						.addClass("is-copied")
						.html(icon("check", "xs"))
						.attr({ "aria-label": i18n.copied, title: i18n.copied });
				})
				.fail(function () {
					$button.attr({ "aria-label": i18n.copyFailed, title: i18n.copyFailed });
				})
				.always(function () {
					$button.data(
						"wvcResetTimer",
						window.setTimeout(function () {
							$button
								.removeClass("is-copied")
								.html(icon("copy", "xs"))
								.attr({ "aria-label": i18n.copy, title: i18n.copy });
						}, 1800)
					);
				});
		});
	})();

	/* ---------------------------------------------------------------------
	 * Table view: filter + search + sort + counts
	 * ------------------------------------------------------------------ */

	var tableView = (function () {
		var $table = $(".wvc-table").first();

		if (!$table.length) {
			return null;
		}

		var $tbody = $table.children("tbody");
		var $rows = $tbody.children("tr").not("[data-empty]");
		var $filterButtons = $("#wvc-filter-tabs button");
		var $search = $("[data-role='table-search']");
		var $resultCount = $("[data-role='result-count']");
		var columnCount = $table.find("thead th").length || 1;

		var state = { filter: "all", query: "", countsPending: false };
		var $noResults = null;

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

		function rowMatchesFilter($row) {
			if (state.filter === "compatible") {
				return $row.children("td.compatible").length > 0;
			}

			if (state.filter === "incompatible") {
				return $row.children("td.not-compatible").length > 0;
			}

			return true;
		}

		function rowMatchesQuery($row) {
			if (!state.query) {
				return true;
			}

			return $row.text().toLowerCase().indexOf(state.query) !== -1;
		}

		function updateCounts() {
			var counts = {
				all: $rows.length,
				compatible: $rows.filter(function () {
					return $(this).children("td.compatible").length > 0;
				}).length,
				incompatible: $rows.filter(function () {
					return $(this).children("td.not-compatible").length > 0;
				}).length
			};

			$filterButtons.each(function () {
				var $button = $(this);
				var key = $button.data("filter");
				var value = counts[key];
				var isUnknownWhileScanning = state.countsPending && key !== "all";

				$button
					.find(".wvc-segmented__count")
					.text(typeof value === "number" && !isUnknownWhileScanning ? String(value) : "");
			});
		}

		function apply() {
			var visible = 0;

			$rows.each(function () {
				var $row = $(this);
				var show = rowMatchesFilter($row) && rowMatchesQuery($row);

				$row.toggle(show);

				if (show) {
					visible += 1;
				}
			});

			// Empty state for a filter/search combination that matches nothing.
			if (!visible && $rows.length) {
				getNoResultsRow().appendTo($tbody).show();
			} else if ($noResults) {
				$noResults.hide();
			}

			if ($resultCount.length) {
				if (!$rows.length) {
					$resultCount.text("");
				} else if (state.filter === "all" && !state.query) {
					$resultCount.text(format(i18n.showingAll, $rows.length));
				} else {
					$resultCount.text(format(i18n.showingFiltered, visible, $rows.length));
				}
			}
		}

		/* Filtering. */
		$filterButtons.on("click", function () {
			var $button = $(this);

			state.filter = $button.data("filter");

			$filterButtons.removeClass("active").attr("aria-pressed", "false");
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

			$tbody.append(sorted);

			// Keep the empty-result row last.
			if ($noResults) {
				$noResults.appendTo($tbody);
			}
		});

		updateCounts();
		apply();

		return {
			table: $table,
			filterButtons: $filterButtons,
			setCountsPending: function (pending) {
				state.countsPending = !!pending;
				updateCounts();
			},
			refresh: function () {
				updateCounts();
				apply();
			}
		};
	})();

	/* ---------------------------------------------------------------------
	 * Scanner: queued compatibility checks
	 * ------------------------------------------------------------------ */

	(function scanner() {
		var $statusCells = $("td.vip-compatibility-status");
		var $logNoteContainer = $("#wvc-log-note-container");
		var logNoteFilename = $logNoteContainer.data("filename");
		var $table = tableView ? tableView.table : $(".wvc-table").first();
		var targetEntity = $table.data("target-entity");

		// These are the directories that undergo an async compatibility check.
		var asyncCompatibilityCheckFiles = ["plugins", "themes", "mu-plugins"];
		var isAsyncEntity = $.inArray(targetEntity, asyncCompatibilityCheckFiles) !== -1;

		var $scan = $("[data-role='scan']");
		var $scanLabel = $("[data-role='scan-label']");
		var $scanBar = $("[data-role='scan-bar']");
		var $filterButtons = tableView ? tableView.filterButtons : $("#wvc-filter-tabs button");

		var queue = $statusCells.toArray();
		var total = queue.length;
		var completed = 0;
		var active = 0;

		// Checks are CPU heavy on the server; a small pool keeps the admin
		// responsive instead of firing one request per row at once.
		var CONCURRENCY = 4;

		function renderLogNote(html, type) {
			$logNoteContainer.html(
				'<div class="wvc-notice wvc-notice--' +
					type +
					'">' +
					icon(type === "warning" ? "alert" : "info", "sm", "wvc-notice__icon") +
					'<div class="wvc-notice__body">' +
					html +
					"</div></div>"
			);
		}

		function loadLogNote() {
			if (!$logNoteContainer.length || !logNoteFilename) {
				return;
			}

			$.ajax({
				url: settings.ajax_url,
				type: "POST",
				data: {
					_ajax_nonce: settings.nonce,
					action: "wvc_render_log_note",
					filename: logNoteFilename
				},
				success: function (response) {
					renderLogNote(response.data.message, "info");
				},
				error: function () {
					renderLogNote(
						"<p><strong>" + i18n.error + ":</strong> " + i18n.unableToFetchLogDetails + "</p>",
						"warning"
					);
				}
			});
		}

		function updateProgress() {
			if (!$scan.length) {
				return;
			}

			$scanLabel.text(format(i18n.scanning, completed, total));
			$scanBar.css("width", total ? (completed / total) * 100 + "%" : "0%");
		}

		function complete() {
			$filterButtons.prop("disabled", false);

			if ($scan.length) {
				$scanBar.css("width", "100%");
				$scan.attr("hidden", "hidden");
			}

			if (tableView) {
				tableView.setCountsPending(false);
				tableView.refresh();
			}

			loadLogNote();
		}

		function resolveCell($cell, statusClass, label) {
			$cell.addClass(statusClass).empty().append(buildPill(statusClass, label));
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
			var directoryPath = $cell.data("directory-path");

			return $.ajax({
				url: settings.ajax_url,
				type: "POST",
				data: {
					_ajax_nonce: settings.nonce,
					action: "wvc_check_vip_compatibility",
					directory_path: directoryPath
				},
				beforeSend: function () {
					$cell.empty().append(buildPendingPill(i18n.checking));
				},
				success: function (response) {
					if (response.success) {
						resolveCell($cell, response.data.class, response.data.message);
					} else {
						resolveCell($cell, "not-compatible", i18n.error);
					}
				},
				error: function () {
					resolveCell($cell, "not-compatible", i18n.error);
				}
			}).always(onSettled);
		}

		function pump() {
			while (active < CONCURRENCY && queue.length) {
				active += 1;
				checkCell(queue.shift());
			}
		}

		if (!total) {
			// Nothing to scan asynchronously — still surface the log note.
			loadLogNote();
			return;
		}

		// Filtering mid-scan would report partial results, so hold the tabs.
		if (isAsyncEntity) {
			$filterButtons.prop("disabled", true);

			if (tableView) {
				tableView.setCountsPending(true);
			}
		}

		if ($scan.length) {
			$scan.removeAttr("hidden");
			updateProgress();
		}

		pump();
	})();

	/* ---------------------------------------------------------------------
	 * Overview tabs
	 * ------------------------------------------------------------------ */

	(function tabs() {
		var $tabs = $("#wvc-navigation-tabs button");
		var $panels = $(".wvc-navigation-tab-content");

		if (!$tabs.length) {
			return;
		}

		function activate($tab) {
			$tabs.removeClass("active").attr({ "aria-selected": "false", tabindex: "-1" });
			$panels.removeClass("active");

			$tab.addClass("active").attr({ "aria-selected": "true", tabindex: "0" });
			$("#" + $tab.data("tab")).addClass("active");
		}

		$tabs.on("click", function () {
			activate($(this));
		});

		// Roving focus with the arrow keys, per the WAI-ARIA tabs pattern.
		$tabs.on("keydown", function (event) {
			var index = $tabs.index(this);
			var next = null;

			if (event.key === "ArrowRight" || event.key === "ArrowDown") {
				next = (index + 1) % $tabs.length;
			} else if (event.key === "ArrowLeft" || event.key === "ArrowUp") {
				next = (index - 1 + $tabs.length) % $tabs.length;
			} else if (event.key === "Home") {
				next = 0;
			} else if (event.key === "End") {
				next = $tabs.length - 1;
			}

			if (null === next) {
				return;
			}

			event.preventDefault();
			activate($tabs.eq(next));
			$tabs.eq(next).trigger("focus");
		});
	})();

	/* ---------------------------------------------------------------------
	 * Overview dashboard
	 * ------------------------------------------------------------------ */

	(function dashboard() {
		var $container = $("#wvc-chart-container");

		if (!$container.length) {
			return;
		}

		var categories = $container.data("categories") || [];
		var chartInstances = {};
		var results = {};
		var attempted = {};

		var GAUGE_RADIUS = 52;
		var GAUGE_CIRCUMFERENCE = 2 * Math.PI * GAUGE_RADIUS;
		var COLORS = { ok: "#12805c", bad: "#b32d2e" };

		function card(category) {
			return $container.find("[data-category='" + category + "']");
		}

		function showCardState($card, content) {
			$card.find("[data-role='readout']").attr("hidden", "hidden");
			$card.find("[data-role='state']").empty().append(content).show();
		}

		function renderChart(category, compatible, incompatible) {
			var canvas = document.getElementById("chart-" + category);

			// Chart.js only ships on the overview screen; degrade to numbers.
			if (!canvas || typeof window.Chart === "undefined") {
				return;
			}

			if (chartInstances[category]) {
				chartInstances[category].destroy();
			}

			chartInstances[category] = new window.Chart(canvas.getContext("2d"), {
				type: "doughnut",
				data: {
					labels: [i18n.compatible, i18n.incompatible],
					datasets: [
						{
							data: [compatible, incompatible],
							backgroundColor: [COLORS.ok, COLORS.bad],
							borderColor: "#ffffff",
							borderWidth: 2,
							hoverOffset: 6
						}
					]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					cutout: "72%",
					animation: { duration: 500 },
					plugins: {
						legend: { display: false },
						tooltip: {
							backgroundColor: "#14181f",
							padding: 10,
							cornerRadius: 6,
							boxPadding: 4,
							titleFont: { size: 12 },
							bodyFont: { size: 12 }
						}
					}
				}
			});
		}

		function renderCard(category, compatible, incompatible) {
			var $card = card(category);
			var checked = compatible + incompatible;

			$card.find("[data-role='compatible']").text(compatible);
			$card.find("[data-role='incompatible']").text(incompatible);

			if (!checked) {
				showCardState($card, $("<span/>").text(i18n.nothingToCheck || ""));
				return;
			}

			$card.find("[data-role='state']").empty().hide();
			$card.find("[data-role='percent']").text(Math.round((compatible / checked) * 100) + "%");
			$card.find("[data-role='readout']").removeAttr("hidden");

			renderChart(category, compatible, incompatible);
		}

		function renderCardError(category) {
			var $card = card(category);

			$card.find("[data-role='compatible']").text("–");
			$card.find("[data-role='incompatible']").text("–");

			showCardState(
				$card,
				[
					$("<span/>").text(i18n.unableToFetchData || ""),
					$("<button/>", {
						type: "button",
						class: "wvc-btn wvc-btn--ghost",
						text: i18n.error
					}).on("click", function () {
						fetchCategory(category);
					})
				]
			);
		}

		function updateReadiness() {
			var compatible = 0;
			var incompatible = 0;
			var failed = false;
			var $gauge = $("[data-role='gauge']");

			$.each(categories, function (index, category) {
				if (!results[category]) {
					failed = true;
					return;
				}

				compatible += results[category].compatible;
				incompatible += results[category].not_compatible;
			});

			var checked = compatible + incompatible;
			var percent = checked ? Math.round((compatible / checked) * 100) : 0;

			$("[data-role='total-compatible']").text(compatible);
			$("[data-role='total-incompatible']").text(incompatible);
			$("[data-role='total-items']").text(checked);

			$("[data-role='score']").text(checked ? percent : "–");
			$("[data-role='score-unit']").text(checked ? "%" : "");

			$gauge.removeClass("is-good is-fair is-poor");

			if (checked) {
				$gauge.addClass(percent >= 90 ? "is-good" : percent >= 70 ? "is-fair" : "is-poor");

				// Set as an inline style: the stylesheet's own `stroke-dasharray`
				// would win over an SVG presentation attribute.
				$("[data-role='gauge-value']").css(
					"stroke-dasharray",
					(percent / 100) * GAUGE_CIRCUMFERENCE + " " + GAUGE_CIRCUMFERENCE
				);
			}

			var summary;

			if (!checked) {
				summary = i18n.noDataAvailable;
			} else if (!incompatible) {
				summary = i18n.readinessPerfect;
			} else {
				summary = format(i18n.readinessSummary, percent + "%") + " " + i18n.readinessAttention;
			}

			if (failed) {
				summary += " " + i18n.readinessUnavailable;
			}

			$("[data-role='readiness-summary']").text(summary);
		}

		function allAttempted() {
			var count = 0;

			$.each(categories, function (index, category) {
				if (attempted[category]) {
					count += 1;
				}
			});

			return count === categories.length;
		}

		function fetchCategory(category) {
			$.ajax({
				url: settings.ajax_url,
				type: "POST",
				data: {
					_ajax_nonce: settings.nonce,
					action: "wvc_get_chart_data",
					category: category
				},
				beforeSend: function () {
					showCardState(
						card(category),
						$("<span/>", { class: "wvc-chart-card__ring-skeleton", "aria-hidden": "true" })
					);
				},
				success: function (response) {
					if (response.success) {
						var compatible = parseInt(response.data.compatible, 10) || 0;
						var incompatible = parseInt(response.data.not_compatible, 10) || 0;

						results[category] = { compatible: compatible, not_compatible: incompatible };
						renderCard(category, compatible, incompatible);
					} else {
						delete results[category];
						renderCardError(category);
					}
				},
				error: function () {
					delete results[category];
					renderCardError(category);
				},
				complete: function () {
					attempted[category] = true;

					if (allAttempted()) {
						updateReadiness();
					}
				}
			});
		}

		$.each(categories, function (index, category) {
			fetchCategory(category);
		});
	})();
});
