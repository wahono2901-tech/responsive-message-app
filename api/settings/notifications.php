<?php
/**
 * API Notifications Configuration
 * File: api/settings/notifications.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cookie');

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check authentication
Auth::checkAuth();

// Get database connection
$db = Database::getInstance()->getConnection();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse input for POST requests
$input = json_decode(file_get_contents('php://input'), true);
$action = $_POST['action'] ?? $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'mailersend') {
                getMailerSendConfig($db);
            } elseif ($action === 'fonnte') {
                getFonnteConfig($db);
            } else {
                // Get both configs
                $mailersend = getMailerSendConfigData($db);
                $fonnte = getFonnteConfigData($db);
                echo json_encode([
                    'success' => true,
                    'mailersend' => $mailersend,
                    'fonnte' => $fonnte
                ]);
            }
            break;
            
        case 'POST':
            switch ($action) {
                case 'update_mailersend':
                    updateMailerSendConfig($db, $input);
                    break;
                case 'update_fonnte':
                    updateFonnteConfig($db, $input);
                    break;
                case 'test_mailersend':
                    testMailerSendConnection($input);
                    break;
                case 'test_fonnte':
                    testFonnteConnection($input);
                    break;
                case 'send_test_email':
                    sendTestEmail($input);
                    break;
                case 'send_test_whatsapp':
                    sendTestWhatsApp($input);
                    break;
                default:
                    throw new Exception('Invalid action');
            }
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ============================================
// FUNGSI GET CONFIG
// ============================================

function getMailerSendConfigData($db) {
    $sql = "SELECT * FROM mailersend_config ORDER BY id DESC LIMIT 1";
    $stmt = $db->query($sql);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        // Default config
        return [
            'id' => null,
            'api_token' => '',
            'domain' => '',
            'domain_id' => '',
            'from_email' => '',
            'from_name' => 'SMKN 12 Jakarta - Aplikasi Pesan Responsif',
            'smtp_server' => 'smtp.mailersend.net',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'test_domain' => '',
            'is_active' => 1
        ];
    }
    
    return $config;
}

function getMailerSendConfig($db) {
    $config = getMailerSendConfigData($db);
    echo json_encode([
        'success' => true,
        'config' => $config
    ]);
}

function getFonnteConfigData($db) {
    $sql = "SELECT * FROM fonnte_config ORDER BY id DESC LIMIT 1";
    $stmt = $db->query($sql);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        // Default config
        return [
            'id' => null,
            'api_token' => '',
            'account_token' => '',
            'device_id' => '6285174207795',
            'api_url' => 'https://api.fonnte.com/send',
            'email' => '',
            'password' => '',
            'country_code' => '62',
            'is_active' => 1
        ];
    }
    
    return $config;
}

function getFonnteConfig($db) {
    $config = getFonnteConfigData($db);
    echo json_encode([
        'success' => true,
        'config' => $config
    ]);
}

// ============================================
// FUNGSI UPDATE CONFIG
// ============================================

function updateMailerSendConfig($db, $data) {
    $api_token = $data['api_token'] ?? '';
    $domain = $data['domain'] ?? '';
    $domain_id = $data['domain_id'] ?? '';
    $from_email = $data['from_email'] ?? '';
    $from_name = $data['from_name'] ?? 'SMKN 12 Jakarta - Aplikasi Pesan Responsif';
    $smtp_server = $data['smtp_server'] ?? 'smtp.mailersend.net';
    $smtp_username = $data['smtp_username'] ?? '';
    $smtp_password = $data['smtp_password'] ?? '';
    $smtp_port = (int)($data['smtp_port'] ?? 587);
    $smtp_encryption = $data['smtp_encryption'] ?? 'tls';
    $test_domain = $data['test_domain'] ?? '';
    $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    
    // Check if exists
    $check = $db->query("SELECT id FROM mailersend_config LIMIT 1")->fetch();
    
    if ($check) {
        $sql = "UPDATE mailersend_config SET 
                api_token = ?, domain = ?, domain_id = ?, from_email = ?, from_name = ?,
                smtp_server = ?, smtp_username = ?, smtp_password = ?, smtp_port = ?,
                smtp_encryption = ?, test_domain = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            $api_token, $domain, $domain_id, $from_email, $from_name,
            $smtp_server, $smtp_username, $smtp_password, $smtp_port,
            $smtp_encryption, $test_domain, $is_active, $check['id']
        ]);
    } else {
        $sql = "INSERT INTO mailersend_config 
                (api_token, domain, domain_id, from_email, from_name, smtp_server, 
                 smtp_username, smtp_password, smtp_port, smtp_encryption, test_domain, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            $api_token, $domain, $domain_id, $from_email, $from_name,
            $smtp_server, $smtp_username, $smtp_password, $smtp_port,
            $smtp_encryption, $test_domain, $is_active
        ]);
    }
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Konfigurasi MailerSend berhasil disimpan'
        ]);
    } else {
        throw new Exception('Gagal menyimpan konfigurasi');
    }
}

function updateFonnteConfig($db, $data) {
    $api_token = $data['api_token'] ?? '';
    $account_token = $data['account_token'] ?? '';
    $device_id = $data['device_id'] ?? '6285174207795';
    $api_url = $data['api_url'] ?? 'https://api.fonnte.com/send';
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $country_code = $data['country_code'] ?? '62';
    $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    
    // Check if exists
    $check = $db->query("SELECT id FROM fonnte_config LIMIT 1")->fetch();
    
    if ($check) {
        $sql = "UPDATE fonnte_config SET 
                api_token = ?, account_token = ?, device_id = ?, api_url = ?,
                email = ?, password = ?, country_code = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            $api_token, $account_token, $device_id, $api_url,
            $email, $password, $country_code, $is_active, $check['id']
        ]);
    } else {
        $sql = "INSERT INTO fonnte_config 
                (api_token, account_token, device_id, api_url, email, password, country_code, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            $api_token, $account_token, $device_id, $api_url,
            $email, $password, $country_code, $is_active
        ]);
    }
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Konfigurasi Fonnte berhasil disimpan'
        ]);
    } else {
        throw new Exception('Gagal menyimpan konfigurasi');
    }
}

// ============================================
// FUNGSI TEST CONNECTION
// ============================================

function testMailerSendConnection($data) {
    $api_token = $data['api_token'] ?? '';
    $from_email = $data['from_email'] ?? '';
    $from_name = $data['from_name'] ?? 'Test Notification';
    
    if (empty($api_token)) {
        throw new Exception('API Token tidak boleh kosong');
    }
    
    if (empty($from_email)) {
        throw new Exception('From Email tidak boleh kosong');
    }
    
    // Send test email to self
    $result = sendMailerSendEmail($api_token, $from_email, $from_name, $from_email, 'Test Connection');
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => '✓ Koneksi MailerSend berhasil! Email test telah dikirim ke ' . $from_email
        ]);
    } else {
        throw new Exception('Gagal mengirim email test: ' . ($result['error'] ?? 'Unknown error'));
    }
}

function testFonnteConnection($data) {
    $api_token = $data['api_token'] ?? '';
    $device_id = $data['device_id'] ?? '6285174207795';
    $api_url = $data['api_url'] ?? 'https://api.fonnte.com/send';
    $country_code = $data['country_code'] ?? '62';
    
    if (empty($api_token)) {
        throw new Exception('API Token tidak boleh kosong');
    }
    
    $result = sendFonnteMessage($api_token, $api_url, $device_id, $device_id, $country_code, 'Test Connection');
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => '✓ Koneksi Fonnte berhasil! WhatsApp test telah dikirim ke ' . $device_id
        ]);
    } else {
        throw new Exception('Gagal mengirim WhatsApp test: ' . ($result['error'] ?? 'Unknown error'));
    }
}

function sendTestEmail($data) {
    $api_token = $data['api_token'] ?? '';
    $from_email = $data['from_email'] ?? '';
    $from_name = $data['from_name'] ?? 'Test Notification';
    $test_email = $data['test_email'] ?? '';
    
    if (empty($test_email)) {
        throw new Exception('Email tujuan harus diisi');
    }
    
    if (empty($api_token)) {
        throw new Exception('API Token tidak boleh kosong');
    }
    
    $result = sendMailerSendEmail($api_token, $from_email, $from_name, $test_email, 'Test Email Notification');
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Email test berhasil dikirim ke ' . $test_email
        ]);
    } else {
        throw new Exception('Gagal mengirim email: ' . ($result['error'] ?? 'Unknown error'));
    }
}

function sendTestWhatsApp($data) {
    $api_token = $data['api_token'] ?? '';
    $api_url = $data['api_url'] ?? 'https://api.fonnte.com/send';
    $device_id = $data['device_id'] ?? '6285174207795';
    $country_code = $data['country_code'] ?? '62';
    $test_phone = $data['test_phone'] ?? '';
    
    if (empty($test_phone)) {
        throw new Exception('Nomor WhatsApp tujuan harus diisi');
    }
    
    if (empty($api_token)) {
        throw new Exception('API Token tidak boleh kosong');
    }
    
    $result = sendFonnteMessage($api_token, $api_url, $test_phone, $device_id, $country_code, 'Test WhatsApp');
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'WhatsApp test berhasil dikirim ke ' . $test_phone
        ]);
    } else {
        throw new Exception('Gagal mengirim WhatsApp: ' . ($result['error'] ?? 'Unknown error'));
    }
}

// ============================================
// FUNGSI SEND EMAIL VIA MAILERSEND
// ============================================

function sendMailerSendEmail($api_token, $from_email, $from_name, $to_email, $subject_prefix = 'Test') {
    $subject = $subject_prefix . ' - SMKN 12 Jakarta';
    
    $html_content = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; background: #f4f6f9; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .header { background: #0b4d8a; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { padding: 30px; }
            .footer { background: #e9ecef; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; border-radius: 0 0 10px 10px; }
            .test-badge { display: inline-block; background: #28a745; color: white; padding: 5px 10px; border-radius: 20px; font-size: 12px; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>📧 ' . htmlspecialchars($subject) . '</h1>
                <p>SMKN 12 Jakarta</p>
            </div>
            <div class="content">
                <h3>Yth. Admin,</h3>
                <p>Ini adalah email test dari halaman pengaturan sistem.</p>
                <p>Jika Anda menerima email ini, konfigurasi MailerSend berhasil.</p>
                <div class="test-badge">✓ Test Berhasil</div>
                <p style="margin-top: 20px; font-size: 12px; color: #6c757d;">
                    Dikirim pada: ' . date('d/m/Y H:i:s') . ' WIB
                </p>
            </div>
            <div class="footer">
                <p>SMKN 12 Jakarta</p>
                <p>Jl. Raya Bogor No. 12, Jakarta Timur</p>
                <p><small>Pesan otomatis dari sistem, mohon tidak membalas email ini.</small></p>
            </div>
        </div>
    </body>
    </html>';
    
    $data = [
        'from' => [
            'email' => $from_email,
            'name' => $from_name
        ],
        'to' => [
            [
                'email' => $to_email,
                'name' => 'Admin User'
            ]
        ],
        'subject' => $subject,
        'html' => $html_content,
        'text' => strip_tags($html_content)
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.mailersend.com/v1/email',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_token,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $success = ($httpCode >= 200 && $httpCode < 300);
    
    // Log result
    $logFile = ROOT_PATH . '/logs/email_debug.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $log = "[" . date('Y-m-d H:i:s') . "] " . ($success ? "SUCCESS" : "FAILED") . " - To: $to_email - HTTP: $httpCode\n";
    if ($curlError) {
        $log .= "CURL Error: $curlError\n";
    }
    if ($response) {
        $log .= "Response: $response\n";
    }
    file_put_contents($logFile, $log, FILE_APPEND);
    
    return [
        'success' => $success,
        'http_code' => $httpCode,
        'response' => json_decode($response, true),
        'error' => $curlError ?: ($httpCode != 200 ? "HTTP $httpCode" : null)
    ];
}

// ============================================
// FUNGSI SEND WHATSAPP VIA FONNTE
// ============================================

function formatPhoneNumber($phone, $country_code = '62') {
    // Hapus semua karakter non-digit
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Jika dimulai dengan 0, ganti dengan kode negara
    if (substr($phone, 0, 1) == '0') {
        $phone = $country_code . substr($phone, 1);
    }
    // Jika tidak dimulai dengan kode negara, tambahkan kode negara
    elseif (substr($phone, 0, strlen($country_code)) !== $country_code) {
        $phone = $country_code . $phone;
    }
    
    return $phone;
}

function sendFonnteMessage($api_token, $api_url, $target, $device_id, $country_code, $type = 'Test') {
    $formatted_target = formatPhoneNumber($target, $country_code);
    
    $current_date = date('d/m/Y H:i');
    
    $message = "🔔 *TEST NOTIFIKASI WHATSAPP - SMKN 12 Jakarta*\n\n";
    $message .= "Yth. *Admin Test*\n\n";
    $message .= "Ini adalah pesan test dari sistem Aplikasi Pesan Responsif.\n\n";
    $message .= "*Detail Test:*\n";
    $message .= "Tipe: " . $type . "\n";
    $message .= "Waktu: " . date('d/m/Y H:i:s') . " WIB\n";
    $message .= "Device ID: " . $device_id . "\n\n";
    $message .= "Jika Anda menerima pesan ini, berarti konfigurasi Fonnte berhasil! ✅\n\n";
    $message .= "_Pesan otomatis._\n";
    $message .= "Waktu: {$current_date}\n";
    $message .= "_Dikirim dari sistem SMKN 12 Jakarta_";
    
    $postData = [
        'target' => $formatted_target,
        'message' => $message,
        'countryCode' => $country_code,
        'delay' => '0'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => ['Authorization: ' . $api_token],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $response_data = json_decode($response, true);
    
    // Cek sukses
    $success = false;
    if ($httpCode == 200) {
        if (isset($response_data['status']) && $response_data['status'] == 1) {
            $success = true;
        } elseif (isset($response_data['status']) && $response_data['status'] === true) {
            $success = true;
        } elseif (isset($response_data['id'])) {
            $success = true;
        }
    }
    
    // Log result
    $logFile = ROOT_PATH . '/logs/whatsapp_debug.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $log = "[" . date('Y-m-d H:i:s') . "] " . ($success ? "SUCCESS" : "FAILED") . " - To: $formatted_target - HTTP: $httpCode\n";
    if ($curlError) {
        $log .= "CURL Error: $curlError\n";
    }
    if ($response) {
        $log .= "Response: $response\n";
    }
    file_put_contents($logFile, $log, FILE_APPEND);
    
    if ($success) {
        // Log success terpisah
        $successLog = ROOT_PATH . '/logs/whatsapp_success.log';
        $successMsg = "[" . date('Y-m-d H:i:s') . "] SUCCESS - WhatsApp sent to $formatted_target\n";
        file_put_contents($successLog, $successMsg, FILE_APPEND);
    }
    
    return [
        'success' => $success,
        'sent' => $success,
        'http_code' => $httpCode,
        'response' => $response_data,
        'error' => $curlError ?: ($response_data['reason'] ?? null)
    ];
}