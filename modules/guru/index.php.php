<?php
/**
 * Guru Home Page - Redirects to Dashboard
 * File: modules/guru/index.php
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

// Check authentication and guru privilege
Auth::checkAuth();

// Only specific guru types can access this page
$allowedTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'];
if (!in_array($_SESSION['user_type'], $allowedTypes)) {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

// Redirect to dashboard
header('Location: dashboard_guru.php');
exit;
?>