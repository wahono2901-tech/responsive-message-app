<?php
/**
 * Database Connection Check
 * File: config/database_check.php
 */

function checkDatabaseConnection() {
    try {
        require_once __DIR__ . '/database.php';
        $db = Database::getInstance();
        
        // Test query
        $result = $db->select("SELECT 1 as test");
        
        if (!empty($result)) {
            return ['success' => true, 'message' => 'Database connected'];
        } else {
            return ['success' => false, 'message' => 'Database query failed'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Jika diakses langsung
if (basename($_SERVER['PHP_SELF']) == 'database_check.php') {
    $result = checkDatabaseConnection();
    echo '<h3>Database Connection Status</h3>';
    echo '<pre>';
    print_r($result);
    echo '</pre>';
}
?>