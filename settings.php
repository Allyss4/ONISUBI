<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

if (!current_user_id()) {
  header('Location: auth.php');
  exit;
}

$userId = current_user_id();

// Check if 'address' column exists in 'users' table via PostgreSQL information_schema
$hasAddressColumn = false;
$checkStmt = $conn->prepare("
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'users' AND column_name = 'address'
");
$checkStmt->execute();
if ($checkStmt->fetch()) {
  $hasAddressColumn = true;
}

$query = $hasAddressColumn
  ? 'SELECT name, email, phone, address FROM users WHERE id = ?'
  : 'SELECT name, email, phone FROM users WHERE id = ?';

$stmt = $conn->prepare($query);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim((string) ($_POST['name'] ?? ''));
  $email = strtolower(trim((string) ($_POST['email'] ?? '')));
  $phone = trim((string) ($_POST['phone'] ?? ''));
  $address = trim((string) ($_POST['address'] ?? ''));

  if ($name === '') {
    $error = 'Your name is required.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Enter a valid email address.';
  } else {
    try {
      if ($hasAddressColumn) {
        $updateStmt = $conn->prepare('UPDATE users SET name = ?, email = ?, phone = ?, address = ? WHERE id = ?');
        $updateStmt->execute([$name, $email, $phone, $address, $userId]);
      } else {
        $updateStmt = $conn->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?');
        $updateStmt->execute([$name, $email, $phone, $userId]);
      }

      $message = 'Your account details were updated successfully.';
      $user['name'] = $name;
      $user['email'] = $email;
      $user['phone'] = $phone;
      if ($hasAddressColumn) {
        $user['address'] = $address;
      }
    } catch (PDOException $e) {
      // SQLSTATE 23505 indicates a unique violation in PostgreSQL (e.g. duplicate email)
      if ($e->getCode() === '23505') {
        $error = 'That email is already in use by another account.';
      } else {
        $error = 'An error occurred while updating your profile. Please try again.';
      }
    }
  }
}
?><!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ONISUBI | Account settings</title>
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
      <nav class="main-nav" id="main-nav" aria-label="Main navigation">
        <a href="index.php">Home</a>
        <a href="account.php">My Account</a>
        <a href="index.php#menu">Menu</a>
        <?php if ($userId): ?>
          <a href="auth.php?action=logout" class="mobile-only-link">Log Out</a>
        <?php else: ?>
          <a href="#" class="mobile-only-link" onclick="document.getElementById('login-prompt-modal').hidden = false; return false;">Log In</a>
        <?php endif; ?>
      </nav>
      <div class="nav-actions">
        <button class="cart-pill">
          Cart <span id="cart-count">0</span>
        </button>
        <?php if ($userId): ?>
          <a class="secondary-btn" href="auth.php?action=logout">Log Out</a>
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

  <main class="checkout-page">
    <div class="container checkout-layout">
      <section class="panel">
        <span class="eyebrow">Account Settings</span>
        <h2>Update your profile</h2>

        <?php if ($error): ?>
          <p class="form-error"><?= e($error) ?></p>
        <?php elseif ($message): ?>
          <p class="form-success" style="color:#1a6d49; margin-bottom: 14px; font-weight: 600;"><?= e($message) ?></p>
        <?php endif; ?>

        <form class="checkout-form" method="post">
          <input type="text" name="name" value="<?= e((string) ($user['name'] ?? '')) ?>" placeholder="Full name" required>
          <input type="email" name="email" value="<?= e((string) ($user['email'] ?? '')) ?>" placeholder="Email address" required>
          <input type="tel" name="phone" value="<?= e((string) ($user['phone'] ?? '')) ?>" placeholder="Contact number">
          <?php if ($hasAddressColumn): ?>
              <textarea name="address" rows="4" placeholder="Delivery address"><?= e((string) ($user['address'] ?? '')) ?></textarea>
          <?php endif; ?>
          <div class="checkout-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </section>
    </div>
  </main>

  <script src="assets/js/script.js"></script>
</body>

</html>