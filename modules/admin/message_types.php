<?php
/**
 * Manajemen Jenis Pesan
 * File: modules/admin/message_types.php
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

$db = Database::getInstance()->getConnection();

// Handle form actions
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;

$success_msg = '';
$error_msg = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_type'])) {
        $jenis_pesan = trim($_POST['jenis_pesan']);
        $description = trim($_POST['description'] ?? '');
        $response_deadline_hours = (int)$_POST['response_deadline_hours'] ?? 72;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (!empty($jenis_pesan)) {
            $stmt = $db->prepare("
                INSERT INTO message_types (jenis_pesan, description, response_deadline_hours, is_active, created_at, updated_at) 
                VALUES (:jenis_pesan, :description, :response_deadline_hours, :is_active, NOW(), NOW())
            ");
            
            try {
                $stmt->execute([
                    ':jenis_pesan' => $jenis_pesan,
                    ':description' => $description,
                    ':response_deadline_hours' => $response_deadline_hours,
                    ':is_active' => $is_active
                ]);
                
                $success_msg = 'Jenis pesan berhasil ditambahkan!';
                logActivity($_SESSION['user_id'], 'ADD_MESSAGE_TYPE', "Menambahkan jenis pesan: $jenis_pesan");
            } catch (PDOException $e) {
                $error_msg = 'Gagal menambahkan jenis pesan: ' . $e->getMessage();
            }
        } else {
            $error_msg = 'Nama jenis pesan tidak boleh kosong!';
        }
    }
    
    if (isset($_POST['edit_type'])) {
        $id = (int)$_POST['id'];
        $jenis_pesan = trim($_POST['jenis_pesan']);
        $description = trim($_POST['description'] ?? '');
        $response_deadline_hours = (int)$_POST['response_deadline_hours'] ?? 72;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (!empty($jenis_pesan)) {
            $stmt = $db->prepare("
                UPDATE message_types 
                SET jenis_pesan = :jenis_pesan, 
                    description = :description,
                    response_deadline_hours = :response_deadline_hours,
                    is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            try {
                $stmt->execute([
                    ':jenis_pesan' => $jenis_pesan,
                    ':description' => $description,
                    ':response_deadline_hours' => $response_deadline_hours,
                    ':is_active' => $is_active,
                    ':id' => $id
                ]);
                
                $success_msg = 'Jenis pesan berhasil diperbarui!';
                logActivity($_SESSION['user_id'], 'UPDATE_MESSAGE_TYPE', "Memperbarui jenis pesan ID: $id");
            } catch (PDOException $e) {
                $error_msg = 'Gagal memperbarui jenis pesan: ' . $e->getMessage();
            }
        } else {
            $error_msg = 'Nama jenis pesan tidak boleh kosong!';
        }
    }
}

// Handle delete action
if ($action === 'delete' && $id > 0) {
    // Check if type is being used
    $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE jenis_pesan_id = :id");
    $checkStmt->execute([':id' => $id]);
    $usage = $checkStmt->fetch();
    
    if ($usage['count'] > 0) {
        $error_msg = 'Jenis pesan tidak dapat dihapus karena masih digunakan oleh ' . $usage['count'] . ' pesan!';
    } else {
        $stmt = $db->prepare("DELETE FROM message_types WHERE id = :id");
        if ($stmt->execute([':id' => $id])) {
            $success_msg = 'Jenis pesan berhasil dihapus!';
            logActivity($_SESSION['user_id'], 'DELETE_MESSAGE_TYPE', "Menghapus jenis pesan ID: $id");
        } else {
            $error_msg = 'Gagal menghapus jenis pesan!';
        }
    }
}

// Handle toggle status action
if ($action === 'toggle' && $id > 0) {
    $stmt = $db->prepare("UPDATE message_types SET is_active = NOT is_active, updated_at = NOW() WHERE id = :id");
    if ($stmt->execute([':id' => $id])) {
        $success_msg = 'Status jenis pesan berhasil diubah!';
        logActivity($_SESSION['user_id'], 'TOGGLE_MESSAGE_TYPE', "Mengubah status jenis pesan ID: $id");
    } else {
        $error_msg = 'Gagal mengubah status jenis pesan!';
    }
}

// Get all message types with statistics
$typesStmt = $db->query("
    SELECT 
        mt.*,
        COALESCE(COUNT(m.id), 0) as total_messages,
        COALESCE(SUM(CASE WHEN m.status = 'Pending' THEN 1 ELSE 0 END), 0) as pending_count,
        COALESCE(SUM(CASE WHEN m.status = 'Disetujui' THEN 1 ELSE 0 END), 0) as approved_count,
        COALESCE(SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END), 0) as rejected_count,
        COALESCE(SUM(CASE WHEN m.status = 'Diproses' THEN 1 ELSE 0 END), 0) as processed_count,
        COALESCE(SUM(CASE WHEN m.status = 'Selesai' THEN 1 ELSE 0 END), 0) as completed_count,
        COALESCE(AVG(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW()))), 0) as avg_response_time
    FROM message_types mt
    LEFT JOIN messages m ON mt.id = m.jenis_pesan_id
    GROUP BY mt.id
    ORDER BY mt.is_active DESC, total_messages DESC, mt.jenis_pesan ASC
");
$messageTypes = $typesStmt->fetchAll();

// Get type for edit
$editType = null;
if ($action === 'edit' && $id > 0) {
    $stmt = $db->prepare("SELECT * FROM message_types WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $editType = $stmt->fetch();
}

$pageTitle = 'Manajemen Jenis Pesan';

require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-tags me-2"></i>Manajemen Jenis Pesan
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Jenis Pesan</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="dashboard.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left me-1"></i> Kembali
            </a>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTypeModal">
                <i class="fas fa-plus me-1"></i> Tambah Jenis Pesan
            </button>
        </div>
    </div>
    
    <!-- Messages -->
    <?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Total Jenis Pesan</h6>
                            <h2 class="mb-0 text-primary"><?php echo count($messageTypes); ?></h2>
                        </div>
                        <div class="widget-icon bg-primary-light rounded-circle p-3">
                            <i class="fas fa-tags fa-2x text-primary"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <?php 
                        $activeCount = array_reduce($messageTypes, function($carry, $type) {
                            return $carry + ($type['is_active'] ? 1 : 0);
                        }, 0);
                        $activePercentage = count($messageTypes) > 0 ? ($activeCount / count($messageTypes)) * 100 : 0;
                        ?>
                        <small class="text-muted"><?php echo $activeCount; ?> aktif (<?php echo number_format($activePercentage, 0); ?>%)</small>
                        <div class="progress mt-1" style="height: 5px;">
                            <div class="progress-bar bg-success" style="width: <?php echo $activePercentage; ?>%"></div>
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
                            <h6 class="text-uppercase text-muted mb-1">Total Pesan</h6>
                            <h2 class="mb-0 text-success">
                                <?php 
                                $totalMessages = array_reduce($messageTypes, function($carry, $type) {
                                    return $carry + $type['total_messages'];
                                }, 0);
                                echo number_format($totalMessages);
                                ?>
                            </h2>
                        </div>
                        <div class="widget-icon bg-success-light rounded-circle p-3">
                            <i class="fas fa-comments fa-2x text-success"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">Rata-rata per jenis: <?php 
                            $avgPerType = count($messageTypes) > 0 ? $totalMessages / count($messageTypes) : 0;
                            echo number_format($avgPerType, 1);
                        ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Rata Waktu Respons</h6>
                            <h2 class="mb-0 text-info">
                                <?php 
                                $avgResponse = array_reduce($messageTypes, function($carry, $type) {
                                    return $carry + $type['avg_response_time'];
                                }, 0);
                                $avgResponse = count($messageTypes) > 0 ? $avgResponse / count($messageTypes) : 0;
                                echo number_format($avgResponse, 1);
                                ?>h
                            </h2>
                        </div>
                        <div class="widget-icon bg-info-light rounded-circle p-3">
                            <i class="fas fa-clock fa-2x text-info"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">Target: ≤72 jam</small>
                        <div class="progress mt-1" style="height: 5px;">
                            <div class="progress-bar bg-<?php echo $avgResponse <= 72 ? 'success' : ($avgResponse <= 96 ? 'warning' : 'danger'); ?>" 
                                 style="width: <?php echo min(100, ($avgResponse / 72) * 100); ?>%"></div>
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
                            <h6 class="text-uppercase text-muted mb-1">Tingkat Penyelesaian</h6>
                            <h2 class="mb-0 text-warning">
                                <?php 
                                $completed = array_reduce($messageTypes, function($carry, $type) {
                                    return $carry + $type['completed_count'];
                                }, 0);
                                $completionRate = $totalMessages > 0 ? ($completed / $totalMessages) * 100 : 0;
                                echo number_format($completionRate, 1);
                                ?>%
                            </h2>
                        </div>
                        <div class="widget-icon bg-warning-light rounded-circle p-3">
                            <i class="fas fa-check-circle fa-2x text-warning"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted"><?php echo number_format($completed); ?> dari <?php echo number_format($totalMessages); ?> pesan</small>
                        <div class="progress mt-1" style="height: 5px;">
                            <div class="progress-bar bg-warning" style="width: <?php echo $completionRate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Message Types Table -->
    <div class="card border-0 shadow">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Daftar Jenis Pesan
                    <span class="badge bg-primary ms-2"><?php echo count($messageTypes); ?> Jenis</span>
                </h5>
                <div class="d-flex">
                    <input type="text" id="searchInput" class="form-control form-control-sm me-2" 
                           placeholder="Cari jenis pesan..." style="width: 200px;">
                    <button class="btn btn-sm btn-outline-secondary" onclick="refreshTable()">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="messageTypesTable">
                    <thead class="table-light">
                        <tr>
                            <th width="50" class="py-3">#</th>
                            <th class="py-3">Jenis Pesan</th>
                            <th class="py-3">Status</th>
                            <th class="py-3 text-center">Total Pesan</th>
                            <th class="py-3 text-center">Pending</th>
                            <th class="py-3 text-center">Disetujui</th>
                            <th class="py-3 text-center">Ditolak</th>
                            <th class="py-3 text-center">Waktu Respons</th>
                            <th class="py-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messageTypes as $index => $type): ?>
                        <tr data-type-id="<?php echo $type['id']; ?>">
                            <td class="align-middle">
                                <span class="badge bg-light text-dark"><?php echo $index + 1; ?></span>
                            </td>
                            <td class="align-middle">
                                <div>
                                    <h6 class="mb-0 fw-bold">
                                        <?php echo htmlspecialchars($type['jenis_pesan']); ?>
                                        <?php if (!$type['is_active']): ?>
                                        <span class="badge bg-secondary ms-1">Nonaktif</span>
                                        <?php endif; ?>
                                    </h6>
                                    <?php if (!empty($type['description'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($type['description']); ?></small>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>Deadline: <?php echo $type['response_deadline_hours']; ?> jam
                                    </small>
                                </div>
                            </td>
                            <td class="align-middle">
                                <div class="form-check form-switch">
                                    <input class="form-check-input status-toggle" type="checkbox" 
                                           data-type-id="<?php echo $type['id']; ?>"
                                           <?php echo $type['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label">
                                        <span class="badge bg-<?php echo $type['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $type['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                        </span>
                                    </label>
                                </div>
                            </td>
                            <td class="align-middle text-center">
                                <span class="badge bg-primary rounded-pill"><?php echo number_format($type['total_messages']); ?></span>
                            </td>
                            <td class="align-middle text-center">
                                <?php if ($type['pending_count'] > 0): ?>
                                <span class="badge bg-warning rounded-pill"><?php echo number_format($type['pending_count']); ?></span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle text-center">
                                <?php if ($type['approved_count'] > 0): ?>
                                <span class="badge bg-success rounded-pill"><?php echo number_format($type['approved_count']); ?></span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle text-center">
                                <?php if ($type['rejected_count'] > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?php echo number_format($type['rejected_count']); ?></span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle text-center">
                                <?php if ($type['avg_response_time'] > 0): ?>
                                <div class="d-flex align-items-center justify-content-center">
                                    <span class="me-1 <?php echo $type['avg_response_time'] > $type['response_deadline_hours'] ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo number_format($type['avg_response_time'], 1); ?>h
                                    </span>
                                    <?php if ($type['avg_response_time'] > $type['response_deadline_hours']): ?>
                                    <i class="fas fa-exclamation-triangle text-danger" title="Melebihi deadline"></i>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-primary" onclick="editType(<?php echo $type['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="viewDetails(<?php echo $type['id']; ?>)">
                                        <i class="fas fa-chart-bar"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteType(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars(addslashes($type['jenis_pesan'])); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white py-3">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <small class="text-muted">
                        Menampilkan <strong><?php echo count($messageTypes); ?></strong> jenis pesan
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Klik ikon grafik untuk melihat detail performa
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Performance Chart Section -->
    <div class="row mt-4">
        <div class="col-lg-12">
            <div class="card border-0 shadow">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line me-2"></i>Performa Jenis Pesan
                        <small class="text-muted ms-2">Analisis komparatif semua jenis pesan</small>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="chart-container" style="height: 400px;">
                                <canvas id="performanceChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card h-100 border-0">
                                <div class="card-body">
                                    <h6 class="mb-3"><i class="fas fa-lightbulb me-2 text-warning"></i>Insights & Rekomendasi</h6>
                                    <?php
                                    // Calculate insights
                                    $typesWithLowUsage = array_filter($messageTypes, function($type) {
                                        return $type['total_messages'] < 10 && $type['is_active'];
                                    });
                                    
                                    $typesSlowResponse = array_filter($messageTypes, function($type) {
                                        return $type['avg_response_time'] > $type['response_deadline_hours'] && $type['total_messages'] > 0;
                                    });
                                    
                                    $typesHighRejection = array_filter($messageTypes, function($type) {
                                        $rejectionRate = $type['total_messages'] > 0 ? ($type['rejected_count'] / $type['total_messages']) * 100 : 0;
                                        return $rejectionRate > 30 && $type['total_messages'] > 0;
                                    });
                                    ?>
                                    
                                    <?php if (count($typesWithLowUsage) > 0): ?>
                                    <div class="alert alert-warning mb-3">
                                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Jenis Pesan Kurang Digunakan</h6>
                                        <small>
                                            <?php 
                                            $lowUsageNames = array_map(function($type) {
                                                return $type['jenis_pesan'];
                                            }, $typesWithLowUsage);
                                            echo implode(', ', $lowUsageNames);
                                            ?>
                                            <br>Pertimbangkan untuk menggabungkan atau menonaktifkan.
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (count($typesSlowResponse) > 0): ?>
                                    <div class="alert alert-danger mb-3">
                                        <h6><i class="fas fa-clock me-2"></i>Waktu Respons Lambat</h6>
                                        <small>
                                            <?php 
                                            $slowNames = array_map(function($type) {
                                                return $type['jenis_pesan'];
                                            }, $typesSlowResponse);
                                            echo implode(', ', $slowNames);
                                            ?>
                                            <br>Perlu perhatian khusus untuk mempercepat respons.
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (count($typesHighRejection) > 0): ?>
                                    <div class="alert alert-info mb-3">
                                        <h6><i class="fas fa-times-circle me-2"></i>Tingkat Penolakan Tinggi</h6>
                                        <small>
                                            <?php 
                                            $highRejectNames = array_map(function($type) {
                                                $rejectionRate = ($type['rejected_count'] / $type['total_messages']) * 100;
                                                return $type['jenis_pesan'] . ' (' . number_format($rejectionRate, 1) . '%)';
                                            }, $typesHighRejection);
                                            echo implode(', ', $highRejectNames);
                                            ?>
                                            <br>Perlu evaluasi kriteria persetujuan.
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($typesWithLowUsage) && empty($typesSlowResponse) && empty($typesHighRejection)): ?>
                                    <div class="alert alert-success">
                                        <h6><i class="fas fa-check-circle me-2"></i>Performa Optimal</h6>
                                        <small>
                                            Semua jenis pesan berjalan dengan baik. 
                                            Tidak ada rekomendasi khusus saat ini.
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-4">
                                        <h6><i class="fas fa-chart-pie me-2"></i>Statistik Cepat</h6>
                                        <ul class="list-unstyled">
                                            <li class="mb-2">
                                                <small>
                                                    <i class="fas fa-circle text-primary me-1"></i>
                                                    Jenis paling populer: 
                                                    <strong><?php 
                                                    $mostPopular = array_reduce($messageTypes, function($carry, $item) {
                                                        return $carry === null || $item['total_messages'] > $carry['total_messages'] ? $item : $carry;
                                                    }, null);
                                                    echo $mostPopular ? htmlspecialchars($mostPopular['jenis_pesan']) : '-';
                                                    ?></strong>
                                                </small>
                                            </li>
                                            <li class="mb-2">
                                                <small>
                                                    <i class="fas fa-circle text-success me-1"></i>
                                                    Cepat respons: 
                                                    <strong><?php 
                                                    $fastest = array_reduce($messageTypes, function($carry, $item) {
                                                        if ($item['total_messages'] == 0) return $carry;
                                                        return $carry === null || $item['avg_response_time'] < $carry['avg_response_time'] ? $item : $carry;
                                                    }, null);
                                                    echo $fastest ? htmlspecialchars($fastest['jenis_pesan']) . ' (' . number_format($fastest['avg_response_time'], 1) . 'h)' : '-';
                                                    ?></strong>
                                                </small>
                                            </li>
                                            <li>
                                                <small>
                                                    <i class="fas fa-circle text-warning me-1"></i>
                                                    Tertinggi pending: 
                                                    <strong><?php 
                                                    $highestPending = array_reduce($messageTypes, function($carry, $item) {
                                                        return $carry === null || $item['pending_count'] > $carry['pending_count'] ? $item : $carry;
                                                    }, null);
                                                    echo $highestPending ? htmlspecialchars($highestPending['jenis_pesan']) . ' (' . number_format($highestPending['pending_count']) . ')' : '-';
                                                    ?></strong>
                                                </small>
                                            </li>
                                        </ul>
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

<!-- Add/Edit Modal -->
<div class="modal fade" id="typeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Tambah Jenis Pesan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="typeForm">
                <input type="hidden" id="typeId" name="id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="jenis_pesan" class="form-label">Nama Jenis Pesan *</label>
                        <input type="text" class="form-control" id="jenis_pesan" name="jenis_pesan" required>
                        <small class="text-muted">Contoh: Konsultasi/Konseling, Kehumasan, Kurikulum</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Deskripsi</label>
                        <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                        <small class="text-muted">Penjelasan singkat tentang jenis pesan ini</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="response_deadline_hours" class="form-label">Deadline Respons (jam) *</label>
                            <input type="number" class="form-control" id="response_deadline_hours" 
                                   name="response_deadline_hours" value="72" min="1" max="720" required>
                            <small class="text-muted">Waktu maksimal untuk merespons pesan</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                                <label class="form-check-label" for="is_active">Aktif</label>
                            </div>
                            <small class="text-muted">Nonaktifkan untuk menyembunyikan jenis pesan</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Type Modal Trigger -->
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Jenis Pesan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="new_jenis_pesan" class="form-label">Nama Jenis Pesan *</label>
                        <input type="text" class="form-control" id="new_jenis_pesan" name="jenis_pesan" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_description" class="form-label">Deskripsi</label>
                        <textarea class="form-control" id="new_description" name="description" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="new_response_deadline" class="form-label">Deadline Respons (jam)</label>
                            <input type="number" class="form-control" id="new_response_deadline" 
                                   name="response_deadline_hours" value="72" min="1" max="720">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="new_is_active" name="is_active" checked>
                                <label class="form-check-label" for="new_is_active">Aktif</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" name="add_type">Tambah</button>
                </div>
            </form>
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

.form-check-input:checked {
    background-color: #198754;
    border-color: #198754;
}

.alert h6 {
    font-size: 0.9rem;
    margin-bottom: 0.3rem;
}

.alert small {
    font-size: 0.85rem;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Initialize chart
document.addEventListener('DOMContentLoaded', function() {
    initializePerformanceChart();
    initializeSearch();
    initializeStatusToggles();
});

// Performance Chart
function initializePerformanceChart() {
    const ctx = document.getElementById('performanceChart').getContext('2d');
    
    const typeNames = <?php echo json_encode(array_column($messageTypes, 'jenis_pesan')); ?>;
    const totalMessages = <?php echo json_encode(array_column($messageTypes, 'total_messages')); ?>;
    const pendingCounts = <?php echo json_encode(array_column($messageTypes, 'pending_count')); ?>;
    const approvedCounts = <?php echo json_encode(array_column($messageTypes, 'approved_count')); ?>;
    const responseTimes = <?php echo json_encode(array_column($messageTypes, 'avg_response_time')); ?>;
    
    const performanceChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: typeNames,
            datasets: [
                {
                    label: 'Total Pesan',
                    data: totalMessages,
                    backgroundColor: 'rgba(13, 110, 253, 0.8)',
                    borderColor: '#0d6efd',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Pending',
                    data: pendingCounts,
                    backgroundColor: 'rgba(255, 193, 7, 0.8)',
                    borderColor: '#ffc107',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Disetujui',
                    data: approvedCounts,
                    backgroundColor: 'rgba(25, 135, 84, 0.8)',
                    borderColor: '#198754',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Waktu Respons (jam)',
                    data: responseTimes,
                    backgroundColor: 'rgba(13, 202, 240, 0.6)',
                    borderColor: '#0dcaf0',
                    borderWidth: 1,
                    type: 'line',
                    yAxisID: 'y1'
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
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                },
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
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Waktu Respons (jam)'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });
}

// Initialize search functionality
function initializeSearch() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#messageTypesTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    }
}

// Initialize status toggle switches
function initializeStatusToggles() {
    document.querySelectorAll('.status-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const typeId = this.dataset.typeId;
            const isActive = this.checked;
            
            fetch(`?action=toggle&id=${typeId}`)
                .then(response => response.text())
                .then(() => {
                    showToast('success', 'Status berhasil diubah');
                    // Update badge
                    const badge = this.closest('td').querySelector('.badge');
                    if (badge) {
                        badge.className = isActive ? 'badge bg-success' : 'badge bg-secondary';
                        badge.textContent = isActive ? 'Aktif' : 'Nonaktif';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    this.checked = !isActive; // Revert on error
                    showToast('error', 'Gagal mengubah status');
                });
        });
    });
}

// Edit type
function editType(id) {
    fetch(`get_type.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('modalTitle').textContent = 'Edit Jenis Pesan';
                document.getElementById('typeId').value = data.type.id;
                document.getElementById('jenis_pesan').value = data.type.jenis_pesan;
                document.getElementById('description').value = data.type.description || '';
                document.getElementById('response_deadline_hours').value = data.type.response_deadline_hours;
                document.getElementById('is_active').checked = data.type.is_active;
                
                // Update form action
                const form = document.getElementById('typeForm');
                const submitBtn = document.getElementById('submitBtn');
                submitBtn.name = 'edit_type';
                submitBtn.textContent = 'Simpan Perubahan';
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('typeModal'));
                modal.show();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', 'Gagal memuat data jenis pesan');
        });
}

// Delete type with confirmation
function deleteType(id, name) {
    if (confirm(`Apakah Anda yakin ingin menghapus jenis pesan "${name}"?`)) {
        window.location.href = `?action=delete&id=${id}`;
    }
}

// View performance details
function viewDetails(id) {
    window.open(`type_details.php?id=${id}`, '_blank');
}

// Refresh table
function refreshTable() {
    window.location.reload();
}

// Show toast notification
function showToast(type, message) {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed top-0 end-0 m-3`;
    toast.style.zIndex = '9999';
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    document.body.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
    bsToast.show();
    
    toast.addEventListener('hidden.bs.toast', function() {
        this.remove();
    });
}

// Export performance report
function exportReport() {
    showLoading('Membuat laporan...');
    
    fetch('export_types.php')
        .then(response => response.blob())
        .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `laporan-jenis-pesan-${new Date().toISOString().slice(0,10)}.xlsx`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            showToast('success', 'Laporan berhasil diexport');
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', 'Gagal mengexport laporan');
        })
        .finally(() => {
            hideLoading();
        });
}

// Loading indicator
function showLoading(message) {
    let loading = document.getElementById('loading');
    if (!loading) {
        loading = document.createElement('div');
        loading.id = 'loading';
        loading.className = 'position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center';
        loading.style.backgroundColor = 'rgba(0,0,0,0.5)';
        loading.style.zIndex = '9999';
        loading.innerHTML = `
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="ms-3 text-white">${message}</div>
        `;
        document.body.appendChild(loading);
    }
}

function hideLoading() {
    const loading = document.getElementById('loading');
    if (loading) {
        loading.remove();
    }
}

// Auto-refresh every 5 minutes
setTimeout(() => {
    if (confirm('Perbarui data jenis pesan?')) {
        refreshTable();
    }
}, 300000); // 5 minutes

// Quick add from dashboard
if (window.location.hash === '#add') {
    const addModal = new bootstrap.Modal(document.getElementById('addTypeModal'));
    addModal.show();
}
</script>

<?php
require_once '../../includes/footer.php';
?>