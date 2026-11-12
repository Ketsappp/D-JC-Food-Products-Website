/* D&JC admin: mobile drawer navigation.
   Works with the markup emitted by php/admin_sidebar.php.
   Opens from the hamburger; closes on scrim click, Esc, the close button,
   choosing a link, pressing Log out, or growing the window past 768px. */
(function () {
    'use strict';

    var mq = window.matchMedia('(max-width: 768px)');
    var body = document.body;
    var drawer = document.getElementById('adminSidebar');
    var toggle = document.getElementById('drawerToggle');
    var scrim = document.getElementById('drawerScrim');
    var closeBtn = document.getElementById('drawerClose');
    if (!drawer || !toggle) return;

    function isOpen() { return body.classList.contains('drawer-open'); }

    function focusables() {
        return Array.prototype.slice.call(
            drawer.querySelectorAll('a[href], button:not([disabled])')
        ).filter(function (el) { return el.offsetParent !== null; });
    }

    function open() {
        if (!mq.matches || isOpen()) return;
        body.classList.add('drawer-open');
        toggle.setAttribute('aria-expanded', 'true');
        // move focus into the drawer (after the visibility transition starts)
        window.setTimeout(function () {
            var active = drawer.querySelector('.nav-item.active') || closeBtn;
            if (active) active.focus();
        }, 30);
    }

    function close(returnFocus) {
        if (!isOpen()) return;
        body.classList.remove('drawer-open');
        toggle.setAttribute('aria-expanded', 'false');
        if (returnFocus) toggle.focus();
    }

    toggle.addEventListener('click', function () { isOpen() ? close(true) : open(); });
    if (scrim) scrim.addEventListener('click', function () { close(true); });
    if (closeBtn) closeBtn.addEventListener('click', function () { close(true); });

    // Esc closes; Tab stays inside the open drawer
    document.addEventListener('keydown', function (e) {
        if (!isOpen()) return;
        if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
        if (e.key !== 'Tab') return;
        var items = focusables();
        if (!items.length) return;
        var first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    // Choosing a page or Log out closes the drawer (logout opens its own modal)
    drawer.addEventListener('click', function (e) {
        var el = e.target.closest('a.nav-item, a.brand, #logoutBtn');
        if (el) close(false);
    });

    // Leaving the phone layout resets everything
    function onChange() { if (!mq.matches) close(false); }
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
})();
