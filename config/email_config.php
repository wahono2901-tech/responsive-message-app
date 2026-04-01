<?php
/**
 * Email Configuration dengan SMTP Gmail
 * File: config/email_config.php
 */

// Email Settings
define('EMAIL_ENABLED', true);
define('EMAIL_USE_SMTP', true); // Gunakan SMTP
define('EMAIL_FROM_EMAIL', 'noreply@smkn12jakarta.sch.id');
define('EMAIL_FROM_NAME', 'Responsive Message App - SMKN 12 Jakarta');

// SMTP Configuration untuk Gmail
define('EMAIL_SMTP_HOST', 'smtp.gmail.com');
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_USER', 'agung.senen3@gmail.com'); // Ganti dengan email Anda
define('EMAIL_SMTP_PASS', 'YOUR_APP_PASSWORD'); // BUKAN password biasa, tapi App Password
define('EMAIL_SMTP_ENCRYPTION', 'tls'); // tls or ssl

// API Keys
define('EMAIL_API_KEYS', 'test_key_123,production_key_456');