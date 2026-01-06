<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireRole(ROLE_SUPER_ADMIN);

$pageTitle = 'Audit Logs';
require_once 'includes/header.php';

$conn = getDBConnection();

// Get filter parameters
$search = sanitizeInput($_GET['search'] ?? '');
$actionFilter = sanitizeInput($_GET['action'] ?? '');
$tableFilter = sanitizeInput($_GET['table'] ?? '');
$dateFrom = sanitizeInput($_GET['date_from'] ?? '');
$dateTo = sanitizeInput($_GET['date_to'] ?? '');

// Build query
$where = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(u.username LIKE ? OR u.full_name LIKE ? OR al.action LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if (!empty($actionFilter)) {
    $where[] = "al.action = ?";
    $params[] = $actionFilter;
    $types .= "s";
}

if (!empty($tableFilter)) {
    $where[] = "al.table_name = ?";
    $params[] = $tableFilter;
    $types .= "s";
}

if (!empty($dateFrom)) {
    $where[] = "DATE(al.created_at) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if (!empty($dateTo)) {
    $where[] = "DATE(al.created_at) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$whereClause = implode(" AND ", $where);

// Get unique actions and tables for filters
$actions = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetch_all(MYSQLI_ASSOC);
$tables = $conn->query("SELECT DISTINCT table_name FROM audit_logs WHERE table_name IS NOT NULL ORDER BY table_name")->fetch_all(MYSQLI_ASSOC);

// Get audit logs
$query = "SELECT al.*, u.username, u.full_name 
          FROM audit_logs al
          LEFT JOIN users u ON al.user_id = u.id
          WHERE $whereClause
          ORDER BY al.created_at DESC
          LIMIT 500";
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$logs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-journal-text"></i> Audit Logs</h2>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">System Activity Log</h5>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-3 mb-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="action">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $act): ?>
                        <option value="<?php echo htmlspecialchars($act['action']); ?>" <?php echo $actionFilter === $act['action'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($act['action'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="table">
                    <option value="">All Tables</option>
                    <?php foreach ($tables as $tbl): ?>
                        <option value="<?php echo htmlspecialchars($tbl['table_name']); ?>" <?php echo $tableFilter === $tbl['table_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($tbl['table_name'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" placeholder="From Date" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" placeholder="To Date" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                <a href="audit_logs.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
        
        <!-- Audit Logs Table -->
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Record ID</th>
                        <th>IP Address</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo formatDateTime($log['created_at'], 'Y-m-d H:i:s'); ?></td>
                            <td><?php echo htmlspecialchars($log['full_name'] ?: $log['username'] ?: 'Unknown'); ?></td>
                            <td><span class="badge bg-<?php echo in_array($log['action'], ['create', 'assign', 'login']) ? 'success' : (in_array($log['action'], ['delete', 'logout']) ? 'danger' : 'primary'); ?>"><?php echo htmlspecialchars(ucfirst($log['action'])); ?></span></td>
                            <td><?php echo htmlspecialchars($log['table_name'] ?: '-'); ?></td>
                            <td><?php echo $log['record_id'] ?: '-'; ?></td>
                            <td><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
                            <td>
                                <?php if ($log['old_values'] || $log['new_values']): ?>
                                    <button type="button" class="btn btn-sm btn-info" onclick="viewLogDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">No audit logs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Log Details Modal -->
<div class="modal fade" id="logDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Audit Log Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="logDetailsContent">
            </div>
        </div>
    </div>
</div>

<script>
function viewLogDetails(log) {
    let content = '<div class="row mb-3"><div class="col-md-6"><strong>Date & Time:</strong><br>' + log.created_at + '</div>';
    content += '<div class="col-md-6"><strong>User:</strong><br>' + (log.full_name || log.username || 'Unknown') + '</div></div>';
    content += '<div class="row mb-3"><div class="col-md-6"><strong>Action:</strong><br>' + log.action + '</div>';
    content += '<div class="col-md-6"><strong>Table:</strong><br>' + (log.table_name || '-') + '</div></div>';
    content += '<div class="row mb-3"><div class="col-md-6"><strong>Record ID:</strong><br>' + (log.record_id || '-') + '</div>';
    content += '<div class="col-md-6"><strong>IP Address:</strong><br>' + (log.ip_address || '-') + '</div></div>';
    
    if (log.old_values) {
        try {
            const oldValues = JSON.parse(log.old_values);
            content += '<div class="mb-3"><strong>Old Values:</strong><pre class="bg-light p-3">' + JSON.stringify(oldValues, null, 2) + '</pre></div>';
        } catch(e) {
            content += '<div class="mb-3"><strong>Old Values:</strong><pre class="bg-light p-3">' + log.old_values + '</pre></div>';
        }
    }
    
    if (log.new_values) {
        try {
            const newValues = JSON.parse(log.new_values);
            content += '<div class="mb-3"><strong>New Values:</strong><pre class="bg-light p-3">' + JSON.stringify(newValues, null, 2) + '</pre></div>';
        } catch(e) {
            content += '<div class="mb-3"><strong>New Values:</strong><pre class="bg-light p-3">' + log.new_values + '</pre></div>';
        }
    }
    
    document.getElementById('logDetailsContent').innerHTML = content;
    const modal = new bootstrap.Modal(document.getElementById('logDetailsModal'));
    modal.show();
}
</script>

<?php require_once 'includes/footer.php'; ?>

