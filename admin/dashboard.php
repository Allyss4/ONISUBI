<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'dashboard';

$endDate = (string) ($_GET['end'] ?? date('Y-m-d'));
$startDate = (string) ($_GET['start'] ?? date('Y-m-d', strtotime('-29 days')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $endDate = date('Y-m-d');
if ($startDate > $endDate) [$startDate, $endDate] = [$endDate, $startDate];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 5;
$offset = ($page - 1) * $perPage;
$productCount = (int) ($conn->query("SELECT COUNT(*) AS total FROM products WHERE LOWER(status) <> 'deleted'")->fetch_assoc()['total'] ?? 0);
$metrics = $conn->prepare("SELECT COUNT(*) AS order_count, SUM(CASE WHEN LOWER(status) IN ('order placed','pending','confirmed','preparing','ready') THEN 1 ELSE 0 END) AS pending_count, SUM(CASE WHEN LOWER(status)='completed' THEN 1 ELSE 0 END) AS completed_count, COALESCE(SUM(CASE WHEN LOWER(status)='completed' THEN total_amount ELSE 0 END),0) AS sales FROM orders WHERE DATE(order_date) BETWEEN ? AND ?");
$metrics->bind_param('ss', $startDate, $endDate);
$metrics->execute();
$dashboardMetrics = $metrics->get_result()->fetch_assoc() ?: [];
$orderCount = (int) ($dashboardMetrics['order_count'] ?? 0);
$pendingCount = (int) ($dashboardMetrics['pending_count'] ?? 0);
$completedCount = (int) ($dashboardMetrics['completed_count'] ?? 0);
$totalSales = (float) ($dashboardMetrics['sales'] ?? 0);
$lowStockCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM inventory i JOIN products p ON p.id=i.product_id WHERE i.quantity <= i.low_stock_limit AND LOWER(p.status) <> \'deleted\'')->fetch_assoc()['total'] ?? 0);
$recentOrderCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM orders WHERE DATE(order_date) BETWEEN ? AND ?');
$recentOrderCountStmt->bind_param('ss', $startDate, $endDate);
$recentOrderCountStmt->execute();
$recentOrderTotal = (int) ($recentOrderCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int) ceil($recentOrderTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$recentOrdersStmt = $conn->prepare('SELECT o.id,o.total_amount,o.status,o.order_date,u.name AS customer_name FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE DATE(o.order_date) BETWEEN ? AND ? ORDER BY o.order_date DESC LIMIT ? OFFSET ?');
$recentOrdersStmt->bind_param('ssii', $startDate, $endDate, $perPage, $offset);
$recentOrdersStmt->execute();
$recentOrders = $recentOrdersStmt->get_result();
$lowStockProducts = $conn->query('SELECT p.name,i.quantity,i.low_stock_limit FROM inventory i JOIN products p ON p.id=i.product_id WHERE i.quantity <= i.low_stock_limit AND LOWER(p.status) <> \'deleted\' ORDER BY i.quantity ASC LIMIT 5');
require __DIR__ . '/_header.php';
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Overview</span><h1>Good day, <?= admin_escape($adminUser['name']) ?></h1><p>Here is what is happening at ONISUBI today.</p></div><div class="admin-heading-actions"><a class="admin-secondary-link" href="https://onisubi.onrender.com/" target="_blank" rel="noopener noreferrer">View Customer Website <span aria-hidden="true">↗</span></a><a class="admin-primary-link" href="orders.php">Review orders <span aria-hidden="true">→</span></a></div></section>
<section class="admin-stat-grid" aria-label="Store performance">
    <article class="admin-stat"><span>Total products</span><strong><?= $productCount ?></strong><small>Active menu items</small><i class="stat-mark stat-mark-coral"></i></article>
    <article class="admin-stat"><span>Total orders</span><strong><?= $orderCount ?></strong><small>All time</small><i class="stat-mark stat-mark-yellow"></i></article>
    <article class="admin-stat"><span>Pending orders</span><strong><?= $pendingCount ?></strong><small>Needs attention</small><i class="stat-mark stat-mark-green"></i></article>
    <article class="admin-stat"><span>Completed orders</span><strong><?= $completedCount ?></strong><small>Fulfilled orders</small><i class="stat-mark stat-mark-blue"></i></article>
    <article class="admin-stat"><span>Total sales</span><strong>₱<?= number_format($totalSales, 2) ?></strong><small>Completed orders</small><i class="stat-mark stat-mark-coral"></i></article>
    <article class="admin-stat <?= $lowStockCount ? 'stat-alert' : '' ?>"><span>Low stock products</span><strong><?= $lowStockCount ?></strong><small>At or below stock limit</small><i class="stat-mark stat-mark-yellow"></i></article>
</section>
<form class="admin-date-filter" method="get"><label>Start Date<input type="date" name="start" value="<?= admin_escape($startDate) ?>" required></label><label>End Date<input type="date" name="end" value="<?= admin_escape($endDate) ?>" required></label><button class="admin-button-primary" type="submit">Apply Date</button></form>
<div class="admin-dashboard-grid">
    <section class="admin-panel admin-dashboard-panel"><div class="admin-panel-heading"><div><span class="admin-eyebrow">Latest activity</span><h2>Recent orders</h2></div><a href="orders.php">View all</a></div>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th></tr></thead><tbody>
        <?php if ($recentOrders && $recentOrders->num_rows): while ($order = $recentOrders->fetch_assoc()): ?><tr><td><a class="admin-order-link" href="orders.php?id=<?= (int) $order['id'] ?>">#<?= (int) $order['id'] ?></a></td><td><?= admin_escape($order['customer_name'] ?? 'Guest') ?></td><td><?= admin_escape(date('M j, Y', strtotime((string) $order['order_date']))) ?></td><td>₱<?= number_format((float) $order['total_amount'], 2) ?></td><td><span class="admin-status status-<?= admin_escape(strtolower(str_replace(' ', '-', (string) $order['status']))) ?>"><?= admin_escape($order['status']) ?></span></td></tr><?php endwhile; else: ?><tr><td colspan="5" class="admin-empty">No orders yet.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?= admin_pagination($page, $totalPages, ['start' => $startDate, 'end' => $endDate]) ?>
    </section>
    <section class="admin-panel admin-dashboard-panel admin-inventory-watch"><div class="admin-panel-heading"><div><span class="admin-eyebrow">Inventory watch</span><h2>Running low</h2></div><a href="inventory.php">Manage stock</a></div>
        <div class="admin-low-stock-list"><?php if ($lowStockProducts && $lowStockProducts->num_rows): while ($product = $lowStockProducts->fetch_assoc()): ?><div class="admin-low-stock-row"><span class="stock-indicator" aria-hidden="true"></span><div><strong><?= admin_escape($product['name']) ?></strong><small>Limit: <?= (int) $product['low_stock_limit'] ?></small></div><b><?= (int) $product['quantity'] ?> left</b></div><?php endwhile; else: ?><p class="admin-empty">Everything is stocked up.</p><?php endif; ?></div>
    </section>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
