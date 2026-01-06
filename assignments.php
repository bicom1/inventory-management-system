<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireAnyRole([ROLE_SUPER_ADMIN, ROLE_IT_ADMIN]);

// Handle CSV export - must be before header output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $conn = getDBConnection();
    $search = sanitizeInput($_GET['search'] ?? '');
    $statusFilter = sanitizeInput($_GET['status'] ?? '');
    $employeeFilter = intval($_GET['employee'] ?? 0);
    
    // Build query for export
    $where = ["1=1"];
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $where[] = "e.full_name LIKE ?";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $types .= "s";
    }
    
    if (!empty($statusFilter)) {
        $where[] = "a.status = ?";
        $params[] = $statusFilter;
        $types .= "s";
    }
    
    if ($employeeFilter > 0) {
        $where[] = "a.employee_id = ?";
        $params[] = $employeeFilter;
        $types .= "i";
    }
    
    $whereClause = implode(" AND ", $where);
    
    $query = "SELECT a.*, e.employee_id, e.full_name as employee_name, u.full_name as assigned_by_name
              FROM assignments a
              INNER JOIN employees e ON a.employee_id = e.id
              INNER JOIN users u ON a.assigned_by = u.id
              WHERE $whereClause
              ORDER BY a.created_at DESC";
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $assignments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get items for each assignment
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
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $itemNames = [];
        $totalQty = 0;
        foreach ($items as $item) {
            $itemNames[] = $item['item_name'] . ' (Qty: ' . $item['quantity'] . ')';
            $totalQty += $item['quantity'];
        }
        $assignment['items_list'] = implode('; ', $itemNames);
        $assignment['total_quantity'] = $totalQty;
    }
    unset($assignment);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=assignments_' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV headers
    fputcsv($output, [
        'ID', 'Employee Name', 'Employee ID', 'Items', 'Total Quantity', 
        'Assigned Date', 'Expected Return Date', 'Status', 'Password', 'Notes', 
        'Assigned By', 'Created At'
    ]);
    
    // CSV data
    foreach ($assignments as $row) {
        fputcsv($output, [
            $row['id'],
            $row['employee_name'],
            $row['employee_id'],
            $row['items_list'] ?? '',
            $row['total_quantity'] ?? 0,
            $row['assigned_date'],
            $row['expected_return_date'] ?? '',
            $row['status'],
            $row['password'] ?? '',
            $row['notes'] ?? '',
            $row['assigned_by_name'],
            $row['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

$pageTitle = 'Assignments';
require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign') {
        $employeeId = intval($_POST['employee_id'] ?? 0);
        $assignedDate = sanitizeInput($_POST['assigned_date'] ?? date('Y-m-d'));
        $expectedReturnDate = sanitizeInput($_POST['expected_return_date'] ?? '');
        $notes = sanitizeInput($_POST['notes'] ?? '');
        $password = sanitizeInput($_POST['password'] ?? '');

        // Arrays of items
        $inventoryIds = $_POST['inventory_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $conditions = $_POST['condition_on_assignment'] ?? [];

        if (!is_array($inventoryIds)) {
            $inventoryIds = [$inventoryIds];
        }
        if (!is_array($quantities)) {
            $quantities = [$quantities];
        }
        if (!is_array($conditions)) {
            $conditions = [$conditions];
        }

        $itemsToAssign = [];
        $itemsCount = count($inventoryIds);

        for ($i = 0; $i < $itemsCount; $i++) {
            $invId = intval($inventoryIds[$i] ?? 0);
            $qty = intval($quantities[$i] ?? 0);
            $cond = sanitizeInput($conditions[$i] ?? 'good');

            if ($invId > 0 && $qty > 0) {
                $itemsToAssign[] = [
                    'inventory_id' => $invId,
                    'quantity' => $qty,
                    'condition' => $cond,
                ];
            }
        }

        if ($employeeId <= 0 || empty($itemsToAssign)) {
            $message = 'Please select an employee and at least one valid item with quantity.';
            $messageType = 'danger';
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                $assignedBy = $_SESSION['user_id'];
                $createdItems = [];

                // First, validate all items and check stock availability
                foreach ($itemsToAssign as $item) {
                    $inventoryId = $item['inventory_id'];
                    $quantity = $item['quantity'];

                    // Check available stock for this item
                    $stmt = $conn->prepare("SELECT available_quantity, item_name FROM inventory WHERE id = ? FOR UPDATE");
                    $stmt->bind_param("i", $inventoryId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 0) {
                        throw new Exception('Inventory item not found (ID: ' . $inventoryId . ').');
                    }
                    
                    $inventory = $result->fetch_assoc();
                    $stmt->close();
                    
                    if ($inventory['available_quantity'] < $quantity) {
                        throw new Exception('Insufficient stock for ' . $inventory['item_name'] . '. Available: ' . $inventory['available_quantity'] . ', requested: ' . $quantity);
                    }
                }

                // Check if there's an existing active assignment for this employee with remaining items
                $stmt = $conn->prepare("
                    SELECT a.id 
                    FROM assignments a
                    WHERE a.employee_id = ? AND a.status = 'active'
                    AND EXISTS (
                        SELECT 1 FROM assignment_items ai WHERE ai.assignment_id = a.id
                    )
                    LIMIT 1
                ");
                $stmt->bind_param("i", $employeeId);
                $stmt->execute();
                $result = $stmt->get_result();
                $existingAssignment = $result->fetch_assoc();
                $stmt->close();
                
                if ($existingAssignment) {
                    // Use existing assignment
                    $assignmentId = $existingAssignment['id'];
                } else {
                    // Create a new assignment record
                    $stmt = $conn->prepare("INSERT INTO assignments (employee_id, assigned_date, expected_return_date, notes, password, assigned_by, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                    $stmt->bind_param("issssi", $employeeId, $assignedDate, $expectedReturnDate, $notes, $password, $assignedBy);
                    
                    if (!$stmt->execute()) {
                        throw new Exception('Error creating assignment: ' . $conn->error);
                    }
                    
                    $assignmentId = $conn->insert_id;
                    $stmt->close();
                }

                // Now create assignment_items for each item
                foreach ($itemsToAssign as $item) {
                    $inventoryId = $item['inventory_id'];
                    $quantity = $item['quantity'];
                    $conditionOnAssignment = $item['condition'];

                    // Create assignment_item
                    $stmt = $conn->prepare("INSERT INTO assignment_items (assignment_id, inventory_id, quantity, condition_on_assignment) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("iiis", $assignmentId, $inventoryId, $quantity, $conditionOnAssignment);
                    
                    if (!$stmt->execute()) {
                        throw new Exception('Error creating assignment item: ' . $conn->error);
                    }
                    
                    $stmt->close();

                    $createdItems[] = [
                        'inventory_id' => $inventoryId,
                        'quantity' => $quantity,
                        'condition' => $conditionOnAssignment,
                    ];

                    // Update inventory stock for this item
                    if (!updateInventoryStock($inventoryId)) {
                        throw new Exception('Error updating inventory stock for item ID ' . $inventoryId . '.');
                    }

                    // Log assignment history for this item
                    $stmt = $conn->prepare("INSERT INTO assignment_history (assignment_id, inventory_id, employee_id, quantity, action, action_date, condition_after, notes, performed_by) VALUES (?, ?, ?, ?, 'assigned', ?, ?, ?, ?)");
                    $stmt->bind_param("iiiisssi", $assignmentId, $inventoryId, $employeeId, $quantity, $assignedDate, $conditionOnAssignment, $notes, $assignedBy);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Commit transaction
                $conn->commit();
                
                // Log audit with summary
                logAudit('assign', 'assignments', $assignmentId, null, [
                    'employee_id' => $employeeId,
                    'items_count' => count($createdItems)
                ]);
                
                $message = 'Items assigned successfully (' . count($createdItems) . ' item(s) in one assignment).';
                $messageType = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'return') {
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        $returnDate = sanitizeInput($_POST['return_date'] ?? date('Y-m-d'));
        $conditionAfter = sanitizeInput($_POST['condition_after'] ?? '');
        $returnNotes = sanitizeInput($_POST['return_notes'] ?? '');
        $selectedItems = $_POST['selected_items'] ?? [];
        
        if ($assignmentId <= 0 || empty($conditionAfter) || empty($selectedItems)) {
            $message = 'Please select at least one item and fill in all required fields.';
            $messageType = 'danger';
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get assignment details
                $stmt = $conn->prepare("SELECT * FROM assignments WHERE id = ? AND status = 'active' FOR UPDATE");
                $stmt->bind_param("i", $assignmentId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception('Assignment not found or already returned.');
                }
                
                $assignment = $result->fetch_assoc();
                $stmt->close();
                
                // Get selected items
                $selectedItemIds = array_map('intval', $selectedItems);
                $placeholders = str_repeat('?,', count($selectedItemIds) - 1) . '?';
                $stmt = $conn->prepare("SELECT * FROM assignment_items WHERE assignment_id = ? AND id IN ($placeholders)");
                $types = 'i' . str_repeat('i', count($selectedItemIds));
                $params = array_merge([$assignmentId], $selectedItemIds);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $assignmentItems = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                if (empty($assignmentItems)) {
                    throw new Exception('No selected items found in this assignment.');
                }
                
                // Delete selected assignment items (they are being returned)
                $performedBy = $_SESSION['user_id'];
                $inventoryIdsToUpdate = [];
                
                foreach ($assignmentItems as $item) {
                    // Delete the assignment item
                    $stmt = $conn->prepare("DELETE FROM assignment_items WHERE id = ?");
                    $stmt->bind_param("i", $item['id']);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Track inventory IDs for stock update
                    if (!in_array($item['inventory_id'], $inventoryIdsToUpdate)) {
                        $inventoryIdsToUpdate[] = $item['inventory_id'];
                    }
                    
                    // Update inventory condition if changed
                    if ($conditionAfter === 'damaged' || $conditionAfter === 'poor') {
                        $stmt = $conn->prepare("UPDATE inventory SET item_condition = ? WHERE id = ?");
                        $stmt->bind_param("si", $conditionAfter, $item['inventory_id']);
                        $stmt->execute();
                        $stmt->close();
                    }
                    
                    // Log return history for each item
                    $stmt = $conn->prepare("INSERT INTO assignment_history (assignment_id, inventory_id, employee_id, quantity, action, action_date, condition_before, condition_after, notes, performed_by) VALUES (?, ?, ?, ?, 'returned', ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiiissssi", $assignmentId, $item['inventory_id'], $assignment['employee_id'], $item['quantity'], $returnDate, $item['condition_on_assignment'], $conditionAfter, $returnNotes, $performedBy);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Check if there are any remaining items in the assignment
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignment_items WHERE assignment_id = ?");
                $stmt->bind_param("i", $assignmentId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $remainingItems = $row['count'] ?? 0;
                $stmt->close();
                
                // If no items left, mark assignment as returned
                if ($remainingItems == 0) {
                    $stmt = $conn->prepare("UPDATE assignments SET status = 'returned' WHERE id = ?");
                    $stmt->bind_param("i", $assignmentId);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Update inventory stock for all affected items
                foreach ($inventoryIdsToUpdate as $invId) {
                    if (!updateInventoryStock($invId)) {
                        throw new Exception('Error updating inventory stock for item ID ' . $invId . '.');
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                logAudit('return', 'assignments', $assignmentId, ['status' => 'active'], ['status' => 'returned']);
                
                $message = 'Items returned successfully (' . count($assignmentItems) . ' item(s)).';
                $messageType = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'mark_damaged' || $action === 'mark_lost') {
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        $actionDate = sanitizeInput($_POST['action_date'] ?? date('Y-m-d'));
        $notes = sanitizeInput($_POST['notes'] ?? '');
        $selectedItems = $_POST['selected_items'] ?? [];
        $newStatus = $action === 'mark_damaged' ? 'damaged' : 'lost';
        
        if ($assignmentId <= 0 || empty($selectedItems)) {
            $message = 'Please select at least one item.';
            $messageType = 'danger';
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get assignment details
                $stmt = $conn->prepare("SELECT * FROM assignments WHERE id = ? AND status = 'active' FOR UPDATE");
                $stmt->bind_param("i", $assignmentId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception('Assignment not found or already processed.');
                }
                
                $assignment = $result->fetch_assoc();
                $stmt->close();
                
                // Get selected items
                $selectedItemIds = array_map('intval', $selectedItems);
                $placeholders = str_repeat('?,', count($selectedItemIds) - 1) . '?';
                $stmt = $conn->prepare("SELECT * FROM assignment_items WHERE assignment_id = ? AND id IN ($placeholders)");
                $types = 'i' . str_repeat('i', count($selectedItemIds));
                $params = array_merge([$assignmentId], $selectedItemIds);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $assignmentItems = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                if (empty($assignmentItems)) {
                    throw new Exception('No selected items found in this assignment.');
                }
                
                // Delete selected assignment items (they are being marked as damaged/lost)
                $performedBy = $_SESSION['user_id'];
                $inventoryIdsToUpdate = [];
                
                foreach ($assignmentItems as $item) {
                    // Delete the assignment item
                    $stmt = $conn->prepare("DELETE FROM assignment_items WHERE id = ?");
                    $stmt->bind_param("i", $item['id']);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Track inventory IDs for stock update
                    if (!in_array($item['inventory_id'], $inventoryIdsToUpdate)) {
                        $inventoryIdsToUpdate[] = $item['inventory_id'];
                    }
                    
                    // Update inventory condition/status
                    $stmt = $conn->prepare("UPDATE inventory SET item_condition = 'damaged', status = ? WHERE id = ?");
                    $stmt->bind_param("si", $newStatus, $item['inventory_id']);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Log history for each item
                    $stmt = $conn->prepare("INSERT INTO assignment_history (assignment_id, inventory_id, employee_id, quantity, action, action_date, condition_before, condition_after, notes, performed_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'damaged', ?, ?)");
                    $stmt->bind_param("iiiissssi", $assignmentId, $item['inventory_id'], $assignment['employee_id'], $item['quantity'], $newStatus, $actionDate, $item['condition_on_assignment'], $notes, $performedBy);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Check if there are any remaining items in the assignment
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignment_items WHERE assignment_id = ?");
                $stmt->bind_param("i", $assignmentId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $remainingItems = $row['count'] ?? 0;
                $stmt->close();
                
                // If no items left, mark assignment with the status
                if ($remainingItems == 0) {
                    $stmt = $conn->prepare("UPDATE assignments SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $newStatus, $assignmentId);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Update inventory stock for all affected items
                foreach ($inventoryIdsToUpdate as $invId) {
                    if (!updateInventoryStock($invId)) {
                        throw new Exception('Error updating inventory stock for item ID ' . $invId . '.');
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                logAudit($newStatus, 'assignments', $assignmentId, ['status' => 'active'], ['status' => $newStatus]);
                
                $message = 'Items marked as ' . $newStatus . ' successfully (' . count($assignmentItems) . ' item(s)).';
                $messageType = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// Get filter parameters
$search = sanitizeInput($_GET['search'] ?? '');
$statusFilter = sanitizeInput($_GET['status'] ?? 'active');
$employeeFilter = intval($_GET['employee'] ?? 0);

// Build query
$where = ["1=1"];
$params = [];
$types = "";

// For search, we'll filter by employee name first, then filter by item name in PHP
if (!empty($search)) {
    $where[] = "e.full_name LIKE ?";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $types .= "s";
}

if (!empty($statusFilter)) {
    $where[] = "a.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($employeeFilter > 0) {
    $where[] = "a.employee_id = ?";
    $params[] = $employeeFilter;
    $types .= "i";
}

$whereClause = implode(" AND ", $where);

// Get employees for filter
$employees = $conn->query("SELECT id, employee_id, full_name FROM employees WHERE status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Get all employees with department for assignment modal
$allEmployees = $conn->query("SELECT id, employee_id, full_name, department FROM employees WHERE status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Get unique departments for assignment modal
$departments = $conn->query("SELECT DISTINCT department FROM employees WHERE status = 'active' AND department IS NOT NULL AND department != '' ORDER BY department")->fetch_all(MYSQLI_ASSOC);

// Get inventory items for assignment
$inventoryItems = $conn->query("SELECT id, item_name, available_quantity FROM inventory WHERE status != 'retired' ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);

// Get assignments (grouped by assignment, not by item)
$query = "SELECT a.*, e.id as employee_db_id, e.employee_id, e.full_name as employee_name, u.full_name as assigned_by_name
          FROM assignments a
          INNER JOIN employees e ON a.employee_id = e.id
          INNER JOIN users u ON a.assigned_by = u.id
          WHERE $whereClause
          ORDER BY a.created_at DESC";
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$assignments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// For each assignment, fetch its items
$filteredAssignments = [];
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
    
    // Filter by item name if search is provided
    if (!empty($search)) {
        $itemMatches = false;
        foreach ($assignment['items'] as $item) {
            if (stripos($item['item_name'], $search) !== false) {
                $itemMatches = true;
                break;
            }
        }
        if ($itemMatches || stripos($assignment['employee_name'], $search) !== false) {
            $filteredAssignments[] = $assignment;
        }
    } else {
        $filteredAssignments[] = $assignment;
    }
}
unset($assignment);
$assignments = $filteredAssignments;
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-person-check"></i> Assignments</h2>
    </div>
</div>

<?php 
if ($message) {
    showSweetAlert($messageType, $message);
}
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Assignments</h5>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#assignModal">
            <i class="bi bi-plus-circle"></i> New Assignment
        </button>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-3 mb-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="Search items or employees..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="returned" <?php echo $statusFilter === 'returned' ? 'selected' : ''; ?>>Returned</option>
                    <option value="damaged" <?php echo $statusFilter === 'damaged' ? 'selected' : ''; ?>>Damaged</option>
                    <option value="lost" <?php echo $statusFilter === 'lost' ? 'selected' : ''; ?>>Lost</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="employee">
                    <option value="0">All Employees</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" <?php echo $employeeFilter == $emp['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($emp['full_name'] . ' (' . $emp['employee_id'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="assignments.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>
            </div>
        </form>
        
        <!-- Assignments Table -->
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 25%;">Items</th>
                        <th style="width: 15%;">Employee</th>
                        <th style="width: 8%;" class="text-center">Total Qty</th>
                        <th style="width: 10%;">Assigned Date</th>
                        <th style="width: 10%;">Expected Return</th>
                        <th style="width: 8%;" class="text-center">Status</th>
                        <th style="width: 12%;">Assigned By</th>
                        <th style="width: 12%;" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($assignments) > 0): ?>
                        <?php foreach ($assignments as $assign): ?>
                        <tr>
                            <td>
                                <?php if (!empty($assign['items'])): ?>
                                    <?php 
                                    $totalQty = 0;
                                    $itemNames = [];
                                    foreach ($assign['items'] as $item): 
                                        $totalQty += $item['quantity'];
                                        $itemNames[] = htmlspecialchars($item['item_name']);
                                    endforeach;
                                    ?>
                                    <div class="text-dark">
                                        <?php echo implode(', ', $itemNames); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted fst-italic">No items</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($assign['employee_name']); ?></div>
                                        <small class="text-muted">ID: <?php echo htmlspecialchars($assign['employee_id']); ?></small>
                                    </div>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-primary" onclick="viewEmployeeDetails(<?php echo $assign['employee_db_id']; ?>)" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info fs-6 px-3 py-2"><?php echo $totalQty ?? 0; ?></span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <i class="bi bi-calendar3 text-muted"></i>
                                    <span><?php echo formatDate($assign['assigned_date']); ?></span>
                                </div>
                            </td>
                            <td>
                                <?php if ($assign['expected_return_date']): ?>
                                    <div class="d-flex align-items-center gap-1">
                                        <i class="bi bi-calendar-check text-muted"></i>
                                        <span><?php echo formatDate($assign['expected_return_date']); ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted fst-italic">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo getStatusBadge($assign['status']); ?> px-3 py-2">
                                    <?php echo ucfirst($assign['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <i class="bi bi-person text-muted"></i>
                                    <span><?php echo htmlspecialchars($assign['assigned_by_name']); ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    <button type="button" class="btn btn-sm btn-info" onclick="viewAssignmentDetails(<?php echo $assign['id']; ?>)" title="View Details">
                                        <i class="bi bi-info-circle"></i> Details
                                    </button>
                                    <?php if ($assign['status'] === 'active'): ?>
                                        <button type="button" class="btn btn-sm btn-success" onclick="returnItem(<?php echo $assign['id']; ?>)" title="Return Items">
                                            <i class="bi bi-arrow-return-left"></i> Return
                                        </button>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-warning" onclick="markDamaged(<?php echo $assign['id']; ?>)" title="Mark as Damaged">
                                                <i class="bi bi-exclamation-triangle"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="markLost(<?php echo $assign['id']; ?>)" title="Mark as Lost">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    <p class="mb-0">No assignments found.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Assignment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="assignForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign">

                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" id="departmentFilter" onchange="filterEmployeesByDepartment()">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept['department']); ?>">
                                    <?php echo htmlspecialchars($dept['department']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Employee <span class="text-danger">*</span></label>
                        <select class="form-select" name="employee_id" id="employeeSelect" disabled required>
                            <option value="">Select Department First</option>
                        </select>
                        <small class="form-text text-muted"><i class="bi bi-info-circle"></i> Select a department above to view employees</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assigned Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="assigned_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Expected Return Date</label>
                            <input type="date" class="form-control" name="expected_return_date">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Items to Assign <span class="text-danger">*</span></label>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle" id="assignmentItemsTable">
                                <thead>
                                    <tr>
                                        <th style="width: 40%;">Item</th>
                                        <th style="width: 15%;">Available</th>
                                        <th style="width: 15%;">Quantity</th>
                                        <th style="width: 20%;">Condition</th>
                                        <th style="width: 10%;"></th>
                                    </tr>
                                </thead>
                                <tbody id="assignmentItemsBody">
                                    <tr class="assignment-item-row">
                                        <td>
                                            <select class="form-select inventory-select" name="inventory_id[]" onchange="updateItemRow(this)" required>
                                                <option value="">Select Item</option>
                                                <?php foreach ($inventoryItems as $item): ?>
                                                    <option value="<?php echo $item['id']; ?>" data-available="<?php echo $item['available_quantity']; ?>">
                                                        <?php echo htmlspecialchars($item['item_name'] . ' (Available: ' . $item['available_quantity'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary available-stock" data-available="0">-</span>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control quantity-input" name="quantity[]" min="1" value="1" onchange="validateItemRow(this)" required>
                                        </td>
                                        <td>
                                            <select class="form-select" name="condition_on_assignment[]">
                                                <option value="excellent">Excellent</option>
                                                <option value="good" selected>Good</option>
                                                <option value="fair">Fair</option>
                                                <option value="poor">Poor</option>
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItemRow(this)">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItemRow()">
                            <i class="bi bi-plus-circle"></i> Add Another Item
                        </button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password (Optional)</label>
                        <input type="text" class="form-control" name="password" placeholder="Enter password if applicable">
                        <small class="form-text text-muted">Optional: Add password for devices or accounts if needed</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign Items</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Return Items</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="returnForm" onsubmit="return validateItemSelection('return')">
                <div class="modal-body">
                    <input type="hidden" name="action" value="return">
                    <input type="hidden" name="assignment_id" id="returnAssignmentId">
                    
                    <div class="mb-3">
                        <label class="form-label">Select Items to Return <span class="text-danger">*</span></label>
                        <div id="returnItemsList" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                            <div class="text-center text-muted">
                                <i class="bi bi-hourglass-split"></i> Loading items...
                            </div>
                        </div>
                        <small class="form-text text-muted">Select at least one item to return</small>
                        <div id="returnItemsError" class="text-danger small mt-1" style="display: none;">Please select at least one item.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Return Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="return_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Condition After Return <span class="text-danger">*</span></label>
                        <select class="form-select" name="condition_after" required>
                            <option value="">Select Condition</option>
                            <option value="excellent">Excellent</option>
                            <option value="good" selected>Good</option>
                            <option value="fair">Fair</option>
                            <option value="poor">Poor</option>
                            <option value="damaged">Damaged</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Return Notes</label>
                        <textarea class="form-control" name="return_notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Return Selected Items</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assignment Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle"></i> Assignment Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading assignment details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Mark Damaged/Lost Modal -->
<div class="modal fade" id="damageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="damageModalTitle">Mark as Damaged/Lost</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="damageForm" onsubmit="return validateItemSelection('damage')">
                <div class="modal-body">
                    <input type="hidden" name="action" id="damageAction">
                    <input type="hidden" name="assignment_id" id="damageAssignmentId">
                    
                    <div class="mb-3">
                        <label class="form-label">Select Items <span class="text-danger">*</span></label>
                        <div id="damageItemsList" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                            <div class="text-center text-muted">
                                <i class="bi bi-hourglass-split"></i> Loading items...
                            </div>
                        </div>
                        <small class="form-text text-muted">Select at least one item to mark</small>
                        <div id="damageItemsError" class="text-danger small mt-1" style="display: none;">Please select at least one item.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="action_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" id="damageSubmitBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Store all employees data
const allEmployees = <?php echo json_encode($allEmployees); ?>;

// Filter employees by department
function filterEmployeesByDepartment() {
    const departmentSelect = document.getElementById('departmentFilter');
    const employeeSelect = document.getElementById('employeeSelect');
    const selectedDepartment = departmentSelect.value;
    
    // Clear current options
    employeeSelect.innerHTML = '';
    
    // Filter employees
    let filteredEmployees;
    if (!selectedDepartment || selectedDepartment === '') {
        // Show all employees when "All Departments" is selected
        filteredEmployees = allEmployees;
    } else {
        // Filter by selected department
        filteredEmployees = allEmployees.filter(emp => emp.department === selectedDepartment);
    }
    
    if (filteredEmployees.length === 0) {
        employeeSelect.innerHTML = '<option value="">No employees found</option>';
        employeeSelect.disabled = true;
        employeeSelect.required = false;
        return;
    }
    
    // Add default option
    employeeSelect.innerHTML = '<option value="">Select Employee</option>';
    
    // Sort employees by name
    filteredEmployees.sort((a, b) => {
        if (a.full_name < b.full_name) return -1;
        if (a.full_name > b.full_name) return 1;
        return 0;
    });
    
    // Populate employee dropdown
    filteredEmployees.forEach(emp => {
        const option = document.createElement('option');
        option.value = emp.id;
        option.textContent = emp.full_name + ' (' + emp.employee_id + ')';
        employeeSelect.appendChild(option);
    });
    
    employeeSelect.disabled = false;
    employeeSelect.required = true;
}

// Reset form when modal is closed
document.addEventListener('DOMContentLoaded', function() {
    const assignModal = document.getElementById('assignModal');
    if (assignModal) {
        assignModal.addEventListener('hidden.bs.modal', function() {
            // Reset department filter
            const departmentFilter = document.getElementById('departmentFilter');
            departmentFilter.value = '';
            // Reset employee select
            const employeeSelect = document.getElementById('employeeSelect');
            employeeSelect.innerHTML = '<option value="">Select Department First</option>';
            employeeSelect.disabled = true;
            employeeSelect.required = false;
            // Trigger filter to reset state
            filterEmployeesByDepartment();
        });
    }
});

function addItemRow() {
    const tbody = document.getElementById('assignmentItemsBody');
    const firstRow = tbody.querySelector('.assignment-item-row');
    const newRow = firstRow.cloneNode(true);

    // Reset values in the cloned row
    const select = newRow.querySelector('.inventory-select');
    const availableSpan = newRow.querySelector('.available-stock');
    const quantityInput = newRow.querySelector('.quantity-input');

    if (select) select.value = '';
    if (availableSpan) {
        availableSpan.textContent = '-';
        availableSpan.dataset.available = '0';
        availableSpan.className = 'badge bg-secondary available-stock';
    }
    if (quantityInput) {
        quantityInput.value = 1;
        quantityInput.setCustomValidity('');
        quantityInput.classList.remove('is-invalid');
    }

    tbody.appendChild(newRow);
}

function removeItemRow(button) {
    const row = button.closest('.assignment-item-row');
    const tbody = document.getElementById('assignmentItemsBody');
    if (tbody.querySelectorAll('.assignment-item-row').length > 1) {
        row.remove();
    } else {
        // Reset instead of removing last row
        const select = row.querySelector('.inventory-select');
        const availableSpan = row.querySelector('.available-stock');
        const quantityInput = row.querySelector('.quantity-input');
        if (select) select.value = '';
        if (availableSpan) {
            availableSpan.textContent = '-';
            availableSpan.dataset.available = '0';
            availableSpan.className = 'badge bg-secondary available-stock';
        }
        if (quantityInput) {
            quantityInput.value = 1;
            quantityInput.setCustomValidity('');
            quantityInput.classList.remove('is-invalid');
        }
    }
}

function updateItemRow(selectEl) {
    const row = selectEl.closest('.assignment-item-row');
    const option = selectEl.options[selectEl.selectedIndex];
    const availableSpan = row.querySelector('.available-stock');
    const quantityInput = row.querySelector('.quantity-input');

    const available = parseInt(option.getAttribute('data-available') || 0);
    if (availableSpan) {
        availableSpan.textContent = available > 0 ? available : '-';
        availableSpan.dataset.available = available;
        availableSpan.className = 'badge bg-' + (available > 0 ? 'success' : 'danger') + ' available-stock';
    }

    if (quantityInput) {
        validateItemRow(quantityInput);
    }
}

function validateItemRow(inputEl) {
    const row = inputEl.closest('.assignment-item-row');
    const selectEl = row.querySelector('.inventory-select');
    const option = selectEl ? selectEl.options[selectEl.selectedIndex] : null;
    const available = option ? parseInt(option.getAttribute('data-available') || 0) : 0;
    const quantity = parseInt(inputEl.value || 0);

    if (!option || !option.value) {
        inputEl.setCustomValidity('Please select an item first.');
        inputEl.classList.add('is-invalid');
        return;
    }

    if (quantity <= 0) {
        inputEl.setCustomValidity('Quantity must be at least 1.');
        inputEl.classList.add('is-invalid');
    } else if (quantity > available) {
        inputEl.setCustomValidity('Quantity cannot exceed available stock (' + available + ').');
        inputEl.classList.add('is-invalid');
    } else {
        inputEl.setCustomValidity('');
        inputEl.classList.remove('is-invalid');
    }
}

function returnItem(assignmentId) {
    document.getElementById('returnAssignmentId').value = assignmentId;
    loadAssignmentItems(assignmentId, 'return');
    const modal = new bootstrap.Modal(document.getElementById('returnModal'));
    modal.show();
}

function markDamaged(assignmentId) {
    document.getElementById('damageModalTitle').textContent = 'Mark as Damaged';
    document.getElementById('damageAction').value = 'mark_damaged';
    document.getElementById('damageAssignmentId').value = assignmentId;
    document.getElementById('damageSubmitBtn').className = 'btn btn-warning';
    document.getElementById('damageSubmitBtn').textContent = 'Mark as Damaged';
    loadAssignmentItems(assignmentId, 'damage');
    const modal = new bootstrap.Modal(document.getElementById('damageModal'));
    modal.show();
}

function markLost(assignmentId) {
    document.getElementById('damageModalTitle').textContent = 'Mark as Lost';
    document.getElementById('damageAction').value = 'mark_lost';
    document.getElementById('damageAssignmentId').value = assignmentId;
    document.getElementById('damageSubmitBtn').className = 'btn btn-danger';
    document.getElementById('damageSubmitBtn').textContent = 'Mark as Lost';
    loadAssignmentItems(assignmentId, 'damage');
    const modal = new bootstrap.Modal(document.getElementById('damageModal'));
    modal.show();
}

// Load assignment items and populate modal
function loadAssignmentItems(assignmentId, modalType) {
    const itemsList = modalType === 'return' 
        ? document.getElementById('returnItemsList')
        : document.getElementById('damageItemsList');
    
    itemsList.innerHTML = '<div class="text-center text-muted"><i class="bi bi-hourglass-split"></i> Loading items...</div>';
    
    fetch(`ajax/get_assignment_items.php?assignment_id=${assignmentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                itemsList.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                return;
            }
            
            if (!data.items || data.items.length === 0) {
                itemsList.innerHTML = '<div class="alert alert-warning">No items found in this assignment.</div>';
                return;
            }
            
            let html = '<div class="list-group">';
            data.items.forEach(item => {
                html += `
                    <div class="list-group-item">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="selected_items[]" value="${item.id}" id="item_${item.id}" required>
                            <label class="form-check-label w-100" for="item_${item.id}">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>${item.item_name}</strong>
                                        <span class="badge bg-${item.item_type === 'asset' ? 'primary' : 'info'} ms-2">${item.item_type}</span>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-secondary">Qty: ${item.quantity}</span>
                                        <span class="badge bg-${getConditionBadgeClass(item.condition_on_assignment)} ms-1">${item.condition_on_assignment}</span>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            itemsList.innerHTML = html;
        })
        .catch(error => {
            itemsList.innerHTML = '<div class="alert alert-danger">Error loading items. Please try again.</div>';
            console.error('Error:', error);
        });
}

function getConditionBadgeClass(condition) {
    const badges = {
        'excellent': 'success',
        'good': 'primary',
        'fair': 'warning',
        'poor': 'danger',
        'damaged': 'dark'
    };
    return badges[condition] || 'secondary';
}

// Validate that at least one item is selected
function validateItemSelection(modalType) {
    const itemsList = modalType === 'return' 
        ? document.getElementById('returnItemsList')
        : document.getElementById('damageItemsList');
    const errorDiv = modalType === 'return'
        ? document.getElementById('returnItemsError')
        : document.getElementById('damageItemsError');
    
    const checkboxes = itemsList.querySelectorAll('input[type="checkbox"][name="selected_items[]"]');
    const checked = Array.from(checkboxes).some(cb => cb.checked);
    
    if (!checked) {
        errorDiv.style.display = 'block';
        return false;
    }
    
    errorDiv.style.display = 'none';
    return true;
}

// View assignment details
function viewAssignmentDetails(assignmentId) {
    const modalBody = document.getElementById('detailsModalBody');
    modalBody.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading assignment details...</p>
        </div>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();
    
    fetch(`ajax/get_assignment_items.php?assignment_id=${assignmentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                modalBody.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                return;
            }
            
            const assignment = data.assignment;
            const items = data.items || [];
            
            let html = `
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="bi bi-person"></i> Employee Information</h6>
                        <table class="table table-sm table-bordered">
                            <tr>
                                <th style="width: 40%;">Name:</th>
                                <td>${assignment.employee_name}</td>
                            </tr>
                            <tr>
                                <th>Employee ID:</th>
                                <td>${assignment.employee_id}</td>
                            </tr>
                            <tr>
                                <th>Department:</th>
                                <td>${assignment.department || '-'}</td>
                            </tr>
                            <tr>
                                <th>Position:</th>
                                <td>${assignment.position || '-'}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="bi bi-calendar"></i> Assignment Information</h6>
                        <table class="table table-sm table-bordered">
                            <tr>
                                <th style="width: 40%;">Assigned Date:</th>
                                <td>${assignment.assigned_date}</td>
                            </tr>
                            <tr>
                                <th>Expected Return:</th>
                                <td>${assignment.expected_return_date || '-'}</td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td><span class="badge bg-${getStatusBadgeClass(assignment.status)}">${assignment.status.charAt(0).toUpperCase() + assignment.status.slice(1)}</span></td>
                            </tr>
                            <tr>
                                <th>Assigned By:</th>
                                <td>${assignment.assigned_by_name}</td>
                            </tr>
                            <tr>
                                <th>Password:</th>
                                <td>${assignment.password ? '<code class="text-primary">' + assignment.password + '</code>' : '<span class="text-muted">-</span>'}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            `;
            
            if (assignment.notes) {
                html += `
                    <div class="mb-4">
                        <h6 class="text-primary"><i class="bi bi-sticky"></i> Notes</h6>
                        <div class="alert alert-light">${assignment.notes}</div>
                    </div>
                `;
            }
            
            html += `
                <h6 class="text-primary mb-3"><i class="bi bi-boxes"></i> Assigned Items (${items.length})</h6>
            `;
            
            if (items.length === 0) {
                html += '<div class="alert alert-warning">No items found in this assignment.</div>';
            } else {
                html += `
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th>Brand</th>
                                    <th>Serial Number</th>
                                    <th>Quantity</th>
                                    <th>Condition (On Assignment)</th>
                                    <th>Condition (On Return)</th>
                                    <th>Price/Unit</th>
                                    <th>Total Price</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                let totalValue = 0;
                items.forEach(item => {
                    const itemTotalPrice = (item.price_per_unit || 0) * (item.quantity || 0);
                    totalValue += itemTotalPrice;
                    
                    html += `
                        <tr>
                            <td><strong>${item.item_name || '-'}</strong></td>
                            <td>${item.category_name || '-'}</td>
                            <td><span class="badge bg-${item.item_type === 'asset' ? 'primary' : 'info'}">${item.item_type ? item.item_type.charAt(0).toUpperCase() + item.item_type.slice(1) : '-'}</span></td>
                            <td>${item.brand || '-'}</td>
                            <td>${item.serial_number || '-'}</td>
                            <td><span class="badge bg-secondary">${item.quantity || 0}</span></td>
                            <td><span class="badge bg-${getConditionBadgeClass(item.condition_on_assignment || 'good')}">${item.condition_on_assignment ? item.condition_on_assignment.charAt(0).toUpperCase() + item.condition_on_assignment.slice(1) : 'Good'}</span></td>
                            <td>${item.condition_on_return ? `<span class="badge bg-${getConditionBadgeClass(item.condition_on_return)}">${item.condition_on_return.charAt(0).toUpperCase() + item.condition_on_return.slice(1)}</span>` : '<span class="text-muted">-</span>'}</td>
                            <td>${item.price_per_unit && item.price_per_unit > 0 ? `PKR ${parseFloat(item.price_per_unit).toFixed(2)}` : '-'}</td>
                            <td>${itemTotalPrice > 0 ? `<strong class="text-primary">PKR ${itemTotalPrice.toFixed(2)}</strong>` : '-'}</td>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="9" class="text-end">Total Assignment Value:</th>
                                    <th><strong class="text-success">PKR ${totalValue.toFixed(2)}</strong></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                `;
            }
            
            modalBody.innerHTML = html;
        })
        .catch(error => {
            modalBody.innerHTML = '<div class="alert alert-danger">Error loading assignment details. Please try again.</div>';
            console.error('Error:', error);
        });
}

function getStatusBadgeClass(status) {
    const badges = {
        'active': 'success',
        'returned': 'secondary',
        'damaged': 'danger',
        'lost': 'dark'
    };
    return badges[status] || 'secondary';
}
</script>

<?php require_once 'includes/footer.php'; ?>

