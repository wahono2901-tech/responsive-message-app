<?php
/**
 * Tool untuk Debug Layanan Eksternal
 * File: tools/test_services.php
 * 
 * HANYA UNTUK DEBUG - Hapus setelah selesai!
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html>
<head>
    <title>Service Debug Tool</title>
    <style>
        body { font-family: monospace; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; }
        .section { margin-bottom: 30px; border-bottom: 1px solid #ccc; }
    </style>
</head>
<body>
    <h1>🔧 Service Debug Tool</h1>";

// Test PHP Extensions
echo "<div class='section'>";
echo "<h2>PHP Extensions</h2>";
$extensions = ['curl', 'openssl', 'json', 'sockets', 'mysqli', 'pdo_mysql'];
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    echo "<p><strong>$ext</strong>: " . ($loaded ? "<span class='success'>✓ Loaded</span>" : "<span class='error'>✗ Not Loaded</span>") . "</p>";
}
echo "</div>";

// Test SMTP Configuration
echo "<div class='section'>";
echo "<h2>SMTP Configuration</h2>";
echo "<pre>";
echo "Host: " . (defined('SMTP_HOST') ? SMTP_HOST : '<span class="error">NOT DEFINED</span>') . "\n";
echo "Port: " . (defined('SMTP_PORT') ? SMTP_PORT : '<span class="error">NOT DEFINED</span>') . "\n";
echo "User: " . (defined('SMTP_USER') ? SMTP_USER : '<span class="error">NOT DEFINED</span>') . "\n";
echo "Pass: " . (defined('SMTP_PASS') ? (SMTP_PASS ? '<span class="success">✓ SET</span>' : '<span class="warning">⚠ EMPTY</span>') : '<span class="error">NOT DEFINED</span>') . "\n";
echo "Secure: " . (defined('SMTP_SECURE') ? SMTP_SECURE : '<span class="error">NOT DEFINED</span>') . "\n";
echo "From: " . (defined('SMTP_FROM') ? SMTP_FROM : '<span class="error">NOT DEFINED</span>') . "\n";
echo "From Name: " . (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '<span class="error">NOT DEFINED</span>') . "\n";
echo "</pre>";

// Test SMTP Connection
echo "<h3>Testing SMTP Connection...</h3>";
$host = defined('SMTP_HOST') ? SMTP_HOST : 'localhost';
$port = defined('SMTP_PORT') ? SMTP_PORT : 25;

$connection = @fsockopen($host, $port, $errno, $errstr, 5);
if ($connection) {
    echo "<p class='success'>✓ Connected to $host:$port</p>";
    fclose($connection);
} else {
    echo "<p class='error'>✗ Failed to connect: $errstr ($errno)</p>";
}
echo "</div>";

// Test Fonnte Configuration
echo "<div class='section'>";
echo "<h2>Fonnte Configuration</h2>";
echo "<pre>";
echo "API URL: " . (defined('FONNTE_API_URL') ? FONNTE_API_URL : '<span class="error">NOT DEFINED</span>') . "\n";
echo "API Key: " . (defined('FONNTE_API_KEY') ? (FONNTE_API_KEY ? '<span class="success">✓ SET</span>' : '<span class="warning">⚠ EMPTY</span>') : '<span class="error">NOT DEFINED</span>') . "\n";
echo "Phone: " . (defined('FONNTE_PHONE') ? FONNTE_PHONE : '<span class="error">NOT DEFINED</span>') . "\n";
echo "</pre>";

// Test Fonnte Connection
if (defined('FONNTE_API_URL') && defined('FONNTE_API_KEY') && FONNTE_API_KEY) {
    echo "<h3>Testing Fonnte Connection...</h3>";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => FONNTE_API_URL . '/device',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: ' . FONNTE_API_KEY]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response) {
        echo "<p class='success'>✓ Connected to Fonnte API (HTTP $httpCode)</p>";
        echo "<pre>" . htmlspecialchars(print_r(json_decode($response, true), true)) . "</pre>";
    } else {
        echo "<p class='error'>✗ Failed to connect: $error</p>";
    }
} else {
    echo "<p class='warning'>⚠ Fonnte not configured, skipping connection test</p>";
}
echo "</div>";

echo "</body></html>";
?>