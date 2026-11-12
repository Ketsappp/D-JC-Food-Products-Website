<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: order.php");
    exit;
}

require_once "db.php";
require_once __DIR__ . "/staff_accounts.php";
require_once __DIR__ . "/email_verification.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Invalid request.");
}

/*
 * Add Staff only needs a full name and an e-mail address.
 * The username and a temporary password are created here; the password is only ever sent by e-mail.
 */
$full_name = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['full_name'] ?? '')));
$email = trim((string) ($_POST['email'] ?? ''));

if ($full_name === '' || $email === '') {
    exit("Please enter the full name and the email address.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
    exit("Please enter a valid email address.");
}

if (strlen($full_name) > 100) {
    exit("The full name is too long.");
}

$usernameBase = staff_username_base($full_name);

if ($usernameBase === null) {
    exit("Please enter the staff member's first name and last name using letters (for example: Juan Dela Cruz).");
}

/* The temporary password can only be delivered by e-mail, so refuse when e-mail is not set up. */
if (!staff_mail_ready()) {
    exit("Email is not set up on this server yet (see mail_config.sample.php), so the login details cannot be sent. The staff account was not created.");
}

/* ALTER TABLE statements must run before the transaction starts. */
ensure_password_change_schema($conn);
ensure_email_verification_schema($conn);

/* One account per e-mail address. */
$check = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");

if (!$check) {
    error_log("Staff email check prepare failed: " . $conn->error);
    http_response_code(500);
    exit("Unable to check the email address. Please try again.");
}

$check->bind_param("s", $email);
$check->execute();
$emailTaken = $check->get_result()->num_rows > 0;
$check->close();

if ($emailTaken) {
    exit("A user with this email address already exists.");
}

$conn->begin_transaction();

try {
    $username = staff_unique_username($conn, $usernameBase);

    $tempPassword = generate_temporary_password();
    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

    if ($hashedPassword === false) {
        throw new RuntimeException("password_hash failed");
    }

    /* Staff do not need the e-mail confirmation customers go through: receiving the login details proves the address. */
    $stmt = $conn->prepare(
        "INSERT INTO users
            (username, password, full_name, email, contact_no, role, image, email_verified_at, must_change_password)
         VALUES (?, ?, ?, ?, '', 'staff', '', NOW(), 1)"
    );

    if (!$stmt) {
        throw new RuntimeException("Staff insert prepare failed: " . $conn->error);
    }

    $stmt->bind_param("ssss", $username, $hashedPassword, $full_name, $email);

    if (!$stmt->execute()) {
        throw new RuntimeException("Staff insert failed: " . $stmt->error);
    }

    $stmt->close();

    /* If the e-mail cannot be sent, nobody could ever learn the password - so the account is not kept. */
    if (!send_staff_credentials_email($email, $full_name, $username, $tempPassword, staff_login_url())) {
        throw new RuntimeException("Credentials e-mail could not be sent");
    }

    $conn->commit();

    echo "Staff account created successfully. The username and a temporary password were e-mailed to " . $email . ".";
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Staff account creation failed: " . $e->getMessage());
    http_response_code(500);
    echo "We couldn't send the email with the login details, so the staff account was not created. "
        . "Please check the email address and the mail settings, then try again.";
}

$conn->close();
