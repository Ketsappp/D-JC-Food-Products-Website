/**
 * Shows every admin notification in the sidebar on EVERY admin page:
 *   Inventory  -> low-stock items
 *   Menu       -> active (not yet completed) orders
 * and the "GCash Orders" button (Menu > Orders) -> GCash payments awaiting confirmation.
 * Other scripts can call window.refreshAdminNotifications() to update immediately.
 */
(function () {
    "use strict";

    var URL = "../php/admin_notifications.php";
    var EVERY_MS = 5000;

    // Badge look (the Inventory page already has .notification-badge; this is the same style).
    var style = document.createElement("style");
    style.textContent =
        ".nav-notify-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;" +
        "padding:0 6px;margin-left:8px;border-radius:50px;background:#dc3545;color:#fff;font-size:12px;" +
        "font-weight:700;line-height:1;vertical-align:middle;flex:0 0 auto}" +
        ".nav-notify-badge[hidden]{display:none}" +
        "#gcashNotificationBadge{margin-left:8px}" +
        // Ordering-page sidebar: keep the badge on the right edge of the link.
        ".category-nav a.category-card{position:relative}" +
        ".category-nav a.category-card .nav-notify-badge{position:absolute;right:10px;top:50%;margin:0;transform:translateY(-50%)}";
    document.head.appendChild(style);

    function setBadge(link, count) {
        var badge = link.querySelector(".nav-notify-badge, .notification-badge");

        if (count <= 0) {
            if (badge) badge.remove();
            return;
        }

        if (!badge) {
            badge = document.createElement("span");
            badge.className = "notification-badge nav-notify-badge";
            link.appendChild(badge);
        }

        badge.textContent = count;
        badge.setAttribute("aria-label", count + " notification" + (count === 1 ? "" : "s"));
    }

    function links(fileName) {
        return document.querySelectorAll(
            '.nav-menu a[href*="' + fileName + '"], .category-nav a[href*="' + fileName + '"]'
        );
    }

    function apply(data) {
        links("inventory.php").forEach(function (a) { setBadge(a, Number(data.low_stock) || 0); });
        links("order.php").forEach(function (a) { setBadge(a, Number(data.active_orders) || 0); });

        var gcash = document.getElementById("gcashNotificationBadge");
        if (gcash) {
            var n = Number(data.gcash_pending) || 0;
            gcash.textContent = n;
            gcash.hidden = n === 0;
        }
    }

    function refresh() {
        return fetch(URL, { cache: "no-store", credentials: "same-origin" })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data && data.success) apply(data); })
            .catch(function () { /* try again on the next tick */ });
    }

    window.refreshAdminNotifications = refresh;

    refresh();
    setInterval(refresh, EVERY_MS);
    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) refresh();
    });
})();
