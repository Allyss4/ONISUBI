<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json');

$stocks = [];

try {
    if (isset($conn) && $conn instanceof PDO) {
        $stmt = $conn->query("
            SELECT p.id, p.name,
                   COALESCE(i.quantity, p.stock, 0)::int AS stock,
                   COALESCE(i.low_stock_limit, 5)::int AS low_stock_limit,
                   p.status
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id
            WHERE LOWER(p.status) <> 'deleted'
            ORDER BY p.id ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) $row['id'];
            $stocks[$pid] = [
                'id'              => $pid,
                'name'            => (string) $row['name'],
                'stock'           => (int) $row['stock'],
                'low_stock_limit' => (int) $row['low_stock_limit'],
                'status'          => (string) ($row['status'] ?? 'active'),
            ];
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

echo json_encode(['success' => true, 'stocks' => $stocks]);
exit;
