<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'logout') { session_destroy(); header('Location: index.php'); exit; }

$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'account.php';
if (!in_array($redirect, ['account.php', 'checkout.php'], true)) {
    $redirect = 'account.php';
}
$msg = $_GET['msg'] ?? '';

$hasPasswordHash = false;
$userColumnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
if ($userColumnCheck && $userColumnCheck->num_rows > 0) { $hasPasswordHash = true; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginValue = trim((string) ($_POST['email'] ?? $_POST['username'] ?? ''));
    $email = strtolower($loginValue);
    if ($action === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        if ($name === '' || strlen($password) < 8) {
            $error = 'Use your name and a password with at least 8 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hasPasswordHash) {
                $stmt = $conn->prepare('INSERT INTO users (name,email,password_hash,phone,address) VALUES (?,?,?,?,?)');
                $stmt->bind_param('sssss', $name, $email, $hash, $phone, $address);
            } else {
                $stmt = $conn->prepare('INSERT INTO users (name,email,password,phone,address) VALUES (?,?,?,?,?)');
                $stmt->bind_param('sssss', $name, $email, $hash, $phone, $address);
            }
            if ($stmt->execute()) { $_SESSION['user_id'] = $stmt->insert_id; header('Location: ' . $redirect); exit; }
            $error = 'That email is already registered.';
        }
    } elseif ($action === 'login') {
        $identifier = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($identifier === '' || !filter_var($identifier, FILTER_VALIDATE_EMAIL) && !preg_match('/^[A-Za-z0-9_.-]+$/', $identifier)) {
            $error = 'Enter a valid email address or username.';
        } else {
            $stmt = $conn->prepare('SELECT id,name,email,password_hash FROM users WHERE email=? OR name=? LIMIT 1');
            $stmt->bind_param('ss', $identifier, $identifier);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            $submittedPassword = (string) ($_POST['password'] ?? '');
            $storedHash = $user['password_hash'] ?? '';
            $passwordMatches = false;
            if ($storedHash !== '' && password_verify($submittedPassword, $storedHash)) { $passwordMatches = true; }

            if ($user && $passwordMatches) { $_SESSION['user_id'] = (int) $user['id']; header('Location: ' . $redirect); exit; }
            $error = 'Email or password is incorrect.';
        }
    }
}
?><!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ONISUBI | Account</title><link rel="stylesheet" href="assets/css/style.css"></head><body><main class="checkout-page"><div class="container checkout-layout">

<?php if ($msg === 'checkout_required'): ?>
<div class="panel" style="grid-column: 1 / -1; margin-bottom: 24px; background-color: var(--color-surface); border-left: 4px solid var(--color-primary); padding: 16px;">
    <strong>Create an account to complete your checkout</strong>
    <p style="margin-top: 8px; color: var(--color-text-light);">We require an account so you can track your order status and view your order history.</p>
</div>
<?php endif; ?>

<section class="panel">
    <h2 id="login-prompt-title">Enter your email to continue</h2>
    <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
    
    <form class="checkout-form magic-auth-form" method="post" action="auth.php">
        <!-- Step 1 -->
        <div class="auth-step-1">
            <input type="email" name="email_check" class="auth-email-input" placeholder="Email address" required style="margin-bottom: 16px;">
            <button type="button" class="btn btn-primary auth-continue-btn" style="width: 100%;">Continue</button>
        </div>

        <!-- Step 2 (Login) -->
        <div class="auth-step-login" hidden>
            <input type="hidden" name="action" value="login" disabled>
            <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
            <input type="hidden" name="email" class="auth-email-hidden" disabled>
            
            <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
                <strong class="auth-email-display"></strong>
                <button type="button" class="text-link auth-back-btn" style="background:none;border:none;cursor:pointer;font-size:0.9rem;">Change</button>
            </div>
            
            <input type="password" name="password" placeholder="Password" required disabled>
            <button class="btn btn-primary" type="submit" style="width: 100%;">Log in</button>
            <p style="margin-top: 16px; text-align: center;"><a class="text-link" href="reset-password.php">Forgot or reset password?</a></p>
        </div>

        <!-- Step 2 (Register) -->
        <div class="auth-step-register" hidden>
            <input type="hidden" name="action" value="register" disabled>
            <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
            <input type="hidden" name="email" class="auth-email-hidden" disabled>
            
            <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
                <strong class="auth-email-display"></strong>
                <button type="button" class="text-link auth-back-btn" style="background:none;border:none;cursor:pointer;font-size:0.9rem;">Change</button>
            </div>
            
            <input type="text" name="name" placeholder="Full name" required disabled value="<?= e($_POST['name'] ?? '') ?>">
            <input type="tel" name="phone" placeholder="Phone number" required disabled value="<?= e($_POST['phone'] ?? '') ?>">
            <textarea name="address" rows="2" placeholder="Delivery address" required disabled><?= e($_POST['address'] ?? '') ?></textarea>
            <input type="password" name="password" minlength="8" placeholder="Password (8+ characters)" required disabled>
            <button class="btn btn-primary" type="submit" style="width: 100%;">Create account</button>
        </div>
    </form>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const form = document.querySelector('.magic-auth-form');
                if(form) {
                    const emailInput = form.querySelector('.auth-email-input');
                    emailInput.value = <?= json_encode($_POST['email'] ?? '') ?>;
                    const checkBtn = form.querySelector('.auth-continue-btn');
                    if(checkBtn) checkBtn.click();
                }
            });
        </script>
    <?php endif; ?>
</section>
</div>
<div class="container"><p><a class="text-link" href="index.php">Back to Home</a></p></div>
</main>
</body>
</html>