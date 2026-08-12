<?php
include 'db.php';

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

    try {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            echo "<" . "script>alert('This email is already registered. Please login or use Forgot Password.');</" . "script>";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, barangay, age_group, current_pets_status, preferred_breed) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $email, $pass, $role, $barangay, $age_group, $current_pets, $pref_breed])) {
                echo "<" . "script>alert('Registration Successful! Please Login.');</" . "script>";
            }
        }
    } catch (PDOException $e) {
        echo "<" . "script>alert('Registration Error: " . $e->getMessage() . "');</" . "script>";
    }
}

// HANDLE FORGOT PASSWORD
if (isset($_POST['reset_password'])) {
    $email = strtolower(trim($_POST['reset_email']));
    $new_password = $_POST['new_password'];

    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $update->execute([$hashed, $email]);
            echo "<" . "script>alert('Password reset successful! Please login with your new password.');</" . "script>";
        } else {
            echo "<" . "script>alert('No account found with that email.');</" . "script>";
        }
    } catch (PDOException $e) {
        echo "<" . "script>alert('Database Error: " . $e->getMessage() . "');</" . "script>";
    }
}

// HANDLE LOGIN
if (isset($_POST['login'])) {
    $email = strtolower(trim($_POST['email']));
    $password = $_POST['password'];

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
                echo "<" . "script>alert('Invalid Password');</" . "script>";
            }
        } else {
            echo "<" . "script>alert('User not found');</" . "script>";
        }
    } catch (PDOException $e) {
        echo "<" . "script>alert('Database Error: " . $e->getMessage() . "');</" . "script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Login | FurFinder</title>
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
        .container { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; box-sizing: border-box; }
        input, select { width: 100%; padding: 10px; margin: 8px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-family: inherit; }
        button { width: 100%; padding: 12px; background: #003366; color: white; border: none; cursor: pointer; border-radius: 4px; font-weight: bold; margin-top: 10px; }
        button:hover { background: #d4af37; color: #003366; }
        .toggle { margin-top: 15px; font-size: 0.9rem; color: #003366; cursor: pointer; text-decoration: underline; }
        .section-label { text-align: left; font-size: 0.85rem; color: #666; font-weight: bold; margin-top: 10px; margin-bottom: -5px; }
    </style>
</head>
<body>
    <div class="container" id="login-box">
        <h2>Login</h2>
        <form method="POST">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" name="login">Login</button>
        </form>
        <p class="toggle" onclick="toggleForm()">No account? Register here.</p>
        <p class="toggle" onclick="showForgot()">Forgot Password?</p>
    </div>

    <div class="container" id="forgot-box" style="display:none;">
        <h2 style="margin-bottom: 5px;">Forgot Password</h2>
        <p style="font-size: 0.85rem; color: #666; margin-bottom: 20px;">Enter your email and a new password.</p>
        <form method="POST">
            <input type="email" name="reset_email" placeholder="Email Address" required>
            <input type="password" name="new_password" placeholder="New Password" required>
            <button type="submit" name="reset_password">Reset Password</button>
        </form>
        <p class="toggle" onclick="showLogin()">Back to Login</p>
    </div>

    <div class="container" id="register-box" style="display:none;">
        <h2 style="margin-bottom: 5px;">Register</h2>
        <p style="font-size: 0.85rem; color: #666; margin-bottom: 20px;">Help us get to know you better!</p>
        
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