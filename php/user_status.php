<?php
/**
 * Account status (Enabled / Disabled) for the users table.
 * A disabled account cannot log in, but nothing is deleted.
 */

/** Adds users.is_active (1 = enabled, 0 = disabled) if it is missing. */
function ensure_user_status_schema(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        $res = $conn->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active'
             LIMIT 1"
        );

        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `users` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (Throwable $error) {
        error_log("ensure_user_status_schema: " . $error->getMessage());
    }
}


/**
 * Logs a BLOCKED (is_active = 0) customer out of the current session.
 * Returns true when the session was cleared. Safe to call on every request;
 * the database is only checked once per request.
 */
function drop_session_if_customer_blocked(?mysqli $conn): bool
{
    static $checked = null;

    if (($_SESSION["role"] ?? "") !== "customer" || empty($_SESSION["user_id"]) || !$conn) {
        return false;
    }

    if ($checked === (int) $_SESSION["user_id"]) {
        return false;
    }

    $uid = (int) $_SESSION["user_id"];

    try {
        $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        return false;
    }

    $checked = $uid;

    if (!$row || (int) $row["is_active"] !== 1) {
        unset($_SESSION["user_id"], $_SESSION["role"], $_SESSION["username"], $_SESSION["full_name"]);
        return true;
    }

    return false;
}
