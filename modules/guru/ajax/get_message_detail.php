<?php
/**
 * AJAX Handler untuk Mendapatkan Detail Pesan
 * File: modules/guru/ajax/get_message_detail.php
 * 
 * REVISI: Memperbaiki tampilan thumbnail gambar di modal Detail Pesan
 * - Menambahkan data attachments ke response JSON
 * - Memastikan HTML thumbnail digenerate dengan benar
 * - Menambahkan timestamp pada URL gambar untuk mencegah cache
 * - Menambahkan logging untuk debugging
 */

require_once '../../../config/config.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/functions.php';

header('Content-Type: application/json');

// Enable error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error ke output
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . '/logs/ajax_errors.log');

// Buat direktori logs jika belum ada
$logDir = ROOT_PATH . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

function ajaxLog($message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        $log .= " - " . print_r($data, true);
    }
    $log .= "\n";
    error_log($log);
}

ajaxLog("========== GET_MESSAGE_DETAIL START ==========");
ajaxLog("Request received", $_GET);

try {
    // Cek authentication
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Session tidak ditemukan. Silakan login ulang.');
    }
    
    Auth::checkAuth();
    ajaxLog("Auth check passed");
    
    $user_id = $_SESSION['user_id'];
    $user_type = $_SESSION['user_type'];
    
    // Hanya menerima AJAX request
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        throw new Exception('Invalid request - not AJAX');
    }
    
    // Validasi message_id
    $messageId = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;
    if (!$messageId) {
        throw new Exception('ID pesan tidak valid');
    }
    ajaxLog("Processing message_id: " . $messageId);
    
    // Koneksi database
    $db = Database::getInstance()->getConnection();
    if (!$db) {
        throw new Exception('Gagal koneksi database');
    }
    ajaxLog("Database connected");
    
    // Define BASE_URL if not defined
    if (!defined('BASE_URL')) {
        define('BASE_URL', $GLOBALS['BASE_URL'] ?? '/smasys');
    }
    
    // Placeholder image (gunakan base64 yang valid)
    $placeholder_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f8f9fa"/><text x="50" y="50" font-family="Arial" font-size="12" fill="#adb5bd" text-anchor="middle" dy=".3em">No Image</text></svg>';
    $placeholder_image = 'data:image/svg+xml;base64,' . base64_encode($placeholder_svg);
    
    // Ambil detail pesan dengan semua informasi terkait
    $sql = "
        SELECT 
            m.*,
            m.is_external,
            CASE 
                WHEN m.is_external = 1 THEN 
                    CONCAT('EXT-', 
                        CASE 
                            WHEN es.phone_number IS NOT NULL AND es.phone_number != '' THEN es.phone_number
                            WHEN es.email IS NOT NULL AND es.email != '' THEN SUBSTRING_INDEX(es.email, '@', 1)
                            ELSE CONCAT('ID', es.id)
                        END
                    )
                ELSE 
                    COALESCE(u.username, CONCAT('USR-', u.id))
            END as nomor_identitas,
            CASE 
                WHEN m.is_external = 1 THEN es.nama_lengkap
                ELSE COALESCE(u.nama_lengkap, m.pengirim_nama, 'Unknown')
            END as pengirim_nama_display,
            CASE 
                WHEN m.is_external = 1 THEN 
                    COALESCE(es.identitas, 'External')
                ELSE 
                    COALESCE(u.user_type, 'Internal')
            END as pengirim_tipe,
            CASE 
                WHEN m.is_external = 1 THEN es.email
                ELSE u.email
            END as pengirim_email,
            CASE 
                WHEN m.is_external = 1 THEN es.phone_number
                ELSE u.phone_number
            END as pengirim_phone,
            u.kelas,
            u.jurusan,
            mt.id as message_type_id,
            mt.jenis_pesan,
            mt.responder_type,
            mt.response_deadline_hours,
            TIMESTAMPDIFF(HOUR, m.created_at, NOW()) as hours_since_created,
            GREATEST(0, mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) as hours_remaining,
            CASE 
                WHEN TIMESTAMPDIFF(HOUR, m.created_at, NOW()) >= mt.response_deadline_hours THEN 'danger'
                WHEN (mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) <= 24 THEN 'warning'
                ELSE 'success'
            END as urgency_color,
            CASE 
                WHEN mr.id IS NOT NULL THEN 1 
                ELSE 0 
            END as has_response,
            mr.catatan_respon as last_response,
            mr.status as response_status,
            ru.nama_lengkap as responder_name,
            mr.created_at as tanggal_respon,
            wr.id as review_id,
            wr.reviewer_id,
            reviewer.nama_lengkap as reviewer_nama,
            reviewer.user_type as reviewer_type,
            wr.catatan as review_catatan,
            wr.created_at as review_date,
            (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id) as attachment_count
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN external_senders es ON m.external_sender_id = es.id
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN message_responses mr ON m.id = mr.message_id 
            AND mr.created_at = (SELECT MAX(created_at) FROM message_responses WHERE message_id = m.id)
        LEFT JOIN users ru ON mr.responder_id = ru.id
        LEFT JOIN wakepsek_reviews wr ON m.id = wr.message_id
        LEFT JOIN users reviewer ON wr.reviewer_id = reviewer.id
        WHERE m.id = ?
    ";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Gagal prepare statement: ' . implode(', ', $db->errorInfo()));
    }
    
    $result = $stmt->execute([$messageId]);
    if (!$result) {
        throw new Exception('Gagal execute query: ' . implode(', ', $stmt->errorInfo()));
    }
    
    $message = $stmt->fetch();
    if (!$message) {
        throw new Exception('Pesan tidak ditemukan dengan ID: ' . $messageId);
    }
    ajaxLog("Message found", ['id' => $message['id'], 'status' => $message['status']]);
    
    // Ambil attachments
    $attachStmt = $db->prepare("
        SELECT 
            id,
            message_id,
            user_id,
            filename,
            filepath,
            filetype,
            filesize,
            is_approved,
            virus_scan_status,
            download_count,
            created_at
        FROM message_attachments 
        WHERE message_id = ? 
        AND is_approved = 1 
        AND virus_scan_status IN ('Clean', 'Pending')
        ORDER BY created_at ASC
    ");
    
    $attachStmt->execute([$messageId]);
    $attachments = $attachStmt->fetchAll();
    ajaxLog("Attachments found", ['count' => count($attachments)]);
    
    // Ambil responses
    $respStmt = $db->prepare("
        SELECT mr.*, u.nama_lengkap as responder_nama, u.user_type as responder_type
        FROM message_responses mr
        LEFT JOIN users u ON mr.responder_id = u.id
        WHERE mr.message_id = ?
        ORDER BY mr.created_at DESC
    ");
    $respStmt->execute([$messageId]);
    $responses = $respStmt->fetchAll();
    ajaxLog("Responses found", ['count' => count($responses)]);
    
    // Ambil reviews
    $reviewStmt = $db->prepare("
        SELECT wr.*, u.nama_lengkap as reviewer_nama, u.user_type as reviewer_type
        FROM wakepsek_reviews wr
        LEFT JOIN users u ON wr.reviewer_id = u.id
        WHERE wr.message_id = ?
        ORDER BY wr.created_at DESC
    ");
    $reviewStmt->execute([$messageId]);
    $reviews = $reviewStmt->fetchAll();
    ajaxLog("Reviews found", ['count' => count($reviews)]);
    
    // =========================================================
    // GENERATE HTML dengan thumbnail gambar
    // =========================================================
    ob_start();
    ?>
    
    <div class="message-detail-container">
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
                        <th>Prioritas</th>
                        <td>
                            <?php
                            $priority = $message['priority'] ?? 'Normal';
                            $priorityClass = match($priority) {
                                'Low' => 'bg-success',
                                'Medium' => 'bg-warning',
                                'High' => 'bg-danger',
                                'Urgent' => 'bg-dark',
                                default => 'bg-secondary'
                            };
                            ?>
                            <span class="badge <?php echo $priorityClass; ?>">
                                <?php echo $priority; ?>
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
                            <?php echo htmlspecialchars($message['pengirim_nama_display'] ?? '-'); ?>
                            <?php if ($message['is_external']): ?>
                                <span class="badge bg-warning ms-1">External</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Identitas</th>
                        <td><?php echo htmlspecialchars($message['nomor_identitas'] ?? '-'); ?></td>
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
        
        <!-- Deadline Info -->
        <?php if (isset($message['hours_remaining'])): ?>
        <div class="alert alert-<?php 
            echo $message['hours_remaining'] <= 0 ? 'danger' : 
                ($message['hours_remaining'] <= 24 ? 'warning' : 'info'); 
        ?> mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-clock me-2"></i>
                    <strong>Deadline Respon:</strong> 
                    <?php echo $message['response_deadline_hours'] ?? 72; ?> jam
                </span>
                <span>
                    <?php if ($message['hours_remaining'] <= 0): ?>
                        <span class="badge bg-danger">Expired</span>
                    <?php else: ?>
                        <span class="badge bg-<?php echo $message['hours_remaining'] <= 24 ? 'warning' : 'success'; ?>">
                            Sisa <?php echo floor($message['hours_remaining']); ?> jam
                        </span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
        
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
        <!-- ATTACHMENTS SECTION - Menampilkan thumbnail gambar -->
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
                            // Buat URL gambar dari filepath
                            $filepath = $attachment['filepath'];
                            // Pastikan path tidak dimulai dengan slash jika BASE_URL sudah memiliki slash
                            $image_url = BASE_URL . '/' . ltrim($filepath, '/');
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
                            
                            // Status virus scan
                            $virus_status = $attachment['virus_scan_status'] ?? 'Pending';
                            $status_class = match($virus_status) {
                                'Clean' => 'success',
                                'Pending' => 'warning',
                                'Infected' => 'danger',
                                'Error' => 'secondary',
                                default => 'secondary'
                            };
                            
                            $download_count = $attachment['download_count'] ?? 0;
                        ?>
                        <div class="col-md-4 col-sm-6">
                            <div class="attachment-item card h-100">
                                <div class="attachment-preview position-relative" 
                                     style="height: 150px; overflow: hidden; cursor: pointer; background: #f8f9fa;"
                                     onclick="previewImage('<?php echo $image_url; ?>', '<?php echo htmlspecialchars($display_name); ?>')">
                                    <img src="<?php echo $image_url . '?t=' . time(); ?>" 
                                         alt="<?php echo htmlspecialchars($display_name); ?>"
                                         style="width: 100%; height: 100%; object-fit: cover;"
                                         loading="lazy"
                                         onerror="this.onerror=null; this.src='<?php echo $placeholder_image; ?>'; this.style.objectFit='contain'; this.style.padding='10px';">
                                    <div class="attachment-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-dark bg-opacity-25 opacity-0 transition-all">
                                        <i class="fas fa-search-plus text-white fa-2x"></i>
                                    </div>
                                    
                                    <!-- Status Badge -->
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
                                               title="Download (<?php echo $download_count; ?>x)"
                                               target="_blank">
                                                <i class="fas fa-download"></i>
                                                <?php if ($download_count > 0): ?>
                                                <span class="badge bg-light text-dark ms-1"><?php echo $download_count; ?></span>
                                                <?php endif; ?>
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
                    
                    <!-- Keterangan Status -->
                    <div class="mt-3 small text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        File dengan status <span class="badge bg-success">Clean</span> aman untuk diunduh.
                        Status <span class="badge bg-warning">Pending</span> masih dalam proses scan.
                    </div>
                    
                <?php else: ?>
                    <!-- Tampilan placeholder ketika tidak ada lampiran -->
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
        
        <!-- Reviews Section -->
        <?php if (!empty($reviews)): ?>
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0">
                    <i class="fas fa-clipboard-check me-2"></i>
                    Review dari Pimpinan (<?php echo count($reviews); ?>)
                </h6>
            </div>
            <div class="card-body">
                <?php foreach ($reviews as $review): ?>
                <div class="border-start border-3 border-<?php echo $review['reviewer_type'] == 'Kepala_Sekolah' ? 'primary' : 'info'; ?> ps-3 mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <strong>
                            <i class="fas fa-<?php echo $review['reviewer_type'] == 'Kepala_Sekolah' ? 'crown' : 'user-tie'; ?> me-1"></i>
                            <?php echo htmlspecialchars($review['reviewer_nama'] ?? 'Unknown'); ?>
                            <span class="badge bg-<?php echo $review['reviewer_type'] == 'Kepala_Sekolah' ? 'primary' : 'info'; ?> ms-1">
                                <?php echo $review['reviewer_type']; ?>
                            </span>
                        </strong>
                        <small class="text-muted"><?php echo date('d M Y H:i', strtotime($review['created_at'])); ?></small>
                    </div>
                    <p class="mb-1" style="white-space: pre-line;"><?php echo nl2br(htmlspecialchars($review['catatan'] ?? '')); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Responses Section -->
        <?php if (!empty($responses)): ?>
        <div class="card">
            <div class="card-header bg-light">
                <h6 class="mb-0">
                    <i class="fas fa-comment-dots me-2"></i>
                    Riwayat Respon (<?php echo count($responses); ?>)
                </h6>
            </div>
            <div class="card-body">
                <?php foreach ($responses as $response): ?>
                <div class="border-start border-3 border-primary ps-3 mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <strong><?php echo htmlspecialchars($response['responder_nama'] ?? 'Sistem'); ?></strong>
                        <small class="text-muted"><?php echo date('d M Y H:i', strtotime($response['created_at'])); ?></small>
                    </div>
                    <p class="mb-1" style="white-space: pre-line;"><?php echo nl2br(htmlspecialchars($response['catatan_respon'] ?? '')); ?></p>
                    <span class="badge bg-<?php 
                        switch($response['status'] ?? '') {
                            case 'Disetujui': echo 'success'; break;
                            case 'Ditolak': echo 'danger'; break;
                            case 'Diproses': echo 'info'; break;
                            default: echo 'secondary';
                        }
                    ?>">
                        <?php echo htmlspecialchars($response['status'] ?? 'Unknown'); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
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
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
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
    .table th {
        font-weight: 600;
        color: #495057;
    }
    /* Pastikan gambar thumbnail muncul */
    .attachment-preview img[src*="data:image"] {
        object-fit: contain !important;
        padding: 10px !important;
    }
    </style>
    
    <?php
    $html = ob_get_clean();
    
    if (empty($html)) {
        throw new Exception('Gagal generate HTML');
    }
    
    ajaxLog("HTML generated successfully", ['length' => strlen($html)]);
    
    // Tambahkan attachments ke message array
    $message['attachments'] = $attachments;
    
    // Return success response
    echo json_encode([
        'success' => true,
        'html' => $html,
        'message' => $message,
        'has_response' => !empty($responses),
        'has_review' => !empty($reviews),
        'attachment_count' => count($attachments),
        'review_count' => count($reviews),
        'response_count' => count($responses)
    ]);
    
    ajaxLog("Response sent successfully", [
        'attachment_count' => count($attachments),
        'has_response' => !empty($responses),
        'has_review' => !empty($reviews)
    ]);
    
} catch (Exception $e) {
    ajaxLog("ERROR: " . $e->getMessage());
    ajaxLog("Stack trace: " . $e->getTraceAsString());
    
    // Return error response
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'message_id' => $messageId ?? 0,
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'time' => date('Y-m-d H:i:s')
        ]
    ]);
}

ajaxLog("========== GET_MESSAGE_DETAIL END ==========\n");
?>