<?php
/**
 * AJAX Handler untuk Mendapatkan Detail Pesan
 * File: modules/wakepsek/ajax/get_message_detail.php
 * 
 * FITUR:
 * - Mengambil detail lengkap pesan beserta responses dan reviews
 * - Menyertakan informasi lampiran
 * - Menampilkan thumbnail gambar
 */

// ============================================================================
// ERROR REPORTING & LOGGING
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/ajax_errors.log');

header('Content-Type: application/json');

// ============================================================================
// LOAD KONFIGURASI
// ============================================================================
require_once '../../../config/config.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/functions.php';

// ============================================================================
// FUNGSI LOGGING
// ============================================================================
function ajaxLog($message, $data = null) {
    $logDir = __DIR__ . '/../../../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $logFile = $logDir . '/ajax_detail.log';
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents($logFile, $log, FILE_APPEND);
}

ajaxLog("========== GET_MESSAGE_DETAIL START ==========");
ajaxLog("Request received", $_GET);

// ============================================================================
// CEK AUTHENTICATION
// ============================================================================
try {
    Auth::checkAuth();
    ajaxLog("Auth check passed");
} catch (Exception $e) {
    ajaxLog("Auth failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Session tidak valid. Silakan login ulang.'
    ]);
    exit;
}

// ============================================================================
// CEK AKSES
// ============================================================================
$allowedTypes = ['Wakil_Kepala', 'Kepala_Sekolah'];
$userType = $_SESSION['user_type'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

if (!in_array($userType, $allowedTypes)) {
    ajaxLog("Access denied - User type: $userType");
    echo json_encode([
        'success' => false,
        'error' => 'Anda tidak memiliki akses ke halaman ini.'
    ]);
    exit;
}

// ============================================================================
// VALIDASI INPUT
// ============================================================================
$messageId = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;

if ($messageId <= 0) {
    ajaxLog("Invalid message_id: $messageId");
    echo json_encode([
        'success' => false,
        'error' => 'ID pesan tidak valid.'
    ]);
    exit;
}

// ============================================================================
// KONEKSI DATABASE
// ============================================================================
try {
    $db = Database::getInstance()->getConnection();
    ajaxLog("Database connected");
} catch (Exception $e) {
    ajaxLog("Database connection failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Gagal terhubung ke database.'
    ]);
    exit;
}

// ============================================================================
// AMBIL DETAIL PESAN
// ============================================================================
try {
    $sql = "
        SELECT 
            m.*,
            m.is_external,
            CASE 
                WHEN m.is_external = 1 THEN es.nama_lengkap
                ELSE COALESCE(u.nama_lengkap, m.pengirim_nama, 'Unknown')
            END as pengirim_nama_display,
            CASE 
                WHEN m.is_external = 1 THEN es.identitas
                ELSE u.user_type
            END as pengirim_tipe,
            mt.jenis_pesan as message_type,
            mt.responder_type as expected_responder_type,
            -- Informasi responder guru
            mr.id as response_id,
            mr.responder_id as guru_responder_id,
            guru.nama_lengkap as guru_responder_nama,
            guru.user_type as guru_responder_type,
            mr.catatan_respon as guru_response,
            mr.status as guru_response_status,
            mr.created_at as guru_response_date,
            -- Informasi review (untuk Wakepsek)
            wr.id as review_id,
            wr.reviewer_id,
            reviewer.nama_lengkap as reviewer_nama,
            reviewer.user_type as reviewer_type,
            wr.catatan as review_catatan,
            wr.created_at as review_date,
            -- Informasi review Wakepsek (untuk Kepsek)
            wr_wakepsek.id as wakepsek_review_id,
            wakepsek.nama_lengkap as wakepsek_reviewer_nama,
            wr_wakepsek.catatan as wakepsek_review_catatan,
            wr_wakepsek.created_at as wakepsek_review_date,
            -- Informasi review Kepsek (untuk Kepsek)
            wr_kepsek.id as kepsek_review_id,
            wr_kepsek.catatan as kepsek_review_catatan,
            wr_kepsek.created_at as kepsek_review_date,
            -- Hitung jumlah attachment
            (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id) as attachment_count
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN external_senders es ON m.external_sender_id = es.id
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN message_responses mr ON m.id = mr.message_id
        LEFT JOIN users guru ON mr.responder_id = guru.id
        LEFT JOIN wakepsek_reviews wr ON m.id = wr.message_id AND wr.reviewer_id = ?
        LEFT JOIN users reviewer ON wr.reviewer_id = reviewer.id
        LEFT JOIN wakepsek_reviews wr_wakepsek ON m.id = wr_wakepsek.message_id AND wr_wakepsek.reviewer_id IN (SELECT id FROM users WHERE user_type = 'Wakil_Kepala')
        LEFT JOIN users wakepsek ON wr_wakepsek.reviewer_id = wakepsek.id
        LEFT JOIN wakepsek_reviews wr_kepsek ON m.id = wr_kepsek.message_id AND wr_kepsek.reviewer_id IN (SELECT id FROM users WHERE user_type = 'Kepala_Sekolah')
        WHERE m.id = ?
        LIMIT 1
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $messageId]);
    $message = $stmt->fetch();
    
    if (!$message) {
        ajaxLog("Message not found: $messageId");
        echo json_encode([
            'success' => false,
            'error' => 'Pesan tidak ditemukan.'
        ]);
        exit;
    }
    
    ajaxLog("Message found", ['id' => $message['id'], 'status' => $message['status']]);
    
} catch (Exception $e) {
    ajaxLog("Error fetching message: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Gagal mengambil detail pesan.'
    ]);
    exit;
}

// ============================================================================
// AMBIL ATTACHMENTS
// ============================================================================
try {
    $attachSql = "
        SELECT 
            id,
            filename,
            filepath,
            filetype,
            filesize,
            virus_scan_status,
            created_at
        FROM message_attachments 
        WHERE message_id = ? 
        AND is_approved = 1 
        ORDER BY created_at ASC
    ";
    
    $attachStmt = $db->prepare($attachSql);
    $attachStmt->execute([$messageId]);
    $attachments = $attachStmt->fetchAll();
    
    ajaxLog("Attachments found: " . count($attachments));
    
} catch (Exception $e) {
    ajaxLog("Error fetching attachments: " . $e->getMessage());
    $attachments = [];
}

// ============================================================================
// GENERATE HTML UNTUK DETAIL
// ============================================================================
$placeholder_image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f8f9fa"/><text x="50" y="50" font-family="Arial" font-size="12" fill="#adb5bd" text-anchor="middle" dy=".3em">No Image</text></svg>');

ob_start();
?>
<div class="message-detail-container">
    <!-- Message Info -->
    <div class="row mb-4">
        <div class="col-md-6">
            <table class="table table-sm">
                <tr>
                    <th width="120">ID Pesan</th>
                    <td><?php echo htmlspecialchars($message['id']); ?></td>
                </tr>
                <tr>
                    <th>Referensi</th>
                    <td><code><?php echo htmlspecialchars($message['reference_number'] ?? '-'); ?></code></td>
                </tr>
                <tr>
                    <th>Jenis Pesan</th>
                    <td>
                        <span class="badge bg-info">
                            <?php echo htmlspecialchars($message['message_type'] ?? 'N/A'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <?php
                        $status = $message['status'] ?? 'Unknown';
                        $badgeClass = match($status) {
                            'Pending' => 'bg-warning',
                            'Dibaca' => 'bg-info',
                            'Diproses' => 'bg-primary',
                            'Disetujui' => 'bg-success',
                            'Ditolak' => 'bg-danger',
                            'Selesai' => 'bg-secondary',
                            default => 'bg-light text-dark'
                        };
                        ?>
                        <span class="badge <?php echo $badgeClass; ?>">
                            <?php echo $status; ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Tanggal Kirim</th>
                    <td><?php echo date('d M Y H:i', strtotime($message['created_at'])); ?></td>
                </tr>
            </table>
        </div>
        <div class="col-md-6">
            <table class="table table-sm">
                <tr>
                    <th width="120">Pengirim</th>
                    <td>
                        <strong><?php echo htmlspecialchars($message['pengirim_nama_display'] ?? '-'); ?></strong>
                        <?php if ($message['is_external']): ?>
                            <span class="badge bg-warning ms-1">External</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Tipe</th>
                    <td><?php echo htmlspecialchars(str_replace('_', ' ', $message['pengirim_tipe'] ?? '-')); ?></td>
                </tr>
                <?php if (!empty($message['pengirim_email'])): ?>
                <tr>
                    <th>Email</th>
                    <td><?php echo htmlspecialchars($message['pengirim_email']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($message['pengirim_phone'])): ?>
                <tr>
                    <th>No. HP</th>
                    <td><?php echo htmlspecialchars($message['pengirim_phone']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
    
    <!-- Message Content -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0">
                <i class="fas fa-envelope me-2"></i>
                Isi Pesan
            </h6>
        </div>
        <div class="card-body">
            <p class="mb-0" style="white-space: pre-line;"><?php echo nl2br(htmlspecialchars($message['isi_pesan'] ?? '')); ?></p>
        </div>
    </div>
    
    <!-- ========================================================= -->
    <!-- LAMPIRAN GAMBAR -->
    <!-- ========================================================= -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0">
                <i class="fas fa-images me-2"></i>
                Lampiran Gambar 
                <span class="badge <?php echo !empty($attachments) ? 'bg-primary' : 'bg-secondary'; ?> ms-2">
                    <?php echo count($attachments); ?>
                </span>
            </h6>
        </div>
        <div class="card-body">
            <?php if (!empty($attachments)): ?>
                <div class="row g-3">
                    <?php foreach ($attachments as $attachment): ?>
                    <?php 
                        $image_url = BASE_URL . '/' . ltrim($attachment['filepath'], '/');
                        $display_name = $attachment['filename'];
                        
                        // Format file size
                        $size_formatted = '';
                        if ($attachment['filesize']) {
                            $size = $attachment['filesize'];
                            if ($size < 1024) {
                                $size_formatted = $size . ' B';
                            } elseif ($size < 1048576) {
                                $size_formatted = round($size / 1024, 1) . ' KB';
                            } else {
                                $size_formatted = round($size / 1048576, 1) . ' MB';
                            }
                        }
                        
                        $virus_status = $attachment['virus_scan_status'] ?? 'Pending';
                        $status_class = match($virus_status) {
                            'Clean' => 'success',
                            'Pending' => 'warning',
                            'Infected' => 'danger',
                            default => 'secondary'
                        };
                    ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="attachment-item card h-100">
                            <div class="attachment-preview position-relative" 
                                 style="height: 150px; overflow: hidden; cursor: pointer; background: #f8f9fa;"
                                 onclick="previewImage('<?php echo $image_url . '?t=' . time(); ?>', '<?php echo htmlspecialchars($display_name); ?>')">
                                <img src="<?php echo $image_url . '?t=' . time(); ?>" 
                                     alt="<?php echo htmlspecialchars($display_name); ?>"
                                     style="width: 100%; height: 100%; object-fit: cover;"
                                     loading="lazy"
                                     onerror="this.onerror=null; this.src='<?php echo $placeholder_image; ?>'; this.style.objectFit='contain'; this.style.padding='10px';">
                                <div class="attachment-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-dark bg-opacity-25 opacity-0 transition-all">
                                    <i class="fas fa-search-plus text-white fa-2x"></i>
                                </div>
                                <span class="position-absolute top-0 end-0 m-1 badge bg-<?php echo $status_class; ?>" 
                                      title="Virus Scan: <?php echo $virus_status; ?>">
                                    <i class="fas fa-shield-alt"></i>
                                </span>
                            </div>
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-truncate" style="max-width: 100px;">
                                        <small title="<?php echo htmlspecialchars($display_name); ?>">
                                            <?php echo htmlspecialchars(substr($display_name, 0, 15)); ?>
                                            <?php if (strlen($display_name) > 15) echo '...'; ?>
                                        </small>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" 
                                                class="btn btn-outline-primary" 
                                                onclick="previewImage('<?php echo $image_url; ?>', '<?php echo htmlspecialchars($display_name); ?>')"
                                                title="Preview">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="<?php echo $image_url; ?>" 
                                           class="btn btn-outline-success" 
                                           download="<?php echo $display_name; ?>"
                                           title="Download"
                                           target="_blank">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <small class="text-muted"><?php echo $size_formatted; ?></small>
                                    <small class="text-muted">
                                        <i class="far fa-clock"></i>
                                        <?php echo date('d/m/Y', strtotime($attachment['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-3 small text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    File dengan status <span class="badge bg-success">Clean</span> aman.
                    Status <span class="badge bg-warning">Pending</span> dalam proses scan.
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
    
    <!-- Respon Guru -->
    <?php if (!empty($message['guru_response'])): ?>
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0">
                <i class="fas fa-chalkboard-teacher me-2"></i>
                Respon dari Guru Responder
            </h6>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between mb-2">
                <div>
                    <strong><?php echo htmlspecialchars($message['guru_responder_nama'] ?? '-'); ?></strong>
                    <span class="badge bg-info ms-1"><?php echo str_replace('Guru_', '', $message['guru_responder_type'] ?? '-'); ?></span>
                </div>
                <small class="text-muted">
                    <i class="far fa-clock me-1"></i>
                    <?php echo date('d M Y H:i', strtotime($message['guru_response_date'])); ?>
                </small>
            </div>
            <div class="bg-light p-3 rounded" style="white-space: pre-line;">
                <?php echo nl2br(htmlspecialchars($message['guru_response'])); ?>
            </div>
            <div class="mt-2">
                <span class="badge bg-<?php echo $message['guru_response_status'] == 'Disetujui' ? 'success' : 'danger'; ?>">
                    <?php echo $message['guru_response_status']; ?>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Review dari Wakepsek -->
    <?php if (!empty($message['wakepsek_review_id'])): ?>
    <div class="card mb-4 border-info">
        <div class="card-header bg-info bg-opacity-10 text-info">
            <h6 class="mb-0">
                <i class="fas fa-user-tie me-2"></i>
                Review dari Wakil Kepala Sekolah
            </h6>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between mb-2">
                <strong><?php echo htmlspecialchars($message['wakepsek_reviewer_nama'] ?? '-'); ?></strong>
                <small class="text-muted">
                    <i class="far fa-clock me-1"></i>
                    <?php echo date('d M Y H:i', strtotime($message['wakepsek_review_date'])); ?>
                </small>
            </div>
            <div class="bg-info bg-opacity-10 p-3 rounded" style="white-space: pre-line;">
                <?php echo nl2br(htmlspecialchars($message['wakepsek_review_catatan'] ?? '')); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Review dari Kepsek -->
    <?php if (!empty($message['kepsek_review_id'])): ?>
    <div class="card mb-4 border-success">
        <div class="card-header bg-success bg-opacity-10 text-success">
            <h6 class="mb-0">
                <i class="fas fa-crown me-2"></i>
                Review dari Kepala Sekolah
            </h6>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between mb-2">
                <strong>Kepala Sekolah</strong>
                <small class="text-muted">
                    <i class="far fa-clock me-1"></i>
                    <?php echo date('d M Y H:i', strtotime($message['kepsek_review_date'])); ?>
                </small>
            </div>
            <div class="bg-success bg-opacity-10 p-3 rounded" style="white-space: pre-line;">
                <?php echo nl2br(htmlspecialchars($message['kepsek_review_catatan'] ?? '')); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (empty($message['guru_response']) && empty($message['wakepsek_review_id']) && empty($message['kepsek_review_id'])): ?>
    <div class="alert alert-warning">
        <i class="fas fa-info-circle me-2"></i>
        Belum ada respons dari guru atau review dari pimpinan untuk pesan ini.
    </div>
    <?php endif; ?>
</div>

<style>
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
}
.attachment-preview img {
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
</style>

<?php
$html = ob_get_clean();

// ============================================================================
// KIRIM RESPONSE JSON
// ============================================================================
$response = [
    'success' => true,
    'html' => $html,
    'has_response' => !empty($message['guru_response']),
    'attachment_count' => count($attachments),
    'message' => [
        'id' => $message['id'],
        'status' => $message['status']
    ]
];

ajaxLog("Response sent successfully");
echo json_encode($response);

ajaxLog("========== GET_MESSAGE_DETAIL END ==========");
?>