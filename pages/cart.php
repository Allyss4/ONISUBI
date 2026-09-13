<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$isLoggedIn = current_user_id() !== null;
$cartItems = json_decode($_COOKIE['onisubi_cart'] ?? '[]', true);
$cartItems = is_array($cartItems) ? $cartItems : [];

$total = 0.0;
foreach ($cartItems as $item) {
  $total += (float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 1);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ONISUBI | Cart</title>
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
          <a href="#" class="mobile-only-link" onclick="document.getElementById('login-prompt-modal').hidden = false; return false;">Log In</a>
        <?php endif; ?>
      </nav>
      <div class="nav-actions">
        <button class="cart-pill">
          Cart <span id="cart-count">0</span>
        </button>
        <?php if ($isLoggedIn): ?>
          <a class="account-link" href="account.php">My Account</a>
        <?php else: ?>
          <button type="button" class="account-link" onclick="document.getElementById('login-prompt-modal').hidden = false;"
            style="background:none; border:none; cursor:pointer; font-size:inherit; font-family:inherit;">Log In</button>
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
              <?php foreach ($cartItems as $index => $item): ?>
                <tr>
                  <td class="cart-product">
                    <img src="<?= e(!empty($item['image']) ? (string) $item['image'] : '../assets/images/onisubi-logo.svg') ?>" alt="<?= e((string) ($item['name'] ?? 'Item')) ?>">
                    <span><?= e((string) ($item['name'] ?? 'Item')) ?></span>
                  </td>
                  <td>
                    <div class="quantity-control">
                      <button type="button" data-cart-action="decrease" data-cart-index="<?= (int) $index ?>"
                        aria-label="Decrease quantity">−</button>
                      <input type="number" inputmode="numeric" min="0" step="1" value="<?= (int) ($item['qty'] ?? 1) ?>" data-cart-qty-input data-cart-index="<?= (int) $index ?>" aria-label="Set quantity for <?= e((string) ($item['name'] ?? 'Item')) ?>">
                      <button type="button" data-cart-action="increase" data-cart-index="<?= (int) $index ?>"
                        aria-label="Increase quantity">+</button>
                    </div>
                  </td>
                  <td>₱<?= number_format((float) ($item['price'] ?? 0), 2) ?></td>
                  <td>₱<?= number_format((float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 1), 2) ?></td>
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
          <button type="button" class="btn btn-primary"
            onclick="document.getElementById('order-confirm-modal').hidden = true; document.getElementById('login-prompt-modal').hidden = false;">
            Continue to checkout
          </button>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div id="login-prompt-modal" class="modal-backdrop" hidden>
    <section class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="login-prompt-title" style="max-height: 90vh; overflow-y: auto;">
      <button class="modal-close" type="button"
        onclick="document.getElementById('login-prompt-modal').hidden = true; document.getElementById('order-confirm-modal').hidden = false;"
        aria-label="Close">&times;</button>
      <span class="eyebrow">Account required</span>
      <h2 id="login-prompt-title">Enter your email to continue</h2>

      <form class="checkout-form magic-auth-form" method="post" action="auth.php" style="margin-top: 16px;">
        <!-- Step 1 -->
        <div class="auth-step-1">
          <input type="email" name="email_check" class="auth-email-input" placeholder="Email address" required style="margin-bottom: 16px;">
          <button type="button" class="btn btn-primary auth-continue-btn" style="width: 100%;">Continue</button>
        </div>

        <!-- Step 2 (Login) -->
        <div class="auth-step-login" hidden>
          <input type="hidden" name="action" value="login" disabled>
          <input type="hidden" name="redirect" value="checkout.php">
          <input type="hidden" name="email" class="auth-email-hidden" disabled>

          <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
            <strong class="auth-email-display"></strong>
            <button type="button" class="text-link auth-back-btn" style="background:none;border:none;cursor:pointer;font-size:0.9rem;">Change</button>
          </div>

          <input type="password" name="password" placeholder="Password" required disabled>
          <button class="btn btn-primary" type="submit" style="width: 100%;">Log in</button>
          <p style="margin-top: 16px; text-align: center;">
            <a class="text-link" href="reset-password.php">Forgot or reset password?</a>
          </p>
        </div>

        <!-- Step 2 (Register) -->
        <div class="auth-step-register" hidden>
          <input type="hidden" name="action" value="register" disabled>
          <input type="hidden" name="redirect" value="checkout.php">
          <input type="hidden" name="email" class="auth-email-hidden" disabled>

          <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
            <strong class="auth-email-display"></strong>
            <button type="button" class="text-link auth-back-btn" style="background:none;border:none;cursor:pointer;font-size:0.9rem;">Change</button>
          </div>

          <input type="text" name="name" placeholder="Full name" required disabled>
          <input type="tel" name="phone" placeholder="Phone number" required disabled>
          <textarea name="address" rows="2" placeholder="Delivery address" required disabled></textarea>
          <input type="password" name="password" minlength="8" placeholder="Password (8+ characters)" required disabled>
          <button class="btn btn-primary" type="submit" style="width: 100%;">Create account</button>
        </div>
      </form>
    </section>
  </div>

  <script src="../assets/js/script.js?v=20260901"></script>
</body>

</html>
