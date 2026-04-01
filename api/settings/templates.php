<?php
/**
 * Response Templates API
 * File: api/settings/templates.php
 * 
 * Endpoints:
 * - GET: Mendapatkan daftar template respons
 * - POST: Menambah/mengedit/menghapus template
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
    handleGetTemplates($db);
} elseif ($method === 'POST') {
    handlePostTemplates($db, $user);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

/**
 * Handle GET request - Get all templates
 */
function handleGetTemplates($db) {
    try {
        $sql = "
            SELECT 
                id, guru_type, name, content, category, 
                default_status, is_active, use_count, 
                created_at, updated_at
            FROM response_templates 
            ORDER BY use_count DESC, name ASC
        ";
        
        $stmt = $db->query($sql);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format content preview
        foreach ($templates as &$template) {
            // Shorten content for preview
            $template['content_preview'] = strlen($template['content']) > 150 
                ? substr($template['content'], 0, 150) . '...' 
                : $template['content'];
            
            // Format guru type display
            $guruTypeDisplay = [
                'ALL' => 'Semua Guru',
                'Guru_BK' => 'Guru BK',
                'Guru_Humas' => 'Guru Humas',
                'Guru_Kurikulum' => 'Guru Kurikulum',
                'Guru_Kesiswaan' => 'Guru Kesiswaan',
                'Guru_Sarana' => 'Guru Sarana'
            ];
            $template['guru_type_display'] = $guruTypeDisplay[$template['guru_type']] ?? $template['guru_type'];
            
            // Format status display
            $template['status_display'] = $template['is_active'] ? 'Aktif' : 'Nonaktif';
            $template['status_color'] = $template['is_active'] ? 'green' : 'red';
            
            // Format default status color
            $statusColors = [
                'Disetujui' => 'green',
                'Ditolak' => 'red',
                'Diproses' => 'orange',
                'Selesai' => 'blue'
            ];
            $template['default_status_color'] = $statusColors[$template['default_status']] ?? 'grey';
        }
        
        echo json_encode([
            'success' => true,
            'data' => $templates,
            'total' => count($templates)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error retrieving templates: ' . $e->getMessage()
        ]);
    }
}

/**
 * Handle POST request - Add, edit, or delete templates
 */
function handlePostTemplates($db, $user) {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
        return;
    }
    
    $action = isset($input['action']) ? $input['action'] : '';
    
    switch ($action) {
        case 'add_template':
            addTemplate($db, $user, $input);
            break;
        case 'edit_template':
            editTemplate($db, $user, $input);
            break;
        case 'delete_template':
            deleteTemplate($db, $user, $input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
}

/**
 * Add new template
 */
function addTemplate($db, $user, $input) {
    // Validate input
    $name = trim($input['name'] ?? '');
    $content = trim($input['content'] ?? '');
    $category = trim($input['category'] ?? 'Umum');
    $defaultStatus = trim($input['default_status'] ?? 'Disetujui');
    $guruType = trim($input['guru_type'] ?? 'ALL');
    $isActive = isset($input['is_active']) ? intval($input['is_active']) : 1;
    
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nama template harus diisi']);
        return;
    }
    
    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Konten template harus diisi']);
        return;
    }
    
    if (strlen($content) < 10) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Konten template minimal 10 karakter']);
        return;
    }
    
    try {
        $sql = "
            INSERT INTO response_templates (
                name, content, category, default_status, 
                guru_type, is_active, use_count, created_at, updated_at
            ) VALUES (
                :name, :content, :category, :default_status,
                :guru_type, :is_active, 0, NOW(), NOW()
            )
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':content' => $content,
            ':category' => $category,
            ':default_status' => $defaultStatus,
            ':guru_type' => $guruType,
            ':is_active' => $isActive
        ]);
        
        $newId = $db->lastInsertId();
        
        // Log activity
        logTemplateActivity($db, $user['id'], 'CREATE', $newId, null, $name, "Created template: $name");
        
        echo json_encode([
            'success' => true,
            'message' => 'Template berhasil ditambahkan',
            'id' => $newId
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error adding template: ' . $e->getMessage()
        ]);
    }
}

/**
 * Edit existing template
 */
function editTemplate($db, $user, $input) {
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $name = trim($input['name'] ?? '');
    $content = trim($input['content'] ?? '');
    $category = trim($input['category'] ?? 'Umum');
    $defaultStatus = trim($input['default_status'] ?? 'Disetujui');
    $guruType = trim($input['guru_type'] ?? 'ALL');
    $isActive = isset($input['is_active']) ? intval($input['is_active']) : 1;
    
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        return;
    }
    
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nama template harus diisi']);
        return;
    }
    
    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Konten template harus diisi']);
        return;
    }
    
    // Check if template exists
    $checkSql = "SELECT name FROM response_templates WHERE id = :id";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([':id' => $id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Template tidak ditemukan']);
        return;
    }
    
    try {
        $sql = "
            UPDATE response_templates 
            SET 
                name = :name,
                content = :content,
                category = :category,
                default_status = :default_status,
                guru_type = :guru_type,
                is_active = :is_active,
                updated_at = NOW()
            WHERE id = :id
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':content' => $content,
            ':category' => $category,
            ':default_status' => $defaultStatus,
            ':guru_type' => $guruType,
            ':is_active' => $isActive,
            ':id' => $id
        ]);
        
        // Log activity
        logTemplateActivity($db, $user['id'], 'UPDATE', $id, $existing['name'], $name, "Updated template: $name");
        
        echo json_encode([
            'success' => true,
            'message' => 'Template berhasil diperbarui'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error updating template: ' . $e->getMessage()
        ]);
    }
}

/**
 * Delete template
 */
function deleteTemplate($db, $user, $input) {
    $id = isset($input['id']) ? intval($input['id']) : 0;
    
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        return;
    }
    
    // Get template name for logging
    $getNameSql = "SELECT name FROM response_templates WHERE id = :id";
    $getNameStmt = $db->prepare($getNameSql);
    $getNameStmt->execute([':id' => $id]);
    $template = $getNameStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Template tidak ditemukan']);
        return;
    }
    
    try {
        $sql = "DELETE FROM response_templates WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        // Log activity
        logTemplateActivity($db, $user['id'], 'DELETE', $id, $template['name'], null, "Deleted template: {$template['name']}");
        
        echo json_encode([
            'success' => true,
            'message' => 'Template berhasil dihapus'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting template: ' . $e->getMessage()
        ]);
    }
}

/**
 * Log template activity
 */
function logTemplateActivity($db, $userId, $action, $recordId, $oldValue, $newValue, $description) {
    try {
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
            ':table_name' => 'response_templates',
            ':record_id' => $recordId,
            ':old_value' => $oldValue,
            ':new_value' => $newValue,
            ':description' => $description,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Error logging template activity: " . $e->getMessage());
    }
}
?>