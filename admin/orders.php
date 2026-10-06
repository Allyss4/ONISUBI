<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'orders';
$statuses = ['Order Placed', 'Pending', 'Confirmed', 'Preparing', 'Ready', 'Out for Delivery', 'Delivered', 'Completed', 'Cancelled', 'Awaiting Payment', 'For Verification'];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$message = '';
$error = '';

$search = trim((string) ($_GET['search'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'status') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $newStatus = trim((string) ($_POST['status'] ?? ''));

        if (!in_array($newStatus, $statuses, true)) {
            $error = 'Select a valid order status.';
        } else {
            try {
                $rpcResult = supabase_call_rpc($conn, 'update_order_status', [
                    'order_id'   => $orderId,
                    'new_status' => $newStatus,
                    'admin_id'   => (int) $adminUser['id'],
                ]);

                if (is_array($rpcResult) && !empty($rpcResult['success'])) {
                    $message = "Order #{$orderId} status updated to {$newStatus}.";
                } else {
                    $stmt = $conn->prepare('UPDATE orders SET status = :status WHERE id = :id');
                    $stmt->execute([':status' => $newStatus, ':id' => $orderId]);
                    $message = "Order #{$orderId} status updated to {$newStatus}.";
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'update_reference') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $reference = trim((string) ($_POST['payment_reference'] ?? ''));
        if ($orderId > 0) {
            try {
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET payment_reference = :ref,
                        payment_status = CASE WHEN payment_status = 'pending' AND :ref <> '' THEN 'paid' ELSE payment_status END
                    WHERE id = :id
                ");
                $stmt->execute([':ref' => $reference, ':id' => $orderId]);
                $message = "Payment reference for Order #{$orderId} updated.";
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$selectedId = (int) ($_GET['id'] ?? 0);
$selectedOrder = null;
$orderItems = [];

if ($selectedId > 0) {
    $detail = $conn->prepare('
        SELECT o.*, COALESCE(o.customer_name, u.name, \'Guest\') AS display_name, u.email, u.phone AS user_phone, u.address AS user_address
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.id = :id
    ');
    $detail->execute([':id' => $selectedId]);
    $selectedOrder = $detail->fetch(PDO::FETCH_ASSOC);

    if ($selectedOrder) {
        $itemsStmt = $conn->prepare('
            SELECT oi.product_id, oi.quantity, oi.price, oi.product_name
            FROM order_items oi
            WHERE oi.order_id = :id
            ORDER BY oi.id ASC
        ');
        $itemsStmt->execute([':id' => $selectedId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Build query with search and status filter
$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = '(o.id::text ILIKE :search OR o.customer_name ILIKE :search OR u.name ILIKE :search OR o.payment_reference ILIKE :search OR o.paymongo_checkout_id ILIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($filterStatus !== '' && $filterStatus !== 'all') {
    $whereClauses[] = 'LOWER(o.status) = LOWER(:filter_status)';
    $params[':filter_status'] = $filterStatus;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Total count for pagination
$countQuery = "SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.id = o.user_id {$whereSql}";
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$orderTotal = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($orderTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch orders via Supabase RPC: get_admin_orders
$orderRows = supabase_call_rpc_table($conn, 'get_admin_orders', [
    'search' => $search !== '' ? $search : null,
    'status' => ($filterStatus !== '' && $filterStatus !== 'all') ? $filterStatus : null,
    'limit'  => $perPage,
    'offset' => $offset,
]);

require __DIR__ . '/_header.php';
?>
<div class="title">
    <div>
        <div class="eyebrow">Admin Panel</div>
        <h1>Orders</h1>
    </div>
    <div style="font-size: 13px; color: var(--muted); font-weight: 700;">
        Total: <?= $orderTotal ?> orders
    </div>
</div>

<?php if ($message): ?>
    <div style="background: #e4f1e5; color: #38603f; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 600;">
        <?= admin_escape($message) ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background: #fde8e8; color: #a94442; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 600;">
        <?= admin_escape($error) ?>
    </div>
<?php endif; ?>

<?php if ($selectedOrder): ?>
<section class="panel" style="margin-bottom: 24px; border-left: 4px solid var(--orange);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 style="margin: 0;">Order #<?= (int) $selectedOrder['id'] ?> Details</h2>
        <a href="orders.php<?= $page > 1 ? '?page=' . $page : '' ?>" style="font-size: 13px; color: var(--brown); font-weight: 700;">✕ Close</a>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
        <div><small style="color:var(--muted); font-weight:700;">CUSTOMER</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape($selectedOrder['display_name']) ?></p></div>
        <div><small style="color:var(--muted); font-weight:700;">PHONE</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape($selectedOrder['phone'] ?: ($selectedOrder['user_phone'] ?? 'None')) ?></p></div>
        <div><small style="color:var(--muted); font-weight:700;">ADDRESS</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape($selectedOrder['address'] ?: ($selectedOrder['user_address'] ?? 'None')) ?></p></div>
        <div><small style="color:var(--muted); font-weight:700;">PAYMENT METHOD</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape($selectedOrder['payment_method'] ?? 'None') ?></p></div>
        <div>
            <small style="color:var(--muted); font-weight:700;">PAYMONGO / PAYMENT REF</small>
            <div style="margin-top: 4px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <?php if (!empty($selectedOrder['payment_reference'])): ?>
                    <code style="background: rgba(240, 146, 26, 0.12); color: var(--brown); padding: 4px 8px; border-radius: 4px; font-weight: 700; font-size: 13px;"><?= admin_escape($selectedOrder['payment_reference']) ?></code>
                    <button type="button" onclick="navigator.clipboard.writeText('<?= admin_escape(addslashes($selectedOrder['payment_reference'])) ?>'); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy', 1500);" style="background: var(--line); border: none; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer;">Copy</button>
                <?php elseif (!empty($selectedOrder['paymongo_checkout_id'])): ?>
                    <code style="background: #f1f5f9; color: #1e293b; padding: 4px 8px; border-radius: 4px; font-weight: 700; font-size: 13px;"><?= admin_escape($selectedOrder['paymongo_checkout_id']) ?></code>
                    <button type="button" onclick="navigator.clipboard.writeText('<?= admin_escape(addslashes($selectedOrder['paymongo_checkout_id'])) ?>'); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy', 1500);" style="background: var(--line); border: none; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer;">Copy</button>
                <?php else: ?>
                    <span style="color:var(--muted); font-weight:normal; font-size:13px;">None</span>
                <?php endif; ?>
            </div>
            <details style="margin-top: 6px;">
                <summary style="font-size: 11px; color: var(--brown); cursor: pointer; font-weight: 600;">Edit Reference</summary>
                <form method="post" style="margin-top: 6px; display: flex; gap: 6px;">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="update_reference">
                    <input type="hidden" name="order_id" value="<?= (int) $selectedOrder['id'] ?>">
                    <input type="text" name="payment_reference" value="<?= admin_escape($selectedOrder['payment_reference'] ?? '') ?>" placeholder="Enter reference" style="padding: 4px 8px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px; width: 150px;">
                    <button type="submit" class="btn" style="background: var(--brown); color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer;">Save</button>
                </form>
            </details>
        </div>
        <div><small style="color:var(--muted); font-weight:700;">ORDER DATE</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape(date('M j, Y h:i A', strtotime((string)$selectedOrder['order_date']))) ?></p></div>
        <div><small style="color:var(--muted); font-weight:700;">CUSTOMER NOTE</small><p style="margin:4px 0; font-weight:600;"><?= admin_escape($selectedOrder['notes'] ?: 'No note') ?></p></div>
    </div>
    <div class="tablewrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orderItems as $item): ?>
                    <tr>
                        <td><strong><?= admin_escape($item['product_name'] ?? 'Item') ?></strong></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td>₱<?= number_format((float) $item['price'], 2) ?></td>
                        <td>₱<?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="3" style="text-align: right; font-weight: 800;">Total:</td>
                    <td style="font-weight: 800; color: var(--orange); font-size: 16px;">₱<?= number_format((float)$selectedOrder['total'], 2) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="panel">
    <form method="get" style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
        <input name="search" placeholder="Search order ID or customer name" value="<?= admin_escape($search) ?>"
               style="flex: 1; min-width: 200px; padding: 10px 14px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
        <select name="status" style="padding: 10px 14px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px; background: #fff;">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $st): ?>
                <option value="<?= admin_escape($st) ?>" <?= strcasecmp($filterStatus, $st) === 0 ? 'selected' : '' ?>><?= admin_escape($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn" style="background: var(--brown); color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; cursor: pointer;">Filter</button>
        <?php if ($search || $filterStatus): ?>
            <a href="orders.php" class="btn" style="background: var(--line); color: var(--ink); padding: 10px 16px; border-radius: 6px; font-weight: 600; display: inline-flex; align-items: center;">Clear</a>
        <?php endif; ?>
    </form>

    <div class="tablewrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Update Status</th>
                    <th>View</th>
                </tr>
            </thead>
            <tbody id="orders-body">
            <?php if (!empty($orderRows)): foreach ($orderRows as $o): ?>
                <tr>
                    <td><strong>#<?= (int) $o['id'] ?></strong></td>
                    <td><?= admin_escape($o['display_name']) ?></td>
                    <td><?= admin_escape(date('M j, Y h:i A', strtotime((string)$o['order_date']))) ?></td>
                    <td>
                        <div><?= admin_escape($o['payment_method'] ?? 'COD') ?></div>
                        <?php if (!empty($o['payment_reference'])): ?>
                            <div style="margin-top: 3px; font-size: 11px; font-family: monospace; color: var(--brown); background: rgba(240, 146, 26, 0.12); padding: 2px 6px; border-radius: 4px; display: inline-block; font-weight: 600;" title="PayMongo Reference">
                                Ref: <?= admin_escape($o['payment_reference']) ?>
                            </div>
                        <?php elseif (!empty($o['paymongo_checkout_id'])): ?>
                            <div style="margin-top: 3px; font-size: 11px; font-family: monospace; color: var(--muted); background: #f1f5f9; padding: 2px 6px; border-radius: 4px; display: inline-block;" title="PayMongo Checkout ID">
                                ID: <?= admin_escape($o['paymongo_checkout_id']) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><strong>₱<?= number_format((float) $o['total'], 2) ?></strong></td>
                    <td>
                        <?php
                        $st = strtolower((string) ($o['status'] ?? 'pending'));
                        $badgeClass = in_array($st, ['completed', 'delivered'], true) ? 'completed' : (in_array($st, ['cancelled'], true) ? 'pending' : 'pending');
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= admin_escape($o['status']) ?></span>
                    </td>
                    <td>
                        <form method="post" style="display: inline-flex; gap: 6px; align-items: center;">
                            <?= admin_csrf_field() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                            <select name="status" style="padding: 4px 8px; border-radius: 4px; border: 1px solid var(--line); font-size: 12px;">
                                <?php foreach ($statuses as $st): ?>
                                    <option value="<?= admin_escape($st) ?>" <?= strcasecmp((string)$o['status'], $st) === 0 ? 'selected' : '' ?>><?= admin_escape($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn" style="background: var(--orange); color: var(--brown); border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer;">Save</button>
                        </form>
                    </td>
                    <td><a class="eye" href="orders.php?id=<?= (int) $o['id'] ?>" title="View order details">◉</a></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="empty">No orders found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div style="display: flex; gap: 8px; justify-content: center; margin-top: 20px;">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="orders.php?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filterStatus ? '&status=' . urlencode($filterStatus) : '' ?>"
                   style="padding: 6px 12px; border-radius: 4px; font-weight: 700; font-size: 13px; <?= $page === $i ? 'background: var(--brown); color: #fff;' : 'background: var(--line); color: var(--ink);' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
