<?php
/** Marks the customer's ready orders as seen (clears the notification). */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/customer_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$userId = current_customer_id();

if ($userId === null || $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code($userId === null ? 401 : 405);
    echo json_encode(["success" => false]);
    exit;
}

ensure_customer_order_schema($conn);

$stmt = $conn->prepare("UPDATE orders SET ready_seen = 1 WHERE user_id = ? AND ready_seen = 0");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->close();

echo json_encode(["success" => true]);
