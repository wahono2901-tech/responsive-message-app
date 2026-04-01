<?php
/**
 * User Message History
 * File: modules/user/history.php
 * 
 * FITUR:
 * - Menampilkan riwayat lengkap pesan user
 * - Filter berdasarkan tanggal, status, jenis pesan
 * - Export data ke PDF/Excel
 * - Statistik pesan per periode
 * - Grafik tren pengiriman pesan
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

define('HISTORY_DEBUG_LOG', $logDir . '/history_debug.log');
define('HISTORY_ERROR_LOG', $logDir . '/history_error.log');

file_put_contents(HISTORY_DEBUG_LOG, "\n[" . date('Y-m-d H:i:s') . "] ========== HISTORY.PHP START ==========\n", FILE_APPEND);
file_put_contents(HISTORY_ERROR_LOG, "\n[" . date('Y-m-d H:i:s') . "] ========== HISTORY.PHP START ==========\n", FILE_APPEND);

function writeHistoryLog($message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents(HISTORY_DEBUG_LOG, $log, FILE_APPEND);
    error_log($log);
}

function writeHistoryError($message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] [ERROR] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents(HISTORY_ERROR_LOG, $log, FILE_APPEND);
    error_log("[ERROR] " . $log);
}

writeHistoryLog("MEMULAI EKSEKUSI HISTORY.PHP");

// ============================================================================
// DEBUG FUNCTION
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
    
    $log_message = "[HISTORY_DEBUG][{$current_date}][STEP {$step_counter}][{$caller}:{$line}] {$title}";
    if ($data !== null) {
        $log_message .= " - " . print_r($data, true);
    }
    error_log($log_message);
    writeHistoryLog("STEP {$step_counter}: {$title}", $data);
}

debug_step("=" . str_repeat("=", 70), null, 'separator');
debug_step("HISTORY.PHP - MULAI EKSEKUSI", [
    'time' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'debug_enabled' => $debug_enabled
], 'start');

// Check authentication
try {
    Auth::checkAuth();
    writeHistoryLog("Auth::checkAuth() SUCCESS");
} catch (Exception $e) {
    writeHistoryError("Auth::checkAuth() FAILED", $e->getMessage());
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$userName = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'User';

writeHistoryLog("User ID: $userId, User Type: $userType, Name: $userName");

// ============================================================================
// FILTER PARAMETERS
// ============================================================================
$period = $_GET['period'] ?? '30days'; // 7days, 30days, 90days, year, all
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'date_desc'; // date_desc, date_asc, status, priority
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$export = $_GET['export'] ?? '';

// Date range based on period
$dateFrom = '';
$dateTo = date('Y-m-d 23:59:59');

switch ($period) {
    case '7days':
        $dateFrom = date('Y-m-d 00:00:00', strtotime('-7 days'));
        break;
    case '30days':
        $dateFrom = date('Y-m-d 00:00:00', strtotime('-30 days'));
        break;
    case '90days':
        $dateFrom = date('Y-m-d 00:00:00', strtotime('-90 days'));
        break;
    case 'year':
        $dateFrom = date('Y-01-01 00:00:00');
        break;
    case 'all':
    default:
        $dateFrom = '1970-01-01 00:00:00';
        break;
}

debug_step("Filter parameters", [
    'period' => $period,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'status' => $statusFilter,
    'type' => $typeFilter,
    'search' => $search,
    'sort' => $sortBy,
    'page' => $page
]);

// Database connection
$db = Database::getInstance()->getConnection();
debug_step("Database connection", ['connected' => !empty($db)]);
writeHistoryLog("Database connected");

// ============================================================================
// BUILD QUERY CONDITIONS
// ============================================================================
$whereConditions = ["m.pengirim_id = :user_id"];
$params = [':user_id' => $userId];

// Date range
$whereConditions[] = "m.created_at BETWEEN :date_from AND :date_to";
$params[':date_from'] = $dateFrom;
$params[':date_to'] = $dateTo;

// Status filter
if ($statusFilter !== 'all') {
    $whereConditions[] = "m.status = :status";
    $params[':status'] = $statusFilter;
}

// Message type filter
if ($typeFilter !== 'all') {
    $whereConditions[] = "m.jenis_pesan_id = :type";
    $params[':type'] = $typeFilter;
}

// Search
if (!empty($search)) {
    $whereConditions[] = "(m.isi_pesan LIKE :search OR mt.jenis_pesan LIKE :search)";
    $params[':search'] = "%$search%";
}

$whereClause = implode(' AND ', $whereConditions);
debug_step("Query conditions", ['where_clause' => $whereClause, 'params' => $params]);

// ============================================================================
// GET STATISTICS
// ============================================================================
$statsSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Dibaca' THEN 1 ELSE 0 END) as dibaca,
        SUM(CASE WHEN status = 'Diproses' THEN 1 ELSE 0 END) as diproses,
        SUM(CASE WHEN status = 'Disetujui' THEN 1 ELSE 0 END) as disetujui,
        SUM(CASE WHEN status = 'Ditolak' THEN 1 ELSE 0 END) as ditolak,
        SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as selesai,
        SUM(CASE WHEN is_external = 1 THEN 1 ELSE 0 END) as external_count,
        SUM(CASE WHEN priority = 'Urgent' THEN 1 ELSE 0 END) as urgent_count,
        SUM(CASE WHEN priority = 'High' THEN 1 ELSE 0 END) as high_count,
        SUM(CASE WHEN priority = 'Medium' THEN 1 ELSE 0 END) as medium_count,
        SUM(CASE WHEN priority = 'Low' THEN 1 ELSE 0 END) as low_count,
        AVG(TIMESTAMPDIFF(HOUR, m.created_at, 
            CASE 
                WHEN m.tanggal_respon IS NOT NULL THEN m.tanggal_respon
                ELSE NOW()
            END
        )) as avg_response_hours,
        MAX(m.created_at) as last_message_date,
        MIN(m.created_at) as first_message_date
    FROM messages m
    LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
    WHERE $whereClause
";

$statsStmt = $db->prepare($statsSql);
$statsStmt->execute($params);
$stats = $statsStmt->fetch();

writeHistoryLog("Statistics", $stats);
debug_step("Statistics", $stats);

// ============================================================================
// GET DAILY STATS FOR CHART
// ============================================================================
$dailyStatsSql = "
    SELECT 
        DATE(m.created_at) as date,
        COUNT(*) as total,
        SUM(CASE WHEN m.status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN m.status IN ('Pending', 'Dibaca', 'Diproses') THEN 1 ELSE 0 END) as pending
    FROM messages m
    WHERE m.pengirim_id = :user_id
        AND m.created_at BETWEEN :date_from AND :date_to
    GROUP BY DATE(m.created_at)
    ORDER BY date ASC
";

$dailyStmt = $db->prepare($dailyStatsSql);
$dailyStmt->execute([
    ':user_id' => $userId,
    ':date_from' => $dateFrom,
    ':date_to' => $dateTo
]);
$dailyStats = $dailyStmt->fetchAll();

// ============================================================================
// GET MESSAGE TYPES FOR FILTER
// ============================================================================
$typeStmt = $db->query("SELECT id, jenis_pesan FROM message_types ORDER BY jenis_pesan");
$messageTypes = $typeStmt->fetchAll();

// ============================================================================
// GET TOTAL COUNT FOR PAGINATION
// ============================================================================
$countSql = "
    SELECT COUNT(*) as total 
    FROM messages m
    LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
    WHERE $whereClause
";

$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = $countStmt->fetch()['total'];

writeHistoryLog("Total messages: $total");
debug_step("Total messages", ['total' => $total]);

// Calculate pagination
$totalPages = ceil($total / $perPage);
$page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));
$offset = ($page - 1) * $perPage;

// ============================================================================
// ORDER BY CLAUSE
// ============================================================================
$orderBy = match($sortBy) {
    'date_asc' => 'm.created_at ASC',
    'status' => 'm.status ASC, m.created_at DESC',
    'priority' => 'CASE m.priority WHEN \'Urgent\' THEN 1 WHEN \'High\' THEN 2 WHEN \'Medium\' THEN 3 WHEN \'Low\' THEN 4 ELSE 5 END, m.created_at DESC',
    default => 'm.created_at DESC' // date_desc
};

// ============================================================================
// GET MESSAGES WITH COMPLETE DETAILS
// ============================================================================
$sql = "
    SELECT 
        m.*,
        mt.jenis_pesan,
        mt.response_deadline_hours,
        u.nama_lengkap as responder_nama,
        u.user_type as responder_type,
        mr.catatan_respon as last_response,
        mr.created_at as last_response_date,
        mr.status as response_status,
        TIMESTAMPDIFF(HOUR, m.created_at, 
            COALESCE(m.tanggal_respon, NOW())
        ) as response_time_hours,
        CASE 
            WHEN m.tanggal_respon IS NOT NULL THEN 
                TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon)
            ELSE NULL
        END as actual_response_hours,
        CASE 
            WHEN m.status IN ('Disetujui', 'Selesai') THEN 'Completed'
            WHEN m.status = 'Ditolak' THEN 'Rejected'
            WHEN m.status IN ('Pending', 'Dibaca', 'Diproses') THEN 'In Progress'
            ELSE m.status
        END as status_group
    FROM messages m
    LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
    LEFT JOIN users u ON m.responder_id = u.id
    LEFT JOIN message_responses mr ON m.id = mr.message_id 
        AND mr.created_at = (
            SELECT MAX(created_at) 
            FROM message_responses 
            WHERE message_id = m.id
        )
    WHERE $whereClause
    ORDER BY $orderBy
    LIMIT :offset, :limit
";

$stmt = $db->prepare($sql);
$params[':offset'] = $offset;
$params[':limit'] = $perPage;

foreach ($params as $key => $value) {
    $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($key, $value, $type);
}

$stmt->execute();
$messages = $stmt->fetchAll();

writeHistoryLog("Messages fetched", ['count' => count($messages)]);
debug_step("Messages fetched", ['count' => count($messages)]);

// ============================================================================
// HANDLE EXPORT
// ============================================================================
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=message_history_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Header CSV
    fputcsv($output, [
        'ID', 'Tanggal', 'Jenis Pesan', 'Isi Pesan', 'Status', 
        'Prioritas', 'Waktu Respons (Jam)', 'Responder', 'Tanggal Respons'
    ]);
    
    // Data
    foreach ($messages as $message) {
        fputcsv($output, [
            $message['id'],
            date('d/m/Y H:i', strtotime($message['created_at'])),
            $message['jenis_pesan'],
            strip_tags($message['isi_pesan']),
            $message['status'],
            $message['priority'],
            $message['actual_response_hours'] ? number_format($message['actual_response_hours'], 1) : '-',
            $message['responder_nama'] ?? '-',
            $message['tanggal_respon'] ? date('d/m/Y H:i', strtotime($message['tanggal_respon'])) : '-'
        ]);
    }
    
    fclose($output);
    exit;
}

if ($export === 'pdf') {
    // Redirect ke fungsi export PDF (implementasi terpisah)
    header('Location: export_pdf.php?' . http_build_query($_GET));
    exit;
}

debug_step("HISTORY.PHP - SIAP MENAMPILKAN HALAMAN", [
    'debug_steps_count' => $step_counter
], 'complete');

writeHistoryLog("HISTORY.PHP SELESAI, MENAMPILKAN HALAMAN");
writeHistoryLog(str_repeat("=", 80) . "\n");

require_once '../../includes/header.php';
?>

<!-- Custom CSS -->
<style>
/* History specific styles */
:root {
    --chart-height: 300px;
    --stat-card-min-width: 160px;
}

/* Timeline styling */
.history-timeline {
    position: relative;
    padding: 20px 0;
}

.history-timeline::before {
    content: '';
    position: absolute;
    top: 0;
    left: 30px;
    height: 100%;
    width: 2px;
    background: linear-gradient(to bottom, #e9ecef, #dee2e6, #e9ecef);
}

.timeline-item {
    position: relative;
    padding-left: 70px;
    margin-bottom: 25px;
}

.timeline-item:last-child {
    margin-bottom: 0;
}

.timeline-badge {
    position: absolute;
    left: 18px;
    top: 0;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    z-index: 2;
}

.timeline-badge.bg-warning { background-color: #ffc107; }
.timeline-badge.bg-success { background-color: #28a745; }
.timeline-badge.bg-danger { background-color: #dc3545; }
.timeline-badge.bg-info { background-color: #17a2b8; }
.timeline-badge.bg-secondary { background-color: #6c757d; }

.timeline-content {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    border-left: 3px solid #007bff;
    transition: all 0.3s ease;
}

.timeline-content:hover {
    background: #ffffff;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

/* Chart container */
.chart-container {
    position: relative;
    height: var(--chart-height);
    width: 100%;
    margin: 20px 0;
}

/* Stat cards */
.stat-card {
    min-width: var(--stat-card-min-width);
    transition: transform 0.2s;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-card .stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.stat-card .stat-value {
    font-size: 24px;
    font-weight: 600;
    line-height: 1.2;
}

.stat-card .stat-label {
    font-size: 13px;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Period selector */
.period-selector {
    display: flex;
    gap: 5px;
}

.period-btn {
    padding: 5px 12px;
    font-size: 13px;
    border-radius: 20px;
    background: #f8f9fa;
    color: #495057;
    text-decoration: none;
    transition: all 0.2s;
}

.period-btn:hover {
    background: #e9ecef;
    color: #212529;
}

.period-btn.active {
    background: #007bff;
    color: white;
}

/* Filter badges */
.filter-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    background: #f8f9fa;
    border-radius: 20px;
    font-size: 12px;
    margin-right: 8px;
    margin-bottom: 8px;
}

.filter-badge .badge-label {
    color: #6c757d;
    margin-right: 5px;
}

.filter-badge .badge-value {
    font-weight: 600;
    color: #495057;
}

.filter-badge .remove-filter {
    margin-left: 8px;
    color: #dc3545;
    cursor: pointer;
    font-size: 14px;
}

/* Responsive table */
.table-history {
    font-size: 14px;
}

.table-history th {
    background: #f8f9fa;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    color: #495057;
    border-top: none;
    white-space: nowrap;
}

.table-history td {
    vertical-align: middle;
}

.message-preview {
    max-width: 300px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Status indicators */
.status-indicator {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 6px;
}

.status-indicator.pending { background-color: #ffc107; }
.status-indicator.dibaca { background-color: #17a2b8; }
.status-indicator.diproses { background-color: #007bff; }
.status-indicator.disetujui { background-color: #28a745; }
.status-indicator.ditolak { background-color: #dc3545; }
.status-indicator.selesai { background-color: #6c757d; }

/* Export dropdown */
.export-dropdown {
    position: relative;
    display: inline-block;
}

.export-menu {
    position: absolute;
    right: 0;
    top: 100%;
    min-width: 180px;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    z-index: 1000;
    display: none;
}

.export-dropdown:hover .export-menu {
    display: block;
}

.export-menu a {
    display: block;
    padding: 8px 15px;
    color: #212529;
    text-decoration: none;
    transition: background 0.2s;
}

.export-menu a:hover {
    background: #f8f9fa;
}

.export-menu a i {
    width: 20px;
    color: #6c757d;
}

/* Empty state */
.empty-state {
    padding: 60px 20px;
    text-align: center;
}

.empty-state i {
    font-size: 64px;
    color: #dee2e6;
    margin-bottom: 20px;
}

.empty-state h5 {
    color: #495057;
    margin-bottom: 10px;
}

.empty-state p {
    color: #6c757d;
    max-width: 400px;
    margin: 0 auto;
}

/* Loading overlay */
#loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255,255,255,0.8);
    z-index: 9999;
    display: none;
    justify-content: center;
    align-items: center;
}

#loading-overlay .spinner-border {
    width: 3rem;
    height: 3rem;
}

/* Debug panel */
#debug-panel {
    position: fixed;
    bottom: 80px;
    right: 20px;
    width: 500px;
    max-height: 500px;
    overflow-y: auto;
    background: #1e1e2f;
    border: 1px solid #ff9900;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    z-index: 10000;
    display: none;
}

#debug-panel .debug-header {
    background: #2d2d44;
    color: white;
    padding: 10px 15px;
    border-radius: 8px 8px 0 0;
    cursor: pointer;
}

#debug-panel .debug-content {
    padding: 15px;
    background: #1e1e2f;
}

.debug-log {
    margin-bottom: 10px;
    padding: 8px;
    border-left: 4px solid;
    background: #2d2d44;
    font-size: 12px;
    font-family: monospace;
    color: #e0e0e0;
}

.debug-log.error { border-left-color: #dc3545; }
.debug-log.warning { border-left-color: #ffc107; }
.debug-log.success { border-left-color: #28a745; }
.debug-log.info { border-left-color: #007bff; }

.debug-timestamp {
    color: #888;
    font-size: 10px;
    display: block;
}

.debug-step {
    color: #ff9900;
    font-weight: bold;
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
    z-index: 10001;
}
</style>

<div class="container-fluid">
    <!-- Debug Toggle Button -->
    <button class="debug-toggle-btn" onclick="toggleDebugPanel()" id="debugToggleBtn">
        <i class="fas fa-bug"></i> Debug
        <?php if ($debug_enabled): ?>
        <span class="badge bg-danger"><?php echo $step_counter; ?></span>
        <?php endif; ?>
    </button>
    
    <!-- Debug Panel -->
    <?php if ($debug_enabled && !empty($debug_steps)): ?>
    <div id="debug-panel">
        <div class="debug-header" onclick="toggleDebugContent()">
            <strong>DEBUG LOG - <?php echo count($debug_steps); ?> STEPS</strong>
            <small class="float-end"><i class="fas fa-chevron-down"></i></small>
        </div>
        <div class="debug-content" id="debug-content">
            <?php foreach ($debug_steps as $log): ?>
            <div class="debug-log <?php echo $log['type']; ?>">
                <span class="debug-timestamp"><?php echo $log['time']; ?></span>
                <span class="debug-step">STEP <?php echo str_pad($log['step'], 2, '0', STR_PAD_LEFT); ?></span><br>
                <strong><?php echo htmlspecialchars($log['title']); ?></strong>
                <?php if ($log['data'] !== null): ?>
                <br><small><?php echo htmlspecialchars(print_r($log['data'], true)); ?></small>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-history me-2"></i>Riwayat Pesan
                <?php if ($debug_enabled): ?>
                <span class="badge bg-danger ms-2">DEBUG MODE ON</span>
                <?php endif; ?>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="dashboard_user.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Riwayat Pesan</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex align-items-center">
            <div class="export-dropdown me-2">
                <button class="btn btn-outline-primary">
                    <i class="fas fa-download me-1"></i>Export
                </button>
                <div class="export-menu">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>">
                        <i class="fas fa-file-csv me-2"></i>CSV
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>">
                        <i class="fas fa-file-pdf me-2"></i>PDF
                    </a>
                </div>
            </div>
            <?php if ($debug_enabled): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['debug' => 'off'])); ?>" 
               class="btn btn-sm btn-outline-secondary me-2">
                <i class="fas fa-bug"></i> Debug OFF
            </a>
            <?php else: ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['debug' => 'on'])); ?>" 
               class="btn btn-sm btn-outline-primary me-2">
                <i class="fas fa-bug"></i> Debug ON
            </a>
            <?php endif; ?>
            <a href="send_message.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i>Pesan Baru
            </a>
        </div>
    </div>
    
    <!-- Period Selector -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                <div class="period-selector mb-2 mb-sm-0">
                    <a href="?period=7days&<?php echo http_build_query(array_merge($_GET, ['period' => '7days'])); ?>" 
                       class="period-btn <?php echo $period === '7days' ? 'active' : ''; ?>">7 Hari</a>
                    <a href="?period=30days&<?php echo http_build_query(array_merge($_GET, ['period' => '30days'])); ?>" 
                       class="period-btn <?php echo $period === '30days' ? 'active' : ''; ?>">30 Hari</a>
                    <a href="?period=90days&<?php echo http_build_query(array_merge($_GET, ['period' => '90days'])); ?>" 
                       class="period-btn <?php echo $period === '90days' ? 'active' : ''; ?>">90 Hari</a>
                    <a href="?period=year&<?php echo http_build_query(array_merge($_GET, ['period' => 'year'])); ?>" 
                       class="period-btn <?php echo $period === 'year' ? 'active' : ''; ?>">Tahun Ini</a>
                    <a href="?period=all&<?php echo http_build_query(array_merge($_GET, ['period' => 'all'])); ?>" 
                       class="period-btn <?php echo $period === 'all' ? 'active' : ''; ?>">Semua</a>
                </div>
                
                <div class="text-muted small">
                    <i class="fas fa-calendar-alt me-1"></i>
                    <?php echo date('d M Y', strtotime($dateFrom)); ?> - <?php echo date('d M Y'); ?>
                </div>
            </div>
            
            <!-- Active Filters -->
            <?php if ($statusFilter !== 'all' || $typeFilter !== 'all' || !empty($search)): ?>
            <div class="mt-3 pt-3 border-top">
                <div class="d-flex flex-wrap align-items-center">
                    <span class="text-muted small me-2">Filter aktif:</span>
                    
                    <?php if ($statusFilter !== 'all'): ?>
                    <div class="filter-badge">
                        <span class="badge-label">Status:</span>
                        <span class="badge-value"><?php echo $statusFilter; ?></span>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'all', 'page' => 1])); ?>" class="remove-filter">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($typeFilter !== 'all'): ?>
                    <div class="filter-badge">
                        <span class="badge-label">Jenis:</span>
                        <?php 
                        $typeName = '';
                        foreach ($messageTypes as $type) {
                            if ($type['id'] == $typeFilter) {
                                $typeName = $type['jenis_pesan'];
                                break;
                            }
                        }
                        ?>
                        <span class="badge-value"><?php echo $typeName; ?></span>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['type' => 'all', 'page' => 1])); ?>" class="remove-filter">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($search)): ?>
                    <div class="filter-badge">
                        <span class="badge-label">Pencarian:</span>
                        <span class="badge-value">"<?php echo htmlspecialchars($search); ?>"</span>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['search' => '', 'page' => 1])); ?>" class="remove-filter">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                            <div class="stat-label">Total Pesan</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo ($stats['pending'] ?? 0) + ($stats['dibaca'] ?? 0) + ($stats['diproses'] ?? 0); ?></div>
                            <div class="stat-label">Dalam Proses</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo ($stats['disetujui'] ?? 0) + ($stats['selesai'] ?? 0); ?></div>
                            <div class="stat-label">Selesai</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['ditolak'] ?? 0; ?></div>
                            <div class="stat-label">Ditolak</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['avg_response_hours'] ? number_format($stats['avg_response_hours'], 1) : '-'; ?></div>
                            <div class="stat-label">Rata-rata Respons (Jam)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-0 shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-secondary bg-opacity-10 text-secondary me-3">
                            <i class="fas fa-external-link-alt"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['external_count'] ?? 0; ?></div>
                            <div class="stat-label">External</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Chart -->
    <?php if (!empty($dailyStats)): ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-chart-line me-2 text-primary"></i>Tren Pengiriman Pesan
            </h6>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="messageChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="period" value="<?php echo $period; ?>">
                
                <div class="col-md-3">
                    <label class="form-label small fw-bold">STATUS</label>
                    <select class="form-select" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>📋 Semua Status</option>
                        <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>🕒 Pending</option>
                        <option value="Dibaca" <?php echo $statusFilter === 'Dibaca' ? 'selected' : ''; ?>>👁️ Dibaca</option>
                        <option value="Diproses" <?php echo $statusFilter === 'Diproses' ? 'selected' : ''; ?>>⚙️ Diproses</option>
                        <option value="Disetujui" <?php echo $statusFilter === 'Disetujui' ? 'selected' : ''; ?>>✅ Disetujui</option>
                        <option value="Ditolak" <?php echo $statusFilter === 'Ditolak' ? 'selected' : ''; ?>>❌ Ditolak</option>
                        <option value="Selesai" <?php echo $statusFilter === 'Selesai' ? 'selected' : ''; ?>>🏁 Selesai</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label small fw-bold">JENIS PESAN</label>
                    <select class="form-select" name="type">
                        <option value="all">📊 Semua Jenis</option>
                        <?php foreach ($messageTypes as $type): ?>
                        <option value="<?php echo $type['id']; ?>" <?php echo $typeFilter == $type['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['jenis_pesan']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold">URUTKAN</label>
                    <select class="form-select" name="sort">
                        <option value="date_desc" <?php echo $sortBy === 'date_desc' ? 'selected' : ''; ?>>📅 Terbaru</option>
                        <option value="date_asc" <?php echo $sortBy === 'date_asc' ? 'selected' : ''; ?>>📅 Terlama</option>
                        <option value="status" <?php echo $sortBy === 'status' ? 'selected' : ''; ?>>🏷️ Status</option>
                        <option value="priority" <?php echo $sortBy === 'priority' ? 'selected' : ''; ?>>⚠️ Prioritas</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label small fw-bold">CARI</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0" name="search" 
                               placeholder="Isi pesan..." 
                               value="<?php echo htmlspecialchars($search ?? ''); ?>">
                    </div>
                </div>
                
                <div class="col-md-1 d-flex align-items-end">
                    <div class="d-grid w-100">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Messages Timeline/Table -->
    <div class="card border-0 shadow">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-list me-2 text-primary"></i>Riwayat Pesan
                <span class="badge bg-primary ms-2"><?php echo $total; ?></span>
            </h6>
            <div>
                <button class="btn btn-sm btn-outline-secondary" onclick="toggleView()" id="viewToggleBtn">
                    <i class="fas fa-list me-1"></i>Table View
                </button>
            </div>
        </div>
        
        <div class="card-body p-0">
            <?php if (empty($messages)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h5>Belum Ada Riwayat Pesan</h5>
                <p>Belum ada pesan dalam periode yang dipilih. Coba ubah filter atau kirim pesan baru.</p>
                <a href="send_message.php" class="btn btn-primary mt-3">
                    <i class="fas fa-plus me-1"></i>Kirim Pesan Baru
                </a>
            </div>
            <?php else: ?>
            
            <!-- Timeline View (Default) -->
            <div id="timelineView">
                <div class="history-timeline p-4">
                    <?php foreach ($messages as $message): 
                        $badgeColor = match($message['status']) {
                            'Pending' => 'bg-warning',
                            'Dibaca' => 'bg-info',
                            'Diproses' => 'bg-primary',
                            'Disetujui' => 'bg-success',
                            'Ditolak' => 'bg-danger',
                            'Selesai' => 'bg-secondary',
                            default => 'bg-secondary'
                        };
                        
                        $icon = match($message['status']) {
                            'Pending' => 'fa-clock',
                            'Dibaca' => 'fa-eye',
                            'Diproses' => 'fa-cog',
                            'Disetujui' => 'fa-check',
                            'Ditolak' => 'fa-times',
                            'Selesai' => 'fa-flag-checkered',
                            default => 'fa-envelope'
                        };
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-badge <?php echo $badgeColor; ?>">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="badge <?php echo $badgeColor; ?> me-2"><?php echo $message['status']; ?></span>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($message['jenis_pesan']); ?></span>
                                    <?php if ($message['priority'] === 'Urgent'): ?>
                                    <span class="badge bg-danger">Mendesak</span>
                                    <?php elseif ($message['priority'] === 'High'): ?>
                                    <span class="badge bg-warning">Tinggi</span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">
                                    <i class="far fa-calendar-alt me-1"></i>
                                    <?php echo Functions::formatDateIndonesia($message['created_at']); ?>
                                </small>
                            </div>
                            
                            <p class="mb-2"><?php echo nl2br(htmlspecialchars($message['isi_pesan'])); ?></p>
                            
                            <?php if ($message['last_response']): ?>
                            <div class="mt-2 pt-2 border-top">
                                <div class="d-flex align-items-start">
                                    <div class="me-2 text-success">
                                        <i class="fas fa-reply"></i>
                                    </div>
                                    <div>
                                        <strong>Respons:</strong>
                                        <p class="mb-1"><?php echo nl2br(htmlspecialchars($message['last_response'])); ?></p>
                                        <small class="text-muted">
                                            <?php echo Functions::timeAgo($message['last_response_date']); ?>
                                            <?php if ($message['responder_nama']): ?>
                                            · oleh <?php echo htmlspecialchars($message['responder_nama']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mt-2 d-flex justify-content-between align-items-center">
                                <div>
                                    <?php if ($message['actual_response_hours']): ?>
                                    <small class="text-muted">
                                        <i class="fas fa-hourglass-half me-1"></i>
                                        Direspon dalam <?php echo number_format($message['actual_response_hours'], 1); ?> jam
                                    </small>
                                    <?php endif; ?>
                                </div>
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="viewMessageDetails(<?php echo $message['id']; ?>)">
                                    <i class="fas fa-eye me-1"></i>Detail
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Table View (Hidden by default) -->
            <div id="tableView" style="display: none;">
                <div class="table-responsive">
                    <table class="table table-hover table-history mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Pesan</th>
                                <th>Status</th>
                                <th>Prioritas</th>
                                <th>Respons</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($messages as $index => $message): ?>
                            <tr>
                                <td><?php echo $offset + $index + 1; ?></td>
                                <td>
                                    <div><?php echo date('d/m/Y', strtotime($message['created_at'])); ?></div>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($message['created_at'])); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($message['jenis_pesan']); ?></span>
                                </td>
                                <td>
                                    <div class="message-preview" title="<?php echo htmlspecialchars($message['isi_pesan']); ?>">
                                        <?php echo htmlspecialchars(substr($message['isi_pesan'], 0, 50)); ?>...
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo match($message['status']) {
                                        'Pending' => 'bg-warning',
                                        'Dibaca' => 'bg-info',
                                        'Diproses' => 'bg-primary',
                                        'Disetujui' => 'bg-success',
                                        'Ditolak' => 'bg-danger',
                                        'Selesai' => 'bg-secondary',
                                        default => 'bg-secondary'
                                    }; ?>">
                                        <?php echo $message['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo match($message['priority']) {
                                        'Urgent' => 'danger',
                                        'High' => 'warning',
                                        'Medium' => 'info',
                                        'Low' => 'success',
                                        default => 'secondary'
                                    }; ?>">
                                        <?php echo $message['priority']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($message['last_response']): ?>
                                    <span class="text-success">
                                        <i class="fas fa-check-circle"></i> <?php echo Functions::timeAgo($message['last_response_date']); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-warning">
                                        <i class="fas fa-clock"></i> Menunggu
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" 
                                            onclick="viewMessageDetails(<?php echo $message['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $startPage + 4);
                        $startPage = max(1, $endPage - 4);
                        
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Message Details Modal -->
    <div class="modal fade" id="messageDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-envelope-open-text me-2"></i>Detail Pesan
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="messageDetailsContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-2">Memuat detail pesan...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loading-overlay">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ============================================
// [KRITIS] FUNGSI GLOBAL - PALING ATAS
// ============================================
window.showLoading = function() {
    document.getElementById('loading-overlay').style.display = 'flex';
};

window.hideLoading = function() {
    document.getElementById('loading-overlay').style.display = 'none';
};

window.viewMessageDetails = function(messageId) {
    window.showLoading();
    
    // Fetch message details via AJAX
    fetch(`ajax/get_message_detail.php?message_id=${messageId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('messageDetailsContent').innerHTML = data.html;
                new bootstrap.Modal(document.getElementById('messageDetailsModal')).show();
            }
            window.hideLoading();
        })
        .catch(error => {
            console.error('Error:', error);
            window.hideLoading();
            alert('Gagal memuat detail pesan');
        });
};

window.toggleView = function() {
    const timeline = document.getElementById('timelineView');
    const tableView = document.getElementById('tableView');
    const btn = document.getElementById('viewToggleBtn');
    
    if (timeline.style.display !== 'none') {
        timeline.style.display = 'none';
        tableView.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-stream me-1"></i>Timeline View';
    } else {
        timeline.style.display = 'block';
        tableView.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-list me-1"></i>Table View';
    }
};

window.toggleDebugPanel = function() {
    const panel = document.getElementById('debug-panel');
    if (panel) {
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    }
};

window.toggleDebugContent = function() {
    const content = document.getElementById('debug-content');
    if (content) {
        content.style.display = content.style.display === 'none' ? 'block' : 'none';
    }
};

window.refreshPage = function() {
    location.reload();
};

// ============================================
// CHART INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($dailyStats)): ?>
    const ctx = document.getElementById('messageChart').getContext('2d');
    
    const dates = <?php echo json_encode(array_column($dailyStats, 'date')); ?>;
    const totals = <?php echo json_encode(array_column($dailyStats, 'total')); ?>;
    const completed = <?php echo json_encode(array_column($dailyStats, 'completed')); ?>;
    const pending = <?php echo json_encode(array_column($dailyStats, 'pending')); ?>;
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: dates.map(date => {
                const d = new Date(date);
                return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
            }),
            datasets: [
                {
                    label: 'Total Pesan',
                    data: totals,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Selesai',
                    data: completed,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Dalam Proses',
                    data: pending,
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    console.log('✅ History page loaded');
});
</script>

<?php require_once '../../includes/footer.php'; ?>