<?php
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/menu_recipe_helpers.php";
require_once __DIR__ . "/units.php";
ensure_menu_size_schema($conn);

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Administrator access required."
    ]);
    exit;
}

$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);

if (!$productId || $productId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid menu product ID."
    ]);
    exit;
}

try {
    // Load active inventory items that can be assigned as ingredients.
    // Materials (cups, boxes, packaging, ...) are not ingredients, but an
    // item already used by this product's recipe is always kept in the list.
    $ingredients = [];

    $ingredientStmt = $conn->prepare("
        SELECT id, product_name, unit, quantity
        FROM inventory
        WHERE COALESCE(is_archived, 0) = 0
          AND (
                category NOT IN ('Materials', 'Matrials', 'Packaging', 'Boxes', 'Sinkers')
                OR id IN (
                    SELECT inventory_product_id
                    FROM menu_product_ingredients
                    WHERE menu_product_id = ?
                )
          )
        ORDER BY product_name ASC
    ");

    if (!$ingredientStmt) {
        throw new Exception("Unable to load inventory ingredients.");
    }

    $ingredientStmt->bind_param("i", $productId);
    $ingredientStmt->execute();
    $ingredientResult = $ingredientStmt->get_result();

    if (!$ingredientResult) {
        throw new Exception("Unable to load inventory ingredients.");
    }

    $factors = [];

    while ($row = $ingredientResult->fetch_assoc()) {
        $info = inventory_unit_info($row["unit"] ?? "pcs");
        $factors[(int) $row["id"]] = $info["factor"];

        $ingredients[] = [
            "id" => (int) $row["id"],
            "name" => $row["product_name"],
            // How the stock is counted (packs, liters, pcs) ...
            "unit" => $row["unit"] ?? "pcs",
            // ... and how a recipe measures it (grams, milliliters, pcs).
            "base_unit" => $info["base"],
            "stock" => (float) $row["quantity"]
        ];
    }

    // The recipe is stored in the inventory unit (packs / liters); the admin sees and types
    // grams / milliliters.
    $toBase = function ($value, int $ingredientId) use ($factors) {
        return $value === null ? null : round((float) $value * ($factors[$ingredientId] ?? 1.0), 3);
    };

    // Load this menu product's existing recipe.
    $recipe = [];

    $recipeStmt = $conn->prepare("
        SELECT inventory_product_id, quantity_required,
               quantity_small, quantity_medium, quantity_large
        FROM menu_product_ingredients
        WHERE menu_product_id = ?
        ORDER BY id ASC
    ");

    if (!$recipeStmt) {
        throw new Exception("Unable to load the product recipe.");
    }

    $recipeStmt->bind_param("i", $productId);
    $recipeStmt->execute();

    $recipeResult = $recipeStmt->get_result();

    while ($row = $recipeResult->fetch_assoc()) {
        $ingredientId = (int) $row["inventory_product_id"];

        $recipe[] = [
            "inventory_product_id" => $ingredientId,
            "quantity_required" => $toBase($row["quantity_required"], $ingredientId),

            // Per-size quantities (null when the product has no sizes).
            "quantity_small" => $toBase($row["quantity_small"], $ingredientId),
            "quantity_medium" => $toBase($row["quantity_medium"], $ingredientId),
            "quantity_large" => $toBase($row["quantity_large"], $ingredientId)
        ];
    }

    $recipeStmt->close();

    // Drinks: the cup and straw are deducted automatically (see apply_menu_recipe_rules), so
    // they are not shown as ingredients. They are added again when the product is saved.
    $categoryStmt = $conn->prepare("SELECT category FROM menu_inventory WHERE id = ? LIMIT 1");
    $categoryStmt->bind_param("i", $productId);
    $categoryStmt->execute();
    $productRow = $categoryStmt->get_result()->fetch_assoc();
    $categoryStmt->close();

    if ($productRow && !menu_category_is_food($conn, (string) $productRow["category"])) {
        $auto = array_flip(serving_material_ids($conn));

        $ingredients = array_values(array_filter(
            $ingredients,
            fn($item) => !isset($auto[$item["id"]])
        ));

        $recipe = array_values(array_filter(
            $recipe,
            fn($row) => !isset($auto[$row["inventory_product_id"]])
        ));
    }

    echo json_encode([
        "success" => true,
        "ingredients" => $ingredients,
        "recipe" => $recipe
    ]);

} catch (Throwable $error) {
    error_log("get_menu_recipe.php: " . $error->getMessage());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to load the product recipe."
    ]);
}

$conn->close();
?>
