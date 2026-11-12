<?php
/** Staff / admin only: show the receipt picture a customer uploaded. GET order_id. */
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/gcash_manual.php";

if (!in_array($_SESSION["role"] ?? "", ["admin", "staff"], true)) {
    http_response_code(403);
    exit;
}

$orderId = filter_var($_GET["order_id"] ?? null, FILTER_VALIDATE_INT);

if (!$orderId || $orderId < 1) {
    http_response_code(400);
    exit;
}

$stmt = $conn->prepare("SELECT gcash_receipt FROM orders WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Only a plain file name is ever stored; basename() keeps it inside the folder.
$name = basename((string) ($row["gcash_receipt"] ?? ""));
$file = gcash_upload_dir() . "/" . $name;

if ($name === "" || !is_file($file)) {
    http_response_code(404);
    exit;
}

gcash_send_image($file);
