<?php
/**
 * Save the low-stock limits (one per unit). Admin only.
 * Used by "Edit limits" on the Inventory page.
 *
 * POST: limit_packs, limit_liters, limit_kg, limit_pcs
 * Answers JSON: { success, message, limits }
 */

require_once __DIR__ . "/auth_check.php";

header("Content-Type: application/json; charset=utf-8");

function respond(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra));
    exit;
}

if (($_SESSION["role"] ?? "") !== "admin") {
    respond(false, "Administrator access required.", 403);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Invalid request method.", 405);
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/units.php";

// field name => unit
$fields = [
    "limit_packs"  => "Packs",
    "limit_liters" => "Liters",
    "limit_kg"     => "kg",
    "limit_pcs"    => "Pcs"
];

$values = [];

foreach ($fields as $field => $unit) {
    $raw = trim((string) ($_POST[$field] ?? ""));

    // Pieces are whole numbers; the other units allow up to 3 decimals.
    $pattern = $unit === "Pcs" ? '/^\d{1,7}$/' : '/^\d{1,7}(\.\d{1,3})?$/';

    if (!preg_match($pattern, $raw)) {
        respond(
            false,
            $unit === "Pcs"
                ? "Pcs limit must be a whole number (0 or more)."
                : "{$unit} limit must be a number (0 or more, up to 3 decimals).",
            400
        );
    }

    if ((float) $raw > 1000000) {
        respond(false, "{$unit} limit is too large (the most is 1,000,000).", 400);
    }

    $values[$unit] = $raw;
}

try {
    inventory_low_thresholds($conn);   // makes sure the table exists

    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "INSERT INTO inventory_low_thresholds (unit, low_limit) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE low_limit = VALUES(low_limit)"
    );

    foreach ($values as $unit => $limit) {
        $stmt->bind_param("ss", $unit, $limit);
        $stmt->execute();
    }

    $stmt->close();
    $conn->commit();

    respond(true, "Low-stock limits saved.", 200, [
        "limits" => inventory_low_thresholds($conn, true)
    ]);
} catch (Throwable $error) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log("inventory_thresholds_save.php: " . $error->getMessage());
    respond(false, "Unable to save the limits. Please try again.", 500);
}
