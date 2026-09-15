(function () {
    "use strict";

    const panel = document.querySelector("[data-admins-panel]");
    if (!panel) {
        return;
    }

    const section = panel.closest("[data-site-panel]");
    let state = "idle";

    const setBusy = function (busy) {
        panel.setAttribute("aria-busy", busy ? "true" : "false");
        const loading = panel.querySelector("[data-admins-loading]");
        if (loading) {
            loading.hidden = !busy;
        }
    };

    const showError = function () {
        setBusy(false);
        const error = panel.querySelector("[data-admins-error]");
        if (error) {
            error.hidden = false;
        }
    };

    const syncPasswordMode = function (form) {
        const selected = form.querySelector("[data-admin-password-mode]:checked");
        const mode = selected ? selected.value : "generate";
        const manual = form.querySelector("[data-admin-password-manual]");
        const inviteHint = form.querySelector("[data-admin-password-invite-hint]");
        if (inviteHint) {
            inviteHint.hidden = mode !== "invite";
        }
        if (manual) {
            manual.hidden = mode !== "manual";
            manual.required = mode === "manual";
            if (mode !== "manual") {
                manual.value = "";
            }
        }
    };

    const load = async function () {
        if (state === "loading" || state === "loaded") {
            return;
        }
        state = "loading";
        const error = panel.querySelector("[data-admins-error]");
        if (error) {
            error.hidden = true;
        }
        setBusy(true);

        try {
            const response = await fetch(panel.getAttribute("data-admins-url"), {
                headers: { Accept: "text/html", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });
            if (!response.ok) {
                throw new Error("HTTP " + response.status);
            }
            const html = await response.text();
            const holder = document.createElement("div");
            holder.setAttribute("data-admins-body", "");
            holder.innerHTML = html;
            panel.querySelectorAll("[data-admins-body]").forEach(function (old) {
                old.remove();
            });
            panel.appendChild(holder);
            setBusy(false);
            state = "loaded";
            holder.querySelectorAll("[data-admin-create]").forEach(syncPasswordMode);
            if (window.PlaneUI && typeof window.PlaneUI.refresh === "function") {
                window.PlaneUI.refresh(holder);
            }
        } catch (err) {
            state = "failed";
            showError();
        }
    };

    const loadIfVisible = function () {
        if (section && !section.hidden) {
            load();
        }
    };

    document.addEventListener("change", function (event) {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.hasAttribute("data-admin-password-mode")) {
            const form = target.closest("[data-admin-create]");
            if (form) {
                syncPasswordMode(form);
            }
        }
    });

    panel.addEventListener("click", function (event) {
        if (event.target instanceof Element && event.target.closest("[data-admins-retry]")) {
            state = "idle";
            load();
        }
    });

    document.addEventListener("ops:tab-activated", function (event) {
        if (event.detail && event.detail.id === (section ? section.id : "admins")) {
            load();
        }
    });

    // Deferred scripts run before DOMContentLoaded; the tab code may already have shown #admins.
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            window.setTimeout(loadIfVisible, 0);
        });
    } else {
        window.setTimeout(loadIfVisible, 0);
    }
})();
