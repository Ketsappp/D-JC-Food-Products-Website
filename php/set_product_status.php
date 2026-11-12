<?php
/**
 * Activate or deactivate an inventory item or a menu product.
 * Admin only.
 *
 * POST: product_id, product_type ("inventory" or "menu"),
 *       status ("active" or "inactive")
 *
 * An inactive item is kept (so past orders stay intact) but cannot be used:
 *   - inactive menu product      -> cannot be ordered
 *   - inactive inventory item    -> cannot be used as an ingredient
 *
 * The status is stored in the existing is_archived column
 * (0 = active, 1 = inactive), so no database change is needed.
 */

require_once __DIR__ . "/auth_check.php";

header("Content-Type: application/json; charset=utf-8");

function respond(bool $success, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(["success" => $success, "message" => $message]);
    exit;
}

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    respond(false, "Administrator access required.", 403);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Invalid request method.", 405);
}

require_once __DIR__ . "/db.php";

$productId = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);
$productType = $_POST["product_type"] ?? "";
$status = $_POST["status"] ?? "";

// Only these two tables can be changed.
$tables = [
    "inventory" => "inventory",
    "menu" => "menu_inventory"
];

if (!$productId || $productId < 1 || !isset($tables[$productType])) {
    respond(false, "Invalid product information.", 400);
}

if (!in_array($status, ["active", "inactive"], true)) {
    respond(false, "Invalid status.", 400);
}

$table = $tables[$productType];
$inactive = $status === "inactive" ? 1 : 0;

try {
    $find = $conn->prepare("SELECT is_archived FROM `$table` WHERE id = ? LIMIT 1");
    $find->bind_param("i", $productId);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();

    if (!$row) {
        respond(false, "This item no longer exists.", 404);
    }

    // Nothing to do when it already has the requested status.
    if ((int) $row["is_archived"] === $inactive) {
        respond(true, $inactive ? "Already inactive." : "Already active.");
    }

    $update = $conn->prepare("UPDATE `$table` SET is_archived = ? WHERE id = ?");
    $update->bind_param("ii", $inactive, $productId);
    $update->execute();
    $update->close();

    respond(true, $inactive ? "Deactivated successfully." : "Activated successfully.");

} catch (Throwable $error) {
    error_log("set_product_status.php: " . $error->getMessage());
    respond(false, "Unable to change the status. Please try again.", 500);
}
