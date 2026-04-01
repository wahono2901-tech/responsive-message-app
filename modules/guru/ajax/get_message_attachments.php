<?php
/**
 * AJAX Handler untuk Mendapatkan Lampiran Pesan
 * File: modules/guru/ajax/get_message_attachments.php
 * 
 * PERBAIKAN: Menghilangkan validasi AJAX request yang ketat
 * - Mengizinkan request dari halaman yang sama
 * - Menambahkan logging untuk debugging
 * - Menggunakan struktur tabel message_attachments yang benar (filename, filepath)
 * - Memperbaiki pembuatan URL file
 * - Menambahkan pengecekan file exists
 */

// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error ke output
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/attachments_errors.log');

require_once '../../../config/config.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Buat log file untuk debugging
$log_file = __DIR__ . '/../../../logs/attachments_debug.log';
if (!is_dir(dirname($log_file))) {
    mkdir(dirname($log_file), 0777, true);
}

function writeAttachmentsLog($message, $data = null) {
    global $log_file;
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents($log_file, $log, FILE_APPEND);
}

writeAttachmentsLog("========== GET MESSAGE ATTACHMENTS START ==========");
writeAttachmentsLog("GET parameters", $_GET);

try {
    // Check authentication - pastikan session ada
    if (!isset($_SESSION['user_id'])) {
        writeAttachmentsLog("ERROR: User not authenticated - session user_id tidak ada");
        throw new Exception('User tidak terautentikasi');
    }
    
    // Cek authentication dengan Auth class
    try {
        Auth::checkAuth();
    } catch (Exception $e) {
        writeAttachmentsLog("Auth check failed: " . $e->getMessage());
        throw new Exception('Unauthorized - ' . $e->getMessage());
    }
    
    writeAttachmentsLog("User authenticated", [
        'user_id' => $_SESSION['user_id'],
        'user_type' => $_SESSION['user_type'] ?? 'Unknown'
    ]);
    
    // ========== PERBAIKAN UTAMA ==========
    // TIDAK MELAKUKAN VALIDASI AJAX REQUEST - biarkan semua request masuk
    // Ini adalah penyebab utama error "Invalid request"
    // Baris berikut DIHAPUS/KOMENTAR:
    // if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    //     writeAttachmentsLog("ERROR: Invalid request - not AJAX");
    //     throw new Exception('Invalid request');
    // }
    
    writeAttachmentsLog("Request accepted - no AJAX validation performed");
    
    // Get parameters
    $message_id = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;
    writeAttachmentsLog("Message ID: " . $message_id);
    
    if (!$message_id || $message_id <= 0) {
        throw new Exception('ID pesan tidak valid');
    }
    
    // Define upload paths
    define('UPLOAD_PATH_MESSAGES', ROOT_PATH . '/uploads/messages/');
    define('UPLOAD_PATH_EXTERNAL', ROOT_PATH . '/uploads/external_messages/');
    define('BASE_URL_UPLOAD_MESSAGES', BASE_URL . '/uploads/messages/');
    define('BASE_URL_UPLOAD_EXTERNAL', BASE_URL . '/uploads/external_messages/');
    
    // Get database connection
    $db = Database::getInstance()->getConnection();
    if (!$db) {
        throw new Exception('Gagal koneksi database');
    }
    writeAttachmentsLog("Database connected");
    
    // Get message info untuk is_external
    $msg_sql = "SELECT is_external FROM messages WHERE id = ?";
    $msg_stmt = $db->prepare($msg_sql);
    if (!$msg_stmt) {
        throw new Exception('Gagal prepare statement: ' . implode(', ', $db->errorInfo()));
    }
    
    $result = $msg_stmt->execute([$message_id]);
    if (!$result) {
        throw new Exception('Gagal execute query: ' . implode(', ', $msg_stmt->errorInfo()));
    }
    
    $message = $msg_stmt->fetch();
    
    if (!$message) {
        writeAttachmentsLog("ERROR: Message not found for ID: " . $message_id);
        throw new Exception('Pesan tidak ditemukan');
    }
    
    writeAttachmentsLog("Message found", [
        'is_external' => $message['is_external']
    ]);
    
    // Get attachments - SESUAI STRUKTUR TABEL (filename, filepath)
    $attach_sql = "SELECT * FROM message_attachments WHERE message_id = ? ORDER BY created_at ASC";
    $attach_stmt = $db->prepare($attach_sql);
    if (!$attach_stmt) {
        throw new Exception('Gagal prepare statement attachments');
    }
    
    $attach_stmt->execute([$message_id]);
    $attachments = $attach_stmt->fetchAll();
    
    writeAttachmentsLog("Attachments found", [
        'count' => count($attachments),
        'raw_data' => $attachments
    ]);
    
    // Process attachments
    $processed_attachments = [];
    
    foreach ($attachments as $att) {
        // Gunakan filepath dari database
        $file_url = '';
        $file_exists = false;
        
        if (!empty($att['filepath'])) {
            // Jika filepath sudah ada, gunakan langsung
            if (strpos($att['filepath'], 'http://') === 0 || strpos($att['filepath'], 'https://') === 0) {
                $file_url = $att['filepath'];
            } else {
                // Pastikan path tidak dimulai dengan slash jika BASE_URL sudah memiliki slash
                $clean_path = ltrim($att['filepath'], '/');
                $file_url = BASE_URL . '/' . $clean_path;
            }
            
            // Cek apakah file exists
            $full_path = ROOT_PATH . '/' . ltrim($att['filepath'], '/');
            $file_exists = file_exists($full_path);
        } else {
            // Fallback ke cara lama (menggunakan filename)
            if ($message['is_external']) {
                $file_url = BASE_URL_UPLOAD_EXTERNAL . $att['filename'];
                $full_path = UPLOAD_PATH_EXTERNAL . $att['filename'];
            } else {
                $file_url = BASE_URL_UPLOAD_MESSAGES . $att['filename'];
                $full_path = UPLOAD_PATH_MESSAGES . $att['filename'];
            }
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
        
        // Format tanggal
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
        
        // Buat array attachment yang sudah diproses
        $processed = [
            'id' => (int)$att['id'],
            'message_id' => (int)$att['message_id'],
            'user_id' => (int)$att['user_id'],
            'filename' => (string)$att['filename'],
            'filepath' => (string)$att['filepath'],
            'filetype' => (string)$att['filetype'],
            'filesize' => (int)$att['filesize'],
            'file_size_formatted' => $file_size_formatted,
            'is_approved' => (int)$att['is_approved'],
            'virus_scan_status' => $virus_status,
            'virus_badge' => $virus_badge,
            'download_count' => (int)$att['download_count'],
            'created_at' => $att['created_at'],
            'date_formatted' => $date_formatted,
            'file_url' => $file_url,
            'file_exists' => $file_exists
        ];
        
        $processed_attachments[] = $processed;
        
        writeAttachmentsLog("Processed attachment", [
            'id' => $att['id'],
            'filename' => $att['filename'],
            'file_url' => $file_url,
            'file_exists' => $file_exists,
            'filesize' => $file_size_formatted,
            'virus_status' => $virus_status
        ]);
    }
    
    $response = [
        'success' => true,
        'attachments' => $processed_attachments,
        'is_external' => (bool)$message['is_external'],
        'count' => count($processed_attachments),
        'message_id' => $message_id
    ];
    
    writeAttachmentsLog("Sending response", [
        'success' => true,
        'count' => count($processed_attachments),
        'is_external' => $message['is_external']
    ]);
    
    echo json_encode($response);
    
} catch (Exception $e) {
    writeAttachmentsLog("ERROR: " . $e->getMessage());
    writeAttachmentsLog("Stack trace: " . $e->getTraceAsString());
    
    $error_response = [
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'message_id' => $message_id ?? 0,
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'time' => date('Y-m-d H:i:s')
        ]
    ];
    
    echo json_encode($error_response);
}

writeAttachmentsLog("========== GET MESSAGE ATTACHMENTS END ==========");
?>