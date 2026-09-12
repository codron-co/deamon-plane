(() => {
    "use strict";

    /**
     * Ops list pages update in place: search, filters, sort, pagination and the
     * saved column layout re-render only `[data-ops-list-region]`.
     *
     * Everything stays a plain link or GET form, so without JavaScript — or when
     * the server answers with anything other than a region — the browser simply
     * navigates and the page still works.
     */
    const FRAGMENT_HEADER = "X-Ops-List-Fragment";
    const FRAGMENT_VALUE = "region";
    const REGION_HEADER = "X-Ops-List-Region";

    const roots = Array.prototype.slice.call(document.querySelectorAll("[data-ops-list]"));
    if (!roots.length) {
        return;
    }

    const inFlight = new WeakMap();

    const regionOf = function (root) {
        return root.querySelector("[data-ops-list-region]");
    };

    const toolbarOf = function (root) {
        return root.querySelector("[data-ops-list-toolbar]");
    };

    const isSameList = function (url) {
        return url.origin === window.location.origin && url.pathname === window.location.pathname;
    };

    /**
     * The toolbar submits like a normal GET form: empty controls are dropped and a
     * new query always starts on page one.
     */
    const toolbarUrl = function (form) {
        const url = new URL(form.getAttribute("action") || form.action || window.location.href, window.location.origin);
        const params = new URLSearchParams();

        new FormData(form).forEach(function (value, key) {
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

    const syncToolbar = function (root, url) {
        const form = toolbarOf(root);
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const params = url.searchParams;
        let filtered = false;

        form.dataset.opsListSyncing = "1";
        Array.prototype.slice.call(form.elements).forEach(function (control) {
            const name = control.name || "";
            if (name === "" || name === "page") {
                return;
            }

            const values = params.getAll(name);
            if (values.length) {
                filtered = true;
            }

            // Never overwrite what the operator is typing or has open right now.
            if (control === document.activeElement) {
                return;
            }

            if (control.type === "checkbox" || control.type === "radio") {
                control.checked = values.indexOf(control.value) !== -1;
                return;
            }

            const next = values.length ? values[0] : "";
            if (control.value !== next) {
                control.value = next;
                // Let the enhanced select redraw its trigger label.
                control.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });
        delete form.dataset.opsListSyncing;

        root.querySelectorAll("[data-ops-list-clear]").forEach(function (link) {
            link.hidden = !filtered;
        });
    };

    /**
     * Bulk actions that target "everything matching the current filter" carry that
     * filter in hidden inputs outside the region, including the page header.
     */
    const syncFilterInputs = function (url) {
        document.querySelectorAll("input[name^='filter_']").forEach(function (input) {
            const key = input.name.slice("filter_".length);
            input.value = url.searchParams.get(key) || "";
        });
    };

    const restoreFocus = function (region, key) {
        if (!key) {
            return;
        }

        const candidates = Array.prototype.slice.call(region.querySelectorAll("[data-ops-list-focus]"));
        const exact = candidates.find(function (node) {
            return node.getAttribute("data-ops-list-focus") === key;
        });

        if (exact) {
            exact.focus();
            return;
        }

        // Paging to the last page removes the control that was just used; keep the
        // operator inside the pager instead of dropping focus to the document.
        if (key.indexOf("page:") === 0) {
            const pager = candidates.find(function (node) {
                return (node.getAttribute("data-ops-list-focus") || "").indexOf("page:") === 0;
            });
            if (pager) {
                pager.focus();
            }
        }
    };

    const update = async function (root, url, options) {
        const region = regionOf(root);
        if (!region) {
            window.location.assign(url.href);
            return;
        }

        const settings = options || {};
        const previous = inFlight.get(root);
        if (previous) {
            previous.abort();
        }

        const controller = new AbortController();
        inFlight.set(root, controller);
        region.setAttribute("aria-busy", "true");
        region.classList.add("is-loading");

        try {
            const response = await fetch(url.href, {
                headers: {
                    Accept: "text/html",
                    "X-Requested-With": "XMLHttpRequest",
                    [FRAGMENT_HEADER]: FRAGMENT_VALUE,
                },
                credentials: "same-origin",
                signal: controller.signal,
            });

            // A sign-in redirect, an error page or an older deployment answers with
            // something that is not a region; hand the URL to the browser.
            if (!response.ok || response.headers.get(REGION_HEADER) !== "1") {
                window.location.assign(url.href);
                return;
            }

            const html = await response.text();
            if (inFlight.get(root) !== controller) {
                return;
            }

            region.innerHTML = html;
            syncToolbar(root, url);
            syncFilterInputs(url);

            if (window.PlaneUI && typeof window.PlaneUI.refresh === "function") {
                window.PlaneUI.refresh(region);
            }

            if (settings.history === "push") {
                window.history.pushState({ opsList: true }, "", url.href);
            } else if (settings.history === "replace") {
                window.history.replaceState({ opsList: true }, "", url.href);
            }

            root.dataset.opsListQuery = url.search;
            restoreFocus(region, settings.focus);
        } catch (error) {
            if (error && error.name === "AbortError") {
                return;
            }
            window.location.assign(url.href);
        } finally {
            if (inFlight.get(root) === controller) {
                inFlight.delete(root);
                region.removeAttribute("aria-busy");
                region.classList.remove("is-loading");
            }
        }
    };

    roots.forEach(function (root) {
        root.dataset.opsListQuery = window.location.search;
    });

    document.addEventListener("submit", function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches("[data-ops-list-toolbar]")) {
            return;
        }

        const root = form.closest("[data-ops-list]");
        if (!root || !regionOf(root)) {
            return;
        }

        event.preventDefault();
        if (form.dataset.opsListSyncing === "1") {
            return;
        }

        // Typing and flipping filters replaces the entry: back should leave the
        // list, not walk through every keystroke.
        update(root, toolbarUrl(form), { history: "replace" });
    });

    document.addEventListener("click", function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest("a[href]");
        if (!link || link.hasAttribute("download") || (link.getAttribute("target") || "") !== "") {
            return;
        }

        const root = link.closest("[data-ops-list]");
        if (!root || !regionOf(root)) {
            return;
        }

        // Sort headers, pagination and "clear filters": links that re-query this
        // very list. Anything pointing elsewhere — a row, a detail page — navigates.
        const requeries = link.closest("[data-ops-list-region]") !== null || link.hasAttribute("data-ops-list-clear");
        if (!requeries) {
            return;
        }

        const url = new URL(link.href, window.location.origin);
        if (!isSameList(url)) {
            return;
        }

        event.preventDefault();
        update(root, url, { history: "push", focus: link.getAttribute("data-ops-list-focus") });
    });

    /**
     * Saving or resetting the column layout only shows up once the table is
     * re-rendered, so the picker asks for a fresh region after the POST lands.
     */
    document.addEventListener("ops:ajax-success", function (event) {
        const detail = event.detail || {};
        const form = detail.form;
        const payload = detail.payload || {};
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (!form.matches("[data-ops-list-refresh]") && payload.refresh_list !== true) {
            return;
        }

        const root = form.closest("[data-ops-list]");
        if (!root || !regionOf(root)) {
            return;
        }

        if (Array.isArray(payload.columns)) {
            root.querySelectorAll("[data-ops-columns-picker] input[name='columns[]']").forEach(function (input) {
                if (input.type === "checkbox" && !input.disabled) {
                    input.checked = payload.columns.indexOf(input.value) !== -1;
                }
            });
        }

        const menu = form.closest("details[open]");
        if (menu) {
            menu.removeAttribute("open");
            const summary = menu.querySelector("summary");
            if (summary) {
                summary.focus();
            }
        }

        update(root, new URL(window.location.href), { history: "keep" });
    });

    window.addEventListener("popstate", function () {
        const url = new URL(window.location.href);

        roots.forEach(function (root) {
            if (!regionOf(root) || root.dataset.opsListQuery === url.search) {
                return;
            }
            update(root, url, { history: "keep" });
        });
    });
})();
