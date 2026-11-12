<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit("Your session has expired. Please log in again.");
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit("Only administrators can update staff accounts.");
}

require_once "db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Invalid request.");
}

// Receive and clean form data.
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$full_name = trim($_POST['full_name'] ?? '');
$contact_no = trim($_POST['contact_no'] ?? '');
$email = trim($_POST['email'] ?? '');
$current_admin_password = $_POST['current_admin_password'] ?? '';

if (!$id || $id < 1 || $username === '' || $full_name === '' || $email === '') {
    exit("Please fill in all required fields.");
}

// Validate email.
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Please enter a valid email address.");
}

// Validate optional Philippine mobile number.
if (
    $contact_no !== '' &&
    !preg_match('/^(09[0-9]{9}|\+639[0-9]{9})$/', $contact_no)
) {
    exit("Please enter a valid Philippine mobile number, such as 09171234567 or +639171234567.");
}

// Every edit requires the logged-in admin's current password.
if ($current_admin_password === '') {
    exit("Please enter your current admin password before editing.");
}

$admin_id = (int) $_SESSION['user_id'];

$adminQuery = $conn->prepare(
    "SELECT password FROM users WHERE id = ? AND role = 'admin' LIMIT 1"
);

if (!$adminQuery) {
    exit("Unable to verify the administrator password.");
}

$adminQuery->bind_param("i", $admin_id);
$adminQuery->execute();
$adminResult = $adminQuery->get_result();
$admin = $adminResult->fetch_assoc();
$adminQuery->close();

if (!$admin) {
    exit("Administrator account could not be verified. Please log in again.");
}

$adminStoredPassword = (string) $admin['password'];
$adminPasswordValid = password_verify(
    $current_admin_password,
    $adminStoredPassword
);

// Support an older plaintext password temporarily.
// If it matches, immediately upgrade it to a secure hash.
if (
    !$adminPasswordValid &&
    hash_equals($adminStoredPassword, $current_admin_password)
) {
    $adminPasswordValid = true;
    $newAdminHash = password_hash(
        $current_admin_password,
        PASSWORD_DEFAULT
    );

    $migrateAdmin = $conn->prepare(
        "UPDATE users SET password = ? WHERE id = ? AND role = 'admin'"
    );

    if ($migrateAdmin) {
        $migrateAdmin->bind_param("si", $newAdminHash, $admin_id);
        $migrateAdmin->execute();
        $migrateAdmin->close();
    }
}

if (!$adminPasswordValid) {
    exit("Incorrect admin password. The staff account was not changed.");
}

// Confirm that the target account exists and is a staff account.
$staffQuery = $conn->prepare(
    "SELECT password FROM users WHERE id = ? AND role = 'staff' LIMIT 1"
);

if (!$staffQuery) {
    exit("Unable to verify the staff account.");
}

$staffQuery->bind_param("i", $id);
$staffQuery->execute();
$staffResult = $staffQuery->get_result();
$staff = $staffResult->fetch_assoc();
$staffQuery->close();

if (!$staff) {
    exit("Staff account not found.");
}

// Prevent duplicate usernames.
$check = $conn->prepare(
    "SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id != ? LIMIT 1"
);
$check->bind_param("si", $username, $id);
$check->execute();
$duplicate = $check->get_result()->num_rows > 0;
$check->close();

if ($duplicate) {
    exit("Username already exists. Please choose another username.");
}

// Prevent duplicate emails.
$check = $conn->prepare(
    "SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? LIMIT 1"
);
$check->bind_param("si", $email, $id);
$check->execute();
$duplicate = $check->get_result()->num_rows > 0;
$check->close();

if ($duplicate) {
    exit("Email address already exists. Please use another email address.");
}

// Prevent duplicate phone numbers when a phone number is provided.
if ($contact_no !== '') {
    $check = $conn->prepare(
        "SELECT id FROM users WHERE contact_no = ? AND id != ? LIMIT 1"
    );
    $check->bind_param("si", $contact_no, $id);
    $check->execute();
    $duplicate = $check->get_result()->num_rows > 0;
    $check->close();

    if ($duplicate) {
        exit("Phone number already exists. Please use another phone number.");
    }
}

// Determine whether the staff password is being changed.
$changePassword = ($password !== '');

if ($changePassword) {

    if (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        exit("Password must contain at least 8 characters, an uppercase letter, a lowercase letter, a number, and a special character.");
    }

    if ($password !== $confirm_password) {
        exit("Password and Confirm Password do not match.");
    }

    // Reject a new password that matches the staff member's existing password.
    $existingStaffPassword = (string) $staff['password'];

    if (
        password_verify($password, $existingStaffPassword) ||
        hash_equals($existingStaffPassword, $password)
    ) {
        exit("You cannot reuse the current staff password. Please choose a different password.");
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "UPDATE users
         SET username = ?, password = ?, full_name = ?, email = ?, contact_no = ?
         WHERE id = ? AND role = 'staff'"
    );

    if (!$stmt) {
        exit("Unable to prepare the staff account update.");
    }

    $stmt->bind_param(
        "sssssi",
        $username,
        $hashedPassword,
        $full_name,
        $email,
        $contact_no,
        $id
    );

} else {

    // No new password: keep the existing staff password unchanged.
    if ($confirm_password !== '') {
        exit("Leave both password fields blank if you do not want to change the password.");
    }

    $stmt = $conn->prepare(
        "UPDATE users
         SET username = ?, full_name = ?, email = ?, contact_no = ?
         WHERE id = ? AND role = 'staff'"
    );

    if (!$stmt) {
        exit("Unable to prepare the staff account update.");
    }

    $stmt->bind_param(
        "ssssi",
        $username,
        $full_name,
        $email,
        $contact_no,
        $id
    );
}

// Execute update.
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo "Staff updated successfully.";
    } else {
        echo "No changes were made.";
    }
} else {
    echo "Unable to update the staff account. Please check the information and try again.";
}

$stmt->close();
$conn->close();
?>
