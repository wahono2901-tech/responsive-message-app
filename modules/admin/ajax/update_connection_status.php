<?php
/**
 * AJAX Handler untuk Update Status Koneksi
 * File: modules/admin/ajax/update_connection_status.php
 */

require_once '../../../config/config.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/functions.php';

// Check authentication
Auth::checkAuth();

header('Content-Type: application/json');

// Fungsi untuk test koneksi Fonnte
function testFonnteConnection() {
    // [Sama dengan fungsi di atas]
    if (empty(WHATSAPP_TOKEN)) {
        return ['status' => 'error', 'color' => 'danger', 'message' => 'Token tidak dikonfigurasi'];
    }
    
    try {
        $start = microtime(true);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.fonnte.com/device',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: ' . WHATSAPP_TOKEN],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = round((microtime(true) - $start) * 1000, 2);
        
        curl_close($ch);
        
        return [
            'status' => $httpCode >= 200 && $httpCode < 300 ? 'online' : 'offline',
            'color' => $httpCode >= 200 && $httpCode < 300 ? 'success' : 'danger',
            'response_time' => $responseTime,
            'message' => $httpCode >= 200 && $httpCode < 300 ? 'Koneksi OK' : 'Koneksi Bermasalah'
        ];
        
    } catch (Exception $e) {
        return ['status' => 'error', 'color' => 'danger', 'message' => $e->getMessage()];
    }
}

// Fungsi untuk test koneksi Mailersend
function testMailersendConnection() {
    // [Sama dengan fungsi di atas]
    if (empty(MAILERSEND_API_TOKEN)) {
        return ['status' => 'error', 'color' => 'danger', 'message' => 'Token tidak dikonfigurasi'];
    }
    
    try {
        $start = microtime(true);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.mailersend.com/v1/domain',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . MAILERSEND_API_TOKEN],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = round((microtime(true) - $start) * 1000, 2);
        
        curl_close($ch);
        
        return [
            'status' => $httpCode >= 200 && $httpCode < 300 ? 'online' : 'offline',
            'color' => $httpCode >= 200 && $httpCode < 300 ? 'success' : 'danger',
            'response_time' => $responseTime,
            'message' => $httpCode >= 200 && $httpCode < 300 ? 'Koneksi OK' : 'Koneksi Bermasalah'
        ];
        
    } catch (Exception $e) {
        return ['status' => 'error', 'color' => 'danger', 'message' => $e->getMessage()];
    }
}

// Get current status
$result = [
    'fonnte' => testFonnteConnection(),
    'mailersend' => testMailersendConnection(),
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($result);
?>