<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$orderId = (int) ($_GET['id'] ?? 0);
$userId = current_user_id();
$isAdmin = is_admin();

$flashNotice = '';
$flashError = '';

// Handle manual payment reference submission (e.g., customer entering PayMongo reference number)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_payment_reference') {
    $postRef = trim((string) ($_POST['payment_reference'] ?? ''));
    if ($orderId > 0 && $postRef !== '') {
        $chkStmt = $conn->prepare('SELECT id, user_id, payment_reference FROM orders WHERE id = ?');
        $chkStmt->execute([$orderId]);
        $existingOrder = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingOrder && (!$existingOrder['user_id'] || (int) $existingOrder['user_id'] === $userId || $isAdmin)) {
            $upStmt = $conn->prepare("
                UPDATE orders
                SET payment_reference = :pref,
                    payment_status = CASE WHEN payment_status = 'pending' THEN 'paid' ELSE payment_status END
                WHERE id = :id
            ");
            $upStmt->execute([':pref' => $postRef, ':id' => $orderId]);
            $flashNotice = 'PayMongo reference saved successfully! Administrators can now track and verify your transaction.';
        } else {
            $flashError = 'Unable to update payment reference for this order.';
        }
    }
}

// JSON API endpoint for order status
if ($orderId && isset($_GET['format']) && $_GET['format'] === 'json') {
    $stmt = $conn->prepare('
        SELECT o.id, o.user_id, o.customer_name, o.phone, o.address, o.total, o.status, 
               o.payment_method, o.payment_status, o.payment_reference, o.paymongo_checkout_id, 
               o.order_date 
        FROM orders o 
        WHERE o.id = ?
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || ($order['user_id'] && (int) $order['user_id'] !== $userId && !$isAdmin)) {
        json_response(['error' => 'Order not found.'], 404);
    }

    $itemStmt = $conn->prepare('SELECT oi.product_id, oi.quantity, oi.price, p.name AS product_name FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
    $itemStmt->execute([$orderId]);
    $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_response($order);
}

// Web page view
$order = null;
if ($orderId) {
    $stmt = $conn->prepare('
        SELECT o.id, o.user_id, o.customer_name, o.phone, o.address, o.total, o.status, 
               o.payment_method, o.payment_status, o.payment_reference, o.paymongo_checkout_id, 
               o.order_date 
        FROM orders o 
        WHERE o.id = ?
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($order && $order['user_id'] && (int) $order['user_id'] !== $userId && !$isAdmin) {
        $order = null;
    }

    if ($order) {
        // Automatically capture reference or checkout session ID passed from PayMongo redirect
        $incomingRef = trim((string) ($_GET['payment_reference'] ?? $_GET['reference'] ?? $_GET['ref'] ?? $_GET['payment_ref'] ?? ''));
        $incomingCheckoutId = trim((string) ($_GET['checkout_session_id'] ?? $_GET['session_id'] ?? $_GET['paymongo_id'] ?? ''));

        $isPaymentSuccess = (isset($_GET['payment']) && $_GET['payment'] === 'success');

        if ($incomingRef !== '' || $incomingCheckoutId !== '' || $isPaymentSuccess) {
            $needsUpdate = false;
            $newRef = $order['payment_reference'];
            $newCid = $order['paymongo_checkout_id'];

            if ($incomingRef !== '' && empty($order['payment_reference'])) {
                $newRef = $incomingRef;
                $needsUpdate = true;
            }
            if ($incomingCheckoutId !== '' && empty($order['paymongo_checkout_id'])) {
                $newCid = $incomingCheckoutId;
                $needsUpdate = true;
            }
            if ($isPaymentSuccess && ($order['payment_status'] === 'pending' || $order['status'] === 'Awaiting Payment')) {
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $autoStmt = $conn->prepare("
                    UPDATE orders
                    SET payment_reference = COALESCE(NULLIF(:pref, ''), payment_reference),
                        paymongo_checkout_id = COALESCE(NULLIF(:pcid, ''), paymongo_checkout_id),
                        payment_status = CASE WHEN :is_paid = 1 THEN 'paid' ELSE payment_status END,
                        status = CASE WHEN :is_paid = 1 AND status = 'Awaiting Payment' THEN 'Order Placed' ELSE status END
                    WHERE id = :id
                ");
                $autoStmt->execute([
                    ':pref'    => $newRef,
                    ':pcid'    => $newCid,
                    ':is_paid' => $isPaymentSuccess ? 1 : 0,
                    ':id'      => $orderId,
                ]);
                $order['payment_reference'] = $newRef;
                $order['paymongo_checkout_id'] = $newCid;
                if ($isPaymentSuccess) {
                    $order['payment_status'] = 'paid';
                    if ($order['status'] === 'Awaiting Payment') {
                        $order['status'] = 'Order Placed';
                    }
                }
                $flashNotice = $isPaymentSuccess
                    ? 'Payment successful via PayMongo! Your order has been placed and is being prepared.'
                    : 'PayMongo transaction reference recorded! Our admins can now verify your payment.';
            }
        }

        $itemStmt = $conn->prepare('SELECT oi.product_id, oi.quantity, oi.price, p.name AS product_name FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
        $itemStmt->execute([$orderId]);
        $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="../index.php" aria-label="Go to ONISUBI home"><img class="brand-logo-image" src="../assets/images/onisubi-logo.svg" alt="ONISUBI logo"><span class="brand-wordmark"><span class="brand-oni">ONI</span><span class="brand-subi">SUBI</span></span></a>
            <nav class="main-nav" id="main-nav" aria-label="Main navigation">
                <a href="../index.php">Home</a>
                <a href="../index.php#menu">Menu</a>
                <a href="account.php">My Account</a>
            </nav>
            <div class="nav-actions">
                <button class="cart-pill">Cart <span id="cart-count">0</span></button>
                <button class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation" aria-expanded="false" aria-controls="main-nav">
                    <span class="hamburger-bar"></span>
                    <span class="hamburger-bar"></span>
                    <span class="hamburger-bar"></span>
                </button>
            </div>
        </div>
        <div class="nav-overlay" id="nav-overlay"></div>
    </header>

    <main class="status-page">
        <div class="container">
            <?php if ($flashNotice): ?>
                <div style="background: #e4f1e5; color: #38603f; padding: 14px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; border: 1px solid #c2e2c6; text-align: center;">
                    <?= e($flashNotice) ?>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div style="background: #fde8e8; color: #a94442; padding: 14px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; border: 1px solid #f5c6cb; text-align: center;">
                    <?= e($flashError) ?>
                </div>
            <?php endif; ?>

            <?php if (!$order): ?>
                <div class="status-card">
                    <p>No order selected or order not found.</p>
                </div>
            <?php else: ?>
                <div class="status-card">
                    <div class="status-header">
                        <h2>Order Confirmed!</h2>
                        <p>Thank you for your order. Your meal is on the way.</p>
                    </div>

                    <div class="status-flow" aria-label="Order status timeline">
                        <?php foreach ($availableStatuses as $index => $stage): ?>
                            <div class="status-flow-step <?= $index <= $currentIndex ? 'active' : '' ?>">
                                <strong><?= e($stage) ?></strong>
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
                                <p><?= e((string) $order['status']) ?></p>
                            </div>
                            <div class="receipt-detail-box">
                                <span>Payment method</span>
                                <p><?= e((string) $paymentStatus) ?></p>
                            </div>
                            <div class="receipt-detail-box">
                                <span>Delivery</span>
                                <p>Delivery</p>
                            </div>
                            <div class="receipt-detail-box">
                                <span>Payment Reference</span>
                                <p style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                    <?php $activeRef = (string) ($order['payment_reference'] ?: $order['paymongo_checkout_id'] ?: ''); ?>
                                    <?php if ($activeRef !== ''): ?>
                                        <strong style="font-family: monospace; font-size: 0.95rem; color: var(--subi); word-break: break-all;"><?= e($activeRef) ?></strong>
                                        <button type="button" class="btn-copy-ref" onclick="navigator.clipboard.writeText('<?= e(addslashes($activeRef)) ?>'); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy', 1500);" style="background: rgba(240, 146, 26, 0.15); border: 1px solid rgba(240, 146, 26, 0.3); color: var(--subi); padding: 2px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; cursor: pointer;">Copy</button>
                                    <?php else: ?>
                                        <span style="color: var(--muted); font-size: 0.88rem; font-style: italic;">Not provided</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="receipt-detail-box">
                                <span>Payment Status</span>
                                <p>
                                    <?php
                                    $pStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
                                    $isPaid = in_array($pStatus, ['paid', 'completed'], true);
                                    ?>
                                    <span style="display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; <?= $isPaid ? 'background: #e4f1e5; color: #38603f;' : 'background: #fef3c7; color: #92400e;' ?>">
                                        <?= e(ucfirst($pStatus)) ?>
                                    </span>
                                </p>
                            </div>
                        </div>

                        <?php if (empty($order['payment_reference']) && empty($order['paymongo_checkout_id'])): ?>
                            <div class="paymongo-ref-box" style="margin: 20px 0; padding: 18px 20px; background: rgba(240, 146, 26, 0.08); border: 1px dashed rgba(240, 146, 26, 0.4); border-radius: 14px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                                    <strong style="color: var(--subi); font-size: 0.95rem;">Have a PayMongo Reference ID?</strong>
                                    <span style="font-size: 0.72rem; background: var(--subi); color: #fff; padding: 3px 8px; border-radius: 4px; font-weight: 700; text-transform: uppercase;">Admin Reference</span>
                                </div>
                                <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 12px; line-height: 1.4;">
                                    If you paid via PayMongo, please provide your payment reference number below so administrators can verify your payment immediately.
                                </p>
                                <form method="post" action="order-status.php?id=<?= (int)$order['id'] ?>" style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <input type="hidden" name="action" value="save_payment_reference">
                                    <input type="text" name="payment_reference" placeholder="e.g. pm_ref_123456 or GCash Ref #" required style="flex: 1; min-width: 220px; padding: 9px 14px; border: 1px solid var(--line); border-radius: 8px; font-size: 0.9rem; background: #fff;">
                                    <button type="submit" class="btn btn-primary" style="padding: 9px 20px; font-size: 0.85rem; border-radius: 8px; font-weight: 700; cursor: pointer;">Save Reference</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="paymongo-ref-verified" style="margin: 20px 0; padding: 14px 20px; background: #e4f1e5; border: 1px solid #c2e2c6; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                                <div>
                                    <div style="font-size: 0.72rem; text-transform: uppercase; font-weight: 800; color: #38603f; letter-spacing: 0.05em;">Admin Reference Linked</div>
                                    <div style="font-size: 0.92rem; color: #1e3a24; margin-top: 3px;">
                                        PayMongo Reference: <strong style="font-family: monospace; font-size: 1rem; color: #1e3a24;"><?= e((string)($order['payment_reference'] ?: $order['paymongo_checkout_id'])) ?></strong>
                                    </div>
                                </div>
                                <div style="font-size: 0.78rem; color: #38603f; font-weight: 700; background: #fff; padding: 5px 12px; border-radius: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                                    ✓ Admin Verification Ready
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="receipt-detail-box">
                            <span>Order details</span>
                            <ul class="receipt-items">
                                <?php foreach ($order['items'] as $item): ?>
                                    <li>
                                        <span><?= e((string) ($item['product_name'] ?? 'Item')) ?> × <?= (int) $item['quantity'] ?></span>
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

                    <p class="feedback-link-wrap" style="margin-top: 20px; text-align: center;">
                        <a class="text-link" href="../index.php#feedback">Customer feedback</a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="../assets/js/script.js"></script>
</body>
</html>
