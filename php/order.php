<?php
require_once __DIR__ . "/auth_check.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once "db.php";
require_once __DIR__ . "/menu_categories.php";

// Menu categories (Mango Series, ...) come from the database; admins add more with "Add Category".
$menuCategories = get_menu_categories($conn);
$firstCategory = $menuCategories[0]['name'] ?? 'Mango Series';

$isAdmin = isset($_SESSION['role'])
    && $_SESSION['role'] === 'admin';

    // Get the logged-in user's name and role.
    $displayName = $_SESSION['full_name']
    ?? $_SESSION['username']
    ?? 'User';

$displayRole = ucfirst($_SESSION['role'] ?? 'staff');

// Count orders that are not yet completed.
$activeOrderCount = 0;

$orderCountResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM orders
    WHERE status IN ('Pending', 'Working', 'Ready for Taking')
");

if ($orderCountResult) {
    $activeOrderCount = (int) $orderCountResult->fetch_assoc()['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order - Milkshake & Coffee</title>
    <link rel="stylesheet" href="../css/order.css?v=m1">
    <link rel="stylesheet" href="../css/responsive.css?v=4">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../css/pos-theme.css?v=gt2">
<link rel="stylesheet" href="../css/forms.css?v=gt1">
<link rel="stylesheet" href="../css/admin-drawer.css?v=1">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
<?php if ($isAdmin): ?>
<link rel="stylesheet" href="../css/menu_catalog.css?v=2">
<?php endif; ?>
</head>
<body>

<?php /* Admin: the Menu is a catalog only (no cart, no ordering). Staff: the POS with the cart. */ ?>
<div class="app-layout<?= $isAdmin ? ' catalog-only' : '' ?>" id="appLayout">
    <!-- Left Navigation Sidebar -->
    <?php $adminActive = 'menu'; include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <header class="top-header">

        <nav class="top-nav">
            <a href="#" class="nav-link active" id="menuNavBtn">Menu</a>

            <a href="#" class="nav-link" id="ordersNavBtn"> Orders
                <span
                    id="ordersNotificationBadge"
                    class="orders-notification-badge"
                    <?= $activeOrderCount === 0 ? 'hidden' : '' ?>
                >
                    <?= $activeOrderCount ?>
                </span>
            </a>
        </nav>
        </header>


        <section class="menu-section" id="menuSection">
            <div class="menu-section-header">
                <h1 class="section-title" id="sectionTitle"><?= htmlspecialchars($firstCategory, ENT_QUOTES, 'UTF-8') ?></h1>

                <?php if ($isAdmin): ?>
                    <div class="menu-header-actions">
                        <button type="button" id="addMenuCategoryBtn" class="add-menu-category-btn">
                            Add Category
                        </button>
                        <button type="button" id="addMenuProductBtn" class="add-menu-product-btn">
                            Add Product
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
                <p class="menu-catalog-note">
                    Menu catalog: add products and categories, change prices and recipes, or hide a product.
                    Orders are taken at the POS.
                </p>
            <?php endif; ?>

            <!-- Menu categories: filter chips, same style as the Orders filters -->
            <div class="menu-category-bar" id="menuCategoryBar" role="group" aria-label="Filter menu by category">
                <?php foreach ($menuCategories as $categoryIndex => $menuCategory): ?>
                    <button type="button" class="menu-category-btn<?= $categoryIndex === 0 ? ' active' : '' ?>"
                            onclick='switchCategory(this, <?= htmlspecialchars(json_encode($menuCategory['name']), ENT_QUOTES, 'UTF-8') ?>)'><?= htmlspecialchars($menuCategory['name'], ENT_QUOTES, 'UTF-8') ?></button>
                <?php endforeach; ?>
            </div>

            <div class="product-grid" id="productGrid">
            </div>
        </section>



    <!-- Orders Section -->
    <section class="menu-section" id="ordersSection" hidden>
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
            <h1 class="section-title" id="ordersTitle" style="margin: 0;">Orders</h1>

        <button type="button" class="gcash-orders-btn" id="openGcashOrders">
            GCash Orders
            <span id="gcashNotificationBadge" class="orders-notification-badge" hidden>0</span>
        </button>
        </div>

        <!-- Show only the orders in one stage. Counts update by themselves. -->
        <div class="orders-filter-bar" id="ordersFilterBar" role="group" aria-label="Filter orders by status">
            <button type="button" class="orders-filter-btn active" data-filter="">
                All <span class="filter-count">0</span>
            </button>
            <button type="button" class="orders-filter-btn" data-filter="Pending">
                <span class="filter-dot"></span> Pending <span class="filter-count">0</span>
            </button>
            <button type="button" class="orders-filter-btn" data-filter="Working">
                <span class="filter-dot"></span> Preparing <span class="filter-count">0</span>
            </button>
            <button type="button" class="orders-filter-btn" data-filter="Ready for Taking">
                <span class="filter-dot"></span> Ready to Serve <span class="filter-count">0</span>
            </button>
            <button type="button" class="orders-filter-btn" data-filter="Done">
                <span class="filter-dot"></span> Completed <span class="filter-count">0</span>
            </button>
        </div>

        <div id="ordersMessage" role="status">
            Loading orders...
        </div>

        <div class="orders-grid" id="ordersGrid"></div>

        <!-- GCash Orders Modal -->
        <div id="gcashOrdersModal" class="gcash-modal" hidden>
            <div class="gcash-modal-overlay" id="closeGcashOverlay"></div>

            <div class="gcash-modal-content"
                role="dialog"
                aria-modal="true"
                aria-labelledby="gcashModalTitle">

                <div class="gcash-modal-header">
                    <h2 id="gcashModalTitle">GCash Orders - Awaiting Payment</h2>

                    <button type="button"
                            class="gcash-modal-close"
                            id="closeGcashOrders"
                            aria-label="Close modal">
                        &times;
                    </button>
                </div>

                <p class="gcash-modal-description">
                    GCash orders that are not paid yet. Press "Confirm payment received" once you see the GCash payment.
                </p>

                <?php if ($isAdmin): ?>
                <!-- The QR code customers scan when PayMongo is not available -->
                <div class="gcash-qr-admin">
                    <img id="gcashQrThumb" src="gcash_qr.php" alt="Current GCash QR code" width="64" height="64">
                    <div class="gcash-qr-admin-text">
                        <strong>Store GCash QR code</strong>
                        <span id="gcashQrStatus">Customers scan this when they pay manually.</span>
                    </div>
                    <label class="gcash-qr-upload-btn">
                        Upload / change
                        <input type="file" id="gcashQrFile" accept="image/jpeg,image/png,image/webp" hidden>
                    </label>
                </div>
                <?php endif; ?>

                <div id="gcashOrdersMessage" role="status">
                    Loading paid GCash orders...
                </div>

                <div id="gcashOrdersList" class="gcash-orders-list"></div>
            </div>
        </div>

        <!-- GCash payment details: receipt picture, account name and reference number -->
        <div id="gcashDetailModal" class="gcash-modal" hidden>
            <div class="gcash-modal-overlay" id="closeGcashDetailOverlay"></div>

            <div class="gcash-modal-content gcash-detail-content" role="dialog" aria-modal="true" aria-labelledby="gcashDetailTitle">
                <div class="gcash-modal-header">
                    <h2 id="gcashDetailTitle">Order details</h2>
                    <button type="button" class="gcash-modal-close" id="closeGcashDetail" aria-label="Close details">&times;</button>
                </div>

                <div class="gcash-detail-body" id="gcashDetailBody"></div>
            </div>
        </div>
        </section>

    </section>

    </main>

    <?php if (!$isAdmin): ?>
    <!-- Right Collapsible Order Sidebar (staff POS only) -->
    <aside class="order-sidebar" id="orderSidebar">
        <button class="toggle-panel-btn" id="togglePanelBtn" title="Toggle Panel">&rsaquo;</button>

        <!-- Expanded State -->
        <div class="order-expanded-content">
            <h2 class="order-title">Order</h2>
            <div class="cart-items-list" id="cartItemsList">
                <!-- Items populated here -->
            </div>
            <div class="cart-limit-hint" id="cartLimitHint">0 of 50 items</div>
            <div class="order-footer">
                <div class="cart-summary-pill">
                    <span id="itemCount">0 Items</span>
                    <span id="totalPrice" class="total-price-text">₱ 0.00</span>
                </div>
                <button class="order-submit-btn" id="checkoutBtn">Order</button>
            </div>
        </div>

        <!-- Collapsed State -->
        <div class="order-collapsed-content" id="collapsedCartView">
            <div class="collapsed-cart-icon-wrap">
                <span class="collapsed-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2.5 3.5H5l2.4 11.2a1 1 0 0 0 .8.8h9.2a1 1 0 0 0 1-.8L20.5 8H6"/></svg></span>
                <span class="collapsed-badge" id="collapsedBadge">0</span>
            </div>
            <div class="collapsed-thumbnails" id="collapsedThumbnails">
                <!-- Mini thumbnails -->
            </div>
        </div>
    </aside>
    <?php endif; ?>
</div>

<?php if (!$isAdmin): ?>
<!-- Customization Modal (staff POS only) -->
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
                <button type="button" class="option-card active" onclick="selectOrderType(this, 'Dine In')">Dine In</button>
                <button type="button" class="option-card" onclick="selectOrderType(this, 'Take Out')">Take Out</button>
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


<!-- Combined Order Confirmation and Payment Modal -->
<div class="modal-overlay" id="confirmationModal">
    <div class="modal-card confirmation-card">
        <h2 class="modal-title center-title">Order Confirmation & Payment</h2>

        <div class="confirm-body">
        <div class="confirm-left">
        <!-- Customer and Order Information -->
        <div class="confirmation-header">
            <span class="order-no" id="confirmOrderNo">Order No. #1</span>

            <div class="customer-name-row">
                <label for="customerNameInput">Name:</label>
                <input
                    type="text"
                    id="customerNameInput"
                    placeholder="Enter customer name (optional)"
                    maxlength="100"
                    value=""
                >
            </div>
        </div>

        <!-- Ordered Items -->
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

        

        <!-- Modal Actions -->
        <div class="modal-actions">
            <button
                type="button"
                class="edit-link-btn"
                onclick="closeConfirmationModal()"
            >
                Edit Order
            </button>
        </div>
        </div><!-- /.confirm-left -->
        <div class="confirm-right">
        <!-- Payment Method -->
        <h3>Payment Method</h3>

        <div class="payment-grid">
            <div
                class="payment-option-card"
                id="cashPaymentOption"
                onclick="selectPayment('Cash')"
            >
                <div class="payment-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.8"/><path d="M6 9.5h.01M18 14.5h.01"/></svg></div>
                <span>Cash</span>
            </div>

            <div
                class="payment-option-card"
                id="gcashPaymentOption"
                onclick="selectPayment('GCash')"
            >
            <div class="payment-icon gcash-logo-wrap">
                <img
                    src="../image/GCash_logo.svg"
                    alt="GCash logo"
                    class="gcash-logo">
            </div> <span>GCash</span>
        </div>
        </div>

        <!-- Cash Payment Details -->
        <div id="cashPaymentSection" hidden>
            <h3>Cash Payment</h3>

            <div class="cash-calc-row">
                <span>Total Amount:</span>
                <span id="cashTotalDisplay" class="bold-text">₱ 0.00</span>
            </div>

            <div class="cash-input-group">
                <label for="cashReceivedInput">Amount Tendered:</label>

                <div class="currency-input-wrap">
                    <span>₱</span>
                    <input
                        type="number"
                        id="cashReceivedInput"
                        placeholder="0.00"
                        min="0"
                        step="0.01"
                        oninput="calculateChange()"
                    >
                </div>
            </div>

            <div class="quick-tender" id="quickTender" role="group" aria-label="Quick amounts"></div>

            <div class="cash-calc-row change-row">
                <span>Change:</span>
                <span id="cashChangeDisplay" class="change-amount">₱ 0.00</span>
            </div>

            <button
                type="button"
                class="confirm-btn primary-action block-btn"
                id="confirmCashBtn"
                onclick="askPlaceOrder('Cash')"
                disabled
            >
                Complete Cash Payment
            </button>
        </div>

        <!-- GCash Payment Details -->
        <div id="gcashPaymentSection" hidden>
            <h3>GCash Payment</h3>

            <div class="qr-container">

            </div>

            <button
                type="button"
                class="switch-payment-btn"
                onclick="selectPayment('Cash')"
            >
                Switch to Cash
            </button>

            <button
                type="button"
                class="confirm-btn primary-action block-btn"
                id="confirmGcashBtn"
                onclick="askPlaceOrder('GCash')"
            >
                Confirm GCash Payment
            </button>
        </div>
        </div><!-- /.confirm-right -->
        </div><!-- /.confirm-body -->
    </div>
</div>

<?php endif; ?>

<!-- Last chance to change their mind before the order is saved -->
<div class="modal-overlay place-order-sure" id="placeOrderSure">
    <div class="modal-card sure-card" role="alertdialog" aria-modal="true" aria-labelledby="sureTitle" aria-describedby="sureText">
        <h2 class="modal-title center-title" id="sureTitle">Place this order?</h2>
        <p class="sure-text" id="sureText"></p>
        <button type="button" class="confirm-btn primary-action block-btn" id="sureYesBtn" onclick="confirmPlaceOrder()">Yes, place the order</button>
        <div class="modal-actions">
            <button type="button" class="edit-link-btn" onclick="cancelPlaceOrder()">No, go back</button>
        </div>
    </div>
</div>

<!-- Menu Product Details Modal -->
<div class="modal-overlay" id="menuDetailsModal">
    <div class="modal-card menu-management-modal">
        <h2>Product Details</h2>
        <div id="menuDetailsContent"></div>
        <div class="modal-actions">
            <button type="button" class="cancel-btn"
                onclick="document.getElementById('menuDetailsModal').classList.remove('show')">
                Close
            </button>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- Add / Edit Menu Product Modal (admin only) -->
<div class="modal-overlay" id="addMenuModal" role="dialog" aria-modal="true" aria-labelledby="addMenuTitle">
    <div class="modal-card edit-menu-modal add-menu-modal form-modal product-form-modal">
        <form id="addMenuForm" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="product_id" id="addMenuId" value="">

            <header class="form-modal-head">
                <div>
                    <h2 id="addMenuTitle">Add Menu Product</h2>
                    <p class="modal-sub">What customers see on the menu, and what each serving uses from inventory.</p>
                </div>
                <button type="button" class="modal-x" aria-label="Close" data-click-target="cancelAddMenu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </header>

            <div class="form-modal-body">

                <div class="product-form-grid">

                    <section class="form-section">
                        <h3>Product details</h3>

                        <div class="field">
                            <label for="addMenuName">Product name <span class="req" aria-hidden="true">*</span></label>
                            <input type="text" id="addMenuName" name="product_name"
                                maxlength="255" placeholder="e.g. Mango Graham" autocomplete="off" required>
                        </div>

                        <div class="field">
                            <label for="addMenuCategory">Category <span class="req" aria-hidden="true">*</span></label>
                            <select id="addMenuCategory" name="category" required>
                                <option value="" disabled selected>Select category</option>
                                <?php foreach ($menuCategories as $menuCategory): ?>
                                    <option value="<?= htmlspecialchars($menuCategory['name'], ENT_QUOTES, 'UTF-8') ?>"<?= !empty($menuCategory['is_food']) ? ' data-food="1"' : '' ?>><?= htmlspecialchars($menuCategory['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="addMenuImage">Product image</label>
                            <div class="image-field">
                                <div class="edit-menu-image-preview" id="addMenuImagePreview">
                                    <span>No image selected</span>
                                </div>
                                <div class="image-field-controls">
                                    <input type="file" id="addMenuImage" name="image"
                                        accept=".jpg,.jpeg,.png,.gif,.webp" aria-describedby="addMenuImageHint">
                                    <p class="field-hint" id="addMenuImageHint">PNG, JPG or WebP. A drink photo on a plain background looks best.</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="form-section">
                        <h3>Pricing</h3>

                        <!-- One price, or (with sizes on) a price for each size -->
                        <div class="field" id="addMenuSinglePrice">
                            <label for="addMenuPrice">Selling price <span class="req" aria-hidden="true">*</span></label>
                            <input type="number" id="addMenuPrice" name="selling_price"
                                min="0.01" step="0.01" inputmode="decimal" placeholder="&#8369; 0.00" required>
                        </div>

                        <div class="add-menu-sizes-toggle">
                            <label>
                                <input type="checkbox" id="addMenuHasSizes" name="has_sizes" value="1" role="switch">
                                <span>Sell in different sizes</span>
                            </label>
                            <p>
                                Turn this on if the product comes in 2 or more sizes.
                                Each size has its own price and its own ingredient quantities.
                            </p>
                        </div>

                        <div id="addMenuSizesPanel" class="add-menu-sizes-panel" hidden>
                            <h3>Sizes</h3>
                            <p>Tick at least two sizes and set the price of each.</p>

                            <div class="add-menu-size-row">
                                <label class="size-check">
                                    <input type="checkbox" name="sizes[]" value="small"> Small
                                </label>
                                <input type="number" name="price_small" min="0.01" step="0.01" inputmode="decimal"
                                    placeholder="&#8369; 0.00" aria-label="Small price" disabled>
                            </div>

                            <div class="add-menu-size-row">
                                <label class="size-check">
                                    <input type="checkbox" name="sizes[]" value="medium"> Medium
                                </label>
                                <input type="number" name="price_medium" min="0.01" step="0.01" inputmode="decimal"
                                    placeholder="&#8369; 0.00" aria-label="Medium price" disabled>
                            </div>

                            <div class="add-menu-size-row">
                                <label class="size-check">
                                    <input type="checkbox" name="sizes[]" value="large"> Large
                                </label>
                                <input type="number" name="price_large" min="0.01" step="0.01" inputmode="decimal"
                                    placeholder="&#8369; 0.00" aria-label="Large price" disabled>
                            </div>
                        </div>
                    </section>

                </div>

                <section class="edit-menu-ingredients form-section">
                    <h3>Ingredients</h3>
                    <p>
                        Add every inventory ingredient used to make one serving.
                        The cup and straw are taken from the inventory automatically.
                        With sizes on, enter how much each size uses.
                        The product shows as available only while all of them are in stock.
                    </p>

                    <div id="addMenuIngredientsList"></div>

                    <button type="button" id="addMenuIngredientBtn" class="add-menu-ingredient-btn">
                        + Add ingredient
                    </button>
                </section>

            </div>

            <footer class="form-modal-foot">
                <p id="addMenuMessage" class="add-menu-message" role="alert"></p>
                <div class="foot-actions">
                    <button type="button" class="cancel-btn" id="cancelAddMenu">
                        Cancel
                    </button>
                    <button type="submit" class="confirm-btn" id="saveAddMenu">
                        Save Product
                    </button>
                </div>
            </footer>
        </form>
    </div>
</div>

<!-- Add Category Modal (admin only) -->
<div class="modal-overlay" id="addCategoryModal" role="dialog" aria-modal="true" aria-labelledby="addCategoryTitle">
    <div class="modal-card form-modal add-category-modal">
        <form id="addCategoryForm" novalidate>
            <header class="form-modal-head">
                <div>
                    <h2 id="addCategoryTitle">Add Category</h2>
                    <p class="modal-sub">A group of products on the menu, like &ldquo;Coffee Series&rdquo;.</p>
                </div>
                <button type="button" class="modal-x" aria-label="Close" data-click-target="cancelAddCategory"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </header>

            <div class="form-modal-body">
                <div class="field">
                    <label for="addCategoryName">Category name <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="addCategoryName" name="name" maxlength="50"
                           placeholder="e.g. Rice Meals" autocomplete="off" required>
                </div>

                <label class="category-food-option">
                    <input type="checkbox" id="addCategoryFood" name="is_food" value="1">
                    <span>
                        <strong>This is a food category (like pizza)</strong>
                        <small>Recipes use inventory units (Packs / Liters / Pcs) and no cup or straw is added. Leave this off for drinks.</small>
                    </span>
                </label>

                <div class="existing-categories">
                    <p class="existing-categories-title">Existing categories</p>
                    <ul id="existingCategoryList">
                        <?php foreach ($menuCategories as $menuCategory): ?>
                            <li><?= htmlspecialchars($menuCategory['name'], ENT_QUOTES, 'UTF-8') ?><?= $menuCategory['is_food'] ? ' <small>food</small>' : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <footer class="form-modal-foot">
                <p id="addCategoryMessage" class="add-menu-message" role="alert"></p>
                <div class="foot-actions">
                    <button type="button" class="cancel-btn" id="cancelAddCategory">Cancel</button>
                    <button type="submit" class="confirm-btn" id="saveAddCategory">Add Category</button>
                </div>
            </footer>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Deactivate Menu Product Confirmation Modal -->
<div id="deactivateMenuModal" class="modal-overlay hidden">
    <div class="modal-box small-modal">
        <h3>Deactivate Menu Product?</h3>

        <p>
            Are you sure you want to deactivate
            <strong id="deactivateMenuName"></strong>?
        </p>

        <p class="deactivate-warning">
            It will be marked Inactive and staff will not be able to order it.
            You can activate it again anytime.
        </p>

        <p id="deactivateMenuMessage" role="alert"></p>

        <div class="modal-actions">
            <button
                type="button"
                id="cancelDeactivateMenu"
                class="btn-cancel">
                Cancel
            </button>

            <button
                type="button"
                id="confirmDeactivateMenu"
                class="btn-confirm deactivate-confirm-btn">
                Deactivate
            </button>
        </div>
    </div>
</div>

<!-- Confirm Order Done Modal -->
<div class="modal-overlay" id="confirmOrderDoneModal">
    <div class="modal-card menu-management-modal">
        <h2>Complete This Order?</h2>

        <p> Are you sure this order is complete? </p>
        <p> Confirming will mark this order as Completed. You can still find it under Completed.</p>
        <p id="confirmOrderDoneMessage" role="alert"></p>

        <div class="modal-actions">
            <button
                type="button"
                class="cancel-btn"
                id="cancelOrderDone">
                Cancel
            </button>

            <button
                type="button"
                class="confirm-btn"
                id="confirmOrderDone">
                Yes, Complete Order
            </button>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="logout-modal hidden">
    <div class="logout-modal-card">
        <h2>Confirm Logout</h2>
        <p>Are you sure you want to log out?</p>

        <div class="logout-modal-actions">
            <button type="button" id="cancelLogout" class="cancel-logout-btn">
                Cancel
            </button>

            <a href="logout.php" class="confirm-logout-btn">
                Yes, Logout
            </a>
        </div>
    </div>
</div>

<script>
    window.isAdmin = <?= json_encode($isAdmin) ?>;
</script>
<script src="../js/order.js?v=m5"></script>
<?php if ($isAdmin): ?>
<script src="../js/menu_add_product.js?v=8"></script>
<script src="../js/menu_add_category.js?v=1"></script>
<?php endif; ?>
<script src="../js/admin_notify.js?v=2"></script>
<script src="../js/logout.js?v=2"></script>
<script>
window.addEventListener("pageshow", function (event) {
    if (event.persisted) {
        // If the staff member just came Back from PayMongo, let order.js cancel that order first.
        Promise.resolve(window.djcPendingCancel).then(function () { window.location.reload(); });
    }
});
</script>
<script src="../js/sinker_stock.js?v=gt3"></script>
<script src="../js/pos_extras.js?v=gt1"></script>
<script src="../js/admin_forms.js?v=gt1"></script>
<script src="../js/admin_drawer.js?v=1"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>