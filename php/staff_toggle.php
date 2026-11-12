<?php
/**
 * Admin only: disable or enable a staff account (replaces deleting it).
 * POST: id, action = "disable" | "enable"
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit("Your session has expired. Please log in again.");
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit("Only administrators can change staff accounts.");
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/user_status.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Invalid request.");
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';

if (!$id || $id < 1 || !in_array($action, ['disable', 'enable'], true)) {
    http_response_code(400);
    exit("Invalid request.");
}

ensure_user_status_schema($conn);

$active = $action === 'enable' ? 1 : 0;

$stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'staff'");
$stmt->bind_param("ii", $active, $id);

if (!$stmt->execute()) {
    error_log("staff_toggle failed: " . $stmt->error);
    http_response_code(500);
    exit("Unable to update the staff account. Please try again.");
}

$exists = $stmt->affected_rows > 0;
$stmt->close();

if (!$exists) {
    // affected_rows is 0 when the status was already set, so check the account exists.
    $check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'staff'");
    $check->bind_param("i", $id);
    $check->execute();
    $found = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$found) {
        http_response_code(404);
        exit("Staff not found.");
    }
}

echo $active ? "Staff activated successfully." : "Staff deactivated successfully.";
