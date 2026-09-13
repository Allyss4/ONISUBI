<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Process password update form
  if (isset($_POST['new_password'], $_POST['token'])) {
    $hash = hash('sha256', (string) $_POST['token']);

    $stmt = $conn->prepare('SELECT user_id FROM password_resets WHERE token_hash = ? AND expires_at > CURRENT_TIMESTAMP');
    $stmt->execute([$hash]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($reset && strlen((string) $_POST['new_password']) >= 8) {
      $password = password_hash((string) $_POST['new_password'], PASSWORD_DEFAULT);

      // Update user password
      $updateStmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
      $updateStmt->execute([$password, (int) $reset['user_id']]);

      // Clear used reset token
      $deleteStmt = $conn->prepare('DELETE FROM password_resets WHERE token_hash = ?');
      $deleteStmt->execute([$hash]);

      $message = 'Password updated. You can now log in.';
    } else {
      $message = 'The reset link is invalid or the password is too short.';
    }
  }
  // Process request password reset form
  elseif (isset($_POST['email'])) {
    $email = strtolower(trim((string) $_POST['email']));

    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
      $token = bin2hex(random_bytes(32));
      $hash = hash('sha256', $token);
      $expires = date('Y-m-d H:i:s', time() + 3600);

      $insertStmt = $conn->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
      $insertStmt->execute([(int) $user['id'], $hash, $expires]);

      $message = 'Reset link: reset-password.php?token=' . $token;
    } else {
      $message = 'If the email exists, a reset link has been prepared.';
    }
  }
}
?><?php echo '<!doctype html>'; ?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ONISUBI | Reset password</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <main class="checkout-page">
        <div class="container">
            <section class="panel">
                <h2>Reset password</h2>
                <?php if ($message): ?>
                        <p><?= e($message) ?></p>
                <?php endif; ?>

                <?php if (isset($_GET['token'])): ?>
                        <form class="checkout-form" method="post">
                            <input type="hidden" name="token" value="<?= e((string) $_GET['token']) ?>">
                            <input type="password" name="new_password" minlength="8" placeholder="New password (8+ characters)" required>
                            <button class="btn btn-primary">Update password</button>
                        </form>
                <?php else: ?>
                        <form class="checkout-form" method="post">
                            <input type="email" name="email" placeholder="Email address" required>
                            <button class="btn btn-primary">Request reset</button>
                        </form>
                <?php endif; ?>
                
                <a class="text-link" href="auth.php">Back to account</a>
            </section>
        </div>
    </main>
</body>
</html>
