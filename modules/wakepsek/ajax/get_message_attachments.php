<?php
/**
 * AJAX Handler untuk Mendapatkan Lampiran Gambar Pesan
 * File: modules/wakepsek/ajax/get_message_attachments.php
 * 
 * FITUR:
 * - Mengambil semua lampiran gambar untuk message_id tertentu
 * - Mengembalikan data dalam format JSON
 * - Menyertakan informasi filepath, filename, filesize, virus status
 * - Error handling yang komprehensif
 */

// ============================================================================
// ERROR REPORTING & LOGGING
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error ke output
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/ajax_errors.log');

// Set header JSON
header('Content-Type: application/json');

// ============================================================================
// LOAD KONFIGURASI
// ============================================================================
require_once '../../../config/config.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/functions.php';

// ============================================================================
// FUNGSI LOGGING
// ============================================================================
function ajaxLog($message, $data = null) {
    $logDir = __DIR__ . '/../../../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $logFile = $logDir . '/ajax_attachments.log';
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents($logFile, $log, FILE_APPEND);
}

ajaxLog("========== GET_MESSAGE_ATTACHMENTS START ==========");
ajaxLog("Request received", $_GET);

// ============================================================================
// CEK AUTHENTICATION
// ============================================================================
try {
    Auth::checkAuth();
    ajaxLog("Auth check passed");
} catch (Exception $e) {
    ajaxLog("Auth failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Session tidak valid. Silakan login ulang.'
    ]);
    exit;
}

// ============================================================================
// CEK AKSES - HANYA WAKEPSEK DAN KEPSEK
// ============================================================================
$allowedTypes = ['Wakil_Kepala', 'Kepala_Sekolah'];
$userType = $_SESSION['user_type'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

if (!in_array($userType, $allowedTypes)) {
    ajaxLog("Access denied - User type: $userType");
    echo json_encode([
        'success' => false,
        'error' => 'Anda tidak memiliki akses ke halaman ini.'
    ]);
    exit;
}

ajaxLog("User authorized", ['user_id' => $userId, 'user_type' => $userType]);

// ============================================================================
// VALIDASI INPUT
// ============================================================================
$messageId = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;

if ($messageId <= 0) {
    ajaxLog("Invalid message_id: $messageId");
    echo json_encode([
        'success' => false,
        'error' => 'ID pesan tidak valid.'
    ]);
    exit;
}

ajaxLog("Processing message_id: $messageId");

// ============================================================================
// KONEKSI DATABASE
// ============================================================================
try {
    $db = Database::getInstance()->getConnection();
    ajaxLog("Database connected");
} catch (Exception $e) {
    ajaxLog("Database connection failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Gagal terhubung ke database.'
    ]);
    exit;
}

// ============================================================================
// CEK APAKAH USER MEMILIKI AKSES KE PESAN INI
// ============================================================================
try {
    // Cek apakah pesan ada
    $checkStmt = $db->prepare("SELECT id, is_external FROM messages WHERE id = ?");
    $checkStmt->execute([$messageId]);
    $message = $checkStmt->fetch();
    
    if (!$message) {
        ajaxLog("Message not found: $messageId");
        echo json_encode([
            'success' => false,
            'error' => 'Pesan tidak ditemukan.'
        ]);
        exit;
    }
    
    ajaxLog("Message found", $message);
    
} catch (Exception $e) {
    ajaxLog("Error checking message: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Gagal memeriksa pesan.'
    ]);
    exit;
}

// ============================================================================
// AMBIL DATA ATTACHMENTS
// ============================================================================
try {
    $sql = "
        SELECT 
            id,
            message_id,
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
        AND virus_scan_status IN ('Clean', 'Pending')
        ORDER BY created_at ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$messageId]);
    $attachments = $stmt->fetchAll();
    
    ajaxLog("Attachments found: " . count($attachments));
    
    // Format data untuk JSON
    $formattedAttachments = [];
    foreach ($attachments as $att) {
        // Pastikan filepath lengkap dengan BASE_URL
        $filepath = $att['filepath'];
        
        // Format file size
        $filesize = $att['filesize'] ? (int)$att['filesize'] : 0;
        
        $formattedAttachments[] = [
            'id' => $att['id'],
            'message_id' => $att['message_id'],
            'filename' => $att['filename'],
            'filepath' => ltrim($filepath, '/'), // Hapus leading slash
            'filetype' => $att['filetype'],
            'filesize' => $filesize,
            'virus_scan_status' => $att['virus_scan_status'] ?? 'Pending',
            'download_count' => (int)($att['download_count'] ?? 0),
            'created_at' => $att['created_at']
        ];
    }
    
    // ============================================================================
    // KIRIM RESPONSE JSON
    // ============================================================================
    $response = [
        'success' => true,
        'attachments' => $formattedAttachments,
        'is_external' => $message['is_external'] ?? 0,
        'count' => count($formattedAttachments)
    ];
    
    ajaxLog("Response prepared", [
        'count' => count($formattedAttachments),
        'is_external' => $message['is_external'] ?? 0
    ]);
    
    echo json_encode($response);
    
} catch (Exception $e) {
    ajaxLog("ERROR: " . $e->getMessage());
    ajaxLog("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => 'Gagal memuat lampiran: ' . $e->getMessage()
    ]);
}

ajaxLog("========== GET_MESSAGE_ATTACHMENTS END ==========");
?>