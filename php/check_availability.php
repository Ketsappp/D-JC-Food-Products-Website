<?php
/**
 * Live "is it taken?" check for the Create an Account form.
 *
 * GET field=username|email|mobile & value=...
 * Returns {"ok":true,"available":true|false,"message":"..."}.
 *
 * The same format rules as the real sign-up are applied first, and the check
 * is rate-limited per visitor so it cannot be used to list accounts quickly.
 * The sign-up itself re-checks everything on the server.
 */
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

function avail_reply(bool $available, string $message = "", int $status = 200): void
{
    http_response_code($status);
    echo json_encode(["ok" => $status === 200, "available" => $available, "message" => $message]);
    exit;
}

// At most 40 checks per minute per visitor.
$now = time();
$window = array_filter($_SESSION["avail_checks"] ?? [], fn($t) => $t > $now - 60);

if (count($window) >= 40) {
    avail_reply(true, "Too many checks. Please slow down.", 429);
}

$window[] = $now;
$_SESSION["avail_checks"] = array_values($window);

$field = (string) ($_GET["field"] ?? "");
$value = trim((string) ($_GET["value"] ?? ""));

try {
    if ($field === "username") {
        if (!preg_match('/^[A-Za-z0-9_.]{4,30}$/', $value)) {
            avail_reply(true);
        }

        $stmt = $conn->prepare("SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $message = "That username is already taken.";
    } elseif ($field === "email") {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 100) {
            avail_reply(true);
        }

        $stmt = $conn->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $message = "That email is already registered.";
    } elseif ($field === "mobile") {
        $value = normalize_mobile($value) ?? "";

        if ($value === "") {
            avail_reply(true);
        }

        $stmt = $conn->prepare("SELECT 1 FROM users WHERE contact_no = ? LIMIT 1");
        $message = "That mobile number is already registered.";
    } else {
        avail_reply(true, "Unknown field.", 400);
    }

    $stmt->bind_param("s", $value);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    avail_reply(!$taken, $taken ? $message : "");
} catch (Throwable $error) {
    error_log("check_availability: " . $error->getMessage());
    // Do not block the sign-up; the real submit checks again.
    avail_reply(true, "", 200);
}
