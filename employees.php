<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireAnyRole([ROLE_SUPER_ADMIN, ROLE_IT_ADMIN]);

// Handle CSV export - must be before header output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $conn = getDBConnection();
    $search = sanitizeInput($_GET['search'] ?? '');
    $departmentFilter = sanitizeInput($_GET['department'] ?? '');
    $statusFilter = sanitizeInput($_GET['status'] ?? '');
    
    // Build query for export
    $where = ["1=1"];
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $where[] = "(employee_id LIKE ? OR full_name LIKE ? OR email LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "sss";
    }
    
    if (!empty($departmentFilter)) {
        $where[] = "department = ?";
        $params[] = $departmentFilter;
        $types .= "s";
    }
    
    if (!empty($statusFilter)) {
        $where[] = "status = ?";
        $params[] = $statusFilter;
        $types .= "s";
    }
    
    $whereClause = implode(" AND ", $where);
    
    $query = "SELECT * FROM employees WHERE $whereClause ORDER BY created_at DESC";
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $employees = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=employees_' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV headers
    fputcsv($output, [
        'ID', 'Employee ID', 'Full Name', 'Email', 'Department', 'Position', 'Phone', 'Status', 'Created At'
    ]);
    
    // CSV data
    foreach ($employees as $row) {
        fputcsv($output, [
            $row['id'],
            $row['employee_id'],
            $row['full_name'],
            $row['email'] ?? '',
            $row['department'] ?? '',
            $row['position'] ?? '',
            $row['phone'] ?? '',
            $row['status'],
            $row['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

$pageTitle = 'Employee Management';
require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $employeeId = sanitizeInput($_POST['employee_id'] ?? '');
        $fullName = sanitizeInput($_POST['full_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $department = sanitizeInput($_POST['department'] ?? '');
        $position = sanitizeInput($_POST['position'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $status = sanitizeInput($_POST['status'] ?? 'active');
        
        if (empty($employeeId) || empty($fullName)) {
            $message = 'Please fill in all required fields.';
            $messageType = 'danger';
        } else {
            if ($action === 'add') {
                // Check if employee ID already exists
                $stmt = $conn->prepare("SELECT id FROM employees WHERE employee_id = ?");
                $stmt->bind_param("s", $employeeId);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $message = 'Employee ID already exists.';
                    $messageType = 'danger';
                } else {
                    $stmt->close();
                    $stmt = $conn->prepare("INSERT INTO employees (employee_id, full_name, email, department, position, phone, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssssss", $employeeId, $fullName, $email, $department, $position, $phone, $status);
                    
                    if ($stmt->execute()) {
                        $empId = $conn->insert_id;
                        logAudit('create', 'employees', $empId, null, ['employee_id' => $employeeId, 'full_name' => $fullName]);
                        $message = 'Employee added successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Error adding employee: ' . $conn->error;
                        $messageType = 'danger';
                    }
                }
                $stmt->close();
            } else {
                $id = intval($_POST['id'] ?? 0);
                $oldValues = [];
                $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $oldValues = $result->fetch_assoc();
                }
                $stmt->close();
                
                // Check if employee ID already exists for another employee
                $stmt = $conn->prepare("SELECT id FROM employees WHERE employee_id = ? AND id != ?");
                $stmt->bind_param("si", $employeeId, $id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $message = 'Employee ID already exists.';
                    $messageType = 'danger';
                } else {
                    $stmt->close();
                    $stmt = $conn->prepare("UPDATE employees SET employee_id = ?, full_name = ?, email = ?, department = ?, position = ?, phone = ?, status = ? WHERE id = ?");
                    $stmt->bind_param("sssssssi", $employeeId, $fullName, $email, $department, $position, $phone, $status, $id);
                    
                    if ($stmt->execute()) {
                        $newValues = ['employee_id' => $employeeId, 'full_name' => $fullName, 'status' => $status];
                        logAudit('update', 'employees', $id, $oldValues, $newValues);
                        $message = 'Employee updated successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Error updating employee: ' . $conn->error;
                        $messageType = 'danger';
                    }
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        // Check if employee has active assignments
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignments WHERE employee_id = ? AND status = 'active'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['count'] > 0) {
            $message = 'Cannot delete employee with active assignments. Please return all items first.';
            $messageType = 'danger';
        } else {
            $oldValues = [];
            $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $oldValues = $result->fetch_assoc();
            }
            $stmt->close();
            
            $stmt = $conn->prepare("DELETE FROM employees WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                logAudit('delete', 'employees', $id, $oldValues, null);
                $message = 'Employee deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Error deleting employee: ' . $conn->error;
                $messageType = 'danger';
            }
            $stmt->close();
        }
    }
}

// Get filter parameters
$search = sanitizeInput($_GET['search'] ?? '');
$departmentFilter = sanitizeInput($_GET['department'] ?? '');
$statusFilter = sanitizeInput($_GET['status'] ?? '');

// Build query
$where = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(employee_id LIKE ? OR full_name LIKE ? OR email LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if (!empty($departmentFilter)) {
    $where[] = "department = ?";
    $params[] = $departmentFilter;
    $types .= "s";
}

if (!empty($statusFilter)) {
    $where[] = "status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$whereClause = implode(" AND ", $where);

// Get pagination parameters
$pagination = getPaginationParams(10);
$page = $pagination['page'];
$offset = $pagination['offset'];
$limit = $pagination['limit'];

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM employees WHERE $whereClause";
$countStmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalEmployees = $countResult->fetch_assoc()['total'];
$countStmt->close();
$totalPages = max(1, ceil($totalEmployees / $limit));

// Get unique departments
$departments = $conn->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetch_all(MYSQLI_ASSOC);

// Get employees with pagination
$query = "SELECT * FROM employees WHERE $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);

$limitParam = $limit;
$offsetParam = $offset;
if (!empty($params)) {
    $types .= "ii";
    $params[] = $limitParam;
    $params[] = $offsetParam;
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param("ii", $limitParam, $offsetParam);
}

$stmt->execute();
$result = $stmt->get_result();
$employees = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-people"></i> Employee Management</h2>
    </div>
</div>

<?php 
if ($message) {
    showSweetAlert($messageType, $message);
}
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Employees</h5>
        <div class="d-flex gap-2">
            <a href="?export=csv<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($departmentFilter) ? '&department=' . urlencode($departmentFilter) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?>" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
            </a>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                <i class="bi bi-plus-circle"></i> Add Employee
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-3 mb-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="Search employees..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php echo $departmentFilter === $dept['department'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="terminated" <?php echo $statusFilter === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="employees.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>
            </div>
        </form>
        
        <!-- Employees Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($employees) > 0): ?>
                        <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                            <td><?php echo htmlspecialchars($emp['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($emp['email'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($emp['department'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($emp['position'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($emp['phone'] ?: '-'); ?></td>
                            <td><span class="badge bg-<?php echo getStatusBadge($emp['status']); ?>"><?php echo ucfirst($emp['status']); ?></span></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="editEmployee(<?php echo htmlspecialchars(json_encode($emp)); ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" style="display:inline;" data-confirm-delete data-message="Are you sure you want to delete this employee?">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center">No employees found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted">
                            Showing <?php echo count($employees); ?> of <?php echo $totalEmployees; ?> employees (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)
                        </small>
                    </div>
                    <?php 
                    echo renderPagination($page, $totalPages, 'employees.php', [
                        'search' => $search,
                        'department' => $departmentFilter,
                        'status' => $statusFilter
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Employee Modal -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Employee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="employeeForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="employeeId">
                    
                    <div class="mb-3">
                        <label class="form-label">Employee ID <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="employee_id" id="employeeIdField" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" id="fullName" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="email">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" id="department">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Position</label>
                            <input type="text" class="form-control" name="position" id="position">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone" id="phone">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="status">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="terminated">Terminated</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editEmployee(emp) {
    document.getElementById('modalTitle').textContent = 'Edit Employee';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('employeeId').value = emp.id;
    document.getElementById('employeeIdField').value = emp.employee_id;
    document.getElementById('fullName').value = emp.full_name;
    document.getElementById('email').value = emp.email || '';
    document.getElementById('department').value = emp.department || '';
    document.getElementById('position').value = emp.position || '';
    document.getElementById('phone').value = emp.phone || '';
    document.getElementById('status').value = emp.status;
    
    const modal = new bootstrap.Modal(document.getElementById('addEmployeeModal'));
    modal.show();
}

// Reset form when modal is closed
document.getElementById('addEmployeeModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('employeeForm').reset();
    document.getElementById('modalTitle').textContent = 'Add Employee';
    document.getElementById('formAction').value = 'add';
    document.getElementById('employeeId').value = '';
});
</script>

<?php require_once 'includes/footer.php'; ?>

