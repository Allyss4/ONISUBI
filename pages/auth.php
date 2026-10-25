<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    header('Location: ../index.php');
    exit;
}

if (!function_exists('resolve_auth_redirect')) {
    /**
     * Safely resolves the redirect path from pages/auth.php
     */
    function resolve_auth_redirect(?string $redirect): string
    {
        $target = trim((string) $redirect);
        $clean = strtok($target, '?') ?: '';
        $clean = ltrim($clean, '/');

        return match ($clean) {
            'index.php', '', 'home' => '../index.php',
            'pages/account.php', 'account.php', 'account' => 'account.php',
            'pages/checkout.php', 'checkout.php', 'checkout' => 'checkout.php',
            'pages/cart.php', 'cart.php', 'cart' => 'cart.php',
            'pages/settings.php', 'settings.php', 'settings' => 'settings.php',
            'admin', 'admin/dashboard.php', '../admin/dashboard.php' => '../admin/dashboard.php',
            default => (str_starts_with($clean, 'pages/') ? substr($clean, 6) : (file_exists(__DIR__ . '/' . $clean) ? $clean : '../index.php')),
        };
    }
}

$rawRedirect = (string) ($_POST['redirect'] ?? $_GET['redirect'] ?? '');
$resolvedRedirect = resolve_auth_redirect($rawRedirect);

// If user is already authenticated and visits auth.php
if (current_user_id() && empty($action)) {
    $stmt = $conn->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => current_user_id()]);
    $existingRole = strtolower((string) ($stmt->fetchColumn() ?: ''));
    if (in_array($existingRole, ['admin', 'staff'], true)) {
        header('Location: ../admin/dashboard.php');
        exit;
    }
    // Customer is already logged in: send to intended destination or account
    header('Location: ' . $resolvedRedirect);
    exit;
}

// Check schema for 'password_hash' column
$hasPasswordHash = true;
try {
    $columnCheckStmt = $conn->prepare("
        SELECT 1 
        FROM information_schema.columns 
        WHERE table_schema = 'public' 
          AND table_name = 'users' 
          AND column_name = 'password_hash'
    ");
    $columnCheckStmt->execute();
    $hasPasswordHash = (bool) $columnCheckStmt->fetchColumn();
} catch (Throwable $e) {
    $hasPasswordHash = true;
}

$error = '';
$emailInput = trim((string) ($_POST['email'] ?? $_POST['username'] ?? $_GET['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));

        if ($name === '' || strlen($password) < 8 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide your name, a valid email, and a password with at least 8 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $passCol = $hasPasswordHash ? 'password_hash' : 'password';
                $stmt = $conn->prepare("INSERT INTO users (name, email, {$passCol}, phone, address, role) VALUES (:name, :email, :hash, :phone, :address, 'customer') RETURNING id");
                $stmt->execute([
                    ':name'    => $name,
                    ':email'   => $email,
                    ':hash'    => $hash,
                    ':phone'   => $phone,
                    ':address' => $address,
                ]);

                $newUserId = (int) $stmt->fetchColumn();
                if (!$newUserId) {
                    $newUserId = (int) $conn->lastInsertId();
                }

                $_SESSION['user_id'] = $newUserId;
                $_SESSION['role'] = 'customer';
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;

                header('Location: ' . $resolvedRedirect);
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') {
                    $error = 'That email is already registered. Please log in.';
                } else {
                    $error = 'Registration failed. Please verify your details and try again.';
                }
            }
        }
    } elseif ($action === 'login') {
        $identifier = strtolower(trim((string) ($_POST['email'] ?? $_POST['username'] ?? '')));
        $submittedPassword = (string) ($_POST['password'] ?? '');

        if ($identifier === '' || $submittedPassword === '') {
            $error = 'Please enter both your email address and password.';
        } else {
            $passCol = $hasPasswordHash ? 'password_hash' : 'password';
            $stmt = $conn->prepare("
                SELECT id, name, email, role, {$passCol} AS password_hash 
                FROM users 
                WHERE LOWER(email) = :identifier OR LOWER(name) = :identifier 
                LIMIT 1
            ");
            $stmt->execute([':identifier' => $identifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $storedHash = (string) ($user['password_hash'] ?? '');
            $passwordMatches = false;

            if ($storedHash !== '' && password_verify($submittedPassword, $storedHash)) {
                $passwordMatches = true;
            }

            if ($user && $passwordMatches) {
                $_SESSION['user_id'] = (int) $user['id'];
                $role = strtolower((string) ($user['role'] ?? 'customer'));
                $_SESSION['role'] = $role;
                $_SESSION['user_name'] = (string) ($user['name'] ?? '');
                $_SESSION['user_email'] = (string) ($user['email'] ?? '');

                if (in_array($role, ['admin', 'staff'], true)) {
                    header('Location: ../admin/dashboard.php');
                    exit;
                }
                header('Location: ' . $resolvedRedirect);
                exit;
            }

            $error = 'Email or password is incorrect.';
        }
    }
}

// Info / prompt messages
$infoMsg = '';
$msgParam = (string) ($_GET['msg'] ?? '');
if ($msgParam === 'checkout_required') {
    $infoMsg = 'Please sign in or create an account to proceed with your checkout.';
} elseif ($msgParam === 'login_required') {
    $infoMsg = 'Please log in to continue.';
} elseif ($msgParam === 'logged_out') {
    $infoMsg = 'You have been successfully signed out.';
} elseif ($msgParam !== '') {
    $infoMsg = $msgParam;
}

$activeTab = ($action === 'register' || (isset($_GET['tab']) && $_GET['tab'] === 'register')) ? 'register' : 'login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ONISUBI | Sign In &amp; Register</title>
  <link rel="icon" type="image/png" href="../assets/images/Onisubi_Logo.png">
  <link rel="apple-touch-icon" href="../assets/images/Onisubi_Logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .auth-card-wrapper {
      min-height: calc(100vh - 120px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 16px;
    }
    .auth-card {
      width: 100%;
      max-width: 440px;
      background: #ffffff;
      border-radius: 24px;
      border: 1px solid rgba(216, 193, 157, 0.5);
      box-shadow: 0 16px 40px rgba(132, 47, 10, 0.1);
      padding: 36px 32px;
      box-sizing: border-box;
    }
    .auth-tab-bar {
      display: flex;
      border-bottom: 2px solid #f0e6d6;
      margin-bottom: 24px;
      gap: 12px;
    }
    .auth-tab-btn {
      flex: 1;
      padding: 10px 4px;
      background: none;
      border: none;
      border-bottom: 3px solid transparent;
      font-size: 1rem;
      font-weight: 600;
      color: #7a6e60;
      cursor: pointer;
      transition: all 0.2s ease;
      margin-bottom: -2px;
    }
    .auth-tab-btn.is-active {
      color: #842f0a;
      border-bottom-color: #ff951c;
      font-weight: 700;
    }
    .auth-alert-error {
      background: #fef2f2;
      border: 1px solid #fca5a5;
      color: #991b1b;
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 0.92rem;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .auth-alert-info {
      background: #eff6ff;
      border: 1px solid #93c5fd;
      color: #1e40af;
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 0.92rem;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .auth-input-group {
      margin-bottom: 16px;
    }
    .auth-input-group label {
      display: block;
      font-size: 0.88rem;
      font-weight: 600;
      margin-bottom: 6px;
      color: #333333;
    }
    .auth-input-group input,
    .auth-input-group textarea {
      width: 100%;
      box-sizing: border-box;
      padding: 12px 16px;
      border: 1.5px solid #d8c19d;
      border-radius: 12px;
      background: #faf7f2;
      font-family: inherit;
      font-size: 0.95rem;
      transition: border-color 0.2s;
    }
    .auth-input-group input:focus,
    .auth-input-group textarea:focus {
      outline: none;
      border-color: #ff951c;
      background: #ffffff;
    }
    .auth-submit-btn {
      width: 100%;
      padding: 14px;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 700;
      margin-top: 8px;
      cursor: pointer;
    }
  </style>
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
        <a href="cart.php">Cart</a>
      </nav>
      <div class="nav-actions">
        <a class="btn btn-secondary" href="../index.php" style="padding: 8px 16px; font-size: 0.88rem;">Back to Store</a>
      </div>
    </div>
  </header>

  <main class="auth-card-wrapper">
    <div class="auth-card">
      <div class="auth-tab-bar" role="tablist">
        <button type="button"
                id="tab-btn-login"
                class="auth-tab-btn <?= $activeTab === 'login' ? 'is-active' : '' ?>"
                onclick="switchAuthTab('login')"
                role="tab"
                aria-selected="<?= $activeTab === 'login' ? 'true' : 'false' ?>">
          Log In
        </button>
        <button type="button"
                id="tab-btn-register"
                class="auth-tab-btn <?= $activeTab === 'register' ? 'is-active' : '' ?>"
                onclick="switchAuthTab('register')"
                role="tab"
                aria-selected="<?= $activeTab === 'register' ? 'true' : 'false' ?>">
          Create Account
        </button>
      </div>

      <?php if (!empty($error)): ?>
        <div class="auth-alert-error" role="alert">
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
          <span><?= e($error) ?></span>
        </div>
      <?php elseif (!empty($infoMsg)): ?>
        <div class="auth-alert-info" role="status">
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span><?= e($infoMsg) ?></span>
        </div>
      <?php endif; ?>

      <!-- Login Form Section -->
      <section id="auth-section-login" <?= $activeTab === 'login' ? '' : 'hidden' ?> aria-label="Login form">
        <form method="post" action="auth.php">
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="redirect" value="<?= e($rawRedirect) ?>">

          <div class="auth-input-group">
            <label for="login-email">Email address</label>
            <input type="email" id="login-email" name="email" value="<?= e($emailInput) ?>" placeholder="you@example.com" required autocomplete="email">
          </div>

          <div class="auth-input-group">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
              <label for="login-password" style="margin-bottom:0;">Password</label>
              <a href="reset-password.php" class="text-link" style="font-size:0.84rem; color:#842f0a; font-weight:500;">Forgot password?</a>
            </div>
            <input type="password" id="login-password" name="password" placeholder="••••••••" required autocomplete="current-password">
          </div>

          <button type="submit" class="btn btn-primary auth-submit-btn">Log In</button>
        </form>
      </section>

      <!-- Register Form Section -->
      <section id="auth-section-register" <?= $activeTab === 'register' ? '' : 'hidden' ?> aria-label="Register form">
        <form method="post" action="auth.php">
          <input type="hidden" name="action" value="register">
          <input type="hidden" name="redirect" value="<?= e($rawRedirect) ?>">

          <div class="auth-input-group">
            <label for="reg-name">Full name</label>
            <input type="text" id="reg-name" name="name" value="<?= e($_POST['name'] ?? '') ?>" placeholder="Juan Dela Cruz" required autocomplete="name">
          </div>

          <div class="auth-input-group">
            <label for="reg-email">Email address</label>
            <input type="email" id="reg-email" name="email" value="<?= e($emailInput) ?>" placeholder="you@example.com" required autocomplete="email">
          </div>

          <div class="auth-input-group">
            <label for="reg-password">Password (min. 8 characters)</label>
            <input type="password" id="reg-password" name="password" minlength="8" placeholder="••••••••" required autocomplete="new-password">
          </div>

          <div class="auth-input-group">
            <label for="reg-phone">Phone number (optional)</label>
            <input type="tel" id="reg-phone" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="09123456789" autocomplete="tel">
          </div>

          <div class="auth-input-group">
            <label for="reg-address">Delivery address (optional)</label>
            <textarea id="reg-address" name="address" rows="2" placeholder="House #, Street, City"><?= e($_POST['address'] ?? '') ?></textarea>
          </div>

          <button type="submit" class="btn btn-primary auth-submit-btn">Create Account</button>
        </form>
      </section>
    </div>
  </main>

  <script>
    function switchAuthTab(tab) {
      const loginSec = document.getElementById('auth-section-login');
      const regSec = document.getElementById('auth-section-register');
      const loginBtn = document.getElementById('tab-btn-login');
      const regBtn = document.getElementById('tab-btn-register');

      if (tab === 'register') {
        loginSec.hidden = true;
        regSec.hidden = false;
        loginBtn.classList.remove('is-active');
        loginBtn.setAttribute('aria-selected', 'false');
        regBtn.classList.add('is-active');
        regBtn.setAttribute('aria-selected', 'true');
      } else {
        regSec.hidden = true;
        loginSec.hidden = false;
        regBtn.classList.remove('is-active');
        regBtn.setAttribute('aria-selected', 'false');
        loginBtn.classList.add('is-active');
        loginBtn.setAttribute('aria-selected', 'true');
      }
    }
  </script>
</body>
</html>
