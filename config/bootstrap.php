<?php
declare(strict_types=1);

// Load Composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Initialize and load the .env file from the project root
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paymongo.php';

function e(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function current_user_id(): ?int
{
  return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function require_login(string $redirect = 'pages/account.php', string $message = ''): int {
    $id = current_user_id(); 
    if (!$id) { 
        $url = 'pages/auth.php?redirect=' . urlencode($redirect);
        if ($message !== '') {
            $url .= '&msg=' . urlencode($message);
        }
        header('Location: ' . $url); 
        exit; 
    } 
    return $id; 
}

function cart_from_request(): array {
    $items = json_decode((string) ($_POST['cart'] ?? '[]'), true);
    return is_array($items) ? $items : [];
}

function paymongo_request(string $method, string $path, ?array $body = null): array {
    $secret = paymongo_secret_key();
  if ($secret === '') {
    throw new RuntimeException('PAYMONGO_SECRET_KEY is not configured.');
  }

  $ch = curl_init(paymongo_api_base_url() . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_USERPWD => $secret . ':',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30,
    ]);
    
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode((string) $response, true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
    $message = is_array($data) && isset($data['errors'][0]['detail'])
      ? (string) $data['errors'][0]['detail']
      : 'PayMongo request failed.';
        throw new RuntimeException($message);
    }
    
    return $data;
}