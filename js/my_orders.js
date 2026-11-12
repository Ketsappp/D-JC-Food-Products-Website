(function () {
    "use strict";

    var list = document.getElementById("ordersList");
    var STEPS = ["Received", "Preparing", "Ready", "Picked up"];
    var firstLoad = true;
    var payMinutes = 15;      // updated from the server on every refresh
    var lateMinutes = 5;      // extra minutes for a late payment (also from the server)
    var lastOrders = [];

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    function money(n) {
        return "₱" + Number(n).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // 754 -> "12:34"
    function clock(totalSeconds) {
        var s = Math.max(0, Math.floor(totalSeconds));
        var m = Math.floor(s / 60);
        return (m < 10 ? "0" : "") + m + ":" + (s % 60 < 10 ? "0" : "") + (s % 60);
    }

    // The text of the "waiting for payment" box. Two stages:
    //   1. normal payment time ("Time left to pay")
    //   2. late-payment window, after the normal time ran out
    function payNoteInner(order, payLeft, cancelLeft) {
        var head;
        var text;

        if (payLeft > 0) {
            head = '<span class="pay-timer-line">Time left to pay: <strong class="pay-timer' +
                (payLeft <= 120 ? " is-urgent" : "") + '">' + clock(payLeft) + '</strong></span>';

            text = order.receipt_submitted
                ? '<span class="gm-received">Receipt received &mdash; our staff are verifying your payment.</span><br>' +
                  'Your order starts once they confirm it. You can send the receipt again if something was wrong.'
                : 'We haven&rsquo;t received your GCash payment yet. Scan our GCash QR code, pay, then send us ' +
                  'a picture of your receipt. Your order starts once our staff confirm it. ' +
                  'If you run out of time you get ' + lateMinutes + ' more minutes for a late payment, ' +
                  'then the order is cancelled automatically.';
        } else {
            head = '<span class="pay-timer-line">The payment time is over. Late payment window: ' +
                '<strong class="pay-timer is-urgent">' + clock(cancelLeft) + '</strong> left</span>';

            text = order.receipt_submitted
                ? '<span class="gm-received">Receipt received &mdash; our staff are verifying your payment.</span><br>' +
                  'Please wait for our staff to confirm it. When this time ends, the order is cancelled automatically.'
                : 'If you have already paid, send your receipt (or show your payment confirmation to our staff) right now. ' +
                  'When this time ends, the order is cancelled automatically.';
        }

        return head + text;
    }

    function stepper(order) {
        // Waiting for the GCash payment: show how long is left to pay.
        if (order.step === 0) {
            var now = Date.now();
            var payExpires = now + (Number(order.pay_seconds_left) || 0) * 1000;
            var cancelExpires = now + (Number(order.cancel_seconds_left) || 0) * 1000;

            return '<p class="order-note pay-note' + (payExpires <= now ? " is-late" : "") + '" ' +
                'data-order-id="' + Number(order.id) + '" ' +
                'data-pay-expires="' + payExpires + '" data-cancel-expires="' + cancelExpires + '">' +
                payNoteInner(order, (payExpires - now) / 1000, (cancelExpires - now) / 1000) + '</p>' +
                '<button type="button" class="gm-order-btn' + (order.receipt_submitted ? ' is-secondary' : '') + '" data-gm-pay="' + Number(order.id) + '">' +
                (order.receipt_submitted ? 'Send receipt again' : 'Pay with GCash QR') + '</button>';
        }

        // Voided: the payment time (and the late-payment window) ran out.
        if (order.step === -1) {
            return '<p class="order-note cancelled-note">This order was cancelled because the GCash payment was not ' +
                'completed (it was cancelled at checkout, or not paid within ' + payMinutes + ' minutes plus ' +
                lateMinutes + ' minutes for late payments). ' +
                'You have not been charged. You can place it again anytime.</p>';
        }

        return '<ol class="stepper">' + STEPS.map(function (label, i) {
            var n = i + 1;
            var cls = n < order.step ? "done" : (n === order.step ? "current" : "");
            return '<li class="' + cls + '"><span class="dot">' + (n < order.step ? "✓" : n) + "</span><span>" + label + "</span></li>";
        }).join("") + "</ol>";
    }

    // "Order Again": the menu page puts this order back in the cart.
    function reorderUrl(order) {
        return "customerorder.php?reorder=" + encodeURIComponent(order.id);
    }

    function paymentText(order) {
        if (order.status_key === "awaiting") return "GCash &middot; awaiting payment";
        if (order.status_key === "cancelled") return "Not paid";
        return "Paid via GCash";
    }

    function card(order) {
        var items = order.items.map(function (i) {
            return "<li><span class=\"qty\">" + i.qty + "×</span> " + esc(i.name) + "</li>";
        }).join("");

        var ready = order.status_key === "ready"
            ? '<p class="ready-note"><svg class="a11y-ico-inline" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16z"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>Your order is ready! Please pick it up at the counter.</p>' : "";

        return '<article class="order-card status-' + order.status_key + '">' +
            '<header><h2>Order #' + esc(order.number) + '</h2>' +
            '<span class="status-pill status-' + order.status_key + '">' + esc(order.status_label) + "</span></header>" +
            '<p class="order-date">' + esc(order.created_at) + "</p>" +
            ready + stepper(order) +
            '<ul class="order-items">' + items + "</ul>" +
            (order.status_key === "done" || order.status_key === "cancelled"
                ? '<a class="order-again-btn" data-store-link data-open-label="Order Again" data-open-href="' + esc(reorderUrl(order)) +
                  '" href="' + esc(reorderUrl(order)) + '">Order Again</a>' : "") +
            '<footer><span>' + paymentText(order) + '</span><strong>' + money(order.total) + "</strong></footer></article>";
    }

    function load() {
        return fetch("my_orders_data.php", { credentials: "same-origin", cache: "no-store" })
            .then(function (r) {
                if (r.status === 401) { window.location.href = "landing_page.php?auth=login&next=my_orders.php"; return null; }
                return r.json();
            })
            .then(function (data) {
                if (!data || !data.success) return;

                payMinutes = data.pay_minutes || payMinutes;
                lateMinutes = data.late_minutes || lateMinutes;
                lastOrders = data.orders;

                if (!data.orders.length) {
                    list.innerHTML = '<div class="empty-box"><p>You haven&rsquo;t placed any orders yet.</p>' +
                        '<a class="btn-order" data-store-link data-open-label="Browse the menu" data-open-href="customerorder.php" href="customerorder.php">Browse the menu</a></div>';
                } else {
                    list.innerHTML = data.orders.map(card).join("");
                }

                // Order Again / Browse the menu follow the store's open / closed state.
                if (window.applyStoreState) window.applyStoreState();

                // Opening the page counts as seeing the notification.
                if (firstLoad && data.ready.length && window.customerNotifyAcknowledge) {
                    firstLoad = false;
                    setTimeout(window.customerNotifyAcknowledge, 6000);
                }
                firstLoad = false;
            })
            .catch(function () {
                list.innerHTML = '<p class="muted">We couldn&rsquo;t load your orders. Please try again.</p>';
            });
    }

    // Every second: update the countdown of each "waiting for payment" box.
    // When the normal time ends it switches to the late-payment window; when
    // that ends too, the server is asked (it cancels the order).
    var expiredReload = false;
    var lastExpiredReload = 0;

    setInterval(function () {
        var notes = document.querySelectorAll(".pay-note[data-order-id]");
        var anyExpired = false;

        notes.forEach(function (el) {
            var now = Date.now();
            var payLeft = (Number(el.getAttribute("data-pay-expires")) - now) / 1000;
            var cancelLeft = (Number(el.getAttribute("data-cancel-expires")) - now) / 1000;
            var id = Number(el.getAttribute("data-order-id"));
            var order = lastOrders.filter(function (o) { return o.id === id; })[0];

            if (!order) return;

            el.classList.toggle("is-late", payLeft <= 0);
            el.innerHTML = payNoteInner(order, payLeft, cancelLeft);

            if (cancelLeft <= 0) anyExpired = true;
        });

        // The late-payment window ran out: ask the server (it cancels the order) right away.
        // (At most once every 5 seconds, so a slow server is never hammered.)
        if (anyExpired && !expiredReload && Date.now() - lastExpiredReload > 5000) {
            expiredReload = true;
            lastExpiredReload = Date.now();
            load().then(function () { expiredReload = false; });
        }
    }, 1000);

    // "Pay with GCash QR" / "Send receipt again" on an order that is still unpaid.
    list.addEventListener("click", function (event) {
        var button = event.target.closest("[data-gm-pay]");
        if (!button || !window.GcashManualPay) return;

        var id = Number(button.getAttribute("data-gm-pay"));
        var order = lastOrders.filter(function (o) { return o.id === id; })[0];
        if (!order) return;

        window.GcashManualPay.open({
            orderId: order.id,
            orderNumber: order.number,
            total: order.total,
            secondsLeft: order.pay_seconds_left,
            cancelSecondsLeft: order.cancel_seconds_left,
            intro: false,
            onSent: load,
            onFinish: load
        });
    });

    window.refreshMyOrders = load;
    load();
    setInterval(load, 10000);
})();
