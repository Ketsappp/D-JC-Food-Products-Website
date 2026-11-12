/**
 * Customer notifications: polls for orders that are ready for pick-up,
 * shows a banner + a badge on "My Orders", and clears it once seen.
 * It also warns about GCash orders that are still unpaid:
 *   - when 3 minutes are left to pay, and
 *   - when the normal payment time is over and the late-payment window starts.
 * Each warning pops up once per order (it is remembered in this browser) and stays in the
 * bell while it applies. Loaded on every customer page while a customer is signed in.
 */
(function () {
    "use strict";

    var POLL_MS = 10000;
    var badges = document.querySelectorAll("[data-orders-badge], #myOrdersBadge");
    var onOrdersPage = /my_orders\.php$/.test(window.location.pathname);
    var shown = {};          // order ids already announced on this page
    var banner = null;

    var WARN_SECONDS = 180;  // "3 minutes left" reminder
    var lateMinutes = 5;     // updated from the server
    var lastReady = [];      // ready orders (for the bell)
    var awaiting = {};       // unpaid GCash orders: id -> { number, payExpires, cancelExpires, receipt }
    var payAlerts = [];      // unpaid orders that are in the "3 minutes left" or "late" stage now
    var toastBox = null;

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    function markSeen() {
        return fetch("my_orders_seen.php", { method: "POST", credentials: "same-origin" }).catch(function () {});
    }

    // Badge = ready orders + payment reminders. The bell (js/customer_bar.js) draws the list.
    function publish() {
        var count = lastReady.length + payAlerts.length;

        badges.forEach(function (b) {
            b.textContent = count;
            b.hidden = count === 0;
        });

        window.dispatchEvent(new CustomEvent("customer:ready-orders", { detail: { ready: lastReady, pay: payAlerts } }));
    }

    function setBadge(count) {
        if (count === 0) lastReady = [];
        publish();
    }

    // Short chime for new notifications (ignored if the browser blocks audio).
    function chime() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator(), gain = ctx.createGain();
            osc.type = "sine"; osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.0001, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.5);
            osc.connect(gain); gain.connect(ctx.destination);
            osc.start(); osc.stop(ctx.currentTime + 0.5);
        } catch (e) { /* no sound */ }
    }

    // ---- payment reminders (unpaid GCash orders) ----
    function stageSeen(id, stage) {
        try {
            var all = JSON.parse(window.localStorage.getItem("djcPayAlerts") || "{}");
            return !!all[id + ":" + stage];
        } catch (e) { return false; }
    }

    function rememberStage(id, stage) {
        try {
            var all = JSON.parse(window.localStorage.getItem("djcPayAlerts") || "{}");
            all[id + ":" + stage] = Date.now();

            // Keep the list short: forget reminders older than a day.
            Object.keys(all).forEach(function (k) {
                if (Date.now() - all[k] > 86400000) delete all[k];
            });

            window.localStorage.setItem("djcPayAlerts", JSON.stringify(all));
        } catch (e) { /* storage blocked: the reminder may show again after a reload */ }
    }

    function payToast(order, stage) {
        if (!toastBox) {
            toastBox = document.createElement("div");
            toastBox.className = "pay-toasts";
            document.body.appendChild(toastBox);
        }

        var late = stage === "late";
        var title = late
            ? "Order #" + esc(order.number) + ": the payment time is over"
            : "Order #" + esc(order.number) + ": less than 3 minutes left to pay";
        var text = late
            ? "Your late-payment window has started. You have " + lateMinutes + " more minutes to pay. " +
              "If you already paid, send your receipt now. After that the order is cancelled."
            : "Please pay with GCash now. If you already paid, send your receipt so our staff can confirm it.";

        var toast = document.createElement("div");
        toast.className = "pay-toast " + (late ? "is-late" : "is-warn");
        toast.setAttribute("role", "alert");
        toast.innerHTML =
            '<div class="pay-toast-body"><strong>' + title + "</strong><span>" + text + "</span></div>" +
            (onOrdersPage ? "" : '<a class="pay-toast-link" href="my_orders.php">Pay now</a>') +
            '<button type="button" class="pay-toast-close" aria-label="Dismiss">&times;</button>';

        function remove() { if (toast.parentNode) toast.parentNode.removeChild(toast); }

        toast.querySelector(".pay-toast-close").addEventListener("click", remove);
        setTimeout(remove, 30000);
        toastBox.appendChild(toast);
        chime();
    }

    // Runs every second: works out which unpaid orders are in a reminder stage.
    function checkPayment() {
        var now = Date.now();
        var list = [];

        Object.keys(awaiting).forEach(function (id) {
            var o = awaiting[id];

            // A receipt was already sent: our staff are verifying it, no need to nag.
            if (o.receipt) return;

            var payLeft = (o.payExpires - now) / 1000;
            var cancelLeft = (o.cancelExpires - now) / 1000;
            var stage = null;

            if (payLeft <= 0) {
                if (cancelLeft > 0) stage = "late";
            } else if (payLeft <= WARN_SECONDS) {
                stage = "warn";
            }

            if (!stage) return;

            list.push({ id: o.id, number: o.number, stage: stage });

            if (!stageSeen(o.id, stage)) {
                rememberStage(o.id, stage);
                payToast(o, stage);
            }
        });

        var signature = list.map(function (a) { return a.id + a.stage; }).join(",");
        var before = payAlerts.map(function (a) { return a.id + a.stage; }).join(",");

        if (signature !== before) {
            payAlerts = list;
            publish();
        }
    }

    function hideBanner() {
        if (banner) { banner.remove(); banner = null; }
    }

    function showBanner(ready) {
        var numbers = ready.map(function (r) { return "#" + esc(r.number); }).join(", ");
        var text = ready.length === 1
            ? "Your order " + numbers + " is ready for pick-up!"
            : "Your orders " + numbers + " are ready for pick-up!";

        if (!banner) {
            banner = document.createElement("div");
            banner.className = "ready-banner";
            banner.setAttribute("role", "alert");
            document.body.appendChild(banner);
        }

        banner.innerHTML =
            '<span class="ready-ico" aria-hidden="true"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16z"/><path d="M10 21a2 2 0 0 0 4 0"/></svg></span>' +
            '<span class="ready-text">' + text + "</span>" +
            (onOrdersPage ? "" : '<a class="ready-link" href="my_orders.php">View</a>') +
            '<button type="button" class="ready-close" aria-label="Dismiss">&times;</button>';

        banner.querySelector(".ready-close").addEventListener("click", function () {
            hideBanner();
            markSeen().then(function () { setBadge(0); });
        });

        chime();
    }

    function check() {
        fetch("my_orders_data.php?summary=1", { credentials: "same-origin", cache: "no-store" })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.success) return;

                // Unpaid GCash orders: remember when their payment time and late window end.
                lateMinutes = data.late_minutes || lateMinutes;
                awaiting = {};

                var fetchedAt = Date.now();

                (data.awaiting || []).forEach(function (o) {
                    awaiting[o.id] = {
                        id: o.id,
                        number: o.number,
                        receipt: !!o.receipt_submitted,
                        payExpires: fetchedAt + (Number(o.pay_seconds_left) || 0) * 1000,
                        cancelExpires: fetchedAt + (Number(o.cancel_seconds_left) || 0) * 1000
                    };
                });

                lastReady = data.ready;
                checkPayment();

                // Tell the top bar's bell and badge what is ready / unpaid (js/customer_bar.js).
                publish();

                if (!data.ready.length) { hideBanner(); return; }

                var fresh = data.ready.some(function (r) { return !shown[r.id]; });
                data.ready.forEach(function (r) { shown[r.id] = true; });
                if (fresh || !banner) showBanner(data.ready);

                // On the My Orders page the customer is already looking at it.
                if (onOrdersPage && window.refreshMyOrders) window.refreshMyOrders();
            })
            .catch(function () {});
    }

    window.customerNotifyAcknowledge = function () {
        hideBanner();
        return markSeen().then(function () { setBadge(0); });
    };

    check();
    setInterval(check, POLL_MS);
    setInterval(checkPayment, 1000);
})();
