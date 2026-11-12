<?php
// save_order.php
header('Content-Type: application/json; charset=UTF-8');

// This endpoint must only ever print JSON. PHP warnings/notices are logged
// (not printed), stray output is discarded, and fatal errors are returned as
// a JSON message instead of an HTML error page.
ini_set('display_errors', '0');
ob_start();

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        error_log('save_order.php fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'status' => 'error',
            'message' => 'Server error: ' . strtok($error['message'], "\n")
                . ' (' . basename($error['file']) . ':' . $error['line'] . ')'
        ]);
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
require_once __DIR__ . '/menu_recipe_helpers.php';
require_once __DIR__ . '/gcash_payment_window.php';
require_once __DIR__ . '/customer_helpers.php';
require_once __DIR__ . '/store_hours.php';

// PayMongo settings are optional for now. If the settings file can't be found,
// Cash orders still work and GCash orders are saved as "awaiting payment" so
// staff can confirm them by hand (TEMPORARY manual mode).
$paymongoConfig = null;

require_once __DIR__ . '/config_path.php';

foreach ([private_config_file('paymongo_config.php')] as $configFile) {
    if ($configFile !== null && is_file($configFile)) {
        $loaded = require $configFile;

        if (is_array($loaded)) {
            $paymongoConfig = $loaded;
            break;
        }
    }
}

$paymongoReady = $paymongoConfig !== null;

function respond($status, $message, $extra = [])
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $extra));
    exit;
}

function createPayMongoCheckout(
    $config,
    $orderNumber,
    $items,
    $successUrl,
    $cancelUrl
) {
    $lineItems = [];

    foreach ($items as $item) {
        $name = trim($item['name']);

        if (!empty($item['sizeLabel'])) {
            $name .= ' (' . $item['sizeLabel'] . ')';
        }

        if (!empty($item['sinkerMeta'])) {
            $name .= ' + ' . $item['sinkerMeta'];
        }

        $lineItems[] = [
            'name' => $name,
            'amount' => (int) round(
                (float) $item['unitPrice'] * 100
            ),
            'currency' => 'PHP',
            'quantity' => (int) $item['qty']
        ];
    }

    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => $lineItems,
                'payment_method_types' => ['gcash'],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'reference_number' => (string) $orderNumber
            ]
        ]
    ];

    $url = rtrim($config['api_url'], '/')
        . '/checkout_sessions';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode(
                $config['secret_key'] . ':'
            ),
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($responseBody === false) {
        error_log('PayMongo connection error: ' . $curlError);
        throw new Exception(
            'Unable to connect to PayMongo. Please try again.'
        );
    }

    $responseData = json_decode($responseBody, true);

    if (
        $httpCode < 200 ||
        $httpCode >= 300 ||
        empty($responseData['data']['attributes']['checkout_url']) ||
        empty($responseData['data']['id'])
    ) {
        error_log('PayMongo API error: ' . $responseBody);
        throw new Exception(
            'PayMongo could not create the checkout session.'
        );
    }

    return [
        'checkout_id' => $responseData['data']['id'],
        'checkout_url' =>
            $responseData['data']['attributes']['checkout_url']
    ];
}


// Accept POST requests only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond('error', 'Invalid request method.');
}

// Read and validate the request body.
$data = json_decode(file_get_contents('php://input'), true);

if (
    !is_array($data) ||
    empty($data['items']) ||
    !is_array($data['items'])
) {
    http_response_code(400);
    respond('error', 'Invalid order payload.');
}

// The order number will be assigned from the database-generated order ID.
$orderNumber = 0;
$customerName = trim($data['customer_name'] ?? '');
$customerName = $customerName !== '' ? $customerName : 'Walk-in Customer';
$paymentMethod = $data['payment_method'] ?? 'Cash';
$items = $data['items'];
// Where PayMongo sends the customer back to: this same site (works on localhost and on a host).
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$siteBase = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$successUrl = $siteBase . '/gcash_return.php';
$cancelUrl = $siteBase . '/gcash_return.php?cancelled=1';

// Validate payment method.
if (!in_array($paymentMethod, ['Cash', 'GCash'], true)) {
    http_response_code(400);
    respond('error', 'Invalid payment method.');
}

// Customers must be signed in and can only pay with GCash (verified by the
// payment provider), so fake orders can't be placed. Only staff and admins
// may take cash at the counter.
$sessionRole = $_SESSION['role'] ?? '';
$isStaffUser = in_array($sessionRole, ['admin', 'staff'], true);

if (!$isStaffUser) {
    // Customers can only order during store hours (see store_hours.php).
    // The website itself stays open; only placing an order is refused.
    $storeStatus = store_status();

    if (!$storeStatus['open']) {
        http_response_code(403);
        respond('error', $storeStatus['order_message'], ['store_closed' => true]);
    }

    if (empty($_SESSION['user_id']) || $sessionRole !== 'customer') {
        http_response_code(401);
        respond('error', 'Please log in to place an order.');
    }

    if ($paymentMethod !== 'GCash') {
        http_response_code(403);
        respond('error', 'GCash is the only accepted payment method.');
    }

    // Use the name on the customer's account.
    $customerName = trim((string) ($_SESSION['full_name'] ?? '')) ?: 'Customer';
}

// Validate cart items before starting the transaction.
foreach ($items as $item) {
    if (
        !is_array($item) ||
        !isset($item['productId'], $item['qty'], $item['name'], $item['unitPrice']) ||
        intval($item['productId']) <= 0 ||
        intval($item['qty']) <= 0 ||
        !is_numeric($item['unitPrice']) ||
        floatval($item['unitPrice']) < 0
    ) {
        http_response_code(400);
        respond('error', 'One or more order items are invalid.');
    }
}

$orderQuantity = array_sum(array_map(fn($item) => intval($item['qty']), $items));

if ($orderQuantity > MAX_ORDER_QTY) {
    http_response_code(400);
    respond('error', 'An order can hold at most ' . MAX_ORDER_QTY . ' items in total.');
}

$totalAmount = 0;
$stockLines = [];

foreach ($items as $item) {
    $totalAmount += floatval($item['unitPrice']) * intval($item['qty']);

    // What the kitchen needs for this line: the product, size and quantity.
    $stockLines[] = [
        'product_id' => intval($item['productId']),
        'size_label' => trim((string) ($item['sizeLabel'] ?? '')),
        'quantity' => intval($item['qty']),
        // "Tapioca Pearls" and "Extra Nata Jelly" in the add-ons each take a portion of grams
        'sinkers' => sinkers_from_text((string) ($item['sinkerMeta'] ?? ''))
    ];
}

// Add the size columns if this database does not have them yet.
// (Must happen before the transaction starts.)
ensure_menu_size_schema($conn);
ensure_customer_order_schema($conn);

// Orders placed by a customer are linked to their account (for My Orders).
$orderUserId = ($sessionRole === 'customer') ? (int) $_SESSION['user_id'] : null;

try {
    $conn->begin_transaction();

    // 1. Create the order.
    $stmt = $conn->prepare(
        "INSERT INTO orders
            (order_number, customer_name, payment_method, total_amount, user_id)
        VALUES (0, ?, ?, ?, ?)"
    );

    $stmt->bind_param(
        "ssdi",
        $customerName,
        $paymentMethod,
        $totalAmount,
        $orderUserId
    );

    $stmt->execute();
    $orderId = $conn->insert_id;
    $stmt->close();

    // Use the database-generated ID as the displayed order number.
    $orderNumber = $orderId;

    $numberStmt = $conn->prepare(
        "UPDATE orders
        SET order_number = ?
        WHERE id = ?"
    );

    $numberStmt->bind_param(
        "ii",
        $orderNumber,
        $orderId
    );

    $numberStmt->execute();
    $numberStmt->close();

    // GCash: create a pending order and start PayMongo checkout.
    // Stock will be deducted only after payment is verified.
    if ($paymentMethod === 'GCash') {

        // Do not take a payment for something that cannot be made.
        deduct_order_ingredients($conn, $stockLines, false);

        // Save the purchased items without deducting stock yet.

        $itemStmt = $conn->prepare(
            "INSERT INTO order_items
                (order_id, product_id, product_name, unit_price, quantity, total_price, size_label)
            VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($items as $item) {
            $productId = intval($item['productId']);
            $sizeLabel = trim((string) ($item['sizeLabel'] ?? ''));
            $qty = intval($item['qty']);
            $unitPrice = floatval($item['unitPrice']);
            $itemTotal = $unitPrice * $qty;

            $displayName = $item['name'];

            if (!empty($item['sizeLabel'])) {
                $displayName .= ' (' . $item['sizeLabel'] . ')';
            }

            if (!empty($item['sinkerMeta'])) {
                $displayName .= ' + ' . $item['sinkerMeta'];
            }


            $itemStmt->bind_param(
                "iisdids",
                $orderId,
                $productId,
                $displayName,
                $unitPrice,
                $qty,
                $itemTotal,
                $sizeLabel
            );

            $itemStmt->execute();

        }

        $itemStmt->close();

        // TEMPORARY manual mode: no PayMongo settings, so keep the order as
        // "awaiting payment". Staff confirm it from the GCash Orders list once
        // they see the GCash payment; stock is deducted at that moment.
        // PayMongo is not set up: the customer pays by scanning the store's GCash
        // QR code and uploading the receipt (see gcash_manual.php).
        if (!$paymongoReady) {
            $conn->commit();

            respond('success', 'Order saved. Pay with the GCash QR code, then upload your receipt.', [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'total_amount' => $totalAmount,
                'manual_gcash' => true,
                'paymongo_down' => false,
                'payment_minutes' => GCASH_PAYMENT_MINUTES,
                'late_payment_minutes' => GCASH_LATE_PAYMENT_MINUTES
            ]);
        }

        // Use the database order ID as the unique PayMongo reference.
        $returnUrl = $siteBase . '/gcash_return.php';

        $successUrl = $returnUrl . '?order_id=' . $orderId;
        $cancelUrl = $returnUrl . '?cancelled=1&order_id=' . $orderId;

        // Create the PayMongo Checkout Session. If PayMongo is down, the order is
        // kept and the customer pays by scanning the store's GCash QR instead.
        try {
            $checkout = createPayMongoCheckout(
                $paymongoConfig,
                $orderId,
                $items,
                $successUrl,
                $cancelUrl
            );
        } catch (Throwable $payMongoError) {
            error_log('PayMongo unavailable, using the GCash QR code: ' . $payMongoError->getMessage());

            $conn->commit();

            respond('success', 'PayMongo is unavailable. Pay with the GCash QR code, then upload your receipt.', [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'total_amount' => $totalAmount,
                'manual_gcash' => true,
                'paymongo_down' => true,
                'payment_minutes' => GCASH_PAYMENT_MINUTES,
                'late_payment_minutes' => GCASH_LATE_PAYMENT_MINUTES
            ]);
        }

        // Associate the Checkout Session with this database order.
        $updateStmt = $conn->prepare(
            "UPDATE orders
             SET paymongo_checkout_id = ?
             WHERE id = ?"
        );

        $updateStmt->bind_param(
            "si",
            $checkout['checkout_id'],
            $orderId
        );

        $updateStmt->execute();
        $updateStmt->close();

        // Save the order only if the checkout session was created.
        $conn->commit();

        respond('success', 'Redirecting to PayMongo checkout.', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total_amount' => $totalAmount,
            'checkout_url' => $checkout['checkout_url'],
            'payment_minutes' => GCASH_PAYMENT_MINUTES,
                'late_payment_minutes' => GCASH_LATE_PAYMENT_MINUTES
        ]);
    }

    // Cash orders: take the ingredients out of the inventory now.
    // This fails (and nothing is saved) if any ingredient is short.
    deduct_order_ingredients($conn, $stockLines, true);

    // Save the purchased items.
    $itemStmt = $conn->prepare(
        "INSERT INTO order_items
            (order_id, product_id, product_name, unit_price, quantity, total_price, size_label)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($items as $item) {
        $productId = intval($item['productId']);
        $qty = intval($item['qty']);
        $unitPrice = floatval($item['unitPrice']);
        $itemTotal = $unitPrice * $qty;
        $sizeLabel = trim((string) ($item['sizeLabel'] ?? ''));

        // Save the purchased item and its customization.
        $displayName = $item['name'];

        if ($sizeLabel !== '') {
            $displayName .= ' (' . $sizeLabel . ')';
        }

        if (!empty($item['sinkerMeta'])) {
            $displayName .= ' + ' . $item['sinkerMeta'];
        }

        $itemStmt->bind_param(
            "iisdids",
            $orderId,
            $productId,
            $displayName,
            $unitPrice,
            $qty,
            $itemTotal,
            $sizeLabel
        );

        $itemStmt->execute();
    }

    $itemStmt->close();

    // 4. Commit only after everything succeeds.
    $conn->commit();

    respond('success', 'Order completed successfully!', [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'total_amount' => $totalAmount
    ]);

} catch (Throwable $e) {
    $conn->rollback();

    // A stock problem (e.g. "Not enough Milk 1L in stock.") is safe to show.
    if ($e instanceof OrderStockException) {
        http_response_code(409);
        respond('error', $e->getMessage());
    }

    error_log('Order transaction failed: ' . $e->getMessage());

    http_response_code(500);

    // Keep detailed database errors in the server log.
    respond(
        'error',
        'The order could not be completed. Please check the available stock and try again.'
    );
}
?>
