<?php
    require_once __DIR__ . "/auth_check.php";

    // Admin: the full Sales Report. Staff: today's sales records only (a table, nothing else).
    $role = $_SESSION['role'] ?? '';

    if (!in_array($role, ['admin', 'staff'], true)) {
        header("Location: order.php");
        exit;
    }

    $isAdmin = ($role === 'admin');

    require_once "db.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isAdmin ? 'Sales Report' : "Today's Sales" ?> - D&JC Food Products</title>
<link rel="stylesheet" href="../css/dashboard.css?v=gt1">
<link rel="stylesheet" href="../css/inventory.css?v=gt1">
<link rel="stylesheet" href="../css/sales.css?v=gt5">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="../css/admin-theme.css?v=sc2">
<link rel="stylesheet" href="../css/admin-drawer.css?v=1">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body>

<div class="app-layout">

    <!-- ==== Sidebar ==== -->
            <?php $adminActive = 'sales'; include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- ==== Main content === -->
    <main class="main-content">
        <div class="page-header">
            <?php if ($isAdmin): ?>
                <h1>Sales Report</h1>
            <?php else: ?>
                <div class="page-title">
                    <h1>Today&rsquo;s Sales <span class="view-only-badge">View only</span></h1>
                    <p class="page-sub"><?= date('l, F j, Y') ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div id="alertBox" class="alert-box hidden"></div>

        <?php if ($isAdmin): ?>
        <!-- ==== Sales Analytics (first) ==== -->
        <section class="sales-analytics">
            <div class="analytics-header">
                <h2>Sales Analytics</h2>
                <p>See how sales are going and which products sell the most.</p>
            </div>

            <div class="analytics-grid">

                <!-- Daily Sales Trend -->
                <div class="analytics-card sales-trend-card">
                    <h3 id="trendTitle">Daily Sales Trend</h3>
                    <p id="trendRange" class="chart-note trend-range hidden"></p>
                    <div class="chart-container">
                        <canvas id="dailySalesChart"></canvas>
                    </div>
                </div>

                <!-- Most Sold Products (bar graph) -->
                <div class="analytics-card most-sold-card">
                    <div class="card-head">
                        <h3>Most Sold Products</h3>

                        <div class="chart-toggle" role="group" aria-label="Show most sold products by">
                            <button type="button" class="active" data-metric="quantity" aria-pressed="true">Quantity</button>
                            <button type="button" data-metric="total" aria-pressed="false">Sales (&#8369;)</button>
                        </div>
                    </div>

                    <p id="topSellingProduct" class="top-product-line">Loading top-selling product...</p>

                    <div class="chart-container">
                        <canvas id="mostSoldChart"></canvas>
                    </div>

                    <p id="mostSoldNote" class="chart-note"></p>
                </div>
            </div>
        </section>

        <!-- ==== Summary cards (below the analytics) ==== -->
        <p class="period-help" id="periodHelp">Select Daily, Weekly or Monthly to filter the report. Select it again to show all sales.</p>
        <div class="stat-cards" role="group" aria-labelledby="periodHelp">
            <button type="button" class="stat-card" data-period="daily" aria-pressed="false">
                <span class="stat-label">Daily Sales</span>
                <span class="stat-value" id="dailySalesVal">&#8369; 0.00</span>
                <span class="stat-sub" id="dailySalesSub">Today</span>
            </button>
            <button type="button" class="stat-card" data-period="weekly" aria-pressed="false">
                <span class="stat-label">Weekly Sales</span>
                <span class="stat-value" id="weeklySalesVal">&#8369; 0.00</span>
                <span class="stat-sub" id="weeklySalesSub">This week</span>
            </button>
            <button type="button" class="stat-card" data-period="monthly" aria-pressed="false">
                <span class="stat-label">Monthly Sales</span>
                <span class="stat-value" id="monthlySalesVal">&#8369; 0.00</span>
                <span class="stat-sub" id="monthlySalesSub"><?php echo date('F Y'); ?></span>
            </button>
            <div class="stat-card stat-card-static">
                <p class="stat-label">Total Customers</p>
                <p class="stat-value" id="totalCustomerVal">0</p>
                <p class="stat-sub" id="totalCustomerSub">This Day</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- ==== Sales Records (table) ==== -->
        <div class="panel sales-records-panel">
            <div class="panel-header">
                <div class="sales-records-header">
                    <h2><?= $isAdmin ? 'Sales Records' : "Today&rsquo;s Sales Records" ?></h2>
                    <p><?= $isAdmin
                        ? 'One row for every completed order. Search by order ID, customer or product.'
                        : 'Every completed order of today. Search by order ID, customer or product.' ?></p>
                </div>

                <?php if ($isAdmin): ?>
                <div class="header-actions">
                    <button type="button" id="filtersBtn" class="btn-secondary">&#8801; Filters</button>
                    <button type="button" id="downloadBtn" class="btn-secondary">Export as PDF</button>
                    <button type="button" id="printBtn" class="btn-secondary">Print</button>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>

            <div id="salesFilterPanel" class="sales-filter-panel hidden">
                <div class="filter-field period-field">
                    <span class="filter-label" id="periodLabel">Period</span>
                    <div class="period-toggle" role="group" aria-labelledby="periodLabel">
                        <button type="button" data-period="daily" aria-pressed="false">Daily</button>
                        <button type="button" data-period="weekly" aria-pressed="false">Weekly</button>
                        <button type="button" data-period="monthly" aria-pressed="false">Monthly</button>
                    </div>
                    <span class="period-hint" id="periodHint">Or choose your own dates:</span>
                </div>

                <div class="filter-field month-field hidden" id="monthField">
                    <label for="monthPicker">Month</label>
                    <select id="monthPicker"></select>
                </div>

                <div class="filter-field date-field">
                    <label for="startDate">Start Date</label>
                    <input type="date" id="startDate">
                </div>

                <div class="filter-field date-field">
                    <label for="endDate">End Date</label>
                    <input type="date" id="endDate">
                </div>

                <div class="filter-actions">
                    <button type="button" id="applyFiltersBtn" class="btn-secondary">
                        Apply Filter
                    </button>

                    <button type="button" id="resetFiltersBtn" class="btn-secondary">
                        Reset
                    </button>
                </div>
            </div>

            <?php endif; ?>

            <div class="sales-toolbar">
                <input
                    type="search"
                    id="salesSearchInput"
                    placeholder="Search order ID, customer or product..."
                    aria-label="Search sales records by order ID, customer or product"
                >

                <span id="salesRecordCount">Loading sales...</span>
                <span id="activePeriodNote" class="active-period-note hidden"></span>
            </div>

            <div class="sales-table-wrap">
                <table class="sales-table" id="salesTable">
                    <thead>
                        <tr>
                            <th scope="col">Order ID</th>
                            <th scope="col"><?= $isAdmin ? 'Date' : 'Time' ?></th>
                            <th scope="col">Customer</th>
                            <th scope="col">Items</th>
                            <th scope="col" class="num">Qty</th>
                            <th scope="col">Payment</th>
                            <th scope="col" class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody id="salesTableBody">
                        <tr>
                            <td colspan="7" class="sales-empty">Loading sales data...</td>
                        </tr>
                    </tbody>
                    <tfoot id="salesTableFoot"></tfoot>
                </table>
            </div>
        </div>

    </main>
</div>

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

<script src="../js/logout.js"></script>
<?php if ($isAdmin): ?>
<script src="../js/chart.umd.min.js?v=1"></script>
<script src="../js/sales_pdf.js?v=4"></script>
<script src="../js/sales_print.js?v=2"></script>
<script src="../js/sales.js?v=20"></script>
<?php else: ?>
<script src="../js/sales_today.js?v=1"></script>
<?php endif; ?>

<script src="../js/admin_notify.js?v=2"></script>
<script src="../js/admin_drawer.js?v=1"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
