<?php
require_once __DIR__ . "/customer_helpers.php";

if (current_customer_id() === null) {
    header("Location: landing_page.php?auth=login&next=my_orders.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$name = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "";
$first = explode(" ", trim($name))[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Orders - D&amp;JC Food Products</title>
<link rel="stylesheet" href="../css/landing.css?v=gt1">
<link rel="stylesheet" href="../css/customer.css?v=gt1">
<link rel="stylesheet" href="../css/customer_bar.css?v=r5">
<link rel="stylesheet" href="../css/gcash_manual.css?v=1">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body>
<?php $active = "orders"; include __DIR__ . "/customer_header.php"; ?>

<main class="page-main">
    <div class="wrap narrow">
        <h1 class="page-h1">My Orders</h1>
        <p class="page-sub">Track your orders here. We&rsquo;ll let you know as soon as one is ready for pick-up.</p>

        <div id="ordersList" class="orders-list" aria-live="polite">
            <p class="muted">Loading your orders&hellip;</p>
        </div>
    </div>
</main>

<?php include __DIR__ . "/gcash_manual_modal.php"; ?>
<script src="../js/customer_notify.js?v=gt3"></script>
<script src="../js/my_orders.js?v=gt3"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
