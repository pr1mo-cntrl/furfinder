<?php
// Supabase PostgreSQL Database Connection
$host = "aws-0-ap-southeast-1.pooler.supabase.com"; // Supabase connection pooler
$port = "6543";
$dbname = "postgres";
$user = "postgres.your_project_id";
$password = "your_secure_supabase_password";

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $conn = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Session Tracking
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>