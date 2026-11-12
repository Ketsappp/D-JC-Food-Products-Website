<?php
/**
 * Staff accounts created by the admin.
 *
 *  - The admin only enters the full name and e-mail address.
 *  - The username is made from the first letter of the first name + the last name (Juan Dela Cruz -> jcruz).
 *  - The password is a random temporary password. It is hashed straight away and e-mailed to the staff member.
 *    It is never shown on screen, never returned to the browser and never written to a log, so the admin cannot see it.
 *  - users.must_change_password = 1 makes auth_check.php send the staff member to change_password.php
 *    until they have chosen their own password.
 */
require_once __DIR__ . "/mailer.php";

const TEMP_PASSWORD_LENGTH = 12;

/** Adds users.must_change_password when it is missing. Call before any transaction (ALTER TABLE commits). */
function ensure_password_change_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $col = $conn->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'"
        );
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {
        error_log("ensure_password_change_schema: " . $e->getMessage());
    }
}

/** Lower-case a-z only: accents removed (ñ -> n), everything else dropped. */
function staff_ascii_letters(string $text): string
{
    if (class_exists("Normalizer")) {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
        if ($decomposed !== false && $decomposed !== null) {
            $text = (string) preg_replace('/\p{Mn}+/u', "", $decomposed);
        }
    }

    $text = strtr($text, [
        "ñ" => "n", "Ñ" => "N", "ç" => "c", "Ç" => "C",
        "á" => "a", "à" => "a", "â" => "a", "ä" => "a", "ã" => "a", "å" => "a",
        "é" => "e", "è" => "e", "ê" => "e", "ë" => "e",
        "í" => "i", "ì" => "i", "î" => "i", "ï" => "i",
        "ó" => "o", "ò" => "o", "ô" => "o", "ö" => "o", "õ" => "o",
        "ú" => "u", "ù" => "u", "û" => "u", "ü" => "u",
        "Á" => "A", "É" => "E", "Í" => "I", "Ó" => "O", "Ú" => "U",
    ]);

    return (string) preg_replace('/[^a-z]/', "", strtolower($text));
}

/** [first name, last name] as lower-case letters, or null when the name is not usable. */
function staff_name_parts(string $fullName): ?array
{
    $name = trim((string) preg_replace('/\s+/u', " ", $fullName));

    if ($name === "" || !preg_match('/^[\p{L}][\p{L} .\'’-]*$/u', $name)) {
        return null;
    }

    $words = [];
    foreach (explode(" ", $name) as $word) {
        if (staff_ascii_letters($word) !== "") {
            $words[] = $word;
        }
    }

    // "Juan Dela Cruz Jr." -> the last name is Cruz, not Jr.
    while (count($words) > 2 && in_array(staff_ascii_letters($words[count($words) - 1]), ["jr", "sr", "ii", "iii", "iv"], true)) {
        array_pop($words);
    }

    if (count($words) < 2) {
        return null;
    }

    return [staff_ascii_letters($words[0]), staff_ascii_letters($words[count($words) - 1])];
}

/** First letter of the first name + the last name, e.g. "Juan Dela Cruz" -> "jcruz". */
function staff_username_base(string $fullName): ?string
{
    $parts = staff_name_parts($fullName);

    if ($parts === null) {
        return null;
    }

    return substr(substr($parts[0], 0, 1) . $parts[1], 0, 30);
}

/** Adds 2, 3, 4 ... until the username is not taken (case-insensitive). */
function staff_unique_username(mysqli $conn, string $base): string
{
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
    $candidate = $base;

    for ($n = 2; $n < 1000; $n++) {
        $stmt->bind_param("s", $candidate);
        $stmt->execute();
        $stmt->store_result();
        $taken = $stmt->num_rows > 0;

        if (!$taken) {
            $stmt->close();
            return $candidate;
        }

        $candidate = $base . $n;
    }

    $stmt->close();
    throw new RuntimeException("No free username for " . $base);
}

/** Random temporary password with an upper-case letter, a lower-case letter, a digit and a symbol (no look-alike characters). */
function generate_temporary_password(int $length = TEMP_PASSWORD_LENGTH): string
{
    $sets = [
        "ABCDEFGHJKLMNPQRSTUVWXYZ",
        "abcdefghijkmnpqrstuvwxyz",
        "23456789",
        "!@#$%&*?",
    ];
    $all = implode("", $sets);
    $chars = [];

    foreach ($sets as $set) {
        $chars[] = $set[random_int(0, strlen($set) - 1)];
    }

    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return implode("", $chars);
}

/** Same rule the staff dialog used to enforce when the admin typed a password. */
function staff_password_problem(string $password): ?string
{
    if (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        return "Your password must have at least 8 characters, an uppercase letter, a lowercase letter, a number and a special character.";
    }

    return null;
}

/** True when real e-mail is set up. Without it the mailer only writes the message to a log file, which would expose the password. */
function staff_mail_ready(): bool
{
    return mail_config() !== null;
}

function staff_login_url(): string
{
    $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
    $dir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");

    return ($https ? "https" : "http") . "://" . ($_SERVER["HTTP_HOST"] ?? "localhost") . $dir . "/landing_page.php?auth=login";
}

/** Sends the username and temporary password. Never call this when the mailer is not configured (see staff_mail_ready). */
function send_staff_credentials_email(string $toEmail, string $fullName, string $username, string $tempPassword, string $loginUrl): bool
{
    if (!staff_mail_ready()) {
        return false;
    }

    $first = trim(explode(" ", trim($fullName))[0] ?? "") ?: "there";
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, "UTF-8");

    $box = "background:#fbf6ef;border:1px solid #ecd9c3;border-radius:12px;padding:16px 18px;margin:18px 0;font-size:15px;line-height:1.9";
    $code = "font-family:Consolas,Menlo,monospace;font-size:16px;font-weight:700;letter-spacing:.5px";

    $html = mail_layout(
        "Your D&JC staff account",
        "<p>Hi " . $h($first) . ",</p>"
        . "<p>An administrator created a staff account for you at D&amp;JC Food Products. Use the details below to log in.</p>"
        . '<div style="' . $box . '">'
        . 'Username: <span style="' . $code . '">' . $h($username) . '</span><br>'
        . 'Temporary password: <span style="' . $code . '">' . $h($tempPassword) . '</span>'
        . "</div>"
        . "<p><strong>You will be asked to choose your own password the first time you log in.</strong> "
        . "Please do not share this email or the temporary password with anyone.</p>",
        "Log in",
        $loginUrl
    );

    $text = "Hi $first,\n\nAn administrator created a staff account for you at D&JC Food Products.\n\n"
        . "Username: $username\nTemporary password: $tempPassword\n\n"
        . "Log in here: $loginUrl\n\n"
        . "You will be asked to choose your own password the first time you log in. "
        . "Please do not share this email or the temporary password with anyone.\n";

    return send_system_mail($toEmail, $fullName, "Your D&JC staff account", $html, $text);
}
