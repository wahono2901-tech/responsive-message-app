<?php
/**
 * Endpoint untuk mengambil CSRF token
 * File: get_csrf_token.php
 * 
 * VERSI FINAL - Berdasarkan test_csrf.php yang sudah berhasil
 */

// Aktifkan error reporting untuk debugging (tapi output tetap JSON)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error ke output

// ============================================
// INCLUDE SESSION CONFIG
// ============================================
require_once 'config/session.php';

// ============================================
// HEADER CORS - HARUS SEBELUM OUTPUT APAPUN
// ============================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-TOKEN");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// DEBUG LOGGING (ke file log, bukan ke output)
// ============================================
error_log("=== GET CSRF TOKEN ===");
error_log("Session ID: " . session_id());
error_log("Session Name: " . session_name());
error_log("CSRF Token in Session: " . ($_SESSION['csrf_token'] ?? 'NOT SET'));

// Generate token jika belum ada (sebenarnya sudah di session.php, tapi untuk jaga-jaga)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    error_log("CSRF Token baru digenerate: " . $_SESSION['csrf_token']);
}

// ============================================
// KIRIM RESPONSE JSON
// ============================================
$response = [
    'status' => 'success',
    'csrf_token' => $_SESSION['csrf_token'],
    'expires' => time() + 3600
];

// Pastikan tidak ada output sebelum json_encode
echo json_encode($response);
error_log("Response sent: " . json_encode($response));
exit();
?>