<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'logout') {
  session_destroy();
  header('Location: ../index.php');
  exit;
}

$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'account.php';
if (!in_array($redirect, ['account.php', 'checkout.php', 'pages/account.php', 'pages/checkout.php'], true)) {
    $redirect = 'account.php';
}
$msg = $_GET['msg'] ?? '';

// PostgreSQL Schema Check for 'password_hash' column
$columnCheckStmt = $conn->prepare("
    SELECT 1 
    FROM information_schema.columns 
    WHERE table_schema = 'public' 
      AND table_name = 'users' 
      AND column_name = 'password_hash'
");
$columnCheckStmt->execute();
$hasPasswordHash = (bool) $columnCheckStmt->fetchColumn();

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
      try {
        if ($hasPasswordHash) {
          $stmt = $conn->prepare('INSERT INTO users (name, email, password_hash, phone, address) VALUES (:name, :email, :hash, :phone, :address)');
        } else {
          $stmt = $conn->prepare('INSERT INTO users (name, email, password, phone, address) VALUES (:name, :email, :hash, :phone, :address)');
        }

        $stmt->execute([
          ':name' => $name,
          ':email' => $email,
          ':hash' => $hash,
          ':phone' => $phone,
          ':address' => $address
        ]);

        $_SESSION['user_id'] = (int) $conn->lastInsertId();
        header('Location: ' . $redirect);
        exit;
      } catch (PDOException $e) {
        // Unique constraint violation in PostgreSQL (SQLSTATE 23505)
        if ($e->getCode() === '23505') {
          $error = 'That email is already registered.';
        } else {
          $error = 'Registration failed: ' . $e->getMessage();
        }
      }
        }
    } elseif ($action === 'login') {
        $identifier = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($identifier === '' || (!filter_var($identifier, FILTER_VALIDATE_EMAIL) && !preg_match('/^[A-Za-z0-9_.-]+$/', $identifier))) {
            $error = 'Enter a valid email address or username.';
        } else {
      $stmt = $conn->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :identifier OR name = :identifier LIMIT 1');
      $stmt->execute([':identifier' => $identifier]);
      $user = $stmt->fetch();

            $submittedPassword = (string) ($_POST['password'] ?? '');
            $storedHash = $user['password_hash'] ?? '';
            $passwordMatches = false;

      if ($storedHash !== '' && password_verify($submittedPassword, $storedHash)) {
        $passwordMatches = true;
      }

      if ($user && $passwordMatches) {
        $_SESSION['user_id'] = (int) $user['id'];
        header('Location: ' . $redirect);
        exit;
      }
            $error = 'Email or password is incorrect.';
        }
    }
}
?>
