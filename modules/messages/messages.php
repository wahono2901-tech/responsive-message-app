<?php
/**
 * Messages Management Interface
 * File: modules/messages/messages.php
 * Version: Direct Database Access (No API Dependency)
 * 
 * REVISI: 
 * - Memperbaiki statistik pesan berdasarkan riwayat respon (message_responses)
 * - INTEGRASI BARU: Menampilkan riwayat respons berjenjang dari wakepsek_reviews
 * - Menampilkan status review dari Wakil Kepala Sekolah dan Kepala Sekolah
 * - Hierarki respons: Guru/Admin -> Wakil Kepala -> Kepala Sekolah
 * - FIX: Undefined variable $action dan struktur tabel wakepsek_reviews yang benar
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check authentication
Auth::checkAuth();

// Get user session
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$privilege_level = $_SESSION['privilege_level'] ?? 'Limited_Access';

// Check if user has access to messages
if (!in_array($user_type, ['Admin', 'Guru', 'Siswa', 'Orang_Tua', 'Wakil_Kepala', 'Kepala_Sekolah', 'Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'])) {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

// Initialize variables
$error = '';
$success = '';
$messages = [];
$pagination = [];
$message_types = [];
$stats = [];
$message = null;

// ============================================================
// DEFINE VARIABLES YANG DIPERLUKAN
// ============================================================
$action = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Filters
$filters = [
    'page' => isset($_GET['page']) ? (int)$_GET['page'] : 1,
    'per_page' => isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20,
    'status' => isset($_GET['status']) ? $_GET['status'] : 'all',
    'type' => isset($_GET['type']) ? $_GET['type'] : 'all',
    'priority' => isset($_GET['priority']) ? $_GET['priority'] : 'all',
    'search' => isset($_GET['search']) ? $_GET['search'] : '',
    'date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : '',
    'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : ''
];

// Ensure page is numeric
$filters['page'] = max(1, intval($filters['page']));
$filters['per_page'] = max(1, min(100, intval($filters['per_page'])));

// Define upload paths
define('UPLOAD_PATH_MESSAGES', ROOT_PATH . '/uploads/messages/');
define('UPLOAD_PATH_EXTERNAL', ROOT_PATH . '/uploads/external_messages/');
define('BASE_URL_UPLOAD_MESSAGES', BASE_URL . '/uploads/messages/');
define('BASE_URL_UPLOAD_EXTERNAL', BASE_URL . '/uploads/external_messages/');

// Placeholder image (base64 SVG)
$placeholder_image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f8f9fa"/><text x="50" y="50" font-family="Arial" font-size="12" fill="#adb5bd" text-anchor="middle" dy=".3em">No Image</text></svg>');

try {
    // Get database connection
    require_once '../../config/database.php';
    $db = Database::getInstance()->getConnection();
    
    // ========== FETCH MESSAGE TYPES ==========
    $stmt = $db->query("SELECT * FROM message_types WHERE is_active = 1 ORDER BY jenis_pesan");
    $message_types = $stmt->fetchAll();
    
    // ========== FETCH STATISTICS ==========
    // Statistik untuk pesan milik user sendiri (sebagai pengirim)
    $stats_own_sql = "
        SELECT 
            COUNT(DISTINCT m.id) as total_messages,
            SUM(CASE WHEN m.status = 'Pending' THEN 1 ELSE 0 END) as pending_messages,
            SUM(CASE WHEN m.status = 'Disetujui' THEN 1 ELSE 0 END) as approved_messages,
            SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END) as rejected_messages,
            SUM(CASE WHEN m.status = 'Selesai' THEN 1 ELSE 0 END) as completed_messages,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, m.created_at, NOW()) > 72 AND m.status = 'Pending' THEN 1 ELSE 0 END) as expired_messages,
            SUM(CASE WHEN m.has_attachments = 1 THEN 1 ELSE 0 END) as messages_with_attachments
        FROM messages m
        WHERE m.pengirim_id = :user_id
    ";
    
    $stats_own_stmt = $db->prepare($stats_own_sql);
    $stats_own_stmt->execute([':user_id' => $user_id]);
    $stats_own = $stats_own_stmt->fetch() ?: [];
    
    // Statistik untuk respon yang diberikan oleh user (sebagai responder/guru)
    $stats_response_sql = "
        SELECT 
            COUNT(DISTINCT mr.id) as total_responses,
            SUM(CASE WHEN mr.status = 'Disetujui' THEN 1 ELSE 0 END) as approved_responses,
            SUM(CASE WHEN mr.status = 'Ditolak' THEN 1 ELSE 0 END) as rejected_responses,
            SUM(CASE WHEN mr.status = 'Diproses' THEN 1 ELSE 0 END) as processed_responses,
            SUM(CASE WHEN mr.status = 'Selesai' THEN 1 ELSE 0 END) as completed_responses,
            SUM(CASE WHEN DATE(mr.created_at) = CURDATE() THEN 1 ELSE 0 END) as responses_today,
            SUM(CASE WHEN mr.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as responses_last_24h
        FROM message_responses mr
        WHERE mr.responder_id = :user_id
    ";
    
    $stats_response_stmt = $db->prepare($stats_response_sql);
    $stats_response_stmt->execute([':user_id' => $user_id]);
    $stats_response = $stats_response_stmt->fetch() ?: [];
    
    // Statistik untuk Wakepsek Review (respons berjenjang) - SESUAI STRUKTUR TABEL
    // Tabel wakepsek_reviews memiliki kolom: id, message_id, reviewer_id, catatan, created_at
    $stats_review_sql = "
        SELECT 
            COUNT(DISTINCT wr.id) as total_reviews,
            SUM(CASE WHEN DATE(wr.created_at) = CURDATE() THEN 1 ELSE 0 END) as reviews_today
        FROM wakepsek_reviews wr
        WHERE wr.reviewer_id = :user_id
    ";
    
    $stats_review_stmt = $db->prepare($stats_review_sql);
    $stats_review_stmt->execute([':user_id' => $user_id]);
    $stats_review = $stats_review_stmt->fetch() ?: [];
    
    // Statistik untuk admin (gabungan semua)
    if ($user_type === 'Admin') {
        $stats_admin_sql = "
            SELECT 
                COUNT(DISTINCT m.id) as total_messages,
                SUM(CASE WHEN m.status = 'Pending' THEN 1 ELSE 0 END) as pending_messages,
                SUM(CASE WHEN m.status = 'Disetujui' THEN 1 ELSE 0 END) as approved_messages,
                SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END) as rejected_messages,
                SUM(CASE WHEN m.status = 'Selesai' THEN 1 ELSE 0 END) as completed_messages,
                SUM(CASE WHEN TIMESTAMPDIFF(HOUR, m.created_at, NOW()) > 72 AND m.status = 'Pending' THEN 1 ELSE 0 END) as expired_messages,
                SUM(CASE WHEN m.has_attachments = 1 THEN 1 ELSE 0 END) as messages_with_attachments,
                
                (SELECT COUNT(*) FROM message_responses WHERE DATE(created_at) = CURDATE()) as responses_today,
                (SELECT COUNT(*) FROM message_responses WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as responses_last_24h,
                (SELECT COUNT(*) FROM message_responses WHERE status = 'Disetujui') as total_approved_responses,
                (SELECT COUNT(*) FROM message_responses WHERE status = 'Ditolak') as total_rejected_responses,
                (SELECT COUNT(*) FROM message_responses WHERE status = 'Diproses') as total_processed_responses,
                (SELECT COUNT(*) FROM message_responses WHERE status = 'Selesai') as total_completed_responses,
                
                (SELECT COUNT(*) FROM wakepsek_reviews) as total_reviews,
                (SELECT COUNT(*) FROM wakepsek_reviews WHERE DATE(created_at) = CURDATE()) as reviews_today
            FROM messages m
        ";
        
        $stats_admin_stmt = $db->prepare($stats_admin_sql);
        $stats_admin_stmt->execute();
        $stats_admin = $stats_admin_stmt->fetch() ?: [];
    }
    
    // Gabungkan statistik sesuai role dengan default values
    $stats = [
        'total' => $stats_own['total_messages'] ?? 0,
        'pending' => $stats_own['pending_messages'] ?? 0,
        'approved' => $stats_own['approved_messages'] ?? 0,
        'rejected' => $stats_own['rejected_messages'] ?? 0,
        'completed' => $stats_own['completed_messages'] ?? 0,
        'expired' => $stats_own['expired_messages'] ?? 0,
        'with_attachments' => $stats_own['messages_with_attachments'] ?? 0,
        
        // Statistik respon
        'total_responses' => $stats_response['total_responses'] ?? 0,
        'approved_responses' => $stats_response['approved_responses'] ?? 0,
        'rejected_responses' => $stats_response['rejected_responses'] ?? 0,
        'processed_responses' => $stats_response['processed_responses'] ?? 0,
        'completed_responses' => $stats_response['completed_responses'] ?? 0,
        'responses_today' => $stats_response['responses_today'] ?? 0,
        'responses_last_24h' => $stats_response['responses_last_24h'] ?? 0,
        
        // Statistik review berjenjang
        'total_reviews' => $stats_review['total_reviews'] ?? 0,
        'reviews_today' => $stats_review['reviews_today'] ?? 0
    ];
    
    // Tambahkan statistik admin jika user adalah admin
    if ($user_type === 'Admin' && isset($stats_admin)) {
        $stats = array_merge($stats, [
            'admin_total' => $stats_admin['total_messages'] ?? 0,
            'admin_pending' => $stats_admin['pending_messages'] ?? 0,
            'admin_approved' => $stats_admin['approved_messages'] ?? 0,
            'admin_rejected' => $stats_admin['rejected_messages'] ?? 0,
            'admin_completed' => $stats_admin['completed_messages'] ?? 0,
            'admin_expired' => $stats_admin['expired_messages'] ?? 0,
            'admin_with_attachments' => $stats_admin['messages_with_attachments'] ?? 0,
            'admin_responses_today' => $stats_admin['responses_today'] ?? 0,
            'admin_responses_last_24h' => $stats_admin['responses_last_24h'] ?? 0,
            'admin_approved_responses' => $stats_admin['total_approved_responses'] ?? 0,
            'admin_rejected_responses' => $stats_admin['total_rejected_responses'] ?? 0,
            'admin_processed_responses' => $stats_admin['total_processed_responses'] ?? 0,
            'admin_completed_responses' => $stats_admin['total_completed_responses'] ?? 0,
            'admin_total_reviews' => $stats_admin['total_reviews'] ?? 0,
            'admin_reviews_today' => $stats_admin['reviews_today'] ?? 0
        ]);
    }
    
    // Handle actions
    switch ($action) {
        case 'view':
            if ($id) {
                $message = getMessageDetailsWithHierarchy($id, $user_id, $user_type);
                if (!$message) {
                    $error = 'Pesan tidak ditemukan atau Anda tidak memiliki akses.';
                }
            }
            break;
            
        case 'delete':
            if ($id && isset($_POST['confirm_delete'])) {
                $result = deleteMessageDirect($id, $user_id, $user_type);
                if ($result['success']) {
                    $success = 'Pesan berhasil dihapus.';
                } else {
                    $error = $result['error'];
                }
                header('Location: messages.php?success=' . urlencode($success) . '&error=' . urlencode($error));
                exit;
            }
            break;
            
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $result = createMessageDirect($_POST, $user_id, $_SESSION['nama_lengkap'], $_SESSION['nis_nip']);
                if ($result['success']) {
                    $success = 'Pesan berhasil dikirim!';
                    header('Location: messages.php?success=' . urlencode($success));
                    exit;
                } else {
                    $error = implode('<br>', $result['errors']);
                }
            }
            break;
            
        case 'respond':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
                $result = respondToMessageDirect($id, $_POST, $user_id, $user_type);
                if ($result['success']) {
                    $success = 'Respon berhasil dikirim!';
                    header('Location: messages.php?action=view&id=' . $id . '&success=' . urlencode($success));
                    exit;
                } else {
                    $error = $result['error'];
                }
            }
            break;
            
        case 'review':
            // Untuk Wakil Kepala Sekolah dan Kepala Sekolah memberikan review
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && in_array($user_type, ['Wakil_Kepala', 'Kepala_Sekolah'])) {
                $result = addWakepsekReview($id, $_POST, $user_id, $user_type);
                if ($result['success']) {
                    $success = 'Review berhasil dikirim!';
                    header('Location: messages.php?action=view&id=' . $id . '&success=' . urlencode($success));
                    exit;
                } else {
                    $error = $result['error'];
                }
            }
            break;
    }
    
    // ========== FETCH MESSAGES WITH FILTERS ==========
    $whereConditions = ["1=1"];
    $params = [];
    
    // Filter by user role (non-admin can only see their own messages)
    if ($user_type !== 'Admin') {
        $whereConditions[] = "m.pengirim_id = :user_id";
        $params[':user_id'] = $user_id;
    }
    
    // Filter by status
    if ($filters['status'] !== 'all') {
        $whereConditions[] = "m.status = :status";
        $params[':status'] = $filters['status'];
    }
    
    // Filter by message type
    if ($filters['type'] !== 'all') {
        $whereConditions[] = "m.jenis_pesan_id = :type";
        $params[':type'] = $filters['type'];
    }
    
    // Filter by priority
    if ($filters['priority'] !== 'all') {
        $whereConditions[] = "m.priority = :priority";
        $params[':priority'] = $filters['priority'];
    }
    
    // Filter by date range
    if (!empty($filters['date_from'])) {
        $whereConditions[] = "DATE(m.created_at) >= :date_from";
        $params[':date_from'] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $whereConditions[] = "DATE(m.created_at) <= :date_to";
        $params[':date_to'] = $filters['date_to'];
    }
    
    // Filter by search
    if (!empty($filters['search'])) {
        $whereConditions[] = "(m.isi_pesan LIKE :search OR m.pengirim_nama LIKE :search OR m.pengirim_nis_nip LIKE :search)";
        $params[':search'] = '%' . $filters['search'] . '%';
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // First, get total count for pagination
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM messages m
        WHERE $whereClause
    ";
    
    $count_stmt = $db->prepare($count_sql);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_count = $count_stmt->fetch()['total'];
    
    // Calculate pagination
    $total_pages = $total_count > 0 ? ceil($total_count / $filters['per_page']) : 1;
    $offset = ($filters['page'] - 1) * $filters['per_page'];
    
    // Now get the messages with pagination - WITH ATTACHMENT COUNT AND REVIEW COUNT
    $sql = "
        SELECT m.*, mt.jenis_pesan,
               (SELECT COUNT(*) FROM message_responses WHERE message_id = m.id) as response_count,
               (SELECT status FROM message_responses WHERE message_id = m.id ORDER BY created_at DESC LIMIT 1) as last_response_status,
               (SELECT created_at FROM message_responses WHERE message_id = m.id ORDER BY created_at DESC LIMIT 1) as last_response_date,
               (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id) as attachment_count,
               (SELECT COUNT(*) FROM wakepsek_reviews WHERE message_id = m.id) as review_count,
               (SELECT catatan FROM wakepsek_reviews WHERE message_id = m.id ORDER BY created_at DESC LIMIT 1) as last_review_note
        FROM messages m
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        WHERE $whereClause
        ORDER BY m.created_at DESC
        LIMIT :offset, :limit
    ";
    
    $stmt = $db->prepare($sql);
    
    // Bind all parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $filters['per_page'], PDO::PARAM_INT);
    
    $stmt->execute();
    $messages = $stmt->fetchAll();
    
    // Set pagination data
    $pagination = [
        'page' => $filters['page'],
        'per_page' => $filters['per_page'],
        'total' => $total_count,
        'total_pages' => $total_pages
    ];
    
} catch (Exception $e) {
    $error = 'Database Error: ' . $e->getMessage();
    error_log("Messages Module Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

// Set page title
$pageTitle = 'Manajemen Pesan';
if ($action === 'view' && isset($message) && $message) {
    $pageTitle = 'Detail Pesan #' . $id;
} elseif ($action === 'create') {
    $pageTitle = 'Buat Pesan Baru';
}

require_once '../../includes/header.php';
?>

<!-- Image Preview Modal -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
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
                <i class="fas fa-comments me-2"></i><?php echo $pageTitle; ?>
                <span class="badge bg-success ms-2">Direct Database Access</span>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Pesan</li>
                </ol>
            </nav>
        </div>
        <div>
            <?php if ($action === ''): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createMessageModal">
                <i class="fas fa-plus me-1"></i> Pesan Baru
            </button>
            <?php elseif ($action === 'view' && isset($message) && $message): ?>
            <a href="messages.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Kembali
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if ($action === 'view' && isset($message) && $message): ?>
    <!-- Message Detail View -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Detail Pesan #<?php echo $id; ?></h5>
                        <div>
                            <?php if ($message['pengirim_id'] == $user_id || $user_type === 'Admin'): ?>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                <i class="fas fa-trash me-1"></i> Hapus
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Message Info -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                 <tr>
                                    <th width="120">Jenis Pesan</th>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars($message['jenis_pesan'] ?? 'N/A'); ?>
                                        </span>
                                        <?php if ($user_type === 'Admin'): ?>
                                        <a href="../admin/type_details.php?id=<?php echo $message['jenis_pesan_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary ms-2" 
                                           title="Lihat detail jenis pesan">
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td>
                                        <?php echo getStatusBadge($message['status']); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Prioritas</th>
                                    <td>
                                        <?php echo getPriorityBadge($message['priority']); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Tanggal Kirim</th>
                                    <td><?php echo formatDateTime($message['created_at']); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="120">Pengirim</th>
                                    <td><?php echo htmlspecialchars($message['pengirim_nama'] ?? '-'); ?></td>
                                </tr>
                                <tr>
                                    <th>NIS/NIP</th>
                                    <td><?php echo htmlspecialchars($message['pengirim_nis_nip'] ?? '-'); ?></td>
                                </tr>
                                <?php if (!empty($message['tanggal_respon'])): ?>
                                <tr>
                                    <th>Tanggal Respon</th>
                                    <td><?php echo formatDateTime($message['tanggal_respon']); ?></td>
                                </tr>
                                <tr>
                                    <th>Responder</th>
                                    <td>
                                        <?php 
                                        if (!empty($message['responder_id'])) {
                                            echo 'Guru/Admin';
                                        } else {
                                            echo 'Sistem';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Message Content -->
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Isi Pesan</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($message['isi_pesan'] ?? '')); ?></p>
                        </div>
                    </div>
                    
                    <!-- Lampiran Gambar -->
                    <?php 
                    $has_attachments = !empty($message['attachments']) && count($message['attachments']) > 0;
                    ?>
                    
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">
                                <i class="fas fa-images me-2"></i>
                                Lampiran Gambar 
                                <span class="badge <?php echo $has_attachments ? 'bg-primary' : 'bg-secondary'; ?> ms-2">
                                    <?php echo $has_attachments ? count($message['attachments']) : '0'; ?>
                                </span>
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if ($has_attachments): ?>
                                <div class="row g-3">
                                    <?php foreach ($message['attachments'] as $attachment): ?>
                                    <?php 
                                        $attachment_file_name = $attachment['filename'] ?? '';
                                        $attachment_original_name = $attachment['original_name'] ?? $attachment_file_name;
                                        $attachment_file_size = $attachment['filesize'] ?? $attachment['file_size'] ?? 0;
                                        
                                        $is_external = $message['is_external'] ?? 0;
                                        
                                        if ($is_external) {
                                            if (strpos($attachment_file_name, 'uploads/external_messages/') !== false) {
                                                $image_url = BASE_URL . '/' . $attachment_file_name;
                                            } else {
                                                $image_url = BASE_URL_UPLOAD_EXTERNAL . $attachment_file_name;
                                            }
                                        } else {
                                            if (strpos($attachment_file_name, 'uploads/messages/') !== false) {
                                                $image_url = BASE_URL . '/' . $attachment_file_name;
                                            } else {
                                                $image_url = BASE_URL_UPLOAD_MESSAGES . $attachment_file_name;
                                            }
                                        }
                                        
                                        if (!empty($attachment['filepath'])) {
                                            $image_url = BASE_URL . '/' . $attachment['filepath'];
                                        }
                                    ?>
                                    <div class="col-md-4 col-sm-6">
                                        <div class="attachment-item card h-100">
                                            <div class="attachment-preview position-relative" 
                                                 style="height: 150px; overflow: hidden; cursor: pointer; background: #f8f9fa;"
                                                 onclick="previewImage('<?php echo $image_url; ?>', '<?php echo htmlspecialchars($attachment_original_name ?: 'image.jpg'); ?>')">
                                                <img src="<?php echo $image_url; ?>" 
                                                     alt="<?php echo htmlspecialchars($attachment_original_name ?: 'Attachment image'); ?>"
                                                     style="width: 100%; height: 100%; object-fit: cover;"
                                                     loading="lazy"
                                                     onerror="this.onerror=null; this.src='<?php echo $placeholder_image; ?>'; this.style.objectFit='contain'; this.style.padding='10px';">
                                                <div class="attachment-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-dark bg-opacity-25 opacity-0 transition-all">
                                                    <i class="fas fa-search-plus text-white fa-2x"></i>
                                                </div>
                                            </div>
                                            <div class="card-body p-2">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div class="text-truncate" style="max-width: 150px;">
                                                        <small title="<?php echo htmlspecialchars($attachment_original_name ?: 'image.jpg'); ?>">
                                                            <?php 
                                                            $display_name = $attachment_original_name ?: 'image.jpg';
                                                            echo htmlspecialchars(substr($display_name, 0, 20)); 
                                                            if (strlen($display_name) > 20) echo '...'; 
                                                            ?>
                                                        </small>
                                                    </div>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" 
                                                                class="btn btn-outline-primary" 
                                                                onclick="previewImage('<?php echo $image_url; ?>', '<?php echo htmlspecialchars($attachment_original_name ?: 'image.jpg'); ?>')"
                                                                title="Preview">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="<?php echo $image_url; ?>" 
                                                           class="btn btn-outline-success" 
                                                           download="<?php echo htmlspecialchars($attachment_original_name ?: 'image.jpg'); ?>"
                                                           title="Download">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                                <small class="text-muted d-block mt-1">
                                                    <?php 
                                                    $size = intval($attachment_file_size);
                                                    echo $size > 0 ? round($size / 1024, 1) . ' KB' : 'Size unknown'; 
                                                    ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <div class="empty-attachment-icon mb-3">
                                        <i class="fas fa-image fa-4x text-muted opacity-50"></i>
                                    </div>
                                    <h6 class="text-muted">Tidak Ada Lampiran Gambar</h6>
                                    <p class="text-muted small mb-0">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Pesan ini tidak dilengkapi dengan gambar lampiran.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- ========================================================= -->
                    <!-- RIWAYAT RESPON BERTINGKAT (HIERARCHICAL RESPONSES) -->
                    <!-- ========================================================= -->
                    <div class="card">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">
                                <i class="fas fa-history me-2"></i>
                                Riwayat Respon Berjenjang
                                <span class="badge bg-primary rounded-pill ms-2">
                                    <?php 
                                        $total_responses = count($message['responses'] ?? []);
                                        $total_reviews = count($message['reviews'] ?? []);
                                        echo $total_responses + $total_reviews;
                                    ?>
                                </span>
                            </h6>
                            <small class="text-muted">Hierarki: Guru/Admin → Wakil Kepala Sekolah → Kepala Sekolah</small>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($message['responses']) || !empty($message['reviews'])): ?>
                                <div class="timeline">
                                    
                                    <!-- 1. RESPON DARI GURU/ADMIN -->
                                    <?php if (!empty($message['responses'])): ?>
                                        <?php foreach ($message['responses'] as $response): ?>
                                        <div class="timeline-item mb-4">
                                            <div class="timeline-badge bg-primary">
                                                <i class="fas fa-chalkboard-teacher"></i>
                                            </div>
                                            <div class="timeline-content card border-0 shadow-sm">
                                                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong>
                                                            <i class="fas fa-user-graduate me-1"></i>
                                                            <?php echo htmlspecialchars($response['responder_nama'] ?? 'Sistem'); ?>
                                                        </strong>
                                                        <span class="badge bg-primary ms-2">Level 1: Guru/Admin</span>
                                                    </div>
                                                    <small class="text-muted"><?php echo formatDateTime($response['created_at'] ?? ''); ?></small>
                                                </div>
                                                <div class="card-body py-3">
                                                    <div class="mb-2">
                                                        <span class="badge bg-<?php 
                                                            switch($response['status'] ?? '') {
                                                                case 'Disetujui': echo 'success'; break;
                                                                case 'Ditolak': echo 'danger'; break;
                                                                case 'Diproses': echo 'info'; break;
                                                                case 'Selesai': echo 'secondary'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <i class="fas fa-flag-checkered me-1"></i>
                                                            Status: <?php echo htmlspecialchars($response['status'] ?? 'Diproses'); ?>
                                                        </span>
                                                    </div>
                                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($response['catatan_respon'] ?? '')); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <!-- 2. REVIEW DARI WAKIL KEPALA SEKOLAH (WAKASEK) -->
                                    <?php if (!empty($message['reviews'])): ?>
                                        <?php foreach ($message['reviews'] as $review): ?>
                                            <?php if ($review['reviewer_type'] === 'Wakil_Kepala'): ?>
                                            <div class="timeline-item mb-4">
                                                <div class="timeline-badge bg-warning">
                                                    <i class="fas fa-user-tie"></i>
                                                </div>
                                                <div class="timeline-content card border-0 shadow-sm">
                                                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <strong>
                                                                <i class="fas fa-star-of-life me-1"></i>
                                                                <?php echo htmlspecialchars($review['reviewer_name'] ?? 'Wakil Kepala Sekolah'); ?>
                                                            </strong>
                                                            <span class="badge bg-warning ms-2">Level 2: Wakil Kepala Sekolah</span>
                                                        </div>
                                                        <small class="text-muted"><?php echo formatDateTime($review['created_at'] ?? ''); ?></small>
                                                    </div>
                                                    <div class="card-body py-3">
                                                        <div class="mb-2">
                                                            <span class="badge bg-info">
                                                                <i class="fas fa-gavel me-1"></i>
                                                                Catatan Review
                                                            </span>
                                                        </div>
                                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($review['catatan'] ?? $review['review_notes'] ?? '')); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <!-- 3. REVIEW DARI KEPALA SEKOLAH (KASEK) -->
                                    <?php if (!empty($message['reviews'])): ?>
                                        <?php foreach ($message['reviews'] as $review): ?>
                                            <?php if ($review['reviewer_type'] === 'Kepala_Sekolah'): ?>
                                            <div class="timeline-item mb-4">
                                                <div class="timeline-badge bg-danger">
                                                    <i class="fas fa-crown"></i>
                                                </div>
                                                <div class="timeline-content card border-0 shadow-sm">
                                                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <strong>
                                                                <i class="fas fa-landmark me-1"></i>
                                                                <?php echo htmlspecialchars($review['reviewer_name'] ?? 'Kepala Sekolah'); ?>
                                                            </strong>
                                                            <span class="badge bg-danger ms-2">Level 3: Kepala Sekolah</span>
                                                        </div>
                                                        <small class="text-muted"><?php echo formatDateTime($review['created_at'] ?? ''); ?></small>
                                                    </div>
                                                    <div class="card-body py-3">
                                                        <div class="mb-2">
                                                            <span class="badge bg-info">
                                                                <i class="fas fa-stamp me-1"></i>
                                                                Catatan Final
                                                            </span>
                                                        </div>
                                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($review['catatan'] ?? $review['review_notes'] ?? '')); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-comment-dots fa-3x text-muted mb-3"></i>
                                    <h6 class="text-muted">Belum Ada Respon atau Review</h6>
                                    <p class="text-muted small">Pesan ini belum mendapatkan respon dari pihak terkait.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Response Form (untuk Guru/Admin) -->
            <?php if (($user_type === 'Admin' || in_array($user_type, ['Guru', 'Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'])) && in_array($message['status'], ['Pending', 'Dibaca', 'Diproses'])): ?>
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-reply-all me-2 text-primary"></i>Berikan Respon (Level 1: Guru/Admin)</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="?action=respond&id=<?php echo $id; ?>" id="responseForm">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Status *</label>
                            <select class="form-select" name="status" required>
                                <option value="">Pilih Status...</option>
                                <option value="Diproses">Diproses</option>
                                <option value="Disetujui">Disetujui</option>
                                <option value="Ditolak">Ditolak</option>
                                <option value="Selesai">Selesai</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Catatan Respon *</label>
                            <textarea class="form-control" name="catatan_respon" rows="4" placeholder="Masukkan catatan respon..." required></textarea>
                            <div id="responseCharCount" class="form-text text-end">0/500</div>
                        </div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary px-4" id="submitResponseBtn">
                                <i class="fas fa-paper-plane me-2"></i> Kirim Respon
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Review Form (untuk Wakil Kepala Sekolah dan Kepala Sekolah) -->
            <?php if (in_array($user_type, ['Wakil_Kepala', 'Kepala_Sekolah'])): 
                $review_level = ($user_type === 'Wakil_Kepala') ? 'Level 2: Wakil Kepala Sekolah' : 'Level 3: Kepala Sekolah';
                $review_icon = ($user_type === 'Wakil_Kepala') ? 'fa-user-tie' : 'fa-crown';
                $review_color = ($user_type === 'Wakil_Kepala') ? 'warning' : 'danger';
            ?>
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas <?php echo $review_icon; ?> me-2 text-<?php echo $review_color; ?>"></i>
                        Berikan Review (<?php echo $review_level; ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="?action=review&id=<?php echo $id; ?>" id="reviewForm">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Catatan Review *</label>
                            <textarea class="form-control" name="catatan" rows="4" placeholder="Masukkan catatan review..." required></textarea>
                            <div id="reviewCharCount" class="form-text text-end">0/500</div>
                        </div>
                        <?php if ($user_type === 'Kepala_Sekolah'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Status Final</label>
                            <select class="form-select" name="status_final">
                                <option value="">Pilih Status Final...</option>
                                <option value="Selesai">Selesai (Final)</option>
                                <option value="Revisi">Perlu Revisi</option>
                            </select>
                            <small class="text-muted">Opsional - akan mengupdate status pesan</small>
                        </div>
                        <?php endif; ?>
                        <div class="text-end">
                            <button type="submit" class="btn btn-<?php echo $review_color; ?> px-4" id="submitReviewBtn">
                                <i class="fas fa-check-double me-2"></i> Kirim Review
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Sidebar Stats -->
        <div class="col-lg-4">
            <!-- Statistik Pesan Saya -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Statistik Pesan Saya</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Total Pesan</span>
                            <span class="badge bg-primary rounded-pill"><?php echo isset($stats['total']) ? $stats['total'] : 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Pending</span>
                            <span class="badge bg-warning rounded-pill"><?php echo isset($stats['pending']) ? $stats['pending'] : 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Disetujui</span>
                            <span class="badge bg-success rounded-pill"><?php echo isset($stats['approved']) ? $stats['approved'] : 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Ditolak</span>
                            <span class="badge bg-danger rounded-pill"><?php echo isset($stats['rejected']) ? $stats['rejected'] : 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Selesai</span>
                            <span class="badge bg-secondary rounded-pill"><?php echo isset($stats['completed']) ? $stats['completed'] : 0; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistik Respon (untuk Guru/Admin) -->
            <?php if (in_array($user_type, ['Guru', 'Admin', 'Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'])): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Statistik Respon Saya</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Total Respon</span>
                            <span class="badge bg-primary rounded-pill"><?php echo isset($stats['total_responses']) ? $stats['total_responses'] : 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Respon Hari Ini</span>
                            <span class="badge bg-info rounded-pill"><?php echo isset($stats['responses_today']) ? $stats['responses_today'] : 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Respon 24 Jam</span>
                            <span class="badge bg-info rounded-pill"><?php echo isset($stats['responses_last_24h']) ? $stats['responses_last_24h'] : 0; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Statistik Review (untuk Wakil Kepala dan Kepala Sekolah) -->
            <?php if (in_array($user_type, ['Wakil_Kepala', 'Kepala_Sekolah'])): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Statistik Review Saya</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Total Review</span>
                            <span class="badge bg-primary rounded-pill"><?php echo isset($stats['total_reviews']) ? $stats['total_reviews'] : 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Review Hari Ini</span>
                            <span class="badge bg-info rounded-pill"><?php echo isset($stats['reviews_today']) ? $stats['reviews_today'] : 0; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Statistik Global (untuk Admin) -->
            <?php if ($user_type === 'Admin' && isset($stats['admin_total'])): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Statistik Global</h5>
                </div>
                <div class="card-body">
                    <h6 class="text-muted mb-2">Semua Pesan</h6>
                    <div class="list-group list-group-flush mb-3">
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Total Pesan</span>
                            <span class="badge bg-primary rounded-pill"><?php echo $stats['admin_total'] ?? 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Pending</span>
                            <span class="badge bg-warning rounded-pill"><?php echo $stats['admin_pending'] ?? 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Disetujui</span>
                            <span class="badge bg-success rounded-pill"><?php echo $stats['admin_approved'] ?? 0; ?></span>
                        </div>
                    </div>
                    
                    <h6 class="text-muted mb-2">Semua Respon & Review</h6>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Respon Hari Ini</span>
                            <span class="badge bg-info rounded-pill"><?php echo $stats['admin_responses_today'] ?? 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Total Review</span>
                            <span class="badge bg-secondary rounded-pill"><?php echo $stats['admin_total_reviews'] ?? 0; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>Review Hari Ini</span>
                            <span class="badge bg-info rounded-pill"><?php echo $stats['admin_reviews_today'] ?? 0; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    <!-- Messages List View -->
    <div class="row">
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter</h5>
                </div>
                <div class="card-body">
                    <form method="GET" id="filterForm">
                        <input type="hidden" name="page" value="1">
                        <div class="mb-3">
                            <label class="form-label">Jenis Pesan</label>
                            <select class="form-select" name="type">
                                <option value="all">Semua Jenis</option>
                                <?php foreach ($message_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" <?php echo ($filters['type'] == $type['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['jenis_pesan']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="all">Semua Status</option>
                                <option value="Pending" <?php echo ($filters['status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="Dibaca" <?php echo ($filters['status'] == 'Dibaca') ? 'selected' : ''; ?>>Dibaca</option>
                                <option value="Diproses" <?php echo ($filters['status'] == 'Diproses') ? 'selected' : ''; ?>>Diproses</option>
                                <option value="Disetujui" <?php echo ($filters['status'] == 'Disetujui') ? 'selected' : ''; ?>>Disetujui</option>
                                <option value="Ditolak" <?php echo ($filters['status'] == 'Ditolak') ? 'selected' : ''; ?>>Ditolak</option>
                                <option value="Selesai" <?php echo ($filters['status'] == 'Selesai') ? 'selected' : ''; ?>>Selesai</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Prioritas</label>
                            <select class="form-select" name="priority">
                                <option value="all">Semua Prioritas</option>
                                <option value="Urgent" <?php echo ($filters['priority'] == 'Urgent') ? 'selected' : ''; ?>>Urgent</option>
                                <option value="High" <?php echo ($filters['priority'] == 'High') ? 'selected' : ''; ?>>High</option>
                                <option value="Medium" <?php echo ($filters['priority'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                <option value="Low" <?php echo ($filters['priority'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        <div class="mb-3"><input type="text" class="form-control" name="search" placeholder="Cari pesan..." value="<?php echo htmlspecialchars($filters['search']); ?>"></div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Terapkan Filter</button>
                            <a href="messages.php" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Reset Filter</a>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Statistik Pesan</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between px-0"><span>Total Pesan</span><span class="badge bg-primary"><?php echo isset($stats['total']) ? $stats['total'] : 0; ?></span></div>
                        <div class="list-group-item d-flex justify-content-between px-0"><span>Pending</span><span class="badge bg-warning"><?php echo isset($stats['pending']) ? $stats['pending'] : 0; ?></span></div>
                        <div class="list-group-item d-flex justify-content-between px-0"><span>Disetujui</span><span class="badge bg-success"><?php echo isset($stats['approved']) ? $stats['approved'] : 0; ?></span></div>
                        <div class="list-group-item d-flex justify-content-between px-0"><span>Ditolak</span><span class="badge bg-danger"><?php echo isset($stats['rejected']) ? $stats['rejected'] : 0; ?></span></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-9">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Daftar Pesan <span class="text-muted small">(<?php echo isset($pagination['total']) ? $pagination['total'] : 0; ?> pesan)</span></h5>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($messages)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Jenis</th>
                                    <th>Isi Pesan</th>
                                    <th>Lampiran</th>
                                    <th>Status</th>
                                    <th>Prioritas</th>
                                    <th>Respon/Review</th>
                                    <th>Tanggal</th>
                                    <th>Aksi</th>
                                  </tr>
                            </thead>
                            <tbody>
                                <?php $start_number = (($filters['page'] - 1) * $filters['per_page']) + 1; ?>
                                <?php foreach ($messages as $index => $msg): ?>
                                <tr>
                                    <td class="align-middle"><?php echo $start_number + $index; ?></td>
                                    <td class="align-middle"><span class="badge bg-info"><?php echo htmlspecialchars($msg['jenis_pesan'] ?? '-'); ?></span></td>
                                    <td class="align-middle">
                                        <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" 
                                             title="<?php echo htmlspecialchars($msg['isi_pesan'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(substr($msg['isi_pesan'] ?? '', 0, 50)); ?>
                                        </div>
                                    </td>
                                    <td class="align-middle text-center">
                                        <?php if (isset($msg['attachment_count']) && $msg['attachment_count'] > 0): ?>
                                        <span class="badge bg-info"><i class="fas fa-paperclip me-1"></i><?php echo $msg['attachment_count']; ?></span>
                                        <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-image opacity-25"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle"><?php echo getStatusBadge($msg['status']); ?></td>
                                    <td class="align-middle"><?php echo getPriorityBadge($msg['priority']); ?></td>
                                    <td class="align-middle">
                                        <?php if (($msg['response_count'] ?? 0) > 0 || ($msg['review_count'] ?? 0) > 0): ?>
                                            <div class="d-flex flex-column">
                                                <?php if (($msg['response_count'] ?? 0) > 0): ?>
                                                <span class="badge bg-primary mb-1">
                                                    <i class="fas fa-reply-all me-1"></i> Respon: <?php echo $msg['response_count']; ?>
                                                </span>
                                                <?php endif; ?>
                                                <?php if (($msg['review_count'] ?? 0) > 0): ?>
                                                <span class="badge bg-warning">
                                                    <i class="fas fa-gavel me-1"></i> 
                                                    Review: <?php echo $msg['review_count']; ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Belum ada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle"><?php echo date('d M Y', strtotime($msg['created_at'])); ?></td>
                                    <td class="align-middle">
                                        <a href="?action=view&id=<?php echo $msg['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Tidak ada pesan</h5>
                        <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#createMessageModal">
                            <i class="fas fa-plus me-1"></i> Buat Pesan
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (isset($pagination['total_pages']) && $pagination['total_pages'] > 1): ?>
                <div class="card-footer bg-white">
                    <nav><ul class="pagination justify-content-center mb-0">
                        <?php if ($pagination['page'] > 1): ?>
                        <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['page'] - 1; ?>"><i class="fas fa-chevron-left"></i></a></li>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                            <?php if ($i == $pagination['page']): ?>
                            <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
                            <?php elseif (abs($i - $pagination['page']) <= 2 || $i == 1 || $i == $pagination['total_pages']): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a></li>
                            <?php elseif (abs($i - $pagination['page']) == 3): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($pagination['page'] < $pagination['total_pages']): ?>
                        <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['page'] + 1; ?>"><i class="fas fa-chevron-right"></i></a></li>
                        <?php endif; ?>
                    </ul></nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Create Message Modal -->
<div class="modal fade" id="createMessageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="?action=create">
                <div class="modal-header"><h5 class="modal-title">Buat Pesan Baru</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Jenis Pesan *</label><select class="form-select" name="jenis_pesan_id" required><option value="">Pilih Jenis Pesan...</option><?php foreach ($message_types as $type): ?><option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['jenis_pesan']); ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label class="form-label">Prioritas</label><select class="form-select" name="priority"><option value="Medium">Medium</option><option value="Low">Low</option><option value="High">High</option><option value="Urgent">Urgent</option></select></div>
                    <div class="mb-3"><label class="form-label">Isi Pesan *</label><textarea class="form-control" name="isi_pesan" rows="6" required></textarea><div id="charCount" class="form-text text-end">0/1000</div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i> Kirim</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<?php if ($action === 'view' && isset($message) && $message): ?>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?action=delete&id=<?php echo $id; ?>">
                <div class="modal-header"><h5 class="modal-title">Konfirmasi Hapus</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><p>Apakah Anda yakin ingin menghapus pesan ini?</p><div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>Aksi ini tidak dapat dibatalkan.</div></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" name="confirm_delete" class="btn btn-danger"><i class="fas fa-trash me-1"></i> Hapus</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.timeline { position: relative; padding-left: 30px; }
.timeline:before { content: ''; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: #e9ecef; }
.timeline-item { position: relative; margin-bottom: 20px; }
.timeline-badge { position: absolute; left: -26px; top: 0; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 14px; z-index: 1; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.timeline-content { margin-left: 15px; }
.attachment-item { transition: transform 0.2s, box-shadow 0.2s; border: 1px solid #e9ecef; overflow: hidden; }
.attachment-item:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
.attachment-preview { position: relative; background: #f8f9fa; }
.attachment-overlay { opacity: 0; transition: opacity 0.3s ease; background: linear-gradient(to bottom, rgba(0,0,0,0.3), rgba(0,0,0,0.5)); }
.attachment-preview:hover .attachment-overlay { opacity: 1; }
.attachment-preview img { transition: transform 0.3s ease; }
.attachment-preview:hover img { transform: scale(1.05); }
.empty-attachment-icon { width: 80px; height: 80px; margin: 0 auto; background: #f8f9fa; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px dashed #dee2e6; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const msgTextarea = document.getElementById('isi_pesan');
    if (msgTextarea) {
        msgTextarea.addEventListener('input', function() {
            const len = this.value.length;
            document.getElementById('charCount').innerHTML = len + '/1000' + (len > 1000 ? ' (Terlalu panjang)' : '');
        });
    }
    const respTextarea = document.getElementById('catatan_respon');
    if (respTextarea) {
        respTextarea.addEventListener('input', function() {
            const len = this.value.length;
            document.getElementById('responseCharCount').innerHTML = len + '/500' + (len > 500 ? ' (Terlalu panjang)' : '');
        });
    }
    const reviewTextarea = document.querySelector('textarea[name="catatan"]');
    if (reviewTextarea) {
        const reviewCharCount = document.getElementById('reviewCharCount');
        if (reviewCharCount) {
            reviewTextarea.addEventListener('input', function() {
                const len = this.value.length;
                reviewCharCount.innerHTML = len + '/500' + (len > 500 ? ' (Terlalu panjang)' : '');
            });
        }
    }
});

function previewImage(url, name) {
    const modal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
    const container = document.getElementById('imagePreviewContainer');
    const downloadBtn = document.getElementById('downloadImageBtn');
    document.querySelector('#imagePreviewModal .modal-title').innerHTML = '<i class="fas fa-image me-2"></i>Preview: ' + name;
    container.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary"></div><p>Memuat...</p></div>';
    const img = new Image();
    img.onload = function() { container.innerHTML = ''; container.appendChild(img); downloadBtn.style.display = 'inline-block'; };
    img.onerror = function() { container.innerHTML = '<div class="text-center p-5"><i class="fas fa-exclamation-triangle text-warning fa-3x"></i><h6>Gambar tidak dapat dimuat</h6></div>'; downloadBtn.style.display = 'none'; };
    img.src = url; img.alt = name; img.className = 'img-fluid'; img.style.maxHeight = '70vh';
    downloadBtn.href = url; downloadBtn.download = name;
    modal.show();
}
</script>

<?php
require_once '../../includes/footer.php';

// ========================================================
// FUNGSI UTAMA DENGAN INTEGRASI WAKEPSEK_REVIEWS
// ========================================================

/**
 * Get message details with hierarchical responses (responses + reviews)
 */
function getMessageDetailsWithHierarchy($id, $user_id, $user_type) {
    try {
        require_once '../../config/database.php';
        $db = Database::getInstance()->getConnection();
        
        $sql = "
            SELECT m.*, mt.jenis_pesan 
            FROM messages m
            LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
            WHERE m.id = :id
        ";
        
        if ($user_type !== 'Admin') {
            $sql .= " AND m.pengirim_id = :user_id";
        }
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($user_type !== 'Admin') {
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $message = $stmt->fetch();
        
        if (!$message) {
            return null;
        }
        
        // Get responses from message_responses (Level 1: Guru/Admin)
        $response_sql = "
            SELECT mr.*, u.nama_lengkap as responder_nama, u.user_type as responder_type 
            FROM message_responses mr
            LEFT JOIN users u ON mr.responder_id = u.id
            WHERE mr.message_id = :message_id
            ORDER BY mr.created_at ASC
        ";
        $response_stmt = $db->prepare($response_sql);
        $response_stmt->execute([':message_id' => $id]);
        $message['responses'] = $response_stmt->fetchAll();
        
        // Get reviews from wakepsek_reviews (Level 2 & 3: Wakil Kepala & Kepala Sekolah)
        // Struktur tabel wakepsek_reviews: id, message_id, reviewer_id, catatan, created_at
        $review_sql = "
            SELECT wr.*, u.nama_lengkap as reviewer_name, u.user_type as reviewer_type
            FROM wakepsek_reviews wr
            LEFT JOIN users u ON wr.reviewer_id = u.id
            WHERE wr.message_id = :message_id
            ORDER BY 
                CASE 
                    WHEN u.user_type = 'Wakil_Kepala' THEN 1
                    WHEN u.user_type = 'Kepala_Sekolah' THEN 2
                    ELSE 3
                END,
                wr.created_at ASC
        ";
        $review_stmt = $db->prepare($review_sql);
        $review_stmt->execute([':message_id' => $id]);
        $reviews = $review_stmt->fetchAll();
        
        // Konversi field catatan untuk kompatibilitas
        foreach ($reviews as &$review) {
            $review['review_notes'] = $review['catatan'] ?? '';
            $review['catatan_respon'] = $review['catatan'] ?? '';
        }
        $message['reviews'] = $reviews;
        
        // Get attachments
        $attachment_sql = "SELECT * FROM message_attachments WHERE message_id = :message_id ORDER BY created_at ASC";
        $attachment_stmt = $db->prepare($attachment_sql);
        $attachment_stmt->execute([':message_id' => $id]);
        $message['attachments'] = $attachment_stmt->fetchAll();
        
        return $message;
        
    } catch (Exception $e) {
        error_log("Error getting message details with hierarchy: " . $e->getMessage());
        return null;
    }
}

/**
 * Add review from Wakil Kepala or Kepala Sekolah
 */
function addWakepsekReview($message_id, $data, $user_id, $user_type) {
    if (empty($data['catatan'])) {
        return ['success' => false, 'error' => 'Catatan review harus diisi'];
    }
    
    try {
        require_once '../../config/database.php';
        $db = Database::getInstance()->getConnection();
        
        $db->beginTransaction();
        
        // Insert review - sesuai struktur tabel wakepsek_reviews
        $review_sql = "
            INSERT INTO wakepsek_reviews (
                message_id, reviewer_id, catatan, created_at
            ) VALUES (
                :message_id, :reviewer_id, :catatan, NOW()
            )
        ";
        
        $review_stmt = $db->prepare($review_sql);
        $review_stmt->execute([
            ':message_id' => $message_id,
            ':reviewer_id' => $user_id,
            ':catatan' => htmlspecialchars($data['catatan'])
        ]);
        
        // Update message status if final decision from Kepala Sekolah
        if ($user_type === 'Kepala_Sekolah' && isset($data['status_final']) && !empty($data['status_final'])) {
            $update_sql = "
                UPDATE messages 
                SET status = :status, 
                    updated_at = NOW()
                WHERE id = :message_id
            ";
            
            $update_stmt = $db->prepare($update_sql);
            $update_stmt->execute([
                ':status' => $data['status_final'],
                ':message_id' => $message_id
            ]);
        }
        
        $db->commit();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        if (isset($db)) $db->rollBack();
        error_log("Error adding review: " . $e->getMessage());
        return ['success' => false, 'error' => 'Gagal menambahkan review: ' . $e->getMessage()];
    }
}

/**
 * Create message directly in database
 */
function createMessageDirect($data, $user_id, $user_name, $user_nis_nip) {
    $errors = [];
    
    if (empty($data['jenis_pesan_id'])) $errors[] = 'Jenis pesan harus dipilih';
    if (empty($data['isi_pesan'])) $errors[] = 'Isi pesan harus diisi';
    elseif (strlen($data['isi_pesan']) < 10) $errors[] = 'Isi pesan minimal 10 karakter';
    elseif (strlen($data['isi_pesan']) > 1000) $errors[] = 'Isi pesan maksimal 1000 karakter';
    
    if (!empty($errors)) return ['success' => false, 'errors' => $errors];
    
    try {
        require_once '../../config/database.php';
        $db = Database::getInstance()->getConnection();
        
        $sql = "
            INSERT INTO messages (
                jenis_pesan_id, pengirim_id, pengirim_nama, 
                pengirim_nis_nip, isi_pesan, status, priority, 
                has_attachments, created_at, updated_at
            ) VALUES (
                :jenis_pesan_id, :pengirim_id, :pengirim_nama,
                :pengirim_nis_nip, :isi_pesan, 'Pending', :priority,
                0, NOW(), NOW()
            )
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':jenis_pesan_id' => $data['jenis_pesan_id'],
            ':pengirim_id' => $user_id,
            ':pengirim_nama' => $user_name,
            ':pengirim_nis_nip' => $user_nis_nip ?? '',
            ':isi_pesan' => htmlspecialchars($data['isi_pesan']),
            ':priority' => $data['priority'] ?? 'Medium'
        ]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error creating message: " . $e->getMessage());
        return ['success' => false, 'errors' => ['Gagal menyimpan pesan: ' . $e->getMessage()]];
    }
}

/**
 * Respond to message directly in database
 */
function respondToMessageDirect($message_id, $data, $user_id, $user_type) {
    if (empty($data['catatan_respon']) || empty($data['status'])) {
        return ['success' => false, 'error' => 'Catatan dan status harus diisi'];
    }
    
    try {
        require_once '../../config/database.php';
        $db = Database::getInstance()->getConnection();
        
        $db->beginTransaction();
        
        $response_sql = "
            INSERT INTO message_responses (
                message_id, responder_id, catatan_respon, status, created_at
            ) VALUES (
                :message_id, :responder_id, :catatan_respon, :status, NOW()
            )
        ";
        
        $response_stmt = $db->prepare($response_sql);
        $response_stmt->execute([
            ':message_id' => $message_id,
            ':responder_id' => $user_id,
            ':catatan_respon' => htmlspecialchars($data['catatan_respon']),
            ':status' => $data['status']
        ]);
        
        $update_sql = "
            UPDATE messages 
            SET status = :status, 
                responder_id = :responder_id,
                tanggal_respon = NOW(),
                updated_at = NOW()
            WHERE id = :message_id
        ";
        
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->execute([
            ':status' => $data['status'],
            ':responder_id' => $user_id,
            ':message_id' => $message_id
        ]);
        
        $db->commit();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        if (isset($db)) $db->rollBack();
        error_log("Error responding to message: " . $e->getMessage());
        return ['success' => false, 'error' => 'Gagal mengirim respon: ' . $e->getMessage()];
    }
}

/**
 * Delete message directly from database
 */
function deleteMessageDirect($id, $user_id, $user_type) {
    try {
        require_once '../../config/database.php';
        $db = Database::getInstance()->getConnection();
        
        $sql = "SELECT pengirim_id FROM messages WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $message = $stmt->fetch();
        
        if (!$message) return ['success' => false, 'error' => 'Pesan tidak ditemukan'];
        if ($user_type !== 'Admin' && $message['pengirim_id'] != $user_id) {
            return ['success' => false, 'error' => 'Anda tidak memiliki izin untuk menghapus pesan ini'];
        }
        
        $db->beginTransaction();
        
        $db->prepare("DELETE FROM message_responses WHERE message_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM wakepsek_reviews WHERE message_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM message_attachments WHERE message_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM messages WHERE id = :id")->execute([':id' => $id]);
        
        $db->commit();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        if (isset($db)) $db->rollBack();
        error_log("Error deleting message: " . $e->getMessage());
        return ['success' => false, 'error' => 'Gagal menghapus pesan: ' . $e->getMessage()];
    }
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status) {
    $badge_class = '';
    $icon = '';
    switch($status) {
        case 'Pending': $badge_class = 'warning'; $icon = 'fas fa-clock'; break;
        case 'Dibaca': $badge_class = 'info'; $icon = 'fas fa-eye'; break;
        case 'Diproses': $badge_class = 'primary'; $icon = 'fas fa-cog'; break;
        case 'Disetujui': $badge_class = 'success'; $icon = 'fas fa-check'; break;
        case 'Ditolak': $badge_class = 'danger'; $icon = 'fas fa-times'; break;
        case 'Selesai': $badge_class = 'secondary'; $icon = 'fas fa-flag-checkered'; break;
        default: $badge_class = 'light'; $icon = 'fas fa-question';
    }
    return '<span class="badge bg-' . $badge_class . '"><i class="' . $icon . ' me-1"></i>' . htmlspecialchars($status ?? 'Unknown') . '</span>';
}

/**
 * Get priority badge HTML
 */
function getPriorityBadge($priority) {
    $badge_class = '';
    switch($priority) {
        case 'Urgent': $badge_class = 'danger'; break;
        case 'High': $badge_class = 'warning'; break;
        case 'Medium': $badge_class = 'info'; break;
        case 'Low': $badge_class = 'secondary'; break;
        default: $badge_class = 'light';
    }
    return '<span class="badge bg-' . $badge_class . '">' . htmlspecialchars($priority ?? 'Unknown') . '</span>';
}

/**
 * Format datetime
 */
function formatDateTime($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '-';
    return date('d M Y H:i', strtotime($datetime));
}
?>