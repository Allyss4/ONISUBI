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

// Only two payment methods are allowed now.
$allowedMethods = ['GCash QR', 'Cash on Delivery'];
$paymentMethod  = trim((string) ($_POST['payment_method'] ?? 'GCash QR'));
if (!in_array($paymentMethod, $allowedMethods, true)) {
    $paymentMethod = 'GCash QR';
}

// Base path of the project, e.g. "/ONISUBI" (works from any page, local or deployed).
// Assumes this file is one folder deep (e.g. /ONISUBI/api/create-order.php).
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');

if (!$items || $name === '' || $phone === '' || $address === '') {
    json_response(['error' => 'Complete the checkout details and add an item.'], 422);
}

try {
    $conn->beginTransaction();
    $total = 0.0;
    $verified = [];

    foreach ($items as $item) {
        $productId    = (int) ($item['id'] ?? 0);
        $requestedQty = max(1, min(99, (int) ($item['qty'] ?? 1)));

        $stmt = $conn->prepare('
            SELECT p.id, p.name, p.price, p.status, p.stock, COALESCE(i.quantity, p.stock) AS available_stock
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id
            WHERE p.id = :id
            LIMIT 1
            FOR UPDATE OF p
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
            'id'              => (int) $product['id'],
            'name'            => (string) $product['name'],
            'price'           => (float) $product['price'],
            'quantity'        => $requestedQty,
            'available_stock' => $availableStock,
        ];
    }

    // COD is confirmed right away; GCash waits for the customer's payment proof.
    $orderStatus = $paymentMethod === 'Cash on Delivery' ? 'Order Placed' : 'Awaiting Payment';

    $orderStmt = $conn->prepare('
        INSERT INTO orders (user_id, customer_name, phone, address, notes, total, status, payment_method, order_date)
        VALUES (:user_id, :customer_name, :phone, :address, :notes, :total, :status, :payment_method, CURRENT_TIMESTAMP)
        RETURNING id
    ');
    $orderStmt->execute([
        ':user_id'        => $userId,
        ':customer_name'  => $name,
        ':phone'          => $phone,
        ':address'        => $address,
        ':notes'          => $notes ?: null,
        ':total'          => $total,
        ':status'         => $orderStatus,
        ':payment_method' => $paymentMethod,
    ]);

    $orderId = (int) $orderStmt->fetchColumn(); // Postgres: id comes from RETURNING

    $itemStmt = $conn->prepare('
        INSERT INTO order_items (order_id, product_id, product_name, quantity, price)
        VALUES (:order_id, :product_id, :product_name, :quantity, :price)
    ');
    foreach ($verified as $item) {
        $itemStmt->execute([
            ':order_id'     => $orderId,
            ':product_id'   => $item['id'],
            ':product_name' => $item['name'],
            ':quantity'     => $item['quantity'],
            ':price'        => $item['price'],
        ]);
    }

    // Reserve stock immediately for both methods.
    // If you'd rather deduct GCash stock only after you verify payment,
    // wrap this block in: if ($paymentMethod === 'Cash on Delivery') { ... }
    $inventoryStmt = $conn->prepare('UPDATE inventory SET quantity = quantity - :qty WHERE product_id = :product_id');
    $stockStmt     = $conn->prepare('UPDATE products SET stock = stock - :qty WHERE id = :product_id');
    foreach ($verified as $item) {
        $inventoryStmt->execute([':qty' => $item['quantity'], ':product_id' => $item['id']]);
        $stockStmt->execute([':qty' => $item['quantity'], ':product_id' => $item['id']]);
    }

    $conn->commit();

    if ($paymentMethod === 'Cash on Delivery') {
        json_response([
            'order_id'       => $orderId,
            'status'         => 'Order Placed',
            'payment_method' => 'Cash on Delivery',
            'checkout_url'   => $basePath . '/pages/order-status.php?id=' . $orderId,
        ]);
    }

    // GCash QR: send the customer to the page that shows the QR image.
    json_response([
        'order_id'       => $orderId,
        'status'         => 'Awaiting Payment',
        'payment_method' => 'GCash QR',
        'checkout_url'   => $basePath . '/pages/gcash-payment.php?id=' . $orderId,
    ]);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    json_response(['error' => $exception->getMessage()], 400);
}
