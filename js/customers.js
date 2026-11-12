(function () {
    "use strict";

    var body = document.getElementById("customersBody");
    var search = document.getElementById("customerSearch");
    var filterBtns = document.querySelectorAll(".cust-filter-btn");
    var noMatch = document.getElementById("noMatch");
    var countEl = document.getElementById("custCount");
    var alertBox = document.getElementById("alertBox");
    var modal = document.getElementById("blockModal");
    var confirmBtn = document.getElementById("confirmBlock");
    var statusFilter = "";
    var target = null;

    function rows() { return Array.prototype.slice.call(body.querySelectorAll("tr[data-id]")); }

    function applyFilters() {
        var q = search.value.trim().toLowerCase();
        var shown = 0;
        rows().forEach(function (tr) {
            var okText = !q || tr.dataset.search.indexOf(q) !== -1;
            var okStatus = !statusFilter ||
                (statusFilter === "active" && tr.dataset.active === "1") ||
                (statusFilter === "blocked" && tr.dataset.active === "0");
            var show = okText && okStatus;
            tr.hidden = !show;
            if (show) shown++;
        });
        var total = rows().length;
        noMatch.hidden = shown !== 0 || total === 0;
        countEl.textContent = total ? "Showing " + shown + " of " + total + " customers" : "";
    }

    function updateCounts() {
        var all = rows(), blocked = all.filter(function (r) { return r.dataset.active === "0"; }).length;
        var counts = { "": all.length, active: all.length - blocked, blocked: blocked };
        filterBtns.forEach(function (b) { b.querySelector("span").textContent = counts[b.dataset.status]; });
    }

    function showAlert(msg, type) {
        alertBox.textContent = msg;
        alertBox.className = "alert-box " + type;
        clearTimeout(showAlert.t);
        showAlert.t = setTimeout(function () { alertBox.className = "alert-box hidden"; }, 4000);
    }

    search.addEventListener("input", applyFilters);
    filterBtns.forEach(function (b) {
        b.addEventListener("click", function () {
            statusFilter = b.dataset.status;
            filterBtns.forEach(function (x) { x.classList.toggle("active", x === b); });
            applyFilters();
        });
    });

    body.addEventListener("click", function (e) {
        var btn = e.target.closest(".cust-btn");
        if (!btn) return;
        target = btn.closest("tr");
        var blocking = target.dataset.active === "1";
        var name = target.dataset.name;
        document.getElementById("blockModalTitle").textContent = blocking ? "Block this customer?" : "Unblock this customer?";
        document.getElementById("blockModalText").textContent = blocking
            ? name + " will be signed out and can no longer log in or place orders. Their order history is kept. You can unblock the account anytime."
            : name + " will be able to log in and order again.";
        confirmBtn.textContent = blocking ? "Block" : "Unblock";
        modal.classList.remove("hidden");
    });

    document.getElementById("cancelBlock").addEventListener("click", function (e) {
        e.preventDefault(); modal.classList.add("hidden"); target = null;
    });

    confirmBtn.addEventListener("click", function (e) {
        e.preventDefault();
        if (!target) return;
        var row = target, blocking = row.dataset.active === "1";
        var fd = new FormData();
        fd.append("id", row.dataset.id);
        fd.append("action", blocking ? "block" : "unblock");
        confirmBtn.style.pointerEvents = "none";

        fetch("customer_toggle.php", { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                modal.classList.add("hidden");
                if (!res.success) { showAlert(res.message, "error"); return; }
                var on = !blocking;
                row.dataset.active = on ? "1" : "0";
                row.classList.toggle("is-blocked", !on);
                var badge = row.querySelector(".status-badge");
                badge.textContent = on ? "Active" : "Blocked";
                badge.classList.toggle("is-enabled", on);
                badge.classList.toggle("is-off", !on);
                var b = row.querySelector(".cust-btn");
                b.classList.toggle("block-btn", on);
                b.classList.toggle("unblock-btn", !on);
                b.innerHTML = on ? "&#9940; Block" : "&#10003; Unblock";
                updateCounts();
                applyFilters();
                showAlert(res.message, "success");
            })
            .catch(function () { modal.classList.add("hidden"); showAlert("Something went wrong. Please try again.", "error"); })
            .then(function () { confirmBtn.style.pointerEvents = ""; target = null; });
    });

    applyFilters();
})();
