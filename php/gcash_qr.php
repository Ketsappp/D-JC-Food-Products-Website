<?php
/** The store's GCash QR code picture (shown to customers who pay manually). */
require_once __DIR__ . "/gcash_manual.php";

$file = gcash_qr_file();

if ($file === null) {
    http_response_code(404);
    exit;
}

gcash_send_image($file);
