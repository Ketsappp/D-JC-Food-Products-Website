<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// An account disabled by the admin is logged out immediately.
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/user_status.php";
require_once __DIR__ . "/staff_accounts.php";

ensure_user_status_schema($conn);
ensure_password_change_schema($conn);

$statusStmt = $conn->prepare("SELECT is_active, must_change_password FROM users WHERE id = ? LIMIT 1");
$statusStmt->bind_param("i", $_SESSION['user_id']);
$statusStmt->execute();
$statusRow = $statusStmt->get_result()->fetch_assoc();
$statusStmt->close();

if (!$statusRow || (int) $statusRow['is_active'] !== 1) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

// Customer accounts (self-registered) must never reach staff/admin pages.
if (($_SESSION['role'] ?? '') === 'customer') {
    header("Location: landing_page.php");
    exit;
}

// A staff member who is still using the temporary password from their welcome e-mail must choose their own first.
if ((int) ($statusRow['must_change_password'] ?? 0) === 1) {
    $isPageRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false;

    if ($isPageRequest) {
        header("Location: change_password.php");
        exit;
    }

    http_response_code(403);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["success" => false, "message" => "Please change your temporary password first."]);
    exit;
}
?>
