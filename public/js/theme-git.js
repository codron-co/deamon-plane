(function () {
    var manifestForm = document.querySelector("[data-theme-git-manifest]");
    if (manifestForm instanceof HTMLFormElement) {
        manifestForm.submit();
    }

    var settings = document.querySelector("[data-theme-git-settings]");
    if (! (settings instanceof HTMLFormElement)) {
        return;
    }

    var select = settings.querySelector("[data-theme-git-selection]");
    var boxes = settings.querySelectorAll("[data-theme-git-include]");

    function syncIncludes() {
        var selected = select instanceof HTMLSelectElement && select.value === "selected";
        boxes.forEach(function (box) {
            if (box instanceof HTMLInputElement) {
                box.disabled = ! selected;
            }
        });
    }

    if (select) {
        select.addEventListener("change", syncIncludes);
    }

    syncIncludes();
})();
