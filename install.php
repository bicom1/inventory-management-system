<?php
/**
 * Installation Script
 * Run this once to set up the database and create admin user
 * 
 * Access via browser: http://localhost/biinventory/install.php
 * 
 * WARNING: Delete this file after installation for security!
 */

// Prevent running if already installed
if (file_exists('config/installed.flag')) {
    die('System is already installed. Delete install.php for security.');
}

$step = $_GET['step'] ?? '1';
$error = '';
$success = '';

// Step 1: Database Connection Test
if ($step == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['db_host'] ?? 'localhost';
    $user = $_POST['db_user'] ?? 'root';
    $pass = $_POST['db_pass'] ?? '';
    $name = $_POST['db_name'] ?? 'biinventory';
    
    $conn = @new mysqli($host, $user, $pass);
    
    if ($conn->connect_error) {
        $error = 'Database connection failed: ' . $conn->connect_error;
    } else {
        // Create database if not exists
        $conn->query("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->select_db($name);
        
        // Read and execute schema
        $schema = file_get_contents('database/schema.sql');
        $schema = str_replace('USE biinventory;', "USE `$name`;", $schema);
        
        // Execute schema (split by semicolon, but handle multi-line statements)
        $statements = array_filter(array_map('trim', explode(';', $schema)));
        
        foreach ($statements as $statement) {
            if (!empty($statement) && !preg_match('/^--/', $statement)) {
                if (!$conn->query($statement)) {
                    $error = 'Error executing SQL: ' . $conn->error . '<br>Statement: ' . substr($statement, 0, 100);
                    break;
                }
            }
        }
        
        if (empty($error)) {
            // Save database config
            $config = "<?php\n";
            $config .= "define('DB_HOST', '$host');\n";
            $config .= "define('DB_USER', '$user');\n";
            $config .= "define('DB_PASS', '$pass');\n";
            $config .= "define('DB_NAME', '$name');\n";
            $config .= "define('DB_CHARSET', 'utf8mb4');\n";
            
            file_put_contents('config/database.php', $config);
            $step = '2';
            $success = 'Database setup completed successfully!';
        }
        
        $conn->close();
    }
}

// Step 2: Create Admin User
if ($step == '2' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';
    
    $username = $_POST['username'] ?? 'admin';
    $email = $_POST['email'] ?? 'admin@bicommunications.com';
    $password = $_POST['password'] ?? '';
    $fullName = $_POST['full_name'] ?? 'Super Administrator';
    
    if (empty($password)) {
        $error = 'Password is required.';
    } else {
        $conn = getDBConnection();
        if ($conn) {
            // Store password as plain text
            $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ?, full_name = ? WHERE username = 'admin'");
            $stmt->bind_param("ssss", $username, $email, $password, $fullName);
            
            if ($stmt->execute()) {
                // Create installed flag
                file_put_contents('config/installed.flag', date('Y-m-d H:i:s'));
                $success = 'Installation completed successfully! You can now login.';
                $step = '3';
            } else {
                $error = 'Error creating admin user: ' . $conn->error;
            }
            $stmt->close();
        } else {
            $error = 'Database connection failed.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BI Inventory System - Installation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="bi bi-gear"></i> BI Inventory System Installation</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($step == '1'): ?>
                            <h5>Step 1: Database Configuration</h5>
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Database Host</label>
                                    <input type="text" class="form-control" name="db_host" value="localhost" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Database User</label>
                                    <input type="text" class="form-control" name="db_user" value="root" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Database Password</label>
                                    <input type="password" class="form-control" name="db_pass">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Database Name</label>
                                    <input type="text" class="form-control" name="db_name" value="biinventory" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Setup Database</button>
                            </form>
                            
                        <?php elseif ($step == '2'): ?>
                            <h5>Step 2: Create Admin User</h5>
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" name="username" value="admin" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="email" value="admin@bicommunications.com" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control" name="full_name" value="Super Administrator" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" name="password" required>
                                    <small class="text-muted">Choose a strong password for the admin account.</small>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Create Admin User</button>
                            </form>
                            
                        <?php elseif ($step == '3'): ?>
                            <div class="text-center">
                                <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                                <h4 class="mt-3">Installation Complete!</h4>
                                <p class="text-muted">Your BI Inventory System is ready to use.</p>
                                <a href="login.php" class="btn btn-primary btn-lg">Go to Login</a>
                                <hr>
                                <p class="text-danger"><small><strong>IMPORTANT:</strong> Delete install.php file for security!</small></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

