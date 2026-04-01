<?php
/**
 * WhatsApp Configuration
 * File: config/whatsapp_config.php
 * 
 * Konfigurasi untuk WhatsApp integration via CallMeBot
 */

// WhatsApp Settings
define('WHATSAPP_ENABLED', true);
define('WHATSAPP_API_KEY', 'YOUR_CALLMEBOT_API_KEY'); // Dapatkan dari callmebot.com
define('WHATSAPP_PHONE', '628129469754'); // Nomor tujuan default
define('WHATSAPP_API_URL', 'https://api.callmebot.com/whatsapp.php');

// API Keys untuk autentikasi endpoint
define('WHATSAPP_API_KEYS', 'test_key_123,production_key_456,debug_key_789');

// Webhook Settings (untuk menerima pesan)
define('WHATSAPP_WEBHOOK_TOKEN', 'your_webhook_verification_token');
define('WHATSAPP_WEBHOOK_URL', 'https://your-domain.com/responsive-message-app/api/whatsapp.php?action=webhook');