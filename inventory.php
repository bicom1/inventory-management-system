<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireAnyRole([ROLE_SUPER_ADMIN, ROLE_IT_ADMIN]);

// Handle CSV export - must be before header output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $conn = getDBConnection();
    $search = sanitizeInput($_GET['search'] ?? '');
    $categoryFilter = intval($_GET['category'] ?? 0);
    $typeFilter = sanitizeInput($_GET['type'] ?? '');
    $statusFilter = sanitizeInput($_GET['status'] ?? '');
    
    // Build query for export
    $where = ["1=1"];
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $where[] = "(i.item_name LIKE ? OR i.brand LIKE ? OR i.serial_number LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "sss";
    }
    
    if ($categoryFilter > 0) {
        $where[] = "i.category_id = ?";
        $params[] = $categoryFilter;
        $types .= "i";
    }
    
    if (!empty($typeFilter)) {
        $where[] = "i.type = ?";
        $params[] = $typeFilter;
        $types .= "s";
    }
    
    if (!empty($statusFilter)) {
        $where[] = "i.status = ?";
        $params[] = $statusFilter;
        $types .= "s";
    }
    
    $whereClause = implode(" AND ", $where);
    
    $query = "SELECT i.*, c.name as category_name FROM inventory i LEFT JOIN categories c ON i.category_id = c.id WHERE $whereClause ORDER BY i.created_at DESC";
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=inventory_' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV headers
    fputcsv($output, [
        'ID', 'Item Name', 'Category', 'Type', 'Brand', 'Serial Number', 
        'Condition', 'Status', 'Total Quantity', 'Assigned Quantity', 
        'Available Quantity', 'Price Per Unit', 'Total Price', 'Description', 'Created At'
    ]);
    
    // CSV data
    foreach ($items as $row) {
        fputcsv($output, [
            $row['id'],
            $row['item_name'],
            $row['category_name'] ?? '',
            $row['type'],
            $row['brand'] ?? '',
            $row['serial_number'] ?? '',
            $row['item_condition'],
            $row['status'],
            $row['total_quantity'],
            $row['assigned_quantity'],
            $row['available_quantity'],
            $row['price_per_unit'] ?? 0,
            $row['total_price'] ?? 0,
            $row['description'] ?? '',
            $row['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

$pageTitle = 'Inventory Management';
require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $itemName = sanitizeInput($_POST['item_name'] ?? '');
        $categoryId = intval($_POST['category_id'] ?? 0);
        $type = sanitizeInput($_POST['type'] ?? '');
        $brand = sanitizeInput($_POST['brand'] ?? '');
        $serialNumber = sanitizeInput($_POST['serial_number'] ?? '');
        $itemCondition = sanitizeInput($_POST['condition'] ?? 'good');
        $status = sanitizeInput($_POST['status'] ?? 'available');
        $totalQuantity = intval($_POST['total_quantity'] ?? 0);
        $pricePerUnit = floatval($_POST['price_per_unit'] ?? 0);
        $totalPrice = $pricePerUnit * $totalQuantity;
        $description = sanitizeInput($_POST['description'] ?? '');
        
        if (empty($itemName) || $categoryId <= 0 || empty($type) || $totalQuantity < 0) {
            $message = 'Please fill in all required fields.';
            $messageType = 'danger';
        } else {
            // Check if user is super admin
            if (!isSuperAdmin()) {
                // Create pending approval
                $requestData = [
                    'item_name' => $itemName,
                    'category_id' => $categoryId,
                    'type' => $type,
                    'brand' => $brand,
                    'serial_number' => $serialNumber,
                    'condition' => $itemCondition,
                    'status' => $status,
                    'total_quantity' => $totalQuantity,
                    'price_per_unit' => $pricePerUnit,
                    'total_price' => $totalPrice,
                    'description' => $description
                ];
                
                if ($action === 'add') {
                    $approvalId = createPendingApproval('add', 'inventory', null, $requestData);
                    if ($approvalId) {
                        $message = 'Your request has been submitted and is pending approval from Super Admin.';
                        $messageType = 'info';
                    } else {
                        $message = 'Error submitting approval request.';
                        $messageType = 'danger';
                    }
                } else {
                    $id = intval($_POST['id'] ?? 0);
                    $approvalId = createPendingApproval('update', 'inventory', $id, $requestData);
                    if ($approvalId) {
                        $message = 'Your update request has been submitted and is pending approval from Super Admin.';
                        $messageType = 'info';
                    } else {
                        $message = 'Error submitting approval request.';
                        $messageType = 'danger';
                    }
                }
            } else {
                // Super admin - execute directly
                if ($action === 'add') {
                    $stmt = $conn->prepare("INSERT INTO inventory (item_name, category_id, type, brand, serial_number, item_condition, status, total_quantity, available_quantity, price_per_unit, total_price, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $availableQuantity = $totalQuantity;
                    $stmt->bind_param("sisssssiidds", $itemName, $categoryId, $type, $brand, $serialNumber, $itemCondition, $status, $totalQuantity, $availableQuantity, $pricePerUnit, $totalPrice, $description);
                    
                    if ($stmt->execute()) {
                        $inventoryId = $conn->insert_id;
                        logAudit('create', 'inventory', $inventoryId, null, ['item_name' => $itemName, 'total_quantity' => $totalQuantity]);
                        $message = 'Inventory item added successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Error adding inventory item: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $stmt->close();
                } else {
                    $id = intval($_POST['id'] ?? 0);
                    $oldValues = [];
                    $stmt = $conn->prepare("SELECT * FROM inventory WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $oldValues = $result->fetch_assoc();
                    }
                    $stmt->close();
                    
                    // Get current assigned quantity from assignment_items
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(ai.quantity), 0) as assigned 
                        FROM assignment_items ai
                        INNER JOIN assignments a ON ai.assignment_id = a.id
                        WHERE ai.inventory_id = ? AND a.status = 'active'
                    ");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $assignedQuantity = $row['assigned'] ?? 0;
                    $stmt->close();
                    
                    // Also check old assignments with direct inventory_id (backward compatibility)
                    $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) as assigned FROM assignments WHERE inventory_id = ? AND status = 'active'");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $assignedQuantity += $row['assigned'] ?? 0;
                    $stmt->close();
                    
                    // Calculate new available quantity - ensure all are properly typed
                    $assignedQuantity = (int)($assignedQuantity ?? 0);
                    $newAvailableQuantity = max(0, (int)$totalQuantity - $assignedQuantity);
                    
                    // Ensure all variables are properly set
                    $itemName = (string)$itemName;
                    $categoryId = (int)$categoryId;
                    $type = (string)$type;
                    $brand = (string)$brand;
                    $serialNumber = (string)$serialNumber;
                    $itemCondition = (string)$itemCondition;
                    $status = (string)$status;
                    $totalQuantity = (int)$totalQuantity;
                    $pricePerUnit = (float)$pricePerUnit;
                    $totalPrice = (float)$totalPrice;
                    $description = (string)$description;
                    $id = (int)$id;
                    
                    // Prepare UPDATE statement - count placeholders carefully
                    // SQL has 14 ? placeholders:
                    // 1.item_name 2.category_id 3.type 4.brand 5.serial_number 6.item_condition 7.status 
                    // 8.total_quantity 9.assigned_quantity 10.available_quantity 11.price_per_unit 12.total_price 13.description 14.id
                    $updateSql = "UPDATE inventory SET item_name = ?, category_id = ?, type = ?, brand = ?, serial_number = ?, item_condition = ?, status = ?, total_quantity = ?, assigned_quantity = ?, available_quantity = ?, price_per_unit = ?, total_price = ?, description = ? WHERE id = ?";
                    $stmt = $conn->prepare($updateSql);
                    
                    // Bind parameters: 14 parameters total
                    // Type string: s=string, i=integer, d=double
                    // Parameters in order: item_name(s), category_id(i), type(s), brand(s), serial_number(s), item_condition(s), status(s), total_quantity(i), assigned_quantity(i), available_quantity(i), price_per_unit(d), total_price(d), description(s), id(i)
                    // Build type string character by character to ensure accuracy
                    $paramTypes = "s";  // item_name
                    $paramTypes .= "i"; // category_id
                    $paramTypes .= "s"; // type
                    $paramTypes .= "s"; // brand
                    $paramTypes .= "s"; // serial_number
                    $paramTypes .= "s"; // item_condition
                    $paramTypes .= "s"; // status
                    $paramTypes .= "i"; // total_quantity
                    $paramTypes .= "i"; // assigned_quantity
                    $paramTypes .= "i"; // available_quantity
                    $paramTypes .= "d"; // price_per_unit
                    $paramTypes .= "d"; // total_price
                    $paramTypes .= "s"; // description
                    $paramTypes .= "i"; // id
                    // $paramTypes should be "sisssssiiiiddsi" (14 characters)
                    $stmt->bind_param($paramTypes, $itemName, $categoryId, $type, $brand, $serialNumber, $itemCondition, $status, $totalQuantity, $assignedQuantity, $newAvailableQuantity, $pricePerUnit, $totalPrice, $description, $id);
                    
                    if ($stmt->execute()) {
                        $newValues = ['item_name' => $itemName, 'total_quantity' => $totalQuantity, 'status' => $status];
                        logAudit('update', 'inventory', $id, $oldValues, $newValues);
                        $message = 'Inventory item updated successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Error updating inventory item: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        // Check if user is super admin
        if (!isSuperAdmin()) {
            // Create pending approval for delete
            $requestData = ['id' => $id];
            $approvalId = createPendingApproval('delete', 'inventory', $id, $requestData);
            if ($approvalId) {
                $message = 'Your delete request has been submitted and is pending approval from Super Admin.';
                $messageType = 'info';
            } else {
                $message = 'Error submitting approval request.';
                $messageType = 'danger';
            }
        } else {
            // Super admin - execute directly
            // Check if item has active assignments (via assignment_items)
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM assignment_items ai
                INNER JOIN assignments a ON ai.assignment_id = a.id
                WHERE ai.inventory_id = ? AND a.status = 'active'
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $activeAssignments = $row['count'] ?? 0;
            $stmt->close();
            
            // Also check old assignments with direct inventory_id (backward compatibility)
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignments WHERE inventory_id = ? AND status = 'active'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $activeAssignments += $row['count'] ?? 0;
            $stmt->close();
            
            if ($activeAssignments > 0) {
                $message = 'Cannot delete item with active assignments. Please return all items first.';
                $messageType = 'danger';
            } else {
                // Start transaction for data integrity
            $conn->begin_transaction();
            
            try {
                // Get old values for audit log
                $oldValues = [];
                $stmt = $conn->prepare("SELECT * FROM inventory WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $oldValues = $result->fetch_assoc();
                }
                $stmt->close();
                
                // Delete assignment_items for this inventory item
                $stmt = $conn->prepare("
                    DELETE ai FROM assignment_items ai
                    INNER JOIN assignments a ON ai.assignment_id = a.id
                    WHERE ai.inventory_id = ? AND a.status != 'active'
                ");
                $stmt->bind_param("i", $id);
                if (!$stmt->execute()) {
                    throw new Exception('Error deleting assignment items: ' . $conn->error);
                }
                $stmt->close();
                
                // Set inventory_id to NULL in assignments table (for non-active assignments, backward compatibility)
                $stmt = $conn->prepare("UPDATE assignments SET inventory_id = NULL WHERE inventory_id = ?");
                $stmt->bind_param("i", $id);
                if (!$stmt->execute()) {
                    throw new Exception('Error updating assignments: ' . $conn->error);
                }
                $stmt->close();
                
                // Set inventory_id to NULL in assignment_history to preserve history but allow deletion
                $stmt = $conn->prepare("UPDATE assignment_history SET inventory_id = NULL WHERE inventory_id = ?");
                $stmt->bind_param("i", $id);
                if (!$stmt->execute()) {
                    throw new Exception('Error updating assignment history: ' . $conn->error);
                }
                $stmt->close();
                
                // Delete the inventory item
                $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $conn->commit();
                    logAudit('delete', 'inventory', $id, $oldValues, null);
                    $message = 'Inventory item deleted successfully. Assignment history has been preserved.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Error deleting inventory item: ' . $conn->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage() . ' Please run the SQL script: database/fix_foreign_key_for_deletion.sql to allow deletion with history.';
                $messageType = 'danger';
            }
            }
        }
    }
}

// Get filter parameters
$search = sanitizeInput($_GET['search'] ?? '');
$categoryFilter = intval($_GET['category'] ?? 0);
$typeFilter = sanitizeInput($_GET['type'] ?? '');
$statusFilter = sanitizeInput($_GET['status'] ?? '');

// Build query
$where = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(item_name LIKE ? OR brand LIKE ? OR serial_number LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if ($categoryFilter > 0) {
    $where[] = "category_id = ?";
    $params[] = $categoryFilter;
    $types .= "i";
}

if (!empty($typeFilter)) {
    $where[] = "type = ?";
    $params[] = $typeFilter;
    $types .= "s";
}

if (!empty($statusFilter)) {
    $where[] = "status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$whereClause = implode(" AND ", $where);

// Get categories for filter
$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Get inventory items
$query = "SELECT i.*, c.name as category_name FROM inventory i LEFT JOIN categories c ON i.category_id = c.id WHERE $whereClause ORDER BY i.created_at DESC";
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-boxes"></i> Inventory Management</h2>
    </div>
</div>

<?php 
if ($message) {
    require_once 'includes/sweetalert.php';
    showSweetAlert($messageType, $message);
}
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Inventory Items</h5>
        <div class="d-flex gap-2">
            <a href="?export=csv<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo $categoryFilter > 0 ? '&category=' . $categoryFilter : ''; ?><?php echo !empty($typeFilter) ? '&type=' . urlencode($typeFilter) : ''; ?><?php echo !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : ''; ?>" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
            </a>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="bi bi-plus-circle"></i> Add Item
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-3 mb-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search items..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="category">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $categoryFilter == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="type">
                    <option value="">All Types</option>
                    <option value="asset" <?php echo $typeFilter === 'asset' ? 'selected' : ''; ?>>Asset</option>
                    <option value="furniture" <?php echo $typeFilter === 'furniture' ? 'selected' : ''; ?>>Furniture</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="available" <?php echo $statusFilter === 'available' ? 'selected' : ''; ?>>Available</option>
                    <option value="assigned" <?php echo $statusFilter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                    <option value="damaged" <?php echo $statusFilter === 'damaged' ? 'selected' : ''; ?>>Damaged</option>
                    <option value="retired" <?php echo $statusFilter === 'retired' ? 'selected' : ''; ?>>Retired</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="inventory.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>
            </div>
        </form>
        
        <!-- Inventory Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Brand</th>
                        <th>Serial #</th>
                        <th>Condition</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Assigned</th>
                        <th>Available</th>
                        <th>Price/Unit</th>
                        <th>Total Price</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                            <td><span class="badge bg-<?php echo $item['type'] === 'asset' ? 'primary' : 'info'; ?>"><?php echo ucfirst($item['type']); ?></span></td>
                            <td><?php echo htmlspecialchars($item['brand'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($item['serial_number'] ?: '-'); ?></td>
                            <td><span class="badge bg-<?php echo getConditionBadge($item['item_condition']); ?>"><?php echo ucfirst($item['item_condition']); ?></span></td>
                            <td><span class="badge bg-<?php echo getStatusBadge($item['status']); ?>"><?php echo ucfirst($item['status']); ?></span></td>
                            <td><?php echo $item['total_quantity']; ?></td>
                            <td><span class="badge bg-info"><?php echo $item['assigned_quantity']; ?></span></td>
                            <td><span class="badge bg-<?php echo $item['available_quantity'] > 0 ? 'success' : 'danger'; ?>"><?php echo $item['available_quantity']; ?></span></td>
                            <td>
                                <?php if (!empty($item['price_per_unit']) && $item['price_per_unit'] > 0): ?>
                                    <span class="text-success fw-semibold">PKR <?php echo number_format($item['price_per_unit'], 2); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['total_price']) && $item['total_price'] > 0): ?>
                                    <span class="text-primary fw-bold">PKR <?php echo number_format($item['total_price'], 2); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" style="display:inline;" data-confirm-delete>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="13" class="text-center">No inventory items found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Inventory Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="itemForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="itemId">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Item Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="item_name" id="itemName" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="category_id" id="categoryId" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" id="type" required>
                                <option value="">Select Type</option>
                                <option value="asset">Asset</option>
                                <option value="furniture">Furniture</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Brand</label>
                            <input type="text" class="form-control" name="brand" id="brand">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Serial Number</label>
                            <input type="text" class="form-control" name="serial_number" id="serialNumber">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Condition</label>
                            <select class="form-select" name="condition" id="condition">
                                <option value="excellent">Excellent</option>
                                <option value="good" selected>Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                                <option value="damaged">Damaged</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="status">
                                <option value="available" selected>Available</option>
                                <option value="assigned">Assigned</option>
                                <option value="damaged">Damaged</option>
                                <option value="retired">Retired</option>
                                <option value="lost">Lost</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Quantity <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="total_quantity" id="totalQuantity" min="0" step="1" oninput="calculateTotalPrice()" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Price Per Unit</label>
                            <div class="input-group">
                                <span class="input-group-text">PKR</span>
                                <input type="number" class="form-control" name="price_per_unit" id="pricePerUnit" min="0" step="0.01" oninput="calculateTotalPrice()" placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Price</label>
                            <div class="input-group">
                                <span class="input-group-text">PKR</span>
                                <input type="text" class="form-control" id="totalPrice" readonly style="background-color: #e9ecef; font-weight: 600;">
                            </div>
                            <small class="form-text text-muted">Calculated automatically: Price Per Unit × Quantity</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Calculate total price based on price per unit and quantity
function calculateTotalPrice() {
    const pricePerUnit = parseFloat(document.getElementById('pricePerUnit').value) || 0;
    const quantity = parseFloat(document.getElementById('totalQuantity').value) || 0;
    const totalPrice = pricePerUnit * quantity;
    
    document.getElementById('totalPrice').value = totalPrice.toFixed(2);
}

function editItem(item) {
    document.getElementById('modalTitle').textContent = 'Edit Inventory Item';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('itemId').value = item.id;
    document.getElementById('itemName').value = item.item_name;
    document.getElementById('categoryId').value = item.category_id;
    document.getElementById('type').value = item.type;
    document.getElementById('brand').value = item.brand || '';
    document.getElementById('serialNumber').value = item.serial_number || '';
    document.getElementById('condition').value = item.item_condition;
    document.getElementById('status').value = item.status;
    document.getElementById('totalQuantity').value = item.total_quantity;
    document.getElementById('pricePerUnit').value = item.price_per_unit || '';
    document.getElementById('description').value = item.description || '';
    
    // Calculate total price for edit mode
    calculateTotalPrice();
    
    const modal = new bootstrap.Modal(document.getElementById('addItemModal'));
    modal.show();
}

// Reset form when modal is closed
document.getElementById('addItemModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('itemForm').reset();
    document.getElementById('modalTitle').textContent = 'Add Inventory Item';
    document.getElementById('formAction').value = 'add';
    document.getElementById('itemId').value = '';
    document.getElementById('totalPrice').value = '';
});
</script>

<?php require_once 'includes/footer.php'; ?>

