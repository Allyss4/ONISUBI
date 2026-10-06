<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$userId = current_user_id();
if (!$userId) {
    json_response(['error' => 'Please log in before checkout.'], 401);
}

$items   = cart_from_request();
$name    = trim((string) ($_POST['name'] ?? ''));
$phone   = trim((string) ($_POST['phone'] ?? ''));
$address = trim((string) ($_POST['address'] ?? ''));
$notes   = trim((string) ($_POST['notes'] ?? ''));

$rawPaymentMethod = trim((string) ($_POST['payment_method'] ?? 'PayMongo'));
$isCod = (strcasecmp($rawPaymentMethod, 'Cash on Delivery') === 0 || strcasecmp($rawPaymentMethod, 'COD') === 0);
$paymentMethod = $isCod ? 'Cash on Delivery' : 'PayMongo';

$basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');

if (!$items || $name === '' || $phone === '' || $address === '') {
    json_response(['error' => 'Complete the checkout details and add an item.'], 422);
}

try {
    // 1. Execute atomic Supabase RPC: create_order
    $rpcResult = supabase_call_rpc($conn, 'create_order', [
        'user_id'        => $userId,
        'customer_name'  => $name,
        'phone'          => $phone,
        'address'        => $address,
        'notes'          => $notes,
        'payment_method' => $paymentMethod,
        'items'          => $items,
    ]);

    if (!is_array($rpcResult) || empty($rpcResult['order_id'])) {
        throw new RuntimeException('Failed to generate order.');
    }

    $orderId     = (int) $rpcResult['order_id'];
    $orderStatus = (string) ($rpcResult['status'] ?? ($isCod ? 'Order Placed' : 'Awaiting Payment'));

    // 2. Handle Cash on Delivery
    if ($isCod) {
        json_response([
            'order_id'       => $orderId,
            'status'         => $orderStatus,
            'payment_method' => 'Cash on Delivery',
            'checkout_url'   => $basePath . '/pages/order-status.php?id=' . $orderId,
        ]);
    }

    // 3. Handle PayMongo Online Checkout
    $lineItems = [];
    foreach ($items as $item) {
        $price = (float) ($item['price'] ?? 0);
        $qty   = max(1, (int) ($item['qty'] ?? 1));
        $lineItems[] = [
            'name'     => (string) ($item['name'] ?? 'ONISUBI Item'),
            'amount'   => (int) round($price * 100), // in centavos (PHP)
            'currency' => 'PHP',
            'quantity' => $qty,
        ];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $scheme . '://' . $host . $basePath;

    $referenceNumber = 'ONISUBI-' . date('Ymd') . '-' . str_pad((string) $orderId, 4, '0', STR_PAD_LEFT);

    $payload = [
        'data' => [
            'attributes' => [
                'line_items'           => $lineItems,
                'payment_method_types' => ['gcash', 'qrph', 'paymaya', 'grab_pay', 'card', 'dob'],
                'success_url'          => $baseUrl . '/pages/order-status.php?id=' . $orderId . '&payment=success&paymongo_id={CHECKOUT_SESSION_ID}',
                'cancel_url'           => $baseUrl . '/pages/checkout.php?payment=cancelled',
                'reference_number'     => $referenceNumber,
                'description'          => 'ONISUBI Order #' . $orderId,
                'metadata'             => [
                    'order_id'       => (string) $orderId,
                    'user_id'        => (string) $userId,
                    'customer_name'  => $name,
                    'phone'          => $phone,
                    'payment_method' => 'PayMongo',
                ],
            ],
        ],
    ];

    $session = paymongo_request('POST', '/v2/checkout_sessions', $payload);
    $checkoutUrl = (string) ($session['data']['attributes']['checkout_url'] ?? '');
    $checkoutSessionId = (string) ($session['data']['id'] ?? '');

    if ($checkoutUrl === '') {
        throw new RuntimeException('PayMongo checkout session could not be created.');
    }

    // Save PayMongo session ID and reference in orders table
    if (isset($conn) && $conn instanceof PDO) {
        $upStmt = $conn->prepare("UPDATE orders SET paymongo_checkout_id = :cid, payment_reference = :ref WHERE id = :id");
        $upStmt->execute([
            ':cid' => $checkoutSessionId,
            ':ref' => $referenceNumber,
            ':id'  => $orderId,
        ]);
    }

    json_response([
        'order_id'       => $orderId,
        'status'         => 'Awaiting Payment',
        'payment_method' => 'PayMongo',
        'checkout_url'   => $checkoutUrl,
    ]);
} catch (Throwable $exception) {
    json_response(['error' => $exception->getMessage()], 400);
}
