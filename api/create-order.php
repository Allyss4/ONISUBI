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
  $conn->beginTransaction();
    $total = 0.0;
    $verified = [];

    foreach ($items as $item) {
        $productId = (int) ($item['id'] ?? 0);
        $requestedQty = max(1, min(99, (int) ($item['qty'] ?? 1)));

    $stmt = $conn->prepare('
            SELECT p.id, p.name, p.price, p.status, p.stock, COALESCE(i.quantity, p.stock) AS available_stock 
            FROM products p 
            LEFT JOIN inventory i ON i.product_id = p.id 
            WHERE p.id = :id 
            LIMIT 1
        ');
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch();

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

  // Updated INSERT statement including required customer_name, phone, address, and notes fields
  $orderStmt = $conn->prepare('
        INSERT INTO orders (user_id, customer_name, phone, address, notes, total, status, payment_method, order_date) 
        VALUES (:user_id, :customer_name, :phone, :address, :notes, :total, :status, :payment_method, CURRENT_TIMESTAMP)
    ');
  $orderStmt->execute([
    ':user_id' => $userId,
    ':customer_name' => $name,
    ':phone' => $phone,
    ':address' => $address,
    ':notes' => $notes ?: null,
    ':total' => $total,
    ':status' => $status,
    ':payment_method' => $paymentMethod,
  ]);

  $orderId = (int) $conn->lastInsertId();

  // Updated INSERT statement including required product_name field
    foreach ($verified as $item) {
    $itemStmt = $conn->prepare('
            INSERT INTO order_items (order_id, product_id, product_name, quantity, price) 
            VALUES (:order_id, :product_id, :product_name, :quantity, :price)
        ');
    $itemStmt->execute([
      ':order_id' => $orderId,
      ':product_id' => $item['id'],
      ':product_name' => $item['name'],
      ':quantity' => $item['quantity'],
      ':price' => $item['price'],
    ]);
    }

    if ($paymentMethod === 'Cash on Delivery') {
        foreach ($verified as $item) {
      $inventoryStmt = $conn->prepare('UPDATE inventory SET quantity = quantity - :qty WHERE product_id = :product_id');
      $inventoryStmt->execute([
        ':qty' => $item['quantity'],
        ':product_id' => $item['id'],
      ]);

      $stockStmt = $conn->prepare('UPDATE products SET stock = stock - :qty WHERE id = :product_id');
      $stockStmt->execute([
        ':qty' => $item['quantity'],
        ':product_id' => $item['id'],
      ]);
        }

        $conn->commit();
        json_response([
            'order_id' => $orderId,
            'status' => 'Order Placed',
            'payment_method' => 'Cash on Delivery',
            'checkout_url' => 'pages/order-status.php?id=' . $orderId,
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

  // Dynamic base URL detection for PayMongo redirect support across local and cloud environments
  $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $baseUrl = $scheme . '://' . $host;

  $referenceNumber = 'ONISUBI-' . $orderId;
    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => $lineItems,
                'payment_method_types' => ['gcash', 'qrph', 'paymaya', 'grab_pay', 'card', 'dob'],
        'success_url' => $baseUrl . '/pages/order-status.php?id=' . $orderId . '&payment=success',
        'cancel_url' => $baseUrl . '/pages/checkout.php?payment=cancelled',
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
  if ($conn->inTransaction()) {
    $conn->rollBack();
  }
    json_response(['error' => $exception->getMessage()], 400);
}
