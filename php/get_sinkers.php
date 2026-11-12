<?php
/**
 * Which sinkers can be ordered right now (used by the Menu and customer order screens).
 * Returns: { success, portion_grams, sinkers: [{ name, available, grams }] }
 */

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/sinkers.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

try {
    ensure_sinker_inventory($conn);

    echo json_encode([
        "success" => true,
        "portion_grams" => SINKER_PORTION_GRAMS,
        "sinkers" => sinker_availability($conn),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log("get_sinkers: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Could not check the sinker stock."]);
}
