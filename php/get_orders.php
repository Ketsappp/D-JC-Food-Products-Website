<?php
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function respond($success, $message, $data = [])
{
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    respond(false, "Invalid request method.");
}

try {
    $sql = "
        SELECT
            o.id,
            o.order_number,
            o.customer_name,
            o.payment_method,
            o.total_amount,
            o.status,
            o.created_at,
            GROUP_CONCAT(
                CONCAT(oi.product_name, ' x', oi.quantity)
                ORDER BY oi.id
                SEPARATOR ', '
            ) AS items
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE (
                o.status <> 'Done'
                OR DATE(o.created_at) = CURDATE()   -- completed orders: today only
              )
        AND ( o.payment_method <> 'GCash'
            OR o.payment_status = 'Paid')
        GROUP BY
            o.id,
            o.order_number,
            o.customer_name,
            o.payment_method,
            o.total_amount,
            o.status,
            o.created_at
        ORDER BY o.created_at ASC, o.id ASC
    ";

    $result = $conn->query($sql);
    $orders = [];

    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }

    respond(true, "Orders loaded successfully.", $orders);

} catch (Throwable $e) {
    error_log("Get orders failed: " . $e->getMessage());

    http_response_code(500);
    respond(false, "Unable to load orders.");
}
?>
