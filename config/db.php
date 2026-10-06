<?php
declare(strict_types=1);

// Supabase Direct PostgreSQL Connection via PDO
$host     = $_ENV['SUPABASE_DB_HOST'] ?? getenv('SUPABASE_DB_HOST') ?: 'aws-0-ap-southeast-1.pooler.supabase.com';
$username = $_ENV['SUPABASE_DB_USER'] ?? getenv('SUPABASE_DB_USER') ?: 'postgres.ghrexntoqduadflakijc';
$password = $_ENV['SUPABASE_DB_PASSWORD'] ?? getenv('SUPABASE_DB_PASSWORD') ?: 'wFqNd8dyxWCeUHrZ';
$database = $_ENV['SUPABASE_DB_NAME'] ?? getenv('SUPABASE_DB_NAME') ?: 'postgres';
$port     = (string) ($_ENV['SUPABASE_DB_PORT'] ?? getenv('SUPABASE_DB_PORT') ?: '6543');

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode=require";

    $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    die('Database connection failed: ' . $e->getMessage());
}
