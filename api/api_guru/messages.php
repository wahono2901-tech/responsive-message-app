<?php
/**
 * Messages API for Guru
 * File: api/api_guru/messages.php
 * Endpoints:
 * - GET /api/api_guru/messages.php?action=list&status=all&page=1&limit=20
 * - GET /api/api_guru/messages.php?action=detail&id=123
 * - POST /api/api_guru/messages.php?action=respond
 * - POST /api/api_guru/messages.php?action=quick_approve
 * - POST /api/api_guru/messages.php?action=quick_reject
 * - POST /api/api_guru/messages.php?action=delete
 * - GET /api/api_guru/messages.php?action=attachments&id=123
 */

require_once 'config.php';

$user = authenticateGuru();
validateGuruAccess($user);

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        getMessagesList($user);
        break;
    case 'detail':
        getMessageDetail($user);
        break;
    case 'respond':
        respondToMessage($user);
        break;
    case 'quick_approve':
        quickApproveMessage($user);
        break;
    case 'quick_reject':
        quickRejectMessage($user);
        break;
    case 'delete':
        deleteMessage($user);
        break;
    case 'attachments':
        getMessageAttachments($user);
        break;
    default:
        sendResponse(false, null, 'Invalid action', 400);
}

function getMessagesList($user) {
    $db = Database::getInstance()->getConnection();
    $guruId = $user['id'];
    $guruType = $user['user_type'];
    
    $statusFilter = $_GET['status'] ?? 'all';
    $priorityFilter = $_GET['priority'] ?? 'all';
    $sourceFilter = $_GET['source'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;
    
    // Map guru type to message type
    $typeMap = [
        'Guru_BK' => 'Konsultasi/Konseling',
        'Guru_Humas' => 'Kehumasan',
        'Guru_Kurikulum' => 'Kurikulum',
        'Guru_Kesiswaan' => 'Kesiswaan',
        'Guru_Sarana' => 'Sarana Prasarana',
        'Guru' => 'Umum',
        'Admin' => 'Administrasi',
        'Wakil_Kepala' => 'Manajemen',
        'Kepala_Sekolah' => 'Kepemimpinan'
    ];
    
    $assignedType = $typeMap[$guruType] ?? '';
    
    // Get message type ID
    $typeStmt = $db->prepare("SELECT id FROM message_types WHERE jenis_pesan = :jenis_pesan");
    $typeStmt->execute([':jenis_pesan' => $assignedType]);
    $messageType = $typeStmt->fetch();
    $messageTypeId = $messageType ? $messageType['id'] : 0;
    
    // Build where conditions
    $whereConditions = ["(m.jenis_pesan_id = :type_id OR EXISTS (SELECT 1 FROM message_responses mr WHERE mr.message_id = m.id AND mr.responder_id = :guru_id))"];
    $params = [
        ':type_id' => $messageTypeId,
        ':guru_id' => $guruId
    ];
    
    if ($statusFilter === 'pending') {
        $whereConditions[] = "m.status IN ('Pending', 'Dibaca', 'Diproses')";
    } elseif ($statusFilter === 'completed') {
        $whereConditions[] = "m.status IN ('Disetujui', 'Ditolak', 'Selesai')";
    } elseif ($statusFilter !== 'all') {
        $whereConditions[] = "m.status = :status";
        $params[':status'] = $statusFilter;
    }
    
    if ($priorityFilter !== 'all') {
        $whereConditions[] = "m.priority = :priority";
        $params[':priority'] = $priorityFilter;
    }
    
    if ($sourceFilter === 'internal') {
        $whereConditions[] = "m.is_external = 0";
    } elseif ($sourceFilter === 'external') {
        $whereConditions[] = "m.is_external = 1";
    }
    
    if (!empty($search)) {
        $whereConditions[] = "(m.isi_pesan LIKE :search OR u.nama_lengkap LIKE :search OR es.nama_lengkap LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) as total 
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN external_senders es ON m.external_sender_id = es.id
        WHERE $whereClause
    ";
    
    $countStmt = $db->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];
    $totalPages = ceil($total / $limit);
    
    // Get messages
    $sql = "
        SELECT 
            m.*,
            m.is_external,
            m.reference_number,
            CASE 
                WHEN m.is_external = 1 THEN es.nama_lengkap
                ELSE COALESCE(u.nama_lengkap, m.pengirim_nama, 'Unknown')
            END as sender_name,
            CASE 
                WHEN m.is_external = 1 THEN 'External'
                ELSE COALESCE(u.user_type, 'Internal')
            END as sender_type,
            mt.jenis_pesan as message_type_name,
            TIMESTAMPDIFF(HOUR, m.created_at, NOW()) as hours_since_created,
            GREATEST(0, mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) as hours_remaining,
            CASE 
                WHEN TIMESTAMPDIFF(HOUR, m.created_at, NOW()) >= mt.response_deadline_hours THEN 'danger'
                WHEN (mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) <= 24 THEN 'warning'
                ELSE 'success'
            END as urgency_color,
            CASE WHEN mr.id IS NOT NULL THEN 1 ELSE 0 END as has_response,
            mr.catatan_respon as last_response,
            (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id) as attachment_count,
            (SELECT COUNT(*) FROM wakepsek_reviews WHERE message_id = m.id) as review_count,
            (SELECT reviewer_type FROM wakepsek_reviews WHERE message_id = m.id ORDER BY created_at DESC LIMIT 1) as last_reviewer_type
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN external_senders es ON m.external_sender_id = es.id
        LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
        LEFT JOIN message_responses mr ON m.id = mr.message_id AND mr.responder_id = :guru_id AND mr.created_at = (SELECT MAX(created_at) FROM message_responses WHERE message_id = m.id)
        WHERE $whereClause
        ORDER BY 
            CASE 
                WHEN m.status IN ('Pending', 'Dibaca', 'Diproses') THEN 1
                ELSE 2
            END,
            CASE m.priority
                WHEN 'Urgent' THEN 1
                WHEN 'High' THEN 2
                WHEN 'Medium' THEN 3
                WHEN 'Low' THEN 4
                ELSE 5
            END,
            m.created_at DESC
        LIMIT :offset, :limit
    ";
    
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':guru_id', $guruId, PDO::PARAM_INT);
    $stmt->execute();
    
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendResponse(true, [
        'messages' => $messages,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages
        ]
    ]);
}

function getMessageDetail($user) {
    $db = Database::getInstance()->getConnection();
    $guruId = $user['id'];
    $messageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($messageId <= 0) {
        sendResponse(false, null, 'Invalid message ID', 400);
    }
    
    try {
        $sql = "
            SELECT 
                m.*,
                m.is_external,
                m.reference_number,
                CASE 
                    WHEN m.is_external = 1 THEN es.nama_lengkap
                    ELSE COALESCE(u.nama_lengkap, m.pengirim_nama, 'Unknown')
                END as sender_name,
                CASE 
                    WHEN m.is_external = 1 THEN 'External'
                    ELSE COALESCE(u.user_type, 'Internal')
                END as sender_type,
                CASE 
                    WHEN m.is_external = 1 THEN es.email
                    ELSE u.email
                END as sender_email,
                CASE 
                    WHEN m.is_external = 1 THEN es.phone_number
                    ELSE u.phone_number
                END as sender_phone,
                CASE 
                    WHEN m.is_external = 1 THEN es.identitas
                    ELSE CONCAT(u.kelas, ' ', u.jurusan)
                END as sender_info,
                mt.jenis_pesan as message_type_name,
                mt.response_deadline_hours,
                TIMESTAMPDIFF(HOUR, m.created_at, NOW()) as hours_since_created,
                GREATEST(0, mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) as hours_remaining,
                CASE 
                    WHEN TIMESTAMPDIFF(HOUR, m.created_at, NOW()) >= mt.response_deadline_hours THEN 'danger'
                    WHEN (mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) <= 24 THEN 'warning'
                    ELSE 'success'
                END as urgency_color,
                mr.id as response_id,
                mr.catatan_respon as response_content,
                mr.status as response_status,
                mr.created_at as response_date,
                ru.nama_lengkap as responder_name,
                wr.id as review_id,
                reviewer.nama_lengkap as reviewer_name,
                reviewer.user_type as reviewer_type,
                wr.catatan as review_notes,
                wr.created_at as review_date,
                (SELECT COUNT(*) FROM message_attachments WHERE message_id = m.id) as attachment_count
            FROM messages m
            LEFT JOIN users u ON m.pengirim_id = u.id
            LEFT JOIN external_senders es ON m.external_sender_id = es.id
            LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
            LEFT JOIN message_responses mr ON m.id = mr.message_id AND mr.responder_id = :guru_id AND mr.created_at = (SELECT MAX(created_at) FROM message_responses WHERE message_id = m.id)
            LEFT JOIN users ru ON mr.responder_id = ru.id
            LEFT JOIN wakepsek_reviews wr ON m.id = wr.message_id
            LEFT JOIN users reviewer ON wr.reviewer_id = reviewer.id
            WHERE m.id = :message_id
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':message_id' => $messageId,
            ':guru_id' => $guruId
        ]);
        
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$message) {
            sendResponse(false, null, 'Message not found', 404);
        }
        
        // Get attachments
        $attachStmt = $db->prepare("SELECT * FROM message_attachments WHERE message_id = :message_id ORDER BY created_at ASC");
        $attachStmt->execute([':message_id' => $messageId]);
        $attachments = $attachStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $message['attachments'] = $attachments;
        
        sendResponse(true, ['message' => $message]);
        
    } catch (Exception $e) {
        error_log("Message detail error: " . $e->getMessage());
        sendResponse(false, null, 'Failed to load message detail', 500);
    }
}

function respondToMessage($user) {
    $db = Database::getInstance()->getConnection();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
    $status = $input['status'] ?? '';
    $catatan = $input['catatan_respon'] ?? '';
    
    if ($messageId <= 0) {
        sendResponse(false, null, 'Invalid message ID', 400);
    }
    
    if (empty($status) || empty($catatan)) {
        sendResponse(false, null, 'Status and response notes are required', 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Insert response
        $responseStmt = $db->prepare("
            INSERT INTO message_responses (message_id, responder_id, catatan_respon, status, created_at)
            VALUES (:message_id, :responder_id, :catatan, :status, NOW())
        ");
        $responseStmt->execute([
            ':message_id' => $messageId,
            ':responder_id' => $user['id'],
            ':catatan' => $catatan,
            ':status' => $status
        ]);
        
        // Update message
        $updateStmt = $db->prepare("
            UPDATE messages 
            SET status = :status, 
                responder_id = :responder_id,
                tanggal_respon = NOW(),
                updated_at = NOW()
            WHERE id = :message_id
        ");
        $updateStmt->execute([
            ':status' => $status,
            ':responder_id' => $user['id'],
            ':message_id' => $messageId
        ]);
        
        $db->commit();
        
        sendResponse(true, ['response_id' => $db->lastInsertId()], 'Response sent successfully');
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Response error: " . $e->getMessage());
        sendResponse(false, null, 'Failed to send response', 500);
    }
}

function quickApproveMessage($user) {
    quickActionMessage($user, 'Disetujui', 'Disetujui melalui aksi cepat.');
}

function quickRejectMessage($user) {
    quickActionMessage($user, 'Ditolak', 'Ditolak melalui aksi cepat.');
}

function quickActionMessage($user, $status, $catatan) {
    $db = Database::getInstance()->getConnection();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
    
    if ($messageId <= 0) {
        sendResponse(false, null, 'Invalid message ID', 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Insert response
        $responseStmt = $db->prepare("
            INSERT INTO message_responses (message_id, responder_id, catatan_respon, status, created_at)
            VALUES (:message_id, :responder_id, :catatan, :status, NOW())
        ");
        $responseStmt->execute([
            ':message_id' => $messageId,
            ':responder_id' => $user['id'],
            ':catatan' => $catatan,
            ':status' => $status
        ]);
        
        // Update message
        $updateStmt = $db->prepare("
            UPDATE messages 
            SET status = :status, 
                responder_id = :responder_id,
                tanggal_respon = NOW(),
                updated_at = NOW()
            WHERE id = :message_id
        ");
        $updateStmt->execute([
            ':status' => $status,
            ':responder_id' => $user['id'],
            ':message_id' => $messageId
        ]);
        
        $db->commit();
        
        sendResponse(true, null, "Message {$status} successfully");
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Quick action error: " . $e->getMessage());
        sendResponse(false, null, "Failed to {$status} message", 500);
    }
}

function deleteMessage($user) {
    $db = Database::getInstance()->getConnection();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
    $deleteReason = isset($input['delete_reason']) ? trim($input['delete_reason']) : '';
    $confirmDelete = isset($input['confirm_delete']) && $input['confirm_delete'] === 'yes';
    
    if ($messageId <= 0) {
        sendResponse(false, null, 'Invalid message ID', 400);
    }
    
    if (!$confirmDelete) {
        sendResponse(false, null, 'You must confirm deletion', 400);
    }
    
    if (empty($deleteReason)) {
        sendResponse(false, null, 'Delete reason is required', 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Check if message belongs to this guru's type
        $checkStmt = $db->prepare("
            SELECT m.*, mt.jenis_pesan 
            FROM messages m
            LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
            WHERE m.id = :message_id
        ");
        $checkStmt->execute([':message_id' => $messageId]);
        $message = $checkStmt->fetch();
        
        if (!$message) {
            sendResponse(false, null, 'Message not found', 404);
        }
        
        // Get attachments to delete files later
        $attachStmt = $db->prepare("SELECT filepath, filename FROM message_attachments WHERE message_id = :message_id");
        $attachStmt->execute([':message_id' => $messageId]);
        $attachments = $attachStmt->fetchAll();
        
        // Log deletion
        $logStmt = $db->prepare("
            INSERT INTO message_deletion_log 
            (message_id, deleted_by, deleted_by_type, delete_reason, message_data, has_response, original_status, attachment_count, created_at)
            VALUES (:message_id, :deleted_by, :deleted_by_type, :delete_reason, :message_data, :has_response, :original_status, :attachment_count, NOW())
        ");
        
        $messageData = json_encode([
            'isi_pesan' => $message['isi_pesan'],
            'jenis_pesan' => $message['jenis_pesan'],
            'is_external' => $message['is_external'],
            'priority' => $message['priority']
        ]);
        
        $logStmt->execute([
            ':message_id' => $messageId,
            ':deleted_by' => $user['id'],
            ':deleted_by_type' => $user['user_type'],
            ':delete_reason' => $deleteReason,
            ':message_data' => $messageData,
            ':has_response' => $message['responder_id'] ? 1 : 0,
            ':original_status' => $message['status'],
            ':attachment_count' => count($attachments)
        ]);
        
        // Delete responses
        $db->prepare("DELETE FROM message_responses WHERE message_id = :message_id")->execute([':message_id' => $messageId]);
        
        // Delete reviews
        $db->prepare("DELETE FROM wakepsek_reviews WHERE message_id = :message_id")->execute([':message_id' => $messageId]);
        
        // Delete attachments from DB
        $db->prepare("DELETE FROM message_attachments WHERE message_id = :message_id")->execute([':message_id' => $messageId]);
        
        // Delete message
        $db->prepare("DELETE FROM messages WHERE id = :message_id")->execute([':message_id' => $messageId]);
        
        $db->commit();
        
        // Delete physical files
        foreach ($attachments as $attachment) {
            $filePath = ROOT_PATH . '/' . ltrim($attachment['filepath'], '/');
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        
        sendResponse(true, null, 'Message deleted successfully');
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Delete error: " . $e->getMessage());
        sendResponse(false, null, 'Failed to delete message', 500);
    }
}

function getMessageAttachments($user) {
    $db = Database::getInstance()->getConnection();
    $messageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($messageId <= 0) {
        sendResponse(false, null, 'Invalid message ID', 400);
    }
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM message_attachments 
            WHERE message_id = :message_id 
            ORDER BY created_at ASC
        ");
        $stmt->execute([':message_id' => $messageId]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add full URLs for images
        foreach ($attachments as &$att) {
            $att['url'] = BASE_URL . '/' . $att['filepath'];
            $att['thumbnail_url'] = BASE_URL . '/' . $att['filepath'];
        }
        
        sendResponse(true, ['attachments' => $attachments]);
        
    } catch (Exception $e) {
        error_log("Attachments error: " . $e->getMessage());
        sendResponse(false, null, 'Failed to load attachments', 500);
    }
}
?>