<?php
/** Email confirmation for new customer accounts. */
require_once __DIR__ . "/mailer.php";

const VERIFY_TOKEN_HOURS = 24;

function ensure_email_verification_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $col = $conn->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at'"
        );
        if ($col->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN email_verified_at DATETIME DEFAULT NULL");
            // Everyone who already exists is treated as confirmed (no one gets locked out).
            $conn->query("UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL");
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS `email_verifications` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `token_hash` char(64) NOT NULL,
                `expires_at` datetime NOT NULL,
                `used_at` datetime DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_verify_token` (`token_hash`),
                KEY `idx_verify_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } catch (Throwable $e) {
        error_log("ensure_email_verification_schema: " . $e->getMessage());
    }
}

/** Creates a fresh token (older unused ones are voided) and emails the link. */
function issue_email_verification(mysqli $conn, int $userId, string $email, string $fullName): bool
{
    $token = bin2hex(random_bytes(32));
    $hash = hash("sha256", $token);
    $hours = VERIFY_TOKEN_HOURS;

    $conn->query("DELETE FROM email_verifications WHERE user_id = " . $userId . " AND used_at IS NULL");

    $ins = $conn->prepare(
        "INSERT INTO email_verifications (user_id, token_hash, expires_at)
         VALUES (?, ?, NOW() + INTERVAL $hours HOUR)"
    );
    $ins->bind_param("is", $userId, $hash);
    $ins->execute();
    $ins->close();

    $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
    $dir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");
    $link = ($https ? "https" : "http") . "://" . ($_SERVER["HTTP_HOST"] ?? "localhost") . $dir . "/verify_email.php?token=" . $token;

    return send_confirm_email($email, $fullName, $link, $hours);
}
