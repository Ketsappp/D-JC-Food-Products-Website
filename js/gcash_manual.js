/**
 * Manual GCash payment for customers (markup: php/gcash_manual_modal.php).
 *
 *   GcashManualPay.open({
 *       orderId, orderNumber, total, secondsLeft, cancelSecondsLeft,
 *       intro: true,          // first show "PayMongo is unavailable"
 *       receiptSent: false,   // already uploaded once (re-sending replaces it)
 *       onClose: fn,          // closed with "I'll do this later" / X / Esc
 *       onFinish: fn          // receipt sent, "View my orders" pressed
 *   });
 *
 * Steps: alert -> scan the QR code -> upload receipt + name + reference number.
 */
(function () {
    'use strict';

    var $ = function (id) { return document.getElementById(id); };

    var alertBox = $('gmAlert');
    var pay = $('gmPay');
    if (!alertBox || !pay) return;

    var dialog = pay.querySelector('.gm-dialog');
    var stepQr = $('gmStepQr');
    var stepReceipt = $('gmStepReceipt');
    var stepDone = $('gmStepDone');
    var fileInput = $('gmFile');
    var preview = $('gmPreview');
    var dropText = $('gmDropText');
    var nameInput = $('gmName');
    var refInput = $('gmRef');
    var submitBtn = $('gmSubmit');

    var opts = {};
    var timer = null;
    var expiresAt = 0;          // end of the normal payment time
    var cancelAt = 0;           // end of the late-payment window (order cancelled)
    var chosenFile = null;
    var lastFocus = null;

    function money(n) {
        return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function clock(seconds) {
        var s = Math.max(0, Math.floor(seconds));
        var m = Math.floor(s / 60);
        return (m < 10 ? '0' : '') + m + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
    }

    // ---------- countdown ----------
    // Three stages: normal payment time -> late-payment window -> cancelled.
    function tick() {
        var now = Date.now();
        var left = Math.max(0, Math.round((expiresAt - now) / 1000));
        var cancelLeft = Math.max(0, Math.round((cancelAt - now) / 1000));
        var line = $('gmTimerLine');

        if (left > 0) {
            line.classList.remove('is-late');
            line.innerHTML = 'Time left to pay: <strong id="gmTimer">' + clock(left) + '</strong>';
        } else if (cancelLeft > 0) {
            line.classList.add('is-late');
            line.innerHTML = 'Payment time is over. Late payment window: <strong id="gmTimer">' + clock(cancelLeft) +
                '</strong> left. If you already paid, send your receipt now.';
        } else {
            line.classList.add('is-late');
            line.innerHTML = 'Time is up. This order is cancelled. If you already paid, please show your receipt to our staff.';
            clearInterval(timer);
        }
    }

    function setStep(name) {
        stepQr.hidden = name !== 'qr';
        stepReceipt.hidden = name !== 'receipt';
        stepDone.hidden = name !== 'done';

        pay.querySelector('.gm-steps').hidden = name === 'done';
        pay.querySelectorAll('[data-gm-step]').forEach(function (li) {
            var n = Number(li.getAttribute('data-gm-step'));
            var current = (name === 'qr' && n === 1) || (name === 'receipt' && n === 2);

            li.classList.toggle('current', current);
            li.classList.toggle('done', name === 'receipt' && n === 1);
        });

        var focusTarget = name === 'qr' ? $('gmPaid') : (name === 'receipt' ? fileInput.parentNode : $('gmDone'));
        if (focusTarget && focusTarget.focus) setTimeout(function () { focusTarget.focus(); }, 30);
    }

    // ---------- open / close ----------
    function showPay() {
        alertBox.hidden = true;
        pay.hidden = false;
        $('gmOrderNo').textContent = opts.orderNumber;
        $('gmOrderNo2').textContent = opts.orderNumber;
        $('gmAmount').textContent = money(opts.total);

        // Fresh copy of the QR code (the admin may have just replaced it).
        var img = $('gmQrImg');
        img.onload = function () { img.hidden = false; $('gmQrMissing').hidden = true; };
        img.onerror = function () { img.hidden = true; $('gmQrMissing').hidden = false; };
        img.src = 'gcash_qr.php?v=' + Date.now();

        resetForm();
        expiresAt = Date.now() + (Number(opts.secondsLeft) || 0) * 1000;
        cancelAt = Date.now() + (Number(opts.cancelSecondsLeft) || (Number(opts.secondsLeft) || 0)) * 1000;
        clearInterval(timer);
        $('gmTimerLine').innerHTML = 'Time left to pay: <strong id="gmTimer">--:--</strong>';
        tick();
        timer = setInterval(tick, 1000);

        setStep('qr');
    }

    function closePay(callback) {
        clearInterval(timer);
        pay.hidden = true;
        alertBox.hidden = true;

        if (lastFocus && lastFocus.focus) lastFocus.focus();
        if (typeof callback === 'function') callback();
    }

    function open(options) {
        opts = options || {};
        lastFocus = document.activeElement;

        if (opts.intro) {
            alertBox.hidden = false;
            setTimeout(function () { $('gmAlertOk').focus(); }, 30);
        } else {
            showPay();
        }
    }

    $('gmAlertOk').addEventListener('click', showPay);
    $('gmClose').addEventListener('click', function () { closePay(opts.onClose); });
    $('gmLater').addEventListener('click', function () { closePay(opts.onClose); });
    $('gmPaid').addEventListener('click', function () { setStep('receipt'); });
    $('gmBack').addEventListener('click', function () { setStep('qr'); });
    $('gmDone').addEventListener('click', function () {
        closePay(opts.onFinish || function () { window.location.href = 'my_orders.php'; });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !pay.hidden && stepDone.hidden) closePay(opts.onClose);

        // keep Tab inside the open window
        if (event.key === 'Tab') {
            var box = !alertBox.hidden ? alertBox.querySelector('.gm-dialog') : (!pay.hidden ? dialog : null);
            if (!box) return;

            var items = Array.prototype.filter.call(
                box.querySelectorAll('button, input, a[href]'),
                function (el) { return !el.disabled && el.offsetParent !== null || el === fileInput; }
            );
            if (!items.length) return;

            var first = items[0];
            var last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });

    // ---------- form ----------
    function fieldBox(input) { return input.closest('.gm-field'); }

    function setError(id, input, message) {
        var el = $(id);
        el.textContent = message || '';
        el.hidden = !message;
        fieldBox(input).classList.toggle('is-invalid', !!message);
    }

    function resetForm() {
        chosenFile = null;
        stepReceipt.reset();
        preview.hidden = true;
        preview.removeAttribute('src');
        dropText.hidden = false;
        ['gmErrFile', 'gmErrName', 'gmErrRef', 'gmErrForm'].forEach(function (id) { $(id).hidden = true; });
        pay.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send receipt';
    }

    function fileProblem(file) {
        if (!file) return 'Please choose a picture of your receipt.';
        if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) return 'Please use a JPG, PNG or WEBP picture.';
        return '';
    }

    function nameProblem(v) {
        v = v.trim();
        if (!v) return 'Please enter the name on your GCash account.';
        if (/\d/.test(v)) return 'The name can’t contain numbers.';
        if (!/^[\p{L}][\p{L}\s.'\-]{1,99}$/u.test(v)) return 'Use letters, spaces, periods, apostrophes or hyphens only.';
        return '';
    }

    function refProblem(v) {
        var digits = v.replace(/[\s\-]/g, '');
        if (!digits) return 'Please enter the reference number from your receipt.';
        if (!/^\d+$/.test(digits)) return 'The reference number has digits only.';
        if (digits.length < 10 || digits.length > 20) return 'The reference number should be 10 to 20 digits.';
        return '';
    }

    fileInput.addEventListener('change', function () {
        chosenFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

        var problem = fileProblem(chosenFile);
        setError('gmErrFile', fileInput, chosenFile ? problem : '');

        if (chosenFile && !problem) {
            preview.src = URL.createObjectURL(chosenFile);
            preview.hidden = false;
            dropText.hidden = true;
        } else {
            preview.hidden = true;
            dropText.hidden = false;
            if (chosenFile) chosenFile = null;
        }
    });

    nameInput.addEventListener('input', function () { setError('gmErrName', nameInput, nameProblem(nameInput.value)); });
    nameInput.addEventListener('blur', function () { setError('gmErrName', nameInput, nameProblem(nameInput.value)); });
    refInput.addEventListener('input', function () {
        // digits with spaces in between: 1234 567 890123
        refInput.value = refInput.value.replace(/[^\d\s\-]/g, '');
        setError('gmErrRef', refInput, refProblem(refInput.value));
    });
    refInput.addEventListener('blur', function () { setError('gmErrRef', refInput, refProblem(refInput.value)); });

    // Phone photos are big; shrink them before sending (keeps uploads fast and under the server limit).
    function shrink(file) {
        return new Promise(function (resolve) {
            if (file.size < 1200 * 1024) { resolve(file); return; }

            var url = URL.createObjectURL(file);
            var img = new Image();

            img.onload = function () {
                var scale = Math.min(1, 1600 / Math.max(img.width, img.height));
                var canvas = document.createElement('canvas');

                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);

                canvas.toBlob(function (blob) {
                    resolve(blob && blob.size < file.size
                        ? new File([blob], 'receipt.jpg', { type: 'image/jpeg' })
                        : file);
                }, 'image/jpeg', 0.85);
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    }

    stepReceipt.addEventListener('submit', function (event) {
        event.preventDefault();

        var fp = fileProblem(chosenFile);
        var np = nameProblem(nameInput.value);
        var rp = refProblem(refInput.value);

        setError('gmErrFile', fileInput, fp);
        setError('gmErrName', nameInput, np);
        setError('gmErrRef', refInput, rp);
        $('gmErrForm').hidden = true;

        if (fp || np || rp) {
            (fp ? fileInput.parentNode : (np ? nameInput : refInput)).focus();
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending...';

        shrink(chosenFile).then(function (file) {
            var body = new FormData();

            body.append('order_id', opts.orderId);
            body.append('sender_name', nameInput.value.trim());
            body.append('reference', refInput.value);
            body.append('receipt', file, file.name || 'receipt.jpg');

            return fetch('submit_gcash_receipt.php', { method: 'POST', body: body, credentials: 'same-origin' });
        })
            .then(function (response) {
                return response.json().then(function (data) { return { ok: response.ok, data: data }; });
            })
            .then(function (result) {
                if (!result.ok || !result.data.success) throw new Error(result.data.message || 'Could not send the receipt.');

                clearInterval(timer);
                setStep('done');
                if (typeof opts.onSent === 'function') opts.onSent();
            })
            .catch(function (error) {
                var message = error && error.message ? error.message : 'Could not send the receipt.';

                // Put a reference-number problem next to its field.
                if (/reference/i.test(message) && !/receipt/i.test(message)) {
                    setError('gmErrRef', refInput, message);
                } else {
                    $('gmErrForm').textContent = message;
                    $('gmErrForm').hidden = false;
                }

                submitBtn.disabled = false;
                submitBtn.textContent = 'Send receipt';
            });
    });

    window.GcashManualPay = { open: open };
})();
