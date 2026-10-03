<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'orders';
$statuses = ['Order Placed', 'Pending', 'Confirmed', 'Preparing', 'Ready', 'Completed', 'Cancelled'];
$page = max(1, (int) ($_GET['page'] ?? $_POST['page'] ?? 1));
$perPage = 10;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'status') {
    admin_verify_csrf();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $newStatus = (string) ($_POST['status'] ?? '');
    if (!in_array($newStatus, $statuses, true)) {
        $error = 'Select a valid order status.';
    } else {
        $conn->begin_transaction();
        $lock = $conn->prepare('SELECT status FROM orders WHERE id=? FOR UPDATE');
        $lock->bind_param('i', $orderId);
        $lock->execute();
        $order = $lock->get_result()->fetch_assoc();
        $previousStatus = (string) ($order['status'] ?? '');
        if (!$order) {
            $error = 'Order not found.';
        } elseif (in_array(strtolower($previousStatus), ['completed', 'cancelled'], true) && strcasecmp($previousStatus, $newStatus) !== 0) {
            $error = 'Completed and cancelled orders cannot be reopened.';
        } elseif (strcasecmp($previousStatus, $newStatus) !== 0) {
            if ($newStatus === 'Completed') {
                $itemsStmt = $conn->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=?');
                $itemsStmt->bind_param('i', $orderId);
                $itemsStmt->execute();
                $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($items as $item) {
                    $productId = (int) $item['product_id'];
                    $quantity = (int) $item['quantity'];
                    $stockStmt = $conn->prepare('SELECT COALESCE(i.quantity,p.stock) AS available FROM products p LEFT JOIN inventory i ON i.product_id=p.id WHERE p.id=? FOR UPDATE');
                    $stockStmt->bind_param('i', $productId);
                    $stockStmt->execute();
                    $stock = $stockStmt->get_result()->fetch_assoc();
                    if (!$stock || (int) $stock['available'] < $quantity) {
                        $error = 'Not enough stock to complete this order. Restock the product and try again.';
                        break;
                    }
                    $remaining = (int) $stock['available'] - $quantity;
                    $inventoryUpdate = $conn->prepare('UPDATE inventory SET quantity=? WHERE product_id=?');
                    $inventoryUpdate->bind_param('ii', $remaining, $productId);
                    $inventoryUpdate->execute();
                    if ($inventoryUpdate->affected_rows === 0) {
                        $inventoryInsert = $conn->prepare('INSERT INTO inventory (product_id,quantity,low_stock_limit) VALUES (?,?,5)');
                        $inventoryInsert->bind_param('ii', $productId, $remaining);
                        $inventoryInsert->execute();
                    }
                    $productUpdate = $conn->prepare('UPDATE products SET stock=? WHERE id=?');
                    $productUpdate->bind_param('ii', $remaining, $productId);
                    $productUpdate->execute();
                }
            }
            if ($error === '') {
                $update = $conn->prepare('UPDATE orders SET status=? WHERE id=?');
                $update->bind_param('si', $newStatus, $orderId);
                if ($update->execute()) {
                    $message = 'Order status updated.';
                } else {
                    $error = 'Unable to update order status.';
                }
            }
        }
        if ($error === '') {
            $conn->commit();
        } else {
            $conn->rollback();
        }
    }
}

$selectedId = (int) ($_GET['id'] ?? 0);
$selectedOrder = null;
$orderItems = null;
if ($selectedId > 0) {
    $detail = $conn->prepare('SELECT o.*,u.name AS customer_name,u.email,u.phone,u.address FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE o.id=?');
    $detail->bind_param('i', $selectedId);
    $detail->execute();
    $selectedOrder = $detail->get_result()->fetch_assoc();
    if ($selectedOrder) {
        $itemsStmt = $conn->prepare('SELECT oi.product_id,oi.quantity,oi.price,p.name AS product_name FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
        $itemsStmt->bind_param('i', $selectedId);
        $itemsStmt->execute();
        $orderItems = $itemsStmt->get_result();
    }
}
$orderTotal = (int) ($conn->query('SELECT COUNT(*) AS total FROM orders')->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int) ceil($orderTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$orderRowsStmt = $conn->prepare('SELECT o.id,o.total_amount,o.status,o.payment_method,o.order_date,o.order_note,u.name AS customer_name FROM orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.order_date DESC LIMIT ? OFFSET ?');
$orderRowsStmt->bind_param('ii', $perPage, $offset);
$orderRowsStmt->execute();
$orderRows = $orderRowsStmt->get_result();
require __DIR__ . '/_header.php';
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Fulfillment</span><h1>Orders</h1><p>Track customer orders from confirmation through completion.</p></div></section>
<?php if ($message): ?><p class="admin-alert admin-alert-success"><?= admin_escape($message) ?></p><?php endif; ?><?php if ($error): ?><p class="admin-alert admin-alert-error"><?= admin_escape($error) ?></p><?php endif; ?>
<?php if ($selectedOrder): ?><section class="admin-panel admin-order-detail"><div class="admin-panel-heading"><div><span class="admin-eyebrow">Order #<?= (int) $selectedOrder['id'] ?></span><h2>Customer and order details</h2></div><a href="orders.php?page=<?= $page ?>">Close details</a></div><div class="admin-detail-grid"><div><span>Customer</span><strong><?= admin_escape($selectedOrder['customer_name'] ?? 'Customer') ?></strong></div><div><span>Email</span><strong><?= admin_escape($selectedOrder['email'] ?? 'Not provided') ?></strong></div><div><span>Phone</span><strong><?= admin_escape($selectedOrder['phone'] ?? 'Not provided') ?></strong></div><div><span>Address</span><strong><?= admin_escape($selectedOrder['address'] ?? 'Not provided') ?></strong></div><div><span>Payment method</span><strong><?= admin_escape($selectedOrder['payment_method'] ?? 'Not recorded') ?></strong></div><div><span>Order date</span><strong><?= admin_escape(date('M j, Y H:i', strtotime((string) $selectedOrder['order_date']))) ?></strong></div><div><span>Customer note</span><strong><?= trim((string) ($selectedOrder['order_note'] ?? '')) !== '' ? nl2br(admin_escape($selectedOrder['order_note'])) : 'No note' ?></strong></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Product</th><th>Quantity</th><th>Unit price</th><th>Subtotal</th></tr></thead><tbody><?php if ($orderItems && $orderItems->num_rows): while ($item = $orderItems->fetch_assoc()): ?><tr><td><?= admin_escape($item['product_name'] ?? 'Removed product') ?></td><td><?= (int) $item['quantity'] ?></td><td>₱<?= number_format((float) $item['price'], 2) ?></td><td>₱<?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?></td></tr><?php endwhile; endif; ?><tr><td colspan="3"><strong>Order total</strong></td><td><strong>₱<?= number_format((float) $selectedOrder['total_amount'], 2) ?></strong></td></tr></tbody></table></div></section><?php endif; ?>
<section class="admin-panel"><div class="admin-panel-heading"><div><span class="admin-eyebrow">Order book</span><h2>All orders</h2></div><span class="admin-count-pill"><?= $orderTotal ?> orders</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Note</th><th>Payment</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if ($orderRows && $orderRows->num_rows): while ($order = $orderRows->fetch_assoc()): ?><tr><td><a class="admin-order-link" href="orders.php?id=<?= (int) $order['id'] ?>&page=<?= $page ?>">#<?= (int) $order['id'] ?></a></td><td><?= admin_escape($order['customer_name'] ?? 'Customer') ?></td><td><?= admin_escape(date('M j, Y H:i', strtotime((string) $order['order_date']))) ?></td><td class="admin-order-note"><?= trim((string) ($order['order_note'] ?? '')) !== '' ? nl2br(admin_escape($order['order_note'])) : 'No note' ?></td><td><?= admin_escape($order['payment_method'] ?? 'Not recorded') ?></td><td>₱<?= number_format((float) $order['total_amount'], 2) ?></td><td><span class="admin-status status-<?= admin_escape(strtolower(str_replace(' ', '-', (string) $order['status']))) ?>"><?= admin_escape($order['status']) ?></span></td><td><form class="admin-inline-form" method="post"><?= admin_csrf_field() ?><input type="hidden" name="page" value="<?= $page ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><label class="admin-sr-only" for="order-status-<?= (int) $order['id'] ?>">Status for order <?= (int) $order['id'] ?></label><select id="order-status-<?= (int) $order['id'] ?>" name="status"><?php foreach ($statuses as $status): ?><option value="<?= admin_escape($status) ?>" <?= strcasecmp((string) $order['status'], $status) === 0 ? 'selected' : '' ?>><?= admin_escape($status) ?></option><?php endforeach; ?></select><button type="submit" class="admin-button-small">Save</button></form></td></tr><?php endwhile; else: ?><tr><td colspan="8" class="admin-empty">No orders have been placed yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?= admin_pagination($page, $totalPages) ?>
<?php require __DIR__ . '/_footer.php'; ?>
