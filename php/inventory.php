<?php
    require_once __DIR__ . "/auth_check.php";

    // Admin: full inventory management.
    // Staff: they can LOOK at the inventory (stock, availability, expiry, details) but cannot
    // add, edit, change stock, or activate / deactivate anything. The pages that save changes
    // (inventory_save.php, inventory_update_stock.php, set_product_status.php) are admin-only too.
    $role = $_SESSION['role'] ?? '';

    if (!in_array($role, ['admin', 'staff'], true)) {
        header("Location: order.php");
        exit;
    }

    $isAdmin = ($role === 'admin');
    require_once "db.php";
    require_once __DIR__ . "/units.php";
    require_once __DIR__ . "/sinkers.php";
    require_once __DIR__ . "/admin_notification_counts.php";

    // Stock needs 3 decimals (1 g = 0.002 pack).
    ensure_inventory_precision_schema($conn);

    // The four sinkers (kg) always exist in the inventory; stock starts at 0 kg.
    ensure_sinker_inventory($conn);

    // Active items first, then inactive ones. "used_by" is the number of active
    // menu products that use the item as an ingredient.
    $sql = "SELECT i.*,
                   DATEDIFF(i.expiration_date, CURDATE()) AS days_left,
                   (SELECT COUNT(DISTINCT m.id)
                    FROM menu_product_ingredients r
                    JOIN menu_inventory m ON m.id = r.menu_product_id
                    WHERE r.inventory_product_id = i.id
                      AND m.is_archived = 0) AS used_by
            FROM inventory i
            ORDER BY i.is_archived ASC, i.id ASC";
    $result = $conn->query($sql);

    // Inventory categories: Ingredients, Sinkers and Materials.
    // Older records may use other names, so they are shown under the closest one.
    function inventoryCategory($category): string
    {
        $materials = ['materials', 'material', 'matrials', 'packaging', 'boxes'];

        if (in_array(strtolower(trim((string) $category)), ['sinkers', 'sinker'], true)) {
            return 'Sinkers';
        }

        return in_array(strtolower(trim((string) $category)), $materials, true)
            ? 'Materials'
            : 'Ingredients';
    }
?>

        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Inventory - D&JC Food Products</title>
        <link rel="stylesheet" href="../css/dashboard.css?v=gt2">
        <link rel="stylesheet" href="../css/inventory.css?v=gt3">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="../css/admin-theme.css?v=st4">
<link rel="stylesheet" href="../css/forms.css?v=gt2">
<link rel="stylesheet" href="../css/admin-drawer.css?v=1">
        <link rel="stylesheet" href="../css/a11y.css?v=gt2">
</head>
        <body>

        <div class="app-layout">

            <!-- ==== Sidebar ==== -->
            <?php $adminActive = 'inventory'; include __DIR__ . '/admin_sidebar.php'; ?>

            <!-- ==== Main content ==== -->
            <main class="main-content">
                <div class="page-header has-stats">
                    <div class="page-title">
                        <h1>Inventory<?php if (!$isAdmin): ?> <span class="view-only-badge">View only</span><?php endif; ?></h1>
                        <p class="page-sub">Stock levels and expiry for ingredients and materials.</p>
                    </div>

                    <?php if ($lowStockCount > 0): ?>
                        <p class="low-stock-summary">
                            <span class="low-stock-indicator"></span>
                            <?= $lowStockCount ?>
                            low-stock item<?= $lowStockCount !== 1 ? 's' : '' ?>
                            need<?= $lowStockCount === 1 ? 's' : '' ?> attention.
                        </p>
                    <?php else: ?>
                        <p class="low-stock-summary in-stock-summary">
                            All inventory items have sufficient stock.
                        </p>
                    <?php endif; ?>
                </div>

                <?php
                // The low-stock limit of every unit (see units.php). Summary cards, the table, the
                // sidebar badge and the notifications all use these same limits.
                $lowThresholds = inventory_low_thresholds($conn);

                // Summary cards (same low-stock rule as the sidebar badge).
                $statTotal = $statLow = $statOut = $statExp = $statExpired = 0;
                if (isset($conn) && ($statRes = $conn->query(
                    "SELECT COUNT(*) AS a,
                            COALESCE(SUM(quantity <= 0), 0) AS o,
                            COALESCE(SUM(quantity > 0 AND quantity <= " . inventory_low_limit_sql() . "), 0) AS l,
                            COALESCE(SUM(expiration_date IS NOT NULL AND expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)), 0) AS e,
                            COALESCE(SUM(expiration_date IS NOT NULL AND expiration_date < CURDATE()), 0) AS x
                     FROM inventory WHERE is_archived = 0"))) {
                    if ($statRow = $statRes->fetch_assoc()) {
                        $statTotal = (int)$statRow['a']; $statOut = (int)$statRow['o'];
                        $statLow   = (int)$statRow['l']; $statExp = (int)$statRow['e']; $statExpired = (int)$statRow['x'];
                    }
                }
                ?>
                <div class="stat-cards" data-inventory-stats>
                    <div class="stat-card">
                        <p class="stat-label">Items tracked</p>
                        <p class="stat-value" data-stat="active"><?= $statTotal ?></p>
                    </div>
                    <button type="button" class="stat-card is-warn" data-filter="low-stock" aria-pressed="false">
                        <p class="stat-label">Low stock</p>
                        <p class="stat-value" data-stat="low"><?= $statLow ?></p>
                        <p class="stat-note">Select to filter</p>
                    </button>
                    <button type="button" class="stat-card is-bad" data-filter="no-stock" aria-pressed="false">
                        <p class="stat-label">Out of stock</p>
                        <p class="stat-value" data-stat="out"><?= $statOut ?></p>
                        <p class="stat-note">Select to filter</p>
                    </button>
                    <button type="button" class="stat-card is-warn" data-expiry-filter="soon" aria-pressed="false">
                        <p class="stat-label">Expiring in 7 days</p>
                        <p class="stat-value" data-stat="expiring"><?= $statExp ?></p>
                        <p class="stat-note">Select to filter</p>
                    </button>
                    <button type="button" class="stat-card is-bad" data-expiry-filter="expired" aria-pressed="false">
                        <p class="stat-label">Expired</p>
                        <p class="stat-value" data-stat="expired"><?= $statExpired ?></p>
                        <p class="stat-note">Select to filter</p>
                    </button>
                </div>

                <!-- How "Low Stocks" is decided -->
                <section class="low-stock-rule" aria-labelledby="lowRuleTitle">
                    <div class="low-rule-main">
                        <h2 id="lowRuleTitle">How &ldquo;Low Stocks&rdquo; is decided</h2>
                        <p>
                            Each unit has its own limit. An item is <strong>Low Stocks</strong> when its quantity is
                            at or below the limit of its unit, <strong>No Stocks</strong> when it reaches 0, and
                            <strong>In Stock</strong> when it is above the limit.
                        </p>
                        <ul class="low-rule-list">
                            <?php foreach ($lowThresholds as $thresholdUnit => $thresholdLimit): ?>
                                <li>
                                    <span class="low-rule-unit"><?= htmlspecialchars($thresholdUnit, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="low-rule-kind"><?= htmlspecialchars(LOW_STOCK_UNIT_KINDS[$thresholdUnit] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="low-rule-value">Low at <strong><?= htmlspecialchars(inventory_low_limit_text($thresholdUnit, $thresholdLimit), ENT_QUOTES, 'UTF-8') ?></strong></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ($isAdmin): ?>
                        <button type="button" id="editThresholdsBtn" class="btn-secondary">Edit limits</button>
                    <?php endif; ?>
                </section>

                <div id="alertBox" class="alert-box hidden"></div>

                <div class="panel inventory-panel">

                    <?php
                    // Load every inventory row once; the table is paginated in the browser.
                    $inventoryRows = [];

                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $row['category'] = inventoryCategory($row['category']);
                            $inventoryRows[] = $row;
                        }
                    }
                    ?>

                    <div class="panel-header">
                        <h2>Inventory Stock</h2>
                        <div class="header-actions">
                            <?php if ($isAdmin): ?>
                            <button type="button" id="addInventoryBtn" class="btn-primary"> Add Product </button>
                            <?php endif; ?>
                            <button type="button" id="filtersBtn" class="btn-secondary btn-filters"
                                    aria-expanded="false" aria-controls="filtersPanel">
                                Filters
                            </button>
                            <?php if ($isAdmin): ?>
                            <button type="button" id="cancelEditBtn" class="btn-secondary hidden">
                                Cancel
                            </button>
                            <button type="button" id="editInventoryBtn" class="btn-secondary">
                                Edit Inventory
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div id="filtersPanel" class="filters-panel hidden">
                        <label class="filter-field">
                            <span>Search</span>
                            <input type="search" id="filterSearch" placeholder="Product name" autocomplete="off">
                        </label>

                        <label class="filter-field">
                            <span>Category</span>
                            <select id="filterCategory">
                                <option value="">All categories</option>
                                <option value="Ingredients">Ingredients</option>
                                <option value="Sinkers">Sinkers</option>
                                <option value="Materials">Materials</option>
                            </select>
                        </label>

                        <label class="filter-field">
                            <span>Availability</span>
                            <select id="filterAvailability">
                                <option value="">All</option>
                                <option value="in-stock">In Stock</option>
                                <option value="low-stock">Low Stocks</option>
                                <option value="no-stock">No Stocks</option>
                            </select>
                        </label>

                        <label class="filter-field">
                            <span>Expiry</span>
                            <select id="filterExpiry">
                                <option value="">All</option>
                                <option value="soon">Expiring in 7 days</option>
                                <option value="expired">Expired</option>
                            </select>
                        </label>

                        <label class="filter-field">
                            <span>Status</span>
                            <select id="filterStatus">
                                <option value="">All</option>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </label>

                        <button type="button" id="resetFiltersBtn" class="btn-secondary">Reset</button>
                    </div>

                    <div class="inventory-table-card">
                        <div class="table-container inventory-table-scroll">
                            <table class="inventory-table" id="inventoryTable">
                                <thead>
                                    <tr>
                                        <th scope="col" class="col-name">Product Name</th>
                                        <th scope="col" class="col-category">Category</th>
                                        <th scope="col" class="col-unit">Unit</th>
                                        <th scope="col" class="col-qty">Quantity</th>
                                        <th scope="col" class="col-availability">Availability</th>
                                        <th scope="col" class="col-expiry">Expiry</th>
                                        <th scope="col" class="col-status">Status</th>
                                        <th scope="col" class="col-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="inventoryTableBody">

                                <?php foreach ($inventoryRows as $product): ?>

                                    <?php
                                    $quantity = (float) $product['quantity'];
                                    $unitInfo = inventory_unit_info($product['unit']);
                                    $lowLimit = inventory_low_limit($product['unit']);

                                    // The limit comes from inventory_low_limit() (units.php); the row carries it
                                    // as data-low-limit so inventory.js uses the very same number.
                                    if ($quantity <= 0) {
                                        $availability = "No Stocks";
                                        $availabilityClass = "no-stock";
                                    } elseif ($quantity <= $lowLimit) {
                                        $availability = "Low Stocks";
                                        $availabilityClass = "low-stock";
                                    } else {
                                        $availability = "In Stock";
                                        $availabilityClass = "in-stock";
                                    }

                                    // Expiry: expired (past), soon (today to 7 days away), ok, or none.
                                    $daysLeft = $product['days_left'] === null ? null : (int) $product['days_left'];
                                    if ($daysLeft === null) {
                                        $expiryState = 'none'; $expiryText = 'No expiry';
                                    } elseif ($daysLeft < 0) {
                                        $expiryState = 'expired';
                                        $expiryText = 'Expired ' . abs($daysLeft) . ($daysLeft === -1 ? ' day ago' : ' days ago');
                                    } elseif ($daysLeft === 0) {
                                        $expiryState = 'soon'; $expiryText = 'Expires today';
                                    } elseif ($daysLeft <= 7) {
                                        $expiryState = 'soon';
                                        $expiryText = 'Expires in ' . $daysLeft . ($daysLeft === 1 ? ' day' : ' days');
                                    } else {
                                        $expiryState = 'ok'; $expiryText = 'Good';
                                    }

                                    $productName = (string) $product['product_name'];
                                    $isActive = (int) $product['is_archived'] === 0;

                                    // Shared by the Edit and View Details buttons.
                                    $dataAttributes = [
                                        'id'              => $product['id'],
                                        'name'            => $product['product_name'],
                                        'category'        => $product['category'],
                                        'unit'            => $product['unit'],
                                        'quantity'        => $product['quantity'],
                                        'date-procured'   => $product['date_procured'],
                                        'expiration-date' => $product['expiration_date'],
                                    ];
                                    ?>

                                    <tr class="inventory-row <?= $isActive && $availabilityClass !== 'in-stock' ? 'row-alert' : '' ?> <?= $isActive ? '' : 'row-inactive' ?> <?= $expiryState === 'expired' ? 'row-expired' : '' ?>"
                                        data-expiry="<?= $expiryState ?>"
                                        data-id="<?= (int) $product['id'] ?>"
                                        data-active="<?= $isActive ? '1' : '0' ?>"
                                        data-name="<?= htmlspecialchars(strtolower($productName), ENT_QUOTES, 'UTF-8') ?>"
                                        data-category="<?= htmlspecialchars((string) $product['category'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-original-qty="<?= format_quantity($product['quantity']) ?>"
                                        data-low-limit="<?= (float) $lowLimit ?>"
                                        data-unit-factor="<?= $unitInfo['kind'] === 'count' ? 0 : $unitInfo['factor'] ?>"
                                        data-unit-base="<?= htmlspecialchars($unitInfo['kind'] === 'count' ? '' : $unitInfo['base'], ENT_QUOTES, 'UTF-8') ?>">

                                        <td class="col-name">
                                            <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td class="col-category">
                                            <?= htmlspecialchars((string) $product['category'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td class="col-unit">
                                            <?= htmlspecialchars((string) $product['unit'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td class="col-qty">
                                            <div class="qty-cell">
                                                <?php if ($isAdmin): ?>
                                                <button type="button" class="stepper" data-step="-1" <?= $isActive ? '' : 'disabled' ?>
                                                        aria-label="Decrease quantity of <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>">&minus;</button>
                                                <?php endif; ?>
                                                <input type="number" class="qty-input" value="<?= format_quantity($product['quantity']) ?>"
                                                       min="0" step="any" inputmode="decimal" readonly tabindex="-1"
                                                       aria-label="Quantity of <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>">
                                                <?php if ($isAdmin): ?>
                                                <button type="button" class="stepper" data-step="1" <?= $isActive ? '' : 'disabled' ?>
                                                        aria-label="Increase quantity of <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>">+</button>
                                                <?php endif; ?>
                                            </div>
                                            <small class="qty-equiv"><?= htmlspecialchars(stock_equivalent($product['quantity'], $product['unit']), ENT_QUOTES, 'UTF-8') ?></small>
                                        </td>

                                        <td class="col-availability">
                                            <span class="availability <?= $availabilityClass ?>"
                                                  title="<?= htmlspecialchars('Low stock at ' . inventory_low_limit_text((string) $product['unit'], (float) $lowLimit) . '; no stock at 0.', ENT_QUOTES, 'UTF-8') ?>"><?= $availability ?></span>
                                        </td>

                                        <td class="col-expiry">
                                            <span class="expiry-pill expiry-<?= $expiryState ?>"><?= htmlspecialchars($expiryText, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($product['expiration_date']): ?>
                                                <small class="expiry-date"><?= htmlspecialchars(date('M j, Y', strtotime($product['expiration_date'])), ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php endif; ?>
                                        </td>

                                        <td class="col-status">
                                            <span class="status-pill <?= $isActive ? 'is-active' : 'is-inactive' ?>">
                                                <?= $isActive ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>

                                        <td class="col-actions">
                                            <div class="row-actions">
                                                <?php if ($isAdmin): ?>
                                                <button type="button" class="edit-product-btn"
                                                    <?php foreach ($dataAttributes as $attrName => $attrValue): ?>
                                                        data-<?= $attrName ?>="<?= htmlspecialchars((string) $attrValue, ENT_QUOTES, 'UTF-8') ?>"
                                                    <?php endforeach; ?>
                                                >Edit</button>
                                                <?php endif; ?>

                                                <button type="button" class="view-product-btn"
                                                    <?php foreach ($dataAttributes as $attrName => $attrValue): ?>
                                                        data-<?= $attrName ?>="<?= htmlspecialchars((string) $attrValue, ENT_QUOTES, 'UTF-8') ?>"
                                                    <?php endforeach; ?>
                                                >View Details</button>

                                                <?php if (!$isAdmin): ?>
                                                    <?php /* staff: view only - no Activate / Deactivate */ ?>
                                                <?php elseif ($isActive): ?>
                                                    <button type="button" class="status-toggle-btn deactivate-btn"
                                                        data-action="deactivate"
                                                        data-id="<?= (int) $product['id'] ?>"
                                                        data-name="<?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-used-by="<?= (int) $product['used_by'] ?>"
                                                    >Deactivate</button>
                                                <?php else: ?>
                                                    <button type="button" class="status-toggle-btn activate-btn"
                                                        data-action="activate"
                                                        data-id="<?= (int) $product['id'] ?>"
                                                        data-name="<?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>"
                                                    >Activate</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                                </tbody>
                            </table>

                            <p id="inventoryEmptyMessage" class="empty-inventory <?= !empty($inventoryRows) ? 'hidden' : '' ?>">
                                <?= empty($inventoryRows) ? 'No inventory products found.' : 'No products match your filters.' ?>
                            </p>
                        </div>

                        <div class="inventory-pagination">
                            <button type="button" id="prevPageBtn" class="btn-page">Prev</button>
                            <span id="pageIndicator" class="page-indicator" aria-live="polite"></span>
                            <button type="button" id="nextPageBtn" class="btn-page">Next</button>
                        </div>
                    </div>

                </div><!-- /.panel -->

        <?php if ($isAdmin): ?>
        <!-- Add / Edit Inventory Modal (admin only - staff cannot edit inventory) -->
        <div id="productModal" class="modal-overlay hidden">
            <div class="modal-box product-modal form-modal">

                <form id="productForm">
                    <input type="hidden" id="productId" name="product_id">

                    <header class="form-modal-head">
                        <div>
                            <h2 id="productModalTitle">Add Product</h2>
                            <p class="modal-sub">Track an ingredient, sinker or material, how much is in stock and when it expires.</p>
                        </div>
                        <button type="button" class="modal-x" aria-label="Close" data-click-target="cancelProductModal"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                    </header>

                    <div class="form-modal-body">

                        <section class="form-section">
                            <h3>Item</h3>
                            <div class="field">
                                <label for="productName">Name <span class="req" aria-hidden="true">*</span></label>
                                <input type="text" id="productName" name="product_name" maxlength="255"
                                    placeholder="e.g. Tapioca pearls" autocomplete="off" required>
                                <p class="field-hint" id="addMergeHint">Already in the inventory? Don&rsquo;t worry: no second copy is made. The quantity you enter is added to the current stock.</p>
                            </div>
                            <div class="form-grid two">
                                <div class="field">
                                    <label for="productCategory">Category <span class="req" aria-hidden="true">*</span></label>
                                    <select id="productCategory" name="category" required>
                                        <option value="" disabled selected>Select category</option>
                                        <option value="Ingredients">Ingredients</option>
                                        <option value="Sinkers">Sinkers</option>
                                        <option value="Materials">Materials</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="productUnit">Unit of measurement <span class="req" aria-hidden="true">*</span></label>
                                    <select id="productUnit" name="unit" required disabled>
                                        <option value="" disabled selected>Select category first</option>
                                    </select>
                                </div>
                            </div>
                            <p class="unit-rule" id="unitRule" aria-live="polite">
                                Ingredients use <strong>Packs</strong>, <strong>Liters</strong> or <strong>Pcs</strong>
                                (1 Pack = 500 g, 1 Liter = 1,000 ml; Pcs for food counted by the piece).
                                Sinkers (Tapioca Pearls, Nata Jelly, Coffee Jelly, Rainbow Jelly) use <strong>kg</strong> (1 kg = 1,000 g).
                                Materials use <strong>Pcs</strong>.
                            </p>
                        </section>

                        <section class="form-section">
                            <h3>Stock</h3>
                            <div class="field">
                                <label for="productQuantity">Quantity on hand <span class="req" aria-hidden="true">*</span></label>
                                <div class="input-with-unit">
                                    <input type="number" id="productQuantity" name="quantity" min="0" step="1"
                                        inputmode="numeric" aria-describedby="productQuantityHint" required>
                                    <span class="unit-suffix" id="productQuantityUnit" aria-hidden="true"></span>
                                </div>
                                <p class="field-hint" id="productQuantityHint">Packs, Liters and kg can have up to 3 decimals; Pcs are whole numbers. Low stock at <?= htmlspecialchars(inventory_low_rule_summary(), ENT_QUOTES, 'UTF-8') ?> (see &ldquo;How Low Stocks is decided&rdquo;).</p>
                            </div>
                        </section>

                        <section class="form-section">
                            <h3>Dates</h3>
                            <div class="form-grid two">
                                <div class="field">
                                    <label for="dateProcured">Date of procurement</label>
                                    <?php $procurementWindow = inventory_procurement_window(); ?>
                                    <input type="date" id="dateProcured" name="date_procured"
                                           min="<?= $procurementWindow['min'] ?>" max="<?= $procurementWindow['max'] ?>"
                                           data-min="<?= $procurementWindow['min'] ?>" data-max="<?= $procurementWindow['max'] ?>"
                                           aria-describedby="procurementHint">
                                    <p class="field-hint" id="procurementHint">This year (<?= $procurementWindow['year'] ?>), up to today.</p>
                                </div>
                                <div class="field">
                                    <label for="expirationDate">Expiration date</label>
                                    <input type="date" id="expirationDate" name="expiration_date" aria-describedby="expirationHint">
                                    <p class="field-hint" id="expirationHint">Leave blank if it does not expire.</p>
                                </div>
                            </div>
                        </section>

                    </div>

                    <footer class="form-modal-foot">
                        <div class="foot-actions">
                            <button type="button" id="cancelProductModal" class="btn-cancel">Cancel</button>
                            <button type="submit" class="btn-confirm">Save</button>
                        </div>
                    </footer>
                </form>

            </div>
        </div>

        <?php endif; ?>

        <!-- Inventory Details Modal -->
        <div id="productDetailsModal" class="modal-overlay hidden">
            <div class="modal-box product-details-modal">

                <h3>Inventory Details</h3>

                <div class="product-details-content">
                    <div class="product-details-info">

                        <p><strong>Name:</strong> <span id="detailsProductName"></span></p>
                        <p><strong>Category:</strong> <span id="detailsCategory"></span></p>
                        <p><strong>Quantity:</strong> <span id="detailsQuantity"></span></p>
                        <p><strong>Unit of Measurement:</strong> <span id="detailsUnit"></span></p>
                        <p><strong>Date of Procurement:</strong> <span id="detailsDateProcured"></span></p>
                        <p><strong>Expiration Date:</strong> <span id="detailsExpirationDate"></span></p>

                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" id="closeProductDetails" class="btn-cancel">
                        Close
                    </button>
                </div>

            </div>
        </div>

        <?php if ($isAdmin): ?>
        <!-- Duplicate Product Warning Modal -->
        <div id="duplicateProductModal" class="modal-overlay hidden">
            <div class="modal-box small-modal">
                <h3>Duplicate Item Detected</h3>

                <p id="duplicateProductMessage" role="alert">
                    A product with the same information already exists.
                </p>

                <p>
                    Please check the existing item before adding it again.
                </p>

                <div class="modal-actions">
                    <button
                        type="button"
                        id="closeDuplicateProductModal"
                        class="btn-confirm">
                        OK
                    </button>
                </div>
            </div>
        </div>


        <!-- Deactivate Confirmation Modal -->
        <div id="deactivateProductModal" class="modal-overlay hidden">
            <div class="modal-box small-modal">
                <h3>Deactivate Item?</h3>

                <p>
                    Are you sure you want to deactivate
                    <strong id="deactivateProductName"></strong>?
                </p>

                <p class="deactivate-warning">
                    It will be marked Inactive and cannot be used until you
                    activate it again.
                </p>

                <p id="deactivateUsedBy" class="deactivate-warning hidden"></p>

                <p id="deactivateProductMessage" role="alert"></p>

                <div class="modal-actions">
                    <button type="button" id="cancelDeactivateProduct" class="btn-cancel">
                        Cancel
                    </button>

                    <button type="button" id="confirmDeactivateProduct" class="btn-confirm deactivate-confirm-btn">
                        Deactivate
                    </button>
                </div>
            </div>
        </div>

            </main>
        </div>

        <?php endif; ?>

        <?php if ($isAdmin): ?>
        <!-- Low-stock limits (admin only) -->
        <div id="thresholdsModal" class="modal-overlay hidden">
            <div class="modal-box small-modal" role="dialog" aria-modal="true" aria-labelledby="thresholdsTitle">
                <h3 id="thresholdsTitle">Low-stock limits</h3>
                <p class="modal-sub">An item is marked <strong>Low Stocks</strong> when its quantity is at or below the limit of its unit.</p>

                <form id="thresholdsForm" class="thresholds-form" novalidate>
                    <?php
                    $thresholdFields = ["Packs" => "limit_packs", "Liters" => "limit_liters", "kg" => "limit_kg", "Pcs" => "limit_pcs"];
                    foreach ($thresholdFields as $thresholdUnit => $thresholdField):
                    ?>
                        <div class="field">
                            <label for="<?= $thresholdField ?>"><?= $thresholdUnit ?> <small>(<?= htmlspecialchars(LOW_STOCK_UNIT_KINDS[$thresholdUnit], ENT_QUOTES, 'UTF-8') ?>)</small></label>
                            <div class="input-with-unit">
                                <input type="number" id="<?= $thresholdField ?>" name="<?= $thresholdField ?>"
                                       min="0" step="<?= $thresholdUnit === 'Pcs' ? '1' : 'any' ?>" inputmode="decimal"
                                       value="<?= htmlspecialchars(format_quantity($lowThresholds[$thresholdUnit]), ENT_QUOTES, 'UTF-8') ?>" required>
                                <span class="unit-suffix" aria-hidden="true"><?= $thresholdUnit ?> or less</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <p class="field-hint">Example: with kg set to 1, a sinker with 1 kg or less shows as Low Stocks. Use 0 to turn the warning off for a unit.</p>
                    <p id="thresholdsMessage" class="thresholds-message" role="alert" hidden></p>
                </form>

                <div class="modal-actions">
                    <button type="button" id="cancelThresholds" class="btn-cancel">Cancel</button>
                    <button type="submit" form="thresholdsForm" id="saveThresholds" class="btn-confirm">Save limits</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Logout confirmation modal -->
        <div id="logoutModal" class="modal-overlay hidden">
            <div class="modal-box small-modal">
                <h3>You are about to Logout</h3>
                <p class="modal-sub">Are you sure?</p>
                <div class="modal-actions">
                    <a href="#" id="cancelLogout" class="btn-cancel">Cancel</a>
                    <a href="logout.php" class="btn-confirm">Confirm</a>
                </div>
            </div>
        </div>

        <script src="../js/logout.js?v=2"></script>
        <script src="../js/inventory.js?v=st8"></script>
<?php if ($isAdmin): ?>
<script src="../js/inventory_thresholds.js?v=1"></script>
<?php endif; ?>
        <script src="../js/admin_summary.js?v=st2"></script>
        <script src="../js/sidebar.js"></script>
        <script>
        window.addEventListener("pageshow", function (event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
        </script>

        <script src="../js/admin_notify.js?v=2"></script>
<script src="../js/admin_forms.js?v=gt3"></script>
<script src="../js/admin_drawer.js?v=1"></script>
<script src="../js/a11y.js?v=gt2"></script>
</body>
        </html>
