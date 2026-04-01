<?php
/**
 * View User's Messages
 * File: modules/user/view_messages.php
 * 
 * VERSI: 5.15 - PROFESSIONAL UI dengan GRAFIK AKTIF dan PREVIEW GAMBAR
 * - PERBAIKAN: Modal detail pesan langsung tampilkan thumbnail gambar
 * - PERBAIKAN: Preview gambar muncul di depan modal detail pesan
 * - PERBAIKAN: Fitur Lihat Lampiran dengan modal terpisah (seperti di followup.php)
 * - PERBAIKAN: Struktur tabel message_attachments yang lengkap
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================================================
// FORCE NO CACHE
// ============================================================================
header("Cache-Control: no-cache, must-revalidate, no-store, max-age=0, private");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

// ============================================================================
// ERROR REPORTING
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// ============================================================================
// CEK AUTHENTICATION
// ============================================================================
try {
    Auth::checkAuth();
} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$userNama = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'User';

// ============================================================================
// DEBUG MODE
// ============================================================================
$debug_enabled = isset($_COOKIE['debug_mode']) ? $_COOKIE['debug_mode'] === 'true' : false;
if (isset($_GET['debug'])) {
    $debug_enabled = $_GET['debug'] === 'on';
    setcookie('debug_mode', $debug_enabled ? 'true' : 'false', time() + 86400 * 30);
}

// ============================================================================
// PLACEHOLDER IMAGE
// ============================================================================
$placeholder_image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f8f9fa"/><text x="50" y="50" font-family="Arial" font-size="12" fill="#adb5bd" text-anchor="middle" dy=".3em">No Image</text></svg>');

// ============================================================================
// FILTER PARAMETERS
// ============================================================================
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$priorityFilter = $_GET['priority'] ?? 'all';
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;

// Database connection
$db = Database::getInstance()->getConnection();

// ============================================================================
// BUILD QUERY
// ============================================================================
$whereConditions = ["m.pengirim_id = :user_id"];
$params = [':user_id' => $userId];

if ($statusFilter !== 'all') {
    $whereConditions[] = "m.status = :status";
    $params[':status'] = $statusFilter;
}

if ($typeFilter !== 'all') {
    $whereConditions[] = "m.jenis_pesan_id = :type";
    $params[':type'] = $typeFilter;
}

if ($priorityFilter !== 'all') {
    $whereConditions[] = "m.priority = :priority";
    $params[':priority'] = $priorityFilter;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(m.created_at) >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(m.created_at) <= :date_to";
    $params[':date_to'] = $dateTo;
}

if (!empty($search)) {
    $whereConditions[] = "(m.isi_pesan LIKE :search OR mt.jenis_pesan LIKE :search OR m.status LIKE :search OR m.priority LIKE :search)";
    $params[':search'] = "%$search%";
}

$whereClause = implode(' AND ', $whereConditions);

// Validate sort column
$allowedSortColumns = ['created_at', 'status', 'priority', 'jenis_pesan'];
$sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Get total count
$countSql = "
    SELECT COUNT(*) as total 
    FROM messages m
    LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
    WHERE $whereClause
";

$stmt = $db->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetch()['total'];

// Calculate pagination
$totalPages = ceil($total / $perPage);
$page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));
$offset = ($page - 1) * $perPage;

// Get messages
$sql = "
    SELECT 
        m.*,
        m.reference_number,
        mt.jenis_pesan,
        mt.response_deadline_hours,
        u.nama_lengkap as responder_nama,
        u.user_type as responder_type,
        u.avatar as responder_avatar,
        mr.catatan_respon as last_response,
        mr.created_at as last_response_date,
        mr.status as response_status,
        TIMESTAMPDIFF(HOUR, m.created_at, NOW()) as hours_since_created,
        GREATEST(0, mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) as hours_remaining,
        CASE 
            WHEN TIMESTAMPDIFF(HOUR, m.created_at, NOW()) >= mt.response_deadline_hours THEN 'danger'
            WHEN (mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) <= 24 THEN 'warning'
            ELSE 'success'
        END as urgency_color,
        (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id AND is_approved = 1) as attachment_count
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
    ORDER BY $sortBy $sortOrder
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

// Get message types for filter
$typeStmt = $db->query("SELECT id, jenis_pesan FROM message_types ORDER BY jenis_pesan");
$messageTypes = $typeStmt->fetchAll();

// Get statistics with trends
$statsSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Dibaca' THEN 1 ELSE 0 END) as dibaca,
        SUM(CASE WHEN status = 'Diproses' THEN 1 ELSE 0 END) as diproses,
        SUM(CASE WHEN status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as disetujui,
        SUM(CASE WHEN status = 'Ditolak' THEN 1 ELSE 0 END) as ditolak,
        SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as selesai,
        SUM(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) > 72 THEN 1 ELSE 0 END) as expired_count,
        
        -- Trends (last 7 days)
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as last_7_days,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_30_days,
        
        -- Response rate
        SUM(CASE WHEN responder_id IS NOT NULL THEN 1 ELSE 0 END) as responded_count,
        AVG(CASE WHEN responder_id IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) ELSE NULL END) as avg_response_hours
    FROM messages 
    WHERE pengirim_id = :user_id
";

$statsStmt = $db->prepare($statsSql);
$statsStmt->execute([':user_id' => $userId]);
$stats = $statsStmt->fetch();

// Calculate response rate
$responseRate = $stats['total'] > 0 ? round(($stats['responded_count'] / $stats['total']) * 100) : 0;
$unreadCount = ($stats['pending'] ?? 0) + ($stats['dibaca'] ?? 0);

// Get recent activity for timeline
$timelineSql = "
    SELECT 
        m.id,
        m.isi_pesan,
        m.status,
        m.created_at,
        mt.jenis_pesan,
        CASE 
            WHEN mr.id IS NOT NULL THEN 'responded'
            ELSE 'sent'
        END as activity_type,
        COALESCE(mr.created_at, m.created_at) as activity_date
    FROM messages m
    LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
    LEFT JOIN message_responses mr ON m.id = mr.message_id
    WHERE m.pengirim_id = :user_id
    ORDER BY activity_date DESC
    LIMIT 10
";

$timelineStmt = $db->prepare($timelineSql);
$timelineStmt->execute([':user_id' => $userId]);
$timeline = $timelineStmt->fetchAll();

// Get response templates
$templatesStmt = $db->prepare("
    SELECT id, name, content
    FROM response_templates 
    WHERE is_active = 1
    ORDER BY id DESC
    LIMIT 5
");
$templatesStmt->execute();
$templates = $templatesStmt->fetchAll();

// ============================================================================
// HANDLE BULK ACTIONS
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulkAction = $_POST['bulk_action'];
    $selectedIds = $_POST['selected_ids'] ?? [];
    
    if (!empty($selectedIds) && is_array($selectedIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        
        switch ($bulkAction) {
            case 'delete':
                $deleteStmt = $db->prepare("UPDATE messages SET status = 'Deleted' WHERE id IN ($placeholders) AND pengirim_id = ?");
                $params = array_merge($selectedIds, [$userId]);
                $deleteStmt->execute($params);
                $_SESSION['success_message'] = count($selectedIds) . ' pesan berhasil dihapus.';
                break;
                
            case 'mark_read':
                $updateStmt = $db->prepare("UPDATE messages SET status = 'Dibaca' WHERE id IN ($placeholders) AND pengirim_id = ?");
                $params = array_merge($selectedIds, [$userId]);
                $updateStmt->execute($params);
                $_SESSION['success_message'] = count($selectedIds) . ' pesan ditandai sebagai dibaca.';
                break;
        }
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
}

// ============================================================================
// HANDLE EXPORT
// ============================================================================
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    $selectedIds = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
    
    // Build query for export
    $exportWhere = ["pengirim_id = :user_id"];
    $exportParams = [':user_id' => $userId];
    
    if (!empty($selectedIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $exportWhere[] = "id IN ($placeholders)";
        $exportParams = array_merge([$userId], $selectedIds);
    } else {
        if ($statusFilter !== 'all') {
            $exportWhere[] = "status = ?";
            $exportParams[] = $statusFilter;
        }
        if ($typeFilter !== 'all') {
            $exportWhere[] = "jenis_pesan_id = ?";
            $exportParams[] = $typeFilter;
        }
        if ($priorityFilter !== 'all') {
            $exportWhere[] = "priority = ?";
            $exportParams[] = $priorityFilter;
        }
        if (!empty($dateFrom)) {
            $exportWhere[] = "DATE(created_at) >= ?";
            $exportParams[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $exportWhere[] = "DATE(created_at) <= ?";
            $exportParams[] = $dateTo;
        }
    }
    
    $exportWhereClause = implode(' AND ', $exportWhere);
    
    $exportSql = "
        SELECT 
            m.id,
            m.isi_pesan,
            m.status,
            m.priority,
            m.created_at,
            m.tanggal_respon,
            mt.jenis_pesan,
            u.nama_lengkap as responder_nama,
            mr.catatan_respon as last_response
        FROM messages m
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users u ON m.responder_id = u.id
        LEFT JOIN message_responses mr ON m.id = mr.message_id
        WHERE $exportWhereClause
        ORDER BY m.created_at DESC
    ";
    
    $exportStmt = $db->prepare($exportSql);
    $exportStmt->execute($exportParams);
    $exportData = $exportStmt->fetchAll();
    
    switch ($exportType) {
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=messages_' . date('Y-m-d_His') . '.csv');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'Jenis', 'Isi Pesan', 'Status', 'Prioritas', 'Tanggal Kirim', 'Tanggal Respons', 'Responder', 'Respons']);
            
            foreach ($exportData as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['jenis_pesan'],
                    strip_tags($row['isi_pesan']),
                    $row['status'],
                    $row['priority'],
                    $row['created_at'],
                    $row['tanggal_respon'] ?? '-',
                    $row['responder_nama'] ?? '-',
                    strip_tags($row['last_response'] ?? '-')
                ]);
            }
            
            fclose($output);
            exit;
            
        case 'excel':
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename=messages_' . date('Y-m-d_His') . '.xls');
            
            echo '<table border="1">';
            echo '<tr><th>ID</th><th>Jenis</th><th>Isi Pesan</th><th>Status</th><th>Prioritas</th><th>Tanggal Kirim</th><th>Tanggal Respons</th><th>Responder</th><th>Respons</th></tr>';
            
            foreach ($exportData as $row) {
                echo '<tr>';
                echo '<td>' . $row['id'] . '</td>';
                echo '<td>' . htmlspecialchars($row['jenis_pesan']) . '</td>';
                echo '<td>' . htmlspecialchars($row['isi_pesan']) . '</td>';
                echo '<td>' . $row['status'] . '</td>';
                echo '<td>' . $row['priority'] . '</td>';
                echo '<td>' . $row['created_at'] . '</td>';
                echo '<td>' . ($row['tanggal_respon'] ?? '-') . '</td>';
                echo '<td>' . ($row['responder_nama'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($row['last_response'] ?? '-') . '</td>';
                echo '</tr>';
            }
            
            echo '</table>';
            exit;
    }
}

// ============================================================================
// PREPARE CHART DATA
// ============================================================================

// Data untuk Activity Chart (7 hari terakhir)
$chartDates = [];
$chartCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartDates[] = date('d M', strtotime("-$i days"));
    
    $countStmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE pengirim_id = ? AND DATE(created_at) = ?");
    $countStmt->execute([$userId, $date]);
    $chartCounts[] = (int)$countStmt->fetch()['count'];
}

// Data untuk Status Chart
$statusPending = ($stats['pending'] ?? 0) + ($stats['dibaca'] ?? 0);
$statusDiproses = $stats['diproses'] ?? 0;
$statusSelesai = ($stats['disetujui'] ?? 0) + ($stats['selesai'] ?? 0);
$statusDitolak = $stats['ditolak'] ?? 0;

// ============================================================================
// HEADER
// ============================================================================
$pageTitle = 'Pesan Saya - PesanApp';
require_once '../../includes/header.php';
?>

<!-- ==========================================================================
     IMAGE PREVIEW MODAL - MODAL PREVIEW GAMBAR DENGAN Z-INDEX LEBIH TINGGI
     ========================================================================== -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
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

<!-- ==========================================================================
     KONTEN UTAMA
     ========================================================================== -->
<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-envelope me-2"></i>Pesan Saya
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Beranda</a></li>
                    <li class="breadcrumb-item active">Pesan Saya</li>
                </ol>
            </nav>
            <p class="text-muted small mb-0">
                <i class="fas fa-info-circle me-1"></i>
                Kelola dan pantau semua pesan yang Anda kirim
            </p>
        </div>
        <div class="d-flex align-items-center mt-2 mt-sm-0">
            <?php if ($debug_enabled): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['debug' => 'off'])); ?>" 
               class="btn btn-outline-warning me-2">
                <i class="fas fa-bug"></i> Debug OFF
            </a>
            <?php else: ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['debug' => 'on'])); ?>" 
               class="btn btn-outline-secondary me-2">
                <i class="fas fa-bug"></i> Debug ON
            </a>
            <?php endif; ?>
            
            <a href="send_message.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i>Pesan Baru
            </a>
        </div>
    </div>

    <!-- QUICK STATS CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-primary">
                                <i class="fas fa-envelope text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Total Pesan</h6>
                            <h2 class="mb-0"><?php echo number_format($stats['total'] ?? 0); ?></h2>
                            <small class="text-muted">Semua pesan</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-warning">
                                <i class="fas fa-clock text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Pending</h6>
                            <h2 class="mb-0"><?php echo ($stats['pending'] ?? 0) + ($stats['dibaca'] ?? 0); ?></h2>
                            <small class="text-muted">Menunggu respons</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-success">
                                <i class="fas fa-check-circle text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Selesai</h6>
                            <h2 class="mb-0"><?php echo ($stats['disetujui'] ?? 0) + ($stats['selesai'] ?? 0); ?></h2>
                            <small class="text-muted">Telah direspons</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-danger">
                                <i class="fas fa-exclamation-triangle text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Perlu Perhatian</h6>
                            <h2 class="mb-0"><?php echo $stats['expired_count'] ?? 0; ?></h2>
                            <small class="text-muted">Melewati batas waktu</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTER SECTION -->
    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>Filter Pesan
                </h5>
                <div>
                    <span class="badge bg-light text-dark me-2" id="activeFilters">0 filter aktif</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="resetAllFilters()">
                        <i class="fas fa-undo me-1"></i>Reset
                    </button>
                </div>
            </div>
            
            <form method="GET" id="filterForm" class="row g-2">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">STATUS</label>
                    <select class="form-select" name="status" id="statusFilter" onchange="updateActiveFilters()">
                        <option value="all">Semua Status</option>
                        <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Dibaca" <?php echo $statusFilter === 'Dibaca' ? 'selected' : ''; ?>>Dibaca</option>
                        <option value="Diproses" <?php echo $statusFilter === 'Diproses' ? 'selected' : ''; ?>>Diproses</option>
                        <option value="Disetujui" <?php echo $statusFilter === 'Disetujui' ? 'selected' : ''; ?>>Disetujui</option>
                        <option value="Ditolak" <?php echo $statusFilter === 'Ditolak' ? 'selected' : ''; ?>>Ditolak</option>
                        <option value="Selesai" <?php echo $statusFilter === 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">JENIS PESAN</label>
                    <select class="form-select" name="type" id="typeFilter" onchange="updateActiveFilters()">
                        <option value="all">Semua Jenis</option>
                        <?php foreach ($messageTypes as $type): ?>
                        <option value="<?php echo $type['id']; ?>" <?php echo $typeFilter == $type['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['jenis_pesan']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">PRIORITAS</label>
                    <select class="form-select" name="priority" id="priorityFilter" onchange="updateActiveFilters()">
                        <option value="all">Semua Prioritas</option>
                        <option value="Low" <?php echo $priorityFilter === 'Low' ? 'selected' : ''; ?>>Rendah</option>
                        <option value="Medium" <?php echo $priorityFilter === 'Medium' ? 'selected' : ''; ?>>Sedang</option>
                        <option value="High" <?php echo $priorityFilter === 'High' ? 'selected' : ''; ?>>Tinggi</option>
                        <option value="Urgent" <?php echo $priorityFilter === 'Urgent' ? 'selected' : ''; ?>>Mendesak</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">RENTANG TANGGAL</label>
                    <div class="d-flex gap-2">
                        <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo $dateFrom; ?>" onchange="updateActiveFilters()">
                        <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo $dateTo; ?>" onchange="updateActiveFilters()">
                    </div>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">PENCARIAN</label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Cari pesan..." value="<?php echo htmlspecialchars($search); ?>" onkeyup="updateActiveFilters()">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- CHARTS SECTION - GRAFIK AKTIF -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow h-100">
                <div class="card-header bg-white border-bottom-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line me-2"></i>Aktivitas 7 Hari Terakhir
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 250px;">
                        <div id="activityChart" style="height: 250px;"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card border-0 shadow h-100">
                <div class="card-header bg-white border-bottom-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Distribusi Status
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 200px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span><span class="badge bg-warning" style="width: 10px; height: 10px;">&nbsp;</span> Pending</span>
                            <span class="fw-bold"><?php echo $stats['pending'] ?? 0; ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span><span class="badge bg-info" style="width: 10px; height: 10px;">&nbsp;</span> Diproses</span>
                            <span class="fw-bold"><?php echo $stats['diproses'] ?? 0; ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span><span class="badge bg-success" style="width: 10px; height: 10px;">&nbsp;</span> Selesai</span>
                            <span class="fw-bold"><?php echo ($stats['disetujui'] ?? 0) + ($stats['selesai'] ?? 0); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span><span class="badge bg-danger" style="width: 10px; height: 10px;">&nbsp;</span> Ditolak</span>
                            <span class="fw-bold"><?php echo $stats['ditolak'] ?? 0; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- RESPONSE RATE CARD -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-3 text-center">
                            <h5 class="fw-bold mb-3">Respons Rate</h5>
                            <div style="position: relative; display: inline-block;">
                                <canvas id="responseRateChart" width="150" height="150"></canvas>
                                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                                    <h3 class="fw-bold mb-0"><?php echo $responseRate; ?>%</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="p-2">
                                        <small class="text-muted d-block">Rata-rata Waktu Respons</small>
                                        <h4 class="fw-bold"><?php echo $stats['avg_response_hours'] ? round($stats['avg_response_hours'], 1) . ' jam' : '-'; ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-2">
                                        <small class="text-muted d-block">Pesan Direspons</small>
                                        <h4 class="fw-bold"><?php echo $stats['responded_count'] ?? 0; ?> / <?php echo $stats['total'] ?? 0; ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-2">
                                        <small class="text-muted d-block">Aktivitas 7 Hari</small>
                                        <h4 class="fw-bold"><?php echo $stats['last_7_days'] ?? 0; ?> pesan</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- QUICK TEMPLATES -->
    <?php if (!empty($templates)): ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-bolt me-2 text-warning"></i>Template Cepat
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($templates as $template): ?>
                <div class="col-md">
                    <button class="btn btn-outline-primary w-100 text-start p-2" onclick="useTemplate(<?php echo $template['id']; ?>)">
                        <i class="fas fa-file-alt me-2"></i>
                        <span class="fw-bold"><?php echo htmlspecialchars($template['name']); ?></span>
                        <small class="d-block text-muted text-truncate"><?php echo htmlspecialchars($template['content']); ?></small>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MESSAGES TABLE -->
    <div class="card border-0 shadow">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-inbox me-2"></i>Daftar Pesan
                <span class="badge bg-primary ms-2"><?php echo $total; ?></span>
            </h5>
            <div class="d-flex gap-2">
                <div class="input-group" style="width: 200px;">
                    <input type="text" class="form-control form-control-sm" placeholder="Cari cepat..." id="quickSearch">
                    <button class="btn btn-sm btn-primary" onclick="quickSearch()"><i class="fas fa-search"></i></button>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="?export=csv&<?php echo http_build_query($_GET); ?>"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
                        <li><a class="dropdown-item" href="?export=excel&<?php echo http_build_query($_GET); ?>"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Bulk Actions Bar -->
        <div class="px-3 py-2 bg-light border-bottom d-flex align-items-center" id="bulkActionsBar" style="display: none;">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                <label class="form-check-label" for="selectAll">Pilih Semua</label>
            </div>
            <span class="ms-3 text-muted" id="selectedCount">0 dipilih</span>
            <div class="ms-auto">
                <button class="btn btn-sm btn-success me-2" onclick="bulkAction('mark_read')">
                    <i class="fas fa-check-circle me-1"></i>Tandai Dibaca
                </button>
                <button class="btn btn-sm btn-danger" onclick="bulkAction('delete')">
                    <i class="fas fa-trash me-1"></i>Hapus
                </button>
            </div>
        </div>
        
        <?php if (empty($messages)): ?>
        <div class="text-center py-5">
            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
            <h5 class="fw-bold mb-2">Belum Ada Pesan</h5>
            <p class="text-muted mb-4">
                <?php if ($statusFilter !== 'all' || $typeFilter !== 'all' || $priorityFilter !== 'all' || !empty($search)): ?>
                Tidak ada pesan yang sesuai dengan filter.
                <?php else: ?>
                Anda belum mengirim pesan apapun.
                <?php endif; ?>
            </p>
            <a href="send_message.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Kirim Pesan Baru
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th width="40">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllHeader" onchange="toggleSelectAll()">
                            </div>
                        </th>
                        <th>#</th>
                        <th>Referensi</th>
                        <th>Jenis</th>
                        <th>Isi Pesan</th>
                        <th>Lamp</th>
                        <th>Status</th>
                        <th>Prioritas</th>
                        <th>Tanggal</th>
                        <th>Respons</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $index => $message): 
                        $hasResponse = !empty($message['last_response']);
                        $urgent = isset($message['hours_remaining']) && $message['hours_remaining'] <= 24;
                        $hasAttachments = ($message['attachment_count'] ?? 0) > 0;
                    ?>
                    <tr>
                        <td>
                            <div class="form-check">
                                <input class="form-check-input message-checkbox" type="checkbox" value="<?php echo $message['id']; ?>" onchange="updateSelectedCount()">
                            </div>
                        </td>
                        <td><span class="fw-bold">#<?php echo $offset + $index + 1; ?></span></td>
                        <td>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($message['reference_number'] ?? 'REF-' . str_pad($message['id'], 6, '0', STR_PAD_LEFT)); ?></span>
                        </td>
                        <td>
                            <span class="badge bg-info"><?php echo htmlspecialchars($message['jenis_pesan']); ?></span>
                        </td>
                        <td>
                            <div class="text-truncate" style="max-width: 200px;">
                                <?php echo htmlspecialchars($message['isi_pesan']); ?>
                            </div>
                            <small class="text-muted">
                                <i class="far fa-clock me-1"></i><?php echo Functions::timeAgo($message['created_at']); ?>
                            </small>
                        </td>
                        <td class="text-center">
                            <?php if ($hasAttachments): ?>
                            <button type="button" 
                                    class="btn btn-sm btn-link p-0" 
                                    onclick="viewAttachments(<?php echo $message['id']; ?>)"
                                    title="Lihat Lampiran (<?php echo $message['attachment_count']; ?> file)">
                                <span class="badge bg-info">
                                    <i class="fas fa-paperclip me-1"></i>
                                    <?php echo $message['attachment_count']; ?>
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
                            $badgeClass = match($message['status']) {
                                'Pending' => 'warning',
                                'Dibaca' => 'info',
                                'Diproses' => 'primary',
                                'Disetujui' => 'success',
                                'Ditolak' => 'danger',
                                'Selesai' => 'secondary',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?php echo $badgeClass; ?>">
                                <?php echo $message['status']; ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $priorityClass = match($message['priority']) {
                                'Low' => 'success',
                                'Medium' => 'warning',
                                'High' => 'danger',
                                'Urgent' => 'dark',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?php echo $priorityClass; ?>">
                                <?php echo $message['priority']; ?>
                            </span>
                            <?php if ($urgent): ?>
                            <span class="badge bg-danger mt-1">
                                <i class="fas fa-clock me-1"></i><?php echo floor($message['hours_remaining']); ?> jam
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small><?php echo date('d/m/Y H:i', strtotime($message['created_at'])); ?></small>
                        </td>
                        <td>
                            <?php if ($hasResponse): ?>
                            <div class="d-flex align-items-center gap-2">
                                <img src="<?php echo $message['responder_avatar'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($message['responder_nama'] ?? 'Responder'); ?>" 
                                     class="rounded-circle" width="24" height="24">
                                <div>
                                    <small class="fw-bold d-block"><?php echo htmlspecialchars($message['responder_nama'] ?? 'Responder'); ?></small>
                                    <small class="text-muted"><?php echo isset($message['last_response_date']) ? Functions::timeAgo($message['last_response_date']) : ''; ?></small>
                                </div>
                            </div>
                            <?php else: ?>
                            <span class="badge bg-secondary">Belum direspons</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <!-- Tombol Lihat Detail -->
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="viewMessage(<?php echo $message['id']; ?>, <?php echo htmlspecialchars(json_encode($message)); ?>)"
                                        title="Lihat Detail">
                                    <i class="fas fa-eye"></i>
                                </button>
                                
                                <!-- Tombol Lihat Lampiran -->
                                <?php if ($hasAttachments): ?>
                                <button type="button" 
                                        class="btn btn-sm btn-outline-info"
                                        onclick="viewAttachments(<?php echo $message['id']; ?>)"
                                        title="Lihat Lampiran (<?php echo $message['attachment_count']; ?> file)">
                                    <i class="fas fa-images"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white border-top-0 py-3">
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

    <!-- TIMELINE -->
    <?php if (!empty($timeline)): ?>
    <div class="card border-0 shadow mt-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-history me-2"></i>Aktivitas Terbaru
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="list-group list-group-flush">
                <?php foreach ($timeline as $activity): ?>
                <div class="list-group-item">
                    <div class="d-flex">
                        <div class="flex-shrink-0 mt-1">
                            <div class="icon-circle-sm bg-<?php echo $activity['activity_type'] === 'sent' ? 'info' : 'success'; ?>">
                                <i class="fas <?php echo $activity['activity_type'] === 'sent' ? 'fa-paper-plane' : 'fa-reply'; ?> text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="d-flex justify-content-between">
                                <h6 class="mb-1">
                                    <?php echo $activity['activity_type'] === 'sent' ? 'Pesan dikirim' : 'Direspons'; ?>
                                    <span class="badge bg-light text-dark ms-2"><?php echo $activity['jenis_pesan']; ?></span>
                                </h6>
                                <small class="text-muted"><?php echo Functions::timeAgo($activity['activity_date']); ?></small>
                            </div>
                            <p class="mb-1 text-muted small">
                                <?php echo htmlspecialchars(substr($activity['isi_pesan'], 0, 150)) . '...'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- MESSAGE DETAIL MODAL - DENGAN THUMBNAIL GAMBAR LANGSUNG -->
<div class="modal fade" id="messageModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
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
            
            <div class="modal-body p-4" id="messageDetailContent" style="background: #f8fafc; min-height: 400px;">
                <!-- Content will be loaded here -->
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Memuat detail pesan...</p>
                </div>
            </div>
            
            <div class="modal-footer bg-light py-3 border-0">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MESSAGE ATTACHMENTS MODAL - MODAL LAMPIRAN (DIAKTIFKAN SEPERTI DI FOLLOWUP.PHP) -->
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
                    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
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

<!-- QUICK ACTIONS FLOATING BUTTON -->
<div class="quick-actions">
    <div class="quick-actions-btn" onclick="toggleQuickMenu()">
        <i class="fas fa-plus"></i>
    </div>
    <div class="quick-actions-menu" id="quickMenu">
        <a href="send_message.php"><i class="fas fa-pen"></i>Pesan Baru</a>
        <a href="#" onclick="showStats()"><i class="fas fa-chart-bar"></i>Statistik</a>
        <a href="#" onclick="showHelp()"><i class="fas fa-question-circle"></i>Bantuan</a>
    </div>
</div>

<!-- DEBUG PANEL -->
<?php if ($debug_enabled): ?>
<div class="debug-panel" id="debugPanel">
    <div class="debug-header" onclick="toggleDebugContent()">
        <h6 class="m-0 text-white">DEBUG PANEL</h6>
    </div>
    <div class="debug-content" id="debugContent" style="display: none;">
        <pre style="color: #0f0; margin: 0;"><?php 
        $debugInfo = [
            'user_id' => $userId,
            'user_type' => $userType,
            'total_messages' => $total,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'filters' => [
                'status' => $statusFilter,
                'type' => $typeFilter,
                'priority' => $priorityFilter,
                'search' => $search
            ],
            'stats' => $stats,
            'chart_data' => [
                'dates' => $chartDates,
                'counts' => $chartCounts
            ]
        ];
        echo htmlspecialchars(print_r($debugInfo, true)); 
        ?></pre>
    </div>
</div>

<div class="debug-toggle-btn" onclick="toggleDebugPanel()">
    <i class="fas fa-bug"></i> Debug
</div>
<?php endif; ?>

<!-- Chart Libraries -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// ==========================================================================
// GLOBAL VARIABLES
// ==========================================================================
let currentMessageId = null;
let debugEnabled = <?php echo $debug_enabled ? 'true' : 'false'; ?>;
let selectedIds = [];
let activityChart, statusChart, responseRateChart;
let imagePreviewModal = null;
let messageModal = null;
let attachmentsModal = null;
let baseUrl = '<?php echo BASE_URL; ?>';
let placeholderImage = '<?php echo $placeholder_image; ?>';

// Chart data dari PHP
const chartDates = <?php echo json_encode($chartDates); ?>;
const chartCounts = <?php echo json_encode($chartCounts); ?>;
const statusPending = <?php echo $statusPending; ?>;
const statusDiproses = <?php echo $statusDiproses; ?>;
const statusSelesai = <?php echo $statusSelesai; ?>;
const statusDitolak = <?php echo $statusDitolak; ?>;
const responseRate = <?php echo $responseRate; ?>;

// ==========================================================================
// THEME TOGGLE
// ==========================================================================
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    
    const icon = document.querySelector('#themeToggle i');
    if (!icon) return;
    icon.className = newTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
}

// Load saved theme
const savedTheme = localStorage.getItem('theme') || 'light';
document.documentElement.setAttribute('data-theme', savedTheme);
const themeIcon = document.querySelector('#themeToggle i');
if (themeIcon) {
    themeIcon.className = savedTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
}

// ==========================================================================
// CHARTS INITIALIZATION - GRAFIK AKTIF
// ==========================================================================
function initCharts() {
    console.log('Initializing charts...');
    
    // Activity Chart (ApexCharts)
    if (document.querySelector("#activityChart")) {
        const activityOptions = {
            series: [{
                name: 'Pesan',
                data: chartCounts
            }],
            chart: {
                type: 'area',
                height: 250,
                toolbar: { show: false },
                animations: { enabled: true }
            },
            colors: ['#4361ee'],
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.7,
                    opacityTo: 0.3,
                }
            },
            dataLabels: {
                enabled: true,
                offsetY: -10,
                style: {
                    fontSize: '12px',
                    colors: ['#304758']
                }
            },
            xaxis: {
                categories: chartDates,
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return val + ' pesan'
                    }
                }
            }
        };
        
        activityChart = new ApexCharts(document.querySelector("#activityChart"), activityOptions);
        activityChart.render();
    }
    
    // Status Chart (Chart.js)
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Diproses', 'Selesai', 'Ditolak'],
                datasets: [{
                    data: [statusPending, statusDiproses, statusSelesai, statusDitolak],
                    backgroundColor: ['#ffc107', '#0dcaf0', '#198754', '#dc3545'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Response Rate Chart
    const responseCtx = document.getElementById('responseRateChart');
    if (responseCtx) {
        responseRateChart = new Chart(responseCtx, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [responseRate, 100 - responseRate],
                    backgroundColor: ['#198754', '#e9ecef'],
                    borderWidth: 0,
                    circumference: 360,
                    rotation: 270
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '70%',
                plugins: {
                    tooltip: { enabled: false },
                    legend: { display: false }
                }
            }
        });
    }
}

// ==========================================================================
// NOTIFICATIONS
// ==========================================================================
function showNotifications() {
    const unreadCount = <?php echo $unreadCount; ?>;
    const pendingCount = <?php echo $stats['pending'] ?? 0; ?>;
    const dibacaCount = <?php echo $stats['dibaca'] ?? 0; ?>;
    const diprosesCount = <?php echo $stats['diproses'] ?? 0; ?>;
    
    Swal.fire({
        title: 'Notifikasi',
        html: `
            <div class="text-start">
                <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                    <span><i class="fas fa-clock text-warning me-2"></i>Pending</span>
                    <span class="fw-bold">${pendingCount}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                    <span><i class="fas fa-eye text-info me-2"></i>Dibaca</span>
                    <span class="fw-bold">${dibacaCount}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                    <span><i class="fas fa-cog text-primary me-2"></i>Diproses</span>
                    <span class="fw-bold">${diprosesCount}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center p-2">
                    <span><i class="fas fa-bell text-danger me-2"></i>Total</span>
                    <span class="fw-bold">${unreadCount}</span>
                </div>
            </div>
        `,
        icon: 'info',
        confirmButtonText: 'Tutup',
        confirmButtonColor: '#3085d6'
    });
}

// ==========================================================================
// FILTER FUNCTIONS
// ==========================================================================
function updateActiveFilters() {
    let count = 0;
    if (document.getElementById('statusFilter').value !== 'all') count++;
    if (document.getElementById('typeFilter').value !== 'all') count++;
    if (document.getElementById('priorityFilter').value !== 'all') count++;
    if (document.querySelector('input[name="date_from"]').value) count++;
    if (document.querySelector('input[name="date_to"]').value) count++;
    if (document.querySelector('input[name="search"]').value) count++;
    
    const activeFiltersEl = document.getElementById('activeFilters');
    if (activeFiltersEl) {
        activeFiltersEl.innerText = count + ' filter aktif';
    }
}

function resetAllFilters() {
    window.location.href = window.location.pathname;
}

// ==========================================================================
// BULK ACTIONS
// ==========================================================================
function toggleSelectAll() {
    const checkboxes = document.querySelectorAll('.message-checkbox');
    const selectAll = document.getElementById('selectAll');
    const selectAllHeader = document.getElementById('selectAllHeader');
    
    checkboxes.forEach(cb => {
        cb.checked = (selectAll && selectAll.checked) || (selectAllHeader && selectAllHeader.checked);
        if (cb.checked) {
            if (!selectedIds.includes(cb.value)) {
                selectedIds.push(cb.value);
            }
        } else {
            selectedIds = selectedIds.filter(id => id !== cb.value);
        }
    });
    
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.message-checkbox:checked');
    selectedIds = Array.from(checkboxes).map(cb => cb.value);
    
    const selectedCountEl = document.getElementById('selectedCount');
    if (selectedCountEl) {
        selectedCountEl.innerText = selectedIds.length + ' dipilih';
    }
    
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    if (bulkActionsBar) {
        bulkActionsBar.style.display = selectedIds.length > 0 ? 'flex' : 'none';
    }
}

function bulkAction(action) {
    if (selectedIds.length === 0) return;
    
    let confirmText = '';
    switch(action) {
        case 'mark_read':
            confirmText = 'Tandai ' + selectedIds.length + ' pesan sebagai dibaca?';
            break;
        case 'delete':
            confirmText = 'Hapus ' + selectedIds.length + ' pesan?';
            break;
    }
    
    Swal.fire({
        title: 'Konfirmasi',
        text: confirmText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Ya, lanjutkan!'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="bulk_action" value="${action}">
                ${selectedIds.map(id => `<input type="hidden" name="selected_ids[]" value="${id}">`).join('')}
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function bulkExport() {
    if (selectedIds.length === 0) return;
    window.location.href = '?export=csv&ids=' + selectedIds.join(',');
}

// ==========================================================================
// PREVIEW GAMBAR - MODAL DENGAN Z-INDEX TINGGI
// ==========================================================================
function previewImage(imageUrl, imageName) {
    console.log('Previewing image:', imageName, 'URL:', imageUrl);
    
    // Pastikan modal image preview sudah diinisialisasi
    if (!imagePreviewModal) {
        const imagePreviewModalEl = document.getElementById('imagePreviewModal');
        if (imagePreviewModalEl) {
            imagePreviewModal = new bootstrap.Modal(imagePreviewModalEl, {
                backdrop: 'static',
                keyboard: false
            });
        } else {
            console.error('Image preview modal element not found');
            return;
        }
    }
    
    // Tampilkan modal preview
    imagePreviewModal.show();
    
    const container = document.getElementById('imagePreviewContainer');
    const downloadBtn = document.getElementById('downloadImageBtn');
    
    // Update judul modal
    document.querySelector('#imagePreviewModal .modal-title').innerHTML = `
        <i class="fas fa-image me-2"></i>
        Preview: ${imageName.substring(0, 50)}${imageName.length > 50 ? '...' : ''}
    `;
    
    // Tampilkan loading
    container.innerHTML = `
        <div class="text-center p-5">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Memuat gambar...</p>
        </div>
    `;
    
    // Buat image object untuk preload
    const img = new Image();
    
    img.onload = function() {
        // Gambar berhasil dimuat
        container.innerHTML = '';
        container.appendChild(img);
        
        // Update download button
        downloadBtn.href = imageUrl;
        downloadBtn.download = imageName;
        downloadBtn.style.display = 'inline-block';
        downloadBtn.onclick = function(e) {
            e.stopPropagation();
        };
    };
    
    img.onerror = function() {
        // Gambar gagal dimuat
        container.innerHTML = `
            <div class="text-center p-5">
                <i class="fas fa-exclamation-triangle text-warning fa-4x mb-3"></i>
                <h6 class="text-muted">Gambar tidak dapat dimuat</h6>
                <p class="text-muted small mb-4">File mungkin telah dihapus, dipindahkan, atau rusak.</p>
                <img src="${placeholderImage}" class="img-fluid opacity-50" style="max-height: 200px;">
                <div class="mt-4">
                    <a href="${imageUrl}" class="btn btn-outline-primary" target="_blank">
                        <i class="fas fa-external-link-alt me-1"></i> Buka di Tab Baru
                    </a>
                </div>
            </div>
        `;
        downloadBtn.style.display = 'none';
    };
    
    // Set atribut gambar
    img.src = imageUrl + '?t=' + new Date().getTime(); // Hindari cache
    img.alt = imageName;
    img.className = 'img-fluid rounded-3';
    img.style.maxHeight = '70vh';
    img.style.maxWidth = '100%';
    img.style.objectFit = 'contain';
}

// ==========================================================================
// MESSAGE FUNCTIONS - DETAIL PESAN DENGAN THUMBNAIL LANGSUNG
// ==========================================================================
function viewMessage(messageId, messageData) {
    console.log('Viewing message:', messageId);
    
    currentMessageId = messageId;
    
    // Inisialisasi modal jika belum
    if (!messageModal) {
        const messageModalEl = document.getElementById('messageModal');
        if (messageModalEl) {
            messageModal = new bootstrap.Modal(messageModalEl, {
                backdrop: 'static'
            });
        }
    }
    
    // Tampilkan modal
    messageModal.show();
    
    // Tampilkan loading
    document.getElementById('messageDetailContent').innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Memuat detail pesan...</p>
        </div>
    `;
    
    // Update header dengan reference_number dan ID jika data tersedia
    if (messageData) {
        const refElement = document.getElementById('detailMessageReference');
        const idElement = document.getElementById('detailMessageId');
        
        if (refElement) {
            refElement.textContent = messageData.reference_number || `REF-${String(messageData.id).padStart(6, '0')}`;
        }
        if (idElement) {
            idElement.textContent = `ID: ${messageData.id || '-'}`;
        }
    }
    
    // Fetch detail pesan
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 detik timeout
    
    fetch('ajax/get_message_detail.php?message_id=' + messageId, {
        signal: controller.signal,
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
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
        console.log('Response data received');
        
        if (data.success && data.html) {
            // Render HTML dari server
            document.getElementById('messageDetailContent').innerHTML = data.html;
            
            // Update header dengan data dari response jika ada
            if (data.message) {
                const refElement = document.getElementById('detailMessageReference');
                const idElement = document.getElementById('detailMessageId');
                
                if (refElement && data.message.reference_number) {
                    refElement.textContent = data.message.reference_number;
                }
                if (idElement && data.message.id) {
                    idElement.textContent = `ID: ${data.message.id}`;
                }
            }
            
            // Inisialisasi thumbnail preview setelah HTML dimuat
            initThumbnailPreviews();
        } else {
            throw new Error(data.error || 'Gagal memuat detail pesan');
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
            <div class="alert alert-danger m-4">
                <i class="fas fa-exclamation-circle me-2"></i>
                ${errorMessage}
                <button class="btn btn-sm btn-outline-danger mt-2" onclick="viewMessage(${messageId})">
                    <i class="fas fa-sync me-1"></i> Coba Lagi
                </button>
            </div>
        `;
    });
}

// ==========================================================================
// ATTACHMENT FUNCTIONS - SEPERTI DI FOLLOWUP.PHP
// ==========================================================================

/**
 * View message attachments dalam modal terpisah
 */
function viewAttachments(messageId) {
    console.log('viewAttachments called with messageId:', messageId);
    
    // Inisialisasi modal attachments jika belum
    if (!attachmentsModal) {
        const attachmentsModalEl = document.getElementById('attachmentsModal');
        if (attachmentsModalEl) {
            attachmentsModal = new bootstrap.Modal(attachmentsModalEl, {
                backdrop: 'static'
            });
        }
    }
    
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
 * Display attachments dalam bentuk grid
 */
function displayAttachments(attachments, isExternal) {
    const container = document.getElementById('attachmentsContent');
    
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
        const imageUrl = baseUrl + '/' + att.filepath;
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
                             onerror="this.onerror=null; this.src='${placeholderImage}'; this.style.objectFit='contain'; this.style.padding='10px';">
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
 * Preview image dari modal attachments (modal bertingkat)
 */
function previewImageFromAttachments(imageUrl, imageName) {
    console.log('previewImageFromAttachments called:', imageName);
    
    // Panggil fungsi previewImage yang sama
    previewImage(imageUrl, imageName);
}

// ==========================================================================
// INITIALIZE THUMBNAIL PREVIEWS
// ==========================================================================
function initThumbnailPreviews() {
    console.log('Initializing thumbnail previews');
    
    // Cari semua elemen dengan class thumbnail-preview
    document.querySelectorAll('.thumbnail-preview').forEach(thumbnail => {
        const imageUrl = thumbnail.getAttribute('data-image-url');
        const imageName = thumbnail.getAttribute('data-image-name') || 'image.jpg';
        
        thumbnail.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            previewImage(imageUrl, imageName);
        });
    });
    
    // Cari semua tombol preview
    document.querySelectorAll('.preview-image-btn').forEach(btn => {
        const imageUrl = btn.getAttribute('data-image-url');
        const imageName = btn.getAttribute('data-image-name') || 'image.jpg';
        
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            previewImage(imageUrl, imageName);
        });
    });
}

// ==========================================================================
// QUICK SEARCH
// ==========================================================================
function quickSearch() {
    const searchTerm = document.getElementById('quickSearch').value;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.value = searchTerm;
    }
    document.getElementById('filterForm').submit();
}

function useTemplate(templateId) {
    Swal.fire('Info', 'Fitur template dalam pengembangan', 'info');
}

function showStats() {
    Swal.fire({
        title: 'Statistik Detail',
        html: `
            <div class="text-start">
                <p>Total Pesan: <strong><?php echo $stats['total'] ?? 0; ?></strong></p>
                <p>Pesan Direspons: <strong><?php echo $stats['responded_count'] ?? 0; ?></strong></p>
                <p>Rata-rata Respons: <strong><?php echo $stats['avg_response_hours'] ? round($stats['avg_response_hours'], 1) . ' jam' : '-'; ?></strong></p>
                <p>Aktivitas 7 Hari: <strong><?php echo $stats['last_7_days'] ?? 0; ?></strong></p>
            </div>
        `,
        icon: 'info'
    });
}

function showHelp() {
    Swal.fire({
        title: 'Bantuan',
        html: `
            <div class="text-start">
                <p><i class="fas fa-keyboard me-2"></i> <strong>Shortcuts:</strong></p>
                <ul>
                    <li>Ctrl+F - Cari</li>
                    <li>Ctrl+N - Pesan Baru</li>
                    <li>Esc - Tutup Modal</li>
                </ul>
            </div>
        `,
        icon: 'info'
    });
}

// ==========================================================================
// HELPER FUNCTIONS
// ==========================================================================
function getStatusBadge(status) {
    const colors = {
        'Pending': 'bg-warning',
        'Dibaca': 'bg-info',
        'Diproses': 'bg-primary',
        'Disetujui': 'bg-success',
        'Ditolak': 'bg-danger',
        'Selesai': 'bg-secondary'
    };
    const color = colors[status] || 'bg-secondary';
    return `<span class="badge ${color}">${escapeHtml(status || 'Unknown')}</span>`;
}

function getPriorityBadge(priority) {
    const colors = {
        'Low': 'success',
        'Medium': 'warning',
        'High': 'danger',
        'Urgent': 'dark'
    };
    const color = colors[priority] || 'secondary';
    return `<span class="badge bg-${color}">${escapeHtml(priority || 'Unknown')}</span>`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ==========================================================================
// QUICK MENU
// ==========================================================================
function toggleQuickMenu() {
    const quickMenu = document.getElementById('quickMenu');
    if (quickMenu) {
        quickMenu.classList.toggle('show');
    }
}

// ==========================================================================
// DEBUG FUNCTIONS
// ==========================================================================
function toggleDebugPanel() {
    const debugPanel = document.getElementById('debugPanel');
    if (debugPanel) {
        debugPanel.classList.toggle('visible');
    }
}

function toggleDebugContent() {
    const content = document.getElementById('debugContent');
    if (content) {
        content.style.display = content.style.display === 'none' ? 'block' : 'none';
    }
}

// ==========================================================================
// KEYBOARD SHORTCUTS
// ==========================================================================
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        const quickSearch = document.getElementById('quickSearch');
        if (quickSearch) quickSearch.focus();
    }
    
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        window.location.href = 'send_message.php';
    }
    
    if (e.key === 'Escape') {
        // Tutup modal preview terlebih dahulu jika ada
        if (imagePreviewModal && document.getElementById('imagePreviewModal').classList.contains('show')) {
            imagePreviewModal.hide();
        } else if (attachmentsModal && document.getElementById('attachmentsModal').classList.contains('show')) {
            attachmentsModal.hide();
        } else {
            // Tutup modal message
            const messageModalInstance = bootstrap.Modal.getInstance(document.getElementById('messageModal'));
            if (messageModalInstance) messageModalInstance.hide();
        }
    }
});

// ==========================================================================
// INITIALIZATION
// ==========================================================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing...');
    
    // Initialize modals
    const imagePreviewModalEl = document.getElementById('imagePreviewModal');
    if (imagePreviewModalEl) {
        imagePreviewModal = new bootstrap.Modal(imagePreviewModalEl, {
            backdrop: 'static',
            keyboard: false
        });
        
        // Set z-index lebih tinggi
        imagePreviewModalEl.style.zIndex = '1060';
    }
    
    const messageModalEl = document.getElementById('messageModal');
    if (messageModalEl) {
        messageModal = new bootstrap.Modal(messageModalEl, {
            backdrop: 'static'
        });
    }
    
    const attachmentsModalEl = document.getElementById('attachmentsModal');
    if (attachmentsModalEl) {
        attachmentsModal = new bootstrap.Modal(attachmentsModalEl, {
            backdrop: 'static'
        });
    }
    
    // Handle modal stacking
    if (imagePreviewModalEl) {
        imagePreviewModalEl.addEventListener('show.bs.modal', function() {
            // Kurangi opacity backdrop modal sebelumnya
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 0) {
                backdrops.forEach((backdrop, index) => {
                    if (index < backdrops.length - 1) {
                        backdrop.style.opacity = '0.3';
                    }
                });
            }
        });
        
        imagePreviewModalEl.addEventListener('hidden.bs.modal', function() {
            // Kembalikan opacity backdrop
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.opacity = '';
            });
        });
    }
    
    // Handle modal show events untuk attachments
    if (attachmentsModalEl) {
        attachmentsModalEl.addEventListener('show.bs.modal', function() {
            console.log('Attachments modal opened');
        });
        
        attachmentsModalEl.addEventListener('shown.bs.modal', function() {
            console.log('Attachments modal shown');
        });
    }
    
    initCharts();
    updateActiveFilters();
    
    // Close quick menu when clicking outside
    document.addEventListener('click', function(e) {
        const quickMenu = document.getElementById('quickMenu');
        const quickBtn = document.querySelector('.quick-actions-btn');
        
        if (quickMenu && quickBtn && !quickBtn.contains(e.target) && !quickMenu.contains(e.target)) {
            quickMenu.classList.remove('show');
        }
    });
    
    if (debugEnabled) {
        console.log('Debug mode enabled');
    }
});

// Fungsi untuk memuat thumbnail dengan lazy loading
function lazyLoadThumbnails() {
    const thumbnails = document.querySelectorAll('.thumbnail-preview[data-src], .attachment-preview img[loading="lazy"]');
    
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    const src = img.getAttribute('data-src') || img.src;
                    
                    if (img.hasAttribute('data-src')) {
                        img.src = img.getAttribute('data-src');
                        img.removeAttribute('data-src');
                    }
                    
                    // Add error handling
                    img.onerror = function() {
                        this.onerror = null;
                        this.src = placeholderImage;
                        this.style.objectFit = 'contain';
                        this.style.padding = '10px';
                    };
                    
                    observer.unobserve(img);
                }
            });
        });
        
        thumbnails.forEach(thumbnail => {
            imageObserver.observe(thumbnail);
        });
    }
}

// Panggil lazy loading setelah konten dimuat
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', lazyLoadThumbnails);
} else {
    lazyLoadThumbnails();
}

// Fungsi untuk memformat ukuran file
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Fungsi untuk memformat tanggal
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Export functions ke global scope
window.formatFileSize = formatFileSize;
window.formatDate = formatDate;
window.lazyLoadThumbnails = lazyLoadThumbnails;
</script>

<!-- Custom Styles -->
<style>
.icon-circle {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.icon-circle-sm {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.chart-container {
    position: relative;
    width: 100%;
}

.card {
    border-radius: 12px;
    transition: all 0.2s ease;
}

.card:hover {
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.08) !important;
}

.badge {
    font-weight: 500;
    padding: 0.5em 0.8em;
}

.table th {
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Quick Actions */
.quick-actions {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    z-index: 1000;
}

.quick-actions-btn {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    cursor: pointer;
    box-shadow: 0 5px 20px rgba(102,126,234,0.4);
    transition: all 0.3s ease;
}

.quick-actions-btn:hover {
    transform: scale(1.1);
}

.quick-actions-menu {
    position: absolute;
    bottom: 70px;
    right: 0;
    background: white;
    border-radius: 16px;
    padding: 1rem;
    min-width: 200px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    display: none;
}

.quick-actions-menu.show {
    display: block;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.quick-actions-menu a {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    color: #333;
    text-decoration: none;
    border-radius: 12px;
    transition: all 0.2s ease;
}

.quick-actions-menu a:hover {
    background: #f8f9fa;
    transform: translateX(5px);
}

/* Debug Panel */
.debug-panel {
    position: fixed;
    bottom: 100px;
    right: 20px;
    width: 400px;
    background: #1e1e2f;
    color: white;
    border-radius: 10px;
    display: none;
    z-index: 10000;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
}

.debug-panel.visible { display: block; }

.debug-header {
    background: #2d2d44;
    padding: 12px 15px;
    cursor: pointer;
    border-radius: 10px 10px 0 0;
}

.debug-content {
    padding: 15px;
    max-height: 400px;
    overflow-y: auto;
    font-size: 12px;
}

.debug-toggle-btn {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #1e1e2f;
    color: white;
    border: 2px solid #ff9900;
    border-radius: 50px;
    padding: 8px 15px;
    cursor: pointer;
    z-index: 10001;
    font-size: 14px;
}

/* Thumbnail Styles */
.thumbnail-preview {
    cursor: pointer;
    transition: all 0.2s ease;
    border: 2px solid transparent;
}

.thumbnail-preview:hover {
    transform: scale(1.02);
    border-color: #667eea;
    box-shadow: 0 5px 15px rgba(102,126,234,0.3);
}

.preview-image-btn {
    cursor: pointer;
    transition: all 0.2s ease;
}

.preview-image-btn:hover {
    transform: scale(1.1);
}

/* Attachment Styles - SEPERTI DI FOLLOWUP.PHP */
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

/* Modal stacking */
.modal#imagePreviewModal {
    z-index: 1060 !important;
}

.modal-backdrop + .modal-backdrop {
    z-index: 1059 !important;
}

.modal-backdrop.show:nth-child(2) {
    opacity: 0.5;
}

.modal-backdrop.show:nth-child(3) {
    opacity: 0.3;
}

/* Message Detail Modal */
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
    min-height: 400px;
}

#messageModal .modal-footer {
    border-top: 1px solid rgba(0,0,0,0.05);
    padding: 1.2rem 2rem;
}

.detail-card {
    animation: fadeInUp 0.4s ease-out;
}

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

/* Thumbnail Gallery */
.thumbnail-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.thumbnail-item {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    cursor: pointer;
    aspect-ratio: 1 / 1;
}

.thumbnail-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(102,126,234,0.4);
}

.thumbnail-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.thumbnail-item .thumbnail-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.thumbnail-item:hover .thumbnail-overlay {
    opacity: 1;
}

.thumbnail-item .thumbnail-overlay i {
    color: white;
    font-size: 1.5rem;
}

/* Preview Image Modal */
#imagePreviewModal .modal-content {
    border: none;
    border-radius: 16px;
    overflow: hidden;
}

#imagePreviewModal .modal-header {
    border-bottom: none;
    padding: 1rem 1.5rem;
}

#imagePreviewModal .modal-body {
    padding: 1.5rem;
    min-height: 300px;
    max-height: 80vh;
    overflow-y: auto;
}

#imagePreviewModal .modal-footer {
    border-top: 1px solid rgba(255,255,255,0.1);
    padding: 1rem 1.5rem;
}

/* Responsive */
@media (max-width: 768px) {
    .icon-circle {
        width: 40px;
        height: 40px;
        font-size: 18px;
    }
    
    .debug-panel {
        width: 300px;
    }
    
    .thumbnail-gallery {
        grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    }
    
    #messageModal .modal-body {
        padding: 1rem !important;
    }
}

/* Dark Mode Support */
[data-theme="dark"] {
    --bg-primary: #1a1a2e;
    --bg-secondary: #16213e;
    --text-primary: #e1e1e1;
    --text-secondary: #b0b0b0;
    --border-color: #2a2a3a;
}

[data-theme="dark"] .card {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
}

[data-theme="dark"] .table {
    color: var(--text-primary);
}

[data-theme="dark"] .table thead th {
    background-color: var(--bg-primary);
    color: var(--text-primary);
    border-bottom-color: var(--border-color);
}

[data-theme="dark"] .table td {
    border-bottom-color: var(--border-color);
}

[data-theme="dark"] .bg-light {
    background-color: var(--bg-primary) !important;
}

[data-theme="dark"] .text-muted {
    color: var(--text-secondary) !important;
}

[data-theme="dark"] .border-bottom {
    border-bottom-color: var(--border-color) !important;
}

[data-theme="dark"] .modal-content {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
}

[data-theme="dark"] .modal-header {
    border-bottom-color: var(--border-color);
}

[data-theme="dark"] .modal-footer {
    border-top-color: var(--border-color);
    background-color: var(--bg-primary) !important;
}

[data-theme="dark"] .close {
    color: var(--text-primary);
}

[data-theme="dark"] .attachment-item {
    background-color: var(--bg-primary);
    border-color: var(--border-color);
}

[data-theme="dark"] .attachment-preview {
    background-color: var(--bg-primary);
}

[data-theme="dark"] .quick-actions-menu {
    background-color: var(--bg-secondary);
    border: 1px solid var(--border-color);
}

[data-theme="dark"] .quick-actions-menu a {
    color: var(--text-primary);
}

[data-theme="dark"] .quick-actions-menu a:hover {
    background-color: var(--bg-primary);
}

[data-theme="dark"] .badge.bg-light {
    background-color: var(--bg-primary) !important;
    color: var(--text-primary) !important;
}

[data-theme="dark"] .list-group-item {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

[data-theme="dark"] .list-group-item:hover {
    background-color: var(--bg-primary);
}

[data-theme="dark"] .icon-circle-sm {
    background-color: var(--bg-primary) !important;
}

[data-theme="dark"] .bg-white {
    background-color: var(--bg-secondary) !important;
}

[data-theme="dark"] .btn-outline-secondary {
    color: var(--text-secondary);
    border-color: var(--border-color);
}

[data-theme="dark"] .btn-outline-secondary:hover {
    background-color: var(--bg-primary);
    color: var(--text-primary);
}

[data-theme="dark"] .dropdown-menu {
    background-color: var(--bg-secondary);
    border-color: var(--border-color);
}

[data-theme="dark"] .dropdown-item {
    color: var(--text-primary);
}

[data-theme="dark"] .dropdown-item:hover {
    background-color: var(--bg-primary);
    color: var(--text-primary);
}

[data-theme="dark"] .form-control {
    background-color: var(--bg-primary);
    border-color: var(--border-color);
    color: var(--text-primary);
}

[data-theme="dark"] .form-control:focus {
    background-color: var(--bg-primary);
    color: var(--text-primary);
}

[data-theme="dark"] .form-select {
    background-color: var(--bg-primary);
    border-color: var(--border-color);
    color: var(--text-primary);
}

[data-theme="dark"] .input-group-text {
    background-color: var(--bg-primary);
    border-color: var(--border-color);
    color: var(--text-primary);
}

/* Animation */
@keyframes pulse {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
    100% {
        transform: scale(1);
    }
}

.pulse-animation {
    animation: pulse 2s infinite;
}

/* Loading Spinner */
.spinner-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255,255,255,0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
}

[data-theme="dark"] .spinner-overlay {
    background: rgba(0,0,0,0.8);
}

/* Tooltip Custom */
.custom-tooltip {
    position: relative;
    display: inline-block;
}

.custom-tooltip .tooltip-text {
    visibility: hidden;
    width: 120px;
    background-color: #333;
    color: #fff;
    text-align: center;
    border-radius: 6px;
    padding: 5px;
    position: absolute;
    z-index: 1;
    bottom: 125%;
    left: 50%;
    margin-left: -60px;
    opacity: 0;
    transition: opacity 0.3s;
}

.custom-tooltip:hover .tooltip-text {
    visibility: visible;
    opacity: 1;
}

/* Status Indicators */
.status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 5px;
}

.status-indicator.pending {
    background-color: #ffc107;
    box-shadow: 0 0 10px #ffc107;
}

.status-indicator.processing {
    background-color: #0dcaf0;
    box-shadow: 0 0 10px #0dcaf0;
}

.status-indicator.completed {
    background-color: #198754;
    box-shadow: 0 0 10px #198754;
}

.status-indicator.rejected {
    background-color: #dc3545;
    box-shadow: 0 0 10px #dc3545;
}

/* Progress Bar */
.progress-sm {
    height: 5px;
}

.progress {
    background-color: var(--border-color);
}

.progress-bar {
    transition: width 0.6s ease;
}

/* Scrollbar Custom */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

[data-theme="dark"] ::-webkit-scrollbar-track {
    background: #2a2a3a;
}

[data-theme="dark"] ::-webkit-scrollbar-thumb {
    background: #4a4a5a;
}

[data-theme="dark"] ::-webkit-scrollbar-thumb:hover {
    background: #5a5a6a;
}

/* Print Styles */
@media print {
    .quick-actions,
    .debug-panel,
    .debug-toggle-btn,
    .btn,
    .modal-footer,
    .no-print {
        display: none !important;
    }
    
    .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    
    .table {
        border-collapse: collapse !important;
    }
    
    .table th,
    .table td {
        background-color: white !important;
        color: black !important;
        border: 1px solid #ddd !important;
    }
}

/* Utility Classes */
.cursor-pointer {
    cursor: pointer;
}

.transition-all {
    transition: all 0.3s ease;
}

.rotate-90 {
    transform: rotate(90deg);
}

.rotate-180 {
    transform: rotate(180deg);
}

/* Success Animation */
@keyframes checkmark {
    0% {
        transform: scale(0);
    }
    50% {
        transform: scale(1.2);
    }
    100% {
        transform: scale(1);
    }
}

.checkmark-animation {
    animation: checkmark 0.5s ease-in-out;
}

/* Error Animation */
@keyframes shake {
    0%, 100% {
        transform: translateX(0);
    }
    10%, 30%, 50%, 70%, 90% {
        transform: translateX(-5px);
    }
    20%, 40%, 60%, 80% {
        transform: translateX(5px);
    }
}

.shake-animation {
    animation: shake 0.6s ease-in-out;
}

/* Loading Skeleton */
.skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% {
        background-position: 200% 0;
    }
    100% {
        background-position: -200% 0;
    }
}

[data-theme="dark"] .skeleton {
    background: linear-gradient(90deg, #2a2a3a 25%, #3a3a4a 50%, #2a2a3a 75%);
    background-size: 200% 100%;
}

/* Image Preview Container */
#imagePreviewContainer {
    min-height: 300px;
    display: flex;
    align-items: center;
    justify-content: center;
}

#imagePreviewContainer img {
    max-width: 100%;
    max-height: 70vh;
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
}

/* Thumbnail Container */
.thumbnails-container {
    max-height: 400px;
    overflow-y: auto;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 12px;
}

[data-theme="dark"] .thumbnails-container {
    background: var(--bg-primary);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem;
}

.empty-state i {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 1rem;
}

[data-theme="dark"] .empty-state i {
    color: #4a4a5a;
}

.empty-state h5 {
    color: #6c757d;
    margin-bottom: 0.5rem;
}

[data-theme="dark"] .empty-state h5 {
    color: #b0b0b0;
}

.empty-state p {
    color: #adb5bd;
}

[data-theme="dark"] .empty-state p {
    color: #8a8a9a;
}

/* Badge Styles */
.badge.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.badge.bg-gradient-success {
    background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
    color: #333;
}

.badge.bg-gradient-danger {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
}

.badge.bg-gradient-warning {
    background: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
    color: #333;
}

/* Responsive Table */
@media (max-width: 992px) {
    .table-responsive {
        border: 0;
    }
    
    .table thead {
        display: none;
    }
    
    .table tr {
        display: block;
        margin-bottom: 1rem;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 0.5rem;
    }
    
    .table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem;
        border: none;
        border-bottom: 1px solid #eee;
    }
    
    .table td:last-child {
        border-bottom: none;
    }
    
    .table td::before {
        content: attr(data-label);
        font-weight: 600;
        margin-right: 1rem;
        color: #6c757d;
    }
    
    [data-theme="dark"] .table td::before {
        color: #b0b0b0;
    }
    
    [data-theme="dark"] .table tr {
        border-color: var(--border-color);
    }
    
    [data-theme="dark"] .table td {
        border-bottom-color: var(--border-color);
    }
}
</style>

<!-- Add this script for handling modal stacking and image preview -->
<script>
// Additional script untuk memastikan modal stacking berfungsi dengan baik
document.addEventListener('DOMContentLoaded', function() {
    // Handle modal show events
    const messageModal = document.getElementById('messageModal');
    const imagePreviewModal = document.getElementById('imagePreviewModal');
    const attachmentsModal = document.getElementById('attachmentsModal');
    
    if (messageModal) {
        messageModal.addEventListener('show.bs.modal', function() {
            // Simpan reference ke modal yang aktif
            window.currentModal = 'message';
        });
        
        messageModal.addEventListener('hidden.bs.modal', function() {
            window.currentModal = null;
        });
    }
    
    if (imagePreviewModal) {
        imagePreviewModal.addEventListener('show.bs.modal', function() {
            // Pastikan z-index cukup tinggi
            this.style.zIndex = '1060';
            
            // Kurangi opacity backdrop modal sebelumnya
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 0) {
                backdrops.forEach((backdrop, index) => {
                    if (index < backdrops.length - 1) {
                        backdrop.style.opacity = '0.3';
                    }
                });
            }
        });
        
        imagePreviewModal.addEventListener('hidden.bs.modal', function() {
            // Kembalikan opacity backdrop
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.opacity = '';
            });
        });
    }
    
    if (attachmentsModal) {
        attachmentsModal.addEventListener('show.bs.modal', function() {
            window.currentModal = 'attachments';
        });
        
        attachmentsModal.addEventListener('hidden.bs.modal', function() {
            window.currentModal = null;
        });
    }
    
    // Handle keyboard events untuk modal stacking
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // Cek apakah modal preview terbuka
            if (imagePreviewModal && imagePreviewModal.classList.contains('show')) {
                e.preventDefault();
                const modal = bootstrap.Modal.getInstance(imagePreviewModal);
                if (modal) modal.hide();
            }
        }
    });
    
    // Prevent event bubbling saat klik di dalam modal preview
    if (imagePreviewModal) {
        imagePreviewModal.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
    
    // Fungsi untuk memuat gambar dengan error handling
    window.loadImageWithFallback = function(imgElement, fallbackUrl) {
        imgElement.onerror = function() {
            this.onerror = null;
            this.src = fallbackUrl || placeholderImage;
            this.style.objectFit = 'contain';
            this.style.padding = '10px';
        };
    };
    
    // Inisialisasi semua gambar dengan fallback
    document.querySelectorAll('img[data-src]').forEach(img => {
        const src = img.getAttribute('data-src');
        img.src = src;
        loadImageWithFallback(img);
    });
});
</script>

<?php
// ============================================================================
// FOOTER
// ============================================================================
require_once '../../includes/footer.php';
?>