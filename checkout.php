<?php
require_once __DIR__ . '/config/bootstrap.php';

$userId = require_login('checkout.php', 'checkout_required');

$stmt = $conn->prepare('SELECT name, email, phone, address FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch() ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ONISUBI | Checkout</title>
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
            <nav class="main-nav" aria-label="Main navigation">
                <a href="cart.php">Cart</a>
                <a href="index.php#menu">Menu</a>
            </nav>
            <div class="nav-actions">
                <button class="cart-pill">Cart <span id="cart-count">0</span></button>
            </div>
        </div>
    </header>

    <main class="checkout-page">
        <div class="container checkout-layout">
            <section class="panel">
                <h2>Checkout</h2>
                <form class="checkout-form" data-checkout-form>
                    <input type="text" name="name" placeholder="Full name" value="<?= e($user['name'] ?? '') ?>" required>
                    <input type="tel" name="phone" placeholder="Phone number" value="<?= e($user['phone'] ?? '') ?>" required>
                    <textarea name="address" rows="4" placeholder="Delivery address" required><?= e($user['address'] ?? '') ?></textarea>
                    <input type="text" name="notes" placeholder="Order notes (optional)">

                    <div class="payment-method-section" aria-label="Payment method selection section">
                        <h3>Payment method</h3>
                        <div class="payment-options" role="radiogroup" aria-label="Payment method">
                            <label class="payment-option selected" data-payment-option>
                                <input type="radio" name="payment_method" value="PayMongo" checked>
                                <span class="payment-card-content">
                                    <span class="payment-icon" aria-hidden="true">💳</span>
                                    <span class="payment-copy">
                                        <span class="payment-title">PayMongo (E-Wallets, Cards, Banks)</span>
                                        <span class="payment-description">Pay securely online using GCash, Maya, GrabPay, QR Ph, Cards, or Online Banking</span>
                                    </span>
                                    <span class="payment-indicator" aria-hidden="true"></span>
                                </span>
                            </label>

                            <label class="payment-option" data-payment-option>
                                <input type="radio" name="payment_method" value="Cash on Delivery">
                                <span class="payment-card-content">
                                    <span class="payment-icon" aria-hidden="true">💵</span>
                                    <span class="payment-copy">
                                        <span class="payment-title">Cash on Delivery</span>
                                        <span class="payment-description">Pay when your order is delivered</span>
                                    </span>
                                    <span class="payment-indicator" aria-hidden="true"></span>
                                </span>
                            </label>
                        </div>

                        <div class="payment-details" aria-live="polite">
                            <div class="payment-instructions is-visible" data-payment-panel="PayMongo">
                                <p>PayMongo (E-Wallets, Cards, Banks)</p>
                            </div>

                            <div class="payment-instructions" data-payment-panel="Cash on Delivery" hidden>
                                <p>You will pay in cash when your order is delivered.</p>
                            </div>
                        </div>
                    </div>

                    <div class="checkout-actions">
                        <button type="submit" class="btn btn-primary">Place Order</button>
                        <a href="cart.php" class="secondary-btn">Back to Cart</a>
                    </div>
                </form>
            </section>

            <aside class="summary-box panel">
                <h3>Order Summary</h3>
                <div class="summary-row">
                    <span>Items</span>
                    <span id="checkout-items">0</span>
                </div>
                <div class="summary-row">
                    <span>Delivery</span>
                    <span>₱0.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total</span>
                    <span id="checkout-total">₱0.00</span>
                </div>
            </aside>
        </div>
    </main>

    <div id="cart-processing-popup" class="cart-popup" aria-live="polite">
        <strong>Order is now processing</strong>
        <span>Your ONISUBI order is being prepared.</span>
    </div>

    <script src="assets/js/script.js"></script>
</body>
</html>