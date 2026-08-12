<?php
include 'db.php';

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
    $stmt = $conn->prepare("UPDATE applications SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    $_SESSION['admin_flash'] = 'Application status updated to ' . $status . '.';
    header("Location: admin.php");
    exit();
}

// 5. Handle Lost Pet Status Update (Mark Found)
if (isset($_POST['mark_found'])) {
    $id = $_POST['lost_pet_id'];
    $stmt = $conn->prepare("UPDATE lost_pets SET status = 'Found' WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['admin_flash'] = 'Lost pet marked as found.';
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | FurFinder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .app-status select, .shelter-update select, .shelter-update input { padding: 5px; border-radius: var(--radius); }
        .app-status { display: flex; gap: 5px; }
        .shelter-update input { width: 100px; }

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

    <?php if(isset($_SESSION['admin_flash'])):
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
            <li><a href="#" onclick="showSection('manage-pets', this)" class="active"><i class="fas fa-dog"></i> Manage Pets</a></li>
            <li><a href="#" onclick="showSection('analytics', this)"><i class="fas fa-chart-line"></i> Analytics & Prediction</a></li> 
            <li><a href="#" onclick="showSection('applications', this)"><i class="fas fa-file-alt"></i> Applications</a></li>
            <li><a href="#" onclick="showSection('lost-found', this)"><i class="fas fa-search-location"></i> Lost & Found</a></li>
            <li><a href="#" onclick="showSection('shelter-status', this)"><i class="fas fa-home"></i> Shelter Status</a></li>
            <li><a href="#" onclick="showSection('archives', this)"><i class="fas fa-archive"></i> Archives</a></li>
        </ul>
        <div class="logout">
            <ul><li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li></ul>
        </div>
    </div>

    <div class="content">
        <h1>Welcome, Admin!</h1>

        <div class="dashboard-stats">
            <div class="card" style="border-left-color: var(--primary-color);">
                <h3>Total Pets</h3>
                <p style="color: var(--primary-color);">
                    <?php echo htmlspecialchars($dog_count + $cat_count); ?>
                </p>
            </div>
            <div class="card" style="border-left-color: var(--accent-color);">
                <h3>Pending Applications</h3>
                <p style="color: var(--accent-color);">
                    <?php echo htmlspecialchars($app_pending); ?>
                </p>
            </div>
            <div class="card" style="border-left-color: var(--danger);">
                <h3>Lost Reports</h3>
                <p style="color: var(--danger);">
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
                <tbody>
                    <?php
                    $pets = $conn->query("SELECT * FROM pets WHERE is_archived = 0 ORDER BY id DESC");
                    while($row = $pets->fetch(PDO::FETCH_ASSOC)):
                    ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><img src="<?php echo htmlspecialchars($row['image_url']); ?>" alt="Pet Photo" style="width: 50px; height: 50px; object-fit: cover;"></td>
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
            <h3 style="border-bottom: none; margin-bottom: 10px;">Archives</h3>
            <p style="font-size: 0.9rem; color: #666; margin-bottom: 20px;">These records are hidden from the main dashboard but preserved securely for reference.</p>
            
            <!-- TABS UI -->
            <div style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 15px;">
                <button type="button" id="archive-tab-pets" style="padding: 8px 20px; border: none; background: var(--primary-color); color: white; border-radius: 4px; cursor: pointer; font-weight: bold; flex: 1;" onclick="switchArchiveTab('pets')">
                    <i class="fas fa-dog"></i> Pets
                </button>
                <button type="button" id="archive-tab-apps" style="padding: 8px 20px; border: none; background: #e2e6ea; color: #333; border-radius: 4px; cursor: pointer; font-weight: bold; flex: 1;" onclick="switchArchiveTab('apps')">
                    <i class="fas fa-file-alt"></i> Applications
                </button>
                <button type="button" id="archive-tab-lost" style="padding: 8px 20px; border: none; background: #e2e6ea; color: #333; border-radius: 4px; cursor: pointer; font-weight: bold; flex: 1;" onclick="switchArchiveTab('lost')">
                    <i class="fas fa-search-location"></i> Lost & Found
                </button>
            </div>

            <!-- ARCHIVED PETS TABLE -->
            <div id="archive-table-pets" style="display: block; overflow-x: auto;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr style="background-color: var(--primary-color); color: white;">
                            <th>ID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
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
                                <td style="display: flex; justify-content: center;">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="pet_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="restore_pet" class="btn-save" style="background-color: #17a2b8;"><i class="fas fa-undo"></i> Restore</button>
                                    </form>
                                </td>
                            </tr>
                        <?php 
                            endwhile; 
                        } else {
                            echo "<tr><td colspan='5' style='text-align:center;'>No archived pets found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>
            </div>

            <!-- ARCHIVED APPLICATIONS TABLE -->
            <div id="archive-table-apps" style="display: none; overflow-x: auto;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr style="background-color: var(--primary-color); color: white;">
                            <th>Pet Name</th>
                            <th>Applicant</th>
                            <th>Final Status</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $archived_apps = $conn->query("SELECT * FROM applications WHERE is_archived = 1 ORDER BY id DESC");
                        if ($archived_apps && $archived_apps->rowCount() > 0) {
                            while($row = $archived_apps->fetch(PDO::FETCH_ASSOC)):
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['pet_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['fullname']); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td style="display: flex; justify-content: center;">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="app_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="restore_application" class="btn-save" style="background-color: #17a2b8;"><i class="fas fa-undo"></i> Restore</button>
                                    </form>
                                </td>
                            </tr>
                        <?php 
                            endwhile; 
                        } else {
                            echo "<tr><td colspan='4' style='text-align:center;'>No archived applications found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>
            </div>

            <!-- ARCHIVED LOST & FOUND TABLE -->
            <div id="archive-table-lost" style="display: none; overflow-x: auto;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr style="background-color: var(--primary-color); color: white;">
                            <th>Photo</th>
                            <th>Pet Name</th>
                            <th>Location</th>
                            <th>Final Status</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $archived_lost = $conn->query("SELECT * FROM lost_pets WHERE is_archived = 1 ORDER BY id DESC");
                        if ($archived_lost && $archived_lost->rowCount() > 0) {
                            while($row = $archived_lost->fetch(PDO::FETCH_ASSOC)):
                        ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($row['photo_path']); ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;" onerror="this.src='https://via.placeholder.com/40'"></td>
                                <td><?php echo htmlspecialchars($row['pet_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['location']); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td style="display: flex; justify-content: center;">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="lost_pet_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="restore_lost_pet" class="btn-save" style="background-color: #17a2b8;"><i class="fas fa-undo"></i> Restore</button>
                                    </form>
                                </td>
                            </tr>
                        <?php 
                            endwhile; 
                        } else {
                            echo "<tr><td colspan='5' style='text-align:center;'>No archived lost & found reports.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>
            </div>

            <script>
            function switchArchiveTab(tabName) {
                var btnPets = document.getElementById('archive-tab-pets');
                var btnApps = document.getElementById('archive-tab-apps');
                var btnLost = document.getElementById('archive-tab-lost');
                
                var tablePets = document.getElementById('archive-table-pets');
                var tableApps = document.getElementById('archive-table-apps');
                var tableLost = document.getElementById('archive-table-lost');
                
                // Reset all buttons
                btnPets.style.background = '#e2e6ea'; btnPets.style.color = '#333';
                btnApps.style.background = '#e2e6ea'; btnApps.style.color = '#333';
                btnLost.style.background = '#e2e6ea'; btnLost.style.color = '#333';
                
                // Hide all tables
                tablePets.style.display = 'none';
                tableApps.style.display = 'none';
                tableLost.style.display = 'none';
                
                // Show active tab
                if (tabName === 'pets') {
                    btnPets.style.background = 'var(--primary-color)'; btnPets.style.color = 'white';
                    tablePets.style.display = 'block';
                } else if (tabName === 'apps') {
                    btnApps.style.background = 'var(--primary-color)'; btnApps.style.color = 'white';
                    tableApps.style.display = 'block';
                } else if (tabName === 'lost') {
                    btnLost.style.background = 'var(--primary-color)'; btnLost.style.color = 'white';
                    tableLost.style.display = 'block';
                }
            }
            </script>
        </div>

        <div id="analytics" class="section">
            <h3><i class="fas fa-chart-bar"></i> Descriptive Analytics</h3>
            <p style="font-size: 0.9rem; color: #666; margin-bottom: 20px;">An overview of current shelter statistics and adoption pipelines.</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; max-width: 950px; margin: 0 auto;">
                <div style="background: #fff; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-radius: 8px;">
                    <h4 style="text-align:center; margin-bottom:15px; color:#555;">Active Population</h4>
                    <div style="position: relative; height:250px; max-width:280px; margin:0 auto;">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>

                <div style="background: #fff; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-radius: 8px;">
                    <h4 style="text-align:center; margin-bottom:15px; color:#555;">Top 5 Available Breeds</h4>
                    <div style="position: relative; height:250px;">
                        <canvas id="breedChart"></canvas>
                    </div>
                </div>

                <div style="background: #fff; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-radius: 8px; grid-column: 1 / -1;">
                    <h4 style="text-align:center; margin-bottom:15px; color:#555;">Application Pipeline</h4>
                    <div style="position: relative; height:250px; max-height: 300px; max-width:280px; margin:0 auto;">
                        <canvas id="appChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div id="applications" class="section">
            <h3>Adoption Applications (Requires Document Review)</h3>
            
            <div style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 15px;">
                <button type="button" class="app-tab-btn" style="padding: 8px 20px; border: none; background: var(--primary-color); color: white; border-radius: 4px; cursor: pointer; font-weight: bold;" onclick="filterApps('pending', this)">Pending Review</button>
                <button type="button" class="app-tab-btn" style="padding: 8px 20px; border: none; background: #e2e6ea; color: #333; border-radius: 4px; cursor: pointer; font-weight: bold;" onclick="filterApps('approved', this)">Approved</button>
                <button type="button" class="app-tab-btn" style="padding: 8px 20px; border: none; background: #e2e6ea; color: #333; border-radius: 4px; cursor: pointer; font-weight: bold;" onclick="filterApps('rejected', this)">Rejected / Completed</button>
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
                <tbody>
                    <?php
                    $apps = $conn->query("SELECT * FROM applications WHERE is_archived = 0 ORDER BY id DESC");
                    while($row = $apps->fetch(PDO::FETCH_ASSOC)):
                        $safeData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                        
                        $clean_status = str_replace('_Seen', '', $row['status']);
                        $statusClass = strtolower($clean_status);
                        if ($statusClass == 'acknowledged') { $statusClass = 'rejected'; }
                    ?>
                        <tr class="app-row status-<?php echo htmlspecialchars($statusClass); ?>">
                            <td style="font-weight:bold; color:var(--primary-color);"><?php echo htmlspecialchars($row['pet_name']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($row['fullname']); ?></strong><br>
                                <small><i class="fas fa-phone"></i> <?php echo htmlspecialchars($row['contact']); ?></small><br>
                                <small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['address']); ?></small>
                                <button type="button" class="btn-view" onclick="viewAppDetails(<?php echo $safeData; ?>)">
                                    <i class="fas fa-eye"></i> View Answers
                                </button>
                            </td>
                            <td>
                                <?php if(isset($row['barangay_cert']) && $row['barangay_cert']): ?>
                                    <a href="<?php echo htmlspecialchars($row['barangay_cert']); ?>" target="_blank" class="doc-link"><i class="fas fa-file-contract"></i> View Brgy Cert</a>
                                <?php else: ?>
                                    <span style="color:#999; font-size:0.8rem;">No Brgy Cert</span><br>
                                <?php endif; ?>
                                
                                <?php if(isset($row['valid_id']) && $row['valid_id']): ?>
                                    <a href="<?php echo htmlspecialchars($row['valid_id']); ?>" target="_blank" class="doc-link"><i class="fas fa-id-card"></i> View Valid ID</a>
                                <?php else: ?>
                                    <span style="color:#999; font-size:0.8rem;">No ID</span><br>
                                <?php endif; ?>

                                <?php if(isset($row['cage_photo']) && $row['cage_photo']): ?>
                                    <a href="<?php echo htmlspecialchars($row['cage_photo']); ?>" target="_blank" class="doc-link"><i class="fas fa-home"></i> View Cage/Leash</a>
                                <?php else: ?>
                                    <span style="color:#999; font-size:0.8rem;">No Cage Photo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="app-status" style="display:flex; gap:5px; flex-wrap:wrap;">
                                    <input type="hidden" name="app_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="update_application" value="Pending" class="status-btn status-btn-pending<?php echo strpos($row['status'], 'Pending') !== false ? ' active' : ''; ?>">Pending</button>
                                    <button type="submit" name="update_application" value="Approved" class="status-btn status-btn-approved<?php echo strpos($row['status'], 'Approved') !== false ? ' active' : ''; ?>">Approved</button>
                                    <button type="submit" name="update_application" value="Rejected" class="status-btn status-btn-rejected<?php echo (strpos($row['status'], 'Rejected') !== false || $row['status'] == 'Acknowledged') ? ' active' : ''; ?>">Rejected</button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" class="js-confirm" data-confirm-msg="Archive/Delete this application?">
                                    <input type="hidden" name="app_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="archive_application" class="btn-delete">Archive</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            
            <script>
            function filterApps(status, btn) {
                document.querySelectorAll('.app-tab-btn').forEach(b => {
                    b.style.background = '#e2e6ea';
                    b.style.color = '#333';
                });
                btn.style.background = 'var(--primary-color)';
                btn.style.color = 'white';

                document.querySelectorAll('.app-row').forEach(row => {
                    row.style.display = 'none'; 
                    if (status === 'rejected' && (row.classList.contains('status-rejected') || row.classList.contains('status-acknowledged'))) {
                        row.style.display = ''; 
                    } else if (row.classList.contains('status-' + status)) {
                        row.style.display = ''; 
                    }
                });
            }

            document.addEventListener('DOMContentLoaded', () => {
                const pendingBtn = document.querySelector('.app-tab-btn');
                if (pendingBtn) filterApps('pending', pendingBtn);
            });
            </script>
        </div>

        <div id="lost-found" class="section">
            <h3 style="color: var(--primary-color); border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px;">User Submitted Lost Pets</h3>
            
            <!-- TABS UI -->
            <div style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 15px;">
                <button type="button" id="admin-tab-missing" style="padding: 8px 20px; border: none; background: var(--danger); color: white; border-radius: 4px; cursor: pointer; font-weight: bold; flex: 1;" onclick="switchAdminLostTab('missing')">
                    <i class="fas fa-search"></i> Active Missing Reports
                </button>
                <button type="button" id="admin-tab-found" style="padding: 8px 20px; border: none; background: #e2e6ea; color: #333; border-radius: 4px; cursor: pointer; font-weight: bold; flex: 1;" onclick="switchAdminLostTab('found')">
                    <i class="fas fa-check-circle"></i> Resolved (Found)
                </button>
            </div>

            <!-- MISSING TABLE -->
            <div id="admin-table-missing" style="display: block; overflow-x: auto;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr style="background-color: var(--primary-color); color: white;">
                            <th>PHOTO</th>
                            <th>PET NAME</th>
                            <th>LOCATION</th>
                            <th>CONTACT</th>
                            <th>DETAILS</th>
                            <th style="text-align: center;">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // THIS IS THE LINE THAT WAS MISSING
                        $missing = $conn->query("SELECT * FROM lost_pets WHERE status = 'Missing' AND is_archived = 0 ORDER BY id DESC");
                        
                        if($missing && $missing->rowCount() > 0) {
                            while($row = $missing->fetch(PDO::FETCH_ASSOC)) {
                                echo "<tr>";
                                echo "<td><img src='{$row['photo_path']}' style='width: 50px; height: 50px; object-fit: cover; border-radius: 4px;' onerror=\"this.src='https://via.placeholder.com/50'\"></td>";
                                echo "<td style='font-weight: bold; color: var(--primary-color);'>" . htmlspecialchars($row['pet_name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['location']) . "</td>";
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
                        } else {
                            echo "<tr><td colspan='6' style='padding: 15px; text-align: center; color: #666;'>No active missing reports.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>
            </div>

            <!-- FOUND TABLE -->
            <div id="admin-table-found" style="display: none; overflow-x: auto;">
                <div class="table-responsive">
                <table class="data-table" style="margin-top: 0;">
                    <thead>
                        <tr style="background-color: var(--success); color: white;">
                            <th>PHOTO</th>
                            <th>PET NAME</th>
                            <th>LOCATION</th>
                            <th>CONTACT</th>
                            <th>DETAILS</th>
                            <th style="text-align: center;">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $found = $conn->query("SELECT * FROM lost_pets WHERE status = 'Found' AND is_archived = 0 ORDER BY id DESC");
                        
                        if($found && $found->rowCount() > 0) {
                            while($row = $found->fetch(PDO::FETCH_ASSOC)) {
                                echo "<tr style='background-color: #f8f9fa;'>";
                                echo "<td><img src='{$row['photo_path']}' style='width: 50px; height: 50px; object-fit: cover; border-radius: 4px; opacity: 0.7;' onerror=\"this.src='https://via.placeholder.com/50'\"></td>";
                                echo "<td style='color: #666;'><del>" . htmlspecialchars($row['pet_name']) . "</del></td>";
                                echo "<td style='color: #666;'>" . htmlspecialchars($row['location']) . "</td>";
                                echo "<td style='color: #666;'>" . htmlspecialchars($row['contact_number']) . "</td>";
                                echo "<td style='font-size: 0.85rem; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #666;' title='" . htmlspecialchars($row['description']) . "'>" . htmlspecialchars($row['description']) . "</td>";
                                
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
                            echo "<tr><td colspan='6' style='padding: 15px; text-align: center; color: #666;'>No found pets yet.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>
            </div>

            <script>
            function switchAdminLostTab(tabName) {
                var btnMissing = document.getElementById('admin-tab-missing');
                var btnFound = document.getElementById('admin-tab-found');
                var tableMissing = document.getElementById('admin-table-missing');
                var tableFound = document.getElementById('admin-table-found');
                
                if (tabName === 'missing') {
                    btnMissing.style.background = 'var(--danger)';
                    btnMissing.style.color = 'white';
                    btnFound.style.background = '#e2e6ea';
                    btnFound.style.color = '#333';
                    
                    tableMissing.style.display = 'block';
                    tableFound.style.display = 'none';
                } else {
                    btnFound.style.background = 'var(--success)';
                    btnFound.style.color = 'white';
                    btnMissing.style.background = '#e2e6ea';
                    btnMissing.style.color = '#333';
                    
                    tableFound.style.display = 'block';
                    tableMissing.style.display = 'none';
                }
            }
            </script>
        </div>

        <div id="shelter-status" class="section">
            <h3>Update Shelter Details</h3>
            <div class="table-responsive">
            <table class="data-table">
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
                                    <select name="status">
                                        <option value="Open" <?php if($row['status'] == 'Open') echo 'selected'; ?>>Open</option>
                                        <option value="Full" <?php if($row['status'] == 'Full') echo 'selected'; ?>>Full</option>
                                    </select>
                                    <button type="submit" name="update_shelter" class="btn-save">Save</button>
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

    <script>
        let pendingConfirmForm = null;
        let pendingConfirmSubmitter = null;
        document.querySelectorAll('form.js-confirm').forEach(function(form) {
            form.addEventListener('submit', function(e) {
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

        function showSection(sectionId, element) {
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelectorAll('.sidebar ul li a').forEach(link => {
                link.classList.remove('active');
            });
            document.getElementById(sectionId).classList.add('active');
            element.classList.add('active');
        }

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

        document.addEventListener('DOMContentLoaded', () => {
             const managePetsSection = document.getElementById('manage-pets');
             if(managePetsSection) managePetsSection.classList.add('active');

             const typeChartEl = document.getElementById('typeChart');
             if (typeChartEl) {
                 new Chart(typeChartEl.getContext('2d'), {
                     type: 'doughnut',
                     data: {
                         labels: ['Dogs', 'Cats'],
                         datasets: [{
                             data: [<?php echo $dog_count; ?>, <?php echo $cat_count; ?>],
                             backgroundColor: ['#003366', '#d4af37'],
                             borderWidth: 1
                         }]
                     },
                     options: { responsive: true, maintainAspectRatio: false }
                 });
             }

             const breedChartEl = document.getElementById('breedChart');
             if (breedChartEl) {
                 new Chart(breedChartEl.getContext('2d'), {
                     type: 'bar',
                     data: {
                         labels: <?php echo json_encode($breed_labels); ?>,
                         datasets: [{
                             label: 'Number Available',
                             data: <?php echo json_encode($breed_counts); ?>,
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
                 new Chart(appChartEl.getContext('2d'), {
                     type: 'pie',
                     data: {
                         labels: ['Pending', 'Approved', 'Rejected'],
                         datasets: [{
                             data: [<?php echo $app_pending; ?>, <?php echo $app_approved; ?>, <?php echo $app_rejected; ?>],
                             backgroundColor: ['#ffc107', '#28a745', '#dc3545'],
                             borderWidth: 1
                         }]
                     },
                     options: { responsive: true, maintainAspectRatio: false }
                 });
             }
        });
    </script>
</body>
</html>