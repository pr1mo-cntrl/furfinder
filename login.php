<?php
include 'db.php';

$login_message = '';
$login_type = '';
$register_message = '';
$register_type = '';
$forgot_message = '';
$forgot_type = '';
$active_box = 'login';

// HANDLE REGISTER
if (isset($_POST['register'])) {
    $name = $_POST['name'];
    $email = strtolower(trim($_POST['email']));
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $barangay = $_POST['barangay'];
    $age_group = $_POST['age_group'];
    $current_pets = $_POST['current_pets_status'];
    $pref_breed = $_POST['preferred_breed'];

    $role = 'user';
    if ($email === 'admin@furfinder.com') { $role = 'admin'; }

    $active_box = 'register';

    try {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $register_message = 'This email is already registered. Please login or use Forgot Password.';
            $register_type = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, barangay, age_group, current_pets_status, preferred_breed) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $email, $pass, $role, $barangay, $age_group, $current_pets, $pref_breed])) {
                $register_message = 'Registration successful! Please login.';
                $register_type = 'success';
                $active_box = 'login';
            }
        }
    } catch (PDOException $e) {
        $register_message = 'Registration error: ' . $e->getMessage();
        $register_type = 'error';
    }
}

// HANDLE FORGOT PASSWORD
if (isset($_POST['reset_password'])) {
    $email = strtolower(trim($_POST['reset_email']));
    $new_password = $_POST['new_password'];

    $active_box = 'forgot';

    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $update->execute([$hashed, $email]);
            $forgot_message = 'Password reset successful! Please login with your new password.';
            $forgot_type = 'success';
            $active_box = 'login';
        } else {
            $forgot_message = 'No account found with that email.';
            $forgot_type = 'error';
        }
    } catch (PDOException $e) {
        $forgot_message = 'Database error: ' . $e->getMessage();
        $forgot_type = 'error';
    }
}

// HANDLE LOGIN
if (isset($_POST['login'])) {
    $email = strtolower(trim($_POST['email']));
    $password = $_POST['password'];

    $active_box = 'login';

    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (password_verify($password, $row['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['name'] = $row['name'];
                $_SESSION['role'] = $row['role'];

                if (isset($_POST['remember'])) {
                    // Extend the session cookie's lifetime in the browser to 30 days.
                    setcookie(session_name(), session_id(), time() + 30 * 24 * 60 * 60, "/");
                }

                if ($row['role'] == 'admin') {
                    header("Location: admin.php");
                    exit();
                } else {
                    header("Location: index.php");
                    exit();
                }
            } else {
                $login_message = 'Invalid password.';
                $login_type = 'error';
            }
        } else {
            $login_message = 'User not found.';
            $login_type = 'error';
        }
    } catch (PDOException $e) {
        $login_message = 'Database error: ' . $e->getMessage();
        $login_type = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | FurFinder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #003366;
            --accent-color: #d4af37;
            --border-color: #dfe3e6;
            --radius: 6px;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Open Sans', 'Segoe UI', sans-serif;
            margin: 0;
            color: #333;
            background: white;
        }

        nav.site-nav { background-color: var(--primary-color); color: white; padding: 0.9rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; box-shadow: 0 1px 2px rgba(16,24,40,0.06); }
        nav.site-nav .logo { display: flex; align-items: center; font-size: 1.3rem; font-weight: 700; letter-spacing: -0.01em; gap: 10px; color: white; text-decoration: none; }
        nav.site-nav .logo i { color: var(--accent-color); }
        nav.site-nav .nav-links { list-style: none; display: flex; gap: 6px; align-items: center; margin: 0; padding: 0; }
        nav.site-nav .nav-links a { color: rgba(255,255,255,0.9); text-decoration: none; font-weight: 500; font-size: 0.92rem; padding: 8px 14px; border-radius: var(--radius); transition: background-color 0.15s ease, color 0.15s ease; }
        nav.site-nav .nav-links a:hover { background-color: rgba(255,255,255,0.12); color: var(--accent-color); }
        nav.site-nav .nav-links a.auth-btn { background: var(--accent-color); color: var(--primary-color); font-weight: 600; }
        nav.site-nav .menu-toggle { display: none; font-size: 1.5rem; cursor: pointer; color: white; background: none; border: none; }

        .auth-shell { display: flex; min-height: calc(100vh - 60px); }

        /* LEFT: form panel */
        .auth-left {
            flex: 1 1 480px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 40px clamp(24px, 6vw, 80px);
        }
        .auth-card { width: 100%; max-width: 400px; }
        .auth-card h1 { font-size: 1.9rem; margin: 0 0 6px; color: #111; letter-spacing: -0.02em; }
        .auth-card .auth-sub { color: #767e89; font-size: 0.95rem; margin: 0 0 28px; }

        label { display: block; font-size: 0.85rem; font-weight: 600; color: #333; margin-bottom: 6px; }
        .section-label { text-align: left; font-size: 0.8rem; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: 0.02em; margin-top: 16px; margin-bottom: 4px; }
        input[type="email"], input[type="password"], input[type="text"], select {
            width: 100%;
            padding: 11px 14px;
            margin-bottom: 18px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.95rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,51,102,0.1);
        }

        .auth-row { display: flex; align-items: center; justify-content: space-between; margin: -8px 0 22px; font-size: 0.88rem; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; font-weight: 400; color: #444; margin: 0; cursor: pointer; }
        .checkbox-label input { width: 16px; height: 16px; margin: 0; accent-color: var(--primary-color); cursor: pointer; }

        button {
            width: 100%;
            padding: 13px;
            background: var(--primary-color);
            color: white;
            border: none;
            cursor: pointer;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.95rem;
            transition: background-color 0.15s ease;
        }
        button:hover { background: var(--accent-color); color: var(--primary-color); }

        .toggle { color: var(--primary-color); cursor: pointer; text-decoration: underline; text-underline-offset: 2px; font-weight: 600; }
        .toggle:hover { color: var(--accent-color); }
        .switch-line { text-align: center; margin-top: 24px; font-size: 0.9rem; color: #666; }

        .message { padding: 10px 12px; border-radius: var(--radius); margin-bottom: 16px; font-size: 0.85rem; text-align: left; }
        .message.error { background: #fdecea; color: #b3261e; border: 1px solid #f5c6c2; }
        .message.success { background: #eaf7ed; color: #1e7b34; border: 1px solid #c3e6cb; }

        /* RIGHT: brand panel */
        .auth-right {
            flex: 1 1 480px;
            position: relative;
            background: var(--primary-color);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }
        .auth-right-inner { position: relative; z-index: 2; max-width: 380px; text-align: center; color: white; }
        .paw-badge {
            width: 96px; height: 96px; border-radius: 50%;
            background: var(--accent-color); color: var(--primary-color);
            display: flex; align-items: center; justify-content: center;
            font-size: 2.4rem; margin: 0 auto 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
        }
        .auth-right-inner h2 { font-size: 1.6rem; margin: 0 0 12px; letter-spacing: -0.01em; }
        .auth-right-inner p { color: rgba(255,255,255,0.8); font-size: 0.95rem; line-height: 1.6; margin: 0 0 32px; }

        .feature-chips { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .chip {
            display: flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.18);
            padding: 9px 16px; border-radius: 30px;
            font-size: 0.85rem; font-weight: 600;
        }
        .chip i { color: var(--accent-color); }

        /* Decorative scattered paw prints, like the reference illustration */
        .deco-icon { position: absolute; color: rgba(255,255,255,0.12); z-index: 1; }
        .deco-icon.lg { font-size: 3.5rem; }
        .deco-icon.md { font-size: 2.2rem; }
        .deco-icon.sm { font-size: 1.4rem; }
        .d1 { top: 8%; left: 10%; }
        .d2 { top: 15%; right: 12%; }
        .d3 { bottom: 12%; left: 8%; }
        .d4 { bottom: 18%; right: 8%; }
        .d5 { top: 45%; left: 4%; }
        .d6 { top: 40%; right: 5%; }
        .d7 { bottom: 40%; left: 45%; }

        @media (max-width: 900px) {
            .auth-right { display: none; }
            .auth-left { padding: 32px 24px; }
        }
        @media (max-width: 420px) {
            .auth-card h1 { font-size: 1.6rem; }
        }
        @media (max-width: 768px) {
            nav.site-nav .menu-toggle { display: block; }
            nav.site-nav .nav-links {
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
            nav.site-nav .nav-links.active-nav { display: flex; }
            nav.site-nav .nav-links li { width: 100%; text-align: center; margin: 5px 0; }
            nav.site-nav .nav-links a { display: inline-block; width: 90%; }
        }
    </style>
</head>
<body>
    <nav class="site-nav">
        <a href="index.php" class="logo"><i class="fas fa-paw"></i> FurFinder</a>
        <button class="menu-toggle" onclick="document.querySelector('nav.site-nav .nav-links').classList.toggle('active-nav')"><i class="fas fa-bars"></i></button>
        <ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="index.php#shelter-section">Shelter</a></li>
            <li><a href="index.php#donate-section">In-Kind Donations</a></li>
            <li><a href="login.php" class="auth-btn">Login / Signup</a></li>
        </ul>
    </nav>

    <div class="auth-shell">
        <div class="auth-left">
            <div class="auth-card" id="login-box" style="display:<?php echo $active_box === 'login' ? 'block' : 'none'; ?>;">
                <h1>Welcome back</h1>
                <p class="auth-sub">Sign in to help pets find their way home.</p>
                <?php if ($login_message): ?>
                    <div class="message <?php echo $login_type; ?>"><?php echo htmlspecialchars($login_message); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <label>Email address</label>
                    <input type="email" name="email" required>
                    <label>Password</label>
                    <input type="password" name="password" required>
                    <div class="auth-row">
                        <label class="checkbox-label"><input type="checkbox" name="remember"> Remember me for 30 days</label>
                        <span class="toggle" onclick="showForgot()">Forgot password?</span>
                    </div>
                    <button type="submit" name="login">Sign in</button>
                </form>
                <p class="switch-line">Don't have an account? <span class="toggle" onclick="toggleForm()">Sign up</span></p>
            </div>

            <div class="auth-card" id="forgot-box" style="display:<?php echo $active_box === 'forgot' ? 'block' : 'none'; ?>;">
                <h1>Forgot password</h1>
                <p class="auth-sub">Enter your email and choose a new password.</p>
                <?php if ($forgot_message): ?>
                    <div class="message <?php echo $forgot_type; ?>"><?php echo htmlspecialchars($forgot_message); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <label>Email address</label>
                    <input type="email" name="reset_email" required>
                    <label>New password</label>
                    <input type="password" name="new_password" required>
                    <button type="submit" name="reset_password">Reset password</button>
                </form>
                <p class="switch-line"><span class="toggle" onclick="showLogin()">Back to login</span></p>
            </div>

            <div class="auth-card" id="register-box" style="display:<?php echo $active_box === 'register' ? 'block' : 'none'; ?>;">
                <h1>Create an account</h1>
                <p class="auth-sub">Help us get to know you better.</p>
                <?php if ($register_message): ?>
                    <div class="message <?php echo $register_type; ?>"><?php echo htmlspecialchars($register_message); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <label>Full name</label>
                    <input type="text" name="name" required>
                    <label>Email address</label>
                    <input type="email" name="email" required>

                    <p class="section-label">Adopter Profile</p>
                    <label>Barangay</label>
                    <input type="text" name="barangay" placeholder="e.g., Camp 7" required>

                    <label>Age group</label>
                    <select name="age_group" required>
                        <option value="" disabled selected>Select age group</option>
                        <option value="18-24">18-24 years old</option>
                        <option value="25-34">25-34 years old</option>
                        <option value="35-44">35-44 years old</option>
                        <option value="45-54">45-54 years old</option>
                        <option value="55+">55+ years old</option>
                    </select>

                    <label>Current pets</label>
                    <input type="text" name="current_pets_status" placeholder="e.g., 1 Dog, None" required>
                    <label>Preferred breed (optional)</label>
                    <input type="text" name="preferred_breed">

                    <p class="section-label">Security</p>
                    <label>Create password</label>
                    <input type="password" name="password" required>

                    <button type="submit" name="register">Sign up</button>
                </form>
                <p class="switch-line">Have an account? <span class="toggle" onclick="toggleForm()">Login here</span></p>
            </div>
        </div>

        <div class="auth-right">
            <i class="fas fa-paw deco-icon lg d1"></i>
            <i class="fas fa-bone deco-icon md d2"></i>
            <i class="fas fa-paw deco-icon sm d3"></i>
            <i class="fas fa-heart deco-icon md d4"></i>
            <i class="fas fa-paw deco-icon md d5"></i>
            <i class="fas fa-house deco-icon sm d6"></i>
            <i class="fas fa-paw deco-icon sm d7"></i>

            <div class="auth-right-inner">
                <div class="paw-badge"><i class="fas fa-paw"></i></div>
                <h2>Every pet deserves a loving home</h2>
                <p>FurFinder connects Baguio's stray and shelter animals with adopters, and helps reunite lost pets with their families.</p>
                <div class="feature-chips">
                    <div class="chip"><i class="fas fa-heart"></i> Adopt</div>
                    <div class="chip"><i class="fas fa-map-marker-alt"></i> Lost &amp; Found</div>
                    <div class="chip"><i class="fas fa-hand-holding-heart"></i> Donate</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleForm() {
            const login = document.getElementById('login-box');
            const reg = document.getElementById('register-box');
            const forgot = document.getElementById('forgot-box');
            forgot.style.display = 'none';
            if (login.style.display === 'none') {
                login.style.display = 'block';
                reg.style.display = 'none';
            } else {
                login.style.display = 'none';
                reg.style.display = 'block';
            }
        }

        function showForgot() {
            document.getElementById('login-box').style.display = 'none';
            document.getElementById('register-box').style.display = 'none';
            document.getElementById('forgot-box').style.display = 'block';
        }

        function showLogin() {
            document.getElementById('forgot-box').style.display = 'none';
            document.getElementById('register-box').style.display = 'none';
            document.getElementById('login-box').style.display = 'block';
        }
    </script>
</body>
</html>
