 <?php
$uri = "postgresql://postgres.hmsmjxdkvaklpuvsrdfv:[REDACTED_ROTATED]@aws-0-ap-southeast-1.pooler.supabase.com:6543/postgres";

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

//Session Tracking
session_start();
?>