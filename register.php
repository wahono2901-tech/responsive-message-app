<?php
/**
 * Halaman Registrasi - Dengan Konfigurasi Terpusat dari settings.php
 * File: register.php
 * 
 * PERBAIKAN:
 * - Mengambil konfigurasi MailerSend dan Fonnte dari settings.php
 * - Tidak perlu define konstanta lagi, langsung baca dari file konfigurasi
 * - Mempertahankan semua fungsi yang sudah berjalan dengan baik
 */

// Aktifkan error reporting maksimal
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_error.log');
ini_set('max_execution_time', 120);

require_once 'config/config.php';
require_once 'includes/auth.php';

// ============================================================================
// DEFINISI KONSTANTA FILE LOG (tetap diperlukan)
// ============================================================================
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

define('REGISTER_DEBUG_LOG', $logDir . '/register_debug.log');
define('REGISTER_EMAIL_LOG', $logDir . '/register_email.log');
define('REGISTER_WHATSAPP_LOG', $logDir . '/register_whatsapp.log');

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
    file_put_contents($file, $log, FILE_APPEND);
    error_log($log);
}

function debug_log($message, $data = null) {
    writeLog(REGISTER_DEBUG_LOG, $message, $data);
}

function email_log($message, $data = null) {
    writeLog(REGISTER_EMAIL_LOG, $message, $data);
}

function wa_log($message, $data = null) {
    writeLog(REGISTER_WHATSAPP_LOG, $message, $data);
}

// Tampilkan konfigurasi untuk verifikasi
debug_log("========== REGISTER.PHP START ==========");
debug_log("MailerSend Config loaded", [
    'from_email' => $mailersendConfig['from_email'] ?? 'not set',
    'is_active' => $mailersendConfig['is_active'] ?? 0
]);
debug_log("Fonnte Config loaded", [
    'device_id' => $fonnteConfig['device_id'] ?? 'not set',
    'is_active' => $fonnteConfig['is_active'] ?? 0
]);

// Inisialisasi
$auth = new Auth();
$success = '';
$errors = [];

// Redirect jika sudah login
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ============================================================================
// FUNGSI FORMAT NOMOR WHATSAPP (SAMA DENGAN SETTINGS.PHP)
// ============================================================================
function formatPhoneNumber($phone) {
    // Hapus semua karakter non-digit
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Jika dimulai dengan 0, ganti dengan 62
    if (substr($phone, 0, 1) == '0') {
        $phone = '62' . substr($phone, 1);
    }
    // Jika tidak dimulai dengan 62, tambahkan 62
    elseif (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }
    
    return $phone;
}

// ============================================================================
// FUNGSI KIRIM WHATSAPP - MENGGUNAKAN KONFIGURASI DARI SETTINGS.PHP
// ============================================================================
function kirimWhatsApp($phone, $nama, $username, $password) {
    global $fonnteConfig;
    
    wa_log("========== FUNGSI KIRIM WHATSAPP ==========");
    wa_log("Input - phone: $phone, nama: $nama, username: $username");
    
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
    
    // Pesan sederhana
    $message = "🔔 *NOTIFIKASI REGISTRASI - SMKN 12 Jakarta*\n\n";
    $message .= "Yth. *$nama*\n\n";
    $message .= "Akun Anda telah berhasil didaftarkan.\n\n";
    $message .= "*DATA LOGIN:*\n";
    $message .= "Username: $username\n";
    $message .= "Password: $password\n\n";
    $message .= "Login di: " . BASE_URL . "login.php\n\n";
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
    
    // Cek sukses (Fonnte mengembalikan status 200 dengan {"status": true} atau {"status": 1})
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
// FUNGSI KIRIM EMAIL VIA MAILERSEND API - MENGGUNAKAN KONFIGURASI DARI SETTINGS.PHP
// ============================================================================
function kirimEmail($email, $nama, $username, $password) {
    global $mailersendConfig;
    
    email_log("========== FUNGSI KIRIM EMAIL ==========");
    email_log("Input - email: $email, nama: $nama");
    
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
    
    $subject = "Registrasi Berhasil - SMKN 12 Jakarta";
    
    // HTML Email dengan format baru
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; background-color: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0; opacity: 0.9; }
        .content { padding: 30px; }
        .credentials { background: #f8f9fa; border-left: 4px solid #28a745; padding: 20px; margin: 20px 0; border-radius: 0 5px 5px 0; }
        .credential-box { background: white; padding: 15px; border-radius: 5px; }
        .credential-item { padding: 10px 0; border-bottom: 1px solid #e9ecef; }
        .credential-item:last-child { border-bottom: none; }
        .credential-label { color: #6c757d; font-weight: bold; display: inline-block; width: 100px; }
        .credential-value { font-family: monospace; color: #28a745; font-weight: bold; }
        .btn { display: inline-block; background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 50px; font-weight: bold; margin: 10px 0; }
        .footer { background: #e9ecef; padding: 20px; text-align: center; color: #6c757d; font-size: 12px; border-top: 1px solid #dee2e6; }
        .warning { color: #dc3545; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 SMKN 12 Jakarta</h1>
            <p>Registrasi Akun Berhasil</p>
        </div>
        
        <div class="content">
            <p>Yth. <strong>' . htmlspecialchars($nama) . '</strong>,</p>
            
            <p>Terima kasih telah mendaftar di Aplikasi Pesan Responsif SMKN 12 Jakarta.</p>
            
            <div class="credentials">
                <h4 style="margin-top: 0; color: #28a745;">📋 INFORMASI AKUN ANDA</h4>
                
                <div class="credential-box">
                    <div class="credential-item">
                        <span class="credential-label">Username:</span>
                        <span class="credential-value">' . htmlspecialchars($username) . '</span>
                    </div>
                    
                    <div class="credential-item">
                        <span class="credential-label">Password:</span>
                        <span class="credential-value">' . htmlspecialchars($password) . '</span>
                    </div>
                    
                    <div class="credential-item">
                        <span class="credential-label">Tanggal:</span>
                        <span class="credential-value">' . date('d/m/Y H:i') . ' WIB</span>
                    </div>
                </div>
                
                <p style="margin-top: 20px; color: #28a745;">
                    <strong>PENTING:</strong> Simpan informasi login ini dengan aman!
                </p>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . BASE_URL . 'login.php" class="btn">🔐 Login Sekarang</a>
            </div>
            
            <p class="warning">
                ⚠️ Email ini dikirim otomatis. Mohon tidak membalas email ini.
            </p>
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
    
    email_log("Data email via API:", [
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
        'error' => $curlError ?: ($response_data['message'] ?? 'Unknown error'),
        'http_code' => $httpCode,
        'response' => $response_data
    ];
}

// ============================================================================
// PROSES REGISTRASI (SISA KODE TETAP SAMA)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register']) && $_POST['register'] == '1') {
    debug_log("========== PROSES REGISTRASI ==========");
    
    // Ambil data
    $data = [
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? '',
        'user_type' => $_POST['user_type'] ?? '',
        'nis_nip' => trim($_POST['nis_nip'] ?? ''),
        'nama_lengkap' => trim($_POST['nama_lengkap'] ?? ''),
        'kelas' => $_POST['kelas'] ?? '',
        'jurusan' => $_POST['jurusan'] ?? '',
        'phone_number' => trim($_POST['phone_number'] ?? '')
    ];
    
    debug_log("Data:", array_diff_key($data, ['password' => '', 'confirm_password' => '']));
    
    // Validasi
    $validation_errors = [];
    if (empty($data['username'])) $validation_errors[] = "Username harus diisi";
    if (empty($data['email'])) $validation_errors[] = "Email harus diisi";
    if (empty($data['password'])) $validation_errors[] = "Password harus diisi";
    if (strlen($data['password']) < 6) $validation_errors[] = "Password minimal 6 karakter";
    if ($data['password'] !== $data['confirm_password']) $validation_errors[] = "Password tidak cocok";
    if (empty($data['user_type'])) $validation_errors[] = "Tipe pengguna harus dipilih";
    if (empty($data['nis_nip'])) $validation_errors[] = "NIS/NIP harus diisi";
    if (empty($data['nama_lengkap'])) $validation_errors[] = "Nama lengkap harus diisi";
    
    if (!empty($validation_errors)) {
        $errors = $validation_errors;
        debug_log("VALIDASI GAGAL:", $validation_errors);
    } else {
        debug_log("VALIDASI BERHASIL, registrasi...");
        
        try {
            $result = $auth->register($data);
            debug_log("Hasil register:", $result);
            
            if ($result['success']) {
                $success = "Registrasi berhasil!";
                debug_log("✓ REGISTRASI BERHASIL! ID: " . $result['user_id']);
                
                // KIRIM NOTIFIKASI
                $email_terkirim = false;
                $wa_terkirim = false;
                $email_error = '';
                $wa_error = '';
                
                // Kirim Email
                if (!empty($data['email'])) {
                    debug_log(">>> Mengirim email ke: " . $data['email']);
                    $email_result = kirimEmail(
                        $data['email'],
                        $data['nama_lengkap'],
                        $data['username'],
                        $data['password']
                    );
                    $email_terkirim = $email_result['success'] ?? false;
                    if (!$email_terkirim) {
                        $email_error = $email_result['error'] ?? 'Email gagal';
                        debug_log("✗ EMAIL GAGAL: " . $email_error);
                        if (isset($email_result['response'])) {
                            debug_log("Detail response:", $email_result['response']);
                        }
                    } else {
                        debug_log("✓ EMAIL BERHASIL");
                    }
                }
                
                // Kirim WhatsApp
                if (!empty($data['phone_number'])) {
                    debug_log(">>> Mengirim WhatsApp ke: " . $data['phone_number']);
                    $wa_result = kirimWhatsApp(
                        $data['phone_number'],
                        $data['nama_lengkap'],
                        $data['username'],
                        $data['password']
                    );
                    $wa_terkirim = $wa_result['success'] ?? false;
                    if (!$wa_terkirim) {
                        $wa_error = $wa_result['error'] ?? 'WhatsApp gagal';
                        debug_log("✗ WHATSAPP GAGAL: " . $wa_error);
                    } else {
                        debug_log("✓ WHATSAPP BERHASIL");
                    }
                }
                
                // Simpan status
                $_SESSION['register_notifications'] = [
                    'email' => $email_terkirim,
                    'whatsapp' => $wa_terkirim,
                    'email_error' => $email_error,
                    'wa_error' => $wa_error
                ];
                
                debug_log("Status - Email: " . ($email_terkirim ? 'OK' : 'Gagal') . ", WA: " . ($wa_terkirim ? 'OK' : 'Gagal'));
                
                // Auto login
                debug_log("Auto login dengan username: " . $data['username']);
                $loginResult = $auth->login($data['username'], $data['password']);
                
                if ($loginResult['success']) {
                    debug_log("✓ Auto login sukses");
                    header('Location: index.php');
                    exit;
                } else {
                    debug_log("✗ Auto login gagal, redirect ke login");
                    $_SESSION['register_success'] = "Registrasi berhasil! Silakan login.";
                    header('Location: login.php');
                    exit;
                }
            } else {
                $errors = $result['errors'] ?? ['Registrasi gagal'];
                debug_log("✗ REGISTRASI GAGAL:", $errors);
            }
        } catch (Exception $e) {
            debug_log("EXCEPTION: " . $e->getMessage());
            debug_log("Stack trace: " . $e->getTraceAsString());
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <div class="card border-0 shadow-lg">
                <div class="card-header bg-success text-white text-center py-4">
                    <h3 class="mb-0">
                        <i class="fas fa-user-plus me-2"></i> Registrasi Akun Baru
                    </h3>
                    <p class="mb-0 mt-2 small">SMKN 12 Jakarta - Aplikasi Pesan Responsif</p>
                </div>
                
                <!-- Status Notifikasi -->
                <?php if (isset($_SESSION['register_notifications'])): ?>
                <div class="alert alert-info alert-dismissible fade show m-3" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-info-circle fa-2x me-3"></i>
                        <div>
                            <strong>Status Notifikasi:</strong><br>
                            <?php if ($_SESSION['register_notifications']['email'] === true): ?>
                                <span class="badge bg-success me-2"><i class="fas fa-check"></i> Email terkirim</span>
                            <?php elseif ($_SESSION['register_notifications']['email'] === false): ?>
                                <span class="badge bg-warning me-2"><i class="fas fa-exclamation-triangle"></i> Email: <?php echo $_SESSION['register_notifications']['email_error'] ?: 'Gagal'; ?></span>
                            <?php endif; ?>
                            
                            <?php if ($_SESSION['register_notifications']['whatsapp'] === true): ?>
                                <span class="badge bg-success me-2"><i class="fab fa-whatsapp"></i> WhatsApp terkirim</span>
                            <?php elseif ($_SESSION['register_notifications']['whatsapp'] === false): ?>
                                <span class="badge bg-warning me-2"><i class="fab fa-whatsapp"></i> WA: <?php echo $_SESSION['register_notifications']['wa_error'] ?: 'Gagal'; ?></span>
                            <?php endif; ?>
                            
                            <p class="mt-2 mb-0 small text-success">
                                <i class="fas fa-check-circle me-1"></i> Data berhasil disimpan di database!
                            </p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['register_notifications']); endif; ?>
                
                <!-- Info Layanan dari Konfigurasi Terpusat -->
                <div class="alert alert-info bg-light border-0 small mx-3">
                    <div class="d-flex">
                        <i class="fas fa-server me-2 text-primary"></i>
                        <div>
                            <strong>Status Layanan (dari settings.php):</strong><br>
                            <span class="badge bg-<?php echo ($fonnteConfig['is_active'] ?? 0) ? 'success' : 'secondary'; ?> me-1">
                                <i class="fab fa-whatsapp"></i> Fonnte: <?php echo ($fonnteConfig['is_active'] ?? 0) ? 'Aktif' : 'Nonaktif'; ?>
                            </span>
                            <span class="badge bg-<?php echo ($mailersendConfig['is_active'] ?? 0) ? 'success' : 'secondary'; ?> me-1">
                                <i class="fas fa-envelope"></i> MailerSend: <?php echo ($mailersendConfig['is_active'] ?? 0) ? 'Aktif' : 'Nonaktif'; ?>
                            </span>
                            <span class="badge bg-info me-1">From: <?php echo $mailersendConfig['from_email'] ?? 'N/A'; ?></span>
                            <small class="d-block mt-1">✓ Menggunakan konfigurasi terpusat dari settings.php</small>
                        </div>
                    </div>
                </div>
                
                <div class="card-body p-4">
                    <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" id="registerForm">
                        <input type="hidden" name="register" value="1">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-user-tag me-1"></i> Tipe Pengguna <span class="text-danger">*</span>
                                </label>
                                <select class="form-control" name="user_type" required>
                                    <option value="">Pilih</option>
                                    <option value="Siswa" <?php echo (isset($_POST['user_type']) && $_POST['user_type'] == 'Siswa') ? 'selected' : ''; ?>>Siswa</option>
                                    <option value="Orang_Tua" <?php echo (isset($_POST['user_type']) && $_POST['user_type'] == 'Orang_Tua') ? 'selected' : ''; ?>>Orang Tua</option>
                                    <option value="Guru" <?php echo (isset($_POST['user_type']) && $_POST['user_type'] == 'Guru') ? 'selected' : ''; ?>>Guru</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-id-card me-1"></i> NIS/NIP <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="nis_nip" required 
                                       value="<?php echo htmlspecialchars($_POST['nis_nip'] ?? ''); ?>">
                                <small class="text-muted" id="nis_nip_hint"></small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-envelope me-1"></i> Email <span class="text-danger">*</span>
                                </label>
                                <input type="email" class="form-control" name="email" required 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                <small class="text-muted">Login akan dikirim ke email ini</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fab fa-whatsapp me-1 text-success"></i> Nomor WhatsApp
                                </label>
                                <input type="text" class="form-control" name="phone_number" 
                                       value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>"
                                       placeholder="08123456789">
                                <small class="text-muted">Untuk notifikasi WhatsApp (opsional)</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-id-badge me-1"></i> Nama Lengkap <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="nama_lengkap" required 
                                       value="<?php echo htmlspecialchars($_POST['nama_lengkap'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-user me-1"></i> Username <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="username" required 
                                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-lock me-1"></i> Password <span class="text-danger">*</span>
                                </label>
                                <input type="password" class="form-control" name="password" id="password" required>
                                <small class="text-muted">Minimal 6 karakter</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-lock me-1"></i> Konfirmasi Password <span class="text-danger">*</span>
                                </label>
                                <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="terms" id="terms" required>
                                <label class="form-check-label" for="terms">
                                    Saya menyetujui syarat dan ketentuan
                                </label>
                            </div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                                <i class="fas fa-user-plus me-2"></i> Daftar Sekarang
                            </button>
                        </div>
                    </form>
                    
                    <div class="text-center mt-3">
                        <p>Sudah punya akun? <a href="login.php" class="text-decoration-none fw-bold">Login di sini</a></p>
                    </div>
                </div>
                
                <div class="card-footer bg-light text-center py-2">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt me-1"></i> Data Anda aman dan terenkripsi
                        | <a href="#" onclick="window.open('<?php echo BASE_URL; ?>logs/register_email.log', '_blank')" class="text-muted">Log Email</a>
                        | <a href="#" onclick="window.open('<?php echo BASE_URL; ?>logs/register_whatsapp.log', '_blank')" class="text-muted">Log WA</a>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('registerForm');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            const pass = document.querySelector('[name="password"]').value;
            const confirm = document.querySelector('[name="confirm_password"]').value;
            
            if (pass !== confirm) {
                e.preventDefault();
                alert('Password tidak cocok!');
                return false;
            }
            
            if (pass.length < 6) {
                e.preventDefault();
                alert('Password minimal 6 karakter!');
                return false;
            }
            
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
            
            return true;
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>