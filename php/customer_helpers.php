<?php
/**
 * Helpers for the customer side: "My Orders", ready-for-pick-up
 * notifications and profile editing.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Adds the columns that link an order to a customer account and track the
 * "ready for pick-up" notification. Safe to call on every request.
 */
function ensure_customer_order_schema(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $columns = [
        ['user_id', 'INT(11) NULL DEFAULT NULL'],
        ['ready_at', 'DATETIME NULL DEFAULT NULL'],
        ['ready_seen', 'TINYINT(1) NOT NULL DEFAULT 1'],
        // Manual GCash payment proof (see gcash_manual.php)
        ['gcash_receipt', 'VARCHAR(255) NULL DEFAULT NULL'],
        ['gcash_sender_name', 'VARCHAR(100) NULL DEFAULT NULL'],
        ['gcash_reference', 'VARCHAR(30) NULL DEFAULT NULL'],
        ['gcash_receipt_at', 'DATETIME NULL DEFAULT NULL'],
        // Fingerprint (SHA-256) of the receipt picture: the same picture cannot be used twice.
        ['gcash_receipt_hash', 'CHAR(64) NULL DEFAULT NULL'],
    ];

    try {
        $check = $conn->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = ?
             LIMIT 1"
        );

        foreach ($columns as [$column, $definition]) {
            $check->bind_param("s", $column);
            $check->execute();

            if ($check->get_result()->num_rows === 0) {
                $conn->query("ALTER TABLE `orders` ADD COLUMN `$column` $definition");
            }
        }

        $check->close();
    } catch (Throwable $error) {
        error_log("ensure_customer_order_schema: " . $error->getMessage());
    }
}

/** Number shown to the customer for an order. */
function display_order_number(array $row): int
{
    return (int) $row["order_number"] > 0 ? (int) $row["order_number"] : (int) $row["id"];
}

/**
 * Customer-friendly status. step: 0 = awaiting payment, 1..4 = progress.
 */
function customer_status(array $row): array
{
    // Voided: the GCash payment was not made in time (see gcash_payment_window.php).
    if (
        $row["status"] === "Cancelled"
        || strtolower((string) $row["payment_status"]) === "voided"
    ) {
        return ["key" => "cancelled", "label" => "Cancelled", "step" => -1];
    }

    $paid = strtolower((string) $row["payment_status"]) === "paid"
        || strtolower((string) $row["payment_method"]) !== "gcash";

    if (!$paid) {
        return ["key" => "awaiting", "label" => "Awaiting payment", "step" => 0];
    }

    switch ($row["status"]) {
        case "Working":
            return ["key" => "preparing", "label" => "Preparing", "step" => 2];
        case "Ready for Taking":
            return ["key" => "ready", "label" => "Ready for pick-up", "step" => 3];
        case "Done":
            return ["key" => "done", "label" => "Completed", "step" => 4];
        default:
            return ["key" => "received", "label" => "Order received", "step" => 1];
    }
}

/** Returns the signed-in customer's id, or null. */
function current_customer_id(): ?int
{
    // A customer the admin has blocked is signed out immediately.
    global $conn;
    require_once __DIR__ . "/user_status.php";
    drop_session_if_customer_blocked($conn ?? null);

    if (!empty($_SESSION["user_id"]) && ($_SESSION["role"] ?? "") === "customer") {
        return (int) $_SESSION["user_id"];
    }

    return null;
}

/**
 * Tells a customer their order is ready. Always writes to a log file;
 * also tries to e-mail them (needs mail() / SMTP set up on the server).
 */
function notify_customer_order_ready(mysqli $conn, int $orderId): void
{
    $stmt = $conn->prepare(
        "SELECT o.id, o.order_number, u.email, u.full_name
         FROM orders o INNER JOIN users u ON u.id = o.user_id
         WHERE o.id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !filter_var($row["email"], FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $number = display_order_number($row);
    $subject = "Your D&JC order #$number is ready for pick-up";
    $body = "Hello " . $row["full_name"] . ",\r\n\r\n"
        . "Good news! Your order #$number is ready for pick-up. Please head to the counter.\r\n\r\n"
        . "Thank you for ordering from D&JC Food Products.\r\n";
    $headers = "From: D&JC Food Products <no-reply@djc.local>\r\nContent-Type: text/plain; charset=UTF-8";

    @mail($row["email"], $subject, $body, $headers);

    $dir = __DIR__ . "/logs";
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents(
        $dir . "/notifications.log",
        date("Y-m-d H:i:s") . "  order #$number  " . $row["email"] . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
