<?php
/**
 * JSON for the signed-in customer's orders. Used by the My Orders page and
 * by the notification bell (polled every few seconds).
 *   ?summary=1  ->  only the orders that are ready and not yet seen.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/customer_helpers.php";
require_once __DIR__ . "/gcash_payment_window.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

$userId = current_customer_id();

if ($userId === null) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Please log in."]);
    exit;
}

ensure_customer_order_schema($conn);

// Cancel GCash orders whose payment time has run out (checked on every refresh).
void_expired_gcash_orders($conn);

// Orders that just became ready and the customer hasn't acknowledged yet.
$ready = [];
$stmt = $conn->prepare(
    "SELECT id, order_number FROM orders
     WHERE user_id = ? AND status = 'Ready for Taking' AND ready_seen = 0
     ORDER BY ready_at DESC, id DESC"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $ready[] = ["id" => (int) $row["id"], "number" => display_order_number($row)];
}
$stmt->close();

if (($_GET["summary"] ?? "") === "1") {
    // GCash orders still waiting for payment: the bell and the pop-ups use these to warn
    // the customer when 3 minutes are left, and when the late-payment window starts.
    $awaiting = [];
    $stmt = $conn->prepare(
        "SELECT id, order_number, status, payment_method, payment_status,
                (gcash_receipt IS NOT NULL AND gcash_receipt <> '') AS has_receipt,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL " . (int) GCASH_PAYMENT_MINUTES . " MINUTE)) AS pay_seconds_left,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL " . (int) GCASH_CANCEL_AFTER_MINUTES . " MINUTE)) AS cancel_seconds_left
         FROM orders
         WHERE user_id = ? AND payment_method = 'GCash' AND status <> 'Cancelled'
           AND (payment_status IS NULL OR payment_status NOT IN ('Paid', 'Voided'))
         ORDER BY id DESC LIMIT 10"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (customer_status($row)["key"] !== "awaiting") {
            continue;
        }
        $awaiting[] = [
            "id" => (int) $row["id"],
            "number" => display_order_number($row),
            "pay_seconds_left" => (int) $row["pay_seconds_left"],
            "cancel_seconds_left" => (int) $row["cancel_seconds_left"],
            "receipt_submitted" => (int) $row["has_receipt"] === 1,
        ];
    }
    $stmt->close();

    echo json_encode([
        "success" => true,
        "ready" => $ready,
        "awaiting" => $awaiting,
        "pay_minutes" => GCASH_PAYMENT_MINUTES,
        "late_minutes" => GCASH_LATE_PAYMENT_MINUTES,
    ]);
    exit;
}

$orders = [];
$stmt = $conn->prepare(
    "SELECT id, order_number, status, payment_method, payment_status, total_amount, created_at,
            (gcash_receipt IS NOT NULL AND gcash_receipt <> '') AS has_receipt,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL " . (int) GCASH_PAYMENT_MINUTES . " MINUTE)) AS pay_seconds_left,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL " . (int) GCASH_CANCEL_AFTER_MINUTES . " MINUTE)) AS cancel_seconds_left
     FROM orders WHERE user_id = ?
     ORDER BY id DESC LIMIT 30"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $status = customer_status($row);
    $orders[(int) $row["id"]] = [
        "id" => (int) $row["id"],
        "number" => display_order_number($row),
        "status_key" => $status["key"],
        "status_label" => $status["label"],
        "step" => $status["step"],
        // Seconds left to pay, and until the order is cancelled (only while it is
        // waiting for payment). When pay_seconds_left reaches 0 the customer is in
        // the late-payment window.
        "pay_seconds_left" => $status["key"] === "awaiting" ? (int) $row["pay_seconds_left"] : 0,
        "cancel_seconds_left" => $status["key"] === "awaiting" ? (int) $row["cancel_seconds_left"] : 0,
        "total" => (float) $row["total_amount"],
        // Has the customer already sent a GCash receipt for this order?
        "receipt_submitted" => (int) $row["has_receipt"] === 1,
        "created_at" => $row["created_at"],
        "items" => [],
    ];
}
$stmt->close();

if ($orders) {
    $ids = implode(",", array_keys($orders));  // integers only
    $res = $conn->query("SELECT order_id, product_id, product_name, quantity FROM order_items WHERE order_id IN ($ids) ORDER BY id");
    while ($item = $res->fetch_assoc()) {
        $orders[(int) $item["order_id"]]["items"][] = [
            "name" => $item["product_name"],
            "product_id" => $item["product_id"] !== null ? (int) $item["product_id"] : null,
            "qty" => (int) $item["quantity"],
        ];
    }
}

echo json_encode([
    "success" => true,
    "ready" => $ready,
    "pay_minutes" => GCASH_PAYMENT_MINUTES,
    "late_minutes" => GCASH_LATE_PAYMENT_MINUTES,
    "orders" => array_values($orders),
]);
