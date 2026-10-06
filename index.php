<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$isLoggedIn = current_user_id() !== null;
$isAdmin = false;
if ($isLoggedIn && isset($conn) && $conn instanceof PDO) {
  try {
    $roleStmt = $conn->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $roleStmt->execute([':id' => current_user_id()]);
    $roleVal = strtolower((string) $roleStmt->fetchColumn());
    $isAdmin = in_array($roleVal, ['admin', 'staff'], true);
  } catch (Throwable $e) {
    $isAdmin = false;
  }
}
$products = [];

if (isset($conn) && $conn instanceof PDO) {
  try {
    $stmt = $conn->query("
      SELECT p.id, p.name, p.price, p.description, p.image,
             COALESCE(i.quantity, p.stock, 0) AS stock,
             COALESCE(i.low_stock_limit, 5) AS low_stock_limit,
             p.status
      FROM products p
      LEFT JOIN inventory i ON i.product_id = p.id
      WHERE LOWER(p.status) <> 'deleted'
      ORDER BY p.id ASC
      LIMIT 12
    ");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $products = [];
  }
}

if (empty($products)) {
  $products = [
    ['id' => 1, 'name' => 'Spam & Egg', 'price' => 40, 'description' => 'Savory spam and fluffy egg.', 'image' => 'https://balancewithjess.com/wp-content/uploads/2022/02/Spam-Musubi-with-Egg-Feat-500x500.jpg'],
    ['id' => 2, 'name' => 'Sizzling Hotdog', 'price' => 40, 'description' => 'A tasty hotdog-filled bite with rich, smoky flavor.', 'image' => 'https://www.sbfoods-worldwide.com/recipes/rst0f40000000t14-img/416_UMAMI_TOPPING_Onigiri_ichiran.jpg'],
    ['id' => 3, 'name' => 'Tuna Mayo', 'price' => 40, 'description' => 'Creamy, savory tuna filling for a classic bite.', 'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcR5-EjRHu8gJ2nWryFFe9zgbQhBY7L4A1fH9eJmrOwyLZ9l7tGJzfo_QtQ3&s=10'],
    ['id' => 4, 'name' => 'Egg', 'price' => 40, 'description' => 'Simple, comforting, and filling egg musubi favorite.', 'image' => 'https://japanesecooking101.com/wp-content/uploads/2023/10/IMG_6350.jpeg'],
  ];
}

$productStocksMap = [];
foreach ($products as $p) {
  $productStocksMap[(int) $p['id']] = [
    'id' => (int) $p['id'],
    'name' => (string) $p['name'],
    'stock' => (int) ($p['stock'] ?? 0),
    'low_stock_limit' => (int) ($p['low_stock_limit'] ?? 5),
  ];
}
// ALLERGENS rice, nori seaweed, luncheon meat, egg, sweet teriyaki sauce
$productDetailsMap = [
  'spam & egg' => [
    'full_description' => 'Our signature crowd-favorite! A hearty slice of pan-seared luncheon meat glazed in a savory-sweet teriyaki shoyu reduction, layered over warm Japanese sushi rice and a fluffy seasoned scrambled egg, tightly wrapped in crisp roasted nori.',
    'ingredients' => ['Rice', 'Nori Seaweed', 'Luncheon Meat', 'Scrambled Egg', 'Sweet Teriyaki Glaze'],
    'allergens' => [
      ['label' => 'Contains Egg', 'type' => 'allergen'],
      ['label' => 'Contains Soy', 'type' => 'allergen'],
    ],
    'badges' => [
      ['label' => 'Best Seller', 'type' => 'badge-bestseller'],
      ['label' => 'Fresh Daily', 'type' => 'badge-info'],
    ],
  ],

  // CAN'T CHANGE THE "HOTDOG" INTO "SIZZLING HOTDOG" - DB
  'sizzling hotdog' => [
    'full_description' => 'A tasty hotdog-filled bite with rich, smoky flavor. Juicy Japanese-style grilled sausage nestled over seasoned sushi rice with sweet umami glaze, enveloped in roasted nori seaweed.',
    'ingredients' => ['Rice', 'Nori Seaweed', 'Hotdog', 'Ketchup', 'Oyster Sauce', 'Pepper', 'Sugar', 'Onion'],
    'allergens' => [
      ['label' => 'Contains Oyster Sauce (Mollusk Allergy)', 'type' => 'allergen'],
      ['label' => 'Contains Soy', 'type' => 'allergen'],
    ],
    'badges' => [
      ['label' => 'Popular Choice', 'type' => 'badge-bestseller'],
      ['label' => 'Dairy-Free', 'type' => 'badge-info'],
    ],
  ],

  'tuna mayo' => [
    'full_description' => 'Creamy, savory tuna filling for a classic Japanese onigiri bite. Made with tender flaked skipjack tuna folded into velvety Kewpie mayonnaise, fresh cracked black pepper, sushi rice, and toasted seaweed.',
    'ingredients' => ['Rice', 'Nori Seaweed', 'Tuna', 'Mayonnaise', 'Pepper', 'Soy Sauce'],
    'allergens' => [
      ['label' => 'Contains Fish (Tuna)', 'type' => 'allergen'],
      ['label' => 'Contains Egg', 'type' => 'allergen'],
      ['label' => 'Contains Soy', 'type' => 'allergen'],
    ],
    'badges' => [
      ['label' => "Chef's Pick", 'type' => 'badge-bestseller'],
      ['label' => 'High Protein', 'type' => 'badge-info'],
    ],
  ],

  'egg' => [
    'full_description' => 'Simple, comforting, and filling egg musubi favorite. Featuring sweet-savory dashi-infused tamagoyaki layered over seasoned Japanese sushi rice, wrapped in roasted nori.',
    'ingredients' => ['Rice', 'Nori Seaweed', 'Egg', 'Pepper', 'Salt'],
    'allergens' => [
      ['label' => 'Contains Egg', 'type' => 'allergen'],
    ],
    'badges' => [
      ['label' => 'Vegetarian Friendly', 'type' => 'badge-bestseller'],
      ['label' => 'Comfort Classic', 'type' => 'badge-info'],
    ],
  ],
];

function getProductExtraDetails(array $product, array $map): array {
  $nameKey = strtolower(trim((string)($product['name'] ?? '')));
  if (isset($map[$nameKey])) {
    return $map[$nameKey];
  }
  foreach ($map as $k => $v) {
    if (str_contains($nameKey, $k) || str_contains($k, $nameKey)) {
      return $v;
    }
  }
  return [
    'full_description' => (string)($product['description'] ?? 'Delicious Japanese rice snack handcrafted with quality nori and savory fillings.'),
    'ingredients' => ['Japanese Rice', 'Roasted Nori Seaweed', 'House Seasoning', 'Chef Special Sauce'],
    'allergens' => [
      ['label' => 'Contains Soy', 'type' => 'allergen'],
      ['label' => 'Freshly Prepared', 'type' => 'badge-info'],
    ],
    'badges' => [
      ['label' => 'Popular', 'type' => 'badge-bestseller'],
    ],
  ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ONISUBI | Rice Snack & Musubi</title>
  <meta name="description" content="ONISUBI brings together Japanese onigiri and Hawaiian musubi in a delicious, budget-friendly snack.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css?v=20260913c">
</head>

<body>
  <header class="site-header">
    <div class="container nav-wrap">
      <a class="brand" href="index.php" aria-label="Go to ONISUBI home">
        <img class="brand-logo-image" src="assets/images/onisubi-logo.svg" alt="ONISUBI logo">
        <span class="brand-wordmark"><span class="brand-oni">ONI</span><span class="brand-subi">SUBI</span></span>
      </a>

      <nav class="main-nav" id="main-nav" aria-label="Main navigation">
        <a href="#about">About</a>
        <a href="#menu">Menu</a>
        <a href="#contact">Contact</a>
        <?php if ($isLoggedIn): ?>
          <a class="account-link" href="pages/account.php">My Account</a>
        <?php else: ?>
          <a href="pages/auth.php?redirect=index.php" class="mobile-only-link">Log In</a>
        <?php endif; ?>
      </nav>

      <div class="nav-actions">
        <button class="cart-pill">
          Cart <span id="cart-count">0</span>
        </button>
        <?php if ($isLoggedIn): ?>
          <a class="account-link" href="pages/account.php">My Account</a>
        <?php else: ?>
          <a class="account-link" href="pages/auth.php?redirect=index.php">Log In</a>
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

  <main>
    <section id="home" class="hero-section">
      <div class="container hero-grid">
        <div class="hero-text">
          <span class="tag">Budget-friendly snack</span>
          <h1>Japanese onigiri + Hawaiian musubi in one delicious bite.</h1>
          <p>
            ONISUBI is a food mix that combines Japanese rice balls and Hawaiian musubi, giving you a fast, tasty, and
            filling meal at only ₱40 each.
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
          <?php foreach ($products as $product):
            $stock = (int) ($product['stock'] ?? 0);
            $limit = (int) ($product['low_stock_limit'] ?? 5);
            $isOutOfStock = $stock <= 0;
            $isLowStock = !$isOutOfStock && $stock <= $limit;

            $extra = getProductExtraDetails($product, $productDetailsMap);
            $fullData = [
              'id' => (int) ($product['id'] ?? 0),
              'name' => (string) ($product['name'] ?? ''),
              'price' => (float) ($product['price'] ?? 40.0),
              'image' => (string) ($product['image'] ?? ''),
              'stock' => $stock,
              'low_stock_limit' => $limit,
              'description' => $extra['full_description'],
              'short_description' => (string) ($product['description'] ?? ''),
              'ingredients' => $extra['ingredients'],
              'allergens' => $extra['allergens'],
              'badges' => $extra['badges'],
            ];
            $jsonData = json_encode($fullData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
          ?>
            <article class="product-card <?= $isOutOfStock ? 'is-out-of-stock' : '' ?>"
                     data-product-card
                     data-product-id="<?= (int) ($product['id'] ?? 0) ?>"
                     data-stock="<?= $stock ?>"
                     data-low-limit="<?= $limit ?>"
                     data-product-json='<?= $jsonData ?>'
                     tabindex="0"
                     role="button"
                     aria-haspopup="dialog"
                     aria-label="View details for <?= e((string) ($product['name'] ?? '')) ?>">
              <div class="product-img-wrap">
                <img src="<?= e((string) ($product['image'] ?? '')) ?>" alt="<?= e((string) ($product['name'] ?? '')) ?>" loading="lazy">
                <span class="product-view-hint">View details</span>
              </div>
              <div class="product-info">
                <div class="rating">★★★★★ <span>4.9</span></div>
                <h3><?= e((string) ($product['name'] ?? '')) ?></h3>
                <p><?= e((string) ($product['description'] ?? '')) ?></p>
                <div class="stock-status-wrap">
                  <?php if ($isOutOfStock): ?>
                    <span class="product-stock-tag out-of-stock" data-stock-indicator>Sold Out</span>
                  <?php elseif ($isLowStock): ?>
                    <span class="product-stock-tag low-stock" data-stock-indicator>Only <?= $stock ?> left!</span>
                  <?php else: ?>
                    <span class="product-stock-tag in-stock" data-stock-indicator>In Stock</span>
                  <?php endif; ?>
                </div>
                <div class="product-bottom">
                  <strong>₱<?= number_format((float) ($product['price'] ?? 0), 2) ?></strong>
                  <button type="button" class="add-cart" data-add-to-cart
                    data-product-id="<?= (int) ($product['id'] ?? 0) ?>"
                    data-name="<?= e((string) ($product['name'] ?? '')) ?>"
                    data-price="<?= (float) ($product['price'] ?? 0) ?>"
                    data-image="<?= e((string) ($product['image'] ?? '')) ?>"
                    <?= $isOutOfStock ? 'disabled' : '' ?>>
                    <?= $isOutOfStock ? 'Out of Stock' : 'Add to cart' ?>
                  </button>
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
            ONISUBI started as an idea to bring together two rice traditions people already love: light portable onigiri
            and flavorful musubi. By combining both, we offer a snack that is familiar, satisfying, and easy to enjoy
            anywhere.
          </p>
          <p>
            Designed for students, professors, and school staff, each piece is packed with flavor and value—making
            quality meal choices accessible to everyone.
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
            <h3>Traditional taste</h3>
            <p>Inspired by Japanese onigiri and Hawaiian musubi favorites.</p>
          </div>
          <div class="feature-item">
            <h3>Affordable price</h3>
            <p>Each piece is only ₱40.00, making it easy to grab anytime.</p>
          </div>
          <div class="feature-item">
            <h3>Fast and filling</h3>
            <p>Perfect for busy school days, breaks, and quick meals.</p>
          </div>
        </div>
      </div>
    </section>

    <section id="feedback" class="newsletter-section section-spacing">
      <div class="container newsletter-box">
        <div>
          <span class="eyebrow">Customer feedback</span>
          <h2>Tell us what you think of ONISUBI</h2>
        </div>
        <form class="newsletter-form">
          <input type="text" maxlength="500" placeholder="Share your feedback with us" aria-label="Your feedback" required>
          <button type="submit" class="btn btn-primary">Send feedback</button>
        </form>
      </div>
    </section>
  </main>

  <footer id="contact" class="site-footer">
    <div class="container footer-grid">
      <div>
        <h4>About Us</h4>
        <ul class="about-list">
          <li><a href="#about">Our Story</a></li>
          <li><a href="pages/terms.php">Terms & Conditions</a></li>
          <li><a href="pages/privacy.php">Privacy Notice</a></li>
        </ul>
      </div>
      <div>
        <h4>Contact</h4>
        <ul class="contact-list">
          <li>
            <a class="contact-icon" href="https://www.facebook.com/share/1Aza6kpUNC/" target="_blank" rel="noopener" aria-label="ONISUBI Facebook page" title="ONISUBI Facebook page">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M14 8h3V4h-3c-3.3 0-5 1.7-5 5v3H6v4h3v4h4v-4h3l1-4h-4V9c0-.7.3-1 1-1Z" />
              </svg>
            </a>
          </li>
          <li>
            <a class="contact-icon" href="https://maps.app.goo.gl/bxbxRkVTyakJkWbq8" target="_blank" rel="noopener" aria-label="University of Caloocan location" title="University of Caloocan">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z" />
                <circle cx="12" cy="9" r="2.3" />
              </svg>
            </a>
          </li>
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



  <!-- Interactive Product Detail Modal -->
  <div id="product-detail-modal" class="modal-backdrop product-modal-backdrop" hidden role="dialog" aria-modal="true" aria-labelledby="product-modal-title">
    <div class="product-modal-dialog">
      <button class="modal-close product-modal-close-btn" type="button" data-product-modal-close aria-label="Close product details">&times;</button>

      <div class="product-modal-body-layout">
        <!-- Fixed Product Image Panel -->
        <div class="product-modal-media-col">
          <div class="product-modal-image-wrap">
            <img id="product-modal-image" src="" alt="Product image" loading="eager">
            <div id="product-modal-badges-overlay" class="modal-badges-overlay"></div>
          </div>
        </div>

        <!-- Details Column -->
        <div class="product-modal-details-col">
          <!-- Independently Scrollable Details Panel -->
          <div class="product-modal-content-scroll" id="product-modal-scrollable-content">
            <div class="product-modal-header">
              <div class="product-modal-rating">★★★★★ <span>4.9 (120+ reviews)</span></div>
              <h2 id="product-modal-title" class="product-modal-title"></h2>
              <div class="product-modal-base-price" id="product-modal-base-price">₱40.00</div>
            </div>

            <div class="product-modal-section">
              <h4 class="section-title">Description</h4>
              <p id="product-modal-description" class="product-modal-desc"></p>
            </div>

            <div class="product-modal-section">
              <h4 class="section-title">Fresh Ingredients</h4>
              <div id="product-modal-ingredients" class="chips-list"></div>
            </div>

            <div class="product-modal-section">
              <h4 class="section-title">Allergen & Dietary Badges</h4>
              <div id="product-modal-allergens" class="badges-list"></div>
            </div>
          </div>

          <!-- Fixed Action Bar at Bottom -->
          <div class="product-modal-sticky-cta">
            <div class="modal-cta-left">
              <div class="modal-stepper" aria-label="Quantity selector">
                <button type="button" class="stepper-btn" id="modal-qty-minus" aria-label="Decrease quantity">−</button>
                <span class="stepper-value" id="modal-qty-value" aria-live="polite">1</span>
                <button type="button" class="stepper-btn" id="modal-qty-plus" aria-label="Increase quantity">+</button>
              </div>
              <div class="modal-price-container">
                <span class="modal-price-label">Total</span>
                <strong class="modal-calculated-price" id="modal-total-price">₱40.00</strong>
              </div>
            </div>

            <div class="modal-cta-right">
              <button type="button" class="btn btn-primary modal-add-cart-button" id="modal-add-to-cart-btn">
                <span class="btn-text">Add to Cart</span>
                <span class="btn-divider">•</span>
                <span class="btn-price" id="modal-btn-price">₱40.00</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Feedback form. Placed before script.js on purpose, so stopImmediatePropagation()
    // blocks script.js's old "Thanks for subscribing!" handler on the same form.
    (function () {
      var form = document.querySelector('#feedback .newsletter-form');
      if (!form) return;
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var button = form.querySelector('button[type="submit"]');
        var original = button.textContent;
        button.textContent = 'Thank you! ✓';
        button.disabled = true;
        form.reset();
        setTimeout(function () {
          button.textContent = original;
          button.disabled = false;
        }, 2500);
      });
    })();
  </script>
  <script>
    window.PRODUCT_STOCKS = <?= json_encode($productStocksMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
  <?= supabase_frontend_config_tag() ?>
  <script src="assets/js/script.js?v=20261007"></script>
</body>

</html>
