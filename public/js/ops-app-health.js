(() => {
    "use strict";

    const copiedLabel = function (root) {
        return (root && root.getAttribute("data-copy-copied")) || "Copied";
    };

    const copyText = async function (text) {
        const value = String(text || "").trim();
        if (!value) {
            return false;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(value);
            return true;
        }

        const area = document.createElement("textarea");
        area.value = value;
        area.setAttribute("readonly", "readonly");
        area.style.position = "fixed";
        area.style.left = "-9999px";
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand("copy");
        area.remove();
        return ok;
    };

    const flashCopied = function (button) {
        if (!(button instanceof HTMLElement)) {
            return;
        }
        const previous = button.textContent;
        button.textContent = copiedLabel(document.querySelector("[data-ops-jobs]"));
        window.setTimeout(function () {
            if (button.dataset.appHealthCopy !== undefined && button.matches(".status-chip")) {
                return;
            }
            button.textContent = previous;
        }, 1200);
    };

    document.addEventListener("click", function (event) {
        const button = event.target.closest("[data-app-health-copy]");
        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        copyText(button.getAttribute("data-copy-text") || button.getAttribute("title") || "").then(function (ok) {
            if (!ok) {
                return;
            }
            if (window.PlaneJobs && typeof window.PlaneJobs.showMessage === "function") {
                window.PlaneJobs.showMessage(copiedLabel(document.querySelector("[data-ops-jobs]")), "status");
            }
            if (!button.classList.contains("status-chip")) {
                flashCopied(button);
            }
        });
    });

    const renderIssues = function (root, health) {
        const list = root.querySelector("[data-app-health-issues]");
        const empty = root.querySelector("[data-app-health-empty]");
        const label = root.querySelector("[data-app-health-label]");
        const copyBtn = root.querySelector("[data-app-health-copy]");
        if (label) {
            label.textContent = health.label || "";
            label.className = "status-chip status-" + (health.tone === "ok" ? "ok" : (health.tone === "error" ? "error" : "unknown"));
        }
        if (copyBtn) {
            copyBtn.setAttribute("data-copy-text", health.copy_text || "");
        }
        if (!list) {
            return;
        }

        const issues = Array.isArray(health.issues) ? health.issues : [];
        list.hidden = issues.length === 0;
        if (empty) {
            empty.hidden = issues.length > 0;
            if (issues.length === 0) {
                empty.textContent = health.copy_text || "";
            }
        }

        const canFix = root.querySelector("[data-app-health-fix]") !== null || root.getAttribute("data-can-fix") === "1";
        const action = root.getAttribute("data-fix-action");
        const token = document.querySelector('meta[name="csrf-token"]');
        const csrf = token ? token.getAttribute("content") : "";

        list.replaceChildren();
        issues.forEach(function (issue) {
            const li = document.createElement("li");
            const text = document.createElement("span");
            text.textContent = issue.message || "";
            li.appendChild(text);
            if (canFix && issue.fix && action) {
                const form = document.createElement("form");
                form.method = "POST";
                form.action = action;
                form.setAttribute("data-ops-pending", "");
                form.setAttribute("data-app-health-fix", "");
                const csrfInput = document.createElement("input");
                csrfInput.type = "hidden";
                csrfInput.name = "_token";
                csrfInput.value = csrf || "";
                const fixInput = document.createElement("input");
                fixInput.type = "hidden";
                fixInput.name = "fix";
                fixInput.value = issue.fix;
                const button = document.createElement("button");
                button.type = "submit";
                button.className = "btn btn-secondary btn-sm";
                button.textContent = issue.fix_label || issue.fix;
                form.appendChild(csrfInput);
                form.appendChild(fixInput);
                form.appendChild(button);
                li.appendChild(form);
            }
            list.appendChild(li);
        });
    };

    const applyListChip = function (health) {
        if (!health || !health.site_id) {
            return;
        }
        const chip = document.querySelector('tr[data-site-id="' + health.site_id + '"] [data-app-health-copy]');
        if (!chip) {
            return;
        }
        chip.textContent = health.label || "";
        chip.className = "status-chip status-" + (health.tone === "ok" ? "ok" : (health.tone === "error" ? "error" : "unknown"));
        chip.setAttribute("data-copy-text", health.copy_text || "");
        chip.setAttribute("title", health.copy_text || "");
    };

    document.addEventListener("ops:ajax-success", function (event) {
        const payload = event.detail && event.detail.payload ? event.detail.payload : {};
        if (!payload.health) {
            return;
        }
        const form = event.detail.form;
        const root = form ? form.closest("[data-app-health-root]") : document.querySelector("[data-app-health-root]");
        if (root) {
            renderIssues(root, payload.health);
        }
        applyListChip(payload.health);
    });
})();
