<?php
require_once __DIR__ . "/auth_check.php";

header("Content-Type: application/json; charset=utf-8");

/**
 * Send a JSON response and stop.
 */
function respond(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));
    exit;
}

// Only administrators can change stock levels.
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    respond(false, "Access denied. Admin permission is required.", 403);
}

// Accept POST requests only.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Invalid request method.", 405);
}

// Expected body: { "items": [ { "id": 1, "quantity": 5 }, ... ] }
$payload = json_decode(file_get_contents("php://input"), true);
$items = is_array($payload) ? ($payload["items"] ?? null) : null;

if (!is_array($items) || count($items) === 0) {
    respond(false, "No stock changes were submitted.", 400);
}

// A single save should never need more than a few hundred rows.
if (count($items) > 500) {
    respond(false, "Too many items were submitted at once.", 400);
}

// Accept only whole numbers (JSON integers or digit-only strings).
function toWholeNumber($value): ?int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }

    return null;
}

// A quantity with up to 3 decimals (Packs / Liters can be fractions;
// pieces are checked against their unit below).
function toQuantity($value): ?string
{
    if (is_int($value) || is_float($value)) {
        $value = number_format((float) $value, 3, ".", "");
    }

    if (!is_string($value) || !preg_match('/^\d+(\.\d{1,3})?$/', $value)) {
        return null;
    }

    return (float) $value <= 1000000 ? $value : null;
}

// Validate every item before touching the database.
$updates = [];

foreach ($items as $item) {
    $id = is_array($item) ? toWholeNumber($item["id"] ?? null) : null;
    $quantity = is_array($item) ? toQuantity($item["quantity"] ?? null) : null;

    // Quantity must be 0 or more (same rules as inventory_save.php).
    if ($id === null || $id < 1 || $quantity === null) {
        respond(false, "Each quantity must be a number of 0 or more (up to 3 decimals).", 400);
    }

    $updates[$id] = $quantity;
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/stock_log_helpers.php";
require_once __DIR__ . "/units.php";

ensure_inventory_precision_schema($conn);

// Save everything together so a failure never leaves a half-saved count.
try {
    $stmt = $conn->prepare(
        "UPDATE inventory
         SET quantity = ?
         WHERE id = ? AND is_archived = 0"
    );

    $conn->begin_transaction();

    $logEntries = [];

    foreach ($updates as $id => $quantity) {
        $before = stock_snapshot($conn, $id);

        // Pieces are counted in whole numbers.
        if ($before !== null && inventory_unit_is_countable((string) $before["unit"]) && fmod((float) $quantity, 1.0) !== 0.0) {
            throw new DomainException($before["product_name"] . " is counted in pieces, so use a whole number.");
        }

        $stmt->bind_param("si", $quantity, $id);
        $stmt->execute();

        if ($before !== null && $stmt->affected_rows > 0 && (float) $before["quantity"] !== (float) $quantity) {
            $logEntries[] = [$id, $before["product_name"], $before["unit"], (float) $before["quantity"], (float) $quantity];
        }
    }

    $conn->commit();
    $stmt->close();

    // Audit trail (after the save succeeded).
    foreach ($logEntries as [$lid, $lname, $lunit, $lbefore, $lafter]) {
        log_stock_change($conn, $lid, $lname, $lunit, $lbefore, $lafter, "Stock update");
    }
} catch (Throwable $error) {
    // Roll back if a transaction was started; ignore if it never began.
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    if ($error instanceof DomainException) {
        respond(false, $error->getMessage(), 400);
    }

    respond(false, "Unable to save the stock changes. Please try again.", 500);
}

// Build a short stock report from the saved data.
$counts = [];

try {
    $report = $conn->query(
        "SELECT
            SUM(quantity <= 0) AS out_of_stock,
            SUM(quantity > 0 AND quantity <= " . inventory_low_limit_sql() . ") AS low_stock
         FROM inventory
         WHERE is_archived = 0"
    );

    $counts = $report ? $report->fetch_assoc() : [];
} catch (Throwable $ignored) {
    // The save already succeeded; the report is only a convenience.
}

respond(true, "Stock saved.", 200, [
    "updated" => count($updates),
    "low_stock" => (int) ($counts["low_stock"] ?? 0),
    "out_of_stock" => (int) ($counts["out_of_stock"] ?? 0)
]);
