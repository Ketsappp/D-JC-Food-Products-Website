<?php
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/units.php";

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Administrator access required."
    ]);
    exit;
}

// Materials (cups, boxes, packaging, ...) are not recipe ingredients.
$sql = "SELECT id, product_name, unit, quantity
        FROM inventory
        WHERE is_archived = 0
          AND category NOT IN ('Materials', 'Matrials', 'Packaging', 'Boxes', 'Sinkers')
        ORDER BY product_name ASC";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to retrieve inventory ingredients."
    ]);
    exit;
}

$ingredients = [];

while ($row = $result->fetch_assoc()) {
    $ingredients[] = [
        "id" => (int) $row["id"],
        "product_name" => $row["product_name"],
        "unit" => $row["unit"],
        // Recipes measure Packs in grams and Liters in milliliters.
        "base_unit" => inventory_unit_info($row["unit"])["base"],
        "quantity" => (float) $row["quantity"]
    ];
}

echo json_encode([
    "success" => true,
    "ingredients" => $ingredients
]);
