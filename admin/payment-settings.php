<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'payment';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $gcashName = trim((string) ($_POST['gcash_name'] ?? ''));
    $gcashNumber = trim((string) ($_POST['gcash_number'] ?? ''));
    $gcashEnabled = isset($_POST['gcash_enabled']);
    $cashEnabled = isset($_POST['cash_enabled']);
    $qrPath = null;

    // Handle optional QR image upload
    if (!empty($_FILES['qr_code']['name']) && $_FILES['qr_code']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['png', 'jpg', 'jpeg', 'webp'];
        $ext = strtolower(pathinfo((string) $_FILES['qr_code']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true)) {
            $dest = __DIR__ . '/assets/images/gcash-qr.' . $ext;
            if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $dest)) {
                $qrPath = 'assets/images/gcash-qr.' . $ext;
            }
        }
    }

    try {
        $rpcResult = supabase_call_rpc($conn, 'update_payment_settings', [
            'p_gcash_name'    => $gcashName,
            'p_gcash_number'  => $gcashNumber,
            'p_gcash_qr'      => $qrPath,
            'p_gcash_enabled' => $gcashEnabled,
            'p_cash_enabled'  => $cashEnabled,
        ]);

        if (is_array($rpcResult) && !empty($rpcResult['success'])) {
            $message = 'Payment settings updated successfully.';
        } else {
            // Direct update fallback
            $sql = 'UPDATE payment_settings SET gcash_name = :name, gcash_number = :num, gcash_enabled = :ge, cash_enabled = :ce, updated_at = NOW()';
            $params = [
                ':name' => $gcashName,
                ':num'  => $gcashNumber,
                ':ge'   => $gcashEnabled ? 'true' : 'false',
                ':ce'   => $cashEnabled ? 'true' : 'false',
            ];
            if ($qrPath) {
                $sql = 'UPDATE payment_settings SET gcash_name = :name, gcash_number = :num, gcash_qr = :qr, gcash_enabled = :ge, cash_enabled = :ce, updated_at = NOW()';
                $params[':qr'] = $qrPath;
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $message = 'Payment settings updated successfully.';
        }
    } catch (Throwable $e) {
        $error = 'Failed to update settings: ' . $e->getMessage();
    }
}

// Fetch current settings via Supabase RPC: get_payment_settings
$settingsRows = supabase_call_rpc_table($conn, 'get_payment_settings');
$settings = $settingsRows[0] ?? [
    'gcash_name'    => 'ONISUBI Official',
    'gcash_number'  => '09308084541',
    'gcash_qr'      => 'assets/images/gcash-qr.png',
    'gcash_enabled' => true,
    'cash_enabled'  => true,
];

require __DIR__ . '/_header.php';
?>
<div class="title">
    <div>
        <div class="eyebrow">Admin Panel</div>
        <h1>Payment Settings</h1>
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
    <section class="panel">
        <h2>GCash Configuration</h2>
        <form method="post" enctype="multipart/form-data">
            <?= admin_csrf_field() ?>
            <div style="display: grid; gap: 16px;">
                <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                    GCash Account Name
                    <input id="gcash-name" name="gcash_name" type="text" value="<?= admin_escape($settings['gcash_name']) ?>" required
                           style="padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
                </label>

                <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                    GCash Mobile Number
                    <input id="gcash-number" name="gcash_number" type="text" value="<?= admin_escape($settings['gcash_number']) ?>" required
                           style="padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
                </label>

                <label style="display: grid; gap: 6px; font-weight: 600; font-size: 13px;">
                    Replace QR Code (Optional)
                    <input type="file" name="qr_code" accept="image/*" style="font-size: 13px;">
                </label>

                <div style="margin-top: 6px;">
                    <span style="font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase;">Current QR Code:</span>
                    <img id="gcash-qr" src="<?= admin_escape($settings['gcash_qr']) ?>" alt="GCash QR Code"
                         style="max-width: 200px; display: block; margin-top: 8px; border-radius: 8px; border: 1px solid var(--line);">
                </div>

                <div style="display: flex; gap: 20px; margin-top: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600;">
                        <input type="checkbox" name="gcash_enabled" value="1" <?= !empty($settings['gcash_enabled']) ? 'checked' : '' ?>>
                        Enable GCash Payments
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600;">
                        <input type="checkbox" name="cash_enabled" value="1" <?= !empty($settings['cash_enabled']) ? 'checked' : '' ?>>
                        Enable Cash on Delivery
                    </label>
                </div>

                <button class="btn orange" id="save-payment" type="submit"
                        style="background: var(--orange); color: var(--brown); font-weight: 700; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; margin-top: 10px; width: fit-content;">
                    Save Settings
                </button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Payment Status</h2>
        <div style="display: grid; gap: 16px;">
            <div style="padding: 14px; background: var(--cream); border-radius: 8px;">
                <small style="color: var(--muted); font-weight: 700; text-transform: uppercase; font-size: 11px;">GCash Checkout</small>
                <div style="font-size: 18px; font-weight: 800; margin-top: 4px; color: var(--brown);" id="gcash-status">
                    <span class="badge <?= !empty($settings['gcash_enabled']) ? 'completed' : 'pending' ?>">
                        <?= !empty($settings['gcash_enabled']) ? 'ACTIVE' : 'DISABLED' ?>
                    </span>
                </div>
            </div>

            <div style="padding: 14px; background: var(--cream); border-radius: 8px;">
                <small style="color: var(--muted); font-weight: 700; text-transform: uppercase; font-size: 11px;">Cash / COD Checkout</small>
                <div style="font-size: 18px; font-weight: 800; margin-top: 4px; color: var(--brown);" id="cash-status">
                    <span class="badge <?= !empty($settings['cash_enabled']) ? 'completed' : 'pending' ?>">
                        <?= !empty($settings['cash_enabled']) ? 'ACTIVE' : 'DISABLED' ?>
                    </span>
                </div>
            </div>

            <div style="padding: 14px; background: var(--cream); border-radius: 8px;">
                <small style="color: var(--muted); font-weight: 700; text-transform: uppercase; font-size: 11px;">Last Updated</small>
                <div style="font-size: 14px; font-weight: 600; margin-top: 4px; color: var(--ink);">
                    <?= !empty($settings['updated_at']) ? admin_escape(date('M j, Y h:i A', strtotime((string) $settings['updated_at']))) : 'Recently' ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
