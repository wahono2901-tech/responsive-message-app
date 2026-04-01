<?php
/**
 * Dashboard API for Guru
 * File: api/api_guru/dashboard.php
 * Endpoints:
 * - GET /api/api_guru/dashboard.php?action=stats&time=all
 * - GET /api/api_guru/dashboard.php?action=trends&time=all
 * - GET /api/api_guru/dashboard.php?action=recent&time=all&limit=20
 */

require_once 'config.php';

$user = authenticateGuru();
validateGuruAccess($user);

$action = $_GET['action'] ?? '';
$timeFilter = $_GET['time'] ?? 'all';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

switch ($action) {
    case 'stats':
        getDashboardStats($user, $timeFilter);
        break;
    case 'trends':
        getMessageTrends($user, $timeFilter);
        break;
    case 'recent':
        getRecentActivity($user, $timeFilter, $limit);
        break;
    default:
        sendResponse(false, null, 'Invalid action', 400);
}

function getDashboardStats($user, $timeFilter) {
    $db = Database::getInstance()->getConnection();
    $guruId = $user['id'];
    $guruType = $user['user_type'];
    
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
    
    // Date filter
    $startDate = '1970-01-01';
    if ($timeFilter !== 'all') {
        switch ($timeFilter) {
            case '7days':
                $startDate = date('Y-m-d', strtotime('-7 days'));
                break;
            case '30days':
                $startDate = date('Y-m-d', strtotime('-30 days'));
                break;
            case '90days':
                $startDate = date('Y-m-d', strtotime('-90 days'));
                break;
            case 'year':
                $startDate = date('Y-m-d', strtotime('-1 year'));
                break;
        }
    }
    
    $stats = [
        'total_assigned' => 0,
        'pending' => 0,
        'dibaca' => 0,
        'diproses' => 0,
        'disetujui' => 0,
        'ditolak' => 0,
        'selesai' => 0,
        'expired' => 0,
        'avg_response_time' => 0,
        'total_responses' => 0
    ];
    
    try {
        // Pesan yang direspons oleh guru
        $sql1 = "
            SELECT m.id, m.status, m.created_at, mr.created_at as response_date
            FROM messages m
            INNER JOIN message_responses mr ON m.id = mr.message_id AND mr.responder_id = :guru_id
            WHERE m.created_at >= :start_date
        ";
        
        // Pesan berdasarkan jenis pesan
        $sql2 = "
            SELECT m.id, m.status, m.created_at, NULL as response_date
            FROM messages m
            WHERE m.jenis_pesan_id = :type_id AND m.created_at >= :start_date
        ";
        
        $params = [
            ':guru_id' => $guruId,
            ':type_id' => $messageTypeId,
            ':start_date' => $startDate
        ];
        
        $stmt1 = $db->prepare($sql1);
        $stmt1->execute([':guru_id' => $guruId, ':start_date' => $startDate]);
        $messagesFromResponses = $stmt1->fetchAll();
        
        $messagesFromType = [];
        if ($messageTypeId > 0) {
            $stmt2 = $db->prepare($sql2);
            $stmt2->execute([':type_id' => $messageTypeId, ':start_date' => $startDate]);
            $messagesFromType = $stmt2->fetchAll();
        }
        
        // Gabungkan dan hilangkan duplikat
        $allMessagesById = [];
        foreach ($messagesFromResponses as $msg) {
            $allMessagesById[$msg['id']] = $msg;
        }
        foreach ($messagesFromType as $msg) {
            if (!isset($allMessagesById[$msg['id']])) {
                $allMessagesById[$msg['id']] = $msg;
            }
        }
        
        $allMessages = array_values($allMessagesById);
        $stats['total_assigned'] = count($allMessages);
        
        foreach ($allMessages as $msg) {
            switch ($msg['status']) {
                case 'Pending': $stats['pending']++; break;
                case 'Dibaca': $stats['dibaca']++; break;
                case 'Diproses': $stats['diproses']++; break;
                case 'Disetujui': $stats['disetujui']++; break;
                case 'Ditolak': $stats['ditolak']++; break;
                case 'Selesai': $stats['selesai']++; break;
            }
            
            if (in_array($msg['status'], ['Pending', 'Dibaca', 'Diproses'])) {
                $created = strtotime($msg['created_at']);
                if ((time() - $created) > 72 * 3600) {
                    $stats['expired']++;
                }
            }
            
            if (!empty($msg['response_date'])) {
                $stats['total_responses']++;
                $responseTime = (strtotime($msg['response_date']) - strtotime($msg['created_at'])) / 3600;
                $stats['avg_response_time'] += $responseTime;
            }
        }
        
        if ($stats['total_responses'] > 0) {
            $stats['avg_response_time'] = round($stats['avg_response_time'] / $stats['total_responses'], 1);
        }
        
        // Get review statistics
        $reviewStats = [
            'total_responded' => $stats['total_assigned'],
            'reviewed_by_wakepsek' => 0,
            'reviewed_by_kepsek' => 0,
            'pending_review' => 0
        ];
        
        $reviewSql = "
            SELECT COUNT(DISTINCT m.id) as reviewed
            FROM messages m
            INNER JOIN wakepsek_reviews wr ON m.id = wr.message_id
            INNER JOIN users reviewer ON wr.reviewer_id = reviewer.id
            WHERE reviewer.user_type = :reviewer_type
            AND m.responder_id = :guru_id
            AND m.created_at >= :start_date
        ";
        
        $reviewStmt = $db->prepare($reviewSql);
        $reviewStmt->execute([':reviewer_type' => 'Wakil_Kepala', ':guru_id' => $guruId, ':start_date' => $startDate]);
        $reviewStats['reviewed_by_wakepsek'] = (int)$reviewStmt->fetch()['reviewed'];
        
        $reviewStmt->execute([':reviewer_type' => 'Kepala_Sekolah', ':guru_id' => $guruId, ':start_date' => $startDate]);
        $reviewStats['reviewed_by_kepsek'] = (int)$reviewStmt->fetch()['reviewed'];
        
        $reviewStats['reviewed_total'] = $reviewStats['reviewed_by_wakepsek'] + $reviewStats['reviewed_by_kepsek'];
        $reviewStats['pending_review'] = $stats['total_assigned'] - $reviewStats['reviewed_total'];
        
        sendResponse(true, [
            'stats' => $stats,
            'review_stats' => $reviewStats,
            'message_type' => $assignedType,
            'guru_name' => $user['nama_lengkap'],
            'guru_type' => $guruType
        ]);
        
    } catch (Exception $e) {
        error_log("Dashboard stats error: " . $e->getMessage());
        sendResponse(false, null, 'Failed to load dashboard data', 500);
    }
}

function getMessageTrends($user, $timeFilter) {
    $db = Database::getInstance()->getConnection();
    $guruId = $user['id'];
    $guruType = $user['user_type'];
    
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
    
    $typeStmt = $db->prepare("SELECT id FROM message_types WHERE jenis_pesan = :jenis_pesan");
    $typeStmt->execute([':jenis_pesan' => $assignedType]);
    $messageType = $typeStmt->fetch();
    $messageTypeId = $messageType ? $messageType['id'] : 0;
    
    $startDate = '1970-01-01';
    if ($timeFilter !== 'all') {
        switch ($timeFilter) {
            case '7days':
                $startDate = date('Y-m-d', strtotime('-7 days'));
                break;
            case '30days':
                $startDate = date('Y-m-d', strtotime('-30 days'));
                break;
            case '90days':
                $startDate = date('Y-m-d', strtotime('-90 days'));
                break;
            case 'year':
                $startDate = date('Y-m-d', strtotime('-1 year'));
                break;
        }
    }
    
    try {
        $trendsSql = "
            SELECT 
                DATE(m.created_at) as date,
                SUM(CASE WHEN m.status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN m.status = 'Dibaca' THEN 1 ELSE 0 END) as dibaca_count,
                SUM(CASE WHEN m.status = 'Diproses' THEN 1 ELSE 0 END) as diproses_count,
                SUM(CASE WHEN m.status = 'Disetujui' THEN 1 ELSE 0 END) as disetujui_count,
                SUM(CASE WHEN m.status = 'Ditolak' THEN 1 ELSE 0 END) as ditolak_count,
                SUM(CASE WHEN m.status = 'Selesai' THEN 1 ELSE 0 END) as selesai_count
            FROM messages m
            WHERE (
                m.jenis_pesan_id = :type_id 
                OR EXISTS (SELECT 1 FROM message_responses mr WHERE mr.message_id = m.id AND mr.responder_id = :guru_id)
            )
            AND m.created_at >= :start_date
            GROUP BY DATE(m.created_at)
            ORDER BY date ASC
        ";
        
        $stmt = $db->prepare($trendsSql);
        $stmt->execute([
            ':guru_id' => $guruId,
            ':type_id' => $messageTypeId,
            ':start_date' => $startDate
        ]);
        
        $trends = $stmt->fetchAll();
        
        $labels = [];
        $pendingData = [];
        $dibacaData = [];
        $diprosesData = [];
        $disetujuiData = [];
        $ditolakData = [];
        $selesaiData = [];
        
        foreach ($trends as $trend) {
            $labels[] = date('d M', strtotime($trend['date']));
            $pendingData[] = (int)$trend['pending_count'];
            $dibacaData[] = (int)$trend['dibaca_count'];
            $diprosesData[] = (int)$trend['diproses_count'];
            $disetujuiData[] = (int)$trend['disetujui_count'];
            $ditolakData[] = (int)$trend['ditolak_count'];
            $selesaiData[] = (int)$trend['selesai_count'];
        }
        
        sendResponse(true, [
            'labels' => $labels,
            'pending' => $pendingData,
            'dibaca' => $dibacaData,
            'diproses' => $diprosesData,
            'disetujui' => $disetujuiData,
            'ditolak' => $ditolakData,
            'selesai' => $selesaiData
        ]);
        
    } catch (Exception $e) {
        error_log("Trends error: " . $e->getMessage());
        sendResponse(false, null, 'Failed to load trends data', 500);
    }
}

function getRecentActivity($user, $timeFilter, $limit) {
    $db = Database::getInstance()->getConnection();
    $guruId = $user['id'];
    $guruType = $user['user_type'];
    
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
    
    $typeStmt = $db->prepare("SELECT id FROM message_types WHERE jenis_pesan = :jenis_pesan");
    $typeStmt->execute([':jenis_pesan' => $assignedType]);
    $messageType = $typeStmt->fetch();
    $messageTypeId = $messageType ? $messageType['id'] : 0;
    
    $startDate = '1970-01-01';
    if ($timeFilter !== 'all') {
        switch ($timeFilter) {
            case '7days':
                $startDate = date('Y-m-d', strtotime('-7 days'));
                break;
            case '30days':
                $startDate = date('Y-m-d', strtotime('-30 days'));
                break;
            case '90days':
                $startDate = date('Y-m-d', strtotime('-90 days'));
                break;
            case 'year':
                $startDate = date('Y-m-d', strtotime('-1 year'));
                break;
        }
    }
    
    try {
        $sql = "
            SELECT 
                m.id as message_id,
                m.isi_pesan as content,
                m.status,
                m.created_at as message_date,
                m.tanggal_respon,
                COALESCE(u.nama_lengkap, m.pengirim_nama, 'Pengirim Tidak Dikenal') as sender_name,
                COALESCE(u.user_type, 'Internal') as sender_type,
                COALESCE(u.nis_nip, m.pengirim_nis_nip, '-') as sender_info,
                mr.id as response_id,
                mr.catatan_respon as response_content,
                mr.created_at as response_date,
                (SELECT COUNT(*) FROM wakepsek_reviews WHERE message_id = m.id) as review_count,
                (SELECT catatan FROM wakepsek_reviews WHERE message_id = m.id ORDER BY created_at DESC LIMIT 1) as latest_review
            FROM messages m
            LEFT JOIN users u ON m.pengirim_id = u.id
            LEFT JOIN message_responses mr ON m.id = mr.message_id AND mr.responder_id = :guru_id
            WHERE (
                m.jenis_pesan_id = :type_id 
                OR EXISTS (SELECT 1 FROM message_responses mr2 WHERE mr2.message_id = m.id AND mr2.responder_id = :guru_id2)
            )
            AND m.created_at >= :start_date
            ORDER BY m.created_at DESC
            LIMIT :limit
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':guru_id', $guruId, PDO::PARAM_INT);
        $stmt->bindValue(':guru_id2', $guruId, PDO::PARAM_INT);
        $stmt->bindValue(':type_id', $messageTypeId, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse(true, ['activities' => $activities]);
        
    } catch (Exception $e) {
        error_log("Recent activity error: " . $e->getMessage());
        sendResponse(false, null, 'Failed to load recent activity', 500);
    }
}
?>