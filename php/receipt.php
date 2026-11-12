<?php
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php';

// Only logged-in users can access receipts.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
$reprint = isset($_GET['reprint']) && $_GET['reprint'] === '1';

if (!$orderId || $orderId <= 0) {
    exit('Invalid order ID.');
}

// Retrieve the order.
$stmt = $conn->prepare(
    "SELECT id, order_number, customer_name, payment_method,
            total_amount, payment_status, created_at,
            receipt_printed_at
     FROM orders
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

// GCash receipts are available only after confirmed payment.
// Cash orders are available after being saved successfully.
$isGCash = strtolower($order['payment_method']) === 'gcash';
$isPaid = strtolower($order['payment_status'] ?? '') === 'paid';

if ($isGCash && !$isPaid) {
    http_response_code(403);
    exit('This GCash payment has not been confirmed. No receipt can be printed yet.');
}

// Retrieve the items belonging to this order.
$itemStmt = $conn->prepare(
    "SELECT product_name, unit_price, quantity, total_price
     FROM order_items
     WHERE order_id = ?"
);

$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$itemsResult = $itemStmt->get_result();

$items = [];

while ($item = $itemsResult->fetch_assoc()) {
    $items[] = $item;
}

$itemStmt->close();

if (empty($items)) {
    exit('No items were found for this order.');
}

// Mark the first automatic print attempt.
// Reprinting can be requested explicitly with ?reprint=1.
$shouldAutoPrint = false;

if ($reprint) {
    $shouldAutoPrint = true;
} elseif (empty($order['receipt_printed_at'])) {
    $markStmt = $conn->prepare(
        "UPDATE orders
         SET receipt_printed_at = NOW()
         WHERE id = ?
           AND receipt_printed_at IS NULL"
    );

    $markStmt->bind_param('i', $orderId);
    $markStmt->execute();

    $shouldAutoPrint = ($markStmt->affected_rows === 1);
    $markStmt->close();
}

function receiptEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - D&JC Food Product</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            background: #f3f3f3;
            color: #222;
            font-family: Arial, sans-serif;
        }

        .receipt {
            width: 100%;
            max-width: 380px;
            margin: 0 auto;
            padding: 24px;
            background: #fff;
            border: 1px solid #ddd;
        }

        .center {
            text-align: center;
        }

        .store-name {
            margin: 0 0 6px;
            font-size: 23px;
            font-weight: 800;
        }

        .muted {
            color: #666;
            font-size: 12px;
        }

        .divider {
            margin: 16px 0;
            border: 0;
            border-top: 1px dashed #777;
        }

        .details p {
            margin: 7px 0;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        th, td {
            padding: 8px 3px;
            text-align: left;
            vertical-align: top;
        }

        th:last-child,
        td:last-child {
            text-align: right;
            white-space: nowrap;
        }

        .total-row td {
            padding-top: 12px;
            border-top: 1px dashed #777;
            font-size: 16px;
            font-weight: bold;
        }

        .actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 20px auto;
        }

        .actions button,
        .actions a {
            padding: 10px 15px;
            border: 0;
            border-radius: 6px;
            background: #333;
            color: white;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        .actions a {
            background: #666;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .receipt {
                max-width: none;
                margin: 0;
                padding: 8px;
                border: 0;
            }

            .actions {
                display: none !important;
            }

            @page {
                margin: 5mm;
            }
        }
    </style>
</head>
<body>

<div class="receipt">
    <div class="center">
        <h1 class="store-name">D&JC</h1>
        <p><strong>Food Product</strong></p>
        <p class="muted">Order Receipt</p>
    </div>

    <hr class="divider">

    <div class="details">
        <p>
            <strong>Order No.:</strong>
            <?= receiptEscape($order['order_number']) ?>
        </p>

        <p>
            <strong>Customer:</strong>
            <?= receiptEscape($order['customer_name']) ?>
        </p>

        <p>
            <strong>Date:</strong>
            <?= receiptEscape(date(
                'M d, Y h:i A',
                strtotime($order['created_at'])
            )) ?>
        </p>

        <p>
            <strong>Payment:</strong>
            <?= receiptEscape($order['payment_method']) ?>
        </p>

        <p>
            <strong>Payment Status:</strong>
            <?= $isPaid ? 'Paid' : (
                strtolower($order['payment_method']) === 'cash'
                    ? 'Cash'
                    : receiptEscape($order['payment_status'])
            ) ?>
        </p>
    </div>

    <hr class="divider">

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Qty</th>
                <th>Amount</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?= receiptEscape($item['product_name']) ?><br>
                        <span class="muted">
                            ₱<?= number_format((float) $item['unit_price'], 2) ?>
                            each
                        </span>
                    </td>

                    <td><?= (int) $item['quantity'] ?></td>

                    <td>
                        ₱<?= number_format((float) $item['total_price'], 2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <tr class="total-row">
                <td colspan="2">TOTAL</td>
                <td>
                    ₱<?= number_format((float) $order['total_amount'], 2) ?>
                </td>
            </tr>
        </tbody>
    </table>

    <hr class="divider">

    <div class="center">
        <p><strong>Thank you for ordering!</strong></p>
        <p class="muted">Please keep this receipt for your reference.</p>
    </div>
</div>

<div class="actions">
    <button type="button" onclick="window.print()">Print Receipt</button>

    <a href="order.php">Back to Ordering</a>
</div>

<?php if ($shouldAutoPrint): ?>
<script>
    window.addEventListener('load', function () {
        setTimeout(function () {
            window.print();
        }, 300);
    });
</script>
<?php endif; ?>

</body>
</html>
