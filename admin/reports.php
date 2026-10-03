<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'reports';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$reviewTotal = (int) ($conn->query('SELECT COUNT(*) AS total FROM customer_reviews')->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int) ceil($reviewTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$reviewsStmt = $conn->prepare('SELECT r.id,r.rating,r.comment,r.created_at,u.name AS customer_name,p.name AS product_name FROM customer_reviews r JOIN users u ON u.id=r.user_id JOIN products p ON p.id=r.product_id ORDER BY r.created_at DESC,r.id DESC LIMIT ? OFFSET ?');
$reviewsStmt->bind_param('ii', $perPage, $offset);
$reviewsStmt->execute();
$reviews = $reviewsStmt->get_result();
require __DIR__ . '/_header.php';
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Customer feedback</span><h1>Customer Reviews</h1><p>Ratings and comments submitted by customers after completed orders.</p></div></section>
<section class="admin-panel"><div class="admin-panel-heading"><div><span class="admin-eyebrow">Verified purchase feedback</span><h2>Product reviews</h2></div><span class="admin-count-pill"><?= $reviewTotal ?> reviews</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Customer</th><th>Product</th><th>Rating</th><th>Review / Comment</th><th>Date</th></tr></thead><tbody>
<?php if ($reviews && $reviews->num_rows): while ($review = $reviews->fetch_assoc()): ?><tr><td><?= admin_escape($review['customer_name']) ?></td><td><?= admin_escape($review['product_name']) ?></td><td><span class="admin-review-stars" aria-label="<?= (int) $review['rating'] ?> out of 5 stars"><?= str_repeat('★', (int) $review['rating']) ?><span><?= str_repeat('☆', 5 - (int) $review['rating']) ?></span></span></td><td class="admin-review-comment"><?= nl2br(admin_escape($review['comment'])) ?></td><td><?= admin_escape(date('F j, Y', strtotime((string) $review['created_at']))) ?></td></tr><?php endwhile; else: ?><tr><td colspan="5" class="admin-empty">No customer reviews yet. Reviews will appear here after customers submit feedback on completed orders.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?= admin_pagination($page, $totalPages) ?>
<?php require __DIR__ . '/_footer.php'; ?>
