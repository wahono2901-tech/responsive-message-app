<?php
/**
 * Cleanup Expired Messages and Old Data
 * File: cron/cleanup.php
 */

require_once '../config/config.php';
require_once '../config/database.php';

// Only allow execution via CLI or cron
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    die('Access denied');
}

// Verify cron key
if (isset($_GET['cron_key']) && $_GET['cron_key'] !== CRON_SECRET_KEY) {
    die('Invalid cron key');
}

$db = Database::getInstance()->getConnection();

try {
    $db->beginTransaction();
    
    // 1. Archive expired messages (older than 90 days)
    $archiveSql = "
        INSERT INTO messages_archive 
        SELECT * FROM messages 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ";
    
    $db->exec($archiveSql);
    
    // 2. Delete archived messages
    $deleteSql = "
        DELETE FROM messages 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ";
    
    $db->exec($deleteSql);
    
    // 3. Mark messages as expired if response time exceeded
    $expireSql = "
        UPDATE messages m
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        SET m.status = 'Expired',
            m.updated_at = NOW()
        WHERE m.status IN ('Pending', 'Dibaca', 'Diproses')
        AND TIMESTAMPDIFF(HOUR, m.created_at, NOW()) > mt.response_deadline_hours
    ";
    
    $db->exec($expireSql);
    
    // 4. Cleanup old sessions (older than 7 days)
    $sessionSql = "
        DELETE FROM sessions 
        WHERE last_activity < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ";
    
    $db->exec($sessionSql);
    
    // 5. Cleanup old login attempts (older than 30 days)
    $loginSql = "
        DELETE FROM login_attempts 
        WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    
    $db->exec($loginSql);
    
    // 6. Cleanup old audit logs (older than 365 days)
    $auditSql = "
        DELETE FROM audit_logs 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)
    ";
    
    $db->exec($auditSql);
    
    // 7. Cleanup temporary files
    cleanupTemporaryFiles();
    
    // 8. Optimize tables
    optimizeTables();
    
    $db->commit();
    
    echo "Cleanup completed successfully" . PHP_EOL;
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Cleanup Error: " . $e->getMessage());
    echo "Cleanup failed: " . $e->getMessage() . PHP_EOL;
}