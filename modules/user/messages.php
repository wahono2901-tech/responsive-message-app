<?php
/**
 * User Messages - Daftar Semua Pesan
 * File: modules/user/messages.php
 * 
 * VERSI: 1.0 - STRUKTUR HEADER/FOOTER YANG BENAR
 * - Menggunakan header.php dan footer.php untuk struktur HTML
 * - Menampilkan semua pesan user dengan filter dan pencarian
 * - UI profesional dengan Bootstrap 5
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

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
// FILTER PARAMETERS
// ============================================================================
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 15;

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

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(m.created_at) >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(m.created_at) <= :date_to";
    $params[':date_to'] = $dateTo;
}

if (!empty($search)) {
    $whereConditions[] = "(m.isi_pesan LIKE :search OR mt.jenis_pesan LIKE :search)";
    $params[':search'] = "%$search%";
}

$whereClause = implode(' AND ', $whereConditions);

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
        mt.jenis_pesan,
        u.nama_lengkap as responder_nama,
        u.avatar as responder_avatar,
        mr.catatan_respon as last_response,
        mr.created_at as last_response_date
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
    ORDER BY m.created_at DESC
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

// Get statistics
$statsSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Dibaca' THEN 1 ELSE 0 END) as dibaca,
        SUM(CASE WHEN status = 'Diproses' THEN 1 ELSE 0 END) as diproses,
        SUM(CASE WHEN status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as selesai,
        SUM(CASE WHEN status = 'Ditolak' THEN 1 ELSE 0 END) as ditolak
    FROM messages 
    WHERE pengirim_id = :user_id
";

$statsStmt = $db->prepare($statsSql);
$statsStmt->execute([':user_id' => $userId]);
$stats = $statsStmt->fetch();

$pageTitle = 'Pesan Saya - PesanApp';
require_once '../../includes/header.php';
?>

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
        </div>
        <div>
            <a href="send_message.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Pesan Baru
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 mb-1">Total Pesan</h6>
                            <h3 class="mb-0 text-white"><?php echo number_format($stats['total'] ?? 0); ?></h3>
                        </div>
                        <i class="fas fa-envelope fa-3x text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 mb-1">Pending</h6>
                            <h3 class="mb-0 text-white"><?php echo ($stats['pending'] ?? 0) + ($stats['dibaca'] ?? 0); ?></h3>
                        </div>
                        <i class="fas fa-clock fa-3x text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 mb-1">Selesai</h6>
                            <h3 class="mb-0 text-white"><?php echo $stats['selesai'] ?? 0; ?></h3>
                        </div>
                        <i class="fas fa-check-circle fa-3x text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 mb-1">Ditolak</h6>
                            <h3 class="mb-0 text-white"><?php echo $stats['ditolak'] ?? 0; ?></h3>
                        </div>
                        <i class="fas fa-times-circle fa-3x text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-filter me-2 text-primary"></i>Filter Pesan
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Semua Status</option>
                        <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Dibaca" <?php echo $statusFilter === 'Dibaca' ? 'selected' : ''; ?>>Dibaca</option>
                        <option value="Diproses" <?php echo $statusFilter === 'Diproses' ? 'selected' : ''; ?>>Diproses</option>
                        <option value="Disetujui" <?php echo $statusFilter === 'Disetujui' ? 'selected' : ''; ?>>Disetujui</option>
                        <option value="Ditolak" <?php echo $statusFilter === 'Ditolak' ? 'selected' : ''; ?>>Ditolak</option>
                        <option value="Selesai" <?php echo $statusFilter === 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Jenis Pesan</label>
                    <select class="form-select" name="type" onchange="this.form.submit()">
                        <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>Semua Jenis</option>
                        <?php foreach ($messageTypes as $type): ?>
                        <option value="<?php echo $type['id']; ?>" <?php echo $typeFilter == $type['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['jenis_pesan']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo $dateFrom; ?>" onchange="this.form.submit()">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo $dateTo; ?>" onchange="this.form.submit()">
                </div>
                
                <div class="col-md-12">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Cari pesan..." value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search me-2"></i>Cari
                        </button>
                        <a href="messages.php" class="btn btn-outline-secondary">
                            <i class="fas fa-undo me-2"></i>Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Messages Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-inbox me-2 text-primary"></i>Daftar Pesan
                <span class="badge bg-primary ms-2"><?php echo $total; ?></span>
            </h5>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-download me-1"></i>Export
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="export_messages.php?format=csv&<?php echo http_build_query($_GET); ?>">
                        <i class="fas fa-file-csv me-2 text-success"></i>CSV
                    </a></li>
                    <li><a class="dropdown-item" href="export_messages.php?format=excel&<?php echo http_build_query($_GET); ?>">
                        <i class="fas fa-file-excel me-2 text-success"></i>Excel
                    </a></li>
                    <li><a class="dropdown-item" href="export_messages.php?format=pdf&<?php echo http_build_query($_GET); ?>">
                        <i class="fas fa-file-pdf me-2 text-danger"></i>PDF
                    </a></li>
                </ul>
            </div>
        </div>
        
        <?php if (empty($messages)): ?>
        <div class="text-center py-5">
            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
            <h5 class="fw-bold mb-2">Belum Ada Pesan</h5>
            <p class="text-muted mb-4">
                <?php if ($statusFilter !== 'all' || $typeFilter !== 'all' || !empty($dateFrom) || !empty($dateTo) || !empty($search)): ?>
                Tidak ada pesan yang sesuai dengan filter yang dipilih.
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
                        <th>No</th>
                        <th>Jenis Pesan</th>
                        <th>Isi Pesan</th>
                        <th>Status</th>
                        <th>Tanggal Kirim</th>
                        <th>Respons</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $index => $message): ?>
                    <tr>
                        <td><?php echo $offset + $index + 1; ?></td>
                        <td>
                            <span class="badge bg-info"><?php echo htmlspecialchars($message['jenis_pesan']); ?></span>
                        </td>
                        <td>
                            <div class="text-truncate" style="max-width: 300px;">
                                <?php echo htmlspecialchars($message['isi_pesan']); ?>
                            </div>
                            <small class="text-muted">
                                <i class="far fa-clock me-1"></i><?php echo Functions::timeAgo($message['created_at']); ?>
                            </small>
                        </td>
                        <td>
                            <?php
                            $statusClass = match($message['status']) {
                                'Pending' => 'warning',
                                'Dibaca' => 'info',
                                'Diproses' => 'primary',
                                'Disetujui' => 'success',
                                'Ditolak' => 'danger',
                                'Selesai' => 'secondary',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?php echo $statusClass; ?>">
                                <?php echo $message['status']; ?>
                            </span>
                        </td>
                        <td>
                            <small><?php echo date('d/m/Y H:i', strtotime($message['created_at'])); ?></small>
                        </td>
                        <td>
                            <?php if (!empty($message['last_response'])): ?>
                            <div class="d-flex align-items-center">
                                <img src="<?php echo $message['responder_avatar'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($message['responder_nama'] ?? 'Responder'); ?>" 
                                     class="rounded-circle me-2" width="24" height="24">
                                <div>
                                    <small class="fw-bold d-block"><?php echo htmlspecialchars($message['responder_nama'] ?? 'Responder'); ?></small>
                                    <small class="text-muted"><?php echo Functions::timeAgo($message['last_response_date']); ?></small>
                                </div>
                            </div>
                            <?php else: ?>
                            <span class="badge bg-secondary">Belum direspons</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="view_message.php?id=<?php echo $message['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i>
                            </a>
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
                    
                    <?php
                    $startPage = max(1, min($page - 2, $totalPages - 4));
                    $endPage = min($totalPages, max($page + 2, 5));
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
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
            <p class="text-center text-muted small mt-2 mb-0">
                Menampilkan <?php echo $offset + 1; ?> - <?php echo min($offset + $perPage, $total); ?> dari <?php echo $total; ?> pesan
            </p>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
/* Custom Styles */
.card {
    border-radius: 12px;
    transition: all 0.2s ease;
}

.card:hover {
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1) !important;
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

.pagination .page-link {
    border-radius: 8px;
    margin: 0 3px;
    border: none;
    color: #6c757d;
}

.pagination .active .page-link {
    background-color: #0d6efd;
    color: white;
}

@media (max-width: 768px) {
    .table th, .table td {
        white-space: nowrap;
    }
}
</style>

<?php
require_once '../../includes/footer.php';
?>