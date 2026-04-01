<?php
/**
 * Send Scheduled Notifications
 * File: cron/notifications.php
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Only allow execution via CLI or cron
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    die('Access denied');
}

// Verify cron key
if (isset($_GET['cron_key']) && $_GET['cron_key'] !== CRON_SECRET_KEY) {
    die('Invalid cron key');
}

$db = Database::getInstance()->getConnection();

try {
    // 1. Send reminders for pending messages (24 hours before deadline)
    sendPendingReminders();
    
    // 2. Send daily reports to admin
    sendDailyReports();
    
    // 3. Send weekly summaries
    if (date('w') == 1) { // Monday
        sendWeeklySummaries();
    }
    
    // 4. Send monthly reports
    if (date('j') == 1) { // First day of month
        sendMonthlyReports();
    }
    
    // 5. Send urgent message alerts
    sendUrgentAlerts();
    
    echo "Notifications sent successfully" . PHP_EOL;
    
} catch (Exception $e) {
    error_log("Notification Error: " . $e->getMessage());
    echo "Notifications failed: " . $e->getMessage() . PHP_EOL;
}

/**
 * Send reminders for pending messages
 */
function sendPendingReminders() {
    global $db;
    
    $sql = "
        SELECT m.*, mt.jenis_pesan, mt.response_deadline_hours,
               u.email, u.phone_number, u.nama_lengkap as pengirim_nama,
               ur.email as responder_email, ur.phone_number as responder_phone,
               ur.nama_lengkap as responder_nama
        FROM messages m
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN users ur ON ur.user_type = mt.responder_type AND ur.is_active = 1
        WHERE m.status IN ('Pending', 'Dibaca', 'Diproses')
        AND TIMESTAMPDIFF(HOUR, m.created_at, NOW()) >= (mt.response_deadline_hours - 24)
        AND TIMESTAMPDIFF(HOUR, m.created_at, NOW()) < mt.response_deadline_hours
        AND m.last_reminder_sent IS NULL
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $messages = $stmt->fetchAll();
    
    foreach ($messages as $message) {
        // Send email reminder to responder
        if (!empty($message['responder_email'])) {
            sendReminderEmail($message, 'responder');
        }
        
        // Send WhatsApp reminder to responder
        if (WHATSAPP_ENABLED && !empty($message['responder_phone'])) {
            sendReminderWhatsApp($message, 'responder');
        }
        
        // Update last reminder sent
        $updateSql = "
            UPDATE messages 
            SET last_reminder_sent = NOW() 
            WHERE id = :message_id
        ";
        
        $updateStmt = $db->prepare($updateSql);
        $updateStmt->execute([':message_id' => $message['id']]);
    }
}