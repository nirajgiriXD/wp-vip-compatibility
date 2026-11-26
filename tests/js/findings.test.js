/**
 * The findings screen: switching tiers in place and expanding every entry.
 */
const { load, unload } = require("./load");
const { findings } = require("./fixtures");

beforeEach(() => {
	jest.useFakeTimers();
	window.history.replaceState(null, "", "/wp-admin/admin.php?page=pfa-findings");
});

afterEach(() => {
	unload();
	jest.useRealTimers();
});

const shownTiers = ($) =>
	$(".pfa-tiergroup")
		.filter((index, group) => !group.hidden)
		.map((index, group) => $(group).data("tier"))
		.get();

const click = ($, $element, extra = {}) => {
	const event = $.Event("click", Object.assign({ button: 0 }, extra));

	$element.trigger(event);

	return event;
};

describe("tiers", () => {
	test("choosing a tier shows only it, without a reload", () => {
		const $ = load(findings());
		const event = click($, $(".pfa-segmented__option[data-tier='important']"));

		expect(event.isDefaultPrevented()).toBe(true);
		expect(shownTiers($)).toEqual(["important"]);
		expect($("[data-role='findings-count']").text()).toBe("2 issues");
		expect($(".pfa-segmented__option.active").data("tier")).toBe("important");
		expect($(".pfa-segmented__option.active").attr("aria-current")).toBe("true");
		expect(window.location.search).toBe("?page=pfa-findings&tier=important");
	});

	test("the tier rides along on the search form and the remove-filter links", () => {
		const $ = load(findings());

		click($, $(".pfa-segmented__option[data-tier='blocking']"));

		expect($("form.pfa-search input[name='tier']").val()).toBe("blocking");
		expect($("a.pfa-activefilter").attr("href")).toContain("tier=blocking");

		click($, $(".pfa-segmented__option[data-tier='']"));

		expect($("form.pfa-search input[name='tier']").length).toBe(0);
		expect($("a.pfa-activefilter").attr("href")).not.toContain("tier=");
	});

	test("choosing the selected tier again clears it", () => {
		const $ = load(findings("info"));

		click($, $(".pfa-segmented__option[data-tier='info']"));

		expect(shownTiers($)).toEqual(["blocking", "important", "info"]);
		expect($(".pfa-segmented__option.active").data("tier")).toBe("");
	});

	test("a tier with nothing in it shows the empty message", () => {
		const $ = load(findings());

		click($, $(".pfa-segmented__option[data-tier='warning']"));

		expect(shownTiers($)).toEqual([]);
		expect($("[data-role='tier-empty']").prop("hidden")).toBe(false);
	});

	test("a modified click is left to the browser", () => {
		const $ = load(findings());
		const event = click($, $(".pfa-segmented__option[data-tier='blocking']"), { ctrlKey: true });

		expect(event.isDefaultPrevented()).toBe(false);
		expect(shownTiers($)).toEqual(["blocking", "important", "info"]);
	});

	test("back and forward follow the address", () => {
		const $ = load(findings());

		window.history.replaceState(null, "", "/wp-admin/admin.php?page=pfa-findings&tier=blocking");
		$(window).trigger("popstate");
		expect(shownTiers($)).toEqual(["blocking"]);

		window.history.replaceState(null, "", "/wp-admin/admin.php?page=pfa-findings&tier=bogus");
		$(window).trigger("popstate");
		expect(shownTiers($)).toEqual(["blocking", "important", "info"]);
	});
});

describe("expand all", () => {
	test("the button only appears with the script and opens every entry", () => {
		const $ = load(findings());
		const $toggle = $("[data-role='expand-all']");

		expect($toggle.prop("hidden")).toBe(false);
		expect($toggle.attr("aria-pressed")).toBe("false");

		$toggle.trigger("click");

		expect($("details.pfa-fix:not([open])").length).toBe(0);
		expect($toggle.attr("aria-pressed")).toBe("true");
		expect($toggle.find("span").text()).toBe("Collapse all");

		$toggle.trigger("click");

		expect($("details.pfa-fix[open]").length).toBe(0);
		expect($toggle.find("span").text()).toBe("Expand all");
	});

	test("opening every entry by hand updates the button", () => {
		const $ = load(findings());

		$("details.pfa-fix").each(function () {
			this.open = true;
			$(this).trigger("toggle");
		});

		expect($("[data-role='expand-all']").attr("aria-pressed")).toBe("true");
	});
});
