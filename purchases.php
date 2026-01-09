<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireAnyRole([ROLE_SUPER_ADMIN, ROLE_IT_ADMIN]);

$pageTitle = 'Purchases';
require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $name = sanitizeInput($_POST['name'] ?? '');
        $title = sanitizeInput($_POST['title'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');
        $purchaseCategoryId = intval($_POST['purchase_category_id'] ?? 0);
        $purchaseDate = sanitizeInput($_POST['purchase_date'] ?? date('Y-m-d'));
        $expiryDate = sanitizeInput($_POST['expiry_date'] ?? '');
        $quantity = intval($_POST['quantity'] ?? 1);
        $unitPrice = floatval($_POST['unit_price'] ?? 0);
        $totalAmount = floatval($_POST['total_amount'] ?? 0);
        $supplier = sanitizeInput($_POST['supplier'] ?? '');
        $invoiceNumber = sanitizeInput($_POST['invoice_number'] ?? '');
        $status = sanitizeInput($_POST['status'] ?? 'active');
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
        // Calculate total if not provided
        if ($totalAmount == 0 && $quantity > 0 && $unitPrice > 0) {
            $totalAmount = $quantity * $unitPrice;
        }
        
        if (empty($name) || empty($purchaseDate)) {
            $message = 'Please fill in all required fields.';
            $messageType = 'danger';
        } else {
            $createdBy = $_SESSION['user_id'];
            
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO purchases (name, title, description, purchase_category_id, purchase_date, expiry_date, quantity, unit_price, total_amount, supplier, invoice_number, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssisssiddsssi", $name, $title, $description, $purchaseCategoryId, $purchaseDate, $expiryDate, $quantity, $unitPrice, $totalAmount, $supplier, $invoiceNumber, $status, $notes, $createdBy);
                
                if ($stmt->execute()) {
                    $purchaseId = $conn->insert_id;
                    logAudit('create', 'purchases', $purchaseId, null, ['name' => $name, 'total_amount' => $totalAmount]);
                    $message = 'Purchase added successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Error adding purchase: ' . $conn->error;
                    $messageType = 'danger';
                }
                $stmt->close();
            } else {
                $id = intval($_POST['id'] ?? 0);
                $oldValues = [];
                $stmt = $conn->prepare("SELECT * FROM purchases WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $oldValues = $result->fetch_assoc();
                }
                $stmt->close();
                
                // Ensure all variables are properly typed before binding
                $name = (string)($name ?? '');
                $title = (string)($title ?? '');
                $description = (string)($description ?? '');
                $purchaseCategoryId = (int)($purchaseCategoryId ?? 0);
                $purchaseDate = (string)($purchaseDate ?? date('Y-m-d'));
                $expiryDate = (string)($expiryDate ?? '');
                $quantity = (int)($quantity ?? 1);
                $unitPrice = (float)($unitPrice ?? 0);
                $totalAmount = (float)($totalAmount ?? 0);
                $supplier = (string)($supplier ?? '');
                $invoiceNumber = (string)($invoiceNumber ?? '');
                $status = (string)($status ?? 'active');
                $notes = (string)($notes ?? '');
                $id = (int)($id ?? 0);
                
                // Prepare UPDATE statement - 14 placeholders
                $stmt = $conn->prepare("UPDATE purchases SET name = ?, title = ?, description = ?, purchase_category_id = ?, purchase_date = ?, expiry_date = ?, quantity = ?, unit_price = ?, total_amount = ?, supplier = ?, invoice_number = ?, status = ?, notes = ? WHERE id = ?");
                
                // Bind parameters: 14 parameters total
                // Type string: s=string, i=integer, d=double
                // Order: name(s), title(s), description(s), purchase_category_id(i), purchase_date(s), expiry_date(s), quantity(i), unit_price(d), total_amount(d), supplier(s), invoice_number(s), status(s), notes(s), id(i)
                $paramTypes = "s";  // name
                $paramTypes .= "s"; // title
                $paramTypes .= "s"; // description
                $paramTypes .= "i"; // purchase_category_id
                $paramTypes .= "s"; // purchase_date
                $paramTypes .= "s"; // expiry_date
                $paramTypes .= "i"; // quantity
                $paramTypes .= "d"; // unit_price
                $paramTypes .= "d"; // total_amount
                $paramTypes .= "s"; // supplier
                $paramTypes .= "s"; // invoice_number
                $paramTypes .= "s"; // status
                $paramTypes .= "s"; // notes
                $paramTypes .= "i"; // id
                // $paramTypes = "sssisiddssssi" (14 characters)
                
                $stmt->bind_param($paramTypes, $name, $title, $description, $purchaseCategoryId, $purchaseDate, $expiryDate, $quantity, $unitPrice, $totalAmount, $supplier, $invoiceNumber, $status, $notes, $id);
                
                if ($stmt->execute()) {
                    $newValues = ['name' => $name, 'total_amount' => $totalAmount, 'status' => $status];
                    logAudit('update', 'purchases', $id, $oldValues, $newValues);
                    $message = 'Purchase updated successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Error updating purchase: ' . $conn->error;
                    $messageType = 'danger';
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        $oldValues = [];
        $stmt = $conn->prepare("SELECT * FROM purchases WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $oldValues = $result->fetch_assoc();
        }
        $stmt->close();
        
        $stmt = $conn->prepare("DELETE FROM purchases WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logAudit('delete', 'purchases', $id, $oldValues, null);
            $message = 'Purchase deleted successfully.';
            $messageType = 'success';
        } else {
            $message = 'Error deleting purchase: ' . $conn->error;
            $messageType = 'danger';
        }
        $stmt->close();
    }
}

// Get filter parameters
$search = sanitizeInput($_GET['search'] ?? '');
$categoryFilter = intval($_GET['category'] ?? 0);
$statusFilter = sanitizeInput($_GET['status'] ?? '');
$dateFrom = sanitizeInput($_GET['date_from'] ?? '');
$dateTo = sanitizeInput($_GET['date_to'] ?? '');

// Build query
$where = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(p.name LIKE ? OR p.title LIKE ? OR p.supplier LIKE ? OR p.invoice_number LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ssss";
}

if ($categoryFilter > 0) {
    $where[] = "p.purchase_category_id = ?";
    $params[] = $categoryFilter;
    $types .= "i";
}

if (!empty($statusFilter)) {
    $where[] = "p.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if (!empty($dateFrom)) {
    $where[] = "p.purchase_date >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if (!empty($dateTo)) {
    $where[] = "p.purchase_date <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$whereClause = implode(" AND ", $where);

// Get pagination parameters
$pagination = getPaginationParams(10);
$page = $pagination['page'];
$offset = $pagination['offset'];
$limit = $pagination['limit'];

// Get purchase categories for filter
$purchaseCategories = $conn->query("SELECT * FROM purchase_categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total 
          FROM purchases p 
          LEFT JOIN purchase_categories pc ON p.purchase_category_id = pc.id
          LEFT JOIN users u ON p.created_by = u.id
          WHERE $whereClause";
$countStmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalPurchases = $countResult->fetch_assoc()['total'];
$countStmt->close();
$totalPages = max(1, ceil($totalPurchases / $limit));

// Get purchases with pagination
$query = "SELECT p.*, pc.name as category_name, u.full_name as created_by_name 
          FROM purchases p 
          LEFT JOIN purchase_categories pc ON p.purchase_category_id = pc.id
          LEFT JOIN users u ON p.created_by = u.id
          WHERE $whereClause 
          ORDER BY p.purchase_date DESC, p.created_at DESC
          LIMIT ? OFFSET ?";
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
$purchases = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-cart-plus"></i> Purchases Management</h2>
    </div>
</div>

<?php 
if ($message) {
    showSweetAlert($messageType, $message);
}
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Purchases</h5>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addPurchaseModal">
            <i class="bi bi-plus-circle"></i> Add Purchase
        </button>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-3 mb-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search purchases..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="category">
                    <option value="0">All Categories</option>
                    <?php foreach ($purchaseCategories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $categoryFilter == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    <option value="consumed" <?php echo $statusFilter === 'consumed' ? 'selected' : ''; ?>>Consumed</option>
                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" placeholder="From Date">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" placeholder="To Date">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                <a href="purchases.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
        
        <!-- Purchases Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Purchase Date</th>
                        <th>Expiry Date</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total Amount</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($purchases) > 0): ?>
                        <?php foreach ($purchases as $purchase): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($purchase['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($purchase['title'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($purchase['category_name'] ?: '-'); ?></td>
                            <td><?php echo formatDate($purchase['purchase_date']); ?></td>
                            <td>
                                <?php 
                                if ($purchase['expiry_date']) {
                                    $expiryDate = strtotime($purchase['expiry_date']);
                                    $today = strtotime('today');
                                    $class = $expiryDate < $today ? 'danger' : ($expiryDate < strtotime('+30 days') ? 'warning' : 'success');
                                    echo '<span class="badge bg-' . $class . '">' . formatDate($purchase['expiry_date']) . '</span>';
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td><?php echo $purchase['quantity']; ?></td>
                            <td><?php echo number_format($purchase['unit_price'], 2); ?></td>
                            <td><strong><?php echo number_format($purchase['total_amount'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($purchase['supplier'] ?: '-'); ?></td>
                            <td><span class="badge bg-<?php echo getStatusBadge($purchase['status']); ?>"><?php echo ucfirst($purchase['status']); ?></span></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="viewPurchase(<?php echo htmlspecialchars(json_encode($purchase)); ?>)">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-info" onclick="editPurchase(<?php echo htmlspecialchars(json_encode($purchase)); ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" style="display:inline;" data-confirm-delete data-message="Are you sure you want to delete this purchase?">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $purchase['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="text-center">No purchases found.</td>
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
                            Showing <?php echo count($purchases); ?> of <?php echo $totalPurchases; ?> purchases (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)
                        </small>
                    </div>
                    <?php 
                    echo renderPagination($page, $totalPages, 'purchases.php', [
                        'search' => $search,
                        'category' => $categoryFilter > 0 ? $categoryFilter : '',
                        'status' => $statusFilter,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Purchase Modal -->
<div class="modal fade" id="addPurchaseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Purchase</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="purchaseForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="purchaseId">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" id="title">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="description" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="purchase_category_id" id="purchaseCategoryId">
                                <option value="0">Select Category</option>
                                <?php foreach ($purchaseCategories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="status">
                                <option value="active" selected>Active</option>
                                <option value="expired">Expired</option>
                                <option value="consumed">Consumed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Purchase Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="purchase_date" id="purchaseDate" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" name="expiry_date" id="expiryDate">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" class="form-control" name="quantity" id="quantity" min="1" value="1" onchange="calculateTotal()">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit Price</label>
                            <input type="number" class="form-control" name="unit_price" id="unitPrice" step="0.01" min="0" value="0" onchange="calculateTotal()">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Total Amount</label>
                            <input type="number" class="form-control" name="total_amount" id="totalAmount" step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Supplier</label>
                            <input type="text" class="form-control" name="supplier" id="supplier">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Invoice Number</label>
                            <input type="text" class="form-control" name="invoice_number" id="invoiceNumber">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Purchase</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Purchase Modal -->
<div class="modal fade" id="viewPurchaseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Purchase Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewPurchaseContent">
            </div>
        </div>
    </div>
</div>

<script>
function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unitPrice = parseFloat(document.getElementById('unitPrice').value) || 0;
    const total = quantity * unitPrice;
    document.getElementById('totalAmount').value = total.toFixed(2);
}

function editPurchase(purchase) {
    document.getElementById('modalTitle').textContent = 'Edit Purchase';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('purchaseId').value = purchase.id;
    document.getElementById('name').value = purchase.name;
    document.getElementById('title').value = purchase.title || '';
    document.getElementById('description').value = purchase.description || '';
    document.getElementById('purchaseCategoryId').value = purchase.purchase_category_id || 0;
    document.getElementById('purchaseDate').value = purchase.purchase_date;
    document.getElementById('expiryDate').value = purchase.expiry_date || '';
    document.getElementById('quantity').value = purchase.quantity;
    document.getElementById('unitPrice').value = purchase.unit_price;
    document.getElementById('totalAmount').value = purchase.total_amount;
    document.getElementById('supplier').value = purchase.supplier || '';
    document.getElementById('invoiceNumber').value = purchase.invoice_number || '';
    document.getElementById('status').value = purchase.status;
    document.getElementById('notes').value = purchase.notes || '';
    
    const modal = new bootstrap.Modal(document.getElementById('addPurchaseModal'));
    modal.show();
}

function viewPurchase(purchase) {
    let content = '<div class="row mb-3"><div class="col-md-6"><strong>Name:</strong><br>' + purchase.name + '</div>';
    content += '<div class="col-md-6"><strong>Title:</strong><br>' + (purchase.title || '-') + '</div></div>';
    content += '<div class="mb-3"><strong>Description:</strong><br>' + (purchase.description || '-') + '</div>';
    content += '<div class="row mb-3"><div class="col-md-4"><strong>Category:</strong><br>' + (purchase.category_name || '-') + '</div>';
    content += '<div class="col-md-4"><strong>Purchase Date:</strong><br>' + purchase.purchase_date + '</div>';
    content += '<div class="col-md-4"><strong>Expiry Date:</strong><br>' + (purchase.expiry_date || '-') + '</div></div>';
    content += '<div class="row mb-3"><div class="col-md-4"><strong>Quantity:</strong><br>' + purchase.quantity + '</div>';
    content += '<div class="col-md-4"><strong>Unit Price:</strong><br>' + parseFloat(purchase.unit_price).toFixed(2) + '</div>';
    content += '<div class="col-md-4"><strong>Total Amount:</strong><br><strong>' + parseFloat(purchase.total_amount).toFixed(2) + '</strong></div></div>';
    content += '<div class="row mb-3"><div class="col-md-6"><strong>Supplier:</strong><br>' + (purchase.supplier || '-') + '</div>';
    content += '<div class="col-md-6"><strong>Invoice Number:</strong><br>' + (purchase.invoice_number || '-') + '</div></div>';
    content += '<div class="mb-3"><strong>Status:</strong><br><span class="badge bg-' + (purchase.status === 'active' ? 'success' : 'warning') + '">' + purchase.status + '</span></div>';
    content += '<div class="mb-3"><strong>Notes:</strong><br>' + (purchase.notes || '-') + '</div>';
    
    document.getElementById('viewPurchaseContent').innerHTML = content;
    const modal = new bootstrap.Modal(document.getElementById('viewPurchaseModal'));
    modal.show();
}

// Reset form when modal is closed
document.getElementById('addPurchaseModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('purchaseForm').reset();
    document.getElementById('modalTitle').textContent = 'Add Purchase';
    document.getElementById('formAction').value = 'add';
    document.getElementById('purchaseId').value = '';
    document.getElementById('purchaseDate').value = '<?php echo date('Y-m-d'); ?>';
});
</script>

<?php require_once 'includes/footer.php'; ?>

