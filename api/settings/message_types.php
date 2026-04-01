<?php
/**
 * Message Types API
 * File: api/settings/message_types.php
 * 
 * Endpoints:
 * - GET: Mendapatkan daftar jenis pesan
 * - POST: Menambah/edit/menghapus jenis pesan
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

// Set header untuk JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cookie');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Verify authentication
$user = verifyAuth();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if user has admin privileges
if (!in_array($user['user_type'], ['Admin', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: Admin access required']);
    exit();
}

// Get database connection
$db = Database::getInstance()->getConnection();

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleGetMessageTypes($db);
} elseif ($method === 'POST') {
    handlePostMessageTypes($db, $user);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

/**
 * Handle GET request - Get all message types
 */
function handleGetMessageTypes($db) {
    try {
        // Get all message types with message count
        $sql = "
            SELECT 
                mt.*,
                (SELECT COUNT(*) FROM messages WHERE jenis_pesan_id = mt.id) as message_count,
                (SELECT COUNT(*) FROM messages WHERE jenis_pesan_id = mt.id AND status = 'Pending') as pending_count,
                (SELECT COUNT(*) FROM messages WHERE jenis_pesan_id = mt.id AND status = 'Disetujui') as approved_count,
                (SELECT COUNT(*) FROM messages WHERE jenis_pesan_id = mt.id AND status = 'Ditolak') as rejected_count,
                (SELECT AVG(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(mr.created_at, NOW()))) 
                 FROM messages m 
                 LEFT JOIN message_responses mr ON m.id = mr.message_id 
                 WHERE m.jenis_pesan_id = mt.id AND mr.id IS NOT NULL) as avg_response_time
            FROM message_types mt
            ORDER BY mt.id ASC
        ";
        
        $stmt = $db->query($sql);
        $messageTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format response time
        foreach ($messageTypes as &$type) {
            if ($type['avg_response_time'] !== null) {
                $type['avg_response_time_formatted'] = round($type['avg_response_time'], 1) . 'h';
            } else {
                $type['avg_response_time_formatted'] = '-';
            }
            
            // Add description fallback
            if (empty($type['deskripsi'])) {
                $type['deskripsi'] = '-';
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => $messageTypes,
            'total' => count($messageTypes)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error retrieving message types: ' . $e->getMessage()
        ]);
    }
}

/**
 * Handle POST request - Add, edit, or delete message types
 */
function handlePostMessageTypes($db, $user) {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
        return;
    }
    
    $action = isset($input['action']) ? $input['action'] : '';
    
    switch ($action) {
        case 'add_message_type':
            addMessageType($db, $user, $input);
            break;
        case 'edit_message_type':
            editMessageType($db, $user, $input);
            break;
        case 'delete_message_type':
            deleteMessageType($db, $user, $input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
}

/**
 * Add new message type
 */
function addMessageType($db, $user, $input) {
    // Validate input
    $jenisPesan = trim($input['jenis_pesan'] ?? '');
    $deskripsi = trim($input['deskripsi'] ?? '');
    $responseDeadlineHours = isset($input['response_deadline_hours']) ? intval($input['response_deadline_hours']) : 72;
    $allowExternal = isset($input['allow_external']) ? intval($input['allow_external']) : 1;
    $isActive = isset($input['is_active']) ? intval($input['is_active']) : 1;
    
    if (empty($jenisPesan)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nama jenis pesan harus diisi']);
        return;
    }
    
    // Check for duplicate
    $checkSql = "SELECT COUNT(*) as count FROM message_types WHERE jenis_pesan = :jenis_pesan";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([':jenis_pesan' => $jenisPesan]);
    $check = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($check['count'] > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Jenis pesan sudah ada']);
        return;
    }
    
    try {
        $sql = "
            INSERT INTO message_types (
                jenis_pesan, deskripsi, response_deadline_hours, 
                allow_external, is_active, created_at, updated_at
            ) VALUES (
                :jenis_pesan, :deskripsi, :response_deadline_hours,
                :allow_external, :is_active, NOW(), NOW()
            )
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':jenis_pesan' => $jenisPesan,
            ':deskripsi' => $deskripsi,
            ':response_deadline_hours' => $responseDeadlineHours,
            ':allow_external' => $allowExternal,
            ':is_active' => $isActive
        ]);
        
        $newId = $db->lastInsertId();
        
        // Log activity
        logActivity($user['id'], 'CREATE', 'message_types', $newId, null, $jenisPesan, "Created message type: $jenisPesan");
        
        echo json_encode([
            'success' => true,
            'message' => 'Jenis pesan berhasil ditambahkan',
            'id' => $newId
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error adding message type: ' . $e->getMessage()
        ]);
    }
}

/**
 * Edit existing message type
 */
function editMessageType($db, $user, $input) {
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $jenisPesan = trim($input['jenis_pesan'] ?? '');
    $deskripsi = trim($input['deskripsi'] ?? '');
    $responseDeadlineHours = isset($input['response_deadline_hours']) ? intval($input['response_deadline_hours']) : 72;
    $allowExternal = isset($input['allow_external']) ? intval($input['allow_external']) : 1;
    $isActive = isset($input['is_active']) ? intval($input['is_active']) : 1;
    
    if ($id <= 0 || empty($jenisPesan)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
        return;
    }
    
    // Check if message type exists
    $checkSql = "SELECT jenis_pesan FROM message_types WHERE id = :id";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([':id' => $id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Jenis pesan tidak ditemukan']);
        return;
    }
    
    // Check for duplicate name (excluding current)
    $checkSql = "SELECT COUNT(*) as count FROM message_types WHERE jenis_pesan = :jenis_pesan AND id != :id";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([
        ':jenis_pesan' => $jenisPesan,
        ':id' => $id
    ]);
    $check = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($check['count'] > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Jenis pesan sudah ada']);
        return;
    }
    
    try {
        $sql = "
            UPDATE message_types 
            SET 
                jenis_pesan = :jenis_pesan,
                deskripsi = :deskripsi,
                response_deadline_hours = :response_deadline_hours,
                allow_external = :allow_external,
                is_active = :is_active,
                updated_at = NOW()
            WHERE id = :id
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':jenis_pesan' => $jenisPesan,
            ':deskripsi' => $deskripsi,
            ':response_deadline_hours' => $responseDeadlineHours,
            ':allow_external' => $allowExternal,
            ':is_active' => $isActive,
            ':id' => $id
        ]);
        
        // Log activity
        logActivity($user['id'], 'UPDATE', 'message_types', $id, $existing['jenis_pesan'], $jenisPesan, "Updated message type: $jenisPesan");
        
        echo json_encode([
            'success' => true,
            'message' => 'Jenis pesan berhasil diperbarui'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error updating message type: ' . $e->getMessage()
        ]);
    }
}

/**
 * Delete message type
 */
function deleteMessageType($db, $user, $input) {
    $id = isset($input['id']) ? intval($input['id']) : 0;
    
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        return;
    }
    
    try {
        // Check if there are messages using this type
        $checkSql = "SELECT COUNT(*) as count FROM messages WHERE jenis_pesan_id = :id";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':id' => $id]);
        $check = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($check['count'] > 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'message' => 'Tidak dapat menghapus: jenis pesan ini memiliki ' . $check['count'] . ' pesan terkait'
            ]);
            return;
        }
        
        // Get name for logging before deletion
        $getNameSql = "SELECT jenis_pesan FROM message_types WHERE id = :id";
        $getNameStmt = $db->prepare($getNameSql);
        $getNameStmt->execute([':id' => $id]);
        $type = $getNameStmt->fetch(PDO::FETCH_ASSOC);
        $typeName = $type ? $type['jenis_pesan'] : 'Unknown';
        
        // Delete assignments
        $deleteAssignmentsSql = "DELETE FROM message_type_assignments WHERE message_type_id = :id";
        $deleteAssignmentsStmt = $db->prepare($deleteAssignmentsSql);
        $deleteAssignmentsStmt->execute([':id' => $id]);
        
        // Delete message type
        $deleteSql = "DELETE FROM message_types WHERE id = :id";
        $deleteStmt = $db->prepare($deleteSql);
        $deleteStmt->execute([':id' => $id]);
        
        // Log activity
        logActivity($user['id'], 'DELETE', 'message_types', $id, $typeName, null, "Deleted message type: $typeName");
        
        echo json_encode([
            'success' => true,
            'message' => 'Jenis pesan berhasil dihapus'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting message type: ' . $e->getMessage()
        ]);
    }
}

/**
 * Log activity function
 */
function logActivity($userId, $action, $table, $recordId, $oldValue, $newValue, $description) {
    try {
        $db = Database::getInstance()->getConnection();
        
        $sql = "INSERT INTO system_logs (
            user_id, action_type, table_name, record_id, 
            old_value, new_value, description, ip_address, user_agent, created_at
        ) VALUES (
            :user_id, :action, :table_name, :record_id,
            :old_value, :new_value, :description, :ip, :ua, NOW()
        )";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':table_name' => $table,
            ':record_id' => $recordId,
            ':old_value' => $oldValue,
            ':new_value' => $newValue,
            ':description' => $description,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
    }
}
?>