<?php
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";

// Allow staff and admin to view Order History.
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['admin', 'staff'], true)) {
    header("Location: order.php");
    exit;
}

// Get completed orders only.
$sql = "
    SELECT
        o.id,
        o.order_number,
        o.customer_name,
        o.payment_method,
        o.total_amount,
        o.created_at,
        GROUP_CONCAT(
            CONCAT(oi.product_name, ' × ', oi.quantity)
            SEPARATOR ', '
        ) AS items
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.status = 'Done'
    GROUP BY
        o.id,
        o.order_number,
        o.customer_name,
        o.payment_method,
        o.total_amount,
        o.created_at
    ORDER BY o.created_at DESC, o.id DESC
";

$result = $conn->query($sql);

if (!$result) {
    die("Unable to load order history.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History - D&JC Food Product</title>
    <link rel="stylesheet" href="../css/order_history.css?v=gt1">

</head>
<body>

<div class="history-container">
    <div class="history-header">
        <h1>Order History</h1>

        <a href="order.php" class="back-btn">
            ← Back to Ordering
        </a>
    </div>

    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th>Date</th>
                    <th>Status</th>
                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                        <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($order = $result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars((string) $order['id'], ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $order['customer_name'] ?: 'Walk-in Customer',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $order['items'] ?? 'No items',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $order['payment_method'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                ₱<?= number_format(
                                    (float) $order['total_amount'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $order['created_at'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                 <span class="done-status">Done</span>
                            </td>
                            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                            <td>
                                <button
                                    type="button"
                                    class="delete-history-btn"
                                    data-order-id="<?= (int) $order['id'] ?>">
                                    Delete
                                </button>
                            </td>
                        <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= (($_SESSION['role'] ?? '') === 'admin') ? 8 : 7 ?>" class="empty-row">
                            No completed orders found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Confirm Permanent Order Deletion Modal -->
<div class="modal-overlay" id="deleteHistoryModal">
    <div class="modal-card">
        <h2>Delete Completed Order?</h2>

        <p>
            Are you sure you want to permanently delete this order?
        </p>
        <p>
            This will also delete its associated order items.
            This action cannot be undone.
        </p>

        <p id="deleteHistoryMessage" role="alert"></p>

        <div class="modal-actions">
            <button type="button" id="cancelHistoryDelete">
                Cancel
            </button>

            <button type="button" id="confirmHistoryDelete">
                Yes, Delete Permanently
            </button>
        </div>
    </div>
</div>

<script src="../js/order_history.js?v=2"></script>

</body>
</html>
