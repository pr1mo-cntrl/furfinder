<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "furfinder_db";

// Database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Connection check
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

//Session Tracking
session_start();
?>