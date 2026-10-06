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
        try {
            $stmt = $conn->prepare("UPDATE products SET status = 'deleted' WHERE id = :id");
            $stmt->execute([':id' => $productId]);
            $message = 'Product removed from the menu.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif (in_array($action, ['create', 'update'], true)) {
        $productId = $action === 'update' ? (int) ($_POST['product_id'] ?? 0) : 0;
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
            try {
                $res = supabase_call_rpc($conn, 'upsert_product', [
                    'id'              => $productId,
                    'name'            => $name,
                    'description'     => $description,
                    'price'           => $price,
                    'image'           => $image,
                    'stock'           => $stock,
                    'status'          => $status,
                    'low_stock_limit' => $lowStockLimit,
                ]);

                if (is_array($res) && !empty($res['success'])) {
                    $message = $action === 'create' ? 'Product added to the menu.' : 'Product changes saved.';
                } else {
                    $error = 'Unable to save product.';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    $editStmt = $conn->prepare('
        SELECT p.*, COALESCE(i.quantity, p.stock, 0) AS current_stock, COALESCE(i.low_stock_limit, 5) AS low_stock_limit
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        WHERE p.id = :id
    ');
    $editStmt->execute([':id' => $editId]);
    $editing = $editStmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch product catalog via Supabase RPC: get_products_with_inventory
$products = supabase_call_rpc_table($conn, 'get_products_with_inventory');
$adminRole = strtolower((string) ($adminUser['role'] ?? ''));

require __DIR__ . '/_header.php';
?>
<div class="title">
    <div>
        <div class="eyebrow">Admin Panel</div>
        <h1>Products</h1>
    </div>
    <div style="font-size: 13px; color: var(--muted); font-weight: 700;">
        Total: <?= count($products) ?> items
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

<div class="grid2">
    <!-- Form Panel -->
    <section class="panel">
        <h2 style="margin-top: 0;"><?= $editing ? 'Edit Product' : 'Add New Product' ?></h2>
        <form method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <input type="hidden" name="product_id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div style="display: grid; gap: 14px;">
                <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                    Product Name
                    <input name="name" required maxlength="100" value="<?= admin_escape($editing['name'] ?? '') ?>"
                           style="padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
                </label>

                <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                    Description
                    <textarea name="description" rows="3"
                              style="padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;"><?= admin_escape($editing['description'] ?? '') ?></textarea>
                </label>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                        Price (₱)
                        <input type="number" name="price" min="0.01" step="0.01" required value="<?= admin_escape($editing['price'] ?? '40.00') ?>"
                               style="padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
                    </label>
                    <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                        Availability
                        <select name="status" style="padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px; background: #fff;">
                            <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Available</option>
                            <option value="unavailable" <?= ($editing['status'] ?? '') === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                        </select>
                    </label>
                </div>

                <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                    Image URL
                    <input type="url" name="image" value="<?= admin_escape($editing['image'] ?? '') ?>" placeholder="https://..."
                           style="padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
                </label>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                        Initial Stock
                        <input type="number" name="stock" min="0" step="1" value="<?= (int) ($editing['current_stock'] ?? 50) ?>"
                               style="padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
                    </label>
                    <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                        Low Stock Alert Limit
                        <input type="number" name="low_stock_limit" min="0" step="1" value="<?= (int) ($editing['low_stock_limit'] ?? 5) ?>"
                               style="padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
                    </label>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 6px;">
                    <button type="submit" class="btn" style="background: var(--orange); color: var(--brown); border: none; padding: 12px 20px; border-radius: 6px; font-weight: 700; cursor: pointer;">
                        <?= $editing ? 'Save Changes' : '+ Add Product' ?>
                    </button>
                    <?php if ($editing): ?>
                        <a href="products.php" class="btn" style="background: var(--line); color: var(--ink); padding: 12px 16px; border-radius: 6px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center;">Cancel</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </section>

    <!-- Product List Panel -->
    <section class="panel">
        <h2 style="margin-top: 0;">Menu Catalog</h2>
        <div class="tablewrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="products-body">
                <?php if (!empty($products)): foreach ($products as $p): ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php if (!empty($p['image'])): ?>
                                    <img src="<?= admin_escape($p['image']) ?>" alt="" style="width: 36px; height: 36px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line);">
                                <?php else: ?>
                                    <span style="width: 36px; height: 36px; background: var(--cream); display: grid; place-items: center; border-radius: 6px; font-weight: 800; color: var(--brown);">O</span>
                                <?php endif; ?>
                                <strong><?= admin_escape($p['name']) ?></strong>
                            </div>
                        </td>
                        <td>₱<?= number_format((float) $p['price'], 2) ?></td>
                        <td>
                            <?php
                            $qty = (int) ($p['inventory_quantity'] ?? $p['current_stock'] ?? $p['stock'] ?? 0);
                            $limit = (int) ($p['low_stock_limit'] ?? 5);
                            ?>
                            <strong style="<?= $qty <= $limit ? 'color: var(--red);' : '' ?>"><?= $qty ?></strong>
                        </td>
                        <td>
                            <span class="badge <?= $p['status'] === 'active' ? 'completed' : 'pending' ?>">
                                <?= $p['status'] === 'active' ? 'Available' : 'Unavailable' ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <a href="products.php?edit=<?= (int) $p['id'] ?>" class="eye" title="Edit product">✎</a>
                                <?php if ($adminRole === 'admin'): ?>
                                    <form method="post" onsubmit="return confirm('Remove this product from the menu?');" style="margin: 0;">
                                        <?= admin_csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                        <button type="submit" style="background: none; border: none; color: var(--red); cursor: pointer; font-size: 14px; font-weight: 700;" title="Delete">✕</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="empty">No products found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
