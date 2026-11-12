<?php
/**
 * Cart stock check: POST {"items":[{productId,sizeLabel,qty,sinkerMeta},...]}
 * Returns {"ok":true,"max_qty":50,"lines":[{"max":43|null}, ...]} in the same order.
 * Read-only; rate-limited per visitor.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/menu_recipe_helpers.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$now = time();
$window = array_filter($_SESSION["cart_checks"] ?? [], fn($t) => $t > $now - 60);

if (count($window) >= 120) {
    http_response_code(429);
    echo json_encode(["ok" => false, "message" => "Too many checks."]);
    exit;
}

$window[] = $now;
$_SESSION["cart_checks"] = array_values($window);

$data = json_decode(file_get_contents("php://input"), true);
$items = is_array($data["items"] ?? null) ? array_slice($data["items"], 0, 100) : [];
$lines = [];

foreach ($items as $item) {
    if (!is_array($item)) {
        echo json_encode(["ok" => false]);
        exit;
    }
    $lines[] = [
        "product_id" => (int) ($item["productId"] ?? 0),
        "size_label" => trim((string) ($item["sizeLabel"] ?? "")),
        "quantity" => max(0, (int) ($item["qty"] ?? 0)),
        "sinkers" => sinkers_from_text((string) ($item["sinkerMeta"] ?? ""))
    ];
}

try {
    $capacity = cart_stock_capacity($conn, $lines);
    echo json_encode(["ok" => true, "max_qty" => MAX_ORDER_QTY, "lines" => array_values($capacity)]);
} catch (Throwable $e) {
    error_log("check_cart_stock.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false]);
}
$conn->close();
