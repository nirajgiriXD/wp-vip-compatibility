/**
 * The queue that resolves "Not scanned yet" rows one AJAX request at a time.
 */
const jQuery = require("jquery");
const { load, unload } = require("./load");
const { table } = require("./fixtures");

let requests;

beforeEach(() => {
	jest.useFakeTimers();
	requests = [];

	// Each request waits until the test settles it.
	jest.spyOn(jQuery, "ajax").mockImplementation((options) => {
		const deferred = jQuery.Deferred();
		const request = { options, deferred };

		requests.push(request);

		if (options.beforeSend) {
			options.beforeSend();
		}

		deferred.done((response) => options.success && options.success(response));
		deferred.fail(() => options.error && options.error());

		return deferred.promise();
	});
});

afterEach(() => {
	unload();
	jest.restoreAllMocks();
	jest.useRealTimers();
	window.history.replaceState(null, "", "/");
});

const pending = (count) =>
	Array.from({ length: count }, (value, index) => ({
		name: `P${index}`,
		status: "pending",
		pending: true,
		target: `plugin:p${index}`
	}));

const verdict = (state, total = 0) => ({
	success: true,
	data: { state, label: state, total, url: total ? "https://example.test/findings" : "", summary: `${total} findings` }
});

test("pending rows are scanned one at a time with the nonce and target", () => {
	const $ = load(table(pending(3)));

	expect(requests).toHaveLength(1);
	expect(requests[0].options.type).toBe("POST");
	expect(requests[0].options.url).toBe("https://example.test/wp-admin/admin-ajax.php");
	expect(requests[0].options.data).toEqual({ _ajax_nonce: "abc123", action: "preflight_audit_scan_target", target: "plugin:p0" });
	expect($("[data-role='scan']").prop("hidden")).toBe(false);
	expect($("[data-role='scan-label']").text()).toBe("Scanning 0 of 3…");
	expect($("td.pfa-col-status").first().text()).toContain("Scanning…");

	requests[0].deferred.resolve(verdict("compatible"));

	expect(requests).toHaveLength(2);
	expect(requests[1].options.data.target).toBe("plugin:p1");
	expect($("[data-role='scan-label']").text()).toBe("Scanning 1 of 3…");
	expect($("[data-role='scan-bar']")[0].style.width).toMatch(/^33\.33/);
});

test("a resolved row takes its verdict, and links to its findings", () => {
	const $ = load(table(pending(1)));

	requests[0].deferred.resolve(verdict("not-compatible", 4));

	const $cell = $("td.pfa-col-status");

	expect($cell.hasClass("is-pending")).toBe(false);
	expect($cell.hasClass("is-not-compatible")).toBe(true);
	expect($cell.closest("tr").attr("data-status")).toBe("not-compatible");
	expect($cell.find(".pfa-status--bad").length).toBe(1);
	expect($cell.find("a.pfa-status__link").attr("href")).toBe("https://example.test/findings");
	expect($cell.find("a.pfa-status__link").text()).toBe("4 findings");
});

test("the findings link uses the count the server pluralised", () => {
	const $ = load(table(pending(1)));
	const response = verdict("review", 1);

	response.data.count = "1 finding";
	requests[0].deferred.resolve(response);

	expect($("a.pfa-status__link").text()).toBe("1 finding");
});

test("a clean row gets no findings link", () => {
	const $ = load(table(pending(1)));

	requests[0].deferred.resolve(verdict("compatible"));

	expect($("a.pfa-status__link").length).toBe(0);
	expect($(".pfa-status--ok").length).toBe(1);
});

test("a failed request marks the row for review with the error", () => {
	const $ = load(table(pending(2)));

	requests[0].deferred.reject();
	requests[1].deferred.resolve({ success: false, data: { message: "Unknown scan target." } });

	const $cells = $("td.pfa-col-status");

	expect($cells.eq(0).text()).toContain("Scan failed");
	expect($cells.eq(1).text()).toContain("Unknown scan target.");
	expect($cells.eq(1).closest("tr").attr("data-status")).toBe("review");
});

test("filters are held during the scan and released with real counts after", () => {
	const $ = load(table(pending(2)));
	const $buttons = $("[data-filter-group] button");

	expect($buttons.filter(":disabled").length).toBe($buttons.length);
	expect($("[data-filter-value='review'] .pfa-segmented__count").text()).toBe("");
	expect($("[data-filter-value='all'] .pfa-segmented__count").text()).toBe("2");

	requests[0].deferred.resolve(verdict("review"));
	requests[1].deferred.resolve(verdict("compatible"));

	expect($buttons.filter(":disabled").length).toBe(0);
	expect($("[data-filter-value='review'] .pfa-segmented__count").text()).toBe("1");
	expect($("[data-role='scan']").attr("hidden")).toBe("hidden");
	expect($("[data-role='scan-bar']")[0].style.width).toBe("100%");
});

test("a page without pending rows sends nothing", () => {
	load(table([{ name: "A", status: "compatible" }]));

	expect(requests).toHaveLength(0);
});

describe("a rescan started from the menu", () => {
	const report = '<div data-role="scan-report" data-scope="plugin"></div>';

	test("reports completion and reloads onto the confirmation", () => {
		window.history.replaceState(null, "", "/wp-admin/admin.php?page=pfa-plugins&pfa-scanning=plugin");
		const errors = jest.spyOn(console, "error").mockImplementation(() => {});

		load(report + table(pending(1)));
		requests[0].deferred.resolve(verdict("compatible"));

		expect(requests).toHaveLength(2);
		expect(requests[1].options.data).toEqual({ _ajax_nonce: "abc123", action: "preflight_audit_scan_complete", scope: "plugin" });

		requests[1].deferred.resolve({ success: true });

		// jsdom cannot navigate, and says so: that is the reload being attempted.
		expect(errors.mock.calls.join(" ")).toMatch(/navigation/i);
	});

	test("an area with nothing in it still finishes", () => {
		jest.spyOn(console, "error").mockImplementation(() => {});

		load(report + table([]));

		expect(requests).toHaveLength(1);
		expect(requests[0].options.data.action).toBe("preflight_audit_scan_complete");
	});
});
