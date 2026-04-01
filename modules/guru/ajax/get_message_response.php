<?php
/**
 * AJAX Handler untuk mengambil response terakhir
 * File: modules/guru/ajax/get_message_response.php
 */

require_once '../../../config/config.php';
require_once '../../../includes/auth.php';

// Set header JSON
header('Content-Type: application/json');

// Check authentication
Auth::checkAuth();

// Check guru privilege
$allowedTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'];
if (!in_array($_SESSION['user_type'], $allowedTypes)) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

// Get message ID
$messageId = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;

if ($messageId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Message ID tidak valid'
    ]);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get latest response
    $stmt = $db->prepare("
        SELECT id, catatan_respon, status, created_at
        FROM message_responses 
        WHERE message_id = :message_id 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([':message_id' => $messageId]);
    $response = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($response) {
        echo json_encode([
            'success' => true,
            'response' => [
                'id' => $response['id'],
                'catatan_respon' => $response['catatan_respon'],
                'status' => $response['status'],
                'created_at' => $response['created_at']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Belum ada respons'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan: ' . $e->getMessage()
    ]);
}