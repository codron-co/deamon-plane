(function () {
    "use strict";

    const THEME_KEY = "plane-theme";
    const THEMES = ["light", "semidark", "dark"];
    let openSelect = null;

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute("content") : "";
    }

    function parseOptions(button) {
        try {
            const options = JSON.parse(button.getAttribute("data-options") || "[]");
            return Array.isArray(options) ? options : [];
        } catch (error) {
            return [];
        }
    }

    function optionIndex(options, value) {
        const index = options.findIndex(function (item) {
            return item && item.value === value;
        });
        return index >= 0 ? index : 0;
    }

    function setTheme(theme) {
        const value = THEMES.indexOf(theme) !== -1 ? theme : "dark";
        document.documentElement.dataset.theme = value;
        try {
            window.localStorage.setItem(THEME_KEY, value);
            document.cookie = THEME_KEY + "=" + encodeURIComponent(value) + ";path=/;max-age=" + String(60 * 60 * 24 * 400) + ";samesite=lax";
        } catch (error) {
            /* localStorage can be unavailable in privacy-restricted contexts */
        }
    }

    function syncCycleButton(button, nextInput, current) {
        const options = parseOptions(button);
        if (!options.length) {
            return;
        }

        const index = optionIndex(options, current);
        const selected = options[index];
        const following = options[(index + 1) % options.length];

        button.setAttribute("data-current", current);
        const label = button.querySelector("[data-pref-label]");
        if (label && selected) {
            label.textContent = selected.label;
        }

        button.querySelectorAll("[data-icon]").forEach(function (icon) {
            icon.hidden = icon.getAttribute("data-icon") !== current;
        });

        if (nextInput && following) {
            nextInput.value = following.value;
        }

        if (selected) {
            const template = button.getAttribute("data-aria-template");
            button.setAttribute("aria-label", template ? template.replace(":mode", selected.label).replace(":locale", selected.label) : selected.label);
        }
    }

    function setupThemeControls() {
        setTheme(document.documentElement.dataset.theme || "dark");
    }

    function positionUserPopover(menu) {
        const trigger = menu.querySelector(".ops-user-trigger");
        const popover = menu.querySelector(".ops-user-popover");
        if (!trigger || !popover || !menu.open) {
            return;
        }

        const rect = trigger.getBoundingClientRect();
        if (window.innerWidth <= 768) {
            popover.style.left = "12px";
            popover.style.right = "12px";
            popover.style.width = "auto";
            popover.style.top = "auto";
            popover.style.bottom = "12px";
            return;
        }

        const width = Math.min(320, window.innerWidth - 16);
        let left = rect.right + 8;
        if (left + width > window.innerWidth - 8) {
            left = Math.max(8, rect.left);
        }

        popover.style.width = width + "px";
        popover.style.left = left + "px";
        popover.style.right = "auto";
        popover.style.top = "auto";
        popover.style.bottom = Math.max(8, window.innerHeight - rect.bottom) + "px";
    }

    function setupUserMenu() {
        const menu = document.querySelector("[data-user-menu]");
        if (!menu) {
            return;
        }

        function closeMenu() {
            menu.removeAttribute("open");
        }

        menu.addEventListener("toggle", function () {
            if (menu.open) {
                positionUserPopover(menu);
            }
        });

        window.addEventListener("resize", function () {
            positionUserPopover(menu);
        });

        document.addEventListener("click", function (event) {
            if (menu.open && !menu.contains(event.target)) {
                closeMenu();
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key !== "Escape" || !menu.open) {
                return;
            }
            closeMenu();
            const summary = menu.querySelector("summary");
            if (summary) {
                summary.focus();
            }
        });
    }

    function setupPrefForms() {
        document.querySelectorAll("[data-pref-form]").forEach(function (form) {
            const kind = form.getAttribute("data-pref-form");
            const button = form.querySelector("[data-pref]");
            const nextInput = form.querySelector("[data-pref-next]");
            if (!button || !nextInput) {
                return;
            }

            form.addEventListener("submit", function (event) {
                event.preventDefault();
                if (button.disabled) {
                    return;
                }

                const field = kind === "locale" ? "locale" : "appearance";
                const value = nextInput.value;
                if (!value) {
                    return;
                }

                button.disabled = true;
                if (field === "appearance") {
                    setTheme(value);
                    syncCycleButton(button, null, value);
                }

                fetch(form.action, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-CSRF-TOKEN": csrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                    body: JSON.stringify(Object.fromEntries([[field, value]])),
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error("preference-failed");
                    }
                    return response.json();
                }).then(function (payload) {
                    if (field === "appearance") {
                        const applied = payload.appearance || value;
                        setTheme(applied);
                        syncCycleButton(button, nextInput, applied);
                        return;
                    }

                    window.location.reload();
                }).catch(function () {
                    form.submit();
                }).finally(function () {
                    button.disabled = false;
                });
            });
        });
    }

    function isInteractiveTarget(target) {
        return Boolean(target.closest("a, button, input, select, textarea, summary, details, [role='button'], [data-row-action]"));
    }

    function setupClickableRows() {
        document.addEventListener("click", function (event) {
            const row = event.target.closest("tr[data-href]");
            if (!row || isInteractiveTarget(event.target)) {
                return;
            }
            const href = row.getAttribute("data-href");
            if (href) {
                window.location.assign(href);
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key !== "Enter") {
                return;
            }
            const row = event.target.closest("tr[data-href]");
            if (!row || event.target !== row) {
                return;
            }
            const href = row.getAttribute("data-href");
            if (href) {
                window.location.assign(href);
            }
        });
    }

    function uniqueId(prefix) {
        return prefix + "-" + Math.random().toString(36).slice(2, 10);
    }

    function optionLabel(option) {
        return (option.textContent || "").trim();
    }

    function enhanceSelect(select) {
        if (!select || select.dataset.opsSelectEnhanced === "true" || select.multiple || select.size > 1 || select.hasAttribute("data-native-select")) {
            return;
        }

        select.dataset.opsSelectEnhanced = "true";

        const wrapper = document.createElement("div");
        wrapper.className = "ops-select";
        if (select.classList.contains("ops-filter")) {
            wrapper.classList.add("ops-select-filter");
        }

        const trigger = document.createElement("button");
        trigger.type = "button";
        trigger.className = "ops-select-trigger";
        trigger.setAttribute("aria-haspopup", "listbox");
        trigger.setAttribute("aria-expanded", "false");
        if (select.classList.contains("ops-filter")) {
            trigger.setAttribute("data-size", "sm");
        }

        const value = document.createElement("span");
        value.className = "ops-select-value";
        trigger.appendChild(value);

        const chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        chevron.setAttribute("viewBox", "0 0 16 16");
        chevron.setAttribute("aria-hidden", "true");
        chevron.classList.add("ops-select-chevron");
        chevron.innerHTML = '<path d="m4 6 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
        trigger.appendChild(chevron);

        const menu = document.createElement("div");
        menu.className = "ops-select-menu";
        menu.setAttribute("role", "listbox");
        menu.hidden = true;

        const menuId = uniqueId("ops-select-list");
        menu.id = menuId;
        trigger.setAttribute("aria-controls", menuId);

        const selectId = select.id;
        const label = selectId ? document.querySelector('label[for="' + CSS.escape(selectId) + '"]') : null;
        const explicitLabel = select.getAttribute("aria-label");
        if (label) {
            if (!label.id) {
                label.id = uniqueId("ops-select-label");
            }
            trigger.setAttribute("aria-labelledby", label.id);
            label.addEventListener("click", function (event) {
                event.preventDefault();
                trigger.focus();
            });
        } else if (explicitLabel) {
            trigger.setAttribute("aria-label", explicitLabel);
        }

        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        wrapper.appendChild(trigger);
        wrapper.appendChild(menu);
        select.classList.add("ops-select-native");
        select.setAttribute("aria-hidden", "true");
        select.tabIndex = -1;

        let highlightedIndex = -1;

        function enabledOptions() {
            return Array.prototype.slice.call(select.options).filter(function (option) {
                return !option.disabled;
            });
        }

        function selectedOption() {
            return select.options[select.selectedIndex] || null;
        }

        function syncTrigger() {
            const selected = selectedOption();
            value.textContent = selected ? optionLabel(selected) : "—";
            value.title = selected ? (selected.title || optionLabel(selected)) : "";
            trigger.disabled = select.disabled;
            trigger.setAttribute("aria-disabled", select.disabled ? "true" : "false");
            menu.querySelectorAll("[data-option-index]").forEach(function (item) {
                const index = Number(item.getAttribute("data-option-index"));
                const active = index === select.selectedIndex;
                item.setAttribute("aria-selected", active ? "true" : "false");
            });
        }

        function setHighlighted(index) {
            const items = Array.prototype.slice.call(menu.querySelectorAll(".ops-select-option:not(:disabled)"));
            if (!items.length) {
                highlightedIndex = -1;
                return;
            }
            const bounded = Math.max(0, Math.min(index, items.length - 1));
            highlightedIndex = bounded;
            items.forEach(function (item, itemIndex) {
                item.classList.toggle("is-highlighted", itemIndex === bounded);
            });
            items[bounded].scrollIntoView({ block: "nearest" });
        }

        function rebuildMenu() {
            menu.innerHTML = "";
            Array.prototype.slice.call(select.options).forEach(function (option, index) {
                const item = document.createElement("button");
                item.type = "button";
                item.className = "ops-select-option";
                item.setAttribute("role", "option");
                item.setAttribute("data-option-index", String(index));
                item.setAttribute("aria-selected", index === select.selectedIndex ? "true" : "false");
                item.disabled = option.disabled;
                if (option.title) {
                    item.title = option.title;
                }

                const labelNode = document.createElement("span");
                labelNode.className = "ops-select-option-label";
                labelNode.textContent = optionLabel(option);
                item.appendChild(labelNode);

                const check = document.createElement("span");
                check.className = "ops-select-check";
                check.setAttribute("aria-hidden", "true");
                check.textContent = "✓";
                item.appendChild(check);

                item.addEventListener("click", function () {
                    if (option.disabled) {
                        return;
                    }
                    select.selectedIndex = index;
                    select.dispatchEvent(new Event("input", { bubbles: true }));
                    select.dispatchEvent(new Event("change", { bubbles: true }));
                    closeMenu(true);
                    syncTrigger();
                });

                menu.appendChild(item);
            });
            highlightedIndex = -1;
            syncTrigger();
        }

        function preferredHighlightIndex() {
            const options = enabledOptions();
            const selected = selectedOption();
            const index = options.indexOf(selected);
            return index >= 0 ? index : 0;
        }

        function choosePlacement() {
            wrapper.classList.remove("is-dropup");
            const rect = trigger.getBoundingClientRect();
            const roomBelow = window.innerHeight - rect.bottom;
            const roomAbove = rect.top;
            if (roomBelow < 230 && roomAbove > roomBelow) {
                wrapper.classList.add("is-dropup");
            }
        }

        function openMenuAt(index) {
            if (select.disabled) {
                return;
            }
            if (openSelect && openSelect !== wrapper && typeof openSelect._opsClose === "function") {
                openSelect._opsClose(false);
            }
            choosePlacement();
            menu.hidden = false;
            wrapper.classList.add("is-open");
            trigger.setAttribute("aria-expanded", "true");
            openSelect = wrapper;
            window.requestAnimationFrame(function () {
                setHighlighted(index === undefined ? preferredHighlightIndex() : index);
            });
        }

        function closeMenu(returnFocus) {
            if (menu.hidden) {
                return;
            }
            menu.hidden = true;
            wrapper.classList.remove("is-open", "is-dropup");
            trigger.setAttribute("aria-expanded", "false");
            highlightedIndex = -1;
            if (openSelect === wrapper) {
                openSelect = null;
            }
            if (returnFocus) {
                trigger.focus();
            }
        }

        wrapper._opsClose = closeMenu;

        trigger.addEventListener("click", function () {
            if (menu.hidden) {
                openMenuAt();
            } else {
                closeMenu(false);
            }
        });

        trigger.addEventListener("keydown", function (event) {
            const keys = ["ArrowDown", "ArrowUp", "Home", "End", "Enter", " ", "Escape"];
            if (keys.indexOf(event.key) === -1) {
                return;
            }

            if (event.key === "Escape") {
                if (!menu.hidden) {
                    event.preventDefault();
                    closeMenu(false);
                }
                return;
            }

            if (menu.hidden) {
                if (["ArrowDown", "ArrowUp", "Enter", " ", "Home", "End"].indexOf(event.key) !== -1) {
                    event.preventDefault();
                    const options = enabledOptions();
                    let start = preferredHighlightIndex();
                    if (event.key === "End") {
                        start = Math.max(0, options.length - 1);
                    } else if (event.key === "Home") {
                        start = 0;
                    }
                    openMenuAt(start);
                }
                return;
            }

            event.preventDefault();
            const items = Array.prototype.slice.call(menu.querySelectorAll(".ops-select-option:not(:disabled)"));
            if (!items.length) {
                return;
            }

            if (event.key === "ArrowDown") {
                setHighlighted(highlightedIndex < 0 ? 0 : highlightedIndex + 1);
            } else if (event.key === "ArrowUp") {
                setHighlighted(highlightedIndex < 0 ? items.length - 1 : highlightedIndex - 1);
            } else if (event.key === "Home") {
                setHighlighted(0);
            } else if (event.key === "End") {
                setHighlighted(items.length - 1);
            } else if ((event.key === "Enter" || event.key === " ") && highlightedIndex >= 0) {
                items[highlightedIndex].click();
            }
        });

        select.addEventListener("change", syncTrigger);

        const observer = new MutationObserver(function () {
            rebuildMenu();
        });
        observer.observe(select, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ["disabled", "label", "selected"],
        });

        if (select.form) {
            select.form.addEventListener("reset", function () {
                window.setTimeout(function () {
                    rebuildMenu();
                }, 0);
            });
        }

        rebuildMenu();
    }

    function setupSelects() {
        document.querySelectorAll("select").forEach(enhanceSelect);

        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (!(node instanceof Element)) {
                        return;
                    }
                    if (node.matches("select")) {
                        enhanceSelect(node);
                    }
                    node.querySelectorAll("select").forEach(enhanceSelect);
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });

        document.addEventListener("click", function (event) {
            if (!openSelect || openSelect.contains(event.target)) {
                return;
            }
            if (typeof openSelect._opsClose === "function") {
                openSelect._opsClose(false);
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && openSelect && typeof openSelect._opsClose === "function") {
                openSelect._opsClose(true);
            }
        });
    }

    function setupCopyButtons() {
        document.addEventListener("click", function (event) {
            const button = event.target.closest("[data-copy-target]");
            if (!button) {
                return;
            }

            const selector = button.getAttribute("data-copy-target");
            const target = selector ? document.querySelector(selector) : null;
            if (!target) {
                return;
            }

            const text = (target.innerText || target.textContent || "").trim();
            if (!text) {
                return;
            }

            const original = button.textContent;
            const copied = button.getAttribute("data-copied-label") || "Copied";
            function markCopied() {
                button.textContent = copied;
                window.setTimeout(function () {
                    button.textContent = original;
                }, 1600);
            }

            if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
                navigator.clipboard.writeText(text).then(markCopied).catch(function () {
                    window.prompt("Copy", text);
                });
                return;
            }

            window.prompt("Copy", text);
        });
    }

    function setupPendingForms() {
        document.addEventListener("submit", function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.hasAttribute("data-ops-pending")) {
                return;
            }
            const button = event.submitter instanceof HTMLButtonElement
                ? event.submitter
                : form.querySelector("button[type='submit']");
            if (!button || button.disabled) {
                return;
            }
            button.disabled = true;
            button.classList.add("is-pending");
            if (button.dataset.pendingLabel) {
                button.textContent = button.dataset.pendingLabel;
            }
        });
    }

    setupThemeControls();
    setupUserMenu();
    setupPrefForms();
    setupClickableRows();
    setupSelects();
    setupCopyButtons();
    setupPendingForms();
})();
