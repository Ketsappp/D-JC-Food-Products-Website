<?php
/**
 * Edit a MENU product and its ingredients (recipe).
 * Admin only. Used by "Edit Product" on the Menu page.
 *
 * POST (multipart/form-data):
 *   product_id, product_name, category, selling_price,
 *   image (optional - leave empty to keep the current image),
 *   ingredient_inventory_id[], ingredient_quantity[]
 *
 * Only these fields change (name, category, price(s), image, ingredients).
 * Other stored data for the product is left untouched.
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
$productId = filter_var($_POST["product_id"] ?? null, FILTER_VALIDATE_INT);
$name = trim($_POST["product_name"] ?? "");
$category = $_POST["category"] ?? "";

// Any category in the menu_categories table (new ones are added with "Add Category").
require_once __DIR__ . "/menu_categories.php";
$allowedCategories = menu_category_names($conn);

if (!$productId || $productId < 1) {
    respond(false, "Invalid menu product.", 400);
}

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
// 2. Validate and store a replacement image (optional)
// ---------------------------------------------------------------------
$newImageName = "";
$newImagePath = "";
$uploadDir = dirname(__DIR__) . "/uploads/products/";

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

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
        respond(false, "Unable to create the upload folder.", 500);
    }

    if (!is_writable($uploadDir)) {
        respond(false, "The upload folder is not writable.", 500);
    }

    $newImageName = uniqid("product_", true) . "." . $extension;
    $newImagePath = $uploadDir . $newImageName;

    if (!move_uploaded_file($tmpName, $newImagePath)) {
        respond(false, "Unable to save the uploaded image.", 500);
    }
}

// ---------------------------------------------------------------------
// 3. Save the product and replace its recipe together
// ---------------------------------------------------------------------
$oldImageName = "";

// Add the size columns if this database does not have them yet.
ensure_menu_size_schema($conn);

try {

    // The product must exist and still be active.
    $find = $conn->prepare(
        "SELECT image FROM menu_inventory WHERE id = ? LIMIT 1"
    );

    $find->bind_param("i", $productId);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();
    $find->close();

    if (!$existing) {
        throw new DomainException("This menu product no longer exists.");
    }

    $oldImageName = (string) ($existing["image"] ?? "");

    // Block a name that another product already uses in the same category.
    $duplicate = $conn->prepare(
        "SELECT id
         FROM menu_inventory
         WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))
           AND category = ?
           AND id <> ?
           AND is_archived = 0
         LIMIT 1"
    );

    $duplicate->bind_param("ssi", $name, $category, $productId);
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

    $sellingPrice = $form['selling_price'];
    $smallPrice = $form['prices']['small'];
    $mediumPrice = $form['prices']['medium'];
    $largePrice = $form['prices']['large'];

    if ($newImageName !== "") {
        $update = $conn->prepare(
            "UPDATE menu_inventory
             SET product_name = ?, category = ?, selling_price = ?,
                 small_price = ?, medium_price = ?, large_price = ?, image = ?
             WHERE id = ?"
        );

        $update->bind_param(
            "ssddddsi",
            $name,
            $category,
            $sellingPrice,
            $smallPrice,
            $mediumPrice,
            $largePrice,
            $newImageName,
            $productId
        );
    } else {
        $update = $conn->prepare(
            "UPDATE menu_inventory
             SET product_name = ?, category = ?, selling_price = ?,
                 small_price = ?, medium_price = ?, large_price = ?
             WHERE id = ?"
        );

        $update->bind_param(
            "ssddddi",
            $name,
            $category,
            $sellingPrice,
            $smallPrice,
            $mediumPrice,
            $largePrice,
            $productId
        );
    }

    $update->execute();
    $update->close();

    // Replace the recipe with the submitted ingredients.
    $clear = $conn->prepare(
        "DELETE FROM menu_product_ingredients WHERE menu_product_id = ?"
    );

    $clear->bind_param("i", $productId);
    $clear->execute();
    $clear->close();

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
            $productId,
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

    // The old picture is no longer used once it has been replaced.
    if ($newImageName !== "" && $oldImageName !== "") {
        $oldPath = $uploadDir . basename($oldImageName);

        if ($oldImageName === basename($oldImageName) && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    respond(true, "Menu product updated successfully.");

} catch (Throwable $error) {

    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    // Do not leave an orphaned upload behind.
    if ($newImagePath !== "" && is_file($newImagePath)) {
        @unlink($newImagePath);
    }

    if ($error instanceof DomainException) {
        respond(false, $error->getMessage(), 409);
    }

    error_log("update_menu_product.php: " . $error->getMessage());
    respond(false, "Unable to update the menu product. Please try again.", 500);
}
