<?php
/**
 * Simple Password Fix - Updates admin password to plain text 'admin123'
 * Access: http://localhost/biinventory/fix_password_simple.php
 */

require_once 'config/database.php';

$conn = getDBConnection();

if (!$conn) {
    die("❌ Database connection failed!");
}

echo "<h2>Fixing Admin Password...</h2>";

// Update admin password to plain text 'admin123'
$stmt = $conn->prepare("UPDATE users SET password = 'admin123' WHERE username = 'admin'");

if ($stmt->execute()) {
    echo "<p>✅ Password updated successfully!</p>";
} else {
    echo "<p>❌ Error: " . $conn->error . "</p>";
}

// If admin doesn't exist, create it
$check = $conn->query("SELECT id FROM users WHERE username = 'admin'");
if ($check->num_rows === 0) {
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
    }
}

// Verify
$result = $conn->query("SELECT username, password FROM users WHERE username = 'admin'");
if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "<p><strong>Username:</strong> " . htmlspecialchars($user['username']) . "</p>";
    echo "<p><strong>Password:</strong> " . htmlspecialchars($user['password']) . "</p>";
    echo "<p>✅ <strong>Login Credentials:</strong></p>";
    echo "<p>Username: <strong>admin</strong></p>";
    echo "<p>Password: <strong>admin123</strong></p>";
}

$stmt->close();
$conn->close();

echo "<hr>";
echo "<p><a href='login.php' style='display:inline-block;padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:5px;'>Go to Login</a></p>";
?>

<style>
body {
    font-family: Arial, sans-serif;
    max-width: 600px;
    margin: 50px auto;
    padding: 20px;
}
h2 {
    color: #333;
}
p {
    margin: 10px 0;
    font-size: 16px;
}
</style>

