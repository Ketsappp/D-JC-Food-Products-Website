<?php
session_start();
require_once __DIR__ . "/auth_check.php";

if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: order.php");
    exit;
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/user_status.php";
require_once __DIR__ . "/email_verification.php";

ensure_user_status_schema($conn);
ensure_email_verification_schema($conn);

$result = $conn->query(
    "SELECT id, username, full_name, email, contact_no, created_at, is_active, email_verified_at
     FROM users
     WHERE role = 'customer'
     ORDER BY created_at DESC, id DESC"
);

$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}

$total = count($customers);
$blocked = count(array_filter($customers, fn($c) => (int) $c["is_active"] !== 1));

function h2(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customers - D&JC Food Products</title>
<link rel="stylesheet" href="../css/dashboard.css?v=gt1">
<link rel="stylesheet" href="../css/staff.css?v=gt1">
<link rel="stylesheet" href="../css/customers.css?v=gt1">
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
            <?php $adminActive = 'customers'; include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- === Main content === -->
    <main class="main-content">
        <div class="page-header">
            <h1>Customer Accounts</h1>
        </div>

        <div id="alertBox" class="alert-box hidden"></div>

        <div class="panel customers-panel">
            <div class="customers-toolbar">
                <input type="search" id="customerSearch" placeholder="Search username, name, email or phone..."
                       aria-label="Search customers">

                <div class="cust-filter" role="group" aria-label="Filter customers by status">
                    <button type="button" class="cust-filter-btn active" data-status="">All <span><?= $total ?></span></button>
                    <button type="button" class="cust-filter-btn" data-status="active">Active <span><?= $total - $blocked ?></span></button>
                    <button type="button" class="cust-filter-btn" data-status="blocked">Blocked <span><?= $blocked ?></span></button>
                </div>
            </div>

            <div class="customers-table-wrap">
                <table class="customers-table" id="customersTable">
                    <thead>
                        <tr>
                            <th scope="col">Username</th>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Phone Number</th>
                            <th scope="col">Registered</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="act-col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="customersBody">
                    <?php foreach ($customers as $c): $on = (int) $c["is_active"] === 1; ?>
                        <tr data-id="<?= (int) $c["id"] ?>" data-active="<?= $on ? "1" : "0" ?>"
                            data-search="<?= h2(mb_strtolower($c["username"] . " " . $c["full_name"] . " " . $c["email"] . " " . $c["contact_no"])) ?>"
                            data-name="<?= h2($c["full_name"]) ?>"
                            class="<?= $on ? "" : "is-blocked" ?>">
                            <td data-label="Username"><?= h2($c["username"]) ?></td>
                            <td data-label="Name"><?= h2($c["full_name"]) ?></td>
                            <td data-label="Email">
                                <a href="mailto:<?= h2($c["email"]) ?>"><?= h2($c["email"]) ?></a>
                                <?php if (empty($c["email_verified_at"])): ?>
                                    <span class="unconfirmed" title="Email not confirmed yet">unconfirmed</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Phone Number"><?= h2($c["contact_no"]) ?: "&mdash;" ?></td>
                            <td data-label="Registered"><?= $c["created_at"] ? h2(date("M j, Y", strtotime($c["created_at"]))) : "&mdash;" ?></td>
                            <td data-label="Status"><span class="status-badge <?= $on ? "is-enabled" : "is-off" ?>"><?= $on ? "Active" : "Blocked" ?></span></td>
                            <td class="act-col">
                                <button type="button" class="cust-btn <?= $on ? "block-btn" : "unblock-btn" ?>">
                                    <?= $on ? "&#9940; Block" : "&#10003; Unblock" ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$customers): ?>
                        <tr class="no-data"><td colspan="7" class="cust-empty">No customer accounts yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <p id="noMatch" class="cust-empty" hidden>No customers match your search.</p>
            </div>
            <p class="cust-count" id="custCount"></p>
        </div>
    </main>
</div>

<!-- Block / Unblock confirmation -->
<div id="blockModal" class="modal-overlay hidden">
    <div class="modal-box small-modal">
        <h3 id="blockModalTitle">Block this customer?</h3>
        <p class="modal-sub" id="blockModalText"></p>
        <div class="modal-actions">
            <a href="#" id="cancelBlock" class="btn-cancel">Cancel</a>
            <a href="#" id="confirmBlock" class="btn-confirm">Confirm</a>
        </div>
    </div>
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
<script src="../js/customers.js?v=1"></script>
<script src="../js/admin_notify.js?v=2"></script>
<script src="../js/admin_drawer.js?v=1"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
