<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'reports';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$reviewTotal = (int) $conn->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
$totalPages = max(1, (int) ceil($reviewTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch reviews via Supabase RPC: get_admin_reviews
$reviews = supabase_call_rpc_table($conn, 'get_admin_reviews', [
    'limit'  => $perPage,
    'offset' => $offset,
]);

require __DIR__ . '/_header.php';
?>
<div class="title">
    <div>
        <div class="eyebrow">Admin Panel</div>
        <h1>Customer Reviews</h1>
    </div>
    <div style="font-size: 13px; color: var(--muted); font-weight: 700;">
        Total: <?= $reviewTotal ?> reviews
    </div>
</div>

<section class="panel">
    <h2>Product Reviews & Feedback</h2>
    <div class="tablewrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Product</th>
                    <th>Rating</th>
                    <th>Comment</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody id="reviews-body">
            <?php if (!empty($reviews)): foreach ($reviews as $review): ?>
                <tr>
                    <td><strong><?= admin_escape($review['customer_name']) ?></strong></td>
                    <td><?= admin_escape($review['product_name']) ?></td>
                    <td>
                        <span style="color: var(--orange); font-size: 15px; letter-spacing: 2px;">
                            <?= str_repeat('★', (int) $review['rating']) ?><span style="color: var(--beige);"><?= str_repeat('☆', max(0, 5 - (int) $review['rating'])) ?></span>
                        </span>
                        <span style="font-size: 11px; color: var(--muted); font-weight: 700; margin-left: 4px;">(<?= (int)$review['rating'] ?>/5)</span>
                    </td>
                    <td style="max-width: 320px; line-height: 1.4; font-size: 13px;">
                        <?= nl2br(admin_escape((string) $review['comment'])) ?>
                    </td>
                    <td><?= admin_escape(date('M j, Y', strtotime((string) $review['created_at']))) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="empty">No customer reviews yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div style="display: flex; gap: 8px; justify-content: center; margin-top: 20px;">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="reports.php?page=<?= $i ?>"
                   style="padding: 6px 12px; border-radius: 4px; font-weight: 700; font-size: 13px; <?= $page === $i ? 'background: var(--brown); color: #fff;' : 'background: var(--line); color: var(--ink);' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
