<?php
/**
 * Customer menu / ordering page.
 *
 * Same screen as the staff Menu/Order page (order.php) but for customers:
 * no Orders list, no order-status labels, no admin tools, and GCash is the
 * only way to pay. Anyone can browse the menu while the store is open; a
 * customer account is needed to place an order.
 *
 * While the store is CLOSED this page does not show the menu at all - it shows
 * a "We're closed" page instead (see below). Staff and admins are sent to
 * order.php before that check, so they are never blocked.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/store_hours.php";
require_once __DIR__ . "/gcash_payment_window.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// A blocked customer is signed out right away.
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/user_status.php";
drop_session_if_customer_blocked($conn);

// Staff and admins use their own ordering screen.
$role = $_SESSION["role"] ?? "";
if ($role === "admin" || $role === "staff") {
    header("Location: order.php");
    exit;
}

// Store closed: customers (and visitors who are not signed in) cannot open the
// menu. This is checked on the server, so typing the address, using a saved
// bookmark or an old tab does not get around it.
$storeStatus = store_status();
$storeOpen = $storeStatus["open"];

if (!$storeOpen) {
    http_response_code(503);

    if ($storeStatus["seconds_until_change"] !== null) {
        header("Retry-After: " . (int) $storeStatus["seconds_until_change"]);
    }

    $active = "menu";
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We're closed - D&amp;JC Food Products</title>
    <link rel="stylesheet" href="../css/landing.css?v=gt1">
    <link rel="stylesheet" href="../css/customer.css?v=gt1">
<link rel="stylesheet" href="../css/customer_bar.css?v=r5">
<link rel="stylesheet" href="../css/gcash_manual.css?v=1">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body data-menu-page="closed">
<?php $active = "menu"; include __DIR__ . "/customer_header.php"; ?>

<main class="page-main">
    <div class="wrap narrow">
        <div class="empty-box store-closed-box">
            <div class="store-closed-icon" aria-hidden="true">&#128339;</div>
            <h1 class="page-h1">We&rsquo;re closed right now</h1>
            <p><?= htmlspecialchars($storeStatus["order_message"], ENT_QUOTES, "UTF-8") ?></p>
            <a class="btn-order" href="landing_page.php">Back to Home</a>
        </div>
    </div>
</main>
<script src="../js/sinker_stock.js?v=gt3"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
<?php
    exit;
}

$loggedIn = !empty($_SESSION["user_id"]);
$displayName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu - D&amp;JC Food Products</title>
    <link rel="stylesheet" href="../css/order.css?v=gt2">
    <link rel="stylesheet" href="../css/responsive.css?v=4">
    <link rel="stylesheet" href="../css/customer.css?v=gt1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="../css/pos-theme.css?v=gt6">
<link rel="stylesheet" href="../css/customer_bar.css?v=r5">
<link rel="stylesheet" href="../css/categories.css?v=r3">
<link rel="stylesheet" href="../css/menu_storefront.css?v=r3">
<link rel="stylesheet" href="../css/gcash_manual.css?v=1">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body data-menu-page="open">
<?php $active = "menu"; include __DIR__ . "/customer_header.php"; ?>

<div class="app-layout" id="appLayout">

    <!-- Main Content Area -->
    <main class="main-content">
                <section class="menu-section" id="menuSection">
            <div class="menu-hero" id="menuHero">
                <div class="menu-hero-copy">
                    <p class="menu-eyebrow">Order online</p>
                    <h2 class="menu-hero-title">What are you craving today?</h2>
                    <p class="menu-hero-sub">Tap anything to pick your size and add-ons, then pay with GCash.</p>
                </div>
                <ul class="menu-hero-facts" aria-label="Store information">
                    <li class="mh-status is-<?= $storeStatus["closing_soon"] ? "soon" : "open" ?>"><span class="mh-dot" aria-hidden="true"></span><?= htmlspecialchars($storeStatus["title"] . " · " . $storeStatus["message"], ENT_QUOTES, "UTF-8") ?></li>
                    <li><img src="../image/GCash_logo.svg" alt="" loading="lazy"> GCash payment</li>
                    <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg> Pick-up at the store</li>
                </ul>
            </div>

            <div class="menu-section-header">
                <h1 class="section-title" id="sectionTitle">Menu</h1>
            </div>

            <div class="menu-category-bar" id="categoryList" role="group" aria-label="Menu categories"></div>

            <div class="product-grid" id="productGrid"></div>
        </section>
    </main>

    <!-- Right Collapsible Order Sidebar -->
    <aside class="order-sidebar" id="orderSidebar">
        <button class="toggle-panel-btn" id="togglePanelBtn" title="Toggle Panel">&rsaquo;</button>

        <div class="order-expanded-content">
            <div class="order-head">
                <h2 class="order-title">Cart</h2>
                <button type="button" class="sheet-close" id="sheetClose" aria-label="Close cart">&times;</button>
            </div>
            <div class="cart-items-list" id="cartItemsList"></div>
            <div class="cart-limit-hint" id="cartLimitHint">0 of 50 items</div>
            <div class="order-footer">
                <div class="cart-summary-pill">
                    <span id="itemCount">0 Items</span>
                    <span id="totalPrice" class="total-price-text">₱ 0.00</span>
                </div>
                <button class="order-submit-btn" id="checkoutBtn" data-requires-open data-open-label="Order">Order</button>
            </div>
        </div>

        <div class="order-collapsed-content" id="collapsedCartView">
            <div class="collapsed-cart-icon-wrap">
                <span class="collapsed-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2.5 3.5H5l2.4 11.2a1 1 0 0 0 .8.8h9.2a1 1 0 0 0 1-.8L20.5 8H6"/></svg></span>
                <span class="collapsed-badge" id="collapsedBadge">0</span>
            </div>
            <div class="collapsed-thumbnails" id="collapsedThumbnails"></div>
        </div>
    </aside>
</div>

<!-- Phones: "View cart" bar and the backdrop behind the cart sheet -->
<div class="sheet-backdrop" id="sheetBackdrop" hidden></div>
<button type="button" class="mobile-cartbar" id="mobileCartBar" aria-controls="orderSidebar" aria-expanded="false" hidden>
    <span class="mcb-left"><span class="mcb-count" id="mcbCount">0</span> View cart</span>
    <span class="mcb-total" id="mcbTotal">&#8369; 0.00</span>
</button>

<!-- Customization Modal (same options as the staff screen) -->
<div class="modal-overlay" id="customizeModal">
    <div class="modal-card">
        <h2 class="modal-title center-title">Customize Order</h2>

        <div class="modal-section" id="sizeSection">
            <span class="section-label">Select Size</span>
            <div class="options-row" id="sizeOptions"></div>
        </div>

        <div class="modal-section" id="orderTypeSection">
            <span class="section-label">Order Type</span>
            <div class="options-grid" id="orderTypeOptions">
                <button type="button" class="option-card active" data-name="Dine In" data-price="0" onclick="selectOrderType(this, 'Dine In')">Dine In</button>
                <button type="button" class="option-card" data-name="Take Out" data-price="0" onclick="selectOrderType(this, 'Take Out')">Take Out</button>
            </div>
        </div>

        <div class="modal-section" id="sinkerSection">
            <span class="section-label">Choose Sinkers</span>
            <div class="options-grid" id="sinkerOptions">
                <button type="button" class="option-card active" data-name="No Sinkers" data-price="0" onclick="selectSinker(this, 'No Sinkers', 0)">No Sinkers</button>
                <button type="button" class="option-card" data-name="Tapioca Pearls" data-price="0" onclick="selectSinker(this, 'Tapioca Pearls', 0)">Tapioca Pearls</button>
                <button type="button" class="option-card" data-name="Nata Jelly" data-price="0" onclick="selectSinker(this, 'Nata Jelly', 0)">Nata Jelly</button>
                <button type="button" class="option-card" data-name="Coffee Jelly" data-price="0" onclick="selectSinker(this, 'Coffee Jelly', 0)">Coffee Jelly</button>
                <button type="button" class="option-card" data-name="Rainbow Jelly" data-price="0" onclick="selectSinker(this, 'Rainbow Jelly', 0)">Rainbow Jelly</button>
            </div>
        </div>

        <div class="modal-section" id="extraSinkerSection">
            <span class="section-label">Add a Sinker <small class="section-note">(+&#8369;15 each)</small></span>
            <div class="options-grid" id="extraSinkerOptions">
                <button type="button" class="option-card disabled" disabled data-name="Tapioca Pearls" data-price="15" onclick="toggleExtraSinker(this, 'Tapioca Pearls', 15)">Tapioca Pearls (+&#8369;15)</button>
                <button type="button" class="option-card disabled" disabled data-name="Nata Jelly" data-price="15" onclick="toggleExtraSinker(this, 'Nata Jelly', 15)">Nata Jelly (+&#8369;15)</button>
                <button type="button" class="option-card disabled" disabled data-name="Coffee Jelly" data-price="15" onclick="toggleExtraSinker(this, 'Coffee Jelly', 15)">Coffee Jelly (+&#8369;15)</button>
                <button type="button" class="option-card disabled" disabled data-name="Rainbow Jelly" data-price="15" onclick="toggleExtraSinker(this, 'Rainbow Jelly', 15)">Rainbow Jelly (+&#8369;15)</button>
            </div>
        </div>

        <div class="modal-actions">
            <button class="cancel-btn" onclick="closeModal()">Cancel</button>
            <button class="confirm-btn primary-action" onclick="confirmOrder()">Add to Order</button>
        </div>
    </div>
</div>

<!-- Order Confirmation & Payment (GCash only) -->
<div class="modal-overlay" id="confirmationModal">
    <div class="modal-card confirmation-card">
        <h2 class="modal-title center-title">Order Confirmation &amp; Payment</h2>

        <div class="confirmation-table-header">
            <span>Qty</span>
            <span>Items</span>
            <span class="text-right">Price</span>
        </div>

        <div class="confirmation-items-list" id="confirmationItemsList"></div>

        <div class="confirmation-total-row">
            <span>Total</span>
            <span id="confirmTotalAmount">₱ 0.00</span>
        </div>

        <hr>

        <h3>Payment Method</h3>
        <div class="payment-grid">
            <div class="payment-option-card active" id="gcashPaymentOption">
                <div class="payment-icon gcash-logo-wrap">
                    <img src="../image/GCash_logo.svg" alt="GCash logo" class="gcash-logo">
                </div>
                <span>GCash</span>
            </div>
        </div>
        <p class="gcash-only-note">
            GCash is the only accepted payment. Your order is confirmed once the payment is
            verified, and we&rsquo;ll notify you when it&rsquo;s ready for pick-up.
        </p>
        <p class="gcash-time-note" id="gcashTimeNote">
            <strong>Pay within <?= (int) GCASH_PAYMENT_MINUTES ?> minutes.</strong>
            If you run out of time you get <?= (int) GCASH_LATE_PAYMENT_MINUTES ?> more minutes for a late payment;
            after that the order is cancelled automatically.
        </p>

        <button type="button" class="confirm-btn primary-action block-btn" id="confirmGcashBtn"
                onclick="askPlaceOrder()">
            Pay with GCash
        </button>

        <div class="modal-actions">
            <button type="button" class="edit-link-btn" onclick="closeConfirmationModal()">Edit Order</button>
        </div>
    </div>
</div>

<!-- Last chance to change their mind before the order is saved -->
<div class="modal-overlay place-order-sure" id="placeOrderSure">
    <div class="modal-card sure-card" role="alertdialog" aria-labelledby="sureTitle">
        <h2 class="modal-title center-title" id="sureTitle">Place this order?</h2>
        <p class="sure-text">Once you continue, your order is saved and you will be taken to GCash payment.</p>
        <button type="button" class="confirm-btn primary-action block-btn" onclick="confirmPlaceOrder()">Yes, place my order</button>
        <div class="modal-actions">
            <button type="button" class="edit-link-btn" onclick="cancelPlaceOrder()">No, go back</button>
        </div>
    </div>
</div>

<style>
    .gcash-only-note { text-align: center; font-size: 13px; color: #6b5a50; margin: 10px 0 14px; line-height: 1.5; }
</style>
<?php if ($loggedIn): include __DIR__ . "/gcash_manual_modal.php"; endif; ?>
<script>
    window.isLoggedIn = <?= json_encode($loggedIn) ?>;
</script>
<?php if ($role === "customer"): ?>
<script src="../js/customer_notify.js?v=gt3"></script>
<?php endif; ?>
<script src="../js/customerorder.js?v=r7"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
