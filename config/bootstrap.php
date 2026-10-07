<?php
declare(strict_types=1);

// Load Composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// initialize and load the .env file from the project root
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supabase.php';
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

function is_admin(?int $userId = null): bool
{
    $uid = $userId ?? current_user_id();
    if (!$uid) {
        return false;
    }
    if (isset($_SESSION['role']) && in_array(strtolower((string) $_SESSION['role']), ['admin', 'staff'], true)) {
        return true;
    }
    if (isset($GLOBALS['conn'])) {
        $stmt = $GLOBALS['conn']->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $uid]);
        $role = strtolower((string) ($stmt->fetchColumn() ?: ''));
        return in_array($role, ['admin', 'staff'], true);
    }
    return false;
}


function require_login(string $redirect = 'account.php', string $message = ''): int {
    $id = current_user_id(); 
    if (!$id) { 
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $isInPages = (strpos($scriptName, '/pages/') !== false);
        $authUrl = $isInPages ? 'auth.php' : 'pages/auth.php';
        $url = $authUrl . '?redirect=' . urlencode($redirect);
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

/**
 * Maps any system order status string to customer tracking flow stages.
 * Stages: ['Order Placed', 'Preparing', 'Out for Delivery', 'Delivered']
 */
function map_order_status_to_tracking(?string $rawStatus): array
{
    $clean = trim((string) $rawStatus);
    $lower = strtolower($clean);

    // Cancelled / Rejected
    if (in_array($lower, ['cancelled', 'canceled', 'rejected'], true)) {
        return [
            'stage'        => 'Cancelled',
            'index'        => -1,
            'is_cancelled' => true,
            'raw_status'   => $clean ?: 'Cancelled',
            'status_label' => 'Cancelled',
            'badge_class'  => 'badge-cancelled',
        ];
    }

    // Delivered / Completed
    if (in_array($lower, ['delivered', 'completed'], true)) {
        return [
            'stage'        => 'Delivered',
            'index'        => 3,
            'is_cancelled' => false,
            'raw_status'   => $clean ?: 'Delivered',
            'status_label' => ($lower === 'completed') ? 'Completed' : 'Delivered',
            'badge_class'  => 'badge-completed',
        ];
    }

    // Out for Delivery / In Transit / Dispatched
    if (in_array($lower, ['out for delivery', 'out_for_delivery', 'in transit', 'on the way', 'dispatched'], true)) {
        return [
            'stage'        => 'Out for Delivery',
            'index'        => 2,
            'is_cancelled' => false,
            'raw_status'   => $clean ?: 'Out for Delivery',
            'status_label' => 'Out for Delivery',
            'badge_class'  => 'badge-delivery',
        ];
    }

    // Preparing / Ready / Processing / In Kitchen
    if (in_array($lower, ['preparing', 'ready', 'processing', 'in kitchen', 'cooking'], true)) {
        return [
            'stage'        => 'Preparing',
            'index'        => 1,
            'is_cancelled' => false,
            'raw_status'   => $clean ?: 'Preparing',
            'status_label' => ($lower === 'ready') ? 'Ready' : 'Preparing',
            'badge_class'  => 'badge-preparing',
        ];
    }

    // Order Placed / Confirmed / Pending / For Verification / Awaiting Payment
    return [
        'stage'        => 'Order Placed',
        'index'        => 0,
        'is_cancelled' => false,
        'raw_status'   => $clean ?: 'Order Placed',
        'status_label' => $clean ?: 'Order Placed',
        'badge_class'  => 'badge-placed',
    ];
}