<?php
require_once __DIR__ . '/../config/bootstrap.php';

$userId = require_login('pages/checkout.php', 'checkout_required');

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
                <a href="cart.php">Cart</a>
                <a href="../index.php#menu">Menu</a>
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
                                <input type="radio" name="payment_method" value="GCash QR" checked>
                                <span class="payment-card-content">
                                    <span class="payment-icon" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-phone-fill" viewBox="0 0 16 16">
                                            <path d="M3 2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zm6 11a1 1 0 1 0-2 0 1 1 0 0 0 2 0"/>
                                        </svg>
                                    </span>
                                    <span class="payment-copy">
                                        <span class="payment-title">Paymongo</span>
                                        <span class="payment-description">Scan our GCash QR code, pay the exact amount, then upload your receipt</span>
                                    </span>
                                    <span class="payment-indicator" aria-hidden="true"></span>
                                </span>
                            </label>

                            <label class="payment-option" data-payment-option>
                                <input type="radio" name="payment_method" value="Cash on Delivery">
                                <span class="payment-card-content">
                                    <span class="payment-icon" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-cash-coin" viewBox="0 0 16 16">
                                            <path fill-rule="evenodd" d="M11 15a4 4 0 1 0 0-8 4 4 0 0 0 0 8m5-4a5 5 0 1 1-10 0 5 5 0 0 1 10 0"/>
                                            <path d="M9.438 11.944c.047.596.518 1.06 1.363 1.116v.44h.375v-.443c.875-.061 1.386-.529 1.386-1.207 0-.618-.39-.936-1.09-1.1l-.296-.07v-1.2c.376.043.614.248.671.532h.658c-.047-.575-.54-1.024-1.329-1.073V8.5h-.375v.45c-.747.073-1.255.522-1.255 1.158 0 .562.378.92 1.007 1.066l.248.061v1.272c-.384-.058-.639-.27-.696-.563h-.668zm1.36-1.354c-.369-.085-.569-.26-.569-.522 0-.294.216-.514.572-.578v1.1zm.432.746c.449.104.655.272.655.569 0 .339-.257.571-.709.614v-1.195z"/>
                                            <path d="M1 0a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h4.083q.088-.517.258-1H3a2 2 0 0 0-2-2V3a2 2 0 0 0 2-2h10a2 2 0 0 0 2 2v3.528c.38.34.717.728 1 1.154V1a1 1 0 0 0-1-1z"/>
                                            <path d="M9.998 5.083 10 5a2 2 0 1 0-3.132 1.65 6 6 0 0 1 3.13-1.567"/>
                                        </svg>
                                    </span>
                                    <span class="payment-copy">
                                        <span class="payment-title">Cash on Delivery</span>
                                        <span class="payment-description">Pay in cash when your order is delivered</span>
                                    </span>
                                    <span class="payment-indicator" aria-hidden="true"></span>
                                </span>
                            </label>
                        </div>

                        <div class="payment-details" aria-live="polite">
                            <div class="payment-instructions is-visible" data-payment-panel="GCash QR">
                                <p>After you place your order, you'll see our GCash QR code. Pay the exact total, then upload your receipt screenshot and reference number so we can verify your payment.</p>
                            </div>

                            <div class="payment-instructions" data-payment-panel="Cash on Delivery" hidden>
                                <p>You will pay in cash when your order is delivered. No online payment needed.</p>
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

    <script src="../assets/js/script.js"></script>
</body>
</html>
