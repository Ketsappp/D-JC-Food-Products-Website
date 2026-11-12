<?php
/**
 * Menu products for the Menu page.
 *
 * Besides the product data, every product says whether it can be ordered
 * right now (is_orderable / availability / size_stock). That depends on the
 * stock of its ingredients - see menu_recipe_helpers.php.
 *
 * Admins can ask for inactive products too (?include_inactive=1) so they
 * can activate them again. Everyone else only gets active products.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/menu_recipe_helpers.php";
require_once __DIR__ . "/menu_categories.php";
require_once __DIR__ . "/store_hours.php";

header('Content-Type: application/json; charset=utf-8');

// Outside store hours customers get no menu data. Staff and admins are exempt
// because the staff ordering screen (order.php) uses this same file.
$callerRole = $_SESSION['role'] ?? '';

if ($callerRole !== 'admin' && $callerRole !== 'staff') {
    $storeStatus = store_status();

    if (!$storeStatus['open']) {
        http_response_code(503);
        header('Cache-Control: no-store');
        echo json_encode([
            'error' => $storeStatus['order_message'],
            'store_closed' => true
        ]);
        exit;
    }
}

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$includeInactive = $isAdmin && (($_GET['include_inactive'] ?? '') === '1');

try {
    ensure_menu_size_schema($conn);

    $sql = "SELECT
                id,
                product_name,
                image,
                category,
                quantity,
                unit,
                selling_price,
                small_price,
                medium_price,
                large_price,
                description,
                is_archived
            FROM menu_inventory
            WHERE is_available = 1"
        . ($includeInactive ? "" : " AND is_archived = 0")
        . " ORDER BY category ASC, product_name ASC";

    $result = $conn->query($sql);

    $products = [];

    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }

    $availability = get_menu_availability($conn, $products);

    // Food categories (pizza-style) from the menu_categories table.
    $foodCategories = [];

    foreach (get_menu_categories($conn) as $menuCategory) {
        if ($menuCategory['is_food']) {
            $foodCategories[] = $menuCategory['name'];
        }
    }

    foreach ($products as &$product) {
        $id = (int) $product['id'];
        $isActive = (int) $product['is_archived'] === 0;
        $stock = $availability[$id] ?? ['orderable' => false, 'sizes' => []];

        $product['is_active'] = $isActive ? 1 : 0;
        // Food (pizza-style) products only ask for the order type in the Customize Order window.
        $product['is_food'] = in_array($product['category'] ?? '', $foodCategories, true) ? 1 : 0;
        $product['is_orderable'] = $isActive && $stock['orderable'];
        $product['size_stock'] = $stock['sizes'];

        if (!$isActive) {
            $product['availability'] = 'Inactive';
        } else {
            $product['availability'] = $stock['orderable'] ? 'Available' : 'Unavailable';
        }

        unset($product['is_archived']);
    }

    unset($product);

    echo json_encode($products);

} catch (Throwable $error) {
    error_log("get_menu.php: " . $error->getMessage());

    http_response_code(500);
    echo json_encode(["error" => "Failed to retrieve menu products."]);
}

$conn->close();
