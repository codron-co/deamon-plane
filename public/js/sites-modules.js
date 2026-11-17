(function () {
    "use strict";

    // Site detail → Modüller. The list is injected by ops-lazy-panels.js, so every
    // handler is delegated from document. A toggle posts JSON and updates the row
    // in place; turning a module off (or installing one) asks first via PlaneConfirm.

    const csrfToken = function () {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute("content") || "" : "";
    };

    const setStatus = function (root, text) {
        const status = root.querySelector("[data-modules-status]");
        if (!status) {
            return;
        }
        status.textContent = text || "";
        status.hidden = !text;
    };

    const setRowError = function (row, text) {
        const error = row ? row.querySelector("[data-module-error]") : null;
        if (!error) {
            return;
        }
        error.textContent = text || "";
        error.hidden = !text;
    };

    const markEnabled = function (root, slug, enabled) {
        root.querySelectorAll("[data-module-toggle]").forEach(function (toggle) {
            if (toggle.getAttribute("data-module-slug") !== slug) {
                return;
            }
            toggle.setAttribute("aria-checked", enabled ? "true" : "false");
            if (enabled) {
                toggle.setAttribute("data-module-installed", "true");
                // A core (locked) module cannot be turned off once it is on.
                if (toggle.getAttribute("data-module-locked") === "true") {
                    toggle.disabled = true;
                }
                const row = toggle.closest("[data-module-row]");
                const chip = row ? row.querySelector("[data-module-not-installed]") : null;
                if (chip) {
                    chip.remove();
                }
            }
        });
    };

    const confirmChange = async function (toggle, enable) {
        const installed = toggle.getAttribute("data-module-installed") === "true";
        if (enable && installed) {
            return true;
        }
        if (!window.PlaneConfirm || typeof window.PlaneConfirm.ask !== "function") {
            return true;
        }
        const kind = enable ? "enable" : "disable";
        return window.PlaneConfirm.ask({
            title: toggle.getAttribute("data-confirm-" + kind + "-title") || "",
            message: toggle.getAttribute("data-confirm-" + kind + "-message") || "",
            confirmLabel: toggle.getAttribute("data-confirm-" + kind + "-label") || "",
            danger: !enable,
        });
    };

    document.addEventListener("click", async function (event) {
        const toggle = event.target instanceof Element ? event.target.closest("[data-module-toggle]") : null;
        if (!toggle || toggle.disabled || toggle.getAttribute("aria-busy") === "true") {
            return;
        }
        const root = toggle.closest("[data-site-modules]");
        if (!root) {
            return;
        }
        event.preventDefault();

        const row = toggle.closest("[data-module-row]");
        const slug = toggle.getAttribute("data-module-slug") || "";
        const enable = toggle.getAttribute("aria-checked") !== "true";
        setRowError(row, "");

        if (!(await confirmChange(toggle, enable))) {
            toggle.focus();
            return;
        }

        toggle.setAttribute("aria-busy", "true");
        toggle.disabled = true;
        if (row) {
            row.classList.add("is-busy");
        }
        setStatus(root, root.getAttribute("data-working-label") || "");

        let data = null;
        let ok = false;
        try {
            const response = await fetch(root.getAttribute("data-modules-url"), {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "X-CSRF-TOKEN": csrfToken(),
                },
                body: JSON.stringify({ module: slug, enabled: enable }),
            });
            data = await response.json().catch(function () {
                return null;
            });
            ok = response.ok && data !== null && data.ok === true;
        } catch (err) {
            ok = false;
        }

        toggle.removeAttribute("aria-busy");
        toggle.disabled = false;
        if (row) {
            row.classList.remove("is-busy");
        }

        if (!ok) {
            const message = data && typeof data.message === "string" && data.message !== ""
                ? data.message
                : root.getAttribute("data-error-label") || "";
            setRowError(row, message);
            setStatus(root, "");
            toggle.focus();
            return;
        }

        const nowEnabled = data.enabled === true;
        markEnabled(root, slug, nowEnabled);
        if (nowEnabled && Array.isArray(data.changed)) {
            data.changed.forEach(function (changedSlug) {
                markEnabled(root, String(changedSlug), true);
            });
        }
        setStatus(root, typeof data.message === "string" ? data.message : "");
        toggle.focus();
    });
})();
