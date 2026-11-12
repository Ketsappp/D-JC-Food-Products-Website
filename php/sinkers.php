<?php
/**
 * Sinkers: Tapioca Pearls, Nata Jelly, Coffee Jelly and Rainbow Jelly.
 *
 * They are stored in the INVENTORY (category "Sinkers") and measured in kg (1 kg = 1,000 g).
 * Every sinker added to a drink - the chosen sinker and the "Add a Sinker" extra - takes
 * SINKER_PORTION_GRAMS from stock when the order is paid / placed.
 * A sinker is shown as unavailable (disabled on the order screens) as soon as there is
 * less than one portion left in the inventory.
 *
 * Change SINKER_PORTION_GRAMS below if a serving is not 10 g.
 */

require_once __DIR__ . "/units.php";

const SINKER_PORTION_GRAMS = 10;
const SINKER_NAMES = ["Tapioca Pearls", "Nata Jelly", "Coffee Jelly", "Rainbow Jelly"];

function sinker_key(string $name): string
{
    return strtolower(trim((string) preg_replace('/\s+/', " ", $name)));
}

/** The sinkers an order line asks for.
 *  Add-on text looks like "Dine In + Tapioca Pearls + Extra Nata Jelly".
 *  A saved order item name also starts with the product ("Mango Graham (Large) + Dine In + ..."),
 *  so pass $firstPartIsProduct = true for those. */
function sinkers_from_text(?string $text, bool $firstPartIsProduct = false): array
{
    $parts = array_map("trim", explode(" + ", (string) $text));

    if ($firstPartIsProduct) {
        array_shift($parts);
    }

    $known = [];

    foreach (SINKER_NAMES as $name) {
        $known[sinker_key($name)] = $name;
    }

    $found = [];

    foreach ($parts as $part) {
        $key = sinker_key((string) preg_replace('/^extra\s+/i', "", $part));

        if (isset($known[$key])) {
            $found[] = $known[$key];
        }
    }

    return $found;
}

/** Make sure the four sinkers exist in the inventory (with 0 kg until the admin adds stock). Safe to call often. */
function ensure_sinker_inventory(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        $find = $conn->prepare(
            "SELECT id FROM inventory WHERE LOWER(TRIM(product_name)) = ? AND is_archived = 0 LIMIT 1"
        );
        $add = $conn->prepare(
            "INSERT INTO inventory
                (product_name, image, category, quantity, unit, minimum_stock,
                 selling_price, small_price, medium_price, large_price,
                 used_quantity, description, is_available)
             VALUES (?, '', 'Sinkers', 0, 'kg', 5, 0, 0, 0, 0, 0, '', 0)"
        );

        foreach (SINKER_NAMES as $name) {
            $key = sinker_key($name);
            $find->bind_param("s", $key);
            $find->execute();

            if ($find->get_result()->num_rows === 0) {
                $add->bind_param("s", $name);
                $add->execute();
            }
        }

        $find->close();
        $add->close();

        // An earlier version kept the sinkers in grams. They are in kg now, so convert any stock still saved in grams
        // (1,500 g -> 1.5 kg). Needs 3 decimals, which is what the precision schema guarantees. Runs once per row.
        $inGrams = $conn->query(
            "SELECT COUNT(*) AS c FROM inventory
             WHERE category = 'Sinkers' AND LOWER(TRIM(unit)) IN ('g','gram','grams')"
        );

        if ($inGrams && (int) $inGrams->fetch_assoc()["c"] > 0) {
            ensure_inventory_precision_schema($conn);
            $conn->query(
                "UPDATE inventory SET quantity = quantity / 1000, unit = 'kg'
                 WHERE category = 'Sinkers' AND LOWER(TRIM(unit)) IN ('g','gram','grams')"
            );
        }
    } catch (Throwable $e) {
        error_log("ensure_sinker_inventory: " . $e->getMessage());
    }
}

/** All active inventory rows for each sinker, soonest-to-expire first. Returns [key => [row, ...]]. */
function sinker_inventory_rows(mysqli $conn): array
{
    $keys = array_map("sinker_key", SINKER_NAMES);
    $in = "'" . implode("','", array_map([$conn, "real_escape_string"], $keys)) . "'";

    $result = $conn->query(
        "SELECT id, product_name, category, unit, quantity
         FROM inventory
         WHERE is_archived = 0 AND LOWER(TRIM(product_name)) IN ($in)
         ORDER BY (expiration_date IS NULL), expiration_date ASC, id ASC"
    );

    $rows = [];

    while ($result && ($row = $result->fetch_assoc())) {
        $rows[sinker_key($row["product_name"])][] = $row;
    }

    return $rows;
}

/** Grams in stock for one inventory row (0 when the item is not measured by weight). */
function sinker_row_grams(array $row): float
{
    $info = inventory_unit_info($row["unit"]);

    return $info["kind"] === "weight" ? (float) $row["quantity"] * $info["factor"] : 0.0;
}

/** For the order screens: every sinker, is it available, and how many grams are left. */
function sinker_availability(mysqli $conn): array
{
    $rows = sinker_inventory_rows($conn);
    $list = [];

    foreach (SINKER_NAMES as $name) {
        $grams = 0.0;

        foreach ($rows[sinker_key($name)] ?? [] as $row) {
            $grams += sinker_row_grams($row);
        }

        $list[] = [
            "name" => $name,
            "grams" => round($grams, 1),
            "available" => $grams + 0.0005 >= SINKER_PORTION_GRAMS,
        ];
    }

    return $list;
}
