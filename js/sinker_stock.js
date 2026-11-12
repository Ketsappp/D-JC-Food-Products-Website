/* Sinker stock on the order screens (staff Menu and customer Menu).
   Tapioca Pearls, Nata Jelly, Coffee Jelly and Rainbow Jelly are inventory items kept in kg (the server converts to grams).
   A sinker button is disabled - and marked "Sold out" - only when the inventory cannot cover one portion.
   Works with the existing customize dialog; it does not change how an order is built.
   Stock is re-read every time the dialog opens; the server checks again when the order is placed. */
(function () {
    'use strict';

    var modal = document.getElementById('customizeModal');
    var sinkerBox = document.getElementById('sinkerOptions');
    var extraBox = document.getElementById('extraSinkerOptions');
    if (!modal || !sinkerBox || !extraBox) return;

    var stock = {};          // lower-case sinker name -> { available, grams }
    var portion = 10;        // grams per sinker (the server sends the real value)
    var loaded = false;
    var queued = false;

    function key(name) { return String(name || '').replace(/\s+/g, ' ').trim().toLowerCase(); }
    function nameOf(button) {
        return button.dataset.name || button.textContent.replace(/\(.*?\)/g, '');
    }

    function chosenSinker() {
        var active = sinkerBox.querySelector('.option-card.active');
        var k = active ? key(nameOf(active)) : '';
        return k && k !== 'no sinkers' ? k : '';
    }

    function setState(button, soldOut, disabled) {
        if (button.classList.contains('sold-out') !== soldOut) button.classList.toggle('sold-out', soldOut);
        if (button.classList.contains('disabled') !== disabled) button.classList.toggle('disabled', disabled);
        if (button.disabled !== disabled) button.disabled = disabled;
        var aria = disabled ? 'true' : null;
        if (button.getAttribute('aria-disabled') !== aria) {
            if (aria) button.setAttribute('aria-disabled', aria); else button.removeAttribute('aria-disabled');
        }
        var title = soldOut ? 'Sold out' : null;
        if (button.getAttribute('title') !== title) {
            if (title) button.setAttribute('title', title); else button.removeAttribute('title');
        }
        if (disabled && button.classList.contains('active')) button.classList.remove('active');
    }

    function apply() {
        if (!loaded) return;
        queued = false;

        var chosen = chosenSinker();

        // 1. the main sinker choice
        sinkerBox.querySelectorAll('.option-card').forEach(function (button) {
            var s = stock[key(nameOf(button))];
            if (!s) return;                                   // "No Sinkers" never runs out
            setState(button, !s.available, !s.available);
        });

        // 2. "Add a Sinker": needs a chosen sinker first (the page's own rule) and enough stock;
        //    the same sinker twice needs two portions
        extraBox.querySelectorAll('.option-card').forEach(function (button) {
            var k = key(nameOf(button));
            var s = stock[k];
            if (!s) return;
            var need = (chosen && k === chosen) ? 2 : 1;
            var soldOut = !s.available || s.grams + 0.0005 < need * portion;
            setState(button, soldOut, soldOut || !chosen);
        });

        // 3. the selected sinker ran out while the dialog was open -> back to "No Sinkers"
        var active = sinkerBox.querySelector('.option-card.active');
        if (active && active.classList.contains('sold-out')) {
            var none = sinkerBox.querySelector('.option-card[data-name="No Sinkers"]') || sinkerBox.querySelector('.option-card');
            if (none && none !== active) none.click();
        }
        if (typeof currentSelection !== 'undefined' && currentSelection.extraSinkerName) {
            var extra = extraBox.querySelector('.option-card.active');
            if (!extra) { currentSelection.extraSinkerName = null; currentSelection.extraSinkerPrice = 0; }
        }
    }

    function schedule() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(apply);
    }

    function refresh() {
        return fetch('get_sinkers.php', { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !Array.isArray(data.sinkers)) return;
                portion = Number(data.portion_grams) || portion;
                stock = {};
                data.sinkers.forEach(function (s) { stock[key(s.name)] = { available: !!s.available, grams: Number(s.grams) || 0 }; });
                loaded = true;
                apply();
            })
            .catch(function () { /* offline: leave the buttons as they are; the server still checks the order */ });
    }

    // the page enables / disables the extras and moves the "active" mark itself - re-apply after it does
    var watcher = new MutationObserver(schedule);
    [sinkerBox, extraBox].forEach(function (box) {
        watcher.observe(box, { subtree: true, attributes: true, attributeFilter: ['class', 'disabled'] });
    });

    // fresh stock every time the dialog opens
    new MutationObserver(function () {
        if (modal.classList.contains('show')) refresh();
    }).observe(modal, { attributes: true, attributeFilter: ['class'] });

    refresh();
})();
