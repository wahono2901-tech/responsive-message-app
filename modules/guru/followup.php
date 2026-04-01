<?php
/**
 * Guru Follow-up Interface - HANYA UNTUK GURU KHUSUS
 * File: modules/guru/followup.php
 * 
 * PERBAIKAN: UI/UX modal Detail Pesan dengan tampilan profesional
 * - Menggunakan card-based design tanpa tabel
 * - Label dengan warna putih agar terbaca di background gelap
 * - Layout grid yang modern dan responsif
 * - Visual hierarchy yang jelas
 * - Animasi halus dan efek hover
 * 
 * PERBAIKAN KHUSUS: 
 * - Memperbaiki modal Lampiran Gambar
 * - Menampilkan reference_number dan ID di header modal Detail Pesan
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================================================
// DEBUG INITIALIZATION (Opsional)
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../debug_error.log');

$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

define('FOLLOWUP_DEBUG_LOG', $logDir . '/followup_debug.log');
define('FOLLOWUP_ERROR_LOG', $logDir . '/followup_error.log');
define('FOLLOWUP_DELETE_LOG', $logDir . '/followup_delete.log');

// ============================================================================
// DEFINE UPLOAD PATHS FOR ATTACHMENTS
// ============================================================================
define('UPLOAD_PATH_MESSAGES', ROOT_PATH . '/uploads/messages/');
define('UPLOAD_PATH_EXTERNAL', ROOT_PATH . '/uploads/external_messages/');
define('BASE_URL_UPLOAD_MESSAGES', BASE_URL . '/uploads/messages/');
define('BASE_URL_UPLOAD_EXTERNAL', BASE_URL . '/uploads/external_messages/');

// Placeholder image (base64 SVG)
$placeholder_image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f8f9fa"/><text x="50" y="50" font-family="Arial" font-size="12" fill="#adb5bd" text-anchor="middle" dy=".3em">No Image</text></svg>');

file_put_contents(FOLLOWUP_DEBUG_LOG, "\n[" . date('Y-m-d H:i:s') . "] ========== FOLLOWUP.PHP START ==========\n", FILE_APPEND);
file_put_contents(FOLLOWUP_ERROR_LOG, "\n[" . date('Y-m-d H:i:s') . "] ========== FOLLOWUP.PHP START ==========\n", FILE_APPEND);

function writeFollowupLog($message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents(FOLLOWUP_DEBUG_LOG, $log, FILE_APPEND);
    error_log($log);
}

function writeFollowupError($message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] [ERROR] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents(FOLLOWUP_ERROR_LOG, $log, FILE_APPEND);
    error_log("[ERROR] " . $log);
}

function writeDeleteLog($message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] [DELETE] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents(FOLLOWUP_DELETE_LOG, $log, FILE_APPEND);
    error_log("[DELETE] " . $log);
}

writeFollowupLog("MEMULAI EKSEKUSI FOLLOWUP.PHP");

// ============================================================================
// DEBUG FUNCTION (Hanya aktif jika debug mode on)
// ============================================================================
$debug_steps = [];
$step_counter = 0;
$debug_enabled = isset($_COOKIE['debug_mode']) ? $_COOKIE['debug_mode'] === 'true' : false;

if (isset($_GET['debug'])) {
    $debug_enabled = $_GET['debug'] === 'on';
    setcookie('debug_mode', $debug_enabled ? 'true' : 'false', time() + 86400 * 30);
}

function debug_step($title, $data = null, $type = 'info') {
    global $debug_steps, $step_counter, $debug_enabled;
    
    if (!$debug_enabled) {
        return;
    }
    
    $step_counter++;
    
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = isset($backtrace[1]) ? $backtrace[1]['function'] : 'main';
    $line = isset($backtrace[0]['line']) ? $backtrace[0]['line'] : 'unknown';
    
    $current_date = date('Y-m-d H:i:s');
    
    $debug_steps[] = [
        'step' => $step_counter,
        'time' => date('H:i:s'),
        'title' => $title,
        'data' => $data,
        'type' => $type,
        'caller' => $caller,
        'line' => $line,
        'date' => $current_date
    ];
    
    $log_message = "[FOLLOWUP_DEBUG][{$current_date}][STEP {$step_counter}][{$caller}:{$line}] {$title}";
    if ($data !== null) {
        $log_message .= " - " . print_r($data, true);
    }
    error_log($log_message);
    writeFollowupLog("STEP {$step_counter}: {$title}", $data);
}

debug_step("=" . str_repeat("=", 70), null, 'separator');
debug_step("FOLLOWUP.PHP - MULAI EKSEKUSI", [
    'time' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'debug_enabled' => $debug_enabled
], 'start');

// ============================================
// CEK AUTHENTICATION
// ============================================
try {
    Auth::checkAuth();
    writeFollowupLog("Auth::checkAuth() SUCCESS");
} catch (Exception $e) {
    writeFollowupError("Auth::checkAuth() FAILED", $e->getMessage());
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ============================================
// DEFINISI GURU KHUSUS YANG BOLEH AKSES FOLLOWUP
// ============================================
// Hanya guru dengan tipe spesifik yang bisa mengakses halaman ini
$specialGuruTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'];
$userType = $_SESSION['user_type'] ?? '';

writeFollowupLog("CEK AKSES - User type: " . $userType);
debug_step("CEK AKSES - User type", $userType);

// CEK APAKAH USER ADALAH GURU KHUSUS
if (!in_array($userType, $specialGuruTypes)) {
    writeFollowupError("ACCESS DENIED - User type tidak diizinkan", $userType);
    debug_step("ACCESS DENIED", $userType, 'error');
    
    // Jika user adalah Guru biasa, redirect ke send_message
    if ($userType === 'Guru') {
        writeFollowupLog("Redirecting regular Guru to send_message.php");
        header('Location: ' . BASE_URL . '/modules/user/send_message.php');
        exit;
    }
    
    // Jika user adalah Admin, redirect ke dashboard
    if (in_array($userType, ['Admin', 'Kepala_Sekolah', 'Wakil_Kepala'])) {
        writeFollowupLog("Redirecting Admin to dashboard.php");
        header('Location: ' . BASE_URL . '/modules/admin/dashboard.php');
        exit;
    }
    
    // Jika bukan Guru sama sekali, redirect ke index
    writeFollowupLog("Redirecting non-guru to index with error");
    header('Location: ' . BASE_URL . '/index.php?error=access_denied');
    exit;
}

// ============================================
// AMBIL DATA GURU
// ============================================
$guruId = $_SESSION['user_id'];
$guruType = $_SESSION['user_type'];
$guruNama = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Guru';

writeFollowupLog("ACCESS GRANTED", [
    'guru_id' => $guruId,
    'guru_type' => $guruType,
    'guru_nama' => $guruNama
]);

debug_step("ACCESS GRANTED", [
    'guru_id' => $guruId,
    'guru_type' => $guruType,
    'guru_nama' => $guruNama
]);

// Filter parameters
$statusFilter = $_GET['status'] ?? 'pending';
$priorityFilter = $_GET['priority'] ?? 'all';
$sourceFilter = $_GET['source'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;

debug_step("Filter parameters", [
    'status' => $statusFilter,
    'priority' => $priorityFilter,
    'source' => $sourceFilter,
    'search' => $search,
    'page' => $page
]);

// Database connection
$db = Database::getInstance()->getConnection();
debug_step("Database connection", ['connected' => !empty($db)]);
writeFollowupLog("Database connected");

// ============================================================================
// ============================ FITUR DELETE PESAN ============================
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_message') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    $deleteReason = trim($_POST['delete_reason'] ?? '');
    $confirmDelete = isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'yes';
    
    writeDeleteLog("PERMINTAAN DELETE DITERIMA", [
        'message_id' => $messageId,
        'reason_length' => strlen($deleteReason),
        'confirmed' => $confirmDelete,
        'guru_id' => $guruId,
        'guru_type' => $guruType
    ]);
    
    debug_step("DELETE REQUEST", [
        'message_id' => $messageId,
        'reason' => $deleteReason,
        'confirmed' => $confirmDelete
    ], 'warning');
    
    if ($messageId <= 0) {
        $_SESSION['error_message'] = 'ID pesan tidak valid.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
    
    if (!$confirmDelete) {
        $_SESSION['error_message'] = 'Anda harus mengkonfirmasi penghapusan.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
    
    if (empty($deleteReason)) {
        $_SESSION['error_message'] = 'Alasan penghapusan harus diisi.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
    
    // Cek apakah pesan ini termasuk dalam jenis pesan guru
    // Perlu ambil messageTypeIds dulu
    $typeStmt = $db->prepare("SELECT id FROM message_types WHERE responder_type = ?");
    $typeStmt->execute([$guruType]);
    $messageTypeIds = array_column($typeStmt->fetchAll(), 'id');
    
    if (empty($messageTypeIds)) {
        $_SESSION['error_message'] = 'Tidak ada jenis pesan yang ditugaskan untuk Anda.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
    
    $placeholders = implode(',', array_fill(0, count($messageTypeIds), '?'));
    
    // Cek apakah pesan ini termasuk dalam jenis pesan guru
    $checkStmt = $db->prepare("
        SELECT m.*, mt.jenis_pesan, u.username as pengirim_username,
               (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id) as attachment_count
        FROM messages m
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users u ON m.pengirim_id = u.id
        WHERE m.id = ? AND m.jenis_pesan_id IN ($placeholders)
    ");
    
    $checkParams = array_merge([$messageId], $messageTypeIds);
    $checkStmt->execute($checkParams);
    $message = $checkStmt->fetch();
    
    writeDeleteLog("DETAIL PESAN YANG AKAN DIHAPUS", $message);
    
    if (!$message) {
        writeDeleteLog("ERROR: Pesan tidak ditemukan atau bukan jenis pesan guru ini");
        $_SESSION['error_message'] = 'Pesan tidak ditemukan atau Anda tidak memiliki akses untuk menghapus pesan ini.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
    
    // Cek apakah pesan sudah direspons
    $responseCheck = $db->prepare("SELECT id FROM message_responses WHERE message_id = ?");
    $responseCheck->execute([$messageId]);
    $hasResponse = $responseCheck->fetch();
    
    // Mulai transaksi
    $db->beginTransaction();
    
    try {
        // Ambil data attachment untuk dihapus filenya
        $attachStmt = $db->prepare("SELECT filepath, filename FROM message_attachments WHERE message_id = ?");
        $attachStmt->execute([$messageId]);
        $attachments = $attachStmt->fetchAll();
        
        // Simpan ke log penghapusan
        $logStmt = $db->prepare("
            INSERT INTO message_deletion_log 
            (message_id, deleted_by, deleted_by_type, delete_reason, message_data, has_response, original_status, attachment_count, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $messageData = json_encode([
            'isi_pesan' => $message['isi_pesan'],
            'jenis_pesan' => $message['jenis_pesan'],
            'pengirim' => $message['pengirim_username'] ?? 'Unknown',
            'is_external' => $message['is_external'],
            'priority' => $message['priority'],
            'created_at' => $message['created_at'],
            'attachment_count' => $message['attachment_count'] ?? 0
        ]);
        
        $logStmt->execute([
            $messageId,
            $guruId,
            $guruType,
            $deleteReason,
            $messageData,
            $hasResponse ? 1 : 0,
            $message['status'],
            $message['attachment_count'] ?? 0
        ]);
        
        writeDeleteLog("LOG PENGHAPUSAN DISIMPAN", [
            'log_id' => $db->lastInsertId()
        ]);
        
        // Hapus responses terkait jika ada
        if ($hasResponse) {
            $deleteResponses = $db->prepare("DELETE FROM message_responses WHERE message_id = ?");
            $deleteResponses->execute([$messageId]);
            writeDeleteLog("RESPONS TERKAIT DIHAPUS", [
                'rows' => $deleteResponses->rowCount()
            ]);
        }
        
        // Hapus reviews terkait jika ada
        $deleteReviews = $db->prepare("DELETE FROM wakepsek_reviews WHERE message_id = ?");
        $deleteReviews->execute([$messageId]);
        if ($deleteReviews->rowCount() > 0) {
            writeDeleteLog("REVIEW TERKAIT DIHAPUS", [
                'rows' => $deleteReviews->rowCount()
            ]);
        }
        
        // Hapus attachments dari database
        $deleteAttachments = $db->prepare("DELETE FROM message_attachments WHERE message_id = ?");
        $deleteAttachments->execute([$messageId]);
        writeDeleteLog("ATTACHMENTS DIHAPUS DARI DATABASE", [
            'rows' => $deleteAttachments->rowCount()
        ]);
        
        // Hapus pesan
        $deleteStmt = $db->prepare("DELETE FROM messages WHERE id = ?");
        $deleteStmt->execute([$messageId]);
        
        writeDeleteLog("PESAN BERHASIL DIHAPUS", [
            'rows' => $deleteStmt->rowCount()
        ]);
        
        $db->commit();
        
        // Hapus file fisik setelah transaksi sukses
        foreach ($attachments as $attachment) {
            $file_path = ROOT_PATH . '/' . ltrim($attachment['filepath'], '/');
            if (file_exists($file_path)) {
                @unlink($file_path);
                writeDeleteLog("FILE FISIK DIHAPUS", $file_path);
            }
        }
        
        $_SESSION['success_message'] = 'Pesan berhasil dihapus. Alasan: ' . htmlspecialchars($deleteReason);
        writeDeleteLog("DELETE COMPLETED SUCCESSFULLY");
        
    } catch (Exception $e) {
        $db->rollBack();
        writeDeleteLog("ERROR SAAT MENGHAPUS: " . $e->getMessage());
        $_SESSION['error_message'] = 'Gagal menghapus pesan: ' . $e->getMessage();
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
    exit;
}

// ============================================================================
// AMBIL SEMUA MESSAGE TYPE IDs BERDASARKAN RESPONDER_TYPE
// ============================================================================
writeFollowupLog("Mengambil message types untuk guru: " . $guruType);

$typeStmt = $db->prepare("SELECT id, jenis_pesan, responder_type FROM message_types WHERE responder_type = ?");
$typeStmt->execute([$guruType]);
$messageTypesData = $typeStmt->fetchAll();
$messageTypeIds = array_column($messageTypesData, 'id');

writeFollowupLog("Message types berdasarkan responder_type", $messageTypesData);

if (empty($messageTypeIds)) {
    writeFollowupError("TIDAK ADA MESSAGE TYPE UNTUK GURU INI", $guruType);
    $_SESSION['error_message'] = 'Tidak ada jenis pesan yang ditugaskan untuk Anda. Hubungi administrator.';
    $messageTypeIds = [];
}

debug_step("Message type IDs", $messageTypeIds);
writeFollowupLog("Final message type IDs", $messageTypeIds);

// ============================================================================
// AMBIL DATA REVIEW WAKEPSEK/KEPSEK UNTUK GRAFIK
// ============================================================================
$reviewStats = [
    'total_responded' => 0,
    'reviewed_by_wakepsek' => 0,
    'reviewed_by_kepsek' => 0,
    'pending_review' => 0,
    'reviewed_total' => 0
];

if (!empty($messageTypeIds)) {
    $statsPlaceholders = implode(',', array_fill(0, count($messageTypeIds), '?'));
    
    // Total pesan yang telah direspons oleh guru ini
    $sql = "SELECT COUNT(DISTINCT m.id) as total 
            FROM messages m
            INNER JOIN message_responses mr ON m.id = mr.message_id
            WHERE mr.responder_id = ? AND m.jenis_pesan_id IN ($statsPlaceholders)";
    
    $params = array_merge([$guruId], $messageTypeIds);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reviewStats['total_responded'] = $stmt->fetch()['total'] ?? 0;
    
    // Pesan yang telah direview oleh Wakepsek
    $sql = "SELECT COUNT(DISTINCT m.id) as total 
            FROM messages m
            INNER JOIN message_responses mr ON m.id = mr.message_id
            INNER JOIN wakepsek_reviews wr ON m.id = wr.message_id
            INNER JOIN users reviewer ON wr.reviewer_id = reviewer.id
            WHERE mr.responder_id = ? 
            AND m.jenis_pesan_id IN ($statsPlaceholders)
            AND reviewer.user_type = 'Wakil_Kepala'";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reviewStats['reviewed_by_wakepsek'] = $stmt->fetch()['total'] ?? 0;
    
    // Pesan yang telah direview oleh Kepsek
    $sql = "SELECT COUNT(DISTINCT m.id) as total 
            FROM messages m
            INNER JOIN message_responses mr ON m.id = mr.message_id
            INNER JOIN wakepsek_reviews wr ON m.id = wr.message_id
            INNER JOIN users reviewer ON wr.reviewer_id = reviewer.id
            WHERE mr.responder_id = ? 
            AND m.jenis_pesan_id IN ($statsPlaceholders)
            AND reviewer.user_type = 'Kepala_Sekolah'";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reviewStats['reviewed_by_kepsek'] = $stmt->fetch()['total'] ?? 0;
    
    // Total yang sudah direview (Wakepsek atau Kepsek)
    $reviewStats['reviewed_total'] = $reviewStats['reviewed_by_wakepsek'] + $reviewStats['reviewed_by_kepsek'];
    
    // Pending review (sudah direspons guru tapi belum direview)
    $reviewStats['pending_review'] = $reviewStats['total_responded'] - $reviewStats['reviewed_total'];
    
    debug_step("Review Statistics", $reviewStats);
    writeFollowupLog("Review Statistics", $reviewStats);
}

// Handle form submissions for quick actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    writeFollowupLog("POST REQUEST DITERIMA", $_POST);
    debug_step("POST REQUEST", $_POST, 'submit');
    
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $messageId = (int)($_POST['message_id'] ?? 0);
        
        // Skip jika ini adalah action delete (sudah ditangani di atas)
        if ($action === 'delete_message') {
            // Sudah ditangani di bagian khusus delete
            // Biarkan redirect di atas yang handle
            return;
        }
        
        writeFollowupLog("Quick action", [
            'action' => $action,
            'message_id' => $messageId
        ]);
        
        debug_step("Quick action", [
            'action' => $action,
            'message_id' => $messageId
        ]);
        
        if ($messageId > 0 && !empty($messageTypeIds)) {
            // Ambil detail pesan
            $msgStmt = $db->prepare("
                SELECT m.*, mt.jenis_pesan, mt.responder_type 
                FROM messages m
                LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
                WHERE m.id = ?
            ");
            $msgStmt->execute([$messageId]);
            $message = $msgStmt->fetch();
            
            writeFollowupLog("Detail pesan untuk quick action", $message);
            
            // Cek apakah pesan ini termasuk dalam type IDs guru
            $isValidType = in_array($message['jenis_pesan_id'], $messageTypeIds);
            writeFollowupLog("Cek valid type", [
                'message_type_id' => $message['jenis_pesan_id'],
                'valid_ids' => $messageTypeIds,
                'isValid' => $isValidType
            ]);
            
            if (!$isValidType) {
                writeFollowupError("QUICK ACTION DITOLAK - Jenis pesan tidak valid", [
                    'message_id' => $messageId,
                    'message_type_id' => $message['jenis_pesan_id'],
                    'valid_ids' => $messageTypeIds
                ]);
                $_SESSION['error_message'] = 'Pesan ini tidak dapat direspons karena jenis pesan tidak sesuai.';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
                exit;
            }
            
            $isExternal = $message['is_external'] ?? 0;
            
            if ($action === 'quick_approve' || $action === 'quick_reject') {
                $status = ($action === 'quick_approve') ? 'Disetujui' : 'Ditolak';
                $catatan = ($action === 'quick_approve') ? 'Disetujui melalui aksi cepat.' : 'Ditolak melalui aksi cepat.';
                
                // Update message
                $updateStmt = $db->prepare("
                    UPDATE messages 
                    SET status = ?, 
                        tanggal_respon = NOW(),
                        responder_id = ?
                    WHERE id = ?
                ");
                $updateResult = $updateStmt->execute([$status, $guruId, $messageId]);
                
                writeFollowupLog("Update message status", [
                    'result' => $updateResult,
                    'rows' => $updateStmt->rowCount()
                ]);
                
                $responseStmt = $db->prepare("
                    INSERT INTO message_responses (message_id, responder_id, catatan_respon, status, is_external)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $responseResult = $responseStmt->execute([
                    $messageId, 
                    $guruId, 
                    $catatan, 
                    $status, 
                    $isExternal
                ]);
                
                writeFollowupLog("Insert response", [
                    'result' => $responseResult,
                    'response_id' => $db->lastInsertId()
                ]);
                
                $_SESSION['success_message'] = 'Pesan berhasil ' . ($action === 'quick_approve' ? 'disetujui' : 'ditolak') . '.' . ($isExternal ? ' (External)' : '');
                writeFollowupLog("Quick action selesai", $_SESSION['success_message']);
                
                header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
                exit;
            }
        }
    }
}

// ============================================================================
// Build query conditions (hanya jika ada messageTypeIds)
// ============================================================================
$messages = [];
$total = 0;
$totalPages = 1;
$stats = [
    'total_assigned' => 0,
    'external_count' => 0,
    'pending' => 0,
    'dibaca' => 0,
    'diproses' => 0,
    'disetujui' => 0,
    'ditolak' => 0,
    'selesai' => 0,
    'with_attachments' => 0
];
$templates = [];

if (!empty($messageTypeIds)) {
    $placeholders = implode(',', array_fill(0, count($messageTypeIds), '?'));
    $whereConditions = ["m.jenis_pesan_id IN ($placeholders)"];
    $params = $messageTypeIds;

    if ($statusFilter === 'pending') {
        $whereConditions[] = "m.status IN ('Pending', 'Dibaca', 'Diproses')";
    } elseif ($statusFilter === 'completed') {
        $whereConditions[] = "m.status IN ('Disetujui', 'Ditolak', 'Selesai')";
    } elseif ($statusFilter !== 'all' && !empty($statusFilter)) {
        $whereConditions[] = "m.status = ?";
        $params[] = $statusFilter;
    }

    if ($priorityFilter !== 'all' && !empty($priorityFilter)) {
        $whereConditions[] = "m.priority = ?";
        $params[] = $priorityFilter;
    }

    if ($sourceFilter !== 'all' && !empty($sourceFilter)) {
        if ($sourceFilter === 'internal') {
            $whereConditions[] = "m.is_external = 0";
        } elseif ($sourceFilter === 'external') {
            $whereConditions[] = "m.is_external = 1";
        }
    }

    if (!empty($search)) {
        $whereConditions[] = "(m.isi_pesan LIKE ? 
                               OR u.nama_lengkap LIKE ? 
                               OR u.username LIKE ?
                               OR u.email LIKE ?
                               OR es.nama_lengkap LIKE ?
                               OR es.email LIKE ?
                               OR es.phone_number LIKE ?)";
        $searchParam = "%$search%";
        for ($i = 0; $i < 7; $i++) {
            $params[] = $searchParam;
        }
    }

    $whereClause = implode(' AND ', $whereConditions);
    writeFollowupLog("Query conditions", [
        'where_clause' => $whereClause,
        'params' => $params
    ]);
    debug_step("Query conditions", ['where_clause' => $whereClause]);

    // Get total count
    $countSql = "
        SELECT COUNT(*) as total 
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN external_senders es ON m.external_sender_id = es.id
        WHERE $whereClause
    ";

    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch()['total'];

    writeFollowupLog("Total messages", $total);
    debug_step("Total messages", ['total' => $total]);

    // Calculate pagination
    $totalPages = ceil($total / $perPage);
    $page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));
    $offset = ($page - 1) * $perPage;

    // Get messages with complete details INCLUDING REVIEW INFO AND ATTACHMENT COUNT
    $sql = "
        SELECT 
            m.*,
            m.is_external,
            m.reference_number,
            CASE 
                WHEN m.is_external = 1 THEN 
                    CONCAT('EXT-', 
                        CASE 
                            WHEN es.phone_number IS NOT NULL AND es.phone_number != '' THEN es.phone_number
                            WHEN es.email IS NOT NULL AND es.email != '' THEN SUBSTRING_INDEX(es.email, '@', 1)
                            ELSE CONCAT('ID', es.id)
                        END
                    )
                ELSE 
                    COALESCE(u.username, CONCAT('USR-', u.id))
            END as nomor_identitas,
            CASE 
                WHEN m.is_external = 1 THEN es.nama_lengkap
                ELSE COALESCE(u.nama_lengkap, m.pengirim_nama, 'Unknown')
            END as pengirim_nama_display,
            CASE 
                WHEN m.is_external = 1 THEN 
                    COALESCE(es.identitas, 'External')
                ELSE 
                    COALESCE(u.user_type, 'Internal')
            END as pengirim_tipe,
            CASE 
                WHEN m.is_external = 1 THEN es.email
                ELSE u.email
            END as pengirim_email,
            CASE 
                WHEN m.is_external = 1 THEN es.phone_number
                ELSE u.phone_number
            END as pengirim_phone,
            u.kelas,
            u.jurusan,
            mt.id as message_type_id,
            mt.jenis_pesan as message_jenis_pesan,
            mt.responder_type as message_responder_type,
            mt.response_deadline_hours,
            TIMESTAMPDIFF(HOUR, m.created_at, NOW()) as hours_since_created,
            GREATEST(0, mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) as hours_remaining,
            CASE 
                WHEN TIMESTAMPDIFF(HOUR, m.created_at, NOW()) >= mt.response_deadline_hours THEN 'danger'
                WHEN (mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) <= 24 THEN 'warning'
                ELSE 'success'
            END as urgency_color,
            CASE 
                WHEN mr.id IS NOT NULL THEN 1 
                ELSE 0 
            END as has_response,
            mr.catatan_respon as last_response,
            mr.status as response_status,
            ru.nama_lengkap as responder_name,
            -- Informasi review dari Wakepsek/Kepsek
            wr.id as review_id,
            wr.reviewer_id,
            reviewer.nama_lengkap as reviewer_nama,
            reviewer.user_type as reviewer_type,
            wr.catatan as review_catatan,
            wr.created_at as review_date,
            -- Status review untuk pewarnaan
            CASE 
                WHEN wr.id IS NOT NULL AND reviewer.user_type = 'Kepala_Sekolah' THEN 'kepsek-reviewed'
                WHEN wr.id IS NOT NULL AND reviewer.user_type = 'Wakil_Kepala' THEN 'wakepsek-reviewed'
                ELSE 'no-review'
            END as review_status,
            -- Hitung jumlah attachment
            (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id) as attachment_count
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN external_senders es ON m.external_sender_id = es.id
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN message_responses mr ON m.id = mr.message_id 
            AND mr.created_at = (SELECT MAX(created_at) FROM message_responses WHERE message_id = m.id)
        LEFT JOIN users ru ON mr.responder_id = ru.id
        LEFT JOIN wakepsek_reviews wr ON m.id = wr.message_id
        LEFT JOIN users reviewer ON wr.reviewer_id = reviewer.id
        WHERE $whereClause
        ORDER BY 
            CASE 
                WHEN m.status IN ('Pending', 'Dibaca', 'Diproses') THEN 1
                ELSE 2
            END,
            CASE m.priority
                WHEN 'Urgent' THEN 1
                WHEN 'High' THEN 2
                WHEN 'Medium' THEN 3
                WHEN 'Low' THEN 4
                ELSE 5
            END,
            m.created_at DESC
        LIMIT ?, ?
    ";

    $limitParams = array_merge($params, [$offset, $perPage]);
    $stmt = $db->prepare($sql);
    $stmt->execute($limitParams);
    $messages = $stmt->fetchAll();

    writeFollowupLog("Messages fetched", ['count' => count($messages)]);
    debug_step("Messages fetched", ['count' => count($messages)]);

    // Get statistics with attachment count
    $statsPlaceholders = implode(',', array_fill(0, count($messageTypeIds), '?'));
    $statsSql = "
        SELECT 
            COUNT(*) as total_assigned,
            SUM(CASE WHEN is_external = 1 THEN 1 ELSE 0 END) as external_count,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'Dibaca' THEN 1 ELSE 0 END) as dibaca,
            SUM(CASE WHEN status = 'Diproses' THEN 1 ELSE 0 END) as diproses,
            SUM(CASE WHEN status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as disetujui,
            SUM(CASE WHEN status = 'Ditolak' THEN 1 ELSE 0 END) as ditolak,
            SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as selesai,
            SUM(CASE WHEN (SELECT COUNT(*) FROM message_attachments WHERE message_id = messages.id) > 0 THEN 1 ELSE 0 END) as with_attachments
        FROM messages 
        WHERE jenis_pesan_id IN ($statsPlaceholders)
    ";

    $statsStmt = $db->prepare($statsSql);
    $statsStmt->execute($messageTypeIds);
    $stats = $statsStmt->fetch();

    writeFollowupLog("Statistics", $stats);

    // Get response templates
    $templatesStmt = $db->prepare("
        SELECT id, name, content, category, default_status, guru_type, is_active, use_count,
               CASE 
                   WHEN category LIKE '%external%' OR category LIKE '%umum%' OR category = 'External' THEN 1 
                   ELSE 0 
               END as for_external
        FROM response_templates 
        WHERE (guru_type = ? OR guru_type = 'ALL' OR guru_type IS NULL OR guru_type = '')
        AND is_active = 1
        ORDER BY for_external DESC, use_count DESC, name ASC
    ");
    $templatesStmt->execute([$guruType]);
    $templates = $templatesStmt->fetchAll();
}

debug_step("FOLLOWUP.PHP - SIAP MENAMPILKAN HALAMAN", [
    'debug_steps_count' => $step_counter
], 'complete');

writeFollowupLog("FOLLOWUP.PHP SELESAI, MENAMPILKAN HALAMAN");
writeFollowupLog(str_repeat("=", 80) . "\n");

require_once '../../includes/header.php';
?>

<!-- Image Preview Modal - MODAL PREVIEW GAMBAR -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-3">
                <h5 class="modal-title">
                    <i class="fas fa-image me-2"></i>
                    Preview Gambar
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4 bg-light" id="imagePreviewContainer">
                <!-- Image will be loaded here -->
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Tutup
                </button>
                <a href="#" class="btn btn-primary" id="downloadImageBtn" download>
                    <i class="fas fa-download me-1"></i> Download
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">
                <i class="fas fa-tasks me-2 text-primary"></i>Follow-Up Pesan
                <?php if ($debug_enabled): ?>
                <span class="badge bg-danger ms-2">DEBUG MODE ON</span>
                <?php endif; ?>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="dashboard_guru.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Follow-Up</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex align-items-center">
            <span class="badge bg-primary p-2">
                <i class="fas fa-user-tag me-1"></i>
                <?php echo str_replace('_', ' ', $guruType); ?>
            </span>
            <?php if (!empty($stats['external_count'])): ?>
            <span class="badge bg-warning p-2 ms-2">
                <i class="fas fa-external-link-alt me-1"></i>
                <?php echo $stats['external_count']; ?> External
            </span>
            <?php endif; ?>
            <?php if (!empty($stats['with_attachments'])): ?>
            <span class="badge bg-info p-2 ms-2">
                <i class="fas fa-paperclip me-1"></i>
                <?php echo $stats['with_attachments']; ?> Lampiran
            </span>
            <?php endif; ?>
            <?php if ($debug_enabled): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['debug' => 'off'])); ?>" 
               class="btn btn-sm btn-outline-secondary ms-2">
                <i class="fas fa-bug"></i> Debug OFF
            </a>
            <?php else: ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['debug' => 'on'])); ?>" 
               class="btn btn-sm btn-outline-primary ms-2">
                <i class="fas fa-bug"></i> Debug ON
            </a>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="btn btn-sm btn-danger ms-2">
                <i class="fas fa-sign-out-alt me-1"></i>Logout
            </a>
        </div>
    </div>
    
    <!-- Alert Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- ========================================================= -->
    <!-- GRAFIK REVIEW WAKEPSEK/KEPSEK -->
    <!-- ========================================================= -->
    <?php if ($reviewStats['total_responded'] > 0): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-chart-pie me-2 text-primary"></i>
                        Status Review dari Pimpinan
                        <span class="badge bg-info ms-2">Total: <?php echo $reviewStats['total_responded']; ?> Pesan Direspon</span>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="row g-2">
                                <!-- Progress Bar untuk Review -->
                                <div class="col-12 mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span><i class="fas fa-clock text-warning me-1"></i> Menunggu Review Pimpinan</span>
                                        <span class="fw-bold"><?php echo $reviewStats['pending_review']; ?> pesan (<?php echo round(($reviewStats['pending_review'] / max($reviewStats['total_responded'], 1)) * 100); ?>%)</span>
                                    </div>
                                    <div class="progress" style="height: 25px;">
                                        <div class="progress-bar bg-warning" role="progressbar" 
                                             style="width: <?php echo ($reviewStats['pending_review'] / max($reviewStats['total_responded'], 1)) * 100; ?>%">
                                            <?php echo $reviewStats['pending_review']; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span><i class="fas fa-user-tie text-primary me-1"></i> Sudah Direview Wakil Kepala</span>
                                        <span class="fw-bold"><?php echo $reviewStats['reviewed_by_wakepsek']; ?> pesan (<?php echo round(($reviewStats['reviewed_by_wakepsek'] / max($reviewStats['total_responded'], 1)) * 100); ?>%)</span>
                                    </div>
                                    <div class="progress" style="height: 25px;">
                                        <div class="progress-bar bg-primary" role="progressbar" 
                                             style="width: <?php echo ($reviewStats['reviewed_by_wakepsek'] / max($reviewStats['total_responded'], 1)) * 100; ?>%">
                                            <?php echo $reviewStats['reviewed_by_wakepsek']; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span><i class="fas fa-crown text-success me-1"></i> Sudah Direview Kepala Sekolah</span>
                                        <span class="fw-bold"><?php echo $reviewStats['reviewed_by_kepsek']; ?> pesan (<?php echo round(($reviewStats['reviewed_by_kepsek'] / max($reviewStats['total_responded'], 1)) * 100); ?>%)</span>
                                    </div>
                                    <div class="progress" style="height: 25px;">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?php echo ($reviewStats['reviewed_by_kepsek'] / max($reviewStats['total_responded'], 1)) * 100; ?>%">
                                            <?php echo $reviewStats['reviewed_by_kepsek']; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card bg-light border-0">
                                <div class="card-body text-center">
                                    <h3 class="mb-0 text-primary"><?php echo $reviewStats['reviewed_total']; ?></h3>
                                    <small class="text-muted">Total Sudah Direview</small>
                                    
                                    <div class="mt-3">
                                        <canvas id="reviewPieChart" style="max-height: 150px;"></canvas>
                                    </div>
                                    
                                    <div class="mt-3 small">
                                        <div class="d-flex justify-content-between">
                                            <span><span class="badge bg-warning">●</span> Menunggu</span>
                                            <span><?php echo $reviewStats['pending_review']; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span><span class="badge bg-primary">●</span> Wakil Kepala</span>
                                            <span><?php echo $reviewStats['reviewed_by_wakepsek']; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span><span class="badge bg-success">●</span> Kepala Sekolah</span>
                                            <span><?php echo $reviewStats['reviewed_by_kepsek']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-primary bg-opacity-10 rounded p-2">
                                <i class="fas fa-envelope text-primary fa-fw"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="mb-1"><?php echo number_format($stats['total_assigned'] ?? 0); ?></h5>
                            <small class="text-muted">Total Pesan</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-warning bg-opacity-10 rounded p-2">
                                <i class="fas fa-hourglass-half text-warning fa-fw"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="mb-1"><?php echo ($stats['pending'] ?? 0) + ($stats['dibaca'] ?? 0); ?></h5>
                            <small class="text-muted">Pending</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-info bg-opacity-10 rounded p-2">
                                <i class="fas fa-cog text-info fa-fw"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="mb-1"><?php echo $stats['diproses'] ?? 0; ?></h5>
                            <small class="text-muted">Diproses</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-success bg-opacity-10 rounded p-2">
                                <i class="fas fa-check-circle text-success fa-fw"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="mb-1"><?php echo ($stats['disetujui'] ?? 0) + ($stats['selesai'] ?? 0); ?></h5>
                            <small class="text-muted">Selesai</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-danger bg-opacity-10 rounded p-2">
                                <i class="fas fa-times-circle text-danger fa-fw"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="mb-1"><?php echo $stats['ditolak'] ?? 0; ?></h5>
                            <small class="text-muted">Ditolak</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-secondary bg-opacity-10 rounded p-2">
                                <i class="fas fa-paperclip text-secondary fa-fw"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="mb-1"><?php echo $stats['with_attachments'] ?? 0; ?></h5>
                            <small class="text-muted">Dengan Lampiran</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small fw-bold">STATUS</label>
                    <select class="form-select" name="status">
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>⏳ Pending & Diproses</option>
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>📋 Semua Status</option>
                        <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>🕒 Pending</option>
                        <option value="Dibaca" <?php echo $statusFilter === 'Dibaca' ? 'selected' : ''; ?>>👁️ Dibaca</option>
                        <option value="Diproses" <?php echo $statusFilter === 'Diproses' ? 'selected' : ''; ?>>⚙️ Diproses</option>
                        <option value="Disetujui" <?php echo $statusFilter === 'Disetujui' ? 'selected' : ''; ?>>✅ Disetujui</option>
                        <option value="Ditolak" <?php echo $statusFilter === 'Ditolak' ? 'selected' : ''; ?>>❌ Ditolak</option>
                        <option value="Selesai" <?php echo $statusFilter === 'Selesai' ? 'selected' : ''; ?>>🏁 Selesai</option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>✅ Selesai/Ditolak</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold">PRIORITAS</label>
                    <select class="form-select" name="priority">
                        <option value="all">📊 Semua Prioritas</option>
                        <option value="Low" <?php echo $priorityFilter === 'Low' ? 'selected' : ''; ?>>🟢 Rendah</option>
                        <option value="Medium" <?php echo $priorityFilter === 'Medium' ? 'selected' : ''; ?>>🟡 Sedang</option>
                        <option value="High" <?php echo $priorityFilter === 'High' ? 'selected' : ''; ?>>🟠 Tinggi</option>
                        <option value="Urgent" <?php echo $priorityFilter === 'Urgent' ? 'selected' : ''; ?>>🔴 Mendesak</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold">SUMBER</label>
                    <select class="form-select" name="source">
                        <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>🌐 Semua Sumber</option>
                        <option value="internal" <?php echo $sourceFilter === 'internal' ? 'selected' : ''; ?>>🏢 Internal</option>
                        <option value="external" <?php echo $sourceFilter === 'external' ? 'selected' : ''; ?>>📱 External</option>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label small fw-bold">CARI</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0" name="search" 
                               placeholder="Nama, email, isi pesan..." 
                               value="<?php echo htmlspecialchars($search ?? ''); ?>">
                    </div>
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <div class="d-grid w-100">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Templates -->
    <?php if (!empty($templates)): ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-sticky-note me-2 text-primary"></i>Template Respons Cepat
                <span class="badge bg-primary ms-2"><?php echo count($templates); ?></span>
            </h6>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($templates as $template): ?>
                <div class="col-md-3">
                    <button type="button" 
                            class="btn btn-outline-secondary w-100 text-start template-btn"
                            onclick="useTemplate(<?php echo $template['id']; ?>, '<?php echo addslashes($template['content'] ?? ''); ?>', '<?php echo addslashes($template['default_status'] ?? ''); ?>')">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="fw-small">
                                <i class="fas fa-copy me-1"></i>
                                <?php echo htmlspecialchars($template['name'] ?? 'Template'); ?>
                            </span>
                            <span class="badge bg-light text-dark ms-1">
                                <?php echo $template['use_count'] ?? 0; ?>x
                            </span>
                        </div>
                        <small class="text-muted d-block text-truncate mt-1">
                            <?php echo htmlspecialchars($template['content'] ?? ''); ?>
                        </small>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Messages Table -->
    <div class="card border-0 shadow">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-list me-2 text-primary"></i>Daftar Pesan
                <span class="badge bg-primary ms-2"><?php echo $total; ?></span>
            </h6>
            <small class="text-muted">
                <i class="fas fa-tag me-1"></i>Responder: <?php echo $guruType; ?>
                <span class="ms-2 mailersend-badge">
                    <i class="fas fa-rocket"></i> MailerSend
                </span>
            </small>
        </div>
        
        <div class="card-body p-0">
            <?php if (empty($messages)): ?>
            <div class="text-center py-5">
                <div class="mb-3">
                    <i class="fas fa-inbox fa-4x text-muted opacity-50"></i>
                </div>
                <h6>Tidak ada pesan</h6>
                <p class="text-muted small">
                    <?php if (empty($messageTypeIds)): ?>
                    Anda belum memiliki jenis pesan yang ditugaskan. Hubungi administrator.
                    <?php elseif ($statusFilter !== 'all'): ?>
                    Tidak ada pesan dengan status <?php echo $statusFilter; ?> untuk Anda.
                    <?php else: ?>
                    Belum ada pesan masuk untuk Anda.
                    <?php endif; ?>
                </p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th width="50">#</th>
                            <th>Pengirim</th>
                            <th>Isi Pesan</th>
                            <th width="70">Lampiran</th>
                            <th width="100">Status</th>
                            <th width="120">Review Pimpinan</th>
                            <th width="120">Prioritas</th>
                            <th width="120">Sisa Waktu</th>
                            <th width="280">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $index => $msg): 
                            $isExternal = !empty($msg['is_external']);
                            $isExpired = isset($msg['hours_remaining']) && $msg['hours_remaining'] <= 0;
                            $hasResponse = !empty($msg['last_response']);
                            $hasReview = !empty($msg['review_id']);
                            $hasAttachments = ($msg['attachment_count'] ?? 0) > 0;
                            $rowClass = $isExternal ? 'table-warning' : '';
                            $rowClass .= $isExpired ? ' opacity-75' : '';
                            $rowClass .= $hasResponse ? ' table-success' : '';
                            
                            // Tentukan warna baris berdasarkan review
                            if ($hasReview) {
                                if ($msg['reviewer_type'] === 'Kepala_Sekolah') {
                                    $rowClass .= ' table-primary';
                                } else {
                                    $rowClass .= ' table-info';
                                }
                            }
                        ?>
                        <tr class="<?php echo $rowClass; ?>" data-review-status="<?php echo $msg['review_status'] ?? 'no-review'; ?>">
                            <td><?php echo $offset + $index + 1; ?></td>
                            <td>
                                <div class="fw-bold">
                                    <?php echo htmlspecialchars($msg['pengirim_nama_display'] ?? 'Unknown'); ?>
                                    <?php if ($isExternal): ?>
                                    <span class="badge bg-warning">External</span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted d-block">
                                    <?php echo htmlspecialchars($msg['nomor_identitas'] ?? ''); ?>
                                </small>
                                <small class="text-muted">
                                    <i class="far fa-clock"></i> <?php echo isset($msg['created_at']) ? Functions::timeAgo($msg['created_at']) : ''; ?>
                                </small>
                                <?php if (!empty($msg['pengirim_email'])): ?>
                                <br><small><i class="fas fa-envelope"></i> Email</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 200px;">
                                    <?php echo htmlspecialchars($msg['isi_pesan'] ?? ''); ?>
                                </div>
                                <?php if ($hasResponse): ?>
                                <small class="text-success d-block">
                                    <i class="fas fa-check-circle"></i> Sudah direspons
                                </small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($hasAttachments): ?>
                                <button type="button" 
                                        class="btn btn-sm btn-link p-0" 
                                        onclick="viewAttachments(<?php echo $msg['id']; ?>)"
                                        title="Lihat Lampiran (<?php echo $msg['attachment_count']; ?> file)">
                                    <span class="badge bg-info">
                                        <i class="fas fa-paperclip me-1"></i>
                                        <?php echo $msg['attachment_count']; ?>
                                    </span>
                                </button>
                                <?php else: ?>
                                <span class="text-muted" title="Tidak ada lampiran">
                                    <i class="fas fa-image opacity-25"></i>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $status = $msg['status'] ?? 'Unknown';
                                $badgeClass = match($status) {
                                    'Pending' => 'bg-warning',
                                    'Dibaca' => 'bg-info',
                                    'Diproses' => 'bg-primary',
                                    'Disetujui' => 'bg-success',
                                    'Ditolak' => 'bg-danger',
                                    'Selesai' => 'bg-secondary',
                                    default => 'bg-light text-dark'
                                };
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo $status; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($hasReview): ?>
                                <span class="badge bg-<?php echo $msg['reviewer_type'] === 'Kepala_Sekolah' ? 'primary' : 'info'; ?>">
                                    <i class="fas fa-check-circle"></i> 
                                    <?php echo $msg['reviewer_type'] === 'Kepala_Sekolah' ? 'Kepsek' : 'Wakepsek'; ?>
                                </span>
                                <button type="button" 
                                        class="btn btn-sm btn-link p-0 ms-1" 
                                        onclick='showReviewDetail(<?php echo json_encode($msg); ?>)'
                                        title="Lihat Catatan Review">
                                    <i class="fas fa-eye text-muted"></i>
                                </button>
                                <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-clock"></i> Menunggu
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $priority = $msg['priority'] ?? 'Normal';
                                $priorityClass = match($priority) {
                                    'Low' => 'success',
                                    'Medium' => 'warning',
                                    'High' => 'danger',
                                    'Urgent' => 'dark',
                                    default => 'secondary'
                                };
                                ?>
                                <span class="badge bg-<?php echo $priorityClass; ?>">
                                    <?php echo $priority; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isExpired): ?>
                                <span class="badge bg-danger">Expired</span>
                                <?php else: ?>
                                <span class="badge bg-<?php echo $msg['urgency_color'] ?? 'secondary'; ?>">
                                    <?php echo isset($msg['hours_remaining']) ? floor($msg['hours_remaining']) : 0; ?> jam
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <!-- Tombol Lihat Detail -->
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-primary"
                                            onclick="viewMessage(<?php echo $msg['id']; ?>)"
                                            title="Lihat Detail">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <!-- Tombol Respons/Edit Respons -->
                                    <?php if ($hasResponse): ?>
                                        <a href="response.php?id=<?php echo $msg['id']; ?>&edit=1" 
                                           class="btn btn-sm btn-outline-warning"
                                           title="Edit Respons">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="response.php?id=<?php echo $msg['id']; ?>" 
                                           class="btn btn-sm btn-outline-success"
                                           title="Beri Respons">
                                            <i class="fas fa-reply"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <!-- Tombol Lihat Lampiran -->
                                    <?php if ($hasAttachments): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-info"
                                            onclick="viewAttachments(<?php echo $msg['id']; ?>)"
                                            title="Lihat Lampiran (<?php echo $msg['attachment_count']; ?> file)">
                                        <i class="fas fa-images"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <!-- TOMBOL DELETE - DILETAKKAN DI SAMPING ICON BOLT -->
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="showDeleteModal(<?php echo $msg['id']; ?>, '<?php echo addslashes($msg['pengirim_nama_display'] ?? ''); ?>')"
                                            title="Hapus Pesan (tidak sesuai kaidah)">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    
                                    <!-- Dropdown untuk Aksi Cepat (Setujui/Tolak) -->
                                    <div class="btn-group">
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                data-bs-toggle="dropdown"
                                                title="Aksi Cepat">
                                            <i class="fas fa-bolt"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <form method="POST" class="quick-action-form">
                                                    <input type="hidden" name="action" value="quick_approve">
                                                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                                    <button type="submit" class="dropdown-item text-success">
                                                        <i class="fas fa-check"></i> Setujui Cepat
                                                    </button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" class="quick-action-form">
                                                    <input type="hidden" name="action" value="quick_reject">
                                                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                                    <button type="submit" class="dropdown-item text-danger">
                                                        <i class="fas fa-times"></i> Tolak Cepat
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal untuk Delete Pesan -->
<div class="modal fade" id="deleteMessageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Konfirmasi Hapus Pesan
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="deleteMessageForm">
                <input type="hidden" name="action" value="delete_message">
                <input type="hidden" name="message_id" id="deleteMessageId">
                <input type="hidden" name="confirm_delete" value="yes">
                
                <div class="modal-body p-4">
                    <div class="alert alert-warning border-0 bg-warning bg-opacity-10">
                        <i class="fas fa-exclamation-circle me-2 text-warning"></i>
                        <strong>Perhatian!</strong> Tindakan ini tidak dapat dibatalkan.
                    </div>
                    
                    <p>Anda akan menghapus pesan dari: <strong id="deleteSenderName" class="text-danger"></strong></p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Alasan Penghapusan <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="delete_reason" rows="4" 
                                  placeholder="Contoh: Pesan mengandung kata-kata tidak sopan, spam, tidak sesuai kaidah, dll." 
                                  required></textarea>
                        <div class="form-text text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Alasan ini akan dicatat untuk keperluan audit.
                        </div>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmDeleteCheck" required>
                        <label class="form-check-label" for="confirmDeleteCheck">
                            Saya yakin ingin menghapus pesan ini dan memahami bahwa tindakan ini tidak dapat dibatalkan.
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger" id="submitDeleteBtn" disabled>
                        <i class="fas fa-trash-alt me-1"></i> Hapus Permanen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal untuk Review Detail -->
<div class="modal fade" id="reviewDetailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-clipboard-check me-2"></i>
                    Detail Review Pimpinan
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="reviewDetailContent">
                <!-- Isi diisi via JavaScript -->
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================
     MESSAGE DETAIL MODAL - MODAL 1 (TAMPILAN PROFESIONAL DENGAN CARD DESIGN)
     ============================================================================ -->
<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <!-- Header dengan gradien yang menarik -->
            <div class="modal-header bg-gradient-primary text-white py-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white bg-opacity-25 p-3 me-3">
                        <i class="fas fa-envelope-open-text fa-2x text-white"></i>
                    </div>
                    <div>
                        <h4 class="modal-title fw-bold mb-1">Detail Pesan</h4>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-white text-primary me-2" id="detailMessageReference">-</span>
                            <small class="opacity-75" id="detailMessageId">ID: -</small>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <!-- Body modal dengan background soft -->
            <div class="modal-body p-4" id="messageDetailContent" style="background: #f8fafc;">
                <!-- Konten akan diisi oleh JavaScript dengan tampilan profesional -->
            </div>
            
            <!-- Footer dengan tombol aksi -->
            <div class="modal-footer bg-light py-3 border-0">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Tutup
                </button>
                <a href="#" class="btn btn-primary px-4" id="respondFromDetailBtn">
                    <i class="fas fa-reply me-2"></i>Respons
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================
     MESSAGE ATTACHMENTS MODAL - MODAL 2 (lampiran) - DIPERBAIKI MENGACU PADA WAKEPSEK
     ============================================================================ -->
<div class="modal fade" id="attachmentsModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title">
                    <i class="fas fa-images me-2"></i>
                    Lampiran Gambar
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="attachmentsContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Memuat lampiran...</p>
                </div>
            </div>
            <div class="modal-footer bg-light py-3">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<!-- DEBUG PANEL -->
<?php if ($debug_enabled && !empty($debug_steps)): ?>
<div class="debug-panel" id="debugPanel">
    <div class="debug-header" onclick="toggleDebugContent()">
        <h3>DEBUG LOG - <?php echo count($debug_steps); ?> STEPS</h3>
        <i class="fas fa-chevron-down" id="debugContentToggleIcon"></i>
    </div>
    <div class="debug-content" id="debugContent">
        <?php foreach ($debug_steps as $step): ?>
            <div class="debug-entry <?php echo $step['type']; ?>">
                <div>
                    <span class="debug-step">STEP <?php echo str_pad($step['step'], 2, '0', STR_PAD_LEFT); ?></span>
                    <span class="debug-message"><?php echo htmlspecialchars($step['title']); ?></span>
                    <span class="debug-time"><?php echo $step['time']; ?></span>
                </div>
                <?php if ($step['data'] !== null): ?>
                    <div class="debug-data">
                        <pre><?php echo htmlspecialchars(print_r($step['data'], true)); ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="debug-toggle-btn" onclick="toggleDebugPanel()" id="debugToggleBtn">
    <i class="fas fa-bug"></i> Debug
    <span class="badge"><?php echo $step_counter; ?></span>
</div>
<?php endif; ?>

<style>
.avatar {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.template-btn {
    height: 80px;
    white-space: normal;
    text-align: left;
    overflow: hidden;
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
.debug-panel {
    background: #1e1e2f;
    color: #e0e0e0;
    font-family: monospace;
    position: fixed;
    bottom: 80px;
    right: 20px;
    width: 500px;
    z-index: 10000;
    max-height: 500px;
    overflow-y: auto;
    display: none;
    border-radius: 10px;
}
.debug-panel.visible { display: block; }
.debug-header {
    background: #2d2d44;
    padding: 10px;
    cursor: pointer;
}
.debug-content { padding: 10px; display: none; }
.debug-content.show { display: block; }
.debug-entry {
    margin-bottom: 10px;
    padding: 8px;
    border-left: 4px solid;
    font-size: 11px;
}
.debug-entry.success { border-left-color: #28a745; }
.debug-entry.error { border-left-color: #dc3545; }
.debug-step { color: #ff9900; }
.debug-time { color: #888; }
.debug-data {
    background: #000;
    padding: 5px;
    margin-top: 5px;
    color: #0f0;
    overflow-x: auto;
}
.debug-toggle-btn {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #1e1e2f;
    color: white;
    border: 2px solid #ff9900;
    border-radius: 50px;
    padding: 8px 12px;
    cursor: pointer;
    z-index: 10002;
}
.quick-action-form { margin: 0; }
.quick-action-form button { width: 100%; text-align: left; }
.progress {
    border-radius: 10px;
    background-color: #e9ecef;
}
.progress-bar {
    border-radius: 10px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Style untuk group tombol aksi */
.d-flex.gap-1 {
    gap: 0.25rem !important;
}

.d-flex.gap-1 .btn {
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Hover effect untuk semua tombol aksi */
.d-flex.gap-1 .btn:hover {
    transform: translateY(-2px);
    transition: transform 0.2s;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

/* Style khusus untuk tombol delete */
.btn-outline-danger {
    border-color: #dc3545;
    background-color: white;
}

.btn-outline-danger:hover {
    background-color: #dc3545;
    border-color: #dc3545;
    color: white;
}

/* Tooltip styling */
.btn[title] {
    position: relative;
}

.btn[title]:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background-color: #333;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1000;
    margin-bottom: 8px;
    pointer-events: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.btn[title]:hover::before {
    content: '';
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: #333;
    margin-bottom: -2px;
    z-index: 1000;
}

/* Style untuk attachment preview */
.attachment-thumbnail {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 4px;
    cursor: pointer;
    border: 2px solid #dee2e6;
    transition: transform 0.2s;
}

.attachment-thumbnail:hover {
    transform: scale(1.1);
    border-color: #0d6efd;
}

.attachment-thumbnail.error {
    object-fit: contain;
    padding: 5px;
    background: #f8f9fa;
}

.attachment-item {
    transition: transform 0.2s, box-shadow 0.2s;
    border: 1px solid #e9ecef;
    overflow: hidden;
}

.attachment-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.attachment-preview {
    position: relative;
    background: #f8f9fa;
}

.attachment-preview img {
    transition: transform 0.3s ease;
}

.attachment-preview:hover img {
    transform: scale(1.05);
}

.attachment-overlay {
    opacity: 0;
    transition: opacity 0.3s ease;
    background: linear-gradient(to bottom, rgba(0,0,0,0.3), rgba(0,0,0,0.5));
}

.attachment-preview:hover .attachment-overlay {
    opacity: 1;
}

/* Responsive untuk mobile */
@media (max-width: 768px) {
    .d-flex.gap-1 {
        flex-wrap: wrap;
    }
    
    .d-flex.gap-1 .btn {
        padding: 0.2rem 0.4rem;
        font-size: 0.75rem;
    }
    
    .table td {
        white-space: nowrap;
    }
    
    .attachment-thumbnail {
        width: 40px;
        height: 40px;
    }
}

/* Animasi untuk modal */
.modal.fade .modal-dialog {
    transition: transform 0.3s ease-out;
}

.modal.show .modal-dialog {
    transform: none;
}

/* Style untuk badge review */
.table-primary {
    background-color: #cfe2ff !important;
}

.table-info {
    background-color: #cff4fc !important;
}

.table-success {
    background-color: #d1e7dd !important;
}

.table-warning {
    background-color: #fff3cd !important;
}

/* Hover effect untuk baris tabel */
.table tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.05) !important;
    cursor: pointer;
}

/* Style untuk dropdown */
.dropdown-menu {
    border: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-radius: 8px;
}

.dropdown-item {
    padding: 8px 16px;
    font-size: 0.875rem;
}

.dropdown-item:hover {
    background-color: #f8f9fa;
}

.dropdown-item.text-success:hover {
    background-color: #d1e7dd;
}

.dropdown-item.text-danger:hover {
    background-color: #f8d7da;
}

/* ============================================================================
   TAMPILAN PROFESIONAL UNTUK MODAL DETAIL PESAN
   ============================================================================ */
#messageModal .modal-content {
    border: none;
    border-radius: 24px;
    overflow: hidden;
}

#messageModal .modal-header {
    border-bottom: none;
    padding: 1.8rem 2rem;
}

#messageModal .modal-body {
    padding: 2rem !important;
}

#messageModal .modal-footer {
    border-top: 1px solid rgba(0,0,0,0.05);
    padding: 1.2rem 2rem;
}

/* Cards untuk setiap section */
.detail-card {
    background: white;
    border-radius: 20px;
    padding: 1.8rem;
    margin-bottom: 1.8rem;
    box-shadow: 0 8px 20px rgba(0,0,0,0.02);
    border: 1px solid rgba(0,0,0,0.03);
    transition: transform 0.2s, box-shadow 0.2s;
}

.detail-card:hover {
    box-shadow: 0 12px 30px rgba(0,0,0,0.05);
}

.detail-card-header {
    display: flex;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #f0f4f9;
}

.detail-card-header i {
    font-size: 1.5rem;
    margin-right: 1rem;
}

.detail-card-header h5 {
    margin: 0;
    font-weight: 700;
    font-size: 1.1rem;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

/* Grid Layout untuk informasi */
.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

.detail-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #6c757d;
    margin-bottom: 0.4rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.detail-value {
    font-size: 1rem;
    font-weight: 500;
    color: #1e293b;
    line-height: 1.5;
}

.detail-value code {
    background: #eef2f6;
    padding: 0.2rem 0.6rem;
    border-radius: 6px;
    font-size: 0.9rem;
    color: #0d6efd;
}

.detail-value small {
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 400;
}

/* Status Badge */
.detail-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
}

.detail-badge i {
    margin-right: 0.4rem;
    font-size: 0.8rem;
}

.detail-badge.primary {
    background: #e7f1ff;
    color: #0d6efd;
}

.detail-badge.success {
    background: #d1e7dd;
    color: #198754;
}

.detail-badge.warning {
    background: #fff3cd;
    color: #856404;
}

.detail-badge.danger {
    background: #f8d7da;
    color: #842029;
}

.detail-badge.info {
    background: #cff4fc;
    color: #055160;
}

/* Status Dot */
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}

.status-dot.primary { background: #0d6efd; }
.status-dot.success { background: #198754; }
.status-dot.warning { background: #ffc107; }
.status-dot.danger { background: #dc3545; }
.status-dot.info { background: #0dcaf0; }

/* Content Box untuk isi pesan */
.detail-content-box {
    background: #f8fafc;
    border-radius: 16px;
    padding: 1.5rem;
    margin-top: 1rem;
    line-height: 1.7;
    max-height: 200px;
    overflow-y: auto;
    font-size: 0.95rem;
    border: 1px solid rgba(0,0,0,0.03);
}

.detail-content-box.primary {
    background: #e7f1ff;
    border-left: 4px solid #0d6efd;
}

/* Info Box dengan background gelap dan teks putih */
.info-box {
    background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
    border-radius: 16px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
}

.info-box .info-label {
    color: rgba(255,255,255,0.6) !important;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
}

.info-box .info-value {
    color: white !important;
    font-size: 1rem;
    font-weight: 500;
}

.info-box .info-value code {
    background: rgba(255,255,255,0.1);
    color: white;
    padding: 0.2rem 0.6rem;
    border-radius: 6px;
}

.info-box .info-value small {
    color: rgba(255,255,255,0.6);
}

/* Attachment Grid untuk lampiran */
.detail-attachment-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1.2rem;
    margin-top: 1rem;
}

.detail-attachment-item {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    border: 1px solid rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}

.detail-attachment-item:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.1);
}

.detail-attachment-preview {
    height: 140px;
    background: #f8fafc;
    position: relative;
    cursor: pointer;
    overflow: hidden;
}

.detail-attachment-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s;
}

.detail-attachment-preview:hover img {
    transform: scale(1.1);
}

.detail-attachment-preview .overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(13, 110, 253, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s;
}

.detail-attachment-preview:hover .overlay {
    opacity: 1;
}

.detail-attachment-preview .overlay i {
    color: white;
    font-size: 2rem;
}

.detail-attachment-info {
    padding: 1rem;
}

.detail-attachment-name {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 0.4rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.detail-attachment-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.8rem;
    color: #6c757d;
}

.detail-attachment-meta a {
    color: #0d6efd;
    text-decoration: none;
    padding: 0.2rem 0.6rem;
    border-radius: 20px;
    background: #e7f1ff;
    transition: all 0.2s;
}

.detail-attachment-meta a:hover {
    background: #0d6efd;
    color: white;
}

/* Empty State */
.detail-empty-state {
    text-align: center;
    padding: 3rem 2rem;
    background: white;
    border-radius: 20px;
}

.detail-empty-state i {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 1.5rem;
}

.detail-empty-state h6 {
    font-size: 1.2rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.5rem;
}

.detail-empty-state p {
    color: #6c757d;
    margin-bottom: 0;
}

/* CSS untuk modal bertingkat */
.modal#imagePreviewModal {
    z-index: 1060 !important;
}
.modal-backdrop + .modal-backdrop {
    z-index: 1059 !important;
}

/* Animasi */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.detail-card {
    animation: fadeInUp 0.4s ease-out;
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

/* ============================================================================
   ATTACHMENT STYLES - UNTUK MODAL LAMPIRAN (MENGACU PADA WAKEPSEK)
   ============================================================================ */
.attachment-item {
    transition: transform 0.2s, box-shadow 0.2s;
    border: 1px solid #e9ecef;
    overflow: hidden;
}
.attachment-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.attachment-preview {
    position: relative;
    background: #f8f9fa;
    height: 150px;
    overflow: hidden;
    cursor: pointer;
}
.attachment-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}
.attachment-preview:hover img {
    transform: scale(1.05);
}
.attachment-overlay {
    opacity: 0;
    transition: opacity 0.3s ease;
    background: linear-gradient(to bottom, rgba(0,0,0,0.3), rgba(0,0,0,0.5));
}
.attachment-preview:hover .attachment-overlay {
    opacity: 1;
}
.empty-attachment-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto;
    background: #f8f9fa;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px dashed #dee2e6;
}
.empty-attachment-icon i {
    font-size: 40px;
    color: #adb5bd;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
let currentMessageId = null;
let currentHasResponse = false;
let attachmentsModal = null;
let imagePreviewModal = null;

function toggleDebugPanel() {
    const panel = document.getElementById('debugPanel');
    if (panel) panel.classList.toggle('visible');
}

function toggleDebugContent() {
    const content = document.getElementById('debugContent');
    if (content) content.classList.toggle('show');
}

// ============================================================================
// INITIALIZE MODALS - DIPERBAIKI DENGAN LOGGING
// ============================================================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded - Initializing modals');
    
    // Initialize modals
    attachmentsModal = new bootstrap.Modal(document.getElementById('attachmentsModal'));
    imagePreviewModal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
    
    // Handle modal stacking
    const attachmentsModalEl = document.getElementById('attachmentsModal');
    const imagePreviewModalEl = document.getElementById('imagePreviewModal');
    
    if (attachmentsModalEl) {
        attachmentsModalEl.addEventListener('show.bs.modal', function() {
            console.log('Attachments modal opened');
        });
        
        attachmentsModalEl.addEventListener('shown.bs.modal', function() {
            console.log('Attachments modal shown');
        });
    }
    
    if (imagePreviewModalEl) {
        imagePreviewModalEl.addEventListener('show.bs.modal', function() {
            console.log('Image preview modal opened');
            // Saat modal preview dibuka, redupkan backdrop modal attachments
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 0) {
                backdrops[backdrops.length - 1].style.opacity = '0.3';
            }
        });
        
        imagePreviewModalEl.addEventListener('shown.bs.modal', function() {
            console.log('Image preview modal shown');
        });
        
        imagePreviewModalEl.addEventListener('hidden.bs.modal', function() {
            console.log('Image preview modal hidden');
            // Saat modal preview ditutup, kembalikan backdrop
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 0) {
                backdrops[backdrops.length - 1].style.opacity = '';
            }
        });
    }
    
    // Test modal initialization
    console.log('Attachments modal instance:', attachmentsModal);
    console.log('Image preview modal instance:', imagePreviewModal);
    
    // Validasi checkbox delete
    const confirmDeleteCheck = document.getElementById('confirmDeleteCheck');
    if (confirmDeleteCheck) {
        confirmDeleteCheck.addEventListener('change', function() {
            document.getElementById('submitDeleteBtn').disabled = !this.checked;
        });
    }
    
    document.getElementById('respondFromDetailBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        if (currentMessageId) {
            window.location.href = currentHasResponse 
                ? 'response.php?id=' + currentMessageId + '&edit=1'
                : 'response.php?id=' + currentMessageId;
        }
    });
    
    document.querySelectorAll('.quick-action-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const action = this.querySelector('input[name="action"]').value;
            if (!confirm(action === 'quick_approve' ? 'Setujui pesan ini?' : 'Tolak pesan ini?')) {
                e.preventDefault();
            }
        });
    });

    // Initialize charts when document is ready
    const reviewCtx = document.getElementById('reviewPieChart');
    if (reviewCtx) {
        new Chart(reviewCtx, {
            type: 'doughnut',
            data: {
                labels: ['Menunggu Review', 'Wakil Kepala', 'Kepala Sekolah'],
                datasets: [{
                    data: [
                        <?php echo $reviewStats['pending_review']; ?>,
                        <?php echo $reviewStats['reviewed_by_wakepsek']; ?>,
                        <?php echo $reviewStats['reviewed_by_kepsek']; ?>
                    ],
                    backgroundColor: ['#ffc107', '#0d6efd', '#198754'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                cutout: '60%'
            }
        });
    }
    
    // Tooltip initialization (if using Bootstrap tooltips)
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl, {
            placement: 'top',
            trigger: 'hover'
        });
    });
    
    // Lazy load images
    const images = document.querySelectorAll('img[loading="lazy"]');
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const image = entry.target;
                    image.src = image.dataset.src || image.src;
                    imageObserver.unobserve(image);
                }
            });
        });
        images.forEach(img => imageObserver.observe(img));
    }
});

/**
 * View message details dengan loading state dan error handling yang lebih baik
 * DAN TAMPILAN PROFESIONAL DENGAN CARD DESIGN
 */
function viewMessage(messageId) {
    currentMessageId = messageId;
    
    const modal = new bootstrap.Modal(document.getElementById('messageModal'));
    modal.show();
    
    // Tampilkan loading dengan style yang lebih baik
    document.getElementById('messageDetailContent').innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Memuat detail pesan...</p>
        </div>
    `;
    
    // Gunakan fetch dengan timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 detik timeout
    
    fetch('ajax/get_message_detail.php?message_id=' + messageId, {
        signal: controller.signal,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        clearTimeout(timeoutId);
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success && data.message) {
            // Format tanggal
            const formatDate = (dateString) => {
                if (!dateString) return '-';
                const date = new Date(dateString);
                return date.toLocaleDateString('id-ID', { 
                    day: '2-digit', 
                    month: 'short', 
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            };
            
            const msg = data.message;
            const createdDate = formatDate(msg.created_at);
            
            // Tentukan class untuk status
            const statusClass = msg.status === 'Disetujui' ? 'success' : 
                               (msg.status === 'Ditolak' ? 'danger' : 
                               (msg.status === 'Pending' ? 'warning' : 'info'));
            
            const priorityClass = msg.priority === 'High' ? 'danger' : 
                                 (msg.priority === 'Medium' ? 'warning' : 
                                 (msg.priority === 'Low' ? 'success' : 'secondary'));
            
            // Buat HTML dengan card design profesional
            let html = `
                <!-- INFORMASI UTAMA DENGAN BACKGROUND GELAP DAN TEKS PUTIH -->
                <div class="info-box">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="info-label">Jenis Pesan</div>
                            <div class="info-value">${msg.jenis_pesan || '-'}</div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span class="badge bg-${statusClass}">${msg.status || 'Pending'}</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Prioritas</div>
                            <div class="info-value">
                                <span class="badge bg-${priorityClass}">${msg.priority || 'Normal'}</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Tanggal Kirim</div>
                            <div class="info-value">${createdDate}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Sisa Waktu</div>
                            <div class="info-value">
                                <span class="badge bg-${msg.urgency_color || 'secondary'}">
                                    ${msg.hours_remaining ? Math.floor(msg.hours_remaining) : 0} jam
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- INFORMASI PENGIRIM -->
                <div class="detail-card">
                    <div class="detail-card-header">
                        <i class="fas fa-user-circle text-primary"></i>
                        <h5 class="text-primary">Informasi Pengirim</h5>
                    </div>
                    
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Nama</span>
                            <span class="detail-value">${msg.pengirim_nama_display || '-'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Tipe</span>
                            <span class="detail-value">
                                ${msg.pengirim_tipe || '-'}
                                ${msg.is_external ? '<span class="badge bg-warning ms-2">External</span>' : ''}
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Identitas</span>
                            <span class="detail-value"><code>${msg.nomor_identitas || '-'}</code></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Email</span>
                            <span class="detail-value">${msg.pengirim_email || '-'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">No. HP</span>
                            <span class="detail-value">${msg.pengirim_phone || '-'}</span>
                        </div>
                    </div>
                </div>
                
                <!-- ISI PESAN -->
                <div class="detail-card">
                    <div class="detail-card-header">
                        <i class="fas fa-envelope text-primary"></i>
                        <h5 class="text-primary">Isi Pesan</h5>
                    </div>
                    
                    <div class="detail-content-box primary">
                        ${(msg.isi_pesan || '<em class="text-muted">Tidak ada isi pesan</em>').replace(/\n/g, '<br>')}
                    </div>
            `;
            
            // LAMPIRAN GAMBAR (Jika Ada)
            if (msg.attachments && msg.attachments.length > 0) {
                html += `
                    <div class="mt-4">
                        <span class="detail-label mb-3 d-block">
                            <i class="fas fa-images text-primary me-2"></i>
                            Lampiran Gambar (${msg.attachments.length})
                        </span>
                        <div class="detail-attachment-grid">
                `;
                
                msg.attachments.forEach(att => {
                    const imageUrl = '<?php echo BASE_URL; ?>/' + att.filepath;
                    const fileName = att.filename || att.original_name || 'image.jpg';
                    const fileSize = att.filesize ? Math.round(att.filesize / 1024) + ' KB' : '';
                    
                    html += `
                        <div class="detail-attachment-item">
                            <div class="detail-attachment-preview" onclick="previewImage('${imageUrl}', '${fileName.replace(/'/g, "\\'")}')">
                                <img src="${imageUrl}?t=${new Date().getTime()}" 
                                     alt="${fileName.replace(/"/g, '&quot;')}"
                                     onerror="this.onerror=null; this.src='<?php echo $placeholder_image; ?>'; this.style.objectFit='contain';">
                                <div class="overlay">
                                    <i class="fas fa-search-plus"></i>
                                </div>
                            </div>
                            <div class="detail-attachment-info">
                                <div class="detail-attachment-name" title="${fileName.replace(/"/g, '&quot;')}">
                                    ${fileName.length > 20 ? fileName.substring(0, 20) + '...' : fileName}
                                </div>
                                <div class="detail-attachment-meta">
                                    <span>${fileSize}</span>
                                    <a href="${imageUrl}" download="${fileName}" title="Download">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += `</div></div>`;
            }
            
            html += `</div>`; // Tutup card
            
            // RESPON GURU (Jika Ada)
            if (msg.has_response) {
                const responseStatusClass = msg.response_status === 'Disetujui' ? 'success' : 'danger';
                const responseDate = formatDate(msg.tanggal_respon);
                
                html += `
                    <div class="detail-card">
                        <div class="detail-card-header">
                            <i class="fas fa-chalkboard-teacher text-success"></i>
                            <h5 class="text-success">Respon Guru</h5>
                        </div>
                        
                        <div class="detail-grid-3">
                            <div class="detail-item">
                                <span class="detail-label">Guru Responder</span>
                                <span class="detail-value">
                                    ${msg.responder_name || '-'}
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Status Respon</span>
                                <span class="detail-value">
                                    <span class="badge bg-${responseStatusClass}">${msg.response_status || '-'}</span>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Waktu Respon</span>
                                <span class="detail-value">
                                    <i class="far fa-calendar-alt text-success me-1"></i>
                                    ${responseDate}
                                </span>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <span class="detail-label mb-2 d-block">Catatan Respon</span>
                            <div class="detail-content-box success">
                                ${(msg.last_response || '<em class="text-muted">Tidak ada catatan respon</em>').replace(/\n/g, '<br>')}
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // REVIEW DARI PIMPINAN (Jika Ada)
            if (msg.review_id) {
                const reviewerIcon = msg.reviewer_type === 'Kepala_Sekolah' ? 'fa-crown' : 'fa-user-tie';
                const reviewerColor = msg.reviewer_type === 'Kepala_Sekolah' ? 'warning' : 'info';
                const reviewDate = formatDate(msg.review_date);
                
                html += `
                    <div class="detail-card">
                        <div class="detail-card-header">
                            <i class="fas ${reviewerIcon} text-${reviewerColor}"></i>
                            <h5 class="text-${reviewerColor}">Review ${msg.reviewer_type || 'Pimpinan'}</h5>
                        </div>
                        
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle bg-${reviewerColor} bg-opacity-10 p-2 me-2">
                                <i class="fas ${reviewerIcon} text-${reviewerColor}"></i>
                            </div>
                            <div>
                                <span class="fw-bold">${msg.reviewer_nama || '-'}</span>
                                <small class="text-muted d-block">${msg.reviewer_type || '-'}</small>
                            </div>
                            <div class="ms-auto">
                                <small class="text-muted">
                                    <i class="far fa-calendar-alt me-1"></i>
                                    ${reviewDate}
                                </small>
                            </div>
                        </div>
                        
                        <div class="detail-content-box" style="background: #f0f4f9; border-left-color: #${msg.reviewer_type === 'Kepala_Sekolah' ? 'ffc107' : '17a2b8'};">
                            ${(msg.review_catatan || '<em class="text-muted">Tidak ada catatan review</em>').replace(/\n/g, '<br>')}
                        </div>
                    </div>
                `;
            }
            
            document.getElementById('messageDetailContent').innerHTML = html;
            
            // Update tombol respond berdasarkan ada tidaknya response
            const respondBtn = document.getElementById('respondFromDetailBtn');
            if (respondBtn) {
                if (data.has_response) {
                    respondBtn.innerHTML = '<i class="fas fa-edit me-2"></i>Edit Respons';
                    respondBtn.classList.remove('btn-primary');
                    respondBtn.classList.add('btn-warning');
                    currentHasResponse = true;
                } else {
                    respondBtn.innerHTML = '<i class="fas fa-reply me-2"></i>Beri Respons';
                    respondBtn.classList.remove('btn-warning');
                    respondBtn.classList.add('btn-primary');
                    currentHasResponse = false;
                }
            }
            
            // Update header dengan reference_number dan ID
            const refElement = document.getElementById('detailMessageReference');
            const idElement = document.getElementById('detailMessageId');
            
            if (refElement) {
                refElement.textContent = msg.reference_number || `Pesan #${msg.id}`;
            }
            if (idElement) {
                idElement.textContent = `ID: ${msg.id || '-'}`;
            }
            
            console.log('Message details loaded successfully', data);
        } else {
            document.getElementById('messageDetailContent').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    ${data.error || 'Gagal memuat detail pesan'}
                </div>
            `;
        }
    })
    .catch(error => {
        clearTimeout(timeoutId);
        console.error('Error:', error);
        
        let errorMessage = 'Terjadi kesalahan saat memuat detail pesan.';
        if (error.name === 'AbortError') {
            errorMessage = 'Request timeout. Silakan coba lagi.';
        } else if (error.message) {
            errorMessage = error.message;
        }
        
        document.getElementById('messageDetailContent').innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                ${errorMessage}
                <button class="btn btn-sm btn-outline-danger mt-2" onclick="viewMessage(${messageId})">
                    <i class="fas fa-sync me-1"></i> Coba Lagi
                </button>
            </div>
        `;
    });
}

/**
 * View message attachments dalam modal terpisah - DIPERBAIKI DENGAN LOGGING DAN TIMEOUT
 */
function viewAttachments(messageId) {
    console.log('viewAttachments called with messageId:', messageId);
    
    // Tampilkan modal attachments
    attachmentsModal.show();
    
    // Tampilkan loading dengan style yang lebih baik
    document.getElementById('attachmentsContent').innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Memuat lampiran...</p>
        </div>
    `;
    
    // Gunakan fetch dengan timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 detik timeout
    
    // Fetch attachments via AJAX - Jangan set header X-Requested-With
    fetch('ajax/get_message_attachments.php?message_id=' + messageId, {
        signal: controller.signal,
        credentials: 'same-origin'
    })
    .then(response => {
        clearTimeout(timeoutId);
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        
        if (data.success) {
            displayAttachments(data.attachments, data.is_external);
        } else {
            document.getElementById('attachmentsContent').innerHTML = `
                <div class="alert alert-warning border-0 rounded-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    ${data.error || 'Tidak dapat memuat lampiran'}
                </div>
            `;
        }
    })
    .catch(error => {
        clearTimeout(timeoutId);
        console.error('Error:', error);
        
        let errorMessage = 'Terjadi kesalahan saat memuat lampiran.';
        if (error.name === 'AbortError') {
            errorMessage = 'Request timeout. Silakan coba lagi.';
        } else if (error.message) {
            errorMessage = error.message;
        }
        
        document.getElementById('attachmentsContent').innerHTML = `
            <div class="alert alert-danger border-0 rounded-3">
                <i class="fas fa-exclamation-circle me-2"></i>
                ${errorMessage}
                <button class="btn btn-sm btn-outline-danger mt-2" onclick="viewAttachments(${messageId})">
                    <i class="fas fa-sync me-1"></i> Coba Lagi
                </button>
            </div>
        `;
    });
}

/**
 * Display attachments dalam bentuk grid - DIPERBAIKI MENGACU PADA WAKEPSEK
 */
function displayAttachments(attachments, isExternal) {
    const container = document.getElementById('attachmentsContent');
    const placeholder = '<?php echo $placeholder_image; ?>';
    
    if (!attachments || attachments.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5">
                <div class="empty-attachment-icon mb-3">
                    <i class="fas fa-image fa-4x text-muted opacity-50"></i>
                </div>
                <h6 class="text-muted">Tidak Ada Lampiran Gambar</h6>
                <p class="text-muted small mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Pesan ini tidak dilengkapi dengan gambar lampiran.
                </p>
            </div>
        `;
        return;
    }
    
    let html = '<div class="row g-4">';
    
    attachments.forEach(att => {
        const imageUrl = '<?php echo BASE_URL; ?>/' + att.filepath;
        const displayName = att.filename || att.original_name || 'image.jpg';
        
        // Format file size
        let sizeFormatted = '';
        if (att.filesize) {
            const size = att.filesize;
            if (size < 1024) {
                sizeFormatted = size + ' B';
            } else if (size < 1048576) {
                sizeFormatted = Math.round(size / 1024) + ' KB';
            } else {
                sizeFormatted = (size / 1048576).toFixed(1) + ' MB';
            }
        }
        
        // Status virus scan
        const virusStatus = att.virus_scan_status || 'Pending';
        let statusBadge = '';
        let statusClass = '';
        
        switch(virusStatus) {
            case 'Clean':
                statusBadge = '<span class="badge bg-success ms-1" title="Aman">✓</span>';
                statusClass = 'border-success';
                break;
            case 'Pending':
                statusBadge = '<span class="badge bg-warning ms-1" title="Dalam proses scan">⏳</span>';
                statusClass = 'border-warning';
                break;
            case 'Infected':
                statusBadge = '<span class="badge bg-danger ms-1" title="Terinfeksi virus">⚠</span>';
                statusClass = 'border-danger';
                break;
            default:
                statusBadge = '<span class="badge bg-secondary ms-1" title="Status tidak diketahui">?</span>';
                statusClass = 'border-secondary';
        }
        
        // Format tanggal upload
        const uploadDate = att.created_at ? new Date(att.created_at).toLocaleDateString('id-ID', {
            day: '2-digit', month: 'short', year: 'numeric'
        }) : '-';
        
        html += `
            <div class="col-md-4 col-sm-6">
                <div class="attachment-item card h-100 ${statusClass}">
                    <div class="attachment-preview position-relative" 
                         style="height: 150px; overflow: hidden; cursor: pointer; background: #f8f9fa;"
                         onclick="previewImageFromAttachments('${imageUrl}', '${displayName.replace(/'/g, "\\'")}')">
                        <img src="${imageUrl}?t=${new Date().getTime()}" 
                             alt="${displayName.replace(/"/g, '&quot;')}"
                             style="width: 100%; height: 100%; object-fit: cover;"
                             loading="lazy"
                             onerror="this.onerror=null; this.src='${placeholder}'; this.style.objectFit='contain'; this.style.padding='10px';">
                        <div class="attachment-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-dark bg-opacity-25 opacity-0 transition-all">
                            <i class="fas fa-search-plus text-white fa-2x"></i>
                        </div>
                    </div>
                    <div class="card-body p-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-truncate" style="max-width: 120px;">
                                <small title="${displayName.replace(/"/g, '&quot;')}">
                                    ${displayName.substring(0, 15)}${displayName.length > 15 ? '...' : ''}
                                    ${statusBadge}
                                </small>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" 
                                        class="btn btn-outline-primary" 
                                        onclick="previewImageFromAttachments('${imageUrl}', '${displayName.replace(/'/g, "\\'")}')"
                                        title="Preview">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <a href="${imageUrl}" 
                                   class="btn btn-outline-success" 
                                   download="${displayName}"
                                   title="Download"
                                   target="_blank">
                                    <i class="fas fa-download"></i>
                                </a>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <small class="text-muted">${sizeFormatted}</small>
                            <small class="text-muted">
                                <i class="far fa-clock"></i>
                                ${uploadDate}
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

/**
 * Preview image dari modal attachments (modal bertingkat) - MENGACU PADA WAKEPSEK
 */
function previewImageFromAttachments(imageUrl, imageName) {
    console.log('previewImageFromAttachments called:', imageName);
    
    // Buka modal preview di atas modal attachments
    imagePreviewModal.show();
    
    const container = document.getElementById('imagePreviewContainer');
    const downloadBtn = document.getElementById('downloadImageBtn');
    const placeholder = '<?php echo $placeholder_image; ?>';
    
    // Set modal title
    document.querySelector('#imagePreviewModal .modal-title').innerHTML = `
        <i class="fas fa-image me-2"></i>
        Preview: ${imageName.substring(0, 30)}${imageName.length > 30 ? '...' : ''}
    `;
    
    // Tampilkan loading
    container.innerHTML = `
        <div class="text-center p-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Memuat gambar...</p>
        </div>
    `;
    
    // Buat image element untuk preload
    const img = new Image();
    img.onload = function() {
        container.innerHTML = '';
        container.appendChild(img);
        downloadBtn.href = imageUrl;
        downloadBtn.style.display = 'inline-block';
        downloadBtn.download = imageName;
    };
    
    img.onerror = function() {
        container.innerHTML = `
            <div class="text-center p-5">
                <i class="fas fa-exclamation-triangle text-warning fa-4x mb-3"></i>
                <h6 class="text-muted">Gambar tidak dapat dimuat</h6>
                <p class="text-muted small mb-4">File mungkin telah dihapus, dipindahkan, atau rusak.</p>
                <img src="${placeholder}" class="img-fluid opacity-50" style="max-height: 200px;">
                <div class="mt-4">
                    <a href="${imageUrl}" class="btn btn-outline-primary" target="_blank">
                        <i class="fas fa-external-link-alt me-1"></i> Buka di Tab Baru
                    </a>
                </div>
            </div>
        `;
        downloadBtn.style.display = 'none';
    };
    
    img.src = imageUrl + '?t=' + new Date().getTime();
    img.alt = imageName;
    img.className = 'img-fluid rounded-3';
    img.style.maxHeight = '70vh';
    img.style.maxWidth = '100%';
    img.style.objectFit = 'contain';
}

/**
 * Preview image langsung (dari detail pesan) - MENGACU PADA WAKEPSEK
 */
function previewImage(imageUrl, imageName) {
    // Panggil fungsi yang sama
    previewImageFromAttachments(imageUrl, imageName);
}

function showReviewDetail(msg) {
    const content = document.getElementById('reviewDetailContent');
    
    let reviewerIcon = msg.reviewer_type === 'Kepala_Sekolah' ? 'fa-crown text-warning' : 'fa-user-tie text-info';
    let reviewerName = msg.reviewer_type === 'Kepala_Sekolah' ? 'Kepala Sekolah' : 'Wakil Kepala Sekolah';
    
    content.innerHTML = `
        <div class="mb-3">
            <div class="d-flex align-items-center mb-3">
                <div class="flex-shrink-0">
                    <div class="avatar bg-light rounded-circle p-2 me-3">
                        <i class="fas ${reviewerIcon} fa-2x"></i>
                    </div>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-0">${msg.reviewer_nama || '-'}</h6>
                    <small class="text-muted">${reviewerName}</small>
                </div>
                <div>
                    <span class="badge bg-primary">
                        <i class="far fa-clock me-1"></i>
                        ${new Date(msg.review_date).toLocaleString('id-ID')}
                    </span>
                </div>
            </div>
            
            <div class="bg-light p-3 rounded">
                <label class="fw-bold text-primary mb-2">
                    <i class="fas fa-quote-left me-1"></i>
                    Catatan Review:
                </label>
                <div class="p-2 bg-white rounded" style="white-space: pre-line; min-height: 100px;">
                    ${msg.review_catatan || '<em class="text-muted">Tidak ada catatan</em>'}
                </div>
            </div>
            
            <div class="mt-3">
                <label class="fw-bold mb-2">Detail Pesan:</label>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="bg-light p-2 rounded">
                            <small class="text-muted d-block">Status Respon Guru</small>
                            <span class="badge bg-${msg.response_status == 'Disetujui' ? 'success' : 'danger'}">
                                ${msg.response_status || '-'}
                            </span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-light p-2 rounded">
                            <small class="text-muted d-block">Waktu Respon Guru</small>
                            <small>${msg.tanggal_respon ? new Date(msg.tanggal_respon).toLocaleString('id-ID') : '-'}</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('reviewDetailModal'));
    modal.show();
}

// Fungsi untuk menampilkan modal delete
function showDeleteModal(messageId, senderName) {
    document.getElementById('deleteMessageId').value = messageId;
    document.getElementById('deleteSenderName').textContent = senderName;
    
    // Reset form
    document.getElementById('deleteMessageForm').reset();
    document.getElementById('submitDeleteBtn').disabled = true;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteMessageModal'));
    modal.show();
}

// Fungsi untuk menggunakan template (jika diperlukan)
function useTemplate(templateId, content, defaultStatus) {
    // Implementasi sesuai kebutuhan
    console.log('Template selected:', templateId, content, defaultStatus);
}
</script>

<?php
// Catatan: File ajax/get_message_detail.php dan ajax/get_message_attachments.php
// perlu dibuat terpisah dengan struktur yang sudah diperbaiki sebelumnya
?>

<?php require_once '../../includes/footer.php'; ?>