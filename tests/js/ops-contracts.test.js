"use strict";

const { test } = require("node:test");
const assert = require("node:assert/strict");
const contracts = require("../../public/js/ops-contracts.js");

test("toolbarUrl drops empties, page, and surrounding space", function () {
    const formData = new FormData();
    formData.append("q", "  acme  ");
    formData.append("channel", "");
    formData.append("page", "4");
    formData.append("status", "error");

    const url = contracts.toolbarUrl("/sites", "https://plane.test", formData);

    assert.equal(url.origin, "https://plane.test");
    assert.equal(url.pathname, "/sites");
    assert.equal(url.searchParams.get("q"), "acme");
    assert.equal(url.searchParams.get("status"), "error");
    assert.equal(url.searchParams.has("page"), false);
    assert.equal(url.searchParams.has("channel"), false);
});

test("jobStateSignature changes when queue_position moves", function () {
    const jobs = new Map([
        ["job-1", { id: "job-1", status: "queued", progress: null, queue_position: 4 }],
    ]);

    const before = contracts.jobStateSignature(jobs);
    jobs.get("job-1").queue_position = 3;

    assert.notEqual(contracts.jobStateSignature(jobs), before);
});

test("advanceBackoff resets when the signature changes", function () {
    const opts = { slowAfter: 8, tierCount: 3 };
    const afterChange = contracts.advanceBackoff(
        { lastSignature: "old", pollTier: 2, unchangedPolls: 7 },
        "job-1:queued::3",
        opts
    );

    assert.deepEqual(afterChange, {
        lastSignature: "job-1:queued::3",
        pollTier: 0,
        unchangedPolls: 0,
    });

    let state = afterChange;
    for (let i = 0; i < 8; i += 1) {
        state = contracts.advanceBackoff(state, afterChange.lastSignature, opts);
    }

    assert.equal(state.pollTier, 1);
    assert.equal(state.unchangedPolls, 0);
});

test("diffJobRows treats 12 and \"12\" as the same row", function () {
    const plan = contracts.diffJobRows(["12"], [
        { id: 12, status: "running" },
        { id: "13" },
    ]);

    assert.deepEqual(plan.reuse, ["12"]);
    assert.deepEqual(plan.create, ["13"]);
    assert.deepEqual(plan.remove, []);
});

test("bulkScope uses filteredTotal only when all is checked", function () {
    assert.deepEqual(contracts.bulkScope(false, 2, 40), { all: false, count: 2 });
    assert.deepEqual(contracts.bulkScope(true, 2, 40), { all: true, count: 40 });
    assert.deepEqual(contracts.bulkScope(true, 0, 40), { all: true, count: 40 });
    assert.deepEqual(contracts.bulkScope(false, 0, 40), { all: false, count: 0 });
});

test("interpolateConfirm replaces every __COUNT__ and __TARGET__", function () {
    assert.equal(
        contracts.interpolateConfirm("Move __COUNT__ to __TARGET__ (__COUNT__)", 3, "beta"),
        "Move 3 to beta (3)"
    );
    assert.equal(contracts.interpolateConfirm("Touch __COUNT__", 12, "main"), "Touch 12");
    assert.equal(contracts.interpolateConfirm("Move to __TARGET__", 9, null), "Move to ");
    assert.equal(contracts.interpolateConfirm("", 3, "beta"), "");
});

test("isTyping is true for fields and contenteditable, not for chrome", function () {
    assert.equal(contracts.isTyping(null), false);
    assert.equal(contracts.isTyping(undefined), false);
    assert.equal(contracts.isTyping({ tagName: "INPUT" }), true);
    assert.equal(contracts.isTyping({ tagName: "TEXTAREA" }), true);
    assert.equal(contracts.isTyping({ tagName: "SELECT" }), true);
    assert.equal(contracts.isTyping({ tagName: "BUTTON" }), false);
    assert.equal(contracts.isTyping({ tagName: "DIV" }), false);
    assert.equal(contracts.isTyping({ tagName: "DIV", isContentEditable: true }), true);
    assert.equal(contracts.isTyping({ tagName: "A" }), false);
});

test("isFailedStatus is only the failed lane", function () {
    assert.equal(contracts.isFailedStatus("failed"), true);
    assert.equal(contracts.isFailedStatus("completed"), false);
    assert.equal(contracts.isFailedStatus("cancelled"), false);
    assert.equal(contracts.isFailedStatus("queued"), false);
    assert.equal(contracts.isFailedStatus("running"), false);
    assert.equal(contracts.isFailedStatus(""), false);
});

test("jobsIndexUrl adds failed=1 only when the toggle is on", function () {
    assert.equal(contracts.jobsIndexUrl("/jobs", false), "/jobs");
    assert.equal(contracts.jobsIndexUrl("/jobs", true), "/jobs?failed=1");
    assert.equal(contracts.jobsIndexUrl("/jobs?foo=1", true), "/jobs?foo=1&failed=1");
    assert.equal(
        contracts.jobsIndexUrl("https://plane.test/jobs", true),
        "https://plane.test/jobs?failed=1"
    );
    assert.equal(contracts.jobsIndexUrl("", true), "/jobs?failed=1");
});

test("textMatches is empty-query-all and case-insensitive", function () {
    assert.equal(contracts.textMatches("", "Coolify env"), true);
    assert.equal(contracts.textMatches("  ", "Coolify env"), true);
    assert.equal(contracts.textMatches("ENV", "Coolify env defaults"), true);
    assert.equal(contracts.textMatches("webhook", "github webhook catalog"), true);
    assert.equal(contracts.textMatches("webhook", "Coolify env"), false);
    assert.equal(contracts.textMatches("APP_KEY", "env APP_KEY timezone"), true);
});

test("envRowVisible keeps the catalog when the needle is a section, not a key", function () {
    assert.equal(contracts.envRowVisible("", "APP_KEY static", false), true);
    assert.equal(contracts.envRowVisible("ortam", "APP_KEY static", false), true);
    assert.equal(contracts.envRowVisible("APP_KEY", "APP_KEY static", true), true);
    assert.equal(contracts.envRowVisible("APP_KEY", "DB_HOST mysql", true), false);
});

test("pageIsHidden and shouldSchedulePoll skip a hidden tab", function () {
    assert.equal(contracts.pageIsHidden(null), false);
    assert.equal(contracts.pageIsHidden({}), false);
    assert.equal(contracts.pageIsHidden({ hidden: false }), false);
    assert.equal(contracts.pageIsHidden({ hidden: true }), true);
    assert.equal(contracts.shouldSchedulePoll(true, { hidden: false }), true);
    assert.equal(contracts.shouldSchedulePoll(true, { hidden: true }), false);
    assert.equal(contracts.shouldSchedulePoll(false, { hidden: false }), false);
    assert.equal(contracts.shouldSchedulePoll(false, { hidden: true }), false);
});

test("isSameList is origin plus pathname, not the query", function () {
    const here = { origin: "https://plane.test", pathname: "/sites" };
    const filtered = { origin: "https://plane.test", pathname: "/sites", search: "?q=acme" };
    const otherPath = { origin: "https://plane.test", pathname: "/sites/abc" };
    const otherHost = { origin: "https://other.test", pathname: "/sites" };

    assert.equal(contracts.isSameList(null, here), false);
    assert.equal(contracts.isSameList(filtered, null), false);
    assert.equal(contracts.isSameList(filtered, here), true);
    assert.equal(contracts.isSameList(otherPath, here), false);
    assert.equal(contracts.isSameList(otherHost, here), false);
});

test("listQueryChanged is what popstate uses to skip a fetch", function () {
    assert.equal(contracts.listQueryChanged("?q=a", "?q=b"), true);
    assert.equal(contracts.listQueryChanged("?q=a", "?q=a"), false);
    assert.equal(contracts.listQueryChanged("", ""), false);
    assert.equal(contracts.listQueryChanged(undefined, ""), false);
    assert.equal(contracts.listQueryChanged("?page=2", ""), true);
});

test("shouldPatchListRegion requires ok and X-Ops-List-Region 1", function () {
    assert.deepEqual(contracts.listFetchHeaders(), {
        Accept: "text/html",
        "X-Requested-With": "XMLHttpRequest",
        "X-Ops-List-Fragment": "region",
    });
    assert.equal(contracts.LIST_REGION_HEADER, "X-Ops-List-Region");
    assert.equal(contracts.shouldPatchListRegion(true, "1"), true);
    assert.equal(contracts.shouldPatchListRegion(true, "0"), false);
    assert.equal(contracts.shouldPatchListRegion(false, "1"), false);
    assert.equal(contracts.shouldPatchListRegion(true, null), false);
    assert.equal(contracts.shouldPatchListRegion(true, ""), false);
});

test("listHistoryMode replaces keystrokes and pushes requeries", function () {
    assert.equal(contracts.listHistoryMode("toolbar"), "replace");
    assert.equal(contracts.listHistoryMode("requery"), "push");
    assert.equal(contracts.listHistoryMode("refresh"), "keep");
    assert.equal(contracts.listHistoryMode("refresh", { redirect: false }), "keep");
    assert.equal(contracts.listHistoryMode("refresh", { redirect: true }), "replace");
    assert.equal(contracts.listHistoryMode("pop"), "keep");
    assert.equal(contracts.listHistoryMode("unknown"), "keep");
    assert.equal(contracts.listHistoryWrite("push"), "pushState");
    assert.equal(contracts.listHistoryWrite("replace"), "replaceState");
    assert.equal(contracts.listHistoryWrite("keep"), null);
    assert.equal(contracts.listHistoryWrite(""), null);
    assert.deepEqual(contracts.LIST_HISTORY_STATE, { opsList: true });
});

test("shouldInterceptListHref is a same-list requery only", function () {
    const here = { origin: "https://plane.test", pathname: "/sites" };
    const paging = { origin: "https://plane.test", pathname: "/sites", search: "?page=2" };
    const detail = { origin: "https://plane.test", pathname: "/sites/abc" };

    assert.equal(contracts.isListRequeryLink({}), false);
    assert.equal(contracts.isListRequeryLink({ inRegion: true }), true);
    assert.equal(contracts.isListRequeryLink({ clear: true }), true);
    assert.equal(contracts.isListRequeryLink({ view: true }), true);
    assert.equal(contracts.shouldInterceptListHref(paging, here, { inRegion: true }), true);
    assert.equal(contracts.shouldInterceptListHref(paging, here, { clear: true }), true);
    assert.equal(contracts.shouldInterceptListHref(paging, here, { view: true }), true);
    assert.equal(contracts.shouldInterceptListHref(detail, here, { inRegion: true }), false);
    assert.equal(contracts.shouldInterceptListHref(paging, here, {}), false);
});

test("confirmOpen is true only for a visible confirm modal", function () {
    assert.equal(contracts.confirmOpen(null), false);
    assert.equal(
        contracts.CONFIRM_MODAL_SELECTOR,
        "[data-ops-confirm-modal]:not([hidden])"
    );

    const hiddenOnly = {
        querySelector: function (sel) {
            return sel === "[data-ops-confirm-modal]" ? { hidden: true } : null;
        },
    };

    assert.equal(contracts.confirmOpen(hiddenOnly), false);
    assert.equal(contracts.confirmOpen({
        querySelector: function (sel) {
            return sel === contracts.CONFIRM_MODAL_SELECTOR ? { hidden: false } : null;
        },
    }), true);
});

test("moveColumnKey swaps one step and never passes a locked key", function () {
    const keys = ["site", "domain", "publish", "live"];
    const locked = ["site"];

    assert.deepEqual(contracts.moveColumnKey(keys, "publish", -1, locked), ["site", "publish", "domain", "live"]);
    assert.deepEqual(contracts.moveColumnKey(keys, "publish", 1, locked), ["site", "domain", "live", "publish"]);
    // Nothing moves above the identity column, and the column itself never moves.
    assert.deepEqual(contracts.moveColumnKey(keys, "domain", -1, locked), keys);
    assert.deepEqual(contracts.moveColumnKey(keys, "site", 1, locked), keys);
    // Edges and unknown keys are no-ops.
    assert.deepEqual(contracts.moveColumnKey(keys, "live", 1, locked), keys);
    assert.deepEqual(contracts.moveColumnKey(keys, "nope", -1, locked), keys);
    // The input is never mutated.
    assert.deepEqual(keys, ["site", "domain", "publish", "live"]);
});

test("pickerColumnOrder puts saved columns first and keeps the rest in place", function () {
    const current = ["site", "domain", "publish", "live", "mail", "updated"];

    assert.deepEqual(
        contracts.pickerColumnOrder(current, ["site", "updated", "domain"]),
        ["site", "updated", "domain", "publish", "live", "mail"]
    );
    // Keys the picker does not know are ignored.
    assert.deepEqual(
        contracts.pickerColumnOrder(current, ["site", "ghost", "live"]),
        ["site", "live", "domain", "publish", "mail", "updated"]
    );
});

test("searchEscapeAction clears a filled search box and blurs an empty one", function () {
    assert.equal(contracts.searchEscapeAction("acme"), "clear");
    assert.equal(contracts.searchEscapeAction("  x "), "clear");
    assert.equal(contracts.searchEscapeAction(""), "blur");
    assert.equal(contracts.searchEscapeAction("   "), "blur");
    assert.equal(contracts.searchEscapeAction(null), "blur");
});

test("scrollEdges only reports the edges that hide items", function () {
    assert.deepEqual(contracts.scrollEdges(0, 300, 300), { start: false, end: false });
    assert.deepEqual(contracts.scrollEdges(0, 500, 300), { start: false, end: true });
    assert.deepEqual(contracts.scrollEdges(100, 500, 300), { start: true, end: true });
    assert.deepEqual(contracts.scrollEdges(200, 500, 300), { start: true, end: false });
    assert.deepEqual(contracts.scrollEdges(NaN, undefined, 300), { start: false, end: false });
});
