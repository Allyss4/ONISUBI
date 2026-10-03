<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'users';
$message = '';
$error = '';
$canManageRoles = strtolower((string) $adminUser['role']) === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageRoles) {
    admin_verify_csrf();
    $userId = (int) ($_POST['user_id'] ?? 0);
    $role = (string) ($_POST['role'] ?? 'customer');
    if (!in_array($role, ['customer', 'staff', 'admin'], true) || $userId === (int) $adminUser['id']) {
        $error = 'That account role cannot be changed.';
    } else {
        $stmt = $conn->prepare('UPDATE users SET role=? WHERE id=?');
        $stmt->bind_param('si', $role, $userId);
        $message = $stmt->execute() ? 'Account role updated.' : 'Unable to update account role.';
    }
}

$users = $conn->query('SELECT u.id,u.name,u.email,u.phone,u.address,u.role,u.created_at,COUNT(o.id) AS order_count,COALESCE(SUM(CASE WHEN LOWER(o.status)=\'completed\' THEN o.total_amount ELSE 0 END),0) AS completed_sales FROM users u LEFT JOIN orders o ON o.user_id=u.id GROUP BY u.id ORDER BY u.created_at DESC');
require __DIR__ . '/_header.php';
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">People</span><h1>Users</h1><p>Review customer details and manage the ONISUBI team.</p></div></section>
<?php if ($message): ?><p class="admin-alert admin-alert-success"><?= admin_escape($message) ?></p><?php endif; ?><?php if ($error): ?><p class="admin-alert admin-alert-error"><?= admin_escape($error) ?></p><?php endif; ?>
<section class="admin-panel"><div class="admin-panel-heading"><div><span class="admin-eyebrow">Accounts</span><h2>Registered users</h2></div><span class="admin-count-pill"><?= $users ? $users->num_rows : 0 ?> accounts</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Name and contact</th><th>Role</th><th>Joined</th><th>Orders</th><th>Completed sales</th><?php if ($canManageRoles): ?><th>Manage role</th><?php endif; ?></tr></thead><tbody>
<?php if ($users && $users->num_rows): while ($user = $users->fetch_assoc()): ?><tr><td><div class="admin-user-cell"><strong><?= admin_escape($user['name']) ?></strong><span><?= admin_escape($user['email']) ?></span><span><?= admin_escape($user['phone'] ?? 'No phone') ?><?= $user['address'] ? ' · ' . admin_escape($user['address']) : '' ?></span></div></td><td><span class="admin-role-pill"><?= admin_escape(ucfirst((string) $user['role'])) ?></span></td><td><?= admin_escape(date('M j, Y', strtotime((string) $user['created_at']))) ?></td><td><?= (int) $user['order_count'] ?></td><td>₱<?= number_format((float) $user['completed_sales'], 2) ?></td><?php if ($canManageRoles): ?><td><?php if ((int) $user['id'] !== (int) $adminUser['id']): ?><form class="admin-inline-form" method="post"><?= admin_csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><label class="admin-sr-only" for="user-role-<?= (int) $user['id'] ?>">Role for <?= admin_escape($user['name']) ?></label><select id="user-role-<?= (int) $user['id'] ?>" name="role"><option value="customer" <?= $user['role'] === 'customer' ? 'selected' : '' ?>>Customer</option><option value="staff" <?= $user['role'] === 'staff' ? 'selected' : '' ?>>Staff</option><option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select><button type="submit" class="admin-button-small">Save</button></form><?php else: ?><span class="admin-table-note">Your account</span><?php endif; ?></td><?php endif; ?></tr><?php endwhile; else: ?><tr><td colspan="6" class="admin-empty">No accounts found.</td></tr><?php endif; ?>
</tbody></table></div><?php if (!$canManageRoles): ?><p class="admin-table-note">Only administrators can change account roles.</p><?php endif; ?></section>
<?php require __DIR__ . '/_footer.php'; ?>
