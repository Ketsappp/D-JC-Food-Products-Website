<?php
    require_once __DIR__ . "/auth_check.php";
    require_once __DIR__ . "/db.php";
    require_once __DIR__ . "/gcash_payment_window.php";
    require_once __DIR__ . "/customer_helpers.php";

    header("Content-Type: application/json; charset=UTF-8");

    function respond($success, $message, $data = [])
    {
        echo json_encode([
            "success" => $success,
            "message" => $message,
            "data" => $data
        ]);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        http_response_code(405);
        respond(false, "Invalid request method.");
    }

    try {
        ensure_customer_order_schema($conn);

        // Void the orders whose payment time has just run out.
        void_expired_gcash_orders($conn);

        $payMinutes = (int) GCASH_PAYMENT_MINUTES;
        $cancelMinutes = (int) GCASH_CANCEL_AFTER_MINUTES;

        $sql = "
            SELECT
                o.id,
                o.order_number,
                o.customer_name,
                o.payment_method,
                o.total_amount,
                o.status,
                o.payment_status,
                o.created_at,
                o.gcash_sender_name,
                o.gcash_reference,
                o.gcash_receipt_at,
                (o.gcash_receipt IS NOT NULL AND o.gcash_receipt <> '') AS has_receipt,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), o.created_at + INTERVAL $payMinutes MINUTE)) AS pay_seconds_left,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), o.created_at + INTERVAL $cancelMinutes MINUTE)) AS cancel_seconds_left,
                (o.created_at < (NOW() - INTERVAL $payMinutes MINUTE)) AS is_late,
                GROUP_CONCAT(
                    CONCAT(oi.product_name, ' x', oi.quantity)
                    ORDER BY oi.id
                    SEPARATOR ', '
                ) AS items
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            WHERE o.payment_method = 'GCash'
            AND (
                o.payment_status IS NULL
                OR o.payment_status NOT IN ('Paid', 'Voided')
            )
            -- Cancelled orders are gone for good: they no longer appear here.
            AND o.status <> 'Cancelled'
            GROUP BY
                o.id,
                o.order_number,
                o.customer_name,
                o.payment_method,
                o.total_amount,
                o.status,
                o.payment_status,
                o.created_at,
                o.gcash_sender_name,
                o.gcash_reference,
                o.gcash_receipt_at,
                o.gcash_receipt
            ORDER BY is_late DESC, o.created_at DESC, o.id DESC
        ";

        $result = $conn->query($sql);
        $orders = [];

        while ($row = $result->fetch_assoc()) {
            $row["pay_seconds_left"] = (int) $row["pay_seconds_left"];
            $row["cancel_seconds_left"] = (int) $row["cancel_seconds_left"];
            // Past the normal payment time, inside the late-payment window.
            $row["is_late"] = (int) $row["is_late"] === 1;
            $row["has_receipt"] = (int) $row["has_receipt"] === 1;
            $orders[] = $row;
        }

        respond(true, "GCash orders loaded successfully.", $orders);

    } catch (Throwable $e) {
        error_log("Get GCash orders failed: " . $e->getMessage());

        http_response_code(500);
        respond(false, "Unable to load GCash orders.");
    }
?>
