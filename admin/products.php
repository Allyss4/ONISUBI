<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'products';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE products SET status='deleted' WHERE id=?");
        $stmt->bind_param('i', $productId);
        $message = $stmt->execute() ? 'Product removed from the menu.' : '';
        $error = $stmt->error;
    } elseif (in_array($action, ['create', 'update'], true)) {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $price = (float) ($_POST['price'] ?? 0);
        $image = trim((string) ($_POST['image'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'active');
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        $lowStockLimit = max(0, (int) ($_POST['low_stock_limit'] ?? 5));
        if ($name === '' || $price <= 0 || !in_array($status, ['active', 'unavailable'], true)) {
            $error = 'Enter a product name, a price greater than zero, and a valid availability.';
        } else {
            if ($action === 'create') {
                $conn->begin_transaction();
                $stmt = $conn->prepare('INSERT INTO products (name,description,price,image,stock,status) VALUES (?,?,?,?,?,?)');
                $stmt->bind_param('ssdsis', $name, $description, $price, $image, $stock, $status);
                if ($stmt->execute()) {
                    $productId = (int) $stmt->insert_id;
                    $inventory = $conn->prepare('INSERT INTO inventory (product_id,quantity,low_stock_limit) VALUES (?,?,?)');
                    $inventory->bind_param('iii', $productId, $stock, $lowStockLimit);
                    if ($inventory->execute()) {
                        $conn->commit();
                        $message = 'Product added to the menu.';
                    } else {
                        $conn->rollback();
                        $error = 'Unable to create inventory for this product.';
                    }
                } else {
                    $conn->rollback();
                    $error = 'Unable to add this product.';
                }
            } else {
                $conn->begin_transaction();
                $stmt = $conn->prepare('UPDATE products SET name=?,description=?,price=?,image=?,stock=?,status=? WHERE id=?');
                $stmt->bind_param('ssdsisi', $name, $description, $price, $image, $stock, $status, $productId);
                $updated = $stmt->execute();
                if ($updated) {
                    $inventoryCheck = $conn->prepare('SELECT id FROM inventory WHERE product_id=? LIMIT 1');
                    $inventoryCheck->bind_param('i', $productId);
                    $inventoryCheck->execute();
                    $inventoryRow = $inventoryCheck->get_result()->fetch_assoc();
                    if ($inventoryRow) {
                        $inventory = $conn->prepare('UPDATE inventory SET quantity=?,low_stock_limit=? WHERE id=?');
                        $inventoryId = (int) $inventoryRow['id'];
                        $inventory->bind_param('iii', $stock, $lowStockLimit, $inventoryId);
                    } else {
                        $inventory = $conn->prepare('INSERT INTO inventory (product_id,quantity,low_stock_limit) VALUES (?,?,?)');
                        $inventory->bind_param('iii', $productId, $stock, $lowStockLimit);
                    }
                    $updated = $inventory->execute();
                }
                if ($updated) {
                    $conn->commit();
                    $message = 'Product changes saved.';
                } else {
                    $conn->rollback();
                    $error = 'Unable to save this product.';
                }
            }
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    $stmt = $conn->prepare("SELECT p.*,COALESCE(i.quantity,p.stock) AS current_stock,COALESCE(i.low_stock_limit,5) AS low_stock_limit FROM products p LEFT JOIN inventory i ON i.product_id=p.id WHERE p.id=? AND p.status<>'deleted'");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc();
}
$products = $conn->query("SELECT p.*,COALESCE(i.quantity,p.stock) AS current_stock,COALESCE(i.low_stock_limit,5) AS low_stock_limit FROM products p LEFT JOIN inventory i ON i.product_id=p.id WHERE LOWER(p.status)<>'deleted' ORDER BY p.id DESC");
$adminRole = strtolower((string) $adminUser['role']);
require __DIR__ . '/_header.php';
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Catalog</span><h1>Products</h1><p>Keep the ONISUBI menu, prices, and availability up to date.</p></div></section>
<?php if ($message): ?><p class="admin-alert admin-alert-success"><?= admin_escape($message) ?></p><?php endif; ?><?php if ($error): ?><p class="admin-alert admin-alert-error"><?= admin_escape($error) ?></p><?php endif; ?>
<div class="admin-management-grid">
    <section class="admin-panel admin-form-panel"><div class="admin-panel-heading"><div><span class="admin-eyebrow"><?= $editing ? 'Edit item' : 'New menu item' ?></span><h2><?= $editing ? 'Product details' : 'Add a product' ?></h2></div></div>
        <form class="admin-form" method="post">
            <?= admin_csrf_field() ?><input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>"><input type="hidden" name="product_id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <label>Product name<input name="name" required maxlength="100" value="<?= admin_escape($editing['name'] ?? '') ?>"></label>
            <label>Description<textarea name="description" rows="3"><?= admin_escape($editing['description'] ?? '') ?></textarea></label>
            <div class="admin-form-row"><label>Price (PHP)<input type="number" name="price" min="0.01" step="0.01" required value="<?= admin_escape($editing['price'] ?? '40.00') ?>"></label><label>Availability<select name="status"><option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Available</option><option value="unavailable" <?= ($editing['status'] ?? '') === 'unavailable' ? 'selected' : '' ?>>Unavailable</option></select></label></div>
            <label>Image URL<input type="url" name="image" value="<?= admin_escape($editing['image'] ?? '') ?>" placeholder="https://..."></label>
            <div class="admin-form-row"><label>Current stock<input type="number" name="stock" min="0" step="1" value="<?= (int) ($editing['current_stock'] ?? 0) ?>"></label><label>Low-stock limit<input type="number" name="low_stock_limit" min="0" step="1" value="<?= (int) ($editing['low_stock_limit'] ?? 5) ?>"></label></div>
            <div class="admin-form-actions"><button class="admin-button-primary" type="submit"><?= $editing ? 'Save changes' : 'Add product' ?></button><?php if ($editing): ?><a class="admin-button-secondary" href="products.php">Cancel</a><?php endif; ?></div>
        </form>
    </section>
    <section class="admin-panel"><div class="admin-panel-heading"><div><span class="admin-eyebrow">Menu overview</span><h2>Product list</h2></div><span class="admin-count-pill"><?= $products ? $products->num_rows : 0 ?> items</span></div>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Product</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php if ($products && $products->num_rows): while ($product = $products->fetch_assoc()): ?><tr><td><div class="admin-product-cell"><?php if ($product['image']): ?><img src="<?= admin_escape($product['image']) ?>" alt=""><?php else: ?><span class="admin-product-placeholder">O</span><?php endif; ?><strong><?= admin_escape($product['name']) ?></strong></div></td><td>₱<?= number_format((float) $product['price'], 2) ?></td><td><?= (int) $product['current_stock'] ?></td><td><span class="admin-status <?= $product['status'] === 'active' ? 'status-completed' : 'status-cancelled' ?>"><?= $product['status'] === 'active' ? 'Available' : 'Unavailable' ?></span></td><td><div class="admin-row-actions"><a href="products.php?edit=<?= (int) $product['id'] ?>" title="Edit Product" aria-label="Edit Product"><i class="bi bi-pencil-square" aria-hidden="true"></i></a><?php if ($adminRole === 'admin'): ?><form method="post" onsubmit="return confirm('Remove this product from the menu?')"><?= admin_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><button type="submit" class="admin-text-danger">Remove</button></form><?php endif; ?></div></td></tr><?php endwhile; else: ?><tr><td colspan="5" class="admin-empty">No products found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
