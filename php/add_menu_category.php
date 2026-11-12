<?php
/**
 * Add a menu category (Mango Series, Pizza Series ...). Admin only.
 * Used by the "Add Category" button on the Menu page.
 *
 * POST: name, is_food (1 for a food category such as pizza; 0 for a cup drink)
 * Answers JSON: { success, message, category: { name, is_food } }
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
require_once __DIR__ . "/menu_categories.php";

// One tidy line: no leading / trailing / double spaces.
$name = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST["name"] ?? "")));
$isFood = (($_POST["is_food"] ?? "0") === "1") ? 1 : 0;

if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
    respond(false, "Category name must be 2 to 50 characters.", 400);
}

// Letters, numbers, spaces and a few marks (e.g. "Rice Meals", "Pasta & Salad", "Kids' Menu").
if (!preg_match("/^[\p{L}\p{N}][\p{L}\p{N} &'’().,\/-]*$/u", $name)) {
    respond(false, "Category name can only use letters, numbers, spaces and & ' ( ) . , / -", 400);
}

// The same name (capitals ignored) cannot be added twice.
foreach (menu_category_names($conn) as $existing) {
    if (mb_strtolower($existing) === mb_strtolower($name)) {
        respond(false, "The category \"{$existing}\" already exists.", 409);
    }
}

try {
    // New categories go after the current ones (older, product-only categories sit at 100).
    $next = (int) $conn->query(
        "SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM menu_categories WHERE sort_order < 100"
    )->fetch_assoc()["next_order"];

    $stmt = $conn->prepare("INSERT INTO menu_categories (name, is_food, sort_order) VALUES (?, ?, ?)");
    $stmt->bind_param("sii", $name, $isFood, $next);
    $stmt->execute();
    $stmt->close();

    respond(true, "Category \"{$name}\" added.", 200, [
        "category" => ["name" => $name, "is_food" => (bool) $isFood]
    ]);
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        respond(false, "That category already exists.", 409);
    }

    error_log("add_menu_category.php: " . $error->getMessage());
    respond(false, "Unable to add the category. Please try again.", 500);
} catch (Throwable $error) {
    error_log("add_menu_category.php: " . $error->getMessage());
    respond(false, "Unable to add the category. Please try again.", 500);
}
