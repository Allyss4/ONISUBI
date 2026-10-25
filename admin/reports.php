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

<?php if (!empty($feedbacks)): ?>
<!-- Survey Responses Panel -->
<section class="panel" style="margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 style="margin: 0;">Google Form Customer Survey Responses</h2>
            <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">
                Detailed responses collected from ONISUBI Customer Feedback Form
            </div>
        </div>
        <?php if ($bugCount > 0): ?>
            <span class="badge" style="background: #fce8e6; color: #a94442; border: 1px solid #f5c2be;">
                ⚠ <?= $bugCount ?> Website Bug/Lag Report<?= $bugCount > 1 ? 's' : '' ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="tablewrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Respondent</th>
                    <th>Favorite Flavor</th>
                    <th>Portion</th>
                    <th>₱40 Fair Price</th>
                    <th>Satisfaction</th>
                    <th>Requested Flavor & Offer</th>
                    <th>Feedback & Highlights</th>
                    <th>Website Issue</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($feedbacks as $fb):
                $portion = (string) ($fb['portion_size'] ?? '');
                $portionClass = 'active';
                if (strtolower($portion) === 'too big') $portionClass = 'pending';
                if (strtolower($portion) === 'too small') $portionClass = 'rejected';

                $priceFair = (string) ($fb['price_fairness'] ?? '');
                $priceBadgeStyle = 'background: #f4ece4; color: var(--ink);';
                if (str_contains(strtolower($priceFair), 'affordable') || strtolower($priceFair) === 'fair') {
                    $priceBadgeStyle = 'background: #e4f1e5; color: #38603f;';
                } elseif (str_contains(strtolower($priceFair), 'expensive')) {
                    $priceBadgeStyle = 'background: #fff0d3; color: #8a5b12;';
                }

                $issues = trim((string) ($fb['website_issues'] ?? ''));
                $hasIssue = ($issues !== '' && strtolower($issues) !== 'no');
                $rating = round((float) ($fb['average_rating'] ?? $fb['rating_overall'] ?? 5));
            ?>
                <tr>
                    <td>
                        <strong><?= admin_escape((string) $fb['respondent_type']) ?></strong>
                    </td>
                    <td>
                        <span class="badge" style="background: var(--cream); color: var(--brown); border: 1px solid var(--line);">
                            <?= admin_escape((string) $fb['favorite_flavor']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $portionClass ?>">
                            <?= admin_escape($portion) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge" style="<?= $priceBadgeStyle ?>">
                            <?= admin_escape($priceFair) ?>
                        </span>
                    </td>
                    <td>
                        <span style="color: var(--orange); font-size: 14px; letter-spacing: 1px;">
                            <?= str_repeat('★', (int) $rating) ?><span style="color: var(--beige);"><?= str_repeat('☆', max(0, 5 - (int) $rating)) ?></span>
                        </span>
                        <div style="font-size: 10px; color: var(--muted); font-weight: 700;">
                            Avg: <?= number_format((float)($fb['average_rating'] ?? $rating), 1) ?>/5
                        </div>
                    </td>
                    <td style="font-size: 12px; line-height: 1.4;">
                        <div><strong>New:</strong> <?= admin_escape((string) $fb['suggested_flavor']) ?></div>
                        <div style="color: var(--muted);"><strong>Offer:</strong> <?= admin_escape((string) $fb['suggested_offer']) ?></div>
                    </td>
                    <td style="max-width: 260px; font-size: 12px; line-height: 1.4;">
                        <?php if (!empty($fb['loved_most']) && strtolower(trim((string)$fb['loved_most'])) !== 'n/a'): ?>
                            <div><strong style="color: var(--brown);">Loved:</strong> <?= admin_escape((string) $fb['loved_most']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($fb['comments']) && strtolower(trim((string)$fb['comments'])) !== 'n/a'): ?>
                            <div style="color: var(--muted);"><strong style="color: var(--ink);">Notes:</strong> <?= admin_escape((string) $fb['comments']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($hasIssue): ?>
                            <span class="badge" style="background: #fce8e6; color: #a94442; font-size: 11px;">
                                <?= admin_escape($issues) ?>
                            </span>
                        <?php else: ?>
                            <span style="color: var(--muted); font-size: 12px;">None</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 11px; color: var(--muted); white-space: nowrap;">
                        <?= admin_escape(date('M j, Y g:i A', strtotime((string) $fb['submitted_at']))) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

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
