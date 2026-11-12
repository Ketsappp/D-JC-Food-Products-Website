/* Small helpers for the Add / Edit Inventory and Add / Edit Product dialogs.
   - the header "x" button clicks the form's own Cancel button (so the page's existing close code runs)
   - Inventory: shows the chosen unit next to Quantity; the expiration date can't be before the procurement date */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var x = e.target.closest('[data-click-target]');
        if (!x) return;
        var target = document.getElementById(x.getAttribute('data-click-target'));
        if (target) target.click();
    });

    var modal = document.getElementById('productModal');
    var unit = document.getElementById('productUnit');
    var suffix = document.getElementById('productQuantityUnit');
    var procured = document.getElementById('dateProcured');
    var expires = document.getElementById('expirationDate');
    if (!modal || !unit) return;

    // Date of procurement: this year, up to today (the page prints the allowed dates on the input;
    // the server checks them again). An older item being edited may keep the date it already has.
    function syncProcured() {
        if (!procured || !procured.getAttribute('data-min')) return;

        var minDate = procured.getAttribute('data-min');
        var maxDate = procured.getAttribute('data-max');
        var value = procured.value;
        var keepsSavedDate = value !== '' && value === (procured.getAttribute('data-original') || '');
        var message = '';

        // A kept older date must not trip the browser's own min / max check either.
        procured.min = keepsSavedDate ? '' : minDate;
        procured.max = keepsSavedDate ? '' : maxDate;

        if (value && !keepsSavedDate) {
            if (value > maxDate) {
                message = 'The date of procurement cannot be in the future. Choose today or an earlier date.';
            } else if (value < minDate) {
                message = 'The date of procurement must be in the current year (' + minDate.slice(0, 4) + ').';
            }
        }

        procured.setCustomValidity(message);
    }

    function sync() {
        syncProcured();

        if (suffix) suffix.textContent = unit.value || '';
        if (procured && expires) {
            expires.min = procured.value || '';
            expires.setCustomValidity(expires.value && procured.value && expires.value < procured.value
                ? 'The expiration date cannot be before the procurement date.' : '');
        }
    }
    unit.addEventListener('change', sync);
    if (procured) procured.addEventListener('change', sync);
    if (expires) expires.addEventListener('change', sync);
    // the page fills the fields from script when it opens the dialog (no change event), so re-sync when it opens
    new MutationObserver(sync).observe(modal, { attributes: true, attributeFilter: ['class'] });
    sync();
})();
