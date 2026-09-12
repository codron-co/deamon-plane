(() => {
    "use strict";

    const STORAGE_KEY = "planeOpsJobs";
    const DISMISS_KEY = "planeOpsJobsDismissed";
    const POLL_MS = 1500;
    const DISMISS_MS = 30000;

    const root = document.querySelector("[data-ops-jobs]");
    if (!root) {
        window.PlaneJobs = { track: function () {}, showMessage: function () {} };
        return;
    }

    const listEl = root.querySelector("[data-ops-jobs-list]");
    const bodyEl = root.querySelector("[data-ops-jobs-body]");
    const summaryEl = root.querySelector("[data-ops-jobs-summary]");
    const countEl = root.querySelector("[data-ops-jobs-count]");
    const toggleBtn = root.querySelector("[data-ops-jobs-toggle]");
    const minimizeBtn = root.querySelector("[data-ops-jobs-minimize]");
    const closeBtn = root.querySelector("[data-ops-jobs-close]");
    const canWrite = root.getAttribute("data-can-write") === "1";
    const indexUrl = root.getAttribute("data-jobs-index") || "/jobs";
    const showBase = (root.getAttribute("data-jobs-show") || "/jobs").replace(/\/$/, "");
    const destroyBase = (root.getAttribute("data-jobs-destroy") || "/jobs").replace(/\/$/, "");
    const deployCancelBase = (root.getAttribute("data-jobs-deploy-cancel") || "/jobs/deployments").replace(/\/$/, "");
    const deployForceBase = (root.getAttribute("data-jobs-deploy-force") || "/jobs/deployments").replace(/\/$/, "");
    const coolifyCancelBase = (root.getAttribute("data-jobs-coolify-cancel") || "/jobs/coolify-deployments").replace(/\/$/, "");
    const coolifyForceBase = (root.getAttribute("data-jobs-coolify-force") || "/jobs/coolify-deployments").replace(/\/$/, "");

    let trackedIds = new Set();
    let jobsById = new Map();
    let dismissedIds = new Set();
    let dismissTimers = new Map();
    let messages = [];
    let pollTimer = null;
    let collapsed = false;
    let dismissed = false;
    let messageSeq = 0;
    let actionBusy = new Set();

    const copy = function (key, fallback) {
        return root.getAttribute("data-copy-" + key) || fallback;
    };

    const csrfToken = function () {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute("content") : "";
    };

    const readStorage = function () {
        try {
            const parsed = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || "[]");
            return Array.isArray(parsed) ? parsed.filter(function (id) { return typeof id === "string"; }) : [];
        } catch (error) {
            return [];
        }
    };

    const writeStorage = function () {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(trackedIds)));
        } catch (error) {
            /* sessionStorage can be unavailable */
        }
    };

    const readDismissed = function () {
        try {
            const parsed = JSON.parse(sessionStorage.getItem(DISMISS_KEY) || "[]");
            return Array.isArray(parsed) ? parsed.filter(function (id) { return typeof id === "string"; }) : [];
        } catch (error) {
            return [];
        }
    };

    const writeDismissed = function () {
        try {
            sessionStorage.setItem(DISMISS_KEY, JSON.stringify(Array.from(dismissedIds)));
        } catch (error) {
            /* sessionStorage can be unavailable */
        }
    };

    const isActive = function (status) {
        return status === "queued" || status === "running";
    };

    const isTerminal = function (status) {
        return status === "completed" || status === "failed" || status === "cancelled";
    };

    const statusLabel = function (status) {
        if (status === "queued") {
            return copy("queued", "Queued");
        }
        if (status === "running") {
            return copy("running", "Running");
        }
        if (status === "failed") {
            return copy("failed", "Failed");
        }
        if (status === "cancelled") {
            return copy("cancelled", "Cancelled");
        }
        return copy("completed", "Done");
    };

    const fetchJson = async function (url, options) {
        const opts = options || {};
        const headers = {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": csrfToken(),
        };
        if (opts.method && opts.method !== "GET") {
            headers["Content-Type"] = "application/json";
        }

        const response = await fetch(url, {
            credentials: "same-origin",
            method: opts.method || "GET",
            headers: headers,
            body: opts.body || undefined,
        });

        if (response.status === 403 || response.status === 401) {
            return null;
        }

        const payload = await response.json().catch(function () {
            return {};
        });

        if (response.status === 419) {
            throw new Error(copy("session", "Session expired. Refresh the page."));
        }

        if (!response.ok) {
            throw new Error(payload.message || copy("request-failed", "Request failed."));
        }

        return payload;
    };

    const applyLiveResults = function (job) {
        if (!job || job.type !== "sites.live_sync" || !job.result || !Array.isArray(job.result.sites)) {
            return;
        }

        job.result.sites.forEach(function (row) {
            if (!row || !row.id) {
                return;
            }

            const tr = document.querySelector('tr[data-site-id="' + row.id + '"]');
            if (!tr) {
                return;
            }

            const chip = tr.querySelector("[data-live-chip]");
            if (chip) {
                chip.textContent = row.live_label || "";
                chip.className = "status-chip status-" + (row.live_tone || "unknown");
            }

            if (row.favicon && window.PlaneFavicon && typeof window.PlaneFavicon.refresh === "function") {
                const mark = tr.querySelector("[data-favicon-host]");
                if (mark) {
                    window.PlaneFavicon.refresh(mark, row.favicon);
                }
            }
        });
    };

    const applyAppHealthResults = function (job) {
        if (!job || job.type !== "sites.bulk_app_health_fix" || !job.result || !Array.isArray(job.result.sites)) {
            return;
        }

        job.result.sites.forEach(function (health) {
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
        });
    };

    const visibleItems = function () {
        const jobs = Array.from(jobsById.values()).filter(function (item) {
            return !dismissedIds.has(item.id);
        });
        return jobs.concat(messages);
    };

    const itemUrl = function (item) {
        return item && typeof item.url === "string" && item.url !== "" ? item.url : "";
    };

    const clearDismissTimer = function (id) {
        const timer = dismissTimers.get(id);
        if (timer) {
            window.clearTimeout(timer);
            dismissTimers.delete(id);
        }
    };

    const scheduleDismiss = function (id) {
        if (dismissTimers.has(id)) {
            return;
        }

        const timer = window.setTimeout(function () {
            dismissTimers.delete(id);
            jobsById.delete(id);
            trackedIds.delete(id);
            writeStorage();
            render();
            stopIfIdle();
        }, DISMISS_MS);

        dismissTimers.set(id, timer);
    };

    const markDismissed = function (id) {
        clearDismissTimer(id);
        jobsById.delete(id);
        trackedIds.delete(id);
        dismissedIds.add(id);
        writeStorage();
        writeDismissed();
        render();
    };

    const actionsFor = function (item) {
        const actions = item && item.actions && typeof item.actions === "object" ? item.actions : {};
        return {
            cancel: actions.cancel === true,
            force_start: actions.force_start === true,
            dismiss: actions.dismiss === true || (isTerminal(item.status) && item.type !== "message"),
        };
    };

    const svgIcon = function (pathD) {
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("viewBox", "0 0 16 16");
        svg.setAttribute("width", "12");
        svg.setAttribute("height", "12");
        svg.setAttribute("aria-hidden", "true");
        const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
        path.setAttribute("d", pathD);
        path.setAttribute("fill", "none");
        path.setAttribute("stroke", "currentColor");
        path.setAttribute("stroke-width", "1.4");
        path.setAttribute("stroke-linecap", "round");
        path.setAttribute("stroke-linejoin", "round");
        svg.appendChild(path);
        return svg;
    };

    const runAction = async function (item, kind, button) {
        if (!item || !item.id || actionBusy.has(item.id)) {
            return;
        }

        actionBusy.add(item.id);
        if (button) {
            button.disabled = true;
        }

        try {
            if (kind === "dismiss") {
                if (item.type === "coolify.deployment") {
                    markDismissed(item.id);
                    return;
                }
                await fetchJson(destroyBase + "/" + encodeURIComponent(item.id), { method: "DELETE" });
                markDismissed(item.id);
                return;
            }

            let url = "";
            if (item.deployment_id) {
                url = (kind === "force_start" ? deployForceBase : deployCancelBase)
                    + "/" + encodeURIComponent(String(item.deployment_id))
                    + "/" + (kind === "force_start" ? "force-start" : "cancel");
            } else if (item.coolify_deployment_uuid) {
                url = (kind === "force_start" ? coolifyForceBase : coolifyCancelBase)
                    + "/" + encodeURIComponent(String(item.coolify_deployment_uuid))
                    + "/" + (kind === "force_start" ? "force-start" : "cancel");
            } else {
                throw new Error(copy("request-failed", "Request failed."));
            }

            const payload = await fetchJson(url, { method: "POST", body: "{}" });
            if (payload && payload.deployment) {
                if (kind === "cancel") {
                    markDismissed(item.id);
                }
                upsertJob(payload.deployment);
            } else {
                await poll();
            }
        } catch (error) {
            showMessage(error && error.message ? error.message : copy("request-failed", "Request failed."), "error");
        } finally {
            actionBusy.delete(item.id);
            render();
        }
    };

    const render = function () {
        const items = visibleItems();
        const active = items.filter(function (item) {
            return isActive(item.status);
        }).length;

        if (items.length === 0) {
            root.hidden = true;
            return;
        }

        if (dismissed && active === 0 && messages.length === 0) {
            root.hidden = true;
            return;
        }

        dismissed = false;
        root.hidden = false;
        root.classList.toggle("is-collapsed", collapsed);
        if (toggleBtn) {
            toggleBtn.setAttribute("aria-expanded", collapsed ? "false" : "true");
        }
        if (summaryEl) {
            summaryEl.textContent = copy("title", "Background tasks");
        }
        if (countEl) {
            countEl.hidden = active === 0;
            countEl.textContent = String(active);
        }
        if (bodyEl) {
            bodyEl.hidden = collapsed;
        }
        if (!listEl) {
            return;
        }

        listEl.replaceChildren();
        items.forEach(function (item) {
            const li = document.createElement("li");
            li.className = "ops-jobs-item is-" + (item.status || "completed");

            const head = document.createElement("div");
            head.className = "ops-jobs-item-head";

            const href = itemUrl(item);
            let title;
            if (href) {
                title = document.createElement("a");
                title.href = href;
                title.className = "ops-jobs-link";
            } else {
                title = document.createElement("strong");
            }
            title.textContent = item.title || copy("title", "Background tasks");

            const actionsEl = document.createElement("div");
            actionsEl.className = "ops-jobs-item-actions";
            const actions = actionsFor(item);

            if (actions.force_start) {
                const forceBtn = document.createElement("button");
                forceBtn.type = "button";
                forceBtn.className = "ops-jobs-item-btn is-force";
                forceBtn.setAttribute("aria-label", copy("force-start", "Force start"));
                forceBtn.title = copy("force-start", "Force start");
                forceBtn.appendChild(svgIcon("M4 3.5v9l9-4.5z"));
                forceBtn.addEventListener("click", function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    runAction(item, "force_start", forceBtn);
                });
                actionsEl.appendChild(forceBtn);
            }

            if (actions.cancel || actions.dismiss) {
                const closeItemBtn = document.createElement("button");
                closeItemBtn.type = "button";
                closeItemBtn.className = "ops-jobs-item-btn is-dismiss";
                const label = actions.cancel
                    ? copy("stop", "Stop deploy")
                    : copy("dismiss", "Dismiss");
                closeItemBtn.setAttribute("aria-label", label);
                closeItemBtn.title = label;
                closeItemBtn.appendChild(svgIcon("M4 4l8 8M12 4l-8 8"));
                closeItemBtn.addEventListener("click", function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    runAction(item, actions.cancel ? "cancel" : "dismiss", closeItemBtn);
                });
                actionsEl.appendChild(closeItemBtn);
            }

            head.appendChild(title);
            head.appendChild(actionsEl);

            const meta = document.createElement("span");
            meta.className = "ops-jobs-meta";
            meta.textContent = item.message || statusLabel(item.status);

            li.appendChild(head);
            li.appendChild(meta);

            if (isActive(item.status)) {
                // Only work Coolify is actually building may animate. A queued item
                // gets an inert rail so the row keeps its rhythm without claiming
                // progress it does not have.
                const waiting = item.status === "queued";
                const indeterminate = !waiting && (item.indeterminate === true || item.progress == null);
                const bar = document.createElement("span");
                bar.className = "ops-jobs-progress"
                    + (indeterminate ? " is-indeterminate" : "")
                    + (waiting ? " is-waiting" : "");
                bar.setAttribute("role", "progressbar");
                bar.setAttribute("aria-valuemin", "0");
                bar.setAttribute("aria-valuemax", "100");
                if (indeterminate) {
                    bar.setAttribute("aria-valuetext", statusLabel(item.status));
                } else if (waiting) {
                    bar.setAttribute("aria-valuenow", "0");
                    bar.setAttribute("aria-valuetext", statusLabel(item.status));
                } else {
                    const value = Math.max(0, Math.min(100, Number(item.progress) || 0));
                    bar.setAttribute("aria-valuenow", String(value));
                }
                const fill = document.createElement("span");
                if (waiting) {
                    fill.style.width = "0%";
                } else if (!indeterminate) {
                    fill.style.width = Math.max(0, Math.min(100, Number(item.progress) || 0)) + "%";
                }
                bar.appendChild(fill);
                li.appendChild(bar);
            }

            listEl.appendChild(li);
        });
    };

    const upsertJob = function (job) {
        if (!job || !job.id) {
            return;
        }

        if (dismissedIds.has(job.id)) {
            return;
        }

        const previous = jobsById.get(job.id);
        jobsById.set(job.id, job);
        if (job.type !== "coolify.deployment") {
            trackedIds.add(job.id);
            writeStorage();
        }

        if (job.type === "sites.live_sync" && job.status === "completed" && (!previous || previous.status !== "completed")) {
            applyLiveResults(job);
            applyAppHealthResults(job);
        }

        if (isActive(job.status)) {
            clearDismissTimer(job.id);
        } else if (isTerminal(job.status) && (!previous || previous.status !== job.status)) {
            scheduleDismiss(job.id);
        }

        render();
    };

    const pruneAbsentDeployments = function (payloadDeployments) {
        const seen = new Set();
        (payloadDeployments || []).forEach(function (job) {
            if (job && job.id) {
                seen.add(job.id);
            }
        });

        Array.from(jobsById.values()).forEach(function (job) {
            if (job.type !== "coolify.deployment") {
                return;
            }
            if (seen.has(job.id)) {
                return;
            }
            clearDismissTimer(job.id);
            jobsById.delete(job.id);
        });
    };

    const stopIfIdle = function () {
        /* Keep polling so Coolify deployments stay visible after bulk jobs finish. */
    };

    const poll = async function () {
        if (!canWrite) {
            return;
        }

        try {
            const payload = await fetchJson(indexUrl);
            if (payload && Array.isArray(payload.jobs)) {
                payload.jobs.forEach(upsertJob);
            }
            if (payload && Array.isArray(payload.deployments)) {
                payload.deployments.forEach(upsertJob);
                pruneAbsentDeployments(payload.deployments);
            } else {
                pruneAbsentDeployments([]);
            }

            const ids = Array.from(trackedIds);
            for (let i = 0; i < ids.length; i += 1) {
                const current = jobsById.get(ids[i]);
                if (current && (current.type === "coolify.deployment" || !isActive(current.status))) {
                    continue;
                }
                const shown = await fetchJson(showBase + "/" + encodeURIComponent(ids[i]));
                if (shown && shown.job) {
                    upsertJob(shown.job);
                }
            }
        } catch (error) {
            /* Keep the last known job list when polling fails. */
        }

        stopIfIdle();
    };

    const startPoll = function () {
        if (!canWrite || pollTimer) {
            return;
        }
        pollTimer = window.setInterval(function () {
            poll();
        }, POLL_MS);
        poll();
    };

    const track = function (job) {
        dismissed = false;
        collapsed = false;
        if (job && job.id) {
            dismissedIds.delete(job.id);
            writeDismissed();
        }
        upsertJob(job);
        startPoll();
    };

    const showMessage = function (message, type) {
        const text = String(message || "").trim();
        if (!text) {
            return;
        }

        dismissed = false;
        collapsed = false;
        const id = "msg-" + String(++messageSeq);
        const item = {
            id: id,
            type: "message",
            title: copy("title", "Background tasks"),
            status: type === "error" ? "failed" : (type === "warning" ? "running" : "completed"),
            progress: 100,
            message: text,
            actions: { dismiss: true, cancel: false, force_start: false },
        };
        messages = messages.filter(function (entry) {
            return entry.message !== text;
        }).concat([item]);
        render();
        window.setTimeout(function () {
            messages = messages.filter(function (entry) {
                return entry.id !== id;
            });
            render();
        }, type === "error" ? DISMISS_MS : 8000);
    };

    if (minimizeBtn) {
        minimizeBtn.addEventListener("click", function () {
            collapsed = !collapsed;
            render();
        });
    }

    if (toggleBtn) {
        toggleBtn.addEventListener("click", function () {
            collapsed = !collapsed;
            render();
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener("click", function () {
            dismissed = true;
            messages = [];
            Array.from(jobsById.values()).forEach(function (job) {
                if (!isActive(job.status)) {
                    clearDismissTimer(job.id);
                    jobsById.delete(job.id);
                    trackedIds.delete(job.id);
                    dismissedIds.add(job.id);
                }
            });
            writeStorage();
            writeDismissed();
            render();
            if (Array.from(jobsById.values()).every(function (job) { return !isActive(job.status); })) {
                root.hidden = true;
            }
        });
    }

    readStorage().forEach(function (id) {
        trackedIds.add(id);
    });
    readDismissed().forEach(function (id) {
        dismissedIds.add(id);
    });
    if (canWrite) {
        startPoll();
    }

    window.PlaneJobs = { track: track, showMessage: showMessage };
})();
