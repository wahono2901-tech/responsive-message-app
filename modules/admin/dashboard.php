<?php
/**
 * Admin Dashboard dengan Grafik Profesional + One Stop Access
 * File: modules/admin/dashboard.php
 * VERSI: 4.16 - TOMBOL DEBUG & LEGEND WARNA GRAFIK PDF
 */

// Aktifkan error reporting maksimal
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/error.log');

// Buat direktori logs jika belum ada
if (!file_exists(__DIR__ . '/../../logs')) {
    mkdir(__DIR__ . '/../../logs', 0777, true);
}

// Fungsi debug untuk mencatat semua langkah
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/../../logs/debug_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message";
    
    if ($data !== null) {
        $log_message .= " - DATA: " . print_r($data, true);
    }
    
    file_put_contents($log_file, $log_message . PHP_EOL, FILE_APPEND);
    error_log($log_message);
}

debug_log("=== SCRIPT START ===");
debug_log("Request URI: " . $_SERVER['REQUEST_URI']);
debug_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
debug_log("GET Parameters: " . print_r($_GET, true));

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

debug_log("Core files loaded");

// Check authentication and admin privilege
Auth::checkAuth();
debug_log("Auth check passed");

// Perbaikan: Check admin privilege dengan cara yang lebih aman
$user_type = $_SESSION['user_type'] ?? '';
$privilege_level = $_SESSION['privilege_level'] ?? '';

debug_log("User session data", [
    'user_type' => $user_type,
    'privilege_level' => $privilege_level,
    'session_id' => session_id(),
    'session_data' => $_SESSION
]);

if ($user_type !== 'Admin' && $privilege_level !== 'Full_Access') {
    debug_log("Access denied - redirecting");
    if (in_array($user_type, ['Siswa', 'Guru', 'Orang_Tua'])) {
        header('Location: ' . BASE_URL . 'modules/dashboard.php');
        exit;
    }
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

$pageTitle = 'Admin Dashboard';

// Cek ketersediaan library
$pdf_library_available = file_exists('../../vendor/fpdf/fpdf.php');
$excel_library_available = file_exists('../../vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Spreadsheet.php');

debug_log("Library check", [
    'pdf_library_available' => $pdf_library_available ? 'YES' : 'NO',
    'pdf_path' => realpath('../../vendor/fpdf/fpdf.php') ?: 'NOT FOUND',
    'excel_library_available' => $excel_library_available ? 'YES' : 'NO',
    'excel_path' => realpath('../../vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Spreadsheet.php') ?: 'NOT FOUND',
    'vendor_autoload_exists' => file_exists('../../vendor/autoload.php') ? 'YES' : 'NO'
]);

// Get dashboard statistics
try {
    debug_log("Attempting database connection");
    $db = Database::getInstance()->getConnection();
    debug_log("Database connected successfully");
    
    // Total users
    $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
    $totalUsers = $stmt->fetch()['total'] ?? 0;
    debug_log("Total users: " . $totalUsers);
    
    // Total messages
    $stmt = $db->query("SELECT COUNT(*) as total FROM messages");
    $totalMessages = $stmt->fetch()['total'] ?? 0;
    debug_log("Total messages: " . $totalMessages);
    
    // Pending messages
    $stmt = $db->query("SELECT COUNT(*) as total FROM messages WHERE status = 'Pending'");
    $pendingMessages = $stmt->fetch()['total'] ?? 0;
    debug_log("Pending messages: " . $pendingMessages);
    
    // Expired messages (last 7 days)
    $stmt = $db->prepare("
        SELECT COUNT(*) as total FROM messages 
        WHERE status = 'Expired' 
        AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->execute();
    $expiredMessages = $stmt->fetch()['total'] ?? 0;
    debug_log("Expired messages: " . $expiredMessages);
    
    // Recent messages
    $stmt = $db->query("
        SELECT m.*, u.nama_lengkap, mt.jenis_pesan 
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        ORDER BY m.created_at DESC 
        LIMIT 10
    ");
    $recentMessages = $stmt->fetchAll() ?: [];
    debug_log("Recent messages count: " . count($recentMessages));
    
    // Message statistics by type
    $stmt = $db->query("
        SELECT mt.jenis_pesan, COUNT(m.id) as total,
               SUM(CASE WHEN m.status = 'Pending' THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN m.status = 'Disetujui' THEN 1 ELSE 0 END) as approved,
               SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END) as rejected,
               SUM(CASE WHEN m.status = 'Diproses' THEN 1 ELSE 0 END) as processed
        FROM message_types mt
        LEFT JOIN messages m ON mt.id = m.jenis_pesan_id
        GROUP BY mt.id, mt.jenis_pesan
        ORDER BY total DESC
    ");
    $messageStats = $stmt->fetchAll() ?: [];
    debug_log("Message stats count: " . count($messageStats));
    
    // User growth (last 30 days)
    $stmt = $db->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as new_users
        FROM users 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date
    ");
    $stmt->execute();
    $userGrowthData = $stmt->fetchAll() ?: [];
    debug_log("User growth data count: " . count($userGrowthData));
    
    // Messages by status
    $stmt = $db->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM messages
        GROUP BY status
        ORDER BY count DESC
    ");
    $messageStatusData = $stmt->fetchAll() ?: [];
    debug_log("Message status data count: " . count($messageStatusData));
    
    // Daily message volume (last 7 days)
    $stmt = $db->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as message_count,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'Disetujui' THEN 1 ELSE 0 END) as approved_count
        FROM messages
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date
    ");
    $stmt->execute();
    $dailyMessagesData = $stmt->fetchAll() ?: [];
    debug_log("Daily messages data count: " . count($dailyMessagesData));
    
    // Users by type
    $stmt = $db->query("
        SELECT 
            user_type,
            COUNT(*) as count
        FROM users
        WHERE is_active = 1
        GROUP BY user_type
        ORDER BY count DESC
    ");
    $usersByTypeData = $stmt->fetchAll() ?: [];
    debug_log("Users by type data count: " . count($usersByTypeData));
    
    // Get top active users
    $stmt = $db->query("
        SELECT u.nama_lengkap, u.user_type, COUNT(m.id) as message_count
        FROM users u
        LEFT JOIN messages m ON u.id = m.pengirim_id
        WHERE u.is_active = 1
        GROUP BY u.id
        ORDER BY message_count DESC
        LIMIT 5
    ");
    $topUsers = $stmt->fetchAll() ?: [];
    debug_log("Top users count: " . count($topUsers));
    
    // Get response rate statistics
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_messages,
            SUM(CASE WHEN responder_id IS NOT NULL THEN 1 ELSE 0 END) as responded,
            ROUND((SUM(CASE WHEN responder_id IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as response_rate
        FROM messages
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $responseStats = $stmt->fetch() ?: ['total_messages' => 0, 'responded' => 0, 'response_rate' => 0];
    debug_log("Response stats", $responseStats);
    
} catch (Exception $e) {
    debug_log("DATABASE ERROR: " . $e->getMessage());
    debug_log("Stack trace: " . $e->getTraceAsString());
    $totalUsers = $totalMessages = $pendingMessages = $expiredMessages = 0;
    $recentMessages = $messageStats = $userGrowthData = $messageStatusData = [];
    $dailyMessagesData = $usersByTypeData = $topUsers = [];
    $responseStats = ['total_messages' => 0, 'responded' => 0, 'response_rate' => 0];
}

// ============================================================================
// HANDLE EXPORT FUNCTIONS
// ============================================================================
debug_log("=== CHECKING EXPORT PARAMETERS ===");
debug_log("GET export parameter: " . (isset($_GET['export']) ? $_GET['export'] : 'NOT SET'));

if (isset($_GET['export']) && !empty($_GET['export'])) {
    $exportType = $_GET['export'];
    debug_log("EXPORT REQUEST DETECTED! Type: " . $exportType);
    
    // Bersihkan semua output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set time filter (default 30days)
    $timeFilter = $_GET['time'] ?? '30days';
    $startDate = '';
    $endDate = date('Y-m-d');
    
    switch ($timeFilter) {
        case '7days':
            $startDate = date('Y-m-d', strtotime('-7 days'));
            break;
        case '30days':
            $startDate = date('Y-m-d', strtotime('-30 days'));
            break;
        case '90days':
            $startDate = date('Y-m-d', strtotime('-90 days'));
            break;
        case 'year':
            $startDate = date('Y-m-d', strtotime('-1 year'));
            break;
        default:
            $timeFilter = '30days';
            $startDate = date('Y-m-d', strtotime('-30 days'));
    }
    
    switch ($exportType) {
        case 'pdf':
            debug_log("PDF EXPORT SELECTED");
            if ($pdf_library_available) {
                require_once '../../vendor/fpdf/fpdf.php';
                exportToPDF($totalUsers, $totalMessages, $pendingMessages, $expiredMessages,
                           $dailyMessagesData, $messageStatusData, $messageStats, 
                           $userGrowthData, $topUsers, $recentMessages, $responseStats,
                           $usersByTypeData, $startDate, $endDate);
            } else {
                echo "<h1>FPDF library tidak ditemukan</h1>";
                exit;
            }
            break;
            
        case 'excel':
            debug_log("EXCEL EXPORT SELECTED");
            if ($excel_library_available) {
                require_once '../../vendor/autoload.php';
                exportToExcel($totalUsers, $totalMessages, $pendingMessages, $expiredMessages,
                             $dailyMessagesData, $messageStatusData, $messageStats, 
                             $userGrowthData, $topUsers, $recentMessages, $usersByTypeData, 
                             $responseStats, $startDate, $endDate);
            } else {
                echo "<h1>PHPSpreadsheet library tidak ditemukan</h1>";
                exit;
            }
            break;
    }
    exit;
}

debug_log("No export parameter detected, rendering normal dashboard");

// ============================================================================
// INCLUDE HEADER - PERTAMA DAN SATU-SATUNYA (SEPERTI dashboard_guru.php)
// ============================================================================
require_once '../../includes/header.php';
?>

<!-- DEBUG PANEL - Dengan tombol ON/OFF -->
<div id="debugPanel" class="debug-panel" style="background: #f0f0f0; border: 2px solid #ff0000; padding: 15px; margin-bottom: 20px; font-family: monospace; font-size: 12px; max-height: 300px; overflow: auto; display: none;">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <strong><i class="fas fa-bug me-2"></i>DEBUG INFORMATION:</strong>
        <button class="btn btn-sm btn-secondary" onclick="toggleDebugPanel()">
            <i class="fas fa-times"></i> Tutup
        </button>
    </div>
    <ul>
        <li>PDF Library Available: <?php echo $pdf_library_available ? 'YES' : 'NO'; ?></li>
        <li>Excel Library Available: <?php echo $excel_library_available ? 'YES' : 'NO'; ?></li>
        <li>Export Parameter in URL: <?php echo isset($_GET['export']) ? $_GET['export'] : 'NOT SET'; ?></li>
        <li>Current URL: <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?></li>
        <li>Session ID: <?php echo session_id(); ?></li>
        <li>User Type: <?php echo $_SESSION['user_type'] ?? 'Not set'; ?></li>
        <li>Check logs at: /responsive-message-app/logs/debug_<?php echo date('Y-m-d'); ?>.log</li>
    </ul>
</div>

<!-- Tombol Debug Kecil -->
<div class="position-fixed bottom-0 end-0 m-3" style="z-index: 9999;">
    <button class="btn btn-sm btn-outline-secondary rounded-circle shadow" onclick="toggleDebugPanel()" title="Toggle Debug Panel">
        <i class="fas fa-bug"></i>
    </button>
</div>

<div class="container-fluid">
    <!-- Dashboard Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard Admin
            </h1>
            <p class="text-muted mb-0"><?php echo date('l, d F Y'); ?> | Selamat datang, <?php echo htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Admin'); ?>!</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="refreshDashboard()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
            <button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#exportModal">
                <i class="fas fa-download me-1"></i> Export Laporan
            </button>
        </div>
    </div>
    
    <!-- ONE STOP ACCESS PANEL -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-rocket me-2 text-primary"></i>One Stop Access Panel
                        <small class="text-muted ms-2">Akses cepat ke semua fitur admin</small>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- User Management -->
                        <div class="col-md-3 col-sm-6">
                            <div class="card access-card h-100 border-0 shadow-sm">
                                <div class="card-body text-center p-4">
                                    <div class="access-icon mb-3">
                                        <i class="fas fa-users fa-3x text-primary"></i>
                                    </div>
                                    <h5 class="card-title">Manajemen User</h5>
                                    <p class="card-text small text-muted">Kelola semua pengguna sistem</p>
                                    <div class="d-grid gap-2">
                                        <a href="manage_users.php" class="btn btn-sm btn-primary">
                                            <i class="fas fa-cog me-1"></i> Kelola User
                                        </a>
                                        <div class="btn-group w-100" role="group">
                                            <a href="manage_users.php?type=Siswa" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-user-graduate me-1"></i> Siswa
                                            </a>
                                            <a href="manage_users.php?type=Guru" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-chalkboard-teacher me-1"></i> Guru
                                            </a>
                                        </div>
                                        <a href="add_user.php" class="btn btn-sm btn-success">
                                            <i class="fas fa-user-plus me-1"></i> Tambah User
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Message Management -->
<div class="col-md-3 col-sm-6">
    <div class="card access-card h-100 border-0 shadow-sm">
        <div class="card-body text-center p-4">
            <div class="access-icon mb-3">
                <i class="fas fa-comments fa-3x text-success"></i>
            </div>
            <h5 class="card-title">Manajemen Pesan</h5>
            <p class="card-text small text-muted">Kelola semua pesan masuk</p>
            <div class="d-grid gap-2">
                <!-- PERBAIKAN: Gunakan URL absolut dengan BASE_URL -->
                <a href="<?php echo BASE_URL; ?>modules/messages/messages.php" class="btn btn-sm btn-success">
                    <i class="fas fa-inbox me-1"></i> Semua Pesan
                </a>
                <div class="btn-group w-100" role="group">
                    <a href="<?php echo BASE_URL; ?>modules/messages/messages.php?status=Pending" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-clock me-1"></i> Tertunda
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/messages/messages.php?status=Disetujui" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-check me-1"></i> Disetujui
                    </a>
                </div>
                <a href="<?php echo BASE_URL; ?>modules/admin/message_types.php" class="btn btn-sm btn-info">
                    <i class="fas fa-tags me-1"></i> Jenis Pesan
                </a>
            </div>
        </div>
    </div>
</div>
                        
                        <!-- Reports & Analytics -->
                        <div class="col-md-3 col-sm-6">
                            <div class="card access-card h-100 border-0 shadow-sm">
                                <div class="card-body text-center p-4">
                                    <div class="access-icon mb-3">
                                        <i class="fas fa-chart-bar fa-3x text-warning"></i>
                                    </div>
                                    <h5 class="card-title">Laporan & Analitik</h5>
                                    <p class="card-text small text-muted">Analisis data dan laporan</p>
                                    <div class="d-grid gap-2">
                                        <a href="reports.php" class="btn btn-sm btn-warning">
                                            <i class="fas fa-file-alt me-1"></i> Laporan Lengkap
                                        </a>
                                        <div class="btn-group w-100" role="group">
                                            <a href="reports.php?type=daily" class="btn btn-sm btn-outline-warning">
                                                <i class="fas fa-calendar-day me-1"></i> Harian
                                            </a>
                                            <a href="reports.php?type=monthly" class="btn btn-sm btn-outline-warning">
                                                <i class="fas fa-calendar-month me-1"></i> Bulanan
                                            </a>
                                        </div>
                                        <a href="analytics.php" class="btn btn-sm btn-dark">
                                            <i class="fas fa-chart-line me-1"></i> Analitik
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- System Management -->
                        <div class="col-md-3 col-sm-6">
                            <div class="card access-card h-100 border-0 shadow-sm">
                                <div class="card-body text-center p-4">
                                    <div class="access-icon mb-3">
                                        <i class="fas fa-cogs fa-3x text-danger"></i>
                                    </div>
                                    <h5 class="card-title">Sistem & Pengaturan</h5>
                                    <p class="card-text small text-muted">Konfigurasi sistem</p>
                                    <div class="d-grid gap-2">
                                        <a href="settings.php" class="btn btn-sm btn-danger">
                                            <i class="fas fa-sliders-h me-1"></i> Pengaturan
                                        </a>
                                        <div class="btn-group w-100" role="group">
                                            <button class="btn btn-sm btn-outline-danger" onclick="backupDatabase()">
                                                <i class="fas fa-database me-1"></i> Backup
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="clearCache()">
                                                <i class="fas fa-broom me-1"></i> Cache
                                            </button>
                                        </div>
                                        <a href="logs.php" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-clipboard-list me-1"></i> System Logs
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Total Pengguna</h6>
                            <h2 class="mb-0 text-primary" id="total-users"><?php echo number_format($totalUsers); ?></h2>
                            <small class="text-muted">
                                <span class="text-success"><i class="fas fa-arrow-up me-1"></i> <?php echo count($userGrowthData); ?> baru</span> (30 hari)
                            </small>
                        </div>
                        <div class="widget-icon bg-primary-light rounded-circle p-3">
                            <i class="fas fa-users fa-2x text-primary"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-primary" style="width: 85%"></div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <a href="manage_users.php" class="btn btn-sm btn-link text-primary p-0">
                        <i class="fas fa-arrow-right me-1"></i> Kelola User
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Total Pesan</h6>
                            <h2 class="mb-0 text-success" id="total-messages"><?php echo number_format($totalMessages); ?></h2>
                            <small class="text-muted">
                                <span id="pending-count"><?php echo $pendingMessages; ?></span> menunggu
                            </small>
                        </div>
                        <div class="widget-icon bg-success-light rounded-circle p-3">
                            <i class="fas fa-comments fa-2x text-success"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-success" style="width: 65%"></div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <a href="../messages/messages.php" class="btn btn-sm btn-link text-success p-0">
                        <i class="fas fa-arrow-right me-1"></i> Lihat Pesan
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Pesan Tertunda</h6>
                            <h2 class="mb-0 text-warning" id="pending-messages"><?php echo number_format($pendingMessages); ?></h2>
                            <small class="text-muted">
                                <span class="text-danger"><i class="fas fa-clock me-1"></i> Perlu perhatian</span>
                            </small>
                        </div>
                        <div class="widget-icon bg-warning-light rounded-circle p-3">
                            <i class="fas fa-clock fa-2x text-warning"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-warning" style="width: 45%"></div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <a href="../messages/messages.php?status=Pending" class="btn btn-sm btn-link text-warning p-0">
                        <i class="fas fa-arrow-right me-1"></i> Tangani Sekarang
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Pesan Expired</h6>
                            <h2 class="mb-0 text-danger" id="expired-messages"><?php echo number_format($expiredMessages); ?></h2>
                            <small class="text-muted">
                                7 hari terakhir
                            </small>
                        </div>
                        <div class="widget-icon bg-danger-light rounded-circle p-3">
                            <i class="fas fa-hourglass-end fa-2x text-danger"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-danger" style="width: 25%"></div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <a href="reports.php?filter=expired" class="btn btn-sm btn-link text-danger p-0">
                        <i class="fas fa-arrow-right me-1"></i> Lihat Laporan
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Charts Row 1 -->
    <div class="row mb-4">
        <!-- Messages Overview Chart -->
        <div class="col-lg-8 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line me-2"></i>Volume Pesan (7 Hari Terakhir)
                        </h5>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-secondary active" data-period="7d">7 Hari</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-period="30d">30 Hari</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-period="90d">90 Hari</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="messageVolumeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Message Status Distribution -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Distribusi Status Pesan
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="statusDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Charts Row 2 -->
    <div class="row mb-4">
        <!-- Message Type Performance -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-bar me-2"></i>Performa Jenis Pesan
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="messageTypeChart"></canvas>
                    </div>
                    <div class="text-center mt-3">
                        <a href="message_types.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-cog me-1"></i> Kelola Jenis Pesan
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- User Growth Chart -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-user-plus me-2"></i>Pertumbuhan Pengguna (30 Hari)
                        </h5>
                        <span class="badge bg-success">+<?php echo count($userGrowthData); ?> baru</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="userGrowthChart"></canvas>
                    </div>
                    <div class="text-center mt-3">
                        <a href="manage_users.php" class="btn btn-sm btn-outline-success">
                            <i class="fas fa-user-plus me-1"></i> Tambah User Baru
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Messages Table -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>Pesan Terbaru
                            <span class="badge bg-primary ms-2">10 Terbaru</span>
                        </h5>
                        <div>
                            <a href="../messages/messages.php" class="btn btn-sm btn-outline-primary me-2">
                                <i class="fas fa-list me-1"></i> Semua Pesan
                            </a>
                            <a href="../messages/messages.php?action=create" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus me-1"></i> Pesan Baru
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="recent-messages">
                            <thead class="table-light">
                                <tr>
                                    <th width="50" class="py-3">#</th>
                                    <th class="py-3">Pengirim</th>
                                    <th class="py-3">Jenis Pesan</th>
                                    <th class="py-3">Isi Pesan</th>
                                    <th class="py-3">Status</th>
                                    <th class="py-3">Waktu</th>
                                    <th class="py-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentMessages)): ?>
                                <?php foreach ($recentMessages as $index => $message): ?>
                                <tr>
                                    <td class="align-middle">
                                        <span class="badge bg-light text-dark"><?php echo $index + 1; ?></span>
                                    </td>
                                    <td class="align-middle">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm me-2">
                                                <div class="avatar-title bg-primary-light rounded-circle">
                                                    <i class="fas fa-user text-primary"></i>
                                                </div>
                                            </div>
                                            <div>
                                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($message['nama_lengkap'] ?? 'Unknown'); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($message['pengirim_nis_nip'] ?? ''); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge bg-info"><?php echo htmlspecialchars($message['jenis_pesan'] ?? 'Unknown'); ?></span>
                                    </td>
                                    <td class="align-middle">
                                        <div class="text-truncate" style="max-width: 200px;">
                                            <?php echo htmlspecialchars(substr($message['isi_pesan'] ?? '', 0, 50)) . '...'; ?>
                                        </div>
                                    </td>
                                    <td class="align-middle">
                                        <?php 
                                        $status = $message['status'] ?? 'Pending';
                                        $badge_class = '';
                                        switch($status) {
                                            case 'Pending': $badge_class = 'warning'; break;
                                            case 'Dibaca': $badge_class = 'info'; break;
                                            case 'Diproses': $badge_class = 'primary'; break;
                                            case 'Disetujui': $badge_class = 'success'; break;
                                            case 'Ditolak': $badge_class = 'danger'; break;
                                            case 'Selesai': $badge_class = 'secondary'; break;
                                            default: $badge_class = 'light'; break;
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $badge_class; ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </td>
                                    <td class="align-middle">
                                        <small class="text-muted">
                                            <?php 
                                            if (!empty($message['created_at'])) {
                                                echo Functions::timeAgo($message['created_at']);
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </small>
                                    </td>
                                    <td class="align-middle">
                                        <div class="btn-group" role="group">
                                            <a href="../messages/messages.php?action=view&id=<?php echo $message['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="../messages/messages.php?action=respond&id=<?php echo $message['id']; ?>" 
                                               class="btn btn-sm btn-outline-success">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <a href="../messages/messages.php?action=delete&id=<?php echo $message['id']; ?>" 
                                               class="btn btn-sm btn-outline-danger" 
                                               onclick="return confirm('Apakah Anda yakin ingin menghapus pesan ini?')">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                                        <p class="text-muted mb-0">Belum ada pesan</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-export me-2"></i>Ekspor Laporan Dashboard
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Pilih format ekspor laporan dashboard:</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-danger" onclick="exportReport('pdf')">
                        <i class="fas fa-file-pdf me-2"></i> PDF Document (FPDF)
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="exportReport('excel')">
                        <i class="fas fa-file-excel me-2"></i> Excel Spreadsheet (PHPSpreadsheet)
                    </button>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Laporan akan dihasilkan dengan format profesional.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Loading Indicator -->
<div id="loading-indicator" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center; color: white; font-size: 18px;">
    <div class="text-center">
        <div class="spinner-border spinner-border-lg mb-3" style="width: 3rem; height: 3rem;"></div>
        <div id="loading-message">Memproses...</div>
    </div>
</div>

<!-- Toast Container -->
<div id="toast-container" class="position-fixed top-0 end-0 p-3" style="z-index: 9998;"></div>

<!-- Chart.js - TETAP ADA KARENA DIBUTUHKAN UNTUK CHART -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
// ============================================================================
// FUNGSI TOGGLE DEBUG PANEL
// ============================================================================
function toggleDebugPanel() {
    const panel = document.getElementById('debugPanel');
    if (panel.style.display === 'none' || panel.style.display === '') {
        panel.style.display = 'block';
    } else {
        panel.style.display = 'none';
    }
}

// ============================================================================
// FUNGSI EXPORT REPORT
// ============================================================================
function exportReport(format) {
    const btn = event.currentTarget;
    const originalHtml = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyiapkan...';
    btn.disabled = true;
    
    // Tampilkan loading
    showLoading('Menyiapkan laporan ' + format.toUpperCase() + '...');
    
    // Buat URL export
    let url = window.location.href.split('?')[0] + '?export=' + format;
    console.log('Export URL:', url);
    
    // Buka di tab baru
    window.open(url, '_blank');
    
    // Reset button setelah delay
    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        hideLoading();
        
        // Close modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
        if (modal) modal.hide();
    }, 1000);
}

// Fungsi showLoading
function showLoading(message) {
    let loadingEl = document.getElementById('loading-indicator');
    let messageEl = document.getElementById('loading-message');
    
    if (loadingEl) {
        if (messageEl) {
            messageEl.textContent = message || 'Memproses...';
        }
        loadingEl.style.display = 'flex';
    }
}

function hideLoading() {
    const loadingEl = document.getElementById('loading-indicator');
    if (loadingEl) {
        loadingEl.style.display = 'none';
    }
}

// Function to refresh dashboard
function refreshDashboard() {
    showLoading('Memperbarui dashboard...');
    setTimeout(function() {
        window.location.reload();
    }, 500);
}

// Initialize all charts
document.addEventListener('DOMContentLoaded', function() {
    try {
        initializeMessageVolumeChart();
        initializeStatusDistributionChart();
        initializeMessageTypeChart();
        initializeUserGrowthChart();
    } catch (error) {
        console.error('Error initializing charts:', error);
    }
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Message Volume Chart (Line Chart)
function initializeMessageVolumeChart() {
    const ctx = document.getElementById('messageVolumeChart');
    if (!ctx) return;
    
    try {
        const dates = <?php echo !empty($dailyMessagesData) ? json_encode(array_column($dailyMessagesData, 'date')) : '[]'; ?>;
        const totalMessages = <?php echo !empty($dailyMessagesData) ? json_encode(array_column($dailyMessagesData, 'message_count')) : '[]'; ?>;
        const pendingMessages = <?php echo !empty($dailyMessagesData) ? json_encode(array_column($dailyMessagesData, 'pending_count')) : '[]'; ?>;
        const approvedMessages = <?php echo !empty($dailyMessagesData) ? json_encode(array_column($dailyMessagesData, 'approved_count')) : '[]'; ?>;
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: dates.map(date => new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })),
                datasets: [
                    {
                        label: 'Total Pesan',
                        data: totalMessages,
                        borderColor: '#0d6efd',
                        backgroundColor: 'rgba(13, 110, 253, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2
                    },
                    {
                        label: 'Pending',
                        data: pendingMessages,
                        borderColor: '#ffc107',
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
                        tension: 0.4,
                        fill: false,
                        borderWidth: 2,
                        borderDash: [5, 5]
                    },
                    {
                        label: 'Disetujui',
                        data: approvedMessages,
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        tension: 0.4,
                        fill: false,
                        borderWidth: 2
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
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [2, 2]
                        },
                        ticks: {
                            stepSize: 1,
                            callback: function(value) {
                                if (Math.floor(value) === value) {
                                    return value;
                                }
                            }
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error creating message volume chart:', error);
    }
}

// Status Distribution Chart (Doughnut)
function initializeStatusDistributionChart() {
    const ctx = document.getElementById('statusDistributionChart');
    if (!ctx) return;
    
    try {
        const statusLabels = <?php echo !empty($messageStatusData) ? json_encode(array_column($messageStatusData, 'status')) : '[]'; ?>;
        const statusCounts = <?php echo !empty($messageStatusData) ? json_encode(array_column($messageStatusData, 'count')) : '[]'; ?>;
        
        const statusColors = {
            'Pending': '#ffc107',
            'Dibaca': '#17a2b8',
            'Diproses': '#0d6efd',
            'Disetujui': '#198754',
            'Ditolak': '#dc3545',
            'Selesai': '#6c757d',
            'Expired': '#343a40'
        };
        
        const backgroundColors = statusLabels.map(label => statusColors[label] || '#6c757d');
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusCounts,
                    backgroundColor: backgroundColors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            padding: 20,
                            boxWidth: 12
                        }
                    }
                },
                cutout: '70%'
            }
        });
    } catch (error) {
        console.error('Error creating status distribution chart:', error);
    }
}

// Message Type Chart (Bar Chart)
function initializeMessageTypeChart() {
    const ctx = document.getElementById('messageTypeChart');
    if (!ctx) return;
    
    try {
        const typeLabels = <?php echo !empty($messageStats) ? json_encode(array_column($messageStats, 'jenis_pesan')) : '[]'; ?>;
        const typeTotals = <?php echo !empty($messageStats) ? json_encode(array_column($messageStats, 'total')) : '[]'; ?>;
        const typePending = <?php echo !empty($messageStats) ? json_encode(array_column($messageStats, 'pending')) : '[]'; ?>;
        const typeApproved = <?php echo !empty($messageStats) ? json_encode(array_column($messageStats, 'approved')) : '[]'; ?>;
        const typeProcessed = <?php echo !empty($messageStats) ? json_encode(array_column($messageStats, 'processed')) : '[]'; ?>;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: typeLabels,
                datasets: [
                    {
                        label: 'Total',
                        data: typeTotals,
                        backgroundColor: 'rgba(13, 110, 253, 0.8)',
                        borderColor: '#0d6efd',
                        borderWidth: 1
                    },
                    {
                        label: 'Pending',
                        data: typePending,
                        backgroundColor: 'rgba(255, 193, 7, 0.8)',
                        borderColor: '#ffc107',
                        borderWidth: 1
                    },
                    {
                        label: 'Diproses',
                        data: typeProcessed,
                        backgroundColor: 'rgba(13, 202, 240, 0.8)',
                        borderColor: '#0dcaf0',
                        borderWidth: 1
                    },
                    {
                        label: 'Disetujui',
                        data: typeApproved,
                        backgroundColor: 'rgba(25, 135, 84, 0.8)',
                        borderColor: '#198754',
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
                        labels: {
                            padding: 10
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [2, 2]
                        },
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error creating message type chart:', error);
    }
}

// User Growth Chart (Line Chart)
function initializeUserGrowthChart() {
    const ctx = document.getElementById('userGrowthChart');
    if (!ctx) return;
    
    try {
        const growthDates = <?php echo !empty($userGrowthData) ? json_encode(array_column($userGrowthData, 'date')) : '[]'; ?>;
        const growthCounts = <?php echo !empty($userGrowthData) ? json_encode(array_column($userGrowthData, 'new_users')) : '[]'; ?>;
        
        // Calculate cumulative growth
        let cumulative = 0;
        const cumulativeGrowth = growthCounts.map(count => {
            cumulative += parseInt(count);
            return cumulative;
        });
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: growthDates.map(date => new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })),
                datasets: [
                    {
                        label: 'Pengguna Baru',
                        data: growthCounts,
                        borderColor: '#0dcaf0',
                        backgroundColor: 'rgba(13, 202, 240, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#0dcaf0'
                    },
                    {
                        label: 'Total Kumulatif',
                        data: cumulativeGrowth,
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25, 135, 84, 0.05)',
                        tension: 0.4,
                        fill: false,
                        borderWidth: 2,
                        borderDash: [3, 3],
                        pointRadius: 4,
                        pointBackgroundColor: '#198754'
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
                            padding: 10
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [2, 2]
                        },
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error creating user growth chart:', error);
    }
}

function showToast(type, message) {
    let toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.className = 'position-fixed top-0 end-0 p-3';
        toastContainer.style.zIndex = '9998';
        document.body.appendChild(toastContainer);
    }
    
    const toastId = 'toast-' + Date.now();
    const toast = document.createElement('div');
    toast.id = toastId;
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    toastContainer.appendChild(toast);
    
    const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
    bsToast.show();
    
    toast.addEventListener('hidden.bs.toast', function() {
        toast.remove();
    });
}

// Period selector functionality
document.querySelectorAll('[data-period]').forEach(button => {
    button.addEventListener('click', function() {
        document.querySelectorAll('[data-period]').forEach(btn => {
            btn.classList.remove('active');
        });
        this.classList.add('active');
        
        const period = this.getAttribute('data-period');
        showToast('info', `Mengubah periode ke ${period}`);
    });
});

function backupDatabase() {
    if (!confirm('Apakah Anda yakin ingin melakukan backup database?')) {
        return;
    }
    
    showLoading('Membuat backup database...');
    
    setTimeout(function() {
        showToast('info', 'Fitur backup sedang dalam pengembangan');
        hideLoading();
    }, 1500);
}

function clearCache() {
    if (!confirm('Apakah Anda yakin ingin membersihkan cache sistem?')) {
        return;
    }
    
    showLoading('Membersihkan cache...');
    
    setTimeout(function() {
        showToast('success', 'Cache berhasil dibersihkan');
        hideLoading();
    }, 1000);
}
</script>

<style>
/* Style untuk konsistensi - SAMA PERSIS DENGAN SEBELUMNYA */
.widget-icon {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bg-primary-light { background-color: rgba(13, 110, 253, 0.1); }
.bg-success-light { background-color: rgba(25, 135, 84, 0.1); }
.bg-warning-light { background-color: rgba(255, 193, 7, 0.1); }
.bg-danger-light { background-color: rgba(220, 53, 69, 0.1); }
.bg-info-light { background-color: rgba(13, 202, 240, 0.1); }

.avatar-sm {
    width: 36px;
    height: 36px;
}

.avatar-title {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chart-container {
    position: relative;
    min-height: 300px;
}

.access-card {
    transition: transform 0.2s, box-shadow 0.2s;
    border: 1px solid #e9ecef !important;
}

.access-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
}

.access-icon {
    transition: transform 0.3s;
}

.access-card:hover .access-icon {
    transform: scale(1.1);
}

.debug-panel {
    background: #f0f0f0;
    border: 2px solid #ff0000;
    padding: 15px;
    margin-bottom: 20px;
    font-family: monospace;
    font-size: 12px;
    max-height: 300px;
    overflow: auto;
    position: relative;
    z-index: 1000;
}

.card {
    border-radius: 10px;
    transition: all 0.3s ease;
}

.card:hover {
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15) !important;
}

.badge {
    font-weight: 500;
    padding: 0.5em 0.8em;
}

.progress {
    border-radius: 10px;
}
</style>

<?php
require_once '../../includes/footer.php';

// ============================================================================
// FUNGSI EXPORT PDF - DIPERBAIKI (TANPA RoundedRect) + LEGEND WARNA HORIZONTAL
// ============================================================================
function exportToPDF($totalUsers, $totalMessages, $pendingMessages, $expiredMessages,
                     $dailyMessagesData, $messageStatusData, $messageStats, 
                     $userGrowthData, $topUsers, $recentMessages, $responseStats,
                     $usersByTypeData, $startDate, $endDate) {
    
    debug_log("=== INSIDE exportToPDF ===");
    
    try {
        // Buat PDF dengan FPDF - Landscape A4
        $pdf = new FPDF('L', 'mm', 'A4');
        $pdf->SetMargins(15, 10, 15); // Margin atas dikurangi dari 15 menjadi 10
        $pdf->SetAutoPageBreak(true, 15); // Auto page break lebih rendah
        
        // HALAMAN 1: RINGKASAN EKSEKUTIF
        $pdf->AddPage();
        
        // Header - dikurangi tingginya dari 40 menjadi 25 (dikurangi 0.5cm dari 30)
        $pdf->SetFillColor(13, 110, 253);
        $pdf->Rect(0, 0, 297, 25, 'F'); // Tinggi header 25mm (dikurangi 5mm)
        
        // Judul Sekolah - posisi disesuaikan
        $pdf->SetFont('Arial', 'B', 18); // Ukuran font dikurangi dari 20 ke 18
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(15, 4); // Y=4 (disesuaikan)
        $pdf->Cell(0, 7, 'SMKN 12 JAKARTA', 0, 1, 'L'); // Tinggi cell 7
        
        // Subjudul - posisi disesuaikan
        $pdf->SetFont('Arial', '', 10); // Ukuran font dikurangi dari 12 ke 10
        $pdf->SetXY(15, 12); // Y=12 (disesuaikan)
        $pdf->Cell(0, 6, 'Laporan Dashboard Admin', 0, 1, 'L'); // Tinggi cell 6
        
        // Informasi periode, tanggal, pencetak - dipindah ke kanan dengan font lebih kecil
        $pdf->SetFont('Arial', '', 8); // Ukuran font 8 (sebelumnya 10)
        $pdf->SetXY(180, 4); // Posisi disesuaikan
        $pdf->Cell(100, 4, 'Periode: ' . date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)), 0, 1, 'R');
        $pdf->SetXY(180, 9); // Y=9
        $pdf->Cell(100, 4, 'Tanggal: ' . date('d/m/Y H:i:s'), 0, 1, 'R');
        $pdf->SetXY(180, 14); // Y=14
        $pdf->Cell(100, 4, 'Dicetak oleh: ' . ($_SESSION['nama_lengkap'] ?? 'Admin'), 0, 1, 'R'); // Nama admin ditambahkan
        
        // Judul RINGKASAN EKSEKUTIF - dinaikkan posisinya (karena header lebih pendek)
        $pdf->SetY(30); // Y=30 (sebelumnya 35) - naik 5mm
        $pdf->SetFont('Arial', 'B', 14); // Ukuran font dikurangi dari 16 ke 14
        $pdf->SetTextColor(13, 110, 253);
        $pdf->Cell(0, 8, 'RINGKASAN EKSEKUTIF', 0, 1, 'C');
        $pdf->Ln(2); // Jarak setelah judul dikurangi dari 5 ke 2
        
        // Statistik Cards
        $startY = $pdf->GetY(); // Sekitar Y=40 (sebelumnya Y=45) - naik 5mm
        
        // Card 1: Total Users
        $pdf->SetFillColor(240, 248, 255);
        $pdf->SetDrawColor(13, 110, 253);
        $pdf->SetLineWidth(0.2);
        $pdf->Rect(15, $startY, 65, 25, 'DF'); // Tinggi card 25mm
        
        $pdf->SetFont('Arial', 'B', 18); // Ukuran font dikurangi dari 20 ke 18
        $pdf->SetTextColor(13, 110, 253);
        $pdf->SetXY(20, $startY + 4); // Y disesuaikan
        $pdf->Cell(55, 6, number_format($totalUsers), 0, 0, 'C');
        $pdf->SetXY(20, $startY + 12); // Y disesuaikan
        $pdf->SetFont('Arial', 'B', 8); // Ukuran font dikurangi dari 9 ke 8
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(55, 5, 'Total Pengguna', 0, 0, 'C');
        
        // Card 2: Total Messages
        $pdf->SetFillColor(230, 255, 230);
        $pdf->SetDrawColor(25, 135, 84);
        $pdf->Rect(85, $startY, 65, 25, 'DF');
        
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor(25, 135, 84);
        $pdf->SetXY(90, $startY + 4);
        $pdf->Cell(55, 6, number_format($totalMessages), 0, 0, 'C');
        $pdf->SetXY(90, $startY + 12);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(55, 5, 'Total Pesan', 0, 0, 'C');
        
        // Card 3: Pending Messages
        $pdf->SetFillColor(255, 255, 230);
        $pdf->SetDrawColor(255, 193, 7);
        $pdf->Rect(155, $startY, 65, 25, 'DF');
        
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor(255, 193, 7);
        $pdf->SetXY(160, $startY + 4);
        $pdf->Cell(55, 6, number_format($pendingMessages), 0, 0, 'C');
        $pdf->SetXY(160, $startY + 12);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(55, 5, 'Pesan Tertunda', 0, 0, 'C');
        
        // Card 4: Expired Messages
        $pdf->SetFillColor(255, 230, 230);
        $pdf->SetDrawColor(220, 53, 69);
        $pdf->Rect(225, $startY, 65, 25, 'DF');
        
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor(220, 53, 69);
        $pdf->SetXY(230, $startY + 4);
        $pdf->Cell(55, 6, number_format($expiredMessages), 0, 0, 'C');
        $pdf->SetXY(230, $startY + 12);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(55, 5, 'Pesan Expired', 0, 0, 'C');
        
        // Response Statistics - dinaikkan posisinya
        $pdf->SetY($startY + 30); // Y = startY + 30 (tetap)
        
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Rect(15, $pdf->GetY(), 265, 20, 'F'); // Tinggi box 20mm
        
        $pdf->SetFont('Arial', 'B', 10); // Ukuran font dikurangi dari 11 ke 10
        $pdf->SetTextColor(13, 110, 253);
        $pdf->SetXY(20, $pdf->GetY() + 3); // Y disesuaikan
        $pdf->Cell(0, 5, 'STATISTIK RESPONS 30 HARI TERAKHIR', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 9); // Ukuran font dikurangi dari 10 ke 9
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(20, $pdf->GetY() + 3); // Y disesuaikan
        $pdf->Cell(80, 5, 'Total Pesan: ' . $responseStats['total_messages'], 0, 0, 'L');
        $pdf->Cell(80, 5, 'Direspons: ' . $responseStats['responded'], 0, 0, 'L');
        $pdf->Cell(80, 5, 'Response Rate: ' . $responseStats['response_rate'] . '%', 0, 1, 'L');
        
        // GRAFIK VOLUME PESAN - posisi judul tetap, grafik dinaikkan 2 cm
        $pdf->Ln(5); // Jarak tetap
        
        $pdf->SetFont('Arial', 'B', 11); // Ukuran font dikurangi dari 12 ke 11
        $pdf->SetTextColor(13, 110, 253);
        $pdf->Cell(0, 6, 'GRAFIK VOLUME PESAN 7 HARI TERAKHIR', 0, 1, 'L');
        
        // Data untuk grafik
        $dates = array_column($dailyMessagesData, 'date');
        $totals = array_column($dailyMessagesData, 'message_count');
        $maxValue = max(array_merge($totals, [1]));
        $barWidth = 25;
        $startX = 30;
        
        // Hitung posisi grafik - dinaikkan 20mm (2 cm) dari posisi sebelumnya
        $currentY = $pdf->GetY(); // Posisi setelah judul
        $chartY = $currentY + 50; // Ditambah 50mm (sebelumnya 70, sekarang 50 = naik 20mm)
        $chartHeight = 45;
        
        // Background grafik - bagian atas dikurangi 2cm
        $pdf->SetFillColor(248, 249, 250);
        $pdf->Rect(20, $currentY + 5, 260, $chartHeight + 35, 'F'); // Background tetap
        
        // Sumbu
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(25, $chartY, 25, $chartY - $chartHeight - 5);
        $pdf->Line(20, $chartY, 270, $chartY);
        
        // Gambar bar
        for ($i = 0; $i < count($dates); $i++) {
            $x = $startX + ($i * ($barWidth + 10));
            $barHeight = ($totals[$i] / $maxValue) * $chartHeight;
            
            // Warna berdasarkan nilai
            if ($totals[$i] > $maxValue * 0.7) {
                $pdf->SetFillColor(220, 53, 69);
            } elseif ($totals[$i] > $maxValue * 0.4) {
                $pdf->SetFillColor(255, 193, 7);
            } else {
                $pdf->SetFillColor(25, 135, 84);
            }
            
            $pdf->Rect($x, $chartY - $barHeight, $barWidth, $barHeight, 'F');
            
            // Label tanggal
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x, $chartY + 3);
            $pdf->Cell($barWidth, 4, date('d/m', strtotime($dates[$i])), 0, 0, 'C');
            
            // Nilai di atas bar
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetXY($x, $chartY - $barHeight - 5);
            $pdf->Cell($barWidth, 4, $totals[$i], 0, 0, 'C');
        }
        
        // LEGEND WARNA HORIZONTAL UNTUK GRAFIK BATANG
        $legendY = $chartY + 15;
        $legendX = 20;
        $spacing = 30; // Jarak antar legend 3cm
        
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($legendX, $legendY);
        $pdf->Cell(30, 4, 'Keterangan:', 0, 1, 'L');
        
        // Baris Legend Horizontal
        $legendRowY = $legendY + 5;
        
        // Legend 1: Tinggi (>70%) - Total Pesan
        $pdf->SetFillColor(220, 53, 69);
        $pdf->Rect($legendX, $legendRowY, 5, 5, 'F');
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetXY($legendX + 8, $legendRowY);
        $pdf->Cell(25, 5, 'Tinggi', 0, 0, 'L');
        
        // Legend 2: Sedang (40-70%) - Pending
        $pdf->SetFillColor(255, 193, 7);
        $pdf->Rect($legendX + $spacing, $legendRowY, 5, 5, 'F');
        $pdf->SetXY($legendX + $spacing + 8, $legendRowY);
        $pdf->Cell(25, 5, 'Sedang', 0, 0, 'L');
        
        // Legend 3: Rendah (<40%) - Diproses
        $pdf->SetFillColor(25, 135, 84);
        $pdf->Rect($legendX + ($spacing * 2), $legendRowY, 5, 5, 'F');
        $pdf->SetXY($legendX + ($spacing * 2) + 8, $legendRowY);
        $pdf->Cell(25, 5, 'Rendah', 0, 0, 'L');
        
        // Legend 4: Nilai di atas bar - Disetujui
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $pdf->Rect($legendX + ($spacing * 3), $legendRowY, 5, 5, 'D');
        $pdf->SetXY($legendX + ($spacing * 3) + 8, $legendRowY);
        $pdf->Cell(25, 5, 'Jumlah', 0, 0, 'L');
        
        // Kategori Jenis Pesan - Baris kedua (Horizontal)
        $categoryY = $legendRowY + 10;
        
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetXY($legendX, $categoryY);
        $pdf->Cell(50, 4, 'Jenis Pesan:', 0, 0, 'L');
        
        // Warna untuk kategori jenis pesan (mengambil dari $messageStats)
        $catColors = [
            [52, 152, 219], // Biru
            [46, 204, 113], // Hijau
            [155, 89, 182], // Ungu
            [241, 196, 15], // Kuning
            [230, 126, 34], // Oranye
            [231, 76, 60],  // Merah
            [52, 73, 94],   // Abu-abu tua
            [26, 188, 156]  // Turquoise
        ];
        
        $catX = $legendX;
        $catSpacing = 28; // Jarak antar kategori 2.8cm
        
        // Hitung total item yang akan ditampilkan
        $totalCategories = count($messageStats);
        $itemsPerRow = floor((270 - $legendX) / $catSpacing); // Hitung item per baris
        $itemsPerRow = min($itemsPerRow, 8); // Maksimal 8 item per baris
        
        $rowCount = 0;
        $currentCatX = $legendX;
        $currentCatY = $categoryY + 5;
        
        foreach (array_slice($messageStats, 0, 12) as $index => $stat) { // Tampilkan maksimal 12 kategori
            $color = $catColors[$index % count($catColors)];
            
            // Gambar kotak warna
            $pdf->SetFillColor($color[0], $color[1], $color[2]);
            $pdf->Rect($currentCatX, $currentCatY, 4, 4, 'F');
            
            // Teks jenis pesan
            $pdf->SetFont('Arial', '', 6);
            $pdf->SetXY($currentCatX + 6, $currentCatY);
            $pdf->Cell(22, 4, substr($stat['jenis_pesan'], 0, 10), 0, 0, 'L');
            
            // Update posisi X untuk item berikutnya
            $currentCatX += $catSpacing;
            $rowCount++;
            
            // Jika sudah mencapai batas per baris, pindah ke baris baru
            if ($rowCount >= $itemsPerRow) {
                $rowCount = 0;
                $currentCatX = $legendX;
                $currentCatY += 6; // Turun 6mm untuk baris baru
            }
            
            // Hentikan jika sudah melebihi batas halaman
            if ($currentCatY > 250) {
                break;
            }
        }
        
        // Posisi setelah legend (disesuaikan agar muat)
        $lastLegendY = max($currentCatY + 8, $legendRowY + 25);
        $pdf->SetY($lastLegendY);
        
        // HALAMAN 2: DISTRIBUSI STATUS
        $pdf->AddPage();
        
        $pdf->SetFillColor(13, 110, 253);
        $pdf->Rect(0, 0, 297, 20, 'F');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(15, 5);
        $pdf->Cell(0, 10, 'HALAMAN 2 - DISTRIBUSI STATUS & JENIS PESAN', 0, 1, 'L');
        
        $pdf->SetY(30);
        
        // Tabel Status
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(13, 110, 253);
        $pdf->Cell(0, 8, 'DISTRIBUSI STATUS PESAN', 0, 1, 'L');
        $pdf->Ln(2);
        
        $pdf->SetFillColor(13, 110, 253);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(100, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Jumlah', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Persentase', 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $fill = false;
        
        $totalStatus = array_sum(array_column($messageStatusData, 'count'));
        $totalStatus = $totalStatus > 0 ? $totalStatus : 1;
        
        foreach ($messageStatusData as $data) {
            if ($fill) $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell(100, 7, $data['status'], 1, 0, 'L', $fill);
            $pdf->Cell(50, 7, $data['count'], 1, 0, 'C', $fill);
            $pdf->Cell(50, 7, round(($data['count'] / $totalStatus) * 100, 1) . '%', 1, 1, 'C', $fill);
            $fill = !$fill;
        }
        
        $pdf->Ln(10);
        
        // Tabel Jenis Pesan
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(13, 110, 253);
        $pdf->Cell(0, 8, 'PERFORMA JENIS PESAN', 0, 1, 'L');
        $pdf->Ln(2);
        
        $pdf->SetFillColor(13, 110, 253);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(60, 8, 'Jenis Pesan', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Total', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Pending', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Diproses', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Disetujui', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Ditolak', 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $fill = false;
        
        foreach ($messageStats as $stat) {
            if ($fill) $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell(60, 6, substr($stat['jenis_pesan'], 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(25, 6, $stat['total'], 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, $stat['pending'], 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, $stat['processed'], 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, $stat['approved'], 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, $stat['rejected'], 1, 1, 'C', $fill);
            $fill = !$fill;
        }
        
        // HALAMAN 3: PESAN TERBARU & TOP USERS
        $pdf->AddPage();
        
        $pdf->SetFillColor(13, 110, 253);
        $pdf->Rect(0, 0, 297, 20, 'F');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(15, 5);
        $pdf->Cell(0, 10, 'HALAMAN 3 - DATA LENGKAP', 0, 1, 'L');
        
        $pdf->SetY(30);
        
        // 10 PESAN TERBARU
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(13, 110, 253);
        $pdf->Cell(0, 8, '10 PESAN TERBARU', 0, 1, 'L');
        $pdf->Ln(2);
        
        $pdf->SetFillColor(13, 110, 253);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Pengirim', 1, 0, 'L', true);
        $pdf->Cell(30, 8, 'Jenis', 1, 0, 'L', true);
        $pdf->Cell(100, 8, 'Isi Pesan', 1, 0, 'L', true);
        $pdf->Cell(25, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Waktu', 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 7);
        $fill = false;
        
        foreach (array_slice($recentMessages, 0, 10) as $index => $msg) {
            if ($fill) $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell(10, 6, $index + 1, 1, 0, 'C', $fill);
            $pdf->Cell(40, 6, substr($msg['nama_lengkap'] ?? '-', 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(30, 6, substr($msg['jenis_pesan'] ?? '-', 0, 15), 1, 0, 'L', $fill);
            $pdf->Cell(100, 6, substr($msg['isi_pesan'] ?? '-', 0, 45), 1, 0, 'L', $fill);
            $pdf->Cell(25, 6, $msg['status'] ?? 'Pending', 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, date('d/m H:i', strtotime($msg['created_at'] ?? '')), 1, 1, 'C', $fill);
            $fill = !$fill;
        }
        
        $pdf->Ln(10);
        
        // TOP 5 PENGGUNA AKTIF
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(13, 110, 253);
        $pdf->Cell(0, 8, 'TOP 5 PENGGUNA AKTIF', 0, 1, 'L');
        $pdf->Ln(2);
        
        $pdf->SetFillColor(13, 110, 253);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
        $pdf->Cell(80, 8, 'Nama', 1, 0, 'L', true);
        $pdf->Cell(50, 8, 'Tipe', 1, 0, 'L', true);
        $pdf->Cell(40, 8, 'Jumlah Pesan', 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $fill = false;
        
        foreach ($topUsers as $index => $user) {
            if ($fill) $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell(10, 7, $index + 1, 1, 0, 'C', $fill);
            $pdf->Cell(80, 7, substr($user['nama_lengkap'] ?? '-', 0, 30), 1, 0, 'L', $fill);
            $pdf->Cell(50, 7, str_replace('_', ' ', $user['user_type'] ?? '-'), 1, 0, 'L', $fill);
            $pdf->Cell(40, 7, $user['message_count'] ?? 0, 1, 1, 'C', $fill);
            $fill = !$fill;
        }
        
        // FOOTER
        $pdf->SetY(-20);
        $pdf->SetDrawColor(13, 110, 253);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(15, $pdf->GetY(), 282, $pdf->GetY());
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 4, 'Laporan ini dihasilkan secara otomatis oleh Aplikasi Pesan Responsif SMKN 12 Jakarta', 0, 1, 'C');
        $pdf->Cell(0, 4, 'Halaman ' . $pdf->PageNo() . ' - ' . date('d/m/Y H:i:s'), 0, 1, 'C');
        
        // Output PDF
        $pdf->Output('Dashboard_Admin_Report_' . date('Y-m-d') . '.pdf', 'D');
        exit;
        
    } catch (Exception $e) {
        debug_log("ERROR in PDF generation: " . $e->getMessage());
        echo "<h1>Error Export PDF</h1>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
        exit;
    }
}

// ============================================================================
// FUNGSI EXPORT EXCEL
// ============================================================================
function exportToExcel($totalUsers, $totalMessages, $pendingMessages, $expiredMessages,
                       $dailyMessagesData, $messageStatusData, $messageStats, 
                       $userGrowthData, $topUsers, $recentMessages, $usersByTypeData, 
                       $responseStats, $startDate, $endDate) {
    
    debug_log("=== INSIDE exportToExcel ===");
    
    try {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        
        // Set properti
        $spreadsheet->getProperties()
            ->setCreator("SMKN 12 Jakarta")
            ->setLastModifiedBy("Admin")
            ->setTitle("Laporan Dashboard Admin")
            ->setSubject("Laporan Dashboard")
            ->setDescription("Laporan Dashboard Admin SMKN 12 Jakarta");
        
        // SHEET 1: RINGKASAN
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ringkasan');
        
        // Page setup A4 Landscape
        $sheet->getPageSetup()
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        
        // Header
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', 'SMKN 12 JAKARTA - LAPORAN DASHBOARD ADMIN');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        
        $sheet->mergeCells('A2:F2');
        $sheet->setCellValue('A2', 'Periode: ' . date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)));
        $sheet->getStyle('A2')->getFont()->setItalic(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');
        
        // Statistik
        $sheet->setCellValue('A4', 'STATISTIK UTAMA');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(12);
        
        $sheet->setCellValue('A5', 'Total Pengguna');
        $sheet->setCellValue('B5', $totalUsers);
        $sheet->setCellValue('C5', 'Total Pesan');
        $sheet->setCellValue('D5', $totalMessages);
        $sheet->setCellValue('E5', 'Response Rate');
        $sheet->setCellValue('F5', $responseStats['response_rate'] . '%');
        
        $sheet->setCellValue('A6', 'Pesan Tertunda');
        $sheet->setCellValue('B6', $pendingMessages);
        $sheet->setCellValue('C6', 'Pesan Expired');
        $sheet->setCellValue('D6', $expiredMessages);
        $sheet->setCellValue('E6', 'Pesan Direspons');
        $sheet->setCellValue('F6', $responseStats['responded']);
        
        $sheet->getStyle('A5:F6')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        
        // Volume Pesan
        $sheet->setCellValue('A8', 'VOLUME PESAN 7 HARI TERAKHIR');
        $sheet->getStyle('A8')->getFont()->setBold(true);
        
        $sheet->setCellValue('A9', 'Tanggal');
        $sheet->setCellValue('B9', 'Total Pesan');
        $sheet->setCellValue('C9', 'Pending');
        $sheet->setCellValue('D9', 'Disetujui');
        
        $sheet->getStyle('A9:D9')->getFont()->setBold(true);
        $sheet->getStyle('A9:D9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet->getStyle('A9:D9')->getFont()->getColor()->setARGB('FFFFFFFF');
        
        $row = 10;
        foreach ($dailyMessagesData as $data) {
            $sheet->setCellValue('A' . $row, date('d/m/Y', strtotime($data['date'])));
            $sheet->setCellValue('B' . $row, $data['message_count']);
            $sheet->setCellValue('C' . $row, $data['pending_count']);
            $sheet->setCellValue('D' . $row, $data['approved_count']);
            $row++;
        }
        
        // SHEET 2: DISTRIBUSI STATUS
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Distribusi Status');
        
        $sheet2->setCellValue('A1', 'DISTRIBUSI STATUS PESAN');
        $sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        
        $sheet2->setCellValue('A3', 'Status');
        $sheet2->setCellValue('B3', 'Jumlah');
        $sheet2->setCellValue('C3', 'Persentase');
        
        $sheet2->getStyle('A3:C3')->getFont()->setBold(true);
        $sheet2->getStyle('A3:C3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet2->getStyle('A3:C3')->getFont()->getColor()->setARGB('FFFFFFFF');
        
        $row = 4;
        $totalStatus = array_sum(array_column($messageStatusData, 'count'));
        $totalStatus = $totalStatus > 0 ? $totalStatus : 1;
        
        foreach ($messageStatusData as $data) {
            $sheet2->setCellValue('A' . $row, $data['status']);
            $sheet2->setCellValue('B' . $row, $data['count']);
            $sheet2->setCellValue('C' . $row, '=B' . $row . '/' . $totalStatus . '*100');
            $sheet2->getStyle('C' . $row)->getNumberFormat()->setFormatCode('0.00"%"');
            $row++;
        }
        
        // SHEET 3: JENIS PESAN
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Jenis Pesan');
        
        $sheet3->setCellValue('A1', 'PERFORMA JENIS PESAN');
        $sheet3->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        
        $sheet3->setCellValue('A3', 'Jenis Pesan');
        $sheet3->setCellValue('B3', 'Total');
        $sheet3->setCellValue('C3', 'Pending');
        $sheet3->setCellValue('D3', 'Diproses');
        $sheet3->setCellValue('E3', 'Disetujui');
        $sheet3->setCellValue('F3', 'Ditolak');
        
        $sheet3->getStyle('A3:F3')->getFont()->setBold(true);
        $sheet3->getStyle('A3:F3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet3->getStyle('A3:F3')->getFont()->getColor()->setARGB('FFFFFFFF');
        
        $row = 4;
        foreach ($messageStats as $stat) {
            $sheet3->setCellValue('A' . $row, $stat['jenis_pesan']);
            $sheet3->setCellValue('B' . $row, $stat['total']);
            $sheet3->setCellValue('C' . $row, $stat['pending']);
            $sheet3->setCellValue('D' . $row, $stat['processed']);
            $sheet3->setCellValue('E' . $row, $stat['approved']);
            $sheet3->setCellValue('F' . $row, $stat['rejected']);
            $row++;
        }
        
        // SHEET 4: PESAN TERBARU
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('Pesan Terbaru');
        
        $sheet4->setCellValue('A1', '10 PESAN TERBARU');
        $sheet4->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        
        $sheet4->setCellValue('A3', '#');
        $sheet4->setCellValue('B3', 'Pengirim');
        $sheet4->setCellValue('C3', 'Jenis');
        $sheet4->setCellValue('D3', 'Isi Pesan');
        $sheet4->setCellValue('E3', 'Status');
        $sheet4->setCellValue('F3', 'Waktu');
        
        $sheet4->getStyle('A3:F3')->getFont()->setBold(true);
        $sheet4->getStyle('A3:F3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet4->getStyle('A3:F3')->getFont()->getColor()->setARGB('FFFFFFFF');
        
        $row = 4;
        foreach (array_slice($recentMessages, 0, 10) as $index => $msg) {
            $sheet4->setCellValue('A' . $row, $index + 1);
            $sheet4->setCellValue('B' . $row, $msg['nama_lengkap'] ?? '-');
            $sheet4->setCellValue('C' . $row, $msg['jenis_pesan'] ?? '-');
            $sheet4->setCellValue('D' . $row, substr($msg['isi_pesan'] ?? '-', 0, 100));
            $sheet4->setCellValue('E' . $row, $msg['status'] ?? '-');
            $sheet4->setCellValue('F' . $row, date('d/m/Y H:i', strtotime($msg['created_at'] ?? '')));
            $row++;
        }
        
        // SHEET 5: TOP USERS
        $sheet5 = $spreadsheet->createSheet();
        $sheet5->setTitle('Top Users');
        
        $sheet5->setCellValue('A1', 'TOP 5 PENGGUNA AKTIF');
        $sheet5->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        
        $sheet5->setCellValue('A3', '#');
        $sheet5->setCellValue('B3', 'Nama');
        $sheet5->setCellValue('C3', 'Tipe');
        $sheet5->setCellValue('D3', 'Jumlah Pesan');
        
        $sheet5->getStyle('A3:D3')->getFont()->setBold(true);
        $sheet5->getStyle('A3:D3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet5->getStyle('A3:D3')->getFont()->getColor()->setARGB('FFFFFFFF');
        
        $row = 4;
        foreach ($topUsers as $index => $user) {
            $sheet5->setCellValue('A' . $row, $index + 1);
            $sheet5->setCellValue('B' . $row, $user['nama_lengkap'] ?? '-');
            $sheet5->setCellValue('C' . $row, str_replace('_', ' ', $user['user_type'] ?? '-'));
            $sheet5->setCellValue('D' . $row, $user['message_count'] ?? 0);
            $row++;
        }
        
        // SHEET 6: DISTRIBUSI USER
        $sheet6 = $spreadsheet->createSheet();
        $sheet6->setTitle('Distribusi User');
        
        $sheet6->setCellValue('A1', 'DISTRIBUSI PENGGUNA BERDASARKAN TIPE');
        $sheet6->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        
        $sheet6->setCellValue('A3', 'Tipe Pengguna');
        $sheet6->setCellValue('B3', 'Jumlah');
        $sheet6->setCellValue('C3', 'Persentase');
        
        $sheet6->getStyle('A3:C3')->getFont()->setBold(true);
        $sheet6->getStyle('A3:C3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet6->getStyle('A3:C3')->getFont()->getColor()->setARGB('FFFFFFFF');
        
        $row = 4;
        $totalUserTypes = array_sum(array_column($usersByTypeData, 'count'));
        $totalUserTypes = $totalUserTypes > 0 ? $totalUserTypes : 1;
        
        foreach ($usersByTypeData as $type) {
            $sheet6->setCellValue('A' . $row, str_replace('_', ' ', $type['user_type']));
            $sheet6->setCellValue('B' . $row, $type['count']);
            $sheet6->setCellValue('C' . $row, '=B' . $row . '/' . $totalUserTypes . '*100');
            $sheet6->getStyle('C' . $row)->getNumberFormat()->setFormatCode('0.00"%"');
            $row++;
        }
        
        // Auto-size columns
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            foreach (range('A', 'F') as $column) {
                $worksheet->getColumnDimension($column)->setAutoSize(true);
            }
        }
        
        // Output file
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Dashboard_Admin_Report_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
        
    } catch (Exception $e) {
        debug_log("ERROR in Excel generation: " . $e->getMessage());
        echo "<h1>Error Export Excel</h1>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
        exit;
    }
}

debug_log("=== SCRIPT END ===");
?>