<?php
/**
 * The lines of one of the signed-in customer's earlier orders, so "Order Again"
 * can put them back in the cart.
 *
 *   GET ?order=<order id>
 *
 * Only the customer's OWN orders can be read. The menu page decides what can
 * still be ordered (price, stock and sizes may have changed since).
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/customer_helpers.php";
require_once __DIR__ . "/menu_recipe_helpers.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

function reorder_respond(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra));
    exit;
}

/**
 * The add-ons ("Dine In + Extra Coke") that were saved after the size in the
 * order line's name, e.g. "Mango Graham (Large) + Dine In + Extra Coke".
 */
function reorder_add_ons(string $savedName, string $sizeLabel): string
{
    $rest = "";

    if ($sizeLabel !== "") {
        $marker = " (" . $sizeLabel . ")";
        $position = strpos($savedName, $marker);

        if ($position !== false) {
            $rest = substr($savedName, $position + strlen($marker));
        }
    }

    // No size in the name: whatever follows the first " + ".
    if ($rest === "" && $sizeLabel === "") {
        $position = strpos($savedName, " + ");
        $rest = $position === false ? "" : substr($savedName, $position);
    }

    $rest = trim($rest);

    if (strncmp($rest, "+ ", 2) === 0) {
        $rest = trim(substr($rest, 2));
    }

    return $rest;
}

$userId = current_customer_id();

if ($userId === null) {
    reorder_respond(false, "Please log in to order again.", 401);
}

$orderId = filter_input(INPUT_GET, "order", FILTER_VALIDATE_INT);

if (!$orderId || $orderId < 1) {
    reorder_respond(false, "That order could not be found.", 400);
}

try {
    // Make sure the size column exists (older databases do not have it).
    ensure_menu_size_schema($conn);

    // The order must belong to the signed-in customer.
    $stmt = $conn->prepare(
        "SELECT id, order_number FROM orders WHERE id = ? AND user_id = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $orderId, $userId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        reorder_respond(false, "That order could not be found.", 404);
    }

    $stmt = $conn->prepare(
        "SELECT product_id, product_name, size_label, quantity
         FROM order_items
         WHERE order_id = ?
         ORDER BY id"
    );
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];

    while ($row = $result->fetch_assoc()) {
        $sizeLabel = trim((string) ($row["size_label"] ?? ""));

        $items[] = [
            "product_id" => $row["product_id"] !== null ? (int) $row["product_id"] : null,
            "name" => (string) $row["product_name"],
            "size_label" => $sizeLabel,
            "add_ons" => reorder_add_ons((string) $row["product_name"], $sizeLabel),
            "qty" => max(1, (int) $row["quantity"])
        ];
    }

    $stmt->close();

    reorder_respond(true, "OK", 200, [
        "order_number" => display_order_number($order),
        "items" => $items
    ]);

} catch (Throwable $error) {
    error_log("reorder_data.php: " . $error->getMessage());
    reorder_respond(false, "We couldn't load that order. Please try again.", 500);
}
