<?php
/**
 * Menu categories (Mango Series, Pizza Series, ...), kept in the database so the
 * admin can add new ones with the "Add Category" button.
 *
 * is_food = 1 -> a food category like pizza: its recipe uses the inventory units
 *                (Packs / Liters) and no cup + straw is added automatically.
 * is_food = 0 -> a cup drink: recipe in grams / milliliters + automatic cup and straw.
 */

const DEFAULT_MENU_CATEGORIES = [
    ['Mango Series', 0],
    ['Coffee Series', 0],
    ['Milkshake Special', 0],
    ['Pizza Series', 1],
];

function ensure_menu_categories_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS menu_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            is_food TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_category_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $count = (int) $conn->query("SELECT COUNT(*) AS total FROM menu_categories")->fetch_assoc()['total'];
        if ($count === 0) {
            $stmt = $conn->prepare("INSERT IGNORE INTO menu_categories (name, is_food, sort_order) VALUES (?, ?, ?)");
            foreach (DEFAULT_MENU_CATEGORIES as $i => [$name, $food]) {
                $order = $i + 1;
                $stmt->bind_param("sii", $name, $food, $order);
                $stmt->execute();
            }
            $stmt->close();
        }

        // Keep any category already used by a product (e.g. from an older database).
        $conn->query("INSERT IGNORE INTO menu_categories (name, is_food, sort_order)
                      SELECT DISTINCT category, 0, 100 FROM menu_inventory
                      WHERE category IS NOT NULL AND category <> ''");
    } catch (Throwable $error) {
        error_log("ensure_menu_categories_schema: " . $error->getMessage());
    }
}

/** @return array<int, array{name:string,is_food:bool}> in display order */
function get_menu_categories(mysqli $conn): array
{
    ensure_menu_categories_schema($conn);
    $rows = [];
    try {
        $result = $conn->query("SELECT name, is_food FROM menu_categories ORDER BY sort_order ASC, id ASC");
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = ['name' => (string) $row['name'], 'is_food' => (int) $row['is_food'] === 1];
        }
    } catch (Throwable $error) {
        error_log("get_menu_categories: " . $error->getMessage());
    }
    if (!$rows) {
        foreach (DEFAULT_MENU_CATEGORIES as [$name, $food]) {
            $rows[] = ['name' => $name, 'is_food' => (bool) $food];
        }
    }
    return $rows;
}

function menu_category_names(mysqli $conn): array
{
    return array_column(get_menu_categories($conn), 'name');
}

/** Is this a food category (pizza-style)? Unknown names are treated as drinks. */
function menu_category_is_food(mysqli $conn, string $name): bool
{
    foreach (get_menu_categories($conn) as $category) {
        if ($category['name'] === $name) {
            return $category['is_food'];
        }
    }
    return false;
}
