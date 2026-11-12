<?php
session_start();

require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/customer_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function respond($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    respond(false, "POST method required.");
}

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    http_response_code(400);
    respond(false, "Invalid JSON data.");
}

$orderId = filter_var($input["order_id"] ?? null, FILTER_VALIDATE_INT);
$status = trim($input["status"] ?? "");

$allowedStatuses = [
    "Pending",
    "Working",
    "Ready for Taking",
    "Done"
];

if (!$orderId || !in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    respond(false, "Invalid order ID or status.");
}

ensure_customer_order_schema($conn);

// Steps must be followed in order, one at a time:
// Pending -> Working (Preparing) -> Ready for Taking (Ready to Serve) -> Done (Complete).
$rank = array_flip($allowedStatuses);

$cur = $conn->prepare("SELECT status FROM orders WHERE id = ? LIMIT 1");
$cur->bind_param("i", $orderId);
$cur->execute();
$current = $cur->get_result()->fetch_assoc();
$cur->close();

if (!$current) {
    http_response_code(404);
    respond(false, "Order not found.");
}

$currentStatus = (string) $current["status"];

if (isset($rank[$currentStatus]) && $status !== $currentStatus) {
    if ($rank[$status] < $rank[$currentStatus]) {
        http_response_code(409);
        respond(false, "This order has already moved past that step and can't go back.", [
            "status" => $currentStatus
        ]);
    }

    if ($rank[$status] > $rank[$currentStatus] + 1) {
        http_response_code(409);
        respond(false, "Steps can't be skipped. Move the order to the next step first.", [
            "status" => $currentStatus
        ]);
    }
}

// Moving an order to "Ready for Taking" raises the customer's notification;
// moving it anywhere else clears it.
$isReady = ($status === "Ready for Taking");

$stmt = $conn->prepare(
    $isReady
        ? "UPDATE orders SET
               ready_seen = IF(status = 'Ready for Taking', ready_seen, 0),
               ready_at = IF(status = 'Ready for Taking', ready_at, NOW()),
               status = ?
           WHERE id = ?"
        : "UPDATE orders SET status = ?, ready_seen = 1 WHERE id = ?"
);

if (!$stmt) {
    http_response_code(500);
    respond(false, "Unable to prepare the status update.");
}

$stmt->bind_param("si", $status, $orderId);

if (!$stmt->execute()) {
    http_response_code(500);
    respond(false, "Failed to update order status.");
}

if ($stmt->affected_rows === 0) {
    $check = $conn->prepare("SELECT id FROM orders WHERE id = ?");
    $check->bind_param("i", $orderId);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$exists) {
        http_response_code(404);
        $stmt->close();
        respond(false, "Order not found.");
    }
}

$stmt->close();

if ($isReady) {
    try {
        notify_customer_order_ready($conn, $orderId);
    } catch (Throwable $e) {
        error_log("Ready notification failed: " . $e->getMessage());
    }
}

respond(true, "Order status updated successfully.", [
    "order_id" => $orderId,
    "status" => $status
]);
