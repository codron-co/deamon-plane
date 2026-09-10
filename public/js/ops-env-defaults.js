(function () {
    "use strict";

    document.querySelectorAll("[data-env-defaults]").forEach(function (form) {
        const body = form.querySelector("[data-env-rows]");
        const template = form.querySelector("[data-env-row-template]");
        const add = form.querySelector("[data-env-add]");
        if (!body || !template || !add) {
            return;
        }

        add.addEventListener("click", function () {
            const index = String(Date.now());
            const wrap = document.createElement("tbody");
            wrap.innerHTML = template.innerHTML.replaceAll("__INDEX__", index);
            const row = wrap.querySelector("[data-env-row]");
            if (!row) {
                return;
            }
            body.appendChild(row);
            const keyInput = row.querySelector('input[name$="[key]"]');
            if (keyInput) {
                keyInput.focus();
            }
        });

        form.addEventListener("click", function (event) {
            const button = event.target.closest("[data-env-remove]");
            if (!button || !form.contains(button)) {
                return;
            }
            const row = button.closest("[data-env-row]");
            if (row) {
                row.remove();
            }
        });
    });
})();
