<?php
/**
 * General Settings API
 * File: api/settings/general.php
 * 
 * Endpoints:
 * - GET: Mendapatkan semua pengaturan umum
 * - POST: Menyimpan pengaturan umum
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

// Set header untuk JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cookie');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Verify authentication
$user = verifyAuth();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if user has admin privileges
if (!in_array($user['user_type'], ['Admin', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: Admin access required']);
    exit();
}

// Get database connection
$db = Database::getInstance()->getConnection();

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleGetGeneralSettings($db);
} elseif ($method === 'POST') {
    handlePostGeneralSettings($db, $user);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

/**
 * Handle GET request - Get all general settings
 */
function handleGetGeneralSettings($db) {
    try {
        // Define all general settings keys
        $settingsKeys = [
            'app_name', 'app_version', 'school_name', 'school_address', 
            'school_phone', 'school_email', 'admin_email', 'timezone',
            'date_format', 'time_format', 'items_per_page', 
            'enable_registration', 'require_email_verification', 'maintenance_mode',
            'session_timeout', 'max_login_attempts', 'message_limit_per_day',
            'default_response_deadline', 'enable_whatsapp', 'enable_email',
            'enable_sms', 'backup_retention_days', 'auto_backup_time'
        ];
        
        // Get settings from database
        $placeholders = implode(',', array_fill(0, count($settingsKeys), '?'));
        $sql = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $stmt->execute($settingsKeys);
        
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        // Set default values for missing settings
        $defaults = [
            'app_name' => 'Responsive Message App',
            'app_version' => '1.0.0',
            'school_name' => 'SMKN 12 Jakarta',
            'school_address' => '',
            'school_phone' => '',
            'school_email' => '',
            'admin_email' => '',
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'd/m/Y',
            'time_format' => 'H:i:s',
            'items_per_page' => '10',
            'enable_registration' => '1',
            'require_email_verification' => '0',
            'maintenance_mode' => '0',
            'session_timeout' => '3600',
            'max_login_attempts' => '5',
            'message_limit_per_day' => '10',
            'default_response_deadline' => '72',
            'enable_whatsapp' => '1',
            'enable_email' => '1',
            'enable_sms' => '0',
            'backup_retention_days' => '30',
            'auto_backup_time' => '02:00'
        ];
        
        foreach ($defaults as $key => $defaultValue) {
            if (!isset($settings[$key])) {
                $settings[$key] = $defaultValue;
                // Insert missing setting
                $insertSql = "INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description, is_public, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->execute([$key, $defaultValue, $this->getSettingType($key), 'General', $this->getSettingDescription($key), 1]);
            }
        }
        
        echo json_encode([
            'success' => true,
            'settings' => $settings
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error retrieving settings: ' . $e->getMessage()
        ]);
    }
}

/**
 * Handle POST request - Save general settings
 */
function handlePostGeneralSettings($db, $user) {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
        return;
    }
    
    $action = isset($input['action']) ? $input['action'] : '';
    
    if ($action !== 'update_general') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        return;
    }
    
    try {
        // Define all settings to update
        $settingsToUpdate = [
            'app_name' => $input['app_name'] ?? '',
            'school_name' => $input['school_name'] ?? '',
            'school_address' => $input['school_address'] ?? '',
            'school_phone' => $input['school_phone'] ?? '',
            'school_email' => $input['school_email'] ?? '',
            'admin_email' => $input['admin_email'] ?? '',
            'timezone' => $input['timezone'] ?? 'Asia/Jakarta',
            'date_format' => $input['date_format'] ?? 'd/m/Y',
            'time_format' => $input['time_format'] ?? 'H:i:s',
            'items_per_page' => $input['items_per_page'] ?? '10',
            'enable_registration' => isset($input['enable_registration']) ? $input['enable_registration'] : '0',
            'require_email_verification' => isset($input['require_email_verification']) ? $input['require_email_verification'] : '0',
            'maintenance_mode' => isset($input['maintenance_mode']) ? $input['maintenance_mode'] : '0',
            'session_timeout' => $input['session_timeout'] ?? '3600',
            'max_login_attempts' => $input['max_login_attempts'] ?? '5',
            'message_limit_per_day' => $input['message_limit_per_day'] ?? '10',
            'default_response_deadline' => $input['default_response_deadline'] ?? '72',
            'enable_whatsapp' => isset($input['enable_whatsapp']) ? $input['enable_whatsapp'] : '1',
            'enable_email' => isset($input['enable_email']) ? $input['enable_email'] : '1',
            'enable_sms' => isset($input['enable_sms']) ? $input['enable_sms'] : '0',
            'backup_retention_days' => $input['backup_retention_days'] ?? '30',
            'auto_backup_time' => $input['auto_backup_time'] ?? '02:00'
        ];
        
        // Update each setting
        $updateSql = "UPDATE system_settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = :key";
        $updateStmt = $db->prepare($updateSql);
        
        $updatedCount = 0;
        foreach ($settingsToUpdate as $key => $value) {
            $updateStmt->execute([
                ':value' => $value,
                ':key' => $key
            ]);
            if ($updateStmt->rowCount() > 0) {
                $updatedCount++;
            }
        }
        
        // Log activity
        logActivity($db, $user['id'], 'UPDATE', 'system_settings', 0, null, json_encode($settingsToUpdate), 'General settings updated');
        
        echo json_encode([
            'success' => true,
            'message' => 'Pengaturan umum berhasil diperbarui',
            'updated_count' => $updatedCount
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error saving settings: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get setting type based on key
 */
function getSettingType($key) {
    $types = [
        'app_name' => 'string',
        'app_version' => 'string',
        'school_name' => 'string',
        'school_address' => 'text',
        'school_phone' => 'string',
        'school_email' => 'string',
        'admin_email' => 'string',
        'timezone' => 'string',
        'date_format' => 'string',
        'time_format' => 'string',
        'items_per_page' => 'integer',
        'enable_registration' => 'boolean',
        'require_email_verification' => 'boolean',
        'maintenance_mode' => 'boolean',
        'session_timeout' => 'integer',
        'max_login_attempts' => 'integer',
        'message_limit_per_day' => 'integer',
        'default_response_deadline' => 'integer',
        'enable_whatsapp' => 'boolean',
        'enable_email' => 'boolean',
        'enable_sms' => 'boolean',
        'backup_retention_days' => 'integer',
        'auto_backup_time' => 'string'
    ];
    
    return $types[$key] ?? 'string';
}

/**
 * Get setting description based on key
 */
function getSettingDescription($key) {
    $descriptions = [
        'app_name' => 'Nama Aplikasi',
        'app_version' => 'Versi Aplikasi',
        'school_name' => 'Nama Sekolah',
        'school_address' => 'Alamat Sekolah',
        'school_phone' => 'Telepon Sekolah',
        'school_email' => 'Email Sekolah',
        'admin_email' => 'Email Admin',
        'timezone' => 'Zona Waktu',
        'date_format' => 'Format Tanggal',
        'time_format' => 'Format Waktu',
        'items_per_page' => 'Item per Halaman',
        'enable_registration' => 'Izinkan Pendaftaran',
        'require_email_verification' => 'Wajib Verifikasi Email',
        'maintenance_mode' => 'Mode Pemeliharaan',
        'session_timeout' => 'Timeout Session',
        'max_login_attempts' => 'Maksimal Percobaan Login',
        'message_limit_per_day' => 'Batas Pesan per Hari',
        'default_response_deadline' => 'Default Deadline Respons',
        'enable_whatsapp' => 'Aktifkan WhatsApp',
        'enable_email' => 'Aktifkan Email',
        'enable_sms' => 'Aktifkan SMS',
        'backup_retention_days' => 'Retensi Backup',
        'auto_backup_time' => 'Waktu Auto Backup'
    ];
    
    return $descriptions[$key] ?? '';
}

/**
 * Log activity function
 */
function logActivity($db, $userId, $action, $table, $recordId, $oldValue, $newValue, $description) {
    try {
        $sql = "INSERT INTO system_logs (
            user_id, action_type, table_name, record_id, 
            old_value, new_value, description, ip_address, user_agent, created_at
        ) VALUES (
            :user_id, :action, :table_name, :record_id,
            :old_value, :new_value, :description, :ip, :ua, NOW()
        )";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':table_name' => $table,
            ':record_id' => $recordId,
            ':old_value' => $oldValue,
            ':new_value' => $newValue,
            ':description' => $description,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
    }
}
?>