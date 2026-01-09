<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
requireRole(ROLE_SUPER_ADMIN);

$pageTitle = 'Pending Approvals';
require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $approvalId = intval($_POST['approval_id'] ?? 0);
    $reviewNotes = sanitizeInput($_POST['review_notes'] ?? '');
    
    if ($action === 'approve' || $action === 'reject') {
        if ($approvalId > 0) {
            $result = processApproval($approvalId, $action, $reviewNotes);
            if ($result) {
                $message = $action === 'approve' ? 'Request approved successfully.' : 'Request rejected.';
                $messageType = 'success';
            } else {
                $message = 'Error processing approval.';
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid approval ID.';
            $messageType = 'danger';
        }
    }
}

// Get pagination parameters
$pagination = getPaginationParams(10);
$page = $pagination['page'];
$offset = $pagination['offset'];
$limit = $pagination['limit'];

// Get total count for pagination
$totalApprovals = $conn->query("SELECT COUNT(*) as total FROM approvals WHERE status = 'pending'")->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalApprovals / $limit));

// Get pending approvals with pagination
$query = "SELECT a.*, u.full_name as requested_by_name, u.username as requested_by_username
          FROM approvals a
          INNER JOIN users u ON a.requested_by = u.id
          WHERE a.status = 'pending'
          ORDER BY a.created_at ASC
          LIMIT $limit OFFSET $offset";
$result = $conn->query($query);
$pendingApprovals = $result->fetch_all(MYSQLI_ASSOC);
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-check-circle"></i> Pending Approvals</h2>
    </div>
</div>

<?php 
if ($message) {
    showSweetAlert($messageType, $message);
}
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Pending Approval Requests</h5>
        <span class="badge bg-danger"><?php echo count($pendingApprovals); ?> Pending</span>
    </div>
    <div class="card-body">
        <?php if (count($pendingApprovals) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Table</th>
                            <th>Requested By</th>
                            <th>Request Date</th>
                            <th>Details</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingApprovals as $approval): ?>
                            <?php 
                            $requestData = json_decode($approval['request_data'], true);
                            $requestType = ucfirst($approval['request_type']);
                            $tableName = ucfirst($approval['table_name']);
                            ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?php echo $approval['request_type'] === 'add' ? 'success' : ($approval['request_type'] === 'update' ? 'warning' : 'danger'); ?>">
                                        <?php echo $requestType; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($tableName); ?></td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($approval['requested_by_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($approval['requested_by_username']); ?></small>
                                    </div>
                                </td>
                                <td><?php echo formatDateTime($approval['created_at']); ?></td>
                                <td>
                                    <?php if ($approval['table_name'] === 'inventory'): ?>
                                        <div class="small">
                                            <?php if ($approval['request_type'] === 'delete'): ?>
                                                <strong>Item ID:</strong> <?php echo $approval['record_id']; ?><br>
                                                <em>Delete request</em>
                                            <?php else: ?>
                                                <strong>Item:</strong> <?php echo htmlspecialchars($requestData['item_name'] ?? '-'); ?><br>
                                                <strong>Type:</strong> <?php echo htmlspecialchars(ucfirst($requestData['type'] ?? '-')); ?><br>
                                                <strong>Quantity:</strong> <?php echo $requestData['total_quantity'] ?? 0; ?><br>
                                                <?php if (!empty($requestData['price_per_unit'])): ?>
                                                    <strong>Price:</strong> PKR <?php echo number_format($requestData['price_per_unit'], 2); ?><br>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <pre class="small"><?php echo htmlspecialchars(json_encode($requestData, JSON_PRETTY_PRINT)); ?></pre>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-success" onclick="showApprovalModal(<?php echo $approval['id']; ?>, 'approve')">
                                            <i class="bi bi-check-circle"></i> Approve
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="showApprovalModal(<?php echo $approval['id']; ?>, 'reject')">
                                            <i class="bi bi-x-circle"></i> Reject
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                Showing <?php echo count($pendingApprovals); ?> of <?php echo $totalApprovals; ?> pending approvals (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)
                            </small>
                        </div>
                        <?php echo renderPagination($page, $totalPages, 'approvals.php'); ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                <p class="mt-3 text-muted">No pending approvals. All requests have been processed.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Approval Modal -->
<div class="modal fade" id="approvalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="approvalModalTitle">Process Approval</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="approvalForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="approvalAction">
                    <input type="hidden" name="approval_id" id="approvalId">
                    
                    <div class="mb-3">
                        <label class="form-label">Review Notes (Optional)</label>
                        <textarea class="form-control" name="review_notes" rows="3" placeholder="Add any notes about this approval..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Are you sure you want to <strong id="actionText">approve</strong> this request?
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="approvalSubmitBtn">
                        <i class="bi bi-check-circle"></i> Confirm
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showApprovalModal(approvalId, action) {
    document.getElementById('approvalId').value = approvalId;
    document.getElementById('approvalAction').value = action;
    
    const modal = document.getElementById('approvalModal');
    const title = document.getElementById('approvalModalTitle');
    const actionText = document.getElementById('actionText');
    const submitBtn = document.getElementById('approvalSubmitBtn');
    
    if (action === 'approve') {
        title.textContent = 'Approve Request';
        actionText.textContent = 'approve';
        submitBtn.className = 'btn btn-success';
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Approve';
    } else {
        title.textContent = 'Reject Request';
        actionText.textContent = 'reject';
        submitBtn.className = 'btn btn-danger';
        submitBtn.innerHTML = '<i class="bi bi-x-circle"></i> Reject';
    }
    
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}
</script>

<?php require_once 'includes/footer.php'; ?>

