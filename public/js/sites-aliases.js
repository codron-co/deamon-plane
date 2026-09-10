(function () {
    document.addEventListener("click", function (event) {
        const button = event.target instanceof Element ? event.target.closest("[data-alias-add]") : null;
        if (!button) {
            return;
        }

        const field = button.closest("[data-alias-field]");
        const list = field ? field.querySelector("[data-alias-list]") : null;
        if (!list) {
            return;
        }

        const row = document.createElement("div");
        row.className = "ops-alias-row";

        const input = document.createElement("input");
        input.className = "field-input";
        input.type = "text";
        input.name = "aliases[]";
        input.autocomplete = "off";
        input.maxLength = 255;
        input.placeholder = list.querySelector("input") ? list.querySelector("input").placeholder : "";

        row.appendChild(input);
        list.appendChild(row);
        input.focus();
    });
})();
