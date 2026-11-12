<?php
/**
 * Pay-by-QR windows for customers (shared by the menu and My Orders pages).
 * Needs css/gcash_manual.css in the <head>; loads js/gcash_manual.js itself.
 * Opened with GcashManualPay.open({...}) - see js/gcash_manual.js.
 */
require_once __DIR__ . "/gcash_manual.php";
require_once __DIR__ . "/gcash_payment_window.php";

$gmHasQr = gcash_qr_file() !== null;
?>
<!-- 1. Alert: PayMongo is not available -->
<div class="gm-overlay" id="gmAlert" hidden>
    <div class="gm-dialog gm-alert" role="alertdialog" aria-modal="true" aria-labelledby="gmAlertTitle" aria-describedby="gmAlertText" tabindex="-1">
        <div class="gm-alert-icon" aria-hidden="true">!</div>
        <h2 id="gmAlertTitle">GCash checkout is unavailable</h2>
        <p id="gmAlertText">
            We can&rsquo;t reach PayMongo right now, but your order is saved. You can still pay by
            scanning our GCash QR code, then uploading a picture of your receipt.
        </p>
        <button type="button" class="gm-btn gm-primary" id="gmAlertOk">Continue to QR code</button>
    </div>
</div>

<!-- 2. Scan the QR code  /  3. Send the receipt -->
<div class="gm-overlay" id="gmPay" hidden>
    <div class="gm-dialog" role="dialog" aria-modal="true" aria-labelledby="gmPayTitle" tabindex="-1">
        <button type="button" class="gm-close" id="gmClose" aria-label="Close">&times;</button>

        <ol class="gm-steps" aria-label="Steps">
            <li data-gm-step="1" class="current"><span>1</span> Scan &amp; pay</li>
            <li data-gm-step="2"><span>2</span> Send receipt</li>
        </ol>

        <!-- Step 1 -->
        <section id="gmStepQr">
            <h2 id="gmPayTitle">Scan to pay with GCash</h2>
            <p class="gm-sub">Order #<strong id="gmOrderNo"></strong> &middot; Pay exactly
                <strong class="gm-amount" id="gmAmount"></strong></p>

            <div class="gm-qr-box">
                <img id="gmQrImg" src="gcash_qr.php" alt="GCash QR code to scan" width="260" height="260"<?= $gmHasQr ? "" : " hidden" ?>>
                <p class="gm-qr-missing" id="gmQrMissing"<?= $gmHasQr ? " hidden" : "" ?>>
                    The store&rsquo;s GCash QR code isn&rsquo;t available yet. Please ask our staff for help.
                </p>
            </div>

            <ol class="gm-howto">
                <li>Open GCash and tap <strong>Scan QR</strong>.</li>
                <li>Scan the code and pay the exact amount.</li>
                <li>Take a screenshot of the receipt, then press <strong>I&rsquo;ve paid</strong>.</li>
            </ol>

            <p class="gm-timer" id="gmTimerLine">Time left to pay: <strong id="gmTimer">--:--</strong></p>

            <div class="gm-actions">
                <button type="button" class="gm-btn gm-ghost" id="gmLater">I&rsquo;ll do this later</button>
                <button type="button" class="gm-btn gm-primary" id="gmPaid">I&rsquo;ve paid</button>
            </div>
        </section>

        <!-- Step 2 -->
        <form id="gmStepReceipt" novalidate hidden>
            <h2>Send your receipt</h2>
            <p class="gm-sub">Our staff use this to confirm your payment for order #<strong id="gmOrderNo2"></strong>.</p>

            <div class="gm-field">
                <label for="gmFile">Picture of the receipt</label>
                <label class="gm-drop" for="gmFile" id="gmDrop">
                    <input type="file" id="gmFile" name="receipt" accept="image/jpeg,image/png,image/webp">
                    <img id="gmPreview" alt="Preview of your receipt" hidden>
                    <span id="gmDropText">Tap to choose a screenshot or photo<small>JPG, PNG or WEBP</small></span>
                </label>
                <p class="gm-error" id="gmErrFile" role="alert" hidden></p>
            </div>

            <div class="gm-field">
                <label for="gmName">Name on the GCash account</label>
                <input type="text" id="gmName" name="sender_name" maxlength="100" autocomplete="name" placeholder="e.g. Juan Dela Cruz">
                <p class="gm-error" id="gmErrName" role="alert" hidden></p>
            </div>

            <div class="gm-field">
                <label for="gmRef">GCash reference number</label>
                <input type="text" id="gmRef" name="reference" inputmode="numeric" maxlength="30" autocomplete="off" placeholder="e.g. 1234 567 890123">
                <p class="gm-hint">Find it on your receipt under &ldquo;Ref No.&rdquo;</p>
                <p class="gm-error" id="gmErrRef" role="alert" hidden></p>
            </div>

            <p class="gm-error gm-form-error" id="gmErrForm" role="alert" hidden></p>

            <div class="gm-actions">
                <button type="button" class="gm-btn gm-ghost" id="gmBack">Back</button>
                <button type="submit" class="gm-btn gm-primary" id="gmSubmit">Send receipt</button>
            </div>
        </form>

        <!-- Done -->
        <section id="gmStepDone" hidden>
            <div class="gm-done-icon" aria-hidden="true">&#10003;</div>
            <h2>Receipt received</h2>
            <p class="gm-sub">Thank you! Our staff will verify your payment, and your order will start right after. You can follow it in My Orders.</p>
            <div class="gm-actions"><button type="button" class="gm-btn gm-primary" id="gmDone">View my orders</button></div>
        </section>
    </div>
</div>
<script src="../js/gcash_manual.js?v=1"></script>
