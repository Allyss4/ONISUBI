<?php
require_once __DIR__ . '/config/bootstrap.php';

if (!current_user_id()) {
  header('Location: auth.php');
  exit;
}

$userId = current_user_id();

// Fetch user profile
$stmt = $conn->prepare('SELECT name, email, phone, address FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

// Fetch user orders
$ordersStmt = $conn->prepare('SELECT id, total, status, order_date, payment_method FROM orders WHERE user_id = :user_id ORDER BY order_date DESC');
$ordersStmt->execute([':user_id' => $userId]);
$orders = $ordersStmt->fetchAll();

// Fetch items for each order
$orderItemsMap = [];
foreach ($orders as $order) {
  $itemsStmt = $conn->prepare('
        SELECT oi.product_id, oi.quantity, p.name 
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = :order_id 
        ORDER BY oi.id
    ');
  $itemsStmt->execute([':order_id' => $order['id']]);
  $orderItemsMap[$order['id']] = $itemsStmt->fetchAll();
}

$statusFlow = ['Order Placed', 'Preparing', 'Out for Delivery', 'Delivered'];
$currentStatus = 'Order Placed';
if (!empty($orders)) {
  $currentStatus = $orders[0]['status'] ?? 'Order Placed';
  if (!in_array($currentStatus, $statusFlow, true)) {
    $currentStatus = 'Order Placed';
  }
}

$currentIndex = array_search($currentStatus, $statusFlow, true);
if ($currentIndex === false) {
  $currentIndex = 0;
}
?><!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ONISUBI | My Account</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
  <header class="site-header">
    <div class="container nav-wrap">
      <a class="brand" href="index.php" aria-label="Go to ONISUBI home">
        <img class="brand-logo-image" src="assets/images/onisubi-logo.svg" alt="ONISUBI logo">
        <span class="brand-wordmark"><span class="brand-oni">ONI</span><span class="brand-subi">SUBI</span></span>
      </a>
      <div class="nav-actions">
        <button class="cart-pill">Cart <span id="cart-count">0</span></button>
        <?php if ($userId): ?>
          <a class="secondary-btn" href="auth.php?action=logout">Log Out</a>
        <?php else: ?>
          <button type="button" class="account-link" onclick="document.getElementById('login-prompt-modal').hidden = false;"
            style="background:none; border:none; cursor:pointer; font-size:inherit; font-family:inherit;">Log In</button>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="cart-page">
    <div class="container">
      <section class="panel account-summary">
        <div class="account-header">
          <div>
            <span class="eyebrow">My Account</span>
            <h2><?= e($user['name'] ?? 'Customer') ?>'s profile</h2>
          </div>
          <div class="account-actions">
            <a class="btn btn-primary" href="order-status.php">Track Order</a>
            <a class="btn btn-secondary" href="settings.php">Account Settings</a>
          </div>
        </div>

        <div class="account-profile-grid">
          <div class="account-profile-item">
            <label>Full name</label>
            <p><?= e($user['name'] ?? 'Not provided') ?></p>
          </div>
          <div class="account-profile-item">
            <label>Email</label>
            <p><?= e($user['email'] ?? 'Not provided') ?></p>
          </div>
          <div class="account-profile-item">
            <label>Phone</label>
            <p><?= e($user['phone'] ?? 'No phone number saved') ?></p>
          </div>
          <div class="account-profile-item">
            <label>Delivery address</label>
            <p><?= e($user['address'] ?? 'No delivery address saved') ?></p>
          </div>
        </div>
      </section>

      <section class="panel account-order-panel" style="margin-top: 28px;">
        <div class="account-header">
          <div>
            <span class="eyebrow">Order Tracking</span>
            <h2>Current Order Status</h2>
          </div>
        </div>

        <div class="status-flow" aria-label="Order tracking progress">
          <?php foreach ($statusFlow as $index => $stage): ?>
            <div class="status-flow-step <?= $index <= $currentIndex ? 'active' : '' ?>"><?= e($stage) ?></div>
          <?php endforeach; ?>
        </div>

        <div class="account-header">
          <div>
            <span class="eyebrow">Order History</span>
            <h2>Current and Past Orders</h2>
          </div>
        </div>

        <?php if (empty($orders)): ?>
          <p class="empty-cart">You have no orders yet.</p>
        <?php else: ?>
          <div class="order-history-list">
            <?php foreach ($orders as $order):
              $items = $orderItemsMap[$order['id']] ?? [];
              $orderTimestamp = strtotime((string) $order['order_date']);
              $dateTime = new DateTime('@' . $orderTimestamp);
              $dateTime->setTimezone(new DateTimeZone('Asia/Manila'));
              $formattedDate = $dateTime->format('F j, Y');
              $formattedTime = $dateTime->format('g:i A');
              ?>
              <div class="order-history-item">
                <div class="order-history-meta">
                  <strong>Order #<?= (int) $order['id'] ?></strong>
                  <span><?= e($formattedDate) ?> • <?= e($formattedTime) ?></span>
                  <span><?= e($order['status']) ?></span>
                </div>
        
                <div class="order-history-products">
                  <strong>Items:</strong>
                  <div class="product-list">
                    <?php if (!empty($items)): ?>
                      <?php foreach ($items as $item): ?>
                        <div class="product-item">
                          <span><?= e($item['name']) ?> × <?= (int) $item['quantity'] ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <span>No items</span>
                    <?php endif; ?>
                  </div>
                  </div>

                <div class="order-history-meta">
                  <div>
                    <span>Payment Method</span>
                    <strong><?= e($order['payment_method'] ?? 'Cash on Delivery') ?></strong>
                  </div>
                  <div>
                    <span>Total</span>
                    <strong>₱<?= number_format((float) $order['total'], 2) ?></strong>
                  </div>
                  </div>
                  
                  <div>
                    <a class="secondary-btn" href="order-status.php?id=<?= (int) $order['id'] ?>">View receipt</a>
                  </div>
                  </div>
                  <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                  </section>
                  </div>
                  </main>

  <script src="assets/js/script.js"></script>
</body>

</html>