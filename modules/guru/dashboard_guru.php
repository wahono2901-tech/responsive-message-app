<?php
/**
 * Guru Dashboard with Integrated Analytics
 * File: modules/guru/dashboard_guru.php
 * 
 * REVISI MAJOR V3:
 * - Mengambil data dari dua sumber: message_responses (responder_id) DAN berdasarkan jenis_pesan
 * - Menampilkan SEMUA status pesan (Pending, Dibaca, Diproses, Disetujui, Ditolak, Selesai)
 * - Data berdasarkan guru yang login (baik sebagai responder maupun berdasarkan jenis pesan)
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check authentication and guru privilege
Auth::checkAuth();

// Only specific guru types can access this page
$allowedTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Guru', 'Admin', 'Wakil_Kepala', 'Kepala_Sekolah'];
if (!in_array($_SESSION['user_type'], $allowedTypes)) {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

$guruId = $_SESSION['user_id'];
$guruType = $_SESSION['user_type'];
$guruName = $_SESSION['nama_lengkap'] ?? 'Guru';

// Map guru type to message type
$typeMap = [
    'Guru_BK' => 'Konsultasi/Konseling',
    'Guru_Humas' => 'Kehumasan',
    'Guru_Kurikulum' => 'Kurikulum',
    'Guru_Kesiswaan' => 'Kesiswaan',
    'Guru_Sarana' => 'Sarana Prasarana',
    'Guru' => 'Umum',
    'Admin' => 'Administrasi',
    'Wakil_Kepala' => 'Manajemen',
    'Kepala_Sekolah' => 'Kepemimpinan'
];

$assignedType = $typeMap[$guruType] ?? '';

// Get time filter dengan default ALL TIME
$timeFilter = $_GET['time'] ?? 'all';
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
    case 'all':
    default:
        $timeFilter = 'all';
        $startDate = '1970-01-01';
        break;
}

// Database connection
$db = Database::getInstance()->getConnection();

// ============================================================================
// 0. GET MESSAGE TYPE ID FOR THIS GURU (untuk fallback)
// ============================================================================
$messageTypeId = 0;
try {
    $typeStmt = $db->prepare("SELECT id FROM message_types WHERE jenis_pesan = :jenis_pesan");
    $typeStmt->execute([':jenis_pesan' => $assignedType]);
    $messageType = $typeStmt->fetch();
    
    if ($messageType) {
        $messageTypeId = $messageType['id'];
        error_log("Dashboard Guru - Message type found: $assignedType (ID: $messageTypeId)");
    } else {
        error_log("Dashboard Guru - Message type not found for: $assignedType");
    }
} catch (PDOException $e) {
    error_log("Error getting message type ID: " . $e->getMessage());
}

// ============================================================================
// 1. GET COMPLETE STATISTICS - DARI DUA SUMBER
// ============================================================================
$stats = [
    'total_assigned' => 0,
    'pending' => 0,
    'dibaca' => 0,
    'diproses' => 0,
    'disetujui' => 0,
    'ditolak' => 0,
    'selesai' => 0,
    'expired' => 0,
    'avg_response_time' => 0,
    'total_responses' => 0
];

try {
    // QUERY 1: Pesan yang direspons oleh guru ini (melalui message_responses)
    // atau pesan yang memiliki responder_id = guru_id
    $sql1 = "
        SELECT 
            m.id,
            m.status,
            m.created_at,
            mr.created_at as response_date,
            'responded' as source
        FROM messages m
        INNER JOIN message_responses mr ON m.id = mr.message_id AND mr.responder_id = :guru_id
    ";
    
    // QUERY 2: Pesan yang menjadi tanggung jawab guru berdasarkan jenis_pesan
    // (untuk pesan yang belum direspons atau responder_id NULL)
    $sql2 = "
        SELECT 
            m.id,
            m.status,
            m.created_at,
            NULL as response_date,
            'assigned' as source
        FROM messages m
        WHERE m.jenis_pesan_id = :type_id
    ";
    
    $params1 = [':guru_id' => $guruId];
    $params2 = [':type_id' => $messageTypeId];
    
    // Tambahkan filter tanggal jika bukan 'all'
    if ($timeFilter !== 'all') {
        $sql1 .= " AND m.created_at >= :start_date";
        $sql2 .= " AND m.created_at >= :start_date";
        $params1[':start_date'] = $startDate;
        $params2[':start_date'] = $startDate;
    }
    
    // Gabungkan kedua query
    $combinedSql = "
        SELECT * FROM (
            $sql1
            UNION
            $sql2
        ) AS combined_messages
        GROUP BY id
        ORDER BY created_at DESC
    ";
    
    // Eksekusi query gabungan
    $combinedStmt = $db->prepare($combinedSql);
    $allMessages = [];
    
    // Execute first part
    $stmt1 = $db->prepare($sql1);
    $stmt1->execute($params1);
    $messagesFromResponses = $stmt1->fetchAll();
    
    // Execute second part if message type exists
    $messagesFromType = [];
    if ($messageTypeId > 0) {
        $stmt2 = $db->prepare($sql2);
        $stmt2->execute($params2);
        $messagesFromType = $stmt2->fetchAll();
    }
    
    // Gabungkan dan hilangkan duplikat
    $allMessagesById = [];
    foreach ($messagesFromResponses as $msg) {
        $allMessagesById[$msg['id']] = $msg;
    }
    foreach ($messagesFromType as $msg) {
        if (!isset($allMessagesById[$msg['id']])) {
            $allMessagesById[$msg['id']] = $msg;
        }
    }
    $allMessages = array_values($allMessagesById);
    
    // Hitung statistik dari data yang sudah digabung
    $stats['total_assigned'] = count($allMessages);
    
    foreach ($allMessages as $msg) {
        switch ($msg['status']) {
            case 'Pending':
                $stats['pending']++;
                break;
            case 'Dibaca':
                $stats['dibaca']++;
                break;
            case 'Diproses':
                $stats['diproses']++;
                break;
            case 'Disetujui':
                $stats['disetujui']++;
                break;
            case 'Ditolak':
                $stats['ditolak']++;
                break;
            case 'Selesai':
                $stats['selesai']++;
                break;
        }
        
        // Hitung expired (pesan > 72 jam dan belum selesai)
        if (in_array($msg['status'], ['Pending', 'Dibaca', 'Diproses'])) {
            $created = strtotime($msg['created_at']);
            if ((time() - $created) > 72 * 3600) {
                $stats['expired']++;
            }
        }
        
        // Hitung waktu respons jika ada
        if (!empty($msg['response_date'])) {
            $stats['total_responses']++;
            $responseTime = (strtotime($msg['response_date']) - strtotime($msg['created_at'])) / 3600;
            $stats['avg_response_time'] += $responseTime;
        }
    }
    
    // Hitung rata-rata waktu respons
    if ($stats['total_responses'] > 0) {
        $stats['avg_response_time'] = round($stats['avg_response_time'] / $stats['total_responses'], 1);
    }
    
    error_log("Dashboard Guru - Combined stats for user_id: $guruId");
    error_log("Dashboard Guru - Total messages: {$stats['total_assigned']}");
    error_log("Dashboard Guru - Status breakdown: Pending={$stats['pending']}, Dibaca={$stats['dibaca']}, Diproses={$stats['diproses']}, Disetujui={$stats['disetujui']}, Ditolak={$stats['ditolak']}, Selesai={$stats['selesai']}");
    
} catch (PDOException $e) {
    error_log("Error getting statistics: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

// ============================================================================
// 2. GET STATUS DISTRIBUTION - DARI DATA YANG SUDAH DIGABUNG
// ============================================================================
$statusDistribution = [];
$statusColors = [
    'Pending' => '#ffc107',
    'Dibaca' => '#17a2b8',
    'Diproses' => '#0d6efd',
    'Disetujui' => '#198754',
    'Ditolak' => '#dc3545',
    'Selesai' => '#6c757d'
];

$allStatuses = ['Pending', 'Dibaca', 'Diproses', 'Disetujui', 'Ditolak', 'Selesai'];

$totalStatus = max(1, $stats['total_assigned']);
foreach ($allStatuses as $status) {
    $count = $stats[strtolower($status)] ?? 0;
    $statusDistribution[] = [
        'status' => $status,
        'count' => $count,
        'percentage' => round(($count / $totalStatus) * 100, 1),
        'color' => $statusColors[$status] ?? '#6c757d'
    ];
}

// ============================================================================
// 3. GET TIME-BASED TRENDS - DARI DATA YANG SUDAH DIGABUNG
// ============================================================================
$chartLabels = [];
$chartPendingData = [];
$chartDibacaData = [];
$chartDiprosesData = [];
$chartDisetujuiData = [];
$chartDitolakData = [];
$chartSelesaiData = [];

try {
    // Query untuk trend per tanggal
    $trendsSql = "
        SELECT 
            DATE(m.created_at) as date,
            SUM(CASE WHEN m.status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN m.status = 'Dibaca' THEN 1 ELSE 0 END) as dibaca_count,
            SUM(CASE WHEN m.status = 'Diproses' THEN 1 ELSE 0 END) as diproses_count,
            SUM(CASE WHEN m.status = 'Disetujui' THEN 1 ELSE 0 END) as disetujui_count,
            SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END) as ditolak_count,
            SUM(CASE WHEN m.status = 'Selesai' THEN 1 ELSE 0 END) as selesai_count
        FROM messages m
        WHERE (
            m.jenis_pesan_id = :type_id 
            OR EXISTS (SELECT 1 FROM message_responses mr WHERE mr.message_id = m.id AND mr.responder_id = :guru_id)
        )
    ";
    
    $params = [
        ':guru_id' => $guruId,
        ':type_id' => $messageTypeId
    ];
    
    if ($timeFilter !== 'all') {
        $trendsSql .= " AND m.created_at >= :start_date";
        $params[':start_date'] = $startDate;
    }
    
    $trendsSql .= " GROUP BY DATE(m.created_at) ORDER BY date ASC";
    
    $trendsStmt = $db->prepare($trendsSql);
    $trendsStmt->execute($params);
    $trends = $trendsStmt->fetchAll();
    
    // Batasi maksimum 15 data point untuk grafik
    $maxDataPoints = 15;
    $trendsCount = count($trends);
    
    if ($trendsCount > 0) {
        if ($trendsCount > $maxDataPoints) {
            $step = ceil($trendsCount / $maxDataPoints);
            $sampledTrends = [];
            for ($i = 0; $i < $trendsCount; $i += $step) {
                $sampledTrends[] = $trends[$i];
            }
            $trends = $sampledTrends;
        }
        
        foreach ($trends as $trend) {
            $chartLabels[] = date('d M', strtotime($trend['date']));
            $chartPendingData[] = (int)$trend['pending_count'];
            $chartDibacaData[] = (int)$trend['dibaca_count'];
            $chartDiprosesData[] = (int)$trend['diproses_count'];
            $chartDisetujuiData[] = (int)$trend['disetujui_count'];
            $chartDitolakData[] = (int)$trend['ditolak_count'];
            $chartSelesaiData[] = (int)$trend['selesai_count'];
        }
    }
    
} catch (PDOException $e) {
    error_log("Error getting trends: " . $e->getMessage());
}

// Default empty data jika tidak ada
if (empty($chartLabels)) {
    $chartLabels = ['Belum Ada Data'];
    $chartPendingData = [0];
    $chartDibacaData = [0];
    $chartDiprosesData = [0];
    $chartDisetujuiData = [0];
    $chartDitolakData = [0];
    $chartSelesaiData = [0];
}

// ============================================================================
// 4. GET PERFORMANCE METRICS
// ============================================================================
$performance = [
    'total_messages_handled' => $stats['total_assigned'],
    'messages_resolved' => $stats['disetujui'] + $stats['selesai'],
    'total_responses_given' => $stats['total_responses'],
    'avg_response_time' => $stats['avg_response_time'],
    'by_status' => $statusDistribution
];

// ============================================================================
// 5. GET RECENT ACTIVITY - DARI DUA SUMBER
// ============================================================================
$recentActivity = [];

try {
    $recentSql = "
        SELECT 
            m.id as message_id,
            m.isi_pesan as content,
            m.status,
            m.created_at as message_date,
            m.tanggal_respon,
            COALESCE(u.nama_lengkap, m.pengirim_nama, 'Pengirim Tidak Dikenal') as sender_name,
            COALESCE(u.user_type, 'Internal') as sender_type,
            COALESCE(u.nis_nip, m.pengirim_nis_nip, '-') as sender_info,
            mr.id as response_id,
            mr.catatan_respon as response_content,
            mr.created_at as response_date,
            (SELECT COUNT(*) FROM wakepsek_reviews WHERE message_id = m.id) as review_count,
            (SELECT catatan FROM wakepsek_reviews WHERE message_id = m.id ORDER BY created_at DESC LIMIT 1) as latest_review
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN message_responses mr ON m.id = mr.message_id AND mr.responder_id = :guru_id
        WHERE (
            m.jenis_pesan_id = :type_id 
            OR EXISTS (SELECT 1 FROM message_responses mr2 WHERE mr2.message_id = m.id AND mr2.responder_id = :guru_id2)
        )
    ";
    
    $params = [
        ':guru_id' => $guruId,
        ':guru_id2' => $guruId,
        ':type_id' => $messageTypeId
    ];
    
    if ($timeFilter !== 'all') {
        $recentSql .= " AND m.created_at >= :start_date";
        $params[':start_date'] = $startDate;
    }
    
    $recentSql .= " ORDER BY m.created_at DESC LIMIT 20";
    
    $recentStmt = $db->prepare($recentSql);
    $recentStmt->execute($params);
    $recentActivity = $recentStmt->fetchAll();
    
    error_log("Dashboard Guru - Recent activity count: " . count($recentActivity));
    
} catch (PDOException $e) {
    error_log("Error getting recent activity: " . $e->getMessage());
}

$pageTitle = 'Dashboard Guru - Analisis Pesan Lengkap (Semua Status)';

require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-chart-line me-2"></i>Dashboard Analisis Pesan
                <span class="badge bg-primary ms-2">Semua Status</span>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="followup.php">Follow-Up</a></li>
                    <li class="breadcrumb-item active">Dashboard</li>
                </ol>
            </nav>
            <p class="text-muted small mb-0">
                <i class="fas fa-user-check me-1"></i>
                Menampilkan pesan yang direspons oleh <strong><?php echo htmlspecialchars($guruName); ?></strong> 
                (<?php echo str_replace('_', ' ', $guruType); ?>) + pesan jenis <strong><?php echo htmlspecialchars($assignedType); ?></strong>
            </p>
        </div>
        <div class="d-flex align-items-center mt-2 mt-sm-0">
            <div class="text-end me-3">
                <span class="badge bg-success p-2">
                    <i class="fas fa-check-circle me-1"></i>
                    Total: <?php echo number_format($stats['total_assigned'] ?? 0); ?> pesan
                </span>
                <span class="badge bg-info p-2 ms-2">
                    <i class="fas fa-reply-all me-1"></i>
                    Direspons: <?php echo number_format($stats['total_responses'] ?? 0); ?>
                </span>
            </div>
            <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#exportModal" <?php echo ($stats['total_assigned'] ?? 0) == 0 ? 'disabled' : ''; ?>>
                <i class="fas fa-file-pdf me-1"></i> Ekspor Laporan
            </button>
        </div>
    </div>
    
    <!-- Time Filter -->
    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-calendar-alt me-2"></i>Rentang Waktu Analisis
                    </h5>
                    <p class="text-muted small mb-0">
                        <?php if ($timeFilter === 'all'): ?>
                        <span class="badge bg-primary">SEMUA WAKTU</span> - Data dari awal hingga <?php echo date('d M Y'); ?>
                        <?php else: ?>
                        <?php echo date('d M Y', strtotime($startDate)); ?> - <?php echo date('d M Y', strtotime($endDate)); ?>
                        (<?php echo round((strtotime($endDate) - strtotime($startDate)) / (60 * 60 * 24)); ?> hari)
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-6">
                    <form method="GET" class="row g-2" id="filterForm">
                        <div class="col-md-8">
                            <select class="form-select" name="time" id="timeFilter" onchange="document.getElementById('filterForm').submit()">
                                <option value="all" <?php echo $timeFilter === 'all' ? 'selected' : ''; ?>>Semua Waktu</option>
                                <option value="7days" <?php echo $timeFilter === '7days' ? 'selected' : ''; ?>>7 Hari Terakhir</option>
                                <option value="30days" <?php echo $timeFilter === '30days' ? 'selected' : ''; ?>>30 Hari Terakhir</option>
                                <option value="90days" <?php echo $timeFilter === '90days' ? 'selected' : ''; ?>>90 Hari Terakhir</option>
                                <option value="year" <?php echo $timeFilter === 'year' ? 'selected' : ''; ?>>1 Tahun Terakhir</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div class="btn-group w-100">
                                <a href="followup.php" class="btn btn-outline-primary">
                                    <i class="fas fa-tasks me-1"></i>Follow-Up
                                </a>
                                <button type="button" class="btn btn-outline-secondary" onclick="refreshDashboard(event)">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (($stats['total_assigned'] ?? 0) == 0): ?>
    <!-- Info jika tidak ada data -->
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Perhatian!</strong> Belum ada data untuk periode yang dipilih. 
        Pastikan ada pesan dengan jenis "<strong><?php echo htmlspecialchars($assignedType); ?></strong>" 
        atau pesan yang telah Anda respons.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php else: ?>
    <!-- Key Performance Indicators - SEMUA STATUS -->
    <div class="row g-3 mb-4">
        <!-- Total Pesan -->
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
                            <h2 class="mb-0"><?php echo number_format($stats['total_assigned'] ?? 0); ?></h2>
                            <small class="text-muted">Semua pesan terkait</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Status Counter - SEMUA 6 STATUS -->
        <div class="col-xl-9 col-md-12">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-muted mb-3">Status Pesan</h6>
                    <div class="row g-3">
                        <div class="col-md-2 col-6">
                            <div class="text-center p-2 rounded bg-warning bg-opacity-10">
                                <i class="fas fa-clock fa-2x text-warning"></i>
                                <h4 class="mb-0 mt-2"><?php echo number_format($stats['pending'] ?? 0); ?></h4>
                                <small class="text-muted">Pending</small>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="text-center p-2 rounded bg-info bg-opacity-10">
                                <i class="fas fa-eye fa-2x text-info"></i>
                                <h4 class="mb-0 mt-2"><?php echo number_format($stats['dibaca'] ?? 0); ?></h4>
                                <small class="text-muted">Dibaca</small>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="text-center p-2 rounded bg-primary bg-opacity-10">
                                <i class="fas fa-cog fa-2x text-primary"></i>
                                <h4 class="mb-0 mt-2"><?php echo number_format($stats['diproses'] ?? 0); ?></h4>
                                <small class="text-muted">Diproses</small>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="text-center p-2 rounded bg-success bg-opacity-10">
                                <i class="fas fa-check fa-2x text-success"></i>
                                <h4 class="mb-0 mt-2"><?php echo number_format($stats['disetujui'] ?? 0); ?></h4>
                                <small class="text-muted">Disetujui</small>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="text-center p-2 rounded bg-danger bg-opacity-10">
                                <i class="fas fa-times fa-2x text-danger"></i>
                                <h4 class="mb-0 mt-2"><?php echo number_format($stats['ditolak'] ?? 0); ?></h4>
                                <small class="text-muted">Ditolak</small>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="text-center p-2 rounded bg-secondary bg-opacity-10">
                                <i class="fas fa-flag-checkered fa-2x text-secondary"></i>
                                <h4 class="mb-0 mt-2"><?php echo number_format($stats['selesai'] ?? 0); ?></h4>
                                <small class="text-muted">Selesai</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Progress Bar for All Status -->
                    <div class="mt-3">
                        <div class="progress" style="height: 12px;">
                            <?php 
                            $total = max(1, $stats['total_assigned'] ?? 1);
                            $pendingPercent = ($stats['pending'] ?? 0) / $total * 100;
                            $dibacaPercent = ($stats['dibaca'] ?? 0) / $total * 100;
                            $diprosesPercent = ($stats['diproses'] ?? 0) / $total * 100;
                            $disetujuiPercent = ($stats['disetujui'] ?? 0) / $total * 100;
                            $ditolakPercent = ($stats['ditolak'] ?? 0) / $total * 100;
                            $selesaiPercent = ($stats['selesai'] ?? 0) / $total * 100;
                            ?>
                            <div class="progress-bar bg-warning" style="width: <?php echo $pendingPercent; ?>%" title="Pending: <?php echo $stats['pending']; ?>"></div>
                            <div class="progress-bar bg-info" style="width: <?php echo $dibacaPercent; ?>%" title="Dibaca: <?php echo $stats['dibaca']; ?>"></div>
                            <div class="progress-bar bg-primary" style="width: <?php echo $diprosesPercent; ?>%" title="Diproses: <?php echo $stats['diproses']; ?>"></div>
                            <div class="progress-bar bg-success" style="width: <?php echo $disetujuiPercent; ?>%" title="Disetujui: <?php echo $stats['disetujui']; ?>"></div>
                            <div class="progress-bar bg-danger" style="width: <?php echo $ditolakPercent; ?>%" title="Ditolak: <?php echo $stats['ditolak']; ?>"></div>
                            <div class="progress-bar bg-secondary" style="width: <?php echo $selesaiPercent; ?>%" title="Selesai: <?php echo $stats['selesai']; ?>"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Waktu Respons Rata-rata -->
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
                            <h6 class="text-muted mb-1">Rata Waktu Respons</h6>
                            <h2 class="mb-0"><?php echo number_format($stats['avg_response_time'] ?? 0, 1); ?> jam</h2>
                            <small class="text-muted">Dari pesan diterima hingga direspons</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Total Respon Diberikan -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-success">
                                <i class="fas fa-reply-all text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Respon Diberikan</h6>
                            <h2 class="mb-0"><?php echo number_format($stats['total_responses'] ?? 0); ?></h2>
                            <small class="text-muted">Total respon yang telah diberikan</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Charts Row -->
    <div class="row g-3 mb-4">
        <!-- Status Distribution Chart - SEMUA 6 STATUS -->
        <div class="col-lg-5">
            <div class="card border-0 shadow h-100">
                <div class="card-header bg-white border-bottom-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Distribusi Status Pesan
                    </h5>
                    <p class="text-muted small mb-0">Semua 6 status pesan</p>
                </div>
                <div class="card-body d-flex flex-column">
                    <div class="chart-container" style="position: relative; height: 220px;">
                        <canvas id="statusDistributionChart"></canvas>
                    </div>
                    <div class="mt-3">
                        <?php foreach ($statusDistribution as $status): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <span class="legend-color" style="background-color: <?php echo $status['color']; ?>;"></span>
                                <span class="small"><?php echo htmlspecialchars($status['status']); ?></span>
                            </div>
                            <div>
                                <span class="fw-bold"><?php echo $status['count']; ?></span>
                                <span class="text-muted small">(<?php echo $status['percentage']; ?>%)</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Message Trends by Status - SEMUA 6 STATUS -->
        <div class="col-lg-7">
            <div class="card border-0 shadow h-100">
                <div class="card-header bg-white border-bottom-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line me-2"></i>Trend Pesan Berdasarkan Status
                    </h5>
                    <p class="text-muted small mb-0">Perkembangan 6 status pesan per periode</p>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 280px;">
                        <canvas id="messageTrendsChart"></canvas>
                    </div>
                    <div class="mt-3 text-center d-flex flex-wrap justify-content-center gap-2">
                        <div class="d-inline-flex align-items-center me-2">
                            <span class="legend-color" style="background-color: #ffc107;"></span>
                            <span class="small ms-1">Pending</span>
                        </div>
                        <div class="d-inline-flex align-items-center me-2">
                            <span class="legend-color" style="background-color: #17a2b8;"></span>
                            <span class="small ms-1">Dibaca</span>
                        </div>
                        <div class="d-inline-flex align-items-center me-2">
                            <span class="legend-color" style="background-color: #0d6efd;"></span>
                            <span class="small ms-1">Diproses</span>
                        </div>
                        <div class="d-inline-flex align-items-center me-2">
                            <span class="legend-color" style="background-color: #198754;"></span>
                            <span class="small ms-1">Disetujui</span>
                        </div>
                        <div class="d-inline-flex align-items-center me-2">
                            <span class="legend-color" style="background-color: #dc3545;"></span>
                            <span class="small ms-1">Ditolak</span>
                        </div>
                        <div class="d-inline-flex align-items-center">
                            <span class="legend-color" style="background-color: #6c757d;"></span>
                            <span class="small ms-1">Selesai</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Performance Metrics by Status -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-bar me-2"></i>Performa Berdasarkan Status
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>Status Pesan</th>
                                    <th>Jumlah</th>
                                    <th>Persentase dari Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statusDistribution as $statusPerf): ?>
                                <tr>
                                    <td>
                                        <span class="badge" style="background-color: <?php echo $statusPerf['color']; ?>; color: white;">
                                            <i class="fas <?php echo match($statusPerf['status']) {
                                                'Pending' => 'fa-clock',
                                                'Dibaca' => 'fa-eye',
                                                'Diproses' => 'fa-cog',
                                                'Disetujui' => 'fa-check',
                                                'Ditolak' => 'fa-times',
                                                default => 'fa-flag-checkered'
                                            }; ?> me-1"></i>
                                            <?php echo htmlspecialchars($statusPerf['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold"><?php echo number_format($statusPerf['count']); ?></span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 6px; width: 150px;">
                                            <div class="progress-bar" style="width: <?php echo $statusPerf['percentage']; ?>%; background-color: <?php echo $statusPerf['color']; ?>"></div>
                                        </div>
                                        <small class="text-muted"><?php echo $statusPerf['percentage']; ?>% dari total</small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Aktivitas Pesan Terbaru -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-inbox me-2"></i>Aktivitas Pesan Terbaru
                    </h5>
                    <a href="followup.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-arrow-right me-1"></i>Lihat Semua
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentActivity)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                        <p class="text-muted">Belum ada aktivitas pesan terbaru</p>
                    </div>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentActivity as $activity): 
                            $statusColor = match($activity['status'] ?? '') {
                                'Disetujui' => 'success',
                                'Selesai' => 'secondary',
                                'Ditolak' => 'danger',
                                'Diproses' => 'primary',
                                'Dibaca' => 'info',
                                default => 'warning'
                            };
                        ?>
                        <div class="list-group-item list-group-item-action">
                            <div class="d-flex align-items-start">
                                <div class="flex-shrink-0 mt-1">
                                    <div class="icon-circle-sm bg-<?php echo $statusColor; ?>">
                                        <i class="fas fa-envelope text-white"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <div class="d-flex justify-content-between flex-wrap">
                                        <h6 class="mb-1">
                                            <?php echo htmlspecialchars($activity['sender_name'] ?? 'Pengirim Tidak Dikenal'); ?>
                                            <span class="badge bg-<?php echo $statusColor; ?> ms-2">
                                                <?php echo $activity['status'] ?? 'Pending'; ?>
                                            </span>
                                            <?php if (($activity['review_count'] ?? 0) > 0): ?>
                                            <span class="badge bg-warning ms-1" title="Telah direview">
                                                <i class="fas fa-gavel me-1"></i>Review
                                            </span>
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-muted">
                                            <?php echo date('d M Y H:i', strtotime($activity['message_date'] ?? 'now')); ?>
                                        </small>
                                    </div>
                                    <p class="mb-1 text-muted small">
                                        <?php echo htmlspecialchars(substr($activity['content'] ?? '', 0, 150)); ?>
                                        <?php if (strlen($activity['content'] ?? '') > 150): ?>...<?php endif; ?>
                                    </p>
                                    <?php if (!empty($activity['response_content'])): ?>
                                    <div class="mt-2 p-2 bg-light rounded small">
                                        <i class="fas fa-reply me-1 text-primary"></i>
                                        <strong>Respon Anda:</strong>
                                        <?php echo htmlspecialchars(substr($activity['response_content'], 0, 100)); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <a href="followup.php?search=<?php echo urlencode($activity['sender_name'] ?? ''); ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye me-1"></i>Lihat Detail
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
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
                    <i class="fas fa-file-export me-2"></i>Ekspor Laporan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Pilih format ekspor laporan dashboard:</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-danger" onclick="exportReport('pdf')">
                        <i class="fas fa-file-pdf me-2"></i>PDF Document
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="exportReport('excel')">
                        <i class="fas fa-file-excel me-2"></i>Excel Spreadsheet
                    </button>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Laporan mencakup semua data pesan yang terkait dengan Anda (baik direspons maupun berdasarkan jenis pesan).
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
// Chart initialization sama seperti sebelumnya
let isChartInitialized = false;
let charts = {};

document.addEventListener('DOMContentLoaded', function() {
    if (!isChartInitialized) {
        setTimeout(function() {
            initializeCharts();
            isChartInitialized = true;
        }, 100);
    }
});

function initializeCharts() {
    try {
        destroyExistingCharts();
        createStatusChart();
        createTrendsChart();
    } catch (error) {
        console.error('Error initializing charts:', error);
    }
}

function destroyExistingCharts() {
    ['statusDistributionChart', 'messageTrendsChart'].forEach(chartId => {
        const canvas = document.getElementById(chartId);
        if (canvas) {
            const existingChart = Chart.getChart(canvas);
            if (existingChart) {
                existingChart.destroy();
            }
        }
    });
}

function createStatusChart() {
    const canvas = document.getElementById('statusDistributionChart');
    if (!canvas) return;
    
    const labels = <?php echo json_encode(array_column($statusDistribution, 'status')); ?>;
    const data = <?php echo json_encode(array_column($statusDistribution, 'count')); ?>;
    const colors = <?php echo json_encode(array_column($statusDistribution, 'color')); ?>;
    
    const hasData = data.some(value => value > 0);
    let finalLabels = labels;
    let finalData = data;
    let finalColors = colors;
    
    if (!hasData && labels.length === 0) {
        finalLabels = ['Belum Ada Data'];
        finalData = [1];
        finalColors = ['#e9ecef'];
    }
    
    charts.statusChart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: finalLabels,
            datasets: [{
                data: finalData,
                backgroundColor: finalColors,
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

function createTrendsChart() {
    const canvas = document.getElementById('messageTrendsChart');
    if (!canvas) return;
    
    const labels = <?php echo json_encode($chartLabels); ?>;
    const pendingData = <?php echo json_encode($chartPendingData); ?>;
    const dibacaData = <?php echo json_encode($chartDibacaData); ?>;
    const diprosesData = <?php echo json_encode($chartDiprosesData); ?>;
    const disetujuiData = <?php echo json_encode($chartDisetujuiData); ?>;
    const ditolakData = <?php echo json_encode($chartDitolakData); ?>;
    const selesaiData = <?php echo json_encode($chartSelesaiData); ?>;
    
    charts.trendsChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'Pending', data: pendingData, backgroundColor: '#ffc107', borderRadius: 4 },
                { label: 'Dibaca', data: dibacaData, backgroundColor: '#17a2b8', borderRadius: 4 },
                { label: 'Diproses', data: diprosesData, backgroundColor: '#0d6efd', borderRadius: 4 },
                { label: 'Disetujui', data: disetujuiData, backgroundColor: '#198754', borderRadius: 4 },
                { label: 'Ditolak', data: ditolakData, backgroundColor: '#dc3545', borderRadius: 4 },
                { label: 'Selesai', data: selesaiData, backgroundColor: '#6c757d', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, font: { size: 10 } } },
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
            }
        }
    });
}

function refreshDashboard(event) {
    const btn = event.currentTarget;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    setTimeout(() => { window.location.reload(); }, 500);
}

function exportReport(format) {
    const btn = event.currentTarget;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyiapkan...';
    btn.disabled = true;
    const url = `export_dashboard_guru.php?format=${format}&time=<?php echo $timeFilter; ?>`;
    window.open(url, '_blank');
    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        const modal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
        if (modal) modal.hide();
    }, 1000);
}

let resizeTimeout;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function() {
        Object.values(charts).forEach(chart => { if (chart) chart.resize(); });
    }, 250);
});
</script>

<style>
.icon-circle { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; }
.icon-circle-sm { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; }
.chart-container { position: relative; width: 100%; height: 280px; }
.legend-color { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 6px; }
.progress { background-color: #e9ecef; border-radius: 4px; overflow: hidden; }
.badge { font-weight: 500; padding: 0.5em 0.8em; }
.list-group-item { border-left: none; border-right: none; border-color: rgba(0,0,0,0.05); }
.list-group-item:first-child { border-top: none; }
.list-group-item:last-child { border-bottom: none; }
.card { transition: all 0.2s ease; }
.card:hover { box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.08) !important; }
@media (max-width: 768px) {
    .icon-circle { width: 48px; height: 48px; font-size: 20px; }
    .h2 { font-size: 1.75rem; }
    .chart-container { height: 250px; }
}
</style>

<?php
require_once '../../includes/footer.php';
?>