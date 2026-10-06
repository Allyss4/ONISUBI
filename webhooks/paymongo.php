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
$paymentId = (string) ($data['id'] ?? '');
$checkoutSessionId = (string) ($payload['data']['attributes']['data']['id'] ?? '');

$orderId = $orderIdFromMetadata;
if ($orderId <= 0 && ($referenceNumber !== '' || $checkoutSessionId !== '')) {
    $refStmt = $conn->prepare("SELECT id FROM orders WHERE (NULLIF(:ref, '') IS NOT NULL AND payment_reference = :ref) OR (NULLIF(:cid, '') IS NOT NULL AND paymongo_checkout_id = :cid) LIMIT 1");
    $refStmt->execute([':ref' => $referenceNumber, ':cid' => $checkoutSessionId]);
    $matched = (int) $refStmt->fetchColumn();
    if ($matched > 0) {
        $orderId = $matched;
    } elseif (preg_match('/-(\d+)$/', $referenceNumber, $matches)) {
        $orderId = (int) $matches[1];
    }
}

// Fallback to paymentId if referenceNumber is not provided
if ($referenceNumber === '' && $paymentId !== '') {
    $referenceNumber = $paymentId;
}

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['received' => false, 'error' => 'No order found in webhook payload']);
    exit;
}

$stmt = $conn->prepare('SELECT id, status, payment_method, payment_reference, paymongo_checkout_id FROM orders WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo json_encode(['received' => false, 'error' => 'Order not found']);
    exit;
}

$method = strtolower((string) ($order['payment_method'] ?? ''));
if (!in_array($method, ['paymongo', 'paymongo checkout', 'gcash qr', 'gcash'], true) && strpos($method, 'paymongo') === false) {
    http_response_code(200);
    echo json_encode(['received' => true, 'status' => 'ignored_non_paymongo']);
    exit;
}

if (strtolower((string) $order['status']) === 'paid' || strtolower((string) $order['status']) === 'order placed') {
    // If already marked paid but reference was missing, ensure reference is saved for admins
    if (($referenceNumber !== '' && empty($order['payment_reference'])) || ($checkoutSessionId !== '' && empty($order['paymongo_checkout_id']))) {
        $upRef = $conn->prepare('UPDATE orders SET payment_reference = COALESCE(NULLIF(:pref, \'\'), payment_reference), paymongo_checkout_id = COALESCE(NULLIF(:cid, \'\'), paymongo_checkout_id) WHERE id = :id');
        $upRef->execute([':pref' => $referenceNumber, ':cid' => $checkoutSessionId, ':id' => $orderId]);
    }
    http_response_code(200);
    echo json_encode(['received' => true, 'status' => 'already_paid']);
    exit;
}

try {
    $conn->beginTransaction();

    $paidStatus = 'Order Placed';
    $updateStmt = $conn->prepare('
        UPDATE orders 
        SET status = :status, 
            payment_status = :pstatus,
            payment_reference = COALESCE(NULLIF(:pref, \'\'), payment_reference),
            paymongo_checkout_id = COALESCE(NULLIF(:cid, \'\'), paymongo_checkout_id)
        WHERE id = :id
    ');
    $updateStmt->execute([
        ':status'  => $paidStatus,
        ':pstatus' => 'paid',
        ':pref'    => $referenceNumber,
        ':cid'     => $checkoutSessionId,
        ':id'      => $orderId,
    ]);

    $itemStmt = $conn->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :id');
    $itemStmt->execute([':id' => $orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $quantity = (int) ($item['quantity'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }

        $inventoryStmt = $conn->prepare('UPDATE inventory SET quantity = GREATEST(0, quantity - :qty), updated_at = CURRENT_TIMESTAMP WHERE product_id = :id');
        $inventoryStmt->execute([':qty' => $quantity, ':id' => $productId]);

        $stockStmt = $conn->prepare('UPDATE products SET stock = GREATEST(0, stock - :qty) WHERE id = :id');
        $stockStmt->execute([':qty' => $quantity, ':id' => $productId]);
    }

    $conn->commit();
    http_response_code(200);
    echo json_encode(['received' => true, 'status' => 'paid']);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['received' => false, 'error' => $e->getMessage()]);
}
