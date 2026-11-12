<?php
/** Admin only: replace the GCash QR code customers scan. POST file field "qr". */
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/gcash_manual.php";

if (($_SESSION["role"] ?? "") !== "admin") {
    gcash_json(false, "Only the administrator can change the GCash QR code.", 403);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    gcash_json(false, "POST method required.", 405);
}

$error = gcash_upload_error($_FILES["qr"] ?? null);

if ($error !== null) {
    gcash_json(false, $error, 400);
}

$extension = gcash_image_extension($_FILES["qr"]["tmp_name"]);
$dir = gcash_upload_dir();

// Remove the old QR code (any format) before saving the new one.
foreach (GCASH_ALLOWED_IMAGES as $old) {
    @unlink($dir . "/qr." . $old);
}

if (!move_uploaded_file($_FILES["qr"]["tmp_name"], $dir . "/qr." . $extension)) {
    gcash_json(false, "The QR code could not be saved. Check that the uploads folder is writable.", 500);
}

gcash_json(true, "GCash QR code updated.", 200, ["version" => time()]);
