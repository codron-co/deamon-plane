(function () {
    const connection = document.querySelector("[data-coolify-connection]");
    const template = connection ? connection.getAttribute("data-options-template") || "" : "";
    const serverSelect = document.querySelector("[data-coolify-servers]");
    const projectSelect = document.querySelector("[data-coolify-projects]");
    const environmentSelect = document.querySelector("[data-coolify-environments]");
    const gitSelect = document.querySelector("[data-coolify-git]");
    const attachSelect = document.querySelector("[data-attach-apps]");

    function fillSelect(select, items, valueKey, labelKey, current) {
        if (!select) {
            return;
        }
        const keep = current || select.value;
        select.innerHTML = "";
        const blank = document.createElement("option");
        blank.value = "";
        blank.textContent = "—";
        select.appendChild(blank);
        items.forEach(function (item) {
            const option = document.createElement("option");
            option.value = item[valueKey];
            option.textContent = item[labelKey];
            option.title = item.title || item.uuid || "";
            if (item.project_uuid) {
                option.dataset.project = item.project_uuid;
            }
            if (option.value === keep) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }

    function readCatalog() {
        if (!environmentSelect) {
            return [];
        }
        const raw = environmentSelect.getAttribute("data-environment-options");
        if (raw) {
            try {
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed) && parsed.length) {
                    return parsed;
                }
            } catch (e) {
                /* fall through */
            }
        }
        return Array.prototype.slice.call(environmentSelect.options).filter(function (option) {
            return option.value;
        }).map(function (option) {
            return {
                uuid: option.value,
                label: option.textContent,
                project_uuid: option.dataset.project || "",
                title: option.title || option.value,
            };
        });
    }

    function applyEnvironmentFilter(preferred) {
        if (!environmentSelect) {
            return;
        }
        const catalog = readCatalog();
        if (catalog.length) {
            environmentSelect.setAttribute("data-environment-options", JSON.stringify(catalog));
        }
        const project = projectSelect ? projectSelect.value : "";
        const scoped = catalog.filter(function (item) {
            return project !== "" && item.project_uuid === project;
        });
        fillSelect(environmentSelect, scoped, "uuid", "label", preferred !== undefined ? preferred : environmentSelect.value);
        if (catalog.length) {
            environmentSelect.setAttribute("data-environment-options", JSON.stringify(catalog));
        }
    }

    function pickValue(select, fallback, preserveCurrent) {
        if (preserveCurrent && select && select.value) {
            return select.value;
        }
        return fallback;
    }

    function loadOptions(id, preserveCurrent) {
        if (!id || !template) {
            return;
        }
        const url = template.replace("__id__", encodeURIComponent(id));
        fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("options");
                }
                return response.json();
            })
            .then(function (data) {
                const defaults = data.defaults || {};
                fillSelect(serverSelect, data.servers || [], "uuid", "label", pickValue(serverSelect, defaults.server, preserveCurrent));
                fillSelect(projectSelect, data.projects || [], "uuid", "label", pickValue(projectSelect, defaults.project, preserveCurrent));
                if (environmentSelect) {
                    environmentSelect.setAttribute("data-environment-options", JSON.stringify(data.environments || []));
                }
                applyEnvironmentFilter(pickValue(environmentSelect, defaults.environment, preserveCurrent));
                fillSelect(gitSelect, data.git_sources || [], "value", "label", pickValue(gitSelect, defaults.git, preserveCurrent));
                if (attachSelect) {
                    const apps = data.apps || [];
                    const review = attachSelect.getAttribute("data-needs-review-label") || "";
                    fillSelect(
                        attachSelect,
                        apps.map(function (app) {
                            return {
                                uuid: app.uuid,
                                label: app.name
                                    + (app.domain ? " — " + app.domain : "")
                                    + (app.branch ? " (" + app.branch + ")" : "")
                                    + (app.needs_review && review ? " · " + review : ""),
                            };
                        }),
                        "uuid",
                        "label",
                        attachSelect.value
                    );
                    document.querySelectorAll("[data-attach-empty]").forEach(function (el) {
                        el.hidden = apps.length > 0;
                    });
                }
            })
            .catch(function () {
                /* keep existing options */
            });
    }

    if (projectSelect) {
        projectSelect.addEventListener("change", function () {
            applyEnvironmentFilter("");
        });
    }
    applyEnvironmentFilter();

    if (connection) {
        connection.addEventListener("change", function () {
            loadOptions(connection.value);
        });
        if (connection.value) {
            loadOptions(connection.value, true);
        }
    }

    const placement = document.querySelector("[data-coolify-placement]");
    function setHidden(el, hide) {
        if (el) {
            el.hidden = hide;
        }
    }
    function setControlsDisabled(root, disabled) {
        if (!root) {
            return;
        }
        root.querySelectorAll("select, textarea, input:not([name='placement'])").forEach(function (el) {
            el.disabled = disabled;
        });
    }
    function syncPlacement() {
        if (!placement) {
            return;
        }
        const attach = placement.querySelector('input[name="placement"][value="attach"]');
        const isAttach = Boolean(attach && attach.checked);
        const locked = Boolean(placement.querySelector('input[name="placement"]:disabled'));
        document.querySelectorAll("[data-attach-field]").forEach(function (el) {
            setHidden(el, !isAttach);
            if (!locked) {
                setControlsDisabled(el, !isAttach);
            }
        });
        document.querySelectorAll("[data-provision-field]").forEach(function (el) {
            setHidden(el, isAttach);
        });
    }
    if (placement) {
        placement.addEventListener("change", syncPlacement);
        syncPlacement();
    }
})();
