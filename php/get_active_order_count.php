<?php
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/gcash_payment_window.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// Void unpaid GCash orders whose time has run out so they are not counted.
void_expired_gcash_orders($conn);

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM orders
    WHERE status IN ('Pending', 'Working', 'Ready for Taking')
");

if (!$result) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to retrieve active order count."
    ]);
    exit;
}

$row = $result->fetch_assoc();

echo json_encode([
    "success" => true,
    "count" => (int) ($row["total"] ?? 0)
]);
