<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireLogin();

$pageTitle = 'Dashboard';
require_once 'includes/header.php';

$stats = getDashboardStats();
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-speedometer2"></i> Dashboard</h2>
        <p class="text-muted">Overview of inventory and asset management</p>
    </div>
</div>

<?php 
if (isset($_GET['error']) && $_GET['error'] === 'access_denied') {
    showSweetAlert('danger', 'Access denied. You don\'t have permission to access this resource.');
}
?>

<?php if ($stats): ?>
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <a href="inventory.php" class="text-decoration-none text-reset">
        <div class="stat-card text-primary">
            <div class="stat-icon"><i class="bi bi-boxes"></i></div>
            <div class="stat-value"><?php echo number_format($stats['total_stock']); ?></div>
            <div class="stat-label">Total Stock</div>
        </div>
        </a>
    </div>
    <div class="col-md-3 mb-3">
        <a href="assignments.php" class="text-decoration-none text-reset">
        <div class="stat-card text-info">
            <div class="stat-icon"><i class="bi bi-person-check"></i></div>
            <div class="stat-value"><?php echo number_format($stats['assigned_stock']); ?></div>
            <div class="stat-label">Assigned Stock</div>
        </div>
        </a>
    </div>
    <div class="col-md-3 mb-3">
    <a href="inventory.php" class="text-decoration-none text-reset">
        <div class="stat-card text-success">
            <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div class="stat-value"><?php echo number_format($stats['available_stock']); ?></div>
            <div class="stat-label">Available Stock</div>
        </div>
        </a>
    </div>
    <div class="col-md-3 mb-3">
        <div class="stat-card text-warning">
            <div class="stat-icon"><i class="bi bi-list-ul"></i></div>
            <div class="stat-value"><?php echo number_format($stats['total_items']); ?></div>
            <div class="stat-label">Total Items</div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4 mb-3">
    <a href="employees.php" class="text-decoration-none text-reset">
        <div class="stat-card text-primary">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div class="stat-value"><?php echo number_format($stats['total_employees']); ?></div>
            <div class="stat-label">Active Employees</div>
        </div>
        </a>
    </div>
    <div class="col-md-4 mb-3">
    <a href="assignments.php" class="text-decoration-none text-reset">
        <div class="stat-card text-info">
            <div class="stat-icon"><i class="bi bi-clipboard-check"></i></div>
            <div class="stat-value"><?php echo number_format($stats['active_assignments']); ?></div>
            <div class="stat-label">Active Assignments</div>
        </div>
        </a>
    </div>
    <div class="col-md-4 mb-3">
        <div class="stat-card text-secondary">
            <div class="stat-icon"><i class="bi bi-pie-chart"></i></div>
            <div class="stat-value">
                <?php 
                $assets = $stats['by_type']['asset'] ?? 0;
                $furniture = $stats['by_type']['furniture'] ?? 0;
                echo number_format($assets) . ' / ' . number_format($furniture);
                ?>
            </div>
            <div class="stat-label">IT Assets / Furniture</div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Assignments</h5>
            </div>
            <div class="card-body">
                <?php
                $conn = getDBConnection();
                $stmt = $conn->prepare("
                    SELECT a.*, e.full_name as employee_name, u.full_name as assigned_by_name
                    FROM assignments a
                    INNER JOIN employees e ON a.employee_id = e.id
                    INNER JOIN users u ON a.assigned_by = u.id
                    WHERE a.status = 'active'
                    ORDER BY a.created_at DESC
                    LIMIT 10
                ");
                $stmt->execute();
                $result = $stmt->get_result();
                $assignments = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                // Fetch items for each assignment
                foreach ($assignments as &$assignment) {
                    $stmt = $conn->prepare("
                        SELECT ai.*, i.item_name
                        FROM assignment_items ai
                        INNER JOIN inventory i ON ai.inventory_id = i.id
                        WHERE ai.assignment_id = ?
                        ORDER BY i.item_name
                    ");
                    $stmt->bind_param("i", $assignment['id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $assignment['items'] = $result->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    
                    // Backward compatibility: check old assignments
                    if (empty($assignment['items']) && !empty($assignment['inventory_id'])) {
                        $stmt = $conn->prepare("SELECT id, item_name FROM inventory WHERE id = ?");
                        $stmt->bind_param("i", $assignment['inventory_id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $assignment['items'] = [[
                                'item_name' => $row['item_name'],
                                'quantity' => $assignment['quantity'] ?? 1
                            ]];
                        }
                        $stmt->close();
                    }
                }
                unset($assignment);
                
                if (count($assignments) > 0):
                ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Items</th>
                                    <th>Employee</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $row): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($row['items'])): ?>
                                            <?php 
                                            $itemNames = [];
                                            foreach ($row['items'] as $item) {
                                                $itemNames[] = htmlspecialchars($item['item_name']) . ' (x' . $item['quantity'] . ')';
                                            }
                                            echo implode(', ', $itemNames);
                                            ?>
                                        <?php else: ?>
                                            <span class="text-muted">No items</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                    <td><?php echo formatDate($row['assigned_date']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>No recent assignments</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Low Stock Items</h5>
            </div>
            <div class="card-body">
                <?php
                $stmt = $conn->prepare("
                    SELECT id, item_name, available_quantity, total_quantity
                    FROM inventory
                    WHERE available_quantity <= 5 AND status != 'retired'
                    ORDER BY available_quantity ASC
                    LIMIT 10
                ");
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0):
                ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Available</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                                    <td><span class="badge bg-<?php echo $row['available_quantity'] == 0 ? 'danger' : 'warning'; ?>"><?php echo $row['available_quantity']; ?></span></td>
                                    <td><?php echo $row['total_quantity']; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-check-circle"></i>
                        <p>All items have sufficient stock</p>
                    </div>
                <?php endif; ?>
                <?php $stmt->close(); ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

