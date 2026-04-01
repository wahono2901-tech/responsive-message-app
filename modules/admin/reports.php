<?php
/**
 * Reports Page with Filtering and Export
 * File: modules/admin/reports.php
 * 
 * VERSI: 6.2 - FIXED MESSAGE REPORT ERROR
 * ✅ Perbaikan query SQL untuk laporan pesan
 * ✅ Penanganan error yang lebih baik
 * ✅ Optimasi performance
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================================================
// CEK AUTHENTICATION & ADMIN PRIVILEGE
// ============================================================================
try {
    Auth::checkAuth();
} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check admin privilege
if (!in_array($_SESSION['user_type'], ['Admin', 'Super_Admin']) && $_SESSION['privilege_level'] !== 'Full_Access') {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_log("=== MEMULAI REPORT MESSAGES ===");

// ============================================================================
// FILTER PARAMETERS
// ============================================================================
$reportType = $_GET['report_type'] ?? 'dashboard';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$dateRange = $_GET['date_range'] ?? 'custom';
$userTypeFilter = $_GET['user_type'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$priorityFilter = $_GET['priority'] ?? 'all';
$messageTypeFilter = $_GET['message_type'] ?? 'all';
$groupBy = $_GET['group_by'] ?? 'day';
$exportFormat = $_GET['export'] ?? '';

error_log("Report Type: $reportType");
error_log("Start Date: $startDate, End Date: $endDate");

// Validate dates
if (strtotime($startDate) > strtotime($endDate)) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

// Handle date ranges
switch ($dateRange) {
    case 'today':
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        break;
    case 'yesterday':
        $startDate = date('Y-m-d', strtotime('-1 day'));
        $endDate = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'this_week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d');
        break;
    case 'last_week':
        $startDate = date('Y-m-d', strtotime('monday last week'));
        $endDate = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'this_month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-d');
        break;
    case 'last_month':
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'last_30_days':
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');
        break;
}

// Database connection
$db = Database::getInstance()->getConnection();

// Test database connection
try {
    $test = $db->query("SELECT 1")->fetch();
    error_log("Database connection OK");
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error");
}

// ============================================================================
// GET REPORT DATA
// ============================================================================
$reportData = [];
$reportTitle = '';

try {
    switch ($reportType) {
        case 'messages':
            $reportTitle = 'Laporan Pesan';
            $reportData = getMessageReport($db, $startDate, $endDate, $userTypeFilter, $statusFilter, $priorityFilter, $messageTypeFilter, $groupBy);
            error_log("Message report data count: " . ($reportData['total_records'] ?? 0));
            break;
            
        case 'responses':
            $reportTitle = 'Laporan Respons';
            $reportData = getResponseReport($db, $startDate, $endDate, $userTypeFilter, $statusFilter);
            break;
            
        case 'dashboard':
        default:
            $reportTitle = 'Dashboard Analitik';
            $reportData = getDashboardData($db);
            break;
    }
} catch (Exception $e) {
    error_log("Error getting report data: " . $e->getMessage());
    $reportData = ['error' => $e->getMessage()];
}

// Handle export
if ($exportFormat && !empty($reportData) && !isset($reportData['error'])) {
    exportReport($reportData, $exportFormat, $reportTitle, $startDate, $endDate, $reportType);
    exit;
}

// ============================================================================
// REPORT FUNCTIONS
// ============================================================================

/**
 * Get message statistics report - FIXED VERSION
 */
function getMessageReport($db, $startDate, $endDate, $userTypeFilter, $statusFilter, $priorityFilter, $messageTypeFilter, $groupBy) {
    error_log("=== MEMULAI getMessageReport ===");
    
    $params = [
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ];
    
    $whereConditions = ["DATE(m.created_at) BETWEEN :start_date AND :end_date"];
    
    if ($userTypeFilter !== 'all') {
        $whereConditions[] = "u.user_type = :user_type";
        $params[':user_type'] = $userTypeFilter;
    }
    
    if ($statusFilter !== 'all') {
        $whereConditions[] = "m.status = :status";
        $params[':status'] = $statusFilter;
    }
    
    if ($priorityFilter !== 'all') {
        $whereConditions[] = "m.priority = :priority";
        $params[':priority'] = $priorityFilter;
    }
    
    if ($messageTypeFilter !== 'all' && !empty($messageTypeFilter)) {
        $whereConditions[] = "m.jenis_pesan_id = :message_type";
        $params[':message_type'] = $messageTypeFilter;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    error_log("Where clause: " . $whereClause);
    
    // ====================================================
    // 1. SUMMARY STATISTICS
    // ====================================================
    $summarySql = "
        SELECT 
            COUNT(*) as total_messages,
            SUM(CASE WHEN m.status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN m.status = 'Dibaca' THEN 1 ELSE 0 END) as dibaca,
            SUM(CASE WHEN m.status = 'Diproses' THEN 1 ELSE 0 END) as diproses,
            SUM(CASE WHEN m.status = 'Disetujui' THEN 1 ELSE 0 END) as disetujui,
            SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END) as ditolak,
            SUM(CASE WHEN m.status = 'Selesai' THEN 1 ELSE 0 END) as selesai,
            SUM(CASE WHEN m.status = 'Expired' THEN 1 ELSE 0 END) as expired,
            AVG(CASE 
                WHEN m.tanggal_respon IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) 
                ELSE NULL 
            END) as avg_response_hours,
            SUM(CASE 
                WHEN m.tanggal_respon IS NOT NULL 
                AND TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) > 72 
                THEN 1 ELSE 0 
            END) as late_responses
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        WHERE $whereClause
    ";
    
    error_log("Summary SQL: " . $summarySql);
    
    try {
        $stmt = $db->prepare($summarySql);
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("Summary result: " . print_r($summary, true));
    } catch (Exception $e) {
        error_log("Summary query error: " . $e->getMessage());
        $summary = [];
    }
    
    // ====================================================
    // 2. TIME SERIES DATA
    // ====================================================
    $groupFormat = match($groupBy) {
        'hour' => '%Y-%m-%d %H:00:00',
        'day' => '%Y-%m-%d',
        'week' => '%Y-%u',
        'month' => '%Y-%m',
        default => '%Y-%m-%d'
    };
    
    $timeSql = "
        SELECT 
            DATE_FORMAT(m.created_at, '$groupFormat') as period,
            COUNT(*) as total,
            SUM(CASE WHEN m.status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN m.status IN ('Pending', 'Dibaca', 'Diproses') THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END) as rejected
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        WHERE $whereClause
        GROUP BY period
        ORDER BY period ASC
    ";
    
    error_log("Time series SQL: " . $timeSql);
    
    try {
        $stmt = $db->prepare($timeSql);
        $stmt->execute($params);
        $timeSeries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Time series count: " . count($timeSeries));
    } catch (Exception $e) {
        error_log("Time series query error: " . $e->getMessage());
        $timeSeries = [];
    }
    
    // ====================================================
    // 3. MESSAGE TYPE BREAKDOWN
    // ====================================================
    $typeSql = "
        SELECT 
            mt.id,
            mt.jenis_pesan,
            COUNT(m.id) as total,
            SUM(CASE WHEN m.status = 'Disetujui' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN m.status = 'Pending' THEN 1 ELSE 0 END) as pending
        FROM message_types mt
        LEFT JOIN messages m ON mt.id = m.jenis_pesan_id 
            AND DATE(m.created_at) BETWEEN :start_date AND :end_date
        GROUP BY mt.id, mt.jenis_pesan
        HAVING total > 0
        ORDER BY total DESC
    ";
    
    error_log("Type breakdown SQL: " . $typeSql);
    
    try {
        $stmt = $db->prepare($typeSql);
        $stmt->execute([':start_date' => $params[':start_date'], ':end_date' => $params[':end_date']]);
        $typeBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Type breakdown count: " . count($typeBreakdown));
    } catch (Exception $e) {
        error_log("Type breakdown query error: " . $e->getMessage());
        $typeBreakdown = [];
    }
    
    // ====================================================
    // 4. PRIORITY DISTRIBUTION
    // ====================================================
    $prioritySql = "
        SELECT 
            priority,
            COUNT(*) as total
        FROM messages m
        WHERE DATE(created_at) BETWEEN :start_date AND :end_date
        GROUP BY priority
        ORDER BY FIELD(priority, 'Urgent', 'High', 'Medium', 'Low')
    ";
    
    error_log("Priority SQL: " . $prioritySql);
    
    try {
        $stmt = $db->prepare($prioritySql);
        $stmt->execute([':start_date' => $params[':start_date'], ':end_date' => $params[':end_date']]);
        $priorityDist = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Priority count: " . count($priorityDist));
    } catch (Exception $e) {
        error_log("Priority query error: " . $e->getMessage());
        $priorityDist = [];
    }
    
    // ====================================================
    // 5. DETAILED MESSAGE LIST
    // ====================================================
    $detailsSql = "
        SELECT 
            m.id,
            m.reference_number,
            DATE_FORMAT(m.created_at, '%d/%m/%Y %H:%i') as tanggal_pesan,
            m.status,
            m.priority,
            mt.jenis_pesan,
            COALESCE(u.nama_lengkap, '-') as pengirim_nama,
            COALESCE(u.user_type, '-') as pengirim_tipe,
            LEFT(m.isi_pesan, 100) as isi_pesan_ringkas,
            CASE 
                WHEN m.tanggal_respon IS NOT NULL 
                THEN CONCAT(TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon), ' jam')
                ELSE '-'
            END as response_time
        FROM messages m
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users u ON m.pengirim_id = u.id
        WHERE $whereClause
        ORDER BY m.created_at DESC
        LIMIT 100
    ";
    
    error_log("Details SQL: " . $detailsSql);
    
    try {
        $stmt = $db->prepare($detailsSql);
        $stmt->execute($params);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Details count: " . count($details));
    } catch (Exception $e) {
        error_log("Details query error: " . $e->getMessage());
        $details = [];
    }
    
    error_log("=== SELESAI getMessageReport ===");
    
    return [
        'summary' => $summary,
        'time_series' => $timeSeries,
        'type_breakdown' => $typeBreakdown,
        'priority_distribution' => $priorityDist,
        'details' => $details,
        'total_records' => count($details)
    ];
}

/**
 * Get response time report
 */
function getResponseReport($db, $startDate, $endDate, $userTypeFilter, $statusFilter) {
    error_log("=== MEMULAI getResponseReport ===");
    
    $params = [
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ];
    
    $whereConditions = ["DATE(m.created_at) BETWEEN :start_date AND :end_date"];
    
    if ($userTypeFilter !== 'all') {
        $whereConditions[] = "u.user_type = :user_type";
        $params[':user_type'] = $userTypeFilter;
    }
    
    if ($statusFilter !== 'all') {
        $whereConditions[] = "m.status = :status";
        $params[':status'] = $statusFilter;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // ====================================================
    // SUMMARY STATISTICS
    // ====================================================
    $summarySql = "
        SELECT 
            COUNT(*) as total_messages,
            SUM(CASE WHEN m.tanggal_respon IS NOT NULL THEN 1 ELSE 0 END) as responded,
            AVG(CASE WHEN m.tanggal_respon IS NOT NULL THEN TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) ELSE NULL END) as avg_response_hours,
            SUM(CASE WHEN m.tanggal_respon IS NOT NULL AND TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) > 72 THEN 1 ELSE 0 END) as late_responses
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        WHERE $whereClause
    ";
    
    error_log("Response summary SQL: " . $summarySql);
    
    try {
        $stmt = $db->prepare($summarySql);
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Response summary error: " . $e->getMessage());
        $summary = [];
    }
    
    // ====================================================
    // STATUS STATISTICS
    // ====================================================
    $statusSql = "
        SELECT 
            status,
            COUNT(*) as total,
            SUM(CASE WHEN tanggal_respon IS NOT NULL THEN 1 ELSE 0 END) as responded
        FROM messages m
        WHERE DATE(created_at) BETWEEN :start_date AND :end_date
        GROUP BY status
    ";
    
    try {
        $stmt = $db->prepare($statusSql);
        $stmt->execute([':start_date' => $params[':start_date'], ':end_date' => $params[':end_date']]);
        $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Status stats error: " . $e->getMessage());
        $statusStats = [];
    }
    
    // ====================================================
    // DETAILS
    // ====================================================
    $detailsSql = "
        SELECT 
            m.id,
            m.reference_number,
            DATE_FORMAT(m.created_at, '%d/%m/%Y %H:%i') as tanggal_pesan,
            DATE_FORMAT(m.tanggal_respon, '%d/%m/%Y %H:%i') as tanggal_respon,
            mt.jenis_pesan,
            u.nama_lengkap as pengirim,
            TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) as response_hours,
            m.status
        FROM messages m
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users u ON m.pengirim_id = u.id
        WHERE $whereClause AND m.tanggal_respon IS NOT NULL
        ORDER BY m.tanggal_respon DESC
        LIMIT 100
    ";
    
    try {
        $stmt = $db->prepare($detailsSql);
        $stmt->execute($params);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Response details error: " . $e->getMessage());
        $details = [];
    }
    
    error_log("=== SELESAI getResponseReport ===");
    
    return [
        'summary' => $summary,
        'status_stats' => $statusStats,
        'details' => $details,
        'total_records' => count($details)
    ];
}

/**
 * Get dashboard data
 */
function getDashboardData($db) {
    error_log("=== MEMULAI getDashboardData ===");
    
    // Today's stats
    $today = date('Y-m-d');
    
    $todaySql = "
        SELECT 
            COUNT(*) as today_messages,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as today_pending,
            SUM(CASE WHEN status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as today_completed
        FROM messages 
        WHERE DATE(created_at) = :today
    ";
    
    try {
        $stmt = $db->prepare($todaySql);
        $stmt->execute([':today' => $today]);
        $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Today stats error: " . $e->getMessage());
        $todayStats = [];
    }
    
    // Weekly trend
    $weekSql = "
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total
        FROM messages 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ";
    
    try {
        $stmt = $db->prepare($weekSql);
        $stmt->execute();
        $weeklyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Weekly trend error: " . $e->getMessage());
        $weeklyTrend = [];
    }
    
    return [
        'today_stats' => $todayStats,
        'weekly_trend' => $weeklyTrend,
        'generated_at' => date('Y-m-d H:i:s')
    ];
}

// ============================================================================
// EXPORT FUNCTIONS (SEDERHANA)
// ============================================================================

/**
 * Export report to CSV
 */
function exportToCSV($data, $title, $startDate, $endDate, $reportType) {
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '_', $title)) . '_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for UTF-8
    
    // Header info
    fputcsv($output, [$title]);
    fputcsv($output, ["Periode: $startDate s/d $endDate"]);
    fputcsv($output, ["Dibuat: " . date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    
    // Summary
    if (isset($data['summary']) && !empty($data['summary'])) {
        fputcsv($output, ['RINGKASAN STATISTIK']);
        foreach ($data['summary'] as $key => $value) {
            fputcsv($output, [ucfirst(str_replace('_', ' ', $key)), $value ?? 0]);
        }
        fputcsv($output, []);
    }
    
    // Details
    if (isset($data['details']) && !empty($data['details'])) {
        fputcsv($output, ['DETAIL DATA']);
        if (!empty($data['details'][0])) {
            fputcsv($output, array_keys($data['details'][0]));
            foreach ($data['details'] as $row) {
                fputcsv($output, $row);
            }
        }
    }
    
    fclose($output);
    exit;
}

/**
 * Export report to JSON
 */
function exportToJSON($data, $title, $startDate, $endDate) {
    while (ob_get_level()) ob_end_clean();
    
    $exportData = [
        'title' => $title,
        'period' => [
            'start_date' => $startDate,
            'end_date' => $endDate
        ],
        'generated_at' => date('Y-m-d H:i:s'),
        'data' => $data
    ];
    
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '_', $title)) . '_' . date('Ymd_His') . '.json"');
    
    echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Export report to selected format
 */
function exportReport($data, $format, $title, $startDate, $endDate, $reportType) {
    switch ($format) {
        case 'csv':
            exportToCSV($data, $title, $startDate, $endDate, $reportType);
            break;
        case 'json':
            exportToJSON($data, $title, $startDate, $endDate);
            break;
        default:
            exportToCSV($data, $title, $startDate, $endDate, $reportType);
    }
}

// Get message types for filter
try {
    $messageTypes = $db->query("SELECT id, jenis_pesan FROM message_types ORDER BY jenis_pesan")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Message types query error: " . $e->getMessage());
    $messageTypes = [];
}

require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">
            <i class="fas fa-chart-bar me-2"></i>Laporan & Analitik
        </h1>
        <?php if (isset($_GET['debug'])): ?>
        <span class="badge bg-danger">DEBUG MODE</span>
        <?php endif; ?>
    </div>
    
    <!-- Error Message -->
    <?php if (isset($reportData['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Terjadi kesalahan: <?php echo htmlspecialchars($reportData['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Report Filters Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-filter me-2"></i>Filter Laporan
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" id="reportForm" class="row g-3">
                <!-- Baris 1: Jenis Laporan & Rentang Tanggal -->
                <div class="col-md-3">
                    <label class="form-label fw-bold">Jenis Laporan</label>
                    <select class="form-select" name="report_type" id="reportType">
                        <option value="dashboard" <?php echo $reportType === 'dashboard' ? 'selected' : ''; ?>>📊 Dashboard</option>
                        <option value="messages" <?php echo $reportType === 'messages' ? 'selected' : ''; ?>>✉️ Laporan Pesan</option>
                        <option value="responses" <?php echo $reportType === 'responses' ? 'selected' : ''; ?>>⏱️ Laporan Respons</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-bold">Rentang Waktu</label>
                    <select class="form-select" name="date_range" id="dateRange">
                        <option value="custom" <?php echo $dateRange === 'custom' ? 'selected' : ''; ?>>Kustom</option>
                        <option value="today" <?php echo $dateRange === 'today' ? 'selected' : ''; ?>>Hari Ini</option>
                        <option value="yesterday" <?php echo $dateRange === 'yesterday' ? 'selected' : ''; ?>>Kemarin</option>
                        <option value="this_week" <?php echo $dateRange === 'this_week' ? 'selected' : ''; ?>>Minggu Ini</option>
                        <option value="last_week" <?php echo $dateRange === 'last_week' ? 'selected' : ''; ?>>Minggu Lalu</option>
                        <option value="this_month" <?php echo $dateRange === 'this_month' ? 'selected' : ''; ?>>Bulan Ini</option>
                        <option value="last_30_days" <?php echo $dateRange === 'last_30_days' ? 'selected' : ''; ?>>30 Hari</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-bold">Tanggal Mulai</label>
                    <input type="date" class="form-control" name="start_date" id="startDate"
                           value="<?php echo htmlspecialchars($startDate); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-bold">Tanggal Selesai</label>
                    <input type="date" class="form-control" name="end_date" id="endDate"
                           value="<?php echo htmlspecialchars($endDate); ?>">
                </div>
                
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i>Tampilkan
                    </button>
                </div>
                
                <!-- Filter Lanjutan (untuk messages) -->
                <?php if ($reportType === 'messages' || $reportType === 'responses'): ?>
                <div class="col-12">
                    <hr>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Tipe Pengguna</label>
                            <select class="form-select" name="user_type">
                                <option value="all" <?php echo $userTypeFilter === 'all' ? 'selected' : ''; ?>>Semua</option>
                                <option value="Siswa" <?php echo $userTypeFilter === 'Siswa' ? 'selected' : ''; ?>>Siswa</option>
                                <option value="Orang_Tua" <?php echo $userTypeFilter === 'Orang_Tua' ? 'selected' : ''; ?>>Orang Tua</option>
                                <option value="Guru" <?php echo $userTypeFilter === 'Guru' ? 'selected' : ''; ?>>Guru</option>
                                <option value="Admin" <?php echo $userTypeFilter === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Semua</option>
                                <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Dibaca" <?php echo $statusFilter === 'Dibaca' ? 'selected' : ''; ?>>Dibaca</option>
                                <option value="Diproses" <?php echo $statusFilter === 'Diproses' ? 'selected' : ''; ?>>Diproses</option>
                                <option value="Disetujui" <?php echo $statusFilter === 'Disetujui' ? 'selected' : ''; ?>>Disetujui</option>
                                <option value="Ditolak" <?php echo $statusFilter === 'Ditolak' ? 'selected' : ''; ?>>Ditolak</option>
                                <option value="Selesai" <?php echo $statusFilter === 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                            </select>
                        </div>
                        
                        <?php if ($reportType === 'messages'): ?>
                        <div class="col-md-2">
                            <label class="form-label">Prioritas</label>
                            <select class="form-select" name="priority">
                                <option value="all" <?php echo $priorityFilter === 'all' ? 'selected' : ''; ?>>Semua</option>
                                <option value="Low" <?php echo $priorityFilter === 'Low' ? 'selected' : ''; ?>>Rendah</option>
                                <option value="Medium" <?php echo $priorityFilter === 'Medium' ? 'selected' : ''; ?>>Sedang</option>
                                <option value="High" <?php echo $priorityFilter === 'High' ? 'selected' : ''; ?>>Tinggi</option>
                                <option value="Urgent" <?php echo $priorityFilter === 'Urgent' ? 'selected' : ''; ?>>Mendesak</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Jenis Pesan</label>
                            <select class="form-select" name="message_type">
                                <option value="all" <?php echo $messageTypeFilter === 'all' ? 'selected' : ''; ?>>Semua</option>
                                <?php foreach ($messageTypes as $type): ?>
                                <option value="<?php echo $type['id']; ?>" <?php echo $messageTypeFilter == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['jenis_pesan']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Group By</label>
                            <select class="form-select" name="group_by">
                                <option value="day" <?php echo $groupBy === 'day' ? 'selected' : ''; ?>>Harian</option>
                                <option value="week" <?php echo $groupBy === 'week' ? 'selected' : ''; ?>>Mingguan</option>
                                <option value="month" <?php echo $groupBy === 'month' ? 'selected' : ''; ?>>Bulanan</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="dropdown w-100">
                                <button class="btn btn-outline-primary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-download me-1"></i>Export
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>">
                                        <i class="fas fa-file-csv me-2"></i>CSV
                                    </a></li>
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'json'])); ?>">
                                        <i class="fas fa-code me-2"></i>JSON
                                    </a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <!-- Report Content -->
    <?php if ($reportType === 'messages'): ?>
        <!-- LAPORAN PESAN -->
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0">
                    <i class="fas fa-envelope me-2"></i><?php echo $reportTitle; ?>
                </h5>
                <small class="text-muted">
                    Periode: <?php echo date('d/m/Y', strtotime($startDate)); ?> - <?php echo date('d/m/Y', strtotime($endDate)); ?>
                </small>
            </div>
            <div class="card-body">
                <!-- Summary Cards -->
                <?php if (isset($reportData['summary']) && !empty($reportData['summary'])): ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h6>Total Pesan</h6>
                                <h3><?php echo number_format($reportData['summary']['total_messages'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <h6>Pending</h6>
                                <h3><?php echo number_format($reportData['summary']['pending'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h6>Disetujui</h6>
                                <h3><?php echo number_format($reportData['summary']['disetujui'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <h6>Ditolak</h6>
                                <h3><?php echo number_format($reportData['summary']['ditolak'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Time Series Table -->
                <?php if (!empty($reportData['time_series'])): ?>
                <div class="mb-4">
                    <h6 class="fw-bold mb-3">Data Time Series</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Periode</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Selesai</th>
                                    <th class="text-end">Pending</th>
                                    <th class="text-end">Ditolak</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['time_series'] as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['period'] ?? '-'); ?></td>
                                    <td class="text-end"><?php echo number_format($row['total'] ?? 0); ?></td>
                                    <td class="text-end"><?php echo number_format($row['completed'] ?? 0); ?></td>
                                    <td class="text-end"><?php echo number_format($row['pending'] ?? 0); ?></td>
                                    <td class="text-end"><?php echo number_format($row['rejected'] ?? 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Type Breakdown Table -->
                <?php if (!empty($reportData['type_breakdown'])): ?>
                <div class="mb-4">
                    <h6 class="fw-bold mb-3">Statistik per Jenis Pesan</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Jenis Pesan</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Disetujui</th>
                                    <th class="text-end">Ditolak</th>
                                    <th class="text-end">Pending</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['type_breakdown'] as $type): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($type['jenis_pesan'] ?? '-'); ?></td>
                                    <td class="text-end"><?php echo number_format($type['total'] ?? 0); ?></td>
                                    <td class="text-end"><?php echo number_format($type['approved'] ?? 0); ?></td>
                                    <td class="text-end"><?php echo number_format($type['rejected'] ?? 0); ?></td>
                                    <td class="text-end"><?php echo number_format($type['pending'] ?? 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Priority Distribution -->
                <?php if (!empty($reportData['priority_distribution'])): ?>
                <div class="mb-4">
                    <h6 class="fw-bold mb-3">Distribusi Prioritas</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Prioritas</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['priority_distribution'] as $priority): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($priority['priority'] ?? '') {
                                                'Urgent' => 'danger',
                                                'High' => 'warning',
                                                'Medium' => 'info',
                                                'Low' => 'success',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo htmlspecialchars($priority['priority'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?php echo number_format($priority['total'] ?? 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Detailed Data -->
                <?php if (!empty($reportData['details'])): ?>
                <div>
                    <h6 class="fw-bold mb-3">Detail Data (<?php echo count($reportData['details']); ?> data)</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Ref</th>
                                    <th>Tanggal</th>
                                    <th>Jenis</th>
                                    <th>Pengirim</th>
                                    <th>Status</th>
                                    <th>Prioritas</th>
                                    <th>Pesan</th>
                                    <th>Waktu Respons</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['details'] as $row): ?>
                                <tr>
                                    <td><?php echo $row['id'] ?? '-'; ?></td>
                                    <td><small><?php echo htmlspecialchars($row['reference_number'] ?? '-'); ?></small></td>
                                    <td><small><?php echo htmlspecialchars($row['tanggal_pesan'] ?? '-'); ?></small></td>
                                    <td><?php echo htmlspecialchars($row['jenis_pesan'] ?? '-'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($row['pengirim_nama'] ?? '-'); ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(str_replace('_', ' ', $row['pengirim_tipe'] ?? '')); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($row['status'] ?? '') {
                                                'Disetujui' => 'success',
                                                'Ditolak' => 'danger',
                                                'Selesai' => 'secondary',
                                                'Pending' => 'warning',
                                                default => 'info'
                                            };
                                        ?>">
                                            <?php echo htmlspecialchars($row['status'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($row['priority'] ?? '') {
                                                'Urgent' => 'danger',
                                                'High' => 'warning',
                                                'Medium' => 'info',
                                                'Low' => 'success',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo htmlspecialchars($row['priority'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['isi_pesan_ringkas'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($row['response_time'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (empty($reportData['details']) && empty($reportData['time_series'])): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Tidak ada data untuk periode yang dipilih.
                </div>
                <?php endif; ?>
            </div>
        </div>
        
    <?php elseif ($reportType === 'responses'): ?>
        <!-- LAPORAN RESPONS -->
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0">
                    <i class="fas fa-clock me-2"></i><?php echo $reportTitle; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (isset($reportData['summary']) && !empty($reportData['summary'])): ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h6>Total Pesan</h6>
                                <h3><?php echo number_format($reportData['summary']['total_messages'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h6>Sudah Direspons</h6>
                                <h3><?php echo number_format($reportData['summary']['responded'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h6>Rata Respons</h6>
                                <h3><?php echo number_format($reportData['summary']['avg_response_hours'] ?? 0, 1); ?> jam</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <h6>Terlambat</h6>
                                <h3><?php echo number_format($reportData['summary']['late_responses'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Details -->
                <?php if (!empty($reportData['details'])): ?>
                <h6 class="fw-bold mb-3">Detail Respons</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Ref</th>
                                <th>Jenis</th>
                                <th>Pengirim</th>
                                <th>Tgl Pesan</th>
                                <th>Tgl Respons</th>
                                <th>Durasi</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['details'] as $row): ?>
                            <tr>
                                <td><?php echo $row['id'] ?? '-'; ?></td>
                                <td><?php echo htmlspecialchars($row['reference_number'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['jenis_pesan'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['pengirim'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['tanggal_pesan'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['tanggal_respon'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['response_hours'] ?? '0'); ?> jam</td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo match($row['status'] ?? '') {
                                            'Disetujui' => 'success',
                                            'Ditolak' => 'danger',
                                            default => 'secondary'
                                        };
                                    ?>">
                                        <?php echo htmlspecialchars($row['status'] ?? '-'); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
    <?php else: ?>
        <!-- DASHBOARD -->
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0">
                    <i class="fas fa-chart-pie me-2"></i>Dashboard Analitik
                </h5>
                <small class="text-muted">
                    Data per <?php echo date('d/m/Y H:i:s'); ?>
                </small>
            </div>
            <div class="card-body">
                <?php if (isset($reportData['today_stats'])): ?>
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h6>Pesan Hari Ini</h6>
                                <h3><?php echo number_format($reportData['today_stats']['today_messages'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <h6>Pending Hari Ini</h6>
                                <h3><?php echo number_format($reportData['today_stats']['today_pending'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h6>Selesai Hari Ini</h6>
                                <h3><?php echo number_format($reportData['today_stats']['today_completed'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Weekly Trend -->
                <?php if (!empty($reportData['weekly_trend'])): ?>
                <h6 class="fw-bold mb-3">Tren 7 Hari Terakhir</h6>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Tanggal</th>
                                <th class="text-end">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['weekly_trend'] as $day): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($day['date'])); ?></td>
                                <td class="text-end"><?php echo number_format($day['total']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Debug Info -->
<?php if (isset($_GET['debug'])): ?>
<div class="container-fluid mt-4">
    <div class="card bg-dark text-white">
        <div class="card-header">
            <i class="fas fa-bug me-2"></i>Debug Information
        </div>
        <div class="card-body">
            <pre class="mb-0 text-white" style="font-size: 12px;">
Report Type: <?php echo $reportType; ?>
Start Date: <?php echo $startDate; ?>
End Date: <?php echo $endDate; ?>
Filters: 
- user_type: <?php echo $userTypeFilter; ?>
- status: <?php echo $statusFilter; ?>
- priority: <?php echo $priorityFilter; ?>
- message_type: <?php echo $messageTypeFilter; ?>
- group_by: <?php echo $groupBy; ?>

Data Count:
- summary: <?php echo isset($reportData['summary']) ? count($reportData['summary']) : 0; ?>
- time_series: <?php echo isset($reportData['time_series']) ? count($reportData['time_series']) : 0; ?>
- type_breakdown: <?php echo isset($reportData['type_breakdown']) ? count($reportData['type_breakdown']) : 0; ?>
- priority_dist: <?php echo isset($reportData['priority_distribution']) ? count($reportData['priority_distribution']) : 0; ?>
- details: <?php echo isset($reportData['details']) ? count($reportData['details']) : 0; ?>
            </pre>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Auto-update date range
document.getElementById('dateRange').addEventListener('change', function() {
    const range = this.value;
    const today = new Date();
    let startDate = '', endDate = '';
    
    switch(range) {
        case 'today':
            startDate = today.toISOString().split('T')[0];
            endDate = startDate;
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            startDate = yesterday.toISOString().split('T')[0];
            endDate = startDate;
            break;
        case 'this_week':
            const monday = new Date(today);
            monday.setDate(monday.getDate() - (monday.getDay() || 7) + 1);
            startDate = monday.toISOString().split('T')[0];
            endDate = today.toISOString().split('T')[0];
            break;
        case 'last_week':
            const lastMonday = new Date(today);
            lastMonday.setDate(lastMonday.getDate() - (lastMonday.getDay() || 7) - 6);
            const lastSunday = new Date(lastMonday);
            lastSunday.setDate(lastSunday.getDate() + 6);
            startDate = lastMonday.toISOString().split('T')[0];
            endDate = lastSunday.toISOString().split('T')[0];
            break;
        case 'this_month':
            startDate = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-01';
            endDate = today.toISOString().split('T')[0];
            break;
        case 'last_30_days':
            const thirtyDaysAgo = new Date(today);
            thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
            startDate = thirtyDaysAgo.toISOString().split('T')[0];
            endDate = today.toISOString().split('T')[0];
            break;
    }
    
    if (startDate) document.getElementById('startDate').value = startDate;
    if (endDate) document.getElementById('endDate').value = endDate;
});
</script>

<?php
require_once '../../includes/footer.php';
?>