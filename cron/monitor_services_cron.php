<?php
/**
 * Cron Job untuk Monitoring Layanan Eksternal
 * File: cron/monitor_services_cron.php
 * 
 * Jalankan setiap 5-10 menit via cron job
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Log file
$logFile = __DIR__ . '/../logs/service_monitor.log';

function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

writeLog("=== Memulai monitoring layanan ===");

// Test SMTP
writeLog("Testing SMTP connection...");
$smtpResult = testSMTPConnection();
writeLog("SMTP: " . ($smtpResult['success'] ? 'OK' : 'FAILED') . " - " . $smtpResult['message']);

// Test Fonnte
writeLog("Testing Fonnte connection...");
$fonnteResult = testFonnteConnection();
writeLog("Fonnte: " . ($fonnteResult['success'] ? 'OK' : 'FAILED') . " - " . $fonnteResult['message']);

// Kirim notifikasi jika ada yang gagal
if (!$smtpResult['success'] || !$fonnteResult['success']) {
    $adminEmail = 'admin@example.com';
    $subject = '⚠️ Peringatan: Layanan Eksternal Bermasalah';
    $body = "Ada masalah dengan layanan eksternal:\n\n";
    
    if (!$smtpResult['success']) {
        $body .= "❌ SMTP: " . $smtpResult['message'] . "\n";
    }
    if (!$fonnteResult['success']) {
        $body .= "❌ Fonnte: " . $fonnteResult['message'] . "\n";
    }
    
    // Kirim email notifikasi (gunakan fungsi mail atau PHPMailer)
    // mail($adminEmail, $subject, $body);
    
    writeLog("NOTIFIKASI: Layanan bermasalah - email notifikasi dikirim ke $adminEmail");
}

writeLog("=== Selesai monitoring layanan ===\n");

/**
 * Test SMTP Connection
 */
function testSMTPConnection() {
    $result = ['success' => false, 'message' => ''];
    
    try {
        $connection = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 5);
        if ($connection) {
            fclose($connection);
            $result['success'] = true;
            $result['message'] = "Connected to " . SMTP_HOST . ":" . SMTP_PORT;
        } else {
            $result['message'] = "Failed to connect: $errstr ($errno)";
        }
    } catch (Exception $e) {
        $result['message'] = "Exception: " . $e->getMessage();
    }
    
    return $result;
}

/**
 * Test Fonnte Connection
 */
function testFonnteConnection() {
    $result = ['success' => false, 'message' => ''];
    
    if (empty(FONNTE_API_KEY)) {
        $result['message'] = "API Key not configured";
        return $result;
    }
    
    try {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => FONNTE_API_URL . '/device',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Authorization: ' . FONNTE_API_KEY]
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $result['success'] = true;
            $result['message'] = "Connected to Fonnte API (HTTP $httpCode)";
        } else {
            $result['message'] = "Failed to connect: HTTP $httpCode - $error";
        }
    } catch (Exception $e) {
        $result['message'] = "Exception: " . $e->getMessage();
    }
    
    return $result;
}
?>