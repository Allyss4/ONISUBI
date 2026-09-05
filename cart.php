<?php
$cartItems = json_decode($_COOKIE['onisubi_cart'] ?? '[]', true);
$cartItems = is_array($cartItems) ? $cartItems : [];

$total = 0;
foreach ($cartItems as $item) {
    $total += (float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
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
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="index.php" aria-label="Go to ONISUBI home"><img class="brand-logo-image" src="assets/images/onisubi-logo.svg" alt="ONISUBI logo"><span class="brand-wordmark"><span class="brand-oni">ONI</span><span class="brand-subi">SUBI</span></span></a>
            <nav class="main-nav" aria-label="Main navigation">
                <a href="index.php">Home</a>
                <a href="index.php#menu">Menu</a>
                <a href="index.php#about">About</a>
            </nav>
            <div class="nav-actions">
                <button class="cart-pill">Cart <span id="cart-count">0</span></button>
            </div>
        </div>
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
                                    <td><?= htmlspecialchars($item['name'] ?? 'Item') ?></td>
                                    <td><div class="quantity-control"><button type="button" data-cart-action="decrease" data-cart-index="<?= (int) $index ?>" aria-label="Decrease quantity">-</button><strong><?= (int)($item['qty'] ?? 1) ?></strong><button type="button" data-cart-action="increase" data-cart-index="<?= (int) $index ?>" aria-label="Increase quantity">+</button></div></td>
                                    <td>₱<?= number_format((float)($item['price'] ?? 0), 2) ?></td>
                                    <td>₱<?= number_format((float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="cart-actions">
                    <a class="secondary-btn" href="index.php#menu">Continue Shopping</a>
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
            <div class="modal-actions"><button class="secondary-btn" type="button" data-modal-close>Keep shopping</button><a class="btn btn-primary" href="checkout.php">Continue to checkout</a></div>
        </section>
    </div>

    <script src="assets/js/script.js?v=20260901"></script>
</body>
</html>
