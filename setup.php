<?php
// setup.php - Re-run this to ensure all tables and columns are created.
$servername = "localhost";
$username = "root";
$password = "";

// Connect
$conn = new mysqli($servername, $username, $password);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Create DB
$conn->query("CREATE DATABASE IF NOT EXISTS furfinder_db");
$conn->select_db("furfinder_db");

// Function to safely add columns (if the table already exists)
function alterTableAddColumn($conn, $tableName, $columnName, $definition) {
    // Check if the column exists using DESCRIBE
    $check_column = $conn->query("DESCRIBE $tableName $columnName");
    if (!$check_column || $check_column->num_rows == 0) {
        // Column does not exist, add it
        $conn->query("ALTER TABLE $tableName ADD COLUMN $columnName $definition");
        echo "Added column $columnName to $tableName.<br>";
    }
}

// Create or check core tables
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), email VARCHAR(100) UNIQUE, password VARCHAR(255), role ENUM('user', 'admin') DEFAULT 'user'
)");

$conn->query("CREATE TABLE IF NOT EXISTS pets (
    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), breed VARCHAR(50), age VARCHAR(20), image_url TEXT, type ENUM('dog', 'cat'), status ENUM('available', 'adopted') DEFAULT 'available'
)");

$conn->query("CREATE TABLE IF NOT EXISTS lost_pets (
    id INT AUTO_INCREMENT PRIMARY KEY, pet_name VARCHAR(100), location VARCHAR(150), last_seen DATETIME, contact_number VARCHAR(50), description TEXT, photo_path VARCHAR(255), status VARCHAR(20) DEFAULT 'Missing'
)");

// UPDATED APPLICATIONS TABLE (More fields, user_id nullable)
$conn->query("CREATE TABLE IF NOT EXISTS applications (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    user_id INT NULL, 
    pet_name VARCHAR(50), 
    fullname VARCHAR(100), 
    address TEXT, 
    contact VARCHAR(20), 
    status VARCHAR(20) DEFAULT 'Pending'
)");

// Add new Questionnaire Columns
alterTableAddColumn($conn, 'applications', 'housing_type', 'VARCHAR(50)'); // Rented/Owned
alterTableAddColumn($conn, 'applications', 'has_fence', 'VARCHAR(10)');    // Yes/No
alterTableAddColumn($conn, 'applications', 'household_members', 'TEXT');    // List people
alterTableAddColumn($conn, 'applications', 'other_pets', 'TEXT');           // List current pets
alterTableAddColumn($conn, 'applications', 'income_source', 'VARCHAR(100)');// Job/Source
alterTableAddColumn($conn, 'applications', 'hours_alone', 'INT');           // Hours pet is alone

$conn->query("CREATE TABLE IF NOT EXISTS donations (
    id INT AUTO_INCREMENT PRIMARY KEY, donor_name VARCHAR(100), amount DECIMAL(10,2), message TEXT, date_created DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS shelters (
    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), status VARCHAR(50) DEFAULT 'Open'
)");
alterTableAddColumn($conn, 'shelters', 'email', 'VARCHAR(100)');
alterTableAddColumn($conn, 'shelters', 'schedule', 'VARCHAR(100)');

// Insert Admin
$pass = password_hash("furfinder2025", PASSWORD_DEFAULT);
$conn->query("INSERT IGNORE INTO users (name, email, password, role) VALUES ('Admin', 'admin@furfinder.com', '$pass', 'admin')");

// Insert/Update Shelter Data
$conn->query("INSERT INTO shelters (id, name, status, email, schedule) VALUES (1, 'Furvent Animal Rescue', 'Open', 'furvent@example.com', 'Mon-Sat (9AM - 5PM)') ON DUPLICATE KEY UPDATE status=VALUES(status), email=VALUES(email), schedule=VALUES(schedule)");
$conn->query("INSERT INTO shelters (id, name, status, email, schedule) VALUES (2, 'Baguio City Vet', 'Open', 'cvao@baguio.gov.ph', 'Mon-Fri (8AM - 5PM)') ON DUPLICATE KEY UPDATE status=VALUES(status), email=VALUES(email), schedule=VALUES(schedule)");

echo "<h1>✅ Database Updated with New Questionnaire Fields!</h1><a href='index.php'>Go back to Home</a>";
?>