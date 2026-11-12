<?php
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gcash_payment_window.php';

// Cancel the order here if its payment time has just run out.
void_expired_gcash_orders($conn);

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
$cancelled = isset($_GET['cancelled']);

// The customer pressed Cancel on the PayMongo checkout: cancel their unpaid order now.
if ($cancelled && $orderId && $orderId > 0 && (int) ($_SESSION['user_id'] ?? 0) > 0) {
    if (($_SESSION['role'] ?? '') === 'customer') {
        cancel_unpaid_paymongo_order($conn, (int) $orderId, (int) $_SESSION['user_id']);
    } elseif (in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
        // Staff-placed orders have no customer account.
        cancel_unpaid_paymongo_order($conn, (int) $orderId, null);
    }
}

$order = null;

if ($orderId && $orderId > 0) {
    $stmt = $conn->prepare(
        "SELECT id, order_number, total_amount, payment_status, status
         FROM orders
         WHERE id = ? AND payment_method = 'GCash'
         LIMIT 1"
    );

    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$isVoided = $order && (
    $order['status'] === 'Cancelled' ||
    strtolower(trim((string)($order['payment_status'] ?? ''))) === 'voided'
);

$isPaid = $order &&
    strtolower(trim((string)($order['payment_status'] ?? ''))) === 'paid';

// Redirect only when the database confirms payment.
// receipt.php performs its own payment-status check as well.
$isStaffUser = in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true);

if ($isPaid && $isStaffUser) {
    header(
        'Location: receipt.php?order_id=' . (int)$order['id'],
        true,
        303
    );
    exit;
}

function gcashEscape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash Payment Status - D&JC</title>

    <?php if ($order && !$cancelled && !$isPaid && !$isVoided): ?>
        <meta http-equiv="refresh" content="3">
    <?php endif; ?>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }

        .payment-box {
            background: white;
            width: 90%;
            max-width: 450px;
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        h1 { font-size: 24px; }

        p {
            line-height: 1.6;
            color: #444;
        }

        .button {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 20px;
            background: #0879e8;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <script>
        // Done with PayMongo (paid or cancelled): the menu page must not cancel this order.
        try { window.sessionStorage.removeItem('djcPayOrder'); } catch (e) {}
    </script>
    <div class="payment-box">
        <?php if (!$order): ?>
            <h1>Payment Status</h1>
            <p>
                We couldn't find your order details.
                Please check your order in the system or contact the staff.
            </p>

        <?php elseif ($isPaid): ?>
            <h1>Payment Confirmed</h1>
            <p>Thank you! Your GCash payment was verified.</p>
            <p>Order number: <strong><?= gcashEscape($order['order_number']) ?></strong></p>
            <p>We&rsquo;ll notify you once your order is ready for pick-up.</p>
            <a class="button" href="my_orders.php">View My Orders</a>

        <?php elseif ($cancelled && $isVoided): ?>
            <h1>Order Cancelled</h1>
            <p>
                You left the checkout without paying, so the order was cancelled.
                You have not been charged.
            </p>
            <p>
                Order number:
                <strong><?= gcashEscape($order['order_number']) ?></strong>
            </p>
            <p>You can place the order again from the menu.</p>
            <a class="button" href="customerorder.php">Back to the menu</a>

        <?php elseif ($isVoided): ?>
            <h1>Order Cancelled</h1>
            <p>
                This order was cancelled because the GCash payment was not
                completed in time (<?= (int) GCASH_PAYMENT_MINUTES ?> minutes, plus
                <?= (int) GCASH_LATE_PAYMENT_MINUTES ?> minutes for late payments).
                You have not been charged for it.
            </p>
            <p>
                Order number:
                <strong><?= gcashEscape($order['order_number']) ?></strong>
            </p>
            <p>You can place the order again from the menu.</p>

        <?php elseif ($cancelled): ?>
            <h1>Payment Cancelled</h1>
            <p>
                You returned from checkout without a confirmed payment.
                No receipt will be printed unless payment is later confirmed.
            </p>
            <p>
                Order number:
                <strong><?= gcashEscape($order['order_number']) ?></strong>
            </p>

        <?php else: ?>
            <h1>Payment Is Being Verified</h1>
            <p>
                We're checking whether PayMongo has confirmed your payment.
                This page will check again automatically every three seconds.
            </p>
            <p>
                Order number:
                <strong><?= gcashEscape($order['order_number']) ?></strong>
            </p>
            <p>
                Total:
                <strong>₱<?= number_format((float)$order['total_amount'], 2) ?></strong>
            </p>
            <p>Please keep this page open while verification continues.</p>
        <?php endif; ?>

        <a class="button" href="<?= $isStaffUser ? 'order.php' : 'customerorder.php' ?>"><?= $isStaffUser ? 'Back to Ordering' : 'Back to Menu' ?></a>
    </div>
</body>
</html>
