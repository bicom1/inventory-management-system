<?php
/**
 * Admin Password Reset Script
 * Use this to reset the admin password if you can't login
 * 
 * Access via browser: http://localhost/biinventory/reset_admin_password.php
 * 
 * WARNING: Delete this file after use for security!
 */

require_once 'config/database.php';

$message = '';
$messageType = '';

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword)) {
        $message = 'Password cannot be empty.';
        $messageType = 'danger';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'danger';
    } elseif (strlen($newPassword) < 6) {
        $message = 'Password must be at least 6 characters long.';
        $messageType = 'danger';
    } else {
        $conn = getDBConnection();
        
        if (!$conn) {
            $message = 'Database connection failed. Please check your database configuration.';
            $messageType = 'danger';
        } else {
            // Check if admin user exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = 'admin'");
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                // Create admin user if it doesn't exist
                $email = 'admin@bicommunications.com';
                $fullName = 'Super Administrator';
                $role = 'super_admin';
                $status = 'active';
                $username = 'admin';
                
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $username, $email, $newPassword, $fullName, $role, $status);
                
                if ($stmt->execute()) {
                    $message = 'Admin user created successfully with your new password!';
                    $messageType = 'success';
                } else {
                    $message = 'Error creating admin user: ' . $conn->error;
                    $messageType = 'danger';
                }
            } else {
                // Update existing admin user - store as plain text
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
                $stmt->bind_param("s", $newPassword);
                
                if ($stmt->execute()) {
                    $message = 'Admin password reset successfully! You can now login with your new password.';
                    $messageType = 'success';
                } else {
                    $message = 'Error resetting password: ' . $conn->error;
                    $messageType = 'danger';
                }
            }
            
            $stmt->close();
        }
    }
}

// Check database connection
$conn = getDBConnection();
$dbStatus = $conn ? 'Connected' : 'Not Connected';
$userExists = false;

if ($conn) {
    $stmt = $conn->prepare("SELECT id, username, email, role, status FROM users WHERE username = 'admin'");
    $stmt->execute();
    $result = $stmt->get_result();
    $userExists = $result->num_rows > 0;
    $adminUser = $userExists ? $result->fetch_assoc() : null;
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Admin Password - BI Inventory System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .reset-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
        }
        .reset-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .reset-body {
            padding: 2rem;
        }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="reset-header">
            <h3><i class="bi bi-key"></i> Reset Admin Password</h3>
            <p class="mb-0">BI Inventory System</p>
        </div>
        <div class="reset-body">
            <!-- Database Status -->
            <div class="alert alert-<?php echo $conn ? 'success' : 'danger'; ?> mb-3">
                <strong>Database Status:</strong> <?php echo $dbStatus; ?>
                <?php if ($conn && $userExists): ?>
                    <br><small>Admin user found in database.</small>
                <?php elseif ($conn && !$userExists): ?>
                    <br><small>Admin user not found. Will be created.</small>
                <?php endif; ?>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i> <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($messageType === 'success'): ?>
                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-primary btn-lg">
                        <i class="bi bi-box-arrow-in-right"></i> Go to Login
                    </a>
                </div>
            <?php else: ?>
                <form method="POST" id="resetForm">
                    <div class="mb-3">
                        <label for="password" class="form-label">New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="6">
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-key-fill"></i> Reset Password
                    </button>
                </form>
            <?php endif; ?>
            
            <hr class="my-4">
            
            <div class="text-center">
                <a href="login.php" class="btn btn-link">Back to Login</a>
            </div>
            
            <div class="alert alert-warning mt-3">
                <small><i class="bi bi-exclamation-triangle"></i> <strong>Security Notice:</strong> Delete this file (reset_admin_password.php) after resetting your password!</small>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validate password match
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
        });
    </script>
</body>
</html>

