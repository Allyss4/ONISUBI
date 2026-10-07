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
$currentTracking = $order ? map_order_status_to_tracking($order['status'] ?? '') : null;
$currentIndex = $currentTracking ? $currentTracking['index'] : 0;

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
                        <h2>Order Confirmed</h2>
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
                                <p class="receipt-ref-line" style="display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap;">
                                    <?php $activeRef = (string) ($order['payment_reference'] ?: $order['paymongo_checkout_id'] ?: ''); ?>
                                    <?php if ($activeRef !== ''): ?>
                                        <strong style="font-family: monospace; font-size: 0.82rem; color: var(--subi); word-break: break-all;"><?= e($activeRef) ?></strong>
                                        <button type="button" class="btn-copy-ref" onclick="navigator.clipboard.writeText('<?= e(addslashes($activeRef)) ?>'); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy', 1500);" style="background: rgba(240, 146, 26, 0.15); border: 1px solid rgba(240, 146, 26, 0.3); color: var(--subi); padding: 2px 6px; border-radius: 5px; font-size: 0.68rem; font-weight: 700; cursor: pointer; flex-shrink: 0;">Copy</button>
                                    <?php else: ?>
                                        <span style="color: var(--muted); font-size: 0.8rem; font-style: italic;">Not provided</span>
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

    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
    <?= supabase_frontend_config_tag() ?>
    <script src="../assets/js/script.js"></script>
    <?php if ($order): ?>
    <script>
      (function() {
        const ORDER_ID = <?= (int) $order['id'] ?>;
        async function checkOrderStatus() {
          try {
            const res = await fetch(`order-status.php?id=${ORDER_ID}&format=json&_t=${Date.now()}`);
            if (!res.ok) return;
            const data = await res.json();
            if (!data || !data.status) return;

            // Map status
            const raw = (data.status || '').toLowerCase();
            let idx = 0;
            if (['delivered', 'completed'].includes(raw)) idx = 3;
            else if (['out for delivery', 'out_for_delivery', 'in transit'].includes(raw)) idx = 2;
            else if (['preparing', 'ready', 'processing'].includes(raw)) idx = 1;

            const steps = document.querySelectorAll('.status-flow-step');
            steps.forEach((step, i) => {
              if (i <= idx) step.classList.add('active');
              else step.classList.remove('active');
            });

            // Update status text in receipt
            const statusP = document.querySelectorAll('.receipt-detail-box p');
            if (statusP && statusP[1]) {
              statusP[1].textContent = data.status;
            }
          } catch(e) {
            console.warn(e);
          }
        }

        if (window.supabase && typeof window.supabase.createClient === 'function' && window.SUPABASE_URL && window.SUPABASE_ANON_KEY) {
          try {
            const sb = window.supabase.createClient(window.SUPABASE_URL, window.SUPABASE_ANON_KEY);
            sb.channel('order-live-' + ORDER_ID)
              .on('postgres_changes', { event: '*', schema: 'public', table: 'orders', filter: `id=eq.${ORDER_ID}` }, () => {
                checkOrderStatus();
              })
              .subscribe();
          } catch(e) {}
        }

        <?php if ($currentIndex < 3): ?>
          setInterval(checkOrderStatus, 5000);
        <?php endif; ?>
      })();
    </script>
    <?php endif; ?>
</body>
</html>
