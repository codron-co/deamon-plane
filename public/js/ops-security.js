(() => {
    "use strict";

    /**
     * Security event list: the funnel icon opens the filter row, a dot on the icon
     * shows that a filter is set. The list itself updates through ops-list.js
     * (search while typing, filters on change, history.replaceState, no reload).
     */
    const root = document.querySelector("[data-security-list]");
    if (!root) {
        return;
    }

    const form = root.querySelector("[data-ops-list-toolbar]");
    const toggle = root.querySelector("[data-security-filter-toggle]");
    const panel = root.querySelector("[data-security-filter-panel]");
    const dot = root.querySelector("[data-security-filter-dot]");
    if (!form || !toggle || !panel) {
        return;
    }

    const filtersSet = function () {
        return Array.prototype.some.call(panel.querySelectorAll("select"), function (select) {
            const name = select.name;
            const value = select.value;
            // The period always has a value; "24h" is the default and not a filter.
            return value !== "" && !(name === "period" && value === "24h");
        });
    };

    const refreshDot = function () {
        if (dot) {
            dot.hidden = !filtersSet();
        }
    };

    toggle.addEventListener("click", function () {
        const open = panel.hidden;
        panel.hidden = !open;
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
        if (open) {
            const first = panel.querySelector("select");
            if (first) {
                first.focus();
            }
        }
    });

    root.addEventListener("ops:list-updated", refreshDot);
    panel.addEventListener("change", refreshDot);
    refreshDot();
})();
