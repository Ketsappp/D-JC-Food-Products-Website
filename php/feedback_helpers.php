<?php
/**
 * Customer feedback: shown to everyone on the landing page.
 * Each customer has ONE feedback (they can edit or delete it).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const FEEDBACK_MIN_LENGTH = 5;
const FEEDBACK_MAX_LENGTH = 500;

/** Creates the feedback table if it does not exist yet. */
function ensure_feedback_schema(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS `feedback` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `rating` tinyint(1) NOT NULL,
                `comment` varchar(500) NOT NULL,
                `is_visible` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_feedback_user` (`user_id`),
                KEY `idx_feedback_visible` (`is_visible`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } catch (Throwable $error) {
        error_log("ensure_feedback_schema: " . $error->getMessage());
    }
}

/** "Juan Dela Cruz" -> "Juan D." (only a short name is shown publicly). */
function feedback_display_name(string $fullName): string
{
    $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);

    if (!$parts) {
        return "Customer";
    }

    $first = $parts[0];

    if (count($parts) > 1) {
        $last = end($parts);
        return $first . " " . mb_strtoupper(mb_substr($last, 0, 1)) . ".";
    }

    return $first;
}

/** The signed-in customer's id, or null. */
function feedback_customer_id(): ?int
{
    global $conn;
    require_once __DIR__ . "/user_status.php";
    drop_session_if_customer_blocked($conn ?? null);

    if (!empty($_SESSION["user_id"]) && ($_SESSION["role"] ?? "") === "customer") {
        return (int) $_SESSION["user_id"];
    }

    return null;
}

function feedback_token(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function feedback_token_ok($sent): bool
{
    return is_string($sent)
        && !empty($_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], $sent);
}

function feedback_json(bool $success, string $message, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["success" => $success, "message" => $message]);
    exit;
}
