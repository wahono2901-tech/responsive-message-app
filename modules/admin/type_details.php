<?php
/**
 * Detail Performa Jenis Pesan
 * File: modules/admin/type_details.php
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check authentication and admin privilege
Auth::checkAuth();
if ($_SESSION['user_type'] !== 'Admin' && $_SESSION['privilege_level'] !== 'Full_Access') {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

// Get message type ID
$type_id = $_GET['id'] ?? 0;
if (!$type_id) {
    header('Location: message_types.php?error=invalid_type');
    exit;
}

$db = Database::getInstance()->getConnection();

// Get message type details
$stmt = $db->prepare("
    SELECT 
        mt.*,
        COALESCE(COUNT(m.id), 0) as total_messages,
        COALESCE(SUM(CASE WHEN m.status = 'Pending' THEN 1 ELSE 0 END), 0) as pending_count,
        COALESCE(SUM(CASE WHEN m.status = 'Disetujui' THEN 1 ELSE 0 END), 0) as approved_count,
        COALESCE(SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END), 0) as rejected_count,
        COALESCE(SUM(CASE WHEN m.status = 'Diproses' THEN 1 ELSE 0 END), 0) as processed_count,
        COALESCE(SUM(CASE WHEN m.status = 'Selesai' THEN 1 ELSE 0 END), 0) as completed_count,
        COALESCE(SUM(CASE WHEN m.status = 'Dibaca' THEN 1 ELSE 0 END), 0) as read_count,
        COALESCE(AVG(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW()))), 0) as avg_response_time,
        COALESCE(MIN(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW()))), 0) as min_response_time,
        COALESCE(MAX(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW()))), 0) as max_response_time
    FROM message_types mt
    LEFT JOIN messages m ON mt.id = m.jenis_pesan_id
    WHERE mt.id = :type_id
    GROUP BY mt.id
");
$stmt->execute([':type_id' => $type_id]);
$type = $stmt->fetch();

if (!$type) {
    header('Location: message_types.php?error=type_not_found');
    exit;
}

// Calculate overdue count separately
$overdueStmt = $db->prepare("
    SELECT COUNT(*) as overdue_count
    FROM messages m
    WHERE m.jenis_pesan_id = :type_id
        AND TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW())) > :deadline
");
$overdueStmt->execute([
    ':type_id' => $type_id,
    ':deadline' => $type['response_deadline_hours']
]);
$overdueResult = $overdueStmt->fetch();
$type['overdue_count'] = $overdueResult['overdue_count'] ?? 0;

// Get recent messages for this type
$recentStmt = $db->prepare("
    SELECT 
        m.id,
        m.isi_pesan,
        m.status,
        m.priority,
        m.created_at,
        m.tanggal_respon,
        m.pengirim_nama as user_name,
        m.pengirim_nis_nip,
        TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW())) as response_hours
    FROM messages m
    WHERE m.jenis_pesan_id = :type_id
    ORDER BY m.created_at DESC
    LIMIT 50
");
$recentStmt->execute([':type_id' => $type_id]);
$recentMessages = $recentStmt->fetchAll();

// Calculate timeline status for each message
foreach ($recentMessages as &$message) {
    $response_hours = $message['response_hours'];
    $status = $message['status'];
    $tanggal_respon = $message['tanggal_respon'];
    
    if ($tanggal_respon) {
        if ($response_hours > $type['response_deadline_hours']) {
            $message['timeline_status'] = 'overdue';
        } else {
            $message['timeline_status'] = 'ontime';
        }
    } else {
        if ($response_hours > $type['response_deadline_hours'] && in_array($status, ['Pending', 'Dibaca'])) {
            $message['timeline_status'] = 'overdue';
        } else {
            $message['timeline_status'] = 'ontime';
        }
    }
}
unset($message); // Unset reference

// Get monthly statistics - simplified
$monthlyStmt = $db->prepare("
    SELECT 
        DATE_FORMAT(m.created_at, '%Y-%m') as month,
        COUNT(*) as total,
        SUM(CASE WHEN m.status = 'Selesai' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN m.status IN ('Pending', 'Dibaca') THEN 1 ELSE 0 END) as pending
    FROM messages m
    WHERE m.jenis_pesan_id = :type_id
        AND m.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(m.created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
");
$monthlyStmt->execute([':type_id' => $type_id]);
$monthlyStats = $monthlyStmt->fetchAll();

// Calculate additional metrics for monthly stats
foreach ($monthlyStats as &$stat) {
    // Get month
    $month = $stat['month'];
    
    // Calculate overdue for this month
    $overdueStmt = $db->prepare("
        SELECT COUNT(*) as overdue_count
        FROM messages m
        WHERE m.jenis_pesan_id = :type_id
            AND DATE_FORMAT(m.created_at, '%Y-%m') = :month
            AND TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW())) > :deadline
    ");
    $overdueStmt->execute([
        ':type_id' => $type_id,
        ':month' => $month,
        ':deadline' => $type['response_deadline_hours']
    ]);
    $overdueResult = $overdueStmt->fetch();
    $stat['overdue'] = $overdueResult['overdue_count'] ?? 0;
    
    // Calculate high priority for this month
    $highPriorityStmt = $db->prepare("
        SELECT COUNT(*) as high_priority_count
        FROM messages m
        WHERE m.jenis_pesan_id = :type_id
            AND DATE_FORMAT(m.created_at, '%Y-%m') = :month
            AND (m.priority = 'High' OR m.priority = 'Urgent')
    ");
    $highPriorityStmt->execute([
        ':type_id' => $type_id,
        ':month' => $month
    ]);
    $highPriorityResult = $highPriorityStmt->fetch();
    $stat['high_priority_count'] = $highPriorityResult['high_priority_count'] ?? 0;
}
unset($stat); // Unset reference

// Get status distribution
$statusStmt = $db->prepare("
    SELECT 
        status,
        COUNT(*) as count
    FROM messages 
    WHERE jenis_pesan_id = :type_id
    GROUP BY status
    ORDER BY FIELD(status, 'Pending', 'Dibaca', 'Diproses', 'Disetujui', 'Ditolak', 'Selesai'), count DESC
");
$statusStmt->execute([':type_id' => $type_id]);
$statusDistribution = $statusStmt->fetchAll();

// Calculate percentage for each status
$totalStatusCount = array_sum(array_column($statusDistribution, 'count'));
foreach ($statusDistribution as &$status) {
    $status['percentage'] = $totalStatusCount > 0 ? round(($status['count'] / $totalStatusCount) * 100, 1) : 0;
}
unset($status); // Unset reference

// Get response time distribution - simplified without complex CASE
$timeStmt = $db->prepare("
    SELECT 
        TIMESTAMPDIFF(HOUR, created_at, COALESCE(tanggal_respon, NOW())) as response_hours
    FROM messages 
    WHERE jenis_pesan_id = :type_id
        AND tanggal_respon IS NOT NULL
");
$timeStmt->execute([':type_id' => $type_id]);
$timeResults = $timeStmt->fetchAll();

// Categorize response times manually in PHP
$timeDistribution = [
    ['time_range' => '≤24 jam', 'count' => 0],
    ['time_range' => '25-48 jam', 'count' => 0],
    ['time_range' => '49-72 jam', 'count' => 0],
    ['time_range' => '73-' . $type['response_deadline_hours'] . ' jam', 'count' => 0],
    ['time_range' => '>' . $type['response_deadline_hours'] . ' jam', 'count' => 0]
];

foreach ($timeResults as $result) {
    $hours = $result['response_hours'];
    if ($hours <= 24) {
        $timeDistribution[0]['count']++;
    } elseif ($hours <= 48) {
        $timeDistribution[1]['count']++;
    } elseif ($hours <= 72) {
        $timeDistribution[2]['count']++;
    } elseif ($hours <= $type['response_deadline_hours']) {
        $timeDistribution[3]['count']++;
    } else {
        $timeDistribution[4]['count']++;
    }
}

// Get top senders for this message type
$sendersStmt = $db->prepare("
    SELECT 
        m.pengirim_nama as full_name,
        m.pengirim_nis_nip as identifier,
        COUNT(m.id) as message_count,
        SUM(CASE WHEN m.status = 'Selesai' THEN 1 ELSE 0 END) as completed_count
    FROM messages m
    WHERE m.jenis_pesan_id = :type_id
    GROUP BY m.pengirim_nama, m.pengirim_nis_nip
    ORDER BY message_count DESC
    LIMIT 10
");
$sendersStmt->execute([':type_id' => $type_id]);
$topSenders = $sendersStmt->fetchAll();

// Calculate high priority count for each sender
foreach ($topSenders as &$sender) {
    $highPriorityStmt = $db->prepare("
        SELECT COUNT(*) as high_priority_count
        FROM messages m
        WHERE m.jenis_pesan_id = :type_id
            AND m.pengirim_nama = :name
            AND (m.priority = 'High' OR m.priority = 'Urgent')
    ");
    $highPriorityStmt->execute([
        ':type_id' => $type_id,
        ':name' => $sender['full_name']
    ]);
    $highPriorityResult = $highPriorityStmt->fetch();
    $sender['high_priority_count'] = $highPriorityResult['high_priority_count'] ?? 0;
}
unset($sender); // Unset reference

// Get priority distribution
$priorityStmt = $db->prepare("
    SELECT 
        priority,
        COUNT(*) as count
    FROM messages 
    WHERE jenis_pesan_id = :type_id
    GROUP BY priority
    ORDER BY FIELD(priority, 'Urgent', 'High', 'Medium', 'Low')
");
$priorityStmt->execute([':type_id' => $type_id]);
$priorityDistribution = $priorityStmt->fetchAll();

// Calculate percentage for each priority
$totalPriorityCount = array_sum(array_column($priorityDistribution, 'count'));
foreach ($priorityDistribution as &$priority) {
    $priority['percentage'] = $totalPriorityCount > 0 ? round(($priority['count'] / $totalPriorityCount) * 100, 1) : 0;
}
unset($priority); // Unset reference

// Helper function for status colors in PHP
function getStatusColor($status) {
    $colors = [
        'Pending' => '#ffc107',
        'Dibaca' => '#17a2b8',
        'Diproses' => '#0dcaf0',
        'Disetujui' => '#198754',
        'Ditolak' => '#dc3545',
        'Selesai' => '#6c757d'
    ];
    return $colors[$status] ?? '#6c757d';
}

// Helper function for priority colors
function getPriorityColor($priority) {
    $colors = [
        'Urgent' => '#dc3545',
        'High' => '#fd7e14',
        'Medium' => '#0dcaf0',
        'Low' => '#6c757d'
    ];
    return $colors[$priority] ?? '#6c757d';
}

// Helper function for priority badge colors
function getPriorityBadgeColor($priority) {
    $colors = [
        'Urgent' => 'danger',
        'High' => 'warning',
        'Medium' => 'info',
        'Low' => 'secondary'
    ];
    return $colors[$priority] ?? 'secondary';
}

$pageTitle = 'Detail Jenis Pesan: ' . htmlspecialchars($type['jenis_pesan']);

require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-chart-bar me-2"></i>Detail Performa
                <small class="text-muted"><?php echo htmlspecialchars($type['jenis_pesan']); ?></small>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="message_types.php">Jenis Pesan</a></li>
                    <li class="breadcrumb-item active">Detail</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="message_types.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left me-1"></i> Kembali
            </a>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Cetak Laporan
            </button>
        </div>
    </div>

    <!-- Type Info Card -->
    <div class="row mb-4">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center">
                                <div class="widget-icon bg-primary-light rounded-circle p-3 me-3">
                                    <i class="fas fa-tag fa-2x text-primary"></i>
                                </div>
                                <div>
                                    <h3 class="mb-1"><?php echo htmlspecialchars($type['jenis_pesan']); ?></h3>
                                    <?php if (!empty($type['description'])): ?>
                                    <p class="text-muted mb-2"><?php echo htmlspecialchars($type['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-<?php echo $type['is_active'] ? 'success' : 'secondary'; ?>">
                                            <i class="fas fa-circle me-1"></i><?php echo $type['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                        </span>
                                        <span class="badge bg-info">
                                            <i class="fas fa-clock me-1"></i>Deadline: <?php echo $type['response_deadline_hours']; ?> jam
                                        </span>
                                        <span class="badge bg-primary">
                                            <i class="fas fa-comments me-1"></i><?php echo number_format($type['total_messages']); ?> pesan
                                        </span>
                                        <span class="badge bg-<?php echo $type['avg_response_time'] <= $type['response_deadline_hours'] ? 'success' : 'danger'; ?>">
                                            <i class="fas fa-tachometer-alt me-1"></i>Rata: <?php echo number_format($type['avg_response_time'], 1); ?> jam
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="btn-group">
                                <a href="message_types.php?action=edit&id=<?php echo $type['id']; ?>" 
                                   class="btn btn-outline-primary">
                                    <i class="fas fa-edit me-1"></i> Edit
                                </a>
                                <button onclick="viewMessagesByType(<?php echo $type['id']; ?>)" 
                                        class="btn btn-outline-success">
                                    <i class="fas fa-eye me-1"></i> Lihat Pesan
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Performance Overview -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Total Pesan</h6>
                            <h2 class="mb-0 text-primary"><?php echo number_format($type['total_messages']); ?></h2>
                        </div>
                        <div class="widget-icon bg-primary-light rounded-circle p-3">
                            <i class="fas fa-comments fa-2x text-primary"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">6 bulan terakhir: <?php 
                            $recentTotal = array_sum(array_column($monthlyStats, 'total'));
                            echo number_format($recentTotal);
                        ?> pesan</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Selesai</h6>
                            <h2 class="mb-0 text-success"><?php echo number_format($type['completed_count']); ?></h2>
                        </div>
                        <div class="widget-icon bg-success-light rounded-circle p-3">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <?php 
                            $completionRate = $type['total_messages'] > 0 ? ($type['completed_count'] / $type['total_messages']) * 100 : 0;
                            ?>
                            Tingkat penyelesaian: <?php echo number_format($completionRate, 1); ?>%
                        </small>
                        <div class="progress mt-1" style="height: 5px;">
                            <div class="progress-bar bg-success" style="width: <?php echo $completionRate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Rata Waktu</h6>
                            <h2 class="mb-0 text-info"><?php echo number_format($type['avg_response_time'], 1); ?>h</h2>
                        </div>
                        <div class="widget-icon bg-info-light rounded-circle p-3">
                            <i class="fas fa-clock fa-2x text-info"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            Min: <?php echo number_format($type['min_response_time'], 1); ?>h | 
                            Max: <?php echo number_format($type['max_response_time'], 1); ?>h
                        </small>
                        <div class="progress mt-1" style="height: 5px;">
                            <div class="progress-bar bg-<?php echo $type['avg_response_time'] <= $type['response_deadline_hours'] ? 'success' : 'danger'; ?>" 
                                 style="width: <?php echo min(100, ($type['avg_response_time'] / $type['response_deadline_hours']) * 100); ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Melebihi Deadline</h6>
                            <h2 class="mb-0 text-danger"><?php echo number_format($type['overdue_count']); ?></h2>
                        </div>
                        <div class="widget-icon bg-danger-light rounded-circle p-3">
                            <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <?php 
                            $overdueRate = $type['total_messages'] > 0 ? ($type['overdue_count'] / $type['total_messages']) * 100 : 0;
                            ?>
                            <?php echo number_format($overdueRate, 1); ?>% dari total pesan
                        </small>
                        <div class="progress mt-1" style="height: 5px;">
                            <div class="progress-bar bg-danger" style="width: <?php echo $overdueRate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="row mb-4">
        <!-- Status Distribution -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Distribusi Status
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div class="mt-3">
                        <div class="row">
                            <?php foreach ($statusDistribution as $status): ?>
                            <div class="col-md-6 mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="fas fa-circle me-1" style="color: <?php echo getStatusColor($status['status']); ?>"></i>
                                        <?php echo htmlspecialchars($status['status']); ?>
                                    </span>
                                    <span class="fw-bold"><?php echo number_format($status['count']); ?> (<?php echo $status['percentage']; ?>%)</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Priority Distribution -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-exclamation-circle me-2"></i>Distribusi Prioritas
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="priorityChart"></canvas>
                    </div>
                    <div class="mt-3">
                        <?php foreach ($priorityDistribution as $priority): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>
                                <i class="fas fa-circle me-1" style="color: <?php echo getPriorityColor($priority['priority']); ?>"></i>
                                <?php echo htmlspecialchars($priority['priority']); ?>
                            </span>
                            <div class="d-flex align-items-center">
                                <div class="progress flex-grow-1 me-2" style="height: 8px; width: 150px;">
                                    <div class="progress-bar" style="width: <?php echo $priority['percentage']; ?>%; background-color: <?php echo getPriorityColor($priority['priority']); ?>"></div>
                                </div>
                                <span class="fw-bold"><?php echo number_format($priority['count']); ?> (<?php echo $priority['percentage']; ?>%)</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Trend and Top Senders -->
    <div class="row mb-4">
        <!-- Monthly Trend -->
        <div class="col-lg-8 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line me-2"></i>Trend 6 Bulan Terakhir
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Senders -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-users me-2"></i>Top 10 Pengirim
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th class="text-end">Jumlah</th>
                                    <th class="text-end">Prioritas Tinggi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topSenders as $sender): ?>
                                <tr>
                                    <td>
                                        <small class="d-block fw-bold"><?php echo htmlspecialchars($sender['full_name']); ?></small>
                                        <?php if (!empty($sender['identifier'])): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($sender['identifier']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end align-middle">
                                        <span class="badge bg-primary"><?php echo $sender['message_count']; ?></span>
                                    </td>
                                    <td class="text-end align-middle">
                                        <?php if ($sender['high_priority_count'] > 0): ?>
                                        <span class="badge bg-danger"><?php echo $sender['high_priority_count']; ?></span>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topSenders)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-3">
                                        <i class="fas fa-users-slash me-2"></i>Belum ada data pengirim
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

    <!-- Recent Messages -->
    <div class="row mb-4">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Pesan Terbaru
                            <span class="badge bg-primary ms-2"><?php echo min(50, count($recentMessages)); ?> pesan</span>
                        </h5>
                        <div>
                            <input type="text" id="searchMessages" class="form-control form-control-sm" 
                                   placeholder="Cari pesan..." style="width: 200px;">
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="recentMessagesTable">
                            <thead class="table-light">
                                <tr>
                                    <th width="50" class="py-3">#</th>
                                    <th class="py-3">Isi Pesan</th>
                                    <th class="py-3">Pengirim</th>
                                    <th class="py-3">Tanggal</th>
                                    <th class="py-3">Status</th>
                                    <th class="py-3">Prioritas</th>
                                    <th class="py-3">Waktu Respons</th>
                                    <th class="py-3">Timeline</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentMessages as $index => $message): ?>
                                <tr>
                                    <td class="align-middle">
                                        <span class="badge bg-light text-dark"><?php echo $index + 1; ?></span>
                                    </td>
                                    <td class="align-middle">
                                        <div class="fw-bold" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars(substr($message['isi_pesan'], 0, 100)); ?><?php echo strlen($message['isi_pesan']) > 100 ? '...' : ''; ?>
                                        </div>
                                    </td>
                                    <td class="align-middle">
                                        <div><?php echo htmlspecialchars($message['user_name']); ?></div>
                                        <?php if (!empty($message['pengirim_nis_nip'])): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($message['pengirim_nis_nip']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <?php echo date('d M Y', strtotime($message['created_at'])); ?>
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge bg-<?php 
                                            $statusColor = '';
                                            switch($message['status']) {
                                                case 'Pending': $statusColor = 'warning'; break;
                                                case 'Dibaca': $statusColor = 'info'; break;
                                                case 'Diproses': $statusColor = 'primary'; break;
                                                case 'Disetujui': $statusColor = 'success'; break;
                                                case 'Ditolak': $statusColor = 'danger'; break;
                                                case 'Selesai': $statusColor = 'secondary'; break;
                                                default: $statusColor = 'light'; break;
                                            }
                                            echo $statusColor;
                                        ?>">
                                            <?php echo htmlspecialchars($message['status']); ?>
                                        </span>
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge bg-<?php 
                                            $priorityColor = '';
                                            switch($message['priority']) {
                                                case 'Urgent': $priorityColor = 'danger'; break;
                                                case 'High': $priorityColor = 'warning'; break;
                                                case 'Medium': $priorityColor = 'info'; break;
                                                case 'Low': $priorityColor = 'secondary'; break;
                                                default: $priorityColor = 'light'; break;
                                            }
                                            echo $priorityColor;
                                        ?>">
                                            <?php echo htmlspecialchars($message['priority']); ?>
                                        </span>
                                    </td>
                                    <td class="align-middle">
                                        <?php if ($message['tanggal_respon']): ?>
                                        <span class="<?php echo $message['response_hours'] > $type['response_deadline_hours'] ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo number_format($message['response_hours'], 1); ?> jam
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <?php if ($message['timeline_status'] === 'overdue'): ?>
                                        <span class="badge bg-danger">
                                            <i class="fas fa-exclamation-circle me-1"></i>Terlambat
                                        </span>
                                        <?php elseif ($message['status'] === 'Selesai'): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i>Tepat Waktu
                                        </span>
                                        <?php else: ?>
                                        <?php
                                        $responseRatio = $type['response_deadline_hours'] > 0 ? $message['response_hours'] / $type['response_deadline_hours'] : 0;
                                        $timelineColor = $responseRatio > 0.8 ? 'warning' : 'info';
                                        ?>
                                        <span class="badge bg-<?php echo $timelineColor; ?>">
                                            <i class="fas fa-clock me-1"></i>Dalam Proses
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recentMessages)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox me-2"></i>Belum ada pesan untuk jenis ini
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white py-3">
                    <div class="text-end">
                        <a href="../messages/messages.php?type=<?php echo $type['id']; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-eye me-1"></i> Lihat Semua Pesan
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recommendations -->
    <div class="row">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-lightbulb me-2 text-warning"></i>Analisis & Rekomendasi
                    </h5>
                </div>
                <div class="card-body">
                    <?php
                    // Calculate metrics for recommendations
                    $pendingCount = $type['pending_count'] + $type['read_count'];
                    $pendingRate = $type['total_messages'] > 0 ? ($pendingCount / $type['total_messages']) * 100 : 0;
                    $rejectionRate = $type['total_messages'] > 0 ? ($type['rejected_count'] / $type['total_messages']) * 100 : 0;
                    $overdueRate = $type['total_messages'] > 0 ? ($type['overdue_count'] / $type['total_messages']) * 100 : 0;
                    $completionRate = $type['total_messages'] > 0 ? ($type['completed_count'] / $type['total_messages']) * 100 : 0;
                    
                    // Calculate high priority percentage
                    $highPriorityCount = 0;
                    foreach ($priorityDistribution as $priority) {
                        if ($priority['priority'] == 'High' || $priority['priority'] == 'Urgent') {
                            $highPriorityCount += $priority['count'];
                        }
                    }
                    $highPriorityRate = $type['total_messages'] > 0 ? ($highPriorityCount / $type['total_messages']) * 100 : 0;
                    ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-tachometer-alt me-2 text-primary"></i>Metrik Kinerja</h6>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <small>
                                        <i class="fas fa-circle text-<?php echo $completionRate >= 80 ? 'success' : ($completionRate >= 50 ? 'warning' : 'danger'); ?> me-1"></i>
                                        Tingkat Penyelesaian: <strong><?php echo number_format($completionRate, 1); ?>%</strong>
                                        <?php if ($completionRate < 80): ?>
                                        <br><span class="text-muted">Target minimal 80%</span>
                                        <?php endif; ?>
                                    </small>
                                </li>
                                <li class="mb-2">
                                    <small>
                                        <i class="fas fa-circle text-<?php echo $overdueRate <= 10 ? 'success' : ($overdueRate <= 20 ? 'warning' : 'danger'); ?> me-1"></i>
                                        Pesan Terlambat: <strong><?php echo number_format($overdueRate, 1); ?>%</strong>
                                        <?php if ($overdueRate > 10): ?>
                                        <br><span class="text-muted">Target maksimal 10%</span>
                                        <?php endif; ?>
                                    </small>
                                </li>
                                <li class="mb-2">
                                    <small>
                                        <i class="fas fa-circle text-<?php echo $pendingRate <= 15 ? 'success' : ($pendingRate <= 30 ? 'warning' : 'danger'); ?> me-1"></i>
                                        Pesan Belum Selesai: <strong><?php echo number_format($pendingRate, 1); ?>%</strong>
                                        <?php if ($pendingRate > 15): ?>
                                        <br><span class="text-muted">Target maksimal 15%</span>
                                        <?php endif; ?>
                                    </small>
                                </li>
                                <li class="mb-2">
                                    <small>
                                        <i class="fas fa-circle text-<?php echo $highPriorityRate <= 20 ? 'success' : ($highPriorityRate <= 40 ? 'warning' : 'danger'); ?> me-1"></i>
                                        Prioritas Tinggi: <strong><?php echo number_format($highPriorityRate, 1); ?>%</strong>
                                        <?php if ($highPriorityRate > 20): ?>
                                        <br><span class="text-muted">Target maksimal 20%</span>
                                        <?php endif; ?>
                                    </small>
                                </li>
                            </ul>
                        </div>
                        
                        <div class="col-md-6">
                            <h6><i class="fas fa-bullseye me-2 text-success"></i>Rekomendasi</h6>
                            <div class="recommendations">
                                <?php if ($overdueRate > 20): ?>
                                <div class="alert alert-danger mb-2">
                                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Prioritas Tinggi!</h6>
                                    <small>
                                        Tingkat keterlambatan sangat tinggi (<?php echo number_format($overdueRate, 1); ?>%). 
                                        Perlu penambahan sumber daya atau revisi deadline.
                                    </small>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($pendingRate > 25): ?>
                                <div class="alert alert-warning mb-2">
                                    <h6><i class="fas fa-clock me-2"></i>Percepat Proses</h6>
                                    <small>
                                        Banyak pesan masih pending (<?php echo number_format($pendingRate, 1); ?>%). 
                                        Evaluasi alur kerja dan tingkatkan efisiensi.
                                    </small>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($type['avg_response_time'] > $type['response_deadline_hours']): ?>
                                <div class="alert alert-info mb-2">
                                    <h6><i class="fas fa-chart-line me-2"></i>Optimasi Waktu Respons</h6>
                                    <small>
                                        Rata-rata waktu respons (<?php echo number_format($type['avg_response_time'], 1); ?>h) 
                                        melebihi deadline (<?php echo $type['response_deadline_hours']; ?>h).
                                    </small>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($completionRate < 70): ?>
                                <div class="alert alert-secondary mb-2">
                                    <h6><i class="fas fa-flag-checkered me-2"></i>Tingkatkan Penyelesaian</h6>
                                    <small>
                                        Tingkat penyelesaian rendah (<?php echo number_format($completionRate, 1); ?>%). 
                                        Perlu monitoring lebih ketat.
                                    </small>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($overdueRate <= 10 && $completionRate >= 85 && $pendingRate <= 15 && $highPriorityRate <= 20): ?>
                                <div class="alert alert-success">
                                    <h6><i class="fas fa-check-circle me-2"></i>Kinerja Optimal</h6>
                                    <small>
                                        Semua metrik dalam target yang ditetapkan. 
                                        Pertahankan performa ini.
                                    </small>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($type['total_messages'] == 0): ?>
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-info-circle me-2"></i>Data Terbatas</h6>
                                    <small>
                                        Belum ada pesan untuk jenis ini. Mulai promosikan atau evaluasi kebutuhan jenis pesan ini.
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-3 border-top">
                        <h6><i class="fas fa-calendar-alt me-2 text-info"></i>Tindakan yang Disarankan</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card border-0 bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title">Jangka Pendek (1-7 hari)</h6>
                                        <small>
                                            <ul class="ps-3">
                                                <li>Prioritaskan pesan terlambat</li>
                                                <li>Follow up pesan pending > 3 hari</li>
                                                <li>Verifikasi data statistik bulanan</li>
                                                <?php if ($highPriorityRate > 20): ?>
                                                <li>Tangani prioritas tinggi segera</li>
                                                <?php endif; ?>
                                            </ul>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card border-0 bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title">Jangka Menengah (1-3 bulan)</h6>
                                        <small>
                                            <ul class="ps-3">
                                                <li>Evaluasi alur kerja bulanan</li>
                                                <li>Training staf jika diperlukan</li>
                                                <li>Optimasi template respons</li>
                                                <?php if ($type['avg_response_time'] > $type['response_deadline_hours']): ?>
                                                <li>Review deadline respons</li>
                                                <?php endif; ?>
                                            </ul>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card border-0 bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title">Jangka Panjang (>3 bulan)</h6>
                                        <small>
                                            <ul class="ps-3">
                                                <li>Review kebijakan deadline</li>
                                                <li>Otomatisasi proses manual</li>
                                                <li>Benchmark dengan standar industri</li>
                                                <?php if ($type['total_messages'] < 10): ?>
                                                <li>Evaluasi kebutuhan jenis pesan</li>
                                                <?php endif; ?>
                                            </ul>
                                        </small>
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

<style>
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

.chart-container {
    position: relative;
}

.table th {
    font-weight: 600;
    color: #495057;
}

.table tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.02);
}

.alert h6 {
    font-size: 0.9rem;
    margin-bottom: 0.3rem;
}

.alert small {
    font-size: 0.85rem;
}

.recommendations .alert {
    padding: 0.75rem;
    margin-bottom: 0.5rem;
}

.card.bg-light {
    background-color: #f8f9fa !important;
}

@media print {
    .btn, .card-header h5 i, nav, .breadcrumb {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
    
    .card-header {
        background-color: #f8f9fa !important;
        border-bottom: 2px solid #dee2e6;
    }
    
    .table-responsive {
        overflow: visible !important;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initializeCharts();
    initializeSearch();
});

// Initialize all charts
function initializeCharts() {
    <?php if (!empty($statusDistribution)): ?>
    // Status Distribution Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusLabels = <?php echo json_encode(array_column($statusDistribution, 'status')); ?>;
    const statusData = <?php echo json_encode(array_column($statusDistribution, 'count')); ?>;
    
    // Define colors for status
    const statusColors = statusLabels.map(label => {
        const colors = {
            'Pending': '#ffc107',
            'Dibaca': '#17a2b8',
            'Diproses': '#0dcaf0',
            'Disetujui': '#198754',
            'Ditolak': '#dc3545',
            'Selesai': '#6c757d'
        };
        return colors[label] || '#6c757d';
    });
    
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusData,
                backgroundColor: statusColors,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 15
                    }
                }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($priorityDistribution)): ?>
    // Priority Distribution Chart
    const priorityCtx = document.getElementById('priorityChart').getContext('2d');
    const priorityLabels = <?php echo json_encode(array_column($priorityDistribution, 'priority')); ?>;
    const priorityData = <?php echo json_encode(array_column($priorityDistribution, 'count')); ?>;
    
    // Define colors for priority
    const priorityColors = priorityLabels.map(label => {
        const colors = {
            'Urgent': '#dc3545',
            'High': '#fd7e14',
            'Medium': '#0dcaf0',
            'Low': '#6c757d'
        };
        return colors[label] || '#6c757d';
    });
    
    new Chart(priorityCtx, {
        type: 'bar',
        data: {
            labels: priorityLabels,
            datasets: [{
                label: 'Jumlah Pesan',
                data: priorityData,
                backgroundColor: priorityColors,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($monthlyStats)): ?>
    // Trend Chart
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const months = <?php echo json_encode(array_column($monthlyStats, 'month')); ?>;
    const totals = <?php echo json_encode(array_column($monthlyStats, 'total')); ?>;
    const completed = <?php echo json_encode(array_column($monthlyStats, 'completed')); ?>;
    const highPriority = <?php echo json_encode(array_column($monthlyStats, 'high_priority_count')); ?>;
    
    // Format bulan untuk label
    const formattedMonths = months.map(m => {
        const [year, month] = m.split('-');
        const date = new Date(year, month - 1);
        return date.toLocaleDateString('id-ID', { month: 'short', year: 'numeric' });
    });
    
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: formattedMonths,
            datasets: [
                {
                    label: 'Total Pesan',
                    data: totals,
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    borderColor: '#0d6efd',
                    borderWidth: 2,
                    tension: 0.4,
                    yAxisID: 'y'
                },
                {
                    label: 'Selesai',
                    data: completed,
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    borderColor: '#198754',
                    borderWidth: 2,
                    tension: 0.4,
                    yAxisID: 'y'
                },
                {
                    label: 'Prioritas Tinggi',
                    data: highPriority,
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    borderColor: '#dc3545',
                    borderWidth: 2,
                    tension: 0.4,
                    yAxisID: 'y'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Jumlah Pesan'
                    },
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
    <?php endif; ?>
}

// Initialize search for messages table
function initializeSearch() {
    const searchInput = document.getElementById('searchMessages');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#recentMessagesTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    }
}

// View all messages of this type
function viewMessagesByType(typeId) {
    window.location.href = '../messages/messages.php?type=' + typeId;
}

// Export detailed report
function exportDetailedReport() {
    const typeName = '<?php echo urlencode($type['jenis_pesan']); ?>';
    const date = new Date().toISOString().slice(0, 10);
    window.open(`export_type_detail.php?id=<?php echo $type_id; ?>&name=${typeName}&date=${date}`, '_blank');
}
</script>

<?php
require_once '../../includes/footer.php';
?>