<?php
/**
 * Template untuk Detail Pesan
 * File: modules/guru/ajax/templates/message_detail_template.php
 */
if (!isset($message) || !isset($attachments) || !isset($responses) || !isset($reviews)) {
    throw new Exception('Template variables not set');
}
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
    
    <!-- ATTACHMENTS SECTION -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0">
                <i class="fas fa-images me-2"></i>
                Lampiran 
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
                        // Build image URL from filepath
                        $filepath = $attachment['filepath'];
                        $image_url = BASE_URL . '/' . ltrim($filepath, '/');
                        
                        $display_name = $attachment['filename'];
                        $filetype = $attachment['filetype'] ?? '';
                        $is_image = strpos($filetype, 'image/') === 0;
                        
                        // Virus scan status
                        $virus_status = $attachment['virus_scan_status'] ?? 'Pending';
                        $status_class = match($virus_status) {
                            'Clean' => 'success',
                            'Pending' => 'warning',
                            'Infected' => 'danger',
                            default => 'secondary'
                        };
                        
                        $download_count = $attachment['download_count'] ?? 0;
                        
                        // Format file size
                        $size = $attachment['filesize'] ?? 0;
                        if ($size > 0) {
                            if ($size < 1024) {
                                $size_formatted = $size . ' B';
                            } elseif ($size < 1048576) {
                                $size_formatted = round($size / 1024, 1) . ' KB';
                            } else {
                                $size_formatted = round($size / 1048576, 1) . ' MB';
                            }
                        } else {
                            $size_formatted = 'Unknown';
                        }
                    ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="attachment-item card h-100">
                            <div class="attachment-preview position-relative" 
                                 style="height: 150px; overflow: hidden; cursor: pointer; background: #f8f9fa;">
                                
                                <?php if ($is_image): ?>
                                    <img src="<?php echo $image_url; ?>?t=<?php echo time(); ?>" 
                                         alt="<?php echo htmlspecialchars($display_name); ?>"
                                         style="width: 100%; height: 100%; object-fit: cover;"
                                         loading="lazy"
                                         onclick="previewImage('<?php echo $image_url; ?>', '<?php echo htmlspecialchars($display_name); ?>')"
                                         onerror="this.onerror=null; this.src='<?php echo $placeholder_image; ?>'; this.style.objectFit='contain'; this.style.padding='10px';">
                                <?php else: ?>
                                    <div class="d-flex align-items-center justify-content-center h-100 bg-light"
                                         onclick="previewImage('<?php echo $image_url; ?>', '<?php echo htmlspecialchars($display_name); ?>')">
                                        <i class="fas fa-file fa-3x text-muted"></i>
                                    </div>
                                <?php endif; ?>
                                
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
                                        <?php if ($is_image): ?>
                                        <button type="button" 
                                                class="btn btn-outline-primary" 
                                                onclick="previewImage('<?php echo $image_url; ?>', '<?php echo htmlspecialchars($display_name); ?>')"
                                                title="Preview">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php endif; ?>
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
            <?php else: ?>
                <div class="text-center py-4">
                    <div class="empty-attachment-icon mb-3">
                        <i class="fas fa-image fa-4x text-muted opacity-50"></i>
                    </div>
                    <h6 class="text-muted">Tidak Ada Lampiran</h6>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Pesan ini tidak dilengkapi dengan lampiran.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
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
    cursor: pointer;
}
.attachment-preview:hover img {
    transform: scale(1.05);
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
    width: 120px;
}
</style>