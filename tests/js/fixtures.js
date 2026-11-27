/**
 * Markup builders matching what the PHP screens render.
 *
 * Only the classes and data attributes the script reads are reproduced.
 */

/**
 * A toolbar with one filter group.
 *
 * @param {string}   name    The group name, e.g. "status".
 * @param {string[]} values  Filter values, "all" first.
 * @param {string}   active  The value marked active.
 * @returns {string} Markup.
 */
function filterGroup(name, values, active = "all") {
	const buttons = values
		.map(
			(value) =>
				`<button type="button" class="${value === active ? "active" : ""}" data-filter-value="${value}" aria-pressed="${
					value === active
				}"><span>${value}</span><span class="cutover-audit-segmented__count" data-count="${value}"></span></button>`
		)
		.join("");

	return `<div class="cutover-audit-segmented" role="group" data-filter-group="${name}">${buttons}</div>`;
}

/**
 * A table screen: toolbar, progress bar and rows with detail drawers.
 *
 * @param {Object[]} rows      Each with `name`, `status`, optional `attrs`, `target`, `pending`, `detail`, `cells`.
 * @param {Object}   [options] `groups` (markup), `sortable` (bool), `expandable` (bool), `progress` (bool).
 * @returns {string} Markup.
 */
function table(rows, options = {}) {
	const groups = options.groups !== undefined ? options.groups : filterGroup("status", ["all", "not-compatible", "review", "compatible"]);
	const expandable = options.expandable !== false;

	const body = rows
		.map((row, index) => {
			const attrs = Object.entries(row.attrs || {})
				.map(([key, value]) => ` data-${key}="${value}"`)
				.join("");
			const status = row.pending
				? `<td class="cutover-audit-col-status is-pending" data-target="${row.target}"><span>Not scanned yet</span></td>`
				: `<td class="cutover-audit-col-status is-${row.status}"><span>${row.status}</span></td>`;
			const toggle = expandable
				? `<td class="cutover-audit-col-toggle"><button type="button" data-role="row-toggle" aria-expanded="false" aria-controls="d${index}"></button></td>`
				: "";
			const cells = (row.cells || []).map((cell) => `<td>${cell}</td>`).join("");
			const detail = expandable ? `<tr class="cutover-audit-row-detail" id="d${index}" hidden><td>${row.detail || ""}</td></tr>` : "";

			return `<tr class="cutover-audit-row" data-status="${row.status}"${attrs}>${toggle}<td class="cutover-audit-col-name"><a href="#x">${row.name}</a></td>${cells}${status}</tr>${detail}`;
		})
		.join("");

	const head = [
		expandable ? '<th class="cutover-audit-th"></th>' : "",
		`<th class="cutover-audit-th ${options.sortable ? "is-sortable" : ""}" data-sort-type="text" aria-sort="none"><button type="button" class="cutover-audit-th__sort">Name</button></th>`,
		...(options.extraHeads || []),
		'<th class="cutover-audit-th">Verdict</th>'
	].join("");

	return `
		<div class="wrap cutover-audit">
			<div class="cutover-audit-toolbar">
				<div class="cutover-audit-toolbar__filters">${groups}</div>
				<div class="cutover-audit-toolbar__tools">
					<p class="cutover-audit-result-count" data-role="result-count"></p>
					<div class="cutover-audit-search"><input type="search" class="cutover-audit-search__input" data-role="table-search" /></div>
				</div>
			</div>
			${
				options.progress === false
					? ""
					: '<div class="cutover-audit-scan" data-role="scan" hidden><span data-role="scan-label"></span><div data-role="scan-bar" style="width:0%"></div></div>'
			}
			<div class="cutover-audit-table-wrap" data-role="table-view">
				<table class="cutover-audit-table ${expandable ? "cutover-audit-table--expandable" : ""}">
					<thead><tr>${head}</tr></thead>
					<tbody>${body}</tbody>
				</table>
			</div>
		</div>`;
}

/**
 * The findings screen: tier menu, search form, active filters and tier groups.
 *
 * @param {string} selected The tier selected on load, or "".
 * @returns {string} Markup.
 */
function findings(selected = "") {
	const tiers = ["blocking", "important", "warning", "info"];
	const counts = { "": 4, blocking: 1, important: 2, warning: 0, info: 1 };
	const option = (tier) => {
		const active = tier === selected ? " active" : "";
		const href = tier ? `https://example.test/wp-admin/admin.php?page=cutover-audit-findings&tier=${tier}` : "https://example.test/wp-admin/admin.php?page=cutover-audit-findings";

		return `<a class="cutover-audit-segmented__option${active}" href="${href}" data-tier="${tier}" data-summary="${counts[tier]} issues"><span>${tier || "Everything"}</span></a>`;
	};
	const group = (tier, entries) =>
		`<section class="cutover-audit-tiergroup cutover-audit-tiergroup--${tier}" data-tier="${tier}"${selected && selected !== tier ? " hidden" : ""}>${entries}</section>`;
	const fix = (id) => `<details class="cutover-audit-fix" id="${id}"><summary>${id}</summary><div>body</div></details>`;

	return `
		<div class="wrap cutover-audit">
			<div class="cutover-audit-toolbar cutover-audit-findings__toolbar">
				<nav class="cutover-audit-segmented">${["", ...tiers].map(option).join("")}</nav>
				<p data-role="findings-count">${counts[selected]} issues</p>
				<form class="cutover-audit-search cutover-audit-search--form" method="get" action="https://example.test/wp-admin/admin.php">
					<input type="hidden" name="page" value="cutover-audit-findings" />
					<input type="search" class="cutover-audit-search__input" name="s" />
				</form>
				<button type="button" data-role="expand-all" aria-pressed="false" hidden><svg></svg><span>Expand all</span></button>
			</div>
			<div class="cutover-audit-findings__active">
				<a class="cutover-audit-activefilter" href="https://example.test/wp-admin/admin.php?page=cutover-audit-findings&s=x"><span>Matching x</span></a>
			</div>
			<div class="cutover-audit-fixlist">
				${group("blocking", fix("f1"))}
				${group("important", fix("f2") + fix("f3"))}
				${group("info", fix("f4"))}
			</div>
			<div data-role="tier-empty" hidden><p>Nothing in this tier</p></div>
		</div>`;
}

module.exports = { filterGroup, table, findings };
