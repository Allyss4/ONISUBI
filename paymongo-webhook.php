<?php
require_once __DIR__ . '/config/bootstrap.php';
$payload = json_decode((string) file_get_contents('php://input'), true);
$event = $payload['data']['attributes']['type'] ?? '';
$resource = $payload['data']['attributes']['data'] ?? [];
$orderId = (int) ($resource['attributes']['metadata']['order_id'] ?? 0);
if ($orderId && in_array($event, ['checkout_session.payment.paid', 'payment.paid'], true)) {
    $stmt = $conn->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $status = 'Preparing';
    $stmt->bind_param('si', $status, $orderId);
    $stmt->execute();
}
http_response_code(200); echo json_encode(['received' => true]);