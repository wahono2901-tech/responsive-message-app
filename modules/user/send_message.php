<?php
/**
 * Send Message - SEMUA USER BOLEH MENGAKSES
 * File: modules/user/send_message.php
 * 
 * PERBAIKAN:
 * - Menambahkan generate reference_number seperti di index.php
 * - Menambahkan fungsi kirim WhatsApp (Fonnte) menggunakan konfigurasi dari settings.php
 * - Menambahkan fungsi kirim Email (MailerSend) menggunakan konfigurasi dari settings.php
 * - Mengambil data phone_number dan email dari tabel users untuk notifikasi
 * - Menyimpan status notifikasi di database (email_notified, whatsapp_notified)
 * - Menghilangkan fitur pilihan prioritas (tidak diperlukan)
 * - PENAMBAHAN: Fitur upload gambar lampiran (opsional)
 * - PERBAIKAN: Menyesuaikan dengan struktur tabel message_attachments (filename, filepath, filetype, filesize)
 */

// Include config
require_once __DIR__ . '/../../config/config.php';

// ============================================================================
// LOAD KONFIGURASI TERPUSAT DARI SETTINGS.PHP
// ============================================================================
$mailersendConfig = [];
$fonnteConfig = [];

// Load MailerSend config
$mailersendConfigFile = ROOT_PATH . '/config/mailersend.json';
if (file_exists($mailersendConfigFile)) {
    $mailersendConfig = json_decode(file_get_contents($mailersendConfigFile), true);
} else {
    // Default config jika file belum ada
    $mailersendConfig = [
        'api_token' => 'mlsn.3ae3e0dd1dec6af1d2aa199c1d20287ee29e9fcda6679c4ff4049a7c26061d29',
        'domain' => 'test-r9084zv6rpjgw63d.mlsender.net',
        'from_email' => 'noreply@test-r9084zv6rpjgw63d.mlsender.net',
        'from_name' => 'SMKN 12 Jakarta - Aplikasi Pesan Responsif',
        'is_active' => 1
    ];
}

// Load Fonnte config
$fonnteConfigFile = ROOT_PATH . '/config/fonnte.json';
if (file_exists($fonnteConfigFile)) {
    $fonnteConfig = json_decode(file_get_contents($fonnteConfigFile), true);
} else {
    // Default config jika file belum ada
    $fonnteConfig = [
        'api_token' => 'FS2cq8FckmaTegxtZpFB',
        'api_url' => 'https://api.fonnte.com/send',
        'device_id' => '6285174207795',
        'country_code' => '62',
        'is_active' => 1
    ];
}

// ============================================================================
// DEFINISI KONSTANTA FILE LOG DAN UPLOAD
// ============================================================================
$logDir = ROOT_PATH . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

// Buat folder upload jika belum ada
$uploadDir = ROOT_PATH . '/uploads/messages';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

define('SEND_MESSAGE_DEBUG_LOG', $logDir . '/send_message_debug.log');
define('SEND_MESSAGE_EMAIL_LOG', $logDir . '/send_message_email.log');
define('SEND_MESSAGE_WHATSAPP_LOG', $logDir . '/send_message_whatsapp.log');
define('SEND_MESSAGE_UPLOAD_LOG', $logDir . '/send_message_upload.log');

// ============================================================================
// FUNGSI LOGGING
// ============================================================================
function writeLog($file, $message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    @file_put_contents($file, $log, FILE_APPEND);
    error_log($log);
}

function debug_log($message, $data = null) {
    writeLog(SEND_MESSAGE_DEBUG_LOG, $message, $data);
}

function email_log($message, $data = null) {
    writeLog(SEND_MESSAGE_EMAIL_LOG, $message, $data);
}

function wa_log($message, $data = null) {
    writeLog(SEND_MESSAGE_WHATSAPP_LOG, $message, $data);
}

function upload_log($message, $data = null) {
    writeLog(SEND_MESSAGE_UPLOAD_LOG, $message, $data);
}

debug_log("========== SEND_MESSAGE.PHP START ==========");
debug_log("MailerSend Config loaded", [
    'from_email' => $mailersendConfig['from_email'] ?? 'not set',
    'is_active' => $mailersendConfig['is_active'] ?? 0
]);
debug_log("Fonnte Config loaded", [
    'device_id' => $fonnteConfig['device_id'] ?? 'not set',
    'is_active' => $fonnteConfig['is_active'] ?? 0
]);

// ============================================================================
// FUNGSI FORMAT NOMOR WHATSAPP (SAMA DENGAN INDEX.PHP)
// ============================================================================
function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (substr($phone, 0, 1) == '0') {
        $phone = '62' . substr($phone, 1);
    }
    elseif (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }
    
    return $phone;
}

// ============================================================================
// FUNGSI UPLOAD GAMBAR - SESUAIKAN DENGAN STRUKTUR TABEL
// ============================================================================
function handleImageUpload($file, $message_id, $reference, $user_id) {
    upload_log("========== FUNGSI UPLOAD GAMBAR ==========");
    upload_log("Processing upload for message ID: $message_id, Reference: $reference, User ID: $user_id");
    
    // Cek apakah ada file yang diupload
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        upload_log("Tidak ada file yang diupload (opsional)");
        return ['success' => true, 'uploaded' => false, 'message' => 'Tidak ada file'];
    }
    
    // Cek error upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File melebihi ukuran maksimum yang diizinkan server (upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'File melebihi ukuran maksimum yang diizinkan form',
            UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian',
            UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak ditemukan',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP'
        ];
        $error = $errorMessages[$file['error']] ?? 'Unknown upload error';
        upload_log("ERROR: " . $error);
        return ['success' => false, 'error' => $error];
    }
    
    // Validasi ukuran file (max 5MB)
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        upload_log("ERROR: File terlalu besar - " . $file['size'] . " bytes (max 5MB)");
        return ['success' => false, 'error' => 'Ukuran file maksimal 5MB'];
    }
    
    // Validasi tipe file
    $allowedTypes = [
        'image/jpeg', 'image/jpg', 'image/pjpeg',
        'image/png', 
        'image/gif',
        'image/webp',
        'image/heic', 'image/heif', // Format iPhone
        'image/bmp',
        'image/x-ms-bmp'
    ];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    // Ekstensi yang diizinkan
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    upload_log("File info", [
        'original_name' => $file['name'],
        'size' => $file['size'],
        'tmp_name' => $file['tmp_name'],
        'mime_detected' => $mimeType,
        'extension' => $extension
    ]);
    
    if (!in_array($mimeType, $allowedTypes) && !in_array($extension, $allowedExtensions)) {
        upload_log("ERROR: Tipe file tidak diizinkan - MIME: $mimeType, Ext: $extension");
        return ['success' => false, 'error' => 'Tipe file tidak diizinkan. Gunakan JPG, JPEG, PNG, GIF, WEBP, HEIC, atau BMP'];
    }
    
    // Buat nama file unik
    $timestamp = time();
    $random = bin2hex(random_bytes(8));
    $newFileName = "msg_{$reference}_{$timestamp}_{$random}.{$extension}";
    
    // Tentukan path upload
    global $uploadDir;
    $uploadPath = $uploadDir . '/' . $newFileName;
    
    upload_log("Target path: " . $uploadPath);
    
    // Pindahkan file
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        chmod($uploadPath, 0644); // Set permission
        
        upload_log("✓ FILE BERHASIL DIUPLOAD", [
            'new_filename' => $newFileName,
            'path' => $uploadPath,
            'size' => $file['size']
        ]);
        
        return [
            'success' => true,
            'uploaded' => true,
            'filename' => $newFileName,
            'original_name' => $file['name'],
            'filepath' => 'uploads/messages/' . $newFileName,
            'filesize' => $file['size'],
            'filetype' => $extension,
            'mime_type' => $mimeType
        ];
    } else {
        upload_log("✗ GAGAL MEMINDAHKAN FILE");
        return ['success' => false, 'error' => 'Gagal menyimpan file'];
    }
}

// ============================================================================
// FUNGSI KIRIM WHATSAPP - MENGGUNAKAN KONFIGURASI DARI SETTINGS.PHP
// ============================================================================
function kirimWhatsApp($phone, $nama, $reference, $isi_pesan, $jenis_pesan, $prioritas = 'Medium') {
    global $fonnteConfig;
    
    wa_log("========== FUNGSI KIRIM WHATSAPP ==========");
    wa_log("Input - phone: $phone, nama: $nama, reference: $reference");
    wa_log("Jenis Pesan: $jenis_pesan, Prioritas: $prioritas");
    
    // Cek apakah Fonnte aktif
    if (!isset($fonnteConfig['is_active']) || $fonnteConfig['is_active'] != 1) {
        wa_log("Fonnte tidak aktif, lewati pengiriman WhatsApp");
        return ['success' => false, 'sent' => false, 'error' => 'Layanan WhatsApp tidak aktif'];
    }
    
    // Format nomor
    $formatted_phone = formatPhoneNumber($phone);
    wa_log("Phone setelah format: $formatted_phone");
    
    if (strlen($formatted_phone) < 10 || strlen($formatted_phone) > 15) {
        wa_log("ERROR: Nomor tidak valid - panjang: " . strlen($formatted_phone));
        return ['success' => false, 'error' => 'Nomor tidak valid'];
    }
    
    // Batasi panjang pesan untuk WhatsApp (max 1000 karakter)
    $isi_pesan_short = strlen($isi_pesan) > 500 ? substr($isi_pesan, 0, 500) . '...' : $isi_pesan;
    
    // Mapping prioritas ke emoji
    $priorityEmoji = [
        'Low' => '🔵',
        'Medium' => '🟡',
        'High' => '🔴'
    ];
    $priorityEmoji = $priorityEmoji[$prioritas] ?? '⚪';
    
    // Pesan notifikasi dengan isi pesan asli dan jenis pesan
    $message = "🔔 *NOTIFIKASI PESAN - SMKN 12 Jakarta*\n\n";
    $message .= "Yth. *$nama*\n\n";
    $message .= "Pesan Anda telah berhasil kami terima.\n\n";
    $message .= "*📋 INFORMASI PESAN:*\n";
    $message .= str_repeat("─", 25) . "\n";
    $message .= "📌 Nomor Referensi: $reference\n";
    $message .= "📂 Jenis Pesan: $jenis_pesan\n";
    $message .= "$priorityEmoji Prioritas: $prioritas\n";
    $message .= "📊 Status: Pending (Menunggu diproses)\n";
    $message .= str_repeat("─", 25) . "\n\n";
    
    $message .= "*📝 ISI PESAN ANDA:*\n";
    $message .= "```\n" . $isi_pesan_short . "\n```\n\n";
    
    $message .= "Pesan akan diproses dalam 1x24 jam.\n\n";
    $message .= "Terima kasih telah menghubungi SMKN 12 Jakarta.\n\n";
    $message .= "_Pesan otomatis._";
    
    wa_log("Pesan panjang: " . strlen($message));
    
    // Kirim via Fonnte
    $postData = [
        'target' => $formatted_phone,
        'message' => $message,
        'countryCode' => $fonnteConfig['country_code'] ?? '62',
        'delay' => '0'
    ];
    
    wa_log("Data ke Fonnte:", $postData);
    wa_log("URL: " . ($fonnteConfig['api_url'] ?? 'https://api.fonnte.com/send'));
    wa_log("API Token: " . substr($fonnteConfig['api_token'] ?? '', 0, 5) . '...');
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $fonnteConfig['api_url'] ?? 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => ['Authorization: ' . ($fonnteConfig['api_token'] ?? '')],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    wa_log("HTTP Code: $httpCode");
    if ($curlError) {
        wa_log("CURL Error: $curlError");
    }
    wa_log("Response: $response");
    
    $response_data = json_decode($response, true);
    
    $success = false;
    if ($httpCode == 200) {
        if (isset($response_data['status']) && ($response_data['status'] === true || $response_data['status'] == 1)) {
            $success = true;
        } elseif (isset($response_data['id'])) {
            $success = true;
        }
    }
    
    if ($success) {
        wa_log("✓ WHATSAPP BERHASIL dikirim ke $formatted_phone");
    } else {
        wa_log("✗ WHATSAPP GAGAL dikirim ke $formatted_phone");
        if (isset($response_data['reason'])) {
            wa_log("Reason: " . $response_data['reason']);
        }
    }
    
    return [
        'success' => $success,
        'sent' => $success,
        'error' => $curlError ?: ($response_data['reason'] ?? 'Unknown error')
    ];
}

// ============================================================================
// FUNGSI KIRIM EMAIL - MENGGUNAKAN KONFIGURASI DARI SETTINGS.PHP
// ============================================================================
function kirimEmail($email, $nama, $reference, $isi_pesan, $jenis_pesan, $prioritas = 'Medium') {
    global $mailersendConfig;
    
    email_log("========== FUNGSI KIRIM EMAIL ==========");
    email_log("Input - email: $email, nama: $nama, reference: $reference");
    email_log("Jenis Pesan: $jenis_pesan, Prioritas: $prioritas");
    
    // Cek apakah MailerSend aktif
    if (!isset($mailersendConfig['is_active']) || $mailersendConfig['is_active'] != 1) {
        email_log("MailerSend tidak aktif, lewati pengiriman email");
        return ['success' => false, 'sent' => false, 'error' => 'Layanan email tidak aktif'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        email_log("ERROR: Email tidak valid");
        return ['success' => false, 'error' => 'Email tidak valid'];
    }
    
    if (empty($mailersendConfig['api_token'])) {
        email_log("ERROR: API Token kosong");
        return ['success' => false, 'error' => 'API Token tidak boleh kosong'];
    }
    
    if (empty($mailersendConfig['from_email'])) {
        email_log("ERROR: From Email kosong");
        return ['success' => false, 'error' => 'From Email tidak boleh kosong'];
    }
    
    $subject = "Konfirmasi Pesan #$reference - SMKN 12 Jakarta";
    
    // Mapping prioritas ke badge warna
    $priorityBadge = [
        'Low' => '<span style="background: #17a2b8; color: white; padding: 3px 10px; border-radius: 12px; font-size: 12px;">🔵 Rendah</span>',
        'Medium' => '<span style="background: #ffc107; color: #212529; padding: 3px 10px; border-radius: 12px; font-size: 12px;">🟡 Sedang</span>',
        'High' => '<span style="background: #dc3545; color: white; padding: 3px 10px; border-radius: 12px; font-size: 12px;">🔴 Tinggi</span>'
    ];
    $priorityBadge = $priorityBadge[$prioritas] ?? '<span style="background: #6c757d; color: white; padding: 3px 10px; border-radius: 12px; font-size: 12px;">⚪ ' . $prioritas . '</span>';
    
    // HTML Email dengan format yang lebih informatif
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; background-color: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #0b4d8a, #1a73e8); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0; opacity: 0.9; }
        .content { padding: 30px; }
        .info-box { background: #f8f9fa; border-left: 4px solid #0b4d8a; padding: 20px; margin: 20px 0; border-radius: 0 5px 5px 0; }
        .reference-box { background: #e8f0fe; padding: 15px; border-radius: 5px; text-align: center; margin: 20px 0; }
        .reference { font-family: monospace; font-size: 18px; color: #0b4d8a; font-weight: bold; }
        .message-box { background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px; margin: 15px 0; }
        .message-content { background: white; padding: 15px; border-radius: 5px; white-space: pre-line; font-style: italic; color: #495057; }
        .detail-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .detail-table td { padding: 10px; border-bottom: 1px solid #e9ecef; }
        .detail-table td:first-child { font-weight: bold; color: #495057; width: 120px; }
        .btn { display: inline-block; background: #0b4d8a; color: white; padding: 12px 30px; text-decoration: none; border-radius: 50px; font-weight: bold; margin: 10px 0; }
        .footer { background: #e9ecef; padding: 20px; text-align: center; color: #6c757d; font-size: 12px; border-top: 1px solid #dee2e6; }
        .priority-badge { display: inline-block; margin-left: 10px; }
        .message-preview { max-height: 200px; overflow-y: auto; }
        .attachment-info { background: #e7f3ff; padding: 10px; border-radius: 5px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 SMKN 12 Jakarta</h1>
            <p>Konfirmasi Penerimaan Pesan</p>
        </div>
        
        <div class="content">
            <p>Yth. <strong>' . htmlspecialchars($nama) . '</strong>,</p>
            
            <p>Terima kasih telah menghubungi SMKN 12 Jakarta melalui Aplikasi Pesan Responsif.</p>
            
            <div class="reference-box">
                <p style="margin: 0; color: #6c757d;">Nomor Referensi Pesan Anda:</p>
                <p class="reference">' . htmlspecialchars($reference) . '</p>
            </div>
            
            <div class="info-box">
                <h4 style="margin-top: 0; color: #0b4d8a;">📋 DETAIL PESAN</h4>
                
                <table class="detail-table">
                    <tr>
                        <td>Jenis Pesan</td>
                        <td><strong>' . htmlspecialchars($jenis_pesan) . '</strong></td>
                    </tr>
                    <tr>
                        <td>Prioritas</td>
                        <td>' . $priorityBadge . '</td>
                    </tr>
                    <tr>
                        <td>Status</td>
                        <td><span style="background: #ffc107; color: #212529; padding: 3px 10px; border-radius: 12px;">Pending</span> (Menunggu Diproses)</td>
                    </tr>
                    <tr>
                        <td>Estimasi Respons</td>
                        <td>1x24 jam kerja</td>
                    </tr>
                </table>
            </div>
            
            <div class="message-box">
                <h4 style="margin-top: 0; color: #0b4d8a;">📝 ISI PESAN ANDA</h4>
                <div class="message-content message-preview">
                    ' . nl2br(htmlspecialchars($isi_pesan)) . '
                </div>
            </div>';
            
            // Tambahkan informasi lampiran jika ada
            if (isset($_SESSION['uploaded_files']) && !empty($_SESSION['uploaded_files'])) {
                $html .= '
            <div class="attachment-info">
                <p><strong>📎 Lampiran:</strong> ' . count($_SESSION['uploaded_files']) . ' file gambar</p>
            </div>';
            }
            
            $html .= '
            
            <p><strong>PENTING:</strong> Simpan nomor referensi di atas untuk mengecek status pesan Anda.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . BASE_URL . 'index.php" class="btn">🏫 Kembali ke Beranda</a>
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; ' . date('Y') . ' SMKN 12 Jakarta. All rights reserved.</p>
            <p><i>Powered by MailerSend</i></p>
        </div>
    </div>
</body>
</html>';
    
    // Data untuk MailerSend API
    $data = [
        'from' => [
            'email' => $mailersendConfig['from_email'],
            'name' => $mailersendConfig['from_name'] ?? 'SMKN 12 Jakarta'
        ],
        'to' => [
            [
                'email' => $email,
                'name' => $nama
            ]
        ],
        'subject' => $subject,
        'html' => $html,
        'text' => strip_tags($html)
    ];
    
    email_log("Data email:", [
        'from' => $mailersendConfig['from_email'],
        'to' => $email,
        'subject' => $subject,
        'html_length' => strlen($html)
    ]);
    
    // Kirim via MailerSend API
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.mailersend.com/v1/email',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $mailersendConfig['api_token'],
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    email_log("HTTP Code: $httpCode");
    if ($curlError) {
        email_log("CURL Error: $curlError");
    }
    email_log("Response: $response");
    
    $response_data = json_decode($response, true);
    $success = ($httpCode >= 200 && $httpCode < 300);
    
    if ($success) {
        email_log("✓ EMAIL BERHASIL dikirim ke $email");
    } else {
        email_log("✗ EMAIL GAGAL dikirim ke $email");
        if (isset($response_data['message'])) {
            email_log("Pesan error: " . $response_data['message']);
        }
    }
    
    return [
        'success' => $success,
        'sent' => $success,
        'error' => $curlError ?: ($response_data['message'] ?? 'Unknown error')
    ];
}

// ============================================================================
// FUNGSI GET NAMA JENIS PESAN
// ============================================================================
function getJenisPesanName($db, $jenis_pesan_id) {
    try {
        $stmt = $db->prepare("SELECT jenis_pesan FROM message_types WHERE id = ?");
        $stmt->execute([$jenis_pesan_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return !empty($result) ? $result['jenis_pesan'] : 'Tidak diketahui';
    } catch (Exception $e) {
        debug_log("Gagal ambil nama jenis pesan: " . $e->getMessage());
        return 'Tidak diketahui';
    }
}

// ============================================================================
// FUNGSI HITUNG EXPIRED AT (SESUAI DENGAN MESSAGE TYPES)
// ============================================================================
function hitungExpiredAt($db, $jenis_pesan_id) {
    $deadline_hours = 72; // Default 72 jam
    try {
        $stmt = $db->prepare("SELECT response_deadline_hours FROM message_types WHERE id = ?");
        $stmt->execute([$jenis_pesan_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($result)) {
            $deadline_hours = $result['response_deadline_hours'];
        }
    } catch (Exception $e) {
        debug_log("Gagal ambil deadline, pakai default", ['error' => $e->getMessage()]);
    }
    
    return date('Y-m-d H:i:s', strtotime('+' . $deadline_hours . ' hours'));
}

// Cek apakah file session.php ada
$session_file = __DIR__ . '/../../config/session.php';
if (file_exists($session_file)) {
    require_once $session_file;
} else {
    // Fallback: start session manually
    if (session_status() === PHP_SESSION_NONE) {
        session_name('RMSESSID');
        session_start();
    }
}

// DEBUG: Log sebelum cek session
debug_log("=== SEND_MESSAGE ACCESS ===");
debug_log("Session ID: " . session_id());
debug_log("Session Name: " . session_name());
debug_log("Session save path: " . session_save_path());
debug_log("Session data: " . print_r($_SESSION, true));

// Cek login
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    debug_log("SEND_MESSAGE: No session, redirecting to login");
    
    // Redirect ke login
    $login_url = rtrim(BASE_URL, '/') . '/login.php?error=session_expired&from=send_message';
    header('Location: ' . $login_url);
    exit;
}

// Log sukses
debug_log("SEND_MESSAGE accessed by: " . ($_SESSION['username'] ?? 'unknown') . 
          " ID: " . ($_SESSION['user_id'] ?? 'none') . 
          " Type: " . ($_SESSION['user_type'] ?? 'unknown'));

// ============================================
// LOGIKA SEDERHANA: SEMUA USER BOLEH AKSES
// ============================================
$userType = $_SESSION['user_type'] ?? '';

// ============================================
// KONEKSI DATABASE
// ============================================
try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    debug_log("Database connection successful");
} catch (PDOException $e) {
    $db_error = "Koneksi database gagal: " . $e->getMessage();
    debug_log("Send Message DB Error: " . $e->getMessage());
    $db = null;
}

// ============================================
// AMBIL DATA JENIS PESAN
// ============================================
$messageTypes = [];
$db_error = '';

if ($db) {
    try {
        $stmt = $db->query("SELECT * FROM message_types ORDER BY jenis_pesan");
        $messageTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        debug_log("Message types loaded: " . count($messageTypes));
    } catch (PDOException $e) {
        $messageTypes = [];
        $db_error = "Gagal mengambil data jenis pesan: " . $e->getMessage();
        debug_log("Send Message Query Error: " . $e->getMessage());
    }
}

// ============================================
// AMBIL DATA USER UNTUK EMAIL & PHONE
// ============================================
$user_email = '';
$user_phone = '';
$user_nama = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? '';

if ($db && isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("SELECT email, phone_number FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userData) {
            $user_email = $userData['email'] ?? '';
            $user_phone = $userData['phone_number'] ?? '';
            debug_log("User data loaded", ['email' => $user_email, 'phone' => $user_phone]);
        }
    } catch (Exception $e) {
        debug_log("Error loading user data: " . $e->getMessage());
    }
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
$error = '';
$success = '';
$jenis_pesan_id = 0;
$isi_pesan = '';

// Cek duplikat submit (sama seperti index.php)
if (!isset($_SESSION['processed_messages'])) {
    $_SESSION['processed_messages'] = [];
}

foreach ($_SESSION['processed_messages'] as $key => $timestamp) {
    if (time() - $timestamp > 3600) {
        unset($_SESSION['processed_messages'][$key]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    
    debug_log("========== FORM SUBMIT DETECTED ==========");
    
    $form_unique_id = $_POST['form_unique_id'] ?? '';
    $submit_token = md5($form_unique_id . $_SERVER['REMOTE_ADDR']);
    
    if (isset($_SESSION['processed_messages'][$submit_token])) {
        debug_log("✗ DUPLICATE SUBMIT DETECTED", ['token' => $submit_token]);
        $error = "Form ini sudah diproses. Silakan refresh halaman untuk mengirim pesan baru.";
    } else {
        $_SESSION['processed_messages'][$submit_token] = time();
        $_SESSION['form_unique_id'] = bin2hex(random_bytes(16));
        
        $jenis_pesan_id = (int)($_POST['jenis_pesan_id'] ?? 0);
        $isi_pesan = trim($_POST['isi_pesan'] ?? '');
        
        debug_log("Form data", [
            'jenis_pesan_id' => $jenis_pesan_id,
            'isi_pesan_length' => strlen($isi_pesan),
            'user_id' => $_SESSION['user_id']
        ]);
        
        // Validasi input
        $errors = [];
        if ($jenis_pesan_id <= 0) {
            $errors[] = "Jenis pesan harus dipilih";
        }
        if (empty($isi_pesan)) {
            $errors[] = "Isi pesan harus diisi";
        } elseif (strlen($isi_pesan) < 10) {
            $errors[] = "Isi pesan minimal 10 karakter";
        } elseif (strlen($isi_pesan) > 5000) {
            $errors[] = "Isi pesan maksimal 5000 karakter";
        }
        if (!$db) {
            $errors[] = "Koneksi database tidak tersedia. Silakan coba lagi nanti.";
        }
        
        if (!empty($errors)) {
            $error = implode("<br>", $errors);
            debug_log("Validation errors", $errors);
        } else {
            try {
                $db->beginTransaction();
                debug_log("Transaction started");
                
                // ============================================
                // STEP 1: GENERATE REFERENCE NUMBER (SAMA SEPERTI INDEX.PHP)
                // ============================================
                $reference = 'MSG' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                $attempt = 1;
                
                do {
                    $stmt = $db->prepare("SELECT id FROM messages WHERE reference_number = ? LIMIT 1");
                    $stmt->execute([$reference]);
                    $cek_ref = $stmt->fetch();
                    
                    if (!empty($cek_ref)) {
                        $reference = 'MSG' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                        $attempt++;
                    }
                } while (!empty($cek_ref) && $attempt < 10);
                
                debug_log("Reference number generated", ['reference' => $reference]);
                
                // ============================================
                // STEP 2: HITUNG EXPIRED AT
                // ============================================
                $expired_at = hitungExpiredAt($db, $jenis_pesan_id);
                debug_log("Expired at", ['expired_at' => $expired_at]);
                
                // ============================================
                // STEP 3: INSERT MESSAGES
                // ============================================
                // Cek struktur tabel messages
                $stmt = $db->query("DESCRIBE messages");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Cek apakah ada kolom title/judul
                if (in_array('title', $columns) || in_array('judul', $columns)) {
                    $titleField = in_array('title', $columns) ? 'title' : 'judul';
                    $autoTitle = substr($isi_pesan, 0, 50) . (strlen($isi_pesan) > 50 ? '...' : '');
                    
                    $sql = "INSERT INTO messages (
                        reference_number, 
                        pengirim_id, 
                        pengirim_nama, 
                        pengirim_email,
                        pengirim_phone,
                        jenis_pesan_id, 
                        {$titleField}, 
                        isi_pesan, 
                        status, 
                        is_external,
                        ip_address,
                        user_agent,
                        expired_at,
                        email_notified,
                        whatsapp_notified,
                        has_attachments,
                        created_at,
                        updated_at
                    ) VALUES (
                        :reference, 
                        :pengirim_id, 
                        :pengirim_nama, 
                        :pengirim_email,
                        :pengirim_phone,
                        :jenis_pesan_id, 
                        :title, 
                        :isi_pesan, 
                        'Pending', 
                        0,
                        :ip,
                        :ua,
                        :expired_at,
                        0,
                        0,
                        0,
                        NOW(),
                        NOW()
                    )";
                    
                    $stmt = $db->prepare($sql);
                    $result = $stmt->execute([
                        ':reference' => $reference,
                        ':pengirim_id' => $_SESSION['user_id'],
                        ':pengirim_nama' => $user_nama,
                        ':pengirim_email' => $user_email ?: null,
                        ':pengirim_phone' => $user_phone ?: null,
                        ':jenis_pesan_id' => $jenis_pesan_id,
                        ':title' => $autoTitle,
                        ':isi_pesan' => $isi_pesan,
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ':expired_at' => $expired_at
                    ]);
                } else {
                    $sql = "INSERT INTO messages (
                        reference_number, 
                        pengirim_id, 
                        pengirim_nama, 
                        pengirim_email,
                        pengirim_phone,
                        jenis_pesan_id, 
                        isi_pesan, 
                        status, 
                        is_external,
                        ip_address,
                        user_agent,
                        expired_at,
                        email_notified,
                        whatsapp_notified,
                        has_attachments,
                        created_at,
                        updated_at
                    ) VALUES (
                        :reference, 
                        :pengirim_id, 
                        :pengirim_nama, 
                        :pengirim_email,
                        :pengirim_phone,
                        :jenis_pesan_id, 
                        :isi_pesan, 
                        'Pending', 
                        0,
                        :ip,
                        :ua,
                        :expired_at,
                        0,
                        0,
                        0,
                        NOW(),
                        NOW()
                    )";
                    
                    $stmt = $db->prepare($sql);
                    $result = $stmt->execute([
                        ':reference' => $reference,
                        ':pengirim_id' => $_SESSION['user_id'],
                        ':pengirim_nama' => $user_nama,
                        ':pengirim_email' => $user_email ?: null,
                        ':pengirim_phone' => $user_phone ?: null,
                        ':jenis_pesan_id' => $jenis_pesan_id,
                        ':isi_pesan' => $isi_pesan,
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ':expired_at' => $expired_at
                    ]);
                }
                
                if ($result) {
                    $message_id = $db->lastInsertId();
                    debug_log("✓ INSERT MESSAGES BERHASIL", ['message_id' => $message_id, 'reference' => $reference]);
                    
                    // ============================================
                    // STEP 4: HANDLE FILE UPLOAD (OPSIONAL)
                    // ============================================
                    $upload_result = null;
                    $uploaded_files = [];
                    
                    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                        debug_log(">>> MEMPROSES UPLOAD GAMBAR");
                        
                        $total_files = count($_FILES['attachments']['name']);
                        debug_log("Total files: " . $total_files);
                        
                        for ($i = 0; $i < $total_files; $i++) {
                            $file = [
                                'name' => $_FILES['attachments']['name'][$i],
                                'type' => $_FILES['attachments']['type'][$i],
                                'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                                'error' => $_FILES['attachments']['error'][$i],
                                'size' => $_FILES['attachments']['size'][$i]
                            ];
                            
                            $upload_result = handleImageUpload($file, $message_id, $reference, $_SESSION['user_id']);
                            
                            if ($upload_result['success'] && isset($upload_result['uploaded']) && $upload_result['uploaded']) {
                                // Simpan ke database - SESUAIKAN DENGAN STRUKTUR TABEL
                                $attachStmt = $db->prepare("
                                    INSERT INTO message_attachments (
                                        message_id, 
                                        user_id,
                                        filename, 
                                        filepath, 
                                        filesize, 
                                        filetype,
                                        is_approved,
                                        virus_scan_status,
                                        download_count,
                                        created_at
                                    ) VALUES (?, ?, ?, ?, ?, ?, 1, 'Pending', 0, NOW())
                                ");
                                
                                $attachResult = $attachStmt->execute([
                                    $message_id,
                                    $_SESSION['user_id'],
                                    $upload_result['filename'],
                                    $upload_result['filepath'],
                                    $upload_result['filesize'],
                                    $upload_result['filetype']
                                ]);
                                
                                if ($attachResult) {
                                    $uploaded_files[] = $upload_result;
                                    debug_log("✓ Data attachment disimpan ke database", [
                                        'attachment_id' => $db->lastInsertId()
                                    ]);
                                } else {
                                    debug_log("✗ Gagal menyimpan data attachment ke database");
                                }
                            } elseif (!$upload_result['success']) {
                                debug_log("✗ Gagal upload file ke-$i: " . ($upload_result['error'] ?? 'Unknown error'));
                                // Jangan batalkan transaksi, tetap lanjutkan karena upload opsional
                            }
                        }
                        
                        // Update has_attachments jika ada file yang berhasil diupload
                        if (!empty($uploaded_files)) {
                            $updateStmt = $db->prepare("UPDATE messages SET has_attachments = 1 WHERE id = ?");
                            $updateStmt->execute([$message_id]);
                            debug_log("✓ has_attachments diupdate menjadi 1");
                            
                            // Simpan ke session untuk ditampilkan di email
                            $_SESSION['uploaded_files'] = $uploaded_files;
                        }
                    } else {
                        debug_log("Tidak ada file yang diupload (opsional)");
                    }
                    
                    // ============================================
                    // STEP 5: AMBIL NAMA JENIS PESAN
                    // ============================================
                    $jenis_pesan_name = getJenisPesanName($db, $jenis_pesan_id);
                    debug_log("Jenis pesan: " . $jenis_pesan_name);
                    
                    // ============================================
                    // STEP 6: KIRIM NOTIFIKASI (EMAIL & WHATSAPP)
                    // ============================================
                    $email_terkirim = false;
                    $wa_terkirim = false;
                    $email_error = '';
                    $wa_error = '';
                    
                    // Kirim Email jika user memiliki email
                    if (!empty($user_email)) {
                        debug_log(">>> MENGIRIM EMAIL NOTIFIKASI ke: " . $user_email);
                        $email_result = kirimEmail($user_email, $user_nama, $reference, $isi_pesan, $jenis_pesan_name);
                        $email_terkirim = $email_result['success'] ?? false;
                        
                        if ($email_terkirim) {
                            debug_log("✓ EMAIL NOTIFIKASI BERHASIL");
                            $updateStmt = $db->prepare("UPDATE messages SET email_notified = 1 WHERE id = ?");
                            $updateStmt->execute([$message_id]);
                        } else {
                            $email_error = $email_result['error'] ?? 'Email gagal';
                            debug_log("✗ EMAIL NOTIFIKASI GAGAL: " . $email_error);
                        }
                    } else {
                        debug_log("User tidak memiliki email, lewati notifikasi email");
                    }
                    
                    // Kirim WhatsApp jika user memiliki nomor HP
                    if (!empty($user_phone)) {
                        debug_log(">>> MENGIRIM WHATSAPP NOTIFIKASI ke: " . $user_phone);
                        $wa_result = kirimWhatsApp($user_phone, $user_nama, $reference, $isi_pesan, $jenis_pesan_name);
                        $wa_terkirim = $wa_result['success'] ?? false;
                        
                        if ($wa_terkirim) {
                            debug_log("✓ WHATSAPP NOTIFIKASI BERHASIL");
                            $updateStmt = $db->prepare("UPDATE messages SET whatsapp_notified = 1 WHERE id = ?");
                            $updateStmt->execute([$message_id]);
                        } else {
                            $wa_error = $wa_result['error'] ?? 'WhatsApp gagal';
                            debug_log("✗ WHATSAPP NOTIFIKASI GAGAL: " . $wa_error);
                        }
                    } else {
                        debug_log("User tidak memiliki nomor HP, lewati notifikasi WhatsApp");
                    }
                    
                    // Hapus session uploaded_files setelah digunakan
                    if (isset($_SESSION['uploaded_files'])) {
                        unset($_SESSION['uploaded_files']);
                    }
                    
                    $db->commit();
                    debug_log("✓✓✓ TRANSACTION COMMITTED");
                    
                    // TAMPILKAN SUKSES
                    $success = "<div class='alert alert-success'>";
                    $success .= "<h5><i class='fas fa-check-circle'></i> PESAN BERHASIL DIKIRIM!</h5>";
                    $success .= "<p><strong>Nomor Referensi:</strong> <span class='badge bg-primary p-2'>{$reference}</span></p>";
                    $success .= "<p><strong>Jenis Pesan:</strong> {$jenis_pesan_name}</p>";
                    
                    if (!empty($uploaded_files)) {
                        $success .= "<hr>";
                        $success .= "<p><strong>📎 Lampiran:</strong> " . count($uploaded_files) . " file berhasil diupload:</p>";
                        $success .= "<ul class='small'>";
                        foreach ($uploaded_files as $file) {
                            $success .= "<li><i class='fas fa-image text-success'></i> " . htmlspecialchars($file['original_name']) . " (" . round($file['filesize'] / 1024, 1) . " KB)</li>";
                        }
                        $success .= "</ul>";
                    }
                    
                    if (!empty($user_email) || !empty($user_phone)) {
                        $success .= "<hr>";
                        $success .= "<p><strong>Notifikasi:</strong></p>";
                        if (!empty($user_email)) {
                            $success .= "<p>📧 Email: " . ($email_terkirim ? "✓ Terkirim ke {$user_email}" : "✗ Gagal - {$email_error}") . "</p>";
                        }
                        if (!empty($user_phone)) {
                            $success .= "<p>📱 WhatsApp: " . ($wa_terkirim ? "✓ Terkirim ke {$user_phone}" : "✗ Gagal - {$wa_error}") . "</p>";
                        }
                    }
                    
                    $success .= "<p class='mt-2 mb-0'><i class='fas fa-info-circle'></i> Simpan nomor referensi untuk melacak status pesan.</p>";
                    $success .= "</div>";
                    
                    // Reset POST data
                    $_POST = [];
                    $jenis_pesan_id = 0;
                    $isi_pesan = '';
                    
                } else {
                    $errorInfo = $stmt->errorInfo();
                    throw new Exception("Gagal mengirim pesan: " . ($errorInfo[2] ?? 'Unknown error'));
                }
                
            } catch (Exception $e) {
                if ($db && $db->inTransaction()) {
                    $db->rollBack();
                    debug_log("✓ ROLLBACK: Transaction dibatalkan");
                }
                
                $error = "Kesalahan: " . $e->getMessage();
                debug_log("✗ ERROR: " . $e->getMessage());
            }
        }
    }
}

// ============================================
// TAMPILKAN HALAMAN
// ============================================
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-12">
            
            <!-- User Info dengan Link Navigasi -->
            <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center">
                <div class="mb-2 mb-md-0">
                    <i class="fas fa-user-circle me-2"></i>
                    <strong><?php echo htmlspecialchars($user_nama); ?></strong>
                    <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($userType); ?></span>
                    
                    <!-- Tampilkan email dan phone jika ada -->
                    <?php if (!empty($user_email)): ?>
                        <span class="badge mailersend-badge ms-2">
                            <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($user_email); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($user_phone)): ?>
                        <span class="badge whatsapp-badge ms-2">
                            <i class="fab fa-whatsapp me-1"></i><?php echo htmlspecialchars($user_phone); ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="d-flex gap-2">
                    <!-- Link ke halaman view messages untuk semua user -->
                    <a href="view_messages.php" class="btn btn-sm btn-info">
                        <i class="fas fa-list me-1"></i>Lihat Pesan Saya
                    </a>
                    
                    <!-- Link ke halaman khusus guru (hanya untuk guru khusus) -->
                    <?php 
                    $specialGuruTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'];
                    if (in_array($userType, $specialGuruTypes)): ?>
                        <a href="<?php echo rtrim(BASE_URL, '/'); ?>/modules/guru/followup.php" class="btn btn-sm btn-warning">
                            <i class="fas fa-chalkboard-teacher me-1"></i>Followup Guru
                        </a>
                    <?php endif; ?>
                    
                    <!-- Link ke dashboard admin (hanya untuk admin) -->
                    <?php if (in_array($userType, ['Admin', 'Kepala_Sekolah', 'Wakil_Kepala'])): ?>
                        <a href="<?php echo rtrim(BASE_URL, '/'); ?>/modules/admin/dashboard.php" class="btn btn-sm btn-warning">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard Admin
                        </a>
                    <?php endif; ?>
                    
                    <a href="<?php echo rtrim(BASE_URL, '/'); ?>/logout.php" class="btn btn-sm btn-danger">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </a>
                </div>
            </div>
            
            <!-- Status Layanan -->
            <div class="service-info bg-light p-3 rounded mb-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <i class="fas fa-info-circle me-2 text-primary"></i>
                        <strong>Status Notifikasi:</strong>
                    </div>
                    <div>
                        <span class="badge mailersend-badge me-2">
                            <i class="fas fa-envelope me-1"></i> MailerSend: <?php echo ($mailersendConfig['is_active'] ?? 0) ? 'Aktif' : 'Nonaktif'; ?>
                        </span>
                        <span class="badge whatsapp-badge">
                            <i class="fab fa-whatsapp me-1"></i> Fonnte: <?php echo ($fonnteConfig['is_active'] ?? 0) ? 'Aktif' : 'Nonaktif'; ?>
                        </span>
                    </div>
                </div>
                <small class="text-muted d-block mt-2">
                    <i class="fas fa-check-circle text-success me-1"></i>
                    Notifikasi akan dikirim ke email/WhatsApp Anda jika data tersedia.
                </small>
            </div>
            
            <!-- Pesan Error Database -->
            <?php if (!empty($db_error)): ?>
                <div class="alert alert-warning alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($db_error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Error Message -->
            <?php if ($error && !strpos($error, '<div')): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-times-circle me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Success Message (dari HTML yang sudah dibangun) -->
            <?php if (strpos($success, '<div') !== false): ?>
                <?php echo $success; ?>
            <?php elseif ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Form Kirim Pesan -->
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-paper-plane me-2"></i>
                        Formulir Kirim Pesan
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="sendMessageForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="form_unique_id" value="<?php echo $_SESSION['form_unique_id'] ?? bin2hex(random_bytes(16)); ?>">
                        
                        <div class="mb-3">
                            <label for="jenis_pesan_id" class="form-label">
                                <i class="fas fa-tag me-1"></i> Jenis Pesan <span class="text-danger">*</span>
                            </label>
                            <select class="form-control <?php echo ($error && $jenis_pesan_id <= 0) ? 'is-invalid' : ''; ?>" 
                                    id="jenis_pesan_id" 
                                    name="jenis_pesan_id" 
                                    required>
                                <option value="">-- Pilih Jenis Pesan --</option>
                                <?php if (!empty($messageTypes)): ?>
                                    <?php foreach ($messageTypes as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" 
                                            <?php echo (isset($_POST['jenis_pesan_id']) && $_POST['jenis_pesan_id'] == $type['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['jenis_pesan']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($messageTypes) && $db): ?>
                                <div class="text-danger small mt-1">
                                    <i class="fas fa-exclamation-circle"></i> Data jenis pesan tidak tersedia
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="isi_pesan" class="form-label">
                                <i class="fas fa-envelope me-1"></i> Isi Pesan <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control <?php echo ($error && empty($isi_pesan)) ? 'is-invalid' : ''; ?>" 
                                      id="isi_pesan" 
                                      name="isi_pesan" 
                                      rows="6" 
                                      required
                                      minlength="10"
                                      maxlength="5000"
                                      placeholder="Tulis pesan Anda di sini... (minimal 10 karakter)"><?php echo htmlspecialchars($_POST['isi_pesan'] ?? ''); ?></textarea>
                            <div class="d-flex justify-content-between mt-1">
                                <small class="text-muted">
                                    <span id="charCount">0</span>/5000 karakter
                                </small>
                                <small class="text-muted">
                                    Minimal 10 karakter
                                </small>
                            </div>
                        </div>
                        
                        <!-- ========================================================= -->
                        <!-- FITUR UPLOAD GAMBAR (OPSIONAL) -->
                        <!-- ========================================================= -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-images me-1"></i> Lampiran Gambar <span class="text-muted">(Opsional)</span>
                            </label>
                            
                            <div class="upload-area border rounded p-4 text-center bg-light" id="uploadArea">
                                <div class="upload-icon mb-3">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-primary"></i>
                                </div>
                                <h6 class="mb-2">Klik untuk pilih gambar atau drag & drop</h6>
                                <p class="text-muted small mb-3">Format: JPG, JPEG, PNG, GIF, WEBP, HEIC, BMP (Max 5MB per file)</p>
                                
                                <div class="d-flex justify-content-center">
                                    <button type="button" class="btn btn-outline-primary" id="selectFilesBtn">
                                        <i class="fas fa-folder-open me-1"></i> Pilih File
                                    </button>
                                </div>
                                
                                <input type="file" 
                                       class="d-none" 
                                       id="attachments" 
                                       name="attachments[]" 
                                       multiple 
                                       accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.bmp,image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,image/bmp">
                            </div>
                            
                            <!-- Preview Area -->
                            <div id="previewArea" class="row g-2 mt-3"></div>
                            
                            <div class="form-text text-muted small">
                                <i class="fas fa-info-circle me-1"></i>
                                Anda dapat memilih lebih dari satu gambar. Hanya gambar yang akan ditampilkan di preview.
                            </div>
                        </div>
                        
                        <!-- Info Notifikasi -->
                        <div class="alert alert-info bg-light border-0 mb-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-bell fa-2x me-3 text-primary"></i>
                                <div>
                                    <strong>Notifikasi akan dikirim ke:</strong><br>
                                    <?php if (!empty($user_email)): ?>
                                        <span class="badge bg-primary me-2"><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($user_email); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary me-2"><i class="fas fa-envelope me-1"></i> Email tidak tersedia</span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($user_phone)): ?>
                                        <span class="badge bg-success"><i class="fab fa-whatsapp me-1"></i> <?php echo htmlspecialchars($user_phone); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="fab fa-whatsapp me-1"></i> WhatsApp tidak tersedia</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="reset" class="btn btn-secondary me-md-2" id="resetBtn">
                                <i class="fas fa-undo me-1"></i>Reset
                            </button>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-paper-plane me-1"></i>Kirim Pesan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Informasi Tambahan -->
            <div class="card mt-3 bg-light">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-info-circle me-1"></i> Informasi</h6>
                            <ul class="small mb-0">
                                <li>Pesan akan diproses oleh admin dalam 1x24 jam</li>
                                <li>Nomor referensi akan diberikan setelah pesan terkirim</li>
                                <li>Simpan nomor referensi untuk mengecek status pesan</li>
                                <li>Notifikasi akan dikirim ke email/WhatsApp Anda jika tersedia</li>
                                <li>Anda dapat melampirkan gambar pendukung (opsional, maksimal 5MB per file)</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-history me-1"></i> Riwayat Pesan</h6>
                            <a href="view_messages.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-list me-1"></i>Lihat Riwayat Pesan Saya
                            </a>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-search me-1"></i>
                                    Untuk melacak pesan, gunakan fitur "Lacak Status Pesan" di halaman utama.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.upload-area {
    border: 2px dashed #0d6efd;
    background-color: #f8f9fa;
    transition: all 0.3s ease;
    cursor: pointer;
}

.upload-area:hover {
    background-color: #e9ecef;
    border-color: #0a58ca;
}

.upload-area.dragover {
    background-color: #e2e6ea;
    border-color: #0a58ca;
}

.preview-item {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}

.preview-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.preview-item img {
    width: 100%;
    height: 120px;
    object-fit: cover;
}

.preview-item .preview-info {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 4px 8px;
    font-size: 11px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.preview-item .remove-file {
    position: absolute;
    top: 5px;
    right: 5px;
    background: rgba(220,53,69,0.9);
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.2s;
}

.preview-item .remove-file:hover {
    background: #dc3545;
    transform: scale(1.1);
}

.file-size {
    font-size: 10px;
    opacity: 0.9;
}

/* Badge styles */
.mailersend-badge {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
}

.whatsapp-badge {
    background: linear-gradient(135deg, #25d366 0%, #128C7E 100%);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Hitung karakter
    const textarea = document.getElementById('isi_pesan');
    const charCount = document.getElementById('charCount');
    
    if (textarea && charCount) {
        const updateCharCount = function() {
            const count = textarea.value.length;
            charCount.textContent = count;
            
            if (count < 10) {
                charCount.classList.add('text-danger');
            } else {
                charCount.classList.remove('text-danger');
            }
        };
        
        textarea.addEventListener('input', updateCharCount);
        updateCharCount(); // Initial count
    }
    
    // =========================================================
    // FITUR UPLOAD GAMBAR
    // =========================================================
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('attachments');
    const selectFilesBtn = document.getElementById('selectFilesBtn');
    const previewArea = document.getElementById('previewArea');
    
    // Klik pada area upload atau tombol untuk memilih file
    uploadArea.addEventListener('click', function() {
        fileInput.click();
    });
    
    selectFilesBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        fileInput.click();
    });
    
    // Drag & Drop
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleFiles(files);
        }
    });
    
    // Saat file dipilih
    fileInput.addEventListener('change', function() {
        handleFiles(this.files);
    });
    
    // Fungsi untuk handle files
    function handleFiles(files) {
        previewArea.innerHTML = ''; // Kosongkan preview
        
        if (files.length === 0) return;
        
        const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp'];
        const maxSize = 5 * 1024 * 1024; // 5MB
        
        Array.from(files).forEach((file, index) => {
            // Validasi ekstensi
            const extension = file.name.split('.').pop().toLowerCase();
            if (!allowedExtensions.includes(extension)) {
                showPreviewError(file, 'Format tidak didukung');
                return;
            }
            
            // Validasi ukuran
            if (file.size > maxSize) {
                showPreviewError(file, 'File >5MB');
                return;
            }
            
            // Preview gambar
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const previewCol = document.createElement('div');
                    previewCol.className = 'col-6 col-md-4 col-lg-3';
                    previewCol.innerHTML = `
                        <div class="preview-item">
                            <img src="${e.target.result}" alt="${file.name}">
                            <div class="preview-info">
                                <span class="text-truncate" style="max-width: 70px;">${file.name.substring(0, 10)}${file.name.length > 10 ? '...' : ''}</span>
                                <span class="file-size">${(file.size / 1024).toFixed(1)} KB</span>
                            </div>
                            <button type="button" class="remove-file" data-index="${index}" title="Hapus">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    `;
                    
                    previewArea.appendChild(previewCol);
                    
                    // Tombol hapus
                    previewCol.querySelector('.remove-file').addEventListener('click', function(e) {
                        e.stopPropagation();
                        removeFile(index);
                    });
                };
                
                reader.readAsDataURL(file);
            } else {
                showPreviewError(file, 'Bukan gambar');
            }
        });
    }
    
    function showPreviewError(file, error) {
        const previewCol = document.createElement('div');
        previewCol.className = 'col-6 col-md-4 col-lg-3';
        previewCol.innerHTML = `
            <div class="preview-item border border-danger">
                <div class="bg-light d-flex align-items-center justify-content-center" style="height: 120px;">
                    <i class="fas fa-exclamation-triangle text-danger fa-2x"></i>
                </div>
                <div class="preview-info bg-danger">
                    <span class="text-truncate" style="max-width: 70px;">${file.name.substring(0, 10)}${file.name.length > 10 ? '...' : ''}</span>
                    <span class="file-size">${error}</span>
                </div>
                <button type="button" class="remove-file" title="Hapus">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        previewArea.appendChild(previewCol);
        
        previewCol.querySelector('.remove-file').addEventListener('click', function() {
            removeFile(null);
        });
    }
    
    function removeFile(index) {
        // Untuk demo sederhana, kita reload preview
        // Dalam implementasi sebenarnya, perlu manipulasi FileList
        fileInput.value = '';
        previewArea.innerHTML = '';
    }
    
    // Reset button
    document.getElementById('resetBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('Reset formulir? Semua data yang diisi akan hilang.')) {
            document.getElementById('sendMessageForm').reset();
            previewArea.innerHTML = '';
            if (charCount) charCount.textContent = '0';
        }
    });
    
    // Validasi form sebelum submit
    const form = document.getElementById('sendMessageForm');
    const submitBtn = document.getElementById('submitBtn');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            const jenisPesan = document.getElementById('jenis_pesan_id');
            const isiPesan = document.getElementById('isi_pesan');
            
            if (!jenisPesan.value) {
                e.preventDefault();
                alert('Silakan pilih jenis pesan');
                jenisPesan.focus();
                return;
            }
            
            if (!isiPesan.value.trim()) {
                e.preventDefault();
                alert('Isi pesan tidak boleh kosong');
                isiPesan.focus();
                return;
            }
            
            if (isiPesan.value.trim().length < 10) {
                e.preventDefault();
                alert('Isi pesan minimal 10 karakter');
                isiPesan.focus();
                return;
            }
            
            // Validasi ukuran total file (maksimal 5 file atau 25MB)
            if (fileInput.files.length > 5) {
                e.preventDefault();
                alert('Maksimal 5 file yang dapat diupload sekaligus');
                return;
            }
            
            // Disable button saat submit
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Mengirim...';
            submitBtn.disabled = true;
        });
    }
});
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>