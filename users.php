<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireRole(ROLE_SUPER_ADMIN);

$pageTitle = 'User Management';
require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $username = sanitizeInput($_POST['username'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $fullName = sanitizeInput($_POST['full_name'] ?? '');
        $role = sanitizeInput($_POST['role'] ?? 'hr_manager');
        $status = sanitizeInput($_POST['status'] ?? 'active');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($email) || empty($fullName)) {
            $message = 'Please fill in all required fields.';
            $messageType = 'danger';
        } else {
            if ($action === 'add') {
                if (empty($password)) {
                    $message = 'Password is required for new users.';
                    $messageType = 'danger';
                } else {
                    // Check if username or email already exists
                    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                    $stmt->bind_param("ss", $username, $email);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        $message = 'Username or email already exists.';
                        $messageType = 'danger';
                    } else {
                        $stmt->close();
                        // Store password as plain text
                        $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssss", $username, $email, $password, $fullName, $role, $status);
                        
                        if ($stmt->execute()) {
                            $userId = $conn->insert_id;
                            logAudit('create', 'users', $userId, null, ['username' => $username, 'role' => $role]);
                            $message = 'User added successfully.';
                            $messageType = 'success';
                        } else {
                            $message = 'Error adding user: ' . $conn->error;
                            $messageType = 'danger';
                        }
                    }
                    $stmt->close();
                }
            } else {
                $id = intval($_POST['id'] ?? 0);
                $oldValues = [];
                $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $oldValues = $result->fetch_assoc();
                }
                $stmt->close();
                
                // Check if username or email already exists for another user
                $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                $stmt->bind_param("ssi", $username, $email, $id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $message = 'Username or email already exists.';
                    $messageType = 'danger';
                } else {
                    $stmt->close();
                    
                    if (!empty($password)) {
                        // Store password as plain text
                        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ?, full_name = ?, role = ?, status = ? WHERE id = ?");
                        $stmt->bind_param("ssssssi", $username, $email, $password, $fullName, $role, $status, $id);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, status = ? WHERE id = ?");
                        $stmt->bind_param("sssssi", $username, $email, $fullName, $role, $status, $id);
                    }
                    
                    if ($stmt->execute()) {
                        $newValues = ['username' => $username, 'role' => $role, 'status' => $status];
                        logAudit('update', 'users', $id, $oldValues, $newValues);
                        $message = 'User updated successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Error updating user: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        // Prevent deleting own account
        if ($id == $_SESSION['user_id']) {
            $message = 'You cannot delete your own account.';
            $messageType = 'danger';
        } else {
            $oldValues = [];
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $oldValues = $result->fetch_assoc();
            }
            $stmt->close();
            
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                logAudit('delete', 'users', $id, $oldValues, null);
                $message = 'User deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Error deleting user: ' . $conn->error;
                $messageType = 'danger';
            }
            $stmt->close();
        }
    }
}

// Get users
$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-person-gear"></i> User Management</h2>
    </div>
</div>

<?php 
if ($message) {
    showSweetAlert($messageType, $message);
}
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">System Users</h5>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-plus-circle"></i> Add User
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($users) > 0): ?>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><span class="badge bg-<?php echo $user['role'] === 'super_admin' ? 'danger' : ($user['role'] === 'it_admin' ? 'primary' : 'info'); ?>"><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></span></td>
                            <td><span class="badge bg-<?php echo getStatusBadge($user['status']); ?>"><?php echo ucfirst($user['status']); ?></span></td>
                            <td><?php echo formatDate($user['created_at']); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" style="display:inline;" data-confirm-delete data-message="Are you sure you want to delete this user?">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">No users found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="userForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="userId">
                    
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="username" id="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" id="fullName" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" id="email" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" id="role" required>
                                <option value="hr_manager">HR Manager</option>
                                <option value="it_admin">IT Admin</option>
                                <option value="super_admin">Super Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" id="passwordLabel">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password" id="password">
                        <small class="text-muted" id="passwordHelp">Leave blank to keep current password when editing.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editUser(user) {
    document.getElementById('modalTitle').textContent = 'Edit User';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('userId').value = user.id;
    document.getElementById('username').value = user.username;
    document.getElementById('fullName').value = user.full_name;
    document.getElementById('email').value = user.email;
    document.getElementById('role').value = user.role;
    document.getElementById('status').value = user.status;
    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    document.getElementById('passwordLabel').innerHTML = 'Password <small class="text-muted">(leave blank to keep current)</small>';
    document.getElementById('passwordHelp').textContent = 'Leave blank to keep current password.';
    
    const modal = new bootstrap.Modal(document.getElementById('addUserModal'));
    modal.show();
}

// Reset form when modal is closed
document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('userForm').reset();
    document.getElementById('modalTitle').textContent = 'Add User';
    document.getElementById('formAction').value = 'add';
    document.getElementById('userId').value = '';
    document.getElementById('password').required = true;
    document.getElementById('passwordLabel').innerHTML = 'Password <span class="text-danger">*</span>';
    document.getElementById('passwordHelp').textContent = '';
});
</script>

<?php require_once 'includes/footer.php'; ?>

