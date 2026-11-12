/**
 * Store hours page (admin): weekly hours, plus special days (holiday / half day).
 * Talks to store_hours_save.php, then reloads so the status card and lists are fresh.
 */
(function () {
    'use strict';

    var SAVE_URL = 'store_hours_save.php';
    var alertBox = document.getElementById('alertBox');

    // ---------------------------------------------------------------- helpers
    function showAlert(text, type) {
        if (!alertBox) return;
        alertBox.textContent = text;
        alertBox.className = 'alert-box ' + (type || 'success');
        alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function toMinutes(time) {
        var parts = String(time).split(':');
        return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
    }

    function validTime(time) {
        return /^([01]\d|2[0-3]):[0-5]\d$/.test(time || '');
    }

    function post(data) {
        return fetch(SAVE_URL, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.text().then(function (text) {
                var json;
                try { json = JSON.parse(text); } catch (e) { json = null; }
                if (!json) {
                    throw new Error('Your session may have expired. Please refresh the page and log in again.');
                }
                return json;
            });
        });
    }

    // Save, then reload with a message (the status card and lists come from the server).
    function saveAndReload(data, button, errorTarget) {
        var label = button ? button.textContent : '';
        if (button) { button.disabled = true; button.textContent = 'Saving...'; }

        return post(data).then(function (result) {
            if (!result.success) throw new Error(result.message || 'Unable to save.');
            try { sessionStorage.setItem('shFlash', result.message); } catch (e) { /* ignore */ }
            window.location.reload();
        }).catch(function (error) {
            if (button) { button.disabled = false; button.textContent = label; }
            if (errorTarget) {
                errorTarget.textContent = error.message;
                errorTarget.hidden = false;
            } else {
                showAlert(error.message, 'error');
            }
        });
    }

    try {
        var flash = sessionStorage.getItem('shFlash');
        if (flash) {
            sessionStorage.removeItem('shFlash');
            showAlert(flash, 'success');
        }
    } catch (e) { /* ignore */ }

    // ------------------------------------------------------------ weekly hours
    var weeklyForm = document.getElementById('weeklyForm');
    var weeklyMessage = document.getElementById('weeklyMessage');

    if (weeklyForm) {
        weeklyForm.querySelectorAll('.sh-day').forEach(function (row) {
            var toggle = row.querySelector('.sh-open');
            var text = row.querySelector('.sh-switch-text');
            var inputs = row.querySelectorAll('input[type="time"]');

            toggle.addEventListener('change', function () {
                row.classList.toggle('is-off', !toggle.checked);
                text.textContent = toggle.checked ? 'Open' : 'Closed';
                inputs.forEach(function (input) { input.disabled = !toggle.checked; });
                weeklyMessage.hidden = true;
            });
        });

        weeklyForm.addEventListener('input', function () { weeklyMessage.hidden = true; });

        weeklyForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var data = new FormData();
            data.append('action', 'save_weekly');
            var problem = '';

            weeklyForm.querySelectorAll('.sh-day').forEach(function (row) {
                if (problem) return;
                var day = row.dataset.weekday;
                var name = row.querySelector('.sh-dayname').textContent;
                var open = row.querySelector('.sh-open').checked;
                var from = row.querySelector('.sh-from').value;
                var to = row.querySelector('.sh-to-input').value;

                if (open) {
                    if (!validTime(from) || !validTime(to)) {
                        problem = 'Please enter an opening and a closing time for ' + name + '.';
                    } else if (toMinutes(from) >= toMinutes(to)) {
                        problem = name + ': the closing time must be later than the opening time.';
                    }
                }

                data.append('days[' + day + '][open]', open ? '1' : '0');
                data.append('days[' + day + '][from]', from);
                data.append('days[' + day + '][to]', to);
            });

            if (problem) {
                weeklyMessage.textContent = problem;
                weeklyMessage.hidden = false;
                return;
            }

            weeklyMessage.hidden = true;
            saveAndReload(data, document.getElementById('saveWeekly'), weeklyMessage);
        });
    }

    // ------------------------------------------------------------ special days
    var modal = document.getElementById('specialModal');
    var form = document.getElementById('specialForm');
    var dateInput = document.getElementById('spDate');
    var fromInput = document.getElementById('spFrom');
    var toInput = document.getElementById('spTo');
    var noteInput = document.getElementById('spNote');
    var hoursRow = document.getElementById('spHoursRow');
    var specialMessage = document.getElementById('specialMessage');
    var saveSpecial = document.getElementById('saveSpecial');
    var title = document.getElementById('specialModalTitle');
    var sub = document.getElementById('specialModalSub');
    var lastFocus = null;

    if (!modal || !form) return;

    function mode() { return form.querySelector('input[name="mode"]:checked').value; }

    function setMode(value) {
        form.querySelector('input[name="mode"][value="' + value + '"]').checked = true;
        hoursRow.hidden = value !== 'hours';
    }

    function openSpecial(opts) {
        lastFocus = document.activeElement;
        opts = opts || {};
        title.textContent = opts.title || 'Special day';
        sub.textContent = opts.sub || 'Close the store, or give one day different hours.';
        dateInput.value = opts.date || '';
        setMode(opts.mode || 'closed');
        fromInput.value = opts.from || '09:00';
        toInput.value = opts.to || '12:00';
        noteInput.value = opts.note || '';
        specialMessage.hidden = true;
        saveSpecial.disabled = false;
        saveSpecial.textContent = opts.save || 'Save';
        modal.classList.remove('hidden');
        (opts.date ? form.querySelector('input[name="mode"]:checked') : dateInput).focus();
    }

    function closeSpecial() {
        modal.classList.add('hidden');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    // A half-day default: open at the regular time, close 4 hours later.
    function halfDayTimes() {
        var open = form.dataset.regularOpen || '09:00';
        var end = Math.min(toMinutes(open) + 240, 23 * 60 + 59);
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return { from: open, to: pad(Math.floor(end / 60)) + ':' + pad(end % 60) };
    }

    document.getElementById('addSpecial').addEventListener('click', function () {
        openSpecial({ date: form.dataset.tomorrow });
    });

    document.querySelectorAll('[data-quick]').forEach(function (button) {
        button.addEventListener('click', function () {
            var kind = button.dataset.quick;
            if (kind === 'close-today') {
                openSpecial({ title: 'Close the store today?', sub: 'Customers will not be able to order for the rest of today.',
                              date: form.dataset.today, mode: 'closed', save: 'Close today' });
            } else if (kind === 'close-tomorrow') {
                openSpecial({ title: 'Close the store tomorrow?', sub: 'Good for a holiday. You can add a note customers will see.',
                              date: form.dataset.tomorrow, mode: 'closed', save: 'Close tomorrow' });
            } else {
                var times = halfDayTimes();
                openSpecial({ title: 'Half day today', sub: 'Choose the hours the store is open today.',
                              date: form.dataset.today, mode: 'hours', from: times.from, to: times.to, note: 'Half day', save: 'Save half day' });
            }
        });
    });

    document.querySelectorAll('.sh-edit').forEach(function (button) {
        button.addEventListener('click', function () {
            openSpecial({
                title: 'Edit special day',
                date: button.dataset.date,
                mode: button.dataset.mode,
                from: button.dataset.from,
                to: button.dataset.to,
                note: button.dataset.note
            });
        });
    });

    form.querySelectorAll('input[name="mode"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            hoursRow.hidden = mode() !== 'hours';
            specialMessage.hidden = true;
        });
    });

    document.getElementById('cancelSpecial').addEventListener('click', closeSpecial);
    modal.addEventListener('mousedown', function (event) { if (event.target === modal) closeSpecial(); });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (saveSpecial.disabled) return;

        var problem = '';
        var date = dateInput.value;

        if (!date) {
            problem = 'Please choose a date.';
        } else if (date < form.dataset.today) {
            problem = 'That date has already passed. Please choose today or a later date.';
        } else if (date > form.dataset.max) {
            problem = 'Please choose a date within the next year.';
        } else if (mode() === 'hours') {
            if (!validTime(fromInput.value) || !validTime(toInput.value)) {
                problem = 'Please enter an opening and a closing time.';
            } else if (toMinutes(fromInput.value) >= toMinutes(toInput.value)) {
                problem = 'The closing time must be later than the opening time.';
            }
        }

        if (problem) {
            specialMessage.textContent = problem;
            specialMessage.hidden = false;
            return;
        }

        var data = new FormData();
        data.append('action', 'save_day');
        data.append('date', date);
        data.append('mode', mode());
        data.append('from', mode() === 'hours' ? fromInput.value : '');
        data.append('to', mode() === 'hours' ? toInput.value : '');
        data.append('note', noteInput.value.trim());

        saveAndReload(data, saveSpecial, specialMessage);
    });

    // ------------------------------------------------------------ remove a day
    var removeModal = document.getElementById('removeModal');
    var removeText = document.getElementById('removeText');
    var confirmRemove = document.getElementById('confirmRemove');
    var pendingDate = '';

    function openRemove(date, text) {
        lastFocus = document.activeElement;
        pendingDate = date;
        removeText.textContent = text;
        confirmRemove.disabled = false;
        confirmRemove.textContent = 'Remove';
        removeModal.classList.remove('hidden');
        confirmRemove.focus();
    }

    function closeRemove() {
        removeModal.classList.add('hidden');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.querySelectorAll('.sh-remove').forEach(function (button) {
        button.addEventListener('click', function () {
            openRemove(button.dataset.date, button.dataset.label + ' will follow the regular weekly hours again.');
        });
    });

    var reopen = document.getElementById('reopenToday');
    if (reopen) {
        reopen.addEventListener('click', function () {
            openRemove(reopen.dataset.date, 'Today will follow the regular weekly hours again.');
        });
    }

    document.getElementById('cancelRemove').addEventListener('click', closeRemove);
    removeModal.addEventListener('mousedown', function (event) { if (event.target === removeModal) closeRemove(); });

    confirmRemove.addEventListener('click', function () {
        var data = new FormData();
        data.append('action', 'delete_day');
        data.append('date', pendingDate);
        saveAndReload(data, confirmRemove, null).then(function () {
            // only reached when saving failed (success reloads the page)
            if (!removeModal.classList.contains('hidden')) closeRemove();
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (!modal.classList.contains('hidden')) closeSpecial();
        else if (!removeModal.classList.contains('hidden')) closeRemove();
    });
})();
