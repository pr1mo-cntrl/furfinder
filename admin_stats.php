<?php
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$dog_count = $conn->query("SELECT COUNT(*) FROM pets WHERE type='dog' AND is_archived=0")->fetchColumn() ?: 0;
$cat_count = $conn->query("SELECT COUNT(*) FROM pets WHERE type='cat' AND is_archived=0")->fetchColumn() ?: 0;
$app_pending = $conn->query("SELECT COUNT(*) FROM applications WHERE status LIKE 'Pending%' AND is_archived=0")->fetchColumn() ?: 0;
$lost_reports = $conn->query("SELECT COUNT(*) FROM lost_pets WHERE LOWER(status)='missing'")->fetchColumn() ?: 0;

echo json_encode([
    'total_pets' => (int)$dog_count + (int)$cat_count,
    'pending_applications' => (int)$app_pending,
    'lost_reports' => (int)$lost_reports,
]);
