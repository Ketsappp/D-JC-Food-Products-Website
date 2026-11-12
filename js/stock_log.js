(function () {
    "use strict";

    var body = document.getElementById("logBody");
    var search = document.getElementById("logSearch");
    var from = document.getElementById("logFrom");
    var to = document.getElementById("logTo");
    var clear = document.getElementById("logClear");
    var typeBtns = document.querySelectorAll(".log-types .cust-filter-btn");
    var noMatch = document.getElementById("logNoMatch");
    var count = document.getElementById("logCount");
    var type = "";

    function rows() { return Array.prototype.slice.call(body.querySelectorAll("tr[data-type]")); }

    function apply() {
        var q = search.value.trim().toLowerCase(), shown = 0;
        rows().forEach(function (tr) {
            var ok = (!q || tr.dataset.search.indexOf(q) !== -1) &&
                     (!type || tr.dataset.type === type) &&
                     (!from.value || tr.dataset.date >= from.value) &&
                     (!to.value || tr.dataset.date <= to.value);
            tr.hidden = !ok;
            if (ok) shown++;
        });
        var total = rows().length;
        noMatch.hidden = shown !== 0 || total === 0;
        count.textContent = total ? "Showing " + shown + " of " + total + " entries" : "";
    }

    search.addEventListener("input", apply);
    from.addEventListener("input", apply);
    to.addEventListener("input", apply);
    clear.addEventListener("click", function () { from.value = ""; to.value = ""; search.value = ""; apply(); });
    typeBtns.forEach(function (b) {
        b.addEventListener("click", function () {
            type = b.dataset.type;
            typeBtns.forEach(function (x) { x.classList.toggle("active", x === b); });
            apply();
        });
    });

    apply();
})();
