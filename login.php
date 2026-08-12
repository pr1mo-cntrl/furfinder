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
            background: #f4f7f6;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            color: #333;
        }
        .container {
            background: white;
            padding: 2.25rem 2rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 8px 24px rgba(0,0,0,0.06);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .container h2 { color: var(--primary-color); font-size: 1.4rem; letter-spacing: -0.01em; }
        label, .section-label { text-align: left; font-size: 0.8rem; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: 0.02em; margin-top: 12px; margin-bottom: -2px; display: block; }
        input, select {
            width: 100%;
            padding: 10px 12px;
            margin: 8px 0;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            box-sizing: border-box;
            font-family: inherit;
            font-size: 0.95rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,51,102,0.1);
        }
        button {
            width: 100%;
            padding: 12px;
            background: var(--primary-color);
            color: white;
            border: none;
            cursor: pointer;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.95rem;
            margin-top: 14px;
            transition: background-color 0.15s ease;
        }
        button:hover { background: var(--accent-color); color: var(--primary-color); }
        .toggle { margin-top: 18px; font-size: 0.85rem; color: var(--primary-color); cursor: pointer; text-decoration: underline; text-underline-offset: 2px; }
        .toggle:hover { color: var(--accent-color); }
        .message { padding: 10px 12px; border-radius: var(--radius); margin-bottom: 12px; font-size: 0.85rem; text-align: left; }
        .message.error { background: #fdecea; color: #b3261e; border: 1px solid #f5c6c2; }
        .message.success { background: #eaf7ed; color: #1e7b34; border: 1px solid #c3e6cb; }

        @media (max-width: 480px) {
            body { padding: 16px; align-items: flex-start; padding-top: 40px; }
            .container { padding: 1.75rem 1.25rem; }
        }
    </style>
</head>
<body>
    <div class="container" id="login-box" style="display:<?php echo $active_box === 'login' ? 'block' : 'none'; ?>;">
        <h2>Login</h2>
        <?php if ($login_message): ?>
            <div class="message <?php echo $login_type; ?>"><?php echo htmlspecialchars($login_message); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" name="login">Login</button>
        </form>
        <p class="toggle" onclick="toggleForm()">No account? Register here.</p>
        <p class="toggle" onclick="showForgot()">Forgot Password?</p>
    </div>

    <div class="container" id="forgot-box" style="display:<?php echo $active_box === 'forgot' ? 'block' : 'none'; ?>;">
        <h2 style="margin-bottom: 5px;">Forgot Password</h2>
        <p style="font-size: 0.85rem; color: #666; margin-bottom: 20px;">Enter your email and a new password.</p>
        <?php if ($forgot_message): ?>
            <div class="message <?php echo $forgot_type; ?>"><?php echo htmlspecialchars($forgot_message); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="email" name="reset_email" placeholder="Email Address" required>
            <input type="password" name="new_password" placeholder="New Password" required>
            <button type="submit" name="reset_password">Reset Password</button>
        </form>
        <p class="toggle" onclick="showLogin()">Back to Login</p>
    </div>

    <div class="container" id="register-box" style="display:<?php echo $active_box === 'register' ? 'block' : 'none'; ?>;">
        <h2 style="margin-bottom: 5px;">Register</h2>
        <p style="font-size: 0.85rem; color: #666; margin-bottom: 20px;">Help us get to know you better!</p>
        <?php if ($register_message): ?>
            <div class="message <?php echo $register_type; ?>"><?php echo htmlspecialchars($register_message); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="name" placeholder="Full Name" required>
            <input type="email" name="email" placeholder="Email Address" required>
            
            <p class="section-label">Adopter Profile</p>
            <input type="text" name="barangay" placeholder="Barangay (e.g., Camp 7)" required>
            
            <select name="age_group" required>
                <option value="" disabled selected>Select Age Group</option>
                <option value="18-24">18-24 years old</option>
                <option value="25-34">25-34 years old</option>
                <option value="35-44">35-44 years old</option>
                <option value="45-54">45-54 years old</option>
                <option value="55+">55+ years old</option>
            </select>

            <input type="text" name="current_pets_status" placeholder="Current Pets (e.g., 1 Dog, None)" required>
            <input type="text" name="preferred_breed" placeholder="Preferred Breed (Optional)">
            
            <p class="section-label">Security</p>
            <input type="password" name="password" placeholder="Create Password" required>
            
            <button type="submit" name="register">Sign Up</button>
        </form>
        <p class="toggle" onclick="toggleForm()">Have an account? Login here.</p>
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