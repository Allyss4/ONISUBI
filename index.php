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

$productRatings = [];
$siteRating = 4.9;
$siteReviewCount = 0;
$featuredReviews = [];

if (isset($conn) && $conn instanceof PDO) {
  try {
    $rateStmt = $conn->query("
      SELECT product_id, 
             ROUND(AVG(rating), 1) AS avg_rating,
             COUNT(*) AS review_count
      FROM reviews
      WHERE product_id IS NOT NULL
      GROUP BY product_id
    ");
    while ($row = $rateStmt->fetch(PDO::FETCH_ASSOC)) {
      $productRatings[(int) $row['product_id']] = [
        'rating' => (float) $row['avg_rating'],
        'count'  => (int) $row['review_count'],
      ];
    }

    $overallStmt = $conn->query("
      SELECT ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS total_count 
      FROM reviews
    ");
    $overallRow = $overallStmt->fetch(PDO::FETCH_ASSOC);
    if ($overallRow && (int) ($overallRow['total_count'] ?? 0) > 0) {
      $siteRating = (float) $overallRow['avg_rating'];
      $siteReviewCount = (int) $overallRow['total_count'];
    }

    $revQuery = $conn->query("
      SELECT r.id, r.rating, r.comment, r.customer_name, r.created_at, p.name AS product_name
      FROM reviews r
      LEFT JOIN products p ON p.id = r.product_id
      WHERE r.rating >= 4
      ORDER BY r.created_at DESC
      LIMIT 12
    ");
    $featuredReviews = $revQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    // Keep defaults
  }
}

$carouselItems = [];
if (!empty($featuredReviews)) {
  $filtered = array_values(array_filter($featuredReviews, fn($r) => (int) ($r['rating'] ?? 0) >= 4));
  if (!empty($filtered)) {
    $sequence = $filtered;
    while (count($sequence) < 6) {
      $sequence = array_merge($sequence, $filtered);
    }
    $carouselItems = array_merge($sequence, $sequence);
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
  <link rel="stylesheet" href="assets/css/style.css?v=20261025_carousel">
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
        <a href="#reviews">Reviews</a>
        <a href="#contact">Contact</a>
        <?php if ($isLoggedIn): ?>
          <a class="account-link mobile-only-link" href="pages/account.php">My Account</a>
        <?php else: ?>
          <a href="#" class="mobile-only-link" onclick="openLoginModal(); return false;">Log In</a>
        <?php endif; ?>
      </nav>

      <div class="nav-actions">
        <button class="cart-pill">
          Cart <span id="cart-count">0</span>
        </button>
        <?php if ($isLoggedIn): ?>
          <a class="account-link" href="pages/account.php">My Account</a>
        <?php else: ?>
          <button type="button" class="account-link" onclick="openLoginModal();"
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
            $productId = (int) ($product['id'] ?? 0);
            $stock = (int) ($product['stock'] ?? 0);
            $limit = (int) ($product['low_stock_limit'] ?? 5);
            $isOutOfStock = $stock <= 0;
            $isLowStock = !$isOutOfStock && $stock <= $limit;

            $ratingInfo = $productRatings[$productId] ?? null;
            $pRating = $ratingInfo ? (float) $ratingInfo['rating'] : 5.0;
            $pReviewCount = $ratingInfo ? (int) $ratingInfo['count'] : 0;
            $starsCount = (int) round($pRating);
            $starDisplay = str_repeat('★', max(1, min(5, $starsCount))) . str_repeat('☆', max(0, 5 - max(1, min(5, $starsCount))));

            $extra = getProductExtraDetails($product, $productDetailsMap);
            $fullData = [
              'id' => $productId,
              'name' => (string) ($product['name'] ?? ''),
              'price' => (float) ($product['price'] ?? 40.0),
              'image' => (string) ($product['image'] ?? ''),
              'stock' => $stock,
              'low_stock_limit' => $limit,
              'rating' => $pRating,
              'review_count' => $pReviewCount,
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
                     data-product-id="<?= $productId ?>"
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
                <div class="rating">
                  <?= $starDisplay ?> <span><?= number_format($pRating, 1) ?> (<?= $pReviewCount > 0 ? $pReviewCount : 'New' ?>)</span>
                </div>
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
                    data-product-id="<?= $productId ?>"
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

    <section id="reviews" class="reviews-section section-spacing">
      <div class="container">
        <div class="section-heading center">
          <span class="eyebrow">Customer Reviews</span>
          <h2>What our customers say</h2>
          <p class="section-subheading">
            Rated <strong><?= number_format($siteRating, 1) ?> / 5.0</strong> based on <strong><?= $siteReviewCount ?></strong> customer ratings
          </p>
        </div>
      </div>

      <div class="reviews-carousel-wrapper" aria-label="Customer reviews carousel" role="region">
        <?php if (!empty($carouselItems)): ?>
          <div class="reviews-track">
            <?php foreach ($carouselItems as $rev): 
              $revRating = max(1, min(5, (int) ($rev['rating'] ?? 5)));
              $revStars = str_repeat('★', $revRating) . str_repeat('☆', 5 - $revRating);
              $revAuthor = !empty($rev['customer_name']) ? (string) $rev['customer_name'] : 'Customer';
              $revInitial = strtoupper(mb_substr($revAuthor, 0, 1));
              $revDate = !empty($rev['created_at']) ? date('M d, Y', strtotime($rev['created_at'])) : 'Recent';
            ?>
              <div class="review-card">
                <div class="review-card-top">
                  <div class="review-rating-stars"><?= $revStars ?></div>
                  <span class="review-date"><?= e($revDate) ?></span>
                </div>
                <p class="review-comment">"<?= e((string) ($rev['comment'] ?? '')) ?>"</p>
                <div class="review-author-wrap">
                  <div class="review-avatar"><?= e($revInitial) ?></div>
                  <div class="review-author-info">
                    <strong><?= e($revAuthor) ?></strong>
                    <?php if (!empty($rev['product_name'])): ?>
                      <span class="review-product-tag"><?= e((string) $rev['product_name']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="container">
            <p class="empty-reviews">No reviews submitted yet. Be the first to share your thoughts!</p>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section id="feedback" class="newsletter-section section-spacing">
      <div class="container newsletter-box">
        <div>
          <span class="eyebrow">Customer feedback</span>
          <h2>Tell us what you think of ONISUBI</h2>
          <p style="color: var(--muted); margin-top: 6px; max-width: 540px;">
            Your feedback directly shapes our menu, portion sizes, and service. Help us make ONISUBI even better!
          </p>
        </div>
        <div class="newsletter-action">
          <button type="button" class="btn btn-primary" onclick="openFeedbackModal();">Give Feedback</button>
        </div>
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



  <!-- Login/Register Modal -->
  <div id="login-prompt-modal" class="modal-backdrop" hidden>
    <section class="confirm-modal auth-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="login-prompt-title" style="max-height: 90vh; overflow-y: auto;">
      <button class="modal-close" type="button" onclick="document.getElementById('login-prompt-modal').hidden = true;" aria-label="Close">&times;</button>
      <span class="eyebrow">Welcome</span>
      <h2 id="login-prompt-title">Enter your email to continue</h2>

      <form class="checkout-form magic-auth-form" method="post" action="pages/auth.php" style="margin-top: 16px;">
        <!-- Step 1 -->
        <div class="auth-step-1">
          <div class="modal-field-group">
            <label for="modal-email-check">Email address</label>
            <input type="email" id="modal-email-check" name="email_check" class="auth-email-input" placeholder="you@example.com" required>
          </div>
          <button type="button" class="btn btn-primary auth-continue-btn" style="width: 100%;">Continue</button>
        </div>

        <!-- Step 2 (Login) -->
        <div class="auth-step-login" hidden style="display: none;">
          <input type="hidden" name="action" value="login" disabled>
          <input type="hidden" name="redirect" value="index.php">
          <input type="hidden" name="email" class="auth-email-hidden" disabled>

          <div class="auth-modal-user-bar">
            <strong class="auth-email-display"></strong>
            <button type="button" class="text-link auth-back-btn">Change</button>
          </div>

          <div class="modal-field-group">
            <label for="modal-login-password">Password</label>
            <input type="password" id="modal-login-password" name="password" placeholder="••••••••" required disabled>
          </div>
          <button class="btn btn-primary" type="submit" style="width: 100%;">Log in</button>
          <p style="margin-top: 16px; text-align: center;">
            <a class="text-link" href="pages/reset-password.php">Forgot or reset password?</a>
          </p>
        </div>

        <!-- Step 2 (Register) -->
        <div class="auth-step-register" hidden style="display: none;">
          <input type="hidden" name="action" value="register" disabled>
          <input type="hidden" name="redirect" value="index.php">
          <input type="hidden" name="email" class="auth-email-hidden" disabled>

          <div class="auth-modal-user-bar">
            <strong class="auth-email-display"></strong>
            <button type="button" class="text-link auth-back-btn">Change</button>
          </div>

          <div class="modal-field-group">
            <label for="modal-reg-name">Full name</label>
            <input type="text" id="modal-reg-name" name="name" placeholder="Juan Dela Cruz" required disabled>
          </div>

          <div class="modal-field-group">
            <label for="modal-reg-phone">Phone number</label>
            <input type="tel" id="modal-reg-phone" name="phone" placeholder="09123456789" required disabled>
          </div>

          <div class="modal-field-group">
            <label for="modal-reg-address">Delivery address</label>
            <textarea id="modal-reg-address" name="address" rows="2" placeholder="House #, Street, City" required disabled></textarea>
          </div>

          <div class="modal-field-group">
            <label for="modal-reg-password">Password (8+ characters)</label>
            <input type="password" id="modal-reg-password" name="password" minlength="8" placeholder="••••••••" required disabled>
          </div>

          <button class="btn btn-primary auth-submit-btn" type="submit" style="width: 100%;">Create account</button>
        </div>
      </form>
    </section>
  </div>

  <!-- Customer Feedback & Review Modal -->
  <div id="feedback-modal" class="modal-backdrop" hidden>
    <section class="confirm-modal feedback-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="feedback-modal-title" style="max-height: 90vh; overflow-y: auto;">
      <button class="modal-close" type="button" onclick="closeFeedbackModal();" aria-label="Close">&times;</button>
      <span class="eyebrow">Feedback & Review</span>
      <h2 id="feedback-modal-title">Share your ONISUBI experience</h2>

      <div id="feedback-status" class="feedback-status-msg" role="alert"></div>

      <form id="feedback-form" class="checkout-form" style="margin-top: 16px;">
        <div class="modal-field-group">
          <label>Your Overall Rating</label>
          <div class="star-rating-picker" id="feedback-star-picker" role="radiogroup" aria-label="Rating from 1 to 5 stars">
            <span data-star="1" class="is-active" role="radio" aria-checked="false" tabindex="0">★</span>
            <span data-star="2" class="is-active" role="radio" aria-checked="false" tabindex="0">★</span>
            <span data-star="3" class="is-active" role="radio" aria-checked="false" tabindex="0">★</span>
            <span data-star="4" class="is-active" role="radio" aria-checked="false" tabindex="0">★</span>
            <span data-star="5" class="is-active" role="radio" aria-checked="true" tabindex="0">★</span>
          </div>
          <input type="hidden" name="rating" id="feedback-rating-input" value="5">
        </div>

        <div class="feedback-grid-2">
          <div class="modal-field-group">
            <label for="feedback-respondent-type">I am a...</label>
            <select id="feedback-respondent-type" name="respondent_type" required>
              <option value="Student" selected>Student</option>
              <option value="Professor">Professor</option>
              <option value="School Staff">School Staff</option>
              <option value="Visitor">Visitor</option>
            </select>
          </div>

          <div class="modal-field-group">
            <label for="feedback-favorite-flavor">Favorite / Reviewed Flavor</label>
            <select id="feedback-favorite-flavor" name="favorite_flavor" required>
              <?php foreach ($products as $p): ?>
                <option value="<?= e((string) ($p['name'] ?? '')) ?>"><?= e((string) ($p['name'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="feedback-grid-2">
          <div class="modal-field-group">
            <label for="feedback-portion-size">Portion Size</label>
            <select id="feedback-portion-size" name="portion_size">
              <option value="Just right" selected>Just right</option>
              <option value="Too small">Too small</option>
              <option value="Too big">Too big</option>
            </select>
          </div>

          <div class="modal-field-group">
            <label for="feedback-price-fairness">Is ₱40.00 fair?</label>
            <select id="feedback-price-fairness" name="price_fairness">
              <option value="Yes, very affordable">Yes, very affordable</option>
              <option value="Fair" selected>Fair</option>
              <option value="A bit expensive">A bit expensive</option>
              <option value="Very Expensive">Very Expensive</option>
            </select>
          </div>
        </div>

        <div class="modal-field-group">
          <label for="feedback-loved-most">What did you love most?</label>
          <input type="text" id="feedback-loved-most" name="loved_most" placeholder="e.g. Crispy nori, savory spam flavor, warm rice...">
        </div>

        <div class="modal-field-group">
          <label for="feedback-comments">Comments & Suggestions</label>
          <textarea id="feedback-comments" name="comments" rows="3" placeholder="Tell us anything else you'd like us to improve or keep doing..."></textarea>
        </div>

        <div class="feedback-grid-2">
          <div class="modal-field-group">
            <label for="feedback-suggested-flavor">New flavors you want?</label>
            <input type="text" id="feedback-suggested-flavor" name="suggested_flavor" placeholder="e.g. Chicken Teriyaki, Kimchi...">
          </div>

          <div class="modal-field-group">
            <label for="feedback-website-issues">Any website bugs/issues?</label>
            <select id="feedback-website-issues" name="website_issues">
              <option value="No" selected>No, worked smoothly</option>
              <option value="Yes">Yes, experienced issues</option>
            </select>
          </div>
        </div>

        <button type="submit" id="feedback-submit-btn" class="btn btn-primary" style="width: 100%; margin-top: 8px;">
          Submit Feedback & Review
        </button>
      </form>
    </section>
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
    function openLoginModal() {
      var modal = document.getElementById('login-prompt-modal');
      if (!modal) return;
      var form = modal.querySelector('.magic-auth-form');
      if (form && typeof form.resetMagicAuth === 'function') {
        form.resetMagicAuth();
      } else {
        var s1 = modal.querySelector('.auth-step-1');
        var sL = modal.querySelector('.auth-step-login');
        var sR = modal.querySelector('.auth-step-register');
        if (s1) { s1.hidden = false; s1.style.display = ''; }
        if (sL) { sL.hidden = true; sL.style.display = 'none'; }
        if (sR) { sR.hidden = true; sR.style.display = 'none'; }
      }
      var emailInput = modal.querySelector('.auth-email-input');
      if (emailInput) {
        emailInput.value = '';
        setTimeout(function () { emailInput.focus(); }, 60);
      }
      modal.hidden = false;
    }
    (function () {
      var modal = document.getElementById('login-prompt-modal');
      if (modal) {
        modal.addEventListener('click', function (e) {
          if (e.target === modal) modal.hidden = true;
        });
      }
    })();
    window.PRODUCT_STOCKS = <?= json_encode($productStocksMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
  <?= supabase_frontend_config_tag() ?>
  <script src="assets/js/script.js?v=20261025_feedback"></script>
</body>

</html>
