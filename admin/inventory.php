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
    $lowStockLimit = max(0, (int) ($_POST['low_stock_limit'] ?? 5));

    try {
        $res = supabase_call_rpc($conn, 'adjust_inventory', [
            'product_id'      => $productId,
            'adjustment'      => $adjustment,
            'low_stock_limit' => $lowStockLimit,
        ]);

        if (is_array($res) && !empty($res['success'])) {
            $newQuantity = (int) ($res['new_quantity'] ?? 0);
            $message = "Inventory updated. Current stock is now {$newQuantity}.";
        } else {
            $error = 'Unable to update inventory.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Fetch all active products with inventory counts via Supabase RPC: get_products_with_inventory
$inventoryRows = supabase_call_rpc_table($conn, 'get_products_with_inventory');

$totalItems = count($inventoryRows);
$inStockCount = 0;
$lowStockCount = 0;
$outOfStockCount = 0;

foreach ($inventoryRows as $r) {
    $qty = (int) ($r['inventory_quantity'] ?? $r['quantity'] ?? $r['stock'] ?? 0);
    $lim = (int) ($r['low_stock_limit'] ?? 5);
    if ($qty <= 0) {
        $outOfStockCount++;
    } elseif ($qty <= $lim) {
        $lowStockCount++;
    } else {
        $inStockCount++;
    }
}

require __DIR__ . '/_header.php';
?>
<div class="title">
    <div>
        <div class="eyebrow">Admin Panel</div>
        <h1>Inventory</h1>
    </div>
    <div style="font-size: 13px; color: var(--muted); font-weight: 700;">
        <?= $totalItems ?> items tracked
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

<!-- Summary Stat Cards -->
<div class="cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 24px;">
    <div class="card" style="background: var(--white); padding: 18px; border-radius: 12px; border: 1px solid var(--line);">
        <small style="color: var(--muted); font-weight: 700; text-transform: uppercase;">Total Tracked</small>
        <div style="font-size: 26px; font-weight: 800; color: var(--ink); margin-top: 4px;" id="stat-total-tracked"><?= $totalItems ?></div>
    </div>
    <div class="card" style="background: var(--white); padding: 18px; border-radius: 12px; border: 1px solid var(--line);">
        <small style="color: var(--muted); font-weight: 700; text-transform: uppercase;">In Stock</small>
        <div style="font-size: 26px; font-weight: 800; color: var(--green); margin-top: 4px;" id="stat-in-stock"><?= $inStockCount ?></div>
    </div>
    <div class="card" style="background: var(--white); padding: 18px; border-radius: 12px; border: 1px solid var(--line);">
        <small style="color: var(--muted); font-weight: 700; text-transform: uppercase;">Low Stock Alert</small>
        <div style="font-size: 26px; font-weight: 800; color: #8a5b12; margin-top: 4px;" id="stat-low-stock"><?= $lowStockCount ?></div>
    </div>
    <div class="card" style="background: var(--white); padding: 18px; border-radius: 12px; border: 1px solid var(--line);">
        <small style="color: var(--muted); font-weight: 700; text-transform: uppercase;">Out of Stock</small>
        <div style="font-size: 26px; font-weight: 800; color: var(--red); margin-top: 4px;" id="stat-out-stock"><?= $outOfStockCount ?></div>
    </div>
</div>

<!-- Search and Filter Bar -->
<div class="filters" style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 15px;">
    <input type="text" id="inventory-search" placeholder="Search product name..." style="padding: 8px 14px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; min-width: 250px; background: #fff;">
    <select id="inventory-filter-status" style="padding: 8px 14px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; background: #fff;">
        <option value="all">All Stock Statuses</option>
        <option value="in_stock">In Stock (Normal)</option>
        <option value="low_stock">Low Stock (At or Below Min)</option>
        <option value="out_of_stock">Out of Stock (Zero)</option>
    </select>
</div>

<!-- Unified Single Inventory Table -->
<section class="panel">
    <div class="tablewrap">
        <table class="table" id="inventory-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Current Stock</th>
                    <th>Low Stock Limit</th>
                    <th>Status</th>
                    <th>Adjust Stock & Limit</th>
                </tr>
            </thead>
            <tbody id="inventory-body">
            <?php if (!empty($inventoryRows)): foreach ($inventoryRows as $row):
                $quantity = (int) ($row['inventory_quantity'] ?? $row['quantity'] ?? $row['stock'] ?? 0);
                $limit = (int) ($row['low_stock_limit'] ?? 5);
                $low = $quantity <= $limit;
                $badgeClass = $quantity <= 0 ? 'out' : ($low ? 'pending' : 'completed');
                $badgeText = $quantity <= 0 ? 'Out of stock' : ($low ? 'Low stock' : 'In stock');
            ?>
                <tr data-product-id="<?= (int) $row['id'] ?>">
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <?php if (!empty($row['image'])): ?>
                                <img src="<?= admin_escape($row['image']) ?>" alt="" style="width: 36px; height: 36px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line);">
                            <?php else: ?>
                                <span style="width: 36px; height: 36px; background: var(--cream); display: grid; place-items: center; border-radius: 6px; font-weight: 800; color: var(--brown);">O</span>
                            <?php endif; ?>
                            <strong><?= admin_escape($row['name']) ?></strong>
                        </div>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="button" class="btn-stock-adj" data-action="-1" data-pid="<?= (int) $row['id'] ?>" title="Deduct 1"
                                    style="width: 28px; height: 28px; display: grid; place-items: center; border-radius: 4px; border: 1px solid var(--line); background: #fff; color: var(--ink); font-weight: 800; font-size: 15px; cursor: pointer;">−</button>
                            <strong class="stock-qty-val" style="min-width: 32px; text-align: center; font-size: 15px; <?= $low ? 'color: var(--red);' : '' ?>"><?= $quantity ?></strong>
                            <button type="button" class="btn-stock-adj" data-action="1" data-pid="<?= (int) $row['id'] ?>" title="Add 1"
                                    style="width: 28px; height: 28px; display: grid; place-items: center; border-radius: 4px; border: 1px solid var(--line); background: #fff; color: var(--ink); font-weight: 800; font-size: 15px; cursor: pointer;">+</button>
                        </div>
                    </td>
                    <td>
                        <span class="stock-limit-val" style="font-weight: 600;"><?= $limit ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $badgeClass ?> stock-badge-val"><?= $badgeText ?></span>
                    </td>
                    <td>
                        <form method="post" class="inventory-adjust-form" data-pid="<?= (int) $row['id'] ?>" style="display: inline-flex; gap: 8px; align-items: center; margin: 0;">
                            <?= admin_csrf_field() ?>
                            <input type="hidden" name="product_id" value="<?= (int) $row['id'] ?>">
                            <label style="font-size: 11px; font-weight: 600; color: var(--muted); display: inline-flex; flex-direction: column;">
                                +/- Qty
                                <input type="number" name="adjustment" value="0" step="1"
                                       style="width: 60px; padding: 4px 6px; border-radius: 4px; border: 1px solid var(--line); font-size: 13px; background: #fff;">
                            </label>
                            <label style="font-size: 11px; font-weight: 600; color: var(--muted); display: inline-flex; flex-direction: column;">
                                Min
                                <input type="number" min="0" name="low_stock_limit" value="<?= $limit ?>" step="1"
                                       style="width: 50px; padding: 4px 6px; border-radius: 4px; border: 1px solid var(--line); font-size: 13px; background: #fff;">
                            </label>
                            <button type="submit" class="btn btn-apply-adj"
                                    style="background: var(--brown); color: #fff; border: none; padding: 5px 12px; border-radius: 4px; font-size: 12px; font-weight: 700; cursor: pointer; align-self: flex-end;">
                                Apply
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="empty">No inventory records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p style="font-size: 12px; color: var(--muted); margin: 15px 0 0;">
        <strong>Tip:</strong> Click <strong>−</strong> or <strong>+</strong> for instant single-item stock adjustments. Or enter custom adjustments (e.g. <code>+20</code> or <code>-5</code>) and alert limits, then click <strong>Apply</strong>.
    </p>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
