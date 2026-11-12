<?php
/**
 * Cancel an order that is still waiting for its PayMongo payment.
 * Called by the menu page (customer) and the staff order page when the person comes Back
 * from the PayMongo checkout. A customer can only cancel their own order; staff / admin can
 * cancel the orders placed at the POS (orders without a customer account).
 * POST (JSON): order_id
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/customer_helpers.php";
require_once __DIR__ . "/gcash_payment_window.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "POST method required."]);
    exit;
}

$isStaff = in_array($_SESSION["role"] ?? "", ["admin", "staff"], true);
$userId = $isStaff ? null : current_customer_id();

if (!$isStaff && $userId === null) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Please log in again."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$orderId = filter_var($input["order_id"] ?? null, FILTER_VALIDATE_INT);

if (!$orderId || $orderId < 1) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid order."]);
    exit;
}

echo json_encode([
    "success" => true,
    "cancelled" => cancel_unpaid_paymongo_order($conn, (int) $orderId, $userId),
]);
