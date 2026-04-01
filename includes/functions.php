<?php
/**
 * Utility Functions
 * File: includes/functions.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class Functions {
    
    /**
     * Format tanggal Indonesia
     */
    public static function formatDateIndonesia($date, $includeTime = true) {
        if (empty($date) || $date == '0000-00-00 00:00:00') return '-';
        
        $timestamp = strtotime($date);
        if ($timestamp === false) return '-';
        
        $months = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];
        
        $day = date('d', $timestamp);
        $month = $months[date('n', $timestamp) - 1];
        $year = date('Y', $timestamp);
        
        $formatted = "$day $month $year";
        
        if ($includeTime) {
            $time = date('H:i', $timestamp);
            $formatted .= " $time";
        }
        
        return $formatted;
    }
    
    /**
     * Calculate time difference in human readable format
     */
    public static function timeAgo($datetime) {
        if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return '-';
        
        $time = strtotime($datetime);
        if ($time === false) return '-';
        
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 0) {
            return 'waktu mendatang';
        } elseif ($diff < 60) {
            return 'baru saja';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return "$mins menit lalu";
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return "$hours jam lalu";
        } elseif ($diff < 2592000) {
            $days = floor($diff / 86400);
            return "$days hari lalu";
        } else {
            return self::formatDateIndonesia($datetime, false);
        }
    }
    
    /**
     * Get status badge HTML
     */
    public static function getStatusBadge($status) {
        $badges = [
            'Pending' => '<span class="badge bg-warning">Menunggu</span>',
            'Dibaca' => '<span class="badge bg-info">Dibaca</span>',
            'Diproses' => '<span class="badge bg-primary">Diproses</span>',
            'Disetujui' => '<span class="badge bg-success">Disetujui</span>',
            'Ditolak' => '<span class="badge bg-danger">Ditolak</span>',
            'Selesai' => '<span class="badge bg-secondary">Selesai</span>',
            'Expired' => '<span class="badge bg-dark">Kadaluarsa</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge bg-light text-dark">' . htmlspecialchars($status) . '</span>';
    }
    
    /**
     * Get priority badge HTML
     */
    public static function getPriorityBadge($priority) {
        $badges = [
            'Low' => '<span class="badge bg-success">Rendah</span>',
            'Medium' => '<span class="badge bg-info">Sedang</span>',
            'High' => '<span class="badge bg-warning">Tinggi</span>',
            'Urgent' => '<span class="badge bg-danger">Mendesak</span>'
        ];
        
        return $badges[$priority] ?? '<span class="badge bg-light text-dark">' . htmlspecialchars($priority) . '</span>';
    }
    
    /**
     * Calculate response time color
     */
    public static function getResponseTimeColor($createdAt, $deadlineHours = 72) {
        if (empty($createdAt)) return 'secondary';
        
        $created = strtotime($createdAt);
        if ($created === false) return 'secondary';
        
        $now = time();
        $deadline = $created + ($deadlineHours * 3600);
        $remaining = $deadline - $now;
        
        if ($remaining <= 0) {
            return 'danger'; // Expired
        } elseif ($remaining <= 24 * 3600) {
            return 'warning'; // Less than 1 day
        } elseif ($remaining <= 48 * 3600) {
            return 'info'; // Less than 2 days
        } else {
            return 'success'; // More than 2 days
        }
    }
    
    /**
     * Get response time progress
     */
    public static function getResponseTimeProgress($createdAt, $deadlineHours = 72) {
        if (empty($createdAt)) return 0;
        
        $created = strtotime($createdAt);
        if ($created === false) return 0;
        
        $now = time();
        $total = $deadlineHours * 3600;
        $elapsed = $now - $created;
        
        $percentage = ($elapsed / $total) * 100;
        return min(100, max(0, $percentage));
    }
    
    /**
     * ============================================
     * LOGGING FUNCTIONS
     * ============================================
     */
    
    /**
     * Log system activity
     * 
     * @param int|null $userId User ID performing the action
     * @param string $actionType Type of action (CREATE, UPDATE, DELETE, LOGIN, LOGOUT, etc.)
     * @param string|null $tableName Name of the affected table
     * @param int|null $recordId ID of the affected record
     * @param string|null $oldValue Old value before change
     * @param string|null $newValue New value after change
     * @param string|null $description Optional description
     * @return bool Success status
     */
    public static function logActivity($userId, $actionType, $tableName = null, $recordId = null, $oldValue = null, $newValue = null, $description = null) {
        try {
            $db = Database::getInstance()->getConnection();
            
            $ipAddress = self::getClientIP();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            // Set session variables for triggers
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['log_ip'] = $ipAddress;
            $_SESSION['log_user_agent'] = $userAgent;
            
            // Use description if provided, otherwise use new_value
            $desc = $description;
            if (empty($desc) && !empty($newValue)) {
                $desc = $newValue;
            }
            
            $query = "
                INSERT INTO system_logs (
                    user_id, action_type, table_name, record_id, 
                    old_value, new_value, description, ip_address, user_agent
                ) VALUES (
                    :user_id, :action_type, :table_name, :record_id, 
                    :old_value, :new_value, :description, :ip_address, :user_agent
                )
            ";
            
            $stmt = $db->prepare($query);
            $result = $stmt->execute([
                ':user_id' => $userId,
                ':action_type' => $actionType,
                ':table_name' => $tableName,
                ':record_id' => $recordId,
                ':old_value' => $oldValue,
                ':new_value' => $newValue,
                ':description' => $desc,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            self::logError("Error logging activity: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log login activity
     * 
     * @param int|null $userId User ID (null for failed login)
     * @param bool $success Whether login was successful
     * @param string|null $username Username attempted
     * @return bool Success status
     */
    public static function logLoginActivity($userId, $success, $username = null) {
        $actionType = $success ? 'LOGIN' : 'LOGIN_FAILED';
        $description = $success 
            ? "User logged in successfully" 
            : "Failed login attempt for user: " . ($username ?? 'unknown');
        
        return self::logActivity($userId, $actionType, null, null, null, null, $description);
    }
    
    /**
     * Log logout activity
     * 
     * @param int|null $userId User ID
     * @return bool Success status
     */
    public static function logLogoutActivity($userId) {
        return self::logActivity($userId, 'LOGOUT', null, null, null, null, "User logged out");
    }
    
    /**
     * Log CRUD operations
     * 
     * @param int $userId User ID performing the action
     * @param string $action CREATE, UPDATE, or DELETE
     * @param string $tableName Name of the affected table
     * @param int $recordId ID of the affected record
     * @param string|null $oldData Old data (JSON encoded)
     * @param string|null $newData New data (JSON encoded)
     * @return bool Success status
     */
    public static function logCrudActivity($userId, $action, $tableName, $recordId, $oldData = null, $newData = null) {
        $description = "";
        switch ($action) {
            case 'CREATE':
                $description = "Created new record in $tableName (ID: $recordId)";
                break;
            case 'UPDATE':
                $description = "Updated record in $tableName (ID: $recordId)";
                break;
            case 'DELETE':
                $description = "Deleted record from $tableName (ID: $recordId)";
                break;
            default:
                $description = "$action operation on $tableName (ID: $recordId)";
        }
        
        return self::logActivity($userId, $action, $tableName, $recordId, $oldData, $newData, $description);
    }
    
    /**
     * Log error occurrence
     * 
     * @param string $errorMessage Error message
     * @param string|null $errorCode Error code
     * @param array|null $context Additional context
     * @return bool Success status
     */
    public static function logErrorActivity($errorMessage, $errorCode = null, $context = null) {
        $description = "Error: $errorMessage";
        if ($errorCode) {
            $description .= " (Code: $errorCode)";
        }
        if ($context) {
            $description .= " - Context: " . json_encode($context);
        }
        
        return self::logActivity(null, 'ERROR', null, null, null, null, $description);
    }
    
    /**
     * Log backup activity
     * 
     * @param int $userId User ID performing backup
     * @param string $backupType Type of backup (auto, manual, database, files)
     * @param string $result Success or failure
     * @param string|null $details Additional details
     * @return bool Success status
     */
    public static function logBackupActivity($userId, $backupType, $result, $details = null) {
        $description = "Backup ($backupType): $result";
        if ($details) {
            $description .= " - $details";
        }
        
        return self::logActivity($userId, 'BACKUP', null, null, null, null, $description);
    }
    
    /**
     * Get logs for a specific user
     * 
     * @param int $userId User ID
     * @param int $limit Number of records to return
     * @param string $actionType Optional action type filter
     * @return array List of logs
     */
    public static function getUserLogs($userId, $limit = 50, $actionType = null) {
        try {
            $db = Database::getInstance()->getConnection();
            
            $sql = "
                SELECT * FROM system_logs 
                WHERE user_id = :user_id
            ";
            $params = [':user_id' => $userId];
            
            if ($actionType) {
                $sql .= " AND action_type = :action_type";
                $params[':action_type'] = $actionType;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT :limit";
            
            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            self::logError("Error getting user logs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clean old logs
     * 
     * @param int $days Keep logs from last N days
     * @return int Number of deleted records
     */
    public static function cleanOldLogs($days = 90) {
        try {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                DELETE FROM system_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            
            $deletedCount = $stmt->rowCount();
            
            self::logActivity(
                null, 
                'CLEANUP', 
                null, 
                null, 
                null, 
                null, 
                "Cleaned $deletedCount logs older than $days days"
            );
            
            return $deletedCount;
            
        } catch (Exception $e) {
            self::logError("Error cleaning old logs: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get log statistics
     * 
     * @return array Statistics data
     */
    public static function getLogStatistics() {
        try {
            $db = Database::getInstance()->getConnection();
            
            $stats = [];
            
            // Total logs
            $stmt = $db->query("SELECT COUNT(*) as total FROM system_logs");
            $stats['total_logs'] = (int)$stmt->fetch()['total'];
            
            // Logs by action type
            $stmt = $db->query("
                SELECT action_type, COUNT(*) as count 
                FROM system_logs 
                GROUP BY action_type 
                ORDER BY count DESC
            ");
            $stats['by_action'] = $stmt->fetchAll();
            
            // Logs by day (last 7 days)
            $stmt = $db->query("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as count
                FROM system_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");
            $stats['by_day'] = $stmt->fetchAll();
            
            // Most active users
            $stmt = $db->query("
                SELECT 
                    u.id,
                    u.nama_lengkap,
                    u.user_type,
                    COUNT(l.id) as activity_count
                FROM system_logs l
                LEFT JOIN users u ON l.user_id = u.id
                WHERE l.user_id IS NOT NULL
                GROUP BY l.user_id
                ORDER BY activity_count DESC
                LIMIT 10
            ");
            $stats['top_users'] = $stmt->fetchAll();
            
            return $stats;
            
        } catch (Exception $e) {
            self::logError("Error getting log statistics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Send email notification
     */
    public static function sendEmail($to, $subject, $body, $attachments = []) {
        try {
            require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';
            require_once __DIR__ . '/../libs/PHPMailer/Exception.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // SMTP configuration
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            
            // Sender & recipient
            $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            // Attachments
            foreach ($attachments as $attachment) {
                $mail->addAttachment($attachment['path'], $attachment['name']);
            }
            
            return $mail->send();
            
        } catch (Exception $e) {
            self::logError("Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send WhatsApp message via API
     */
    public static function sendWhatsApp($phone, $message) {
        if (!WHATSAPP_ENABLED) {
            return false;
        }
        
        try {
            $url = WHATSAPP_API_URL;
            $data = [
                'phone' => WHATSAPP_PHONE,
                'text' => $message,
                'apikey' => WHATSAPP_API_KEY
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
            
        } catch (Exception $e) {
            self::logError("WhatsApp Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send SMS via gateway
     */
    public static function sendSMS($phone, $message) {
        if (!SMS_ENABLED) {
            return false;
        }
        
        try {
            $url = SMS_URL . '?' . http_build_query([
                'userkey' => SMS_USERKEY,
                'passkey' => SMS_PASSKEY,
                'nohp' => $phone,
                'pesan' => urlencode($message)
            ]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            return strpos($response, 'success') !== false;
            
        } catch (Exception $e) {
            self::logError("SMS Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Upload file with validation
     */
    public static function uploadFile($file, $allowedTypes = null, $maxSize = null) {
        if ($allowedTypes === null) {
            $allowedTypes = defined('ALLOWED_FILE_TYPES') ? ALLOWED_FILE_TYPES : ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        }
        
        if ($maxSize === null) {
            $maxSize = defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : 5242880; // 5MB default
        }
        
        $result = [
            'success' => false,
            'message' => '',
            'filename' => '',
            'filepath' => ''
        ];
        
        // Check errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'File terlalu besar',
                UPLOAD_ERR_FORM_SIZE => 'File terlalu besar',
                UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian',
                UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload',
                UPLOAD_ERR_NO_TMP_DIR => 'Folder temp tidak ditemukan',
                UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file',
                UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi'
            ];
            $result['message'] = $errors[$file['error']] ?? 'Error upload tidak diketahui';
            return $result;
        }
        
        // Check size
        if ($file['size'] > $maxSize) {
            $result['message'] = 'File terlalu besar. Maksimal ' . round($maxSize / 1024 / 1024, 1) . 'MB';
            return $result;
        }
        
        // Check type
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes)) {
            $result['message'] = 'Tipe file tidak diizinkan. Hanya: ' . implode(', ', $allowedTypes);
            return $result;
        }
        
        // Generate unique filename
        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $filepath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../uploads/';
        $fullPath = $filepath . $filename;
        
        // Create upload directory if not exists
        if (!is_dir($filepath)) {
            mkdir($filepath, 0755, true);
        }
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $fullPath)) {
            // Scan for viruses (basic check)
            if (self::scanFileForViruses($fullPath)) {
                $result['success'] = true;
                $result['filename'] = $filename;
                $result['filepath'] = $fullPath;
                $result['message'] = 'Upload berhasil';
            } else {
                unlink($fullPath);
                $result['message'] = 'File terdeteksi berbahaya';
            }
        } else {
            $result['message'] = 'Gagal menyimpan file';
        }
        
        return $result;
    }
    
    /**
     * Basic virus scanning (for production, use professional solution)
     */
    private static function scanFileForViruses($filepath) {
        // Basic checks
        if (!file_exists($filepath)) {
            return false;
        }
        
        $contents = @file_get_contents($filepath, false, null, 0, 1024);
        if ($contents === false) {
            return false;
        }
        
        // Check for suspicious patterns
        $suspicious = [
            '<?php', 'eval(', 'base64_decode', 'system(', 'exec(',
            'shell_exec', 'passthru', 'assert(', 'popen(', 'proc_open'
        ];
        
        foreach ($suspicious as $pattern) {
            if (stripos($contents, $pattern) !== false) {
                self::logError("Virus scan detected: $pattern in $filepath");
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Create database backup
     */
    public static function createBackup() {
        try {
            $backupFile = (defined('BACKUP_PATH') ? BACKUP_PATH : __DIR__ . '/../backups/') . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            
            // Create backup directory if not exists
            $backupDir = dirname($backupFile);
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            // MySQL dump command
            $command = sprintf(
                'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s',
                escapeshellarg(DB_HOST),
                escapeshellarg(DB_PORT),
                escapeshellarg(DB_USER),
                escapeshellarg(DB_PASS),
                escapeshellarg(DB_NAME),
                escapeshellarg($backupFile)
            );
            
            exec($command, $output, $returnVar);
            
            if ($returnVar === 0 && file_exists($backupFile)) {
                // Compress backup
                $compressedFile = $backupFile . '.gz';
                $gz = gzopen($compressedFile, 'w9');
                gzwrite($gz, file_get_contents($backupFile));
                gzclose($gz);
                
                // Delete uncompressed file
                unlink($backupFile);
                
                // Log backup
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("
                    INSERT INTO backups (filename, filepath, size_bytes) 
                    VALUES (:filename, :filepath, :size)
                ");
                $stmt->execute([
                    ':filename' => basename($compressedFile),
                    ':filepath' => $compressedFile,
                    ':size' => filesize($compressedFile)
                ]);
                
                return ['success' => true, 'file' => $compressedFile];
            } else {
                return ['success' => false, 'error' => 'Backup failed'];
            }
            
        } catch (Exception $e) {
            self::logError("Backup Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Generate pagination HTML
     */
    public static function generatePagination($currentPage, $totalPages, $urlPattern) {
        if ($totalPages <= 1) {
            return '';
        }
        
        $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
        
        // Previous button
        if ($currentPage > 1) {
            $html .= '<li class="page-item">';
            $html .= '<a class="page-link" href="' . sprintf($urlPattern, $currentPage - 1) . '">&laquo; Prev</a>';
            $html .= '</li>';
        }
        
        // Page numbers
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);
        
        for ($i = $start; $i <= $end; $i++) {
            $active = $i == $currentPage ? ' active' : '';
            $html .= '<li class="page-item' . $active . '">';
            $html .= '<a class="page-link" href="' . sprintf($urlPattern, $i) . '">' . $i . '</a>';
            $html .= '</li>';
        }
        
        // Next button
        if ($currentPage < $totalPages) {
            $html .= '<li class="page-item">';
            $html .= '<a class="page-link" href="' . sprintf($urlPattern, $currentPage + 1) . '">Next &raquo;</a>';
            $html .= '</li>';
        }
        
        $html .= '</ul></nav>';
        return $html;
    }
    
    /**
     * Get comprehensive message statistics for a user
     */
    public static function getUserMessageStatistics($user_id) {
        $db = Database::getInstance()->getConnection();
        
        $stats = [
            'sent' => [
                'total' => 0,
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0,
                'processed' => 0,
                'completed' => 0,
                'read' => 0
            ],
            'received' => [
                'total' => 0,
                'pending_response' => 0,
                'responded' => 0
            ]
        ];
        
        try {
            // Get sent messages
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'Disetujui' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'Ditolak' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status = 'Diproses' THEN 1 ELSE 0 END) as processed,
                    SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'Dibaca' THEN 1 ELSE 0 END) as read_count
                FROM messages 
                WHERE pengirim_id = :user_id
            ");
            $stmt->execute([':user_id' => $user_id]);
            $sent = $stmt->fetch();
            
            if ($sent) {
                $stats['sent'] = [
                    'total' => (int)$sent['total'],
                    'pending' => (int)$sent['pending'],
                    'approved' => (int)$sent['approved'],
                    'rejected' => (int)$sent['rejected'],
                    'processed' => (int)$sent['processed'],
                    'completed' => (int)$sent['completed'],
                    'read' => (int)$sent['read_count']
                ];
            }
            
            // Get user type
            $stmt = $db->prepare("SELECT user_type FROM users WHERE id = :user_id");
            $stmt->execute([':user_id' => $user_id]);
            $user = $stmt->fetch();
            
            if ($user && in_array($user['user_type'], ['Admin', 'Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Wakil_Kepala', 'Kepala_Sekolah'])) {
                // Get received messages
                $stmt = $db->prepare("
                    SELECT 
                        COUNT(DISTINCT m.id) as total,
                        SUM(CASE WHEN m.status IN ('Pending', 'Dibaca', 'Diproses') THEN 1 ELSE 0 END) as pending_response,
                        COUNT(DISTINCT mr.id) as responded
                    FROM messages m
                    LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
                    LEFT JOIN message_responses mr ON m.id = mr.message_id AND mr.responder_id = :user_id
                    WHERE mt.responder_type = :user_type OR m.responder_id = :user_id
                ");
                $stmt->execute([
                    ':user_id' => $user_id,
                    ':user_type' => $user['user_type']
                ]);
                $received = $stmt->fetch();
                
                if ($received) {
                    $stats['received'] = [
                        'total' => (int)$received['total'],
                        'pending_response' => (int)$received['pending_response'],
                        'responded' => (int)$received['responded']
                    ];
                }
            }
            
        } catch (Exception $e) {
            self::logError("Error getting user message statistics: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Get all messages for a user (both sent and received)
     */
    public static function getUserAllMessages($user_id, $limit = 15) {
        $db = Database::getInstance()->getConnection();
        $messages = [];
        
        try {
            // Get user type
            $stmt = $db->prepare("SELECT user_type FROM users WHERE id = :user_id");
            $stmt->execute([':user_id' => $user_id]);
            $user = $stmt->fetch();
            
            if (!$user) return $messages;
            
            if (in_array($user['user_type'], ['Admin', 'Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Wakil_Kepala', 'Kepala_Sekolah'])) {
                // For Admin and Guru roles: Get all messages
                $stmt = $db->prepare("
                    SELECT m.*, mt.jenis_pesan,
                           CASE 
                               WHEN m.pengirim_id = :user_id THEN 'sent'
                               ELSE 'received'
                           END as direction,
                           u_sender.nama_lengkap as sender_name,
                           u_responder.nama_lengkap as responder_name
                    FROM messages m
                    LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
                    LEFT JOIN users u_sender ON m.pengirim_id = u_sender.id
                    LEFT JOIN users u_responder ON m.responder_id = u_responder.id
                    WHERE (m.pengirim_id = :user_id 
                           OR mt.responder_type = :user_type 
                           OR m.responder_id = :user_id)
                    ORDER BY m.created_at DESC
                    LIMIT :limit
                ");
                $stmt->execute([
                    ':user_id' => $user_id,
                    ':user_type' => $user['user_type'],
                    ':limit' => $limit
                ]);
            } else {
                // For other users: Get only sent messages
                $stmt = $db->prepare("
                    SELECT m.*, mt.jenis_pesan,
                           'sent' as direction,
                           NULL as sender_name,
                           u_responder.nama_lengkap as responder_name
                    FROM messages m
                    LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
                    LEFT JOIN users u_responder ON m.responder_id = u_responder.id
                    WHERE m.pengirim_id = :user_id
                    ORDER BY m.created_at DESC
                    LIMIT :limit
                ");
                $stmt->execute([
                    ':user_id' => $user_id,
                    ':limit' => $limit
                ]);
            }
            
            $messages = $stmt->fetchAll();
            
        } catch (Exception $e) {
            self::logError("Error getting user all messages: " . $e->getMessage());
        }
        
        return $messages;
    }
    
    /**
     * Get user's sent messages
     */
    public static function getUserSentMessages($user_id, $limit = 10) {
        $db = Database::getInstance()->getConnection();
        $messages = [];
        
        try {
            $stmt = $db->prepare("
                SELECT m.*, mt.jenis_pesan, u.nama_lengkap as responder_name
                FROM messages m
                LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
                LEFT JOIN users u ON m.responder_id = u.id
                WHERE m.pengirim_id = :user_id
                ORDER BY m.created_at DESC
                LIMIT :limit
            ");
            $stmt->execute([
                ':user_id' => $user_id,
                ':limit' => $limit
            ]);
            
            $messages = $stmt->fetchAll();
            
        } catch (Exception $e) {
            self::logError("Error getting user sent messages: " . $e->getMessage());
        }
        
        return $messages;
    }
    
    /**
     * Get user's received messages (for responders)
     */
    public static function getUserReceivedMessages($user_id, $limit = 10) {
        $db = Database::getInstance()->getConnection();
        $messages = [];
        
        try {
            // Get user type first
            $stmt = $db->prepare("SELECT user_type FROM users WHERE id = :user_id");
            $stmt->execute([':user_id' => $user_id]);
            $user = $stmt->fetch();
            
            if (!$user || !in_array($user['user_type'], ['Admin', 'Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Wakil_Kepala', 'Kepala_Sekolah'])) {
                return $messages;
            }
            
            $stmt = $db->prepare("
                SELECT m.*, mt.jenis_pesan, u.nama_lengkap as sender_name, mr.catatan_respon
                FROM messages m
                LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
                LEFT JOIN users u ON m.pengirim_id = u.id
                LEFT JOIN message_responses mr ON m.id = mr.message_id AND mr.responder_id = :user_id
                WHERE mt.responder_type = :user_type OR m.responder_id = :user_id
                ORDER BY m.created_at DESC
                LIMIT :limit
            ");
            $stmt->execute([
                ':user_id' => $user_id,
                ':user_type' => $user['user_type'],
                ':limit' => $limit
            ]);
            
            $messages = $stmt->fetchAll();
            
        } catch (Exception $e) {
            self::logError("Error getting user received messages: " . $e->getMessage());
        }
        
        return $messages;
    }
    
    /**
     * Get user activity summary
     */
    public static function getUserActivitySummary($user_id) {
        $db = Database::getInstance()->getConnection();
        $summary = [
            'last_login' => null,
            'last_message_sent' => null,
            'last_response_given' => null,
            'total_logins' => 0
        ];
        
        try {
            // Get last login
            $stmt = $db->prepare("
                SELECT created_at 
                FROM user_activity_logs 
                WHERE user_id = :user_id AND activity_type = 'LOGIN'
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([':user_id' => $user_id]);
            $lastLogin = $stmt->fetch();
            if ($lastLogin) {
                $summary['last_login'] = $lastLogin['created_at'];
            }
            
            // Get total logins
            $stmt = $db->prepare("
                SELECT COUNT(*) as total 
                FROM user_activity_logs 
                WHERE user_id = :user_id AND activity_type = 'LOGIN'
            ");
            $stmt->execute([':user_id' => $user_id]);
            $totalLogins = $stmt->fetch();
            if ($totalLogins) {
                $summary['total_logins'] = (int)$totalLogins['total'];
            }
            
            // Get last message sent
            $stmt = $db->prepare("
                SELECT created_at 
                FROM messages 
                WHERE pengirim_id = :user_id
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([':user_id' => $user_id]);
            $lastMessage = $stmt->fetch();
            if ($lastMessage) {
                $summary['last_message_sent'] = $lastMessage['created_at'];
            }
            
            // Get last response given
            $stmt = $db->prepare("
                SELECT created_at 
                FROM message_responses 
                WHERE responder_id = :user_id
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([':user_id' => $user_id]);
            $lastResponse = $stmt->fetch();
            if ($lastResponse) {
                $summary['last_response_given'] = $lastResponse['created_at'];
            }
            
        } catch (Exception $e) {
            self::logError("Error getting user activity summary: " . $e->getMessage());
        }
        
        return $summary;
    }
    
    /**
     * Format bytes to human readable size
     */
    public static function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * Generate random string
     */
    public static function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        return $randomString;
    }
    
    /**
     * Validate email format
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate phone number format (Indonesian)
     */
    public static function validatePhone($phone) {
        // Remove all non-digit characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Check if it's a valid Indonesian phone number
        // Starts with 08 or +62
        if (preg_match('/^(08[1-9][0-9]{7,10}|62[0-9]{9,12})$/', $phone)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Sanitize input for database
     */
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([__CLASS__, 'sanitize'], $input);
        }
        
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $input;
    }
    
    /**
     * Escape output for HTML display
     */
    public static function escape($output) {
        return htmlspecialchars($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Check if string is JSON
     */
    public static function isJson($string) {
        if (!is_string($string)) {
            return false;
        }
        
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }
    
    /**
     * Get client IP address
     */
    public static function getClientIP() {
        $ip = '';
        
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ip = $_SERVER['HTTP_FORWARDED'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return $ip;
    }
    
    /**
     * Get browser information
     */
    public static function getBrowserInfo() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $browsers = [
            'Chrome' => 'Chrome',
            'Firefox' => 'Firefox',
            'Safari' => 'Safari',
            'Opera' => 'Opera',
            'MSIE' => 'Internet Explorer',
            'Trident' => 'Internet Explorer',
            'Edge' => 'Edge'
        ];
        
        foreach ($browsers as $key => $browser) {
            if (strpos($userAgent, $key) !== false) {
                return $browser;
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Generate CSV from array
     */
    public static function generateCSV($data, $filename = 'export.csv') {
        if (empty($data)) {
            return false;
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fputs($output, $bom = chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        // Write headers if data is associative array
        if (isset($data[0]) && is_array($data[0])) {
            fputcsv($output, array_keys($data[0]));
        }
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Generate QR code
     */
    public static function generateQRCode($text, $size = 200) {
        try {
            require_once __DIR__ . '/../libs/phpqrcode/qrlib.php';
            
            $tempDir = sys_get_temp_dir();
            $filename = $tempDir . '/qrcode_' . md5($text) . '.png';
            
            QRcode::png($text, $filename, QR_ECLEVEL_L, $size / 10, 2);
            
            $imageData = file_get_contents($filename);
            unlink($filename);
            
            return 'data:image/png;base64,' . base64_encode($imageData);
            
        } catch (Exception $e) {
            self::logError("QR Code Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log error to file
     */
    public static function logError($message, $context = []) {
        $logFile = __DIR__ . '/../logs/errors_' . date('Y-m-d') . '.log';
        
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        
        $logEntry = date('Y-m-d H:i:s') . ' - ' . $message;
        
        if (!empty($context)) {
            $logEntry .= ' - Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        $logEntry .= PHP_EOL;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * Get server load information
     */
    public static function getServerLoad() {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return [
                '1min' => $load[0],
                '5min' => $load[1],
                '15min' => $load[2]
            ];
        }
        
        return ['1min' => 0, '5min' => 0, '15min' => 0];
    }
    
    /**
     * Check if running in development mode
     */
    public static function isDevelopment() {
        return defined('ENVIRONMENT') && ENVIRONMENT === 'development';
    }
    
    /**
     * Get current URL
     */
    public static function getCurrentUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }
    
    /**
     * Redirect with flash message
     */
    public static function redirect($url, $message = null, $type = 'success') {
        if ($message !== null) {
            $_SESSION['flash_message'] = $message;
            $_SESSION['flash_type'] = $type;
        }
        
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Get flash message
     */
    public static function getFlashMessage() {
        if (isset($_SESSION['flash_message'])) {
            $message = $_SESSION['flash_message'];
            $type = $_SESSION['flash_type'] ?? 'info';
            
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            
            return [
                'message' => $message,
                'type' => $type
            ];
        }
        
        return null;
    }
}
?>