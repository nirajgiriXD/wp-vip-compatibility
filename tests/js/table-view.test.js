/**
 * Filtering, searching, sorting, paging and counts on the table screens.
 */
const { load, unload } = require("./load");
const { table, filterGroup } = require("./fixtures");

beforeEach(() => jest.useFakeTimers());
afterEach(() => {
	unload();
	jest.useRealTimers();
});

const visibleNames = ($) =>
	$("tbody tr.switchrail-audit-row:visible")
		.map((index, row) => $(row).find(".switchrail-audit-col-name").text())
		.get();

const count = ($, value, group = "status") =>
	$(`[data-filter-group='${group}'] [data-filter-value='${value}'] .switchrail-audit-segmented__count`).text();

const rows = [
	{ name: "Alpha", status: "not-compatible", attrs: { state: "active" } },
	{ name: "Beta", status: "review", attrs: { state: "inactive" }, detail: "Author: Zed" },
	{ name: "Gamma", status: "compatible", attrs: { state: "active" } },
	{ name: "Delta", status: "compatible", attrs: { state: "inactive" } }
];

const twoGroups = filterGroup("state", ["all", "active", "inactive"]) + filterGroup("status", ["all", "not-compatible", "review", "compatible"]);

describe("counts", () => {
	test("each option counts the rows it would show", () => {
		const $ = load(table(rows));

		expect(count($, "all")).toBe("4");
		expect(count($, "not-compatible")).toBe("1");
		expect(count($, "review")).toBe("1");
		expect(count($, "compatible")).toBe("2");
		expect($("[data-role='result-count']").text()).toBe("Showing all 4");
	});

	test("a group is counted against the other groups' filters", () => {
		const $ = load(table(rows, { groups: twoGroups }));

		$("[data-filter-group='state'] [data-filter-value='active']").trigger("click");

		expect(count($, "all")).toBe("2");
		expect(count($, "compatible")).toBe("1");
		// The state group itself still counts every state.
		expect(count($, "inactive", "state")).toBe("2");
	});
});

describe("filtering", () => {
	test("clicking an option shows only matching rows and marks it pressed", () => {
		const $ = load(table(rows));
		const $button = $("[data-filter-value='compatible']");

		$button.trigger("click");

		expect(visibleNames($)).toEqual(["Gamma", "Delta"]);
		expect($button.hasClass("active")).toBe(true);
		expect($button.attr("aria-pressed")).toBe("true");
		expect($("[data-filter-value='all']").attr("aria-pressed")).toBe("false");
		expect($("[data-role='result-count']").text()).toBe("Showing 2 of 4");
	});

	test("filter groups combine", () => {
		const $ = load(table(rows, { groups: twoGroups }));

		$("[data-filter-group='state'] [data-filter-value='inactive']").trigger("click");
		$("[data-filter-group='status'] [data-filter-value='compatible']").trigger("click");

		expect(visibleNames($)).toEqual(["Delta"]);
	});

	test("the option the server marked active filters on load", () => {
		const $ = load(table(rows, { groups: filterGroup("status", ["all", "not-compatible", "review", "compatible"], "review") }));

		expect(visibleNames($)).toEqual(["Beta"]);
	});

	test("no matches shows a message row spanning the table", () => {
		const $ = load(table(rows.slice(2)));

		$("[data-filter-value='not-compatible']").trigger("click");

		const $empty = $("tr.switchrail-audit-no-results");

		expect($empty.length).toBe(1);
		expect($empty.is(":visible")).toBe(true);
		expect($empty.text()).toContain("No matching results");
		expect($empty.find("td").attr("colspan")).toBe(String($("thead th").length));

		$("[data-filter-value='all']").trigger("click");

		expect($empty.is(":visible")).toBe(false);
	});
});

describe("searching", () => {
	test("search matches row text after a short pause", () => {
		const $ = load(table(rows));

		$("[data-role='table-search']").val("  GAM ").trigger("input");
		expect(visibleNames($)).toHaveLength(4);

		jest.advanceTimersByTime(140);
		expect(visibleNames($)).toEqual(["Gamma"]);
	});

	test("search also reads the detail drawer", () => {
		const $ = load(table(rows));

		$("[data-role='table-search']").val("zed").trigger("input");
		jest.advanceTimersByTime(140);

		expect(visibleNames($)).toEqual(["Beta"]);
	});

	test("Escape clears the search", () => {
		const $ = load(table(rows));
		const $search = $("[data-role='table-search']");

		$search.val("gam").trigger("input");
		jest.advanceTimersByTime(140);

		$search.trigger($.Event("keydown", { key: "Escape" }));

		expect($search.val()).toBe("");
		expect(visibleNames($)).toHaveLength(4);
	});
});

describe("sorting", () => {
	test("a sortable header sorts ascending, then descending, keeping drawers with their rows", () => {
		const $ = load(table(rows, { sortable: true }));
		const $sort = $(".switchrail-audit-th__sort");

		$sort.trigger("click");
		expect(visibleNames($)).toEqual(["Alpha", "Beta", "Delta", "Gamma"]);
		expect($sort.closest("th").attr("aria-sort")).toBe("ascending");

		$sort.trigger("click");
		expect(visibleNames($)).toEqual(["Gamma", "Delta", "Beta", "Alpha"]);
		expect($sort.closest("th").attr("aria-sort")).toBe("descending");

		$("tbody > tr.switchrail-audit-row").each(function () {
			expect($(this).next().hasClass("switchrail-audit-row-detail")).toBe(true);
		});
	});

	test("number columns sort by value, not by text", () => {
		const numbers = [
			{ name: "a", status: "compatible", cells: ["1,200"] },
			{ name: "b", status: "compatible", cells: ["30"] },
			{ name: "c", status: "compatible", cells: ["4"] }
		];
		const $ = load(
			table(numbers, {
				expandable: false,
				extraHeads: ['<th class="switchrail-audit-th is-sortable" data-sort-type="number" aria-sort="none"><button type="button" class="switchrail-audit-th__sort">Rows</button></th>']
			})
		);

		$("th[data-sort-type='number'] .switchrail-audit-th__sort").trigger("click");

		expect(visibleNames($)).toEqual(["c", "b", "a"]);
	});
});

describe("paging", () => {
	const many = Array.from({ length: 45 }, (value, index) => ({
		name: `Item ${String(index + 1).padStart(2, "0")}`,
		status: index < 5 ? "review" : "compatible"
	}));

	test("rows are shown twenty at a time with a pager", () => {
		const $ = load(table(many));
		const $pager = $(".switchrail-audit-pager");

		expect(visibleNames($)).toHaveLength(20);
		expect($pager.prop("hidden")).toBe(false);
		expect($pager.find(".switchrail-audit-pager__range").text()).toBe("1–20 of 45");
		expect($pager.find(".switchrail-audit-pager__page").text()).toBe("Page 1 of 3");
		expect($pager.find("[data-page='prev']").prop("disabled")).toBe(true);

		$pager.find("[data-page='next']").trigger("click");
		$pager.find("[data-page='next']").trigger("click");

		expect(visibleNames($)).toHaveLength(5);
		expect($pager.find(".switchrail-audit-pager__range").text()).toBe("41–45 of 45");
		expect($pager.find("[data-page='next']").prop("disabled")).toBe(true);

		$pager.find("[data-page='prev']").trigger("click");
		expect(visibleNames($)[0]).toBe("Item 21");
	});

	test("filtering returns to the first page and hides a pager with one page", () => {
		const $ = load(table(many));

		$(".switchrail-audit-pager [data-page='next']").trigger("click");
		$("[data-filter-value='review']").trigger("click");

		expect(visibleNames($)).toHaveLength(5);
		expect($(".switchrail-audit-pager").prop("hidden")).toBe(true);
	});
});

describe("row drawers", () => {
	test("the toggle opens and closes the drawer", () => {
		const $ = load(table(rows));
		const $toggle = $("[data-role='row-toggle']").first();
		const $detail = $toggle.closest("tr").next(".switchrail-audit-row-detail");

		$toggle.trigger("click");
		expect($toggle.attr("aria-expanded")).toBe("true");
		expect($detail.prop("hidden")).toBe(false);
		expect($toggle.closest("tr").hasClass("is-open")).toBe(true);

		$toggle.trigger("click");
		expect($toggle.attr("aria-expanded")).toBe("false");
		expect($detail.prop("hidden")).toBe(true);
	});

	test("clicking the row opens it, but a link inside the row does not", () => {
		const $ = load(table(rows));
		const $row = $("tr.switchrail-audit-row").first();

		$row.find("a").trigger("click");
		expect($row.hasClass("is-open")).toBe(false);

		$row.find(".switchrail-audit-col-status").trigger("click");
		expect($row.hasClass("is-open")).toBe(true);
	});

	test("finishing a text selection is not a click", () => {
		const $ = load(table(rows));
		const $row = $("tr.switchrail-audit-row").first();
		const spy = jest.spyOn(window, "getSelection").mockReturnValue({ toString: () => "Alpha" });

		$row.find(".switchrail-audit-col-status").trigger("click");

		expect($row.hasClass("is-open")).toBe(false);
		spy.mockRestore();
	});

	test("an open drawer is hidden with its filtered-out row and comes back open", () => {
		const $ = load(table(rows));
		const $row = $("tr.switchrail-audit-row").first();
		const $detail = $row.next(".switchrail-audit-row-detail");

		$row.find("[data-role='row-toggle']").trigger("click");
		$("[data-filter-value='compatible']").trigger("click");
		expect($detail.is(":visible")).toBe(false);

		$("[data-filter-value='all']").trigger("click");
		expect($detail.is(":visible")).toBe(true);
	});

	test("a row without a drawer does nothing", () => {
		const $ = load(table(rows));
		const $row = $("tr.switchrail-audit-row").first();

		$row.next(".switchrail-audit-row-detail").remove();
		$row.find("[data-role='row-toggle']").trigger("click");

		expect($row.find("[data-role='row-toggle']").attr("aria-expanded")).toBe("false");
	});
});

test("a table view without a table is ignored", () => {
	const $ = load('<div data-role="table-view"></div>');

	expect($(".switchrail-audit-pager").length).toBe(0);
});
