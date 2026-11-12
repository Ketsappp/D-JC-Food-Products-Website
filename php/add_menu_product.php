<?php
/**
 * Add a MENU product together with its ingredients (recipe).
 * Admin only. Used by the "Add Product" button on the Menu page.
 *
 * POST (multipart/form-data):
 *   product_name, category, selling_price, image (optional),
 *   ingredient_inventory_id[], ingredient_quantity[]
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
require_once __DIR__ . "/menu_recipe_helpers.php";

// ---------------------------------------------------------------------
// 1. Validate the form data
// ---------------------------------------------------------------------
$name = trim($_POST["product_name"] ?? "");
$category = $_POST["category"] ?? "";

// Any category in the menu_categories table (new ones are added with "Add Category").
require_once __DIR__ . "/menu_categories.php";
$allowedCategories = menu_category_names($conn);

if ($name === "" || mb_strlen($name) > 255) {
    respond(false, "Please enter a product name (up to 255 characters).", 400);
}

if (!in_array($category, $allowedCategories, true)) {
    respond(false, "Please choose a valid category.", 400);
}

// Food categories (like pizza) are sold in one size only: ignore the "different sizes" switch.
if (menu_category_is_food($conn, $category)) {
    $_POST["has_sizes"] = "0";
}

// Price(s) and ingredients, with or without sizes.
try {
    $form = parse_menu_product_form($_POST);

    // Grams / milliliters -> inventory units (packs, liters), and the automatic cup and
    // straw for drinks. Without this the typed numbers would be saved as they are.
    $form = apply_menu_recipe_rules($conn, $form, $category);
} catch (InvalidArgumentException $error) {
    respond(false, $error->getMessage(), 400);
}

// ---------------------------------------------------------------------
// 2. Validate and store the optional image
// ---------------------------------------------------------------------
$imageName = "";
$imagePath = "";

if (isset($_FILES["image"]) && $_FILES["image"]["error"] !== UPLOAD_ERR_NO_FILE) {

    if ($_FILES["image"]["error"] !== UPLOAD_ERR_OK) {
        respond(false, "Image upload failed. Please try again.", 400);
    }

    $allowedImages = [
        "jpg"  => "image/jpeg",
        "jpeg" => "image/jpeg",
        "png"  => "image/png",
        "gif"  => "image/gif",
        "webp" => "image/webp"
    ];

    $tmpName = $_FILES["image"]["tmp_name"];
    $extension = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));

    if (!isset($allowedImages[$extension])) {
        respond(false, "Invalid image type. Upload JPG, PNG, GIF, or WEBP only.", 400);
    }

    if (!is_uploaded_file($tmpName)) {
        respond(false, "Invalid file upload.", 400);
    }

    $imageInfo = @getimagesize($tmpName);

    if ($imageInfo === false || ($imageInfo["mime"] ?? "") !== $allowedImages[$extension]) {
        respond(false, "The uploaded file is not a valid image.", 400);
    }

    $uploadDir = dirname(__DIR__) . "/uploads/products/";

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
        respond(false, "Unable to create the upload folder.", 500);
    }

    if (!is_writable($uploadDir)) {
        respond(false, "The upload folder is not writable.", 500);
    }

    $imageName = uniqid("product_", true) . "." . $extension;
    $imagePath = $uploadDir . $imageName;

    if (!move_uploaded_file($tmpName, $imagePath)) {
        respond(false, "Unable to save the uploaded image.", 500);
    }
}

// ---------------------------------------------------------------------
// 3. Save the product and its recipe together
// ---------------------------------------------------------------------
// Add the size columns if this database does not have them yet.
ensure_menu_size_schema($conn);

try {

    // Block a second product with the same name in the same category.
    $duplicate = $conn->prepare(
        "SELECT id
         FROM menu_inventory
         WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))
           AND category = ?
           AND is_archived = 0
         LIMIT 1"
    );

    $duplicate->bind_param("ss", $name, $category);
    $duplicate->execute();
    $isDuplicate = $duplicate->get_result()->num_rows > 0;
    $duplicate->close();

    if ($isDuplicate) {
        throw new DomainException("A product named \"{$name}\" already exists in {$category}.");
    }

    // Every ingredient must be an active inventory item.
    $check = $conn->prepare(
        "SELECT id FROM inventory WHERE id = ? AND is_archived = 0 LIMIT 1"
    );

    foreach (array_keys($form['recipe']) as $ingredientId) {
        $check->bind_param("i", $ingredientId);
        $check->execute();

        if ($check->get_result()->num_rows === 0) {
            throw new DomainException(
                "One of the selected ingredients no longer exists in the inventory."
            );
        }
    }

    $check->close();

    $conn->begin_transaction();

    // Columns that are not on the form get neutral defaults.
    $insert = $conn->prepare(
        "INSERT INTO menu_inventory
            (product_name, image, category, quantity, unit, unit_cost,
             selling_price, small_price, medium_price, large_price,
             description, is_available)
         VALUES (?, ?, ?, 0, 'pcs', 0, ?, ?, ?, ?, '', 1)"
    );

    $sellingPrice = $form['selling_price'];
    $smallPrice = $form['prices']['small'];
    $mediumPrice = $form['prices']['medium'];
    $largePrice = $form['prices']['large'];

    $insert->bind_param(
        "sssdddd",
        $name,
        $imageName,
        $category,
        $sellingPrice,
        $smallPrice,
        $mediumPrice,
        $largePrice
    );
    $insert->execute();

    $menuProductId = (int) $conn->insert_id;
    $insert->close();

    $link = $conn->prepare(
        "INSERT INTO menu_product_ingredients
            (menu_product_id, inventory_product_id, quantity_required,
             quantity_small, quantity_medium, quantity_large)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    foreach ($form['recipe'] as $ingredientId => $quantities) {
        $base = $quantities['base'];
        $small = $quantities['small'];
        $medium = $quantities['medium'];
        $large = $quantities['large'];

        $link->bind_param(
            "iidddd",
            $menuProductId,
            $ingredientId,
            $base,
            $small,
            $medium,
            $large
        );

        $link->execute();
    }

    $link->close();
    $conn->commit();
    $conn->close();

    respond(true, "Menu product added successfully.");

} catch (Throwable $error) {

    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    // Do not leave an orphaned image behind.
    if ($imagePath !== "" && is_file($imagePath)) {
        @unlink($imagePath);
    }

    if ($error instanceof DomainException) {
        respond(false, $error->getMessage(), 409);
    }

    error_log("add_menu_product.php: " . $error->getMessage());
    respond(false, "Unable to save the menu product. Please try again.", 500);
}
