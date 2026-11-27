/**
 * Menus, help, the "/" shortcut, rescan confirmation, busy buttons and copying.
 */
const { load, unload } = require("./load");

beforeEach(() => {
	jest.useFakeTimers();

	// jsdom cannot submit a form; stop it trying after the script has run.
	window.addEventListener("submit", preventSubmit);
});

afterEach(() => {
	unload();
	window.removeEventListener("submit", preventSubmit);
	jest.restoreAllMocks();
	jest.useRealTimers();
	delete window.HTMLDialogElement.prototype.showModal;
	delete window.HTMLDialogElement.prototype.close;
});

function preventSubmit(event) {
	event.preventDefault();
}

/**
 * Gives jsdom's <dialog> the two methods it is missing.
 */
function supportDialogs() {
	window.HTMLDialogElement.prototype.showModal = function () {
		this.open = true;
	};
	window.HTMLDialogElement.prototype.close = function () {
		this.open = false;
		this.dispatchEvent(new window.Event("close"));
	};
}

describe("menus", () => {
	const markup = `
		<details class="cutover-audit-menu" open><summary>Export</summary><a href="#" id="inside">JSON</a></details>
		<details class="cutover-audit-menu"><summary>Rescan</summary></details>
		<p id="outside">elsewhere</p>`;

	test("a click outside closes an open menu, a click inside does not", () => {
		const $ = load(markup);
		const menu = $(".cutover-audit-menu")[0];

		$("#inside").trigger("click");
		expect(menu.open).toBe(true);

		$("#outside").trigger("click");
		expect(menu.open).toBe(false);
	});

	test("Escape closes an open menu and returns focus to it", () => {
		const $ = load(markup);
		const menu = $(".cutover-audit-menu")[0];

		$(document).trigger($.Event("keydown", { key: "Escape" }));

		expect(menu.open).toBe(false);
		expect(document.activeElement).toBe(menu.querySelector("summary"));
	});

	test("other keys leave menus alone", () => {
		const $ = load(markup);

		$(document).trigger($.Event("keydown", { key: "a" }));

		expect($(".cutover-audit-menu")[0].open).toBe(true);
	});
});

describe("help", () => {
	test("the bar button drives the WordPress help panel", () => {
		const $ = load(`
			<button data-role="help-toggle" aria-expanded="false">Help</button>
			<a id="contextual-help-link" href="#"></a>
			<div id="contextual-help-wrap" style="display:none"></div>`);
		const clicks = jest.fn();

		$("#contextual-help-link").on("click", clicks);

		$("[data-role='help-toggle']").trigger("click");
		expect(clicks).toHaveBeenCalledTimes(1);
		expect($("[data-role='help-toggle']").attr("aria-expanded")).toBe("true");

		$("#contextual-help-wrap").show();
		$("[data-role='help-toggle']").trigger("click");
		expect($("[data-role='help-toggle']").attr("aria-expanded")).toBe("false");
	});
});

describe("the / shortcut", () => {
	const markup = `<div class="cutover-audit"><input type="search" class="cutover-audit-search__input" id="search" /><textarea id="notes"></textarea></div>`;

	test("focuses the search field", () => {
		const $ = load(markup);
		const event = $.Event("keydown", { key: "/" });

		$(document.body).trigger(event);

		expect(document.activeElement.id).toBe("search");
		expect(event.isDefaultPrevented()).toBe(true);
	});

	test("is ignored while typing or with a modifier", () => {
		const $ = load(markup);

		$("#notes").trigger("focus").trigger($.Event("keydown", { key: "/" }));
		expect(document.activeElement.id).toBe("notes");

		$(document.body).trigger($.Event("keydown", { key: "/", ctrlKey: true }));
		expect(document.activeElement.id).toBe("notes");
	});
});

describe("rescan confirmation", () => {
	const markup = `
		<div class="wrap cutover-audit">
			<form class="cutover-audit-inline-form" data-confirm="rescan-all" id="rescan" method="post">
				<button type="submit" id="go">Rescan</button>
			</form>
		</div>`;

	test("without <dialog> support it falls back to confirm()", () => {
		const $ = load(markup);
		const confirm = jest.spyOn(window, "confirm").mockReturnValue(false);

		$("#rescan").trigger("submit");

		expect(confirm).toHaveBeenCalledWith("Rescan everything?\n\nThis re-reads everything.");
		expect($("#go").hasClass("is-busy")).toBe(false);

		confirm.mockReturnValue(true);
		$("#rescan").trigger("submit");

		expect($("#go").hasClass("is-busy")).toBe(true);
	});

	test("a full rescan asks first in a dialog", () => {
		supportDialogs();
		const $ = load(markup);

		$("#rescan").trigger("submit");

		const $dialog = $("dialog.cutover-audit-dialog");

		expect($dialog.length).toBe(1);
		expect($dialog.parent().is(".wrap.cutover-audit")).toBe(true);
		expect($dialog[0].open).toBe(true);
		expect($dialog.find(".cutover-audit-dialog__title").text()).toBe("Rescan everything?");
		expect($("#go").hasClass("is-busy")).toBe(false);
		expect(document.activeElement).toBe($dialog.find("[data-dialog='confirm']")[0]);
	});

	test("cancel closes the dialog and returns focus to the button", () => {
		supportDialogs();
		const $ = load(markup);

		$("#rescan").trigger("submit");
		$("[data-dialog='cancel']").trigger("click");

		expect($("dialog.cutover-audit-dialog")[0].open).toBe(false);
		expect(document.activeElement.id).toBe("go");
		expect($("#rescan").attr("data-confirmed")).toBeUndefined();
	});

	test("a click on the backdrop closes the dialog", () => {
		supportDialogs();
		const $ = load(markup);

		$("#rescan").trigger("submit");
		$("dialog.cutover-audit-dialog").trigger("click");

		expect($("dialog.cutover-audit-dialog")[0].open).toBe(false);
	});

	test("confirming submits the form and shows it as busy", () => {
		supportDialogs();
		const $ = load(markup);
		const form = $("#rescan")[0];
		const submitted = jest.fn();

		form.addEventListener("submit", submitted);

		$("#rescan").trigger("submit");
		$("[data-dialog='confirm']").trigger("click");

		expect(form.getAttribute("data-confirmed")).toBe("true");
		expect(submitted).toHaveBeenCalled();
		expect($("#go").hasClass("is-busy")).toBe(true);
		expect($("#rescan").hasClass("is-submitting")).toBe(true);
	});

	test("the dialog is built once", () => {
		supportDialogs();
		const $ = load(markup);

		$("#rescan").trigger("submit");
		$("[data-dialog='cancel']").trigger("click");
		$("#rescan").trigger("submit");

		expect($("dialog.cutover-audit-dialog").length).toBe(1);
	});
});

describe("busy buttons", () => {
	test("only the pressed button in the scope menu shows as busy", () => {
		const $ = load(`
			<form class="cutover-audit-menu__form" id="menu">
				<button type="submit" name="scope" value="plugin" id="plugins">Plugins</button>
				<button type="submit" name="scope" value="theme" id="themes">Themes</button>
			</form>`);

		$("#themes").trigger("click");

		expect($("#themes").hasClass("is-busy")).toBe(true);
		expect($("#plugins").hasClass("is-busy")).toBe(false);
		expect($("#menu").hasClass("is-submitting")).toBe(true);
	});

	test("a submit with no known button marks the first one", () => {
		const $ = load(`<form class="cutover-audit-inline-form" id="f"><button type="submit" id="a">A</button></form>`);

		$("#f").trigger("submit");

		expect($("#a").hasClass("is-busy")).toBe(true);
	});
});

describe("clipboard", () => {
	// The native promise resolves on the microtask queue, and jQuery then hands
	// the result over on a timer.
	async function settle() {
		await Promise.resolve();
		jest.advanceTimersByTime(1);
	}

	const labelled = '<button data-role="copy" data-clipboard="ALTER TABLE x;"><svg></svg><span>Copy 1 SQL statement</span></button>';
	const iconOnly = '<button data-role="copy" data-clipboard="SELECT 1;" aria-label="Copy to clipboard"><svg class="old"></svg></button>';

	function secureClipboard(result) {
		Object.defineProperty(window, "isSecureContext", { configurable: true, value: true });
		Object.defineProperty(window.navigator, "clipboard", {
			configurable: true,
			value: { writeText: jest.fn(() => result) }
		});
	}

	afterEach(() => {
		delete window.isSecureContext;
		delete window.navigator.clipboard;
	});

	test("copies with the clipboard API and confirms on the label, then resets", async () => {
		secureClipboard(Promise.resolve());
		const $ = load(labelled);

		$("[data-role='copy']").trigger("click");
		await settle();

		expect(window.navigator.clipboard.writeText).toHaveBeenCalledWith("ALTER TABLE x;");
		expect($("[data-role='copy'] span").text()).toBe("Copied");
		expect($("[data-role='copy']").hasClass("is-copied")).toBe(true);

		jest.advanceTimersByTime(1800);

		expect($("[data-role='copy'] span").text()).toBe("Copy 1 SQL statement");
		expect($("[data-role='copy']").hasClass("is-copied")).toBe(false);
	});

	test("an icon-only button swaps its icon and label", async () => {
		secureClipboard(Promise.resolve());
		const $ = load(iconOnly);

		$("[data-role='copy']").trigger("click");
		await settle();

		expect($("[data-role='copy']").attr("aria-label")).toBe("Copied");
		expect($("[data-role='copy'] svg.old").length).toBe(0);

		jest.advanceTimersByTime(1800);

		expect($("[data-role='copy']").attr("aria-label")).toBe("Copy to clipboard");
	});

	test("without the clipboard API it falls back to execCommand", () => {
		document.execCommand = jest.fn(() => true);
		const $ = load(labelled);

		$("[data-role='copy']").trigger("click");

		expect(document.execCommand).toHaveBeenCalledWith("copy");
		expect($("textarea").length).toBe(0);
		expect($("[data-role='copy'] span").text()).toBe("Copied");
	});

	test("a failed copy tells the reader how to copy instead", () => {
		document.execCommand = jest.fn(() => {
			throw new Error("blocked");
		});
		const $ = load(labelled + iconOnly);

		$("[data-role='copy']").trigger("click");

		expect($("[data-role='copy']").first().find("span").text()).toBe("Press Ctrl+C to copy");
		expect($("[data-role='copy']").last().attr("aria-label")).toBe("Press Ctrl+C to copy");
	});
});
