prepare("INSERT INTO users (name, email, password, role, barangay, age_group, current_pets_status, preferred_breed) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$name, $email, $pass, $role, $barangay, $age_group, $current_pets, $pref_breed])) {
            echo "";
        }
    } catch (PDOException $e) {
        echo "";
    }
}

// HANDLE LOGIN
if (isset($_POST['login'])) {
    $email = strtolower(trim($_POST['email'])); // Force lowercase
    $password = $_POST['password'];

    try {
        // Proper PDO Prepared Statement
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) { // If a row was found (replaces rowCount > 0)
            if (password_verify($password, $row['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['name'] = $row['name'];
                $_SESSION['role'] = $row['role'];

                if ($row['role'] == 'admin') {
                    header("Location: admin.php");
                    exit(); // Always exit after a header redirect
                } else {
                    header("Location: index.php");
                    exit();
                }
            } else {
                echo "";
            }
        } else {
            echo "";
        }
    } catch (PDOException $e) {
        echo "";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Login | FurFinder</title>
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
        /* Increased width slightly to accommodate the longer form nicely */
        .container { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; box-sizing: border-box; }
        /* Added box-sizing so padding doesn't push inputs outside the container */
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
            if (login.style.display === 'none') {
                login.style.display = 'block';
                reg.style.display = 'none';
            } else {
                login.style.display = 'none';
                reg.style.display = 'block';
            }
        }
    </script>
</body>
</html>