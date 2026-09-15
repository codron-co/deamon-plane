(function () {
    "use strict";

    const fieldFor = function (element) {
        return element instanceof Element ? element.closest("[data-alias-field]") : null;
    };

    const renumber = function (field) {
        const addButton = field.querySelector("[data-alias-add]");
        const template = addButton ? addButton.getAttribute("data-remove-aria") || "" : "";
        field.querySelectorAll("[data-alias-row]").forEach(function (row, index) {
            const remove = row.querySelector("[data-alias-remove]");
            if (remove && template) {
                remove.setAttribute("aria-label", template.replace("__N__", String(index + 1)));
            }
        });
    };

    const buildRow = function (field) {
        const list = field.querySelector("[data-alias-list]");
        const addButton = field.querySelector("[data-alias-add]");
        const row = document.createElement("div");
        row.className = "ops-alias-row";
        row.setAttribute("data-alias-row", "");

        const input = document.createElement("input");
        input.className = "field-input";
        input.type = "text";
        input.name = "aliases[]";
        input.autocomplete = "off";
        input.maxLength = 255;
        const sample = list ? list.querySelector("input") : null;
        input.placeholder = sample ? sample.placeholder : "";
        row.appendChild(input);

        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "btn btn-ghost btn-sm";
        remove.setAttribute("data-alias-remove", "");
        remove.textContent = addButton ? addButton.getAttribute("data-remove-label") || "" : "";
        row.appendChild(remove);

        return row;
    };

    document.addEventListener("click", function (event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        const add = target.closest("[data-alias-add]");
        if (add) {
            const field = fieldFor(add);
            const list = field ? field.querySelector("[data-alias-list]") : null;
            if (!list) {
                return;
            }
            const row = buildRow(field);
            list.appendChild(row);
            renumber(field);
            row.querySelector("input").focus();
            return;
        }

        const remove = target.closest("[data-alias-remove]");
        if (remove) {
            const field = fieldFor(remove);
            const row = remove.closest("[data-alias-row]");
            const list = field ? field.querySelector("[data-alias-list]") : null;
            if (!field || !row || !list) {
                return;
            }
            row.remove();
            // Keep one empty row so the operator can still type an alias without pressing Add.
            if (!list.querySelector("[data-alias-row]")) {
                list.appendChild(buildRow(field));
            }
            renumber(field);
            const next = list.querySelector("[data-alias-row] input");
            if (next) {
                next.focus();
            }
        }
    });
})();
