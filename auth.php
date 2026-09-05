<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'logout') { session_destroy(); header('Location: index.php'); exit; }

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
        if ($name === '' || strlen($password) < 8) {
            $error = 'Use your name and a password with at least 8 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hasPasswordHash) {
                $stmt = $conn->prepare('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)');
                $stmt->bind_param('sss', $name, $email, $hash);
            } else {
                $stmt = $conn->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)');
                $stmt->bind_param('sss', $name, $email, $hash);
            }
            if ($stmt->execute()) { $_SESSION['user_id'] = $stmt->insert_id; header('Location: account.php'); exit; }
            $error = 'That email is already registered.';
        }
    } elseif ($action === 'login') {
        $identifier = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($identifier === '' || !filter_var($identifier, FILTER_VALIDATE_EMAIL) && !preg_match('/^[A-Za-z0-9_.-]+$/', $identifier)) {
            $error = 'Enter a valid email address or username.';
        } else {
            $stmt = $conn->prepare('SELECT id,name,email,password_hash,password FROM users WHERE email=? OR name=? LIMIT 1');
            $stmt->bind_param('ss', $identifier, $identifier);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            $submittedPassword = (string) ($_POST['password'] ?? '');
            $storedHash = $user['password_hash'] ?? '';
            $storedPassword = $user['password'] ?? '';
            $passwordMatches = false;
            if ($storedHash !== '' && password_verify($submittedPassword, $storedHash)) { $passwordMatches = true; }
            elseif ($storedPassword !== '' && (password_verify($submittedPassword, $storedPassword) || hash_equals($storedPassword, $submittedPassword))) { $passwordMatches = true; }

            if ($user && $passwordMatches) { $_SESSION['user_id'] = (int) $user['id']; header('Location: account.php'); exit; }
            $error = 'Email or password is incorrect.';
        }
    }
}
?><!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ONISUBI | Account</title><link rel="stylesheet" href="assets/css/style.css"></head><body><main class="checkout-page"><div class="container checkout-layout"><section class="panel"><h2>Customer account</h2><?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?><form class="checkout-form" method="post"><input type="hidden" name="action" value="login"><input type="text" name="email" placeholder="Email or username" value="<?= e($_POST['email'] ?? '') ?>" required><input type="password" name="password" placeholder="Password" required><button class="btn btn-primary">Log in</button></form><p><a class="text-link" href="reset-password.php">Forgot or reset password?</a></p></section><section class="panel"><h2>Create account</h2><form class="checkout-form" method="post"><input type="hidden" name="action" value="register"><input name="name" placeholder="Full name" required><input type="email" name="email" placeholder="Email address" required><input type="password" name="password" minlength="8" placeholder="Password (8+ characters)" required><button class="btn btn-secondary">Create account</button></form></section></div><div class="container"><p><a class="text-link" href="index.php">Back to Home</a></p></div></main></body></html>