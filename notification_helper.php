<?php
// Builds the notification list rendered into index.php's bell.
//
// Two kinds of alert are merged into one list, newest first:
//   - application: an update on one of this user's own adoption applications
//   - found_pet:   a lost pet reunited with its owner, announced to everyone
//
// Both carry a data-kind/data-id pair, which is how notifications.js decides
// what is unread without any read flag in the database.

// A reunited pet stays announced, and stays on the public "Reunited" feed, for
// this long after being marked found.
const FOUND_ANNOUNCEMENT_DAYS = 3;

function notificationAgo($timestamp) {
    if (empty($timestamp)) return '';
    $then = strtotime($timestamp);
    if (!$then) return '';
    $diff = time() - $then;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $then);
}

// The user's own application updates. status_updated_at is what the applicant
// actually cares about - a decision made today on an application filed last
// month should sort as today - but it only exists after migrate.php has run.
function fetchApplicationNotifications($conn, $user_id) {
    $base = "SELECT id, pet_name, status, %s AS ts
               FROM applications
              WHERE user_id::text = ? AND status IN ('Pending', 'Approved', 'Rejected')
           ORDER BY id DESC";
    try {
        $stmt = $conn->prepare(sprintf($base, 'COALESCE(status_updated_at, created_at)'));
        $stmt->execute([(string)$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Notifications: no application timestamps yet (run migrate.php?): ' . $e->getMessage());
        $stmt = $conn->prepare(sprintf($base, 'NULL::timestamptz'));
        $stmt->execute([(string)$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Recently reunited pets, announced to every signed-in user. Pre-existing rows
// have no found_at, so created_at stands in - which after the migration is the
// migration timestamp, giving them one final window rather than vanishing.
function fetchFoundNotifications($conn) {
    $sql = "SELECT id, pet_name, location, COALESCE(found_at, created_at) AS ts
              FROM lost_pets
             WHERE status = 'Found' AND is_archived = 0
               AND COALESCE(found_at, created_at) > NOW() - INTERVAL '" . FOUND_ANNOUNCEMENT_DAYS . " days'
          ORDER BY ts DESC";
    try {
        $res = $conn->query($sql);
        return $res ? $res->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        error_log('Notifications: found-pet announcements unavailable (run migrate.php?): ' . $e->getMessage());
        return [];
    }
}

function buildUserNotifications($conn, $user_id) {
    $items = [];

    foreach (fetchApplicationNotifications($conn, $user_id) as $row) {
        $items[] = [
            'kind'    => 'application',
            'id'      => (int)$row['id'],
            'status'  => $row['status'],
            'subject' => $row['pet_name'],
            'ts'      => $row['ts'],
        ];
    }

    foreach (fetchFoundNotifications($conn) as $row) {
        $items[] = [
            'kind'    => 'found_pet',
            'id'      => (int)$row['id'],
            'status'  => 'Found',
            'subject' => $row['pet_name'],
            'meta'    => $row['location'],
            'ts'      => $row['ts'],
        ];
    }

    // Newest first. Rows with no timestamp (pre-migration) sort to the bottom
    // rather than jumping to the top as epoch 0 would sort ascending.
    usort($items, function ($a, $b) {
        $ta = empty($a['ts']) ? 0 : strtotime($a['ts']);
        $tb = empty($b['ts']) ? 0 : strtotime($b['ts']);
        if ($ta === $tb) return $b['id'] - $a['id'];
        return $tb - $ta;
    });

    $html = '';
    foreach ($items as $item) {
        $html .= renderUserNotification($item);
    }

    return [$html, count($items)];
}

function renderUserNotification($item) {
    $ago = notificationAgo($item['ts']);
    $id = $item['id'];

    if ($item['kind'] === 'found_pet') {
        $meta = htmlspecialchars($item['meta']);
        if ($ago !== '') $meta .= ' &middot; ' . htmlspecialchars($ago);
        return '
        <div class="notif-link" data-kind="found_pet" data-id="' . $id . '" data-goto="lost">
            <i class="fas fa-heart" style="color: var(--success);"></i>
            <span>
                <strong>' . htmlspecialchars($item['subject']) . '</strong> has been found and reunited!
                <span class="notif-meta">' . $meta . '</span>
            </span>
        </div>';
    }

    $pet = htmlspecialchars($item['subject']);
    $meta = $ago !== '' ? '<span class="notif-meta">' . htmlspecialchars($ago) . '</span>' : '';

    if ($item['status'] === 'Approved') {
        return '
        <div class="notif-link" data-kind="application" data-id="' . $id . '">
            <i class="fas fa-circle-check" style="color: var(--success);"></i>
            <span>
                <strong>Application approved!</strong>
                Your application to adopt <strong>' . $pet . '</strong> was approved. Please visit the
                Baguio City Veterinary and Agriculture Office (CVAO) for your screening and interview.
                ' . $meta . '
                <form method="POST" action="index.php">
                    <input type="hidden" name="app_id" value="' . $id . '">
                    <button type="submit" name="dismiss_notification" class="notif-dismiss-btn notif-dismiss-approved">Okay, got it!</button>
                </form>
            </span>
        </div>';
    }

    if ($item['status'] === 'Rejected') {
        return '
        <div class="notif-link" data-kind="application" data-id="' . $id . '">
            <i class="fas fa-circle-xmark" style="color: var(--danger);"></i>
            <span>
                <strong>Application update</strong>
                Your application to adopt <strong>' . $pet . '</strong> was not approved at this time.
                Thank you for your interest in giving a shelter pet a home.
                ' . $meta . '
                <form method="POST" action="index.php">
                    <input type="hidden" name="app_id" value="' . $id . '">
                    <button type="submit" name="dismiss_notification" class="notif-dismiss-btn notif-dismiss-rejected">Dismiss</button>
                </form>
            </span>
        </div>';
    }

    return '
    <div class="notif-link" data-kind="application" data-id="' . $id . '">
        <i class="fas fa-hourglass-half" style="color: var(--accent-color);"></i>
        <span>
            <strong>Application pending</strong>
            Your application to adopt <strong>' . $pet . '</strong> is under review by the CVAO team.
            We will update you here as soon as a decision is made.
            ' . $meta . '
        </span>
    </div>';
}
