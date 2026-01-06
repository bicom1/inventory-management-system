<?php
/**
 * Password Hash Generator
 * Use this script to generate password hashes for the system
 * 
 * Usage: php setup_password.php
 * Or access via browser: http://localhost/biinventory/setup_password.php
 */

// If accessed via browser, show form
if (php_sapi_name() !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Password Hash Generator</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Password Hash Generator</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                            $password = $_POST['password'] ?? '';
                            if (!empty($password)) {
                                $hash = password_hash($password, PASSWORD_BCRYPT);
                                echo '<div class="alert alert-success">';
                                echo '<strong>Password Hash:</strong><br>';
                                echo '<code>' . htmlspecialchars($hash) . '</code>';
                                echo '</div>';
                                echo '<p>Use this hash in your database INSERT or UPDATE statement.</p>';
                            }
                        }
                        ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Enter Password</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Generate Hash</button>
                        </form>
                        <hr>
                        <p class="text-muted"><small>Default admin password: admin123</small></p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// CLI usage
if ($argc < 2) {
    echo "Usage: php setup_password.php <password>\n";
    echo "Example: php setup_password.php admin123\n";
    exit(1);
}

$password = $argv[1];
$hash = password_hash($password, PASSWORD_BCRYPT);

echo "Password: $password\n";
echo "Hash: $hash\n";
echo "\nSQL Update Statement:\n";
echo "UPDATE users SET password = '$hash' WHERE username = 'admin';\n";

