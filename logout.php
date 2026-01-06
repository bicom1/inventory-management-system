<?php
require_once 'config/config.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    logAudit('logout', 'users', $_SESSION['user_id']);
}

session_unset();
session_destroy();

header('Location: ' . BASE_URL . 'login.php');
exit;

