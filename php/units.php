<?php
/**
 * Units of measurement for the INVENTORY.
 *
 *   Ingredients -> "Packs", "Liters" or "Pcs" (Pcs for food counted by the piece, e.g. eggs, buns)
 *   Sinkers     -> "kg"      (Tapioca Pearls, Nata Jelly, Coffee Jelly, Rainbow Jelly - see sinkers.php; 1 kg = 1,000 g)
 *   Materials   -> "Pcs"
 *
 * Conversions used when a recipe is written in grams / milliliters:
 *   1 Pack  = 500 grams
 *   1 Liter = 1,000 milliliters
 *
 * Stock is always kept in the inventory item's OWN unit (e.g. 9.998 Packs).
 * Recipes are stored in that same unit too, so ordering/deduction code stays
 * simple; the g / ml figures are only what the admin types and sees.
 */

const PACK_GRAMS = 500;
const LITER_ML = 1000;

/** The units an inventory item of this category may use. */
function inventory_unit_options(string $category): array
{
    switch ($category) {
        case "Materials":
            return ["Pcs"];

        case "Sinkers":
            return ["kg"];

        default:
            return ["Packs", "Liters", "Pcs"];
    }
}

/**
 * LOW-STOCK LIMITS ("thresholds")
 *
 * An item is "Low Stocks" when its quantity is at or below the limit of ITS unit,
 * "No Stocks" at 0, and "In Stock" above the limit. There is one limit per unit:
 *
 *   Packs  10      Liters  10      kg  1      Pcs  10      (the starting values)
 *
 * The limits are stored in the database (table inventory_low_thresholds) so the admin
 * can change them on the Inventory page ("Edit limits"). Everything that decides
 * "is this low?" - the Inventory page, the summary cards, the sidebar badge and the
 * notifications - reads them from here, so they can never disagree.
 *
 * Older items saved with other spellings (pack, l, g, ml ...) follow the same limits:
 * grams use the kg limit x 1,000 and milliliters use the Liters limit x 1,000.
 */
const LOW_STOCK_DEFAULT = 10;   // any unit that is not listed below
const LOW_STOCK_UNIT_DEFAULTS = ["Packs" => 10, "Liters" => 10, "kg" => 1, "Pcs" => 10];

/** What kind of item uses each unit (shown next to the limit). */
const LOW_STOCK_UNIT_KINDS = ["Packs" => "Ingredients", "Liters" => "Ingredients", "kg" => "Sinkers", "Pcs" => "Materials and food items"];

/** "pack", "Packs", "L", "liters" ... -> "Packs" | "Liters" | "kg" | "Pcs" (null for anything else). */
function inventory_threshold_unit(?string $unit): ?string
{
    switch (strtolower(trim((string) $unit))) {
        case "pack":
        case "packs":
            return "Packs";

        case "l":
        case "liter":
        case "liters":
        case "litre":
        case "litres":
            return "Liters";

        case "kg":
        case "kilogram":
        case "kilograms":
            return "kg";

        case "pcs":
        case "pc":
        case "piece":
        case "pieces":
            return "Pcs";

        default:
            return null;
    }
}

/**
 * The current limit for each unit, e.g. ["Packs" => 10.0, "Liters" => 10.0, "kg" => 1.0, "Pcs" => 10.0].
 * Creates the table (with the starting values) the first time. If the database cannot be
 * reached it falls back to the starting values, so a page never breaks over this.
 */
function inventory_low_thresholds(?mysqli $conn = null, bool $reload = false): array
{
    static $cache = null;

    if ($reload) {
        $cache = null;
    }

    if ($cache !== null) {
        return $cache;
    }

    $limits = [];

    foreach (LOW_STOCK_UNIT_DEFAULTS as $unit => $value) {
        $limits[$unit] = (float) $value;
    }

    $conn = $conn ?? ($GLOBALS["conn"] ?? null);

    if ($conn instanceof mysqli) {
        try {
            $rows = [];
            $result = null;

            try {
                $result = $conn->query("SELECT unit, low_limit FROM inventory_low_thresholds");
            } catch (Throwable $missingTable) {
                $result = false;
            }

            if ($result === false) {
                $conn->query(
                    "CREATE TABLE IF NOT EXISTS inventory_low_thresholds (
                        unit VARCHAR(20) NOT NULL PRIMARY KEY,
                        low_limit DECIMAL(12,3) NOT NULL,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
                $result = $conn->query("SELECT unit, low_limit FROM inventory_low_thresholds");
            }

            while ($result && ($row = $result->fetch_assoc())) {
                $rows[(string) $row["unit"]] = (float) $row["low_limit"];
            }

            // Add any unit that has no row yet (first run, or a unit added later).
            $insert = null;

            foreach (LOW_STOCK_UNIT_DEFAULTS as $unit => $value) {
                if (!array_key_exists($unit, $rows)) {
                    $insert = $insert ?? $conn->prepare("INSERT IGNORE INTO inventory_low_thresholds (unit, low_limit) VALUES (?, ?)");
                    $startValue = (string) $value;
                    $insert->bind_param("ss", $unit, $startValue);
                    $insert->execute();
                    $rows[$unit] = (float) $value;
                }
            }

            if ($insert) {
                $insert->close();
            }

            foreach ($limits as $unit => $value) {
                if (isset($rows[$unit])) {
                    $limits[$unit] = max(0.0, $rows[$unit]);
                }
            }
        } catch (Throwable $error) {
            error_log("inventory_low_thresholds: " . $error->getMessage());
        }
    }

    return $cache = $limits;
}

/** The low-stock limit for an item with this unit, in that unit. */
function inventory_low_limit(?string $unit): float
{
    $limits = inventory_low_thresholds();
    $name = inventory_threshold_unit($unit);

    if ($name !== null) {
        return $limits[$name];
    }

    $key = strtolower(trim((string) $unit));

    if (in_array($key, ["g", "gram", "grams"], true)) {
        return $limits["kg"] * 1000;
    }

    if (in_array($key, ["ml", "milliliter", "milliliters"], true)) {
        return $limits["Liters"] * 1000;
    }

    return (float) LOW_STOCK_DEFAULT;
}

/** The same rule as a piece of SQL, for counting low-stock items: "quantity <= " . inventory_low_limit_sql() */
function inventory_low_limit_sql(string $unitColumn = "unit"): string
{
    $limits = inventory_low_thresholds();
    $n = fn(float $value): string => number_format($value, 3, ".", "");

    return "(CASE"
        . " WHEN LOWER($unitColumn) IN ('pack','packs') THEN " . $n($limits["Packs"])
        . " WHEN LOWER($unitColumn) IN ('l','liter','liters','litre','litres') THEN " . $n($limits["Liters"])
        . " WHEN LOWER($unitColumn) IN ('kg','kilogram','kilograms') THEN " . $n($limits["kg"])
        . " WHEN LOWER($unitColumn) IN ('pcs','pc','piece','pieces') THEN " . $n($limits["Pcs"])
        . " WHEN LOWER($unitColumn) IN ('g','gram','grams') THEN " . $n($limits["kg"] * 1000)
        . " WHEN LOWER($unitColumn) IN ('ml','milliliter','milliliters') THEN " . $n($limits["Liters"] * 1000)
        . " ELSE " . LOW_STOCK_DEFAULT . " END)";
}

/** "10 Packs or less", "1 kg or less" - how a limit is written for people. */
function inventory_low_limit_text(string $unit, ?float $limit = null): string
{
    $limit = $limit ?? inventory_low_thresholds()[$unit] ?? (float) LOW_STOCK_DEFAULT;

    return format_quantity($limit) . " " . $unit . " or less";
}

/** One sentence listing every limit, e.g. for form hints: "10 Packs, 10 Liters, 1 kg or 10 Pcs". */
function inventory_low_rule_summary(): string
{
    $parts = [];

    foreach (inventory_low_thresholds() as $unit => $limit) {
        $parts[] = format_quantity($limit) . " " . $unit;
    }

    $last = array_pop($parts);

    return implode(", ", $parts) . " or " . $last;
}

/**
 * What a unit means.
 * Returns: kind (weight | volume | count), base (g | ml | pcs) and factor
 * (how many base units are in ONE inventory unit; 1 for counts).
 * Older spellings (pack, l, kg, pcs ...) are understood too.
 */
function inventory_unit_info(?string $unit): array
{
    $key = strtolower(trim((string) $unit));

    switch ($key) {
        case "pack":
        case "packs":
            return ["kind" => "weight", "base" => "g", "factor" => (float) PACK_GRAMS];

        case "kg":
            return ["kind" => "weight", "base" => "g", "factor" => 1000.0];

        case "g":
        case "gram":
        case "grams":
            return ["kind" => "weight", "base" => "g", "factor" => 1.0];

        case "l":
        case "liter":
        case "liters":
        case "litre":
        case "litres":
            return ["kind" => "volume", "base" => "ml", "factor" => (float) LITER_ML];

        case "ml":
        case "milliliter":
        case "milliliters":
            return ["kind" => "volume", "base" => "ml", "factor" => 1.0];

        default:
            return ["kind" => "count", "base" => (string) $unit, "factor" => 1.0];
    }
}

/** Is this unit one of the allowed choices for the category? */
function is_valid_inventory_unit(string $category, string $unit): bool
{
    return in_array($unit, inventory_unit_options($category), true);
}

/** Whole numbers only for counted items (pieces). */
function inventory_unit_is_countable(string $unit): bool
{
    return inventory_unit_info($unit)["kind"] === "count";
}

/** 12.5 -> "12.5", 12.000 -> "12" */
function format_quantity(float|string|null $value, int $decimals = 3): string
{
    $text = number_format((float) $value, $decimals, ".", "");

    return rtrim(rtrim($text, "0"), ".") ?: "0";
}

/** "4,999 g" for 9.998 Packs, "1,500 ml" for 1.5 Liters, "" for pieces. */
function stock_equivalent(float|string|null $quantity, ?string $unit): string
{
    $info = inventory_unit_info($unit);

    if ($info["kind"] === "count" || $info["factor"] === 1.0) {
        return "";
    }

    $amount = round((float) $quantity * $info["factor"], 1);

    return rtrim(rtrim(number_format($amount, 1, ".", ","), "0"), ".") . " " . $info["base"];
}

/**
 * Stock needs 3 decimals: taking 1 gram out of a pack is 0.002 Packs, which
 * the old DECIMAL(10,2) column would round away. Safe to call many times.
 * (ALTER TABLE ends any open transaction - call it BEFORE begin_transaction().)
 */
function ensure_inventory_precision_schema(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        $res = $conn->query(
            "SELECT NUMERIC_SCALE AS s FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'quantity'"
        );
        $row = $res ? $res->fetch_assoc() : null;

        if ($row && (int) $row["s"] < 3) {
            $conn->query("ALTER TABLE inventory MODIFY quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000");
        }
    } catch (Throwable $e) {
        error_log("ensure_inventory_precision_schema: " . $e->getMessage());
    }
}

/**
 * The dates an item's "date of procurement" may have: from 1 January of THIS year up to
 * today. "Today" is the store's date (Manila time, same as the opening hours), so a wrong
 * clock on someone's computer cannot allow a future date.
 *
 * @return array{min: string, max: string, year: string}  dates as Y-m-d
 */
function inventory_procurement_window(): array
{
    $today = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));

    return [
        "min"  => $today->format("Y") . "-01-01",
        "max"  => $today->format("Y-m-d"),
        "year" => $today->format("Y")
    ];
}
