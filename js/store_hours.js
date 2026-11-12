/**
 * Keeps every "go to the menu" link correct while a customer page is open.
 *
 * The server draws the first version of the page (store_gate.php and
 * store_link_attrs() in store_hours.php) and puts the current state in
 * window.storeStatus. This script then:
 *
 *  - asks store_status.php once a minute, when the customer returns to the
 *    tab, AND exactly when the store is due to open or close (the server
 *    sends the number of seconds, so the customer's own clock does not matter);
 *  - while the store is closed, turns each [data-store-link] element
 *    (Order Now, See the Full Menu, the Menu link, the sneak-peek cards,
 *    Order Again ...) into a "We're closed" message that cannot be clicked;
 *  - puts the normal link and text back when the store opens again;
 *  - reloads the menu page the moment the store closes (and the "We're closed"
 *    page the moment it opens), so the menu is never left open after hours.
 *
 * Markers on an element:
 *   data-store-link      the element is a link to the customer menu
 *   data-open-href       its normal address
 *   data-open-label      its normal text
 *   data-keep-label      keep its own text while closed (Menu link, cards)
 *   data-closed-hide     hide it completely while closed
 *   data-requires-open   a button that is only disabled while closed (Order)
 *
 * The server refuses closed-hours menu requests and orders either way.
 */
(function () {
    'use strict';

    var REFRESH_MS = 60000;
    var timer = null;

    function currentStatus() {
        return window.storeStatus || null;
    }

    function applyToLink(element, status) {
        var closed = !status.open;
        var openHref = element.getAttribute('data-open-href');
        var openLabel = element.getAttribute('data-open-label');

        if (closed) {
            element.removeAttribute('href');
            element.setAttribute('aria-disabled', 'true');
            element.title = status.order_message || '';
        } else {
            if (openHref) element.setAttribute('href', openHref);
            element.removeAttribute('aria-disabled');
            element.removeAttribute('title');
        }

        if (element.hasAttribute('data-closed-hide')) {
            element.hidden = closed;
        }

        if (!element.hasAttribute('data-keep-label') && openLabel) {
            element.textContent = closed ? status.closed_label : openLabel;
        }
    }

    function applyToButton(element, status) {
        var closed = !status.open;

        // Remember the normal label ("Order") so it can come back.
        if (!element.dataset.openLabel) {
            element.dataset.openLabel = element.textContent;
        }

        element.disabled = closed;
        element.classList.toggle('is-store-closed', closed);
        element.textContent = closed ? 'Closed' : element.dataset.openLabel;
        element.title = closed ? status.order_message : '';
    }

    /** Draw the given state on every link and button of the page. */
    function apply(status) {
        window.storeStatus = status;
        window.storeIsOpen = status.open;
        window.storeClosedMessage = status.order_message || '';

        document.querySelectorAll('[data-store-link]').forEach(function (element) {
            applyToLink(element, status);
        });

        document.querySelectorAll('[data-requires-open]').forEach(function (element) {
            applyToButton(element, status);
        });

        // The menu page must not stay open after closing time, and the
        // "We're closed" page should turn into the menu when we open.
        var menuPage = document.body ? document.body.getAttribute('data-menu-page') : null;

        if (menuPage === 'open' && !status.open) {
            window.location.reload();
            return;
        }

        if (menuPage === 'closed' && status.open) {
            window.location.reload();
            return;
        }

        scheduleNextCheck(status);
    }

    /** Check again at the exact second the store opens or closes. */
    function scheduleNextCheck(status) {
        clearTimeout(timer);

        var seconds = status.seconds_until_change;

        if (typeof seconds !== 'number' || seconds < 0) return;

        var wait = (seconds + 1) * 1000;

        // Longer than a timer can hold: the once-a-minute check covers it.
        if (wait > 2147483000) return;

        timer = setTimeout(refresh, wait);
    }

    async function refresh() {
        try {
            var response = await fetch('store_status.php', { cache: 'no-store' });
            var status = await response.json();

            if (status && typeof status.open === 'boolean') {
                apply(status);
            }
        } catch (error) {
            // Offline or a hiccup: keep what is shown; the next check will retry.
        }
    }

    // Links drawn after the page loaded (e.g. "Order Again" on My Orders)
    // call this to take on the current open / closed state.
    window.applyStoreState = function () {
        var status = currentStatus();

        if (status) apply(status);
    };

    window.refreshStoreStatus = refresh;

    // A closed link can never be followed, even if something re-adds an href.
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('[data-store-link][aria-disabled="true"]') : null;

        if (link) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    setInterval(refresh, REFRESH_MS);

    // Check again as soon as the customer comes back to the tab.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') refresh();
    });

    // Start from what the server drew, then confirm with a fresh check.
    window.applyStoreState();
    refresh();
})();
