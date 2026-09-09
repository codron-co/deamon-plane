(function () {
    const connection = document.querySelector("[data-coolify-connection]");
    if (!connection) {
        return;
    }

    const template = connection.getAttribute("data-options-template") || "";
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
            if (item.project_uuid) {
                option.dataset.project = item.project_uuid;
            }
            if (option.value === keep) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }

    function loadOptions(id) {
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
                fillSelect(serverSelect, data.servers || [], "uuid", "label", defaults.server);
                fillSelect(projectSelect, data.projects || [], "uuid", "label", defaults.project);
                fillSelect(environmentSelect, data.environments || [], "uuid", "label", defaults.environment);
                fillSelect(gitSelect, data.git_sources || [], "value", "label", defaults.git);
                if (attachSelect) {
                    fillSelect(
                        attachSelect,
                        (data.apps || []).map(function (app) {
                            return {
                                uuid: app.uuid,
                                label: app.name + (app.domain ? " — " + app.domain : "") + (app.branch ? " (" + app.branch + ")" : ""),
                            };
                        }),
                        "uuid",
                        "label",
                        attachSelect.value
                    );
                }
            })
            .catch(function () {
                /* keep existing options */
            });
    }

    connection.addEventListener("change", function () {
        loadOptions(connection.value);
    });

    const placement = document.querySelector("[data-coolify-placement]");
    const attachField = document.querySelector("[data-attach-field]");
    function syncPlacement() {
        if (!placement || !attachField) {
            return;
        }
        const attach = placement.querySelector('input[name="placement"][value="attach"]');
        attachField.hidden = !attach || !attach.checked;
    }
    if (placement) {
        placement.addEventListener("change", syncPlacement);
        syncPlacement();
    }
})();
