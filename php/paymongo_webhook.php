<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/menu_recipe_helpers.php';

require_once __DIR__ . '/config_path.php';

$configFile = private_config_file('paymongo_config.php');
$config = $configFile !== null ? require $configFile : [];

function webhookResponse($httpCode, $message)
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => $httpCode >= 200 && $httpCode < 300,
        'message' => $message
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhookResponse(405, 'POST requests only.');
}

$webhookSecret = $config['webhook_secret'] ?? '';

if ($webhookSecret === '') {
    error_log('PayMongo webhook signing secret is not configured.');
    webhookResponse(500, 'Webhook configuration is incomplete.');
}

$rawBody = file_get_contents('php://input');
$signatureHeader = '';

foreach (getallheaders() as $name => $value) {
    if (strtolower($name) === 'paymongo-signature') {
        $signatureHeader = $value;
        break;
    }
}

$signatureParts = [];

foreach (explode(',', $signatureHeader) as $part) {
    $pair = explode('=', trim($part), 2);

    if (count($pair) === 2) {
        $signatureParts[$pair[0]] = $pair[1];
    }
}

$timestamp = $signatureParts['t'] ?? '';
$testSignature = $signatureParts['te'] ?? '';

if (
    $timestamp === '' ||
    $testSignature === '' ||
    !ctype_digit($timestamp) ||
    abs(time() - (int) $timestamp) > 300
) {
    webhookResponse(401, 'Invalid or expired webhook signature.');
}

$expectedSignature = hash_hmac(
    'sha256',
    $timestamp . '.' . $rawBody,
    $webhookSecret
);

if (!hash_equals($expectedSignature, $testSignature)) {
    webhookResponse(401, 'Webhook signature verification failed.');
}

$event = json_decode($rawBody, true);

$eventAttributes = $event['data']['attributes'] ?? [];

if (($eventAttributes['type'] ?? '') !== 'checkout_session.payment.paid') {
    webhookResponse(200, 'Event ignored.');
}

$session = $eventAttributes['data'] ?? [];
$sessionId = $session['id'] ?? '';
$sessionAttributes = $session['attributes'] ?? [];
$payments = $sessionAttributes['payments'] ?? [];

if (
    $sessionId === '' ||
    !is_array($payments) ||
    empty($payments)
) {
    webhookResponse(400, 'Missing checkout or payment information.');
}

$paidPayment = null;

foreach ($payments as $payment) {
    $attributes = $payment['attributes'] ?? [];

    if (($attributes['status'] ?? '') === 'paid') {
        $paidPayment = $payment;
        break;
    }
}

if ($paidPayment === null) {
    webhookResponse(200, 'No successful payment to process.');
}

$paymentAttributes = $paidPayment['attributes'] ?? [];
$paymentId = $paidPayment['id'] ?? '';
$paidAmount = $paymentAttributes['amount'] ?? null;
$currency = strtoupper($paymentAttributes['currency'] ?? '');

if (
    $paymentId === '' ||
    !is_numeric($paidAmount) ||
    $currency !== 'PHP'
) {
    error_log(
        'PayMongo webhook invalid payment details: ' .
        json_encode([
            'payment_id_missing' => $paymentId === '',
            'amount' => $paidAmount,
            'currency' => $currency
        ])
    );

    webhookResponse(400, 'Invalid payment details.');
}

// Add the size columns if needed (must happen before the transaction).
ensure_menu_size_schema($conn);

try {
    $conn->begin_transaction();

    // Lock the matching order while processing this payment.
    $stmt = $conn->prepare(
        "SELECT id, total_amount, payment_status
         FROM orders
         WHERE paymongo_checkout_id = ?
         FOR UPDATE"
    );

    $stmt->bind_param('s', $sessionId);
    $stmt->execute();

    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        $conn->rollback();
        error_log('PayMongo webhook: checkout session not matched.');
        webhookResponse(404, 'Order not found.');
    }

    $orderId = (int) $order['id'];
    $expectedAmount = (int) round(
        (float) $order['total_amount'] * 100
    );

    if ((int) $paidAmount !== $expectedAmount) {
        $conn->rollback();
        error_log('PayMongo webhook: payment amount mismatch for order ' . $orderId);
        webhookResponse(400, 'Payment amount does not match the order.');
    }

    // Duplicate notifications must not deduct stock again.
    if (strtolower($order['payment_status']) === 'paid') {
        $conn->commit();
        webhookResponse(200, 'Payment was already processed.');
    }

    // Lock the order items and retrieve their exact inventory IDs.
    $itemsStmt = $conn->prepare(
        "SELECT product_id, quantity, size_label, product_name
         FROM order_items
         WHERE order_id = ?
         FOR UPDATE"
    );

    $itemsStmt->bind_param('i', $orderId);
    $itemsStmt->execute();

    $result = $itemsStmt->get_result();
    $itemsToDeduct = [];

    while ($item = $result->fetch_assoc()) {
        if (
            empty($item['product_id']) ||
            (int) $item['quantity'] <= 0
        ) {
            throw new Exception(
                'An order item is missing its inventory product ID.'
            );
        }

        $itemsToDeduct[] = [
            'product_id' => (int) $item['product_id'],
            'size_label' => (string) ($item['size_label'] ?? ''),
            'quantity' => (int) $item['quantity'],
            // the saved name ends with the add-ons, e.g. "... + Dine In + Tapioca Pearls"
            'sinkers' => sinkers_from_text((string) ($item['product_name'] ?? ''), true)
        ];
    }

    $itemsStmt->close();

    if (empty($itemsToDeduct)) {
        throw new Exception('No order items found.');
    }

    // Take the ingredients out of the inventory, only after verified payment.
    deduct_order_ingredients($conn, $itemsToDeduct, true);

    // Record payment only after all stock deductions succeed. A payment that
    // arrives after the order was voided (time ran out) reinstates the order.
    $updateStmt = $conn->prepare(
        "UPDATE orders
         SET payment_status = 'Paid',
             status = IF(status = 'Cancelled', 'Pending', status),
             paymongo_payment_id = ?
         WHERE id = ?"
    );

    $updateStmt->bind_param('si', $paymentId, $orderId);
    $updateStmt->execute();
    $updateStmt->close();

    $conn->commit();

    webhookResponse(200, 'Payment confirmed and order updated.');

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // The transaction may already have ended.
    }

    error_log('PayMongo webhook processing failed: ' . $e->getMessage());

    webhookResponse(500, 'Payment processing failed. Please retry delivery.');
}
?>
