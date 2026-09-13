<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json');

$email = strtolower(trim((string) ($_GET['email'] ?? '')));
if ($email === '') {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $conn->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);

$exists = $stmt->fetchColumn() !== false;

echo json_encode(['exists' => $exists]);
exit;
