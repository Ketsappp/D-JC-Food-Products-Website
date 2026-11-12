<?php
/**
 * Login + Register logic for the pop-up on the landing page.
 *
 * Login and Register used to be two separate pages (login.php, register.php).
 * They now live in one pop-up on landing_page.php. This file holds what those
 * two pages did on the server - the checks are exactly the same:
 *
 *   login:    username + password (5 wrong attempts lock the username for
 *             15 minutes, see login_attempts.php), blocked / disabled accounts,
 *             email must be confirmed (customers), then sent to the right
 *             place by role.
 *   register: validation, duplicate check, new customer account, confirmation
 *             email, then back to the pop-up's Login tab with a notice.
 *   forgot:   emails a password reset link (reset_password.php, a separate page
 *             because it is opened from the email and needs its token).
 *
 * Call auth_process($conn) before anything is printed. It returns what the
 * pop-up (auth_modal.php) needs to draw itself, and redirects on success.
 */
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/user_status.php";
require_once __DIR__ . "/email_verification.php";
require_once __DIR__ . "/mailer.php";
require_once __DIR__ . "/login_attempts.php";

// Pages a customer may be sent to after logging in.
const AUTH_ALLOWED_NEXT = ["customerorder.php", "my_orders.php", "profile.php"];

/**
 * Reset e-mails are sent through mailer.php (SMTP, see mail_config.sample.php).
 * RESET_LOG_LINKS: also write the link to php/logs/reset_links.log for local
 * testing. Set to false in production.
 */
const RESET_LOG_LINKS = false;
const RESET_TOKEN_MINUTES = 30;

/** A query-string value as text ("" when missing or not text). */
function auth_get(string $key): string
{
    $value = $_GET[$key] ?? "";

    return is_string($value) ? $value : "";
}

/**
 * @return array{
 *   available: bool, open: string, next: string,
 *   login_error: string, login_notice: string, login_username: string,
 *   resend: bool, resend_email: string,
 *   reg_errors: array<string,string>, reg_form_error: string,
 *   reg_values: array<string,string>, reg_terms: bool,
 *   forgot_error: string, forgot_email: string, forgot_sent: bool
 * }
 */
function auth_process(mysqli $conn): array
{
    $next = $_POST["next"] ?? auth_get("next");

    if (!is_string($next) || !in_array($next, AUTH_ALLOWED_NEXT, true)) {
        $next = "";
    }

    $auth = [
        "available"      => true,   // false once a customer is signed in (no pop-up needed)
        "open"           => "",     // "", "login" or "register": which tab opens first
        "next"           => $next,
        "login_error"    => "",
        "login_notice"   => "",
        "login_username" => "",
        "resend"         => false,  // show "Resend confirmation email"
        "resend_email"   => "",
        "reg_errors"     => [],
        "reg_form_error" => "",
        "reg_values"     => ["full_name" => "", "username" => "", "email" => "", "mobile" => ""],
        "reg_terms"      => false,
        "forgot_error"   => "",
        "forgot_email"   => "",
        "forgot_sent"    => false   // the "we sent a link" message replaces the form
    ];

    // A signed-in customer has no use for the pop-up.
    if (!empty($_SESSION["user_id"]) && ($_SESSION["role"] ?? "") === "customer") {
        $auth["available"] = false;

        return $auth;
    }

    // ---- Links that open the pop-up (?auth=login / ?auth=register, email links) ----
    $tab = auth_get("auth");

    if ($tab === "login" || $tab === "register" || $tab === "forgot") {
        $auth["open"] = $tab;
    }

    $registered = auth_get("registered");
    $notice = "";

    if ($registered === "1") {
        $notice = "Account created! We sent a confirmation link to your email. Please confirm your email address before logging in.";
    } elseif ($registered === "nomail") {
        $notice = "Account created, but we couldn't send the confirmation email. Use \"Resend confirmation email\" below.";
        $auth["resend"] = true;
    } elseif (auth_get("verified") === "1") {
        $notice = "Email confirmed! You can now log in.";
    } elseif (auth_get("reset") === "1") {
        $notice = "Your password has been reset. Please log in with your new password.";
    }

    if ($notice !== "") {
        $auth["login_notice"] = $notice;
        $auth["open"] = "login";
    }

    $auth["resend_email"] = auth_get("email");

    // ---- A form was submitted from the pop-up ----
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $action = $_POST["auth_action"] ?? "";

        if ($action === "login") {
            auth_do_login($conn, $auth);
        } elseif ($action === "register") {
            auth_do_register($conn, $auth);
        } elseif ($action === "forgot") {
            auth_do_forgot($conn, $auth);
        }
    }

    return $auth;
}

/** Log in. Redirects on success; otherwise fills in the error to show. */
function auth_do_login(mysqli $conn, array &$auth): void
{
    $auth["open"] = "login";

    // Same tables as before: make sure the newer columns exist.
    ensure_user_status_schema($conn);
    ensure_email_verification_schema($conn);
    ensure_login_attempts_schema($conn);

    $username = trim((string) ($_POST["username"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");

    $auth["login_username"] = $username;

    if ($username === "" || $password === "") {
        $auth["login_error"] = "Please enter both username and password.";

        return;
    }

    // Locked after too many wrong attempts: refuse before looking at the
    // password (even the correct one), so a locked name cannot be guessed.
    $lock = login_attempts_check($conn, $username);

    if ($lock["locked"]) {
        $auth["login_error"] = login_attempts_message($lock);

        return;
    }

    $stmt = $conn->prepare(
        "SELECT id, username, password, full_name, email, role, is_active, email_verified_at
         FROM users
         WHERE username = ?
         LIMIT 1"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $stmt->close();

        // An unknown name is counted too, so the messages never reveal
        // which usernames exist.
        $auth["login_error"] = login_attempts_message(
            login_attempt_failed($conn, $username)
        );

        return;
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    $storedPassword = $user["password"];

    // First, check whether the password is securely hashed.
    $passwordIsValid = password_verify($password, $storedPassword);

    // Temporary compatibility for existing plain-text accounts.
    // If valid, immediately migrate the password to a hash.
    if (!$passwordIsValid && hash_equals((string) $storedPassword, $password)) {
        $passwordIsValid = true;

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $newHash, $user["id"]);
        $update->execute();
        $update->close();
    }

    if (!$passwordIsValid) {
        $auth["login_error"] = login_attempts_message(
            login_attempt_failed($conn, $username)
        );

        return;
    }

    // The password was right: start counting again from zero.
    login_attempts_clear($conn, $username);

    if ((int) $user["is_active"] !== 1) {
        $auth["login_error"] = $user["role"] === "customer"
            ? "This account has been blocked. Please contact the administrator."
            : "This account has been deactivated. Please contact the administrator.";

        return;
    }

    if ($user["role"] === "customer" && empty($user["email_verified_at"])) {
        $auth["login_error"] = "Please confirm your email address first. Check your inbox for the confirmation link.";
        $auth["resend"] = true;
        $auth["resend_email"] = (string) $user["email"];

        return;
    }

    session_regenerate_id(true);

    $_SESSION["user_id"] = $user["id"];
    $_SESSION["username"] = $user["username"];
    $_SESSION["full_name"] = $user["full_name"];
    $_SESSION["role"] = $user["role"];

    if ($user["role"] === "admin") {
        header("Location: inventory.php");
    } elseif ($user["role"] === "customer") {
        header("Location: " . ($auth["next"] !== "" ? $auth["next"] : "landing_page.php"));
    } else {
        header("Location: order.php");
    }

    exit;
}

/** Create a customer account. Redirects on success; otherwise fills in the errors. */
function auth_do_register(mysqli $conn, array &$auth): void
{
    $auth["open"] = "register";

    ensure_user_status_schema($conn);
    ensure_email_verification_schema($conn);

    // Someone who is already signed in (staff / admin) must log out first.
    if (!empty($_SESSION["user_id"])) {
        $auth["reg_form_error"] = "You are already signed in. Please log out before creating another account.";

        return;
    }

    $values = [
        "full_name" => trim((string) ($_POST["full_name"] ?? "")),
        "username"  => trim((string) ($_POST["username"] ?? "")),
        "email"     => trim((string) ($_POST["email"] ?? "")),
        "mobile"    => trim((string) ($_POST["mobile"] ?? ""))
    ];
    $password      = (string) ($_POST["password"] ?? "");
    $confirm       = (string) ($_POST["confirm_password"] ?? "");
    $acceptedTerms = isset($_POST["terms"]);

    $errors = [];
    $formError = "";

    if (!csrf_valid()) {
        $formError = "Your session expired. Please try again.";
    }

    // Name
    if ($values["full_name"] === "") {
        $errors["full_name"] = "Please enter your name.";
    } elseif (mb_strlen($values["full_name"]) > 100) {
        $errors["full_name"] = "Name must be 100 characters or fewer.";
    } elseif (!preg_match("/^[\p{L}][\p{L}\s.'\-]*$/u", $values["full_name"])) {
        $errors["full_name"] = "Name may only contain letters, spaces, periods, apostrophes and hyphens.";
    }

    // Username
    if (!preg_match('/^[A-Za-z0-9_.]{4,30}$/', $values["username"])) {
        $errors["username"] = "Username must be 4-30 characters: letters, numbers, underscore or period.";
    }

    // Email
    if (!filter_var($values["email"], FILTER_VALIDATE_EMAIL) || mb_strlen($values["email"]) > 100) {
        $errors["email"] = "Please enter a valid email address.";
    }

    // Mobile
    $mobile = normalize_mobile($values["mobile"]);

    if ($mobile === null) {
        $errors["mobile"] = "Enter a valid PH mobile number, e.g. 09123456789.";
    }

    // Password
    $pwProblem = password_problem($password, $values["username"]);

    if ($pwProblem !== null) {
        $errors["password"] = $pwProblem;
    }

    if ($password !== $confirm) {
        $errors["confirm_password"] = "Passwords do not match.";
    }

    // Terms
    if (!$acceptedTerms) {
        $errors["terms"] = "You must agree to the Terms of Service.";
    }

    // Uniqueness (only if the format checks passed)
    if ($formError === "" && !$errors) {
        $dup = $conn->prepare(
            "SELECT username, email, contact_no FROM users
             WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?) OR contact_no = ?"
        );
        $dup->bind_param("sss", $values["username"], $values["email"], $mobile);
        $dup->execute();
        $res = $dup->get_result();

        while ($row = $res->fetch_assoc()) {
            if (strcasecmp($row["username"], $values["username"]) === 0) {
                $errors["username"] = "That username is already taken.";
            }

            if (strcasecmp((string) $row["email"], $values["email"]) === 0) {
                $errors["email"] = "That email is already registered.";
            }

            if ($row["contact_no"] === $mobile) {
                $errors["mobile"] = "That mobile number is already registered.";
            }
        }

        $dup->close();
    }

    if ($formError === "" && !$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "INSERT INTO users
                (username, password, full_name, email, contact_no, role, image, terms_accepted_at, email_verified_at)
             VALUES (?, ?, ?, ?, ?, 'customer', '', NOW(), NULL)"
        );

        $ok = false;

        if ($stmt) {
            $stmt->bind_param(
                "sssss",
                $values["username"], $hash, $values["full_name"], $values["email"], $mobile
            );

            try {
                $ok = $stmt->execute();
            } catch (mysqli_sql_exception $ex) {
                // 1062 = duplicate key (race with another sign-up)
                if ((int) $ex->getCode() === 1062) {
                    $errors["username"] = "That username is already taken.";
                } else {
                    error_log("Register insert failed: " . $ex->getMessage());
                }
            }

            $stmt->close();
        }

        if ($ok) {
            // New accounts start unconfirmed: email them a confirmation link.
            $newId = (int) $conn->insert_id;
            $mailed = false;

            try {
                $mailed = issue_email_verification($conn, $newId, $values["email"], $values["full_name"]);
            } catch (Throwable $ex) {
                error_log("Register: confirmation email failed: " . $ex->getMessage());
            }

            unset($_SESSION["csrf_token"]);

            header(
                "Location: landing_page.php?auth=login&registered=" . ($mailed ? "1" : "nomail")
                . "&email=" . urlencode($values["email"])
            );
            exit;
        }

        if (!$errors) {
            $formError = "We couldn't create your account. Please try again.";
        }
    }

    $auth["reg_errors"] = $errors;
    $auth["reg_form_error"] = $formError;
    $auth["reg_values"] = $values;
    $auth["reg_terms"] = $acceptedTerms;
}

/** Forgot password: email a reset link. The answer is the same whether or not the email exists. */
function auth_do_forgot(mysqli $conn, array &$auth): void
{
    $auth["open"] = "forgot";

    $email = trim((string) ($_POST["email"] ?? ""));
    $auth["forgot_email"] = $email;

    if (!csrf_valid()) {
        $auth["forgot_error"] = "Your session expired. Please try again.";

        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $auth["forgot_error"] = "Please enter a valid email address.";

        return;
    }

    $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        // Throttle: one link per minute per account.
        $recent = $conn->prepare(
            "SELECT 1 FROM password_resets
             WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE) LIMIT 1"
        );
        $recent->bind_param("i", $user["id"]);
        $recent->execute();
        $tooSoon = $recent->get_result()->num_rows > 0;
        $recent->close();

        if (!$tooSoon) {
            $token = bin2hex(random_bytes(32));
            $hash = hash("sha256", $token);
            $minutes = RESET_TOKEN_MINUTES;

            // Invalidate older unused links, then store the new one.
            $conn->query("DELETE FROM password_resets WHERE user_id = " . (int) $user["id"] . " AND used_at IS NULL");

            $ins = $conn->prepare(
                "INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (?, ?, NOW() + INTERVAL $minutes MINUTE)"
            );
            $ins->bind_param("is", $user["id"], $hash);
            $ins->execute();
            $ins->close();

            $link = sibling_url("reset_password.php") . "?token=" . $token;

            send_reset_email($email, $user["full_name"], $link, $minutes);

            if (RESET_LOG_LINKS) {
                $dir = __DIR__ . "/logs";

                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }

                @file_put_contents(
                    $dir . "/reset_links.log",
                    date("Y-m-d H:i:s") . "  " . $email . "  " . $link . PHP_EOL,
                    FILE_APPEND | LOCK_EX
                );
            }
        }
    }

    // Same answer whether or not the email exists (prevents account probing).
    $auth["forgot_sent"] = true;
}
