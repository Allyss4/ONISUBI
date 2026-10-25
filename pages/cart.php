<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$isLoggedIn = current_user_id() !== null;
$cartItems = json_decode($_COOKIE['onisubi_cart'] ?? '[]', true);
$cartItems = is_array($cartItems) ? $cartItems : [];

// Fetch current available stock from products & inventory
$productStocks = [];
if (isset($conn) && $conn instanceof PDO) {
  try {
    $stmt = $conn->query("
      SELECT p.id, p.name,
             COALESCE(i.quantity, p.stock, 0)::int AS stock,
             COALESCE(i.low_stock_limit, 5)::int AS low_stock_limit,
             p.status
      FROM products p
      LEFT JOIN inventory i ON i.product_id = p.id
      WHERE LOWER(p.status) <> 'deleted'
      ORDER BY p.id ASC
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $pid = (int) $row['id'];
      $productStocks[$pid] = [
        'id'              => $pid,
        'name'            => (string) $row['name'],
        'stock'           => (int) $row['stock'],
        'low_stock_limit' => (int) $row['low_stock_limit'],
        'status'          => (string) ($row['status'] ?? 'active'),
      ];
    }
  } catch (Throwable $e) {
    // fallback empty
  }
}

function resolve_item_stock(array $item, array $stocks): int {
  $pid = (int) ($item['id'] ?? 0);
  if ($pid > 0 && isset($stocks[$pid])) {
    return (int) $stocks[$pid]['stock'];
  }
  $itemName = strtolower(trim((string) ($item['name'] ?? '')));
  foreach ($stocks as $stockData) {
    $prodName = strtolower(trim((string) $stockData['name']));
    if ($prodName !== '' && (str_contains($itemName, $prodName) || str_contains($prodName, $itemName))) {
      return (int) $stockData['stock'];
    }
  }
  return 99;
}

$hasAdjustedStock = false;
$total = 0.0;
foreach ($cartItems as &$item) {
  $maxStock = resolve_item_stock($item, $productStocks);
  $qty = (int) ($item['qty'] ?? 1);
  if ($qty > $maxStock && $maxStock >= 0) {
    $item['qty'] = max(0, $maxStock);
    $hasAdjustedStock = true;
  }
  $total += (float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 1);
}
unset($item);

if ($hasAdjustedStock) {
  setcookie('onisubi_cart', json_encode($cartItems), time() + 86400, '/');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ONISUBI | Cart</title>
  <link rel="icon" type="image/png" href="../assets/images/Onisubi_Logo.png">
  <link rel="apple-touch-icon" href="../assets/images/Onisubi_Logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
  <header class="site-header">
    <div class="container nav-wrap">
      <a class="brand" href="../index.php" aria-label="Go to ONISUBI home">
        <img class="brand-logo-image" src="../assets/images/onisubi-logo.svg" alt="ONISUBI logo">
        <span class="brand-wordmark"><span class="brand-oni">ONI</span><span class="brand-subi">SUBI</span></span>
      </a>
      <nav class="main-nav" id="main-nav" aria-label="Main navigation">
        <a href="../index.php">Home</a>
        <a href="../index.php#menu">Menu</a>
        <a href="../index.php#about">About</a>
        <?php if ($isLoggedIn): ?>
          <a href="account.php" class="mobile-only-link">My Account</a>
        <?php else: ?>
          <a href="auth.php?redirect=cart.php" class="mobile-only-link">Log In</a>
        <?php endif; ?>
      </nav>
      <div class="nav-actions">
        <button class="cart-pill">
          Cart <span id="cart-count">0</span>
        </button>
        <?php if ($isLoggedIn): ?>
          <a class="account-link" href="account.php">My Account</a>
        <?php else: ?>
          <a class="account-link" href="auth.php?redirect=cart.php">Log In</a>
        <?php endif; ?>
        <button class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation" aria-expanded="false" aria-controls="main-nav">
          <span class="hamburger-bar"></span>
          <span class="hamburger-bar"></span>
          <span class="hamburger-bar"></span>
        </button>
      </div>
    </div>
    <div class="nav-overlay" id="nav-overlay"></div>
  </header>

  <main class="cart-page">
    <div class="container cart-layout">
      <section class="panel">
        <h2>Your Cart</h2>
        <table class="cart-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Qty</th>
              <th>Price</th>
              <th>Item Subtotal</th>
            </tr>
          </thead>
          <tbody data-cart-items>
            <?php if (empty($cartItems)): ?>
              <tr>
                <td colspan="4" class="empty-cart">Your cart is empty.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($cartItems as $index => $item):
                $itemQty = (int) ($item['qty'] ?? 1);
                $maxStock = resolve_item_stock($item, $productStocks);
                $isOutOfStock = $maxStock <= 0;
                $isAtMax = $itemQty >= $maxStock;
              ?>
                <tr data-cart-row data-cart-index="<?= (int) $index ?>" data-product-id="<?= (int) ($item['id'] ?? 0) ?>" data-max-stock="<?= $maxStock ?>">
                  <td class="cart-product">
                    <img src="<?= e(!empty($item['image']) ? (string) $item['image'] : '../assets/images/onisubi-logo.svg') ?>" alt="<?= e((string) ($item['name'] ?? 'Item')) ?>">
                    <span><?= e((string) ($item['name'] ?? 'Item')) ?></span>
                  </td>
                  <td>
                    <div class="cart-qty-wrap">
                      <div class="quantity-control <?= $isAtMax ? 'at-max-limit' : '' ?>">
                        <button type="button" data-cart-action="decrease" data-cart-index="<?= (int) $index ?>"
                          aria-label="Decrease quantity">−</button>
                        <input type="number" inputmode="numeric" min="0" max="<?= $maxStock ?>" step="1" value="<?= $itemQty ?>" data-cart-qty-input data-cart-index="<?= (int) $index ?>" aria-label="Set quantity for <?= e((string) ($item['name'] ?? 'Item')) ?>">
                        <button type="button" data-cart-action="increase" data-cart-index="<?= (int) $index ?>"
                          <?= $isAtMax ? 'disabled' : '' ?>
                          aria-label="Increase quantity">+</button>
                      </div>
                      <?php if ($isOutOfStock): ?>
                        <span class="cart-stock-notice out-of-stock" data-cart-stock-notice>Out of stock (0 left)</span>
                      <?php elseif ($isAtMax): ?>
                        <span class="cart-stock-notice max-reached" data-cart-stock-notice>Max stock reached (<?= $maxStock ?> left)</span>
                      <?php elseif ($maxStock <= 5): ?>
                        <span class="cart-stock-notice low-stock" data-cart-stock-notice>Only <?= $maxStock ?> left in stock</span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>₱<?= number_format((float) ($item['price'] ?? 0), 2) ?></td>
                  <td>₱<?= number_format((float) ($item['price'] ?? 0) * $itemQty, 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
                <div class="cart-actions">
                  <a class="secondary-btn" href="../index.php#menu">Continue Shopping</a>
                  <a class="btn btn-primary checkout-action" href="checkout.php" data-checkout-link>Checkout</a>
                </div>
                </section>

      <aside class="summary-box panel">
        <h3>Order Summary</h3>
        <div class="summary-row">
          <span>Subtotal</span>
          <span data-cart-total>₱<?= number_format($total, 2) ?></span>
        </div>
        <div class="summary-row">
          <span>Delivery</span>
          <span>₱0.00</span>
        </div>
        <div class="summary-row total">
          <span>Total</span>
          <span>₱<?= number_format($total, 2) ?></span>
        </div>
      </aside>
    </div>
  </main>

  <div id="cart-processing-popup" class="cart-popup" aria-live="polite">
    <strong>Order is now processing</strong>
    <span>Your ONISUBI order is being prepared.</span>
  </div>

  <div id="order-confirm-modal" class="modal-backdrop" hidden>
    <section class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
      <button class="modal-close" type="button" data-modal-close aria-label="Close">&times;</button>
      <span class="eyebrow">Ready to order?</span>
      <h2 id="confirm-title">Review your ONISUBI cart</h2>
      <div class="confirm-items" data-confirm-items></div>
      <div class="confirm-total"><span>Total</span><strong data-confirm-total>₱0.00</strong></div>
      <div class="modal-actions">
        <button class="secondary-btn" type="button" data-modal-close>Keep shopping</button>
        <?php if ($isLoggedIn): ?>
          <a class="btn btn-primary" href="checkout.php">Continue to checkout</a>
        <?php else: ?>
          <a class="btn btn-primary" href="auth.php?redirect=checkout.php&msg=checkout_required">Continue to checkout</a>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <script>
    window.PRODUCT_STOCKS = <?= json_encode($productStocks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
  <script src="../assets/js/script.js?v=20261007"></script>
</body>

</html>
