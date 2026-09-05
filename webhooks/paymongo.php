<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/paymongo.php';

header('Content-Type: application/json');

function paymongo_validate_webhook_signature(string $payload, string $signatureHeader): bool
{
    $secret = paymongo_webhook_secret();
    if ($secret === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signatureHeader);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    echo json_encode(['received' => false, 'error' => 'Empty payload']);
    exit;
}

$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? $_SERVER['HTTP_X_PAYMONGO_SIGNATURE'] ?? $_SERVER['HTTP_PAYMONGO_SIG'] ?? '';
if ($signature === '') {
    http_response_code(401);
    echo json_encode(['received' => false, 'error' => 'Missing PayMongo signature']);
    exit;
}

if (!paymongo_validate_webhook_signature($rawBody, $signature)) {
    http_response_code(401);
    echo json_encode(['received' => false, 'error' => 'Invalid signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['received' => false, 'error' => 'Invalid JSON']);
    exit;
}

$eventType = $payload['data']['attributes']['type'] ?? '';
if ($eventType !== 'checkout_session.payment.paid') {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => true]);
    exit;
}

$data = $payload['data']['attributes']['data'] ?? [];
$orderIdFromMetadata = (int) ($data['attributes']['metadata']['order_id'] ?? 0);
$referenceNumber = (string) ($data['attributes']['reference_number'] ?? '');

$orderId = $orderIdFromMetadata;
if ($orderId <= 0 && $referenceNumber !== '') {
    $orderId = (int) preg_replace('/[^0-9]/', '', $referenceNumber);
}

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['received' => false, 'error' => 'No order found in webhook payload']);
    exit;
}

$stmt = $conn->prepare('SELECT id, status, payment_method FROM orders WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    http_response_code(404);
    echo json_encode(['received' => false, 'error' => 'Order not found']);
    exit;
}

if (strtolower((string) $order['status']) === 'paid') {
    http_response_code(200);
    echo json_encode(['received' => true, 'status' => 'already_paid']);
    exit;
}

if (strtolower((string) $order['payment_method']) !== 'paymongo') {
    http_response_code(200);
    echo json_encode(['received' => true, 'status' => 'ignored_non_paymongo']);
    exit;
}

$conn->begin_transaction();

$paidStatus = 'Order Placed';
$stmt = $conn->prepare('UPDATE orders SET status = ? WHERE id = ? AND status = ?');
$stmt->bind_param('ssi', $paidStatus, $orderId, $paidStatus);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    $conn->rollback();
    http_response_code(200);
    echo json_encode(['received' => true, 'status' => 'duplicate']);
    exit;
}

$itemStmt = $conn->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($items as $item) {
    $productId = (int) ($item['product_id'] ?? 0);
    $quantity = (int) ($item['quantity'] ?? 0);
    if ($productId <= 0 || $quantity <= 0) {
        continue;
    }

    $inventoryStmt = $conn->prepare('UPDATE inventory SET quantity = quantity - ? WHERE product_id = ?');
    $inventoryStmt->bind_param('ii', $quantity, $productId);
    $inventoryStmt->execute();

    $stockStmt = $conn->prepare('UPDATE Products SET stock = stock - ? WHERE id = ?');
    $stockStmt->bind_param('ii', $quantity, $productId);
    $stockStmt->execute();
}

$conn->commit();
http_response_code(200);
echo json_encode(['received' => true, 'status' => 'paid']);
