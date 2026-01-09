<?php
/**
 * Helper Functions
 * BI Communications Inventory Management System
 */

/**
 * Log audit trail
 */
function logAudit($action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $conn = getDBConnection();
    if (!$conn) {
        return false;
    }
    
    $userId = $_SESSION['user_id'];
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $oldValuesJson = $oldValues ? json_encode($oldValues) : null;
    $newValuesJson = $newValues ? json_encode($newValues) : null;
    
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississss", $userId, $action, $tableName, $recordId, $oldValuesJson, $newValuesJson, $ipAddress, $userAgent);
    
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Update inventory stock quantities
 */
function updateInventoryStock($inventoryId) {
    $conn = getDBConnection();
    if (!$conn) {
        return false;
    }
    
    // Calculate assigned quantity from active assignments via assignment_items
    // Also include old assignments that have inventory_id directly (for backward compatibility)
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(ai.quantity), 0) as assigned 
        FROM assignment_items ai
        INNER JOIN assignments a ON ai.assignment_id = a.id
        WHERE ai.inventory_id = ? AND a.status = 'active'
    ");
    $stmt->bind_param("i", $inventoryId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $assignedQuantity = $row['assigned'] ?? 0;
    $stmt->close();
    
    // Also check old assignments with direct inventory_id (for backward compatibility)
    $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) as assigned FROM assignments WHERE inventory_id = ? AND status = 'active'");
    $stmt->bind_param("i", $inventoryId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $assignedQuantity += $row['assigned'] ?? 0;
    $stmt->close();
    
    // Get total quantity
    $stmt = $conn->prepare("SELECT total_quantity FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $inventoryId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $totalQuantity = $row['total_quantity'] ?? 0;
    $stmt->close();
    
    // Calculate available quantity
    $availableQuantity = max(0, $totalQuantity - $assignedQuantity);
    
    // Update inventory
    $stmt = $conn->prepare("UPDATE inventory SET assigned_quantity = ?, available_quantity = ? WHERE id = ?");
    $stmt->bind_param("iii", $assignedQuantity, $availableQuantity, $inventoryId);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Get dashboard statistics
 */
function getDashboardStats() {
    $conn = getDBConnection();
    if (!$conn) {
        return null;
    }
    
    $stats = [];
    
    // Total stock
    $result = $conn->query("SELECT SUM(total_quantity) as total FROM inventory");
    $row = $result->fetch_assoc();
    $stats['total_stock'] = $row['total'] ?? 0;
    
    // Assigned stock
    $result = $conn->query("SELECT SUM(assigned_quantity) as assigned FROM inventory");
    $row = $result->fetch_assoc();
    $stats['assigned_stock'] = $row['assigned'] ?? 0;
    
    // Available stock
    $result = $conn->query("SELECT SUM(available_quantity) as available FROM inventory");
    $row = $result->fetch_assoc();
    $stats['available_stock'] = $row['available'] ?? 0;
    
    // IT Assets vs Furniture
    $result = $conn->query("SELECT type, SUM(total_quantity) as total FROM inventory GROUP BY type");
    $stats['by_type'] = [];
    while ($row = $result->fetch_assoc()) {
        $stats['by_type'][$row['type']] = $row['total'];
    }
    
    // Total items (unique inventory items)
    $result = $conn->query("SELECT COUNT(*) as count FROM inventory");
    $row = $result->fetch_assoc();
    $stats['total_items'] = $row['count'] ?? 0;
    
    // Total employees
    $result = $conn->query("SELECT COUNT(*) as count FROM employees WHERE status = 'active'");
    $row = $result->fetch_assoc();
    $stats['total_employees'] = $row['count'] ?? 0;
    
    // Active assignments
    $result = $conn->query("SELECT COUNT(*) as count FROM assignments WHERE status = 'active'");
    $row = $result->fetch_assoc();
    $stats['active_assignments'] = $row['count'] ?? 0;
    
    return $stats;
}

/**
 * Get condition badge class
 */
function getConditionBadge($condition) {
    $badges = [
        'excellent' => 'success',
        'good' => 'primary',
        'fair' => 'warning',
        'poor' => 'danger',
        'damaged' => 'dark'
    ];
    
    return $badges[$condition] ?? 'secondary';
}

/**
 * Get status badge class
 */
function getStatusBadge($status) {
    $badges = [
        'available' => 'success',
        'assigned' => 'info',
        'damaged' => 'danger',
        'retired' => 'secondary',
        'lost' => 'dark',
        'active' => 'success',
        'returned' => 'secondary',
        'inactive' => 'secondary',
        'terminated' => 'danger'
    ];
    
    return $badges[$status] ?? 'secondary';
}

/**
 * Create a pending approval request
 */
function createPendingApproval($requestType, $tableName, $recordId, $requestData) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $conn = getDBConnection();
    if (!$conn) {
        return false;
    }
    
    $requestedBy = $_SESSION['user_id'];
    $requestDataJson = json_encode($requestData);
    
    $stmt = $conn->prepare("INSERT INTO approvals (request_type, table_name, record_id, request_data, requested_by, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("ssisi", $requestType, $tableName, $recordId, $requestDataJson, $requestedBy);
    
    $result = $stmt->execute();
    $approvalId = $conn->insert_id;
    $stmt->close();
    
    return $result ? $approvalId : false;
}

/**
 * Get count of pending approvals
 */
function getPendingApprovalsCount() {
    $conn = getDBConnection();
    if (!$conn) {
        return 0;
    }
    
    $result = $conn->query("SELECT COUNT(*) as count FROM approvals WHERE status = 'pending'");
    $row = $result->fetch_assoc();
    
    return $row['count'] ?? 0;
}

/**
 * Check if current user is super admin
 */
function isSuperAdmin() {
    if (!isLoggedIn()) {
        return false;
    }
    
    return ($_SESSION['role'] ?? '') === ROLE_SUPER_ADMIN;
}

/**
 * Process approval (approve or reject)
 */
function processApproval($approvalId, $action, $reviewNotes = '') {
    if (!isLoggedIn() || !isSuperAdmin()) {
        return false;
    }
    
    $conn = getDBConnection();
    if (!$conn) {
        return false;
    }
    
    $reviewedBy = $_SESSION['user_id'];
    $status = $action === 'approve' ? 'approved' : 'rejected';
    
    // Get approval details
    $stmt = $conn->prepare("SELECT * FROM approvals WHERE id = ? AND status = 'pending'");
    $stmt->bind_param("i", $approvalId);
    $stmt->execute();
    $result = $stmt->get_result();
    $approval = $result->fetch_assoc();
    $stmt->close();
    
    if (!$approval) {
        return false;
    }
    
    // Update approval status
    $stmt = $conn->prepare("UPDATE approvals SET status = ?, reviewed_by = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?");
    $stmt->bind_param("sisi", $status, $reviewedBy, $reviewNotes, $approvalId);
    $stmt->execute();
    $stmt->close();
    
    // If approved, process the request
    if ($action === 'approve') {
        return processApprovedRequest($approval);
    }
    
    return true;
}

/**
 * Process an approved request
 */
function processApprovedRequest($approval) {
    $conn = getDBConnection();
    if (!$conn) {
        return false;
    }
    
    $requestData = json_decode($approval['request_data'], true);
    $tableName = $approval['table_name'];
    $requestType = $approval['request_type'];
    $recordId = $approval['record_id'];
    
    try {
        $conn->begin_transaction();
        
        if ($requestType === 'add') {
            // Process add request based on table
            if ($tableName === 'inventory') {
                $stmt = $conn->prepare("INSERT INTO inventory (item_name, category_id, type, brand, serial_number, item_condition, status, total_quantity, available_quantity, price_per_unit, total_price, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $availableQuantity = $requestData['total_quantity'];
                $pricePerUnit = $requestData['price_per_unit'] ?? 0;
                $totalPrice = $requestData['total_price'] ?? 0;
                $itemName = $requestData['item_name'];
                $categoryId = $requestData['category_id'];
                $type = $requestData['type'];
                $brand = $requestData['brand'] ?? '';
                $serialNumber = $requestData['serial_number'] ?? '';
                $condition = $requestData['condition'];
                $status = $requestData['status'];
                $totalQuantity = $requestData['total_quantity'];
                $description = $requestData['description'] ?? '';
                
                $stmt->bind_param("sisssssiidds", 
                    $itemName,
                    $categoryId,
                    $type,
                    $brand,
                    $serialNumber,
                    $condition,
                    $status,
                    $totalQuantity,
                    $availableQuantity,
                    $pricePerUnit,
                    $totalPrice,
                    $description
                );
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($requestType === 'update') {
            // Process update request
            if ($tableName === 'inventory') {
                // Get current assigned quantity
                $stmt = $conn->prepare("
                    SELECT COALESCE(SUM(ai.quantity), 0) as assigned 
                    FROM assignment_items ai
                    INNER JOIN assignments a ON ai.assignment_id = a.id
                    WHERE ai.inventory_id = ? AND a.status = 'active'
                ");
                $stmt->bind_param("i", $recordId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $assignedQuantity = $row['assigned'] ?? 0;
                $stmt->close();
                
                // Also check old assignments
                $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) as assigned FROM assignments WHERE inventory_id = ? AND status = 'active'");
                $stmt->bind_param("i", $recordId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $assignedQuantity += $row['assigned'] ?? 0;
                $stmt->close();
                
                // Ensure assigned quantity is properly typed
                $assignedQuantity = (int)($assignedQuantity ?? 0);
                $newAvailableQuantity = max(0, (int)$requestData['total_quantity'] - $assignedQuantity);
                
                // Ensure all variables are properly typed before binding
                $itemName = (string)($requestData['item_name'] ?? '');
                $categoryId = (int)($requestData['category_id'] ?? 0);
                $type = (string)($requestData['type'] ?? '');
                $brand = (string)($requestData['brand'] ?? '');
                $serialNumber = (string)($requestData['serial_number'] ?? '');
                $condition = (string)($requestData['condition'] ?? 'good');
                $status = (string)($requestData['status'] ?? 'available');
                $totalQuantity = (int)($requestData['total_quantity'] ?? 0);
                $pricePerUnit = (float)($requestData['price_per_unit'] ?? 0);
                $totalPrice = (float)($requestData['total_price'] ?? 0);
                $description = (string)($requestData['description'] ?? '');
                $recordId = (int)($recordId ?? 0);
                
                // Prepare UPDATE statement - 14 placeholders
                $stmt = $conn->prepare("UPDATE inventory SET item_name = ?, category_id = ?, type = ?, brand = ?, serial_number = ?, item_condition = ?, status = ?, total_quantity = ?, assigned_quantity = ?, available_quantity = ?, price_per_unit = ?, total_price = ?, description = ? WHERE id = ?");
                
                // Bind parameters: 14 parameters total
                // Type string: s=string, i=integer, d=double
                // Order: item_name(s), category_id(i), type(s), brand(s), serial_number(s), item_condition(s), status(s), total_quantity(i), assigned_quantity(i), available_quantity(i), price_per_unit(d), total_price(d), description(s), id(i)
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
                // $paramTypes = "sisssssiiiiddsi" (14 characters)
                
                $stmt->bind_param($paramTypes,
                    $itemName,
                    $categoryId,
                    $type,
                    $brand,
                    $serialNumber,
                    $condition,
                    $status,
                    $totalQuantity,
                    $assignedQuantity,
                    $newAvailableQuantity,
                    $pricePerUnit,
                    $totalPrice,
                    $description,
                    $recordId
                );
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($requestType === 'delete') {
            // Process delete request
            if ($tableName === 'inventory') {
                // Check if item has active assignments
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count 
                    FROM assignment_items ai
                    INNER JOIN assignments a ON ai.assignment_id = a.id
                    WHERE ai.inventory_id = ? AND a.status = 'active'
                ");
                $stmt->bind_param("i", $recordId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $activeAssignments = $row['count'] ?? 0;
                $stmt->close();
                
                if ($activeAssignments > 0) {
                    throw new Exception('Cannot delete item with active assignments.');
                }
                
                // Delete assignment_items
                $stmt = $conn->prepare("
                    DELETE ai FROM assignment_items ai
                    INNER JOIN assignments a ON ai.assignment_id = a.id
                    WHERE ai.inventory_id = ? AND a.status != 'active'
                ");
                $stmt->bind_param("i", $recordId);
                $stmt->execute();
                $stmt->close();
                
                // Delete the inventory item
                $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
                $stmt->bind_param("i", $recordId);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

/**
 * Get pagination parameters
 */
function getPaginationParams($itemsPerPage = 10) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($page - 1) * $itemsPerPage;
    return ['page' => $page, 'offset' => $offset, 'limit' => $itemsPerPage];
}

/**
 * Render pagination controls
 */
function renderPagination($currentPage, $totalPages, $baseUrl, $additionalParams = []) {
    if ($totalPages <= 1) {
        return '';
    }
    
    // Build query string for additional parameters
    $queryString = '';
    if (!empty($additionalParams)) {
        $queryParts = [];
        foreach ($additionalParams as $key => $value) {
            if ($value !== '' && $value !== null) {
                $queryParts[] = urlencode($key) . '=' . urlencode($value);
            }
        }
        if (!empty($queryParts)) {
            $queryString = '&' . implode('&', $queryParts);
        }
    }
    
    $html = '<nav aria-label="Page navigation">';
    $html .= '<ul class="pagination justify-content-center">';
    
    // Previous button
    if ($currentPage > 1) {
        $prevPage = $currentPage - 1;
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $prevPage . $queryString . '">Previous</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    // Page numbers
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=1' . $queryString . '">1</a></li>';
        if ($startPage > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $i . $queryString . '">' . $i . '</a></li>';
        }
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $totalPages . $queryString . '">' . $totalPages . '</a></li>';
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $nextPage = $currentPage + 1;
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $nextPage . $queryString . '">Next</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    $html .= '</ul>';
    $html .= '</nav>';
    
    return $html;
}

