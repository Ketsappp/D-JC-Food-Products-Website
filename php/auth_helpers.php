<?php
/**
 * Shared helpers for Register / Forgot Password / Reset Password.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** HTML-escape shortcut. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/** CSRF token for the current session. */
function csrf_token(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function csrf_valid(): bool
{
    $sent = $_POST["csrf_token"] ?? "";

    return is_string($sent)
        && !empty($_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], $sent);
}

/**
 * Normalizes a Philippine mobile number to 09XXXXXXXXX.
 * Accepts 09XXXXXXXXX, 9XXXXXXXXX, +639XXXXXXXXX, 639XXXXXXXXX
 * (spaces and dashes allowed). Returns null when invalid.
 */
function normalize_mobile(string $raw): ?string
{
    $digits = preg_replace("/[\s\-()]/", "", $raw);

    if (preg_match('/^(?:\+?63|0)?(9\d{9})$/', $digits, $m)) {
        return "0" . $m[1];
    }

    return null;
}

/** Passwords nobody should be allowed to use, even when they meet the rules below. */
const COMMON_PASSWORDS = [
    "password", "password1", "password123", "passw0rd", "p@ssw0rd", "p@ssword1", "qwerty123", "qwertyuiop",
    "letmein123", "welcome123", "admin123", "iloveyou1", "abc12345", "12345678", "123456789", "1q2w3e4r"
];

/**
 * Strong-password rule (also mirrored in js/auth_modal.js, keep them in sync):
 * 8-72 characters with an uppercase letter, a lowercase letter, a number and
 * a special character; not a well-known password; not the username.
 *
 * Returns an error message, or null when the password is acceptable.
 */
function password_problem(string $password, string $username = ""): ?string
{
    if (strlen($password) > 72) {
        return "Password must not be longer than 72 characters.";
    }

    $missing = [];

    if (strlen($password) < 8) {
        $missing[] = "at least 8 characters";
    }

    if (!preg_match("/[A-Z]/", $password)) {
        $missing[] = "an uppercase letter";
    }

    if (!preg_match("/[a-z]/", $password)) {
        $missing[] = "a lowercase letter";
    }

    if (!preg_match("/\d/", $password)) {
        $missing[] = "a number";
    }

    if (!preg_match("/[^A-Za-z0-9]/", $password)) {
        $missing[] = "a special character (such as ! @ # $ %)";
    }

    if ($missing) {
        return "Password needs " . implode(", ", $missing) . ".";
    }

    if (in_array(rtrim(strtolower($password), "!@#$%^&*._-"), COMMON_PASSWORDS, true)) {
        return "That password is too common. Please choose a stronger one.";
    }

    if ($username !== "" && stripos($password, $username) !== false) {
        return "Password must not contain your username.";
    }

    return null;
}

/** Absolute URL of a sibling script in this php/ folder. */
function sibling_url(string $script): string
{
    $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
    $host = $_SERVER["HTTP_HOST"] ?? "localhost";
    $dir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");

    return ($https ? "https" : "http") . "://" . $host . $dir . "/" . $script;
}

/** Shared <head> + brand block for the auth pages. */
function auth_header(string $title, bool $wide = false): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>D&amp;JC Food Products - <?= e($title) ?></title>
<link rel="stylesheet" href="../css/login.css?v=gt1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="../css/auth-pages.css?v=gt1">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card<?= $wide ? " wide" : "" ?>">
            <div class="brand">
                <div class="brand-logo">D&amp;JC</div>
                <p class="brand-tagline">Food Products</p>
                <p class="brand-sub">Milktea &amp; Milkshake</p>
            </div>
<?php
}

function auth_footer(): void
{
    ?>
        </div>
    </div>
</body>
</html>
<?php
}
