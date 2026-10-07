<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$userId = current_user_id();
if (!$userId) {
    json_response(['error' => 'Unauthorized'], 401);
}

$trackId = (int) ($_GET['track_id'] ?? 0);

$stmt = $conn->prepare('
    SELECT id, total, status, payment_method, payment_status, payment_reference, order_date 
    FROM orders 
    WHERE user_id = :uid 
    ORDER BY order_date DESC, id DESC
');
$stmt->execute([':uid' => $userId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$currentOrder = null;
if ($trackId > 0) {
    foreach ($orders as $o) {
        if ((int) $o['id'] === $trackId) {
            $currentOrder = $o;
            break;
        }
    }
}

if (!$currentOrder && !empty($orders)) {
    foreach ($orders as $o) {
        $st = strtolower(trim((string) ($o['status'] ?? '')));
        if (!in_array($st, ['delivered', 'completed', 'cancelled', 'canceled', 'rejected'], true)) {
            $currentOrder = $o;
            break;
        }
    }
    if (!$currentOrder) {
        $currentOrder = $orders[0];
    }
}

$tracking = $currentOrder ? map_order_status_to_tracking((string) ($currentOrder['status'] ?? '')) : null;
if ($tracking && $currentOrder) {
    $tracking['order_id'] = (int) $currentOrder['id'];
}

json_response([
    'success' => true,
    'current_order' => $currentOrder,
    'tracking' => $tracking,
    'orders' => $orders,
]);
