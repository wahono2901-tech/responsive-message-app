<?php
/**
 * Dashboard for Wakil Kepala Sekolah (Wakepsek) and Kepala Sekolah (Kepsek)
 * File: modules/wakepsek/dashboard.php
 * 
 * [SEMUA BAGIAN ATAS TETAP SAMA - TIDAK DIUBAH]
 * 
 * PERBAIKAN: Alur modal bertingkat untuk preview gambar
 * - Mengikuti pola dari modules/guru/followup.php
 * - Modal 1: Detail Modal (Modal Utama)
 * - Modal 2: Attachments Modal (Modal Lampiran)
 * - Modal 3: Image Preview Modal (Modal Preview)
 * - Penanganan backdrop yang benar untuk stacking
 */

// ============================================================================
// ERROR REPORTING & LOGGING
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/wakepsek_error.log');

// Buat direktori logs jika belum ada
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

define('WAKEPSEK_DEBUG_LOG', $logDir . '/wakepsek_debug.log');

function wakepsek_log($message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents(WAKEPSEK_DEBUG_LOG, $log, FILE_APPEND);
    error_log($log);
}

wakepsek_log("========== WAKEPSEK DASHBOARD START ==========");

// ============================================================================
// LOAD KONFIGURASI
// ============================================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================================================
// DEFINE UPLOAD PATHS FOR ATTACHMENTS
// ============================================================================
define('UPLOAD_PATH_MESSAGES', ROOT_PATH . '/uploads/messages/');
define('UPLOAD_PATH_EXTERNAL', ROOT_PATH . '/uploads/external_messages/');
define('BASE_URL_UPLOAD_MESSAGES', BASE_URL . '/uploads/messages/');
define('BASE_URL_UPLOAD_EXTERNAL', BASE_URL . '/uploads/external_messages/');

// Placeholder image (base64 SVG)
$placeholder_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f8f9fa"/><text x="50" y="50" font-family="Arial" font-size="12" fill="#adb5bd" text-anchor="middle" dy=".3em">No Image</text></svg>';
$placeholder_image = 'data:image/svg+xml;base64,' . base64_encode($placeholder_svg);

// ============================================================================
// CEK AUTHENTICATION
// ============================================================================
Auth::checkAuth();

// ============================================================================
// CEK AKSES - HANYA WAKEPSEK DAN KEPSEK
// ============================================================================
$allowedTypes = ['Wakil_Kepala', 'Kepala_Sekolah'];
$userType = $_SESSION['user_type'] ?? '';
$userId = $_SESSION['user_id'];
$userNama = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'User';

wakepsek_log("User login: $userType - $userNama (ID: $userId)");

if (!in_array($userType, $allowedTypes)) {
    wakepsek_log("ACCESS DENIED - User type: $userType");
    
    // Redirect berdasarkan tipe user
    if ($userType === 'Admin') {
        header('Location: ' . BASE_URL . '/modules/admin/dashboard.php');
        exit;
    } elseif (in_array($userType, ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'])) {
        header('Location: ' . BASE_URL . '/modules/guru/followup.php');
        exit;
    } elseif ($userType === 'Guru') {
        header('Location: ' . BASE_URL . '/modules/user/send_message.php');
        exit;
    } elseif (in_array($userType, ['Siswa', 'Orang_Tua'])) {
        header('Location: ' . BASE_URL . '/modules/dashboard.php');
        exit;
    }
    
    header('Location: ' . BASE_URL . '/index.php?error=access_denied');
    exit;
}

// ============================================================================
// DATABASE CONNECTION
// ============================================================================
$db = Database::getInstance()->getConnection();

// ============================================================================
// PARAMETER FILTER
// ============================================================================
$statusFilter = $_GET['status'] ?? 'all';
$guruFilter = $_GET['guru'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 15;

wakepsek_log("Filter parameters", [
    'status' => $statusFilter,
    'guru' => $guruFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'search' => $search,
    'page' => $page,
    'user_type' => $userType
]);

// ============================================================================
// AMBIL DAFTAR RESPONDER TYPE DARI MESSAGE_TYPES
// ============================================================================
$responderTypes = [];
$responderTypeLabels = [
    'Guru_BK' => 'Bimbingan Konseling',
    'Guru_Kesiswaan' => 'Kesiswaan',
    'Guru_Humas' => 'Hubungan Masyarakat',
    'Guru_Kurikulum' => 'Kurikulum',
    'Guru_Sarana' => 'Sarana Prasarana',
    'Guru' => 'Guru Umum'
];

try {
    $typeStmt = $db->query("
        SELECT DISTINCT responder_type 
        FROM message_types 
        WHERE responder_type IS NOT NULL 
        AND responder_type != ''
        ORDER BY responder_type
    ");
    $responderTypes = $typeStmt->fetchAll(PDO::FETCH_COLUMN);
    wakepsek_log("Responder types from message_types: " . implode(', ', $responderTypes));
} catch (Exception $e) {
    wakepsek_log("Error loading responder types: " . $e->getMessage());
}

// ============================================================================
// AMBIL DAFTAR GURU RESPONDER BERDASARKAN RESPONDER_TYPE DARI MESSAGE_TYPES
// ============================================================================
$guruList = [];

try {
    if (!empty($responderTypes)) {
        // Bangun kondisi IN untuk responder_type
        $placeholders = implode(',', array_fill(0, count($responderTypes), '?'));
        
        $guruStmt = $db->prepare("
            SELECT id, nama_lengkap, user_type 
            FROM users 
            WHERE user_type IN ($placeholders)
            AND is_active = 1
            ORDER BY 
                CASE user_type
                    WHEN 'Guru_BK' THEN 1
                    WHEN 'Guru_Kesiswaan' THEN 2
                    WHEN 'Guru_Humas' THEN 3
                    WHEN 'Guru_Kurikulum' THEN 4
                    WHEN 'Guru_Sarana' THEN 5
                    WHEN 'Guru' THEN 6
                    ELSE 7
                END,
                nama_lengkap
        ");
        $guruStmt->execute($responderTypes);
        $guruList = $guruStmt->fetchAll();
    } else {
        // Fallback jika tidak ada data responder_type
        $guruStmt = $db->query("
            SELECT id, nama_lengkap, user_type 
            FROM users 
            WHERE user_type IN ('Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Guru')
            AND is_active = 1
            ORDER BY user_type, nama_lengkap
        ");
        $guruList = $guruStmt->fetchAll();
    }
    
    wakepsek_log("Guru list loaded based on responder_type: " . count($guruList));
} catch (Exception $e) {
    wakepsek_log("Error loading guru list: " . $e->getMessage());
}

// ============================================================================
// AMBIL DATA UNTUK GRAFIK KOMPARASI GURU (BERDASARKAN RESPONDER_TYPE)
// ============================================================================
$guruChartData = [];
$guruPerformanceStats = [
    'top_performer' => '-',
    'top_performer_count' => 0,
    'fastest_responder' => '-',
    'fastest_time' => 0,
    'total_responded_all' => 0,
    'avg_response_all' => 0,
    'completion_rate' => 0
];

try {
    // Bangun kondisi untuk user_type berdasarkan responder_type yang ada
    $responderConditions = [];
    if (!empty($responderTypes)) {
        foreach ($responderTypes as $type) {
            $responderConditions[] = "u.user_type = '" . addslashes($type) . "'";
        }
    } else {
        $responderConditions[] = "u.user_type IN ('Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Guru')";
    }
    
    $responderWhere = implode(' OR ', $responderConditions);
    
    // Hitung untuk setiap guru: total pesan masuk, pending, response, expired
    $chartStmt = $db->query("
        SELECT 
            u.id,
            u.nama_lengkap,
            u.user_type,
            COUNT(DISTINCT m.id) as total_messages,
            SUM(CASE WHEN m.status = 'Pending' AND m.expired_at > NOW() THEN 1 ELSE 0 END) as pending_messages,
            SUM(CASE WHEN mr.id IS NOT NULL THEN 1 ELSE 0 END) as responded_messages,
            SUM(CASE WHEN m.expired_at < NOW() AND m.status != 'Selesai' THEN 1 ELSE 0 END) as expired_messages,
            AVG(TIMESTAMPDIFF(HOUR, m.created_at, mr.created_at)) as avg_response_hours,
            MIN(TIMESTAMPDIFF(HOUR, m.created_at, mr.created_at)) as min_response_hours,
            MAX(TIMESTAMPDIFF(HOUR, m.created_at, mr.created_at)) as max_response_hours,
            COUNT(DISTINCT mr.id) as response_count,
            COUNT(DISTINCT m.id) as message_count
        FROM users u
        LEFT JOIN message_responses mr ON u.id = mr.responder_id
        LEFT JOIN messages m ON mr.message_id = m.id
        WHERE ($responderWhere)
        AND u.is_active = 1
        AND (m.created_at BETWEEN '$dateFrom 00:00:00' AND '$dateTo 23:59:59' OR m.created_at IS NULL)
        GROUP BY u.id
        HAVING total_messages > 0 OR responded_messages > 0
        ORDER BY responded_messages DESC
        LIMIT 15
    ");
    $guruChartData = $chartStmt->fetchAll();
    
    // Hitung statistik tambahan
    if (!empty($guruChartData)) {
        $guruPerformanceStats['total_responded_all'] = array_sum(array_column($guruChartData, 'responded_messages'));
        $guruPerformanceStats['avg_response_all'] = round(array_sum(array_column($guruChartData, 'avg_response_hours')) / count($guruChartData), 1);
        
        // Cari top performer (guru dengan response terbanyak)
        $topIndex = 0;
        $topCount = 0;
        foreach ($guruChartData as $index => $guru) {
            if ($guru['responded_messages'] > $topCount) {
                $topCount = $guru['responded_messages'];
                $topIndex = $index;
            }
        }
        if ($topCount > 0) {
            $guruPerformanceStats['top_performer'] = $guruChartData[$topIndex]['nama_lengkap'];
            $guruPerformanceStats['top_performer_count'] = $topCount;
        }
        
        // Cari fastest responder
        $fastestTime = 999;
        $fastestName = '-';
        foreach ($guruChartData as $guru) {
            if ($guru['min_response_hours'] && $guru['min_response_hours'] < $fastestTime) {
                $fastestTime = $guru['min_response_hours'];
                $fastestName = $guru['nama_lengkap'];
            }
        }
        if ($fastestTime < 999) {
            $guruPerformanceStats['fastest_responder'] = $fastestName;
            $guruPerformanceStats['fastest_time'] = round($fastestTime, 1);
        }
        
        // Hitung completion rate
        $totalMessages = array_sum(array_column($guruChartData, 'total_messages'));
        $totalResponded = array_sum(array_column($guruChartData, 'responded_messages'));
        $guruPerformanceStats['completion_rate'] = $totalMessages > 0 ? round(($totalResponded / $totalMessages) * 100, 1) : 0;
    }
    
    wakepsek_log("Guru chart data loaded: " . count($guruChartData));
} catch (Exception $e) {
    wakepsek_log("Error loading guru chart data: " . $e->getMessage());
}

// ============================================================================
// AMBIL DATA UNTUK GRAFIK JENIS PESAN
// ============================================================================
$messageTypeChartData = [];
try {
    $typeChartStmt = $db->prepare("
        SELECT 
            mt.id,
            mt.jenis_pesan,
            mt.responder_type,
            COUNT(DISTINCT m.id) as total_messages,
            SUM(CASE WHEN mr.id IS NOT NULL THEN 1 ELSE 0 END) as responded_messages,
            SUM(CASE WHEN m.status = 'Pending' AND m.expired_at > NOW() THEN 1 ELSE 0 END) as pending_messages,
            SUM(CASE WHEN m.expired_at < NOW() AND m.status != 'Selesai' THEN 1 ELSE 0 END) as expired_messages,
            AVG(TIMESTAMPDIFF(HOUR, m.created_at, mr.created_at)) as avg_response_hours,
            MIN(TIMESTAMPDIFF(HOUR, m.created_at, mr.created_at)) as min_response_hours,
            MAX(TIMESTAMPDIFF(HOUR, m.created_at, mr.created_at)) as max_response_hours
        FROM message_types mt
        LEFT JOIN messages m ON mt.id = m.jenis_pesan_id
        LEFT JOIN message_responses mr ON m.id = mr.message_id
        WHERE DATE(m.created_at) BETWEEN ? AND ?
        GROUP BY mt.id
        HAVING total_messages > 0
        ORDER BY total_messages DESC
    ");
    $typeChartStmt->execute([$dateFrom, $dateTo]);
    $messageTypeChartData = $typeChartStmt->fetchAll();
    wakepsek_log("Message type chart data loaded: " . count($messageTypeChartData));
} catch (Exception $e) {
    wakepsek_log("Error loading message type chart data: " . $e->getMessage());
}

// ============================================================================
// AMBIL STATISTIK BERDASARKAN USER TYPE
// ============================================================================
$stats = [
    'total_responded' => 0,
    'pending_review' => 0,
    'reviewed' => 0,
    'total_guru' => count($guruList),
    'avg_response_time' => 0,
    'fastest_responder' => '-',
    'slowest_responder' => '-',
    'total_message_types' => count($messageTypeChartData)
];

try {
    if ($userType === 'Wakil_Kepala') {
        // Untuk Wakepsek: Total pesan yang sudah direspons guru
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT m.id) as total
            FROM messages m
            INNER JOIN message_responses mr ON m.id = mr.message_id
            WHERE DATE(m.created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['total_responded'] = $stmt->fetch()['total'] ?? 0;
        
        // Untuk Wakepsek: Pesan yang sudah direspons guru tapi belum direview wakepsek
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT m.id) as total
            FROM messages m
            INNER JOIN message_responses mr ON m.id = mr.message_id
            LEFT JOIN wakepsek_reviews wr ON m.id = wr.message_id
            WHERE DATE(m.created_at) BETWEEN ? AND ?
            AND wr.id IS NULL
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['pending_review'] = $stmt->fetch()['total'] ?? 0;
        
        // Untuk Wakepsek: Pesan yang sudah direview oleh Wakepsek
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT m.id) as total
            FROM messages m
            INNER JOIN wakepsek_reviews wr ON m.id = wr.message_id
            INNER JOIN users reviewer ON wr.reviewer_id = reviewer.id
            WHERE DATE(m.created_at) BETWEEN ? AND ?
            AND reviewer.user_type = 'Wakil_Kepala'
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['reviewed'] = $stmt->fetch()['total'] ?? 0;
        
        // Rata-rata waktu respons guru
        $stmt = $db->prepare("
            SELECT AVG(TIMESTAMPDIFF(HOUR, m.created_at, mr.created_at)) as avg_time
            FROM messages m
            INNER JOIN message_responses mr ON m.id = mr.message_id
            WHERE DATE(m.created_at) BETWEEN ? AND ?
            AND mr.created_at IS NOT NULL
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['avg_response_time'] = round($stmt->fetch()['avg_time'] ?? 0);
        
    } else { // Kepala_Sekolah
        // Untuk Kepsek: Total pesan yang sudah direspons guru ATAU sudah direview Wakepsek
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT m.id) as total
            FROM messages m
            WHERE (
                EXISTS (SELECT 1 FROM message_responses mr WHERE mr.message_id = m.id)
                OR 
                EXISTS (SELECT 1 FROM wakepsek_reviews wr WHERE wr.message_id = m.id)
            )
            AND DATE(m.created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['total_responded'] = $stmt->fetch()['total'] ?? 0;
        
        // Untuk Kepsek: Pesan yang sudah direspons guru/telah direview Wakepsek tapi belum direview Kepsek
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT m.id) as total
            FROM messages m
            WHERE (
                EXISTS (SELECT 1 FROM message_responses mr WHERE mr.message_id = m.id)
                OR 
                EXISTS (SELECT 1 FROM wakepsek_reviews wr WHERE wr.message_id = m.id)
            )
            AND NOT EXISTS (
                SELECT 1 FROM wakepsek_reviews wr2 
                WHERE wr2.message_id = m.id 
                AND wr2.reviewer_id = ?
            )
            AND DATE(m.created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $dateFrom, $dateTo]);
        $stats['pending_review'] = $stmt->fetch()['total'] ?? 0;
        
        // Untuk Kepsek: Pesan yang sudah direview oleh Kepsek
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT m.id) as total
            FROM messages m
            INNER JOIN wakepsek_reviews wr ON m.id = wr.message_id
            WHERE wr.reviewer_id = ?
            AND DATE(m.created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $dateFrom, $dateTo]);
        $stats['reviewed'] = $stmt->fetch()['total'] ?? 0;
        
        // Rata-rata waktu review (guru + wakepsek)
        $stmt = $db->prepare("
            SELECT AVG(TIMESTAMPDIFF(HOUR, m.created_at, wr.created_at)) as avg_time
            FROM messages m
            INNER JOIN wakepsek_reviews wr ON m.id = wr.message_id
            WHERE DATE(m.created_at) BETWEEN ? AND ?
            AND wr.reviewer_id IN (SELECT id FROM users WHERE user_type = 'Wakil_Kepala')
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $stats['avg_response_time'] = round($stmt->fetch()['avg_time'] ?? 0);
    }
    
    // Responder tercepat (berdasarkan responder_type)
    $responderConditions = [];
    if (!empty($responderTypes)) {
        foreach ($responderTypes as $type) {
            $responderConditions[] = "u.user_type = '" . addslashes($type) . "'";
        }
    } else {
        $responderConditions[] = "u.user_type IN ('Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Guru')";
    }
    
    $responderWhere = implode(' OR ', $responderConditions);
    
    $fastStmt = $db->query("
        SELECT u.nama_lengkap, AVG(TIMESTAMPDIFF(HOUR, m.created_at, mr.created_at)) as avg_time
        FROM users u
        INNER JOIN message_responses mr ON u.id = mr.responder_id
        INNER JOIN messages m ON mr.message_id = m.id
        WHERE ($responderWhere)
        GROUP BY u.id
        ORDER BY avg_time ASC
        LIMIT 1
    ");
    $fastest = $fastStmt->fetch();
    $stats['fastest_responder'] = $fastest ? $fastest['nama_lengkap'] . ' (' . round($fastest['avg_time']) . ' jam)' : '-';
    
    wakepsek_log("Statistics for $userType", $stats);
    
} catch (Exception $e) {
    wakepsek_log("Error loading statistics: " . $e->getMessage());
}

// ============================================================================
// BUILD QUERY UNTUK MESSAGES BERDASARKAN USER TYPE
// ============================================================================
$params = [];

if ($userType === 'Wakil_Kepala') {
    // Untuk Wakepsek: HANYA pesan yang sudah direspons guru
    $whereConditions = ["mr.id IS NOT NULL"];
} else {
    // Untuk Kepsek: HANYA pesan yang sudah direspons guru ATAU sudah direview Wakepsek
    $whereConditions = ["(mr.id IS NOT NULL OR wr_wakepsek.id IS NOT NULL)"];
}

// Filter tanggal
$whereConditions[] = "DATE(m.created_at) BETWEEN ? AND ?";
$params[] = $dateFrom;
$params[] = $dateTo;

// Filter status review berdasarkan user type
if ($userType === 'Wakil_Kepala') {
    if ($statusFilter === 'pending') {
        $whereConditions[] = "wr.id IS NULL";
    } elseif ($statusFilter === 'reviewed') {
        $whereConditions[] = "wr.id IS NOT NULL AND reviewer.user_type = 'Wakil_Kepala'";
    }
} else { // Kepala_Sekolah
    if ($statusFilter === 'pending') {
        $whereConditions[] = "NOT EXISTS (SELECT 1 FROM wakepsek_reviews wr2 WHERE wr2.message_id = m.id AND wr2.reviewer_id = ?)";
        $params[] = $userId;
    } elseif ($statusFilter === 'reviewed') {
        $whereConditions[] = "wr_kepsek.id IS NOT NULL";
    } elseif ($statusFilter === 'completed') {
        // Filter untuk pesan yang sudah direview lengkap (Guru → Wakepsek → Kepsek)
        $whereConditions[] = "mr.id IS NOT NULL AND wr_wakepsek.id IS NOT NULL AND wr_kepsek.id IS NOT NULL";
    }
}

// Filter guru responder (hanya guru yang sesuai dengan responder_type)
if ($guruFilter !== 'all' && !empty($guruFilter)) {
    $whereConditions[] = "mr.responder_id = ?";
    $params[] = $guruFilter;
}

// Filter search
if (!empty($search)) {
    $whereConditions[] = "(m.isi_pesan LIKE ? OR u.nama_lengkap LIKE ? OR mt.jenis_pesan LIKE ? OR guru.nama_lengkap LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

// Hitung total untuk pagination
if ($userType === 'Wakil_Kepala') {
    $countSql = "
        SELECT COUNT(DISTINCT m.id) as total
        FROM messages m
        INNER JOIN message_responses mr ON m.id = mr.message_id
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users guru ON mr.responder_id = guru.id
        LEFT JOIN wakepsek_reviews wr ON m.id = wr.message_id
        LEFT JOIN users reviewer ON wr.reviewer_id = reviewer.id
        WHERE $whereClause
    ";
} else {
    $countSql = "
        SELECT COUNT(DISTINCT m.id) as total
        FROM messages m
        LEFT JOIN message_responses mr ON m.id = mr.message_id
        LEFT JOIN wakepsek_reviews wr_wakepsek ON m.id = wr_wakepsek.message_id AND wr_wakepsek.reviewer_id IN (SELECT id FROM users WHERE user_type = 'Wakil_Kepala')
        LEFT JOIN wakepsek_reviews wr_kepsek ON m.id = wr_kepsek.message_id AND wr_kepsek.reviewer_id = ?
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users guru ON mr.responder_id = guru.id
        WHERE $whereClause
    ";
    
    // Tambahkan userId untuk parameter count
    if ($userType === 'Kepala_Sekolah' && strpos($countSql, '?')) {
        $countParams = array_merge([$userId], $params);
    } else {
        $countParams = $params;
    }
}

$countStmt = $db->prepare($countSql);
if ($userType === 'Kepala_Sekolah' && isset($countParams)) {
    $countStmt->execute($countParams);
} else {
    $countStmt->execute($params);
}
$totalMessages = $countStmt->fetch()['total'];
$totalPages = ceil($totalMessages / $perPage);
$page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));
$offset = ($page - 1) * $perPage;

// Query utama berdasarkan user type
if ($userType === 'Wakil_Kepala') {
    $sql = "
        SELECT 
            m.*,
            m.is_external,
            CASE 
                WHEN m.is_external = 1 THEN es.nama_lengkap
                ELSE COALESCE(u.nama_lengkap, m.pengirim_nama, 'Unknown')
            END as pengirim_nama_display,
            CASE 
                WHEN m.is_external = 1 THEN es.identitas
                ELSE u.user_type
            END as pengirim_tipe,
            mt.jenis_pesan as message_type,
            mt.responder_type as expected_responder_type,
            -- Informasi responder guru
            mr.id as response_id,
            mr.responder_id as guru_responder_id,
            guru.nama_lengkap as guru_responder_nama,
            guru.user_type as guru_responder_type,
            mr.catatan_respon as guru_response,
            mr.status as guru_response_status,
            mr.created_at as guru_response_date,
            -- Informasi review (hanya dari Wakepsek)
            wr.id as review_id,
            wr.reviewer_id,
            reviewer.nama_lengkap as reviewer_nama,
            reviewer.user_type as reviewer_type,
            wr.catatan as review_catatan,
            wr.created_at as review_date,
            -- Hitung jumlah attachment
            (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id) as attachment_count
        FROM messages m
        INNER JOIN message_responses mr ON m.id = mr.message_id
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN external_senders es ON m.external_sender_id = es.id
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users guru ON mr.responder_id = guru.id
        LEFT JOIN wakepsek_reviews wr ON m.id = wr.message_id
        LEFT JOIN users reviewer ON wr.reviewer_id = reviewer.id AND reviewer.user_type = 'Wakil_Kepala'
        WHERE $whereClause
        ORDER BY 
            CASE WHEN wr.id IS NULL THEN 0 ELSE 1 END, -- Pending review dulu
            m.created_at DESC
        LIMIT ?, ?
    ";
    
    $limitParams = array_merge($params, [$offset, $perPage]);
    
} else { // Kepala_Sekolah - QUERY SUDAH BENAR DENGAN DATA WAKEPSEK
    $sql = "
        SELECT 
            m.*,
            m.is_external,
            CASE 
                WHEN m.is_external = 1 THEN es.nama_lengkap
                ELSE COALESCE(u.nama_lengkap, m.pengirim_nama, 'Unknown')
            END as pengirim_nama_display,
            CASE 
                WHEN m.is_external = 1 THEN es.identitas
                ELSE u.user_type
            END as pengirim_tipe,
            mt.jenis_pesan as message_type,
            mt.responder_type as expected_responder_type,
            -- Informasi responder guru (jika ada)
            mr.id as response_id,
            mr.responder_id as guru_responder_id,
            guru.nama_lengkap as guru_responder_nama,
            guru.user_type as guru_responder_type,
            mr.catatan_respon as guru_response,
            mr.status as guru_response_status,
            mr.created_at as guru_response_date,
            -- Informasi review dari Wakepsek (berdasarkan tabel wakepsek_reviews)
            wr_wakepsek.id as wakepsek_review_id,
            wakepsek.nama_lengkap as wakepsek_reviewer_nama,
            wakepsek.user_type as wakepsek_reviewer_type,
            wr_wakepsek.catatan as wakepsek_review_catatan,
            wr_wakepsek.created_at as wakepsek_review_date,
            -- Informasi review dari Kepsek
            wr_kepsek.id as kepsek_review_id,
            wr_kepsek.catatan as kepsek_review_catatan,
            wr_kepsek.created_at as kepsek_review_date,
            -- Hitung jumlah attachment
            (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id) as attachment_count
        FROM messages m
        LEFT JOIN message_responses mr ON m.id = mr.message_id
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN external_senders es ON m.external_sender_id = es.id
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users guru ON mr.responder_id = guru.id
        -- Join dengan wakepsek_reviews untuk review dari Wakil Kepala
        LEFT JOIN wakepsek_reviews wr_wakepsek ON m.id = wr_wakepsek.message_id 
            AND wr_wakepsek.reviewer_id IN (SELECT id FROM users WHERE user_type = 'Wakil_Kepala')
        LEFT JOIN users wakepsek ON wr_wakepsek.reviewer_id = wakepsek.id
        -- Join dengan wakepsek_reviews untuk review dari Kepala Sekolah
        LEFT JOIN wakepsek_reviews wr_kepsek ON m.id = wr_kepsek.message_id AND wr_kepsek.reviewer_id = ?
        WHERE $whereClause
        ORDER BY 
            CASE 
                WHEN wr_kepsek.id IS NULL THEN 0 
                ELSE 1 
            END,
            m.created_at DESC
        LIMIT ?, ?
    ";
    
    $limitParams = array_merge([$userId], $params, [$offset, $perPage]);
}

$stmt = $db->prepare($sql);
$stmt->execute($limitParams);
$messages = $stmt->fetchAll();

wakepsek_log("Messages fetched for $userType: " . count($messages));

// ============================================================================
// AMBIL ATTACHMENTS UNTUK SEMUA MESSAGE (UNTUK KEPERLUAN DETAIL)
// ============================================================================
$attachmentsByMessage = [];
if (!empty($messages)) {
    $messageIds = array_column($messages, 'id');
    
    if (!empty($messageIds)) {
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        
        $attachStmt = $db->prepare("
            SELECT * FROM message_attachments 
            WHERE message_id IN ($placeholders)
            ORDER BY created_at ASC
        ");
        $attachStmt->execute($messageIds);
        $allAttachments = $attachStmt->fetchAll();
        
        // Group attachments by message_id
        foreach ($allAttachments as $attachment) {
            $attachmentsByMessage[$attachment['message_id']][] = $attachment;
        }
        
        wakepsek_log("Attachments loaded for " . count($attachmentsByMessage) . " messages");
    }
}

// ============================================================================
// HANDLE REVIEW SUBMISSION
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'submit_review') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $catatan = trim($_POST['catatan'] ?? '');
        
        wakepsek_log("Review submission", [
            'message_id' => $messageId,
            'catatan_length' => strlen($catatan),
            'user_type' => $userType
        ]);
        
        if ($messageId <= 0) {
            $_SESSION['error_message'] = 'ID pesan tidak valid.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
            exit;
        }
        
        // Cek apakah sudah pernah direview oleh user ini
        $checkStmt = $db->prepare("SELECT id FROM wakepsek_reviews WHERE message_id = ? AND reviewer_id = ?");
        $checkStmt->execute([$messageId, $userId]);
        
        if ($checkStmt->fetch()) {
            $_SESSION['error_message'] = 'Anda sudah pernah mereview pesan ini.';
        } else {
            try {
                $insertStmt = $db->prepare("
                    INSERT INTO wakepsek_reviews (message_id, reviewer_id, catatan, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $insertStmt->execute([$messageId, $userId, $catatan]);
                
                $role = ($userType === 'Kepala_Sekolah') ? 'Kepala Sekolah' : 'Wakil Kepala Sekolah';
                $_SESSION['success_message'] = "Review dari $role berhasil disimpan. Guru responder dapat melihat catatan Anda.";
                wakepsek_log("Review saved successfully for $userType");
                
            } catch (Exception $e) {
                wakepsek_log("Error saving review: " . $e->getMessage());
                $_SESSION['error_message'] = 'Gagal menyimpan review: ' . $e->getMessage();
            }
        }
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
}

// ============================================================================
// FUNGSI EXPORT PDF (SESUAIKAN DENGAN USER TYPE)
// ============================================================================
function exportToPDF($messages, $stats, $guruList, $dateFrom, $dateTo, $userType, $userNama) {
    require_once '../../vendor/fpdf/fpdf.php';
    
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetMargins(15, 10, 15);
    $pdf->SetAutoPageBreak(true, 15);
    
    // Halaman 1
    $pdf->AddPage();
    
    // Header
    $pdf->SetFillColor(13, 110, 253);
    $pdf->Rect(0, 0, 297, 20, 'F');
    
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(15, 4);
    $pdf->Cell(0, 7, 'SMKN 12 JAKARTA', 0, 1, 'L');
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetXY(15, 12);
    $pdf->Cell(0, 6, 'Laporan Monitoring ' . ($userType == 'Kepala_Sekolah' ? 'Kepala Sekolah' : 'Wakil Kepala Sekolah'), 0, 1, 'L');
    
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY(180, 4);
    $pdf->Cell(100, 4, 'Periode: ' . date('d/m/Y', strtotime($dateFrom)) . ' - ' . date('d/m/Y', strtotime($dateTo)), 0, 1, 'R');
    $pdf->SetXY(180, 9);
    $pdf->Cell(100, 4, 'Tanggal: ' . date('d/m/Y H:i:s'), 0, 1, 'R');
    $pdf->SetXY(180, 14);
    $pdf->Cell(100, 4, 'Dicetak oleh: ' . $userNama, 0, 1, 'R');
    
    // Statistik Cards
    $pdf->SetY(25);
    $startY = $pdf->GetY();
    
    // Judul card berbeda berdasarkan user type
    $cardTitle1 = ($userType == 'Kepala_Sekolah') ? 'Total Direspon G/W' : 'Total Direspon Guru';
    $cardTitle2 = ($userType == 'Kepala_Sekolah') ? 'Menunggu Review' : 'Menunggu Review';
    $cardTitle3 = ($userType == 'Kepala_Sekolah') ? 'Sudah Direview' : 'Sudah Direview';
    
    // Card 1: Total Direspon
    $pdf->SetFillColor(240, 248, 255);
    $pdf->SetDrawColor(13, 110, 253);
    $pdf->Rect(15, $startY, 85, 25, 'DF');
    
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(13, 110, 253);
    $pdf->SetXY(20, $startY + 4);
    $pdf->Cell(75, 6, $stats['total_responded'], 0, 0, 'C');
    $pdf->SetXY(20, $startY + 12);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(75, 5, $cardTitle1, 0, 0, 'C');
    
    // Card 2: Menunggu Review
    $pdf->SetFillColor(255, 255, 230);
    $pdf->SetDrawColor(255, 193, 7);
    $pdf->Rect(106, $startY, 85, 25, 'DF');
    
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(255, 193, 7);
    $pdf->SetXY(111, $startY + 4);
    $pdf->Cell(75, 6, $stats['pending_review'], 0, 0, 'C');
    $pdf->SetXY(111, $startY + 12);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(75, 5, $cardTitle2, 0, 0, 'C');
    
    // Card 3: Sudah Direview
    $pdf->SetFillColor(230, 255, 230);
    $pdf->SetDrawColor(25, 135, 84);
    $pdf->Rect(197, $startY, 85, 25, 'DF');
    
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(25, 135, 84);
    $pdf->SetXY(202, $startY + 4);
    $pdf->Cell(75, 6, $stats['reviewed'], 0, 0, 'C');
    $pdf->SetXY(202, $startY + 12);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(75, 5, $cardTitle3, 0, 0, 'C');
    
    // Tabel Pesan - judul berbeda
    $pdf->SetY($startY + 35);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(13, 110, 253);
    
    if ($userType == 'Kepala_Sekolah') {
        $pdf->Cell(0, 8, 'DAFTAR PESAN YANG TELAH DIRESPON GURU / WAKIL KEPALA SEKOLAH', 0, 1, 'L');
    } else {
        $pdf->Cell(0, 8, 'DAFTAR PESAN YANG TELAH DIRESPON GURU', 0, 1, 'L');
    }
    $pdf->Ln(2);
    
    // Header tabel
    $pdf->SetFillColor(13, 110, 253);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    
    if ($userType == 'Kepala_Sekolah') {
        $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Pengirim', 1, 0, 'L', true);
        $pdf->Cell(30, 8, 'Guru Responder', 1, 0, 'L', true);
        $pdf->Cell(20, 8, 'Tipe', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Wakepsek', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Kepsek', 1, 0, 'C', true);
        $pdf->Cell(90, 8, 'Isi Pesan / Catatan', 1, 1, 'L', true);
    } else {
        $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Pengirim', 1, 0, 'L', true);
        $pdf->Cell(40, 8, 'Guru Responder', 1, 0, 'L', true);
        $pdf->Cell(20, 8, 'Tipe', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Review', 1, 0, 'C', true);
        $pdf->Cell(85, 8, 'Isi Pesan / Catatan', 1, 1, 'L', true);
    }
    
    // Isi tabel
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 7);
    $fill = false;
    
    foreach (array_slice($messages, 0, 20) as $index => $msg) {
        if ($fill) $pdf->SetFillColor(245, 245, 245);
        
        if ($userType == 'Kepala_Sekolah') {
            $wakepsekStatus = !empty($msg['wakepsek_review_id']) ? '✓' : '-';
            $kepsekStatus = !empty($msg['kepsek_review_id']) ? '✓' : '-';
            $responderType = $msg['guru_responder_type'] ? str_replace('Guru_', '', $msg['guru_responder_type']) : '-';
            
            $pdf->Cell(10, 12, $index + 1, 1, 0, 'C', $fill);
            $pdf->Cell(35, 12, substr($msg['pengirim_nama_display'] ?? '-', 0, 15), 1, 0, 'L', $fill);
            $pdf->Cell(30, 12, substr($msg['guru_responder_nama'] ?? '-', 0, 12), 1, 0, 'L', $fill);
            $pdf->Cell(20, 12, $responderType, 1, 0, 'C', $fill);
            $pdf->Cell(20, 12, $wakepsekStatus, 1, 0, 'C', $fill);
            $pdf->Cell(20, 12, $kepsekStatus, 1, 0, 'C', $fill);
            
            // Kolom isi pesan + catatan
            $content = "Pesan: " . substr($msg['isi_pesan'] ?? '', 0, 30);
            if (!empty($msg['guru_response'])) {
                $content .= " | Respon: " . substr($msg['guru_response'], 0, 15);
            }
            
            $pdf->Cell(90, 12, $content, 1, 1, 'L', $fill);
        } else {
            $reviewStatus = !empty($msg['review_id']) ? '✓' : '⏳';
            $responderType = $msg['guru_responder_type'] ? str_replace('Guru_', '', $msg['guru_responder_type']) : '-';
            
            $pdf->Cell(10, 12, $index + 1, 1, 0, 'C', $fill);
            $pdf->Cell(40, 12, substr($msg['pengirim_nama_display'] ?? '-', 0, 15), 1, 0, 'L', $fill);
            $pdf->Cell(40, 12, substr($msg['guru_responder_nama'] ?? '-', 0, 15), 1, 0, 'L', $fill);
            $pdf->Cell(20, 12, $responderType, 1, 0, 'C', $fill);
            $pdf->Cell(20, 12, $msg['status'] ?? '-', 1, 0, 'C', $fill);
            $pdf->Cell(20, 12, $reviewStatus, 1, 0, 'C', $fill);
            
            // Kolom isi pesan + catatan
            $content = "Pesan: " . substr($msg['isi_pesan'] ?? '', 0, 35);
            if (!empty($msg['guru_response'])) {
                $content .= " | Respon: " . substr($msg['guru_response'], 0, 20);
            }
            
            $pdf->Cell(85, 12, $content, 1, 1, 'L', $fill);
        }
        
        $fill = !$fill;
    }
    
    // Footer
    $pdf->SetY(-20);
    $pdf->SetDrawColor(13, 110, 253);
    $pdf->Line(15, $pdf->GetY(), 282, $pdf->GetY());
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 4, 'Laporan monitoring ' . ($userType == 'Kepala_Sekolah' ? 'Kepala Sekolah' : 'Wakil Kepala Sekolah'), 0, 1, 'C');
    $pdf->Cell(0, 4, 'Halaman ' . $pdf->PageNo(), 0, 1, 'C');
    
    $pdf->Output('Monitoring_Report_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}

// ============================================================================
// FUNGSI EXPORT EXCEL (SESUAIKAN DENGAN USER TYPE)
// ============================================================================
function exportToExcel($messages, $stats, $guruList, $dateFrom, $dateTo, $userType, $userNama) {
    require_once '../../vendor/autoload.php';
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    
    // Set properti
    $spreadsheet->getProperties()
        ->setCreator("SMKN 12 Jakarta")
        ->setLastModifiedBy($userNama)
        ->setTitle("Laporan Monitoring " . $userType)
        ->setSubject("Laporan Monitoring")
        ->setDescription("Laporan monitoring pesan yang telah direspons");
    
    // Sheet 1: Ringkasan
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Ringkasan');
    
    $sheet->mergeCells('A1:F1');
    $sheet->setCellValue('A1', 'SMKN 12 JAKARTA - LAPORAN MONITORING ' . strtoupper(str_replace('_', ' ', $userType)));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
    
    $sheet->mergeCells('A2:F2');
    $sheet->setCellValue('A2', 'Periode: ' . date('d/m/Y', strtotime($dateFrom)) . ' - ' . date('d/m/Y', strtotime($dateTo)));
    $sheet->getStyle('A2')->getFont()->setItalic(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');
    
    $sheet->setCellValue('A4', 'STATISTIK MONITORING');
    $sheet->getStyle('A4')->getFont()->setBold(true);
    
    $sheet->setCellValue('A5', 'Total Direspon');
    $sheet->setCellValue('B5', $stats['total_responded']);
    $sheet->setCellValue('C5', 'Menunggu Review');
    $sheet->setCellValue('D5', $stats['pending_review']);
    $sheet->setCellValue('E5', 'Sudah Direview');
    $sheet->setCellValue('F5', $stats['reviewed']);
    
    $sheet->getStyle('A5:F5')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    
    // Sheet 2: Detail Pesan
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Detail Pesan');
    
    if ($userType == 'Kepala_Sekolah') {
        $sheet2->setCellValue('A1', 'DAFTAR PESAN YANG TELAH DIRESPON GURU / WAKIL KEPALA SEKOLAH');
        
        // Header untuk Kepsek
        $headers = ['No', 'Tanggal', 'Pengirim', 'Tipe', 'Jenis Pesan', 'Status', 
                    'Guru Responder', 'Tipe Guru', 'Respon Guru', 'Review Wakepsek', 'Review Kepsek', 'Lampiran'];
        
        $col = 'A';
        foreach ($headers as $header) {
            $sheet2->setCellValue($col . '3', $header);
            $sheet2->getStyle($col . '3')->getFont()->setBold(true);
            $sheet2->getStyle($col . '3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
            $sheet2->getStyle($col . '3')->getFont()->getColor()->setARGB('FFFFFFFF');
            $col++;
        }
        
        $row = 4;
        foreach ($messages as $index => $msg) {
            $col = 'A';
            $sheet2->setCellValue($col++ . $row, $index + 1);
            $sheet2->setCellValue($col++ . $row, date('d/m/Y H:i', strtotime($msg['created_at'])));
            $sheet2->setCellValue($col++ . $row, $msg['pengirim_nama_display'] ?? '-');
            $sheet2->setCellValue($col++ . $row, str_replace('_', ' ', $msg['pengirim_tipe'] ?? '-'));
            $sheet2->setCellValue($col++ . $row, $msg['message_type'] ?? '-');
            $sheet2->setCellValue($col++ . $row, $msg['status'] ?? '-');
            $sheet2->setCellValue($col++ . $row, $msg['guru_responder_nama'] ?? '-');
            $sheet2->setCellValue($col++ . $row, str_replace('Guru_', '', $msg['guru_responder_type'] ?? '-'));
            $sheet2->setCellValue($col++ . $row, $msg['guru_response'] ?? '-');
            $sheet2->setCellValue($col++ . $row, !empty($msg['wakepsek_review_id']) ? 'Sudah' : 'Belum');
            $sheet2->setCellValue($col++ . $row, !empty($msg['kepsek_review_id']) ? 'Sudah' : 'Belum');
            $sheet2->setCellValue($col++ . $row, ($msg['attachment_count'] ?? 0) > 0 ? 'Ada' : 'Tidak');
            $row++;
        }
        
    } else {
        $sheet2->setCellValue('A1', 'DAFTAR PESAN YANG TELAH DIRESPON GURU');
        
        // Header untuk Wakepsek
        $headers = ['No', 'Tanggal', 'Pengirim', 'Tipe', 'Jenis Pesan', 'Status', 
                    'Guru Responder', 'Tipe Guru', 'Respon Guru', 'Status Review', 'Reviewer', 'Lampiran'];
        
        $col = 'A';
        foreach ($headers as $header) {
            $sheet2->setCellValue($col . '3', $header);
            $sheet2->getStyle($col . '3')->getFont()->setBold(true);
            $sheet2->getStyle($col . '3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
            $sheet2->getStyle($col . '3')->getFont()->getColor()->setARGB('FFFFFFFF');
            $col++;
        }
        
        $row = 4;
        foreach ($messages as $index => $msg) {
            $col = 'A';
            $sheet2->setCellValue($col++ . $row, $index + 1);
            $sheet2->setCellValue($col++ . $row, date('d/m/Y H:i', strtotime($msg['created_at'])));
            $sheet2->setCellValue($col++ . $row, $msg['pengirim_nama_display'] ?? '-');
            $sheet2->setCellValue($col++ . $row, str_replace('_', ' ', $msg['pengirim_tipe'] ?? '-'));
            $sheet2->setCellValue($col++ . $row, $msg['message_type'] ?? '-');
            $sheet2->setCellValue($col++ . $row, $msg['status'] ?? '-');
            $sheet2->setCellValue($col++ . $row, $msg['guru_responder_nama'] ?? '-');
            $sheet2->setCellValue($col++ . $row, str_replace('Guru_', '', $msg['guru_responder_type'] ?? '-'));
            $sheet2->setCellValue($col++ . $row, $msg['guru_response'] ?? '-');
            $sheet2->setCellValue($col++ . $row, !empty($msg['review_id']) ? 'Sudah' : 'Belum');
            $sheet2->setCellValue($col++ . $row, $msg['reviewer_nama'] ?? '-');
            $sheet2->setCellValue($col++ . $row, ($msg['attachment_count'] ?? 0) > 0 ? 'Ada' : 'Tidak');
            $row++;
        }
    }
    
    // Auto-size columns
    foreach (range('A', 'L') as $column) {
        $sheet2->getColumnDimension($column)->setAutoSize(true);
    }
    
    // Output file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Monitoring_Report_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ============================================================================
// HANDLE EXPORT
// ============================================================================
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    if ($exportType === 'pdf') {
        exportToPDF($messages, $stats, $guruList, $dateFrom, $dateTo, $userType, $userNama);
    } elseif ($exportType === 'excel') {
        exportToExcel($messages, $stats, $guruList, $dateFrom, $dateTo, $userType, $userNama);
    }
}

// ============================================================================
// TAMPILAN HALAMAN
// ============================================================================
require_once '../../includes/header.php';

// Tentukan judul tabel berdasarkan user type
$tableTitle = ($userType === 'Kepala_Sekolah') 
    ? 'Daftar Pesan yang Direspon Guru & Wakil Kepala Sekolah'
    : 'Daftar Pesan yang Direspon Guru';

// Fungsi untuk mendapatkan label tipe guru
function getGuruTypeLabel($type) {
    global $responderTypeLabels;
    return isset($responderTypeLabels[$type]) ? $responderTypeLabels[$type] : str_replace('_', ' ', $type);
}
?>

<!-- ============================================================================
     IMAGE PREVIEW MODAL - MODAL 3 (Tertinggi)
     ============================================================================ -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-image me-2"></i>
                    Preview Gambar
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-3" id="imagePreviewContainer">
                <!-- Image will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
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
            <h1 class="h2 mb-0">
                <i class="fas fa-tachometer-alt me-2 text-primary"></i>
                Dashboard <?php echo $userType === 'Kepala_Sekolah' ? 'Kepala Sekolah' : 'Wakil Kepala Sekolah'; ?>
            </h1>
            <p class="text-muted mb-0">
                <?php echo date('l, d F Y'); ?> | <?php echo htmlspecialchars($userNama); ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success" onclick="exportReport('excel')">
                <i class="fas fa-file-excel me-1"></i> Excel
            </button>
            <button class="btn btn-danger" onclick="exportReport('pdf')">
                <i class="fas fa-file-pdf me-1"></i> PDF
            </button>
            <button class="btn btn-primary" onclick="window.location.reload()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="btn btn-outline-danger">
                <i class="fas fa-sign-out-alt me-1"></i> Logout
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
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-primary bg-opacity-10 rounded p-3">
                                <i class="fas fa-check-circle fa-2x text-primary"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-uppercase text-muted mb-1">
                                <?php echo ($userType === 'Kepala_Sekolah') ? 'Direspon G/W' : 'Direspon Guru'; ?>
                            </h6>
                            <h2 class="mb-0"><?php echo number_format($stats['total_responded']); ?></h2>
                            <small class="text-muted">Total</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-warning bg-opacity-10 rounded p-3">
                                <i class="fas fa-hourglass-half fa-2x text-warning"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-uppercase text-muted mb-1">
                                <?php echo ($userType === 'Kepala_Sekolah') ? 'Menunggu Review' : 'Menunggu Review'; ?>
                            </h6>
                            <h2 class="mb-0"><?php echo number_format($stats['pending_review']); ?></h2>
                            <small class="text-muted">Perlu perhatian</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-success bg-opacity-10 rounded p-3">
                                <i class="fas fa-clipboard-check fa-2x text-success"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-uppercase text-muted mb-1">
                                <?php echo ($userType === 'Kepala_Sekolah') ? 'Sudah Direview' : 'Sudah Direview'; ?>
                            </h6>
                            <h2 class="mb-0"><?php echo number_format($stats['reviewed']); ?></h2>
                            <small class="text-muted">Telah di-approve</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar bg-info bg-opacity-10 rounded p-3">
                                <i class="fas fa-clock fa-2x text-info"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-uppercase text-muted mb-1">Rata-rata Respon</h6>
                            <h2 class="mb-0"><?php echo $stats['avg_response_time']; ?> jam</h2>
                            <small class="text-muted">Tercepat: <?php echo $stats['fastest_responder']; ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- GRAFIK KOMPARASI KINERJA GURU (PROFESIONAL & INFORMATIF) -->
    <!-- ========================================================= -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-lg">
                <div class="card-header bg-white py-3 border-bottom border-primary border-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0 fw-bold">
                                <i class="fas fa-chart-bar me-2 text-primary"></i>
                                Komparasi Kinerja Guru Responder
                            </h5>
                            <p class="text-muted small mb-0">
                                <i class="fas fa-info-circle me-1"></i>
                                Berdasarkan tipe guru dari message_types.responder_type
                            </p>
                        </div>
                        <div>
                            <span class="badge bg-primary p-2">
                                <i class="fas fa-calendar me-1"></i>
                                <?php echo date('d/m/Y', strtotime($dateFrom)); ?> - <?php echo date('d/m/Y', strtotime($dateTo)); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <!-- Statistik Ringkas Guru -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="bg-light p-3 rounded-3 text-center">
                                <div class="small text-muted mb-1">Total Guru Aktif</div>
                                <div class="h3 mb-0 fw-bold text-primary"><?php echo count($guruChartData); ?></div>
                                <div class="progress mt-2" style="height: 4px;">
                                    <div class="progress-bar bg-primary" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="bg-light p-3 rounded-3 text-center">
                                <div class="small text-muted mb-1">Total Pesan Direspon</div>
                                <div class="h3 mb-0 fw-bold text-success"><?php echo $guruPerformanceStats['total_responded_all']; ?></div>
                                <div class="progress mt-2" style="height: 4px;">
                                    <div class="progress-bar bg-success" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="bg-light p-3 rounded-3 text-center">
                                <div class="small text-muted mb-1">Rata-rata Waktu</div>
                                <div class="h3 mb-0 fw-bold text-info"><?php echo $guruPerformanceStats['avg_response_all']; ?> jam</div>
                                <div class="progress mt-2" style="height: 4px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo min(100, $guruPerformanceStats['avg_response_all']); ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="bg-light p-3 rounded-3 text-center">
                                <div class="small text-muted mb-1">Tingkat Penyelesaian</div>
                                <div class="h3 mb-0 fw-bold text-warning"><?php echo $guruPerformanceStats['completion_rate']; ?>%</div>
                                <div class="progress mt-2" style="height: 4px;">
                                    <div class="progress-bar bg-warning" style="width: <?php echo $guruPerformanceStats['completion_rate']; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="chart-container" style="height: 400px; position: relative;">
                                <canvas id="guruPerformanceChart"></canvas>
                            </div>
                            
                            <!-- Legend dengan Tooltip -->
                            <div class="d-flex flex-wrap justify-content-center gap-3 mt-3">
                                <div class="d-flex align-items-center" title="Total pesan yang masuk ke guru">
                                    <span style="display: inline-block; width: 16px; height: 16px; background-color: #17a2b8; margin-right: 6px; border-radius: 4px;"></span>
                                    <small class="text-muted">Total Pesan</small>
                                </div>
                                <div class="d-flex align-items-center" title="Pesan yang masih menunggu respon">
                                    <span style="display: inline-block; width: 16px; height: 16px; background-color: #ffc107; margin-right: 6px; border-radius: 4px;"></span>
                                    <small class="text-muted">Pending</small>
                                </div>
                                <div class="d-flex align-items-center" title="Pesan yang sudah direspons">
                                    <span style="display: inline-block; width: 16px; height: 16px; background-color: #28a745; margin-right: 6px; border-radius: 4px;"></span>
                                    <small class="text-muted">Sudah Direspon</small>
                                </div>
                                <div class="d-flex align-items-center" title="Pesan yang expired (lewat batas waktu)">
                                    <span style="display: inline-block; width: 16px; height: 16px; background-color: #dc3545; margin-right: 6px; border-radius: 4px;"></span>
                                    <small class="text-muted">Expired</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <!-- Statistik Detail Guru -->
                            <div class="bg-light p-4 rounded-3 h-100">
                                <h6 class="fw-bold mb-3 border-bottom pb-2">
                                    <i class="fas fa-trophy me-2 text-warning"></i>
                                    Pencapaian Terbaik
                                </h6>
                                
                                <div class="mb-4">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-2">
                                                <i class="fas fa-crown text-warning fa-2x"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <small class="text-muted d-block">Top Performer</small>
                                            <strong class="fs-5"><?php echo $guruPerformanceStats['top_performer']; ?></strong>
                                            <span class="badge bg-warning ms-2"><?php echo $guruPerformanceStats['top_performer_count']; ?> respon</span>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-info bg-opacity-10 rounded-circle p-2">
                                                <i class="fas fa-rocket text-info fa-2x"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <small class="text-muted d-block">Fastest Responder</small>
                                            <strong class="fs-5"><?php echo $guruPerformanceStats['fastest_responder']; ?></strong>
                                            <span class="badge bg-info ms-2"><?php echo $guruPerformanceStats['fastest_time']; ?> jam</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <h6 class="fw-bold mb-3 border-bottom pb-2">
                                    <i class="fas fa-chart-line me-2 text-success"></i>
                                    Distribusi Kinerja
                                </h6>
                                
                                <?php
                                $highPerformers = 0;
                                $mediumPerformers = 0;
                                $lowPerformers = 0;
                                
                                foreach ($guruChartData as $guru) {
                                    $rate = $guru['total_messages'] > 0 ? ($guru['responded_messages'] / $guru['total_messages']) * 100 : 0;
                                    if ($rate >= 80) $highPerformers++;
                                    elseif ($rate >= 50) $mediumPerformers++;
                                    else $lowPerformers++;
                                }
                                ?>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Kinerja Tinggi (>80%)</small>
                                        <small class="fw-bold"><?php echo $highPerformers; ?> guru</small>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo ($highPerformers / max(1, count($guruChartData))) * 100; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Kinerja Sedang (50-80%)</small>
                                        <small class="fw-bold"><?php echo $mediumPerformers; ?> guru</small>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-warning" style="width: <?php echo ($mediumPerformers / max(1, count($guruChartData))) * 100; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Kinerja Rendah (<50%)</small>
                                        <small class="fw-bold"><?php echo $lowPerformers; ?> guru</small>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-danger" style="width: <?php echo ($lowPerformers / max(1, count($guruChartData))) * 100; ?>%"></div>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="text-center">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Klik pada bar chart untuk detail lengkap
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- GRAFIK KOMPARASI JENIS PESAN -->
    <!-- ========================================================= -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie me-2 text-success"></i>
                        Komparasi Jenis Pesan
                        <small class="text-muted ms-2">Berdasarkan message_types dan responder_type</small>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-7">
                            <div class="chart-container" style="height: 350px;">
                                <canvas id="messageTypeChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="bg-light p-3 rounded">
                                <h6 class="fw-bold mb-3">
                                    <i class="fas fa-tags me-2 text-info"></i>
                                    Statistik Jenis Pesan
                                </h6>
                                
                                <div class="table-responsive" style="max-height: 300px;">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Jenis Pesan</th>
                                                <th>Responder</th>
                                                <th>Total</th>
                                                <th>Direspon</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($messageTypeChartData as $type): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(substr($type['jenis_pesan'], 0, 20)); ?></td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo str_replace('Guru_', '', $type['responder_type'] ?? '-'); ?>
                                                    </span>
                                                </td>
                                                <td class="fw-bold"><?php echo $type['total_messages']; ?></td>
                                                <td>
                                                    <?php 
                                                    $responseRate = $type['total_messages'] > 0 
                                                        ? round(($type['responded_messages'] / $type['total_messages']) * 100, 1) 
                                                        : 0;
                                                    ?>
                                                    <div class="progress" style="height: 5px;">
                                                        <div class="progress-bar bg-success" style="width: <?php echo $responseRate; ?>%"></div>
                                                    </div>
                                                    <small class="text-muted"><?php echo $type['responded_messages']; ?> (<?php echo $responseRate; ?>%)</small>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <hr>
                                <div class="d-flex justify-content-between mt-2">
                                    <span class="text-muted">Total Jenis Pesan:</span>
                                    <span class="fw-bold"><?php echo $stats['total_message_types']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Total Pesan:</span>
                                    <span class="fw-bold"><?php echo array_sum(array_column($messageTypeChartData, 'total_messages')); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label fw-bold small">STATUS REVIEW</label>
                    <select class="form-select" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Semua</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>
                            <?php echo ($userType === 'Kepala_Sekolah') ? 'Menunggu Review Kepsek' : 'Menunggu Review'; ?>
                        </option>
                        <option value="reviewed" <?php echo $statusFilter === 'reviewed' ? 'selected' : ''; ?>>
                            <?php echo ($userType === 'Kepala_Sekolah') ? 'Sudah Direview Kepsek' : 'Sudah Direview'; ?>
                        </option>
                        <?php if ($userType === 'Kepala_Sekolah'): ?>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>
                            ✓ Review Lengkap (Guru → Wakepsek → Kepsek)
                        </option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-bold small">GURU RESPONDER</label>
                    <select class="form-select" name="guru">
                        <option value="all">Semua Guru</option>
                        <?php 
                        $currentType = '';
                        foreach ($guruList as $guru): 
                            if ($currentType !== $guru['user_type']):
                                if ($currentType !== '') echo '</optgroup>';
                                $currentType = $guru['user_type'];
                                $label = getGuruTypeLabel($currentType);
                                echo '<optgroup label="' . htmlspecialchars($label) . '">';
                            endif;
                        ?>
                        <option value="<?php echo $guru['id']; ?>" <?php echo $guruFilter == $guru['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($guru['nama_lengkap']); ?>
                        </option>
                        <?php 
                        endforeach; 
                        if ($currentType !== '') echo '</optgroup>';
                        ?>
                    </select>
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Berdasarkan responder_type dari message_types
                    </small>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-bold small">TANGGAL DARI</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo $dateFrom; ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-bold small">TANGGAL SAMPAI</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo $dateTo; ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-bold small">CARI</label>
                    <input type="text" class="form-control" name="search" placeholder="Cari pesan/pengirim/guru..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Messages Table -->
    <div class="card border-0 shadow">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-2 text-primary"></i>
                <?php echo $tableTitle; ?>
                <span class="badge bg-primary ms-2"><?php echo $totalMessages; ?></span>
            </h5>
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i>
                <?php if ($userType === 'Kepala_Sekolah'): ?>
                Menampilkan hanya pesan yang telah direspons guru atau Wakepsek
                <?php else: ?>
                Menampilkan hanya pesan yang telah direspons guru
                <?php endif; ?>
            </small>
        </div>
        
        <div class="card-body p-0">
            <?php if (empty($messages)): ?>
            <div class="text-center py-5">
                <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                <h6>Tidak ada data</h6>
                <p class="text-muted small">
                    <?php if ($userType === 'Kepala_Sekolah'): ?>
                    Tidak ada pesan yang telah direspons guru atau wakil kepala sekolah pada periode ini.
                    <?php else: ?>
                    Tidak ada pesan yang telah direspons guru pada periode ini.
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
                            <th>Guru Responder</th>
                            <th>Tipe Guru</th>
                            <th width="70">Lamp</th>
                            <?php if ($userType === 'Kepala_Sekolah'): ?>
                            <th width="70">Wakepsek</th>
                            <th width="70">Kepsek</th>
                            <?php else: ?>
                            <th width="70">Status</th>
                            <th width="70">Review</th>
                            <?php endif; ?>
                            <th>Isi Pesan / Respon Guru</th>
                            <th width="120">Waktu</th>
                            <th width="120">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $index => $msg): 
                            if ($userType === 'Kepala_Sekolah') {
                                $hasWakepsekReview = !empty($msg['wakepsek_review_id']);
                                $hasKepsekReview = !empty($msg['kepsek_review_id']);
                                $hasAttachments = ($msg['attachment_count'] ?? 0) > 0;
                                $rowClass = $hasKepsekReview ? 'table-success' : ($hasWakepsekReview ? 'table-info' : '');
                            } else {
                                $isReviewed = !empty($msg['review_id']);
                                $hasAttachments = ($msg['attachment_count'] ?? 0) > 0;
                                $rowClass = $isReviewed ? 'table-success' : ($msg['status'] == 'Disetujui' ? 'table-info' : '');
                            }
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td><?php echo $offset + $index + 1; ?></td>
                            <td>
                                <div class="fw-bold">
                                    <?php echo htmlspecialchars($msg['pengirim_nama_display'] ?? 'Unknown'); ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo str_replace('_', ' ', $msg['pengirim_tipe'] ?? ''); ?>
                                </small>
                            </td>
                            <td>
                                <?php if (!empty($msg['guru_responder_nama'])): ?>
                                <div class="fw-bold">
                                    <?php echo htmlspecialchars($msg['guru_responder_nama']); ?>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($msg['guru_responder_type'])): ?>
                                <span class="badge bg-info">
                                    <?php echo getGuruTypeLabel($msg['guru_responder_type']); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
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
                            
                            <?php if ($userType === 'Kepala_Sekolah'): ?>
                            <td class="text-center">
                                <?php if ($hasWakepsekReview): ?>
                                <span class="badge bg-info" title="Direview oleh Wakil Kepala">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <button type="button" 
                                        class="btn btn-sm btn-link p-0" 
                                        onclick='showWakepsekReview(<?php echo htmlspecialchars(json_encode($msg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)); ?>)'
                                        title="Lihat Review Wakepsek">
                                    <i class="fas fa-eye text-muted"></i>
                                </button>
                                <?php else: ?>
                                <span class="badge bg-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($hasKepsekReview): ?>
                                <span class="badge bg-success" title="Direview oleh Kepala Sekolah">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <?php elseif (!$hasKepsekReview && $hasWakepsekReview): ?>
                                <button class="btn btn-sm btn-outline-success" 
                                        onclick="showReviewModal(<?php echo $msg['id']; ?>, '<?php echo addslashes($msg['guru_responder_nama'] ?? 'Guru'); ?>', <?php echo htmlspecialchars(json_encode($msg)); ?>)"
                                        title="Beri Review (Wakepsek sudah mereview)">
                                    <i class="fas fa-check"></i>
                                </button>
                                <?php elseif (!$hasKepsekReview && !$hasWakepsekReview): ?>
                                <span class="badge bg-secondary" title="Menunggu review dari Wakepsek">-</span>
                                <?php endif; ?>
                            </td>
                            <?php else: ?>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $msg['status'] == 'Disetujui' ? 'success' : 'warning'; ?>">
                                    <?php echo $msg['status'] ?? 'Pending'; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($isReviewed): ?>
                                <span class="badge bg-success">✓</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">⏳</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            
                            <td>
                                <div class="mb-1">
                                    <small class="text-muted">📨 Pesan:</small>
                                    <div class="text-truncate" style="max-width: 200px;">
                                        <?php echo htmlspecialchars($msg['isi_pesan'] ?? ''); ?>
                                    </div>
                                </div>
                                <?php if (!empty($msg['guru_response'])): ?>
                                <div>
                                    <small class="text-muted">💬 Respon:</small>
                                    <div class="text-truncate" style="max-width: 200px;">
                                        <?php echo htmlspecialchars($msg['guru_response']); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                            
                            <td>
                                <small>
                                    <i class="far fa-calendar me-1"></i>
                                    <?php echo date('d/m/Y', strtotime($msg['created_at'])); ?>
                                    <br>
                                    <i class="far fa-clock me-1"></i>
                                    <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                                </small>
                            </td>
                            
                            <td>
                                <?php if ($userType === 'Wakil_Kepala'): ?>
                                    <?php if (empty($msg['review_id'])): ?>
                                    <button class="btn btn-sm btn-success" 
                                            onclick="showReviewModal(<?php echo $msg['id']; ?>, '<?php echo addslashes($msg['guru_responder_nama'] ?? 'Guru'); ?>', <?php echo htmlspecialchars(json_encode($msg)); ?>)"
                                            title="Beri Review (Guru sudah merespon)">
                                        <i class="fas fa-check me-1"></i> Review
                                    </button>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" 
                                            onclick="showReviewDetail(<?php echo htmlspecialchars(json_encode($msg)); ?>)"
                                            title="Lihat Detail Review">
                                        <i class="fas fa-eye me-1"></i> Detail
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <button class="btn btn-sm btn-outline-primary mt-1"
                                        onclick="showMessageDetail(<?php echo htmlspecialchars(json_encode($msg)); ?>, '<?php echo $userType; ?>', <?php echo isset($attachmentsByMessage[$msg['id']]) ? htmlspecialchars(json_encode($attachmentsByMessage[$msg['id']])) : '[]'; ?>)"
                                        title="Lihat Detail Lengkap">
                                    <i class="fas fa-info-circle"></i>
                                </button>
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
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================================
     MODAL REVIEW - DENGAN THUMBNAIL GAMBAR LAMPIRAN DAN TANGGAL LENGKAP
     ============================================================================ -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-clipboard-check me-2 text-success"></i>
                    Beri Review / Checklist
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="submit_review">
                <input type="hidden" name="message_id" id="reviewMessageId">
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <?php if ($userType === 'Wakil_Kepala'): ?>
                        Anda akan memberikan review untuk respon dari guru: 
                        <?php else: ?>
                        Anda akan memberikan review untuk pesan yang telah direview oleh Wakil Kepala Sekolah:
                        <?php endif; ?>
                        <strong id="guruName"></strong>
                    </div>
                    
                    <!-- Pesan Asli User dengan Tanggal Lengkap -->
                    <div id="originalMessageContainer" class="mb-4 p-3 bg-primary bg-opacity-10 rounded border-start border-primary border-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h6 class="text-primary mb-0">
                                <i class="fas fa-envelope me-2"></i>
                                Pesan Asli dari Pengirim
                            </h6>
                            <div id="originalMessageDate" class="text-muted small">
                                <i class="far fa-calendar-alt me-1"></i>
                                <span id="originalMessageDateText">-</span>
                                <i class="far fa-clock ms-2 me-1"></i>
                                <span id="originalMessageTimeText">-</span>
                                <span class="badge bg-light text-dark ms-2" id="originalMessageDayText">-</span>
                            </div>
                        </div>
                        <div id="originalMessageContent" class="mb-2 p-2 bg-white rounded" style="white-space: pre-line;">
                            <!-- Isi dari JavaScript -->
                        </div>
                        
                        <!-- Informasi Pengirim (Tambahan) -->
                        <div id="originalMessageSender" class="mt-2 small text-muted">
                            <i class="fas fa-user me-1"></i>
                            <span id="originalMessageSenderName">-</span>
                            <span class="badge bg-light text-dark ms-2" id="originalMessageSenderType">-</span>
                        </div>
                        
                        <!-- ======================================================== -->
                        <!-- THUMBNAIL GAMBAR LAMPIRAN -->
                        <!-- ======================================================== -->
                        <div id="reviewAttachmentsContainer" class="mt-3" style="display: none;">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-images me-2 text-primary"></i>
                                <small class="fw-bold text-primary">Lampiran Gambar:</small>
                            </div>
                            <div id="reviewAttachmentsGrid" class="row g-2">
                                <!-- Thumbnail akan dimuat via JavaScript -->
                            </div>
                        </div>
                    </div>
                    
                    <!-- Respon Guru -->
                    <div id="guruResponseContainer" class="mb-4 p-3 bg-success bg-opacity-10 rounded border-start border-success border-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h6 class="text-success mb-0">
                                <i class="fas fa-chalkboard-teacher me-2"></i>
                                Respon dari Guru Responder
                            </h6>
                            <div id="guruResponseDate" class="text-muted small">
                                <i class="far fa-calendar-alt me-1"></i>
                                <span id="guruResponseDateText">-</span>
                                <i class="far fa-clock ms-2 me-1"></i>
                                <span id="guruResponseTimeText">-</span>
                            </div>
                        </div>
                        <div id="guruResponseContent" class="mb-2 p-2 bg-white rounded" style="white-space: pre-line;">
                            <!-- Isi dari JavaScript -->
                        </div>
                        <small class="text-muted" id="guruResponseMeta">
                            <!-- Isi dari JavaScript -->
                        </small>
                    </div>
                    
                    <!-- Review dari Wakil Kepala Sekolah (untuk Kepsek) - DIPERBAIKI BERDASARKAN STRUKTUR TABEL wakepsek_reviews -->
                    <?php if ($userType === 'Kepala_Sekolah'): ?>
                    <div id="wakepsekReviewContainer" class="mb-4 p-3 bg-info bg-opacity-10 rounded border-start border-info border-4" style="display: none;">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h6 class="text-info mb-0">
                                <i class="fas fa-user-tie me-2"></i>
                                Review dari Wakil Kepala Sekolah
                            </h6>
                            <div id="wakepsekReviewDate" class="text-muted small">
                                <i class="far fa-calendar-alt me-1"></i>
                                <span id="wakepsekReviewDateText">-</span>
                                <i class="far fa-clock ms-2 me-1"></i>
                                <span id="wakepsekReviewTimeText">-</span>
                            </div>
                        </div>
                        
                        <!-- Card untuk menampilkan Review Wakepsek dengan struktur yang jelas -->
                        <div class="wakepsek-review-card" style="border: 1px solid #b8daff; border-radius: 8px; overflow: hidden;">
                            <div class="wakepsek-review-header" style="background: #17a2b8; color: white; padding: 12px 15px;">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-user-tie me-2" style="font-size: 18px;"></i>
                                    <span style="font-weight: 600;" id="wakepsekReviewerName">Wakil Kepala Sekolah</span>
                                </div>
                            </div>
                            
                            <div class="wakepsek-review-body" style="background: #f8f9fa; padding: 15px;">
                                <div class="wakepsek-review-content" id="wakepsekReviewContent" style="background: white; padding: 15px; border-radius: 6px; min-height: 100px; border: 1px solid #e9ecef; font-size: 14px; line-height: 1.6; white-space: pre-line;">
                                    <!-- Isi review Wakepsek akan dimuat via JavaScript -->
                                </div>
                                
                                <div class="wakepsek-review-meta mt-3 text-end" style="font-size: 12px; color: #6c757d;">
                                    <i class="far fa-calendar-alt me-1"></i>
                                    <span id="wakepsekReviewFullDate">-</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Debug Info (hanya untuk development) -->
                        <div class="mt-2 small text-muted" style="display: none;" id="wakepsekReviewDebug">
                            <!-- Debug info akan diisi JavaScript jika diperlukan -->
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Form Catatan Review -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Catatan Review <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="catatan" rows="4" 
                                  placeholder="Tulis catatan review Anda di sini..." required></textarea>
                        <small class="text-muted">
                            Catatan ini akan dibaca oleh guru responder.
                        </small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i> Simpan Review
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================================
     MODAL DETAIL LENGKAP PESAN - MODAL 1 (Modal Utama)
     ============================================================================ -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <!-- Header dengan gradien -->
            <div class="modal-header bg-gradient-primary text-white py-4" style="background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white bg-opacity-25 p-3 me-3">
                        <i class="fas fa-info-circle fa-2x text-white"></i>
                    </div>
                    <div>
                        <h4 class="modal-title fw-bold mb-1">Detail Lengkap Pesan</h4>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-white text-primary me-2" id="detailMessageReference">-</span>
                            <small class="opacity-75" id="detailMessageId">ID: -</small>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <!-- Body modal - konten akan diisi oleh JavaScript -->
            <div class="modal-body p-4" id="detailModalContent" style="background: #f8fafc;">
                <!-- Konten akan diisi oleh JavaScript -->
            </div>
            
            <!-- Footer -->
            <div class="modal-footer bg-light py-3 border-0">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal untuk Detail Review (Wakepsek) -->
<div class="modal fade" id="reviewDetailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-clipboard-check me-2 text-success"></i>
                    Detail Review
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="reviewDetailContent">
                <!-- Isi diisi via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================
     MODAL UNTUK DETAIL REVIEW WAKEPSEK (Khusus Kepsek) - TAMPILAN PROFESIONAL HANYA REVIEW SAJA
     ============================================================================ -->
<div class="modal fade" id="wakepsekReviewModal" tabindex="-1">
    <div class="modal-dialog modal-md">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-gradient-info text-white py-3" style="background: linear-gradient(135deg, #17a2b8 0%, #0f6674 100%);">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3">
                        <i class="fas fa-user-tie fa-2x text-white"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Review Wakil Kepala Sekolah</h5>
                        <small class="opacity-75" id="wakepsekReviewerNameDisplay">-</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4" id="wakepsekReviewModalContent">
                <!-- Konten akan diisi oleh JavaScript -->
            </div>
            
            <div class="modal-footer bg-light py-2">
                <div class="w-100 d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="wakepsekReviewDateDisplay">
                        <i class="far fa-clock me-1"></i>
                        <span>-</span>
                    </small>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================
     MESSAGE ATTACHMENTS MODAL - MODAL 2 (Muncul di atas detail modal)
     ============================================================================ -->
<div class="modal fade" id="attachmentsModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-images me-2 text-primary"></i>
                    Lampiran Gambar
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="attachmentsContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                    <p class="mt-2">Memuat lampiran...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<style>
.avatar {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.table > :not(caption) > * > * {
    padding: 0.75rem;
}
.badge {
    font-weight: 500;
}
.chart-container {
    position: relative;
    width: 100%;
    height: 100%;
}
.progress {
    border-radius: 10px;
}
.border-3 {
    border-width: 3px !important;
}
.shadow-lg {
    box-shadow: 0 1rem 3rem rgba(0,0,0,.175) !important;
}
/* Style untuk tampilan review yang lebih baik */
.border-primary.border-4 {
    border-left-width: 4px !important;
}
.border-success.border-4 {
    border-left-width: 4px !important;
}
.border-info.border-4 {
    border-left-width: 4px !important;
}
/* Style untuk modal review */
#originalMessageContent, #guruResponseContent {
    max-height: 200px;
    overflow-y: auto;
    font-size: 0.95rem;
    line-height: 1.5;
}
/* Style untuk tanggal di modal review */
#originalMessageDate, #guruResponseDate, #wakepsekReviewDate {
    background-color: rgba(255,255,255,0.5);
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.85rem;
}

/* ============================================================================
   TAMPILAN PROFESIONAL MODAL DETAIL PESAN
   ============================================================================ */
#detailModal .modal-content {
    border: none;
    border-radius: 24px;
    overflow: hidden;
}

#detailModal .modal-header {
    border-bottom: none;
    padding: 1.8rem 2rem;
}

#detailModal .modal-body {
    padding: 2rem !important;
}

#detailModal .modal-footer {
    border-top: 1px solid rgba(0,0,0,0.05);
    padding: 1.2rem 2rem;
}

/* Cards */
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

/* Grid Layout */
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

/* Badges */
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

/* Content Boxes */
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

.detail-content-box.success {
    background: #d1e7dd;
    border-left: 4px solid #198754;
}

.detail-content-box.info {
    background: #cff4fc;
    border-left: 4px solid #0dcaf0;
}

.detail-content-box.warning {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
}

/* Attachment Grid */
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

/* Timeline */
.detail-timeline {
    position: relative;
    padding-left: 2rem;
}

.detail-timeline::before {
    content: '';
    position: absolute;
    left: 0.8rem;
    top: 0.5rem;
    bottom: 0.5rem;
    width: 2px;
    background: linear-gradient(to bottom, #0d6efd, #198754);
    border-radius: 2px;
}

.detail-timeline-item {
    position: relative;
    padding: 1.2rem;
    background: white;
    border-radius: 16px;
    margin-bottom: 1.2rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    border: 1px solid rgba(0,0,0,0.03);
}

.detail-timeline-item::before {
    content: '';
    position: absolute;
    left: -2rem;
    top: 1.5rem;
    width: 1rem;
    height: 1rem;
    border-radius: 50%;
    background: white;
    border: 2px solid;
}

.detail-timeline-item.primary::before {
    border-color: #0d6efd;
}

.detail-timeline-item.success::before {
    border-color: #198754;
}

.detail-timeline-item.info::before {
    border-color: #0dcaf0;
}

.detail-timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.8rem;
}

.detail-timeline-title {
    font-weight: 700;
    font-size: 0.95rem;
    color: #1e293b;
}

.detail-timeline-date {
    font-size: 0.8rem;
    color: #6c757d;
}

.detail-timeline-content {
    color: #334155;
    line-height: 1.6;
    font-size: 0.9rem;
    white-space: pre-line;
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

/* Responsive */
@media (max-width: 768px) {
    .detail-grid,
    .detail-grid-3 {
        grid-template-columns: 1fr;
    }
    
    .detail-attachment-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    }
    
    .detail-card {
        padding: 1.2rem;
    }
    
    #detailModal .modal-body {
        padding: 1.2rem !important;
    }
    
    #detailModal .modal-header {
        padding: 1.2rem;
    }
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
}

.bg-gradient-info {
    background: linear-gradient(135deg, #17a2b8 0%, #0f6674 100%);
}

/* ============================================================================
   ATTACHMENT STYLES - UNTUK MODAL LAMPIRAN
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

/* ============================================================================
   CSS UNTUK MODAL BERTINGKAT - MENGACU PADA FOLLOWUP.PHP
   ============================================================================ */
.modal#imagePreviewModal {
    z-index: 1060 !important;
}
.modal-backdrop + .modal-backdrop {
    z-index: 1059 !important;
}
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<!-- Chart.js Data Labels Plugin -->
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0/dist/chartjs-plugin-datalabels.min.js"></script>

<script>
// Register plugin
Chart.register(ChartDataLabels);

let attachmentsModal = null;
let imagePreviewModal = null;

// ============================================================================
// INITIALIZATION - MENGACU PADA FOLLOWUP.PHP
// ============================================================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Dashboard initialized for ' + '<?php echo $userType; ?>');
    
    // Initialize modals (MENGACU PADA FOLLOWUP.PHP)
    attachmentsModal = new bootstrap.Modal(document.getElementById('attachmentsModal'));
    imagePreviewModal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
    
    // Handle modal stacking (MENGACU PADA FOLLOWUP.PHP)
    const attachmentsModalEl = document.getElementById('attachmentsModal');
    const imagePreviewModalEl = document.getElementById('imagePreviewModal');
    
    if (attachmentsModalEl) {
        attachmentsModalEl.addEventListener('show.bs.modal', function() {
            console.log('Attachments modal opened');
        });
    }
    
    if (imagePreviewModalEl) {
        imagePreviewModalEl.addEventListener('show.bs.modal', function() {
            // Saat modal preview dibuka, redupkan backdrop modal attachments
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 0) {
                backdrops[backdrops.length - 1].style.opacity = '0.3';
            }
        });
        
        imagePreviewModalEl.addEventListener('hidden.bs.modal', function() {
            // Saat modal preview ditutup, kembalikan backdrop
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 0) {
                backdrops[backdrops.length - 1].style.opacity = '';
            }
        });
    }
    
    // Monitor semua tombol dengan onclick showWakepsekReview
    const wakepsekReviewButtons = document.querySelectorAll('button[onclick*="showWakepsekReview"]');
    console.log('Found Wakepsek review buttons:', wakepsekReviewButtons.length);
    wakepsekReviewButtons.forEach((button, index) => {
        const originalOnclick = button.getAttribute('onclick');
        console.log(`Button ${index + 1} onclick:`, originalOnclick);
    });
    
    // Initialize charts
    initCharts();
});

// ============================================================================
// EXPORT FUNCTIONS
// ============================================================================
function exportReport(format) {
    const url = new URL(window.location.href);
    url.searchParams.set('export', format);
    window.open(url.toString(), '_blank');
}

// ============================================================================
// CHART INITIALIZATION
// ============================================================================
function initCharts() {
    // Initialize Guru Performance Chart
    const guruCtx = document.getElementById('guruPerformanceChart');
    if (guruCtx) {
        try {
            const guruNames = <?php echo !empty($guruChartData) ? json_encode(array_column($guruChartData, 'nama_lengkap')) : '[]'; ?>;
            const totalMessages = <?php echo !empty($guruChartData) ? json_encode(array_column($guruChartData, 'total_messages')) : '[]'; ?>;
            const pendingMessages = <?php echo !empty($guruChartData) ? json_encode(array_column($guruChartData, 'pending_messages')) : '[]'; ?>;
            const respondedMessages = <?php echo !empty($guruChartData) ? json_encode(array_column($guruChartData, 'responded_messages')) : '[]'; ?>;
            const expiredMessages = <?php echo !empty($guruChartData) ? json_encode(array_column($guruChartData, 'expired_messages')) : '[]'; ?>;
            
            new Chart(guruCtx, {
                type: 'bar',
                data: {
                    labels: guruNames,
                    datasets: [
                        {
                            label: 'Total Pesan',
                            data: totalMessages,
                            backgroundColor: 'rgba(23, 162, 184, 0.7)',
                            borderColor: '#17a2b8',
                            borderWidth: 2,
                            borderRadius: 6,
                            barPercentage: 0.8,
                            categoryPercentage: 0.9
                        },
                        {
                            label: 'Pending',
                            data: pendingMessages,
                            backgroundColor: 'rgba(255, 193, 7, 0.7)',
                            borderColor: '#ffc107',
                            borderWidth: 2,
                            borderRadius: 6,
                            barPercentage: 0.8,
                            categoryPercentage: 0.9
                        },
                        {
                            label: 'Direspon',
                            data: respondedMessages,
                            backgroundColor: 'rgba(40, 167, 69, 0.7)',
                            borderColor: '#28a745',
                            borderWidth: 2,
                            borderRadius: 6,
                            barPercentage: 0.8,
                            categoryPercentage: 0.9
                        },
                        {
                            label: 'Expired',
                            data: expiredMessages,
                            backgroundColor: 'rgba(220, 53, 69, 0.7)',
                            borderColor: '#dc3545',
                            borderWidth: 2,
                            borderRadius: 6,
                            barPercentage: 0.8,
                            categoryPercentage: 0.9
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                boxWidth: 10,
                                font: { size: 11, weight: '500' }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleFont: { size: 13, weight: 'bold' },
                            bodyFont: { size: 12 }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false, drawBorder: true },
                            ticks: { maxRotation: 45, minRotation: 45, font: { size: 11, weight: '500' } }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { borderDash: [3, 3], color: '#e0e0e0' },
                            ticks: { precision: 0, font: { size: 11 }, stepSize: 1 },
                            title: {
                                display: true,
                                text: 'Jumlah Pesan',
                                font: { size: 12, weight: 'bold' }
                            }
                        }
                    },
                    animation: { duration: 2000, easing: 'easeInOutQuart' },
                    layout: { padding: { top: 20, bottom: 20 } }
                }
            });
        } catch (error) {
            console.error('Error creating guru performance chart:', error);
        }
    }
    
    // Initialize Message Type Chart
    const typeCtx = document.getElementById('messageTypeChart');
    if (typeCtx) {
        try {
            const typeLabels = <?php echo !empty($messageTypeChartData) ? json_encode(array_column($messageTypeChartData, 'jenis_pesan')) : '[]'; ?>;
            const typeTotal = <?php echo !empty($messageTypeChartData) ? json_encode(array_column($messageTypeChartData, 'total_messages')) : '[]'; ?>;
            const typeResponded = <?php echo !empty($messageTypeChartData) ? json_encode(array_column($messageTypeChartData, 'responded_messages')) : '[]'; ?>;
            
            new Chart(typeCtx, {
                type: 'bar',
                data: {
                    labels: typeLabels.map(label => label.length > 15 ? label.substring(0, 15) + '...' : label),
                    datasets: [
                        {
                            label: 'Total',
                            data: typeTotal,
                            backgroundColor: 'rgba(13, 110, 253, 0.8)',
                            borderColor: '#0d6efd',
                            borderWidth: 1
                        },
                        {
                            label: 'Direspon',
                            data: typeResponded,
                            backgroundColor: 'rgba(40, 167, 69, 0.8)',
                            borderColor: '#28a745',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { padding: 20, usePointStyle: true, boxWidth: 10 }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45 } },
                        y: { beginAtZero: true, grid: { borderDash: [2, 2] }, ticks: { precision: 0 } }
                    }
                }
            });
        } catch (error) {
            console.error('Error creating message type chart:', error);
        }
    }
}

// ============================================================================
// HELPER FUNCTION UNTUK FORMAT TANGGAL
// ============================================================================
function formatDateWithDay(dateString) {
    if (!dateString) return { date: '-', time: '-', day: '-', full: '-' };
    
    const date = new Date(dateString);
    const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    
    const dayName = days[date.getDay()];
    const day = date.getDate();
    const month = months[date.getMonth()];
    const year = date.getFullYear();
    const hours = date.getHours().toString().padStart(2, '0');
    const minutes = date.getMinutes().toString().padStart(2, '0');
    const seconds = date.getSeconds().toString().padStart(2, '0');
    
    return {
        date: `${day} ${month} ${year}`,
        time: `${hours}:${minutes}:${seconds}`,
        day: dayName,
        full: `${dayName}, ${day} ${month} ${year} ${hours}:${minutes}:${seconds} WIB`
    };
}

// ============================================================================
// REVIEW MODAL FUNCTIONS
// ============================================================================
function showReviewModal(messageId, guruName, msgData = null) {
    document.getElementById('reviewMessageId').value = messageId;
    document.getElementById('guruName').textContent = guruName;
    
    // Reset attachments grid
    document.getElementById('reviewAttachmentsGrid').innerHTML = '';
    
    // Tampilkan pesan asli user dengan tanggal lengkap
    if (msgData) {
        const originalMsgDiv = document.getElementById('originalMessageContent');
        if (originalMsgDiv) {
            originalMsgDiv.innerHTML = (msgData.isi_pesan || '<em class="text-muted">Tidak ada pesan</em>').replace(/\n/g, '<br>');
        }
        
        // Format tanggal pesan asli
        if (msgData.created_at) {
            const formatted = formatDateWithDay(msgData.created_at);
            document.getElementById('originalMessageDateText').textContent = formatted.date;
            document.getElementById('originalMessageTimeText').textContent = formatted.time;
            document.getElementById('originalMessageDayText').textContent = formatted.day;
        }
        
        // Tampilkan informasi pengirim
        document.getElementById('originalMessageSenderName').textContent = msgData.pengirim_nama_display || '-';
        document.getElementById('originalMessageSenderType').textContent = msgData.pengirim_tipe ? msgData.pengirim_tipe.replace('_', ' ') : '-';
        
        // ========================================================
        // TAMPILKAN THUMBNAIL GAMBAR LAMPIRAN
        // ========================================================
        const attachmentsGrid = document.getElementById('reviewAttachmentsGrid');
        const placeholder = '<?php echo $placeholder_image; ?>';
        
        // Fetch attachments untuk pesan ini
        fetch('ajax/get_message_attachments.php?message_id=' + messageId)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.attachments && data.attachments.length > 0) {
                    let html = '';
                    
                    // Tampilkan maksimal 4 thumbnail
                    const maxThumbnails = 4;
                    const attachmentsToShow = data.attachments.slice(0, maxThumbnails);
                    const remainingCount = data.attachments.length - maxThumbnails;
                    
                    attachmentsToShow.forEach(att => {
                        const imageUrl = '<?php echo BASE_URL; ?>/' + att.filepath;
                        const displayName = att.filename || 'gambar';
                        
                        html += `
                            <div class="col-auto">
                                <div class="attachment-thumbnail-item" 
                                     onclick="previewImage('${imageUrl}', '${displayName.replace(/'/g, "\\'")}')"
                                     title="${displayName}">
                                    <img src="${imageUrl}?t=${new Date().getTime()}" 
                                         alt="${displayName}"
                                         onerror="this.onerror=null; this.src='${placeholder}';">
                                    <div class="thumbnail-overlay">
                                        <i class="fas fa-search-plus"></i>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    if (remainingCount > 0) {
                        html += `
                            <div class="col-auto">
                                <div class="attachment-thumbnail-item" 
                                     onclick="viewAllAttachments(${messageId})"
                                     title="Lihat semua ${data.attachments.length} lampiran">
                                    <img src="${placeholder}" style="opacity: 0.3;">
                                    <div class="more-files-badge">
                                        +${remainingCount}
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    
                    attachmentsGrid.innerHTML = html;
                    
                    // Tampilkan container jika ada attachment
                    if (data.attachments.length > 0) {
                        document.getElementById('reviewAttachmentsContainer').style.display = 'block';
                    } else {
                        document.getElementById('reviewAttachmentsContainer').style.display = 'none';
                    }
                } else {
                    // Sembunyikan container jika tidak ada attachment
                    document.getElementById('reviewAttachmentsContainer').style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error loading attachments:', error);
                document.getElementById('reviewAttachmentsContainer').style.display = 'none';
            });
        
        // Tampilkan respon guru dengan tanggal
        const guruResponseDiv = document.getElementById('guruResponseContent');
        const guruResponseMeta = document.getElementById('guruResponseMeta');
        const guruResponseDateText = document.getElementById('guruResponseDateText');
        const guruResponseTimeText = document.getElementById('guruResponseTimeText');
        
        if (guruResponseDiv) {
            if (msgData.guru_response) {
                guruResponseDiv.innerHTML = (msgData.guru_response || '<em class="text-muted">Tidak ada respon</em>').replace(/\n/g, '<br>');
                
                // Format tanggal respon guru
                if (msgData.guru_response_date) {
                    const formatted = formatDateWithDay(msgData.guru_response_date);
                    guruResponseDateText.textContent = formatted.date;
                    guruResponseTimeText.textContent = formatted.time;
                } else {
                    guruResponseDateText.textContent = '-';
                    guruResponseTimeText.textContent = '-';
                }
                
                if (guruResponseMeta) {
                    guruResponseMeta.innerHTML = `Status: <span class="badge bg-${msgData.guru_response_status == 'Disetujui' ? 'success' : 'danger'}">${msgData.guru_response_status || '-'}</span>`;
                }
            } else {
                guruResponseDiv.innerHTML = '<em class="text-muted">Belum ada respon dari guru</em>';
                guruResponseDateText.textContent = '-';
                guruResponseTimeText.textContent = '-';
                if (guruResponseMeta) {
                    guruResponseMeta.innerHTML = '';
                }
            }
        }
    }
    
    // Tampilkan review Wakepsek jika ada (untuk Kepsek)
    <?php if ($userType === 'Kepala_Sekolah'): ?>
    if (msgData && msgData.wakepsek_review_id) {
        console.log('Menampilkan review Wakepsek dengan data:', {
            id: msgData.wakepsek_review_id,
            catatan: msgData.wakepsek_review_catatan,
            reviewer: msgData.wakepsek_reviewer_nama,
            tanggal: msgData.wakepsek_review_date
        });
        
        const container = document.getElementById('wakepsekReviewContainer');
        const contentDiv = document.getElementById('wakepsekReviewContent');
        const reviewerNameSpan = document.getElementById('wakepsekReviewerName');
        const wakepsekDateText = document.getElementById('wakepsekReviewDateText');
        const wakepsekTimeText = document.getElementById('wakepsekReviewTimeText');
        const wakepsekFullDate = document.getElementById('wakepsekReviewFullDate');
        
        if (container && contentDiv) {
            container.style.display = 'block';
            
            // Format tanggal review wakepsek
            if (msgData.wakepsek_review_date) {
                const formatted = formatDateWithDay(msgData.wakepsek_review_date);
                wakepsekDateText.textContent = formatted.date;
                wakepsekTimeText.textContent = formatted.time;
                if (wakepsekFullDate) {
                    wakepsekFullDate.textContent = formatted.full;
                }
            } else {
                wakepsekDateText.textContent = '-';
                wakepsekTimeText.textContent = '-';
                if (wakepsekFullDate) {
                    wakepsekFullDate.textContent = '-';
                }
            }
            
            // Tampilkan isi review Wakepsek (dari tabel wakepsek_reviews.catatan)
            contentDiv.innerHTML = (msgData.wakepsek_review_catatan || '<em class="text-muted">Tidak ada catatan review dari Wakil Kepala Sekolah</em>').replace(/\n/g, '<br>');
            
            // Tampilkan nama reviewer
            if (reviewerNameSpan) {
                reviewerNameSpan.textContent = msgData.wakepsek_reviewer_nama || 'Wakil Kepala Sekolah';
            }
        }
        
        // Debug info (untuk development)
        const debugDiv = document.getElementById('wakepsekReviewDebug');
        if (debugDiv) {
            debugDiv.innerHTML = `
                <small class="text-muted">
                    <strong>Debug:</strong> 
                    ID: ${msgData.wakepsek_review_id} | 
                    Reviewer: ${msgData.wakepsek_reviewer_nama} | 
                    Tanggal: ${msgData.wakepsek_review_date}
                </small>
            `;
            debugDiv.style.display = 'block';
        }
    } else {
        const container = document.getElementById('wakepsekReviewContainer');
        if (container) {
            container.style.display = 'none';
        }
    }
    <?php endif; ?>
    
    const modal = new bootstrap.Modal(document.getElementById('reviewModal'));
    modal.show();
}

// Fungsi untuk melihat semua attachment
function viewAllAttachments(messageId) {
    // Tutup modal review
    const reviewModal = bootstrap.Modal.getInstance(document.getElementById('reviewModal'));
    if (reviewModal) {
        reviewModal.hide();
    }
    
    // Buka modal attachments
    setTimeout(() => {
        viewAttachments(messageId);
    }, 300);
}

function showReviewDetail(msg) {
    const content = document.getElementById('reviewDetailContent');
    
    let reviewerIcon = msg.reviewer_type === 'Kepala_Sekolah' ? 'fa-crown text-warning' : 'fa-user-tie text-info';
    let reviewerName = msg.reviewer_type === 'Kepala_Sekolah' ? 'Kepala Sekolah' : 'Wakil Kepala Sekolah';
    
    // Format tanggal review
    const reviewDateFormatted = msg.review_date ? formatDateWithDay(msg.review_date).full : '-';
    
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
                        <i class="far fa-calendar-alt me-1"></i>
                        ${reviewDateFormatted}
                    </span>
                </div>
            </div>
            
            <div class="bg-light p-3 rounded">
                <label class="fw-bold text-primary mb-2">
                    <i class="fas fa-quote-left me-1"></i>
                    Catatan Review:
                </label>
                <div class="p-2 bg-white rounded" style="white-space: pre-line; min-height: 100px;">
                    ${(msg.review_catatan || '<em class="text-muted">Tidak ada catatan</em>').replace(/\n/g, '<br>')}
                </div>
            </div>
            
            <div class="mt-3">
                <label class="fw-bold mb-2">Detail Pesan:</label>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="bg-light p-2 rounded">
                            <small class="text-muted d-block">Status Respon Guru</small>
                            <span class="badge bg-${msg.guru_response_status == 'Disetujui' ? 'success' : 'danger'}">
                                ${msg.guru_response_status || '-'}
                            </span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-light p-2 rounded">
                            <small class="text-muted d-block">Waktu Respon Guru</small>
                            <small>${msg.guru_response_date ? formatDateWithDay(msg.guru_response_date).full : '-'}</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('reviewDetailModal'));
    modal.show();
}

// ============================================================================
// FUNGSI SHOW WAKEPSEK REVIEW - TAMPILAN PROFESIONAL HANYA REVIEW SAJA
// ============================================================================
function showWakepsekReview(msg) {
    console.log('========== SHOW WAKEPSEK REVIEW ==========');
    console.log('Data lengkap yang diterima:', msg);
    
    // Identifikasi data review Wakepsek
    let reviewId = msg.wakepsek_review_id || msg.review_id || msg.wakepsek_id || null;
    let reviewCatatan = msg.wakepsek_review_catatan || msg.wakepsek_catatan || msg.review_catatan || msg.catatan_wakepsek || 'Tidak ada catatan review';
    let reviewerNama = msg.wakepsek_reviewer_nama || msg.wakepsek_nama || msg.reviewer_nama || 'Wakil Kepala Sekolah';
    let reviewDate = msg.wakepsek_review_date || msg.wakepsek_created_at || msg.review_date || msg.created_at || null;
    
    console.log('Data review yang akan ditampilkan:', {
        id: reviewId,
        catatan: reviewCatatan,
        reviewer: reviewerNama,
        tanggal: reviewDate
    });
    
    const modalElement = document.getElementById('wakepsekReviewModal');
    const contentElement = document.getElementById('wakepsekReviewModalContent');
    const reviewerNameDisplay = document.getElementById('wakepsekReviewerNameDisplay');
    const reviewDateDisplay = document.getElementById('wakepsekReviewDateDisplay');
    
    if (!modalElement || !contentElement) {
        console.error('Modal elements not found');
        alert('Error: Modal elements not found');
        return;
    }
    
    // Format tanggal
    let formattedDate = '-';
    if (reviewDate) {
        try {
            const formatted = formatDateWithDay(reviewDate);
            formattedDate = formatted.full;
        } catch (e) {
            console.error('Error formatting date:', e);
            formattedDate = reviewDate;
        }
    }
    
    // Update header dan footer
    if (reviewerNameDisplay) {
        reviewerNameDisplay.textContent = reviewerNama;
    }
    
    if (reviewDateDisplay) {
        const dateSpan = reviewDateDisplay.querySelector('span');
        if (dateSpan) {
            dateSpan.textContent = formattedDate;
        }
    }
    
    // Tampilkan konten review yang elegan
    contentElement.innerHTML = `
        <div class="wakepsek-review-card">
            <div class="position-relative">
                <i class="fas fa-quote-right quote-icon"></i>
                
                <!-- Catatan Review -->
                <div class="wakepsek-review-content">
                    ${reviewCatatan.replace(/\n/g, '<br>')}
                </div>
                
                <!-- Informasi Tambahan -->
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <div class="review-meta-item">
                        <i class="fas fa-hashtag"></i>
                        <span>ID: ${reviewId || '-'}</span>
                    </div>
                    <div class="review-meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>${formattedDate}</span>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Buka modal
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
}

// ============================================================================
// FUNGSI SHOW MESSAGE DETAIL - DENGAN TAMPILAN PROFESIONAL (TANPA TABEL)
// ============================================================================
function showMessageDetail(msg, userType, attachments = []) {
    console.log('========== SHOW MESSAGE DETAIL ==========');
    console.log('Message detail data:', msg);
    
    // Update reference di header
    const refElement = document.getElementById('detailMessageReference');
    const idElement = document.getElementById('detailMessageId');
    if (refElement) {
        refElement.textContent = msg.reference_number || `Pesan #${msg.id}`;
    }
    if (idElement) {
        idElement.textContent = `ID: ${msg.id || '-'}`;
    }
    
    // Format tanggal
    const formatSafe = (dateString) => {
        if (!dateString) return '-';
        try {
            return formatDateWithDay(dateString).full;
        } catch (e) {
            console.error('Error formatting date:', e);
            return dateString;
        }
    };
    
    const createdDate = formatSafe(msg.created_at);
    const responseDate = formatSafe(msg.guru_response_date);
    const wakepsekDate = formatSafe(msg.wakepsek_review_date);
    const kepsekDate = formatSafe(msg.kepsek_review_date);
    
    // Status classes
    const statusClass = msg.status === 'Disetujui' ? 'success' : 'warning';
    const responseStatusClass = msg.guru_response_status === 'Disetujui' ? 'success' : 
                               (msg.guru_response_status === 'Pending' ? 'warning' : 'danger');
    
    // Mulai membangun HTML
    let html = '';
    
    // ========================================================================
    // CARD INFORMASI DASAR
    // ========================================================================
    html += `
        <div class="detail-card">
            <div class="detail-card-header">
                <i class="fas fa-info-circle text-primary"></i>
                <h5 class="text-primary">Informasi Dasar</h5>
            </div>
            
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Pengirim</span>
                    <span class="detail-value">
                        ${msg.pengirim_nama_display || '-'}
                        <small class="d-block">${msg.pengirim_tipe ? msg.pengirim_tipe.replace('_', ' ') : '-'}</small>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Jenis Pesan</span>
                    <span class="detail-value">
                        ${msg.message_type || '-'}
                        <small class="d-block">${msg.expected_responder_type ? 'Responder: ' + msg.expected_responder_type.replace('Guru_', '') : '-'}</small>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Waktu Kirim</span>
                    <span class="detail-value">
                        <i class="far fa-calendar-alt text-primary me-1"></i>
                        ${createdDate}
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Status Pesan</span>
                    <span class="detail-value">
                        <span class="detail-badge ${statusClass}">
                            <span class="status-dot ${statusClass} me-2"></span>
                            ${msg.status || 'Pending'}
                        </span>
                    </span>
                </div>
            </div>
            
            <div class="mt-4">
                <span class="detail-label mb-2 d-block">Isi Pesan</span>
                <div class="detail-content-box primary">
                    ${(msg.isi_pesan || '<em class="text-muted">Tidak ada pesan</em>').replace(/\n/g, '<br>')}
                </div>
            </div>
    `;
    
    // ========================================================================
    // LAMPIRAN GAMBAR (Jika Ada)
    // ========================================================================
    if (attachments && attachments.length > 0) {
        html += `
            <div class="mt-4">
                <span class="detail-label mb-3 d-block">
                    <i class="fas fa-images text-primary me-2"></i>
                    Lampiran Gambar (${attachments.length})
                </span>
                <div class="detail-attachment-grid">
        `;
        
        attachments.forEach(att => {
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
    
    html += `</div>`; // Tutup card informasi dasar
    
    // ========================================================================
    // CARD RESPON GURU (Jika Ada)
    // ========================================================================
    if (msg.guru_responder_nama) {
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
                            ${msg.guru_responder_nama}
                            <small class="d-block">${msg.guru_responder_type ? msg.guru_responder_type.replace('Guru_', '') : '-'}</small>
                        </span>
                    </div>
                    
                    <div class="detail-item">
                        <span class="detail-label">Status Respon</span>
                        <span class="detail-value">
                            <span class="detail-badge ${responseStatusClass}">
                                <span class="status-dot ${responseStatusClass} me-2"></span>
                                ${msg.guru_response_status || '-'}
                            </span>
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
                        ${(msg.guru_response || '<em class="text-muted">Tidak ada catatan respon</em>').replace(/\n/g, '<br>')}
                    </div>
                </div>
            </div>
        `;
    }
    
    // ========================================================================
    // REVIEW (Jika Ada) - Khusus untuk Kepala Sekolah
    // ========================================================================
    if (userType === 'Kepala_Sekolah') {
        if (msg.wakepsek_review_id || msg.kepsek_review_id) {
            html += `
                <div class="detail-card">
                    <div class="detail-card-header">
                        <i class="fas fa-clock text-info"></i>
                        <h5 class="text-info">Review</h5>
                    </div>
                    
                    <div class="detail-timeline">
            `;
            
            if (msg.wakepsek_review_id) {
                html += `
                    <div class="detail-timeline-item primary">
                        <div class="detail-timeline-header">
                            <span class="detail-timeline-title">
                                <i class="fas fa-user-tie me-2"></i>
                                Review Wakil Kepala Sekolah
                            </span>
                            <span class="detail-timeline-date">${wakepsekDate}</span>
                        </div>
                        <div class="detail-timeline-content">
                            <strong>Reviewer:</strong> ${msg.wakepsek_reviewer_nama || '-'}<br>
                            <div class="mt-2 p-3 bg-light rounded-3">
                                ${(msg.wakepsek_review_catatan || '<em class="text-muted">Tidak ada catatan</em>').replace(/\n/g, '<br>')}
                            </div>
                        </div>
                    </div>
                `;
            }
            
            if (msg.kepsek_review_id) {
                html += `
                    <div class="detail-timeline-item success">
                        <div class="detail-timeline-header">
                            <span class="detail-timeline-title">
                                <i class="fas fa-crown me-2"></i>
                                Review Kepala Sekolah
                            </span>
                            <span class="detail-timeline-date">${kepsekDate}</span>
                        </div>
                        <div class="detail-timeline-content">
                            <div class="p-3 bg-light rounded-3">
                                ${(msg.kepsek_review_catatan || '<em class="text-muted">Tidak ada catatan</em>').replace(/\n/g, '<br>')}
                            </div>
                        </div>
                    </div>
                `;
            }
            
            html += `</div></div>`;
        } else {
            // Empty State jika tidak ada review
            html += `
                <div class="detail-empty-state">
                    <i class="fas fa-clipboard-check"></i>
                    <h6>Belum Ada Review</h6>
                    <p class="text-muted">Pesan ini belum mendapatkan review dari pimpinan.</p>
                </div>
            `;
        }
    }
    
    document.getElementById('detailModalContent').innerHTML = html;
    
    const modal = new bootstrap.Modal(document.getElementById('detailModal'));
    modal.show();
}

// ============================================================================
// ATTACHMENT FUNCTIONS - MENGACU PADA FOLLOWUP.PHP
// ============================================================================
function viewAttachments(messageId) {
    attachmentsModal.show();
    
    document.getElementById('attachmentsContent').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary"></div>
            <p class="mt-2">Memuat lampiran...</p>
        </div>
    `;
    
    // Fetch attachments via AJAX
    fetch('ajax/get_message_attachments.php?message_id=' + messageId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayAttachments(data.attachments, data.is_external);
            } else {
                document.getElementById('attachmentsContent').innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        ${data.error || 'Tidak dapat memuat lampiran'}
                    </div>
                `;
            }
        })
        .catch(error => {
            document.getElementById('attachmentsContent').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Error: ${error.message}
                </div>
            `;
        });
}

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
    
    let html = '<div class="row g-3">';
    
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

// ============================================================================
// IMAGE PREVIEW FUNCTIONS - MENGACU PADA FOLLOWUP.PHP
// ============================================================================
function previewImageFromAttachments(imageUrl, imageName) {
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
            <p class="mt-2 text-muted">Memuat gambar...</p>
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
                <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
                <h6 class="text-muted">Gambar tidak dapat dimuat</h6>
                <p class="text-muted small mb-3">File mungkin telah dihapus, dipindahkan, atau rusak.</p>
                <img src="${placeholder}" class="img-fluid opacity-50" style="max-height: 200px;">
                <div class="mt-3">
                    <a href="${imageUrl}" class="btn btn-sm btn-outline-primary" target="_blank">
                        <i class="fas fa-external-link-alt me-1"></i> Buka di Tab Baru
                    </a>
                </div>
            </div>
        `;
        downloadBtn.style.display = 'none';
    };
    
    img.src = imageUrl + '?t=' + new Date().getTime(); // Tambah timestamp untuk mencegah cache
    img.alt = imageName;
    img.className = 'img-fluid';
    img.style.maxHeight = '70vh';
    img.style.maxWidth = '100%';
    img.style.objectFit = 'contain';
    img.id = 'previewImage';
}

/**
 * Preview image langsung (dari detail pesan) - MENGACU PADA FOLLOWUP.PHP
 */
function previewImage(imageUrl, imageName) {
    // Panggil fungsi yang sama
    previewImageFromAttachments(imageUrl, imageName);
}
</script>

<?php require_once '../../includes/footer.php'; ?>