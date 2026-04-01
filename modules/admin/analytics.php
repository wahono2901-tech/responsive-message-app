<?php
/**
 * Admin Analytics Dashboard - PROFESSIONAL COMPLETE VERSION
 * File: modules/admin/analytics.php
 * 
 * ✅ SEMUA GRAFIK LENGKAP (5 Charts)
 * ✅ EXPORT PDF, EXCEL, CSV PROFESIONAL
 * ✅ ANTI INFINITE LOOP - Fixed Height, Destroy Charts
 * ✅ ERROR HANDLING LENGKAP
 * ✅ PHP 8.2 COMPATIBLE
 * 
 * @author Responsive Message App
 * @version 4.0.0 - Complete Professional
 */

// ============================================
// ERROR REPORTING (MATIKAN DI PRODUCTION)
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// ============================================
// INITIALIZATION & VALIDATION
// ============================================
while (ob_get_level()) ob_end_clean();
ob_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

Auth::checkAuth();
if ($_SESSION['user_type'] !== 'Admin' && $_SESSION['privilege_level'] !== 'Full_Access') {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

// ============================================
// GLOBAL SCOPE - USE STATEMENTS
// ============================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Chart\Layout;

// ============================================
// DATE RANGE HANDLER
// ============================================
$datePreset = $_GET['preset'] ?? 'last30days';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

if (empty($startDate) || empty($endDate)) {
    switch ($datePreset) {
        case 'today': $startDate = date('Y-m-d'); $endDate = date('Y-m-d'); break;
        case 'yesterday': $startDate = date('Y-m-d', strtotime('-1 day')); $endDate = date('Y-m-d', strtotime('-1 day')); break;
        case 'last7days': $startDate = date('Y-m-d', strtotime('-7 days')); $endDate = date('Y-m-d'); break;
        case 'last30days': $startDate = date('Y-m-d', strtotime('-30 days')); $endDate = date('Y-m-d'); break;
        case 'last90days': $startDate = date('Y-m-d', strtotime('-90 days')); $endDate = date('Y-m-d'); break;
        case 'thisMonth': $startDate = date('Y-m-01'); $endDate = date('Y-m-d'); break;
        case 'lastMonth': $startDate = date('Y-m-01', strtotime('-1 month')); $endDate = date('Y-m-t', strtotime('-1 month')); break;
        case 'thisYear': $startDate = date('Y-01-01'); $endDate = date('Y-m-d'); break;
        default: $startDate = date('Y-m-d', strtotime('-30 days')); $endDate = date('Y-m-d');
    }
}

if (strtotime($startDate) > strtotime($endDate)) {
    $temp = $startDate; $startDate = $endDate; $endDate = $temp;
}

$pageTitle = 'Analytics Dashboard - Professional';
$db = Database::getInstance()->getConnection();

// ============================================
// COMPLETE ANALYTICS FUNCTIONS
// ============================================

/**
 * Get system overview with trend analysis
 */
function getSystemOverview($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $sql = "SELECT 
                    COUNT(*) as total_messages,
                    COUNT(DISTINCT pengirim_id) as unique_senders,
                    COUNT(DISTINCT CASE WHEN is_external = 1 THEN external_sender_id END) as external_senders,
                    COUNT(DISTINCT responder_id) as unique_responders,
                    SUM(CASE WHEN is_external = 1 THEN 1 ELSE 0 END) as external_messages,
                    AVG(CASE WHEN tanggal_respon IS NOT NULL 
                        THEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) 
                        ELSE NULL END) as avg_response_time,
                    COUNT(CASE WHEN status IN ('Disetujui', 'Selesai') THEN 1 END) as resolved_messages
                FROM messages 
                WHERE created_at BETWEEN ? AND ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$startDateTime, $endDateTime]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Previous period for trend
        $periodDays = max(1, (strtotime($endDate) - strtotime($startDate)) / 86400);
        $prevStartDate = date('Y-m-d', strtotime($startDate . ' - ' . $periodDays . ' days'));
        $prevEndDate = date('Y-m-d', strtotime($endDate . ' - ' . $periodDays . ' days'));
        
        $prevStartDateTime = $prevStartDate . ' 00:00:00';
        $prevEndDateTime = $prevEndDate . ' 23:59:59';
        
        $sql = "SELECT COUNT(*) as total FROM messages WHERE created_at BETWEEN ? AND ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$prevStartDateTime, $prevEndDateTime]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $trend = ($previous['total'] ?? 0) > 0 
            ? round(((($current['total_messages'] ?? 0) - ($previous['total'] ?? 0)) / ($previous['total'] ?? 1)) * 100, 1)
            : 0;
        
        return [
            'total_messages' => (int)($current['total_messages'] ?? 0),
            'unique_senders' => (int)($current['unique_senders'] ?? 0),
            'external_senders' => (int)($current['external_senders'] ?? 0),
            'unique_responders' => (int)($current['unique_responders'] ?? 0),
            'external_messages' => (int)($current['external_messages'] ?? 0),
            'avg_response_time' => round((float)($current['avg_response_time'] ?? 0), 1),
            'resolved_messages' => (int)($current['resolved_messages'] ?? 0),
            'trend_percentage' => $trend,
            'trend_direction' => $trend >= 0 ? 'up' : 'down'
        ];
    } catch (PDOException $e) {
        error_log("Error in getSystemOverview: " . $e->getMessage());
        return [
            'total_messages' => 0, 'unique_senders' => 0, 'external_senders' => 0,
            'unique_responders' => 0, 'external_messages' => 0, 'avg_response_time' => 0,
            'resolved_messages' => 0, 'trend_percentage' => 0, 'trend_direction' => 'up'
        ];
    }
}

/**
 * Get message status distribution with colors
 */
function getMessageStatusStats($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $totalSql = "SELECT COUNT(*) as total FROM messages WHERE created_at BETWEEN ? AND ?";
        $totalStmt = $db->prepare($totalSql);
        $totalStmt->execute([$startDateTime, $endDateTime]);
        $totalMessages = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 1;
        
        $sql = "SELECT 
                    COALESCE(status, 'Unknown') as status,
                    COUNT(*) as total
                FROM messages 
                WHERE created_at BETWEEN ? AND ?
                GROUP BY status
                ORDER BY total DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$startDateTime, $endDateTime]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$row) {
            $row['percentage'] = $totalMessages > 0 ? round(($row['total'] / $totalMessages) * 100, 1) : 0;
            $row['color'] = match($row['status']) {
                'Pending' => '#ffc107',
                'Disetujui' => '#28a745',
                'Ditolak' => '#dc3545',
                'Diproses' => '#0d6efd',
                'Dibaca' => '#17a2b8',
                'Selesai' => '#6c757d',
                'Expired' => '#212529',
                default => '#6c757d'
            };
        }
        
        return $results;
    } catch (PDOException $e) {
        error_log("Error in getMessageStatusStats: " . $e->getMessage());
        return [];
    }
}

/**
 * Get message priority distribution with colors
 */
function getMessagePriorityStats($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $totalSql = "SELECT COUNT(*) as total FROM messages WHERE created_at BETWEEN ? AND ?";
        $totalStmt = $db->prepare($totalSql);
        $totalStmt->execute([$startDateTime, $endDateTime]);
        $totalMessages = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 1;
        
        $sql = "SELECT 
                    COALESCE(priority, 'Normal') as priority,
                    COUNT(*) as total
                FROM messages 
                WHERE created_at BETWEEN ? AND ?
                GROUP BY priority
                ORDER BY 
                    CASE COALESCE(priority, 'Normal')
                        WHEN 'Urgent' THEN 1
                        WHEN 'High' THEN 2
                        WHEN 'Medium' THEN 3
                        WHEN 'Low' THEN 4
                        ELSE 5
                    END";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$startDateTime, $endDateTime]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$row) {
            $row['percentage'] = $totalMessages > 0 ? round(($row['total'] / $totalMessages) * 100, 1) : 0;
            $row['color'] = match($row['priority']) {
                'Urgent' => '#dc3545',
                'High' => '#fd7e14',
                'Medium' => '#ffc107',
                'Low' => '#28a745',
                default => '#6c757d'
            };
        }
        
        return $results;
    } catch (PDOException $e) {
        error_log("Error in getMessagePriorityStats: " . $e->getMessage());
        return [];
    }
}

/**
 * Get message type analytics
 */
function getMessageTypeAnalytics($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $sql = "SELECT 
                    mt.jenis_pesan,
                    COUNT(m.id) as total,
                    AVG(CASE WHEN m.tanggal_respon IS NOT NULL 
                        THEN TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) 
                        ELSE NULL END) as avg_response_time,
                    SUM(CASE WHEN m.is_external = 1 THEN 1 ELSE 0 END) as external_count,
                    SUM(CASE WHEN m.status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as resolved_count
                FROM message_types mt
                LEFT JOIN messages m ON mt.id = m.jenis_pesan_id 
                    AND m.created_at BETWEEN ? AND ?
                GROUP BY mt.id, mt.jenis_pesan
                HAVING total > 0
                ORDER BY total DESC
                LIMIT 10";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$startDateTime, $endDateTime]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getMessageTypeAnalytics: " . $e->getMessage());
        return [];
    }
}

/**
 * Get teacher performance metrics with SLA
 */
function getTeacherPerformance($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $sql = "SELECT 
                    u.id,
                    u.nama_lengkap,
                    u.user_type,
                    COUNT(DISTINCT m.id) as messages_handled,
                    COUNT(DISTINCT mr.id) as responses_given,
                    AVG(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(mr.created_at, m.tanggal_respon, m.created_at))) as avg_response_time,
                    SUM(CASE WHEN m.status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as resolved_messages,
                    COUNT(DISTINCT CASE WHEN m.is_external = 1 THEN m.id END) as external_handled,
                    ROUND(AVG(CASE 
                        WHEN m.tanggal_respon IS NOT NULL 
                        AND TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) <= COALESCE(mt.response_deadline_hours, 72)
                        THEN 1 ELSE 0 END) * 100, 1) as sla_compliance
                FROM users u
                LEFT JOIN messages m ON u.id = m.responder_id 
                    AND m.created_at BETWEEN ? AND ?
                LEFT JOIN message_responses mr ON u.id = mr.responder_id 
                    AND mr.created_at BETWEEN ? AND ?
                LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
                WHERE u.user_type LIKE 'Guru_%'
                GROUP BY u.id, u.nama_lengkap, u.user_type
                HAVING messages_handled > 0
                ORDER BY resolved_messages DESC, messages_handled DESC
                LIMIT 10";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $startDateTime, $endDateTime,
            $startDateTime, $endDateTime
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getTeacherPerformance: " . $e->getMessage());
        return [];
    }
}

/**
 * Get external senders analytics
 */
function getExternalSendersAnalytics($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $sql = "SELECT 
                    COUNT(DISTINCT es.id) as total_senders,
                    COUNT(m.id) as total_messages,
                    AVG(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, m.created_at))) as avg_response_time,
                    SUM(CASE WHEN m.status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as resolved_messages,
                    COUNT(DISTINCT m.jenis_pesan_id) as message_types_used
                FROM external_senders es
                LEFT JOIN messages m ON es.id = m.external_sender_id 
                    AND m.created_at BETWEEN ? AND ?
                WHERE es.created_at BETWEEN ? AND ?
                    OR m.created_at BETWEEN ? AND ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $startDateTime, $endDateTime,
            $startDateTime, $endDateTime,
            $startDateTime, $endDateTime
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_senders' => (int)($result['total_senders'] ?? 0),
            'total_messages' => (int)($result['total_messages'] ?? 0),
            'avg_response_time' => round((float)($result['avg_response_time'] ?? 0), 1),
            'resolved_messages' => (int)($result['resolved_messages'] ?? 0),
            'message_types_used' => (int)($result['message_types_used'] ?? 0)
        ];
    } catch (PDOException $e) {
        error_log("Error in getExternalSendersAnalytics: " . $e->getMessage());
        return [
            'total_senders' => 0, 'total_messages' => 0, 'avg_response_time' => 0,
            'resolved_messages' => 0, 'message_types_used' => 0
        ];
    }
}

/**
 * Get SLA compliance analytics
 */
function getSLACompliance($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $sql = "SELECT 
                    COUNT(*) as total_resolved,
                    SUM(CASE 
                        WHEN TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) <= 
                            COALESCE(mt.response_deadline_hours, 72) 
                        THEN 1 ELSE 0 
                    END) as within_sla,
                    AVG(CASE 
                        WHEN TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) > 
                            COALESCE(mt.response_deadline_hours, 72) 
                        THEN TIMESTAMPDIFF(HOUR, m.created_at, m.tanggal_respon) 
                        ELSE NULL 
                    END) as avg_overdue_hours,
                    COUNT(DISTINCT m.responder_id) as responders_count
                FROM messages m
                LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
                WHERE m.created_at BETWEEN ? AND ?
                    AND m.status IN ('Disetujui', 'Ditolak', 'Selesai')
                    AND m.tanggal_respon IS NOT NULL";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$startDateTime, $endDateTime]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $complianceRate = ($result['total_resolved'] ?? 0) > 0 
            ? round(($result['within_sla'] ?? 0) / ($result['total_resolved'] ?? 1) * 100, 1)
            : 0;
        
        return [
            'total_resolved' => (int)($result['total_resolved'] ?? 0),
            'within_sla' => (int)($result['within_sla'] ?? 0),
            'compliance_rate' => $complianceRate,
            'avg_overdue_hours' => round((float)($result['avg_overdue_hours'] ?? 0), 1),
            'responders_count' => (int)($result['responders_count'] ?? 0)
        ];
    } catch (PDOException $e) {
        error_log("Error in getSLACompliance: " . $e->getMessage());
        return [
            'total_resolved' => 0, 'within_sla' => 0, 'compliance_rate' => 0,
            'avg_overdue_hours' => 0, 'responders_count' => 0
        ];
    }
}

/**
 * Get daily trends with moving average
 */
function getDailyTrends($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $sql = "SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as total_messages,
                    SUM(CASE WHEN is_external = 1 THEN 1 ELSE 0 END) as external_messages,
                    COUNT(DISTINCT pengirim_id) as unique_senders,
                    AVG(CASE WHEN tanggal_respon IS NOT NULL 
                        THEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) 
                        ELSE NULL END) as avg_response_time,
                    SUM(CASE WHEN status IN ('Disetujui', 'Selesai') THEN 1 ELSE 0 END) as resolved_messages
                FROM messages 
                WHERE created_at BETWEEN ? AND ?
                GROUP BY DATE(created_at)
                ORDER BY date ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$startDateTime, $endDateTime]);
        $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate 7-day moving average
        $movingAverage = [];
        foreach ($trends as $i => $trend) {
            $sum = 0;
            $count = 0;
            for ($j = max(0, $i - 6); $j <= $i; $j++) {
                $sum += (int)($trends[$j]['total_messages'] ?? 0);
                $count++;
            }
            $movingAverage[] = $count > 0 ? round($sum / $count, 1) : 0;
        }
        
        return [
            'daily' => $trends,
            'moving_avg' => $movingAverage
        ];
    } catch (PDOException $e) {
        error_log("Error in getDailyTrends: " . $e->getMessage());
        return ['daily' => [], 'moving_avg' => []];
    }
}

/**
 * Get user growth analytics
 */
function getUserGrowth($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $sql = "SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as new_users,
                    SUM(CASE WHEN user_type LIKE 'Guru_%' THEN 1 ELSE 0 END) as new_teachers,
                    SUM(CASE WHEN user_type = 'Siswa' THEN 1 ELSE 0 END) as new_students
                FROM users 
                WHERE created_at BETWEEN ? AND ?
                GROUP BY DATE(created_at)
                ORDER BY date ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$startDateTime, $endDateTime]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getUserGrowth: " . $e->getMessage());
        return [];
    }
}

/**
 * Get response time distribution
 */
function getResponseTimeDistribution($db, $startDate, $endDate) {
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime = $endDate . ' 23:59:59';
    
    try {
        $totalSql = "SELECT COUNT(*) as total FROM messages WHERE created_at BETWEEN ? AND ? AND tanggal_respon IS NOT NULL";
        $totalStmt = $db->prepare($totalSql);
        $totalStmt->execute([$startDateTime, $endDateTime]);
        $totalResponses = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 1;
        
        $sql = "SELECT 
                    CASE 
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 1 THEN '0-1 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 6 THEN '1-6 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 12 THEN '6-12 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 24 THEN '12-24 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 48 THEN '24-48 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 72 THEN '48-72 jam'
                        ELSE '>72 jam'
                    END as response_range,
                    COUNT(*) as total
                FROM messages 
                WHERE created_at BETWEEN ? AND ?
                    AND tanggal_respon IS NOT NULL
                GROUP BY 
                    CASE 
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 1 THEN '0-1 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 6 THEN '1-6 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 12 THEN '6-12 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 24 THEN '12-24 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 48 THEN '24-48 jam'
                        WHEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) <= 72 THEN '48-72 jam'
                        ELSE '>72 jam'
                    END
                ORDER BY 
                    CASE response_range
                        WHEN '0-1 jam' THEN 1
                        WHEN '1-6 jam' THEN 2
                        WHEN '6-12 jam' THEN 3
                        WHEN '12-24 jam' THEN 4
                        WHEN '24-48 jam' THEN 5
                        WHEN '48-72 jam' THEN 6
                        ELSE 7
                    END";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$startDateTime, $endDateTime]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$row) {
            $row['percentage'] = $totalResponses > 0 ? round(($row['total'] / $totalResponses) * 100, 1) : 0;
        }
        
        return $results;
    } catch (PDOException $e) {
        error_log("Error in getResponseTimeDistribution: " . $e->getMessage());
        return [];
    }
}

// ============================================
// GET ALL ANALYTICS DATA
// ============================================
$systemOverview = getSystemOverview($db, $startDate, $endDate);
$messageStatusStats = getMessageStatusStats($db, $startDate, $endDate);
$messagePriorityStats = getMessagePriorityStats($db, $startDate, $endDate);
$messageTypeStats = getMessageTypeAnalytics($db, $startDate, $endDate);
$teacherPerformance = getTeacherPerformance($db, $startDate, $endDate);
$externalSenders = getExternalSendersAnalytics($db, $startDate, $endDate);
$slaCompliance = getSLACompliance($db, $startDate, $endDate);
$dailyTrends = getDailyTrends($db, $startDate, $endDate);
$userGrowth = getUserGrowth($db, $startDate, $endDate);
$responseTimeDist = getResponseTimeDistribution($db, $startDate, $endDate);

// ============================================
// PREPARE CHART DATA (LIMIT 14 DAYS)
// ============================================
$chartLabels = [];
$chartMessages = [];
$chartExternal = [];
$chartMovingAvg = [];
$chartResolved = [];

if (!empty($dailyTrends['daily'])) {
    $dailyData = array_slice($dailyTrends['daily'], -14);
    $chartDates = array_column($dailyData, 'date');
    $chartMessages = array_column($dailyData, 'total_messages');
    $chartExternal = array_column($dailyData, 'external_messages');
    $chartResolved = array_column($dailyData, 'resolved_messages');
    $chartMovingAvg = array_slice($dailyTrends['moving_avg'], -14);
    
    foreach ($chartDates as $date) {
        $chartLabels[] = date('d M', strtotime($date));
    }
}

// User Growth Data
$userGrowthLabels = [];
$userGrowthTeachers = [];
$userGrowthStudents = [];

if (!empty($userGrowth)) {
    $userData = array_slice($userGrowth, -14);
    $userDates = array_column($userData, 'date');
    $userGrowthTeachers = array_column($userData, 'new_teachers');
    $userGrowthStudents = array_column($userData, 'new_students');
    
    foreach ($userDates as $date) {
        $userGrowthLabels[] = date('d M', strtotime($date));
    }
}

// ============================================
// EXPORT HANDLER
// ============================================
if (isset($_GET['export']) && !empty($_GET['export'])) {
    $exportFormat = $_GET['export'];
    
    switch ($exportFormat) {
        case 'pdf':
            exportAnalyticsPDF([
                'system' => $systemOverview,
                'status' => $messageStatusStats,
                'priority' => $messagePriorityStats,
                'types' => $messageTypeStats,
                'teachers' => $teacherPerformance,
                'external' => $externalSenders,
                'sla' => $slaCompliance,
                'response' => $responseTimeDist
            ], $startDate, $endDate);
            break;
        case 'excel':
            exportAnalyticsExcel([
                'system' => $systemOverview,
                'status' => $messageStatusStats,
                'priority' => $messagePriorityStats,
                'types' => $messageTypeStats,
                'teachers' => $teacherPerformance,
                'external' => $externalSenders,
                'sla' => $slaCompliance,
                'response' => $responseTimeDist
            ], $startDate, $endDate);
            break;
        case 'csv':
            exportAnalyticsCSV([
                'system' => $systemOverview,
                'status' => $messageStatusStats,
                'priority' => $messagePriorityStats,
                'teachers' => $teacherPerformance
            ], $startDate, $endDate);
            break;
    }
    exit;
}

// ============================================
// PROFESSIONAL PDF EXPORT
// ============================================
function exportAnalyticsPDF($data, $startDate, $endDate) {
    $fpdfPath = $_SERVER['DOCUMENT_ROOT'] . '/responsive-message-app/vendor/fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) {
        die('FPDF tidak ditemukan. Download dari http://www.fpdf.org');
    }
    require_once $fpdfPath;
    
    while (ob_get_level()) ob_end_clean();
    
    class PDF extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 18);
            $this->SetTextColor(13, 110, 253);
            $this->Cell(0, 10, APP_NAME, 0, 1, 'C');
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(108, 117, 125);
            $this->Cell(0, 8, 'ANALYTICS DASHBOARD REPORT', 0, 1, 'C');
            $this->Ln(5);
            $this->SetDrawColor(13, 110, 253);
            $this->SetLineWidth(0.5);
            $this->Line(15, $this->GetY(), 285, $this->GetY());
            $this->Ln(10);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(108, 117, 125);
            $this->Cell(0, 6, 'Generated by ' . APP_NAME . ' - ' . date('d/m/Y H:i:s'), 0, 1, 'C');
            $this->Cell(0, 6, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
        }
        
        function SectionTitle($title, $color = [13, 110, 253]) {
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor($color[0], $color[1], $color[2]);
            $this->Cell(0, 10, $title, 0, 1, 'L');
            $this->SetDrawColor($color[0], $color[1], $color[2]);
            $this->SetLineWidth(0.3);
            $this->Line(15, $this->GetY(), 100, $this->GetY());
            $this->Ln(6);
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 10);
        }
        
        function TableHeader($headers, $widths, $color = [13, 110, 253]) {
            $this->SetFillColor($color[0], $color[1], $color[2]);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 9);
            for ($i = 0; $i < count($headers); $i++) {
                $this->Cell($widths[$i], 10, $headers[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);
        }
        
        function TableRow($data, $widths, $isEven = false) {
            if ($isEven) {
                $this->SetFillColor(248, 249, 250);
                $fill = true;
            } else {
                $fill = false;
            }
            for ($i = 0; $i < count($data); $i++) {
                $this->Cell($widths[$i], 8, (string)$data[$i], 1, 0, 
                           ($i == 0 ? 'L' : 'C'), $fill);
            }
            $this->Ln();
        }
    }
    
    $pdf = new PDF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->SetMargins(15, 15, 15);
    
    // Header Info
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(50, 7, 'Periode:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(100, 7, date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(40, 7, 'Cetak:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 7, date('d/m/Y H:i:s'), 0, 1, 'L');
    $pdf->Ln(5);
    
    // SYSTEM OVERVIEW
    $pdf->SectionTitle('SYSTEM OVERVIEW', [13, 110, 253]);
    $sys = $data['system'];
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(70, 7, 'Total Messages: ' . number_format($sys['total_messages']), 0, 0);
    $pdf->Cell(70, 7, 'Resolved: ' . number_format($sys['resolved_messages']), 0, 0);
    $pdf->Cell(70, 7, 'Avg Response: ' . $sys['avg_response_time'] . 'h', 0, 1);
    $pdf->Cell(70, 7, 'External Senders: ' . number_format($sys['external_senders']), 0, 0);
    $pdf->Cell(70, 7, 'External Msgs: ' . number_format($sys['external_messages']), 0, 0);
    $pdf->Cell(70, 7, 'Responders: ' . number_format($sys['unique_responders']), 0, 1);
    $pdf->Ln(5);
    
    // SLA COMPLIANCE
    $sla = $data['sla'];
    $pdf->Cell(70, 7, 'SLA Compliance: ' . $sla['compliance_rate'] . '%', 0, 0);
    $pdf->Cell(70, 7, 'Within SLA: ' . $sla['within_sla'] . '/' . $sla['total_resolved'], 0, 0);
    $pdf->Cell(70, 7, 'Overdue Avg: ' . $sla['avg_overdue_hours'] . 'h', 0, 1);
    $pdf->Ln(10);
    
    // MESSAGE STATUS
    if (!empty($data['status'])) {
        $pdf->SectionTitle('MESSAGE STATUS', [13, 110, 253]);
        $headers = ['Status', 'Jumlah', 'Persentase'];
        $widths = [100, 50, 50];
        $pdf->TableHeader($headers, $widths, [13, 110, 253]);
        
        $total = array_sum(array_column($data['status'], 'total'));
        foreach ($data['status'] as $i => $row) {
            $percentage = $total > 0 ? round($row['total'] / $total * 100, 1) : 0;
            $pdf->TableRow([
                $row['status'],
                number_format($row['total']),
                $percentage . '%'
            ], $widths, ($i % 2 == 0));
        }
        $pdf->Ln(10);
    }
    
    // MESSAGE PRIORITY
    if (!empty($data['priority'])) {
        $pdf->SectionTitle('MESSAGE PRIORITY', [255, 193, 7]);
        $pdf->TableHeader($headers, $widths, [255, 193, 7]);
        
        $total = array_sum(array_column($data['priority'], 'total'));
        foreach ($data['priority'] as $i => $row) {
            $percentage = $total > 0 ? round($row['total'] / $total * 100, 1) : 0;
            $pdf->TableRow([
                $row['priority'],
                number_format($row['total']),
                $percentage . '%'
            ], $widths, ($i % 2 == 0));
        }
        $pdf->Ln(10);
    }
    
    // RESPONSE TIME DISTRIBUTION
    if (!empty($data['response'])) {
        $pdf->SectionTitle('RESPONSE TIME', [23, 162, 184]);
        $pdf->TableHeader($headers, $widths, [23, 162, 184]);
        
        $total = array_sum(array_column($data['response'], 'total'));
        foreach ($data['response'] as $i => $row) {
            $percentage = $total > 0 ? round($row['total'] / $total * 100, 1) : 0;
            $pdf->TableRow([
                $row['response_range'],
                number_format($row['total']),
                $percentage . '%'
            ], $widths, ($i % 2 == 0));
        }
        $pdf->Ln(10);
    }
    
    // MESSAGE TYPES
    if (!empty($data['types'])) {
        $pdf->AddPage();
        $pdf->SectionTitle('MESSAGE TYPES', [13, 110, 253]);
        
        $headers2 = ['Jenis Pesan', 'Total', 'External', 'Resolved', 'Avg Resp'];
        $widths2 = [70, 35, 35, 35, 45];
        $pdf->TableHeader($headers2, $widths2, [13, 110, 253]);
        
        foreach ($data['types'] as $i => $row) {
            $pdf->TableRow([
                substr($row['jenis_pesan'] ?? '-', 0, 30),
                $row['total'] ?? 0,
                $row['external_count'] ?? 0,
                $row['resolved_count'] ?? 0,
                $row['avg_response_time'] ? number_format($row['avg_response_time'], 1) . 'h' : '-'
            ], $widths2, ($i % 2 == 0));
        }
        $pdf->Ln(10);
    }
    
    // TOP TEACHERS
    if (!empty($data['teachers'])) {
        $pdf->SectionTitle('TOP PERFORMING TEACHERS', [40, 167, 69]);
        
        $headers3 = ['No', 'Nama Guru', 'Tipe', 'Msg', 'Resp', 'Avg', 'Resolved', 'SLA'];
        $widths3 = [10, 55, 40, 25, 25, 30, 25, 30];
        $pdf->TableHeader($headers3, $widths3, [40, 167, 69]);
        
        foreach ($data['teachers'] as $i => $t) {
            $pdf->TableRow([
                $i + 1,
                substr($t['nama_lengkap'] ?? '-', 0, 25),
                str_replace('Guru_', '', $t['user_type'] ?? '-'),
                $t['messages_handled'] ?? 0,
                $t['responses_given'] ?? 0,
                number_format($t['avg_response_time'] ?? 0, 1) . 'h',
                $t['resolved_messages'] ?? 0,
                number_format($t['sla_compliance'] ?? 0, 1) . '%'
            ], $widths3, ($i % 2 == 0));
        }
        $pdf->Ln(10);
    }
    
    // EXTERNAL SENDERS
    $ext = $data['external'];
    $pdf->SectionTitle('EXTERNAL SENDERS', [255, 193, 7]);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(70, 7, 'Total Senders: ' . number_format($ext['total_senders']), 0, 0);
    $pdf->Cell(70, 7, 'Total Messages: ' . number_format($ext['total_messages']), 0, 0);
    $pdf->Cell(70, 7, 'Avg Response: ' . $ext['avg_response_time'] . 'h', 0, 1);
    $pdf->Cell(70, 7, 'Resolved: ' . number_format($ext['resolved_messages']), 0, 0);
    $pdf->Cell(70, 7, 'Msg Types: ' . $ext['message_types_used'], 0, 1);
    
    $filename = 'analytics_report_' . date('Ymd_His') . '.pdf';
    $pdf->Output('D', $filename);
    exit;
}

// ============================================
// PROFESSIONAL EXCEL EXPORT
// ============================================
function exportAnalyticsExcel($data, $startDate, $endDate) {
    $autoloadPath = $_SERVER['DOCUMENT_ROOT'] . '/responsive-message-app/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        die('PHPSpreadsheet tidak ditemukan. Jalankan: composer require phpoffice/phpspreadsheet:^5.0');
    }
    require_once $autoloadPath;
    
    while (ob_get_level()) ob_end_clean();
    
    try {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        
        // SHEET 1: EXECUTIVE SUMMARY
        $sheet1 = $spreadsheet->createSheet();
        $sheet1->setTitle('Executive Summary');
        $row1 = 1;
        
        $sheet1->setCellValue('A' . $row1, APP_NAME . ' - ANALYTICS REPORT');
        $sheet1->mergeCells('A' . $row1 . ':F' . $row1);
        $sheet1->getStyle('A' . $row1)->getFont()->setBold(true)->setSize(18);
        $sheet1->getStyle('A' . $row1)->getFont()->getColor()->setARGB('FF0D6EFD');
        $sheet1->getStyle('A' . $row1)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row1 += 2;
        
        $sheet1->setCellValue('A' . $row1, 'Period');
        $sheet1->setCellValue('B' . $row1, date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)));
        $sheet1->getStyle('A' . $row1)->getFont()->setBold(true);
        $row1 += 2;
        
        $sys = $data['system'];
        $sheet1->setCellValue('A' . $row1, 'SYSTEM OVERVIEW');
        $sheet1->getStyle('A' . $row1)->getFont()->setBold(true)->setSize(14);
        $sheet1->getStyle('A' . $row1)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet1->getStyle('A' . $row1)->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet1->mergeCells('A' . $row1 . ':C' . $row1);
        $row1 += 2;
        
        $sheet1->setCellValue('A' . $row1, 'Metric');
        $sheet1->setCellValue('B' . $row1, 'Value');
        $sheet1->getStyle('A' . $row1 . ':B' . $row1)->getFont()->setBold(true);
        $row1++;
        $sheet1->setCellValue('A' . $row1, 'Total Messages');
        $sheet1->setCellValue('B' . $row1, $sys['total_messages']);
        $row1++;
        $sheet1->setCellValue('A' . $row1, 'Resolved');
        $sheet1->setCellValue('B' . $row1, $sys['resolved_messages']);
        $row1++;
        $sheet1->setCellValue('A' . $row1, 'Avg Response Time');
        $sheet1->setCellValue('B' . $row1, $sys['avg_response_time'] . ' hours');
        $row1++;
        $sheet1->setCellValue('A' . $row1, 'External Senders');
        $sheet1->setCellValue('B' . $row1, $sys['external_senders']);
        $row1++;
        $sheet1->setCellValue('A' . $row1, 'External Messages');
        $sheet1->setCellValue('B' . $row1, $sys['external_messages']);
        $row1++;
        $sheet1->setCellValue('A' . $row1, 'Unique Responders');
        $sheet1->setCellValue('B' . $row1, $sys['unique_responders']);
        
        // SHEET 2: MESSAGE STATUS
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Message Status');
        $row2 = 1;
        
        $sheet2->setCellValue('A' . $row2, 'MESSAGE STATUS DISTRIBUTION');
        $sheet2->mergeCells('A' . $row2 . ':C' . $row2);
        $sheet2->getStyle('A' . $row2)->getFont()->setBold(true)->setSize(14);
        $sheet2->getStyle('A' . $row2)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet2->getStyle('A' . $row2)->getFont()->getColor()->setARGB('FFFFFFFF');
        $row2 += 2;
        
        $sheet2->setCellValue('A' . $row2, 'Status');
        $sheet2->setCellValue('B' . $row2, 'Jumlah');
        $sheet2->setCellValue('C' . $row2, 'Persentase');
        $sheet2->getStyle('A' . $row2 . ':C' . $row2)->getFont()->setBold(true);
        $row2++;
        
        $total = array_sum(array_column($data['status'], 'total'));
        foreach ($data['status'] as $row) {
            $percentage = $total > 0 ? round($row['total'] / $total * 100, 1) : 0;
            $sheet2->setCellValue('A' . $row2, $row['status']);
            $sheet2->setCellValue('B' . $row2, $row['total']);
            $sheet2->setCellValue('C' . $row2, $percentage . '%');
            $row2++;
        }
        
        // SHEET 3: PRIORITY
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Message Priority');
        $row3 = 1;
        
        $sheet3->setCellValue('A' . $row3, 'MESSAGE PRIORITY DISTRIBUTION');
        $sheet3->mergeCells('A' . $row3 . ':C' . $row3);
        $sheet3->getStyle('A' . $row3)->getFont()->setBold(true)->setSize(14);
        $sheet3->getStyle('A' . $row3)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC107');
        $row3 += 2;
        
        $sheet3->setCellValue('A' . $row3, 'Priority');
        $sheet3->setCellValue('B' . $row3, 'Jumlah');
        $sheet3->setCellValue('C' . $row3, 'Persentase');
        $sheet3->getStyle('A' . $row3 . ':C' . $row3)->getFont()->setBold(true);
        $row3++;
        
        $total = array_sum(array_column($data['priority'], 'total'));
        foreach ($data['priority'] as $row) {
            $percentage = $total > 0 ? round($row['total'] / $total * 100, 1) : 0;
            $sheet3->setCellValue('A' . $row3, $row['priority']);
            $sheet3->setCellValue('B' . $row3, $row['total']);
            $sheet3->setCellValue('C' . $row3, $percentage . '%');
            $row3++;
        }
        
        // SHEET 4: TEACHER PERFORMANCE
        if (!empty($data['teachers'])) {
            $sheet4 = $spreadsheet->createSheet();
            $sheet4->setTitle('Top Teachers');
            $row4 = 1;
            
            $sheet4->setCellValue('A' . $row4, 'TOP PERFORMING TEACHERS');
            $sheet4->mergeCells('A' . $row4 . ':H' . $row4);
            $sheet4->getStyle('A' . $row4)->getFont()->setBold(true)->setSize(14);
            $sheet4->getStyle('A' . $row4)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF28A745');
            $sheet4->getStyle('A' . $row4)->getFont()->getColor()->setARGB('FFFFFFFF');
            $row4 += 2;
            
            $sheet4->setCellValue('A' . $row4, 'No');
            $sheet4->setCellValue('B' . $row4, 'Nama Guru');
            $sheet4->setCellValue('C' . $row4, 'Tipe');
            $sheet4->setCellValue('D' . $row4, 'Messages');
            $sheet4->setCellValue('E' . $row4, 'Responses');
            $sheet4->setCellValue('F' . $row4, 'Avg Response');
            $sheet4->setCellValue('G' . $row4, 'Resolved');
            $sheet4->setCellValue('H' . $row4, 'SLA');
            $sheet4->getStyle('A' . $row4 . ':H' . $row4)->getFont()->setBold(true);
            $row4++;
            
            foreach ($data['teachers'] as $i => $t) {
                $sheet4->setCellValue('A' . $row4, $i + 1);
                $sheet4->setCellValue('B' . $row4, $t['nama_lengkap'] ?? '-');
                $sheet4->setCellValue('C' . $row4, str_replace('Guru_', '', $t['user_type'] ?? '-'));
                $sheet4->setCellValue('D' . $row4, $t['messages_handled'] ?? 0);
                $sheet4->setCellValue('E' . $row4, $t['responses_given'] ?? 0);
                $sheet4->setCellValue('F' . $row4, number_format($t['avg_response_time'] ?? 0, 1) . 'h');
                $sheet4->setCellValue('G' . $row4, $t['resolved_messages'] ?? 0);
                $sheet4->setCellValue('H' . $row4, number_format($t['sla_compliance'] ?? 0, 1) . '%');
                $row4++;
            }
        }
        
        // Auto size columns
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            foreach (range('A', 'H') as $col) {
                $worksheet->getColumnDimension($col)->setAutoSize(true);
            }
        }
        
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'analytics_');
        $writer->save($tempFile);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="analytics_report_' . date('Ymd_His') . '.xlsx"');
        header('Content-Length: ' . filesize($tempFile));
        
        readfile($tempFile);
        unlink($tempFile);
        exit;
        
    } catch (Exception $e) {
        error_log("Excel Error: " . $e->getMessage());
        die('Error: ' . $e->getMessage());
    }
}

// ============================================
// CSV EXPORT
// ============================================
function exportAnalyticsCSV($data, $startDate, $endDate) {
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="analytics_report_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, [APP_NAME . ' - ANALYTICS REPORT']);
    fputcsv($output, ["Periode: " . date('d/m/Y', strtotime($startDate)) . " - " . date('d/m/Y', strtotime($endDate))]);
    fputcsv($output, []);
    
    // System Overview
    fputcsv($output, ['SYSTEM OVERVIEW']);
    $sys = $data['system'];
    fputcsv($output, ['Total Messages', $sys['total_messages']]);
    fputcsv($output, ['Resolved', $sys['resolved_messages']]);
    fputcsv($output, ['Avg Response Time', $sys['avg_response_time'] . ' hours']);
    fputcsv($output, ['External Senders', $sys['external_senders']]);
    fputcsv($output, ['External Messages', $sys['external_messages']]);
    fputcsv($output, []);
    
    // Message Status
    fputcsv($output, ['MESSAGE STATUS']);
    fputcsv($output, ['Status', 'Jumlah', 'Persentase']);
    $total = array_sum(array_column($data['status'], 'total'));
    foreach ($data['status'] as $row) {
        $percentage = $total > 0 ? round($row['total'] / $total * 100, 1) : 0;
        fputcsv($output, [$row['status'], $row['total'], $percentage . '%']);
    }
    fputcsv($output, []);
    
    // Priority
    fputcsv($output, ['MESSAGE PRIORITY']);
    fputcsv($output, ['Priority', 'Jumlah', 'Persentase']);
    $total = array_sum(array_column($data['priority'], 'total'));
    foreach ($data['priority'] as $row) {
        $percentage = $total > 0 ? round($row['total'] / $total * 100, 1) : 0;
        fputcsv($output, [$row['priority'], $row['total'], $percentage . '%']);
    }
    
    fclose($output);
    exit;
}

// ============================================
// HTML DASHBOARD - WITH ALL CHARTS
// ============================================
require_once '../../includes/header.php';
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<style>
/* ============================================
   FIXED CHART CONTAINERS - ANTI INFINITE LOOP
   ============================================ */
.chart-container {
    position: relative;
    width: 100%;
    height: 250px !important;
    max-height: 250px;
    overflow: hidden;
}

.chart-card {
    height: 350px;
    display: flex;
    flex-direction: column;
}

.chart-card .card-body {
    flex: 1;
    min-height: 0;
    padding: 1rem;
}

canvas {
    max-width: 100%;
    max-height: 100%;
    width: auto !important;
    height: auto !important;
}

.kpi-card {
    transition: all 0.3s ease;
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.kpi-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.kpi-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.badge-excellent { background: rgba(40, 167, 69, 0.15); color: #28a745; }
.badge-good { background: rgba(13, 110, 253, 0.15); color: #0d6efd; }
.badge-warning { background: rgba(255, 193, 7, 0.15); color: #856404; }
.badge-danger { background: rgba(220, 53, 69, 0.15); color: #dc3545; }
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-chart-pie me-2 text-primary"></i>
                Analytics Dashboard
                <span class="badge bg-primary ms-2">Professional</span>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Analytics</li>
                </ol>
            </nav>
            <p class="text-muted small mb-0">
                <i class="fas fa-info-circle me-1"></i>
                Complete analytics with 5 professional charts
            </p>
        </div>
        <div class="d-flex align-items-center mt-2 mt-sm-0">
            <div class="btn-group">
                <a href="?preset=<?php echo $datePreset; ?>&export=pdf" class="btn btn-outline-danger">
                    <i class="fas fa-file-pdf me-1"></i>PDF Report
                </a>
                <a href="?preset=<?php echo $datePreset; ?>&export=excel" class="btn btn-outline-success">
                    <i class="fas fa-file-excel me-1"></i>Excel Report
                </a>
                <a href="?preset=<?php echo $datePreset; ?>&export=csv" class="btn btn-outline-info">
                    <i class="fas fa-file-csv me-1"></i>CSV Report
                </a>
            </div>
        </div>
    </div>
    
    <!-- Date Range Picker -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <div class="d-flex flex-wrap gap-2">
                        <a href="?preset=today" class="btn btn-sm <?php echo $datePreset === 'today' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Today</a>
                        <a href="?preset=yesterday" class="btn btn-sm <?php echo $datePreset === 'yesterday' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Yesterday</a>
                        <a href="?preset=last7days" class="btn btn-sm <?php echo $datePreset === 'last7days' ? 'btn-primary' : 'btn-outline-secondary'; ?>">7 Days</a>
                        <a href="?preset=last30days" class="btn btn-sm <?php echo $datePreset === 'last30days' ? 'btn-primary' : 'btn-outline-secondary'; ?>">30 Days</a>
                        <a href="?preset=last90days" class="btn btn-sm <?php echo $datePreset === 'last90days' ? 'btn-primary' : 'btn-outline-secondary'; ?>">90 Days</a>
                        <a href="?preset=thisMonth" class="btn btn-sm <?php echo $datePreset === 'thisMonth' ? 'btn-primary' : 'btn-outline-secondary'; ?>">This Month</a>
                        <a href="?preset=lastMonth" class="btn btn-sm <?php echo $datePreset === 'lastMonth' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Last Month</a>
                        <a href="?preset=thisYear" class="btn btn-sm <?php echo $datePreset === 'thisYear' ? 'btn-primary' : 'btn-outline-secondary'; ?>">This Year</a>
                    </div>
                </div>
                <div class="col-lg-4 mt-3 mt-lg-0">
                    <form method="GET" class="d-flex">
                        <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo $startDate; ?>">
                        <span class="mx-2 align-self-center text-muted">–</span>
                        <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo $endDate; ?>">
                        <button type="submit" class="btn btn-primary btn-sm ms-2">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- KPI Cards Row 1 - 4 Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="kpi-icon bg-primary bg-opacity-10">
                                <i class="fas fa-envelope text-primary"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Total Messages</h6>
                            <h3 class="mb-0"><?php echo number_format($systemOverview['total_messages']); ?></h3>
                            <small class="text-<?php echo $systemOverview['trend_direction'] === 'up' ? 'success' : 'danger'; ?>">
                                <i class="fas fa-arrow-<?php echo $systemOverview['trend_direction']; ?> me-1"></i>
                                <?php echo abs($systemOverview['trend_percentage']); ?>%
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="kpi-icon bg-success bg-opacity-10">
                                <i class="fas fa-check-circle text-success"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Resolved</h6>
                            <h3 class="mb-0"><?php echo number_format($systemOverview['resolved_messages']); ?></h3>
                            <small class="text-muted">
                                <?php echo $systemOverview['total_messages'] > 0 ? round($systemOverview['resolved_messages'] / $systemOverview['total_messages'] * 100, 1) : 0; ?>% rate
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="kpi-icon bg-warning bg-opacity-10">
                                <i class="fas fa-clock text-warning"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Avg Response</h6>
                            <h3 class="mb-0"><?php echo $systemOverview['avg_response_time']; ?>h</h3>
                            <small class="text-<?php echo $systemOverview['avg_response_time'] <= 24 ? 'success' : ($systemOverview['avg_response_time'] <= 72 ? 'warning' : 'danger'); ?>">
                                <?php echo $systemOverview['avg_response_time'] <= 24 ? 'Fast' : ($systemOverview['avg_response_time'] <= 72 ? 'Moderate' : 'Slow'); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="kpi-icon bg-info bg-opacity-10">
                                <i class="fas fa-external-link-alt text-info"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">External</h6>
                            <h3 class="mb-0"><?php echo number_format($systemOverview['external_senders']); ?></h3>
                            <small class="text-muted"><?php echo $systemOverview['external_messages']; ?> messages</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- KPI Cards Row 2 - 4 Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="kpi-icon bg-secondary bg-opacity-10">
                                <i class="fas fa-gavel text-secondary"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">SLA Compliance</h6>
                            <h3 class="mb-0"><?php echo $slaCompliance['compliance_rate']; ?>%</h3>
                            <small class="text-<?php echo $slaCompliance['compliance_rate'] >= 90 ? 'success' : 'warning'; ?>">
                                <?php echo $slaCompliance['within_sla']; ?>/<?php echo $slaCompliance['total_resolved']; ?> responses
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="kpi-icon bg-danger bg-opacity-10">
                                <i class="fas fa-user-clock text-danger"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Overdue</h6>
                            <h3 class="mb-0"><?php echo $slaCompliance['total_resolved'] - $slaCompliance['within_sla']; ?></h3>
                            <small class="text-muted">Avg <?php echo $slaCompliance['avg_overdue_hours']; ?>h late</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="kpi-icon bg-primary bg-opacity-10">
                                <i class="fas fa-users text-primary"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Responders</h6>
                            <h3 class="mb-0"><?php echo number_format($slaCompliance['responders_count']); ?></h3>
                            <small class="text-muted">Active teachers</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="kpi-icon bg-warning bg-opacity-10">
                                <i class="fas fa-envelope-open-text text-warning"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Msg Types</h6>
                            <h3 class="mb-0"><?php echo count($messageTypeStats); ?></h3>
                            <small class="text-muted">Active message types</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Charts Row 1 - Daily Trends & Status -->
    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card shadow-sm chart-card">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line text-primary me-2"></i>
                        Daily Message Trends
                    </h5>
                    <span class="badge bg-light text-dark">Last 14 days | 7-day MA</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="dailyTrendsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card shadow-sm chart-card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie text-warning me-2"></i>
                        Message Status
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Charts Row 2 - Priority, Response Time, User Growth -->
    <div class="row g-4 mb-4">
        <div class="col-xl-4">
            <div class="card shadow-sm chart-card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-flag text-danger me-2"></i>
                        Priority Distribution
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="priorityChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card shadow-sm chart-card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-hourglass-half text-info me-2"></i>
                        Response Time
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="responseTimeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card shadow-sm chart-card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-user-plus text-success me-2"></i>
                        User Growth
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="userGrowthChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Message Types Table -->
    <?php if (!empty($messageTypeStats)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-tags text-primary me-2"></i>
                        Message Types Statistics
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>Jenis Pesan</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">External</th>
                                    <th class="text-center">Resolved</th>
                                    <th class="text-center">Avg Response</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($messageTypeStats as $type): ?>
                                <tr>
                                    <td class="fw-medium"><?php echo htmlspecialchars($type['jenis_pesan'] ?? '-'); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2">
                                            <?php echo number_format($type['total'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2">
                                            <?php echo number_format($type['external_count'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-success bg-opacity-10 text-success px-3 py-2">
                                            <?php echo number_format($type['resolved_count'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($type['avg_response_time'])): ?>
                                            <span class="badge bg-<?php echo $type['avg_response_time'] <= 24 ? 'success' : ($type['avg_response_time'] <= 72 ? 'warning' : 'danger'); ?> bg-opacity-10 px-3 py-2">
                                                <?php echo number_format($type['avg_response_time'], 1); ?>h
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Teacher Performance Table -->
    <?php if (!empty($teacherPerformance)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-chalkboard-teacher text-success me-2"></i>
                        Top Performing Teachers
                    </h5>
                    <span class="badge bg-success"><?php echo count($teacherPerformance); ?> teachers</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th width="50">#</th>
                                    <th>Nama Guru</th>
                                    <th>Tipe</th>
                                    <th class="text-center">Messages</th>
                                    <th class="text-center">Responses</th>
                                    <th class="text-center">Avg Response</th>
                                    <th class="text-center">Resolved</th>
                                    <th class="text-center">External</th>
                                    <th class="text-center">SLA</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teacherPerformance as $i => $t): ?>
                                <tr>
                                    <td class="fw-bold text-center"><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($t['nama_lengkap'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-info bg-opacity-10 text-info px-3 py-2">
                                            <?php echo str_replace('Guru_', '', $t['user_type'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center fw-medium"><?php echo $t['messages_handled'] ?? 0; ?></td>
                                    <td class="text-center"><?php echo $t['responses_given'] ?? 0; ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo ($t['avg_response_time'] ?? 999) <= 24 ? 'success' : (($t['avg_response_time'] ?? 999) <= 72 ? 'warning' : 'danger'); ?> bg-opacity-10 px-3 py-2">
                                            <?php echo number_format($t['avg_response_time'] ?? 0, 1); ?>h
                                        </span>
                                    </td>
                                    <td class="text-center fw-bold text-success"><?php echo $t['resolved_messages'] ?? 0; ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2">
                                            <?php echo $t['external_handled'] ?? 0; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo ($t['sla_compliance'] ?? 0) >= 90 ? 'success' : (($t['sla_compliance'] ?? 0) >= 70 ? 'warning' : 'danger'); ?> px-3 py-2">
                                            <?php echo number_format($t['sla_compliance'] ?? 0, 1); ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- External Senders Summary -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-external-link-alt text-warning me-2"></i>
                        External Senders Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 col-6 mb-3">
                            <div class="text-center">
                                <h2 class="fw-bold text-warning mb-1"><?php echo number_format($externalSenders['total_senders']); ?></h2>
                                <small class="text-muted">Total Senders</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="text-center">
                                <h2 class="fw-bold text-primary mb-1"><?php echo number_format($externalSenders['total_messages']); ?></h2>
                                <small class="text-muted">Messages Sent</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="text-center">
                                <h2 class="fw-bold text-info mb-1"><?php echo $externalSenders['avg_response_time']; ?>h</h2>
                                <small class="text-muted">Avg Response</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="text-center">
                                <h2 class="fw-bold text-success mb-1"><?php echo $externalSenders['message_types_used']; ?></h2>
                                <small class="text-muted">Msg Types Used</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// CHART INITIALIZATION - ANTI INFINITE LOOP
// ============================================
let charts = {};

document.addEventListener('DOMContentLoaded', function() {
    // Destroy existing charts
    destroyCharts();
    
    // Initialize all charts
    initDailyTrendsChart();
    initStatusChart();
    initPriorityChart();
    initResponseTimeChart();
    initUserGrowthChart();
});

function destroyCharts() {
    Object.keys(charts).forEach(key => {
        if (charts[key]) {
            charts[key].destroy();
            delete charts[key];
        }
    });
}

// 1. Daily Trends Chart with Moving Average
function initDailyTrendsChart() {
    const ctx = document.getElementById('dailyTrendsChart');
    if (!ctx) return;
    
    charts.dailyTrends = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [
                {
                    label: 'Total Messages',
                    data: <?php echo json_encode($chartMessages); ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 6
                },
                {
                    label: 'External Messages',
                    data: <?php echo json_encode($chartExternal); ?>,
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 6
                },
                {
                    label: '7-Day Moving Avg',
                    data: <?php echo json_encode($chartMovingAvg); ?>,
                    borderColor: '#dc3545',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    pointHoverRadius: 0,
                    tension: 0.4,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2,
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 12, padding: 15 } },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
                x: { grid: { display: false } }
            }
        }
    });
}

// 2. Status Chart (Doughnut)
function initStatusChart() {
    const ctx = document.getElementById('statusChart');
    if (!ctx) return;
    
    charts.status = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($messageStatusStats, 'status')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($messageStatusStats, 'total')); ?>,
                backgroundColor: <?php echo json_encode(array_column($messageStatusStats, 'color')); ?>,
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 1.2,
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10, font: { size: 10 } } },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return `${label}: ${value.toLocaleString()} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

// 3. Priority Chart (Pie)
function initPriorityChart() {
    const ctx = document.getElementById('priorityChart');
    if (!ctx) return;
    
    charts.priority = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: <?php echo json_encode(array_column($messagePriorityStats, 'priority')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($messagePriorityStats, 'total')); ?>,
                backgroundColor: <?php echo json_encode(array_column($messagePriorityStats, 'color')); ?>,
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 1.2,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10, font: { size: 10 } } }
            }
        }
    });
}

// 4. Response Time Chart (Bar)
function initResponseTimeChart() {
    const ctx = document.getElementById('responseTimeChart');
    if (!ctx) return;
    
    charts.responseTime = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($responseTimeDist, 'response_range')); ?>,
            datasets: [{
                label: 'Number of Responses',
                data: <?php echo json_encode(array_column($responseTimeDist, 'total')); ?>,
                backgroundColor: '#0d6efd',
                borderRadius: 6,
                barPercentage: 0.7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 1.5,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return `${value.toLocaleString()} responses (${percentage}%)`;
                        }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
                x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, font: { size: 9 } } }
            }
        }
    });
}

// 5. User Growth Chart (Line)
function initUserGrowthChart() {
    const ctx = document.getElementById('userGrowthChart');
    if (!ctx) return;
    
    charts.userGrowth = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($userGrowthLabels); ?>,
            datasets: [
                {
                    label: 'New Teachers',
                    data: <?php echo json_encode($userGrowthTeachers); ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 6
                },
                {
                    label: 'New Students',
                    data: <?php echo json_encode($userGrowthStudents); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 1.5,
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 12, padding: 15, font: { size: 10 } } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
                x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, font: { size: 10 } } }
            }
        }
    });
}

// Handle window resize with debounce
let resizeTimer;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function() {
        Object.values(charts).forEach(chart => {
            if (chart) chart.resize();
        });
    }, 250);
});
</script>

<?php require_once '../../includes/footer.php'; ?>