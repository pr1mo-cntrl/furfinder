<?php
include 'db.php';

// --- TAB LOGIC ---
$activeTab = 'home'; 
// Allow the URL to set the active tab after a redirect
if (isset($_GET['tab'])) { $activeTab = $_GET['tab']; }
if (isset($_POST['submit_lost_report'])) { $activeTab = 'lost'; }
if (isset($_POST['submit_application'])) { $activeTab = 'adopt'; }
if (isset($_POST['submit_donation'])) { $activeTab = 'donate'; }
if (isset($_GET['search']) || isset($_GET['type_filter'])) { $activeTab = 'adopt'; }

// --- HANDLE USER MARKING PET AS FOUND ---
if (isset($_POST['mark_as_found'])) {
    $report_id = $_POST['report_id'];
    $current_user = $_SESSION['user_id'];
    
    $update_query = "UPDATE lost_pets SET status = 'Found' WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($update_query);
    if ($stmt->execute([$report_id, $current_user])) {
        $_SESSION['flash_msg'] = "Great news! Your pet has been marked as Found.";
        header("Location: index.php?tab=lost");
        exit();
    }
}

// --- HANDLE USER DELETING THEIR OWN LOST PET REPORT ---
if (isset($_POST['delete_own_report'])) {
    $report_id = $_POST['report_id'];
    $current_user = $_SESSION['user_id'];
    
    $delete_query = "DELETE FROM lost_pets WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($delete_query);
    if ($stmt->execute([$report_id, $current_user])) {
        $_SESSION['flash_msg'] = "Your lost pet report has been successfully deleted.";
        header("Location: index.php?tab=lost");
        exit();
    }
}

// --- 1. HANDLE LOST PET SUBMISSION ---
if (isset($_POST['submit_lost_report'])) {
    $name = $_POST['lf_name'];
    $loc = $_POST['lf_location'];
    $time = $_POST['lf_time'];
    $contact = $_POST['lf_contact'];
    $desc = $_POST['lf_desc'];
    
    if (isset($_FILES["lf_photo"]) && $_FILES["lf_photo"]["error"] == 0) {
        $file_tmp = $_FILES["lf_photo"]["tmp_name"];
        $file_name = $_FILES["lf_photo"]["name"];
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_filename = "lost_" . time() . "_" . uniqid() . "." . $file_ext;
        
        $supabase_url = trim(getenv('SUPABASE_URL') ?: $_SERVER['SUPABASE_URL'] ?? '');
        $supabase_key = trim(getenv('SUPABASE_SERVICE_KEY') ?: $_SERVER['SUPABASE_SERVICE_KEY'] ?? '');
        
        $storage_url = "$supabase_url/storage/v1/object/pet-photos/$unique_filename";
        $file_data = file_get_contents($file_tmp);
        
        $ch = curl_init($storage_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Required for Render
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $supabase_key",
            "Content-Type: " . $_FILES["lf_photo"]["type"],
            "x-upsert: true"
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 || $http_code === 201) {
            $uid = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
            $status = 'Missing';
            $image_url = "$supabase_url/storage/v1/object/public/pet-photos/$unique_filename";
            
            $stmt = $conn->prepare("INSERT INTO lost_pets (user_id, pet_name, location, last_seen, contact_number, description, photo_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$uid, $name, $loc, $time, $contact, $desc, $image_url, $status]);
            
            $_SESSION['flash_msg'] = "Alert Posted Successfully!";
            header("Location: index.php?tab=lost");
            exit();
        } else {
            $error_details = json_encode($response);
            echo "<script>alert('Supabase Error! Code: " . $http_code . " | Details: ' + " . $error_details . ");</script>";
        }
    }
}

// --- 2. HANDLE ADOPTION ---
if (isset($_POST['submit_application'])) {
    $uid = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    $pname = $_POST['app_pet_name'];
    $fname = $_POST['app_fullname'];
    $addr = $_POST['app_address'];
    $cont = $_POST['app_contact'];
    $housing = $_POST['app_housing'];
    $fence = $_POST['app_fence'];
    $members = $_POST['app_members'];
    $other_pets = $_POST['app_other_pets'];
    $income = $_POST['app_income'];
    $hours = $_POST['app_hours'];

    $upload_dir = "uploads/";
    if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }

    $brgy_path = ""; $id_path = ""; $cage_path = "";

    if (isset($_FILES['barangay_cert']) && $_FILES['barangay_cert']['error'] == 0) {
        $ext = pathinfo($_FILES['barangay_cert']['name'], PATHINFO_EXTENSION);
        $brgy_path = $upload_dir . time() . "_brgy." . $ext;
        move_uploaded_file($_FILES['barangay_cert']['tmp_name'], $brgy_path);
    }
    if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] == 0) {
        $ext = pathinfo($_FILES['valid_id']['name'], PATHINFO_EXTENSION);
        $id_path = $upload_dir . time() . "_id." . $ext;
        move_uploaded_file($_FILES['valid_id']['tmp_name'], $id_path);
    }
    if (isset($_FILES['cage_photo']) && $_FILES['cage_photo']['error'] == 0) {
        $ext = pathinfo($_FILES['cage_photo']['name'], PATHINFO_EXTENSION);
        $cage_path = $upload_dir . time() . "_cage." . $ext;
        move_uploaded_file($_FILES['cage_photo']['tmp_name'], $cage_path);
    }

    $sql = "INSERT INTO applications (pet_name, user_id, fullname, contact, address, housing_type, has_fence, household_members, other_pets, income_source, hours_alone, barangay_cert, valid_id, cage_photo, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";
    $stmt = $conn->prepare($sql);
    
    if ($stmt->execute([$pname, $uid, $fname, $cont, $addr, $housing, $fence, $members, $other_pets, $income, $hours, $brgy_path, $id_path, $cage_path])) {
        $_SESSION['flash_msg'] = "Application submitted successfully! CVAO will notify you when approved.";
        header("Location: index.php?tab=adopt");
        exit();
    }
}

// --- 3. HANDLE DONATION ---
if (isset($_POST['submit_donation'])) {
    $dname = $_POST['donor_name'];
    $damt = $_POST['donor_amount'];
    $dmsg = $_POST['donor_message'];
    
    $stmt = $conn->prepare("INSERT INTO donations (donor_name, amount, message) VALUES (?, ?, ?)");
    $stmt->execute([$dname, $damt, $dmsg]);
    $showQR = true; 
}

// --- 4. CALCULATE FUNDRAISER TOTAL ---
$fundraiser_target = 50000;
$total_raised_query = $conn->query("SELECT SUM(amount) FROM donations");
$total_raised = 0;
if ($total_raised_query) {
    $row = $total_raised_query->fetch();
    $total_raised = $row[0] ? $row[0] : 0; 
}

$progress_percent = ($total_raised / $fundraiser_target) * 100;
if ($progress_percent > 100) $progress_percent = 100; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FurFinder | Baguio City</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* CSS RESET & VARS */
        :root {
            --primary-color: #003366; 
            --accent-color: #d4af37; 
            --bg-light: #f4f7f6;
            --text-dark: #333;
            --white: #ffffff;
            --success: #28a745;
            --danger: #dc3545;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Open Sans', sans-serif; }
        body { background-color: var(--bg-light); color: var(--text-dark); line-height: 1.6; padding-bottom: 50px; overflow-x: hidden; }

        /* LOADING SCREEN STYLES */
        #loader {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: var(--primary-color); z-index: 9999;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            transition: opacity 0.5s ease-out;
        }
        .paw-print { color: var(--accent-color); font-size: 4rem; animation: bounce 1s infinite alternate; }
        #loader h2 { color: white; margin-top: 20px; font-size: 2rem; opacity: 0; animation: fadeInText 1s ease-in forwards 0.5s; }
        @keyframes bounce { from { transform: translateY(0); } to { transform: translateY(-20px); } }
        @keyframes fadeInText { to { opacity: 1; } }

        /* NAV */
        nav { background-color: var(--primary-color); color: var(--white); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .logo { display: flex; align-items: center; font-size: 1.5rem; font-weight: 700; gap: 10px; }
        .logo i { color: var(--accent-color); }
        .nav-links { list-style: none; display: flex; gap: 20px; align-items: center; }
        .nav-links a { color: white; text-decoration: none; font-weight: 600; padding: 8px 12px; border-radius: 4px; transition: var(--transition); cursor: pointer; }
        .nav-links a:hover, .nav-links a.active { background-color: rgba(255,255,255,0.15); color: var(--accent-color); }
        .auth-btn { background: var(--accent-color); color: var(--primary-color) !important; padding: 8px 20px !important; border-radius: 20px; text-align: center; }
        
        /* NEW: Hamburger Menu Icon */
        .menu-toggle { display: none; font-size: 1.8rem; cursor: pointer; color: white; }

        /* LAYOUT */
        .container { max-width: 1100px; margin: 2rem auto; padding: 0 20px; display: none; }
        .container.active { display: block; animation: fadeIn 0.5s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        h1, h2, h3 { color: var(--primary-color); margin-bottom: 1rem; }
        .section-header { text-align: center; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid #ddd; }
        img { max-width: 100%; height: auto; }

        /* HERO */
        .hero { background: linear-gradient(rgba(0,51,102,0.7), rgba(0,51,102,0.7)), url('https://images.unsplash.com/photo-1548199973-03cce0bbc87b?auto=format&fit=crop&w=1000&q=80'); background-size: cover; background-position: center; padding: 4rem 2rem; border-radius: 8px; text-align: center; margin-bottom: 2rem; color: white; }
        .hero h1 { color: white; font-size: 2.5rem; text-shadow: 2px 2px 4px rgba(0,0,0,0.6); }
        .hero p { color: #f1f1f1; font-size: 1.1rem; }

        /* CONTENT */
        .content-box { background: white; padding: 2rem; border-radius: 8px; margin-bottom: 2rem; box-shadow: 0 3px 6px rgba(0,0,0,0.1); }
        .ordinance-box { border-left: 5px solid var(--accent-color); }
        .req-list li { margin-bottom: 15px; list-style: none; padding-left: 1.5rem; position: relative; }
        .req-list li::before { content: "\f00c"; font-family: "Font Awesome 6 Free"; font-weight: 900; color: var(--success); position: absolute; left: 0; top: 3px; }

        /* FAQ STYLE */
        .faq-box { margin-top: 2rem; }
        .faq-item { border-bottom: 1px solid #eee; padding: 15px 0; }
        .faq-item h4 { color: var(--primary-color); margin-bottom: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .faq-item h4:hover { color: var(--accent-color); }
        .faq-item p { color: #555; font-size: 0.95rem; line-height: 1.5; display: none; margin-left: 10px; }
        .faq-item.open p { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from {opacity:0; transform:translateY(-5px);} to {opacity:1; transform:translateY(0);} }

        /* PET GRID & CARDS */
        .pet-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .pet-card { background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 3px 6px rgba(0,0,0,0.1); transition: var(--transition); position: relative; }
        .pet-card:hover { transform: translateY(-5px); }
        .pet-img { height: 200px; background: #eee; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .pet-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
        .pet-card:hover .pet-img img { transform: scale(1.05); }
        .pet-details { padding: 1.5rem; }
        
        .badge-new { position: absolute; top: 10px; right: 10px; background: var(--accent-color); color: var(--primary-color); padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 0.8rem; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        
        /* LOST & FOUND */
        .lf-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; }
        .report-form { background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 3px 6px rgba(0,0,0,0.1); }
        .missing-feed { display: flex; flex-direction: column; gap: 15px; }
        .missing-card { background: white; padding: 1rem; border-radius: 8px; border-left: 5px solid var(--danger); display: flex; gap: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); align-items: flex-start; }
        .missing-img-container { width: 100px; height: 100px; background: #eee; border-radius: 8px; flex-shrink: 0; overflow: hidden; }
        .missing-img-container img { width: 100%; height: 100%; object-fit: cover; }
        .active-report-card { background: #f8f9fa; border: 1px solid #ddd; padding: 10px; border-radius: 6px; display: flex; align-items: center; gap: 15px; margin-bottom: 10px; }

        /* SHELTERS */
        .shelter-card { background: white; border-radius: 8px; padding: 2rem; margin-bottom: 2rem; display: flex; gap: 2rem; align-items: flex-start; box-shadow: 0 3px 6px rgba(0,0,0,0.1); }
        .shelter-logo img { width: 100px; height: 100px; object-fit: cover; border-radius: 50%; border: 3px solid var(--primary-color); padding: 2px; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 0.85rem; margin-left: 10px; }
        .status-open { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-full { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* FORMS & MODALS */
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.9rem; color: #555; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .btn-primary { display: block; width: 100%; padding: 12px; background-color: var(--primary-color); color: white; border: none; border-radius: 4px; margin-top: 1rem; cursor: pointer; font-weight: 600; transition: 0.2s; }
        .btn-primary:hover { background-color: var(--accent-color); color: var(--primary-color); }
        .btn-secondary { background-color: #e2e6ea; color: #333; border: 1px solid #ccc; padding: 8px 12px; border-radius: 4px; cursor: pointer; margin-left: 5px; font-size: 0.9rem; }
        .btn-secondary:hover { background-color: #dbe0e5; }
        
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); align-items: center; justify-content: center; backdrop-filter: blur(3px); }
        .modal-content { background-color: #fefefe; padding: 2rem; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; position: relative; animation: fadeIn 0.3s; }
        .close-modal { float: right; font-size: 28px; cursor: pointer; color: #aaa; position: absolute; right: 20px; top: 15px; }

        /* =========================================================
           NEW RESPONSIVE MEDIA QUERIES FOR PHONES AND TABLETS 
           ========================================================= */
        @media (max-width: 768px) {
            /* Mobile Navbar */
            .menu-toggle { display: block; }
            nav { padding: 1rem; }
            .nav-links { 
                display: none; 
                flex-direction: column; 
                width: 100%; 
                position: absolute; 
                top: 100%; 
                left: 0; 
                background-color: var(--primary-color); 
                padding: 10px 0; 
                box-shadow: 0 4px 6px rgba(0,0,0,0.2); 
            }
            .nav-links.active-nav { display: flex; }
            .nav-links li { width: 100%; text-align: center; margin: 5px 0; }
            .nav-links a { display: inline-block; width: 90%; }
            
            /* Responsive Content Adjustments */
            .hero { padding: 2rem 1rem; }
            .hero h1 { font-size: 1.8rem; }
            .content-box, .ordinance-box { padding: 1.5rem; }
            
            /* Layout Grids */
            .lf-layout { grid-template-columns: 1fr; }
            .shelter-card { flex-direction: column; align-items: center; text-align: center; }
            .missing-card { flex-direction: column; align-items: center; text-align: center; }
            .missing-img-container { width: 100%; height: 200px; }
            
            /* Active Reports Adjustments */
            .active-report-card { flex-direction: column; text-align: center; width: 100%; }
            .active-report-actions { justify-content: center; width: 100%; margin-top: 10px; }
            .active-report-actions form { width: 48%; }
            .active-report-actions button { width: 100%; }
            
            /* Search Filters */
            form[method="GET"] { flex-direction: column; }
            form[method="GET"] input, form[method="GET"] select, form[method="GET"] button { width: 100%; }
        }
    </style>
</head>
<body>

    <div id="loader">
        <i class="fas fa-paw paw-print"></i>
        <h2>FurFinder</h2>
    </div>

    <nav>
        <div class="logo"><i class="fas fa-paw"></i> FurFinder</div>
        <!-- Hamburger Menu Button -->
        <div class="menu-toggle" onclick="toggleMobileNav()"><i class="fas fa-bars"></i></div>
        
        <ul class="nav-links" id="nav-links">
            <li><a onclick="showPage('home')" id="nav-home" class="active">Home</a></li>
            <li><a onclick="showPage('shelters')" id="nav-shelters">Shelter</a></li>
            <li><a onclick="showPage('donate')" id="nav-donate">In-Kind Donations</a></li>
            
            <?php if(isset($_SESSION['user_id'])): ?>
                <li><a onclick="showPage('adopt')" id="nav-adopt">Adopt</a></li>
                <li><a onclick="showPage('lost')" id="nav-lost">Lost & Found</a></li>
                
                <li style="color:var(--accent-color); margin:10px 0;">Hi, <?php echo htmlspecialchars(isset($_SESSION['name']) ? $_SESSION['name'] : 'User'); ?></li>
                <li><a href="logout.php" class="auth-btn">Logout</a></li>
            <?php else: ?>
                <li><a href="login.php" class="auth-btn">Login / Signup</a></li>
            <?php endif; ?>
        </ul>
    </nav>

    <?php if(isset($_SESSION['flash_msg'])): ?>
    <div id="flash-banner" style="position: fixed; top: 90px; right: 20px; z-index: 9999; background: var(--success); color: white; padding: 15px 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 15px; transition: opacity 0.5s ease;">
        <i class="fas fa-check-circle" style="font-size: 1.5rem;"></i>
        <div>
            <h4 style="margin: 0; color: white;">Success!</h4>
            <p style="margin: 0; font-size: 0.9rem;"><?php echo $_SESSION['flash_msg']; ?></p>
        </div>
        <button onclick="this.parentElement.style.opacity='0'; setTimeout(()=>this.parentElement.style.display='none', 500);" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; margin-left: 10px;">&times;</button>
    </div>
    <script>
        setTimeout(() => {
            const banner = document.getElementById('flash-banner');
            if(banner) {
                banner.style.opacity = '0';
                setTimeout(() => banner.style.display = 'none', 500);
            }
        }, 5000);
    </script>
    <?php unset($_SESSION['flash_msg']); endif; ?> 

    <?php
// 1. Handle dismiss actions for Approved or Rejected applications
if (isset($_POST['dismiss_notification'])) {
    $app_id_to_dismiss = $_POST['app_id'];
    
    $result = $conn->query("SELECT status FROM applications WHERE id = '$app_id_to_dismiss'");
    if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
        $new_status = $row['status'] . '_Seen';
        $conn->query("UPDATE applications SET status = '$new_status' WHERE id = '$app_id_to_dismiss'");
    }
    
    echo "<script>window.location.href='index.php';</script>";
}

// 2. Display notifications for logged-in users
if (isset($_SESSION['user_id'])) {
    $current_user_id = $_SESSION['user_id']; 
    
    $check_status = $conn->query("SELECT * FROM applications WHERE user_id = '$current_user_id' AND status IN ('Pending', 'Approved', 'Rejected')");

    if ($check_status && $check_status->rowCount() > 0) {
        while ($app = $check_status->fetch(PDO::FETCH_ASSOC)) {
            $app_id = $app['id'];
            $status = $app['status'];

            if ($status == 'Pending') {
                echo '
                <div style="background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; border: 1px solid #ffeeba; margin-bottom: 20px; width: 90%; margin-left: auto; margin-right: auto; text-align: center; font-family: sans-serif;">
                    <strong>⏳ Application Pending:</strong> Your adoption application is currently under review by the CVAO team. We will update you here as soon as a decision is made!
                </div>';
            } 
            elseif ($status == 'Approved') {
                echo '
                <div style="background-color: #d4edda; color: #155724; padding: 20px; border-radius: 8px; border: 1px solid #c3e6cb; margin-bottom: 20px; width: 90%; margin-left: auto; margin-right: auto; text-align: center; font-family: sans-serif;">
                    <h3 style="margin-top: 0;">🎉 Application Approved!</h3>
                    <p style="margin-bottom: 15px;">Your adoption application has been reviewed and approved. Please proceed to the Baguio City Veterinary and Agriculture Office (CVAO) for your physical screening and interview.</p>
                    <form method="POST">
                        <input type="hidden" name="app_id" value="' . $app_id . '">
                        <button type="submit" name="dismiss_notification" style="background-color: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer; font-weight: bold;">Okay, got it!</button>
                    </form>
                </div>';
            }
            elseif ($status == 'Rejected') {
                echo '
                <div style="background-color: #f8d7da; color: #721c24; padding: 20px; border-radius: 8px; border: 1px solid #f5c6cb; margin-bottom: 20px; width: 90%; margin-left: auto; margin-right: auto; text-align: center; font-family: sans-serif;">
                    <h3 style="margin-top: 0;">❌ Application Update</h3>
                    <p style="margin-bottom: 15px;">We regret to inform you that your recent adoption application was not approved by the CVAO at this time. Thank you for your interest in providing a home for our shelter pets.</p>
                    <form method="POST">
                        <input type="hidden" name="app_id" value="' . $app_id . '">
                        <button type="submit" name="dismiss_notification" style="background-color: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer; font-weight: bold;">Dismiss</button>
                    </form>
                </div>';
            }
        }
    }
}
?>

<section id="home" class="container active">
        <div class="hero">
            <h1>Adopt, Don't Shop.</h1>
            <p>Help us find loving homes for the strays of Baguio City.</p>
            
            <?php if(isset($_SESSION['user_id'])): ?>
            <div style="margin-top: 25px; display:flex; gap:15px; justify-content:center; flex-wrap:wrap;">
                <button onclick="showPage('adopt')" class="btn-primary" style="width:auto; display:inline-block; padding: 12px 30px; font-size: 1.1rem; box-shadow: 0 4px 6px rgba(0,0,0,0.2);">
                    <i class="fas fa-search"></i> Find a Friend
                </button>
                <button onclick="showPage('lost')" class="btn-primary" style="background:white; color:var(--primary-color); width:auto; display:inline-block; padding: 12px 30px; font-size: 1.1rem; box-shadow: 0 4px 6px rgba(0,0,0,0.2);">
                    <i class="fas fa-exclamation-triangle"></i> Report Lost
                </button>
            </div>
            <?php else: ?>
            <div style="margin-top: 25px; display:flex; justify-content:center;">
                <a href="login.php" class="btn-primary" style="background: var(--accent-color); color: var(--primary-color); width:auto; display:inline-block; padding: 12px 30px; font-size: 1.1rem; box-shadow: 0 4px 6px rgba(0,0,0,0.2); text-decoration: none; font-weight: bold;">
                    Login or Register to View Pets
                </a>
            </div>
            <?php endif; ?>
            
        </div>

        <div class="content-box">
            <h2><i class="fas fa-question-circle"></i> Pet Adoption FAQ</h2>
            <div class="faq-box">
                <div class="faq-item" onclick="toggleFaq(this)">
                    <h4>How can I adopt from FurFinder? <i class="fas fa-chevron-down"></i></h4>
                    <p>Applicants go through a screening process to ensure our rescued animals go to loving homes. The process includes filling out the application form on this site, an interview (phone/online), and a shelter visit to meet your chosen pet.</p>
                </div>
                <div class="faq-item" onclick="toggleFaq(this)">
                    <h4>Can you adopt my pet? <i class="fas fa-chevron-down"></i></h4>
                    <p>FurFinder and its partners generally do not adopt owned pets. We already have hundreds of shelter animals rescued from cruelty and neglect. If you need to give up your pet, please consider other rehoming options first.</p>
                </div>
                <div class="faq-item" onclick="toggleFaq(this)">
                    <h4>Why is there an adoption fee? <i class="fas fa-chevron-down"></i></h4>
                    <p>The adoption fee is a token of your commitment and helps cover the costs of spay/neuter surgery, vaccinations, and flea/tick treatments for the animals. It is typically P500 for cats and P1000 for dogs.</p>
                </div>
                <div class="faq-item" onclick="toggleFaq(this)">
                    <h4>Can my adoption application get denied? <i class="fas fa-chevron-down"></i></h4>
                    <p>Yes. Reasons for denial include: inability to keep the pet indoors (or safe), incompatibility with household members, or circumstances that may compromise the health and safety of the animal.</p>
                </div>
                <div class="faq-item" onclick="toggleFaq(this)">
                    <h4>I live in the province/abroad. Can I still adopt? <i class="fas fa-chevron-down"></i></h4>
                    <p>Yes, but special arrangements must be made for meet-and-greets. Please contact us to discuss logistics.</p>
                </div>
                <div class="faq-item" onclick="toggleFaq(this)">
                    <h4>Do you have purebred cats or dogs? <i class="fas fa-chevron-down"></i></h4>
                    <p>It is rare that purebreds are admitted. Sadly, they are often valued more than aspins/puspins who are equally deserving. Please consider adopting a local breed!</p>
                </div>
                <div class="faq-item" onclick="toggleFaq(this)">
                    <h4>Can I return my adopted pet if I change my mind? <i class="fas fa-chevron-down"></i></h4>
                    <p>A pet is a lifetime commitment. However, if you truly cannot keep your adopted pet, <strong>please do not abandon them</strong>. Return them to us so we can find another home for them.</p>
                </div>
            </div>
        </div>

        <div class="ordinance-box">
            <h2><i class="fas fa-gavel"></i> City Ordinance Requirements</h2>
            <p style="margin-bottom: 1rem; font-style: italic;">As per Baguio City Ordinance #19 s.2021</p>
            <ul class="req-list">
                <li><strong>Barangay Certificate:</strong> Must state that you are a resident of said barangay.</li>
                <li><strong>Valid Identification (ID):</strong> Present one of the following: UMID, Driver's License, PRC ID, etc.</li>
                <li><strong>Dog Cage & Leash:</strong> Required for transport.</li>
            </ul>
        </div>
    </section>

<section id="adopt" class="container">
        <div class="section-header">
            <h2>Available for Adoption</h2>
            <p>Meet our furry friends looking for a forever home.</p>
        </div>

        <div style="margin-bottom: 30px; display: flex; justify-content: center; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <form method="GET" style="display: flex; gap: 10px; width: 100%; max-width: 800px; flex-wrap: wrap;">
                <input type="text" name="search" placeholder="Search by name or breed..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" style="padding: 10px; border: 1px solid #ccc; border-radius: 4px; flex-grow: 1; min-width: 200px;">
                
                <select name="type_filter" style="padding: 10px; border: 1px solid #ccc; border-radius: 4px; min-width: 120px;">
                    <option value="">All Types</option>
                    <option value="dog" <?php if(isset($_GET['type_filter']) && $_GET['type_filter'] == 'dog') echo 'selected'; ?>>Dogs</option>
                    <option value="cat" <?php if(isset($_GET['type_filter']) && $_GET['type_filter'] == 'cat') echo 'selected'; ?>>Cats</option>
                </select>

                <select name="age_filter" style="padding: 10px; border: 1px solid #ccc; border-radius: 4px; min-width: 150px;">
                    <option value="">All Ages</option>
                    <option value="0-6" <?php if(isset($_GET['age_filter']) && $_GET['age_filter'] == '0-6') echo 'selected'; ?>>Puppy / Kitten</option>
                    <option value="Young" <?php if(isset($_GET['age_filter']) && $_GET['age_filter'] == 'Young') echo 'selected'; ?>>Young</option>
                    <option value="Adult" <?php if(isset($_GET['age_filter']) && $_GET['age_filter'] == 'Adult') echo 'selected'; ?>>Adult</option>
                    <option value="Senior" <?php if(isset($_GET['age_filter']) && $_GET['age_filter'] == 'Senior') echo 'selected'; ?>>Senior</option>
                </select>

                <button type="submit" class="btn-primary" style="width: auto; margin-top: 0; padding: 10px 25px;"><i class="fas fa-search"></i> Filter</button>
            </form>
        </div>

        <div class="pet-grid">
            <?php
            $sql = "SELECT * FROM pets WHERE status='available' AND is_archived = 0";
            
            if (isset($_GET['search']) && !empty($_GET['search'])) {
                $search = $conn->real_escape_string($_GET['search']);
                $sql .= " AND (name LIKE '%$search%' OR breed LIKE '%$search%')";
            }
            if (isset($_GET['type_filter']) && !empty($_GET['type_filter'])) {
                $type = $conn->real_escape_string($_GET['type_filter']);
                $sql .= " AND type = '$type'";
            }
            if (isset($_GET['age_filter']) && !empty($_GET['age_filter'])) {
                $age = $conn->real_escape_string($_GET['age_filter']);
                $sql .= " AND age LIKE '%$age%'"; 
            }
            
            $result = $conn->query($sql);

            if($result && $result->rowCount() > 0){
                while($row = $result->fetch(PDO::FETCH_ASSOC)){
                    $age_parts = explode(' ', $row['age']);
                    $badge_text = !empty($age_parts[0]) ? htmlspecialchars($age_parts[0]) : 'New';
                    $badge = "<span class='badge-new'>{$badge_text}</span>";
                    
                    $backstory = !empty($row['backstory']) ? htmlspecialchars($row['backstory']) : "This pet is still waiting to tell their story!";
                    $medical = !empty($row['medical_history']) ? htmlspecialchars($row['medical_history']) : "Standard health check completed.";

                    echo "<div class='pet-card'>
                        $badge
                        <div class='pet-img'><img src='{$row['image_url']}' alt='Pet'></div>
                        <div class='pet-details'>
                            <h3 style='margin-bottom: 3px;'>" . htmlspecialchars($row['name']) . "</h3>
                            <p style='color: #666; font-size: 0.9rem; margin-bottom: 12px; font-weight: 600;'>" . htmlspecialchars($row['breed']) . " • " . htmlspecialchars($row['age']) . "</p>
                            
                            <details style='margin-bottom: 15px; background: #f9f9f9; padding: 10px; border-radius: 6px; border: 1px solid #eaeaea;'>
                                <summary style='cursor: pointer; font-weight: bold; color: var(--primary-color); outline: none; font-size: 0.9rem;'>
                                    <i class='fas fa-book-open'></i> Read My Story
                                </summary>
                                <div style='margin-top: 12px; font-size: 0.85rem; color: #444;'>
                                    <p style='margin-bottom: 8px;'><strong>About Me:</strong><br> $backstory</p>
                                    <p style='border-top: 1px dashed #ccc; padding-top: 8px;'><strong><i class='fas fa-notes-medical' style='color: var(--danger);'></i> Health Info:</strong><br> $medical</p>
                                </div>
                            </details>

                            <div style='display:flex; gap:5px; margin-top:10px;'>
                                <button class='btn-primary' style='flex:1; margin-top:0;' onclick=\"openAdoptModal('{$row['name']}')\">Apply to Adopt</button>
                                <button class='btn-secondary' onclick=\"sharePet('{$row['name']}', '{$row['breed']}')\"><i class='fas fa-share-alt'></i></button>
                            </div>
                        </div>
                    </div>";
                }
            } else {
                echo "<p style='text-align:center; width:100%; color:#666; padding: 40px; background: white; border-radius: 8px;'>No pets match your search criteria right now. Try adjusting your filters!</p>";
            }
            ?>
        </div>
    </section>

    <section id="lost" class="container">
        <div class="section-header">
            <h2>Lost & Found</h2>
        </div>

        <?php if(isset($_SESSION['user_id'])): ?>
        <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 3px 6px rgba(0,0,0,0.1); margin-bottom: 2rem; border-top: 4px solid var(--primary-color);">
            <h3 style="margin-bottom: 15px; color: var(--primary-color);"><i class="fas fa-clipboard-list"></i> My Active Reports</h3>
            
            <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                <?php
                $my_uid = $_SESSION['user_id'];
                $my_reports = $conn->query("SELECT * FROM lost_pets WHERE user_id = '$my_uid' AND status = 'Missing'");
                
                if($my_reports && $my_reports->rowCount() > 0) {
                    while($my_row = $my_reports->fetch(PDO::FETCH_ASSOC)) {
                    ?>
                    <div class="active-report-card">
                        <img src="<?php echo $my_row['photo_path']; ?>" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px;">
                        <div style="flex-grow: 1;">
                            <strong style="color: var(--danger);"><?php echo htmlspecialchars($my_row['pet_name']); ?></strong><br>
                            <span style="font-size: 0.85rem; color: #666;">Reported Missing</span>
                        </div>
                        <div class="active-report-actions" style="display: flex; gap: 8px;">
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="report_id" value="<?php echo $my_row['id']; ?>">
                                <button type="submit" name="mark_as_found" class="btn-primary" style="background: var(--success); margin: 0; padding: 8px 15px; font-size: 0.85rem;" onclick="return confirm('Are you sure you want to mark this pet as found?');">
                                    <i class="fas fa-check"></i> Found
                                </button>
                            </form>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="report_id" value="<?php echo $my_row['id']; ?>">
                                <button type="submit" name="delete_own_report" class="btn-primary" style="background: var(--danger); margin: 0; padding: 8px 15px; font-size: 0.85rem;" onclick="return confirm('Are you sure you want to permanently delete this report?');">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php
                }
                } else {
                    echo "<p style='color: #666; font-size: 0.9rem; margin: 0;'>You currently have no active missing pet reports.</p>";
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="lf-layout">
            <div class="report-form">
                <h3>Report Missing Pet</h3>
                <div style="background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; border-left: 5px solid #dc3545; margin-bottom: 20px; text-align: center; font-size: 0.9rem;">
                    <strong><i class="fas fa-user-lock"></i> Privacy Notice:</strong><br>
                    Your contact information is kept strictly confidential. Only the <strong>CVAO Admin</strong> will be able to view your phone number to coordinate with you if your pet is found.
            </div>
                <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Are you sure you want to post this lost pet alert?');">
                    <div class="form-group"><label>Pet Name</label><input type="text" name="lf_name" required></div>
                    <div class="form-group"><label>Location Last Seen</label><input type="text" name="lf_location" required></div>
                    <div class="form-group"><label>Time & Date Last Seen</label><input type="datetime-local" name="lf_time" required></div>
                    <div class="form-group"><label>Contact No.</label><input type="tel" name="lf_contact" required></div>
                    <div class="form-group"><label>Photo of Pet</label><input type="file" name="lf_photo" accept="image/*" required></div>
                    <div class="form-group"><label>Description of Pet</label><textarea name="lf_desc"></textarea></div>
                    <button type="submit" name="submit_lost_report" class="btn-primary">Post Alert</button>
                </form>
            </div>
            <div class="missing-feed">
                <h3>Recent Reports</h3>
                <?php
                $lost = $conn->query("SELECT * FROM lost_pets ORDER BY id DESC");
                
                if($lost && $lost->rowCount() > 0) {
                    while($row = $lost->fetch(PDO::FETCH_ASSOC)){
                        $status = $row['status']; 
                        
                        // Set colors for the left border and the status badge
                        $border_color = ($status == 'Found') ? "var(--success)" : "var(--danger)";
                        $badge_bg = ($status == 'Found') ? "#d4edda" : "#f8d7da";
                        $badge_text = ($status == 'Found') ? "#155724" : "#721c24";
                        
                        $map_query = urlencode($row['location'] . " Baguio City");
                        $map_link = "https://www.google.com/maps/search/?api=1&query=" . $map_query;

                        echo "<div class='missing-card' style='border-left: 5px solid {$border_color}; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); display: flex; gap: 15px; margin-bottom: 15px; align-items: stretch;'>
                            <div class='missing-img-container' style='width: 120px; height: 120px; flex-shrink: 0; border-radius: 6px; overflow: hidden; background: #eee;'>
                                <img src='{$row['photo_path']}' style='width: 100%; height: 100%; object-fit: cover;'>
                            </div>
                            <div style='flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between;'>
                                <div>
                                    <div style='display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px;'>
                                        <h4 style='margin: 0; color: var(--primary-color); font-size: 1.1rem;'>" . htmlspecialchars($row['pet_name']) . "</h4>
                                        <span style='background: {$badge_bg}; color: {$badge_text}; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;'>" . htmlspecialchars($status) . "</span>
                                    </div>
                                    <p style='margin: 0 0 8px 0; font-size: 0.85rem;'><a href='$map_link' target='_blank' style='text-decoration:none; color:#666;'><i class='fas fa-map-marker-alt' style='color:var(--danger)'></i> " . htmlspecialchars($row['location']) . "</a></p>
                                    ";
                                    
                                    // Wrap the description in a stylized quote box
                                    if (!empty($row['description'])) {
                                        echo "<p style='font-size: 0.85rem; color: #555; margin: 0 0 10px 0; font-style: italic; background: #f8f9fa; padding: 8px 10px; border-radius: 4px; border-left: 3px solid #ddd; line-height: 1.4;'>" . htmlspecialchars($row['description']) . "</p>";
                                    }
                                    
                                echo "</div>
                                
                                <div style='margin-top: auto; border-top: 1px solid #eee; padding-top: 8px; display: flex; justify-content: space-between; align-items: center;'>
                                    <span style='font-size: 0.8rem; color: #777;'>Contact CVAO to report/claim:</span>
                                    <a href='tel:0744435332' style='text-decoration:none; color:var(--primary-color); font-weight:bold; font-size: 0.9rem; background: #eef2f5; padding: 4px 10px; border-radius: 4px; transition: background 0.2s;' onmouseover=\"this.style.background='#dce4ec'\" onmouseout=\"this.style.background='#eef2f5'\"><i class='fas fa-phone'></i> (074) 443-5332</a>
                                </div>
                            </div>
                        </div>";
                    }
                } else { echo "<p>No lost pets reported.</p>"; }
                ?>
            </div>
    </section>

    <section id="shelters" class="container">
        <div class="section-header"><h2>Shelter</h2></div>
        <?php
        $cvao_status = "Open";
        $cvao_res = $conn->query("SELECT status FROM shelters WHERE name LIKE '%Baguio%' LIMIT 1");
        if($cvao_res && $r = $cvao_res->fetch(PDO::FETCH_ASSOC)) $cvao_status = $r['status'];
        ?>
        <div class="shelter-card">
            <div class="shelter-logo"><img src="uploads/shelter_cvao.jpg" onerror="this.src='https://via.placeholder.com/100?text=Logo'"></div>
            <div class="shelter-info">
                <h3>Baguio City Vet Office <span class="status-badge <?php echo ($cvao_status=='Open')?'status-open':'status-full'; ?>"><?php echo $cvao_status; ?></span></h3>
                <ul>
                    <li><i class="fas fa-map-marker-alt"></i> Slaughterhouse Cmpnd, Baguio</li>
                                        <li><a href="tel:0744435332" style="color:inherit; text-decoration:none;"><i class="fas fa-phone"></i> (074) 443-5332</a></li>
                    <li><i class="far fa-clock"></i> Mon - Fri, 8AM - 5PM</li>
                </ul>
            </div>
        </div>
    </section>

<section id="donate" class="container">
<div class="content-box" style="max-width:800px; margin:0 auto 2rem auto; padding: 20px;">
    
    <div style="background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; border-left: 5px solid #dc3545; margin-bottom: 25px; text-align: center;">
        <strong><i class="fas fa-exclamation-triangle"></i> CVAO Policy Notice:</strong><br>
        The Baguio City Veterinary and Agriculture Office strictly accepts <strong>IN-KIND DONATIONS ONLY</strong>. We do not accept monetary donations (cash, GCash, or bank transfers).
    </div>

    <h3 style="text-align:center; color:var(--primary-color); margin-bottom: 20px;"><i class="fas fa-box-open"></i> What We Currently Need</h3>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; text-align: left;">
        
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #ddd;">
            <h4 style="color: var(--success); margin-top: 0;"><i class="fas fa-dog"></i> Food & Feeding</h4>
            <ul style="color: #555; padding-left: 20px; margin-bottom: 0;">
                <li>Adult Dog Food & Puppy Kibble</li>
                <li>Adult Cat Food & Kitten Wet Food</li>
                <li>Stainless Steel Feeding Bowls</li>
            </ul>
        </div>
        
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #ddd;">
            <h4 style="color: #17a2b8; margin-top: 0;"><i class="fas fa-pump-soap"></i> Shelter Supplies</h4>
            <ul style="color: #555; padding-left: 20px; margin-bottom: 0;">
                <li>Leashes, Collars, and Harnesses</li>
                <li>Old Towels and Blankets</li>
                <li>Pet Soap, Shampoo, and Cleaning Brushes</li>
            </ul>
        </div>

    </div>

    <div style="text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #eee;">
        <p style="color: #666; margin: 0;">
            <strong>Drop-off Location:</strong> Baguio CVAO Compound, Slaughterhouse Compound, Magsaysay Ave.
        </p>
    </div>

</div>
            </section>

<div id="adoptModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeAdoptModal()">×</span>
            <h3>Adopt <span id="adopt-pet-name"></span></h3>
            <p style="margin-bottom:15px; font-size:0.9rem; color:#666;">Please fill out the form honestly. No login required.</p>
            
            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Are you sure you want to submit this adoption application? Please verify that all your details and documents are correct.');">
                <input type="hidden" id="app_pet_name" name="app_pet_name">
                
                <h4 style="color:var(--primary-color); border-bottom:1px solid #eee; margin-bottom:10px;">Personal Details</h4>
                <div class="form-group"><label>Full Name</label><input type="text" name="app_fullname" required></div>
                <div class="form-group"><label>Exact Address</label><input type="text" name="app_address" required></div>
                <div class="form-group"><label>Phone Number</label><input type="text" name="app_contact" required></div>

                <h4 style="color:var(--primary-color); border-bottom:1px solid #eee; margin:20px 0 10px;">Questionnaire</h4>
                <div class="form-group">
                    <label>Housing Type</label>
                    <select name="app_housing">
                        <option value="Owned">Owned House</option>
                        <option value="Rented">Rented (Landlord allows pets)</option>
                        <option value="Condo">Condo/Apartment</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Do you have a fence/gate?</label>
                    <select name="app_fence">
                        <option value="Yes">Yes</option>
                        <option value="No">No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Who do you live with? (Household Members)</label>
                    <textarea name="app_members" placeholder="e.g. Spouse, 2 kids (ages 5 & 8)" required></textarea>
                </div>
                <div class="form-group">
                    <label>Do you have other pets? (List them)</label>
                    <textarea name="app_other_pets" placeholder="e.g. 1 dog (Male, neutered), 2 cats" required></textarea>
                </div>
                <div class="form-group">
                    <label>Source of Income / Occupation</label>
                    <input type="text" name="app_income" required>
                </div>
                <div class="form-group">
                    <label>How many hours will the pet be alone daily?</label>
                    <input type="number" name="app_hours" required>
                </div>
                <div class="form-group" style="margin-top: 25px; border-top: 1px solid #eee; padding-top: 15px;">
    <h4 style="color: #003366; margin-bottom: 5px;">Required Documents</h4>
    <p style="font-size: 0.85rem; color: #666; margin-bottom: 15px;">Please upload clear images for verification.</p>

    <label style="display: block; margin-bottom: 5px;">1. Barangay Certificate</label>
    <input type="file" name="barangay_cert" accept="image/*,.pdf" required style="width: 100%; padding: 8px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px;">

    <label style="display: block; margin-bottom: 5px;">2. Valid Government ID</label>
    <input type="file" name="valid_id" accept="image/*,.pdf" required style="width: 100%; padding: 8px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px;">

    <label style="display: block; margin-bottom: 5px;">3. Photo of Pet Cage or Secure Area</label>
    <input type="file" name="cage_photo" accept="image/*" required style="width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px;">
</div>
                <button type="submit" name="submit_application" class="btn-primary">Submit Application</button>
            </form>
        </div>
    </div>

    <?php if(isset($showQR)): ?>
    <div id="qrModal" class="modal" style="display:flex;">
        <div class="modal-content" style="text-align:center; background:#007dfe; color:white;">
            <span class="close-modal" onclick="this.parentElement.parentElement.style.display='none'">×</span>
            <h3>Scan GCash QR</h3>
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=FurFinderDonation" style="margin:20px; border-radius:8px;">
        </div>
    </div>
    <?php endif; ?>

    <script>
        window.addEventListener('load', () => {
            const loader = document.getElementById('loader');
            setTimeout(() => { loader.style.opacity = '0'; setTimeout(() => { loader.style.display = 'none'; }, 500); }, 1500);
        });

        function showPage(pageId) {
            document.querySelectorAll('.container').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-links a').forEach(l => l.classList.remove('active'));
            document.getElementById(pageId).classList.add('active');
            const link = document.getElementById('nav-' + pageId);
            if(link) link.classList.add('active');
            
            // NEW: Automatically close mobile navigation when a link is clicked
            document.getElementById('nav-links').classList.remove('active-nav');
        }

        // NEW: Function to toggle mobile menu open/closed
        function toggleMobileNav() {
            document.getElementById('nav-links').classList.toggle('active-nav');
        }

        function toggleFaq(element) {
            element.classList.toggle('open');
            const icon = element.querySelector('i');
            if(element.classList.contains('open')) {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }

        function openAdoptModal(name) {
            document.getElementById('adopt-pet-name').innerText = name;
            document.getElementById('app_pet_name').value = name;
            document.getElementById('adoptModal').style.display = 'flex';
        }
        function closeAdoptModal() {
            document.getElementById('adoptModal').style.display = 'none';
        }

        function sharePet(name, breed) {
            if (navigator.share) {
                navigator.share({
                    title: 'Adopt ' + name,
                    text: 'Check out ' + name + ', a ' + breed + ' at FurFinder!',
                    url: window.location.href
                }).catch(console.error);
            } else { alert('Link copied!'); }
        }

        // Initialize Tab
        document.addEventListener('DOMContentLoaded', () => { showPage('<?php echo $activeTab; ?>'); });
    </script>
</body>
</html>