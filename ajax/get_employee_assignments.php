<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireLogin();

$employeeId = intval($_GET['employee_id'] ?? 0);

if ($employeeId <= 0) {
    echo '<div class="alert alert-danger">Invalid employee ID.</div>';
    exit;
}

$conn = getDBConnection();

// Get employee info
$stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->bind_param("i", $employeeId);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

if (!$employee) {
    echo '<div class="alert alert-danger">Employee not found.</div>';
    exit;
}

// Get assignments (grouped)
$stmt = $conn->prepare("
    SELECT a.*, u.full_name as assigned_by_name
    FROM assignments a
    INNER JOIN users u ON a.assigned_by = u.id
    WHERE a.employee_id = ? AND a.status = 'active'
    ORDER BY a.assigned_date DESC
");
$stmt->bind_param("i", $employeeId);
$stmt->execute();
$result = $stmt->get_result();
$assignments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// For each assignment, fetch its items
foreach ($assignments as &$assignment) {
    $stmt = $conn->prepare("
        SELECT ai.*, i.item_name, i.type as item_type
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
    
    // If no items found in assignment_items, check if it's an old assignment with inventory_id
    if (empty($assignment['items']) && !empty($assignment['inventory_id'])) {
        $stmt = $conn->prepare("SELECT id, item_name, type as item_type FROM inventory WHERE id = ?");
        $stmt->bind_param("i", $assignment['inventory_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $assignment['items'] = [[
                'inventory_id' => $row['id'],
                'item_name' => $row['item_name'],
                'item_type' => $row['item_type'],
                'quantity' => $assignment['quantity'] ?? 1,
                'condition_on_assignment' => $assignment['condition_on_assignment'] ?? 'good'
            ]];
        }
        $stmt->close();
    }
}
unset($assignment);
?>

<div class="mb-3">
    <h6>Employee Information</h6>
    <p><strong>Name:</strong> <?php echo htmlspecialchars($employee['full_name']); ?><br>
    <strong>Employee ID:</strong> <?php echo htmlspecialchars($employee['employee_id']); ?><br>
    <strong>Department:</strong> <?php echo htmlspecialchars($employee['department'] ?: '-'); ?><br>
    <strong>Position:</strong> <?php echo htmlspecialchars($employee['position'] ?: '-'); ?></p>
</div>

<h6>Active Assignments</h6>
<?php if (count($assignments) > 0): ?>
    <div class="table-responsive">
        <table class="table table-sm table-bordered">
            <thead>
                <tr>
                    <th>Assignment Date</th>
                    <th>Items</th>
                    <th>Expected Return</th>
                    <th>Assigned By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $assign): ?>
                <tr>
                    <td><?php echo formatDate($assign['assigned_date']); ?></td>
                    <td>
                        <?php if (!empty($assign['items'])): ?>
                            <div class="d-flex flex-column gap-1">
                                <?php foreach ($assign['items'] as $item): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <span><?php echo htmlspecialchars($item['item_name']); ?></span>
                                        <span class="badge bg-<?php echo $item['item_type'] === 'asset' ? 'primary' : 'info'; ?>"><?php echo ucfirst($item['item_type']); ?></span>
                                        <span class="badge bg-secondary">Qty: <?php echo $item['quantity']; ?></span>
                                        <span class="badge bg-<?php echo getConditionBadge($item['condition_on_assignment'] ?? 'good'); ?>"><?php echo ucfirst($item['condition_on_assignment'] ?? 'good'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted">No items</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo formatDate($assign['expected_return_date']); ?></td>
                    <td><?php echo htmlspecialchars($assign['assigned_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <p class="text-muted">No active assignments.</p>
<?php endif; ?>

