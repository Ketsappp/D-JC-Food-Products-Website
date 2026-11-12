<?php
/**
 * Customer: send the proof of a manual GCash payment.
 * POST (multipart): order_id, sender_name, reference, receipt (picture)
 * Can be sent again (replaces the earlier receipt) until staff confirm the payment.
 * A reference number or a receipt picture that was already used on another order is refused.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/customer_helpers.php";
require_once __DIR__ . "/gcash_manual.php";
require_once __DIR__ . "/gcash_payment_window.php";

ini_set("display_errors", "0");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    gcash_json(false, "POST method required.", 405);
}

$userId = current_customer_id();

if ($userId === null) {
    gcash_json(false, "Please log in again.", 401);
}

ensure_customer_order_schema($conn);

$orderId = filter_var($_POST["order_id"] ?? null, FILTER_VALIDATE_INT);
$name = trim(preg_replace('/\s+/', " ", (string) ($_POST["sender_name"] ?? "")));
$reference = preg_replace('/[\s\-]/', "", (string) ($_POST["reference"] ?? ""));

if (!$orderId || $orderId < 1) {
    gcash_json(false, "Invalid order.", 400);
}

if ($name === "") {
    gcash_json(false, "Please enter the name on the GCash account you paid from.", 400);
}

if (!preg_match("/^[\\p{L}][\\p{L}\\s.'\\-]{1,99}$/u", $name)) {
    gcash_json(false, "The name can only have letters, spaces, periods, apostrophes and hyphens.", 400);
}

if (!preg_match('/^\d{10,20}$/', $reference)) {
    gcash_json(false, "Enter the GCash reference number (10 to 20 digits, spaces are fine).", 400);
}

$uploadError = gcash_upload_error($_FILES["receipt"] ?? null);

if ($uploadError !== null) {
    gcash_json(false, $uploadError, 400);
}

try {
    // Cancel what ran out of time first, so a late upload is refused properly.
    void_expired_gcash_orders($conn);

    $stmt = $conn->prepare(
        "SELECT id, status, payment_status, gcash_receipt
         FROM orders
         WHERE id = ? AND user_id = ? AND payment_method = 'GCash'
         LIMIT 1"
    );
    $stmt->bind_param("ii", $orderId, $userId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        gcash_json(false, "We couldn't find that order.", 404);
    }

    if (($order["payment_status"] ?? "") === "Paid") {
        gcash_json(false, "This order is already paid.", 409);
    }

    if (($order["payment_status"] ?? "") === "Voided" || ($order["status"] ?? "") === "Cancelled") {
        gcash_json(false, "This order was cancelled because the payment time ran out. If you already paid, please show your receipt to our staff.", 409);
    }

    // The same reference number cannot be used for two orders.
    $dup = $conn->prepare("SELECT id FROM orders WHERE gcash_reference = ? AND id <> ? LIMIT 1");
    $dup->bind_param("si", $reference, $orderId);
    $dup->execute();
    $used = $dup->get_result()->num_rows > 0;
    $dup->close();

    if ($used) {
        gcash_json(false, "That reference number was already used on another order. Each payment has its own reference number, please check it.", 409);
    }

    // The same receipt picture cannot be used for two orders either. Pictures are compared by
    // their fingerprint, so renaming the file does not help.
    $hash = hash_file("sha256", $_FILES["receipt"]["tmp_name"]);

    // Receipts sent before this check existed have no fingerprint yet: add it now.
    $missing = $conn->query(
        "SELECT id, gcash_receipt FROM orders
         WHERE gcash_receipt IS NOT NULL AND gcash_receipt <> '' AND gcash_receipt_hash IS NULL
         LIMIT 200"
    );

    if ($missing) {
        $fill = $conn->prepare("UPDATE orders SET gcash_receipt_hash = ? WHERE id = ?");

        while ($row = $missing->fetch_assoc()) {
            $path = gcash_upload_dir() . "/" . basename((string) $row["gcash_receipt"]);

            if (is_file($path)) {
                $oldHash = hash_file("sha256", $path);
                $rowId = (int) $row["id"];
                $fill->bind_param("si", $oldHash, $rowId);
                $fill->execute();
            }
        }

        $fill->close();
    }

    $dupPicture = $conn->prepare("SELECT id FROM orders WHERE gcash_receipt_hash = ? AND id <> ? LIMIT 1");
    $dupPicture->bind_param("si", $hash, $orderId);
    $dupPicture->execute();
    $pictureUsed = $dupPicture->get_result()->num_rows > 0;
    $dupPicture->close();

    if ($pictureUsed) {
        gcash_json(false, "This receipt picture was already used on another order. Please send the receipt of this payment.", 409);
    }

    $extension = gcash_image_extension($_FILES["receipt"]["tmp_name"]);
    $fileName = "receipt_" . $orderId . "_" . bin2hex(random_bytes(12)) . "." . $extension;

    if (!move_uploaded_file($_FILES["receipt"]["tmp_name"], gcash_upload_dir() . "/" . $fileName)) {
        gcash_json(false, "The receipt could not be saved. Please try again.", 500);
    }

    $update = $conn->prepare(
        "UPDATE orders
         SET gcash_receipt = ?, gcash_sender_name = ?, gcash_reference = ?, gcash_receipt_hash = ?, gcash_receipt_at = NOW()
         WHERE id = ?"
    );
    $update->bind_param("ssssi", $fileName, $name, $reference, $hash, $orderId);
    $update->execute();
    $update->close();

    // A re-sent receipt replaces the earlier picture.
    $old = basename((string) ($order["gcash_receipt"] ?? ""));

    if ($old !== "" && $old !== $fileName) {
        @unlink(gcash_upload_dir() . "/" . $old);
    }

    gcash_json(true, "Receipt received. Our staff will verify your payment shortly.");
} catch (Throwable $error) {
    error_log("submit_gcash_receipt: " . $error->getMessage());
    gcash_json(false, "Something went wrong. Please try again.", 500);
}
