<?php
/**
 * TEMPORARY manual GCash confirmation (used while PayMongo is not set up).
 * A staff member / admin confirms they received the GCash payment: the
 * ingredients are deducted and the order is marked Paid.
 *
 * Timing: staff can confirm a payment for GCASH_PAYMENT_MINUTES, and a LATE
 * payment for another GCASH_LATE_PAYMENT_MINUTES. After that the order is
 * cancelled for good and can no longer be confirmed (see
 * gcash_payment_window.php).
 */
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/menu_recipe_helpers.php";
require_once __DIR__ . "/gcash_payment_window.php";

header("Content-Type: application/json; charset=UTF-8");

function respond($success, $message, $extra = [])
{
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra));
    exit;
}

if (!in_array($_SESSION["role"] ?? "", ["admin", "staff"], true)) {
    http_response_code(403);
    respond(false, "Only staff can confirm payments.");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    respond(false, "POST method required.");
}

$input = json_decode(file_get_contents("php://input"), true);
$orderId = filter_var($input["order_id"] ?? null, FILTER_VALIDATE_INT);

if (!$orderId || $orderId < 1) {
    http_response_code(400);
    respond(false, "Invalid order ID.");
}

ensure_menu_size_schema($conn);

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "SELECT id, status, payment_status, paymongo_checkout_id,
                TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds
         FROM orders
         WHERE id = ? AND payment_method = 'GCash' FOR UPDATE"
    );
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        $conn->rollback();
        http_response_code(404);
        respond(false, "GCash order not found.");
    }

    if (strtolower((string) $order["payment_status"]) === "paid") {
        $conn->commit();
        respond(true, "This order was already confirmed.");
    }

    // After GCASH_PAYMENT_MINUTES + GCASH_LATE_PAYMENT_MINUTES the order is
    // cancelled for good. The time is checked here too (not only by the automatic
    // cancelling), so a late click can never bring a cancelled order back.
    $isVoided = $order["status"] === "Cancelled"
        || strtolower((string) $order["payment_status"]) === "voided";
    $isExpired = (int) $order["age_seconds"] > GCASH_CANCEL_AFTER_MINUTES * 60;

    if ($isVoided || $isExpired) {
        if (!$isVoided) {
            // The automatic check has not run yet: cancel the order right now.
            $void = $conn->prepare(
                "UPDATE orders SET status = 'Cancelled', payment_status = 'Voided' WHERE id = ?"
            );
            $void->bind_param("i", $orderId);
            $void->execute();
            $void->close();

            $conn->commit();

            if (!empty($order["paymongo_checkout_id"])) {
                paymongo_expire_checkout((string) $order["paymongo_checkout_id"]);
            }
        } else {
            $conn->rollback();
        }

        http_response_code(409);
        respond(
            false,
            "This order was cancelled because the payment time ran out (" .
            GCASH_PAYMENT_MINUTES . " minutes, plus " . GCASH_LATE_PAYMENT_MINUTES .
            " minutes for late payments). Please ask the customer to place a new order."
        );
    }

    // Past the normal payment time, but still inside the late-payment window.
    $isLate = (int) $order["age_seconds"] > GCASH_PAYMENT_MINUTES * 60;

    $itemsStmt = $conn->prepare(
        "SELECT product_id, quantity, size_label, product_name FROM order_items WHERE order_id = ? FOR UPDATE"
    );
    $itemsStmt->bind_param("i", $orderId);
    $itemsStmt->execute();
    $res = $itemsStmt->get_result();

    $lines = [];
    while ($item = $res->fetch_assoc()) {
        if (empty($item["product_id"]) || (int) $item["quantity"] <= 0) {
            throw new Exception("An order item is missing its product ID.");
        }
        $lines[] = [
            "product_id" => (int) $item["product_id"],
            "size_label" => (string) ($item["size_label"] ?? ""),
            "quantity" => (int) $item["quantity"],
            // the saved name ends with the add-ons, e.g. "... + Dine In + Tapioca Pearls"
            "sinkers" => sinkers_from_text((string) ($item["product_name"] ?? ""), true),
        ];
    }
    $itemsStmt->close();

    if (!$lines) {
        throw new Exception("No order items found.");
    }

    // Take the ingredients out of the inventory (fails if any is short).
    deduct_order_ingredients($conn, $lines, true);

    $ref = "MANUAL-" . (int) $_SESSION["user_id"];
    $upd = $conn->prepare(
        "UPDATE orders
         SET payment_status = 'Paid',
             paymongo_payment_id = ?
         WHERE id = ?"
    );
    $upd->bind_param("si", $ref, $orderId);
    $upd->execute();
    $upd->close();

    $conn->commit();
    respond(
        true,
        $isLate
            ? "Late payment confirmed. The order is now in the queue."
            : "Payment confirmed. The order is now in the queue."
    );

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}

    if ($e instanceof OrderStockException) {
        http_response_code(409);
        respond(false, $e->getMessage());
    }

    error_log("confirm_gcash_payment failed: " . $e->getMessage());
    http_response_code(500);
    respond(false, "Unable to confirm the payment. Please try again.");
}
