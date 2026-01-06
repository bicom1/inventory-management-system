<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireLogin();

$pageTitle = 'Reports';
require_once 'includes/header.php';

$conn = getDBConnection();
$reportType = sanitizeInput($_GET['type'] ?? 'summary');
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-file-earmark-text"></i> Reports</h2>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item">
                <a class="nav-link <?php echo $reportType === 'summary' ? 'active' : ''; ?>" href="?type=summary">Stock Summary</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $reportType === 'employee' ? 'active' : ''; ?>" href="?type=employee">Employee-wise</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $reportType === 'category' ? 'active' : ''; ?>" href="?type=category">Category-wise</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $reportType === 'assignments' ? 'active' : ''; ?>" href="?type=assignments">Assignment History</a>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <?php if ($reportType === 'summary'): ?>
            <h5 class="mb-3">Stock Summary Report</h5>
            <?php
            $summary = $conn->query("
                SELECT 
                    i.*,
                    c.name as category_name,
                    COUNT(DISTINCT a.id) as assignment_count
                FROM inventory i
                LEFT JOIN categories c ON i.category_id = c.id
                LEFT JOIN assignment_items ai ON i.id = ai.inventory_id
                LEFT JOIN assignments a ON ai.assignment_id = a.id AND a.status = 'active'
                GROUP BY i.id
                ORDER BY i.item_name
            ")->fetch_all(MYSQLI_ASSOC);
            ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Type</th>
                            <th>Total Stock</th>
                            <th>Assigned</th>
                            <th>Available</th>
                            <th>Status</th>
                            <th>Active Assignments</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                            <td><span class="badge bg-<?php echo $item['type'] === 'asset' ? 'primary' : 'info'; ?>"><?php echo ucfirst($item['type']); ?></span></td>
                            <td><?php echo $item['total_quantity']; ?></td>
                            <td><?php echo $item['assigned_quantity']; ?></td>
                            <td><?php echo $item['available_quantity']; ?></td>
                            <td><span class="badge bg-<?php echo getStatusBadge($item['status']); ?>"><?php echo ucfirst($item['status']); ?></span></td>
                            <td><?php echo $item['assignment_count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($reportType === 'employee'): ?>
            <h5 class="mb-3">Employee-wise Assignment Report</h5>
            <?php
            $employeeReport = $conn->query("
                SELECT 
                    e.id,
                    e.employee_id,
                    e.full_name,
                    e.department,
                    COUNT(DISTINCT a.id) as total_assignments,
                    SUM(a.quantity) as total_items
                FROM employees e
                LEFT JOIN assignments a ON e.id = a.employee_id AND a.status = 'active'
                WHERE e.status = 'active'
                GROUP BY e.id
                HAVING total_assignments > 0
                ORDER BY e.full_name
            ")->fetch_all(MYSQLI_ASSOC);
            ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Full Name</th>
                            <th>Department</th>
                            <th>Total Assignments</th>
                            <th>Total Items</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($employeeReport) > 0): ?>
                            <?php foreach ($employeeReport as $emp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                                <td><?php echo htmlspecialchars($emp['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($emp['department'] ?: '-'); ?></td>
                                <td><?php echo $emp['total_assignments']; ?></td>
                                <td><?php echo $emp['total_items']; ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" onclick="viewEmployeeDetails(<?php echo $emp['id']; ?>)">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">No active assignments found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($reportType === 'category'): ?>
            <h5 class="mb-3">Category-wise Stock Report</h5>
            <?php
            $categoryReport = $conn->query("
                SELECT 
                    c.id,
                    c.name as category_name,
                    COUNT(DISTINCT i.id) as item_count,
                    SUM(i.total_quantity) as total_stock,
                    SUM(i.assigned_quantity) as assigned_stock,
                    SUM(i.available_quantity) as available_stock
                FROM categories c
                LEFT JOIN inventory i ON c.id = i.category_id
                GROUP BY c.id
                ORDER BY c.name
            ")->fetch_all(MYSQLI_ASSOC);
            ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Items Count</th>
                            <th>Total Stock</th>
                            <th>Assigned Stock</th>
                            <th>Available Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryReport as $cat): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($cat['category_name']); ?></strong></td>
                            <td><?php echo $cat['item_count']; ?></td>
                            <td><?php echo $cat['total_stock'] ?? 0; ?></td>
                            <td><?php echo $cat['assigned_stock'] ?? 0; ?></td>
                            <td><?php echo $cat['available_stock'] ?? 0; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($reportType === 'assignments'): ?>
            <h5 class="mb-3">Assignment History Report</h5>
            <?php
            $historyReport = $conn->query("
                SELECT 
                    ah.*,
                    i.item_name,
                    e.employee_id,
                    e.full_name as employee_name,
                    u.full_name as performed_by_name
                FROM assignment_history ah
                INNER JOIN inventory i ON ah.inventory_id = i.id
                INNER JOIN employees e ON ah.employee_id = e.id
                INNER JOIN users u ON ah.performed_by = u.id
                ORDER BY ah.created_at DESC
                LIMIT 100
            ")->fetch_all(MYSQLI_ASSOC);
            ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Item</th>
                            <th>Employee</th>
                            <th>Action</th>
                            <th>Quantity</th>
                            <th>Condition Before</th>
                            <th>Condition After</th>
                            <th>Performed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($historyReport) > 0): ?>
                            <?php foreach ($historyReport as $hist): ?>
                            <tr>
                                <td><?php echo formatDateTime($hist['created_at'], 'Y-m-d H:i'); ?></td>
                                <td><?php echo htmlspecialchars($hist['item_name']); ?></td>
                                <td><?php echo htmlspecialchars($hist['employee_name'] . ' (' . $hist['employee_id'] . ')'); ?></td>
                                <td><span class="badge bg-<?php echo $hist['action'] === 'assigned' ? 'success' : ($hist['action'] === 'returned' ? 'info' : 'warning'); ?>"><?php echo ucfirst($hist['action']); ?></span></td>
                                <td><?php echo $hist['quantity']; ?></td>
                                <td><?php echo $hist['condition_before'] ? ucfirst($hist['condition_before']) : '-'; ?></td>
                                <td><?php echo $hist['condition_after'] ? ucfirst($hist['condition_after']) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($hist['performed_by_name']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center">No assignment history found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Employee Details Modal -->
<div class="modal fade" id="employeeDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Employee Assignment Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="employeeDetailsContent">
                <div class="loading">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function viewEmployeeDetails(employeeId) {
    const modal = new bootstrap.Modal(document.getElementById('employeeDetailsModal'));
    modal.show();
    
    // Fetch employee details via AJAX or load in modal
    fetch('ajax/get_employee_assignments.php?employee_id=' + employeeId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('employeeDetailsContent').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('employeeDetailsContent').innerHTML = '<div class="alert alert-danger">Error loading employee details.</div>';
        });
}
</script>

<?php require_once 'includes/footer.php'; ?>

