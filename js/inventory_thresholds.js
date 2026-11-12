/**
 * Inventory page: "Edit limits" (admin only).
 * Opens the low-stock limits dialog, saves them with inventory_thresholds_save.php,
 * then reloads so the table, the cards and the sidebar badge all use the new limits.
 */
(function () {
    'use strict';

    var openButton = document.getElementById('editThresholdsBtn');
    var modal = document.getElementById('thresholdsModal');
    var form = document.getElementById('thresholdsForm');
    var cancelButton = document.getElementById('cancelThresholds');
    var saveButton = document.getElementById('saveThresholds');
    var message = document.getElementById('thresholdsMessage');

    if (!openButton || !modal || !form) return;

    var initial = {};

    function setMessage(text) {
        message.textContent = text || '';
        message.hidden = !text;
    }

    function open() {
        // Show the saved numbers again if the dialog was closed without saving.
        form.querySelectorAll('input').forEach(function (input) {
            if (!(input.name in initial)) initial[input.name] = input.value;
            input.value = initial[input.name];
        });

        setMessage('');
        modal.classList.remove('hidden');

        var first = form.querySelector('input');
        if (first) first.focus();
    }

    function close() {
        modal.classList.add('hidden');
        openButton.focus();
    }

    openButton.addEventListener('click', open);
    cancelButton.addEventListener('click', close);

    modal.addEventListener('mousedown', function (event) {
        if (event.target === modal) close();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) close();
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (saveButton.disabled) return;

        // Quick checks; the server checks everything again.
        var inputs = Array.prototype.slice.call(form.querySelectorAll('input'));

        for (var i = 0; i < inputs.length; i++) {
            var value = inputs[i].value.trim();

            if (value === '' || isNaN(Number(value)) || Number(value) < 0) {
                setMessage('Enter 0 or more for every unit.');
                inputs[i].focus();
                return;
            }
        }

        saveButton.disabled = true;
        setMessage('');

        fetch('inventory_thresholds_save.php', { method: 'POST', body: new FormData(form) })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    throw new Error(result.data.message || 'Unable to save the limits.');
                }

                setMessage('');
                window.location.reload();
            })
            .catch(function (error) {
                setMessage(error.message || 'Unable to save the limits.');
                saveButton.disabled = false;
            });
    });
})();
