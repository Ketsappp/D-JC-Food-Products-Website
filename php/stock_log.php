<?php
session_start();
require_once __DIR__ . "/auth_check.php";

if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: order.php");
    exit;
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/stock_log_helpers.php";

ensure_stock_log_schema($conn);

$logs = [];
$res = $conn->query(
    "SELECT id, item_name, unit, change_type, quantity_before, quantity_change, quantity_after, source, user_name, created_at
     FROM stock_log
     ORDER BY created_at DESC, id DESC
     LIMIT 1000"
);
while ($row = $res->fetch_assoc()) {
    $logs[] = $row;
}

function h3(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
}

function qty(string|float|null $n): string
{
    $n = (float) $n;
    return rtrim(rtrim(number_format($n, 2, ".", ","), "0"), ".");
}

$counts = ["" => count($logs), "added" => 0, "new" => 0, "reduced" => 0];
foreach ($logs as $l) {
    if ($l["change_type"] === "Stock added") $counts["added"]++;
    elseif ($l["change_type"] === "New item") $counts["new"]++;
    else $counts["reduced"]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock log - D&JC Food Products</title>
<link rel="stylesheet" href="../css/dashboard.css?v=gt1">
<link rel="stylesheet" href="../css/staff.css?v=gt1">
<link rel="stylesheet" href="../css/customers.css?v=gt1">
<link rel="stylesheet" href="../css/stock_log.css?v=gt1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="../css/admin-theme.css?v=sc2">
<link rel="stylesheet" href="../css/admin-drawer.css?v=1">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body>

<div class="app-layout">

            <?php $adminActive = 'log'; include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1>Stock Log</h1>
        </div>
        <p class="log-intro">A record of every time stock was added, changed or reduced by hand &mdash; who did it, when, and by how much.</p>

        <div class="panel customers-panel">
            <div class="customers-toolbar">
                <input type="search" id="logSearch" placeholder="Search item or user..." aria-label="Search the stock log">

                <div class="log-dates">
                    <label>From <input type="date" id="logFrom"></label>
                    <label>To <input type="date" id="logTo"></label>
                    <button type="button" id="logClear" class="cust-filter-btn">Clear</button>
                </div>
            </div>

            <div class="cust-filter log-types" role="group" aria-label="Filter by type">
                <button type="button" class="cust-filter-btn active" data-type="">All <span><?= $counts[""] ?></span></button>
                <button type="button" class="cust-filter-btn" data-type="Stock added">Stock added <span><?= $counts["added"] ?></span></button>
                <button type="button" class="cust-filter-btn" data-type="New item">New item <span><?= $counts["new"] ?></span></button>
                <button type="button" class="cust-filter-btn" data-type="Stock reduced">Stock reduced <span><?= $counts["reduced"] ?></span></button>
            </div>

            <div class="customers-table-wrap">
                <table class="customers-table log-table">
                    <thead>
                        <tr>
                            <th scope="col">Date &amp; Time</th>
                            <th scope="col">Item</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="num">Before</th>
                            <th scope="col" class="num">Change</th>
                            <th scope="col" class="num">After</th>
                            <th scope="col">Done by</th>
                            <th scope="col">Where</th>
                        </tr>
                    </thead>
                    <tbody id="logBody">
                    <?php foreach ($logs as $l):
                        $ts = strtotime($l["created_at"]);
                        $chg = (float) $l["quantity_change"];
                        $cls = $l["change_type"] === "Stock reduced" ? "neg" : "pos";
                        $unit = h3($l["unit"]);
                    ?>
                        <tr data-type="<?= h3($l["change_type"]) ?>" data-date="<?= date("Y-m-d", $ts) ?>"
                            data-search="<?= h3(mb_strtolower($l["item_name"] . " " . $l["user_name"])) ?>">
                            <td data-label="Date &amp; Time"><?= h3(date("M j, Y g:i A", $ts)) ?></td>
                            <td data-label="Item"><strong><?= h3($l["item_name"]) ?></strong></td>
                            <td data-label="Type"><span class="log-type <?= $cls ?>"><?= h3($l["change_type"]) ?></span></td>
                            <td data-label="Before" class="num"><?= qty($l["quantity_before"]) ?> <?= $unit ?></td>
                            <td data-label="Change" class="num chg <?= $cls ?>"><?= ($chg > 0 ? "+" : "") . qty($chg) ?> <?= $unit ?></td>
                            <td data-label="After" class="num"><?= qty($l["quantity_after"]) ?> <?= $unit ?></td>
                            <td data-label="Done by"><?= h3($l["user_name"]) ?: "&mdash;" ?></td>
                            <td data-label="Where"><?= h3($l["source"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$logs): ?>
                        <tr class="no-data"><td colspan="8" class="cust-empty">No stock changes have been recorded yet. They will appear here whenever stock is added or changed.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <p id="logNoMatch" class="cust-empty" hidden>No log entries match your filters.</p>
            </div>
            <p class="cust-count" id="logCount"></p>
        </div>
    </main>
</div>

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
<script src="../js/stock_log.js?v=1"></script>
<script src="../js/admin_notify.js?v=2"></script>
<script src="../js/admin_drawer.js?v=1"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
