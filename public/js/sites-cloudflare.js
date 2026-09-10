(function () {
    document.addEventListener("ops:ajax-success", function (event) {
        const detail = event.detail || {};
        const form = detail.form;
        const payload = detail.payload;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute("data-cloudflare-zone") || !payload || payload.ok === false) {
            return;
        }

        const panel = form.closest("[data-cloudflare-panel]");
        if (!panel) {
            return;
        }

        applyZoneResult(panel, form, payload);
    });

    function applyZoneResult(panel, form, payload) {
        const nameservers = Array.isArray(payload.nameservers)
            ? payload.nameservers.filter(function (value) {
                return typeof value === "string" && value.trim() !== "";
            })
            : [];

        renderNameservers(panel, nameservers);
        setFact(panel, "[data-cloudflare-zone-id]", payload.zone_id, true);
        setFact(
            panel,
            "[data-cloudflare-zone-status]",
            payload.zone_status || panel.getAttribute("data-pending-status") || "",
            false,
        );
        setFact(panel, "[data-cloudflare-dns]", new Date().toISOString().replace("T", " ").slice(0, 19), false);

        const submit = form.querySelector("[data-cloudflare-submit]");
        const refreshLabel = panel.getAttribute("data-refresh-label");
        if (submit && refreshLabel) {
            submit.textContent = refreshLabel;
            form.setAttribute("data-confirm-label", refreshLabel);
        }

        let chip = panel.querySelector("[data-cloudflare-status-chip]");
        if (!chip) {
            const head = panel.querySelector(".site-card-head");
            chip = document.createElement("span");
            chip.className = "status-chip";
            chip.setAttribute("data-cloudflare-status-chip", "");
            if (head) {
                head.appendChild(chip);
            }
        }
        if (chip) {
            chip.textContent = payload.zone_status || panel.getAttribute("data-pending-status") || "";
        }
    }

    function renderNameservers(panel, nameservers) {
        const list = panel.querySelector("[data-cloudflare-ns-list]");
        const all = panel.querySelector("#site-ns-all");
        const copyLabel = panel.getAttribute("data-copy-label") || "Copy";
        const copiedLabel = panel.getAttribute("data-copied-label") || "Copied";
        const noneLabel = panel.getAttribute("data-none-label") || "—";

        if (all) {
            all.textContent = nameservers.join("\n");
        }

        if (!list) {
            return;
        }

        list.replaceChildren();

        if (nameservers.length === 0) {
            const empty = document.createElement("li");
            empty.className = "muted";
            empty.setAttribute("data-cloudflare-ns-empty", "");
            empty.textContent = noneLabel;
            list.appendChild(empty);
            toggleCopyAll(panel, false);
            return;
        }

        nameservers.forEach(function (ns, index) {
            const item = document.createElement("li");
            item.className = "ops-ns-row";

            const code = document.createElement("code");
            code.id = "site-ns-" + index;
            code.textContent = ns;

            const button = document.createElement("button");
            button.type = "button";
            button.className = "btn btn-ghost btn-sm";
            button.setAttribute("data-copy-target", "#site-ns-" + index);
            button.setAttribute("data-copied-label", copiedLabel);
            button.textContent = copyLabel;

            item.appendChild(code);
            item.appendChild(button);
            list.appendChild(item);
        });

        toggleCopyAll(panel, true);
    }

    function toggleCopyAll(panel, visible) {
        let button = panel.querySelector("[data-cloudflare-copy-all]");
        const head = panel.querySelector(".ops-ns-head");
        if (visible && !button && head) {
            button = document.createElement("button");
            button.type = "button";
            button.className = "btn btn-ghost btn-sm";
            button.setAttribute("data-copy-target", "#site-ns-all");
            button.setAttribute("data-copied-label", panel.getAttribute("data-copied-label") || "Copied");
            button.setAttribute("data-cloudflare-copy-all", "");
            button.textContent = head.getAttribute("data-copy-all-label") || "Copy all";
            head.appendChild(button);
        }
        if (button) {
            button.hidden = !visible;
        }
    }

    function setFact(panel, selector, value, asCode) {
        const target = panel.querySelector(selector);
        if (!target) {
            return;
        }

        const text = typeof value === "string" ? value.trim() : "";
        target.replaceChildren();
        if (text === "") {
            target.textContent = panel.getAttribute("data-none-label") || "—";
            return;
        }

        if (asCode) {
            const code = document.createElement("code");
            code.textContent = text;
            target.appendChild(code);
            return;
        }

        target.textContent = text;
    }
})();
