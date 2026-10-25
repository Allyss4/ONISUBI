<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
$adminUser = admin_require_user();
$activePage = 'reports';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$reviewTotal = 0;
try {
    $reviewTotal = (int) $conn->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
} catch (Throwable $e) {
    $reviewTotal = 0;
}

$totalPages = max(1, (int) ceil($reviewTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch reviews via Supabase RPC: get_admin_reviews
$reviews = [];
try {
    $reviews = supabase_call_rpc_table($conn, 'get_admin_reviews', [
        'limit'  => $perPage,
        'offset' => $offset,
    ]);
} catch (Throwable $e) {
    try {
        $stmt = $conn->prepare('
            SELECT r.id, COALESCE(r.customer_name, u.name, \'Customer\') AS customer_name,
                   COALESCE(p.name, \'Product\') AS product_name, r.rating, r.comment, r.created_at
            FROM public.reviews r
            LEFT JOIN public.users u ON u.id = r.user_id
            LEFT JOIN public.products p ON p.id = r.product_id
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT :lim OFFSET :off
        ');
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {
        $reviews = [];
    }
}

// Fetch detailed customer feedback & survey responses
$feedbacks = [];
$feedbackTotal = 0;
try {
    $feedbackTotal = (int) $conn->query('SELECT COUNT(*) FROM customer_feedback')->fetchColumn();
    $feedbacks = supabase_call_rpc_table($conn, 'get_customer_feedback', [
        'limit'  => 50,
        'offset' => 0,
    ]);
} catch (Throwable $e) {
    try {
        $feedbacks = $conn->query('SELECT * FROM public.customer_feedback ORDER BY submitted_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $feedbackTotal = count($feedbacks);
    } catch (Throwable $e2) {
        $feedbacks = [];
        $feedbackTotal = 0;
    }
}

// Compute aggregate metrics
$avgRating = 0.0;
$topFlavor = 'Spam & Egg / Tuna Mayo';
$bugCount = 0;
$portionRightCount = 0;

if (!empty($feedbacks)) {
    $ratingsSum = 0.0;
    $flavorsCount = [];
    foreach ($feedbacks as $fb) {
        $ratingsSum += (float) ($fb['average_rating'] ?? $fb['rating_overall'] ?? 5);
        $flv = trim((string) ($fb['favorite_flavor'] ?? ''));
        if ($flv !== '') {
            $flavorsCount[$flv] = ($flavorsCount[$flv] ?? 0) + 1;
        }
        $issues = strtolower(trim((string) ($fb['website_issues'] ?? '')));
        if ($issues !== '' && $issues !== 'no') {
            $bugCount++;
        }
        if (strtolower(trim((string) ($fb['portion_size'] ?? ''))) === 'just right') {
            $portionRightCount++;
        }
    }
    $avgRating = count($feedbacks) > 0 ? $ratingsSum / count($feedbacks) : 0.0;
    if (!empty($flavorsCount)) {
        arsort($flavorsCount);
        $topFlavor = (string) array_key_first($flavorsCount);
    }
} elseif (!empty($reviews)) {
    $ratingsSum = array_sum(array_column($reviews, 'rating'));
    $avgRating = count($reviews) > 0 ? $ratingsSum / count($reviews) : 0.0;
}

require __DIR__ . '/_header.php';
?>
<div class="title">
    <div>
        <div class="eyebrow">Admin Panel</div>
        <h1>Customer Feedback & Reviews</h1>
    </div>
    <div style="font-size: 13px; color: var(--muted); font-weight: 700;">
        <?= $feedbackTotal ?> Surveys • <?= $reviewTotal ?> Product Reviews
    </div>
</div>

<!-- Summary Metric Cards -->
<div class="cards" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));">
    <div class="card">
        <small>TOTAL RESPONSES</small>
        <div class="metric"><?= $feedbackTotal > 0 ? $feedbackTotal : $reviewTotal ?></div>
        <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Survey & Review submissions</div>
    </div>
    <div class="card">
        <small>AVERAGE SATISFACTION</small>
        <div class="metric" style="color: var(--orange);">
            <?= number_format($avgRating, 1) ?> <span style="font-size: 18px; vertical-align: middle;">★</span>
        </div>
        <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Out of 5.0 overall rating</div>
    </div>
    <div class="card">
        <small>TOP FAVORITE FLAVOR</small>
        <div class="metric" style="font-size: 20px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
            <?= admin_escape($topFlavor) ?>
        </div>
        <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Leading pick from customers</div>
    </div>
    <div class="card">
        <small>PORTION SATISFACTION</small>
        <div class="metric" style="color: var(--green);">
            <?= $feedbackTotal > 0 ? round(($portionRightCount / $feedbackTotal) * 100) : 100 ?>%
        </div>
        <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Rated portion as "Just right"</div>
    </div>
</div>


<!-- Product Reviews & Feedback Table -->
<section class="panel">
    <h2>Product Reviews & Customer Comments</h2>
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
                    <td style="max-width: 360px; line-height: 1.4; font-size: 13px;">
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
