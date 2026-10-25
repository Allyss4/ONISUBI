<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'dashboard';

// Fetch metrics using Supabase RPC or direct query
$dashboardMetrics = supabase_call_rpc($conn, 'get_admin_dashboard_metrics', [
    'start_date' => date('Y-m-d', strtotime('-30 days')),
    'end_date'   => date('Y-m-d'),
]) ?: [];

$productCount   = (int) ($dashboardMetrics['products'] ?? 0);
$orderCount     = (int) ($dashboardMetrics['orders'] ?? 0);
$pendingCount   = (int) ($dashboardMetrics['pending_count'] ?? $dashboardMetrics['pending'] ?? 0);
$totalSales     = (float) ($dashboardMetrics['sales'] ?? 0);
$customerCount  = (int) ($dashboardMetrics['customers'] ?? 0);

if ($customerCount === 0) {
    $custStmt = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'customer'");
    $customerCount = (int) $custStmt->fetchColumn();
}

// Fetch recent 5 orders via Supabase RPC: get_admin_orders
$recentOrders = supabase_call_rpc_table($conn, 'get_admin_orders', [
    'limit' => 5,
]);

// Fetch products with inventory via Supabase RPC: get_products_with_inventory
$allInventory = supabase_call_rpc_table($conn, 'get_products_with_inventory');
$lowStockProducts = array_filter($allInventory, function ($p) {
    return (int) ($p['inventory_quantity'] ?? 0) <= (int) ($p['low_stock_limit'] ?? 5);
});
$lowStockProducts = array_slice($lowStockProducts, 0, 5);

// Favicon configuration for admin dashboard
$pageFavicon = 'assets/images/Onisubi_Logo.png';
require __DIR__ . '/_header.php';
?>
<div class="title">
    <div>
        <div class="eyebrow">Admin Panel</div>
        <h1>Dashboard</h1>
    </div>
    <div style="font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 12px;">
        <span>Welcome back, <strong><?= admin_escape($adminUser['name']) ?></strong></span>
       
    </div>
</div>

<div class="cards">
    <div class="card">
        <small>Total Sales</small>
        <div class="metric" id="stat-sales">₱<?= number_format($totalSales, 2) ?></div>
    </div>
    <div class="card">
        <small>Total Orders</small>
        <div class="metric" id="stat-orders"><?= $orderCount ?></div>
    </div>
    <div class="card">
        <small>Pending Orders</small>
        <div class="metric" id="stat-pending"><?= $pendingCount ?></div>
    </div>
    <div class="card">
        <small>Customers</small>
        <div class="metric" id="stat-customers"><?= $customerCount ?></div>
    </div>
</div>

<div class="grid2">
    <section class="panel">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="margin: 0;">Recent Orders</h2>
            <a href="orders.php" style="font-size: 13px; color: var(--orange); font-weight: 700;">View All →</a>
        </div>
        <div class="tablewrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>View</th>
                    </tr>
                </thead>
                <tbody id="recent-orders">
                <?php if (!empty($recentOrders)): foreach ($recentOrders as $o): ?>
                    <tr>
                        <td>#<?= (int) $o['id'] ?></td>
                        <td><?= admin_escape($o['customer_name'] ?? 'Guest') ?></td>
                        <td>
                            <?php
                            $st = strtolower((string) ($o['status'] ?? 'pending'));
                            $badgeClass = in_array($st, ['completed', 'delivered'], true) ? 'completed' : 'pending';
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= admin_escape($o['status']) ?></span>
                        </td>
                        <td><a class="eye" href="orders.php?id=<?= (int) $o['id'] ?>" title="View order">◉</a></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="empty">No orders yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="margin: 0;">Inventory Watch</h2>
            <a href="inventory.php" style="font-size: 13px; color: var(--orange); font-weight: 700;">Manage Stock →</a>
        </div>
        <div class="tablewrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="inventory-watch">
                <?php if (!empty($lowStockProducts)): foreach ($lowStockProducts as $p): ?>
                    <?php
                    $qty = (int) ($p['inventory_quantity'] ?? $p['quantity'] ?? $p['stock'] ?? 0);
                    $limit = (int) ($p['low_stock_limit'] ?? 5);
                    $isLow = $qty <= $limit;
                    ?>
                    <tr>
                        <td><?= admin_escape($p['name']) ?></td>
                        <td><strong><?= $qty ?></strong></td>
                        <td>
                            <span class="badge <?= $isLow ? 'pending' : 'completed' ?>">
                                <?= $isLow ? ($qty <= 0 ? 'Out of Stock' : 'Low Stock') : 'In Stock' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="3" class="empty">No inventory yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
