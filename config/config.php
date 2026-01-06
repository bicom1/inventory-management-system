<?php
/**
 * Main Configuration File
 * BI Communications Inventory Management System
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('Asia/Manila');

// Base URL (adjust for your Laragon setup)
define('BASE_URL', 'http://localhost/biinventory/');
define('BASE_PATH', __DIR__ . '/../');

// Include database configuration
require_once __DIR__ . '/database.php';

// Include SweetAlert helper
require_once __DIR__ . '/../includes/sweetalert.php';

// Session timeout (30 minutes)
define('SESSION_TIMEOUT', 1800);

// Roles
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_IT_ADMIN', 'it_admin');
define('ROLE_HR_MANAGER', 'hr_manager');

// Check session timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
$_SESSION['last_activity'] = time();

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

// Helper function to check user role
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $userRole = $_SESSION['role'] ?? '';
    
    // Super admin has all permissions
    if ($userRole === ROLE_SUPER_ADMIN) {
        return true;
    }
    
    return $userRole === $role;
}

// Helper function to check if user has any of the specified roles
function hasAnyRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $userRole = $_SESSION['role'] ?? '';
    
    if ($userRole === ROLE_SUPER_ADMIN) {
        return true;
    }
    
    return in_array($userRole, $roles);
}

// Require login for protected pages
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

// Require specific role
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        header('Location: ' . BASE_URL . 'index.php?error=access_denied');
        exit;
    }
}

// Require any of the specified roles
function requireAnyRole($roles) {
    requireLogin();
    if (!hasAnyRole($roles)) {
        header('Location: ' . BASE_URL . 'index.php?error=access_denied');
        exit;
    }
}

// Sanitize input
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Format date for display
function formatDate($date, $format = 'Y-m-d') {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    return date($format, strtotime($date));
}

// Format datetime for display
function formatDateTime($datetime, $format = 'Y-m-d H:i:s') {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($datetime));
}

