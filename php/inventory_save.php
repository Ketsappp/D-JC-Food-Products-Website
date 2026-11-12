<?php
/**
 * Add or edit an INVENTORY item.
 *
 * Fields: product_id (edit only), product_name, category, unit, quantity,
 *         date_procured, expiration_date.
 *
 * ADDING an item that already exists (same name and unit, e.g. "Tapioca Pearls" in kg)
 * does NOT create a second copy: the quantity entered is added to the existing stock.
 *
 * Menu products are saved by add_menu_product.php (Menu page, admin only).
 * Responses are plain text so the existing inventory.js handler can show them.
 */

require_once __DIR__ . "/auth_check.php";

header("Content-Type: text/plain; charset=utf-8");

// Only administrators can add or edit inventory.
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    http_response_code(403);
    exit("Administrator access required.");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Invalid request.");
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/stock_log_helpers.php";
require_once __DIR__ . "/units.php";
require_once __DIR__ . "/sinkers.php";

ensure_inventory_precision_schema($conn);

/**
 * Return a valid Y-m-d date, null for an empty value, or false if invalid.
 */
function parseDate(string $value)
{
    $value = trim($value);

    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat("!Y-m-d", $value);

    return ($date && $date->format("Y-m-d") === $value) ? $value : false;
}

$productId = trim($_POST["product_id"] ?? "");
$name = trim($_POST["product_name"] ?? "");
$category = $_POST["category"] ?? "";
$unit = trim($_POST["unit"] ?? "");
$quantityInput = trim($_POST["quantity"] ?? "");

$allowedCategories = ["Ingredients", "Sinkers", "Materials"];

if ($name === "" || mb_strlen($name) > 255) {
    http_response_code(400);
    exit("Please enter a name (up to 255 characters).");
}

if (!in_array($category, $allowedCategories, true)) {
    http_response_code(400);
    exit("Please choose a category: Ingredients, Sinkers or Materials.");
}

// Ingredients use Packs, Liters or Pcs; Sinkers use kg; Materials use Pcs.
if (!is_valid_inventory_unit($category, $unit)) {
    http_response_code(400);

    if ($category === "Materials") {
        exit("Materials can only use Pcs as the unit.");
    }

    exit(
        $category === "Sinkers"
            ? "Sinkers are measured in kg."
            : "Ingredients can only use Packs, Liters or Pcs as the unit."
    );
}

// The order screens offer exactly these four sinkers, so no other name makes sense here.
if ($category === "Sinkers" && !in_array(sinker_key($name), array_map("sinker_key", SINKER_NAMES), true)) {
    http_response_code(400);
    exit("Sinkers can only be: " . implode(", ", SINKER_NAMES) . ".");
}

// Pieces are counted in whole numbers; Packs / Liters may have up to 3 decimals
// (for example 9.998 Packs after a few grams were used).
if ($quantityInput === "") {
    http_response_code(400);
    exit("Please enter the quantity.");
}

if (inventory_unit_is_countable($unit)) {
    if (!ctype_digit($quantityInput)) {
        http_response_code(400);
        exit("Quantity for pieces must be a whole number, without decimals.");
    }
} elseif (!preg_match('/^\d+(\.\d{1,3})?$/', $quantityInput)) {
    http_response_code(400);
    exit("Quantity must be a number with up to 3 decimals.");
}

if ((float) $quantityInput > 1000000) {
    http_response_code(400);
    exit("Quantity is too large.");
}

$quantity = (float) $quantityInput;
$quantityText = format_quantity($quantityInput);

$dateProcured = parseDate($_POST["date_procured"] ?? "");
$expirationDate = parseDate($_POST["expiration_date"] ?? "");

if ($dateProcured === false || $expirationDate === false) {
    http_response_code(400);
    exit("Please enter valid dates.");
}

if ($dateProcured !== null && $expirationDate !== null && $expirationDate < $dateProcured) {
    http_response_code(400);
    exit("The expiration date cannot be earlier than the date of procurement.");
}

if ($productId !== "" && !ctype_digit($productId)) {
    http_response_code(400);
    exit("Invalid inventory item.");
}

// Date of procurement: in the current year, and not after today. (It may be left empty.)
// An older item that was saved before this rule can still be edited as long as its date
// is left as it is; the rule applies to a new or changed date.
if ($dateProcured !== null) {
    $window = inventory_procurement_window();

    if ($dateProcured > $window["max"] || $dateProcured < $window["min"]) {

        $keepsSavedDate = false;

        if ($productId !== "") {
            $saved = $conn->prepare("SELECT date_procured FROM inventory WHERE id = ? LIMIT 1");
            $savedId = (int) $productId;
            $saved->bind_param("i", $savedId);
            $saved->execute();
            $savedRow = $saved->get_result()->fetch_assoc();
            $saved->close();

            $keepsSavedDate = $savedRow && (string) $savedRow["date_procured"] === $dateProcured;
        }

        if (!$keepsSavedDate) {
            http_response_code(400);
            exit(
                $dateProcured > $window["max"]
                    ? "The date of procurement cannot be in the future. Choose today or an earlier date."
                    : "The date of procurement must be in the current year ({$window["year"]})."
            );
        }
    }
}

try {

    // ------------------------------------------------------------------
    // EDIT: update only the fields on the form, so other stored data
    // (prices, supplier, image, ...) is never overwritten.
    // ------------------------------------------------------------------
    if ($productId !== "") {

        $id = (int) $productId;

        // Remember the old quantity so the change can be logged.
        $before = stock_snapshot($conn, $id);

        $stmt = $conn->prepare(
            "UPDATE inventory
             SET product_name = ?,
                 category = ?,
                 quantity = ?,
                 unit = ?,
                 date_procured = ?,
                 expiration_date = ?
             WHERE id = ?"
        );

        $stmt->bind_param(
            "ssssssi",
            $name,
            $category,
            $quantityText,
            $unit,
            $dateProcured,
            $expirationDate,
            $id
        );

        $stmt->execute();
        $stmt->close();

        if ($before !== null) {
            log_stock_change($conn, $id, $name, $unit, (float) $before["quantity"], $quantity, "Edit item");
        }

        $conn->close();

        exit("Inventory item updated successfully.");
    }

    // ------------------------------------------------------------------
    // ADD: is this item already in the inventory?
    //
    // Same name (capitals, spaces ignored) and same unit = the same item. It is NOT added
    // a second time; the quantity entered is added to what is in stock now. (Sinkers are
    // created automatically at 0 kg, so "Tapioca Pearls" always exists - adding it tops
    // up that row.) Other units are not merged, because 2 kg and 2 Packs cannot be added.
    // The rows are locked while this runs so two saves at once cannot lose a quantity.
    // ------------------------------------------------------------------
    $conn->begin_transaction();

    $wantedName = sinker_key($name);      // lower case, trimmed, single spaces
    $wantedUnit = strtolower($unit);

    $find = $conn->prepare(
        "SELECT id, product_name, quantity, date_procured, expiration_date, is_archived
         FROM inventory
         WHERE LOWER(TRIM(unit)) = ?
         ORDER BY is_archived ASC, id ASC
         FOR UPDATE"
    );
    $find->bind_param("s", $wantedUnit);
    $find->execute();
    $candidates = $find->get_result();

    $existing = null;

    // Active items come first, so an active copy is always preferred over an inactive one.
    while ($candidate = $candidates->fetch_assoc()) {
        if (sinker_key((string) $candidate["product_name"]) === $wantedName) {
            $existing = $candidate;
            break;
        }
    }

    $find->close();

    if ($existing !== null) {

        $existingId = (int) $existing["id"];
        $before = (float) $existing["quantity"];
        $after = round($before + $quantity, 3);

        if ($after > 1000000) {
            $conn->rollback();
            $conn->close();
            http_response_code(400);
            exit("Quantity is too large. The new total would be more than 1,000,000.");
        }

        // The item keeps its own dates. A date is only filled in if the item had none.
        $update = $conn->prepare(
            "UPDATE inventory
             SET quantity = ?,
                 date_procured = COALESCE(date_procured, ?),
                 expiration_date = COALESCE(expiration_date, ?)
             WHERE id = ?"
        );
        $afterText = format_quantity($after);
        $update->bind_param("sssi", $afterText, $dateProcured, $expirationDate, $existingId);
        $update->execute();
        $update->close();

        $conn->commit();

        log_stock_change($conn, $existingId, (string) $existing["product_name"], $unit, $before, $after, "Add stock");

        $conn->close();

        $itemName = (string) $existing["product_name"];
        $message = $quantity > 0
            ? "\"{$itemName}\" already exists, so no new item was created. Added "
                . format_quantity($quantity) . " {$unit} to its stock ("
                . format_quantity($before) . " to " . format_quantity($after) . " {$unit})."
            : "\"{$itemName}\" already exists, so no new item was created. Its stock is unchanged because the quantity was 0.";

        if ((int) $existing["is_archived"] === 1) {
            $message .= " This item is inactive; activate it to use it.";
        }

        // Tells the page to keep this message on screen a little longer.
        header("X-Inventory-Merged: 1");

        exit($message);
    }

    // Not in the inventory yet: create it.
    // Columns that are no longer on the form get neutral defaults.
    $stmt = $conn->prepare(
        "INSERT INTO inventory
            (product_name, image, category, quantity, unit, minimum_stock,
             date_procured, expiration_date, supplier, unit_cost,
             selling_price, small_price, medium_price, large_price,
             used_quantity, description, is_available)
         VALUES (?, '', ?, ?, ?, 5, ?, ?, '', 0, 0, 0, 0, 0, 0, '', 0)"
    );

    $stmt->bind_param(
        "ssssss",
        $name,
        $category,
        $quantityText,
        $unit,
        $dateProcured,
        $expirationDate
    );

    $stmt->execute();
    $newId = (int) $conn->insert_id;
    $stmt->close();

    $conn->commit();

    log_stock_change($conn, $newId, $name, $unit, null, $quantity, "Add item");

    $conn->close();

    exit("Inventory item added successfully.");

} catch (Throwable $error) {
    error_log("inventory_save.php: " . $error->getMessage());

    try {
        $conn->rollback();   // nothing half-saved
    } catch (Throwable $ignored) {
    }

    http_response_code(500);
    exit("Unable to save the inventory item. Please try again.");
}
