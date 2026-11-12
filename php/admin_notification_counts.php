<?php
require_once __DIR__ . "/units.php";

// Default notification counts
$lowStockCount = 0;

// Count active (active) inventory items with quantity of 10 or less
$lowStockResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM inventory
    WHERE quantity <= " . inventory_low_limit_sql() . "
      AND is_archived = 0
");

if ($lowStockResult) {
    $lowStockRow = $lowStockResult->fetch_assoc();
    $lowStockCount = (int) $lowStockRow['total'];
}
?>
