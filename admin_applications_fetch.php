<?php
include 'db.php';
include 'admin_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$html = '';
$apps = $conn->query("SELECT * FROM applications WHERE is_archived = 0 ORDER BY id DESC");
while ($row = $apps->fetch(PDO::FETCH_ASSOC)) {
    $html .= renderApplicationRow($row);
}

echo json_encode(['html' => $html]);
