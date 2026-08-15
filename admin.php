<?php
include 'db.php';
include 'admin_helpers.php';

// FIX: Start session and verify admin BEFORE any data can be modified
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// If an uploaded file exceeds post_max_size, PHP silently empties $_POST and
// $_FILES entirely - without this check the page just reloads with no
// feedback and it looks like the form submission vanished.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['admin_flash'] = "Your upload was too large for the server to accept. Please use a smaller photo (under 12MB) and try again.";
    $_SESSION['admin_flash_type'] = 'error';
    header("Location: admin.php");
    exit();
}

// --- PHP HANDLERS ---

// 1. Handle New Pet (Supabase Cloud Storage & PRG Redirect Fix)
if (isset($_POST['add_pet'])) {
    $name = $_POST['name'];
    $breed = trim($_POST['breed']);
    if ($breed === 'Mixed Breed' && !empty($_POST['custom_breed'])) {
        $breed = 'Mixed Breed (' . trim($_POST['custom_breed']) . ')';
    }
    $age = $_POST['age'];
    $type = $_POST['type'];
    $backstory = $_POST['backstory'];
    $medical_history = $_POST['medical_history'];

    if (isset($_FILES["pet_photo"]) && $_FILES["pet_photo"]["error"] == 0) {
        $file_tmp = $_FILES["pet_photo"]["tmp_name"];
        $file_name = $_FILES["pet_photo"]["name"];
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_filename = time() . "_" . uniqid() . "." . $file_ext;
        
        $supabase_url = getenv('SUPABASE_URL');
        $supabase_key = getenv('SUPABASE_SERVICE_KEY');
        
        $storage_url = "$supabase_url/storage/v1/object/pet-photos/$unique_filename";
        $file_data = file_get_contents($file_tmp);
        
        $ch = curl_init($storage_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_data);
        
        // CRITICAL FIX: Bypass strict SSL verification for Render's environment
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $supabase_key",
            "Content-Type: " . $_FILES["pet_photo"]["type"],
            "x-upsert: true"
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 || $http_code === 201) {
            $image_url = "$supabase_url/storage/v1/object/public/pet-photos/$unique_filename";
            
            $stmt = $conn->prepare("INSERT INTO pets (name, breed, age, backstory, medical_history, image_url, type) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $breed, $age, $backstory, $medical_history, $image_url, $type]);
            
            header("Location: admin.php");
            exit();
        } else {
            error_log("Supabase upload failed (HTTP $http_code): $response");
            $_SESSION['admin_flash'] = "We couldn't upload that photo right now. Please try again.";
            $_SESSION['admin_flash_type'] = 'error';
            header("Location: admin.php");
            exit();
        }
    } else {
        $upload_error = isset($_FILES["pet_photo"]) ? $_FILES["pet_photo"]["error"] : UPLOAD_ERR_NO_FILE;
        error_log("Add pet blocked, photo upload error code: $upload_error");
        $_SESSION['admin_flash'] = ($upload_error == UPLOAD_ERR_INI_SIZE || $upload_error == UPLOAD_ERR_FORM_SIZE)
            ? "That photo is too large. Please use an image under 12MB."
            : "We couldn't read the pet photo upload. Please choose the photo again and resubmit.";
        $_SESSION['admin_flash_type'] = 'error';
        header("Location: admin.php");
        exit();
    }
}

// 2. Handle Pet Archiving (Soft Delete)
if (isset($_POST['delete_pet'])) {
    $id = $_POST['pet_id'];
    $stmt = $conn->prepare("UPDATE pets SET is_archived = 1 WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['admin_flash'] = 'Pet securely archived.';
    header("Location: admin.php");
    exit();
}

// 3. Handle Shelter Update
if (isset($_POST['update_shelter'])) {
    $id = $_POST['shelter_id'];
    $status = $_POST['status'];
    $email = $_POST['email'];
    $schedule = $_POST['schedule'];

    $stmt = $conn->prepare("UPDATE shelters SET status = ?, email = ?, schedule = ? WHERE id = ?");
    $stmt->execute([$status, $email, $schedule, $id]);
    $_SESSION['admin_flash'] = 'Shelter details updated.';
    header("Location: admin.php");
    exit();
}

// 4. Handle Application Status Update (Removed duplicate block from top of file)
if (isset($_POST['update_application'])) {
    $id = $_POST['app_id'];
    $status = $_POST['update_application'];
    // status_updated_at is what the applicant's notification sorts on.
    try {
        $stmt = $conn->prepare("UPDATE applications SET status = ?, status_updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $id]);
    } catch (PDOException $e) {
        error_log('update_application: no status_updated_at column yet (run migrate.php?): ' . $e->getMessage());
        $stmt = $conn->prepare("UPDATE applications SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
    }
    $_SESSION['admin_flash'] = 'Application status updated to ' . $status . '.';
    header("Location: admin.php");
    exit();
}

// 5. Handle Lost Pet Status Update (Mark Found)
if (isset($_POST['mark_found'])) {
    $id = $_POST['lost_pet_id'];
    // found_at drives the announcement in every user's bell and the 3-day
    // window the resolved post stays on the public feed. Falls back if
    // migrate.php hasn't added the column yet.
    try {
        $stmt = $conn->prepare("UPDATE lost_pets SET status = 'Found', found_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log('mark_found: no found_at column yet (run migrate.php?): ' . $e->getMessage());
        $stmt = $conn->prepare("UPDATE lost_pets SET status = 'Found' WHERE id = ?");
        $stmt->execute([$id]);
    }
    $_SESSION['admin_flash'] = 'Lost pet marked as found. All users have been notified.';
    header("Location: admin.php");
    exit();
}

// 6. Handle Application Archive (Soft Delete)
if (isset($_POST['archive_application'])) {
    $id = $_POST['app_id'];
    $stmt = $conn->prepare("UPDATE applications SET is_archived = 1 WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['admin_flash'] = 'Application securely archived.';
    header("Location: admin.php");
    exit();
}

// 7. Handle Lost Pet Archiving (Soft Delete)
if (isset($_POST['delete_lost_pet'])) {
    $id = $_POST['lost_pet_id'];
    $stmt = $conn->prepare("UPDATE lost_pets SET is_archived = 1 WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['admin_flash'] = 'Lost pet post archived securely.';
    header("Location: admin.php");
    exit();
}

// Handle Restoring Lost Pets
if (isset($_POST['restore_lost_pet'])) {
    $id = $_POST['lost_pet_id'];
    $stmt = $conn->prepare("UPDATE lost_pets SET is_archived = 0 WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['admin_flash'] = 'Lost pet restored successfully.';
    header("Location: admin.php");
    exit();
}

// 8. Handle Pet Update
if (isset($_POST['update_pet'])) {
    $id = $_POST['edit_pet_id'];
    $name = $_POST['edit_name'];
    $breed = $_POST['edit_breed'];
    $age = $_POST['edit_age'];
    $backstory = $_POST['edit_backstory'];
    $medical_history = $_POST['edit_medical_history'];

    $stmt = $conn->prepare("UPDATE pets SET name=?, breed=?, age=?, backstory=?, medical_history=? WHERE id=?");
    if($stmt->execute([$name, $breed, $age, $backstory, $medical_history, $id])){
        $_SESSION['admin_flash'] = 'Pet details updated successfully.';
        header("Location: admin.php");
        exit();
    }
}

// 9. Handle Pet Adopted
if (isset($_POST['mark_adopted'])) {
    $id = $_POST['pet_id'];
    $stmt = $conn->prepare("UPDATE pets SET status = 'adopted' WHERE id = ?");
    if($stmt->execute([$id])){
        $_SESSION['admin_flash'] = 'Pet marked as adopted.';
        header("Location: admin.php");
        exit();
    }
}

// 10. Handle Restoring Archived Items
if (isset($_POST['restore_pet'])) {
    $id = $_POST['pet_id'];
    $stmt = $conn->prepare("UPDATE pets SET is_archived = 0 WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['admin_flash'] = 'Pet restored successfully.';
    header("Location: admin.php");
    exit();
}

if (isset($_POST['restore_application'])) {
    $id = $_POST['app_id'];
    $stmt = $conn->prepare("UPDATE applications SET is_archived = 0 WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['admin_flash'] = 'Application restored successfully.';
    header("Location: admin.php");
    exit();
}

// --- DESCRIPTIVE ANALYTICS DATA FETCHING ---
// FIX: Using fetchColumn() prevents fatal array offset errors if the database stutters
$dog_count = $conn->query("SELECT COUNT(*) FROM pets WHERE type='dog' AND is_archived=0")->fetchColumn() ?: 0;
$cat_count = $conn->query("SELECT COUNT(*) FROM pets WHERE type='cat' AND is_archived=0")->fetchColumn() ?: 0;

$breed_labels = [];
$breed_counts = [];
$breed_res = $conn->query("SELECT breed, COUNT(*) as count FROM pets WHERE status='available' AND is_archived=0 GROUP BY breed ORDER BY count DESC LIMIT 5");
if ($breed_res) {
    while($row = $breed_res->fetch(PDO::FETCH_ASSOC)) {
        $breed_labels[] = $row['breed'];
        $breed_counts[] = $row['count'];
    }
}

$app_pending = $conn->query("SELECT COUNT(*) FROM applications WHERE status LIKE 'Pending%' AND is_archived=0")->fetchColumn() ?: 0;
$app_approved = $conn->query("SELECT COUNT(*) FROM applications WHERE status LIKE 'Approved%' AND is_archived=0")->fetchColumn() ?: 0;
$app_rejected = $conn->query("SELECT COUNT(*) FROM applications WHERE (status LIKE 'Rejected%' OR status='Acknowledged') AND is_archived=0")->fetchColumn() ?: 0;

// --- ADMIN NOTIFICATION FEED ---
// Incoming work the admin should be told about: someone applying to adopt, and
// someone posting a lost pet. Both kinds go into one list, newest first.
//
// created_at is what makes that single ordering possible - see migrate.php. If
// that migration hasn't been run yet the query below throws, and we fall back
// to per-table id order so the bell still works, just grouped by type.
$notif_items = [];
$notif_merged_by_time = true;

$notif_sql = "
    SELECT id, 'application'::text AS kind, fullname::text AS actor, pet_name::text AS subject, status::text AS meta, created_at
      FROM applications
     WHERE is_archived = 0
    UNION ALL
    SELECT id, 'lost_pet'::text AS kind, NULL::text AS actor, pet_name::text AS subject, location::text AS meta, created_at
      FROM lost_pets
     WHERE is_archived = 0 AND status = 'Missing'
     ORDER BY created_at DESC, id DESC
     LIMIT 15";

try {
    $notif_items = $conn->query($notif_sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Admin notifications: falling back to id order (run migrate.php?): " . $e->getMessage());
    $notif_merged_by_time = false;
    $notif_items = [];

    $res = $conn->query("SELECT id, pet_name, fullname, status FROM applications WHERE is_archived = 0 ORDER BY id DESC LIMIT 10");
    foreach (($res ? $res->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
        $notif_items[] = ['id' => $r['id'], 'kind' => 'application', 'actor' => $r['fullname'], 'subject' => $r['pet_name'], 'meta' => $r['status'], 'created_at' => null];
    }
    $res = $conn->query("SELECT id, pet_name, location FROM lost_pets WHERE is_archived = 0 AND status = 'Missing' ORDER BY id DESC LIMIT 10");
    foreach (($res ? $res->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
        $notif_items[] = ['id' => $r['id'], 'kind' => 'lost_pet', 'actor' => null, 'subject' => $r['pet_name'], 'meta' => $r['location'], 'created_at' => null];
    }
}

function notifAgo($timestamp) {
    if (empty($timestamp)) return '';
    $then = strtotime($timestamp);
    if (!$then) return '';
    $diff = time() - $then;
    if ($diff < 0)     return 'just now';
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $then);
}

// Emitted as a JSON island the charts read from, so live-sync can swap the
// numbers in the same way it swaps a table body.
$analytics_payload = [
    'types'    => ['dogs' => (int)$dog_count, 'cats' => (int)$cat_count],
    'breeds'   => ['labels' => $breed_labels, 'counts' => array_map('intval', $breed_counts)],
    'pipeline' => ['pending' => (int)$app_pending, 'approved' => (int)$app_approved, 'rejected' => (int)$app_rejected],
];

// A background live-sync render must not consume one-shot flash messages that
// were queued for the user's next real page load.
$is_live_fetch = isset($_GET['live']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | FurFinder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="live-sync.js" defer></script>
    <script src="notifications.js" defer></script>
    <style>
        :root {
            --primary-color: #003366;
            --accent-color: #d4af37;
            --bg-light: #f4f7f6;
            --text-dark: #333;
            --white: #ffffff;
            --danger: #dc3545;
            --success: #28a745;
            --border-color: #e2e5e9;
            --radius: 6px;
            --shadow-sm: 0 1px 2px rgba(16,24,40,0.06);
            --shadow-md: 0 1px 3px rgba(16,24,40,0.08), 0 4px 12px rgba(16,24,40,0.06);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Open Sans', 'Segoe UI', sans-serif; }
        body { display: flex; min-height: 100vh; background-color: var(--bg-light); color: var(--text-dark); }
        .sidebar {
            width: 240px;
            background-color: var(--primary-color);
            color: var(--white);
            padding: 20px 0;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 100;
        }
        .sidebar h2 { text-align: center; margin-bottom: 30px; color: var(--accent-color); font-size: 1.4rem; font-weight: 700; letter-spacing: -0.01em; }
        .sidebar ul { list-style: none; padding: 0; width: 100%; }
        .sidebar ul li a {
            display: block;
            padding: 13px 20px;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 500;
            transition: background-color 0.15s ease, color 0.15s ease;
            border-left: 3px solid transparent;
        }
        .sidebar ul li a:hover, .sidebar ul li a.active {
            background-color: rgba(255,255,255,0.08);
            border-left-color: var(--accent-color);
            color: var(--white);
        }
        .sidebar .logout { margin-top: auto; }
        .sidebar .logout a { color: #ff8a94; }
        .sidebar .logout a:hover { background-color: rgba(220, 53, 69, 0.15); border-left-color: var(--danger); color: white; }
        .sidebar-toggle { display: none; }

        .content {
            flex-grow: 1;
            padding: 32px 40px;
            display: block;
            min-width: 0;
        }
        .section {
            background: var(--white);
            padding: 28px;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            display: none;
        }
        .section.active { display: block; }
        .section h3 { margin-bottom: 18px; color: var(--primary-color); font-size: 1.15rem; font-weight: 700; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; }
        .section-note { font-size: 0.875rem; color: #767e89; margin: -6px 0 20px; }

        /* Admin notification bell */
        .admin-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
        .admin-header h1 { font-size: 1.6rem; font-weight: 700; color: var(--text-dark); }
        .notif-bell-wrap { position: relative; flex-shrink: 0; }
        .notif-bell {
            position: relative;
            color: var(--primary-color);
            font-size: 1.15rem;
            cursor: pointer;
            padding: 9px 12px;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            background: var(--white);
            display: inline-block;
            box-shadow: var(--shadow-sm);
        }
        .notif-bell:hover { background-color: #f8f9fa; }
        .notif-badge {
            position: absolute; top: -6px; right: -6px;
            background: var(--danger); color: var(--white);
            font-size: 0.65rem; font-weight: 700;
            min-width: 17px; height: 17px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px; border: 2px solid var(--white);
        }
        .notif-dropdown {
            display: none; position: absolute; top: 115%; right: 0;
            background: var(--white); color: var(--text-dark);
            width: 340px; max-width: 90vw; max-height: 420px; overflow-y: auto;
            border-radius: var(--radius); border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md); z-index: 3000; text-align: left;
        }
        .notif-dropdown.open { display: block; }
        .notif-dropdown-header {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 11px 14px; font-weight: 700; font-size: 0.85rem;
            border-bottom: 1px solid var(--border-color); background: #f8f9fa;
            position: sticky; top: 0;
        }
        .notif-markall { background: none; border: none; color: var(--primary-color); font-size: 0.75rem; font-weight: 600; cursor: pointer; padding: 0; }
        .notif-markall:hover { text-decoration: underline; }
        .notif-group { padding: 9px 14px 5px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #767e89; }
        .notif-link {
            display: flex; gap: 10px; align-items: flex-start; width: 100%;
            padding: 10px 14px; border: none; border-bottom: 1px solid #eee;
            background: none; text-align: left; cursor: pointer;
            font-family: inherit; font-size: 0.85rem; color: var(--text-dark);
        }
        .notif-link:hover { background: #f8f9fa; }
        .notif-link i { color: #767e89; margin-top: 2px; flex-shrink: 0; }
        .notif-link .notif-meta { display: block; color: #767e89; font-size: 0.75rem; margin-top: 2px; }
        /* Unread: highlighted until the next time the bell is opened. */
        .notif-link.is-new { background: #fffbe6; }
        .notif-link.is-new:hover { background: #fff6cc; }
        .notif-link.is-new i { color: var(--accent-color); }
        .notif-empty { padding: 22px 15px; color: #888; text-align: center; font-size: 0.88rem; }

        /* Shared tab bar - same visual language as the Manage Pets toolbar */
        .tab-bar { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 16px; flex-wrap: wrap; }
        .tab-btn {
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            background: #f8f9fa;
            color: #4a5568;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            flex: 1;
            min-width: 130px;
            transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        }
        .tab-btn:hover { border-color: #bbb; }
        .tab-btn.active { background: var(--primary-color); color: var(--white); border-color: var(--primary-color); }
        .tab-btn i { margin-right: 6px; }

        /* Analytics chart cards - match the .card / .section surface treatment */
        .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; max-width: 950px; margin: 0 auto; }
        .chart-card { background: var(--white); padding: 20px; border: 1px solid var(--border-color); border-radius: var(--radius); box-shadow: var(--shadow-sm); }
        .chart-card h4 { text-align: center; margin-bottom: 15px; color: #4a5568; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
        .chart-card-wide { grid-column: 1 / -1; }

        .table-empty { text-align: center; color: #767e89; padding: 18px 15px; }
        .thumb { width: 50px; height: 50px; object-fit: cover; border-radius: var(--radius); border: 1px solid var(--border-color); display: block; }
        .row-muted td { color: #767e89; }

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
            width: 100%;
        }
        .card {
            background: white;
            padding: 18px 20px;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            text-align: left;
        }
        .card h3 { margin-bottom: 8px; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #767e89; }
        .card p { font-size: 2.1rem; font-weight: 700; margin: 0; color: var(--primary-color); }

        .form-row { display: flex; gap: 12px; margin-bottom: 15px; align-items: center; flex-wrap: wrap; }
        .form-row input, .form-row select { padding: 9px 12px; border: 1px solid var(--border-color); border-radius: var(--radius); flex: 1; font-size: 0.9rem; }
        .btn-add { background: var(--success); color: white; border: none; padding: 10px 20px; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 0.9rem; }
        .btn-delete { background: var(--danger); color: white; border: none; padding: 7px 12px; border-radius: var(--radius); cursor: pointer; font-size: 0.85rem; font-weight: 600; }
        .btn-save { background: var(--primary-color); color: white; border: none; padding: 7px 12px; border-radius: var(--radius); cursor: pointer; font-size: 0.85rem; font-weight: 600; }

        .status-btn { border: 1px solid var(--border-color); background: #f8f9fa; color: #555; padding: 6px 12px; border-radius: var(--radius); cursor: pointer; font-size: 0.78rem; font-weight: 600; transition: all 0.15s ease; }
        .status-btn:hover { border-color: #bbb; }
        .status-btn-pending.active { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .status-btn-approved.active { background: var(--success); color: white; border-color: var(--success); }
        .status-btn-rejected.active { background: var(--danger); color: white; border-color: var(--danger); }

        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .data-table { width: 100%; min-width: 640px; border-collapse: collapse; margin-top: 16px; font-size: 0.9rem; }
        .data-table th, .data-table td { padding: 11px 15px; text-align: left; border-bottom: 1px solid var(--border-color); vertical-align: middle; }
        .data-table th { background-color: #f8f9fa; color: #4a5568; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.03em; border-bottom: 1px solid var(--border-color); }
        .data-table tr:hover { background-color: #fafbfc; }
        /* The shelter <form> lives inside a <tr>, so the parser hoists it out of the
           table - style the cells via the table id, not via the form class. */
        .app-status select, #shelter-table select, #shelter-table input {
            padding: 7px 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-family: inherit;
            color: var(--text-dark);
            background: var(--white);
        }
        .app-status { display: flex; gap: 5px; }
        #shelter-table input { width: 100%; min-width: 150px; }
        .shelter-actions { display: flex; gap: 8px; align-items: center; }

        .doc-link { color: var(--primary-color); text-decoration: underline; text-underline-offset: 2px; font-size: 0.85rem; display: block; margin-bottom: 3px;}
        .doc-link:hover { color: var(--accent-color); }

        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: sticky; padding: 12px 0; flex-direction: row; align-items: center; flex-wrap: wrap; }
            .sidebar h2 { display: none; }
            .sidebar ul { display: flex; flex-wrap: wrap; width: auto; }
            .sidebar ul li a { padding: 10px 14px; border-left: none; border-bottom: 3px solid transparent; font-size: 0.85rem; }
            .sidebar ul li a:hover, .sidebar ul li a.active { border-left-color: transparent; border-bottom-color: var(--accent-color); }
            .sidebar .logout { margin-top: 0; margin-left: auto; }
            .content { padding: 20px; }
            .section { padding: 18px; }
            .dashboard-stats { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
            .card p { font-size: 1.6rem; }
        }

        .modal {
            display: none; 
            position: fixed; 
            z-index: 2000; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.5); 
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 24px;
            border: 1px solid var(--border-color);
            width: 90%;
            max-width: 500px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            position: relative;
        }
        #appDetailsModal .modal-content {
            max-height: 80vh;
            overflow-y: auto;
            max-width: 600px;
        }

        .close-modal { color: #aaa; float: right; font-size: 26px; font-weight: bold; cursor: pointer; line-height: 1; }
        .close-modal:hover { color: black; }
        .modal-content label { display: block; margin-top: 15px; font-weight: 600; font-size: 0.85rem; color: #555; }
        .modal-content input { width: 100%; padding: 9px 12px; margin-top: 5px; border: 1px solid var(--border-color); border-radius: var(--radius); }
        .modal-content .btn-primary { margin-top: 20px; width: 100%; padding: 10px; background: var(--primary-color); color: white; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; }

        .btn-view { background: #17a2b8; color: white; border: none; padding: 5px 10px; border-radius: var(--radius); cursor: pointer; font-size: 0.8rem; margin-top: 5px; width: 100%; font-weight: 600; }
        .btn-view:hover { background: #138496; }
        .detail-row { display: flex; border-bottom: 1px solid #eee; padding: 10px 0; gap: 10px; }
        .detail-label { font-weight: bold; width: 180px; color: var(--primary-color); flex-shrink: 0; }

        .confirm-modal-content { max-width: 400px; text-align: center; }
        .confirm-modal-actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
        .btn-neutral { background-color: #6c757d; color: white; border: none; padding: 10px; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 0.9rem; flex: 1; }
        .btn-neutral:hover { background-color: #5a6268; }
        .confirm-modal-actions .btn-primary { flex: 1; margin-top: 0; }

        @media (max-width: 600px) {
            .modal-content { width: 94%; margin: 6% auto; padding: 18px; }
            .detail-row { flex-direction: column; gap: 2px; }
            .detail-label { width: auto; }
        }
        .detail-value { color: #333; flex-grow: 1; }
    </style>
</head>
<body>

    <?php if(isset($_SESSION['admin_flash']) && !$is_live_fetch):
        $admin_flash_is_error = (isset($_SESSION['admin_flash_type']) && $_SESSION['admin_flash_type'] === 'error');
        $admin_flash_bg = $admin_flash_is_error ? 'var(--danger)' : 'var(--success)';
        $admin_flash_icon = $admin_flash_is_error ? 'fa-circle-exclamation' : 'fa-check-circle';
    ?>
    <div id="admin-flash-banner" style="position: fixed; top: 20px; right: 20px; z-index: 9999; background: <?php echo $admin_flash_bg; ?>; color: white; padding: 15px 25px; border-radius: var(--radius); box-shadow: var(--shadow-md); display: flex; align-items: center; gap: 15px; transition: opacity 0.5s ease;">
        <i class="fas <?php echo $admin_flash_icon; ?>" style="font-size: 1.3rem;"></i>
        <span><?php echo htmlspecialchars($_SESSION['admin_flash']); ?></span>
        <button onclick="this.parentElement.style.opacity='0'; setTimeout(()=>this.parentElement.style.display='none', 500);" style="background: none; border: none; color: white; font-size: 1.3rem; cursor: pointer;">&times;</button>
    </div>
    <script>
        setTimeout(() => {
            const banner = document.getElementById('admin-flash-banner');
            if(banner) { banner.style.opacity = '0'; setTimeout(() => banner.style.display = 'none', 500); }
        }, 4000);
    </script>
    <?php unset($_SESSION['admin_flash']); unset($_SESSION['admin_flash_type']); endif; ?>

    <div class="sidebar">
        <h2>Admin Panel</h2>
        <ul>
            <li><a href="#manage-pets" data-section="manage-pets" class="active"><i class="fas fa-dog"></i> Manage Pets</a></li>
            <li><a href="#analytics" data-section="analytics"><i class="fas fa-chart-line"></i> Analytics &amp; Prediction</a></li>
            <li><a href="#applications" data-section="applications"><i class="fas fa-file-alt"></i> Applications</a></li>
            <li><a href="#lost-found" data-section="lost-found"><i class="fas fa-search-location"></i> Lost &amp; Found</a></li>
            <li><a href="#shelter-status" data-section="shelter-status"><i class="fas fa-home"></i> Shelter Status</a></li>
            <li><a href="#archives" data-section="archives"><i class="fas fa-archive"></i> Archives</a></li>
        </ul>
        <div class="logout">
            <ul><li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li></ul>
        </div>
    </div>

    <div class="content">
        <div class="admin-header">
            <h1>Welcome, Admin!</h1>

            <div class="notif-bell-wrap">
                <span class="notif-bell" id="admin-notif-bell" role="button" tabindex="0" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="notif-badge" id="admin-notif-badge" style="display:none;">0</span>
                </span>
                <div class="notif-dropdown" id="admin-notif-dropdown">
                    <div class="notif-dropdown-header">
                        <span>Notifications</span>
                        <button type="button" class="notif-markall" id="admin-notif-markall">Mark all read</button>
                    </div>
                    <!-- Swapped by live-sync whenever an application or lost pet
                         report changes, so a new alert lands without a refresh. -->
                    <div id="admin-notif-body" data-live="applications lost_pets">
                        <?php if (empty($notif_items)): ?>
                            <div class="notif-empty">Nothing new right now.</div>
                        <?php else: ?>
                            <?php if (!$notif_merged_by_time): ?>
                                <div class="notif-group">Grouped by type &mdash; run migrate.php to sort by time</div>
                            <?php endif; ?>
                            <?php foreach ($notif_items as $n):
                                $is_app = ($n['kind'] === 'application');
                                $ago = notifAgo($n['created_at']);
                            ?>
                            <button type="button" class="notif-link"
                                    data-kind="<?php echo $is_app ? 'application' : 'lost_pet'; ?>"
                                    data-id="<?php echo (int)$n['id']; ?>"
                                    data-goto="<?php echo $is_app ? 'applications' : 'lost-found'; ?>">
                                <i class="fas <?php echo $is_app ? 'fa-file-alt' : 'fa-search-location'; ?>"></i>
                                <span>
                                    <?php if ($is_app): ?>
                                        <strong><?php echo htmlspecialchars($n['actor']); ?></strong>
                                        applied to adopt
                                        <strong><?php echo htmlspecialchars($n['subject']); ?></strong>
                                    <?php else: ?>
                                        <strong><?php echo htmlspecialchars($n['subject']); ?></strong>
                                        reported missing
                                    <?php endif; ?>
                                    <span class="notif-meta">
                                        <?php echo htmlspecialchars(str_replace('_Seen', '', (string)$n['meta'])); ?>
                                        <?php if ($ago !== ''): ?>
                                            &middot; <?php echo htmlspecialchars($ago); ?>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-stats" id="dashboard-stats" data-live="pets lost_pets applications">
            <div class="card" style="border-left-color: var(--primary-color);">
                <h3>Total Pets</h3>
                <p id="stat-total-pets" style="color: var(--primary-color);">
                    <?php echo htmlspecialchars($dog_count + $cat_count); ?>
                </p>
            </div>
            <div class="card" style="border-left-color: var(--accent-color);">
                <h3>Pending Applications</h3>
                <p id="stat-pending-apps" style="color: var(--accent-color);">
                    <?php echo htmlspecialchars($app_pending); ?>
                </p>
            </div>
            <div class="card" style="border-left-color: var(--danger);">
                <h3>Lost Reports</h3>
                <p id="stat-lost-reports" style="color: var(--danger);">
                    <?php echo $conn->query("SELECT COUNT(*) FROM lost_pets WHERE LOWER(status)='missing'")->fetchColumn() ?: 0; ?>
                </p>
            </div>
        </div>

        <div id="manage-pets" class="section active">
            <h3>Add New Adoptable Pet</h3>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-row">
                    <select name="type" id="pet_type" required onchange="updateDropdowns()">
                        <option value="" disabled selected>Select Type</option>
                        <option value="dog">Dog</option>
                        <option value="cat">Cat</option>
                    </select>
                    
                    <input type="text" name="name" placeholder="Name of pet" required>
                    
                    <!-- Replaced select with a searchable datalist input -->
                    <input type="text" name="breed" id="pet_breed" list="breed_list" placeholder="Select type first" autocomplete="off" required oninput="checkOtherBreed()">
                    <datalist id="breed_list"></datalist>
                    
                    <!-- Hidden custom breed input (only for Mixed Breed now) -->
                    <input type="text" name="custom_breed" id="custom_breed" placeholder="What breeds? (e.g., Terrier/Poodle)" style="display: none;">
                    
                    <select name="age" id="pet_age" required>
                        <option value="" disabled selected>Select type first</option>
                    </select>
                </div>

                <div style="margin-bottom: 15px;">
                    <textarea name="backstory" placeholder="Pet's Backstory / Personality (e.g., 'Found wandering in Camp 7, very sweet and loves to play.')" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 10px; font-family: inherit; resize: vertical; min-height: 80px;"></textarea>
                    <textarea name="medical_history" placeholder="Medical History & Needs (e.g., 'Fully vaccinated, neutered, requires grain-free diet.')" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; resize: vertical; min-height: 80px;"></textarea>
                </div>
                
                <div class="form-row">
                    <input type="file" name="pet_photo" accept="image/*" required style="flex-grow: 1;">
                    <button type="submit" name="add_pet" class="btn-add">Add Pet</button>
                </div>
            </form>

            <h3 style="margin-top:40px;">Current Adoptable Pets</h3>
            <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Photo</th> 
                        <th>Name</th>
                        <th>Breed</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="pets-tbody" data-live="pets">
                    <?php
                    $pets = $conn->query("SELECT * FROM pets WHERE is_archived = 0 ORDER BY id DESC");
                    while($row = $pets->fetch(PDO::FETCH_ASSOC)):
                    ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><img src="<?php echo htmlspecialchars($row['image_url']); ?>" alt="Pet Photo" class="thumb"></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['breed']); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($row['type'])); ?></td>
                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                            <td>
                                <div style="display: flex; gap: 5px; align-items: center;">
                                    <?php if(strtolower($row['status']) != 'adopted'): ?>
                                    <form method="POST" class="js-confirm" data-confirm-msg="Mark this pet as successfully adopted?" style="margin:0;">
                                        <input type="hidden" name="pet_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="mark_adopted" class="btn-save" style="background-color: var(--success);">Adopted</button>
                                    </form>
                                    <?php endif; ?>
                            
                                    <button type="button" class="btn-save" 
                                            onclick="openEditModal('<?php echo htmlspecialchars($row['id'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['breed'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['age'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars(isset($row['backstory']) ? $row['backstory'] : '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars(isset($row['medical_history']) ? $row['medical_history'] : '', ENT_QUOTES); ?>')">
                                        Edit
                                    </button>
                                    
                                    <form method="POST" class="js-confirm" data-confirm-msg="Are you sure you want to securely archive this pet?" style="margin:0;">
                                        <input type="hidden" name="pet_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="delete_pet" class="btn-delete">Archive</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div id="archives" class="section">
            <h3>Archives</h3>
            <p class="section-note">These records are hidden from the main dashboard but preserved securely for reference.</p>

            <!-- TABS UI -->
            <div class="tab-bar">
                <button type="button" id="archive-tab-pets" class="tab-btn active" onclick="switchArchiveTab('pets')">
                    <i class="fas fa-dog"></i> Pets
                </button>
                <button type="button" id="archive-tab-apps" class="tab-btn" onclick="switchArchiveTab('apps')">
                    <i class="fas fa-file-alt"></i> Applications
                </button>
                <button type="button" id="archive-tab-lost" class="tab-btn" onclick="switchArchiveTab('lost')">
                    <i class="fas fa-search-location"></i> Lost & Found
                </button>
            </div>

            <!-- ARCHIVED PETS TABLE -->
            <div id="archive-table-pets" style="display: block;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="archive-pets-tbody" data-live="pets">
                        <?php
                        $archived_pets = $conn->query("SELECT * FROM pets WHERE is_archived = 1 ORDER BY id DESC");
                        if ($archived_pets && $archived_pets->rowCount() > 0) {
                            while($row = $archived_pets->fetch(PDO::FETCH_ASSOC)):
                        ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($row['type'])); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td style="text-align: center;">
                                    <form method="POST" style="margin:0; display:inline-block;">
                                        <input type="hidden" name="pet_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="restore_pet" class="btn-save" style="background-color: #17a2b8;"><i class="fas fa-undo"></i> Restore</button>
                                    </form>
                                </td>
                            </tr>
                        <?php
                            endwhile;
                        } else {
                            echo "<tr><td colspan='5' class='table-empty'>No archived pets found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- ARCHIVED APPLICATIONS TABLE -->
            <div id="archive-table-apps" style="display: none;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr>
                            <th>Pet Name</th>
                            <th>Applicant</th>
                            <th>Final Status</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="archive-apps-tbody" data-live="applications">
                        <?php
                        $archived_apps = $conn->query("SELECT * FROM applications WHERE is_archived = 1 ORDER BY id DESC");
                        if ($archived_apps && $archived_apps->rowCount() > 0) {
                            while($row = $archived_apps->fetch(PDO::FETCH_ASSOC)):
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['pet_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['fullname']); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td style="text-align: center;">
                                    <form method="POST" style="margin:0; display:inline-block;">
                                        <input type="hidden" name="app_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="restore_application" class="btn-save" style="background-color: #17a2b8;"><i class="fas fa-undo"></i> Restore</button>
                                    </form>
                                </td>
                            </tr>
                        <?php
                            endwhile;
                        } else {
                            echo "<tr><td colspan='4' class='table-empty'>No archived applications found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- ARCHIVED LOST & FOUND TABLE -->
            <div id="archive-table-lost" style="display: none;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Pet Name</th>
                            <th>Location</th>
                            <th>Last seen</th>
                            <th>Contact</th>
                            <th>Details</th>
                            <th>Final Status</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="archive-lost-tbody" data-live="lost_pets">
                        <?php
                        $archived_lost = $conn->query("SELECT * FROM lost_pets WHERE is_archived = 1 ORDER BY id DESC");
                        if ($archived_lost && $archived_lost->rowCount() > 0) {
                            while($row = $archived_lost->fetch(PDO::FETCH_ASSOC)):
                        ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($row['photo_path']); ?>" alt="Lost Pet Photo" class="thumb" onerror="this.src='https://via.placeholder.com/50'"></td>
                                <td><?php echo htmlspecialchars($row['pet_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['location']); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td style="text-align: center;">
                                    <form method="POST" style="margin:0; display:inline-block;">
                                        <input type="hidden" name="lost_pet_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="restore_lost_pet" class="btn-save" style="background-color: #17a2b8;"><i class="fas fa-undo"></i> Restore</button>
                                    </form>
                                </td>
                            </tr>
                        <?php
                            endwhile;
                        } else {
                            echo "<tr><td colspan='5' class='table-empty'>No archived lost &amp; found reports.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>

            <script>
            function switchArchiveTab(tabName) {
                ['pets', 'apps', 'lost'].forEach(function(name) {
                    var btn = document.getElementById('archive-tab-' + name);
                    var table = document.getElementById('archive-table-' + name);
                    var isActive = (name === tabName);
                    btn.classList.toggle('active', isActive);
                    table.style.display = isActive ? 'block' : 'none';
                });
            }
            </script>
        </div>

        <div id="analytics" class="section">
            <h3><i class="fas fa-chart-bar"></i> Descriptive Analytics</h3>
            <p class="section-note">An overview of current shelter statistics and adoption pipelines.</p>

            <!-- The charts read their numbers from here, so live-sync can refresh
                 them by swapping this island like any other data-live region.
                 JSON_HEX_TAG keeps a breed name containing "</script>" inert. -->
            <script type="application/json" id="analytics-data" data-live="pets applications"><?php
                echo json_encode($analytics_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            ?></script>

            <div class="chart-grid">
                <div class="chart-card">
                    <h4>Active Population</h4>
                    <div style="position: relative; height:250px; max-width:280px; margin:0 auto;">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <h4>Top 5 Available Breeds</h4>
                    <div style="position: relative; height:250px;">
                        <canvas id="breedChart"></canvas>
                    </div>
                </div>

                <div class="chart-card chart-card-wide">
                    <h4>Application Pipeline</h4>
                    <div style="position: relative; height:250px; max-width:280px; margin:0 auto;">
                        <canvas id="appChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div id="applications" class="section">
            <h3>Adoption Applications</h3>
            <p class="section-note">Review each applicant's submitted documents before approving or rejecting.</p>

            <div class="tab-bar">
                <button type="button" class="app-tab-btn tab-btn active" onclick="filterApps('pending', this)"><i class="fas fa-hourglass-half"></i> Pending Review</button>
                <button type="button" class="app-tab-btn tab-btn" onclick="filterApps('approved', this)"><i class="fas fa-check-circle"></i> Approved</button>
                <button type="button" class="app-tab-btn tab-btn" onclick="filterApps('rejected', this)"><i class="fas fa-times-circle"></i> Rejected / Completed</button>
            </div>

            <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Pet</th>
                        <th>Applicant</th>
                        <th>Requirements</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="applications-tbody" data-live="applications">
                    <?php
                    $apps = $conn->query("SELECT * FROM applications WHERE is_archived = 0 ORDER BY id DESC");
                    while($row = $apps->fetch(PDO::FETCH_ASSOC)):
                        echo renderApplicationRow($row);
                    endwhile;
                    ?>
                </tbody>
            </table>
            </div>
            
            <script>
            let currentAppFilter = 'pending';

            function filterApps(status, btn) {
                currentAppFilter = status;
                document.querySelectorAll('.app-tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                document.querySelectorAll('.app-row').forEach(row => {
                    row.style.display = 'none';
                    if (status === 'rejected' && (row.classList.contains('status-rejected') || row.classList.contains('status-acknowledged'))) {
                        row.style.display = '';
                    } else if (row.classList.contains('status-' + status)) {
                        row.style.display = '';
                    }
                });
            }

            function applyCurrentAppFilter() {
                document.querySelectorAll('.app-row').forEach(row => {
                    row.style.display = 'none';
                    if (currentAppFilter === 'rejected' && (row.classList.contains('status-rejected') || row.classList.contains('status-acknowledged'))) {
                        row.style.display = '';
                    } else if (row.classList.contains('status-' + currentAppFilter)) {
                        row.style.display = '';
                    }
                });
            }

            // Rows arriving from live-sync are re-filtered via the live:updated
            // listener further down; no dedicated poll needed here.

            document.addEventListener('DOMContentLoaded', () => {
                const pendingBtn = document.querySelector('.app-tab-btn');
                if (pendingBtn) filterApps('pending', pendingBtn);
            });
            </script>
        </div>

        <div id="lost-found" class="section">
            <h3>User Submitted Lost Pets</h3>
            <p class="section-note">Reports submitted by the public. Mark a pet as found once it is reunited, or archive the report.</p>

            <!-- TABS UI -->
            <div class="tab-bar">
                <button type="button" id="admin-tab-missing" class="tab-btn active" onclick="switchAdminLostTab('missing')">
                    <i class="fas fa-search"></i> Active Missing Reports
                </button>
                <button type="button" id="admin-tab-found" class="tab-btn" onclick="switchAdminLostTab('found')">
                    <i class="fas fa-check-circle"></i> Resolved (Found)
                </button>
            </div>

            <!-- MISSING TABLE -->
            <div id="admin-table-missing" style="display: block;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr>
                            <th>PHOTO</th>
                            <th>PET NAME</th>
                            <th>LOCATION</th>
                            <th>LAST SEEN</th> <!-- NEW HEADER -->
                            <th>CONTACT</th>
                            <th>DETAILS</th>
                            <th style="text-align: center;">ACTION</th>
                        </tr>
                    </thead>
                    <tbody id="lost-missing-tbody" data-live="lost_pets">
                        <?php
                        $missing = $conn->query("SELECT * FROM lost_pets WHERE status = 'Missing' AND is_archived = 0 ORDER BY id DESC");
                        
                        if($missing && $missing->rowCount() > 0) {
                            while($row = $missing->fetch(PDO::FETCH_ASSOC)) {
                                // Prepare variables
                                $date_seen = !empty($row['last_seen']) ? date('M d, Y', strtotime($row['last_seen'])) : 'N/A';
                                $time_seen = !empty($row['last_seen']) ? date('h:i A', strtotime($row['last_seen'])) : '';

                                // Safe JS variables
                                $js_name = htmlspecialchars($row['pet_name'], ENT_QUOTES);
                                $js_photo = htmlspecialchars($row['photo_path'], ENT_QUOTES);
                                $js_loc = htmlspecialchars($row['location'], ENT_QUOTES);
                                $js_date = htmlspecialchars($date_seen, ENT_QUOTES);
                                $js_time = htmlspecialchars($time_seen, ENT_QUOTES);
                                $js_contact = htmlspecialchars($row['contact_number'], ENT_QUOTES);
                                $js_desc = htmlspecialchars(str_replace(array("\r", "\n"), ' ', $row['description']), ENT_QUOTES);

                                echo "<tr>";
                                echo "<td><img src='{$row['photo_path']}' alt='Lost Pet Photo' class='thumb' onerror=\"this.src='https://via.placeholder.com/50'\"></td>";
                                
                                // Clickable link triggering modal
                                echo "<td><a href='javascript:void(0)' onclick=\"viewLostPetDetails('$js_name', '$js_photo', '$js_loc', '$js_date', '$js_time', '$js_contact', '$js_desc')\" style='font-weight: 600; color: var(--primary-color); text-decoration: underline; text-underline-offset: 3px; cursor: pointer;'>" . htmlspecialchars($row['pet_name']) . "</a></td>";
                                
                                echo "<td>" . htmlspecialchars($row['location']) . "</td>";
                                echo "<td>" . htmlspecialchars($date_seen) . "<br><small style='color: #767e89;'>" . htmlspecialchars($time_seen) . "</small></td>";
                                echo "<td>" . htmlspecialchars($row['contact_number']) . "</td>";
                                echo "<td style='font-size: 0.85rem; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;' title='" . htmlspecialchars($row['description']) . "'>" . htmlspecialchars($row['description']) . "</td>";
                                
                                echo "<td>
                                        <div style='display: flex; gap: 5px; justify-content: center;'>
                                            <form method='POST' style='margin:0;'>
                                                <input type='hidden' name='lost_pet_id' value='{$row['id']}'>
                                                <button type='submit' name='mark_found' class='btn-save' style='background: var(--primary-color);'>Mark Found</button>
                                            </form>
                                            <form method='POST' style='margin:0;' class='js-confirm' data-confirm-msg='Archive this report?'>
                                                <input type='hidden' name='lost_pet_id' value='{$row['id']}'>
                                                <button type='submit' name='delete_lost_pet' class='btn-delete'><i class='fas fa-trash'></i></button>
                                            </form>
                                        </div>
                                      </td>";
                                echo "</tr>";
                            }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- FOUND TABLE -->
            <div id="admin-table-found" style="display: none;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr>
                            <th>PHOTO</th>
                            <th>PET NAME</th>
                            <th>LOCATION</th>
                            <th>LAST SEEN</th> <!-- NEW HEADER -->
                            <th>CONTACT</th>
                            <th>DETAILS</th>
                            <th style="text-align: center;">ACTION</th>
                        </tr>
                    </thead>
                    <tbody id="lost-found-tbody" data-live="lost_pets">
                        <?php
                        $found = $conn->query("SELECT * FROM lost_pets WHERE status = 'Found' AND is_archived = 0 ORDER BY id DESC");
                        
                        if($found && $found->rowCount() > 0) {
                            while($row = $found->fetch(PDO::FETCH_ASSOC)) {
                                // Prepare variables
                                $date_seen = !empty($row['last_seen']) ? date('M d, Y', strtotime($row['last_seen'])) : 'N/A';
                                $time_seen = !empty($row['last_seen']) ? date('h:i A', strtotime($row['last_seen'])) : '';

                                // Safe JS variables
                                $js_name = htmlspecialchars($row['pet_name'], ENT_QUOTES);
                                $js_photo = htmlspecialchars($row['photo_path'], ENT_QUOTES);
                                $js_loc = htmlspecialchars($row['location'], ENT_QUOTES);
                                $js_date = htmlspecialchars($date_seen, ENT_QUOTES);
                                $js_time = htmlspecialchars($time_seen, ENT_QUOTES);
                                $js_contact = htmlspecialchars($row['contact_number'], ENT_QUOTES);
                                $js_desc = htmlspecialchars(str_replace(array("\r", "\n"), ' ', $row['description']), ENT_QUOTES);

                                echo "<tr class='row-muted'>";
                                echo "<td><img src='{$row['photo_path']}' alt='Found Pet Photo' class='thumb' style='opacity: 0.7;' onerror=\"this.src='https://via.placeholder.com/50'\"></td>";
                                
                                // Clickable link triggering modal with <del> tag
                                echo "<td><del><a href='javascript:void(0)' onclick=\"viewLostPetDetails('$js_name', '$js_photo', '$js_loc', '$js_date', '$js_time', '$js_contact', '$js_desc')\" style='color: #767e89; text-decoration: underline; cursor: pointer;'>" . htmlspecialchars($row['pet_name']) . "</a></del></td>";
                                
                                echo "<td>" . htmlspecialchars($row['location']) . "</td>";
                                echo "<td><del>" . htmlspecialchars($date_seen) . "</del><br><small style='color: #767e89;'><del>" . htmlspecialchars($time_seen) . "</del></small></td>";
                                echo "<td>" . htmlspecialchars($row['contact_number']) . "</td>";
                                echo "<td style='font-size: 0.85rem; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;' title='" . htmlspecialchars($row['description']) . "'>" . htmlspecialchars($row['description']) . "</td>";
                                
                                echo "<td>
                                        <div style='display: flex; justify-content: center;'>
                                            <form method='POST' style='margin:0;' class='js-confirm' data-confirm-msg='Archive this resolved report?'>
                                                <input type='hidden' name='lost_pet_id' value='{$row['id']}'>
                                                <button type='submit' name='delete_lost_pet' class='btn-delete'><i class='fas fa-trash'></i></button>
                                            </form>
                                        </div>
                                      </td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='table-empty'>No found pets yet.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>

            <script>
            function switchAdminLostTab(tabName) {
                ['missing', 'found'].forEach(function(name) {
                    var btn = document.getElementById('admin-tab-' + name);
                    var table = document.getElementById('admin-table-' + name);
                    var isActive = (name === tabName);
                    btn.classList.toggle('active', isActive);
                    table.style.display = isActive ? 'block' : 'none';
                });
            }
            </script>
        </div>

        <div id="shelter-status" class="section">
            <h3>Update Shelter Details</h3>
            <p class="section-note">Keep each partner shelter's contact details, visiting schedule and capacity status current.</p>
            <div class="table-responsive">
            <table class="data-table" id="shelter-table">
                <thead>
                    <tr>
                        <th>Shelter</th>
                        <th>Email</th>
                        <th>Schedule</th>
                        <th>Current Status</th>
                        <th>Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $shelters = $conn->query("SELECT * FROM shelters WHERE name != 'Furvent Animal Rescue'");
                    while($row = $shelters->fetch(PDO::FETCH_ASSOC)):
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <form method="POST" class="shelter-update">
                                <input type="hidden" name="shelter_id" value="<?php echo $row['id']; ?>">
                                <td><input type="email" name="email" value="<?php echo htmlspecialchars($row['email']); ?>" required></td>
                                <td><input type="text" name="schedule" value="<?php echo htmlspecialchars($row['schedule']); ?>" required></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td>
                                    <div class="shelter-actions">
                                        <select name="status">
                                            <option value="Open" <?php if($row['status'] == 'Open') echo 'selected'; ?>>Open</option>
                                            <option value="Full" <?php if($row['status'] == 'Full') echo 'selected'; ?>>Full</option>
                                        </select>
                                        <button type="submit" name="update_shelter" class="btn-save">Save</button>
                                    </div>
                                </td>
                            </form>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div> 
    
    <div id="editPetModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="document.getElementById('editPetModal').style.display='none'">&times;</span>
            <h3>Edit Pet Details</h3>
            <form method="POST">
                <input type="hidden" name="edit_pet_id" id="edit_pet_id">
                <label>Name</label><input type="text" name="edit_name" id="edit_name" required>
                <label>Breed</label><input type="text" name="edit_breed" id="edit_breed" required>
                <label>Age</label><input type="text" name="edit_age" id="edit_age" required>
                
                <label>Backstory</label>
                <textarea name="edit_backstory" id="edit_backstory" required style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; min-height: 80px;"></textarea>
                
                <label>Medical History</label>
                <textarea name="edit_medical_history" id="edit_medical_history" required style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; min-height: 80px;"></textarea>

                <button type="submit" name="update_pet" class="btn-primary" style="margin-top: 15px;">Save Changes</button>
            </form>
        </div>
    </div>

    <div id="appDetailsModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="document.getElementById('appDetailsModal').style.display='none'">&times;</span>
            <h3 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 20px;">Adoption Questionnaire</h3>
            <div id="appDetailsContent"></div>
        </div>
    </div>

    <div id="genericConfirmModal" class="modal">
        <div class="modal-content confirm-modal-content">
            <h3 style="margin-top:0;">Please Confirm</h3>
            <p id="genericConfirmMessage" style="color:#666;">Are you sure?</p>
            <div class="confirm-modal-actions">
                <button type="button" class="btn-neutral" onclick="closeGenericConfirm()">Cancel</button>
                <button type="button" class="btn-primary" onclick="confirmGenericAction()">Yes, Continue</button>
            </div>
        </div>
    </div>

        <!-- LOST PET DETAILS MODAL -->
            <div id="lostPetDetailsModal" class="modal">
                <div class="modal-content" style="max-width: 500px; text-align: center;">
                    <span class="close-modal" onclick="document.getElementById('lostPetDetailsModal').style.display='none'">&times;</span>
                    <h3 style="margin-bottom: 15px; color: var(--primary-color);" id="modalLostPetName">Pet Name</h3>
                    
                    <img id="modalLostPetImage" src="" alt="Lost Pet" style="width: 100%; max-height: 300px; object-fit: contain; border-radius: var(--radius); margin-bottom: 15px; background: #f8f9fa;">
                    
                    <div style="text-align: left;">
                        <div class="detail-row"><span class="detail-label">Location:</span> <span class="detail-value" id="modalLostPetLocation"></span></div>
                        <div class="detail-row"><span class="detail-label">Last Seen:</span> <span class="detail-value" id="modalLostPetLastSeen"></span></div>
                        <div class="detail-row"><span class="detail-label">Contact:</span> <span class="detail-value" id="modalLostPetContact"></span></div>
                        <div class="detail-row" style="flex-direction: column; align-items: flex-start; border-bottom: none;">
                            <span class="detail-label" style="width: 100%; margin-bottom: 5px;">Details/Description:</span> 
                            <span class="detail-value" id="modalLostPetDetails" style="background: #f8f9fa; padding: 10px; border-radius: 4px; width: 100%; border: 1px solid #eee; min-height: 60px;"></span>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            function viewLostPetDetails(name, photo, location, dateSeen, timeSeen, contact, details) {
                document.getElementById('modalLostPetName').textContent = name;
                document.getElementById('modalLostPetImage').src = photo || 'https://via.placeholder.com/500';
                document.getElementById('modalLostPetLocation').textContent = location;
                document.getElementById('modalLostPetLastSeen').textContent = (dateSeen !== 'N/A') ? (dateSeen + ' at ' + timeSeen) : 'Not specified';
                document.getElementById('modalLostPetContact').textContent = contact;
                document.getElementById('modalLostPetDetails').textContent = details;
                
                document.getElementById('lostPetDetailsModal').style.display = 'flex';
            }
            </script>

    <script>
        let pendingConfirmForm = null;
        let pendingConfirmSubmitter = null;
        // Delegated on document (not querySelectorAll+forEach) so it also covers
        // rows injected later by AJAX polling, not just what existed at page load.
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form.classList || !form.classList.contains('js-confirm')) return;
            if (form.dataset.confirmed === 'true') {
                form.dataset.confirmed = 'false';
                return;
            }
            e.preventDefault();
            pendingConfirmForm = form;
            pendingConfirmSubmitter = e.submitter;
            document.getElementById('genericConfirmMessage').textContent = form.dataset.confirmMsg || 'Are you sure?';
            document.getElementById('genericConfirmModal').style.display = 'flex';
        });
        function closeGenericConfirm() {
            document.getElementById('genericConfirmModal').style.display = 'none';
            pendingConfirmForm = null;
            pendingConfirmSubmitter = null;
        }
        function confirmGenericAction() {
            document.getElementById('genericConfirmModal').style.display = 'none';
            if (pendingConfirmForm) {
                pendingConfirmForm.dataset.confirmed = 'true';
                if (pendingConfirmSubmitter && pendingConfirmForm.requestSubmit) {
                    pendingConfirmForm.requestSubmit(pendingConfirmSubmitter);
                } else {
                    pendingConfirmForm.submit();
                }
            }
        }
    </script>

    <script>
        // Expanded breed lists (no "Other" needed, they can just type it!)
        const breedOptions = {
            'dog': ['Aspin', 'Shih Tzu', 'Chow Chow', 'Golden Retriever', 'Pomeranian', 'Labrador Retriever', 'German Shepherd', 'Bulldog', 'Poodle', 'Beagle', 'Rottweiler', 'Dachshund', 'Siberian Husky', 'Pug', 'Boxer', 'Chihuahua', 'Doberman Pinscher', 'Great Dane', 'Mixed Breed'],
            'cat': ['Puspin', 'Persian', 'Siamese', 'Maine Coon', 'Bengal', 'Sphynx', 'British Shorthair', 'Scottish Fold', 'Ragdoll', 'Abyssinian', 'Russian Blue', 'Mixed Breed']
        };

        const ageOptions = {
            'dog': ['Puppy (0-6 Months)', 'Young (6 Mos - 1 Year)', 'Adult (1-4 Years)', 'Senior (5+ Years)'],
            'cat': ['Kitten (0-6 Months)', 'Young (6 Mos - 1 Year)', 'Adult (1-4 Years)', 'Senior (5+ Years)']
        };

        function checkOtherBreed() {
            var breedInput = document.getElementById('pet_breed').value;
            var customBreedInput = document.getElementById('custom_breed');
            
            // Only show the extra text box if they exactly typed or selected "Mixed Breed"
            if (breedInput === 'Mixed Breed') {
                customBreedInput.style.display = 'block';
                customBreedInput.required = true;
            } else {
                customBreedInput.style.display = 'none';
                customBreedInput.required = false;
                customBreedInput.value = ''; 
            }
        }

        function updateDropdowns() {
            const typeSelected = document.getElementById('pet_type').value;
            const breedList = document.getElementById('breed_list');
            const petBreedInput = document.getElementById('pet_breed');
            const ageDropdown = document.getElementById('pet_age');

            // Reset fields
            breedList.innerHTML = '';
            ageDropdown.innerHTML = '<option value="" disabled selected>Select Age</option>';
            
            petBreedInput.value = '';
            petBreedInput.placeholder = "Type to search breed...";

            var customBreedInput = document.getElementById('custom_breed');
            if (customBreedInput) {
                customBreedInput.style.display = 'none';
                customBreedInput.required = false;
                customBreedInput.value = '';
            }

            // Populate the hidden datalist with the big breed arrays
            if (breedOptions[typeSelected]) {
                breedOptions[typeSelected].forEach(function(breed) {
                    let option = document.createElement('option');
                    option.value = breed;
                    breedList.appendChild(option);
                });
            }

            // Populate the age dropdown normally
            if (ageOptions[typeSelected]) {
                ageOptions[typeSelected].forEach(function(age) {
                    let option = document.createElement('option');
                    option.value = age;
                    option.text = age;
                    ageDropdown.appendChild(option);
                });
            }
        }

        // The open section is remembered so a refresh - including the redirect
        // after every admin POST - lands back where you were instead of
        // snapping to Manage Pets.
        const ADMIN_SECTION_KEY = 'furfinder_admin_section';
        const DEFAULT_SECTION = 'manage-pets';

        function isSectionId(id) {
            const el = id ? document.getElementById(id) : null;
            return !!(el && el.classList.contains('section'));
        }

        function showSection(sectionId, element) {
            if (!isSectionId(sectionId)) return;

            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelectorAll('.sidebar ul li a').forEach(link => {
                link.classList.remove('active');
            });
            document.getElementById(sectionId).classList.add('active');

            const link = element || document.querySelector('.sidebar a[data-section="' + sectionId + '"]');
            if (link) link.classList.add('active');

            // Private-mode browsers can throw on localStorage writes.
            try { localStorage.setItem(ADMIN_SECTION_KEY, sectionId); } catch (e) { /* not fatal */ }
        }

        function restoreSection() {
            // The URL hash wins so a bookmarked or shared link opens its section;
            // localStorage covers the POST-redirect case, which drops the hash.
            let target = decodeURIComponent(location.hash.replace(/^#/, ''));
            if (!isSectionId(target)) {
                try { target = localStorage.getItem(ADMIN_SECTION_KEY) || ''; } catch (e) { target = ''; }
            }
            if (!isSectionId(target)) target = DEFAULT_SECTION;
            showSection(target);
        }

        document.querySelectorAll('.sidebar a[data-section]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const sectionId = this.dataset.section;
                showSection(sectionId, this);
                // replaceState, not a hash assignment: the URL should reflect the
                // open section without stacking a history entry per sidebar click.
                if (window.history && history.replaceState) {
                    history.replaceState(null, '', '#' + sectionId);
                } else {
                    location.hash = sectionId;
                }
            });
        });

        window.addEventListener('hashchange', restoreSection);

        // Run immediately (this script is at the end of <body>) rather than on
        // DOMContentLoaded, so the correct section is up before the first paint.
        restoreSection();

        function openEditModal(id, name, breed, age, backstory, medical) {
            document.getElementById('edit_pet_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_breed').value = breed;
            document.getElementById('edit_age').value = age;
            document.getElementById('edit_backstory').value = backstory || "";
            document.getElementById('edit_medical_history').value = medical || "";
            document.getElementById('editPetModal').style.display = 'flex'; 
        }

        function viewAppDetails(data) {
            const container = document.getElementById('appDetailsContent');
            let html = `
                <div class="detail-row"><span class="detail-label">Pet Applied For:</span> <span class="detail-value">${data.pet_name}</span></div>
                <div class="detail-row"><span class="detail-label">Applicant Name:</span> <span class="detail-value">${data.fullname}</span></div>
                <div class="detail-row"><span class="detail-label">Contact:</span> <span class="detail-value">${data.contact}</span></div>
                <div class="detail-row"><span class="detail-label">Address:</span> <span class="detail-value">${data.address}</span></div>
                
                <h4 style="margin: 20px 0 10px; color: var(--accent-color); border-bottom: 1px solid #ddd; padding-bottom: 5px;">Questionnaire Answers</h4>
                
                <div class="detail-row"><span class="detail-label">Housing Type:</span> <span class="detail-value">${data.housing_type || '<span style="color:#999">N/A</span>'}</span></div>
                <div class="detail-row"><span class="detail-label">Has Fence/Gate?</span> <span class="detail-value">${data.has_fence || '<span style="color:#999">N/A</span>'}</span></div>
                <div class="detail-row"><span class="detail-label">Household Members:</span> <span class="detail-value">${data.household_members || '<span style="color:#999">N/A</span>'}</span></div>
                <div class="detail-row"><span class="detail-label">Other Pets:</span> <span class="detail-value">${data.other_pets || '<span style="color:#999">N/A</span>'}</span></div>
                <div class="detail-row"><span class="detail-label">Source of Income:</span> <span class="detail-value">${data.income_source || '<span style="color:#999">N/A</span>'}</span></div>
                <div class="detail-row"><span class="detail-label">Hours Pet Alone:</span> <span class="detail-value">${data.hours_alone || '<span style="color:#999">N/A</span>'}</span></div>
            `;
            container.innerHTML = html;
            document.getElementById('appDetailsModal').style.display = 'flex';
        }

        let typeChart = null, breedChart = null, appChart = null;

        function readAnalytics() {
            const island = document.getElementById('analytics-data');
            if (!island) return null;
            try {
                return JSON.parse(island.textContent);
            } catch (e) {
                return null;
            }
        }

        function buildCharts() {
            const data = readAnalytics();
            if (!data) return;

            const typeChartEl = document.getElementById('typeChart');
            if (typeChartEl) {
                typeChart = new Chart(typeChartEl.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Dogs', 'Cats'],
                        datasets: [{
                            data: [data.types.dogs, data.types.cats],
                            backgroundColor: ['#003366', '#d4af37'],
                            borderWidth: 1
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false }
                });
            }

            const breedChartEl = document.getElementById('breedChart');
            if (breedChartEl) {
                breedChart = new Chart(breedChartEl.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: data.breeds.labels,
                        datasets: [{
                            label: 'Number Available',
                            data: data.breeds.counts,
                            backgroundColor: '#17a2b8',
                            borderRadius: 4,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                    }
                });
            }

            const appChartEl = document.getElementById('appChart');
            if (appChartEl) {
                appChart = new Chart(appChartEl.getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: ['Pending', 'Approved', 'Rejected'],
                        datasets: [{
                            data: [data.pipeline.pending, data.pipeline.approved, data.pipeline.rejected],
                            backgroundColor: ['#ffc107', '#28a745', '#dc3545'],
                            borderWidth: 1
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false }
                });
            }
        }

        // Charts are canvas-backed, so live-sync can't swap them the way it swaps
        // a table body - it refreshes the JSON island and we push the new numbers
        // into the existing Chart instances instead of rebuilding them.
        function refreshCharts() {
            const data = readAnalytics();
            if (!data) return;

            if (typeChart) {
                typeChart.data.datasets[0].data = [data.types.dogs, data.types.cats];
                typeChart.update();
            }
            if (breedChart) {
                breedChart.data.labels = data.breeds.labels;
                breedChart.data.datasets[0].data = data.breeds.counts;
                breedChart.update();
            }
            if (appChart) {
                appChart.data.datasets[0].data = [data.pipeline.pending, data.pipeline.approved, data.pipeline.rejected];
                appChart.update();
            }
        }

        document.addEventListener('live:updated', (e) => {
            const changed = e.detail.datasets;
            // The applications table is filtered client-side by the active tab,
            // and the freshly swapped rows arrive unfiltered.
            if (changed.includes('applications')) applyCurrentAppFilter();
            // Both datasets feed the analytics island.
            if (changed.includes('pets') || changed.includes('applications')) refreshCharts();
            // The notification bell re-marks itself - notifications.js listens
            // for this event too.
        });

        document.addEventListener('DOMContentLoaded', () => {
            buildCharts();
            NotificationBell.create({
                storageKey: 'furfinder_admin_notif_seen',
                bellId: 'admin-notif-bell',
                badgeId: 'admin-notif-badge',
                dropdownId: 'admin-notif-dropdown',
                bodyId: 'admin-notif-body',
                markAllId: 'admin-notif-markall',
                datasets: ['applications', 'lost_pets'],
                onSelect: (link) => {
                    showSection(link.dataset.goto);
                    if (window.history && history.replaceState) {
                        history.replaceState(null, '', '#' + link.dataset.goto);
                    }
                }
            });
            LiveSync.start({ interval: 5000 });
        });
    </script>
</body>
</html>