<?php
// Cheap change-detector polled by live-sync.js.
//
// It returns one fingerprint per dataset rather than any actual content. The
// client only pulls a fresh page render when a fingerprint it cares about
// moves, so the steady-state cost of "always up to date" is this endpoint
// alone - a handful of hashes - instead of re-sending every table every tick.
include 'db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Hashing the whole row (t::text renders the record as a literal) means any
// column change is caught - a new pet, an edited breed, a status flip, an
// archive - without having to list the columns that matter per table.
function tableFingerprint($conn, $table) {
    try {
        $sql = "SELECT md5(coalesce(string_agg(t::text, ',' ORDER BY t.id), '')) FROM $table t";
        return (string)($conn->query($sql)->fetchColumn() ?: '');
    } catch (PDOException $e) {
        // Returning a constant on failure means the client simply sees "no
        // change" and retries next tick, rather than refresh-looping.
        error_log("live_version: fingerprint failed for $table: " . $e->getMessage());
        return 'unavailable';
    }
}

// Public data - index.php shows these to signed-out visitors too.
$versions = [
    'pets'      => tableFingerprint($conn, 'pets'),
    'lost_pets' => tableFingerprint($conn, 'lost_pets'),
    'shelters'  => tableFingerprint($conn, 'shelters'),
];

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $versions['applications'] = tableFingerprint($conn, 'applications');
}

if (isset($_SESSION['user_id'])) {
    // Scoped to this user so one applicant's notification bell doesn't churn
    // every time an unrelated application changes.
    try {
        // user_id is compared as text so this holds whether the column is
        // integer or varchar.
        $stmt = $conn->prepare(
            "SELECT md5(coalesce(string_agg(a::text, ',' ORDER BY a.id), '')) FROM applications a WHERE a.user_id::text = ?"
        );
        $stmt->execute([(string)$_SESSION['user_id']]);
        $versions['notifications'] = (string)($stmt->fetchColumn() ?: '');
    } catch (PDOException $e) {
        error_log("live_version: notification fingerprint failed: " . $e->getMessage());
        $versions['notifications'] = 'unavailable';
    }
}

echo json_encode($versions);
