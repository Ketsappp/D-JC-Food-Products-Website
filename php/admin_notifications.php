<?php
/**
 * Live notification counts for the admin / staff sidebar.
 * Polled by js/admin_notify.js on every admin page.
 */
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/gcash_payment_window.php";
require_once __DIR__ . "/units.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

function notify_count(mysqli $conn, string $sql): int
{
    try {
        $res = $conn->query($sql);
        $row = $res ? $res->fetch_assoc() : null;
        return (int) ($row["total"] ?? 0);
    } catch (Throwable $e) {
        error_log("admin_notifications: " . $e->getMessage());
        return 0;
    }
}

// Orders whose GCash payment time has run out are voided here (this page is polled).
void_expired_gcash_orders($conn);

echo json_encode([
    "success" => true,
    // Low-stock inventory items (admin and staff can both open Inventory).
    // Same rule as the Inventory page and the sidebar badge (the per-unit limits in units.php).
    "low_stock" => notify_count(
        $conn,
        "SELECT COUNT(*) AS total FROM inventory
         WHERE quantity <= " . inventory_low_limit_sql() . " AND is_archived = 0"
    ),
    // Orders that are not completed yet (Pending / Preparing / Ready).
    "active_orders" => notify_count(
        $conn,
        "SELECT COUNT(*) AS total FROM orders WHERE status IN ('Pending', 'Working', 'Ready for Taking')"
    ),
    // GCash orders still waiting for payment confirmation (same list as the GCash Orders window).
    "gcash_pending" => notify_count(
        $conn,
        "SELECT COUNT(*) AS total FROM orders
         WHERE payment_method = 'GCash' AND (payment_status IS NULL OR payment_status NOT IN ('Paid', 'Voided'))"
    ),
]);
