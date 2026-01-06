<?php
/**
 * Simple Test Script - Check if login works
 * Access: http://localhost/biinventory/test_login.php
 */

require_once 'config/database.php';

$conn = getDBConnection();

if (!$conn) {
    die("❌ Database connection failed! Check config/database.php");
}

echo "<h2>BI Inventory - Login Test</h2>";

// Check if database exists
$dbCheck = $conn->query("SELECT DATABASE()");
$dbName = $dbCheck->fetch_array()[0];
echo "<p><strong>Database:</strong> " . ($dbName ?: "Not connected") . "</p>";

// Check if users table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'users'");
if ($tableCheck->num_rows === 0) {
    die("❌ Users table not found! Please import database/schema.sql");
}
echo "<p>✅ Users table exists</p>";

// Check admin user
$result = $conn->query("SELECT id, username, email, password, role, status FROM users WHERE username = 'admin'");

if ($result->num_rows === 0) {
    echo "<p>❌ Admin user not found!</p>";
    echo "<p>Creating admin user...</p>";
    
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role, status) VALUES (?, ?, ?, ?, ?, ?)");
    $username = 'admin';
    $email = 'admin@bicommunications.com';
    $password = 'admin123';
    $fullName = 'Super Administrator';
    $role = 'super_admin';
    $status = 'active';
    
    $stmt->bind_param("ssssss", $username, $email, $password, $fullName, $role, $status);
    
    if ($stmt->execute()) {
        echo "<p>✅ Admin user created!</p>";
    } else {
        echo "<p>❌ Error: " . $conn->error . "</p>";
    }
    $stmt->close();
} else {
    $user = $result->fetch_assoc();
    echo "<p>✅ Admin user found</p>";
    echo "<p><strong>Username:</strong> " . htmlspecialchars($user['username']) . "</p>";
    echo "<p><strong>Email:</strong> " . htmlspecialchars($user['email']) . "</p>";
    echo "<p><strong>Role:</strong> " . htmlspecialchars($user['role']) . "</p>";
    echo "<p><strong>Status:</strong> " . htmlspecialchars($user['status']) . "</p>";
    echo "<p><strong>Password:</strong> " . htmlspecialchars($user['password']) . " (plain text)</p>";
    
    // Test password
    if ($user['password'] === 'admin123') {
        echo "<p>✅ Password is correct: 'admin123'</p>";
    } else {
        echo "<p>⚠️ Password is: '" . htmlspecialchars($user['password']) . "' (not 'admin123')</p>";
        echo "<p>Updating password to 'admin123'...</p>";
        
        $stmt = $conn->prepare("UPDATE users SET password = 'admin123' WHERE username = 'admin'");
        if ($stmt->execute()) {
            echo "<p>✅ Password updated to 'admin123'</p>";
        } else {
            echo "<p>❌ Error: " . $conn->error . "</p>";
        }
        $stmt->close();
    }
}

echo "<hr>";
echo "<h3>Login Credentials:</h3>";
echo "<p><strong>Username:</strong> admin</p>";
echo "<p><strong>Password:</strong> admin123</p>";
echo "<p><a href='login.php' class='btn btn-primary'>Go to Login Page</a></p>";

$conn->close();
?>

<style>
body {
    font-family: Arial, sans-serif;
    max-width: 800px;
    margin: 50px auto;
    padding: 20px;
}
h2 {
    color: #333;
}
p {
    margin: 10px 0;
}
.btn {
    display: inline-block;
    padding: 10px 20px;
    background: #007bff;
    color: white;
    text-decoration: none;
    border-radius: 5px;
}
</style>

