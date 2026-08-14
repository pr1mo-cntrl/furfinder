<?php
session_start();

// Load DATABASE_URL from a local .env file if it's not already set as a real env var
if (getenv('DATABASE_URL') === false) {
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

$uri = getenv('DATABASE_URL');
if (!$uri) {
    die("Database configuration missing. Set the DATABASE_URL environment variable (see .env.example).");
}

$db_parsed = parse_url($uri);
$host = $db_parsed['host'];
$port = $db_parsed['port'];
$dbname = ltrim($db_parsed['path'], '/');
$user = $db_parsed['user'];
$pass = $db_parsed['pass'];

try {
    $conn = new PDO("pgsql:host=$host;port=$port;dbname=$dbname;user=$user;password=$pass");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}