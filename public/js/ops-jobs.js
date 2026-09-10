(() => {
    "use strict";

    const STORAGE_KEY = "planeOpsJobs";
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

    let trackedIds = new Set();
    let jobsById = new Map();
    let messages = [];
    let pollTimer = null;
    let collapsed = false;
    let dismissed = false;
    let messageSeq = 0;

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

    const isActive = function (status) {
        return status === "queued" || status === "running";
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
        return copy("completed", "Done");
    };

    const fetchJson = async function (url) {
        const response = await fetch(url, {
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": csrfToken(),
            },
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

    const visibleItems = function () {
        const jobs = Array.from(jobsById.values());
        return jobs.concat(messages);
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

            const title = document.createElement("strong");
            title.textContent = item.title || copy("title", "Background tasks");

            const meta = document.createElement("span");
            meta.className = "ops-jobs-meta";
            meta.textContent = item.message || statusLabel(item.status);

            li.appendChild(title);
            li.appendChild(meta);

            if (isActive(item.status)) {
                const bar = document.createElement("span");
                bar.className = "ops-jobs-progress";
                bar.setAttribute("role", "progressbar");
                bar.setAttribute("aria-valuemin", "0");
                bar.setAttribute("aria-valuemax", "100");
                const value = Math.max(0, Math.min(100, Number(item.progress) || 0));
                bar.setAttribute("aria-valuenow", String(value));
                const fill = document.createElement("span");
                fill.style.width = value + "%";
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

        const previous = jobsById.get(job.id);
        jobsById.set(job.id, job);
        trackedIds.add(job.id);
        writeStorage();

        if (job.status === "completed" && (!previous || previous.status !== "completed")) {
            applyLiveResults(job);
            window.setTimeout(function () {
                jobsById.delete(job.id);
                trackedIds.delete(job.id);
                writeStorage();
                render();
                stopIfIdle();
            }, DISMISS_MS);
        }

        if (job.status === "failed" && (!previous || previous.status !== "failed")) {
            window.setTimeout(function () {
                jobsById.delete(job.id);
                trackedIds.delete(job.id);
                writeStorage();
                render();
                stopIfIdle();
            }, DISMISS_MS);
        }

        render();
    };

    const stopIfIdle = function () {
        const busy = Array.from(jobsById.values()).some(function (job) {
            return isActive(job.status);
        });
        if (!busy && pollTimer) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
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

            const ids = Array.from(trackedIds);
            for (let i = 0; i < ids.length; i += 1) {
                const current = jobsById.get(ids[i]);
                if (current && !isActive(current.status)) {
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
            title: copy("title", "Background tasks"),
            status: type === "error" ? "failed" : (type === "warning" ? "running" : "completed"),
            progress: 100,
            message: text,
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
                    jobsById.delete(job.id);
                    trackedIds.delete(job.id);
                }
            });
            writeStorage();
            render();
            if (Array.from(jobsById.values()).every(function (job) { return !isActive(job.status); })) {
                root.hidden = true;
            }
        });
    }

    readStorage().forEach(function (id) {
        trackedIds.add(id);
    });
    if (trackedIds.size > 0) {
        startPoll();
    }

    window.PlaneJobs = { track: track, showMessage: showMessage };
})();
