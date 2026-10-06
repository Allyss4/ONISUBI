<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'staff';

$message = '';
$error = '';
$canManageRoles = strtolower((string) ($adminUser['role'] ?? '')) === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageRoles) {
    admin_verify_csrf();
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $newRole = strtolower(trim((string) ($_POST['role'] ?? 'staff')));

    if ($targetId === (int) $adminUser['id']) {
        $error = 'You cannot modify your own administrator role.';
    } elseif (!in_array($newRole, ['admin', 'staff', 'customer'], true)) {
        $error = 'Invalid role specified.';
    } else {
        try {
            $stmt = $conn->prepare('UPDATE users SET role = :role WHERE id = :id');
            $stmt->execute([':role' => $newRole, ':id' => $targetId]);
            $message = "User role successfully changed to {$newRole}.";
        } catch (Throwable $e) {
            $error = 'Failed to update role: ' . $e->getMessage();
        }
    }
}

// Fetch all staff and admin members via Supabase RPC: get_admin_staff
$staffMembers = supabase_call_rpc_table($conn, 'get_admin_staff');

require __DIR__ . '/_header.php';
?>
<div class="title">
    <div>
        <div class="eyebrow">Admin Panel</div>
        <h1>Staff & Admins</h1>
    </div>
    <div style="font-size: 13px; color: var(--muted); font-weight: 700;">
        <?= count($staffMembers) ?> team members
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

<section class="panel">
    <div class="tablewrap">
        <table class="table">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Member Since</th>
                    <?php if ($canManageRoles): ?><th>Manage Role</th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="staff-body">
            <?php if (!empty($staffMembers)): foreach ($staffMembers as $s): ?>
                <tr>
                    <td>#<?= (int) $s['id'] ?></td>
                    <td><strong><?= admin_escape($s['name']) ?></strong></td>
                    <td><?= admin_escape($s['email']) ?></td>
                    <td>
                        <span class="badge <?= strtolower((string)$s['role']) === 'admin' ? 'completed' : 'pending' ?>">
                            <?= admin_escape(strtoupper($s['role'])) ?>
                        </span>
                    </td>
                    <td><?= admin_escape(date('M j, Y', strtotime((string)$s['created_at']))) ?></td>
                    <?php if ($canManageRoles): ?>
                        <td>
                            <?php if ((int)$s['id'] !== (int)$adminUser['id']): ?>
                                <form method="post" style="display: inline-flex; gap: 6px; align-items: center;">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
                                    <select name="role" style="padding: 4px 8px; border-radius: 4px; border: 1px solid var(--line); font-size: 12px;">
                                        <option value="staff" <?= strtolower((string)$s['role']) === 'staff' ? 'selected' : '' ?>>Staff</option>
                                        <option value="admin" <?= strtolower((string)$s['role']) === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        <option value="customer">Demote to Customer</option>
                                    </select>
                                    <button type="submit" class="btn" style="background: var(--brown); color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer;">Save</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size: 12px; color: var(--muted); font-style: italic;">Current Session</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="<?= $canManageRoles ? '6' : '5' ?>" class="empty">No staff members found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
