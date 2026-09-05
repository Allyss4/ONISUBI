<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

header('Content-Type: application/json');

$email = strtolower(trim((string) ($_GET['email'] ?? '')));
if ($email === '') {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

echo json_encode(['exists' => $result->num_rows > 0]);
exit;
