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

    const contracts = window.PlaneOpsContracts;

    /**
     * The toolbar submits like a normal GET form: empty controls are dropped and a
     * new query always starts on page one.
     */
    const toolbarUrl = function (form) {
        return contracts.toolbarUrl(
            form.getAttribute("action") || form.action || window.location.href,
            window.location.origin,
            new FormData(form)
        );
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
                // A radio set's empty "All" option stands for the absent parameter.
                control.checked = values.length
                    ? values.indexOf(control.value) !== -1
                    : control.type === "radio" && control.value === "";
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
     * Column order lives in the picker's DOM order: the form posts `columns[]`
     * in that order. The move buttons stay hidden until this script runs, so a
     * page without JavaScript simply posts the order it was rendered in.
     */
    const pickerItems = function (list) {
        return Array.prototype.slice.call(list.querySelectorAll(":scope > [data-ops-column-key]"));
    };

    const pickerKeys = function (list) {
        return pickerItems(list).map(function (item) {
            return item.getAttribute("data-ops-column-key");
        });
    };

    const lockedPickerKeys = function (list) {
        return pickerItems(list)
            .filter(function (item) {
                return item.hasAttribute("data-ops-column-locked");
            })
            .map(function (item) {
                return item.getAttribute("data-ops-column-key");
            });
    };

    const refreshPickerButtons = function (list) {
        const keys = pickerKeys(list);
        const locked = lockedPickerKeys(list);
        pickerItems(list).forEach(function (item) {
            const key = item.getAttribute("data-ops-column-key");
            item.querySelectorAll("[data-ops-column-move]").forEach(function (button) {
                const delta = Number(button.getAttribute("data-ops-column-move"));
                const next = contracts.moveColumnKey(keys, key, delta, locked);
                button.disabled = next.join(",") === keys.join(",");
            });
        });
    };

    const applyPickerOrder = function (list, order) {
        const byKey = {};
        pickerItems(list).forEach(function (item) {
            byKey[item.getAttribute("data-ops-column-key")] = item;
        });
        order.forEach(function (key) {
            if (byKey[key]) {
                list.appendChild(byKey[key]);
            }
        });
        refreshPickerButtons(list);
    };

    const syncPickerOrder = function (root, columns) {
        root.querySelectorAll("[data-ops-columns-list]").forEach(function (list) {
            applyPickerOrder(list, contracts.pickerColumnOrder(pickerKeys(list), columns));
        });
    };

    roots.forEach(function (root) {
        root.querySelectorAll("[data-ops-columns-list]").forEach(function (list) {
            list.querySelectorAll("[data-ops-column-move-group]").forEach(function (group) {
                group.hidden = false;
            });
            const picker = list.closest("[data-ops-columns-picker]");
            const hint = picker ? picker.querySelector("[data-ops-columns-order-hint]") : null;
            if (hint) {
                hint.hidden = false;
            }
            refreshPickerButtons(list);
        });
    });

    document.addEventListener("click", function (event) {
        const button = event.target.closest("[data-ops-column-move]");
        if (!button) {
            return;
        }
        const list = button.closest("[data-ops-columns-list]");
        const item = button.closest("[data-ops-column-key]");
        if (!list || !item) {
            return;
        }

        event.preventDefault();
        const key = item.getAttribute("data-ops-column-key");
        const delta = Number(button.getAttribute("data-ops-column-move"));
        const keys = pickerKeys(list);
        const next = contracts.moveColumnKey(keys, key, delta, lockedPickerKeys(list));
        if (next.join(",") === keys.join(",")) {
            return;
        }

        applyPickerOrder(list, next);

        // Keep the keyboard on the row that moved; at an edge the pressed button
        // is now disabled, so hand focus to its sibling.
        const target = button.disabled
            ? item.querySelector("[data-ops-column-move]:not([disabled])")
            : button;
        if (target) {
            target.focus();
        }

        const picker = list.closest("[data-ops-columns-picker]");
        const status = picker ? picker.querySelector("[data-ops-columns-status]") : null;
        const template = list.getAttribute("data-moved-template") || "";
        if (status && template) {
            status.textContent = template
                .replace("__COLUMN__", item.getAttribute("data-ops-column-label") || key)
                .replace("__POSITION__", String(next.indexOf(key) + 1));
        }
    });

    /**
     * Bulk actions that target "everything matching the current filter" carry that
     * filter in hidden inputs outside the region, including the page header.
     */
    const syncLayoutFromRegion = function (root, region, url) {
        const marker = region.querySelector("[data-ops-list-columns]");
        const columns = marker && marker.getAttribute("data-ops-list-columns")
            ? marker.getAttribute("data-ops-list-columns").split(",").filter(Boolean)
            : [];
        const sort = marker ? String(marker.getAttribute("data-ops-list-sort") || "") : "";
        const sortParts = sort.split(":");
        const sortKey = sortParts[0] || "";
        const sortDir = sortParts[1] || "";

        if (columns.length) {
            root.querySelectorAll("[data-ops-columns-picker] input[name='columns[]']").forEach(function (input) {
                if (input.type === "checkbox" && !input.disabled) {
                    input.checked = columns.indexOf(input.value) !== -1;
                }
            });
            syncPickerOrder(root, columns);
        }

        const form = root.querySelector("[data-ops-saved-view-form]");
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const params = url.searchParams;
        ["q", "channel", "status", "publish", "deploy", "agent", "pack", "health", "app", "theme"].forEach(function (name) {
            const input = form.querySelector("input[name='" + name + "']");
            if (input) {
                input.value = params.get(name) || "";
            }
        });

        const sortKeyInput = form.querySelector("input[name='sort_key']");
        const sortDirInput = form.querySelector("input[name='sort_dir']");
        if (sortKeyInput && sortKey) {
            sortKeyInput.value = sortKey;
        }
        if (sortDirInput && sortDir) {
            sortDirInput.value = sortDir;
        }

        if (columns.length) {
            form.querySelectorAll("input[name='columns[]']").forEach(function (input) {
                input.remove();
            });
            columns.forEach(function (key) {
                const input = document.createElement("input");
                input.type = "hidden";
                input.name = "columns[]";
                input.value = key;
                form.appendChild(input);
            });
        }
    };

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
                headers: contracts.listFetchHeaders(),
                credentials: "same-origin",
                signal: controller.signal,
            });

            // A sign-in redirect, an error page or an older deployment answers with
            // something that is not a region; hand the URL to the browser.
            if (!contracts.shouldPatchListRegion(
                response.ok,
                response.headers.get(contracts.LIST_REGION_HEADER)
            )) {
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
            syncLayoutFromRegion(root, region, url);
            root.dispatchEvent(new CustomEvent("ops:list-updated", { detail: { url: url.href } }));

            if (window.PlaneUI && typeof window.PlaneUI.refresh === "function") {
                window.PlaneUI.refresh(region);
            }

            const write = contracts.listHistoryWrite(settings.history);
            if (write) {
                window.history[write](contracts.LIST_HISTORY_STATE, "", url.href);
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
        update(root, toolbarUrl(form), { history: contracts.listHistoryMode("toolbar") });
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
        const url = new URL(link.href, window.location.origin);
        if (!contracts.shouldInterceptListHref(url, window.location, {
            inRegion: link.closest("[data-ops-list-region]") !== null,
            clear: link.hasAttribute("data-ops-list-clear"),
            view: link.hasAttribute("data-ops-list-view"),
        })) {
            return;
        }

        event.preventDefault();
        update(root, url, {
            history: contracts.listHistoryMode("requery"),
            focus: link.getAttribute("data-ops-list-focus"),
        });
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
            syncPickerOrder(root, payload.columns);
        }

        const menu = form.closest("details[open]");
        if (menu) {
            menu.removeAttribute("open");
            const summary = menu.querySelector("summary");
            if (summary) {
                summary.focus();
            }
        }

        const next = payload.redirect
            ? new URL(payload.redirect, window.location.origin)
            : new URL(window.location.href);
        if (!contracts.isSameList(next, window.location)) {
            return;
        }

        update(root, next, {
            history: contracts.listHistoryMode("refresh", { redirect: Boolean(payload.redirect) }),
        });
    });

    window.addEventListener("popstate", function () {
        const url = new URL(window.location.href);

        roots.forEach(function (root) {
            if (!regionOf(root) || !contracts.listQueryChanged(root.dataset.opsListQuery, url.search)) {
                return;
            }
            update(root, url, { history: contracts.listHistoryMode("pop") });
        });
    });
})();
