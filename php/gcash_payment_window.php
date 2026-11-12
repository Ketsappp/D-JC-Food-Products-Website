<?php
/**
 * Time limit for paying a GCash order.
 *
 *   0 - 15 min   normal payment time (GCASH_PAYMENT_MINUTES)
 *  15 - 20 min   late-payment window (GCASH_LATE_PAYMENT_MINUTES): the order is
 *                still waiting, and staff can still confirm a late payment
 *  after 20 min  the order is VOIDED for good (GCASH_CANCEL_AFTER_MINUTES):
 *
 *                status = 'Cancelled', payment_status = 'Voided'
 *
 * Nothing has to be put back in the inventory, because ingredients are only
 * taken when the payment is confirmed (see confirm_gcash_payment.php and
 * paymongo_webhook.php).
 *
 * There is no scheduler: void_expired_gcash_orders() runs whenever the My
 * Orders page and the staff pages refresh themselves (every few seconds), so
 * an order is voided within seconds of its time running out. The staff
 * confirmation also checks the time itself, so a late click can never bring
 * a cancelled order back.
 *
 * Times are compared with the DATABASE clock (the same clock that stamps
 * orders.created_at), so the PHP time zone never matters.
 *
 * The one exception: a payment that PayMongo itself reports as paid
 * (paymongo_webhook.php) is verified money, so it reinstates the order.
 */

// Normal time to pay (minutes).
const GCASH_PAYMENT_MINUTES = 15;

// Extra minutes after that during which a late payment is still accepted.
const GCASH_LATE_PAYMENT_MINUTES = 5;

// The order is cancelled once this many minutes have passed.
const GCASH_CANCEL_AFTER_MINUTES = GCASH_PAYMENT_MINUTES + GCASH_LATE_PAYMENT_MINUTES;

/**
 * Void every unpaid GCash order whose time has run out.
 *
 * @return int how many orders were voided now
 */
function void_expired_gcash_orders(mysqli $conn): int
{
    $voided = 0;

    try {
        $minutes = GCASH_CANCEL_AFTER_MINUTES;

        $find = $conn->prepare(
            "SELECT id, paymongo_checkout_id
             FROM orders
             WHERE payment_method = 'GCash'
               AND (payment_status IS NULL OR payment_status NOT IN ('Paid', 'Voided'))
               AND status <> 'Cancelled'
               AND created_at < (NOW() - INTERVAL ? MINUTE)
             ORDER BY id
             LIMIT 50"
        );
        $find->bind_param("i", $minutes);
        $find->execute();
        $result = $find->get_result();

        $expired = [];

        while ($row = $result->fetch_assoc()) {
            $expired[] = $row;
        }

        $find->close();

        if (!$expired) {
            return 0;
        }

        // The conditions are repeated here so an order that was confirmed a
        // moment ago (by staff or PayMongo) is never voided by mistake.
        $void = $conn->prepare(
            "UPDATE orders
             SET status = 'Cancelled', payment_status = 'Voided'
             WHERE id = ?
               AND payment_method = 'GCash'
               AND (payment_status IS NULL OR payment_status NOT IN ('Paid', 'Voided'))
               AND status <> 'Cancelled'
               AND created_at < (NOW() - INTERVAL ? MINUTE)"
        );

        foreach ($expired as $order) {
            $orderId = (int) $order["id"];

            $void->bind_param("ii", $orderId, $minutes);
            $void->execute();

            if ($void->affected_rows === 1) {
                $voided++;

                // Also close the PayMongo checkout so it cannot be paid later.
                if (!empty($order["paymongo_checkout_id"])) {
                    paymongo_expire_checkout((string) $order["paymongo_checkout_id"]);
                }
            }
        }

        $void->close();
    } catch (Throwable $error) {
        error_log("void_expired_gcash_orders: " . $error->getMessage());
    }

    return $voided;
}

/**
 * Ask PayMongo to expire a checkout session (best effort: a problem here is
 * only logged, the order is already voided in our own database).
 */
function paymongo_expire_checkout(string $checkoutId): void
{
    try {
        $config = null;

        require_once __DIR__ . "/config_path.php";

        foreach ([private_config_file("paymongo_config.php")] as $file) {
            if ($file !== null && is_file($file)) {
                $loaded = require $file;

                if (is_array($loaded)) {
                    $config = $loaded;
                    break;
                }
            }
        }

        if (!$config || empty($config["secret_key"]) || empty($config["api_url"])) {
            return;     // manual mode: there is no PayMongo checkout to close
        }

        $curl = curl_init(
            rtrim($config["api_url"], "/") . "/checkout_sessions/" . rawurlencode($checkoutId) . "/expire"
        );

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic " . base64_encode($config["secret_key"] . ":"),
                "Accept: application/json"
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6
        ]);

        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($body === false || $code < 200 || $code >= 300) {
            error_log("PayMongo expire checkout {$checkoutId} failed (HTTP {$code}).");
        }
    } catch (Throwable $error) {
        error_log("paymongo_expire_checkout: " . $error->getMessage());
    }
}

/**
 * A customer left the PayMongo checkout without paying (Back button or the checkout's own
 * cancel link): cancel their order right away instead of leaving it waiting in My Orders.
 *
 * Only the caller's own PayMongo order is touched ($userId = the customer's id, or null for an
 * order placed at the staff POS, which has no customer account), and only while it is still unpaid. If
 * the payment was already confirmed (webhook) nothing happens. The PayMongo checkout is
 * closed too, so it cannot be paid afterwards.
 *
 * @return bool true when the order was cancelled now
 */
function cancel_unpaid_paymongo_order(mysqli $conn, int $orderId, ?int $userId): bool
{
    try {
        $owner = $userId === null ? "user_id IS NULL" : "user_id = ?";

        $find = $conn->prepare(
            "SELECT paymongo_checkout_id
             FROM orders
             WHERE id = ? AND $owner AND payment_method = 'GCash'
               AND paymongo_checkout_id IS NOT NULL AND paymongo_checkout_id <> ''
             LIMIT 1"
        );
        if ($userId === null) {
            $find->bind_param("i", $orderId);
        } else {
            $find->bind_param("ii", $orderId, $userId);
        }
        $find->execute();
        $order = $find->get_result()->fetch_assoc();
        $find->close();

        if (!$order) {
            return false;
        }

        // The conditions are repeated so a payment confirmed a moment ago is never cancelled.
        $cancel = $conn->prepare(
            "UPDATE orders
             SET status = 'Cancelled', payment_status = 'Voided'
             WHERE id = ? AND $owner AND payment_method = 'GCash'
               AND (payment_status IS NULL OR payment_status NOT IN ('Paid', 'Voided'))
               AND status <> 'Cancelled'"
        );
        if ($userId === null) {
            $cancel->bind_param("i", $orderId);
        } else {
            $cancel->bind_param("ii", $orderId, $userId);
        }
        $cancel->execute();
        $done = $cancel->affected_rows === 1;
        $cancel->close();

        if ($done) {
            paymongo_expire_checkout((string) $order["paymongo_checkout_id"]);
        }

        return $done;
    } catch (Throwable $error) {
        error_log("cancel_unpaid_paymongo_order: " . $error->getMessage());
        return false;
    }
}
