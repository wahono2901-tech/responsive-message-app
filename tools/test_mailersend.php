<?php
/**
 * Test MailerSend Connection
 * File: tools/test_mailersend.php
 * HAPUS FILE INI SETELAH SELESAI TEST!
 */

// ============================================================================
// SEMUA USE STATEMENT HARUS DI SINI - PALING ATAS FILE
// ============================================================================
require_once __DIR__ . '/../config/config.php';

// Cek dan load PHPMailer jika tersedia
$use_phpmailer = file_exists(__DIR__ . '/../vendor/autoload.php');
if ($use_phpmailer) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Import class PHPMailer di GLOBAL SCOPE (harus di sini, bukan di dalam fungsi)
if ($use_phpmailer) {
    // Gunakan class dengan nama lengkap (FQCN) - tanpa 'use'
    // Kita tidak perlu 'use' karena kita akan panggil dengan nama lengkap
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>MailerSend Test</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow: auto; }
        .section { margin-bottom: 20px; border-bottom: 1px solid #ccc; padding-bottom: 10px; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        h2 { color: #666; font-size: 1.2rem; }
        .debug { background: #000; color: #0f0; padding: 10px; border-radius: 5px; font-size: 12px; max-height: 200px; overflow: auto; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 8px; border-bottom: 1px solid #eee; text-align: left; }
        th { background: #f0f0f0; }
        .info { background: #e7f3ff; padding: 10px; border-left: 4px solid #2196F3; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>📧 MailerSend Connection Test</h1>
        
        <div class='info'>
            <p><strong>Informasi:</strong> File ini akan menguji koneksi ke server SMTP MailerSend.</p>
            <p>Pastikan konfigurasi di file <code>config/config.php</code> sudah benar.</p>
        </div>";

// Tampilkan konfigurasi
echo "<div class='section'>";
echo "<h2>📋 Konfigurasi Saat Ini:</h2>";
echo "<table>";
echo "<tr><th>Parameter</th><th>Nilai</th></tr>";
echo "<tr><td>SMTP_HOST</td><td><code>" . (defined('SMTP_HOST') ? SMTP_HOST : '<span class="error">TIDAK DITEMUKAN</span>') . "</code></td></tr>";
echo "<tr><td>SMTP_PORT</td><td><code>" . (defined('SMTP_PORT') ? SMTP_PORT : '<span class="error">TIDAK DITEMUKAN</span>') . "</code></td></tr>";
echo "<tr><td>SMTP_USER</td><td><code>" . (defined('SMTP_USER') ? SMTP_USER : '<span class="error">TIDAK DITEMUKAN</span>') . "</code></td></tr>";
echo "<tr><td>SMTP_PASS</td><td><code>" . (defined('SMTP_PASS') ? (SMTP_PASS ? '******** (tersimpan)' : '<span class="warning">KOSONG</span>') : '<span class="error">TIDAK DITEMUKAN</span>') . "</code></td></tr>";
echo "<tr><td>SMTP_SECURE</td><td><code>" . (defined('SMTP_SECURE') ? SMTP_SECURE : '<span class="error">TIDAK DITEMUKAN</span>') . "</code></td></tr>";
echo "<tr><td>SMTP_FROM</td><td><code>" . (defined('SMTP_FROM') ? SMTP_FROM : '<span class="error">TIDAK DITEMUKAN</span>') . "</code></td></tr>";
echo "<tr><td>SMTP_FROM_NAME</td><td><code>" . (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '<span class="error">TIDAK DITEMUKAN</span>') . "</code></td></tr>";
echo "</table>";
echo "</div>";

// Test 1: Cek apakah konstanta terdefinisi
echo "<div class='section'>";
echo "<h2>🔍 Test 1: Cek Definisi Konstanta</h2>";

$all_defined = defined('SMTP_HOST') && defined('SMTP_PORT') && defined('SMTP_USER') && 
                defined('SMTP_PASS') && defined('SMTP_SECURE') && defined('SMTP_FROM');

if ($all_defined) {
    echo "<p class='success'>✓ Semua konstanta SMTP terdefinisi dengan baik</p>";
} else {
    echo "<p class='error'>✗ Ada konstanta yang tidak terdefinisi. Periksa file config/config.php</p>";
}
echo "</div>";

// Test 2: Koneksi Socket Dasar
echo "<div class='section'>";
echo "<h2>🔌 Test 2: Koneksi Socket Dasar</h2>";

if (defined('SMTP_HOST') && defined('SMTP_PORT')) {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $timeout = 5;
    
    $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
    
    if ($connection) {
        echo "<p class='success'>✓ Koneksi ke $host:$port BERHASIL</p>";
        fclose($connection);
    } else {
        echo "<p class='error'>✗ Koneksi ke $host:$port GAGAL: $errstr ($errno)</p>";
        echo "<p class='warning'>💡 Pastikan firewall tidak memblokir port $port</p>";
        echo "<p class='warning'>💡 Pastikan host '$host' dapat diakses dari server Anda</p>";
    }
} else {
    echo "<p class='error'>✗ SMTP_HOST atau SMTP_PORT tidak terdefinisi</p>";
}
echo "</div>";

// Test 3: Cek Ekstensi PHP
echo "<div class='section'>";
echo "<h2>🧩 Test 3: Ekstensi PHP</h2>";

$extensions = ['openssl', 'sockets', 'curl'];
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    echo "<p><strong>$ext</strong>: " . ($loaded ? 
        "<span class='success'>✓ Loaded</span>" : 
        "<span class='error'>✗ Not Loaded</span>") . "</p>";
}
echo "</div>";

// Test 4: Cek PHPMailer
echo "<div class='section'>";
echo "<h2>📦 Test 4: PHPMailer Availability</h2>";

if ($use_phpmailer) {
    echo "<p class='success'>✓ PHPMailer ditemukan</p>";
    
    // Cek versi PHPMailer dengan aman
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        echo "<p>Versi: " . \PHPMailer\PHPMailer\PHPMailer::VERSION . "</p>";
    }
} else {
    echo "<p class='warning'>⚠ PHPMailer tidak ditemukan.</p>";
    echo "<p>Install dengan perintah: <code>composer require phpmailer/phpmailer</code></p>";
    echo "<p>Atau download manual dari: <a href='https://github.com/PHPMailer/PHPMailer' target='_blank'>GitHub</a></p>";
}
echo "</div>";

// Test 5: Coba Kirim Email (jika PHPMailer tersedia)
if ($use_phpmailer) {
    echo "<div class='section'>";
    echo "<h2>📨 Test 5: Test Kirim Email</h2>";
    
    // Pastikan semua konstanta terdefinisi
    if (defined('SMTP_HOST') && defined('SMTP_PORT') && defined('SMTP_USER') && 
        defined('SMTP_PASS') && defined('SMTP_SECURE') && defined('SMTP_FROM')) {
        
        // Gunakan class dengan nama lengkap (Fully Qualified Class Name)
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Enable debug output
            $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function($str, $level) {
                echo "<pre class='debug'>" . htmlspecialchars($str) . "</pre>";
            };
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port = SMTP_PORT;
            $mail->Timeout = 30; // Timeout 30 detik
            
            // Recipients
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress(SMTP_FROM); // Kirim ke diri sendiri untuk test
            $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Test Email dari ' . (defined('APP_NAME') ? APP_NAME : 'Aplikasi');
            $mail->Body = '<h3>Test Email Berhasil!</h3>
                          <p>Ini adalah email test dari sistem monitoring.</p>
                          <p><strong>Waktu:</strong> ' . date('Y-m-d H:i:s') . '</p>
                          <p><strong>Server:</strong> ' . $_SERVER['SERVER_NAME'] . '</p>';
            $mail->AltBody = 'Test Email dari sistem monitoring. Waktu: ' . date('Y-m-d H:i:s');
            
            $mail->send();
            echo "<p class='success'>✓ Email test BERHASIL dikirim ke " . SMTP_FROM . "</p>";
            echo "<p class='success'>✅ Fitur MailerSend AKTIF dan berfungsi dengan baik!</p>";
            
        } catch (\Exception $e) {
            echo "<p class='error'>✗ Gagal mengirim email: " . $mail->ErrorInfo . "</p>";
            
            // Analisis error umum
            $errorMsg = $mail->ErrorInfo;
            if (strpos($errorMsg, 'Authentication') !== false) {
                echo "<p class='warning'>💡 Error autentikasi: Username atau password salah</p>";
            } elseif (strpos($errorMsg, 'Connection') !== false) {
                echo "<p class='warning'>💡 Error koneksi: Tidak bisa terhubung ke server SMTP</p>";
            } elseif (strpos($errorMsg, 'timeout') !== false) {
                echo "<p class='warning'>💡 Timeout: Server terlalu lambat merespon</p>";
            } elseif (strpos($errorMsg, 'SSL') !== false) {
                echo "<p class='warning'>💡 Error SSL: Mungkin perlu mengubah SMTP_SECURE</p>";
            }
        }
    } else {
        echo "<p class='error'>✗ Konfigurasi SMTP tidak lengkap. Periksa file config/config.php</p>";
    }
    echo "</div>";
}

// Test 6: Cek DNS Record
echo "<div class='section'>";
echo "<h2>🌐 Test 6: DNS Record</h2>";

if (defined('SMTP_HOST')) {
    $host = parse_url(SMTP_HOST, PHP_URL_HOST) ?: SMTP_HOST;
    $dns = dns_get_record($host, DNS_A | DNS_MX);
    
    if ($dns) {
        echo "<p class='success'>✓ DNS record ditemukan untuk $host</p>";
        echo "<table>";
        echo "<tr><th>Tipe</th><th>Host</th><th>Target/IP</th></tr>";
        foreach (array_slice($dns, 0, 5) as $record) {
            $target = $record['type'] == 'A' ? $record['ip'] : ($record['target'] ?? '-');
            echo "<tr><td>{$record['type']}</td><td>{$record['host']}</td><td>{$target}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>✗ DNS record TIDAK ditemukan untuk $host</p>";
        echo "<p class='warning'>💡 Pastikan hostname '$host' benar dan dapat di-resolve</p>";
    }
} else {
    echo "<p class='error'>✗ SMTP_HOST tidak terdefinisi</p>";
}
echo "</div>";

// Rekomendasi untuk MailerSend
echo "<div class='section'>";
echo "<h2>📝 Rekomendasi untuk MailerSend</h2>";
echo "<ul>";
echo "<li><strong>SMTP Host:</strong> smtp.mailersend.net</li>";
echo "<li><strong>Port:</strong> 587 (TLS) atau 465 (SSL)</li>";
echo "<li><strong>Encryption:</strong> TLS untuk port 587, SSL untuk port 465</li>";
echo "<li><strong>Username:</strong> Dari dashboard MailerSend (bukan email biasa)</li>";
echo "<li><strong>Password:</strong> Dari dashboard MailerSend</li>";
echo "<li><strong>From Email:</strong> Gunakan domain yang sudah diverifikasi di MailerSend</li>";
echo "</ul>";
echo "<p class='info'>Pastikan domain Anda sudah diverifikasi di dashboard MailerSend sebelum mengirim email.</p>";
echo "</div>";

// Kesimpulan
echo "<div class='section'>";
echo "<h2>📊 Kesimpulan</h2>";

$socket_ok = false;
if (defined('SMTP_HOST') && defined('SMTP_PORT')) {
    $connection = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 3);
    $socket_ok = ($connection !== false);
    if ($connection) fclose($connection);
}

$phpmailer_ok = $use_phpmailer;
$config_ok = $all_defined;

if ($socket_ok && $phpmailer_ok && $config_ok) {
    echo "<p class='success' style='font-size: 1.2rem;'>✅ <strong>KESIMPULAN: Fitur MailerSend SIAP DIGUNAKAN</strong></p>";
    echo "<p>Semua komponen berfungsi dengan baik. Anda dapat menggunakan fitur email di aplikasi.</p>";
} elseif ($socket_ok && !$phpmailer_ok) {
    echo "<p class='warning' style='font-size: 1.2rem;'>⚠️ <strong>KESIMPULAN: Koneksi OK tapi PHPMailer belum terinstall</strong></p>";
    echo "<p>Install PHPMailer untuk dapat mengirim email.</p>";
} elseif (!$socket_ok && $phpmailer_ok && $config_ok) {
    echo "<p class='warning' style='font-size: 1.2rem;'>⚠️ <strong>KESIMPULAN: PHPMailer terinstall tapi koneksi SMTP gagal</strong></p>";
    echo "<p>Periksa konfigurasi SMTP dan koneksi jaringan.</p>";
} elseif ($socket_ok && $phpmailer_ok && !$config_ok) {
    echo "<p class='warning' style='font-size: 1.2rem;'>⚠️ <strong>KESIMPULAN: Konfigurasi tidak lengkap</strong></p>";
    echo "<p>Lengkapi semua konstanta SMTP di file config/config.php</p>";
} else {
    echo "<p class='error' style='font-size: 1.2rem;'>❌ <strong>KESIMPULAN: Fitur MailerSend TIDAK SIAP</strong></p>";
    echo "<p>Perbaiki masalah di atas sebelum menggunakan fitur email.</p>";
}
echo "</div>";

echo "<div class='section'>";
echo "<h2>📝 Informasi Sistem</h2>";
echo "<table>";
echo "<tr><td>PHP Version</td><td>" . phpversion() . "</td></tr>";
echo "<tr><td>Server Software</td><td>" . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</td></tr>";
echo "<tr><td>Document Root</td><td>" . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "</td></tr>";
echo "<tr><td>Current Time</td><td>" . date('Y-m-d H:i:s') . "</td></tr>";
echo "<tr><td>Memory Limit</td><td>" . ini_get('memory_limit') . "</td></tr>";
echo "<tr><td>Max Execution Time</td><td>" . ini_get('max_execution_time') . " detik</td></tr>";
echo "<tr><td>File Path</td><td>" . __FILE__ . "</td></tr>";
echo "</table>";
echo "</div>";

echo "</div></body></html>";
?>