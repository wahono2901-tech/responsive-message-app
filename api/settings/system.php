<?php
/**
 * API System Settings
 * File: api/settings/system.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cookie');

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check authentication
Auth::checkAuth();

// Get database connection
$db = Database::getInstance()->getConnection();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
$action = $_POST['action'] ?? $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'stats') {
                getSystemStats($db);
            } elseif ($action === 'info') {
                getSystemInfo($db);
            } else {
                // Get all system data
                $stats = getSystemStatsData($db);
                $info = getSystemInfoData($db);
                echo json_encode([
                    'success' => true,
                    'stats' => $stats,
                    'info' => $info
                ]);
            }
            break;
            
        case 'POST':
            switch ($action) {
                case 'clear_logs':
                    clearOldLogs($db, $input);
                    break;
                case 'export_config':
                    exportConfig($db);
                    break;
                case 'import_config':
                    importConfig($db, $_FILES);
                    break;
                default:
                    throw new Exception('Invalid action');
            }
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ============================================
// FUNGSI GET DATA
// ============================================

function getSystemStatsData($db) {
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
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getSystemStats($db) {
    $stats = getSystemStatsData($db);
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
}

function getSystemInfoData($db) {
    return [
        'php_version' => phpversion(),
        'mysql_version' => $db->getAttribute(PDO::ATTR_SERVER_VERSION),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Apache',
        'db_host' => DB_HOST,
        'db_port' => DB_PORT,
        'db_name' => DB_NAME,
        'os' => PHP_OS,
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get()
    ];
}

function getSystemInfo($db) {
    $info = getSystemInfoData($db);
    echo json_encode([
        'success' => true,
        'info' => $info
    ]);
}

// ============================================
// FUNGSI CLEAR LOGS
// ============================================

function clearOldLogs($db, $input) {
    $days = (int)($input['days'] ?? 30);
    
    if ($days < 1 || $days > 365) {
        throw new Exception('Jumlah hari harus antara 1-365');
    }
    
    // Get count before delete
    $sql = "SELECT COUNT(*) as total FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
    $stmt = $db->prepare($sql);
    $stmt->execute([$days]);
    $count = $stmt->fetch()['total'];
    
    // Delete old logs
    $sql = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
    $stmt = $db->prepare($sql);
    $stmt->execute([$days]);
    $deleted = $stmt->rowCount();
    
    // Log audit
    logAudit($db, $_SESSION['user_id'], 'CLEANUP', 'audit_logs', 0, "Cleared $deleted log entries older than $days days");
    
    echo json_encode([
        'success' => true,
        'message' => "Berhasil membersihkan $deleted entri log",
        'deleted' => $deleted,
        'total_before' => $count
    ]);
}

// ============================================
// FUNGSI EXPORT CONFIG
// ============================================

function exportConfig($db) {
    $config = [
        'message_types' => [],
        'templates' => [],
        'settings' => [],
        'mailersend' => [],
        'fonnte' => []
    ];
    
    // Get message types
    $sql = "SELECT * FROM message_types ORDER BY id";
    $stmt = $db->query($sql);
    $config['message_types'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get templates
    $sql = "SELECT * FROM response_templates ORDER BY id";
    $stmt = $db->query($sql);
    $config['templates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get general settings
    if (file_exists(ROOT_PATH . '/config/settings.json')) {
        $config['settings'] = json_decode(file_get_contents(ROOT_PATH . '/config/settings.json'), true);
    }
    
    // Get MailerSend config
    if (file_exists(ROOT_PATH . '/config/mailersend.json')) {
        $config['mailersend'] = json_decode(file_get_contents(ROOT_PATH . '/config/mailersend.json'), true);
    }
    
    // Get Fonnte config
    if (file_exists(ROOT_PATH . '/config/fonnte.json')) {
        $config['fonnte'] = json_decode(file_get_contents(ROOT_PATH . '/config/fonnte.json'), true);
    }
    
    // Log audit
    logAudit($db, $_SESSION['user_id'], 'EXPORT', 'config', 0, "Configuration exported");
    
    echo json_encode([
        'success' => true,
        'config' => $config,
        'exported_at' => date('Y-m-d H:i:s')
    ]);
}

// ============================================
// FUNGSI IMPORT CONFIG
// ============================================

function importConfig($db, $files) {
    if (!isset($files['config_file']) || $files['config_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File tidak ditemukan atau gagal diupload');
    }
    
    $content = file_get_contents($files['config_file']['tmp_name']);
    $config = json_decode($content, true);
    
    if (!$config) {
        throw new Exception('File konfigurasi tidak valid');
    }
    
    $imported = [];
    
    // Import message types
    if (!empty($config['message_types'])) {
        $sql = "INSERT INTO message_types (id, jenis_pesan, deskripsi, response_deadline_hours, allow_external, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                jenis_pesan = VALUES(jenis_pesan), deskripsi = VALUES(deskripsi),
                response_deadline_hours = VALUES(response_deadline_hours),
                allow_external = VALUES(allow_external), is_active = VALUES(is_active),
                updated_at = NOW()";
        $stmt = $db->prepare($sql);
        
        foreach ($config['message_types'] as $type) {
            $stmt->execute([
                $type['id'],
                $type['jenis_pesan'],
                $type['deskripsi'] ?? '',
                $type['response_deadline_hours'] ?? 72,
                $type['allow_external'] ?? 1,
                $type['is_active'] ?? 1
            ]);
            $imported['message_types'] = ($imported['message_types'] ?? 0) + 1;
        }
    }
    
    // Import templates
    if (!empty($config['templates'])) {
        $sql = "INSERT INTO response_templates (id, name, content, category, default_status, guru_type, is_active, use_count, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                name = VALUES(name), content = VALUES(content), category = VALUES(category),
                default_status = VALUES(default_status), guru_type = VALUES(guru_type),
                is_active = VALUES(is_active), updated_at = NOW()";
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
            $imported['templates'] = ($imported['templates'] ?? 0) + 1;
        }
    }
    
    // Import MailerSend config
    if (!empty($config['mailersend'])) {
        $mailerFile = ROOT_PATH . '/config/mailersend.json';
        file_put_contents($mailerFile, json_encode($config['mailersend'], JSON_PRETTY_PRINT));
        $imported['mailersend'] = true;
    }
    
    // Import Fonnte config
    if (!empty($config['fonnte'])) {
        $fonnteFile = ROOT_PATH . '/config/fonnte.json';
        file_put_contents($fonnteFile, json_encode($config['fonnte'], JSON_PRETTY_PRINT));
        $imported['fonnte'] = true;
    }
    
    // Log audit
    logAudit($db, $_SESSION['user_id'], 'IMPORT', 'config', 0, "Configuration imported: " . json_encode($imported));
    
    echo json_encode([
        'success' => true,
        'message' => 'Konfigurasi berhasil diimpor',
        'imported' => $imported
    ]);
}

// ============================================
// LOG AUDIT FUNCTION
// ============================================

function logAudit($db, $userId, $action, $table, $recordId, $description) {
    try {
        if (strlen($description) > 50000) {
            $description = substr($description, 0, 50000) . '... (truncated)';
        }
        
        $sql = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, old_value, new_value, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $userId,
            $action,
            $table,
            $recordId,
            null,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Exception in logAudit: " . $e->getMessage());
    }
}