<?php
/**
 * API untuk test MailerSend
 * Mendukung: test connection, send test email
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../config/config.php';
require_once '../../includes/auth.php';

// Verify token
$headers = getallheaders();
$token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
$userId = verifyToken($token);

if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$input = json_decode(file_get_contents('php://input'), true);

function sendTestEmail($config, $to_email, $to_name = 'Admin Test') {
    if (empty($config['api_token']) || empty($config['from_email'])) {
        return ['success' => false, 'message' => 'API Token dan From Email harus diisi', 'sent' => false];
    }
    
    $subject = "Test Notifikasi Email - SMKN 12 Jakarta";
    
    $html_content = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html_content .= '<style>
        body{font-family:Arial;line-height:1.6;background:#f4f6f9;padding:20px}
        .container{max-width:600px;margin:0 auto;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1)}
        .header{background:#0b4d8a;color:white;padding:20px;text-align:center}
        .content{padding:30px}
        .footer{background:#e9ecef;padding:20px;text-align:center;font-size:12px;color:#6c757d}
    </style>';
    $html_content .= '</head><body><div class="container">';
    $html_content .= '<div class="header"><h1>📧 TEST NOTIFIKASI EMAIL</h1><p>SMKN 12 Jakarta</p></div>';
    $html_content .= '<div class="content">';
    $html_content .= '<h3>Yth. ' . htmlspecialchars($to_name) . ',</h3>';
    $html_content .= '<p>Ini adalah email test dari halaman pengaturan.</p>';
    $html_content .= '<p>Jika Anda menerima email ini, konfigurasi MailerSend berhasil.</p>';
    $html_content .= '<p>Waktu: ' . date('d/m/Y H:i:s') . ' WIB</p>';
    $html_content .= '</div><div class="footer"><p>SMKN 12 Jakarta</p></div>';
    $html_content .= '</div></body></html>';
    
    $data = [
        'from' => [
            'email' => $config['from_email'],
            'name' => $config['from_name']
        ],
        'to' => [
            [
                'email' => $to_email,
                'name' => $to_name
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
            'Authorization: Bearer ' . $config['api_token'],
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
    
    if ($success) {
        return ['success' => true, 'message' => "Email test berhasil dikirim ke $to_email", 'sent' => true];
    } else {
        $errorMsg = $curlError ?: "HTTP $httpCode";
        if ($response) {
            $respData = json_decode($response, true);
            if (isset($respData['message'])) {
                $errorMsg .= " - " . $respData['message'];
            }
        }
        return ['success' => false, 'message' => "Gagal mengirim email: $errorMsg", 'sent' => false];
    }
}

function testMailerSendConnection($config) {
    if (empty($config['api_token'])) {
        return ['success' => false, 'message' => 'API Token tidak boleh kosong'];
    }
    
    if (empty($config['from_email'])) {
        return ['success' => false, 'message' => 'From Email tidak boleh kosong'];
    }
    
    $test_email = $config['from_email'];
    $result = sendTestEmail($config, $test_email, 'Admin Test');
    
    if ($result['success']) {
        return ['success' => true, 'message' => "✓ Koneksi MailerSend berhasil! Email test telah dikirim ke $test_email", 'sent' => true];
    } else {
        return ['success' => false, 'message' => "❌ Gagal mengirim email test: " . ($result['message'] ?? 'Unknown error'), 'sent' => false];
    }
}

try {
    if ($action === 'email') {
        $to_email = $input['email'] ?? '';
        $config = $input['config'] ?? [];
        
        if (empty($to_email)) {
            echo json_encode(['success' => false, 'message' => 'Email tujuan harus diisi', 'sent' => false]);
            exit;
        }
        
        $result = sendTestEmail($config, $to_email);
        echo json_encode($result);
        
    } else {
        $config = $input;
        $result = testMailerSendConnection($config);
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log("MailerSend Test API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage(), 'sent' => false]);
}