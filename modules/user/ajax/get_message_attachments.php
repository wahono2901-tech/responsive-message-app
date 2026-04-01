<?php
/**
 * AJAX Handler untuk Mendapatkan Lampiran Pesan
 * File: modules/user/ajax/get_message_attachments.php
 * 
 * Fungsi: Mengembalikan daftar lampiran untuk pesan tertentu
 */

require_once '../../../config/config.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Enable error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . '/logs/attachments_errors.log');

// Buat direktori logs jika belum ada
$logDir = ROOT_PATH . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

function attachmentsLog($message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    error_log($log);
}

attachmentsLog("========== GET MESSAGE ATTACHMENTS START ==========");
attachmentsLog("GET parameters", $_GET);

try {
    // Cek authentication
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Session tidak ditemukan. Silakan login ulang.');
    }
    
    Auth::checkAuth();
    attachmentsLog("Auth check passed");
    
    $user_id = $_SESSION['user_id'];
    
    // Validasi message_id
    $message_id = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;
    if (!$message_id) {
        throw new Exception('ID pesan tidak valid');
    }
    attachmentsLog("Processing message_id: " . $message_id);
    
    // Koneksi database
    $db = Database::getInstance()->getConnection();
    if (!$db) {
        throw new Exception('Gagal koneksi database');
    }
    attachmentsLog("Database connected");
    
    // Define BASE_URL if not defined
    if (!defined('BASE_URL')) {
        define('BASE_URL', $GLOBALS['BASE_URL'] ?? '/smasys');
    }
    
    // Define upload paths
    define('UPLOAD_PATH_MESSAGES', ROOT_PATH . '/uploads/messages/');
    define('UPLOAD_PATH_EXTERNAL', ROOT_PATH . '/uploads/external_messages/');
    define('BASE_URL_UPLOAD_MESSAGES', BASE_URL . '/uploads/messages/');
    define('BASE_URL_UPLOAD_EXTERNAL', BASE_URL . '/uploads/external_messages/');
    
    // Placeholder image
    $placeholder_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f8f9fa"/><text x="50" y="50" font-family="Arial" font-size="12" fill="#adb5bd" text-anchor="middle" dy=".3em">No Image</text></svg>';
    $placeholder_image = 'data:image/svg+xml;base64,' . base64_encode($placeholder_svg);
    
    // Cek apakah user memiliki akses ke pesan ini
    $checkStmt = $db->prepare("SELECT id, is_external FROM messages WHERE id = ? AND pengirim_id = ?");
    $checkStmt->execute([$message_id, $user_id]);
    $message = $checkStmt->fetch();
    
    if (!$message) {
        attachmentsLog("ERROR: Message not found or access denied for ID: " . $message_id);
        throw new Exception('Pesan tidak ditemukan atau Anda tidak memiliki akses');
    }
    
    attachmentsLog("Message found", ['is_external' => $message['is_external']]);
    
    // Get attachments
    $attach_sql = "
        SELECT 
            id,
            message_id,
            user_id,
            filename,
            filepath,
            filetype,
            filesize,
            is_approved,
            virus_scan_status,
            download_count,
            created_at
        FROM message_attachments 
        WHERE message_id = ? 
        AND is_approved = 1 
        ORDER BY created_at ASC
    ";
    
    $attach_stmt = $db->prepare($attach_sql);
    $attach_stmt->execute([$message_id]);
    $attachments = $attach_stmt->fetchAll();
    
    attachmentsLog("Attachments found", ['count' => count($attachments)]);
    
    // Process attachments
    $processed_attachments = [];
    
    foreach ($attachments as $att) {
        // Buat URL file
        $file_url = '';
        
        if (!empty($att['filepath'])) {
            // Jika filepath sudah ada, gunakan langsung
            if (strpos($att['filepath'], 'http://') === 0 || strpos($att['filepath'], 'https://') === 0) {
                $file_url = $att['filepath'];
            } else {
                $clean_path = ltrim($att['filepath'], '/');
                $file_url = BASE_URL . '/' . $clean_path;
            }
        } else {
            // Fallback ke cara lama (menggunakan filename)
            if ($message['is_external']) {
                $file_url = BASE_URL_UPLOAD_EXTERNAL . $att['filename'];
            } else {
                $file_url = BASE_URL_UPLOAD_MESSAGES . $att['filename'];
            }
        }
        
        // Cek apakah file exists
        $file_exists = false;
        if (!empty($att['filepath'])) {
            $full_path = ROOT_PATH . '/' . ltrim($att['filepath'], '/');
            $file_exists = file_exists($full_path);
        }
        
        // Format ukuran file
        $file_size_formatted = 'Unknown';
        if (!empty($att['filesize'])) {
            $size = (int)$att['filesize'];
            if ($size < 1024) {
                $file_size_formatted = $size . ' B';
            } elseif ($size < 1048576) {
                $file_size_formatted = round($size / 1024, 1) . ' KB';
            } else {
                $file_size_formatted = round($size / 1048576, 1) . ' MB';
            }
        }
        
        // Format tanggal upload
        $date_formatted = !empty($att['created_at']) 
            ? date('d M Y H:i', strtotime($att['created_at']))
            : '-';
        
        // Tentukan status virus scan
        $virus_status = $att['virus_scan_status'] ?? 'Pending';
        $virus_badge = match($virus_status) {
            'Clean' => 'success',
            'Pending' => 'warning',
            'Infected' => 'danger',
            'Error' => 'secondary',
            default => 'secondary'
        };
        
        $processed_attachments[] = [
            'id' => (int)$att['id'],
            'message_id' => (int)$att['message_id'],
            'filename' => (string)$att['filename'],
            'filepath' => (string)$att['filepath'],
            'filetype' => (string)$att['filetype'],
            'filesize' => (int)$att['filesize'],
            'file_size_formatted' => $file_size_formatted,
            'virus_scan_status' => $virus_status,
            'virus_badge' => $virus_badge,
            'download_count' => (int)$att['download_count'],
            'created_at' => $att['created_at'],
            'date_formatted' => $date_formatted,
            'file_url' => $file_url,
            'file_exists' => $file_exists
        ];
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'attachments' => $processed_attachments,
        'is_external' => (bool)$message['is_external'],
        'count' => count($processed_attachments),
        'message_id' => $message_id
    ]);
    
    attachmentsLog("Response sent successfully", [
        'count' => count($processed_attachments)
    ]);
    
} catch (Exception $e) {
    attachmentsLog("ERROR: " . $e->getMessage());
    attachmentsLog("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'message_id' => $message_id ?? 0,
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'time' => date('Y-m-d H:i:s')
        ]
    ]);
}

attachmentsLog("========== GET MESSAGE ATTACHMENTS END ==========\n");
?>