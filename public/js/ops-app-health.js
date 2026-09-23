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

    const toneClass = function (health) {
        return "status-chip status-" + (health.tone === "ok" ? "ok" : (health.tone === "error" ? "error" : "unknown"));
    };

    const applyListChip = function (health) {
        if (!health || !health.site_id) {
            return;
        }
        const row = document.querySelector('tr[data-site-id="' + health.site_id + '"]');
        if (!row) {
            return;
        }

        const pop = row.querySelector("[data-app-health-pop]");
        if (pop) {
            const trigger = pop.querySelector("[data-app-health-trigger]");
            if (trigger) {
                trigger.textContent = health.label || "";
                trigger.className = toneClass(health) + " app-health-pop-trigger";
            }
            const copy = pop.querySelector("[data-app-health-copy]");
            if (copy) {
                copy.setAttribute("data-copy-text", health.copy_text || "");
            }
            const list = pop.querySelector("[data-app-health-pop-list]");
            if (list) {
                const issues = Array.isArray(health.issues) ? health.issues : [];
                const lines = issues.length ? issues.map(function (issue) {
                    return issue.message || "";
                }) : [health.copy_text || ""];
                list.replaceChildren();
                lines.forEach(function (line) {
                    const li = document.createElement("li");
                    li.textContent = line;
                    list.appendChild(li);
                });
            }
            return;
        }

        const chip = row.querySelector("[data-app-health-copy]");
        if (!chip) {
            return;
        }
        chip.textContent = health.label || "";
        chip.className = toneClass(health);
        chip.setAttribute("data-copy-text", health.copy_text || "");
        chip.setAttribute("title", health.copy_text || "");
    };

    /*
     * Sites list: the "N issues" chip opens a small popover listing the issues.
     * Hover (mouse) and keyboard focus preview it; a click pins it. Escape,
     * an outside click or focus leaving closes it. The popover is fixed-positioned
     * so the horizontally scrolling table wrapper never clips it.
     */
    let openPop = null;
    let pinned = false;
    let hoverTimer = 0;

    const popParts = function (pop) {
        return {
            trigger: pop.querySelector("[data-app-health-trigger]"),
            panel: pop.querySelector("[data-app-health-popover]"),
        };
    };

    const placePop = function (pop) {
        const parts = popParts(pop);
        if (!parts.trigger || !parts.panel || parts.panel.hidden) {
            return;
        }
        const rect = parts.trigger.getBoundingClientRect();
        const panel = parts.panel;
        const gap = 6;
        const margin = 8;
        const width = panel.offsetWidth;
        const height = panel.offsetHeight;
        let left = rect.left;
        if (left + width > window.innerWidth - margin) {
            left = Math.max(margin, window.innerWidth - margin - width);
        }
        let top = rect.bottom + gap;
        if (top + height > window.innerHeight - margin && rect.top - gap - height >= margin) {
            top = rect.top - gap - height;
        }
        panel.style.left = Math.round(left) + "px";
        panel.style.top = Math.round(top) + "px";
    };

    const closePop = function (returnFocus) {
        window.clearTimeout(hoverTimer);
        if (!openPop) {
            return;
        }
        const pop = openPop;
        const parts = popParts(pop);
        openPop = null;
        pinned = false;
        if (parts.panel) {
            parts.panel.hidden = true;
        }
        pop.classList.remove("is-open");
        if (parts.trigger) {
            parts.trigger.setAttribute("aria-expanded", "false");
            if (returnFocus) {
                parts.trigger.focus();
            }
        }
    };

    const showPop = function (pop, pin) {
        window.clearTimeout(hoverTimer);
        if (openPop && openPop !== pop) {
            closePop(false);
        }
        const parts = popParts(pop);
        if (!parts.trigger || !parts.panel) {
            return;
        }
        openPop = pop;
        pinned = pinned || Boolean(pin);
        parts.panel.hidden = false;
        pop.classList.add("is-open");
        parts.trigger.setAttribute("aria-expanded", "true");
        placePop(pop);
    };

    document.addEventListener("click", function (event) {
        const trigger = event.target.closest("[data-app-health-trigger]");
        if (trigger) {
            event.preventDefault();
            const pop = trigger.closest("[data-app-health-pop]");
            if (openPop === pop && pinned) {
                closePop(false);
            } else if (pop) {
                showPop(pop, true);
            }
            return;
        }
        if (openPop && !openPop.contains(event.target)) {
            closePop(false);
        }
    });

    document.addEventListener("pointerover", function (event) {
        if (event.pointerType !== "mouse") {
            return;
        }
        const pop = event.target.closest("[data-app-health-pop]");
        if (pop) {
            if (openPop === pop) {
                window.clearTimeout(hoverTimer);
            } else if (!pinned) {
                showPop(pop, false);
            }
        }
    });

    document.addEventListener("pointerout", function (event) {
        if (event.pointerType !== "mouse" || !openPop || pinned) {
            return;
        }
        const pop = event.target.closest("[data-app-health-pop]");
        if (pop !== openPop || (event.relatedTarget && pop.contains(event.relatedTarget))) {
            return;
        }
        window.clearTimeout(hoverTimer);
        hoverTimer = window.setTimeout(function () {
            if (!pinned) {
                closePop(false);
            }
        }, 160);
    });

    document.addEventListener("focusin", function (event) {
        const trigger = event.target.closest && event.target.closest("[data-app-health-trigger]");
        if (trigger) {
            let keyboard = true;
            try {
                keyboard = trigger.matches(":focus-visible");
            } catch (error) {
                keyboard = true;
            }
            if (keyboard) {
                showPop(trigger.closest("[data-app-health-pop]"), false);
            }
            return;
        }
        if (openPop && !openPop.contains(event.target)) {
            closePop(false);
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape" || !openPop) {
            return;
        }
        event.preventDefault();
        closePop(true);
    });

    window.addEventListener("resize", function () {
        if (openPop) {
            placePop(openPop);
        }
    });

    document.addEventListener("scroll", function () {
        if (openPop) {
            placePop(openPop);
        }
    }, true);

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
