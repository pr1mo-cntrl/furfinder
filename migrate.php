<?php
// One-off schema updates for the live Postgres database. Safe to open more than
// once - every statement is guarded with IF NOT EXISTS.
//
// Open this in a browser while signed in as an admin, then you can forget about
// it. It is NOT run automatically: DDL on every page load would take a table
// lock several times a minute once live-sync starts polling.
include 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die('Admins only. <a href="login.php">Sign in</a> first.');
}

header('Content-Type: text/html; charset=utf-8');

$migrations = [
    // The admin notification feed merges applications and lost pet reports into
    // one list ordered newest-first, which needs a sort key the two tables
    // share. NOW() as the default means existing rows all land on the migration
    // timestamp - they tie, and fall back to id order - while everything filed
    // from here on sorts correctly.
    'applications.created_at' =>
        "ALTER TABLE applications ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT NOW()",
    'lost_pets.created_at' =>
        "ALTER TABLE lost_pets ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT NOW()",
];

echo '<!doctype html><meta charset="utf-8"><title>FurFinder migrations</title>';
echo '<body style="font-family: Segoe UI, sans-serif; max-width: 640px; margin: 40px auto; color: #333;">';
echo '<h1 style="color:#003366; font-size:1.4rem;">Database migrations</h1><ul style="line-height:1.9;">';

$failed = 0;
foreach ($migrations as $label => $sql) {
    try {
        $conn->exec($sql);
        echo '<li>✅ <strong>' . htmlspecialchars($label) . '</strong> — up to date</li>';
    } catch (PDOException $e) {
        $failed++;
        error_log("migrate.php failed on $label: " . $e->getMessage());
        echo '<li>❌ <strong>' . htmlspecialchars($label) . '</strong> — ' . htmlspecialchars($e->getMessage()) . '</li>';
    }
}

echo '</ul>';
echo $failed
    ? '<p style="color:#dc3545;"><strong>' . $failed . ' migration(s) failed.</strong> The admin notification list will keep working, just grouped by type instead of merged by time.</p>'
    : '<p style="color:#28a745;"><strong>All good.</strong> Notifications are now ordered newest-first across both types.</p>';
echo '<p><a href="admin.php">← Back to the admin panel</a></p></body>';
