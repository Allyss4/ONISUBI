<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';

$user = admin_require_user();

$stats = $conn->query("
    SELECT
        COALESCE(SUM(total) FILTER (WHERE status IN ('Order Placed', 'Preparing', 'Out for Delivery', 'Delivered')), 0) AS sales,
        COUNT(*) AS orders,
        COUNT(*) FILTER (WHERE status NOT IN ('Delivered', 'Cancelled', 'Payment Rejected', 'Awaiting Payment')) AS pending,
        COUNT(*) FILTER (WHERE status = 'For Verification') AS to_verify,
        COUNT(*) FILTER (WHERE status = 'Cancelled' AND payment_status = 'paid' AND payment_method <> 'Cash on Delivery') AS refunds
    FROM orders
")->fetch();

$customerCount = (int) $conn->query("SELECT COUNT(*) FROM users WHERE COALESCE(role, 'customer') NOT IN ('admin', 'staff')")->fetchColumn();

$recentOrders = $conn->query('
    SELECT id, customer_name, status
    FROM orders
    ORDER BY order_date DESC NULLS LAST, id DESC
    LIMIT 5
')->fetchAll();

$watch = $conn->query('
    SELECT p.name,
           COALESCE(i.quantity, p.stock, 0) AS stock,
           COALESCE(i.low_stock_limit, 5) AS stock_limit
    FROM products p
    LEFT JOIN inventory i ON i.product_id = p.id
    ORDER BY COALESCE(i.quantity, p.stock, 0) ASC, p.name
    LIMIT 5
')->fetchAll();

admin_header('Dashboard', 'index.php', $user);
?>
        <?php if ((int) $stats['refunds'] > 0): ?>
          <div class="notice error">
            <?= (int) $stats['refunds'] ?> cancelled order(s) were already paid and need a refund in PayMongo.
            <a href="orders.php?refund=1"><b>View</b></a>
          </div>
        <?php endif; ?>
        <?php if ((int) $stats['to_verify'] > 0): ?>
          <div class="notice">
            <?= (int) $stats['to_verify'] ?> GCash payment(s) waiting for verification.
            <a href="orders.php?status=<?= urlencode('For Verification') ?>"><b>Review now</b></a>
          </div>
        <?php endif; ?>

        <div class="cards">
          <div class="card">
            <small>Total Sales</small>
            <div class="metric"><?= h(admin_peso($stats['sales'])) ?></div>
          </div>
          <div class="card">
            <small>Total Orders</small>
            <div class="metric"><?= (int) $stats['orders'] ?></div>
          </div>
          <div class="card">
            <small>Pending Orders</small>
            <div class="metric"><?= (int) $stats['pending'] ?></div>
          </div>
          <div class="card">
            <small>Customers</small>
            <div class="metric"><?= $customerCount ?></div>
          </div>
        </div>

        <div class="grid2">
          <section class="panel">
            <h2>Recent Orders</h2>
            <table class="table">
              <thead>
                <tr><th>Order ID</th><th>Name</th><th>Status</th><th>View</th></tr>
              </thead>
              <tbody>
                <?php if (!$recentOrders): ?>
                  <tr><td colspan="4" class="empty">No orders yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentOrders as $order): ?>
                  <tr>
                    <td>#<?= (int) $order['id'] ?></td>
                    <td><?= h($order['customer_name'] ?: '—') ?></td>
                    <td><?= admin_status_badge((string) $order['status']) ?></td>
                    <td><a class="eye" href="order.php?id=<?= (int) $order['id'] ?>" title="View order">◉</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>

          <section class="panel">
            <h2>Inventory Watch</h2>
            <table class="table">
              <thead>
                <tr><th>Product</th><th>Stock</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php if (!$watch): ?>
                  <tr><td colspan="3" class="empty">No products yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($watch as $row): ?>
                  <tr>
                    <td><?= h($row['name']) ?></td>
                    <td><?= (int) $row['stock'] ?></td>
                    <td><?= admin_stock_badge((int) $row['stock'], (int) $row['stock_limit']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>
        </div>
<?php admin_footer();