<?php
// db_connection.php
// session_start();

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root'); // Change as per your setup
define('DB_PASSWORD', ''); // Change as per your setup
define('DB_NAME', 'hr_management_portal');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// Function to generate random password
function generateRandomPassword($length = 12) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

// Function to check/create HR user
function checkAndCreateHR($conn) {
    // Check if HR user exists
    $sql = "SELECT * FROM users WHERE email = 'hr@hrportal.com'";
    $result = $conn->query($sql);
    
    if ($result->num_rows == 0) {
        // Create HR user
        $password = generateRandomPassword();
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Store plain password in text file
        $password_file = __DIR__ . '/hr_password.txt';
        file_put_contents($password_file, "HR_MANAGER Password: $password\nGenerated on: " . date('Y-m-d H:i:s'));
        
        // Insert into database
        $sql = "INSERT INTO users (email, password_hash, role, is_active) 
                VALUES ('hr@hrportal.com', ?, 'hr', 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $password_hash);
        
        if ($stmt->execute()) {
            return "HR account created successfully! Password saved in hr_password.txt";
        } else {
            return "Error creating HR account: " . $conn->error;
        }
    }
    return null;
}

// Check and create HR if needed
$hr_message = checkAndCreateHR($conn);
?>