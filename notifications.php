<?php
include 'db.php';
include 'notification_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

list($html, $count) = buildUserNotifications($conn, $_SESSION['user_id']);

echo json_encode([
    'count' => $count,
    'html' => $html,
]);
