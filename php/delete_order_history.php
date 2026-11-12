<?php
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function respond($success, $message, $statusCode = 200)
{
    http_response_code($statusCode);

    echo json_encode([
        "success" => $success,
        "message" => $message
    ]);

    exit;
}

// Only allow POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Invalid request method.", 405);
}

// Only administrators can permanently delete orders.
if (($_SESSION["role"] ?? "") !== "admin") {
    respond(false, "You are not authorized to delete orders.", 403);
}

// Read the requested order ID.
$data = json_decode(file_get_contents("php://input"), true);
$orderId = filter_var(
    $data["order_id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if ($orderId === false || $orderId === null) {
    respond(false, "Invalid order ID.", 400);
}

try {
    $conn->begin_transaction();

    // Only completed orders can be deleted from Order History.
    $check = $conn->prepare(
        "SELECT id FROM orders WHERE id = ? AND status = 'Done' FOR UPDATE"
    );
    $check->bind_param("i", $orderId);
    $check->execute();
    $check->store_result();

    if ($check->num_rows === 0) {
        $check->close();
        $conn->rollback();

        respond(false, "Completed order not found.", 404);
    }

    $check->close();

    // Delete the order's associated items first.
    $deleteItems = $conn->prepare(
        "DELETE FROM order_items WHERE order_id = ?"
    );
    $deleteItems->bind_param("i", $orderId);
    $deleteItems->execute();
    $deleteItems->close();

    // Delete the completed order.
    $deleteOrder = $conn->prepare(
        "DELETE FROM orders WHERE id = ? AND status = 'Done'"
    );
    $deleteOrder->bind_param("i", $orderId);
    $deleteOrder->execute();

    if ($deleteOrder->affected_rows !== 1) {
        $deleteOrder->close();
        throw new Exception("The order could not be deleted.");
    }

    $deleteOrder->close();

    $conn->commit();

    respond(true, "Completed order deleted successfully.");

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // Preserve the original error.
    }

    error_log("Order history deletion failed: " . $e->getMessage());

    respond(false, "Unable to delete the order. Please try again.", 500);
}
?>
