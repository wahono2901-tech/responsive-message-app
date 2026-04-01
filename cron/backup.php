<?php
/**
 * Automated Database Backup Script
 * File: cron/backup.php
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Only allow execution via CLI or cron
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    die('Access denied');
}

// Verify cron key
if (isset($_GET['cron_key']) && $_GET['cron_key'] !== CRON_SECRET_KEY) {
    die('Invalid cron key');
}

// Create backup directory if it doesn't exist
if (!is_dir(BACKUP_PATH)) {
    mkdir(BACKUP_PATH, 0755, true);
}

// Generate backup filename
$timestamp = date('Y-m-d_H-i-s');
$backupFile = BACKUP_PATH . "backup_{$timestamp}.sql";
$compressedFile = $backupFile . '.gz';

try {
    // Database connection info
    $host = DB_HOST;
    $port = DB_PORT;
    $user = DB_USER;
    $pass = DB_PASS;
    $name = DB_NAME;
    
    // Create backup command
    $command = sprintf(
        'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers %s > %s 2>&1',
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        escapeshellarg($pass),
        escapeshellarg($name),
        escapeshellarg($backupFile)
    );
    
    // Execute backup
    exec($command, $output, $returnVar);
    
    if ($returnVar === 0 && file_exists($backupFile)) {
        // Compress backup
        $gz = gzopen($compressedFile, 'w9');
        gzwrite($gz, file_get_contents($backupFile));
        gzclose($gz);
        
        // Delete uncompressed file
        unlink($backupFile);
        
        // Encrypt backup (optional)
        if (defined('BACKUP_ENCRYPTION_KEY') && BACKUP_ENCRYPTION_KEY) {
            encryptBackup($compressedFile);
        }
        
        // Upload to cloud storage (optional)
        if (defined('CLOUD_BACKUP_ENABLED') && CLOUD_BACKUP_ENABLED) {
            uploadToCloud($compressedFile);
        }
        
        // Log backup
        logBackup($compressedFile);
        
        // Cleanup old backups (keep last 30 days)
        cleanupOldBackups();
        
        echo "Backup successful: " . basename($compressedFile) . PHP_EOL;
        
    } else {
        throw new Exception("Backup failed: " . implode(PHP_EOL, $output));
    }
    
} catch (Exception $e) {
    error_log("Backup Error: " . $e->getMessage());
    echo "Backup failed: " . $e->getMessage() . PHP_EOL;
}