<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'inventory';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $productId = (int) ($_POST['product_id'] ?? 0);
    $adjustment = (int) ($_POST['adjustment'] ?? 0);
    $lowStockLimit = max(0, (int) ($_POST['low_stock_limit'] ?? 0));
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT p.stock, i.id AS inventory_id, i.quantity, i.low_stock_limit FROM products p LEFT JOIN inventory i ON i.product_id=p.id WHERE p.id=? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $inventory = $stmt->get_result()->fetch_assoc();
    $currentQuantity = (int) ($inventory['quantity'] ?? $inventory['stock'] ?? 0);
    if (!$inventory) {
        $error = 'Product not found.';
    } elseif ($currentQuantity + $adjustment < 0) {
        $error = 'Stock cannot be reduced below zero.';
    } else {
        $quantity = $currentQuantity + $adjustment;
        if ($inventory['inventory_id'] !== null) {
            $update = $conn->prepare('UPDATE inventory SET quantity=?,low_stock_limit=? WHERE id=?');
            $inventoryId = (int) $inventory['inventory_id'];
            $update->bind_param('iii', $quantity, $lowStockLimit, $inventoryId);
        } else {
            $update = $conn->prepare('INSERT INTO inventory (quantity,low_stock_limit,product_id) VALUES (?,?,?)');
            $update->bind_param('iii', $quantity, $lowStockLimit, $productId);
        }
        $updated = $update->execute();
        $productUpdate = $conn->prepare('UPDATE products SET stock=? WHERE id=?');
        $productUpdate->bind_param('ii', $quantity, $productId);
        $updated = $updated && $productUpdate->execute();
        if ($updated) {
            $conn->commit();
            $message = 'Inventory updated.';
        } else {
            $conn->rollback();
            $error = 'Unable to update inventory.';
        }
    }
    if ($error !== '') {
        $conn->rollback();
    }
}

$inventoryRows = $conn->query("SELECT p.id,p.name,p.image,p.status,i.quantity,i.low_stock_limit,i.updated_at FROM products p LEFT JOIN inventory i ON i.product_id=p.id WHERE LOWER(p.status)<>'deleted' ORDER BY p.name");
require __DIR__ . '/_header.php';
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Stock control</span><h1>Inventory</h1><p>Adjust counts and keep low-stock alerts tuned for each menu item.</p></div></section>
<?php if ($message): ?><p class="admin-alert admin-alert-success"><?= admin_escape($message) ?></p><?php endif; ?><?php if ($error): ?><p class="admin-alert admin-alert-error"><?= admin_escape($error) ?></p><?php endif; ?>
<section class="admin-panel"><div class="admin-panel-heading"><div><span class="admin-eyebrow">Live counts</span><h2>Current stock</h2></div><span class="admin-count-pill"><?= $inventoryRows ? $inventoryRows->num_rows : 0 ?> products</span></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Product</th><th>On hand</th><th>Low-stock limit</th><th>Updated</th><th>Adjust stock</th></tr></thead><tbody>
    <?php if ($inventoryRows && $inventoryRows->num_rows): while ($row = $inventoryRows->fetch_assoc()): $quantity = (int) ($row['quantity'] ?? 0); $limit = (int) ($row['low_stock_limit'] ?? 5); $low = $quantity <= $limit; ?><tr><td><div class="admin-product-cell"><?php if ($row['image']): ?><img src="<?= admin_escape($row['image']) ?>" alt=""><?php else: ?><span class="admin-product-placeholder">O</span><?php endif; ?><strong><?= admin_escape($row['name']) ?></strong></div></td><td><strong><?= $quantity ?></strong> <span class="admin-status <?= $low ? 'status-pending' : 'status-completed' ?>"><?= $low ? 'Low stock' : 'In stock' ?></span></td><td><?= $limit ?></td><td><?= admin_escape($row['updated_at'] ? date('M j, Y H:i', strtotime((string) $row['updated_at'])) : 'Not recorded') ?></td><td><form class="admin-inline-form" method="post"><?= admin_csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $row['id'] ?>"><label class="admin-sr-only" for="adjust-<?= (int) $row['id'] ?>">Stock adjustment</label><input id="adjust-<?= (int) $row['id'] ?>" type="number" name="adjustment" value="0" step="1" aria-label="Stock adjustment for <?= admin_escape($row['name']) ?>"><label class="admin-sr-only" for="limit-<?= (int) $row['id'] ?>">Low stock limit</label><input id="limit-<?= (int) $row['id'] ?>" type="number" min="0" name="low_stock_limit" value="<?= $limit ?>" step="1" aria-label="Low stock limit for <?= admin_escape($row['name']) ?>"><button type="submit" class="admin-button-small">Apply</button></form></td></tr><?php endwhile; else: ?><tr><td colspan="5" class="admin-empty">No inventory records found.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="admin-table-note">Use a positive adjustment to add stock and a negative adjustment to reduce it. Enter 0 to only change the alert limit.</p>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
