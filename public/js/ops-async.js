(() => {
    "use strict";

    const csrfToken = function () {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute("content") : "";
    };

    const restorePending = function (form, submitter) {
        const buttons = [];
        if (submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) {
            buttons.push(submitter);
        }
        form.querySelectorAll("button[type='submit'], button:not([type]), input[type='submit']").forEach(function (button) {
            if (!buttons.includes(button)) {
                buttons.push(button);
            }
        });

        buttons.forEach(function (button) {
            button.disabled = false;
            button.classList.remove("is-pending");
            if (button.dataset.originalLabel) {
                button.textContent = button.dataset.originalLabel;
            }
        });
    };

    const shouldSkip = function (form) {
        if (form.hasAttribute("data-ops-native") || form.hasAttribute("data-pref-form")) {
            return true;
        }
        if ((form.getAttribute("target") || "") === "_blank") {
            return true;
        }

        const method = (form.getAttribute("method") || "get").toUpperCase();
        if (method === "GET") {
            return true;
        }

        const action = String(form.getAttribute("action") || form.action || "");
        return /\/logout\/?$/.test(action);
    };

    const flattenErrors = function (errors) {
        if (!errors || typeof errors !== "object") {
            return "";
        }

        return Object.keys(errors).map(function (key) {
            const value = errors[key];
            if (Array.isArray(value)) {
                return value.join(" ");
            }
            return String(value || "");
        }).filter(Boolean).join(" ");
    };

    const requestFailed = function () {
        const root = document.querySelector("[data-ops-jobs]");
        return (root && root.getAttribute("data-copy-request-failed")) || "Request failed.";
    };

    const sessionExpired = function () {
        const root = document.querySelector("[data-ops-jobs]");
        return (root && root.getAttribute("data-copy-session")) || "Session expired. Refresh the page.";
    };

    document.addEventListener("submit", async function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.closest(".ops-app") || shouldSkip(form)) {
            return;
        }

        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        event.preventDefault();

        const action = (submitter && submitter.getAttribute("formaction")) || form.getAttribute("action") || form.action;
        const method = (form.getAttribute("method") || "POST").toUpperCase();
        const body = new FormData(form);
        if ((submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) && submitter.name) {
            body.append(submitter.name, submitter.value);
        }

        try {
            const response = await fetch(action, {
                method: method,
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "X-CSRF-TOKEN": csrfToken(),
                },
                credentials: "same-origin",
                body: body,
            });

            restorePending(form, submitter);

            const payload = await response.json().catch(function () {
                return {};
            });

            if (response.status === 419) {
                window.PlaneJobs && window.PlaneJobs.showMessage(sessionExpired(), "error");
                return;
            }

            if (payload.job) {
                window.PlaneJobs && window.PlaneJobs.track(payload.job);
                return;
            }

            if (response.status === 422) {
                window.PlaneJobs && window.PlaneJobs.showMessage(flattenErrors(payload.errors) || payload.message || requestFailed(), "error");
                return;
            }

            if (response.status === 403) {
                window.PlaneJobs && window.PlaneJobs.showMessage(payload.message || requestFailed(), "error");
                return;
            }

            const type = payload.type || (payload.ok === false ? "error" : "status");
            if (payload.message) {
                window.PlaneJobs && window.PlaneJobs.showMessage(payload.message, type);
            } else if (!response.ok) {
                window.PlaneJobs && window.PlaneJobs.showMessage(requestFailed(), "error");
            }

            if (payload.redirect) {
                const next = new URL(payload.redirect, window.location.origin);
                if (next.pathname !== window.location.pathname) {
                    window.location.assign(next.href);
                }
            }
        } catch (error) {
            restorePending(form, submitter);
            window.PlaneJobs && window.PlaneJobs.showMessage(requestFailed(), "error");
        }
    });
})();
