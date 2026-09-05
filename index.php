<?php
require_once __DIR__ . '/config/db.php';

$products = [];

if (isset($conn)) {
    $result = $conn->query("SELECT id, name, price, description, image, stock, status FROM Products ORDER BY id LIMIT 8");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
    }
}

if (empty($products)) {
    $products = [
        ['id' => 1, 'name' => 'Spam & Egg', 'price' => 40, 'description' => 'Main flavor with savory spam and fluffy egg.', 'image' => 'https://balancewithjess.com/wp-content/uploads/2022/02/Spam-Musubi-with-Egg-Feat-500x500.jpg'],
        ['id' => 2, 'name' => 'Sizzling Hotdog', 'price' => 40, 'description' => 'A tasty hotdog-filled bite with rich, smoky flavor.', 'image' => 'https://www.sbfoods-worldwide.com/recipes/rst0f40000000t14-img/416_UMAMI_TOPPING_Onigiri_ichiran.jpg'],
        ['id' => 3, 'name' => 'Tuna Mayo', 'price' => 40, 'description' => 'Creamy, savory tuna filling for a classic bite.', 'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcR5-EjRHu8gJ2nWryFFe9zgbQhBY7L4A1fH9eJmrOwyLZ9l7tGJzfo_QtQ3&s=10'],
        ['id' => 4, 'name' => 'Egg', 'price' => 40, 'description' => 'Simple, comforting, and filling egg musubi favorite.', 'image' => 'https://japanesecooking101.com/wp-content/uploads/2023/10/IMG_6350.jpeg'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
     name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ONISUBI | Rice Snack & Musubi</title>
    <meta name="description" content="ONISUBI brings together Japanese onigiri and Hawaiian musubi in a delicious, budget-friendly snack.">
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
                <a href="#home">Home</a>
                <a href="#about">About</a>
                <a href="#menu">Menu</a>
                <a href="#contact">Contact</a>
            </nav>

            <div class="nav-actions">
                <a class="account-link" href="auth.php">Account</a>
                <button class="cart-pill">
                    Cart <span id="cart-count">0</span>
                </button>
            </div>
        </div>
    </header>

    <main>
        <section id="home" class="hero-section">
            <div class="container hero-grid">
                <div class="hero-text">
                    <span class="tag">Budget-friendly snack</span>
                    <h1>Japanese onigiri + Hawaiian musubi in one delicious bite.</h1>
                    <p>
                        ONISUBI is a food mix that combines Japanese rice balls and Hawaiian musubi, giving you a fast, tasty, and filling meal at only ₱40 each.
                    </p>

                    <div class="hero-actions">
                        <a class="btn btn-primary" href="#menu">Order now</a>
                        <a class="btn btn-secondary" href="#about">Learn more</a>
                    </div>

                    <div class="stats-row">
                        <div>
                            <strong>₱40</strong>
                            <span>Each piece</span>
                        </div>
                        <div>
                            <strong>4 Flavors</strong>
                            <span>Popular picks</span>
                        </div>
                        <div>
                            <strong>Fast</strong>
                            <span>Grab-and-go</span>
                        </div>
                    </div>
                </div>

                <div class="hero-visual">
                    <div class="product-showcase">
                        <div class="showcase-badge">Best Seller</div>
      <img src="https://balancewithjess.com/wp-content/uploads/2022/02/Spam-Musubi-with-Egg-Feat-500x500.jpg" alt="Spam Musubi with Egg">
                        <div class="showcase-card">
                            <p>Spam & Egg</p>
                            <strong>₱40.00</strong>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="promo-strip">
            <div class="container promo-inner">
                <div>Freshly made daily</div>
                <div>Perfect for students</div>
                <div>Portable and filling</div>
            </div>
        </section>

        <section id="menu" class="products-section section-spacing">
            <div class="container">
                <div class="section-heading row-heading">
                    <div>
                        <span class="eyebrow">Menu</span>
                        <h2>Best picks for your cravings</h2>
                    </div>
                    <a href="#contact" class="text-link">Order today</a>
                </div>

                <div class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <article class="product-card">
                            <div class="badge">₱40</div>
                            <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                            <div class="product-info">
                                <div class="rating">★★★★★ <span>4.9</span></div>
                                <h3><?= htmlspecialchars($product['name']) ?></h3>
                                <p><?= htmlspecialchars($product['description']) ?></p>
                                <div class="product-bottom">
                                    <strong>₱<?= number_format((float) $product['price'], 2) ?></strong>
                                    <button class="add-cart" data-add-to-cart data-product-id="<?= (int) $product['id'] ?>" data-name="<?= htmlspecialchars($product['name']) ?>" data-price="<?= (float) $product['price'] ?>" data-image="<?= htmlspecialchars($product['image'] ?? '') ?>">Add to cart</button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="about" class="deal-section section-spacing">
            <div class="container deal-box">
                <div class="deal-copy">
                    <span class="eyebrow">Our story</span>
                    <h2>Affordable, delicious, and built for busy days.</h2>
                    <p>
                        ONISUBI started as an idea to bring together two rice traditions people already love: light portable onigiri and flavorful musubi. By combining both, we offer a snack that is familiar, satisfying, and easy to enjoy anywhere.
                    </p>
                    <p>
                        Designed for students, professors, and school staff, each piece is packed with flavor and value—making quality meal choices accessible to everyone.
                    </p>
                    <a class="btn btn-primary" href="#contact">Get yours</a>
                </div>
                <div class="deal-visual">
                    <img src="https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=900&q=80" alt="Onisubi snack presentation">
                </div>
            </div>
        </section>

        <section class="features-section section-spacing">
            <div class="container">
                <div class="section-heading center">
                    <span class="eyebrow">Why ONISUBI</span>
                    <h2>Simple, filling, and wallet-friendly</h2>
                </div>

                <div class="feature-grid">
                    <div class="feature-item">
                        <div class="feature-icon">🥢</div>
                        <h3>Traditional taste</h3>
                        <p>Inspired by Japanese onigiri and Hawaiian musubi favorites.</p>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">💸</div>
                        <h3>Affordable price</h3>
                        <p>Each piece is only ₱40.00, making it easy to grab anytime.</p>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">⚡</div>
                        <h3>Fast and filling</h3>
                        <p>Perfect for busy school days, breaks, and quick meals.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="newsletter-section section-spacing">
            <div class="container newsletter-box">
                <div>
                    <span class="eyebrow">Stay updated</span>
                    <h2>Catch the latest ONISUBI flavor drops</h2>
                </div>
                <form class="newsletter-form">
                    <input type="email" placeholder="Enter your email address" aria-label="Email address">
                    <button type="submit" class="btn btn-primary">Subscribe</button>
                </form>
            </div>
        </section>
    </main>

    <footer id="contact" class="site-footer">
        <div class="container footer-grid">
            <div>
                <div class="brand footer-brand"><img class="brand-logo-image" src="assets/images/onisubi-logo.svg" alt="ONISUBI logo"><span class="brand-wordmark"><span class="brand-oni">ONI</span><span class="brand-subi">SUBI</span></span></div>
                <p>Bringing together comfort, flavor, and affordability in every bite.</p>
            </div>
            <div>
                <h4>Menu</h4>
                <ul>
                    <li><a href="#menu">Spam & Egg</a></li>
                    <li><a href="#menu">Hotdog</a></li>
                    <li><a href="#menu">Tuna Mayo</a></li>
                    <li><a href="#menu">Egg</a></li>
                </ul>
            </div>
            <div>
                <h4>About</h4>
                <ul>
                    <li><a href="#about">Our story</a></li>
                    <li><a href="terms.php">Terms & Conditions</a></li>
                    <li><a href="privacy.php">Privacy Notice</a></li>
                </ul>
            </div>
            <div>
                <h4>Contact</h4>
                <ul class="contact-list">
                    <li><a class="contact-icon" href="https://www.facebook.com/share/1Aza6kpUNC/" target="_blank" rel="noopener" aria-label="ONISUBI Facebook page" title="ONISUBI Facebook page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 8h3V4h-3c-3.3 0-5 1.7-5 5v3H6v4h3v4h4v-4h3l1-4h-4V9c0-.7.3-1 1-1Z"/></svg></a></li>
                    <li><a class="contact-icon" href="https://maps.app.goo.gl/bxbxRkVTyakJkWbq8" target="_blank" rel="noopener" aria-label="University of Caloocan location" title="University of Caloocan"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.3"/></svg></a></li>
                </ul>
            </div>
        </div>
        <div class="container footer-bottom">
            <p>© 2026 ONISUBI. All rights reserved.</p>
        </div>
    </footer>

    <div id="cart-processing-popup" class="cart-popup" aria-live="polite">
        <strong>Order is now processing</strong>
        <span>Your ONISUBI order is being prepared.</span>
    </div>

    <script src="assets/js/script.js"></script>
</body>
</html>
