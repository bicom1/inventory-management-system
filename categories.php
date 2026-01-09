<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireAnyRole([ROLE_SUPER_ADMIN, ROLE_IT_ADMIN]);

// Handle CSV export - must be before header output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $conn = getDBConnection();
    $categories = $conn->query("SELECT c.*, COUNT(i.id) as item_count FROM categories c LEFT JOIN inventory i ON c.id = i.category_id GROUP BY c.id ORDER BY c.name")->fetch_all(MYSQLI_ASSOC);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=categories_' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV headers
    fputcsv($output, [
        'ID', 'Name', 'Description', 'Item Count', 'Created At'
    ]);
    
    // CSV data
    foreach ($categories as $row) {
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['description'] ?? '',
            $row['item_count'] ?? 0,
            $row['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

$pageTitle = 'Category Management';
require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $name = sanitizeInput($_POST['name'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');
        
        if (empty($name)) {
            $message = 'Please enter category name.';
            $messageType = 'danger';
        } else {
            if ($action === 'add') {
                // Check if category already exists
                $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
                $stmt->bind_param("s", $name);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $message = 'Category already exists.';
                    $messageType = 'danger';
                } else {
                    $stmt->close();
                    $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                    $stmt->bind_param("ss", $name, $description);
                    
                    if ($stmt->execute()) {
                        $catId = $conn->insert_id;
                        logAudit('create', 'categories', $catId, null, ['name' => $name]);
                        $message = 'Category added successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Error adding category: ' . $conn->error;
                        $messageType = 'danger';
                    }
                }
                $stmt->close();
            } else {
                $id = intval($_POST['id'] ?? 0);
                $oldValues = [];
                $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $oldValues = $result->fetch_assoc();
                }
                $stmt->close();
                
                // Check if category name already exists for another category
                $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
                $stmt->bind_param("si", $name, $id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $message = 'Category name already exists.';
                    $messageType = 'danger';
                } else {
                    $stmt->close();
                    $stmt = $conn->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
                    $stmt->bind_param("ssi", $name, $description, $id);
                    
                    if ($stmt->execute()) {
                        $newValues = ['name' => $name];
                        logAudit('update', 'categories', $id, $oldValues, $newValues);
                        $message = 'Category updated successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Error updating category: ' . $conn->error;
                        $messageType = 'danger';
                    }
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        // Check if category is used in inventory
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM inventory WHERE category_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['count'] > 0) {
            $message = 'Cannot delete category that is used in inventory items.';
            $messageType = 'danger';
        } else {
            $oldValues = [];
            $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $oldValues = $result->fetch_assoc();
            }
            $stmt->close();
            
            $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                logAudit('delete', 'categories', $id, $oldValues, null);
                $message = 'Category deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Error deleting category: ' . $conn->error;
                $messageType = 'danger';
            }
            $stmt->close();
        }
    }
}

// Get pagination parameters
$pagination = getPaginationParams(10);
$page = $pagination['page'];
$offset = $pagination['offset'];
$limit = $pagination['limit'];

// Get total count for pagination
$totalCategories = $conn->query("SELECT COUNT(*) as total FROM categories")->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalCategories / $limit));

// Get categories with pagination
$categories = $conn->query("SELECT c.*, COUNT(i.id) as item_count FROM categories c LEFT JOIN inventory i ON c.id = i.category_id GROUP BY c.id ORDER BY c.name LIMIT $limit OFFSET $offset")->fetch_all(MYSQLI_ASSOC);
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-tags"></i> Category Management</h2>
    </div>
</div>

<?php 
if ($message) {
    showSweetAlert($messageType, $message);
}
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Categories</h5>
        <div class="d-flex gap-2">
            <a href="?export=csv" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
            </a>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-circle"></i> Add Category
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Items Count</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($categories) > 0): ?>
                        <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($cat['description'] ?: '-'); ?></td>
                            <td><span class="badge bg-info"><?php echo $cat['item_count']; ?></span></td>
                            <td><?php echo formatDate($cat['created_at']); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="editCategory(<?php echo htmlspecialchars(json_encode($cat)); ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ($cat['item_count'] == 0): ?>
                                <form method="POST" style="display:inline;" data-confirm-delete data-message="Are you sure you want to delete this category?">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No categories found.</td>
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
                            Showing <?php echo count($categories); ?> of <?php echo $totalCategories; ?> categories (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)
                        </small>
                    </div>
                    <?php echo renderPagination($page, $totalPages, 'categories.php'); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="categoryForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="categoryId">
                    
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="categoryName" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCategory(cat) {
    document.getElementById('modalTitle').textContent = 'Edit Category';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('categoryId').value = cat.id;
    document.getElementById('categoryName').value = cat.name;
    document.getElementById('description').value = cat.description || '';
    
    const modal = new bootstrap.Modal(document.getElementById('addCategoryModal'));
    modal.show();
}

// Reset form when modal is closed
document.getElementById('addCategoryModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('categoryForm').reset();
    document.getElementById('modalTitle').textContent = 'Add Category';
    document.getElementById('formAction').value = 'add';
    document.getElementById('categoryId').value = '';
});
</script>

<?php require_once 'includes/footer.php'; ?>

