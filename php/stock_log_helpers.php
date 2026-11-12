<?php
/**
 * Audit trail for MANUAL inventory changes (who added / changed stock, when, by how much).
 * Automatic deductions made when orders are completed are not manual changes, so they are not listed here.
 */

function ensure_stock_log_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS `stock_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `inventory_id` int(11) DEFAULT NULL,
                `item_name` varchar(150) NOT NULL,
                `unit` varchar(30) NOT NULL DEFAULT '',
                `change_type` varchar(20) NOT NULL,
                `quantity_before` decimal(12,3) NOT NULL DEFAULT 0.000,
                `quantity_change` decimal(12,3) NOT NULL DEFAULT 0.000,
                `quantity_after` decimal(12,3) NOT NULL DEFAULT 0.000,
                `source` varchar(40) NOT NULL DEFAULT '',
                `user_id` int(11) DEFAULT NULL,
                `user_name` varchar(100) NOT NULL DEFAULT '',
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_stock_log_created` (`created_at`),
                KEY `idx_stock_log_item` (`inventory_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        // Tables created earlier used 2 decimals; stock now needs 3.
        $scale = $conn->query(
            "SELECT NUMERIC_SCALE AS s FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_log' AND COLUMN_NAME = 'quantity_after'"
        )->fetch_assoc();

        if ($scale && (int) $scale["s"] < 3) {
            $conn->query(
                "ALTER TABLE stock_log
                    MODIFY quantity_before DECIMAL(12,3) NOT NULL DEFAULT 0.000,
                    MODIFY quantity_change DECIMAL(12,3) NOT NULL DEFAULT 0.000,
                    MODIFY quantity_after DECIMAL(12,3) NOT NULL DEFAULT 0.000"
            );
        }
    } catch (Throwable $e) {
        error_log("ensure_stock_log_schema: " . $e->getMessage());
    }
}

/**
 * Records one stock change. Never throws: a logging problem must not block the real save.
 *   $before = null for a brand-new item.
 */
function log_stock_change(
    mysqli $conn,
    ?int $inventoryId,
    string $itemName,
    string $unit,
    ?float $before,
    float $after,
    string $source
): void {
    try {
        ensure_stock_log_schema($conn);

        if ($before === null) {
            $type = "New item";
            $before = 0.0;
        } elseif ($after > $before) {
            $type = "Stock added";
        } elseif ($after < $before) {
            $type = "Stock reduced";
        } else {
            return; // nothing changed
        }

        $change = round($after - $before, 3);
        $userId = isset($_SESSION["user_id"]) ? (int) $_SESSION["user_id"] : null;
        $userName = (string) ($_SESSION["full_name"] ?? $_SESSION["username"] ?? "");

        $stmt = $conn->prepare(
            "INSERT INTO stock_log
                (inventory_id, item_name, unit, change_type, quantity_before, quantity_change, quantity_after, source, user_id, user_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "isssdddsis",
            $inventoryId, $itemName, $unit, $type, $before, $change, $after, $source, $userId, $userName
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log("log_stock_change: " . $e->getMessage());
    }
}

/** Current quantity + name + unit of an item, or null. */
function stock_snapshot(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT product_name, unit, quantity FROM inventory WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}
