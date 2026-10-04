<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$userId = current_user_id();
if (!$userId) {
    header('Location: login.php');
    exit;
}

const MAX_PROOF_BYTES = 5 * 1024 * 1024; // 5 MB
const ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

function back_with_error(int $orderId, string $message): void
{
    header('Location: gcash-payment.php?id=' . $orderId . '&error=' . urlencode($message));
    exit;
}

$orderId   = (int) ($_POST['order_id'] ?? 0);
$reference = trim((string) ($_POST['reference'] ?? ''));

// 1. The order must exist, belong to this user, and still be unpaid.
$stmt = $conn->prepare('
    SELECT id, status, payment_method, payment_proof
    FROM orders
    WHERE id = :id AND user_id = :uid
    LIMIT 1
');
$stmt->execute([':id' => $orderId, ':uid' => $userId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}
if ($order['payment_method'] !== 'GCash QR') {
    back_with_error($orderId, 'This order does not use GCash.');
}
if (!in_array($order['status'], ['Awaiting Payment', 'Payment Rejected'], true)) {
    back_with_error($orderId, 'Payment proof was already submitted for this order.');
}

// 2. Validate the reference number (GCash refs are digits, usually 13).
if (!preg_match('/^[0-9]{10,20}$/', $reference)) {
    back_with_error($orderId, 'Enter a valid GCash reference number (digits only).');
}

// 3. Validate the uploaded file.
$file = $_FILES['proof'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    back_with_error($orderId, 'Please choose a screenshot to upload.');
}
if ($file['size'] > MAX_PROOF_BYTES) {
    back_with_error($orderId, 'Image is too large (max 5 MB).');
}
if (!is_uploaded_file($file['tmp_name'])) {
    back_with_error($orderId, 'Invalid upload.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!isset(ALLOWED_MIME[$mime]) || @getimagesize($file['tmp_name']) === false) {
    back_with_error($orderId, 'Only JPG, PNG, or WEBP images are allowed.');
}

// 4. Save with a server-generated name (never trust the original filename).
$dir = __DIR__ . '/../uploads/proofs';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    back_with_error($orderId, 'Could not save the file. Please try again.');
}

$filename = 'order' . $orderId . '_' . bin2hex(random_bytes(8)) . '.' . ALLOWED_MIME[$mime];
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
    back_with_error($orderId, 'Could not save the file. Please try again.');
}

// 5. Update the order and remove any previous rejected proof.
$update = $conn->prepare('
    UPDATE orders
    SET payment_reference = :ref,
        payment_proof     = :proof,
        status            = :status,
        proof_uploaded_at = CURRENT_TIMESTAMP
    WHERE id = :id AND user_id = :uid
');
$update->execute([
    ':ref'    => $reference,
    ':proof'  => $filename,
    ':status' => 'For Verification',
    ':id'     => $orderId,
    ':uid'    => $userId,
]);

if (!empty($order['payment_proof'])) {
    $old = $dir . '/' . basename((string) $order['payment_proof']);
    if (is_file($old)) {
        @unlink($old);
    }
}

header('Location: order-status.php?id=' . $orderId . '&proof=submitted');
exit;
