<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireLogin();

$assignmentId = intval($_GET['assignment_id'] ?? 0);

if ($assignmentId <= 0) {
    echo json_encode(['error' => 'Invalid assignment ID.']);
    exit;
}

$conn = getDBConnection();

// Get assignment details
$stmt = $conn->prepare("
    SELECT a.*, e.full_name as employee_name, e.employee_id, e.department, e.position, u.full_name as assigned_by_name
    FROM assignments a
    INNER JOIN employees e ON a.employee_id = e.id
    INNER JOIN users u ON a.assigned_by = u.id
    WHERE a.id = ?
");
$stmt->bind_param("i", $assignmentId);
$stmt->execute();
$result = $stmt->get_result();
$assignment = $result->fetch_assoc();
$stmt->close();

// Get assignment items with full details including available quantity
$stmt = $conn->prepare("
    SELECT ai.*, i.item_name, i.type as item_type, i.brand, i.serial_number, i.item_condition, 
           i.price_per_unit, i.total_price, i.description as item_description, c.name as category_name,
           i.available_quantity
    FROM assignment_items ai
    INNER JOIN inventory i ON ai.inventory_id = i.id
    LEFT JOIN categories c ON i.category_id = c.id
    WHERE ai.assignment_id = ?
    ORDER BY i.item_name
");
$stmt->bind_param("i", $assignmentId);
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate available quantity for edit (current available + current assigned quantity for this item)
foreach ($items as &$item) {
    $item['available_for_edit'] = $item['available_quantity'] + $item['quantity'];
}
unset($item);

header('Content-Type: application/json');
echo json_encode([
    'assignment' => $assignment,
    'items' => $items
]);

