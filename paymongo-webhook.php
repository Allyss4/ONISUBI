<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
$event = $payload['data']['attributes']['type'] ?? '';
$resource = $payload['data']['attributes']['data'] ?? [];
$orderId = (int) ($resource['attributes']['metadata']['order_id'] ?? 0);

if ($orderId && in_array($event, ['checkout_session.payment.paid', 'payment.paid'], true)) {
  if (isset($conn) && $conn instanceof PDO) {
    $stmt = $conn->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $status = 'Preparing';
    $stmt->execute([$status, $orderId]);
  }
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode(['received' => true]);