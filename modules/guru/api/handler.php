<?php
/**
 * API Handler for Guru Actions
 * File: modules/guru/api/handler.php
 */

require_once '../../../config/config.php';
require_once '../../../includes/auth.php';

Auth::checkAuth();

// Only specific guru types can access
$allowedTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'];
if (!in_array($_SESSION['user_type'], $allowedTypes)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

$guruId = $_SESSION['user_id'];
$guruType = $_SESSION['user_type'];
$db = Database::getInstance()->getConnection();

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_template':
            $templateId = $_GET['template_id'] ?? 0;
            
            $stmt = $db->prepare("
                SELECT * FROM response_templates 
                WHERE id = :id 
                AND (guru_type = :guru_type OR guru_type = 'ALL')
                AND is_active = 1
            ");
            $stmt->execute([
                ':id' => $templateId,
                ':guru_type' => $guruType
            ]);
            
            $template = $stmt->fetch();
            
            if ($template) {
                echo json_encode([
                    'success' => true,
                    'template' => $template
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Template tidak ditemukan'
                ]);
            }
            break;
            
        case 'submit_detailed_response':
            $messageId = $_POST['message_id'] ?? 0;
            $catatan = $_POST['catatan_respon'] ?? '';
            $status = $_POST['status'] ?? 'Diproses';
            
            if (strlen($catatan) < 10) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Catatan respons harus minimal 10 karakter'
                ]);
                exit;
            }
            
            // Update message
            $updateStmt = $db->prepare("
                UPDATE messages 
                SET status = :status, 
                    tanggal_respon = NOW(),
                    responder_id = :guru_id
                WHERE id = :message_id
            ");
            $updateStmt->execute([
                ':status' => $status,
                ':guru_id' => $guruId,
                ':message_id' => $messageId
            ]);
            
            // Insert response record
            $responseStmt = $db->prepare("
                INSERT INTO message_responses (message_id, responder_id, catatan_respon, status)
                VALUES (:message_id, :guru_id, :catatan, :status)
            ");
            $responseStmt->execute([
                ':message_id' => $messageId,
                ':guru_id' => $guruId,
                ':catatan' => $catatan,
                ':status' => $status
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Respons berhasil dikirim',
                'status' => $status
            ]);
            break;
            
        case 'quick_approve':
            $messageId = $_POST['message_id'] ?? 0;
            
            // Update message
            $updateStmt = $db->prepare("
                UPDATE messages 
                SET status = 'Disetujui', 
                    tanggal_respon = NOW(),
                    responder_id = :guru_id
                WHERE id = :message_id
            ");
            $updateStmt->execute([
                ':guru_id' => $guruId,
                ':message_id' => $messageId
            ]);
            
            // Insert response record
            $responseStmt = $db->prepare("
                INSERT INTO message_responses (message_id, responder_id, catatan_respon, status)
                VALUES (:message_id, :guru_id, :catatan, :status)
            ");
            $responseStmt->execute([
                ':message_id' => $messageId,
                ':guru_id' => $guruId,
                ':catatan' => 'Disetujui melalui aksi cepat.',
                ':status' => 'Disetujui'
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Pesan disetujui'
            ]);
            break;
            
        case 'quick_reject':
            $messageId = $_POST['message_id'] ?? 0;
            
            // Update message
            $updateStmt = $db->prepare("
                UPDATE messages 
                SET status = 'Ditolak', 
                    tanggal_respon = NOW(),
                    responder_id = :guru_id
                WHERE id = :message_id
            ");
            $updateStmt->execute([
                ':guru_id' => $guruId,
                ':message_id' => $messageId
            ]);
            
            // Insert response record
            $responseStmt = $db->prepare("
                INSERT INTO message_responses (message_id, responder_id, catatan_respon, status)
                VALUES (:message_id, :guru_id, :catatan, :status)
            ");
            $responseStmt->execute([
                ':message_id' => $messageId,
                ':guru_id' => $guruId,
                ':catatan' => 'Ditolak melalui aksi cepat.',
                ':status' => 'Ditolak'
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Pesan ditolak'
            ]);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Aksi tidak dikenali'
            ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan: ' . $e->getMessage()
    ]);
}