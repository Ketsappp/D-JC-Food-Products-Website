/* D&JC accessibility layer.
   - skip-to-content link
   - dialogs: role/aria-modal/label, focus moved in, Tab trapped, Esc closes, focus restored
   - keyboard access for clickable cards (menu products, payment options)
   - accessible names for symbol-only buttons (+ / - / x), live regions for status messages
   Works on dynamically rendered content (menu grid, cart, orders). No dependencies. */
(function () {
    'use strict';

    var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
    var MODAL_SEL = '.modal-overlay, .logout-modal, .gcash-modal';
    var CARD_SEL  = '.modal-card, .logout-modal-card, .gcash-modal-content';
    var CLOSE_SEL = '[data-close],[data-dismiss],.cancel-btn,.btn-cancel,.cancel-logout-btn,.modal-close,.close-btn,.gcash-modal-close,[aria-label="Close"],[aria-label="Dismiss"]';
    var uid = 0;
    var open = new Map();   // modal element -> { last, card }
    var bgInert = [];       // page regions we made inert while a dialog is open
    var restoreTo = null;   // element to refocus once the last dialog closes

    function shown(el) {
        if (!el || el.hidden) return false;
        var s = getComputedStyle(el);
        // some pages hide dialogs with opacity:0 + pointer-events:none only, so check that too
        return s.display !== 'none' && s.visibility !== 'hidden' && s.pointerEvents !== 'none';
    }
    function rendered(el) { return el.getClientRects().length > 0; }
    function focusables(root) {
        return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
            return rendered(el) && getComputedStyle(el).visibility !== 'hidden';
        });
    }

    /* ---------------- skip link ---------------- */
    function skipLink() {
        if (document.querySelector('.skip-link')) return;
        var main = document.querySelector('main');
        if (!main) return;
        if (!main.id) main.id = 'main-content';
        if (!main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1');
        var a = document.createElement('a');
        a.className = 'skip-link';
        a.href = '#' + main.id;
        a.textContent = 'Skip to main content';
        document.body.insertBefore(a, document.body.firstChild);
    }

    /* ---------------- dialogs ---------------- */
    function onOpen(el) {
        var card = el.querySelector(CARD_SEL) || el;
        open.set(el, { last: document.activeElement, card: card });
        if (!card.getAttribute('role') && !el.getAttribute('role')) card.setAttribute('role', 'dialog');
        if (!card.hasAttribute('aria-modal') && !el.hasAttribute('aria-modal')) card.setAttribute('aria-modal', 'true');
        if (!card.hasAttribute('aria-labelledby') && !el.hasAttribute('aria-labelledby') && !card.hasAttribute('aria-label')) {
            var h = card.querySelector('h1,h2,h3,.modal-title');
            if (h) { if (!h.id) h.id = 'dlg-title-' + (++uid); card.setAttribute('aria-labelledby', h.id); }
        }
        if (!card.hasAttribute('tabindex')) card.setAttribute('tabindex', '-1');
        // if the page did not already move focus into the dialog, focus the dialog itself (reads its title; avoids popping the on-screen keyboard on tablets)
        setTimeout(function () { if (open.has(el) && !card.contains(document.activeElement)) card.focus({ preventScroll: true }); }, 30);
    }
    function onClose(el) {
        var rec = open.get(el);
        open.delete(el);
        if (open.size === 0 && rec && rec.last && rec.last !== document.body) restoreTo = rec.last;
    }
    // while a dialog is open, everything outside it is inert (not focusable, hidden from screen readers)
    function updateBackground() {
        bgInert.forEach(function (n) { n.inert = false; });
        bgInert = [];
        if (open.size) {
            var top = Array.from(open.keys()).pop();
            Array.prototype.forEach.call(document.body.children, function (c) {
                if (c === top || c.contains(top) || /^(SCRIPT|STYLE|LINK|NOSCRIPT)$/.test(c.tagName)) return;
                if (open.has(c)) return;
                if (!c.inert) { c.inert = true; bgInert.push(c); }
            });
        } else if (restoreTo) {
            var t = restoreTo; restoreTo = null;
            if (document.contains(t) && typeof t.focus === 'function') t.focus({ preventScroll: true });
        }
    }
    function sync(el) {
        var isOpen = shown(el), was = open.has(el);
        if (isOpen && !was) onOpen(el);
        else if (!isOpen && was) onClose(el);
    }
    function closer(modal) {
        var list = Array.prototype.slice.call(modal.querySelectorAll(CLOSE_SEL)).filter(rendered);
        if (list.length) return list[0];
        return Array.prototype.slice.call(modal.querySelectorAll('button')).filter(rendered).find(function (b) {
            return /^(cancel|close|no|not now)$/i.test((b.textContent || '').trim());
        });
    }
    document.addEventListener('keydown', function (e) {
        if (!open.size) return;
        var top = Array.from(open.keys()).pop();
        var card = open.get(top).card;
        if (e.key === 'Escape') {
            var c = closer(top);
            if (c) { e.preventDefault(); c.click(); }
            return;
        }
        if (e.key !== 'Tab') return;
        var f = focusables(card);
        if (!f.length) { e.preventDefault(); card.focus(); return; }
        var first = f[0], last = f[f.length - 1], a = document.activeElement;
        if (!card.contains(a)) { e.preventDefault(); first.focus(); }
        else if (e.shiftKey && (a === first || a === card)) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && a === last) { e.preventDefault(); first.focus(); }
    });

    /* ---------------- enhancements for (re)rendered content ---------------- */
    function keyboardCard(el) {
        if (el.__a11y) return;
        el.__a11y = true;
        if (el.querySelector('button,a[href],input,select,textarea')) return;   // admin cards already have real buttons
        el.setAttribute('role', 'button');
        el.setAttribute('tabindex', '0');
        el.addEventListener('keydown', function (e) {
            if (e.target !== el) return;
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el.click(); }
        });
    }
    function enhance() {
        document.querySelectorAll('.product-card, .payment-option-card').forEach(keyboardCard);
        document.querySelectorAll('.product-card[role="button"]').forEach(function (c) {
            if (c.classList.contains('unavailable-product')) c.setAttribute('aria-disabled', 'true');
            else c.removeAttribute('aria-disabled');
        });
        document.querySelectorAll('.payment-option-card[role="button"]').forEach(function (c) {
            c.setAttribute('aria-pressed', (c.classList.contains('active') || c.classList.contains('selected')) ? 'true' : 'false');
        });

        document.querySelectorAll('.qty-btn').forEach(function (b) {
            if (b.hasAttribute('aria-label')) return;
            var t = (b.textContent || '').trim();
            var it = b.closest('.cart-item'), n = it && it.querySelector('.cart-item-title');
            var name = n ? n.textContent.trim() : 'item';
            if (t === '+') b.setAttribute('aria-label', 'Increase quantity of ' + name);
            else if (t === '-' || t === '\u2212' || t === '\u2013') b.setAttribute('aria-label', 'Decrease quantity of ' + name);
        });
        document.querySelectorAll('button').forEach(function (b) {
            if (b.hasAttribute('aria-label') || b.hasAttribute('aria-labelledby')) return;
            var t = (b.textContent || '').trim();
            if (t === '\u00D7' || t === '\u2715' || t === '\u2716' || t === 'x' || t === 'X') b.setAttribute('aria-label', 'Close');
        });
        var tg = document.querySelector('.toggle-panel-btn'), layout = document.querySelector('.app-layout');
        if (tg && layout) {
            if (!tg.hasAttribute('aria-label')) tg.setAttribute('aria-label', 'Show or hide the order panel');
            tg.setAttribute('aria-expanded', layout.classList.contains('collapsed') ? 'false' : 'true');
        }
        document.querySelectorAll('#alertBox, .alert-box, #toast, .toast, .ready-banner').forEach(function (a) {
            if (!a.hasAttribute('role')) a.setAttribute('role', 'status');
            if (!a.hasAttribute('aria-live')) a.setAttribute('aria-live', 'polite');
        });
        document.querySelectorAll('.cart-summary-pill').forEach(function (s) {
            if (!s.hasAttribute('aria-live')) { s.setAttribute('aria-live', 'polite'); s.setAttribute('aria-atomic', 'true'); }
        });
    }

    var queued = false;
    function schedule() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(function () { queued = false; enhance(); });
    }

    function init() {
        skipLink();
        // Some pages hide dialogs with opacity:0 only (they stay focusable). Flag that pattern so a11y.css can
        // hide closed ones with visibility - done in CSS so it flips at the same instant the page adds .show.
        var fade = Array.prototype.some.call(document.querySelectorAll('.modal-overlay'), function (m) {
            var s = getComputedStyle(m);
            return s.pointerEvents === 'none' && s.display !== 'none' && !m.hidden && !m.classList.contains('hidden');
        });
        if (fade) document.documentElement.classList.add('a11y-fade-modals');
        document.querySelectorAll(MODAL_SEL).forEach(sync);
        updateBackground();
        enhance();
        new MutationObserver(function (muts) {
            var changed = new Set();
            muts.forEach(function (m) {
                var t = m.target;
                if (m.type === 'attributes' && t.matches && t.matches(MODAL_SEL)) changed.add(t);
                if (m.type === 'childList') {
                    Array.prototype.forEach.call(m.addedNodes, function (n) {
                        if (n.nodeType !== 1) return;
                        if (n.matches(MODAL_SEL)) changed.add(n);
                        if (n.querySelectorAll) n.querySelectorAll(MODAL_SEL).forEach(function (x) { changed.add(x); });
                    });
                }
            });
            changed.forEach(sync);
            if (changed.size) updateBackground();
            schedule();
        }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'hidden', 'style'] });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
