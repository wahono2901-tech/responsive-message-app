<?php
/**
 * Admin Settings - Integrated System Configuration
 * File: modules/admin/settings.php
 * 
 * ⚡ FITUR LENGKAP TERINTEGRASI DENGAN BACKUP & RESTORE:
 * ✅ General Settings - Aplikasi, sekolah, kontak (Database Integration)
 * ✅ Message Types - CRUD dengan SLA & assignment (Full CRUD)
 * ✅ User Management - Role, permissions, aktivasi
 * ✅ Response Templates - CRUD dengan kategori (Full CRUD)
 * ✅ System Configuration - Backup, logging, security
 * ✅ Audit Trail - Log aktivitas sistem
 * ✅ Export/Import - Backup & restore konfigurasi
 * ✅ Notifications - MailerSend & Fonnte Configuration (Database Integration)
 * ✅ Backup & Restore Database - FULLY FUNCTIONAL dengan mysqldump
 * ✅ PHP 8.2 Compatible
 * 
 * @author Responsive Message App
 * @version 4.0.0 - Full Database Integration
 */

// ============================================
// DEBUG INITIALIZATION
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('max_execution_time', 300);

// Hentikan output buffering
while (ob_get_level()) ob_end_clean();
ob_start();

// ============================================
// INCLUDE REQUIRED FILES FIRST
// ============================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Now ROOT_PATH is already defined in config.php
ini_set('error_log', ROOT_PATH . '/debug_error.log');

// Check authentication and admin privilege
Auth::checkAuth();
if ($_SESSION['user_type'] !== 'Admin' && $_SESSION['privilege_level'] !== 'Full_Access') {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

// ============================================
// KONSTANTA UNTUK BACKUP
// ============================================
if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', ROOT_PATH . '/backups');
}

// Buat direktori logs, dan backups jika belum ada
foreach ([ROOT_PATH . '/logs', BACKUP_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ============================================
// FUNGSI LOGGING
// ============================================
define('MAILERSEND_LOG', ROOT_PATH . '/logs/mailersend_test.log');
define('FONNTE_LOG', ROOT_PATH . '/logs/fonnte_test.log');
define('EMAIL_DEBUG_LOG', ROOT_PATH . '/logs/email_debug.log');
define('WHATSAPP_DEBUG_LOG', ROOT_PATH . '/logs/whatsapp_debug.log');
define('WHATSAPP_SUCCESS_LOG', ROOT_PATH . '/logs/whatsapp_success.log');
define('ADMIN_SETTINGS_LOG', ROOT_PATH . '/logs/admin_settings.log');
define('BACKUP_LOG', ROOT_PATH . '/logs/backup_restore.log');

// Pastikan direktori logs writable
foreach ([MAILERSEND_LOG, FONNTE_LOG, EMAIL_DEBUG_LOG, WHATSAPP_DEBUG_LOG, WHATSAPP_SUCCESS_LOG, ADMIN_SETTINGS_LOG, BACKUP_LOG] as $logFile) {
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    if (!file_exists($logFile)) {
        file_put_contents($logFile, '');
    }
}

function writeLog($file, $message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    @file_put_contents($file, $log, FILE_APPEND);
    error_log($log);
}

function mailersend_log($message, $data = null) {
    writeLog(MAILERSEND_LOG, $message, $data);
}

function fonnte_log($message, $data = null) {
    writeLog(FONNTE_LOG, $message, $data);
}

function email_log($message, $data = null) {
    writeLog(EMAIL_DEBUG_LOG, $message, $data);
}

function whatsapp_log($message, $data = null) {
    writeLog(WHATSAPP_DEBUG_LOG, $message, $data);
}

function whatsapp_success_log($message, $data = null) {
    writeLog(WHATSAPP_SUCCESS_LOG, $message, $data);
}

function admin_log($message, $data = null) {
    writeLog(ADMIN_SETTINGS_LOG, $message, $data);
}

function backup_log($message, $data = null) {
    writeLog(BACKUP_LOG, $message, $data);
}

admin_log("========== ADMIN SETTINGS.PHP STARTED ==========");
admin_log("ROOT_PATH dari config.php: " . ROOT_PATH);

// ============================================
// DATABASE CONNECTION
// ============================================
$db = Database::getInstance()->getConnection();

// ============================================
// FUNGSI BACKUP DATABASE - MENGGUNAKAN MYSQLDUMP
// ============================================

function createDatabaseBackup() {
    backup_log("\n" . str_repeat("=", 80));
    backup_log("createDatabaseBackup() DIPANGGIL");
    
    $host = DB_HOST;
    $port = DB_PORT;
    $user = DB_USER;
    $pass = DB_PASS;
    $name = DB_NAME;
    
    $host = str_replace(':8080', '', $host);
    
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "backup_{$name}_{$timestamp}.sql";
    $backupPath = BACKUP_DIR . '/' . $filename;
    
    backup_log("Host: $host, Port: $port, Database: $name");
    backup_log("Backup file: $backupPath");
    
    $checkMysqldump = shell_exec('which mysqldump 2>&1');
    if (empty($checkMysqldump)) {
        backup_log("ERROR: mysqldump tidak ditemukan di sistem");
        return createDatabaseBackupPHP($name, $backupPath);
    }
    
    if (!empty($port) && $port != '3306') {
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s --routines --triggers --events %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($name)
        );
    } else {
        $command = sprintf(
            'mysqldump --host=%s --user=%s --password=%s --routines --triggers --events %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($name)
        );
    }
    
    backup_log("Command: " . str_replace($pass, '******', $command));
    
    $output = shell_exec($command . ' > ' . escapeshellarg($backupPath));
    
    if (file_exists($backupPath) && filesize($backupPath) > 0) {
        $size = filesize($backupPath);
        backup_log("✓ Backup berhasil! Ukuran: " . round($size / 1024 / 1024, 2) . " MB");
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $backupPath,
            'size' => $size,
            'message' => "Backup berhasil dibuat: $filename (" . round($size / 1024 / 1024, 2) . " MB)"
        ];
    } else {
        $error = $output ?: "Unknown error";
        backup_log("✗ Backup gagal: $error");
        backup_log("Mencoba fallback ke metode PHP...");
        return createDatabaseBackupPHP($name, $backupPath);
    }
}

function createDatabaseBackupPHP($dbname, $backupPath) {
    backup_log("Menggunakan metode PHP fallback untuk backup");
    
    try {
        $db = Database::getInstance()->getConnection();
        
        $tables = [];
        $result = $db->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        backup_log("Ditemukan " . count($tables) . " tabel");
        
        $sql = "-- Responsive Message App Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Database: " . $dbname . "\n";
        $sql .= "-- PHP Version: " . phpversion() . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $sql .= "START TRANSACTION;\n";
        $sql .= "SET time_zone = '+07:00';\n\n";
        
        foreach ($tables as $table) {
            backup_log("Memproses tabel: $table");
            
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            
            $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $sql .= $create[1] . ";\n\n";
            
            $rows = $db->query("SELECT * FROM `$table`");
            $columns = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (is_numeric($value)) {
                        $values[] = $value;
                    } else {
                        $values[] = "'" . $db->quote($value) . "'";
                    }
                }
                $sql .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
        
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $sql .= "COMMIT;\n";
        
        file_put_contents($backupPath, $sql);
        
        if (file_exists($backupPath) && filesize($backupPath) > 0) {
            $size = filesize($backupPath);
            backup_log("✓ Backup PHP berhasil! Ukuran: " . round($size / 1024 / 1024, 2) . " MB");
            return [
                'success' => true,
                'filename' => basename($backupPath),
                'path' => $backupPath,
                'size' => $size,
                'message' => "Backup berhasil dibuat menggunakan PHP: " . basename($backupPath) . " (" . round($size / 1024 / 1024, 2) . " MB)"
            ];
        } else {
            throw new Exception("Gagal menulis file backup");
        }
        
    } catch (Exception $e) {
        backup_log("✗ Backup PHP gagal: " . $e->getMessage());
        return [
            'success' => false,
            'message' => "Backup gagal: " . $e->getMessage()
        ];
    }
}

function restoreDatabase($backupFile) {
    backup_log("\n" . str_repeat("=", 80));
    backup_log("restoreDatabase() DIPANGGIL");
    backup_log("File: $backupFile");
    
    if (!file_exists($backupFile)) {
        backup_log("✗ File backup tidak ditemukan");
        return ['success' => false, 'message' => 'File backup tidak ditemukan'];
    }
    
    $host = DB_HOST;
    $port = DB_PORT;
    $user = DB_USER;
    $pass = DB_PASS;
    $name = DB_NAME;
    
    $host = str_replace(':8080', '', $host);
    
    backup_log("Host: $host, Port: $port, Database: $name");
    
    $checkMysql = shell_exec('which mysql 2>&1');
    if (empty($checkMysql)) {
        backup_log("ERROR: mysql client tidak ditemukan di sistem");
        return ['success' => false, 'message' => 'mysql client tidak ditemukan. Restore menggunakan metode PHP...'];
    }
    
    if (!empty($port) && $port != '3306') {
        $command = sprintf(
            'mysql --host=%s --port=%s --user=%s --password=%s %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($name)
        );
    } else {
        $command = sprintf(
            'mysql --host=%s --user=%s --password=%s %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($name)
        );
    }
    
    backup_log("Command: " . str_replace($pass, '******', $command));
    
    $output = shell_exec('cat ' . escapeshellarg($backupFile) . ' | ' . $command);
    
    if (empty($output)) {
        backup_log("✓ Restore berhasil!");
        return [
            'success' => true,
            'message' => 'Database berhasil direstore dari: ' . basename($backupFile)
        ];
    } else {
        backup_log("✗ Restore gagal: $output");
        backup_log("Mencoba fallback ke restore manual...");
        return restoreDatabaseManual($backupFile);
    }
}

function restoreDatabaseManual($backupFile) {
    backup_log("Mencoba restore manual menggunakan PHP");
    
    try {
        $db = Database::getInstance()->getConnection();
        
        $sql = file_get_contents($backupFile);
        $queries = explode(';', $sql);
        
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        $count = 0;
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                try {
                    $db->exec($query);
                    $count++;
                } catch (Exception $e) {
                    backup_log("Query error: " . $e->getMessage());
                }
            }
        }
        
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        backup_log("✓ Restore manual berhasil! Dieksekusi $count queries");
        return [
            'success' => true,
            'message' => "Database berhasil direstore secara manual ($count queries dieksekusi)"
        ];
        
    } catch (Exception $e) {
        backup_log("✗ Restore manual gagal: " . $e->getMessage());
        return [
            'success' => false,
            'message' => "Restore gagal: " . $e->getMessage()
        ];
    }
}

function getBackupFiles() {
    $files = [];
    if (is_dir(BACKUP_DIR)) {
        $allFiles = glob(BACKUP_DIR . '/*.{sql,zip,gz}', GLOB_BRACE);
        foreach ($allFiles as $file) {
            $files[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'size_formatted' => formatFileSize(filesize($file)),
                'date' => filemtime($file),
                'date_formatted' => date('d/m/Y H:i:s', filemtime($file))
            ];
        }
        usort($files, function($a, $b) {
            return $b['date'] - $a['date'];
        });
    }
    return $files;
}

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// ============================================
// FUNGSI DATABASE UNTUK SETTINGS (SYSTEM SETTINGS)
// ============================================

/**
 * Get system setting by key
 */
function getSetting($key, $default = null) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result['setting_value'];
        }
        return $default;
    } catch (Exception $e) {
        admin_log("Error getting setting $key: " . $e->getMessage());
        return $default;
    }
}

/**
 * Get all settings by category
 */
function getSettingsByCategory($category = null) {
    global $db;
    try {
        if ($category) {
            $stmt = $db->prepare("SELECT setting_key, setting_value, setting_type, description, is_editable FROM system_settings WHERE category = ? ORDER BY display_order");
            $stmt->execute([$category]);
        } else {
            $stmt = $db->prepare("SELECT setting_key, setting_value, setting_type, description, is_editable FROM system_settings ORDER BY display_order");
            $stmt->execute();
        }
        
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $value = $row['setting_value'];
            // Convert based on type
            if ($row['setting_type'] === 'boolean') {
                $value = (bool)$value;
            } elseif ($row['setting_type'] === 'integer') {
                $value = (int)$value;
            }
            $settings[$row['setting_key']] = [
                'value' => $value,
                'type' => $row['setting_type'],
                'description' => $row['description'],
                'editable' => $row['is_editable']
            ];
        }
        return $settings;
    } catch (Exception $e) {
        admin_log("Error getting settings: " . $e->getMessage());
        return [];
    }
}

/**
 * Update system setting
 */
function updateSetting($key, $value) {
    global $db;
    try {
        $stmt = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ? AND is_editable = 1");
        return $stmt->execute([$value, $key]);
    } catch (Exception $e) {
        admin_log("Error updating setting $key: " . $e->getMessage());
        return false;
    }
}

/**
 * Update multiple settings
 */
function updateSettings($settings) {
    global $db;
    try {
        $db->beginTransaction();
        
        foreach ($settings as $key => $value) {
            $stmt = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ? AND is_editable = 1");
            $stmt->execute([$value, $key]);
        }
        
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        admin_log("Error updating settings: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNGSI DATABASE UNTUK MAILERSEND CONFIG
// ============================================

function getMailerSendConfig() {
    global $db;
    try {
        $stmt = $db->prepare("SELECT * FROM mailersend_config LIMIT 1");
        $stmt->execute();
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($config) {
            return [
                'id' => $config['id'],
                'api_token' => $config['api_token'],
                'domain' => $config['domain'],
                'domain_id' => $config['domain_id'],
                'from_email' => $config['from_email'],
                'from_name' => $config['from_name'],
                'smtp_server' => $config['smtp_server'],
                'smtp_username' => $config['smtp_username'],
                'smtp_password' => $config['smtp_password'],
                'smtp_port' => $config['smtp_port'],
                'smtp_encryption' => $config['smtp_encryption'],
                'test_domain' => $config['test_domain'],
                'is_active' => $config['is_active']
            ];
        }
        
        // Return default structure if no record exists
        return [
            'id' => null,
            'api_token' => '',
            'domain' => '',
            'domain_id' => '',
            'from_email' => '',
            'from_name' => 'SMKN 12 Jakarta - Aplikasi Pesan Responsif',
            'smtp_server' => 'smtp.mailersend.net',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'test_domain' => '',
            'is_active' => 0
        ];
    } catch (Exception $e) {
        admin_log("Error getting MailerSend config: " . $e->getMessage());
        return [
            'id' => null,
            'api_token' => '',
            'domain' => '',
            'domain_id' => '',
            'from_email' => '',
            'from_name' => 'SMKN 12 Jakarta - Aplikasi Pesan Responsif',
            'smtp_server' => 'smtp.mailersend.net',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'test_domain' => '',
            'is_active' => 0
        ];
    }
}

function updateMailerSendConfig($config) {
    global $db;
    try {
        $existing = getMailerSendConfig();
        
        if ($existing['id']) {
            // Update existing
            $stmt = $db->prepare("UPDATE mailersend_config SET 
                api_token = ?, domain = ?, domain_id = ?, from_email = ?, from_name = ?,
                smtp_server = ?, smtp_username = ?, smtp_password = ?, smtp_port = ?,
                smtp_encryption = ?, test_domain = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?");
            return $stmt->execute([
                $config['api_token'],
                $config['domain'],
                $config['domain_id'],
                $config['from_email'],
                $config['from_name'],
                $config['smtp_server'],
                $config['smtp_username'],
                $config['smtp_password'],
                $config['smtp_port'],
                $config['smtp_encryption'],
                $config['test_domain'],
                $config['is_active'],
                $existing['id']
            ]);
        } else {
            // Insert new
            $stmt = $db->prepare("INSERT INTO mailersend_config 
                (api_token, domain, domain_id, from_email, from_name, smtp_server, smtp_username, smtp_password, smtp_port, smtp_encryption, test_domain, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            return $stmt->execute([
                $config['api_token'],
                $config['domain'],
                $config['domain_id'],
                $config['from_email'],
                $config['from_name'],
                $config['smtp_server'],
                $config['smtp_username'],
                $config['smtp_password'],
                $config['smtp_port'],
                $config['smtp_encryption'],
                $config['test_domain'],
                $config['is_active']
            ]);
        }
    } catch (Exception $e) {
        admin_log("Error updating MailerSend config: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNGSI DATABASE UNTUK FONNTE CONFIG
// ============================================

function getFonnteConfig() {
    global $db;
    try {
        $stmt = $db->prepare("SELECT * FROM fonnte_config LIMIT 1");
        $stmt->execute();
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($config) {
            return [
                'id' => $config['id'],
                'api_token' => $config['api_token'],
                'account_token' => $config['account_token'],
                'device_id' => $config['device_id'],
                'api_url' => $config['api_url'],
                'email' => $config['email'],
                'password' => $config['password'],
                'country_code' => $config['country_code'],
                'is_active' => $config['is_active']
            ];
        }
        
        return [
            'id' => null,
            'api_token' => '',
            'account_token' => '',
            'device_id' => '',
            'api_url' => 'https://api.fonnte.com/send',
            'email' => '',
            'password' => '',
            'country_code' => '62',
            'is_active' => 0
        ];
    } catch (Exception $e) {
        admin_log("Error getting Fonnte config: " . $e->getMessage());
        return [
            'id' => null,
            'api_token' => '',
            'account_token' => '',
            'device_id' => '',
            'api_url' => 'https://api.fonnte.com/send',
            'email' => '',
            'password' => '',
            'country_code' => '62',
            'is_active' => 0
        ];
    }
}

function updateFonnteConfig($config) {
    global $db;
    try {
        $existing = getFonnteConfig();
        
        if ($existing['id']) {
            $stmt = $db->prepare("UPDATE fonnte_config SET 
                api_token = ?, account_token = ?, device_id = ?, api_url = ?,
                email = ?, password = ?, country_code = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?");
            return $stmt->execute([
                $config['api_token'],
                $config['account_token'],
                $config['device_id'],
                $config['api_url'],
                $config['email'],
                $config['password'],
                $config['country_code'],
                $config['is_active'],
                $existing['id']
            ]);
        } else {
            $stmt = $db->prepare("INSERT INTO fonnte_config 
                (api_token, account_token, device_id, api_url, email, password, country_code, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            return $stmt->execute([
                $config['api_token'],
                $config['account_token'],
                $config['device_id'],
                $config['api_url'],
                $config['email'],
                $config['password'],
                $config['country_code'],
                $config['is_active']
            ]);
        }
    } catch (Exception $e) {
        admin_log("Error updating Fonnte config: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNGSI LAINNYA (Format Phone, Send Test, etc)
// ============================================

function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (substr($phone, 0, 1) == '0') {
        $phone = '62' . substr($phone, 1);
    } elseif (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }
    
    return $phone;
}

function sendTestWhatsApp($config, $to_phone, $to_name = 'Admin Test') {
    whatsapp_success_log("\n" . str_repeat("=", 80));
    whatsapp_success_log("sendTestWhatsApp() DIPANGGIL");
    whatsapp_success_log("To: $to_phone, Name: $to_name");
    
    $formatted_phone = formatPhoneNumber($to_phone);
    whatsapp_success_log("Original phone: $to_phone");
    whatsapp_success_log("Formatted phone: $formatted_phone");
    
    if (strlen($formatted_phone) < 10 || strlen($formatted_phone) > 15) {
        $error = "Nomor tidak valid: $formatted_phone (panjang: " . strlen($formatted_phone) . ")";
        whatsapp_success_log("ERROR: $error");
        return ['success' => false, 'sent' => false, 'error' => $error];
    }
    
    $school_name = getSetting('school_name', 'SMKN 12 Jakarta');
    $current_date = date('d/m/Y H:i');
    
    $message = "🔔 *TEST NOTIFIKASI WHATSAPP - " . $school_name . "*\n\n";
    $message .= "Yth. *$to_name*\n\n";
    $message .= "Ini adalah pesan test dari sistem Aplikasi Pesan Responsif.\n\n";
    $message .= "*Detail Test:*\n";
    $message .= "Waktu: " . date('d/m/Y H:i:s') . " WIB\n";
    $message .= "Tujuan: $to_phone\n\n";
    $message .= "Jika Anda menerima pesan ini, berarti konfigurasi Fonnte berhasil! ✅\n\n";
    $message .= "_Pesan otomatis._\n";
    $message .= "Waktu: {$current_date}\n";
    $message .= "_Dikirim dari perangkat: " . ($config['device_id'] ?? 'Sistem') . "_";
    
    whatsapp_success_log("Message length: " . strlen($message));
    
    $postData = [
        'target' => $formatted_phone,
        'message' => $message,
        'countryCode' => $config['country_code'] ?? '62',
        'delay' => '0'
    ];
    
    whatsapp_success_log("Data dikirim:", $postData);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['api_url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => ['Authorization: ' . $config['api_token']],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    whatsapp_success_log("HTTP Code: $httpCode");
    if ($curlError) {
        whatsapp_success_log("CURL Error: $curlError");
    }
    whatsapp_success_log("Response: $response");
    
    $response_data = json_decode($response, true);
    
    $success = false;
    if ($httpCode == 200) {
        if (isset($response_data['status']) && $response_data['status'] == 1) {
            $success = true;
        } elseif (isset($response_data['status']) && $response_data['status'] === true) {
            $success = true;
        } elseif (isset($response_data['id'])) {
            $success = true;
        }
    }
    
    whatsapp_success_log($success ? "✓ BERHASIL" : "✗ GAGAL");
    whatsapp_success_log(str_repeat("=", 80) . "\n");
    
    return [
        'success' => $success,
        'sent' => $success,
        'http_code' => $httpCode,
        'response' => $response_data,
        'error' => $curlError ?: ($response_data['reason'] ?? null)
    ];
}

function sendTestEmail($config, $to_email, $to_name = 'Admin Test') {
    email_log("\n" . str_repeat("=", 80));
    email_log("sendTestEmail() DIPANGGIL");
    email_log("To: $to_email, Name: $to_name");
    
    if (!function_exists('curl_init')) {
        email_log("ERROR: cURL tidak tersedia");
        return ['success' => false, 'error' => 'cURL tidak tersedia', 'sent' => false];
    }
    
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        email_log("ERROR: Email tidak valid: $to_email");
        return ['success' => false, 'error' => 'Email tidak valid', 'sent' => false];
    }
    
    if (empty($config['api_token'])) {
        email_log("ERROR: API Token kosong");
        return ['success' => false, 'error' => 'API Token tidak boleh kosong', 'sent' => false];
    }
    
    if (empty($config['from_email'])) {
        email_log("ERROR: From Email kosong");
        return ['success' => false, 'error' => 'From Email tidak boleh kosong', 'sent' => false];
    }
    
    $school_name = getSetting('school_name', 'SMKN 12 Jakarta');
    $subject = "Test Notifikasi Email - " . $school_name;
    
    $html_content = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html_content .= '<style>
        body{font-family:Arial;line-height:1.6;background:#f4f6f9;padding:20px}
        .container{max-width:600px;margin:0 auto;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1)}
        .header{background:#0b4d8a;color:white;padding:20px;text-align:center}
        .content{padding:30px}
        .footer{background:#e9ecef;padding:20px;text-align:center;font-size:12px;color:#6c757d}
    </style>';
    $html_content .= '</head><body><div class="container">';
    $html_content .= '<div class="header"><h1>📧 TEST NOTIFIKASI EMAIL</h1><p>' . htmlspecialchars($school_name) . '</p></div>';
    $html_content .= '<div class="content">';
    $html_content .= '<h3>Yth. Admin,</h3>';
    $html_content .= '<p>Ini adalah email test dari halaman pengaturan.</p>';
    $html_content .= '<p>Jika Anda menerima email ini, konfigurasi MailerSend berhasil.</p>';
    $html_content .= '</div><div class="footer"><p>' . htmlspecialchars($school_name) . '</p></div>';
    $html_content .= '</div></body></html>';
    
    $data = [
        'from' => [
            'email' => $config['from_email'],
            'name' => $config['from_name']
        ],
        'to' => [
            [
                'email' => $to_email,
                'name' => $to_name
            ]
        ],
        'subject' => $subject,
        'html' => $html_content,
        'text' => strip_tags($html_content)
    ];
    
    email_log("Data email:", [
        'from' => $config['from_email'],
        'to' => $to_email,
        'subject' => $subject
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.mailersend.com/v1/email',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['api_token'],
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    email_log("HTTP Code: $httpCode");
    if ($curlError) {
        email_log("CURL Error: $curlError");
    }
    email_log("Response: $response");
    
    $success = ($httpCode >= 200 && $httpCode < 300);
    
    if ($success) {
        email_log("✓ TEST EMAIL BERHASIL dikirim ke $to_email");
        return ['success' => true, 'message' => "Email test berhasil dikirim ke $to_email", 'sent' => true];
    } else {
        $errorMsg = $curlError ?: "HTTP $httpCode";
        if ($response) {
            $respData = json_decode($response, true);
            if (isset($respData['message'])) {
                $errorMsg .= " - " . $respData['message'];
            }
        }
        email_log("✗ TEST EMAIL GAGAL: $errorMsg");
        return ['success' => false, 'error' => $errorMsg, 'sent' => false];
    }
}

function testMailerSendConnection($config) {
    mailersend_log("\n" . str_repeat("=", 80));
    mailersend_log("testMailerSendConnection() DIPANGGIL");
    mailersend_log("API Token: " . substr($config['api_token'], 0, 15) . '...');
    
    if (empty($config['api_token'])) {
        return ['success' => false, 'message' => 'API Token tidak boleh kosong'];
    }
    
    if (empty($config['from_email'])) {
        return ['success' => false, 'message' => 'From Email tidak boleh kosong'];
    }
    
    $test_email = $config['from_email'];
    
    mailersend_log("Mencoba test dengan mengirim email ke: $test_email");
    
    $result = sendTestEmail($config, $test_email, 'Admin Test');
    
    if ($result['success']) {
        return [
            'success' => true, 
            'message' => "✓ Koneksi MailerSend berhasil! Email test telah dikirim ke $test_email"
        ];
    } else {
        return [
            'success' => false,
            'message' => "❌ Gagal mengirim email test: " . ($result['error'] ?? 'Unknown error')
        ];
    }
}

function testFonnteConnection($config) {
    fonnte_log("\n" . str_repeat("=", 80));
    fonnte_log("testFonnteConnection() DIPANGGIL");
    fonnte_log("API Token: " . substr($config['api_token'], 0, 10) . '...');
    
    if (empty($config['api_token'])) {
        return ['success' => false, 'message' => 'API Token tidak boleh kosong'];
    }
    
    $test_phone = $config['device_id'] ?? '';
    if (empty($test_phone)) {
        return ['success' => false, 'message' => 'Device ID tidak boleh kosong'];
    }
    
    fonnte_log("Mencoba test dengan mengirim WhatsApp ke device sendiri: $test_phone");
    
    $result = sendTestWhatsApp($config, $test_phone, 'Admin Test');
    
    if ($result['success']) {
        return [
            'success' => true, 
            'message' => "✓ Koneksi Fonnte berhasil! WhatsApp test telah dikirim ke $test_phone"
        ];
    } else {
        return [
            'success' => false,
            'message' => "❌ Gagal mengirim WhatsApp test: " . ($result['error'] ?? 'Unknown error')
        ];
    }
}

function logAudit($db, $userId, $action, $table, $recordId, $description) {
    try {
        if (strlen($description) > 50000) {
            $description = substr($description, 0, 50000) . '... (truncated)';
        }
        
        if ($description === null) {
            $description = '';
        }
        
        $sql = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, old_value, new_value, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            $userId,
            $action,
            $table,
            $recordId,
            null,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        if (!$result) {
            error_log("Failed to insert audit log: " . print_r($stmt->errorInfo(), true));
        }
    } catch (Exception $e) {
        error_log("Exception in logAudit: " . $e->getMessage());
    }
}

// ============================================
// LOAD CONFIGURATIONS FROM DATABASE
// ============================================
$mailersendConfig = getMailerSendConfig();
$fonnteConfig = getFonnteConfig();
$systemSettings = getSettingsByCategory('General');

admin_log("Current MailerSend Config", [
    'api_token' => substr($mailersendConfig['api_token'], 0, 15) . '...',
    'from_email' => $mailersendConfig['from_email']
]);

admin_log("Current Fonnte Config", [
    'api_token' => substr($fonnteConfig['api_token'], 0, 10) . '...',
    'device_id' => $fonnteConfig['device_id']
]);

// ============================================
// GET ACTIVE TAB
// ============================================
$activeTab = $_GET['tab'] ?? 'general';

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    admin_log("POST Request diterima", ['action' => $action]);
    
    try {
        switch ($action) {
            // ========================================
            // GENERAL SETTINGS - DATABASE INTEGRATION
            // ========================================
            case 'update_general':
                $settings = [
                    'app_name' => $_POST['app_name'] ?? '',
                    'app_url' => $_POST['app_url'] ?? '',
                    'school_name' => $_POST['school_name'] ?? '',
                    'school_address' => $_POST['school_address'] ?? '',
                    'school_phone' => $_POST['school_phone'] ?? '',
                    'school_email' => $_POST['school_email'] ?? '',
                    'admin_email' => $_POST['admin_email'] ?? '',
                    'timezone' => $_POST['timezone'] ?? 'Asia/Jakarta',
                    'date_format' => $_POST['date_format'] ?? 'd/m/Y',
                    'time_format' => $_POST['time_format'] ?? 'H:i:s',
                    'items_per_page' => (int)($_POST['items_per_page'] ?? 10),
                    'enable_registration' => isset($_POST['enable_registration']) ? '1' : '0',
                    'require_email_verification' => isset($_POST['require_email_verification']) ? '1' : '0',
                    'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0'
                ];
                
                $updated = updateSettings($settings);
                
                if ($updated) {
                    logAudit($db, $_SESSION['user_id'], 'UPDATE', 'system_settings', 0, 'General settings updated');
                    $_SESSION['success_message'] = 'Pengaturan umum berhasil diperbarui';
                } else {
                    $_SESSION['error_message'] = 'Gagal memperbarui pengaturan';
                }
                
                header('Location: settings.php?tab=general&success=1');
                exit;
                
            // ========================================
            // BACKUP DATABASE
            // ========================================
            case 'backup_database':
                admin_log("Memulai proses backup database");
                
                $result = createDatabaseBackup();
                
                if ($result['success']) {
                    logAudit($db, $_SESSION['user_id'], 'BACKUP', 'system', 0, "Database backup created: " . $result['filename']);
                    $_SESSION['success_message'] = $result['message'];
                } else {
                    $_SESSION['error_message'] = $result['message'];
                }
                
                header('Location: settings.php?tab=backup');
                exit;
                
            // ========================================
            // RESTORE DATABASE
            // ========================================
            case 'restore_database':
                admin_log("Memulai proses restore database");
                
                if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('File backup tidak ditemukan atau gagal diupload');
                }
                
                $uploadedFile = $_FILES['backup_file']['tmp_name'];
                $originalName = $_FILES['backup_file']['name'];
                
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, ['sql', 'zip', 'gz'])) {
                    throw new Exception('Format file tidak valid. Hanya file .sql, .zip, atau .gz yang diperbolehkan');
                }
                
                $backupPath = BACKUP_DIR . '/restore_' . date('Ymd_His') . '_' . $originalName;
                if (!move_uploaded_file($uploadedFile, $backupPath)) {
                    throw new Exception('Gagal menyimpan file backup');
                }
                
                admin_log("File backup disimpan: $backupPath");
                
                $sqlFile = $backupPath;
                if ($ext === 'zip') {
                    $zip = new ZipArchive;
                    if ($zip->open($backupPath) === true) {
                        $zip->extractTo(BACKUP_DIR);
                        $zip->close();
                        $files = glob(BACKUP_DIR . '/*.sql');
                        if (!empty($files)) {
                            $sqlFile = $files[0];
                        }
                    }
                } elseif ($ext === 'gz') {
                    $sqlFile = BACKUP_DIR . '/' . basename($originalName, '.gz');
                    $bufferSize = 4096;
                    $file = gzopen($backupPath, 'rb');
                    $outFile = fopen($sqlFile, 'wb');
                    while (!gzeof($file)) {
                        fwrite($outFile, gzread($file, $bufferSize));
                    }
                    fclose($outFile);
                    gzclose($file);
                }
                
                $result = restoreDatabase($sqlFile);
                
                if ($result['success']) {
                    logAudit($db, $_SESSION['user_id'], 'RESTORE', 'system', 0, "Database restored from: $originalName");
                    $_SESSION['success_message'] = $result['message'];
                } else {
                    $_SESSION['error_message'] = $result['message'];
                }
                
                header('Location: settings.php?tab=backup');
                exit;
                
            // ========================================
            // DELETE BACKUP FILE
            // ========================================
            case 'delete_backup':
                $filename = $_POST['filename'] ?? '';
                if (empty($filename)) {
                    throw new Exception('Nama file tidak valid');
                }
                
                $filepath = BACKUP_DIR . '/' . basename($filename);
                if (file_exists($filepath) && is_file($filepath)) {
                    if (unlink($filepath)) {
                        logAudit($db, $_SESSION['user_id'], 'DELETE', 'backup', 0, "Deleted backup file: $filename");
                        $_SESSION['success_message'] = "File backup '$filename' berhasil dihapus";
                    } else {
                        throw new Exception('Gagal menghapus file backup');
                    }
                } else {
                    throw new Exception('File backup tidak ditemukan');
                }
                
                header('Location: settings.php?tab=backup');
                exit;
                
            // ========================================
            // CLEAR OLD LOGS
            // ========================================
            case 'clear_logs':
                $days = (int)($_POST['days'] ?? 30);
                
                $sql = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
                $stmt = $db->prepare($sql);
                $stmt->execute([$days]);
                $deleted = $stmt->rowCount();
                
                logAudit($db, $_SESSION['user_id'], 'CLEANUP', 'audit_logs', 0, "Cleared $deleted log entries older than $days days");
                $_SESSION['success_message'] = "Berhasil membersihkan $deleted entri log";
                header('Location: settings.php?tab=system&success=1');
                exit;
                
            // ========================================
            // MESSAGE TYPES - FULL CRUD
            // ========================================
            case 'add_message_type':
                $jenis_pesan = trim($_POST['jenis_pesan'] ?? '');
                $deskripsi = trim($_POST['deskripsi'] ?? '');
                $response_deadline_hours = (int)($_POST['response_deadline_hours'] ?? 72);
                $allow_external = isset($_POST['allow_external']) ? 1 : 0;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $responder_type = $_POST['responder_type'] ?? 'Guru_BK';
                $color_code = $_POST['color_code'] ?? '#0d6efd';
                $icon_class = $_POST['icon_class'] ?? 'fas fa-envelope';
                
                if (empty($jenis_pesan)) {
                    throw new Exception('Nama jenis pesan harus diisi');
                }
                
                $sql = "INSERT INTO message_types (jenis_pesan, deskripsi, response_deadline_hours, allow_external, is_active, responder_type, color_code, icon_class, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $stmt = $db->prepare($sql);
                $stmt->execute([$jenis_pesan, $deskripsi, $response_deadline_hours, $allow_external, $is_active, $responder_type, $color_code, $icon_class]);
                $typeId = $db->lastInsertId();
                
                logAudit($db, $_SESSION['user_id'], 'CREATE', 'message_types', $typeId, "Created message type: $jenis_pesan");
                $_SESSION['success_message'] = 'Jenis pesan berhasil ditambahkan';
                header('Location: settings.php?tab=message_types&success=1');
                exit;
                
            // ========================================
            // EDIT MESSAGE TYPE - FULL CRUD
            // ========================================
            case 'edit_message_type':
                $id = (int)($_POST['id'] ?? 0);
                $jenis_pesan = trim($_POST['jenis_pesan'] ?? '');
                $deskripsi = trim($_POST['deskripsi'] ?? '');
                $response_deadline_hours = (int)($_POST['response_deadline_hours'] ?? 72);
                $allow_external = isset($_POST['allow_external']) ? 1 : 0;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $responder_type = $_POST['responder_type'] ?? 'Guru_BK';
                $color_code = $_POST['color_code'] ?? '#0d6efd';
                $icon_class = $_POST['icon_class'] ?? 'fas fa-envelope';
                
                if ($id <= 0 || empty($jenis_pesan)) {
                    throw new Exception('Data tidak valid');
                }
                
                $sql = "UPDATE message_types SET 
                        jenis_pesan = ?, deskripsi = ?, response_deadline_hours = ?, 
                        allow_external = ?, is_active = ?, responder_type = ?,
                        color_code = ?, icon_class = ?, updated_at = NOW() 
                        WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$jenis_pesan, $deskripsi, $response_deadline_hours, $allow_external, $is_active, $responder_type, $color_code, $icon_class, $id]);
                
                logAudit($db, $_SESSION['user_id'], 'UPDATE', 'message_types', $id, "Updated message type: $jenis_pesan");
                $_SESSION['success_message'] = 'Jenis pesan berhasil diperbarui';
                header('Location: settings.php?tab=message_types&success=1');
                exit;
                
            // ========================================
            // DELETE MESSAGE TYPE
            // ========================================
            case 'delete_message_type':
                $id = (int)($_POST['id'] ?? 0);
                
                $sql = "SELECT COUNT(*) as total FROM messages WHERE jenis_pesan_id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$id]);
                $count = $stmt->fetch()['total'];
                
                if ($count > 0) {
                    throw new Exception("Tidak dapat menghapus: jenis pesan ini memiliki $count pesan terkait");
                }
                
                $sql = "DELETE FROM message_types WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$id]);
                
                logAudit($db, $_SESSION['user_id'], 'DELETE', 'message_types', $id, "Deleted message type ID: $id");
                $_SESSION['success_message'] = 'Jenis pesan berhasil dihapus';
                header('Location: settings.php?tab=message_types&success=1');
                exit;
                
            // ========================================
            // RESPONSE TEMPLATES - FULL CRUD
            // ========================================
            case 'add_template':
                $name = trim($_POST['name'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $category = trim($_POST['category'] ?? 'Umum');
                $default_status = $_POST['default_status'] ?? 'Disetujui';
                $guru_type = $_POST['guru_type'] ?? 'ALL';
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($name) || empty($content)) {
                    throw new Exception('Nama dan konten template harus diisi');
                }
                
                $sql = "INSERT INTO response_templates (name, content, category, default_status, guru_type, is_active, use_count, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())";
                $stmt = $db->prepare($sql);
                $stmt->execute([$name, $content, $category, $default_status, $guru_type, $is_active]);
                
                logAudit($db, $_SESSION['user_id'], 'CREATE', 'response_templates', $db->lastInsertId(), "Created template: $name");
                $_SESSION['success_message'] = 'Template respons berhasil ditambahkan';
                header('Location: settings.php?tab=templates&success=1');
                exit;
                
            // ========================================
            // EDIT TEMPLATE - FULL CRUD
            // ========================================
            case 'edit_template':
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $category = trim($_POST['category'] ?? 'Umum');
                $default_status = $_POST['default_status'] ?? 'Disetujui';
                $guru_type = $_POST['guru_type'] ?? 'ALL';
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if ($id <= 0 || empty($name) || empty($content)) {
                    throw new Exception('Data tidak valid');
                }
                
                $sql = "UPDATE response_templates SET 
                        name = ?, content = ?, category = ?, default_status = ?, 
                        guru_type = ?, is_active = ?, updated_at = NOW() 
                        WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$name, $content, $category, $default_status, $guru_type, $is_active, $id]);
                
                logAudit($db, $_SESSION['user_id'], 'UPDATE', 'response_templates', $id, "Updated template: $name");
                $_SESSION['success_message'] = 'Template respons berhasil diperbarui';
                header('Location: settings.php?tab=templates&success=1');
                exit;
                
            // ========================================
            // DELETE TEMPLATE
            // ========================================
            case 'delete_template':
                $id = (int)($_POST['id'] ?? 0);
                
                $sql = "DELETE FROM response_templates WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$id]);
                
                logAudit($db, $_SESSION['user_id'], 'DELETE', 'response_templates', $id, "Deleted template ID: $id");
                $_SESSION['success_message'] = 'Template respons berhasil dihapus';
                header('Location: settings.php?tab=templates&success=1');
                exit;
                
            // ========================================
            // USER MANAGEMENT
            // ========================================
            case 'update_user_status':
                $userId = (int)($_POST['user_id'] ?? 0);
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                $sql = "UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$isActive, $userId]);
                
                logAudit($db, $_SESSION['user_id'], 'UPDATE', 'users', $userId, "User status updated to: " . ($isActive ? 'Active' : 'Inactive'));
                $_SESSION['success_message'] = 'Status pengguna berhasil diperbarui';
                header('Location: settings.php?tab=users&success=1');
                exit;
                
            case 'reset_user_password':
                $userId = (int)($_POST['user_id'] ?? 0);
                $newPassword = bin2hex(random_bytes(4));
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                $sql = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$hashedPassword, $userId]);
                
                $sql = "SELECT email, nama_lengkap FROM users WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                if (!empty($user['email'])) {
                    $mailersendConfig = getMailerSendConfig();
                    if ($mailersendConfig['is_active'] && !empty($mailersendConfig['api_token'])) {
                        $school_name = getSetting('school_name', 'SMKN 12 Jakarta');
                        $subject = "Password Reset - " . $school_name;
                        $html_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
                        $html_content .= '<h3>Yth. ' . htmlspecialchars($user['nama_lengkap']) . '</h3>';
                        $html_content .= '<p>Password Anda telah direset oleh administrator.</p>';
                        $html_content .= '<p><strong>Password baru Anda: ' . $newPassword . '</strong></p>';
                        $html_content .= '<p>Silakan login dan segera ganti password Anda.</p>';
                        $html_content .= '</body></html>';
                        
                        $data = [
                            'from' => [
                                'email' => $mailersendConfig['from_email'],
                                'name' => $mailersendConfig['from_name']
                            ],
                            'to' => [
                                ['email' => $user['email'], 'name' => $user['nama_lengkap']]
                            ],
                            'subject' => $subject,
                            'html' => $html_content
                        ];
                        
                        $ch = curl_init();
                        curl_setopt_array($ch, [
                            CURLOPT_URL => 'https://api.mailersend.com/v1/email',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode($data),
                            CURLOPT_HTTPHEADER => [
                                'Authorization: Bearer ' . $mailersendConfig['api_token'],
                                'Content-Type: application/json'
                            ]
                        ]);
                        curl_exec($ch);
                        curl_close($ch);
                    }
                }
                
                logAudit($db, $_SESSION['user_id'], 'UPDATE', 'users', $userId, "Password reset for user ID: $userId");
                $_SESSION['success_message'] = 'Password berhasil direset. Password baru telah dikirim ke email pengguna.';
                header('Location: settings.php?tab=users&success=1');
                exit;
                
            // ========================================
            // MAILERSEND CONFIGURATION - DATABASE INTEGRATION
            // ========================================
            case 'update_mailersend':
                $config = [
                    'api_token' => trim($_POST['api_token'] ?? ''),
                    'domain' => trim($_POST['domain'] ?? ''),
                    'domain_id' => trim($_POST['domain_id'] ?? ''),
                    'from_email' => trim($_POST['from_email'] ?? ''),
                    'from_name' => trim($_POST['from_name'] ?? 'SMKN 12 Jakarta - Aplikasi Pesan Responsif'),
                    'smtp_server' => trim($_POST['smtp_server'] ?? 'smtp.mailersend.net'),
                    'smtp_username' => trim($_POST['smtp_username'] ?? ''),
                    'smtp_password' => trim($_POST['smtp_password'] ?? ''),
                    'smtp_port' => (int)($_POST['smtp_port'] ?? 587),
                    'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
                    'test_domain' => trim($_POST['test_domain'] ?? ''),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0
                ];
                
                admin_log("Updating MailerSend config", ['from_email' => $config['from_email']]);
                
                $updated = updateMailerSendConfig($config);
                
                if ($updated) {
                    logAudit($db, $_SESSION['user_id'], 'UPDATE', 'mailersend_config', 0, "MailerSend configuration updated");
                    $_SESSION['success_message'] = 'Konfigurasi MailerSend berhasil diperbarui';
                } else {
                    throw new Exception('Gagal menyimpan konfigurasi MailerSend');
                }
                
                header('Location: settings.php?tab=notifications&success=1');
                exit;
                
            // ========================================
            // FONNTE CONFIGURATION - DATABASE INTEGRATION
            // ========================================
            case 'update_fonnte':
                $config = [
                    'api_token' => trim($_POST['fonnte_api_token'] ?? ''),
                    'account_token' => trim($_POST['account_token'] ?? ''),
                    'device_id' => trim($_POST['device_id'] ?? ''),
                    'api_url' => trim($_POST['api_url'] ?? 'https://api.fonnte.com/send'),
                    'email' => trim($_POST['fonnte_email'] ?? ''),
                    'password' => trim($_POST['fonnte_password'] ?? ''),
                    'country_code' => trim($_POST['country_code'] ?? '62'),
                    'is_active' => isset($_POST['fonnte_is_active']) ? 1 : 0
                ];
                
                admin_log("Updating Fonnte config", ['device_id' => $config['device_id']]);
                
                $updated = updateFonnteConfig($config);
                
                if ($updated) {
                    logAudit($db, $_SESSION['user_id'], 'UPDATE', 'fonnte_config', 0, "Fonnte configuration updated");
                    $_SESSION['success_message'] = 'Konfigurasi Fonnte berhasil diperbarui';
                } else {
                    throw new Exception('Gagal menyimpan konfigurasi Fonnte');
                }
                
                header('Location: settings.php?tab=notifications&success=1');
                exit;
                
            // ========================================
            // TEST MAILERSEND CONNECTION
            // ========================================
            case 'test_mailersend_connection':
                $config = [
                    'api_token' => trim($_POST['api_token'] ?? ''),
                    'from_email' => trim($_POST['from_email'] ?? ''),
                    'from_name' => trim($_POST['from_name'] ?? 'SMKN 12 Jakarta - Test'),
                    'domain' => trim($_POST['domain'] ?? '')
                ];
                
                admin_log("Testing MailerSend connection", ['from_email' => $config['from_email']]);
                
                $result = testMailerSendConnection($config);
                
                if ($result['success']) {
                    $_SESSION['success_message'] = $result['message'];
                } else {
                    $_SESSION['error_message'] = $result['message'];
                }
                
                header('Location: settings.php?tab=notifications#mailersend');
                exit;
                
            // ========================================
            // TEST FONNTE CONNECTION
            // ========================================
            case 'test_fonnte_connection':
                $config = [
                    'api_token' => trim($_POST['fonnte_api_token'] ?? ''),
                    'device_id' => trim($_POST['device_id'] ?? ''),
                    'api_url' => trim($_POST['api_url'] ?? 'https://api.fonnte.com/send'),
                    'country_code' => trim($_POST['country_code'] ?? '62')
                ];
                
                admin_log("Testing Fonnte connection", ['device_id' => $config['device_id']]);
                
                $result = testFonnteConnection($config);
                
                if ($result['success']) {
                    $_SESSION['success_message'] = $result['message'];
                } else {
                    $_SESSION['error_message'] = $result['message'];
                }
                
                header('Location: settings.php?tab=notifications#fonnte');
                exit;
                
            // ========================================
            // SEND TEST EMAIL
            // ========================================
            case 'send_test_email':
                $test_email = trim($_POST['test_email'] ?? '');
                $config = [
                    'api_token' => trim($_POST['api_token'] ?? ''),
                    'from_email' => trim($_POST['from_email'] ?? ''),
                    'from_name' => trim($_POST['from_name'] ?? 'SMKN 12 Jakarta - Notifikasi'),
                    'domain' => trim($_POST['domain'] ?? '')
                ];
                
                admin_log("Sending test email", ['to' => $test_email]);
                
                if (empty($test_email)) {
                    $_SESSION['error_message'] = 'Email tujuan harus diisi';
                } else {
                    $result = sendTestEmail($config, $test_email, 'Admin Test');
                    
                    if ($result['success']) {
                        $_SESSION['success_message'] = $result['message'];
                    } else {
                        $_SESSION['error_message'] = 'Gagal mengirim email: ' . ($result['error'] ?? 'Unknown error');
                    }
                }
                
                header('Location: settings.php?tab=notifications#mailersend');
                exit;
                
            // ========================================
            // SEND TEST WHATSAPP
            // ========================================
            case 'send_test_whatsapp':
                $test_phone = trim($_POST['test_phone'] ?? '');
                $config = [
                    'api_token' => trim($_POST['fonnte_api_token'] ?? ''),
                    'api_url' => trim($_POST['api_url'] ?? 'https://api.fonnte.com/send'),
                    'country_code' => trim($_POST['country_code'] ?? '62'),
                    'device_id' => trim($_POST['device_id'] ?? '')
                ];
                
                admin_log("Sending test WhatsApp", ['to' => $test_phone]);
                
                if (empty($test_phone)) {
                    $_SESSION['error_message'] = 'Nomor WhatsApp tujuan harus diisi';
                } else {
                    $result = sendTestWhatsApp($config, $test_phone, 'Admin Test');
                    
                    if ($result['success']) {
                        $_SESSION['success_message'] = $result['message'];
                    } else {
                        $_SESSION['error_message'] = 'Gagal mengirim WhatsApp: ' . ($result['error'] ?? 'Unknown error');
                    }
                }
                
                header('Location: settings.php?tab=notifications#fonnte');
                exit;
                
            // ========================================
            // EXPORT CONFIG
            // ========================================
            case 'export_config':
                $config = [
                    'message_types' => [],
                    'templates' => [],
                    'system_settings' => [],
                    'mailersend' => getMailerSendConfig(),
                    'fonnte' => getFonnteConfig()
                ];
                
                $sql = "SELECT * FROM message_types ORDER BY id";
                $stmt = $db->query($sql);
                $config['message_types'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $sql = "SELECT * FROM response_templates ORDER BY id";
                $stmt = $db->query($sql);
                $config['templates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $sql = "SELECT setting_key, setting_value, setting_type, category FROM system_settings ORDER BY display_order";
                $stmt = $db->query($sql);
                $config['system_settings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="config_export_' . date('Ymd_His') . '.json"');
                echo json_encode($config, JSON_PRETTY_PRINT);
                exit;
                
            // ========================================
            // IMPORT CONFIG
            // ========================================
            case 'import_config':
                if (!isset($_FILES['config_file']) || $_FILES['config_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('File tidak ditemukan atau gagal diupload');
                }
                
                $content = file_get_contents($_FILES['config_file']['tmp_name']);
                $config = json_decode($content, true);
                
                if (!$config) {
                    throw new Exception('File konfigurasi tidak valid');
                }
                
                $db->beginTransaction();
                
                try {
                    if (!empty($config['message_types'])) {
                        $sql = "INSERT IGNORE INTO message_types (id, jenis_pesan, deskripsi, response_deadline_hours, allow_external, is_active, responder_type, color_code, icon_class, created_at, updated_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                        $stmt = $db->prepare($sql);
                        foreach ($config['message_types'] as $type) {
                            $stmt->execute([
                                $type['id'],
                                $type['jenis_pesan'],
                                $type['deskripsi'] ?? '',
                                $type['response_deadline_hours'] ?? 72,
                                $type['allow_external'] ?? 1,
                                $type['is_active'] ?? 1,
                                $type['responder_type'] ?? 'Guru_BK',
                                $type['color_code'] ?? '#0d6efd',
                                $type['icon_class'] ?? 'fas fa-envelope'
                            ]);
                        }
                    }
                    
                    if (!empty($config['templates'])) {
                        $sql = "INSERT IGNORE INTO response_templates (id, name, content, category, default_status, guru_type, is_active, created_at, updated_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                        $stmt = $db->prepare($sql);
                        foreach ($config['templates'] as $template) {
                            $stmt->execute([
                                $template['id'],
                                $template['name'],
                                $template['content'],
                                $template['category'] ?? 'Umum',
                                $template['default_status'] ?? 'Disetujui',
                                $template['guru_type'] ?? 'ALL',
                                $template['is_active'] ?? 1
                            ]);
                        }
                    }
                    
                    if (!empty($config['mailersend'])) {
                        updateMailerSendConfig($config['mailersend']);
                    }
                    
                    if (!empty($config['fonnte'])) {
                        updateFonnteConfig($config['fonnte']);
                    }
                    
                    if (!empty($config['system_settings'])) {
                        foreach ($config['system_settings'] as $setting) {
                            $sql = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                            $stmt = $db->prepare($sql);
                            $stmt->execute([$setting['setting_value'], $setting['setting_key']]);
                        }
                    }
                    
                    $db->commit();
                    
                    logAudit($db, $_SESSION['user_id'], 'IMPORT', 'config', 0, "Configuration imported from file");
                    $_SESSION['success_message'] = 'Konfigurasi berhasil diimpor';
                } catch (Exception $e) {
                    $db->rollBack();
                    throw new Exception('Gagal mengimpor konfigurasi: ' . $e->getMessage());
                }
                
                header('Location: settings.php?tab=system&success=1');
                exit;
        }
    } catch (Exception $e) {
        admin_log("ERROR dalam POST processing", $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
        $redirect = $_SERVER['HTTP_REFERER'] ?? 'settings.php';
        header('Location: ' . $redirect);
        exit;
    }
}

// ============================================
// GET DATA FOR DISPLAY
// ============================================
$sql = "SELECT mt.*, 
        (SELECT COUNT(*) FROM messages WHERE jenis_pesan_id = mt.id) as message_count
        FROM message_types mt
        ORDER BY mt.is_active DESC, mt.jenis_pesan ASC";
$stmt = $db->query($sql);
$messageTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT * FROM response_templates ORDER BY use_count DESC, name ASC";
$stmt = $db->query($sql);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT u.*, 
        COUNT(DISTINCT m.id) as total_messages,
        COUNT(DISTINCT mr.id) as total_responses,
        MAX(m.created_at) as last_activity
        FROM users u
        LEFT JOIN messages m ON u.id = m.pengirim_id
        LEFT JOIN message_responses mr ON u.id = mr.responder_id
        GROUP BY u.id
        ORDER BY u.is_active DESC, u.created_at DESC";
$stmt = $db->query($sql);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT id, nama_lengkap, user_type FROM users WHERE user_type LIKE 'Guru_%' AND is_active = 1 ORDER BY nama_lengkap";
$stmt = $db->query($sql);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT a.*, u.nama_lengkap as user_name 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id 
        ORDER BY a.created_at DESC 
        LIMIT 50";
$stmt = $db->query($sql);
$auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM users WHERE is_active = 1) as active_users,
        (SELECT COUNT(*) FROM messages) as total_messages,
        (SELECT COUNT(*) FROM message_responses) as total_responses,
        (SELECT COUNT(*) FROM external_senders) as total_external,
        (SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as logs_24h,
        (SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) 
         FROM information_schema.tables 
         WHERE table_schema = DATABASE()) as db_size_mb";
$stmt = $db->query($sql);
$systemStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get backup files for display
$backupFiles = getBackupFiles();

// Get general settings from database
$generalSettings = getSettingsByCategory('General');

// Helper function to get setting value
function getSettingValue($key, $default = '') {
    global $generalSettings;
    return isset($generalSettings[$key]) ? $generalSettings[$key]['value'] : $default;
}

// ============================================
// SUCCESS/ERROR MESSAGES
// ============================================
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
if (isset($_SESSION['warning_message'])) {
    $warning = $_SESSION['warning_message'];
    unset($_SESSION['warning_message']);
}

$pageTitle = 'Pengaturan Sistem - Admin';
require_once '../../includes/header.php';
?>

<style>
/* Settings Page Styles */
.settings-container {
    max-width: 1400px;
    margin: 0 auto;
}

.settings-sidebar {
    background: white;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    overflow: hidden;
}

.settings-nav {
    list-style: none;
    padding: 0;
    margin: 0;
}

.settings-nav-item {
    border-bottom: 1px solid #f1f3f5;
}

.settings-nav-link {
    display: flex;
    align-items: center;
    padding: 1rem 1.5rem;
    color: #495057;
    text-decoration: none;
    transition: all 0.2s ease;
}

.settings-nav-link:hover {
    background: #f8f9fa;
    color: #0d6efd;
}

.settings-nav-link.active {
    background: #e7f1ff;
    color: #0d6efd;
    border-left: 4px solid #0d6efd;
}

.settings-nav-link i {
    width: 24px;
    font-size: 1.1rem;
    margin-right: 12px;
}

.settings-content {
    background: white;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    padding: 2rem;
}

.settings-section {
    margin-bottom: 2rem;
}

.settings-section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #f1f3f5;
}

.settings-section-title h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #212529;
    margin: 0;
}

.settings-section-title h3 i {
    margin-right: 0.75rem;
    color: #0d6efd;
}

.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
}

.table-responsive {
    margin: 1.5rem 0;
}

.badge-active {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-weight: 500;
}

.badge-inactive {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-weight: 500;
}

.template-preview {
    background: #f8f9fa;
    border-left: 4px solid #0d6efd;
    padding: 1rem;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #495057;
    margin-top: 0.5rem;
}

.backup-list {
    max-height: 300px;
    overflow-y: auto;
}

.json-viewer {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 1rem;
    border-radius: 8px;
    font-family: 'Consolas', monospace;
    font-size: 0.85rem;
    overflow-x: auto;
}

.icon-circle {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.icon-circle-sm {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.test-card {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    margin-top: 20px;
    border-left: 4px solid #17a2b8;
}

.log-link {
    font-size: 0.8rem;
    color: #6c757d;
    text-decoration: none;
}

.log-link:hover {
    color: #0d6efd;
    text-decoration: underline;
}

.mailersend-badge {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.whatsapp-badge {
    background: linear-gradient(145deg, #25D366, #128C7E);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.backup-file-item {
    display: flex;
    align-items: center;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #f1f3f5;
    transition: background-color 0.2s;
}

.backup-file-item:hover {
    background-color: #f8f9fa;
}

.backup-file-item:last-child {
    border-bottom: none;
}

.backup-file-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
    margin-right: 1rem;
}

.backup-file-info {
    flex: 1;
}

.backup-file-name {
    font-weight: 600;
    margin-bottom: 0.25rem;
    word-break: break-all;
}

.backup-file-meta {
    font-size: 0.8rem;
    color: #6c757d;
}

.backup-file-actions {
    display: flex;
    gap: 0.5rem;
}

.backup-stats {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    color: white;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-cog me-2 text-primary"></i>Pengaturan Sistem
                <span class="badge bg-info ms-2">Administrator</span>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Pengaturan</li>
                </ol>
            </nav>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-primary" onclick="window.location.reload()">
                <i class="fas fa-sync-alt me-1"></i>Refresh
            </button>
        </div>
    </div>
    
    <?php if (isset($message) && $message): ?>
    <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (isset($error) && $error): ?>
    <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (isset($warning) && $warning): ?>
    <div class="alert alert-warning alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo htmlspecialchars($warning); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3">
            <div class="settings-sidebar">
                <div class="p-3 bg-primary bg-opacity-10 border-bottom">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-sliders-h me-2"></i>
                        Menu Pengaturan
                    </h6>
                </div>
                <ul class="settings-nav">
                    <li class="settings-nav-item">
                        <a href="?tab=general" class="settings-nav-link <?php echo $activeTab === 'general' ? 'active' : ''; ?>">
                            <i class="fas fa-cog"></i>
                            <span>Umum</span>
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="?tab=message_types" class="settings-nav-link <?php echo $activeTab === 'message_types' ? 'active' : ''; ?>">
                            <i class="fas fa-tags"></i>
                            <span>Jenis Pesan</span>
                            <span class="badge bg-primary ms-auto"><?php echo count($messageTypes); ?></span>
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="?tab=templates" class="settings-nav-link <?php echo $activeTab === 'templates' ? 'active' : ''; ?>">
                            <i class="fas fa-sticky-note"></i>
                            <span>Template Respons</span>
                            <span class="badge bg-primary ms-auto"><?php echo count($templates); ?></span>
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="?tab=users" class="settings-nav-link <?php echo $activeTab === 'users' ? 'active' : ''; ?>">
                            <i class="fas fa-users-cog"></i>
                            <span>Manajemen Pengguna</span>
                            <span class="badge bg-primary ms-auto"><?php echo $systemStats['total_users']; ?></span>
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="?tab=notifications" class="settings-nav-link <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>">
                            <i class="fas fa-bell"></i>
                            <span>Notifikasi</span>
                            <span class="badge bg-info ms-auto">API</span>
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="?tab=system" class="settings-nav-link <?php echo $activeTab === 'system' ? 'active' : ''; ?>">
                            <i class="fas fa-server"></i>
                            <span>Sistem & Keamanan</span>
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="?tab=audit" class="settings-nav-link <?php echo $activeTab === 'audit' ? 'active' : ''; ?>">
                            <i class="fas fa-history"></i>
                            <span>Audit Trail</span>
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="?tab=backup" class="settings-nav-link <?php echo $activeTab === 'backup' ? 'active' : ''; ?>">
                            <i class="fas fa-database"></i>
                            <span>Backup & Restore</span>
                            <span class="badge bg-success ms-auto"><?php echo count($backupFiles); ?></span>
                        </a>
                    </li>
                </ul>
                
                <!-- System Status Card -->
                <div class="p-3 border-top">
                    <div class="d-flex align-items-center mb-3">
                        <div class="flex-shrink-0">
                            <div class="icon-circle-sm bg-success bg-opacity-10">
                                <i class="fas fa-check-circle text-success"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="mb-0">System Status</h6>
                            <small class="text-muted">All systems operational</small>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="small">Database:</span>
                        <span class="small fw-bold text-success">Active</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="small">Size:</span>
                        <span class="small fw-bold"><?php echo $systemStats['db_size_mb'] ?? 0; ?> MB</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="small">Last 24h logs:</span>
                        <span class="small fw-bold"><?php echo $systemStats['logs_24h'] ?? 0; ?></span>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">MailerSend:</span>
                            <span class="badge bg-<?php echo $mailersendConfig['is_active'] ? 'success' : 'secondary'; ?>">
                                <?php echo $mailersendConfig['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between small mt-1">
                            <span class="text-muted">Fonnte:</span>
                            <span class="badge bg-<?php echo $fonnteConfig['is_active'] ? 'success' : 'secondary'; ?>">
                                <?php echo $fonnteConfig['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between small mt-1">
                            <span class="text-muted">Backups:</span>
                            <span class="badge bg-info"><?php echo count($backupFiles); ?> files</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Content Area -->
        <div class="col-lg-9">
            <div class="settings-content">
                
                <!-- ========================================
                    1. GENERAL SETTINGS - DATABASE INTEGRATED
                ======================================== -->
                <?php if ($activeTab === 'general'): ?>
                <div class="settings-section">
                    <div class="settings-section-title">
                        <h3><i class="fas fa-cog"></i>Pengaturan Umum</h3>
                    </div>
                    
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="update_general">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Nama Aplikasi</label>
                                <input type="text" class="form-control" name="app_name" 
                                       value="<?php echo htmlspecialchars(getSettingValue('app_name', 'Responsive Message App')); ?>" required>
                                <small class="text-muted"><?php echo getSettingValue('app_name_description', 'Nama aplikasi yang akan ditampilkan'); ?></small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">URL Aplikasi</label>
                                <input type="url" class="form-control" name="app_url" 
                                       value="<?php echo htmlspecialchars(getSettingValue('app_url', BASE_URL)); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Nama Sekolah</label>
                                <input type="text" class="form-control" name="school_name" 
                                       value="<?php echo htmlspecialchars(getSettingValue('school_name', 'SMKN 12 Jakarta')); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Email Admin</label>
                                <input type="email" class="form-control" name="admin_email" 
                                       value="<?php echo htmlspecialchars(getSettingValue('admin_email', 'admin@smkn12jakarta.sch.id')); ?>">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label fw-bold">Alamat Sekolah</label>
                                <textarea class="form-control" name="school_address" rows="2"><?php echo htmlspecialchars(getSettingValue('school_address', '')); ?></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Telepon</label>
                                <input type="text" class="form-control" name="school_phone" 
                                       value="<?php echo htmlspecialchars(getSettingValue('school_phone', '')); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Email Sekolah</label>
                                <input type="email" class="form-control" name="school_email" 
                                       value="<?php echo htmlspecialchars(getSettingValue('school_email', '')); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Zona Waktu</label>
                                <select class="form-select" name="timezone">
                                    <option value="Asia/Jakarta" <?php echo getSettingValue('timezone', 'Asia/Jakarta') === 'Asia/Jakarta' ? 'selected' : ''; ?>>WIB (Jakarta)</option>
                                    <option value="Asia/Makassar" <?php echo getSettingValue('timezone', 'Asia/Jakarta') === 'Asia/Makassar' ? 'selected' : ''; ?>>WITA (Makassar)</option>
                                    <option value="Asia/Jayapura" <?php echo getSettingValue('timezone', 'Asia/Jakarta') === 'Asia/Jayapura' ? 'selected' : ''; ?>>WIT (Jayapura)</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Format Tanggal</label>
                                <select class="form-select" name="date_format">
                                    <option value="d/m/Y" <?php echo getSettingValue('date_format', 'd/m/Y') === 'd/m/Y' ? 'selected' : ''; ?>>31/12/2025</option>
                                    <option value="Y-m-d" <?php echo getSettingValue('date_format', 'd/m/Y') === 'Y-m-d' ? 'selected' : ''; ?>>2025-12-31</option>
                                    <option value="m/d/Y" <?php echo getSettingValue('date_format', 'd/m/Y') === 'm/d/Y' ? 'selected' : ''; ?>>12/31/2025</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Item per Halaman</label>
                                <input type="number" class="form-control" name="items_per_page" 
                                       value="<?php echo (int)getSettingValue('items_per_page', 10); ?>" min="5" max="100">
                            </div>
                            <div class="col-12 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="enable_registration" 
                                           id="enableRegistration" <?php echo getSettingValue('enable_registration', '1') == '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="enableRegistration">
                                        Izinkan pendaftaran pengguna baru
                                    </label>
                                </div>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="require_email_verification" 
                                           id="requireEmailVerification" <?php echo getSettingValue('require_email_verification', '0') == '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="requireEmailVerification">
                                        Wajib verifikasi email
                                    </label>
                                </div>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="maintenance_mode" 
                                           id="maintenanceMode" <?php echo getSettingValue('maintenance_mode', '0') == '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="maintenanceMode">
                                        Mode pemeliharaan
                                    </label>
                                    <small class="d-block text-muted mt-1">
                                        Saat diaktifkan, hanya admin yang dapat mengakses sistem
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Simpan Pengaturan
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- ========================================
                    2. MESSAGE TYPES - FULL CRUD
                ======================================== -->
                <?php if ($activeTab === 'message_types'): ?>
                <div class="settings-section">
                    <div class="settings-section-title">
                        <h3><i class="fas fa-tags"></i>Jenis Pesan</h3>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMessageTypeModal">
                            <i class="fas fa-plus me-1"></i>Tambah Baru
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Jenis Pesan</th>
                                    <th>Deskripsi</th>
                                    <th class="text-center">Responder</th>
                                    <th class="text-center">SLA (jam)</th>
                                    <th class="text-center">External</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Pesan</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($messageTypes as $type): ?>
                                <tr>
                                    <td class="fw-medium">#<?php echo $type['id']; ?></td>
                                    <td>
                                        <span class="fw-medium">
                                            <i class="<?php echo htmlspecialchars($type['icon_class'] ?? 'fas fa-envelope'); ?> me-2" style="color: <?php echo htmlspecialchars($type['color_code'] ?? '#0d6efd'); ?>"></i>
                                            <?php echo htmlspecialchars($type['jenis_pesan']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($type['deskripsi'] ?? '-', 0, 50)); ?></td>
                                    <td class="text-center">
                                        <span class="badge" style="background-color: <?php echo htmlspecialchars($type['color_code'] ?? '#0d6efd'); ?>20; color: <?php echo htmlspecialchars($type['color_code'] ?? '#0d6efd'); ?>; padding: 0.5rem 1rem;">
                                            <?php echo str_replace('Guru_', '', $type['responder_type'] ?? 'Guru BK'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info bg-opacity-10 text-info px-3 py-2">
                                            <?php echo $type['response_deadline_hours']; ?>h
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($type['allow_external']): ?>
                                        <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2">
                                            <i class="fas fa-external-link-alt me-1"></i>Ya
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2">
                                            Tidak
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($type['is_active']): ?>
                                        <span class="badge-active px-3 py-2">
                                            <i class="fas fa-check-circle me-1"></i>Aktif
                                        </span>
                                        <?php else: ?>
                                        <span class="badge-inactive px-3 py-2">
                                            <i class="fas fa-times-circle me-1"></i>Nonaktif
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?php echo $type['message_count'] ?? 0; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editMessageType(<?php echo $type['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if (($type['message_count'] ?? 0) == 0): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus jenis pesan ini?')">
                                                <input type="hidden" name="action" value="delete_message_type">
                                                <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Add Message Type Modal -->
                <div class="modal fade" id="addMessageTypeModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="fas fa-plus-circle me-2 text-primary"></i>
                                        Tambah Jenis Pesan Baru
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="add_message_type">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Nama Jenis Pesan *</label>
                                        <input type="text" class="form-control" name="jenis_pesan" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Deskripsi</label>
                                        <textarea class="form-control" name="deskripsi" rows="2"></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Batas Waktu Respons (jam)</label>
                                            <input type="number" class="form-control" name="response_deadline_hours" value="72" min="1" max="720">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Responder Type</label>
                                            <select class="form-select" name="responder_type">
                                                <option value="Guru_BK">Guru BK</option>
                                                <option value="Guru_Humas">Guru Humas</option>
                                                <option value="Guru_Kurikulum">Guru Kurikulum</option>
                                                <option value="Guru_Kesiswaan">Guru Kesiswaan</option>
                                                <option value="Guru_Sarana">Guru Sarana</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Warna</label>
                                            <input type="color" class="form-control form-control-color" name="color_code" value="#0d6efd">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Icon Class</label>
                                            <select class="form-select" name="icon_class">
                                                <option value="fas fa-envelope">📧 Envelope</option>
                                                <option value="fas fa-comments">💬 Comments</option>
                                                <option value="fas fa-handshake">🤝 Handshake</option>
                                                <option value="fas fa-book">📚 Book</option>
                                                <option value="fas fa-users">👥 Users</option>
                                                <option value="fas fa-school">🏫 School</option>
                                                <option value="fas fa-question">❓ Question</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="allow_external" id="allowExternal" checked>
                                                <label class="form-check-label" for="allowExternal">
                                                    Izinkan pengirim eksternal
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive" checked>
                                                <label class="form-check-label" for="isActive">
                                                    Aktif
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Simpan
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Message Type Modal -->
                <div class="modal fade" id="editMessageTypeModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST" id="editMessageTypeForm">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="fas fa-edit me-2 text-warning"></i>
                                        Edit Jenis Pesan
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="edit_message_type">
                                    <input type="hidden" name="id" id="edit_type_id">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Nama Jenis Pesan *</label>
                                        <input type="text" class="form-control" name="jenis_pesan" id="edit_jenis_pesan" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Deskripsi</label>
                                        <textarea class="form-control" name="deskripsi" id="edit_deskripsi" rows="2"></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Batas Waktu Respons (jam)</label>
                                            <input type="number" class="form-control" name="response_deadline_hours" id="edit_response_deadline_hours" min="1" max="720">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Responder Type</label>
                                            <select class="form-select" name="responder_type" id="edit_responder_type">
                                                <option value="Guru_BK">Guru BK</option>
                                                <option value="Guru_Humas">Guru Humas</option>
                                                <option value="Guru_Kurikulum">Guru Kurikulum</option>
                                                <option value="Guru_Kesiswaan">Guru Kesiswaan</option>
                                                <option value="Guru_Sarana">Guru Sarana</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Warna</label>
                                            <input type="color" class="form-control form-control-color" name="color_code" id="edit_color_code">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Icon Class</label>
                                            <select class="form-select" name="icon_class" id="edit_icon_class">
                                                <option value="fas fa-envelope">📧 Envelope</option>
                                                <option value="fas fa-comments">💬 Comments</option>
                                                <option value="fas fa-handshake">🤝 Handshake</option>
                                                <option value="fas fa-book">📚 Book</option>
                                                <option value="fas fa-users">👥 Users</option>
                                                <option value="fas fa-school">🏫 School</option>
                                                <option value="fas fa-question">❓ Question</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="allow_external" id="edit_allow_external">
                                                <label class="form-check-label" for="edit_allow_external">
                                                    Izinkan pengirim eksternal
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                                <label class="form-check-label" for="edit_is_active">
                                                    Aktif
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-save me-1"></i>Update
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- ========================================
                    3. RESPONSE TEMPLATES - FULL CRUD
                ======================================== -->
                <?php if ($activeTab === 'templates'): ?>
                <div class="settings-section">
                    <div class="settings-section-title">
                        <h3><i class="fas fa-sticky-note"></i>Template Respons</h3>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTemplateModal">
                            <i class="fas fa-plus me-1"></i>Tambah Template
                        </button>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <?php foreach ($templates as $template): ?>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="mb-0 fw-bold">
                                            <?php echo htmlspecialchars($template['name']); ?>
                                            <?php if ($template['guru_type'] === 'ALL'): ?>
                                            <span class="badge bg-info ms-2">Global</span>
                                            <?php else: ?>
                                            <span class="badge bg-primary ms-2"><?php echo str_replace('Guru_', '', $template['guru_type']); ?></span>
                                            <?php endif; ?>
                                        </h6>
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-chart-line me-1"></i><?php echo $template['use_count']; ?>x
                                        </span>
                                    </div>
                                    
                                    <div class="template-preview mb-2">
                                        <?php echo nl2br(htmlspecialchars(substr($template['content'], 0, 150))); ?>
                                        <?php if (strlen($template['content']) > 150): ?>...<?php endif; ?>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-light text-dark me-1">
                                                <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($template['category']); ?>
                                            </span>
                                            <span class="badge bg-<?php echo $template['is_active'] ? 'success' : 'secondary'; ?> bg-opacity-10">
                                                <?php echo $template['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                            </span>
                                        </div>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editTemplate(<?php echo $template['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus template ini?')">
                                                <input type="hidden" name="action" value="delete_template">
                                                <input type="hidden" name="id" value="<?php echo $template['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Add Template Modal -->
                <div class="modal fade" id="addTemplateModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="fas fa-plus-circle me-2 text-success"></i>
                                        Tambah Template Respons
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="add_template">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Nama Template *</label>
                                        <input type="text" class="form-control" name="name" required>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Kategori</label>
                                            <select class="form-select" name="category">
                                                <option value="Umum">Umum</option>
                                                <option value="Persetujuan">Persetujuan</option>
                                                <option value="Penolakan">Penolakan</option>
                                                <option value="Informasi">Informasi</option>
                                                <option value="External">External</option>
                                                <option value="Follow-up">Follow-up</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Default Status</label>
                                            <select class="form-select" name="default_status">
                                                <option value="Disetujui">Disetujui</option>
                                                <option value="Ditolak">Ditolak</option>
                                                <option value="Selesai">Selesai</option>
                                                <option value="Diproses">Diproses</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Tipe Guru</label>
                                        <select class="form-select" name="guru_type">
                                            <option value="ALL">Semua Guru</option>
                                            <option value="Guru_BK">Guru BK</option>
                                            <option value="Guru_Humas">Guru Humas</option>
                                            <option value="Guru_Kurikulum">Guru Kurikulum</option>
                                            <option value="Guru_Kesiswaan">Guru Kesiswaan</option>
                                            <option value="Guru_Sarana">Guru Sarana</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Konten Template *</label>
                                        <textarea class="form-control" name="content" rows="6" required></textarea>
                                        <small class="text-muted">
                                            Gunakan template untuk respons cepat. Minimal 10 karakter.
                                        </small>
                                    </div>
                                    
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="templateActive" checked>
                                        <label class="form-check-label" for="templateActive">
                                            Aktif
                                        </label>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save me-1"></i>Simpan Template
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Template Modal -->
                <div class="modal fade" id="editTemplateModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST" id="editTemplateForm">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="fas fa-edit me-2 text-warning"></i>
                                        Edit Template Respons
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="edit_template">
                                    <input type="hidden" name="id" id="edit_template_id">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Nama Template *</label>
                                        <input type="text" class="form-control" name="name" id="edit_template_name" required>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Kategori</label>
                                            <select class="form-select" name="category" id="edit_template_category">
                                                <option value="Umum">Umum</option>
                                                <option value="Persetujuan">Persetujuan</option>
                                                <option value="Penolakan">Penolakan</option>
                                                <option value="Informasi">Informasi</option>
                                                <option value="External">External</option>
                                                <option value="Follow-up">Follow-up</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Default Status</label>
                                            <select class="form-select" name="default_status" id="edit_template_default_status">
                                                <option value="Disetujui">Disetujui</option>
                                                <option value="Ditolak">Ditolak</option>
                                                <option value="Selesai">Selesai</option>
                                                <option value="Diproses">Diproses</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Tipe Guru</label>
                                        <select class="form-select" name="guru_type" id="edit_template_guru_type">
                                            <option value="ALL">Semua Guru</option>
                                            <option value="Guru_BK">Guru BK</option>
                                            <option value="Guru_Humas">Guru Humas</option>
                                            <option value="Guru_Kurikulum">Guru Kurikulum</option>
                                            <option value="Guru_Kesiswaan">Guru Kesiswaan</option>
                                            <option value="Guru_Sarana">Guru Sarana</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Konten Template *</label>
                                        <textarea class="form-control" name="content" id="edit_template_content" rows="6" required></textarea>
                                    </div>
                                    
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="edit_template_is_active">
                                        <label class="form-check-label" for="edit_template_is_active">
                                            Aktif
                                        </label>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-save me-1"></i>Update Template
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- ========================================
                    4. USER MANAGEMENT
                ======================================== -->
                <?php if ($activeTab === 'users'): ?>
                <div class="settings-section">
                    <div class="settings-section-title">
                        <h3><i class="fas fa-users-cog"></i>Manajemen Pengguna</h3>
                    </div>
                    
                    <div class="stats-card mb-4">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="fw-bold mb-1"><?php echo $systemStats['total_users']; ?></h3>
                                    <small>Total Pengguna</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="fw-bold mb-1"><?php echo $systemStats['active_users']; ?></h3>
                                    <small>Aktif</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="fw-bold mb-1"><?php echo $systemStats['total_users'] - $systemStats['active_users']; ?></h3>
                                    <small>Nonaktif</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="fw-bold mb-1"><?php echo $systemStats['total_external']; ?></h3>
                                    <small>Eksternal</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Nama Lengkap</th>
                                    <th>Tipe</th>
                                    <th>Email</th>
                                    <th class="text-center">Messages</th>
                                    <th class="text-center">Responses</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>#<?php echo $user['id']; ?></td>
                                    <td>
                                        <span class="fw-medium"><?php echo htmlspecialchars($user['nama_lengkap'] ?? '-'); ?></span>
                                        <?php if ($user['user_type'] === 'Admin'): ?>
                                        <span class="badge bg-danger ms-2">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($user['user_type']) {
                                                'Admin' => 'danger',
                                                'Siswa' => 'success',
                                                default => 'info'
                                            };
                                        ?> bg-opacity-10 px-3 py-2">
                                            <?php echo str_replace('_', ' ', $user['user_type'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2">
                                            <?php echo $user['total_messages'] ?? 0; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-success bg-opacity-10 text-success px-3 py-2">
                                            <?php echo $user['total_responses'] ?? 0; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($user['is_active']): ?>
                                        <span class="badge-active px-3 py-2">
                                            <i class="fas fa-check-circle me-1"></i>Aktif
                                        </span>
                                        <?php else: ?>
                                        <span class="badge-inactive px-3 py-2">
                                            <i class="fas fa-times-circle me-1"></i>Nonaktif
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="update_user_status">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo $user['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-<?php echo $user['is_active'] ? 'warning' : 'success'; ?>">
                                                    <i class="fas fa-<?php echo $user['is_active'] ? 'ban' : 'check'; ?>"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Reset password pengguna ini? Password baru akan dikirim ke email.')">
                                                <input type="hidden" name="action" value="reset_user_password">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-info">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- ========================================
                    5. NOTIFICATIONS - MAILERSEND & FONNTE (DATABASE INTEGRATED)
                ======================================== -->
                <?php if ($activeTab === 'notifications'): ?>
                <div class="settings-section">
                    <div class="settings-section-title">
                        <h3><i class="fas fa-bell"></i>Konfigurasi Notifikasi</h3>
                        <div>
                            <span class="mailersend-badge me-2">MailerSend</span>
                            <span class="whatsapp-badge">Fonnte</span>
                        </div>
                    </div>
                    
                    <div class="alert alert-info bg-light border-0 mb-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-info-circle fa-2x me-3 text-primary"></i>
                            <div>
                                <strong>Status Layanan Notifikasi:</strong><br>
                                <span class="badge bg-success me-2">MailerSend: <?php echo $mailersendConfig['from_email'] ?: 'Belum dikonfigurasi'; ?></span>
                                <span class="badge bg-success">Fonnte Device: <?php echo $fonnteConfig['device_id'] ?: 'Belum dikonfigurasi'; ?></span>
                                <div class="mt-2">
                                    <a href="<?php echo BASE_URL; ?>logs/email_debug.log" target="_blank" class="btn btn-sm btn-outline-secondary me-2">
                                        <i class="fas fa-file-alt me-1"></i>Log Email
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>logs/whatsapp_success.log" target="_blank" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-file-alt me-1"></i>Log WhatsApp
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-4">
                        <!-- MAILERSEND CONFIGURATION -->
                        <div class="col-lg-6" id="mailersend">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white py-3 border-bottom">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-primary bg-opacity-10">
                                                <i class="fas fa-envelope text-primary"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="mb-0 fw-bold">MailerSend Configuration</h6>
                                            <small class="text-muted">Email Notification Service</small>
                                        </div>
                                        <?php if ($mailersendConfig['is_active']): ?>
                                        <span class="badge-active px-3 py-2">
                                            <i class="fas fa-check-circle me-1"></i>Aktif
                                        </span>
                                        <?php else: ?>
                                        <span class="badge-inactive px-3 py-2">
                                            <i class="fas fa-times-circle me-1"></i>Nonaktif
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_mailersend">
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-key me-1 text-primary"></i>API Token
                                            </label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" name="api_token" id="api_token"
                                                       value="<?php echo htmlspecialchars($mailersendConfig['api_token']); ?>"
                                                       placeholder="mlsn.xxx...">
                                                <button class="btn btn-outline-secondary" type="button" onclick="toggleField('api_token')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Domain</label>
                                                <input type="text" class="form-control" name="domain" 
                                                       value="<?php echo htmlspecialchars($mailersendConfig['domain']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Domain ID</label>
                                                <input type="text" class="form-control" name="domain_id" 
                                                       value="<?php echo htmlspecialchars($mailersendConfig['domain_id']); ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">From Email</label>
                                            <input type="email" class="form-control" name="from_email" 
                                                   value="<?php echo htmlspecialchars($mailersendConfig['from_email']); ?>"
                                                   placeholder="noreply@domain.com" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">From Name</label>
                                            <input type="text" class="form-control" name="from_name" 
                                                   value="<?php echo htmlspecialchars($mailersendConfig['from_name']); ?>">
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">SMTP Server</label>
                                                <input type="text" class="form-control" name="smtp_server" 
                                                       value="<?php echo htmlspecialchars($mailersendConfig['smtp_server']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">SMTP Port</label>
                                                <input type="number" class="form-control" name="smtp_port" 
                                                       value="<?php echo $mailersendConfig['smtp_port']; ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">SMTP Username</label>
                                                <input type="text" class="form-control" name="smtp_username" 
                                                       value="<?php echo htmlspecialchars($mailersendConfig['smtp_username']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">SMTP Password</label>
                                                <div class="input-group">
                                                    <input type="password" class="form-control" name="smtp_password" id="smtp_password"
                                                           value="<?php echo htmlspecialchars($mailersendConfig['smtp_password']); ?>">
                                                    <button class="btn btn-outline-secondary" type="button" onclick="toggleField('smtp_password')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">SMTP Encryption</label>
                                            <select class="form-select" name="smtp_encryption">
                                                <option value="tls" <?php echo $mailersendConfig['smtp_encryption'] == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                                <option value="ssl" <?php echo $mailersendConfig['smtp_encryption'] == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                                <option value="none" <?php echo $mailersendConfig['smtp_encryption'] == 'none' ? 'selected' : ''; ?>>None</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Test Domain</label>
                                            <input type="text" class="form-control" name="test_domain" 
                                                   value="<?php echo htmlspecialchars($mailersendConfig['test_domain']); ?>">
                                        </div>
                                        
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" name="is_active" 
                                                   id="mailersendActive" <?php echo $mailersendConfig['is_active'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="mailersendActive">
                                                Aktifkan MailerSend
                                            </label>
                                        </div>
                                        
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary flex-grow-1">
                                                <i class="fas fa-save me-1"></i>Simpan
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <!-- Test Connection & Send Email Section -->
                                    <div class="test-card mt-4">
                                        <h6 class="fw-bold mb-3">
                                            <i class="fas fa-vial me-2 text-info"></i>Test MailerSend
                                        </h6>
                                        
                                        <form method="POST" class="mb-3">
                                            <input type="hidden" name="action" value="test_mailersend_connection">
                                            <input type="hidden" name="api_token" value="<?php echo htmlspecialchars($mailersendConfig['api_token']); ?>">
                                            <input type="hidden" name="from_email" value="<?php echo htmlspecialchars($mailersendConfig['from_email']); ?>">
                                            <input type="hidden" name="from_name" value="<?php echo htmlspecialchars($mailersendConfig['from_name']); ?>">
                                            <input type="hidden" name="domain" value="<?php echo htmlspecialchars($mailersendConfig['domain']); ?>">
                                            <button type="submit" class="btn btn-outline-info w-100">
                                                <i class="fas fa-plug me-1"></i>Test Koneksi
                                            </button>
                                        </form>
                                        
                                        <form method="POST">
                                            <input type="hidden" name="action" value="send_test_email">
                                            <input type="hidden" name="api_token" value="<?php echo htmlspecialchars($mailersendConfig['api_token']); ?>">
                                            <input type="hidden" name="from_email" value="<?php echo htmlspecialchars($mailersendConfig['from_email']); ?>">
                                            <input type="hidden" name="from_name" value="<?php echo htmlspecialchars($mailersendConfig['from_name']); ?>">
                                            <input type="hidden" name="domain" value="<?php echo htmlspecialchars($mailersendConfig['domain']); ?>">
                                            
                                            <label class="form-label fw-bold">Kirim Test Email</label>
                                            <div class="input-group mb-2">
                                                <input type="email" class="form-control" name="test_email" 
                                                       placeholder="email@example.com" required>
                                                <button type="submit" class="btn btn-success">
                                                    <i class="fas fa-paper-plane me-1"></i>Kirim
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- FONNTE CONFIGURATION -->
                        <div class="col-lg-6" id="fonnte">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white py-3 border-bottom">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-success bg-opacity-10">
                                                <i class="fab fa-whatsapp text-success"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="mb-0 fw-bold">Fonnte Configuration</h6>
                                            <small class="text-muted">WhatsApp Notification Service</small>
                                        </div>
                                        <?php if ($fonnteConfig['is_active']): ?>
                                        <span class="badge-active px-3 py-2">
                                            <i class="fas fa-check-circle me-1"></i>Aktif
                                        </span>
                                        <?php else: ?>
                                        <span class="badge-inactive px-3 py-2">
                                            <i class="fas fa-times-circle me-1"></i>Nonaktif
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_fonnte">
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-key me-1 text-success"></i>API Token
                                            </label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" name="fonnte_api_token" id="fonnte_api_token"
                                                       value="<?php echo htmlspecialchars($fonnteConfig['api_token']); ?>"
                                                       placeholder="FS2cq8FckmaTegxtZpFB">
                                                <button class="btn btn-outline-secondary" type="button" onclick="toggleField('fonnte_api_token')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-id-card me-1 text-success"></i>Account Token
                                            </label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" name="account_token" id="account_token"
                                                       value="<?php echo htmlspecialchars($fonnteConfig['account_token']); ?>"
                                                       placeholder="hzCktiDwSP1sfdXt4PrNtmFkaamX">
                                                <button class="btn btn-outline-secondary" type="button" onclick="toggleField('account_token')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-mobile-alt me-1 text-success"></i>Device ID / Nomor
                                            </label>
                                            <input type="text" class="form-control" name="device_id" 
                                                   value="<?php echo htmlspecialchars($fonnteConfig['device_id']); ?>"
                                                   placeholder="6285174207795">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-globe me-1 text-success"></i>API URL
                                            </label>
                                            <input type="url" class="form-control" name="api_url" 
                                                   value="<?php echo htmlspecialchars($fonnteConfig['api_url']); ?>"
                                                   placeholder="https://api.fonnte.com/send">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Country Code</label>
                                            <input type="text" class="form-control" name="country_code" 
                                                   value="<?php echo htmlspecialchars($fonnteConfig['country_code'] ?? '62'); ?>"
                                                   placeholder="62">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Email (Optional)</label>
                                            <input type="email" class="form-control" name="fonnte_email" 
                                                   value="<?php echo htmlspecialchars($fonnteConfig['email']); ?>">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Password (Optional)</label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" name="fonnte_password" id="fonnte_password"
                                                       value="<?php echo htmlspecialchars($fonnteConfig['password']); ?>">
                                                <button class="btn btn-outline-secondary" type="button" onclick="toggleField('fonnte_password')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" name="fonnte_is_active" 
                                                   id="fonnteActive" <?php echo $fonnteConfig['is_active'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="fonnteActive">
                                                Aktifkan Fonnte
                                            </label>
                                        </div>
                                        
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-success flex-grow-1">
                                                <i class="fas fa-save me-1"></i>Simpan
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <!-- Test Connection & Send WhatsApp Section -->
                                    <div class="test-card mt-4">
                                        <h6 class="fw-bold mb-3">
                                            <i class="fas fa-vial me-2 text-info"></i>Test Fonnte
                                        </h6>
                                        
                                        <form method="POST" class="mb-3">
                                            <input type="hidden" name="action" value="test_fonnte_connection">
                                            <input type="hidden" name="fonnte_api_token" value="<?php echo htmlspecialchars($fonnteConfig['api_token']); ?>">
                                            <input type="hidden" name="device_id" value="<?php echo htmlspecialchars($fonnteConfig['device_id']); ?>">
                                            <input type="hidden" name="api_url" value="<?php echo htmlspecialchars($fonnteConfig['api_url']); ?>">
                                            <input type="hidden" name="country_code" value="<?php echo htmlspecialchars($fonnteConfig['country_code'] ?? '62'); ?>">
                                            <button type="submit" class="btn btn-outline-info w-100">
                                                <i class="fas fa-plug me-1"></i>Test Koneksi
                                            </button>
                                        </form>
                                        
                                        <form method="POST">
                                            <input type="hidden" name="action" value="send_test_whatsapp">
                                            <input type="hidden" name="fonnte_api_token" value="<?php echo htmlspecialchars($fonnteConfig['api_token']); ?>">
                                            <input type="hidden" name="api_url" value="<?php echo htmlspecialchars($fonnteConfig['api_url']); ?>">
                                            <input type="hidden" name="country_code" value="<?php echo htmlspecialchars($fonnteConfig['country_code'] ?? '62'); ?>">
                                            <input type="hidden" name="device_id" value="<?php echo htmlspecialchars($fonnteConfig['device_id']); ?>">
                                            
                                            <label class="form-label fw-bold">Kirim Test WhatsApp</label>
                                            <div class="input-group mb-2">
                                                <input type="text" class="form-control" name="test_phone" 
                                                       placeholder="081234567890" required>
                                                <button type="submit" class="btn btn-success">
                                                    <i class="fab fa-whatsapp me-1"></i>Kirim
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- ========================================
                    6. SYSTEM & SECURITY
                ======================================== -->
                <?php if ($activeTab === 'system'): ?>
                <div class="settings-section">
                    <div class="settings-section-title">
                        <h3><i class="fas fa-shield-alt"></i>Sistem & Keamanan</h3>
                    </div>
                    
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white border-bottom-0 pt-3">
                                    <h6 class="mb-0 fw-bold">
                                        <i class="fas fa-database me-2 text-primary"></i>
                                        Database Maintenance
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="?tab=backup">
                                        <input type="hidden" name="action" value="backup_database">
                                        <p class="small text-muted mb-3">
                                            Buat backup database lengkap termasuk semua tabel, data, dan struktur.
                                        </p>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-outline-primary">
                                                <i class="fas fa-database me-2"></i>Backup Database Sekarang
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <hr class="my-4">
                                    
                                    <form method="POST">
                                        <input type="hidden" name="action" value="clear_logs">
                                        <label class="form-label fw-bold">Bersihkan Log</label>
                                        <div class="input-group mb-3">
                                            <input type="number" class="form-control" name="days" value="30" min="1" max="365">
                                            <span class="input-group-text">hari</span>
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-outline-warning" onclick="return confirm('Hapus log audit lebih dari 30 hari?')">
                                                <i class="fas fa-trash-alt me-2"></i>Hapus Log Lama
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white border-bottom-0 pt-3">
                                    <h6 class="mb-0 fw-bold">
                                        <i class="fas fa-file-export me-2 text-success"></i>
                                        Ekspor / Impor Konfigurasi
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="export_config">
                                        <p class="small text-muted mb-3">
                                            Ekspor semua pengaturan, jenis pesan, template, dan konfigurasi notifikasi.
                                        </p>
                                        <div class="d-grid mb-4">
                                            <button type="submit" class="btn btn-outline-success">
                                                <i class="fas fa-download me-2"></i>Ekspor Konfigurasi
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <form method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="action" value="import_config">
                                        <label class="form-label fw-bold">Impor Konfigurasi</label>
                                        <div class="mb-3">
                                            <input type="file" class="form-control" name="config_file" accept=".json" required>
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-outline-warning" onclick="return confirm('Impor konfigurasi akan menimpa data yang ada? Lanjutkan?')">
                                                <i class="fas fa-upload me-2"></i>Impor Konfigurasi
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Manajemen Akun Pimpinan -->
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="mb-0 fw-bold">
                                        <i class="fas fa-user-tie me-2 text-warning"></i>
                                        Manajemen Akun Pimpinan
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">Kelola akun Kepala Sekolah dan Wakil Kepala Sekolah:</p>
                                    <div class="d-flex gap-2">
                                        <a href="manage_users.php?type=Kepala_Sekolah" class="btn btn-primary">
                                            <i class="fas fa-user-tie me-1"></i> Kelola Kepala Sekolah
                                        </a>
                                        <a href="manage_users.php?type=Wakil_Kepala" class="btn btn-info">
                                            <i class="fas fa-user-graduate me-1"></i> Kelola Wakil Kepala
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="mb-0 fw-bold">
                                        <i class="fas fa-info-circle me-2 text-info"></i>
                                        Informasi Sistem
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <small class="text-muted d-block">PHP Version</small>
                                            <span class="fw-medium"><?php echo phpversion(); ?></span>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <small class="text-muted d-block">MySQL Version</small>
                                            <span class="fw-medium"><?php echo $db->getAttribute(PDO::ATTR_SERVER_VERSION); ?></span>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <small class="text-muted d-block">Server</small>
                                            <span class="fw-medium"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Apache'; ?></span>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <small class="text-muted d-block">Database Size</small>
                                            <span class="fw-medium"><?php echo $systemStats['db_size_mb'] ?? 0; ?> MB</span>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <small class="text-muted d-block">Host</small>
                                            <span class="fw-medium"><?php echo DB_HOST; ?>:<?php echo DB_PORT; ?></span>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <small class="text-muted d-block">Database</small>
                                            <span class="fw-medium"><?php echo DB_NAME; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- ========================================
                    7. AUDIT TRAIL
                ======================================== -->
                <?php if ($activeTab === 'audit'): ?>
                <div class="settings-section">
                    <div class="settings-section-title">
                        <h3><i class="fas fa-history"></i>Audit Trail</h3>
                        <span class="badge bg-primary">50 Log Terbaru</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>Waktu</th>
                                    <th>Pengguna</th>
                                    <th>Aksi</th>
                                    <th>Tabel</th>
                                    <th>ID</th>
                                    <th>Deskripsi</th>
                                    <th>IP Address</th>
                                 </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td>
                                        <span class="fw-medium"><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($log['action_type']) {
                                                'CREATE' => 'success',
                                                'UPDATE' => 'info',
                                                'DELETE' => 'danger',
                                                'BACKUP' => 'primary',
                                                'RESTORE' => 'warning',
                                                'IMPORT' => 'warning',
                                                'CLEANUP' => 'secondary',
                                                'TEST' => 'info',
                                                default => 'secondary'
                                            };
                                        ?> bg-opacity-10 px-3 py-2">
                                            <?php echo $log['action_type']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['table_name'] ?? '-'); ?></td>
                                    <td><?php echo $log['record_id'] ?? '-'; ?></td>
                                    <td>
                                        <small><?php echo htmlspecialchars($log['new_value'] ?? '-'); ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- ========================================
                    8. BACKUP & RESTORE
                ======================================== -->
                <?php if ($activeTab === 'backup'): ?>
                <div class="settings-section">
                    <div class="settings-section-title">
                        <h3><i class="fas fa-database"></i>Backup & Restore Database</h3>
                        <span class="badge bg-info">MySQL <?php echo DB_HOST; ?>:<?php echo DB_PORT; ?></span>
                    </div>
                    
                    <div class="alert alert-info">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-info-circle fa-2x me-3 text-primary"></i>
                            <div>
                                <strong>Informasi Database:</strong><br>
                                Host: <?php echo DB_HOST; ?> | Port: <?php echo DB_PORT; ?> | Database: <?php echo DB_NAME; ?><br>
                                Backup disimpan di folder <code><?php echo BACKUP_DIR; ?></code>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-4">
                        <!-- Create Backup -->
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white py-3">
                                    <h6 class="mb-0 fw-bold">
                                        <i class="fas fa-download me-2 text-primary"></i>
                                        Buat Backup Baru
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">
                                        Membuat backup lengkap database termasuk semua tabel, data, dan struktur.
                                    </p>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="backup_database">
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="fas fa-database me-2"></i>Backup Sekarang
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <div class="backup-stats mt-4">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Total Backup Files:</span>
                                            <span class="badge bg-white text-primary"><?php echo count($backupFiles); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-2">
                                            <span>Total Size:</span>
                                            <span class="badge bg-white text-primary">
                                                <?php 
                                                $totalSize = array_sum(array_column($backupFiles, 'size'));
                                                echo formatFileSize($totalSize);
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Restore Database -->
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white py-3">
                                    <h6 class="mb-0 fw-bold">
                                        <i class="fas fa-upload me-2 text-success"></i>
                                        Restore Database
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">
                                        Pilih file backup (.sql, .zip, .gz) untuk direstore. Semua data akan diganti.
                                    </p>
                                    <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('PERINGATAN: Semua data yang ada akan ditimpa! Pastikan Anda memiliki backup terbaru. Lanjutkan restore?')">
                                        <input type="hidden" name="action" value="restore_database">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Pilih File Backup</label>
                                            <input type="file" class="form-control" name="backup_file" 
                                                   accept=".sql,.zip,.gz" required>
                                            <small class="text-muted">Format yang didukung: .sql, .zip, .gz</small>
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-warning btn-lg">
                                                <i class="fas fa-upload me-2"></i>Restore Database
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- List of backups -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-archive me-2 text-warning"></i>
                                Daftar Backup Tersedia (<?php echo count($backupFiles); ?>)
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($backupFiles)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-database fa-4x text-muted mb-3"></i>
                                <h6 class="text-muted">Belum ada file backup</h6>
                                <p class="small text-muted">Klik tombol "Backup Sekarang" untuk membuat backup pertama</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Nama File</th>
                                            <th>Ukuran</th>
                                            <th>Tanggal</th>
                                            <th class="text-end">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($backupFiles as $file): ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-file-code me-2 text-secondary"></i>
                                                <?php echo htmlspecialchars($file['name']); ?>
                                            </td>
                                            <td><?php echo $file['size_formatted']; ?></td>
                                            <td><?php echo $file['date_formatted']; ?></td>
                                            <td class="text-end">
                                                <div class="btn-group">
                                                    <a href="<?php echo BASE_URL; ?>backups/<?php echo urlencode($file['name']); ?>" 
                                                       class="btn btn-sm btn-outline-primary" download>
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Hapus file backup <?php echo htmlspecialchars($file['name']); ?>?')">
                                                        <input type="hidden" name="action" value="delete_backup">
                                                        <input type="hidden" name="filename" value="<?php echo htmlspecialchars($file['name']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Instructions -->
                    <div class="alert alert-secondary mt-4">
                        <h6 class="fw-bold mb-2"><i class="fas fa-info-circle me-2"></i>Panduan Backup & Restore:</h6>
                        <ul class="mb-0 small">
                            <li>Backup menggunakan <code>mysqldump</code> jika tersedia, fallback ke metode PHP jika tidak</li>
                            <li>File backup disimpan di folder <code><?php echo BACKUP_DIR; ?></code></li>
                            <li>Format file: <code>backup_nama database_tanggal.sql</code></li>
                            <li>Untuk restore, upload file .sql, .zip, atau .gz (maksimal 50MB)</li>
                            <li>Selalu backup sebelum melakukan restore untuk keamanan data</li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
</div>

<script>
// Toggle field visibility untuk password/token
function toggleField(fieldId) {
    const field = document.getElementById(fieldId);
    if (field) {
        const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
        field.setAttribute('type', type);
    } else {
        const fields = document.getElementsByName(fieldId);
        if (fields.length > 0) {
            const field = fields[0];
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
        }
    }
}

// Edit Message Type - FULLY IMPLEMENTED
function editMessageType(id) {
    // Fetch message type data via AJAX
    fetch(`api/get_message_type.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const type = data.data;
                document.getElementById('edit_type_id').value = type.id;
                document.getElementById('edit_jenis_pesan').value = type.jenis_pesan;
                document.getElementById('edit_deskripsi').value = type.deskripsi || '';
                document.getElementById('edit_response_deadline_hours').value = type.response_deadline_hours;
                document.getElementById('edit_responder_type').value = type.responder_type || 'Guru_BK';
                document.getElementById('edit_color_code').value = type.color_code || '#0d6efd';
                document.getElementById('edit_icon_class').value = type.icon_class || 'fas fa-envelope';
                document.getElementById('edit_allow_external').checked = type.allow_external == 1;
                document.getElementById('edit_is_active').checked = type.is_active == 1;
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('editMessageTypeModal'));
                modal.show();
            } else {
                alert('Gagal mengambil data: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat mengambil data');
        });
}

// Edit Template - FULLY IMPLEMENTED
function editTemplate(id) {
    // Fetch template data via AJAX
    fetch(`api/get_template.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const template = data.data;
                document.getElementById('edit_template_id').value = template.id;
                document.getElementById('edit_template_name').value = template.name;
                document.getElementById('edit_template_category').value = template.category || 'Umum';
                document.getElementById('edit_template_default_status').value = template.default_status || 'Disetujui';
                document.getElementById('edit_template_guru_type').value = template.guru_type || 'ALL';
                document.getElementById('edit_template_content').value = template.content;
                document.getElementById('edit_template_is_active').checked = template.is_active == 1;
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('editTemplateModal'));
                modal.show();
            } else {
                alert('Gagal mengambil data: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat mengambil data');
        });
}

// Confirm backup restore
function confirmRestore() {
    return confirm('PERINGATAN: Semua data yang ada akan ditimpa! Pastikan Anda memiliki backup terbaru. Lanjutkan restore?');
}

// Auto-refresh every 10 minutes
setTimeout(() => {
    if (confirm('Sesi Anda akan segera berakhir. Perbarui halaman?')) {
        location.reload();
    }
}, 600000); // 10 menit
</script>

<?php require_once '../../includes/footer.php'; ?>