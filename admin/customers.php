<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'customers';

$search = trim((string) ($_GET['search'] ?? ''));
$selectedId = (int) ($_GET['id'] ?? 0);

// Fetch customers via Supabase RPC: get_admin_customers
$customers = supabase_call_rpc_table($conn, 'get_admin_customers', [
    'search' => $search !== '' ? $search : null,
]);

// Selected customer details
$selectedCustomer = null;
$customerOrders = [];
if ($selectedId > 0) {
    foreach ($customers as $c) {
        if ((int) $c['id'] === $selectedId) {
            $selectedCustomer = $c;
            break;
        }
    }
    if (!$selectedCustomer) {
        $singleStmt = $conn->prepare('SELECT id, name, email, phone, address, created_at FROM users WHERE id = :id');
        $singleStmt->execute([':id' => $selectedId]);
        $selectedCustomer = $singleStmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($selectedCustomer) {
        $orderStmt = $conn->prepare('SELECT id, total, status, order_date FROM orders WHERE user_id = :uid ORDER BY order_date DESC LIMIT 5');
        $orderStmt->execute([':uid' => $selectedId]);
        $customerOrders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

require __DIR__ . '/_header.php';
?>
<div class="title">
    <div>
        <div class="eyebrow">Admin Panel</div>
        <h1>Customers</h1>
    </div>
    <div style="font-size: 13px; color: var(--muted); font-weight: 700;">
        Total: <?= count($customers) ?> customers
    </div>
</div>

<?php if ($selectedCustomer): ?>
    <section class="panel" style="margin-bottom: 24px; border-left: 4px solid var(--orange);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="margin: 0;">Customer Profile: <?= admin_escape($selectedCustomer['name']) ?></h2>
            <a href="customers.php<?= $search ? '?search=' . urlencode($search) : '' ?>" style="font-size: 13px; color: var(--brown); font-weight: 700;">✕ Close</a>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
            <div><small style="color:var(--muted); font-weight:700;">EMAIL</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape($selectedCustomer['email']) ?></p></div>
            <div><small style="color:var(--muted); font-weight:700;">PHONE</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape($selectedCustomer['phone'] ?? 'None') ?></p></div>
            <div><small style="color:var(--muted); font-weight:700;">ADDRESS</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape($selectedCustomer['address'] ?? 'None') ?></p></div>
            <div><small style="color:var(--muted); font-weight:700;">JOINED</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape(date('M j, Y', strtotime((string)$selectedCustomer['created_at']))) ?></p></div>
        </div>
        <?php if (!empty($customerOrders)): ?>
            <h3 style="font-size: 14px; margin: 15px 0 8px; color: var(--brown);">Recent Orders</h3>
            <div class="tablewrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customerOrders as $co): ?>
                            <tr>
                                <td>#<?= (int)$co['id'] ?></td>
                                <td>₱<?= number_format((float)$co['total'], 2) ?></td>
                                <td><span class="badge pending"><?= admin_escape($co['status']) ?></span></td>
                                <td><?= admin_escape(date('M j, Y', strtotime((string)$co['order_date']))) ?></td>
                                <td><a class="eye" href="orders.php?id=<?= (int)$co['id'] ?>">◉</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="panel">
    <form method="get" style="display: flex; gap: 10px; margin-bottom: 20px;">
        <input name="search" placeholder="Search customer by name, email, or phone" value="<?= admin_escape($search) ?>"
               style="flex: 1; padding: 10px 14px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
        <button type="submit" class="btn" style="background: var(--brown); color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; cursor: pointer;">Search</button>
        <?php if ($search): ?>
            <a href="customers.php" class="btn" style="background: var(--line); color: var(--ink); padding: 10px 16px; border-radius: 6px; font-weight: 600; display: inline-flex; align-items: center;">Clear</a>
        <?php endif; ?>
    </form>

    <div class="tablewrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Orders</th>
                    <th>Total Spent</th>
                    <th>View</th>
                </tr>
            </thead>
            <tbody id="customers-body">
            <?php if (!empty($customers)): foreach ($customers as $c): ?>
                <tr>
                    <td>#<?= (int) $c['id'] ?></td>
                    <td><strong><?= admin_escape($c['name']) ?></strong></td>
                    <td><?= admin_escape($c['email']) ?></td>
                    <td><?= admin_escape($c['phone'] ?? '—') ?></td>
                    <td><?= (int) $c['order_count'] ?></td>
                    <td>₱<?= number_format((float) $c['total_spent'], 2) ?></td>
                    <td><a class="eye" href="customers.php?id=<?= (int) $c['id'] ?><?= $search ? '&search=' . urlencode($search) : '' ?>" title="View customer details">◉</a></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="empty">No customers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
