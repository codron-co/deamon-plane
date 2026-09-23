(function (root, factory) {
    "use strict";

    const api = factory();

    if (typeof module === "object" && module.exports) {
        module.exports = api;
    }

    root.PlaneOpsContracts = api;
})(typeof globalThis !== "undefined" ? globalThis : this, function () {
    "use strict";

    /**
     * Shared helpers for ops-list / ops-jobs / ops-ui / shortcuts IIFEs
     * and `node --test`. Classic script in the browser; CommonJS when
     * required from tests/js. Do not copy these algorithms into the test
     * tree (ADR-11).
     */

    const CONFIRM_MODAL_SELECTOR = "[data-ops-confirm-modal]:not([hidden])";
    const TYPING_TAGS = { input: true, textarea: true, select: true };

    const toolbarUrl = function (action, origin, formData) {
        const url = new URL(action || origin, origin);
        const params = new URLSearchParams();

        formData.forEach(function (value, key) {
            if (typeof value !== "string" || key === "page") {
                return;
            }

            const trimmed = value.trim();
            if (trimmed !== "") {
                params.append(key, trimmed);
            }
        });

        url.search = params.toString();

        return url;
    };

    const jobRowKey = function (item) {
        if (!item || item.id === undefined || item.id === null) {
            return null;
        }

        return String(item.id);
    };

    /**
     * A poll must patch the same row, not rebuild it: identity is String(id).
     * 12 and "12" are one row; a missing id is skipped, not a new node.
     */
    const diffJobRows = function (existingKeys, incomingItems) {
        const keys = [];
        const incoming = new Set();

        (incomingItems || []).forEach(function (item) {
            const key = jobRowKey(item);
            if (key === null) {
                return;
            }

            keys.push(key);
            incoming.add(key);
        });

        const existing = (existingKeys || []).map(String);
        const existingSet = new Set(existing);

        return {
            keys: keys,
            reuse: keys.filter(function (key) {
                return existingSet.has(key);
            }),
            create: keys.filter(function (key) {
                return !existingSet.has(key);
            }),
            remove: existing.filter(function (key) {
                return !incoming.has(key);
            }),
        };
    };

    /**
     * What the operator would notice changing. Anything else is not worth a
     * faster poll. Accepts the widget Map or a plain array of job objects.
     */
    const jobStateSignature = function (jobs) {
        const parts = [];
        const push = function (id, job) {
            if (!job) {
                return;
            }

            parts.push([
                id,
                job.status,
                job.progress == null ? "" : job.progress,
                job.queue_position == null ? "" : job.queue_position,
            ].join(":"));
        };

        if (jobs && typeof jobs.forEach === "function" && typeof jobs.get === "function") {
            jobs.forEach(function (job, id) {
                push(id, job);
            });
        } else if (Array.isArray(jobs)) {
            jobs.forEach(function (job) {
                if (job && job.id != null) {
                    push(job.id, job);
                }
            });
        }

        parts.sort();

        return parts.join("|");
    };

    const advanceBackoff = function (state, signature, options) {
        const current = state || {};
        const opts = options || {};
        const slowAfter = opts.slowAfter;
        const tierCount = opts.tierCount;

        if (signature !== current.lastSignature) {
            return {
                lastSignature: signature,
                pollTier: 0,
                unchangedPolls: 0,
            };
        }

        let unchangedPolls = (current.unchangedPolls || 0) + 1;
        let pollTier = current.pollTier || 0;

        if (unchangedPolls >= slowAfter && pollTier < tierCount - 1) {
            pollTier += 1;
            unchangedPolls = 0;
        }

        return {
            lastSignature: current.lastSignature,
            pollTier: pollTier,
            unchangedPolls: unchangedPolls,
        };
    };

    /**
     * What the next bulk submit would touch. `all` is the `all=1` sweep
     * (filtered total), never the number of boxes on this page.
     */
    const bulkScope = function (all, checked, filteredTotal) {
        const sweep = Boolean(all);

        return {
            all: sweep,
            count: sweep ? Number(filteredTotal) : Number(checked),
        };
    };

    /**
     * Confirm / summary copy ships `__COUNT__` and (branch) `__TARGET__`.
     * Turkish strings stay in Blade; this only substitutes the tokens.
     */
    const interpolateConfirm = function (template, count, target) {
        if (typeof template !== "string" || template === "") {
            return "";
        }

        return template
            .replace(/__TARGET__/g, target == null ? "" : String(target))
            .replace(/__COUNT__/g, String(count));
    };

    /**
     * A confirm dialog owns the keyboard while it is up. Callers pass
     * `document` (or a querySelector stand-in). The selector includes
     * `:not([hidden])` so a closed modal is not "open".
     */
    const confirmOpen = function (root) {
        if (!root || typeof root.querySelector !== "function") {
            return false;
        }

        return Boolean(root.querySelector(CONFIRM_MODAL_SELECTOR));
    };

    /**
     * Bare `/` and `g` stay off while the operator is in a field.
     * Duck-typed so `node --test` does not need jsdom / HTMLElement.
     */
    const isTyping = function (node) {
        if (node == null || typeof node !== "object") {
            return false;
        }

        if (node.isContentEditable) {
            return true;
        }

        const tag = typeof node.tagName === "string" ? node.tagName.toLowerCase() : "";

        return Object.prototype.hasOwnProperty.call(TYPING_TAGS, tag);
    };

    /**
     * Escape inside a list search box: a filled box is cleared first (and the
     * list re-queried); an empty one hands focus back to the page.
     */
    const searchEscapeAction = function (value) {
        return String(value == null ? "" : value).trim() === "" ? "blur" : "clear";
    };

    /**
     * Which edges of a horizontally scrolling strip hide more items, so the
     * fade only shows where there is something to scroll to.
     */
    const scrollEdges = function (scrollLeft, scrollWidth, clientWidth) {
        const left = Math.max(0, Number(scrollLeft) || 0);
        const hidden = (Number(scrollWidth) || 0) - (Number(clientWidth) || 0);
        if (hidden <= 1) {
            return { start: false, end: false };
        }

        return { start: left > 1, end: left < hidden - 1 };
    };

    const isFailedStatus = function (status) {
        return status === "failed";
    };

    /**
     * In-page Settings search and palette needles. Empty query keeps
     * every section; matching is case-insensitive substring.
     */
    const textMatches = function (query, haystack) {
        const q = String(query == null ? "" : query).trim().toLowerCase();
        if (q === "") {
            return true;
        }

        return String(haystack == null ? "" : haystack).toLowerCase().indexOf(q) !== -1;
    };

    /**
     * Settings env-defaults rows. Empty query, or a section-only needle
     * with no row hit ("ortam", "env"), keeps every row so the catalog
     * does not go blank. A key substring hides the rest.
     */
    const envRowVisible = function (query, haystack, anyRowHit) {
        const q = String(query == null ? "" : query).trim();
        if (q === "" || !anyRowHit) {
            return true;
        }

        return textMatches(query, haystack);
    };

    /**
     * A background tab must cost Coolify nothing. Duck-typed so
     * `node --test` does not need jsdom — pass `{ hidden: true }`.
     */
    const pageIsHidden = function (doc) {
        return Boolean(doc && doc.hidden);
    };

    const shouldSchedulePoll = function (canWrite, doc) {
        return Boolean(canWrite) && !pageIsHidden(doc);
    };

    /**
     * List-fragment fetch. Pest already walks the server half
     * (`X-Ops-List-Fragment` / `X-Ops-List-Region`). These helpers are
     * the browser half: same-path requery, patch vs navigate, and
     * replaceState vs pushState vs keep. Duck-typed — no jsdom.
     */
    const LIST_FRAGMENT_HEADER = "X-Ops-List-Fragment";
    const LIST_FRAGMENT_VALUE = "region";
    const LIST_REGION_HEADER = "X-Ops-List-Region";
    const LIST_REGION_VALUE = "1";
    const LIST_HISTORY_STATE = { opsList: true };

    const listFetchHeaders = function () {
        const headers = {
            Accept: "text/html",
            "X-Requested-With": "XMLHttpRequest",
        };
        headers[LIST_FRAGMENT_HEADER] = LIST_FRAGMENT_VALUE;

        return headers;
    };

    /**
     * Same list = same origin + pathname. A different query is still
     * this list; a row / detail URL is not.
     */
    const isSameList = function (url, location) {
        if (!url || !location) {
            return false;
        }

        return url.origin === location.origin && url.pathname === location.pathname;
    };

    /**
     * popstate skips a fetch when the query string has not moved.
     * Both sides are `URL.search` (`?q=…` or `""`).
     */
    const listQueryChanged = function (previousSearch, nextSearch) {
        return String(previousSearch || "") !== String(nextSearch || "");
    };

    /**
     * Patch the region only when the server says this is a fragment.
     * Sign-in, error, or an older deploy → hand the URL to the browser.
     */
    const shouldPatchListRegion = function (ok, regionHeader) {
        return Boolean(ok) && String(regionHeader) === LIST_REGION_VALUE;
    };

    /**
     * Toolbar typing / filters replace the entry so Back leaves the
     * list. Sort, paging, clear and saved-view chips push. popstate
     * and a same-URL refresh keep. A POST success that returns a
     * redirect replaces — the new query is the truth.
     */
    const listHistoryMode = function (kind, options) {
        const opts = options || {};

        if (kind === "toolbar") {
            return "replace";
        }

        if (kind === "requery") {
            return "push";
        }

        if (kind === "refresh" && opts.redirect) {
            return "replace";
        }

        return "keep";
    };

    const listHistoryWrite = function (mode) {
        if (mode === "push") {
            return "pushState";
        }

        if (mode === "replace") {
            return "replaceState";
        }

        return null;
    };

    /**
     * A click is a list requery only when it lives in the region, or
     * is clear-filters / a saved-view chip. Row / detail links navigate.
     */
    const isListRequeryLink = function (flags) {
        const f = flags || {};

        return Boolean(f.inRegion || f.clear || f.view);
    };

    const shouldInterceptListHref = function (url, location, flags) {
        return isListRequeryLink(flags) && isSameList(url, location);
    };

    /**
     * Widget poll URL. Only `failed=1` is the failed-only lane; anything
     * else is the mixed two-hour list (unknown values widen).
     */
    const jobsIndexUrl = function (base, failedOnly) {
        const raw = typeof base === "string" && base !== "" ? base : "/jobs";
        let url;

        try {
            url = new URL(raw, "https://plane.local");
        } catch (error) {
            return raw;
        }

        if (failedOnly) {
            url.searchParams.set("failed", "1");
        } else {
            url.searchParams.delete("failed");
        }

        if (/^[a-z][a-z0-9+.-]*:/i.test(raw)) {
            return url.toString();
        }

        return url.pathname + url.search + url.hash;
    };

    /**
     * Column picker order after moving `key` one step (`delta` -1 / +1).
     * Locked keys never move and nothing may pass them, so the identity column
     * stays first. Returns the input order unchanged when the move is impossible.
     */
    const moveColumnKey = function (keys, key, delta, lockedKeys) {
        const locked = lockedKeys || [];
        const order = keys.slice();
        const from = order.indexOf(key);
        const to = from + (delta < 0 ? -1 : 1);
        if (from === -1 || locked.indexOf(key) !== -1 || to < 0 || to >= order.length) {
            return order;
        }
        if (locked.indexOf(order[to]) !== -1) {
            return order;
        }

        order[from] = order[to];
        order[to] = key;

        return order;
    };

    /**
     * Picker order that matches a saved layout: the visible keys in their saved
     * order first, then every other key in its current relative order.
     */
    const pickerColumnOrder = function (currentKeys, visibleKeys) {
        const order = [];
        visibleKeys.forEach(function (key) {
            if (currentKeys.indexOf(key) !== -1 && order.indexOf(key) === -1) {
                order.push(key);
            }
        });
        currentKeys.forEach(function (key) {
            if (order.indexOf(key) === -1) {
                order.push(key);
            }
        });

        return order;
    };

    return {
        toolbarUrl: toolbarUrl,
        moveColumnKey: moveColumnKey,
        pickerColumnOrder: pickerColumnOrder,
        jobRowKey: jobRowKey,
        diffJobRows: diffJobRows,
        jobStateSignature: jobStateSignature,
        advanceBackoff: advanceBackoff,
        bulkScope: bulkScope,
        interpolateConfirm: interpolateConfirm,
        CONFIRM_MODAL_SELECTOR: CONFIRM_MODAL_SELECTOR,
        confirmOpen: confirmOpen,
        isTyping: isTyping,
        searchEscapeAction: searchEscapeAction,
        scrollEdges: scrollEdges,
        isFailedStatus: isFailedStatus,
        jobsIndexUrl: jobsIndexUrl,
        textMatches: textMatches,
        envRowVisible: envRowVisible,
        pageIsHidden: pageIsHidden,
        shouldSchedulePoll: shouldSchedulePoll,
        LIST_FRAGMENT_HEADER: LIST_FRAGMENT_HEADER,
        LIST_FRAGMENT_VALUE: LIST_FRAGMENT_VALUE,
        LIST_REGION_HEADER: LIST_REGION_HEADER,
        LIST_REGION_VALUE: LIST_REGION_VALUE,
        LIST_HISTORY_STATE: LIST_HISTORY_STATE,
        listFetchHeaders: listFetchHeaders,
        isSameList: isSameList,
        listQueryChanged: listQueryChanged,
        shouldPatchListRegion: shouldPatchListRegion,
        listHistoryMode: listHistoryMode,
        listHistoryWrite: listHistoryWrite,
        isListRequeryLink: isListRequeryLink,
        shouldInterceptListHref: shouldInterceptListHref,
    };
});
