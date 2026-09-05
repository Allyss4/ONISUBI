<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);
$userId = current_user_id();
if (!$userId) json_response(['error' => 'Please log in before checkout.'], 401);

$items = cart_from_request();
$name = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$address = trim((string) ($_POST['address'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$paymentMethod = trim((string) ($_POST['payment_method'] ?? 'PayMongo'));

if (!$items || $name === '' || $phone === '' || $address === '') {
    json_response(['error' => 'Complete the checkout details and add an item.'], 422);
}

try {
    $conn->begin_transaction();
    $total = 0.0;
    $verified = [];

    foreach ($items as $item) {
        $productId = (int) ($item['id'] ?? 0);
        $requestedQty = max(1, min(99, (int) ($item['qty'] ?? 1)));

        $stmt = $conn->prepare('SELECT p.id, p.name, p.price, p.status, p.stock, COALESCE(i.quantity, p.stock) AS available_stock FROM Products p LEFT JOIN inventory i ON i.product_id = p.id WHERE p.id = ? LIMIT 1');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();

        if (!$product) {
            throw new RuntimeException('Product is no longer available.');
        }

        $availableStock = (int) ($product['available_stock'] ?? 0);
        $status = strtolower((string) ($product['status'] ?? ''));
        if ($status !== '' && !in_array($status, ['available', 'in stock', 'instock', 'active'], true)) {
            throw new RuntimeException('This product is currently unavailable.');
        }
        if ($availableStock <= 0) {
            throw new RuntimeException('Out of stock.');
        }
        if ($requestedQty > $availableStock) {
            throw new RuntimeException('Only ' . $availableStock . ' item(s) available.');
        }

        $total += (float) $product['price'] * $requestedQty;
        $verified[] = [
            'id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'price' => (float) $product['price'],
            'quantity' => $requestedQty,
            'available_stock' => $availableStock,
        ];
    }

    $status = 'Order Placed';
    $orderStmt = $conn->prepare('INSERT INTO orders (user_id, total, status, payment_method, order_date) VALUES (?, ?, ?, ?, NOW())');
    $orderStmt->bind_param('idss', $userId, $total, $status, $paymentMethod);
    $orderStmt->execute();
    $orderId = (int) $orderStmt->insert_id;

    foreach ($verified as $item) {
        $itemStmt = $conn->prepare('INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
        $itemStmt->bind_param('iiid', $orderId, $item['id'], $item['quantity'], $item['price']);
        $itemStmt->execute();
    }

    if ($paymentMethod === 'Cash on Delivery') {
        foreach ($verified as $item) {
            $inventoryStmt = $conn->prepare('UPDATE inventory SET quantity = quantity - ? WHERE product_id = ?');
            $inventoryStmt->bind_param('ii', $item['quantity'], $item['id']);
            $inventoryStmt->execute();

            $stockStmt = $conn->prepare('UPDATE Products SET stock = stock - ? WHERE id = ?');
            $stockStmt->bind_param('ii', $item['quantity'], $item['id']);
            $stockStmt->execute();
        }

        $conn->commit();
        json_response([
            'order_id' => $orderId,
            'status' => 'Order Placed',
            'payment_method' => 'Cash on Delivery',
            'checkout_url' => 'order-status.php?id=' . $orderId,
        ]);
    }

    $lineItems = [];
    foreach ($verified as $item) {
        $amount = (int) round((float) $item['price'] * 100);
        $lineItems[] = [
            'name' => $item['name'],
            'amount' => $amount,
            'currency' => 'PHP',
            'quantity' => (int) $item['quantity'],
        ];
    }

    $referenceNumber = 'ONISUBI-' . $orderId;
    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => $lineItems,
                'payment_method_types' => ['gcash', 'qrph', 'paymaya', 'grab_pay', 'card', 'dob'],
                'success_url' => 'http://localhost/ONISUBI/order-status.php?id=' . $orderId . '&payment=success',
                'cancel_url' => 'http://localhost/ONISUBI/checkout.php?payment=cancelled',
                'reference_number' => $referenceNumber,
                'metadata' => [
                    'order_id' => (string) $orderId,
                    'user_id' => (string) $userId,
                    'payment_method' => 'PayMongo / GCash',
                ],
            ],
        ],
    ];

    $session = paymongo_request('POST', '/v2/checkout_sessions', $payload);
    $checkoutUrl = $session['data']['attributes']['checkout_url'] ?? '';
    if ($checkoutUrl === '') {
        throw new RuntimeException('PayMongo checkout session could not be created.');
    }

    $conn->commit();
    json_response([
        'order_id' => $orderId,
        'status' => 'Order Placed',
        'payment_method' => 'PayMongo',
        'checkout_url' => $checkoutUrl,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    json_response(['error' => $exception->getMessage()], 400);
}
