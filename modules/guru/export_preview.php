<?php
/**
 * Preview Data for Export
 * File: modules/guru/export_preview.php
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

// Check authentication
Auth::checkAuth();

header('Content-Type: application/json');

$guruId = $_SESSION['user_id'];
$guruType = $_SESSION['user_type'];

// Map guru type
$typeMap = [
    'Guru_BK' => 'Konsultasi/Konseling',
    'Guru_Humas' => 'Kehumasan',
    'Guru_Kurikulum' => 'Kurikulum',
    'Guru_Kesiswaan' => 'Kesiswaan',
    'Guru_Sarana' => 'Sarana Prasarana'
];

$assignedType = $typeMap[$guruType] ?? '';

// Get parameters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// Database connection
$db = Database::getInstance()->getConnection();

// Get message type ID
$typeStmt = $db->prepare("SELECT id FROM message_types WHERE jenis_pesan = :jenis_pesan");
$typeStmt->execute([':jenis_pesan' => $assignedType]);
$messageType = $typeStmt->fetch();
$messageTypeId = $messageType['id'] ?? 0;

// Get monthly data for preview
$monthlySql = "
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status IN ('Disetujui', 'Ditolak') THEN 1 ELSE 0 END) as responded
    FROM messages 
    WHERE jenis_pesan_id = :type_id
        AND DATE(created_at) BETWEEN :start_date AND :end_date
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
";

$monthlyStmt = $db->prepare($monthlySql);
$monthlyStmt->execute([
    ':type_id' => $messageTypeId,
    ':start_date' => $startDate,
    ':end_date' => $endDate
]);

$monthlyData = $monthlyStmt->fetchAll();

// Get total stats
$statsSql = "
    SELECT 
        COUNT(*) as total_messages,
        SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status IN ('Disetujui', 'Ditolak') THEN 1 ELSE 0 END) as responded
    FROM messages 
    WHERE jenis_pesan_id = :type_id
        AND DATE(created_at) BETWEEN :start_date AND :end_date
";

$statsStmt = $db->prepare($statsSql);
$statsStmt->execute([
    ':type_id' => $messageTypeId,
    ':start_date' => $startDate,
    ':end_date' => $endDate
]);

$stats = $statsStmt->fetch();

echo json_encode([
    'success' => true,
    'monthly_data' => $monthlyData,
    'total_stats' => $stats,
    'period' => [
        'start' => $startDate,
        'end' => $endDate
    ],
    'guru_info' => [
        'name' => $_SESSION['nama_lengkap'] ?? 'Guru',
        'type' => $assignedType
    ]
]);
exit;