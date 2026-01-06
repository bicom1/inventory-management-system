<?php
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$userRole = $_SESSION['role'] ?? '';
$userName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?>BI Inventory System</title>
    <link rel="icon" type="image/jpeg" href="https://services.enfieldroyalclinic.com/wp-content/uploads/2026/01/WhatsApp-Image-2026-01-01-at-5.39.54-PM.jpeg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-brand">
                <img src="https://services.enfieldroyalclinic.com/wp-content/uploads/2026/01/WhatsApp-Image-2026-01-01-at-5.39.54-PM.jpeg" alt="BI Inventory Logo" class="sidebar-logo">
                <span>IMS</span>
            </a>
            <button class="sidebar-toggle d-md-none" id="sidebarToggle">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        
        <div class="sidebar-body">
            <nav class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" href="index.php">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    
                    <?php if (hasAnyRole([ROLE_SUPER_ADMIN, ROLE_IT_ADMIN])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'inventory.php' ? 'active' : ''; ?>" href="inventory.php">
                            <i class="bi bi-boxes"></i>
                            <span>Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'purchases.php' ? 'active' : ''; ?>" href="purchases.php">
                            <i class="bi bi-cart-plus"></i>
                            <span>Purchases</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'assignments.php' ? 'active' : ''; ?>" href="assignments.php">
                            <i class="bi bi-person-check"></i>
                            <span>Assignments</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'employees.php' ? 'active' : ''; ?>" href="employees.php">
                            <i class="bi bi-people"></i>
                            <span>Employees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'categories.php' ? 'active' : ''; ?>" href="categories.php">
                            <i class="bi bi-tags"></i>
                            <span>Categories</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'purchase_categories.php' ? 'active' : ''; ?>" href="purchase_categories.php">
                            <i class="bi bi-tag"></i>
                            <span>Purchase Categories</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                            <i class="bi bi-file-earmark-text"></i>
                            <span>Reports</span>
                        </a>
                    </li>
                    
                    <?php if (hasRole(ROLE_SUPER_ADMIN)): ?>
                    <li class="nav-divider"></li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'approvals.php' ? 'active' : ''; ?>" href="approvals.php">
                            <i class="bi bi-check-circle"></i>
                            <span>Approvals</span>
                            <?php 
                            $pendingCount = getPendingApprovalsCount();
                            if ($pendingCount > 0): 
                            ?>
                                <span class="badge bg-danger ms-auto"><?php echo $pendingCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'users.php' ? 'active' : ''; ?>" href="users.php">
                            <i class="bi bi-person-gear"></i>
                            <span>Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'audit_logs.php' ? 'active' : ''; ?>" href="audit_logs.php">
                            <i class="bi bi-journal-text"></i>
                            <span>Audit Logs</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <i class="bi bi-person-circle"></i>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($userName); ?></div>
                    <div class="user-role"><?php echo ucfirst(str_replace('_', ' ', $userRole)); ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn btn-sm btn-outline-light w-100 mt-2">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Bar -->
        <nav class="topbar">
            <div class="topbar-left">
                <button class="btn btn-link sidebar-toggle d-md-none" id="mobileSidebarToggle">
                    <i class="bi bi-list"></i>
                </button>
                <h4 class="page-title mb-0"><?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?></h4>
            </div>
            <div class="topbar-right">
                <span class="text-muted"><?php echo date('l, F j, Y'); ?></span>
            </div>
        </nav>
        
        <!-- Page Content -->
        <div class="content-wrapper">
