<?php
/**
 * Admin only: block or unblock a CUSTOMER account.
 * Blocking ends their access right away (they are signed out on their next
 * request and cannot log in) but nothing is deleted, so order history and
 * sales records stay intact.
 * POST: id, action = "block" | "unblock"
 */
session_start();

header("Content-Type: application/json; charset=UTF-8");

function cust_json(bool $ok, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(["success" => $ok, "message" => $message]);
    exit;
}

if (!isset($_SESSION["user_id"])) {
    cust_json(false, "Your session has expired. Please log in again.", 401);
}

if (($_SESSION["role"] ?? "") !== "admin") {
    cust_json(false, "Only administrators can manage customer accounts.", 403);
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/user_status.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    cust_json(false, "Invalid request.", 405);
}

$id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
$action = $_POST["action"] ?? "";

if (!$id || $id < 1 || !in_array($action, ["block", "unblock"], true)) {
    cust_json(false, "Invalid request.", 400);
}

ensure_user_status_schema($conn);

$check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'customer'");
$check->bind_param("i", $id);
$check->execute();
$found = $check->get_result()->num_rows > 0;
$check->close();

if (!$found) {
    cust_json(false, "Customer not found.", 404);
}

$active = $action === "unblock" ? 1 : 0;

try {
    $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'customer'");
    $stmt->bind_param("ii", $active, $id);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    error_log("customer_toggle failed: " . $e->getMessage());
    cust_json(false, "Unable to update the customer account. Please try again.", 500);
}

cust_json(true, $active ? "Customer unblocked." : "Customer blocked. They can no longer log in.");
