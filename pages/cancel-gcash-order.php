<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$userId = current_user_id();
if (!$userId) {
    header('Location: auth.php');
    exit;
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$cart = [];

try {
    // Check order status before cancelling
    $stmt = $conn->prepare('
        SELECT id, status, payment_method, payment_proof
        FROM orders
        WHERE id = :id AND user_id = :uid
    ');
    $stmt->execute([':id' => $orderId, ':uid' => $userId]);
    $order = $stmt->fetch();

    if (!$order) {
        http_response_code(404);
        exit('Order not found.');
    }

    if (
        $order['payment_method'] !== 'GCash QR'
        || !in_array($order['status'], ['Awaiting Payment', 'Payment Rejected'], true)
    ) {
        header('Location: order-status.php?id=' . $orderId);
        exit;
    }

    // Call atomic Supabase RPC: cancel_order
    $res = supabase_call_rpc($conn, 'cancel_order', [
        'order_id' => $orderId,
        'user_id'  => $userId,
    ]);

    if (!is_array($res) || empty($res['success'])) {
        throw new RuntimeException('Failed to cancel order.');
    }

    $cart = $res['restored_items'] ?? [];

    // Remove any rejected proof image that was left on disk
    if (!empty($order['payment_proof'])) {
        $file = __DIR__ . '/../uploads/proofs/' . basename((string) $order['payment_proof']);
        if (is_file($file)) {
            @unlink($file);
        }
    }
} catch (Throwable $e) {
    header('Location: gcash-payment.php?id=' . $orderId . '&error=' . urlencode('Could not cancel the order: ' . $e->getMessage()));
    exit;
}

// Put the items back in the cart
setcookie('onisubi_cart', json_encode($cart), [
    'expires'  => time() + 86400,
    'path'     => '/',
    'samesite' => 'Lax',
]);

header('Location: checkout.php?switched=1');
exit;
