<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

// Retrieve input data (supports JSON body or Form POST)
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$respondentType = trim((string) ($data['respondent_type'] ?? 'Student'));
if ($respondentType === '') {
    $respondentType = 'Student';
}

$favoriteFlavor = trim((string) ($data['favorite_flavor'] ?? 'Spam & Egg'));
$portionSize = trim((string) ($data['portion_size'] ?? 'Just right'));
$priceFairness = trim((string) ($data['price_fairness'] ?? 'Fair'));
$suggestedFlavor = trim((string) ($data['suggested_flavor'] ?? ''));
$suggestedOffer = trim((string) ($data['suggested_offer'] ?? ''));
$lovedMost = trim((string) ($data['loved_most'] ?? ''));
$comments = trim((string) ($data['comments'] ?? ''));
$websiteIssues = trim((string) ($data['website_issues'] ?? 'No'));

$rating = max(1, min(5, (int) ($data['rating'] ?? 5)));
$userId = current_user_id();

// Lookup product_id by flavor name
$productId = null;
if (!empty($favoriteFlavor) && isset($conn) && $conn instanceof PDO) {
    try {
        $pStmt = $conn->prepare('SELECT id FROM products WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $pStmt->execute([':name' => $favoriteFlavor]);
        $foundId = $pStmt->fetchColumn();
        if ($foundId) {
            $productId = (int) $foundId;
        }
    } catch (Throwable $e) {
        $productId = null;
    }
}

// Prepare review comment
$commentParts = [];
if ($lovedMost !== '') {
    $commentParts[] = "Loved: {$lovedMost}.";
}
if ($comments !== '') {
    $commentParts[] = "Comments: {$comments}.";
}
if ($portionSize !== '') {
    $commentParts[] = "Portion: {$portionSize}.";
}
if ($priceFairness !== '') {
    $commentParts[] = "Price: {$priceFairness}.";
}
$reviewComment = !empty($commentParts) ? implode(' ', $commentParts) : ($comments ?: 'Great experience with ONISUBI!');

// Save to customer_feedback and reviews
if (isset($conn) && $conn instanceof PDO) {
    try {
        // 1. Insert into customer_feedback table
        $fbStmt = $conn->prepare('
            INSERT INTO public.customer_feedback (
                submitted_at,
                respondent_type,
                portion_size,
                favorite_flavor,
                price_fairness,
                website_issues,
                suggested_flavor,
                suggested_offer,
                loved_most,
                comments,
                rating_taste,
                rating_portion,
                rating_price,
                rating_overall,
                average_rating
            ) VALUES (
                CURRENT_TIMESTAMP,
                :respondent_type,
                :portion_size,
                :favorite_flavor,
                :price_fairness,
                :website_issues,
                :suggested_flavor,
                :suggested_offer,
                :loved_most,
                :comments,
                :rating_taste,
                :rating_portion,
                :rating_price,
                :rating_overall,
                :average_rating
            )
        ');
        $fbStmt->execute([
            ':respondent_type' => $respondentType,
            ':portion_size'    => $portionSize,
            ':favorite_flavor' => $favoriteFlavor,
            ':price_fairness'  => $priceFairness,
            ':website_issues'  => $websiteIssues,
            ':suggested_flavor'=> $suggestedFlavor,
            ':suggested_offer' => $suggestedOffer,
            ':loved_most'      => $lovedMost,
            ':comments'        => $comments,
            ':rating_taste'    => $rating,
            ':rating_portion'  => $rating,
            ':rating_price'    => $rating,
            ':rating_overall'  => $rating,
            ':average_rating'  => (float) $rating,
        ]);

        // 2. Insert into reviews table
        $customerDisplayName = $respondentType;
        if (isset($_SESSION['user_name']) && trim((string)$_SESSION['user_name']) !== '') {
            $customerDisplayName = trim((string)$_SESSION['user_name']) . " ({$respondentType})";
        }

        $revStmt = $conn->prepare('
            INSERT INTO public.reviews (
                user_id,
                product_id,
                customer_name,
                rating,
                comment,
                created_at
            ) VALUES (
                :user_id,
                :product_id,
                :customer_name,
                :rating,
                :comment,
                CURRENT_TIMESTAMP
            )
        ');
        $revStmt->execute([
            ':user_id'       => $userId,
            ':product_id'    => $productId,
            ':customer_name' => $customerDisplayName,
            ':rating'        => $rating,
            ':comment'       => $reviewComment,
        ]);

        json_response([
            'success' => true,
            'message' => 'Thank you for your valuable feedback! Your review has been recorded.'
        ]);
    } catch (Throwable $e) {
        json_response([
            'success' => false,
            'error'   => 'Failed to save feedback: ' . $e->getMessage()
        ], 500);
    }
} else {
    json_response(['success' => false, 'error' => 'Database not connected'], 500);
}
