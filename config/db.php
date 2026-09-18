<?php

$host = "aws-0-ap-southeast-1.pooler.supabase.com";
$username = "postgres.ghrexntoqduadflakijc";
$password = "wFqNd8dyxWCeUHrZ";
$database = "postgres";
$port = "6543";

try {
  $dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=require";

  $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
