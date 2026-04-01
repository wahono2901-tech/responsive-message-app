<?php
/**
 * AJAX Handler untuk Mendapatkan Detail Pesan
 * File: modules/user/ajax/get_message_detail.php
 * 
 * VERSI: 2.0 - Terintegrasi dengan view_messages.php
 * - Mendukung struktur tabel message_attachments yang lengkap
 * - Menampilkan thumbnail gambar langsung di modal detail
 * - Preview gambar dengan modal terpisah (z-index tinggi)
 */

require_once '../../../config/config.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Enable error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
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
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    error_log($log);
}

ajaxLog("========== GET MESSAGE DETAIL START ==========");
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
    $user_nama = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'User';
    
    // Validasi message_id
    $message_id = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;
    if (!$message_id) {
        throw new Exception('ID pesan tidak valid');
    }
    ajaxLog("Processing message_id: " . $message_id);
    
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
    
    // Placeholder image
    $placeholder_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f8f9fa"/><text x="50" y="50" font-family="Arial" font-size="12" fill="#adb5bd" text-anchor="middle" dy=".3em">No Image</text></svg>';
    $placeholder_image = 'data:image/svg+xml;base64,' . base64_encode($placeholder_svg);
    
    // Get message details with pengirim information
    $sql = "
        SELECT 
            m.*,
            m.reference_number,
            mt.id as message_type_id,
            mt.jenis_pesan,
            mt.responder_type,
            mt.response_deadline_hours,
            -- Responder information
            u.id as responder_id,
            u.nama_lengkap as responder_name,
            u.user_type as responder_type,
            u.avatar as responder_avatar,
            -- Response information
            mr.id as response_id,
            mr.catatan_respon as last_response,
            mr.created_at as last_response_date,
            mr.status as response_status,
            -- Pengirim information
            pu.id as pengirim_id,
            pu.nama_lengkap as pengirim_nama_display,
            pu.user_type as pengirim_tipe,
            pu.email as pengirim_email,
            pu.phone_number as pengirim_phone,
            pu.kelas,
            pu.jurusan,
            pu.avatar as pengirim_avatar,
            -- Time calculations
            TIMESTAMPDIFF(HOUR, m.created_at, NOW()) as hours_since_created,
            GREATEST(0, COALESCE(mt.response_deadline_hours, 72) - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) as hours_remaining,
            CASE 
                WHEN TIMESTAMPDIFF(HOUR, m.created_at, NOW()) >= COALESCE(mt.response_deadline_hours, 72) THEN 'danger'
                WHEN (COALESCE(mt.response_deadline_hours, 72) - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) <= 24 THEN 'warning'
                ELSE 'success'
            END as urgency_color,
            -- Check if has response
            CASE WHEN mr.id IS NOT NULL THEN 1 ELSE 0 END as has_response
        FROM messages m
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users u ON m.responder_id = u.id
        LEFT JOIN message_responses mr ON m.id = mr.message_id 
            AND mr.created_at = (
                SELECT MAX(created_at) 
                FROM message_responses 
                WHERE message_id = m.id
            )
        LEFT JOIN users pu ON m.pengirim_id = pu.id
        WHERE m.id = ? AND m.pengirim_id = ?
    ";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Gagal prepare statement: ' . implode(', ', $db->errorInfo()));
    }
    
    $result = $stmt->execute([$message_id, $user_id]);
    if (!$result) {
        throw new Exception('Gagal execute query: ' . implode(', ', $stmt->errorInfo()));
    }
    
    $message = $stmt->fetch();
    if (!$message) {
        throw new Exception('Pesan tidak ditemukan dengan ID: ' . $message_id);
    }
    ajaxLog("Message found", [
        'id' => $message['id'],
        'status' => $message['status'],
        'jenis_pesan' => $message['jenis_pesan']
    ]);
    
    // Get attachments sesuai struktur tabel message_attachments
    $attach_sql = "
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
    ";
    
    $attach_stmt = $db->prepare($attach_sql);
    $attach_stmt->execute([$message_id]);
    $attachments = $attach_stmt->fetchAll();
    ajaxLog("Attachments found", [
        'count' => count($attachments),
        'attachments' => $attachments
    ]);
    
    // Format tanggal
    $formatDate = function($dateString) {
        if (!$dateString) return '-';
        $date = new DateTime($dateString);
        return $date->format('d M Y H:i:s');
    };
    
    // Format file size
    $formatFileSize = function($bytes) {
        if (!$bytes) return '-';
        $bytes = intval($bytes);
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 1) . ' GB';
    };
    
    // Tentukan class untuk status
    $statusClass = match($message['status']) {
        'Disetujui' => 'success',
        'Ditolak' => 'danger',
        'Pending' => 'warning',
        'Dibaca' => 'info',
        'Diproses' => 'primary',
        'Selesai' => 'secondary',
        default => 'secondary'
    };
    
    $priorityClass = match($message['priority']) {
        'High' => 'danger',
        'Medium' => 'warning',
        'Low' => 'success',
        'Urgent' => 'dark',
        default => 'secondary'
    };
    
    $responseStatusClass = match($message['response_status']) {
        'Disetujui' => 'success',
        'Ditolak' => 'danger',
        'Pending' => 'warning',
        default => 'secondary'
    };
    
    // Buat HTML response dengan tampilan profesional
    ob_start();
    ?>
    
    <!-- INFORMASI UTAMA DENGAN BACKGROUND GELAP DAN TEKS PUTIH -->
    <div class="info-box" style="background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%); border-radius: 16px; padding: 1.5rem; margin-bottom: 1.5rem;">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="info-label" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Jenis Pesan</div>
                <div class="info-value" style="color: white; font-size: 1rem; font-weight: 500;"><?php echo htmlspecialchars($message['jenis_pesan'] ?? '-'); ?></div>
            </div>
            <div class="col-md-3">
                <div class="info-label" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Status</div>
                <div class="info-value">
                    <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($message['status'] ?? 'Pending'); ?></span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-label" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Prioritas</div>
                <div class="info-value">
                    <span class="badge bg-<?php echo $priorityClass; ?>"><?php echo htmlspecialchars($message['priority'] ?? 'Normal'); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-label" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Tanggal Kirim</div>
                <div class="info-value" style="color: white;"><?php echo $formatDate($message['created_at']); ?></div>
            </div>
            <div class="col-md-6">
                <div class="info-label" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Sisa Waktu</div>
                <div class="info-value">
                    <span class="badge bg-<?php echo $message['urgency_color'] ?? 'secondary'; ?>">
                        <?php echo $message['hours_remaining'] ? floor($message['hours_remaining']) : 0; ?> jam
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- INFORMASI REFERENSI -->
    <div class="detail-card" style="background: white; border-radius: 20px; padding: 1.8rem; margin-bottom: 1.8rem; box-shadow: 0 8px 20px rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.03);">
        <div class="detail-card-header" style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid #f0f4f9;">
            <i class="fas fa-hashtag text-primary" style="font-size: 1.5rem; margin-right: 1rem;"></i>
            <h5 class="text-primary" style="margin: 0; font-weight: 700; font-size: 1.1rem; text-transform: uppercase;">Informasi Referensi</h5>
        </div>
        
        <div class="detail-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem;">
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem; text-transform: uppercase;">Reference Number</span>
                <span class="detail-value" style="font-size: 1rem; font-weight: 500; color: #1e293b;">
                    <code style="background: #eef2f6; padding: 0.2rem 0.6rem; border-radius: 6px; color: #0d6efd;">
                        <?php echo htmlspecialchars($message['reference_number'] ?? 'REF-' . str_pad($message['id'], 6, '0', STR_PAD_LEFT)); ?>
                    </code>
                </span>
            </div>
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem; text-transform: uppercase;">ID Pesan</span>
                <span class="detail-value" style="font-size: 1rem; font-weight: 500; color: #1e293b;">
                    <code style="background: #eef2f6; padding: 0.2rem 0.6rem; border-radius: 6px; color: #0d6efd;"><?php echo $message['id']; ?></code>
                </span>
            </div>
        </div>
    </div>
    
    <!-- INFORMASI PENGIRIM -->
    <div class="detail-card" style="background: white; border-radius: 20px; padding: 1.8rem; margin-bottom: 1.8rem; box-shadow: 0 8px 20px rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.03);">
        <div class="detail-card-header" style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid #f0f4f9;">
            <i class="fas fa-user-circle text-primary" style="font-size: 1.5rem; margin-right: 1rem;"></i>
            <h5 class="text-primary" style="margin: 0; font-weight: 700; font-size: 1.1rem; text-transform: uppercase;">Informasi Pengirim</h5>
        </div>
        
        <div class="detail-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem;">
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem; text-transform: uppercase;">Nama</span>
                <span class="detail-value" style="font-size: 1rem; font-weight: 500; color: #1e293b;">
                    <div class="d-flex align-items-center">
                        <?php if (!empty($message['pengirim_avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($message['pengirim_avatar']); ?>" class="rounded-circle me-2" width="24" height="24">
                        <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($message['pengirim_nama_display'] ?? $user_nama); ?>&background=random" class="rounded-circle me-2" width="24" height="24">
                        <?php endif; ?>
                        <?php echo htmlspecialchars($message['pengirim_nama_display'] ?? $user_nama); ?>
                    </div>
                </span>
            </div>
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem; text-transform: uppercase;">Tipe Pengirim</span>
                <span class="detail-value" style="font-size: 1rem; font-weight: 500; color: #1e293b;">
                    <?php echo str_replace('_', ' ', htmlspecialchars($message['pengirim_tipe'] ?? $user_type)); ?>
                </span>
            </div>
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem; text-transform: uppercase;">Email</span>
                <span class="detail-value" style="font-size: 1rem; font-weight: 500; color: #1e293b;">
                    <?php echo htmlspecialchars($message['pengirim_email'] ?? '-'); ?>
                </span>
            </div>
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem; text-transform: uppercase;">No. HP</span>
                <span class="detail-value" style="font-size: 1rem; font-weight: 500; color: #1e293b;">
                    <?php echo htmlspecialchars($message['pengirim_phone'] ?? '-'); ?>
                </span>
            </div>
            <?php if (!empty($message['kelas']) || !empty($message['jurusan'])): ?>
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem; text-transform: uppercase;">Kelas/Jurusan</span>
                <span class="detail-value" style="font-size: 1rem; font-weight: 500; color: #1e293b;">
                    <?php echo htmlspecialchars($message['kelas'] ?? ''); ?> <?php echo htmlspecialchars($message['jurusan'] ?? ''); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ISI PESAN -->
    <div class="detail-card" style="background: white; border-radius: 20px; padding: 1.8rem; margin-bottom: 1.8rem; box-shadow: 0 8px 20px rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.03);">
        <div class="detail-card-header" style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid #f0f4f9;">
            <i class="fas fa-envelope text-primary" style="font-size: 1.5rem; margin-right: 1rem;"></i>
            <h5 class="text-primary" style="margin: 0; font-weight: 700; font-size: 1.1rem; text-transform: uppercase;">Isi Pesan</h5>
        </div>
        
        <div class="detail-content-box primary" style="background: #e7f1ff; border-radius: 16px; padding: 1.5rem; line-height: 1.7; max-height: 300px; overflow-y: auto; font-size: 0.95rem; border-left: 4px solid #0d6efd;">
            <?php echo nl2br(htmlspecialchars($message['isi_pesan'] ?? 'Tidak ada isi pesan')); ?>
        </div>
    </div>
    
    <?php if (!empty($attachments)): ?>
    <!-- LAMPIRAN GAMBAR - MENGGUNAKAN DATA DARI TABEL MESSAGE_ATTACHMENTS -->
    <div class="detail-card" style="background: white; border-radius: 20px; padding: 1.8rem; margin-bottom: 1.8rem; box-shadow: 0 8px 20px rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.03);">
        <div class="detail-card-header" style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid #f0f4f9;">
            <i class="fas fa-images text-primary" style="font-size: 1.5rem; margin-right: 1rem;"></i>
            <h5 class="text-primary" style="margin: 0; font-weight: 700; font-size: 1.1rem; text-transform: uppercase;">
                Lampiran Gambar
                <span class="badge bg-primary ms-2"><?php echo count($attachments); ?></span>
            </h5>
        </div>
        
        <div class="detail-attachment-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1.2rem; margin-top: 1rem;">
            <?php foreach ($attachments as $att): 
                $imageUrl = BASE_URL . '/' . ltrim($att['filepath'], '/');
                $fileName = htmlspecialchars($att['filename'] ?? 'image.jpg');
                $fileSize = $formatFileSize($att['filesize']);
                $uploadDate = $formatDate($att['created_at']);
                
                // Status virus scan
                $virusStatus = $att['virus_scan_status'] ?? 'Pending';
                $statusBadge = '';
                $statusClass = '';
                
                switch($virusStatus) {
                    case 'Clean':
                        $statusBadge = '<span class="badge bg-success ms-1" title="Aman">✓</span>';
                        $statusClass = 'border-success';
                        break;
                    case 'Pending':
                        $statusBadge = '<span class="badge bg-warning ms-1" title="Dalam proses scan">⏳</span>';
                        $statusClass = 'border-warning';
                        break;
                    case 'Infected':
                        $statusBadge = '<span class="badge bg-danger ms-1" title="Terinfeksi virus">⚠</span>';
                        $statusClass = 'border-danger';
                        break;
                    default:
                        $statusBadge = '<span class="badge bg-secondary ms-1" title="Status tidak diketahui">?</span>';
                        $statusClass = 'border-secondary';
                }
            ?>
            <div class="detail-attachment-item <?php echo $statusClass; ?>" style="background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid rgba(0,0,0,0.05); transition: all 0.3s ease;">
                <div class="detail-attachment-preview" style="height: 160px; background: #f8fafc; position: relative; cursor: pointer; overflow: hidden;" 
                     onclick="previewImage('<?php echo $imageUrl; ?>', '<?php echo addslashes($fileName); ?>')">
                    <img src="<?php echo $imageUrl; ?>?t=<?php echo time(); ?>" 
                         alt="<?php echo $fileName; ?>"
                         style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s;"
                         loading="lazy"
                         onerror="this.onerror=null; this.src='<?php echo $placeholder_image; ?>'; this.style.objectFit='contain'; this.style.padding='10px';">
                    <div class="overlay" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(13, 110, 253, 0.8); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s;">
                        <i class="fas fa-search-plus" style="color: white; font-size: 2rem;"></i>
                    </div>
                </div>
                <div class="detail-attachment-info" style="padding: 1rem;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="detail-attachment-name" style="font-size: 0.9rem; font-weight: 600; margin-bottom: 0.4rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;" 
                             title="<?php echo $fileName; ?>">
                            <?php echo strlen($fileName) > 20 ? substr($fileName, 0, 20) . '...' : $fileName; ?>
                            <?php echo $statusBadge; ?>
                        </div>
                    </div>
                    <div class="detail-attachment-meta" style="display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; color: #6c757d; flex-wrap: wrap;">
                        <span><?php echo $fileSize; ?></span>
                        <div>
                            <a href="<?php echo $imageUrl; ?>" download="<?php echo $fileName; ?>" title="Download" style="color: #0d6efd; text-decoration: none; padding: 0.2rem 0.6rem; border-radius: 20px; background: #e7f1ff; transition: all 0.2s; margin-right: 0.5rem;" onclick="event.stopPropagation()">
                                <i class="fas fa-download"></i>
                            </a>
                            <small class="text-muted">
                                <i class="far fa-clock"></i> <?php echo date('d M Y', strtotime($att['created_at'])); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($message['has_response']): ?>
    <!-- RESPON GURU -->
    <div class="detail-card" style="background: white; border-radius: 20px; padding: 1.8rem; margin-bottom: 1.8rem; box-shadow: 0 8px 20px rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.03);">
        <div class="detail-card-header" style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid #f0f4f9;">
            <i class="fas fa-chalkboard-teacher text-success" style="font-size: 1.5rem; margin-right: 1rem;"></i>
            <h5 class="text-success" style="margin: 0; font-weight: 700; font-size: 1.1rem; text-transform: uppercase;">Respon Guru</h5>
        </div>
        
        <div class="detail-grid-3" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem;">
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem;">Guru Responder</span>
                <span class="detail-value" style="font-size: 1rem; font-weight: 500; color: #1e293b;">
                    <div class="d-flex align-items-center">
                        <?php if (!empty($message['responder_avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($message['responder_avatar']); ?>" class="rounded-circle me-2" width="24" height="24">
                        <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($message['responder_name'] ?? 'Responder'); ?>&background=random" class="rounded-circle me-2" width="24" height="24">
                        <?php endif; ?>
                        <?php echo htmlspecialchars($message['responder_name'] ?? 'Responder'); ?>
                    </div>
                </span>
            </div>
            
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem;">Status Respon</span>
                <span class="detail-value">
                    <span class="badge bg-<?php echo $responseStatusClass; ?>">
                        <?php echo htmlspecialchars($message['response_status'] ?? '-'); ?>
                    </span>
                </span>
            </div>
            
            <div class="detail-item" style="display: flex; flex-direction: column;">
                <span class="detail-label" style="font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem;">Waktu Respon</span>
                <span class="detail-value" style="font-size: 1rem; font-weight: 500; color: #1e293b;">
                    <i class="far fa-calendar-alt text-success me-1"></i>
                    <?php echo $formatDate($message['last_response_date']); ?>
                </span>
            </div>
        </div>
        
        <div class="mt-4">
            <span class="detail-label mb-2 d-block" style="font-size: 0.8rem; font-weight: 600; color: #6c757d;">Catatan Respon</span>
            <div class="detail-content-box success" style="background: #d1e7dd; border-radius: 16px; padding: 1.5rem; line-height: 1.7; max-height: 200px; overflow-y: auto; font-size: 0.95rem; border-left: 4px solid #198754;">
                <?php echo nl2br(htmlspecialchars($message['last_response'])); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php
    $html = ob_get_clean();
    
    // Siapkan data message untuk dikirim
    $messageData = [
        'id' => $message['id'],
        'reference_number' => $message['reference_number'],
        'jenis_pesan' => $message['jenis_pesan'],
        'status' => $message['status'],
        'priority' => $message['priority'],
        'created_at' => $message['created_at'],
        'isi_pesan' => $message['isi_pesan'],
        'hours_remaining' => $message['hours_remaining'],
        'urgency_color' => $message['urgency_color'],
        'pengirim_nama_display' => $message['pengirim_nama_display'] ?? $user_nama,
        'pengirim_tipe' => $message['pengirim_tipe'] ?? $user_type,
        'pengirim_email' => $message['pengirim_email'],
        'pengirim_phone' => $message['pengirim_phone'],
        'kelas' => $message['kelas'],
        'jurusan' => $message['jurusan'],
        'has_response' => $message['has_response'] ? true : false,
        'last_response' => $message['last_response'],
        'response_status' => $message['response_status'],
        'tanggal_respon' => $message['last_response_date'],
        'responder_name' => $message['responder_name'],
        'responder_avatar' => $message['responder_avatar'],
        'attachments' => $attachments,
        'attachment_count' => count($attachments)
    ];
    
    // Return success response
    echo json_encode([
        'success' => true,
        'html' => $html,
        'message' => $messageData,
        'has_response' => $message['has_response'] ? true : false,
        'attachment_count' => count($attachments)
    ]);
    
    ajaxLog("Response sent successfully");
    
} catch (Exception $e) {
    ajaxLog("ERROR: " . $e->getMessage());
    ajaxLog("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'message_id' => $message_id ?? 0,
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'time' => date('Y-m-d H:i:s')
        ]
    ]);
}

ajaxLog("========== GET MESSAGE DETAIL END ==========\n");
?>