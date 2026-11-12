<?php
/**
 * Recipe (ingredient) helpers shared by the Menu page, Cash checkout and
 * the GCash payment webhook.
 *
 * How stock works
 * ---------------
 * A menu product is made from inventory ingredients (menu_product_ingredients).
 * It can be ordered only while every ingredient has enough stock, and an
 * order takes those ingredients out of the inventory.
 *
 * Sizes
 * -----
 * A product may be sold in 2 or more sizes (small / medium / large). Each
 * size can use a different quantity of every ingredient:
 *   quantity_required  - the quantity for a product without sizes
 *   quantity_small / quantity_medium / quantity_large - per-size quantities
 */

require_once __DIR__ . '/units.php';
require_once __DIR__ . '/sinkers.php';

const MENU_SIZE_KEYS = ['small', 'medium', 'large'];

// Every category except pizza is a cup drink (cup + straw are added automatically).
require_once __DIR__ . '/menu_categories.php';

// Categories flagged as food (pizza-style) in menu_categories are not cup drinks.
const PIZZA_CATEGORY = 'Pizza Series';

/**
 * A stock problem that is safe to show to staff (e.g. "Not enough Milk 1L").
 */
class OrderStockException extends RuntimeException
{
}

/**
 * Add the columns that sizes need, if they are missing. Safe to call many
 * times. ALTER TABLE ends any open transaction, so call this BEFORE
 * begin_transaction().
 */
function ensure_menu_size_schema(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    // Stock needs 3 decimals (1 g of a 500 g pack = 0.002).
    ensure_inventory_precision_schema($conn);

    $columns = [
        ['menu_product_ingredients', 'quantity_small', 'DECIMAL(10,3) NULL'],
        ['menu_product_ingredients', 'quantity_medium', 'DECIMAL(10,3) NULL'],
        ['menu_product_ingredients', 'quantity_large', 'DECIMAL(10,3) NULL'],
        ['order_items', 'size_label', 'VARCHAR(20) NULL'],
    ];

    try {
        $check = $conn->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );

        foreach ($columns as [$table, $column, $definition]) {
            $check->bind_param("ss", $table, $column);
            $check->execute();

            if ($check->get_result()->num_rows === 0) {
                $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        }

        $check->close();
    } catch (Throwable $error) {
        error_log("ensure_menu_size_schema: " . $error->getMessage());
    }
}

/**
 * 'Small' / 'medium ' / 'LARGE' -> 'small' / 'medium' / 'large'.
 * Anything else (e.g. 'Regular') -> null.
 */
function recipe_size_key(?string $label): ?string
{
    $key = strtolower(trim((string) $label));

    return in_array($key, MENU_SIZE_KEYS, true) ? $key : null;
}

/**
 * The quantity of one ingredient needed for ONE serving of the given size.
 */
function recipe_required_quantity(array $row, ?string $sizeLabel): float
{
    $key = recipe_size_key($sizeLabel);

    if ($key !== null && isset($row['quantity_' . $key])) {
        return (float) $row['quantity_' . $key];
    }

    return (float) ($row['quantity_required'] ?? 0);
}

/**
 * True when the recipe row is explicitly set to 0 for this size, meaning
 * "this ingredient is not used by this size" (different from a missing quantity).
 */
function recipe_row_unused_for_size(array $row, ?string $sizeLabel): bool
{
    $key = recipe_size_key($sizeLabel);

    return $key !== null
        && array_key_exists('quantity_' . $key, $row)
        && $row['quantity_' . $key] !== null
        && (float) $row['quantity_' . $key] <= 0;
}

/**
 * The sizes a product is sold in (those with a price above zero).
 * An empty list means the product has no sizes.
 */
function menu_product_sizes(array $product): array
{
    $sizes = [];

    foreach (MENU_SIZE_KEYS as $key) {
        if ((float) ($product[$key . '_price'] ?? 0) > 0) {
            $sizes[] = $key;
        }
    }

    return $sizes;
}

/**
 * Work out, for each product, whether it can be ordered right now.
 *
 * @param  array $products rows with id and small_price/medium_price/large_price
 * @return array product id => ['orderable' => bool, 'sizes' => [size => bool]]
 *               ('regular' is used as the size name for products without sizes)
 */
function get_menu_availability(mysqli $conn, array $products): array
{
    $ids = array_values(array_unique(array_map(
        fn($product) => (int) $product['id'],
        $products
    )));

    if (count($ids) === 0) {
        return [];
    }

    // Only integers reach the query, so it is safe to inline them.
    $in = implode(',', $ids);

    $result = $conn->query(
        "SELECT r.menu_product_id,
                r.quantity_required,
                r.quantity_small,
                r.quantity_medium,
                r.quantity_large,
                i.quantity AS stock,
                i.is_archived
         FROM menu_product_ingredients r
         LEFT JOIN inventory i ON i.id = r.inventory_product_id
         WHERE r.menu_product_id IN ($in)"
    );

    $recipes = [];

    while ($row = $result->fetch_assoc()) {
        $recipes[(int) $row['menu_product_id']][] = $row;
    }

    $availability = [];

    foreach ($products as $product) {
        $id = (int) $product['id'];
        $recipe = $recipes[$id] ?? [];
        $sizes = menu_product_sizes($product);
        $sizesToCheck = count($sizes) > 0 ? $sizes : [null];

        $sizeStock = [];
        $orderable = false;

        foreach ($sizesToCheck as $size) {

            // No ingredients means the product is not set up yet.
            $enough = count($recipe) > 0;

            foreach ($recipe as $row) {
                $needed = recipe_required_quantity($row, $size);

                // A size-specific item (e.g. the Small cup) is not used by the other sizes.
                if (recipe_row_unused_for_size($row, $size)) {
                    continue;
                }

                if (
                    $row['stock'] === null ||        // ingredient was deleted
                    (int) $row['is_archived'] === 1 || // ingredient is inactive
                    $needed <= 0 ||
                    round((float) $row['stock'], 3) < round($needed, 3)
                ) {
                    $enough = false;
                    break;
                }
            }

            $sizeStock[$size ?? 'regular'] = $enough;
            $orderable = $orderable || $enough;
        }

        $availability[$id] = [
            'orderable' => $orderable,
            'sizes' => $sizeStock
        ];
    }

    return $availability;
}

/**
 * Check - and, when $apply is true, take - the ingredients an order needs.
 *
 * Call inside a transaction when $apply is true: the ingredient rows are
 * locked while they are checked so two orders cannot use the same stock.
 *
 * @param array $lines each: product_id, size_label, quantity
 * @throws OrderStockException when something cannot be made
 */
function deduct_order_ingredients(mysqli $conn, array $lines, bool $apply = true): void
{
    if (count($lines) === 0) {
        throw new OrderStockException("The order has no items.");
    }

    $productIds = array_values(array_unique(array_map(
        fn($line) => (int) $line['product_id'],
        $lines
    )));

    $in = implode(',', $productIds);

    // 1. Every product must exist and be active.
    $result = $conn->query(
        "SELECT id, product_name, category, is_available, is_archived
         FROM menu_inventory
         WHERE id IN ($in)"
    );

    $products = [];

    while ($row = $result->fetch_assoc()) {
        $products[(int) $row['id']] = $row;
    }

    foreach ($productIds as $productId) {
        $product = $products[$productId] ?? null;

        if (
            !$product ||
            (int) $product['is_archived'] === 1 ||
            (int) $product['is_available'] !== 1
        ) {
            throw new OrderStockException(
                ($product ? $product['product_name'] : "A product in this order")
                . " is not available."
            );
        }
    }

    // 2. Load the recipes.
    $result = $conn->query(
        "SELECT menu_product_id, inventory_product_id, quantity_required,
                quantity_small, quantity_medium, quantity_large
         FROM menu_product_ingredients
         WHERE menu_product_id IN ($in)"
    );

    $recipes = [];

    while ($row = $result->fetch_assoc()) {
        $recipes[(int) $row['menu_product_id']][] = $row;
    }

    // 3. Add up how much of each ingredient the whole order needs.
    $needs = [];

    foreach ($lines as $line) {
        $productId = (int) $line['product_id'];
        $quantity = (int) $line['quantity'];
        $recipe = $recipes[$productId] ?? [];

        if (count($recipe) === 0) {
            throw new OrderStockException(
                $products[$productId]['product_name']
                . " has no ingredients set up yet."
            );
        }

        foreach ($recipe as $row) {
            $perServing = recipe_required_quantity($row, $line['size_label'] ?? null);

            // A size-specific item (e.g. the Small cup) is not used by the other sizes.
            if (recipe_row_unused_for_size($row, $line['size_label'] ?? null)) {
                continue;
            }

            if ($perServing <= 0) {
                throw new OrderStockException(
                    $products[$productId]['product_name']
                    . " has an ingredient without a quantity."
                );
            }

            $ingredientId = (int) $row['inventory_product_id'];
            $needs[$ingredientId] = ($needs[$ingredientId] ?? 0) + $perServing * $quantity;
        }
    }

    // 3a. Cup and straw: every drink serving uses ONE cup (the size's cup) and ONE straw.
    // They are normally part of the saved recipe, but a recipe saved before the matching
    // cup or straw existed would miss them, so they are added here when the recipe does not
    // already use one for this size. (Food products have no cup or straw.)
    $servingMaterials = null;

    foreach ($lines as $line) {
        $productId = (int) $line['product_id'];
        $quantity = (int) $line['quantity'];
        $sizeLabel = $line['size_label'] ?? null;
        $recipe = $recipes[$productId] ?? [];

        if (!recipe_uses_base_units((string) ($products[$productId]['category'] ?? ''), $conn)) {
            continue;
        }

        if ($servingMaterials === null) {
            $servingMaterials = get_serving_materials($conn);
        }

        foreach (['cups', 'straws'] as $listKey) {
            $ids = array_map(fn($item) => (int) $item['id'], $servingMaterials[$listKey]);

            // Does the recipe already use a cup / straw for this size?
            $covered = false;

            foreach ($recipe as $row) {
                if (
                    in_array((int) $row['inventory_product_id'], $ids, true)
                    && !recipe_row_unused_for_size($row, $sizeLabel)
                    && recipe_required_quantity($row, $sizeLabel) > 0
                ) {
                    $covered = true;
                    break;
                }
            }

            if ($covered) {
                continue;
            }

            $item = pick_serving_material($servingMaterials[$listKey], recipe_size_key($sizeLabel));

            if ($item !== null) {
                $itemId = (int) $item['id'];
                $needs[$itemId] = ($needs[$itemId] ?? 0) + 1 * $quantity;
            }
        }
    }

    // 3b. Sinkers: every chosen sinker (and the "Add a Sinker" extra) takes one portion in
    // grams from the inventory. Stock rows of the same sinker are used soonest-expiry first.
    $sinkerPortions = [];

    foreach ($lines as $line) {
        foreach (($line['sinkers'] ?? []) as $sinkerName) {
            $key = sinker_key((string) $sinkerName);
            $sinkerPortions[$key] = ($sinkerPortions[$key] ?? 0) + (int) $line['quantity'];
        }
    }

    if (count($sinkerPortions) > 0) {
        $sinkerRows = sinker_inventory_rows($conn);

        foreach ($sinkerPortions as $key => $portions) {
            $label = $key;

            foreach (SINKER_NAMES as $knownName) {
                if (sinker_key($knownName) === $key) {
                    $label = $knownName;
                }
            }

            $gramsStillNeeded = $portions * SINKER_PORTION_GRAMS;

            foreach ($sinkerRows[$key] ?? [] as $row) {
                if ($gramsStillNeeded <= 0.0005) {
                    break;
                }

                $inStock = sinker_row_grams($row);

                if ($inStock <= 0) {
                    continue;
                }

                $factor = inventory_unit_info($row['unit'])['factor'];
                $takeGrams = min($gramsStillNeeded, $inStock);
                $rowId = (int) $row['id'];

                $needs[$rowId] = ($needs[$rowId] ?? 0) + $takeGrams / $factor;
                $gramsStillNeeded -= $takeGrams;
            }

            if ($gramsStillNeeded > 0.0005) {
                throw new OrderStockException("Not enough " . $label . " in stock.");
            }
        }
    }

    // 4. Check the stock (locking the rows when we are going to change them).
    $ingredientIn = implode(',', array_map('intval', array_keys($needs)));
    $lock = $apply ? " FOR UPDATE" : "";

    $result = $conn->query(
        "SELECT id, product_name, quantity, is_archived
         FROM inventory
         WHERE id IN ($ingredientIn)" . $lock
    );

    $stock = [];

    while ($row = $result->fetch_assoc()) {
        $stock[(int) $row['id']] = $row;
    }

    foreach ($needs as $ingredientId => $needed) {
        $row = $stock[$ingredientId] ?? null;

        if (!$row || (int) $row['is_archived'] === 1) {
            throw new OrderStockException(
                "An ingredient for this order is no longer available."
            );
        }

        if (round((float) $row['quantity'], 3) < round($needed, 3)) {
            throw new OrderStockException(
                "Not enough " . $row['product_name'] . " in stock."
            );
        }
    }

    if (!$apply) {
        return;
    }

    // 5. Take the ingredients out of the inventory.
    $update = $conn->prepare(
        "UPDATE inventory SET quantity = quantity - ? WHERE id = ?"
    );

    foreach ($needs as $ingredientId => $needed) {
        $amount = round($needed, 3);
        $update->bind_param("di", $amount, $ingredientId);
        $update->execute();
    }

    $update->close();
}

/** The most items (all products together) a single order can hold. */
const MAX_ORDER_QTY = 50;

/**
 * For every cart line: how many of it the inventory can supply, counting what the
 * OTHER lines of the cart already use (shared ingredients and sinker portions).
 *
 * Nothing is locked or changed. Returns one entry per line, in the same order:
 *   ['max' => int|null, 'reason' => string]  (max null = cannot be made at all)
 *
 * @param array $lines each: product_id, size_label, quantity, sinkers
 */
function cart_stock_capacity(mysqli $conn, array $lines): array
{
    $result = [];
    $productIds = array_values(array_unique(array_map(fn($l) => (int) $l['product_id'], $lines)));

    if (count($productIds) === 0) {
        return $result;
    }

    $in = implode(',', $productIds);
    $products = [];
    $rs = $conn->query("SELECT id, product_name, is_available, is_archived FROM menu_inventory WHERE id IN ($in)");
    while ($row = $rs->fetch_assoc()) {
        $products[(int) $row['id']] = $row;
    }

    $recipes = [];
    $rs = $conn->query(
        "SELECT menu_product_id, inventory_product_id, quantity_required,
                quantity_small, quantity_medium, quantity_large
         FROM menu_product_ingredients WHERE menu_product_id IN ($in)"
    );
    while ($row = $rs->fetch_assoc()) {
        $recipes[(int) $row['menu_product_id']][] = $row;
    }

    // Per-serving use of every line, as resource => amount. "i:ID" is an inventory row,
    // "s:key" is a sinker (grams across all its stock rows).
    $units = [];
    $stock = [];
    $ingredientIds = [];

    foreach ($lines as $i => $line) {
        $pid = (int) $line['product_id'];
        $product = $products[$pid] ?? null;
        $units[$i] = null;

        if (!$product || (int) $product['is_archived'] === 1 || (int) $product['is_available'] !== 1 || empty($recipes[$pid])) {
            continue;
        }

        $use = [];
        $ok = true;

        foreach ($recipes[$pid] as $row) {
            $size = ($line['size_label'] ?? '') !== '' ? $line['size_label'] : null;

            if (recipe_row_unused_for_size($row, $size)) {
                continue;
            }

            $per = recipe_required_quantity($row, $size);

            if ($per <= 0) {
                $ok = false;
                break;
            }

            $id = (int) $row['inventory_product_id'];
            $use["i:$id"] = ($use["i:$id"] ?? 0) + $per;
            $ingredientIds[$id] = true;
        }

        if (!$ok) {
            continue;
        }

        foreach (($line['sinkers'] ?? []) as $sinkerName) {
            $key = 's:' . sinker_key((string) $sinkerName);
            $use[$key] = ($use[$key] ?? 0) + SINKER_PORTION_GRAMS;
        }

        $units[$i] = $use;
    }

    if ($ingredientIds) {
        $idList = implode(',', array_map('intval', array_keys($ingredientIds)));
        $rs = $conn->query("SELECT id, quantity, is_archived FROM inventory WHERE id IN ($idList)");
        while ($row = $rs->fetch_assoc()) {
            $stock["i:" . (int) $row['id']] = (int) $row['is_archived'] === 1 ? 0.0 : (float) $row['quantity'];
        }
    }

    $sinkerRows = sinker_inventory_rows($conn);
    foreach ($sinkerRows as $key => $rows) {
        $grams = 0.0;
        foreach ($rows as $row) {
            $grams += sinker_row_grams($row);
        }
        $stock["s:$key"] = $grams;
    }

    // Total use of each resource by the whole cart.
    $total = [];
    foreach ($lines as $i => $line) {
        foreach (($units[$i] ?? []) as $res => $per) {
            $total[$res] = ($total[$res] ?? 0) + $per * (int) $line['quantity'];
        }
    }

    foreach ($lines as $i => $line) {
        if ($units[$i] === null) {
            $result[$i] = ['max' => null, 'reason' => 'Not available right now.'];
            continue;
        }

        $max = PHP_INT_MAX;

        foreach ($units[$i] as $res => $per) {
            $others = ($total[$res] ?? 0) - $per * (int) $line['quantity'];
            $left = ($stock[$res] ?? 0) - $others;
            $max = min($max, (int) floor(max(0, $left) / $per + 1e-9));
        }

        $result[$i] = ['max' => $max, 'reason' => ''];
    }

    return $result;
}

/**
 * Read and validate the price(s) and ingredients of the Add / Edit Product
 * form.
 *
 * Without sizes:  selling_price + ingredient_quantity[]
 * With sizes:     has_sizes=1, sizes[] (2 or more of small / medium / large),
 *                 price_small / price_medium / price_large and
 *                 ingredient_quantity_small[] / _medium[] / _large[]
 *                 (one quantity per ingredient for every chosen size)
 *
 * @throws InvalidArgumentException with a message that can be shown to the admin
 */
function parse_menu_product_form(array $post): array
{
    $hasSizes = !empty($post['has_sizes']) && $post['has_sizes'] !== '0';

    $prices = ['small' => 0.0, 'medium' => 0.0, 'large' => 0.0];
    $sizes = [];

    // ---- price(s)
    if ($hasSizes) {
        $chosen = is_array($post['sizes'] ?? null) ? $post['sizes'] : [];

        $sizes = array_values(array_unique(array_filter(
            $chosen,
            fn($size) => in_array($size, MENU_SIZE_KEYS, true)
        )));

        // Keep them in small -> large order.
        usort($sizes, fn($a, $b) => array_search($a, MENU_SIZE_KEYS) <=> array_search($b, MENU_SIZE_KEYS));

        if (count($sizes) < 2) {
            throw new InvalidArgumentException(
                "Choose at least two sizes, or turn sizes off."
            );
        }

        foreach ($sizes as $size) {
            $price = filter_var($post['price_' . $size] ?? null, FILTER_VALIDATE_FLOAT);

            if ($price === false || $price === null || $price <= 0 || $price > 1000000) {
                throw new InvalidArgumentException(
                    "Enter a price above zero for every size."
                );
            }

            $prices[$size] = (float) $price;
        }

        // The base price is the lowest size price ("from" price).
        $sellingPrice = min(array_map(fn($size) => $prices[$size], $sizes));

    } else {
        $price = filter_var($post['selling_price'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($price === false || $price === null || $price <= 0 || $price > 1000000) {
            throw new InvalidArgumentException(
                "Please enter a selling price above zero."
            );
        }

        $sellingPrice = (float) $price;
    }

    // ---- ingredients
    $ids = $post['ingredient_inventory_id'] ?? null;

    if (!is_array($ids) || count($ids) === 0) {
        throw new InvalidArgumentException(
            "Add at least one ingredient with its required quantity."
        );
    }

    if (count($ids) > 50) {
        throw new InvalidArgumentException("A product can have up to 50 ingredients.");
    }

    $readQuantity = function ($list, $index) {
        $value = is_array($list) ? filter_var($list[$index] ?? null, FILTER_VALIDATE_FLOAT) : false;

        if ($value === false || $value === null || $value <= 0 || $value > 100000) {
            throw new InvalidArgumentException(
                "Each ingredient needs a quantity above zero" .
                " (for every size, when sizes are on)."
            );
        }

        return (float) $value;
    };

    $recipe = [];

    foreach ($ids as $index => $rawId) {
        $ingredientId = filter_var($rawId, FILTER_VALIDATE_INT);

        if (!$ingredientId || $ingredientId < 1) {
            throw new InvalidArgumentException("Please choose an ingredient in every row.");
        }

        if (isset($recipe[$ingredientId])) {
            throw new InvalidArgumentException("Each ingredient can only be added once.");
        }

        $row = ['base' => null, 'small' => null, 'medium' => null, 'large' => null];

        if ($hasSizes) {
            foreach ($sizes as $size) {
                $row[$size] = $readQuantity($post['ingredient_quantity_' . $size] ?? null, $index);
            }

            // Older code that does not know about sizes uses this one.
            $row['base'] = $row[$sizes[0]];
        } else {
            $row['base'] = $readQuantity($post['ingredient_quantity'] ?? null, $index);
        }

        $recipe[$ingredientId] = $row;
    }

    return [
        'selling_price' => $sellingPrice,
        'prices' => $prices,
        'sizes' => $sizes,
        'recipe' => $recipe
    ];
}


// =====================================================================
// Recipes in grams / milliliters + automatic cup and straw
// =====================================================================

/**
 * Is this recipe written in grams / milliliters?
 * Every category except pizza is (pizza keeps using the inventory units).
 */
function recipe_uses_base_units(string $category, ?mysqli $conn = null): bool
{
    if ($conn !== null) {
        return !menu_category_is_food($conn, $category);
    }
    return $category !== PIZZA_CATEGORY;
}

/**
 * Active Materials items that can be the automatic cup / straw
 * (their name contains the word "cup" / "straw").
 *
 * @return array ['cups' => [row...], 'straws' => [row...]]  row: id, product_name, unit, quantity
 */
function get_serving_materials(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT id, product_name, unit, quantity
         FROM inventory
         WHERE COALESCE(is_archived, 0) = 0
           AND category IN ('Materials', 'Matrials', 'Packaging', 'Boxes')
         ORDER BY product_name ASC, id ASC"
    );

    $found = ['cups' => [], 'straws' => []];

    while ($row = $result->fetch_assoc()) {
        if (preg_match('/\bcups?\b/i', $row['product_name'])) {
            $found['cups'][] = $row;
        }

        if (preg_match('/\bstraws?\b/i', $row['product_name'])) {
            $found['straws'][] = $row;
        }
    }

    return $found;
}

/** Which size does an item name mention? 'small' | 'medium' | 'large' | null */
function material_name_size(string $name): ?string
{
    if (preg_match('/\b(small|sm|s)\b/i', $name)) {
        return 'small';
    }

    if (preg_match('/\b(medium|med|m)\b/i', $name)) {
        return 'medium';
    }

    if (preg_match('/\b(large|lg|l)\b/i', $name)) {
        return 'large';
    }

    return null;
}

/**
 * Choose the cup (or straw) for a size.
 *   1. an item named for that size ("Cup - Small")
 *   2. otherwise the single item with no size in its name ("Cup")
 *   3. otherwise the LARGE one (no cup for that size, or the serving has no size)
 *   4. otherwise, when there is only one item at all, that one
 * Anything else is ambiguous (the admin is asked to put the size in the name).
 * For a product without sizes the "medium" one is used when no large one exists.
 */
function pick_serving_material(array $items, ?string $size): ?array
{
    if (count($items) === 0) {
        return null;
    }

    $wanted = $size ?? null;

    foreach ($items as $item) {
        if ($wanted !== null && material_name_size($item['product_name']) === $wanted) {
            return $item;
        }
    }

    // An item without any size in its name ("Cup", "Straw") works for every size,
    // but only when it is the only one: two of them would be a guess.
    $generic = array_values(array_filter(
        $items,
        fn($item) => material_name_size($item['product_name']) === null
    ));

    if (count($generic) === 1) {
        return $generic[0];
    }

    // No cup for this size (or the serving has no size): use the LARGE cup / straw.
    foreach ($items as $item) {
        if (material_name_size($item['product_name']) === 'large') {
            return $item;
        }
    }

    if ($wanted === null) {
        foreach ($items as $item) {
            if (material_name_size($item['product_name']) === 'medium') {
                return $item;
            }
        }
    }

    return count($items) === 1 ? $items[0] : null;
}

/** ids of every automatic cup / straw item (so they can be hidden from the ingredient list). */
function serving_material_ids(mysqli $conn): array
{
    $found = get_serving_materials($conn);

    return array_values(array_unique(array_map(
        fn($row) => (int) $row['id'],
        array_merge($found['cups'], $found['straws'])
    )));
}

/**
 * Apply the product-form rules to the parsed form:
 *
 *  1. Every category (food too): the typed quantities are grams (Packs items) or
 *     milliliters (Liters items); Pcs items are counted. They are converted to the
 *     item's own unit for storage (1 Pack = 500 g, 1 Liter = 1,000 ml).
 *  2. Drinks only (not food categories): ONE cup and ONE straw are added to every
 *     serving, using the cup that matches the size.
 *
 * @throws InvalidArgumentException with a message that can be shown to the admin
 */
function apply_menu_recipe_rules(mysqli $conn, array $form, string $category): array
{
    // ---- 1. grams / milliliters -> inventory units (every category)
    $ids = array_keys($form['recipe']);
    $units = [];

    if (count($ids) > 0) {
        $in = implode(',', array_map('intval', $ids));
        $result = $conn->query("SELECT id, product_name, unit FROM inventory WHERE id IN ($in)");

        while ($row = $result->fetch_assoc()) {
            $units[(int) $row['id']] = $row;
        }
    }

    foreach ($form['recipe'] as $ingredientId => $quantities) {
        $item = $units[$ingredientId] ?? null;

        if (!$item) {
            continue; // reported later as "no longer exists"
        }

        $info = inventory_unit_info($item['unit']);

        if ($info['factor'] <= 1) {
            continue; // pieces (or already g / ml): nothing to convert
        }

        foreach (['base', 'small', 'medium', 'large'] as $key) {
            $typed = $quantities[$key];

            if ($typed === null) {
                continue;
            }

            $stored = round($typed / $info['factor'], 3);

            if ($stored <= 0 || abs($stored * $info['factor'] - $typed) > 0.0005) {
                throw new InvalidArgumentException(
                    "Use whole numbers of " . $info['base'] . " for " . $item['product_name']
                    . " (for example 120)."
                );
            }

            $form['recipe'][$ingredientId][$key] = $stored;
        }
    }

    // Food (pizza-style) products have no automatic cup and straw.
    if (!recipe_uses_base_units($category, $conn)) {
        return $form;
    }

    // ---- 2. automatic cup and straw (1 piece each, per serving)
    $materials = get_serving_materials($conn);
    $sizes = $form['sizes'];                      // [] when the product has no sizes
    $sizeList = count($sizes) > 0 ? $sizes : [null];

    // Drop any cup / straw that came with the form: they are managed automatically.
    foreach (array_merge($materials['cups'], $materials['straws']) as $row) {
        unset($form['recipe'][(int) $row['id']]);
    }

    foreach (['cups' => 'cup', 'straws' => 'straw'] as $listKey => $label) {
        foreach ($sizeList as $size) {
            $item = pick_serving_material($materials[$listKey], $size);

            if ($item === null) {
                throw new InvalidArgumentException(
                    $materials[$listKey]
                        ? "Couldn't tell which " . $label . " to use for the " . ($size ?? "regular")
                            . " size. Name the items with their size, e.g. \"" . ucfirst($label) . " - Small\"."
                        : "No " . $label . " found. Add a \"" . ucfirst($label)
                            . "\" item (category Materials, unit Pcs) in Inventory first."
                );
            }

            $itemId = (int) $item['id'];

            if (!isset($form['recipe'][$itemId])) {
                // 0 = "not used by this size"; NULL = size not sold.
                $row = ['base' => 1.0, 'small' => null, 'medium' => null, 'large' => null];

                foreach ($sizes as $s) {
                    $row[$s] = 0.0;
                }

                $form['recipe'][$itemId] = $row;
            }

            if ($size === null) {
                $form['recipe'][$itemId]['base'] = 1.0;
            } else {
                $form['recipe'][$itemId][$size] = 1.0;
            }
        }
    }

    return $form;
}
