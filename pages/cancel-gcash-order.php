<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$userId = current_user_id();
if (!$userId) {
    header('Location: login.php');
    exit;
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$cart = [];

try {
    $conn->beginTransaction();

    // Lock the order so a double-click can't restock twice.
    $stmt = $conn->prepare('
        SELECT id, status, payment_method, payment_proof
        FROM orders
        WHERE id = :id AND user_id = :uid
        FOR UPDATE
    ');
    $stmt->execute([':id' => $orderId, ':uid' => $userId]);
    $order = $stmt->fetch();

    if (!$order) {
        $conn->rollBack();
        http_response_code(404);
        exit('Order not found.');
    }

    // Only unpaid GCash orders can be cancelled by the customer.
    // (Once proof is submitted it's with the admin for verification.)
    if (
        $order['payment_method'] !== 'GCash QR'
        || !in_array($order['status'], ['Awaiting Payment', 'Payment Rejected'], true)
    ) {
        $conn->rollBack();
        header('Location: order-status.php?id=' . $orderId);
        exit;
    }

    $conn->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = :id")
         ->execute([':id' => $orderId]);

    // Return the reserved stock, and collect the items so the cart can be restored.
    $items = $conn->prepare('
        SELECT oi.product_id, oi.quantity, oi.price, p.name, p.image
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = :id
        ORDER BY oi.id
    ');
    $items->execute([':id' => $orderId]);

    $inventoryStmt = $conn->prepare('UPDATE inventory SET quantity = quantity + :qty WHERE product_id = :product_id');
    $stockStmt     = $conn->prepare('UPDATE products SET stock = stock + :qty WHERE id = :product_id');
    foreach ($items->fetchAll() as $row) {
        $inventoryStmt->execute([':qty' => $row['quantity'], ':product_id' => $row['product_id']]);
        $stockStmt->execute([':qty' => $row['quantity'], ':product_id' => $row['product_id']]);

        $cart[] = [
            'id'    => (int) $row['product_id'],
            'name'  => (string) ($row['name'] ?? 'Item'),
            'price' => (float) $row['price'],
            'qty'   => (int) $row['quantity'],
            'image' => (string) ($row['image'] ?? ''),
        ];
    }

    $conn->commit();

    // Remove any rejected proof image that was left on disk.
    if (!empty($order['payment_proof'])) {
        $file = __DIR__ . '/../uploads/proofs/' . basename((string) $order['payment_proof']);
        if (is_file($file)) {
            @unlink($file);
        }
    }
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    header('Location: gcash-payment.php?id=' . $orderId . '&error=' . urlencode('Could not cancel the order. Please try again.'));
    exit;
}

// Put the items back in the cart so checkout isn't empty.
// Same cookie name/path that script.js reads (it falls back to this when localStorage is empty).
setcookie('onisubi_cart', json_encode($cart), [
    'expires'  => time() + 86400,
    'path'     => '/',
    'samesite' => 'Lax',
]);

header('Location: checkout.php?switched=1');
exit;
