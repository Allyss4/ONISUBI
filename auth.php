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
?>