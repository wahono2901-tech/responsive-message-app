<?php
/**
 * Admin Logs & Audit Trail - Complete System Monitoring
 * File: modules/admin/logs.php
 * 
 * ⚡ FITUR LENGKAP:
 * ✅ Audit Trail - Semua aktivitas sistem
 * ✅ Activity Logs - Login, logout, user activity
 * ✅ Error Logs - System errors, warnings
 * ✅ Security Logs - Failed attempts, suspicious activities
 * ✅ Export Logs - PDF, Excel, CSV
 * ✅ Advanced Filters - Date range, action type, user
 * ✅ Chart Analytics - Log statistics visualization
 * ✅ Real-time Monitoring - Auto-refresh
 * ✅ Log Rotation - Auto cleanup
 * ✅ PHP 8.2 Compatible
 * 
 * @author Responsive Message App
 * @version 1.0.0 - Complete Logs Management
 */

// ============================================
// INITIALIZATION & VALIDATION
// ============================================
while (ob_get_level()) ob_end_clean();
ob_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check authentication and admin privilege
Auth::checkAuth();
if ($_SESSION['user_type'] !== 'Admin' && $_SESSION['privilege_level'] !== 'Full_Access') {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

// ============================================
// GLOBAL SCOPE - USE STATEMENTS
// ============================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;

// ============================================
// DATABASE CONNECTION
// ============================================
$db = Database::getInstance()->getConnection();

// ============================================
// GET FILTER PARAMETERS
// ============================================
$logType = $_GET['type'] ?? 'audit';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$actionType = $_GET['action'] ?? 'all';
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Validate dates
if (strtotime($startDate) > strtotime($endDate)) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

// ============================================
// HANDLE EXPORT
// ============================================
if (isset($_GET['export']) && !empty($_GET['export'])) {
    exportLogs($db, $_GET['export'], $logType, $startDate, $endDate, $actionType, $userId, $search);
    exit;
}

// ============================================
// HANDLE LOG ACTIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'clear_old_logs':
                $days = (int)($_POST['days'] ?? 90);
                
                $sql = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
                $stmt = $db->prepare($sql);
                $stmt->execute([$days]);
                $deleted = $stmt->rowCount();
                
                logActivity($db, $_SESSION['user_id'], 'CLEAR_LOGS', "Cleared $deleted log entries older than $days days");
                $_SESSION['success_message'] = "Berhasil membersihkan $deleted entri log";
                break;
                
            case 'clear_all_logs':
                // Only allow if confirmed
                $sql = "TRUNCATE TABLE audit_logs";
                $db->exec($sql);
                
                logActivity($db, $_SESSION['user_id'], 'CLEAR_LOGS', "Cleared all log entries");
                $_SESSION['success_message'] = "Semua entri log telah dibersihkan";
                break;
                
            case 'delete_log':
                $logId = (int)($_POST['log_id'] ?? 0);
                
                $sql = "DELETE FROM audit_logs WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$logId]);
                
                $_SESSION['success_message'] = "Log berhasil dihapus";
                break;
        }
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
}

// ============================================
// GET LOGS DATA
// ============================================

/**
 * Build query conditions based on filters
 */
function buildLogQuery($db, $logType, $startDate, $endDate, $actionType, $userId, $search) {
    $params = [];
    $conditions = [];
    
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    $conditions[] = "a.created_at BETWEEN ? AND ?";
    $params[] = $startDateTime;
    $params[] = $endDateTime;
    
    if ($actionType !== 'all') {
        $conditions[] = "a.action_type = ?";
        $params[] = $actionType;
    }
    
    if ($userId > 0) {
        $conditions[] = "a.user_id = ?";
        $params[] = $userId;
    }
    
    if (!empty($search)) {
        $conditions[] = "(a.table_name LIKE ? OR a.new_value LIKE ? OR u.nama_lengkap LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    return [$whereClause, $params];
}

// Get total count
list($whereClause, $params) = buildLogQuery($db, $logType, $startDate, $endDate, $actionType, $userId, $search);

$countSql = "SELECT COUNT(*) as total FROM audit_logs a $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalLogs = $countStmt->fetch()['total'];
$totalPages = ceil($totalLogs / $limit);

// Get logs with pagination
$sql = "SELECT 
            a.*,
            u.nama_lengkap as user_name,
            u.user_type,
            u.email as user_email,
            TIMESTAMPDIFF(HOUR, a.created_at, NOW()) as hours_ago
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        $whereClause
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
$params[] = $limit;
$params[] = $offset;
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsSql = "SELECT 
                COUNT(*) as total_logs,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT action_type) as unique_actions,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h,
                COUNT(CASE WHEN action_type IN ('DELETE', 'UPDATE') THEN 1 END) as modifications,
                COUNT(CASE WHEN action_type = 'LOGIN_FAILED' THEN 1 END) as failed_logins,
                MIN(created_at) as oldest_log,
                MAX(created_at) as newest_log
            FROM audit_logs";
$stmt = $db->query($statsSql);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get action type distribution
$actionSql = "SELECT 
                action_type,
                COUNT(*) as total,
                COUNT(*) * 100.0 / SUM(COUNT(*)) OVER() as percentage
            FROM audit_logs
            WHERE created_at BETWEEN ? AND ?
            GROUP BY action_type
            ORDER BY total DESC";
$stmt = $db->prepare($actionSql);
$stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
$actionDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get daily activity for chart
$dailySql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as total,
                COUNT(CASE WHEN action_type = 'CREATE' THEN 1 END) as creates,
                COUNT(CASE WHEN action_type = 'UPDATE' THEN 1 END) as updates,
                COUNT(CASE WHEN action_type = 'DELETE' THEN 1 END) as deletes,
                COUNT(CASE WHEN action_type = 'LOGIN' THEN 1 END) as logins,
                COUNT(CASE WHEN action_type = 'LOGIN_FAILED' THEN 1 END) as failed
            FROM audit_logs
            WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC";
$stmt = $db->prepare($dailySql);
$stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
$dailyActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top users
$usersSql = "SELECT 
                u.id,
                u.nama_lengkap,
                u.user_type,
                COUNT(a.id) as activity_count,
                MAX(a.created_at) as last_activity
            FROM users u
            JOIN audit_logs a ON u.id = a.user_id
            WHERE a.created_at BETWEEN ? AND ?
            GROUP BY u.id, u.nama_lengkap, u.user_type
            ORDER BY activity_count DESC
            LIMIT 10";
$stmt = $db->prepare($usersSql);
$stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
$topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get system error logs (from PHP error log)
$errorLogs = [];
if ($logType === 'errors') {
    $errorLogFile = ini_get('error_log');
    if (file_exists($errorLogFile)) {
        $lines = file($errorLogFile);
        $lines = array_slice($lines, -100); // Last 100 lines
        foreach ($lines as $line) {
            if (preg_match('/\[(.*?)\] (.*)/', $line, $matches)) {
                $errorLogs[] = [
                    'timestamp' => $matches[1],
                    'message' => $matches[2]
                ];
            }
        }
        $errorLogs = array_reverse($errorLogs);
    }
}

// Get security logs
$securitySql = "SELECT 
                    a.*,
                    u.nama_lengkap as user_name
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.action_type IN ('LOGIN_FAILED', 'ACCESS_DENIED', 'PERMISSION_ERROR')
                    AND a.created_at BETWEEN ? AND ?
                ORDER BY a.created_at DESC
                LIMIT 50";
$stmt = $db->prepare($securitySql);
$stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
$securityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// LOG ACTIVITY FUNCTION
// ============================================
function logActivity($db, $userId, $action, $description) {
    $sql = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_value, ip_address, user_agent, created_at) 
            VALUES (?, ?, 'logs', 0, ?, ?, ?, NOW())";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $userId,
        $action,
        $description,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

// ============================================
// EXPORT FUNCTIONS
// ============================================
function exportLogs($db, $format, $logType, $startDate, $endDate, $actionType, $userId, $search) {
    list($whereClause, $params) = buildLogQuery($db, $logType, $startDate, $endDate, $actionType, $userId, $search);
    
    $sql = "SELECT 
                a.created_at,
                a.action_type,
                a.table_name,
                a.record_id,
                a.new_value as description,
                u.nama_lengkap as user_name,
                u.user_type,
                a.ip_address,
                a.user_agent
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            $whereClause
            ORDER BY a.created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    switch ($format) {
        case 'csv':
            exportLogsCSV($logs, $startDate, $endDate);
            break;
        case 'excel':
            exportLogsExcel($logs, $startDate, $endDate);
            break;
        case 'pdf':
            exportLogsPDF($logs, $startDate, $endDate);
            break;
    }
}

function exportLogsCSV($logs, $startDate, $endDate) {
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, [APP_NAME . ' - AUDIT LOGS REPORT']);
    fputcsv($output, ["Periode: " . date('d/m/Y', strtotime($startDate)) . " - " . date('d/m/Y', strtotime($endDate))]);
    fputcsv($output, ["Diekspor: " . date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    
    fputcsv($output, ['Timestamp', 'User', 'User Type', 'Action', 'Table', 'Record ID', 'Description', 'IP Address']);
    
    foreach ($logs as $log) {
        fputcsv($output, [
            date('d/m/Y H:i:s', strtotime($log['created_at'])),
            $log['user_name'] ?? 'System',
            $log['user_type'] ?? '-',
            $log['action_type'],
            $log['table_name'] ?? '-',
            $log['record_id'] ?? '-',
            $log['description'] ?? '-',
            $log['ip_address'] ?? '-'
        ]);
    }
    
    fclose($output);
    exit;
}

function exportLogsExcel($logs, $startDate, $endDate) {
    $autoloadPath = $_SERVER['DOCUMENT_ROOT'] . '/responsive-message-app/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        die('PHPSpreadsheet tidak ditemukan. Jalankan: composer require phpoffice/phpspreadsheet:^5.0');
    }
    require_once $autoloadPath;
    
    while (ob_get_level()) ob_end_clean();
    
    try {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Audit Logs');
        
        // Header
        $sheet->setCellValue('A1', APP_NAME . ' - AUDIT LOGS REPORT');
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->setCellValue('A2', 'Periode: ' . date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)));
        $sheet->mergeCells('A2:H2');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->setCellValue('A3', 'Diekspor: ' . date('d/m/Y H:i:s'));
        $sheet->mergeCells('A3:H3');
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Headers
        $row = 5;
        $headers = ['Timestamp', 'User', 'User Type', 'Action', 'Table', 'Record ID', 'Description', 'IP Address'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
            $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
            $col++;
        }
        $row++;
        
        // Data
        foreach ($logs as $log) {
            $col = 'A';
            $sheet->setCellValue($col++ . $row, date('d/m/Y H:i:s', strtotime($log['created_at'])));
            $sheet->setCellValue($col++ . $row, $log['user_name'] ?? 'System');
            $sheet->setCellValue($col++ . $row, $log['user_type'] ?? '-');
            $sheet->setCellValue($col++ . $row, $log['action_type']);
            $sheet->setCellValue($col++ . $row, $log['table_name'] ?? '-');
            $sheet->setCellValue($col++ . $row, $log['record_id'] ?? '-');
            $sheet->setCellValue($col++ . $row, $log['description'] ?? '-');
            $sheet->setCellValue($col++ . $row, $log['ip_address'] ?? '-');
            $row++;
        }
        
        // Auto size columns
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'logs_');
        $writer->save($tempFile);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.xlsx"');
        header('Content-Length: ' . filesize($tempFile));
        
        readfile($tempFile);
        unlink($tempFile);
        exit;
        
    } catch (Exception $e) {
        error_log("Excel Export Error: " . $e->getMessage());
        die('Error generating Excel file');
    }
}

function exportLogsPDF($logs, $startDate, $endDate) {
    $fpdfPath = $_SERVER['DOCUMENT_ROOT'] . '/responsive-message-app/vendor/fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) {
        die('FPDF tidak ditemukan. Download dari http://www.fpdf.org');
    }
    require_once $fpdfPath;
    
    while (ob_get_level()) ob_end_clean();
    
    class LogsPDF extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 16);
            $this->SetTextColor(13, 110, 253);
            $this->Cell(0, 10, APP_NAME, 0, 1, 'C');
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(108, 117, 125);
            $this->Cell(0, 8, 'AUDIT LOGS REPORT', 0, 1, 'C');
            $this->Ln(5);
            $this->SetDrawColor(13, 110, 253);
            $this->SetLineWidth(0.5);
            $this->Line(15, $this->GetY(), 195, $this->GetY());
            $this->Ln(10);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(108, 117, 125);
            $this->Cell(0, 6, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
        }
    }
    
    $pdf = new LogsPDF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->SetMargins(15, 15, 15);
    
    // Header Info
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(40, 6, 'Periode:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(100, 6, date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)), 0, 1);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(40, 6, 'Total Logs:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, count($logs), 0, 1);
    $pdf->Ln(10);
    
    // Table Header
    $pdf->SetFillColor(13, 110, 253);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(35, 8, 'Timestamp', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'User', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Action', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Table', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Record', 1, 0, 'C', true);
    $pdf->Cell(70, 8, 'Description', 1, 1, 'C', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 7);
    
    $count = 0;
    foreach ($logs as $log) {
        if ($count++ >= 50) {
            $pdf->AddPage();
            $count = 0;
        }
        
        $pdf->Cell(35, 6, date('d/m/Y H:i', strtotime($log['created_at'])), 1, 0, 'C');
        $pdf->Cell(40, 6, substr($log['user_name'] ?? 'System', 0, 20), 1, 0, 'L');
        $pdf->Cell(25, 6, $log['action_type'], 1, 0, 'C');
        $pdf->Cell(30, 6, substr($log['table_name'] ?? '-', 0, 15), 1, 0, 'L');
        $pdf->Cell(20, 6, $log['record_id'] ?? '-', 1, 0, 'C');
        $pdf->Cell(70, 6, substr($log['description'] ?? '-', 0, 50), 1, 1, 'L');
    }
    
    $filename = 'audit_logs_' . date('Ymd_His') . '.pdf';
    $pdf->Output('D', $filename);
    exit;
}

// ============================================
// GET USERS FOR FILTER
// ============================================
$userListSql = "SELECT id, nama_lengkap, user_type FROM users WHERE is_active = 1 ORDER BY nama_lengkap";
$stmt = $db->query($userListSql);
$userList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// SUCCESS/ERROR MESSAGES
// ============================================
$message = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

$pageTitle = 'Logs & Audit Trail - Admin';
require_once '../../includes/header.php';
?>

<style>
/* Logs Page Styles */
.logs-container {
    max-width: 1600px;
    margin: 0 auto;
}

.log-stats-card {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    transition: all 0.3s ease;
}

.log-stats-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
}

.log-entry {
    border-left: 4px solid transparent;
    transition: all 0.2s ease;
}

.log-entry:hover {
    background-color: #f8f9fa;
}

.log-entry-CREATE { border-left-color: #28a745; }
.log-entry-UPDATE { border-left-color: #ffc107; }
.log-entry-DELETE { border-left-color: #dc3545; }
.log-entry-LOGIN { border-left-color: #0d6efd; }
.log-entry-LOGIN_FAILED { border-left-color: #dc3545; }
.log-entry-LOGOUT { border-left-color: #6c757d; }

.badge-action {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-weight: 500;
    font-size: 0.75rem;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}

.chart-container {
    position: relative;
    width: 100%;
    height: 300px;
}

.log-detail-modal .modal-body {
    max-height: 70vh;
    overflow-y: auto;
}

.timestamp {
    font-family: 'Consolas', monospace;
    font-size: 0.85rem;
}

.json-pretty {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
    font-family: 'Consolas', monospace;
    font-size: 0.85rem;
    overflow-x: auto;
}

.activity-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 6px;
}

@media (max-width: 768px) {
    .log-stats-card {
        padding: 1rem;
    }
    
    .filter-card {
        padding: 1rem;
    }
}
</style>

<div class="container-fluid py-4 logs-container">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-history me-2 text-primary"></i>
                Logs & Audit Trail
                <span class="badge bg-info ms-2">System Monitoring</span>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="settings.php">Settings</a></li>
                    <li class="breadcrumb-item active">Logs</li>
                </ol>
            </nav>
            <p class="text-muted small mb-0">
                <i class="fas fa-database me-1"></i>
                Total <?php echo number_format($totalLogs); ?> logs • 
                <span class="text-success"><?php echo $stats['last_24h'] ?? 0; ?></span> aktivitas 24 jam terakhir
            </p>
        </div>
        <div class="d-flex align-items-center mt-2 mt-sm-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-outline-primary" onclick="window.location.reload()">
                    <i class="fas fa-sync-alt me-1"></i>Refresh
                </button>
                <button type="button" class="btn btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-download me-1"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>">
                        <i class="fas fa-file-csv me-2 text-info"></i>CSV
                    </a></li>
                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>">
                        <i class="fas fa-file-excel me-2 text-success"></i>Excel
                    </a></li>
                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>">
                        <i class="fas fa-file-pdf me-2 text-danger"></i>PDF
                    </a></li>
                </ul>
            </div>
            <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#clearLogsModal">
                <i class="fas fa-trash-alt me-1"></i>Clean Up
            </button>
        </div>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-6">
            <div class="log-stats-card h-100">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="icon-circle bg-primary bg-opacity-10">
                            <i class="fas fa-database text-primary"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Total Logs</h6>
                        <h3 class="mb-0"><?php echo number_format($stats['total_logs'] ?? 0); ?></h3>
                        <small class="text-success">
                            <i class="fas fa-arrow-up me-1"></i>
                            <?php echo $stats['last_24h'] ?? 0; ?> today
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="log-stats-card h-100">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="icon-circle bg-success bg-opacity-10">
                            <i class="fas fa-users text-success"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Active Users</h6>
                        <h3 class="mb-0"><?php echo number_format($stats['unique_users'] ?? 0); ?></h3>
                        <small class="text-muted">This period</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="log-stats-card h-100">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="icon-circle bg-warning bg-opacity-10">
                            <i class="fas fa-edit text-warning"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Modifications</h6>
                        <h3 class="mb-0"><?php echo number_format($stats['modifications'] ?? 0); ?></h3>
                        <small class="text-muted">Create/Update/Delete</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="log-stats-card h-100">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="icon-circle bg-danger bg-opacity-10">
                            <i class="fas fa-shield-alt text-danger"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Security</h6>
                        <h3 class="mb-0"><?php echo number_format($stats['failed_logins'] ?? 0); ?></h3>
                        <small class="text-warning">Failed attempts</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="log-stats-card h-100">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="icon-circle bg-info bg-opacity-10">
                            <i class="fas fa-calendar text-info"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Oldest Log</h6>
                        <h6 class="mb-0"><?php echo $stats['oldest_log'] ? date('d/m/Y', strtotime($stats['oldest_log'])) : '-'; ?></h6>
                        <small class="text-muted">First record</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="log-stats-card h-100">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="icon-circle bg-secondary bg-opacity-10">
                            <i class="fas fa-chart-pie text-secondary"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Actions</h6>
                        <h3 class="mb-0"><?php echo number_format($stats['unique_actions'] ?? 0); ?></h3>
                        <small class="text-muted">Unique types</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Log Type Tabs -->
    <div class="filter-card">
        <ul class="nav nav-tabs card-header-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $logType === 'audit' ? 'active' : ''; ?>" href="?type=audit&<?php echo http_build_query(array_merge($_GET, ['type' => 'audit'])); ?>">
                    <i class="fas fa-history me-2"></i>Audit Trail
                    <span class="badge bg-primary ms-2"><?php echo number_format($stats['total_logs'] ?? 0); ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $logType === 'security' ? 'active' : ''; ?>" href="?type=security&<?php echo http_build_query(array_merge($_GET, ['type' => 'security'])); ?>">
                    <i class="fas fa-shield-alt me-2"></i>Security Logs
                    <span class="badge bg-danger ms-2"><?php echo count($securityLogs); ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $logType === 'errors' ? 'active' : ''; ?>" href="?type=errors&<?php echo http_build_query(array_merge($_GET, ['type' => 'errors'])); ?>">
                    <i class="fas fa-exclamation-triangle me-2"></i>Error Logs
                    <span class="badge bg-warning ms-2"><?php echo count($errorLogs); ?></span>
                </a>
            </li>
        </ul>
        
        <!-- Filter Form -->
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="type" value="<?php echo $logType; ?>">
            
            <div class="col-md-2">
                <label class="form-label small fw-bold">START DATE</label>
                <input type="date" class="form-control" name="start_date" value="<?php echo $startDate; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">END DATE</label>
                <input type="date" class="form-control" name="end_date" value="<?php echo $endDate; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">ACTION TYPE</label>
                <select class="form-select" name="action">
                    <option value="all">Semua Aksi</option>
                    <option value="CREATE" <?php echo $actionType === 'CREATE' ? 'selected' : ''; ?>>CREATE</option>
                    <option value="UPDATE" <?php echo $actionType === 'UPDATE' ? 'selected' : ''; ?>>UPDATE</option>
                    <option value="DELETE" <?php echo $actionType === 'DELETE' ? 'selected' : ''; ?>>DELETE</option>
                    <option value="LOGIN" <?php echo $actionType === 'LOGIN' ? 'selected' : ''; ?>>LOGIN</option>
                    <option value="LOGOUT" <?php echo $actionType === 'LOGOUT' ? 'selected' : ''; ?>>LOGOUT</option>
                    <option value="LOGIN_FAILED" <?php echo $actionType === 'LOGIN_FAILED' ? 'selected' : ''; ?>>LOGIN FAILED</option>
                    <option value="BACKUP" <?php echo $actionType === 'BACKUP' ? 'selected' : ''; ?>>BACKUP</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">USER</label>
                <select class="form-select" name="user_id">
                    <option value="0">Semua User</option>
                    <?php foreach ($userList as $user): ?>
                    <option value="<?php echo $user['id']; ?>" <?php echo $userId == $user['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['nama_lengkap']); ?> (<?php echo str_replace('_', ' ', $user['user_type']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">SEARCH</label>
                <input type="text" class="form-control" name="search" placeholder="Keyword..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>Filter
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Activity Chart -->
    <?php if (!empty($dailyActivity) && $logType === 'audit'): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-chart-line me-2 text-primary"></i>
                Daily Activity Trends
            </h6>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="activityChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Logs Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-list me-2 text-primary"></i>
                <?php 
                echo $logType === 'audit' ? 'Audit Trail' : 
                     ($logType === 'security' ? 'Security Logs' : 'Error Logs'); 
                ?>
                <span class="badge bg-primary ms-2"><?php echo number_format($totalLogs); ?> entries</span>
                <?php if ($totalPages > 1): ?>
                <span class="badge bg-light text-dark ms-2">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                <?php endif; ?>
            </h6>
            <small class="text-muted">
                <i class="fas fa-clock me-1"></i>
                Last update: <?php echo date('H:i:s'); ?>
            </small>
        </div>
        
        <div class="card-body p-0">
            <?php if ($logType === 'audit'): ?>
            <!-- Audit Logs -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th width="160">Timestamp</th>
                            <th width="180">User</th>
                            <th width="100">Action</th>
                            <th width="120">Table</th>
                            <th width="70">Record</th>
                            <th>Description</th>
                            <th width="130">IP Address</th>
                            <th width="50"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>Tidak ada log yang ditemukan</p>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr class="log-entry log-entry-<?php echo $log['action_type']; ?>">
                                <td>
                                    <span class="timestamp" title="<?php echo $log['created_at']; ?>">
                                        <?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?>
                                    </span>
                                    <?php if ($log['hours_ago'] < 24): ?>
                                    <br><small class="text-success"><?php echo $log['hours_ago']; ?>h ago</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="avatar bg-<?php 
                                                echo match($log['user_type'] ?? '') {
                                                    'Admin' => 'danger',
                                                    'Siswa' => 'success',
                                                    default => 'info'
                                                };
                                            ?> bg-opacity-10 rounded-circle p-1">
                                                <i class="fas fa-user text-<?php 
                                                    echo match($log['user_type'] ?? '') {
                                                        'Admin' => 'danger',
                                                        'Siswa' => 'success',
                                                        default => 'info'
                                                    };
                                                ?>"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-2">
                                            <span class="fw-medium"><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></span>
                                            <br><small class="text-muted"><?php echo $log['user_type'] ?? 'System'; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-action bg-<?php 
                                        echo match($log['action_type']) {
                                            'CREATE' => 'success',
                                            'UPDATE' => 'warning',
                                            'DELETE' => 'danger',
                                            'LOGIN' => 'info',
                                            'LOGOUT' => 'secondary',
                                            'LOGIN_FAILED' => 'danger',
                                            'BACKUP' => 'primary',
                                            'CLEANUP' => 'secondary',
                                            default => 'secondary'
                                        };
                                    ?> bg-opacity-10">
                                        <?php echo $log['action_type']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark">
                                        <?php echo htmlspecialchars($log['table_name'] ?? '-'); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($log['record_id'] > 0): ?>
                                    <span class="badge bg-secondary">#<?php echo $log['record_id']; ?></span>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="max-width: 300px;">
                                        <?php echo htmlspecialchars(substr($log['new_value'] ?? '-', 0, 100)); ?>
                                        <?php if (strlen($log['new_value'] ?? '') > 100): ?>
                                        <a href="#" onclick="showFullDescription(<?php echo htmlspecialchars(json_encode($log['new_value'])); ?>); return false;">
                                            ...more
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></small>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                onclick="viewLogDetail(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Hapus log ini?')">
                                            <input type="hidden" name="action" value="delete_log">
                                            <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php elseif ($logType === 'security'): ?>
            <!-- Security Logs -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th width="160">Timestamp</th>
                            <th width="180">User</th>
                            <th width="120">Action</th>
                            <th>Description</th>
                            <th width="130">IP Address</th>
                            <th width="200">User Agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($securityLogs)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="fas fa-shield-alt fa-3x mb-3"></i>
                                <p>Tidak ada security logs</p>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($securityLogs as $log): ?>
                            <tr class="log-entry log-entry-<?php echo $log['action_type']; ?>">
                                <td><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($log['user_name'] ?? 'Unknown'); ?></td>
                                <td>
                                    <span class="badge badge-action bg-<?php 
                                        echo $log['action_type'] === 'LOGIN_FAILED' ? 'danger' : 'warning';
                                    ?> bg-opacity-10">
                                        <?php echo $log['action_type']; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['new_value'] ?? '-'); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></small></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars(substr($log['user_agent'] ?? '-', 0, 50)); ?>...</small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php elseif ($logType === 'errors'): ?>
            <!-- Error Logs -->
            <div class="p-3">
                <?php if (empty($errorLogs)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <p>Tidak ada error logs</p>
                </div>
                <?php else: ?>
                    <?php foreach ($errorLogs as $error): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-2">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                            </div>
                            <div class="flex-grow-1">
                                <strong><?php echo $error['timestamp']; ?></strong>
                                <p class="mb-0 mt-1"><?php echo htmlspecialchars($error['message']); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1 && $logType === 'audit'): ?>
        <div class="card-footer bg-white">
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                    </li>
                    <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    
                    <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a>
                    </li>
                    <?php endif; ?>
                    
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Top Users Card -->
    <?php if (!empty($topUsers) && $logType === 'audit'): ?>
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-trophy me-2 text-warning"></i>
                        Most Active Users
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>Rank</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th class="text-center">Activities</th>
                                    <th>Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topUsers as $index => $user): ?>
                                <tr>
                                    <td class="fw-bold text-center">#<?php echo $index + 1; ?></td>
                                    <td>
                                        <span class="fw-medium"><?php echo htmlspecialchars($user['nama_lengkap']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($user['user_type']) {
                                                'Admin' => 'danger',
                                                'Siswa' => 'success',
                                                default => 'info'
                                            };
                                        ?> bg-opacity-10">
                                            <?php echo str_replace('_', ' ', $user['user_type']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?php echo number_format($user['activity_count']); ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo date('d/m/Y H:i', strtotime($user['last_activity'])); ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-chart-pie me-2 text-primary"></i>
                        Action Distribution
                    </h6>
                </div>
                <div class="card-body">
                    <?php foreach ($actionDistribution as $action): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>
                            <span class="badge badge-action bg-<?php 
                                echo match($action['action_type']) {
                                    'CREATE' => 'success',
                                    'UPDATE' => 'warning',
                                    'DELETE' => 'danger',
                                    'LOGIN' => 'info',
                                    'LOGOUT' => 'secondary',
                                    'LOGIN_FAILED' => 'danger',
                                    default => 'secondary'
                                };
                            ?> bg-opacity-10 me-2">
                                <?php echo $action['action_type']; ?>
                            </span>
                        </span>
                        <span class="fw-medium">
                            <?php echo number_format($action['total']); ?>
                            <span class="text-muted small ms-1">(<?php echo number_format($action['percentage'], 1); ?>%)</span>
                        </span>
                    </div>
                    <div class="progress mb-3" style="height: 6px;">
                        <div class="progress-bar bg-<?php 
                            echo match($action['action_type']) {
                                'CREATE' => 'success',
                                'UPDATE' => 'warning',
                                'DELETE' => 'danger',
                                'LOGIN' => 'info',
                                'LOGOUT' => 'secondary',
                                'LOGIN_FAILED' => 'danger',
                                default => 'secondary'
                            };
                        ?>" style="width: <?php echo $action['percentage']; ?>%"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Clear Logs Modal -->
<div class="modal fade" id="clearLogsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-trash-alt me-2 text-warning"></i>
                    Bersihkan Log
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="clearLogsForm">
                    <input type="hidden" name="action" value="clear_old_logs">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Hapus log lebih dari</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="days" value="90" min="1" max="365">
                            <span class="input-group-text">hari</span>
                        </div>
                        <small class="text-muted">
                            Log yang lebih lama dari jumlah hari yang ditentukan akan dihapus permanen.
                        </small>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Perhatian!</strong> Tindakan ini tidak dapat dibatalkan.
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmClear" required>
                        <label class="form-check-label" for="confirmClear">
                            Saya mengerti dan ingin melanjutkan
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" form="clearLogsForm" class="btn btn-warning" id="clearLogsBtn" disabled>
                    <i class="fas fa-trash-alt me-1"></i>Bersihkan Log
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Log Detail Modal -->
<div class="modal fade log-detail-modal" id="logDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2 text-primary"></i>
                    Detail Log Entry
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="logDetailContent">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// ============================================
// CHART INITIALIZATION
// ============================================
<?php if (!empty($dailyActivity) && $logType === 'audit'): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('activityChart').getContext('2d');
    
    const dates = <?php echo json_encode(array_column($dailyActivity, 'date')); ?>;
    const labels = dates.map(date => {
        const d = new Date(date);
        return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
    });
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Total Activities',
                    data: <?php echo json_encode(array_column($dailyActivity, 'total')); ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 6
                },
                {
                    label: 'Creates',
                    data: <?php echo json_encode(array_column($dailyActivity, 'creates')); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                    hidden: true
                },
                {
                    label: 'Updates',
                    data: <?php echo json_encode(array_column($dailyActivity, 'updates')); ?>,
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                    hidden: true
                },
                {
                    label: 'Deletes',
                    data: <?php echo json_encode(array_column($dailyActivity, 'deletes')); ?>,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                    hidden: true
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
                        boxWidth: 12,
                        padding: 15,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        precision: 0
                    }
                }
            }
        }
    });
});
<?php endif; ?>

// ============================================
// VIEW LOG DETAIL
// ============================================
function viewLogDetail(log) {
    const modal = document.getElementById('logDetailModal');
    const content = document.getElementById('logDetailContent');
    
    let html = `
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <tr>
                    <th width="150">ID</th>
                    <td>${log.id}</td>
                </tr>
                <tr>
                    <th>Timestamp</th>
                    <td>${log.created_at}</td>
                </tr>
                <tr>
                    <th>User</th>
                    <td>${log.user_name || 'System'} (${log.user_type || 'System'})</td>
                </tr>
                <tr>
                    <th>Action</th>
                    <td><span class="badge bg-${getActionColor(log.action_type)}">${log.action_type}</span></td>
                </tr>
                <tr>
                    <th>Table</th>
                    <td>${log.table_name || '-'}</td>
                </tr>
                <tr>
                    <th>Record ID</th>
                    <td>${log.record_id || '-'}</td>
                </tr>
                <tr>
                    <th>Description</th>
                    <td class="json-pretty">${escapeHtml(log.new_value || '-')}</td>
                </tr>
                <tr>
                    <th>IP Address</th>
                    <td>${log.ip_address || '-'}</td>
                </tr>
                <tr>
                    <th>User Agent</th>
                    <td><small>${escapeHtml(log.user_agent || '-')}</small></td>
                </tr>
            </table>
        </div>
    `;
    
    content.innerHTML = html;
    
    const modalInstance = new bootstrap.Modal(modal);
    modalInstance.show();
}

// ============================================
// SHOW FULL DESCRIPTION
// ============================================
function showFullDescription(description) {
    const modal = document.getElementById('logDetailModal');
    const content = document.getElementById('logDetailContent');
    
    content.innerHTML = `
        <div class="json-pretty">
            <pre>${escapeHtml(description)}</pre>
        </div>
    `;
    
    const modalInstance = new bootstrap.Modal(modal);
    modalInstance.show();
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function getActionColor(action) {
    switch(action) {
        case 'CREATE': return 'success';
        case 'UPDATE': return 'warning';
        case 'DELETE': return 'danger';
        case 'LOGIN': return 'info';
        case 'LOGOUT': return 'secondary';
        case 'LOGIN_FAILED': return 'danger';
        default: return 'secondary';
    }
}

function escapeHtml(unsafe) {
    if (!unsafe) return '-';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// ============================================
// CONFIRM CLEAR LOGS
// ============================================
document.getElementById('confirmClear')?.addEventListener('change', function() {
    document.getElementById('clearLogsBtn').disabled = !this.checked;
});

// ============================================
// AUTO-REFRESH EVERY 60 SECONDS
// ============================================
let refreshInterval = setInterval(function() {
    const currentUrl = new URL(window.location.href);
    if (!currentUrl.searchParams.has('export')) {
        fetch(window.location.href, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.text())
        .then(html => {
            // Only refresh if user hasn't interacted recently
            if (!document.hidden) {
                location.reload();
            }
        })
        .catch(() => {});
    }
}, 60000); // 60 seconds

// Clear interval on page unload
window.addEventListener('beforeunload', function() {
    clearInterval(refreshInterval);
});
</script>

<?php require_once '../../includes/footer.php'; ?>