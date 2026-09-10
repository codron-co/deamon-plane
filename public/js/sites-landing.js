(function () {
    document.addEventListener("ops:ajax-success", function (event) {
        const detail = event.detail || {};
        const form = detail.form;
        const payload = detail.payload;
        if (!(form instanceof HTMLFormElement) || !payload || payload.ok === false) {
            return;
        }

        if (form.hasAttribute("data-landing-confirm")) {
            if (payload.ready) {
                window.location.reload();
            }
            return;
        }

        if (form.hasAttribute("data-domain-add") && payload.ok) {
            window.location.reload();
        }
    });

    document.addEventListener("ops:ajax-success", function (event) {
        const detail = event.detail || {};
        const form = detail.form;
        const payload = detail.payload;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute("data-cloudflare-zone") || !payload) {
            return;
        }

        const landing = document.querySelector("[data-landing-panel]");
        if (!landing) {
            return;
        }

        const waiting = payload.waiting === true || (payload.zone_status && payload.zone_status !== "active");
        landing.hidden = !waiting;
    });
})();
