/**
 * Customer top bar: the notification bell and the profile menu (dropdowns).
 * Loaded by php/customer_header.php. Opens on click, closes on Escape, on a
 * click elsewhere, or when the other dropdown opens. The bell list is filled by
 * js/customer_notify.js (orders that are ready for pick-up).
 */
(function () {
    'use strict';

    var menus = [
        { box: document.getElementById('cbarNotify'), button: document.getElementById('cbarBell'), panel: document.getElementById('cbarBellPanel') },
        { box: document.getElementById('cbarProfileMenu'), button: document.getElementById('cbarProfileBtn'), panel: document.getElementById('cbarProfilePanel') }
    ].filter(function (m) { return m.box && m.button && m.panel; });

    function setOpen(menu, open, returnFocus) {
        menu.panel.hidden = !open;
        menu.button.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (!open && returnFocus) menu.button.focus();
    }

    function closeAll(except, returnFocus) {
        menus.forEach(function (m) {
            if (m !== except && !m.panel.hidden) setOpen(m, false, returnFocus && m.panel.contains(document.activeElement));
        });
    }

    menus.forEach(function (menu) {
        menu.button.addEventListener('click', function () {
            var willOpen = menu.panel.hidden;

            closeAll(menu);
            setOpen(menu, willOpen);
        });
    });

    document.addEventListener('click', function (event) {
        menus.forEach(function (m) {
            if (!m.box.contains(event.target)) setOpen(m, false);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;

        menus.forEach(function (m) {
            if (!m.panel.hidden) setOpen(m, false, true);
        });
    });

    // ---- notification list (data comes from customer_notify.js) ----
    var list = document.getElementById('cbarNotes');
    var markRead = document.getElementById('cbarMarkRead');

    function esc(text) {
        return String(text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    window.addEventListener('customer:ready-orders', function (event) {
        if (!list) return;

        var ready = (event.detail && event.detail.ready) || [];
        var pay = (event.detail && event.detail.pay) || [];

        if (!ready.length && !pay.length) {
            list.innerHTML = '<li class="cbar-empty">No new notifications.</li>';
            if (markRead) markRead.hidden = true;
            return;
        }

        // Unpaid GCash orders come first: they are the ones with a clock running.
        var payHtml = pay.map(function (order) {
            var late = order.stage === 'late';

            return '<li><a class="cbar-note" href="my_orders.php"><span class="cbar-note-dot ' + (late ? 'is-late' : 'is-warn') + '" aria-hidden="true"></span>' +
                '<span>Order #' + esc(order.number) + (late
                    ? ': payment time is over. Your late-payment window has started.<small>Pay now, or the order will be cancelled</small>'
                    : ': less than 3 minutes left to pay.<small>Tap to pay for your order</small>') +
                '</span></a></li>';
        }).join('');

        var readyHtml = ready.map(function (order) {
            return '<li><a class="cbar-note" href="my_orders.php"><span class="cbar-note-dot" aria-hidden="true"></span>' +
                '<span>Order #' + esc(order.number) + ' is ready for pick-up!<small>Tap to view your order</small></span></a></li>';
        }).join('');

        list.innerHTML = payHtml + readyHtml;

        // "Mark all as read" only clears ready orders; payment reminders go away when the order is paid.
        if (markRead) markRead.hidden = !ready.length;
    });

    if (markRead) {
        markRead.addEventListener('click', function () {
            if (window.customerNotifyAcknowledge) window.customerNotifyAcknowledge();
        });
    }
})();

/**
 * Cart count on the header's cart icon.
 *
 * The menu page keeps the customer's cart in sessionStorage (key "djcCart") so the number
 * also shows on Home, My Orders and Profile, and survives a page reload. sessionStorage is
 * per browser tab and is cleared when the tab closes. If storage is blocked the badge
 * simply stays hidden.
 *
 *   window.djcCart.read()          -> array of cart lines
 *   window.djcCart.write(cart)     -> saves them and refreshes the badge
 *   window.djcCart.bump()          -> little "added" animation on the icon
 */
(function () {
    'use strict';

    var KEY = 'djcCart';
    var MAX_AGE = 3 * 60 * 60 * 1000;   // a forgotten cart is dropped after 3 hours

    function load() {
        try {
            var raw = window.sessionStorage.getItem(KEY);
            if (!raw) return [];
            var data = JSON.parse(raw);
            if (!data || !Array.isArray(data.items) || Date.now() - (data.t || 0) > MAX_AGE) return [];
            return data.items;
        } catch (e) { return []; }
    }

    function count(items) {
        return items.reduce(function (sum, line) { return sum + (Number(line && line.qty) || 0); }, 0);
    }

    function paint(n) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-cart-badge]'), function (badge) {
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.hidden = n <= 0;
        });
        var cart = document.getElementById('cbarCart');
        if (cart) cart.setAttribute('aria-label', n > 0 ? 'Your cart, ' + n + (n === 1 ? ' item' : ' items') : 'Your cart');
    }

    window.djcCart = {
        read: load,
        write: function (items) {
            try {
                if (items && items.length) {
                    window.sessionStorage.setItem(KEY, JSON.stringify({ t: Date.now(), items: items }));
                } else {
                    window.sessionStorage.removeItem(KEY);
                }
            } catch (e) { /* storage blocked: the cart still works, it just is not remembered */ }
            paint(count(items || []));
        },
        bump: function () {
            var icon = document.getElementById('cbarCart');
            if (!icon) return;
            var badge = icon.querySelector('[data-cart-badge]');
            [icon, badge].forEach(function (el) {
                if (!el) return;
                el.classList.remove('is-bump');
                void el.offsetWidth;          // restart the animation
                el.classList.add('is-bump');
            });
        }
    };

    paint(count(load()));
})();
