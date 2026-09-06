<?php
require_once __DIR__ . '/config/bootstrap.php';
$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId && isset($_GET['format']) && $_GET['format'] === 'json') {
    $stmt = $conn->prepare('SELECT o.id, o.user_id, o.total, o.status, o.payment_method, o.order_date FROM orders o WHERE o.id = ?');
    $stmt->bind_param('i', $orderId); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc();
    if (!$order || ($order['user_id'] && $order['user_id'] !== current_user_id())) json_response(['error' => 'Order not found.'], 404);

    $itemStmt = $conn->prepare('SELECT oi.product_id, oi.quantity, oi.price, p.name AS product_name FROM order_items oi LEFT JOIN Products p ON p.id = oi.product_id WHERE oi.order_id = ?');
    $itemStmt->bind_param('i', $orderId); $itemStmt->execute();
    $order['items'] = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    json_response($order);
}

$order = null;
if ($orderId) {
    $stmt = $conn->prepare('SELECT o.id, o.user_id, o.total, o.status, o.payment_method, o.order_date FROM orders o WHERE o.id = ?');
    $stmt->bind_param('i', $orderId); $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if ($order && $order['user_id'] && $order['user_id'] !== current_user_id()) {
        $order = null;
    }

    if ($order) {
        $itemStmt = $conn->prepare('SELECT oi.product_id, oi.quantity, oi.price, p.name AS product_name FROM order_items oi LEFT JOIN Products p ON p.id = oi.product_id WHERE oi.order_id = ?');
        $itemStmt->bind_param('i', $orderId); $itemStmt->execute();
        $order['items'] = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$availableStatuses = ['Order Placed', 'Preparing', 'Out for Delivery', 'Delivered'];
$currentStatus = $order['status'] ?? 'Order Placed';
$currentIndex = array_search($currentStatus, $availableStatuses, true);
if ($currentIndex === false) {
    $currentIndex = 0;
}

$subTotal = (float) ($order['total'] ?? 0);
$deliveryFee = 0.0;
$total = (float) ($order['total'] ?? 0);
$paymentStatus = $order['payment_method'] ?? 'PayMongo';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ONISUBI | Order Status</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="index.php" aria-label="Go to ONISUBI home"><img class="brand-logo-image" src="assets/images/onisubi-logo.svg" alt="ONISUBI logo"><span class="brand-wordmark"><span class="brand-oni">ONI</span><span class="brand-subi">SUBI</span></span></a>
            <div class="nav-actions">
                <button class="cart-pill">Cart <span id="cart-count">0</span></button>
            </div>
        </div>
    </header>

    <main class="status-page">
        <div class="container">
            <?php if (!$order): ?>
                <div class="status-card" data-order-status>
                    <p>No order selected or order not found.</p>
                </div>
            <?php else: ?>
                <div class="status-card" data-order-status>
                    <div class="status-header">
                        <h2>Order Confirmed!</h2>
                        <p>Thank you for your order. Your meal is on the way.</p>
                    </div>

                    <div class="status-flow" aria-label="Order status timeline">
                        <?php foreach ($availableStatuses as $index => $stage): ?>
                            <div class="status-flow-step <?= $index <= $currentIndex ? 'active' : '' ?>">
                                <strong><?= $stage ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="receipt-card">
                        <div class="receipt-header">
                            <h2>Receipt</h2>
                            <span class="order-history-status">Order ID #<?= (int) $order['id'] ?></span>
                        </div>

                        <div class="receipt-grid">
                            <div class="receipt-detail-box">
                                <span>Order date</span>
                                <p><?= date('F d, Y h:i A', strtotime((string) $order['order_date'])) ?></p>
                            </div>
                            <div class="receipt-detail-box">
                                <span>Status</span>
                                <p><?= e($order['status']) ?></p>
                            </div>
                            <div class="receipt-detail-box">
                                <span>Payment method</span>
                                <p><?= e($paymentStatus) ?></p>
                            </div>
                            <div class="receipt-detail-box">
                                <span>Delivery</span>
                                <p>Delivery</p>
                            </div>
                        </div>

                        <div class="receipt-detail-box">
                            <span>Order details</span>
                            <ul class="receipt-items">
                                <?php foreach ($order['items'] as $item): ?>
                                    <li>
                                        <span><?= e($item['product_name'] ?? 'Item') ?> × <?= (int) $item['quantity'] ?></span>
                                        <strong>₱<?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?></strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="receipt-summary">
                            <div class="receipt-summary-row">
                                <span>Subtotal</span>
                                <strong>₱<?= number_format($subTotal, 2) ?></strong>
                            </div>
                            <div class="receipt-summary-row">
                                <span>Delivery Fee</span>
                                <strong>₱<?= number_format($deliveryFee, 2) ?></strong>
                            </div>
                            <div class="receipt-summary-row total">
                                <span>Total</span>
                                <strong>₱<?= number_format((float) $order['total'], 2) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/script.js"></script>
</body>
</html>
