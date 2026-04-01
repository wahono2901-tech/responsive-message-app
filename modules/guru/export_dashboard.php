<?php
/**
 * EXPORT DASHBOARD GURU - PHPSPREADSHEET 5.x + FPDF PROFESIONAL + GRAFIK
 * File: modules/guru/export_dashboard.php
 * 
 * ✅ LAYOUT A4 LANDSCAPE PROFESIONAL - TIDAK TUMPANG TINDIH
 * ✅ PDF: Header, Footer, Margin yang tepat, Spasi proporsional
 * ✅ EXCEL: 5 Worksheet terorganisir, Auto-size columns, Styling rapi
 * ✅ FIX #1: Deprecated imagefilledpolygon() - Menggunakan array points tanpa $num_points
 * ✅ FIX #2: Implicit conversion float to int - Cast ke int untuk koordinat
 * ✅ FIX #3: FPDF output buffer - Clean output sebelum Output()
 * ✅ PHP 8.2 COMPATIBLE
 * ✅ TEMP FILE METHOD - ANTI CORRUPT
 * 
 * @author Responsive Message App
 * @version 9.0.0 - Layout Profesional A4 Landscape
 */

// ============================================
// PHASE 1: EARLY OUTPUT BUFFER CLEANING (WAJIB!)
// ============================================
// Hapus SEMUA output buffer yang mungkin ada
while (ob_get_level()) ob_end_clean();

// Mulai buffer baru
ob_start();

// ============================================
// INISIALISASI & VALIDASI
// ============================================
if (!isset($_GET['format']) || !isset($_GET['time'])) {
    ob_end_clean();
    die('Akses tidak valid');
}

// Matikan error reporting untuk production, tapi log tetap jalan
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

Auth::checkAuth();

$allowedTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'];
if (!in_array($_SESSION['user_type'], $allowedTypes)) {
    ob_end_clean();
    die('Akses ditolak');
}

$guruId = $_SESSION['user_id'];
$guruType = $_SESSION['user_type'];
$format = $_GET['format'];
$timeFilter = $_GET['time'];

$typeMap = [
    'Guru_BK' => 'Konsultasi/Konseling',
    'Guru_Humas' => 'Kehumasan',
    'Guru_Kurikulum' => 'Kurikulum',
    'Guru_Kesiswaan' => 'Kesiswaan',
    'Guru_Sarana' => 'Sarana Prasarana'
];
$assignedType = $typeMap[$guruType] ?? '';

// ============================================
// SET DATE RANGE
// ============================================
$startDate = ''; $endDate = date('Y-m-d');
switch ($timeFilter) {
    case '7days':  $startDate = date('Y-m-d', strtotime('-7 days')); $periodText = '7 Hari Terakhir'; break;
    case '30days': $startDate = date('Y-m-d', strtotime('-30 days')); $periodText = '30 Hari Terakhir'; break;
    case '90days': $startDate = date('Y-m-d', strtotime('-90 days')); $periodText = '90 Hari Terakhir'; break;
    case 'year':   $startDate = date('Y-m-d', strtotime('-1 year')); $periodText = '1 Tahun Terakhir'; break;
    default: $startDate = date('Y-m-d', strtotime('-30 days')); $periodText = '30 Hari Terakhir';
}

$db = Database::getInstance()->getConnection();

// ============================================
// GET MESSAGE TYPE ID
// ============================================
$messageTypeId = 0;
try {
    $typeStmt = $db->prepare("SELECT id FROM message_types WHERE jenis_pesan = :jenis_pesan");
    $typeStmt->execute([':jenis_pesan' => $assignedType]);
    $messageType = $typeStmt->fetch();
    if ($messageType) $messageTypeId = $messageType['id'];
} catch (PDOException $e) { 
    error_log("Error: " . $e->getMessage()); 
}

// ============================================
// 1. GET STATISTICS
// ============================================
$stats = [
    'total_assigned' => 0, 'external_count' => 0, 'pending' => 0, 'dibaca' => 0,
    'diproses' => 0, 'disetujui' => 0, 'ditolak' => 0, 'selesai' => 0,
    'expired' => 0, 'avg_response_time' => 0
];

if ($messageTypeId > 0) {
    try {
        $statsSql = "SELECT 
            COUNT(*) as total_assigned,
            SUM(CASE WHEN is_external = 1 THEN 1 ELSE 0 END) as external_count,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'Dibaca' THEN 1 ELSE 0 END) as dibaca,
            SUM(CASE WHEN status = 'Diproses' THEN 1 ELSE 0 END) as diproses,
            SUM(CASE WHEN status = 'Disetujui' THEN 1 ELSE 0 END) as disetujui,
            SUM(CASE WHEN status = 'Ditolak' THEN 1 ELSE 0 END) as ditolak,
            SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as selesai,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) > 72 AND status IN ('Pending','Dibaca','Diproses') THEN 1 ELSE 0 END) as expired,
            AVG(CASE WHEN tanggal_respon IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, tanggal_respon) ELSE NULL END) as avg_response_time
        FROM messages 
        WHERE jenis_pesan_id = :type_id AND created_at >= :start_date";
        
        $statsStmt = $db->prepare($statsSql);
        $statsStmt->execute([':type_id' => $messageTypeId, ':start_date' => $startDate]);
        $statsResult = $statsStmt->fetch();
        if ($statsResult) $stats = array_merge($stats, $statsResult);
    } catch (PDOException $e) { 
        error_log("Error stats: " . $e->getMessage()); 
    }
}

// ============================================
// 2. GET SOURCE DISTRIBUTION (UNTUK PIE CHART)
// ============================================
$sourceDist = [];
if ($messageTypeId > 0) {
    try {
        $sourceSql = "SELECT is_external, COUNT(*) as count 
                      FROM messages 
                      WHERE jenis_pesan_id = :type_id AND created_at >= :start_date 
                      GROUP BY is_external";
        $sourceStmt = $db->prepare($sourceSql);
        $sourceStmt->execute([':type_id' => $messageTypeId, ':start_date' => $startDate]);
        $sourceResults = $sourceStmt->fetchAll();
        
        $totalSource = array_sum(array_column($sourceResults, 'count'));
        $totalSource = $totalSource > 0 ? $totalSource : 1;
        
        foreach ($sourceResults as $row) {
            $sourceDist[] = [
                'source_type' => $row['is_external'] == 1 ? 'External' : 'Internal',
                'count' => $row['count'],
                'percentage' => round(($row['count'] / $totalSource) * 100, 1)
            ];
        }
        if (empty($sourceDist)) {
            $sourceDist = [
                ['source_type' => 'Internal', 'count' => 0, 'percentage' => 0],
                ['source_type' => 'External', 'count' => 0, 'percentage' => 0]
            ];
        }
    } catch (PDOException $e) { 
        error_log("Error source: " . $e->getMessage()); 
    }
}

// ============================================
// 3. GET TIME-BASED TRENDS (UNTUK BAR CHART)
// ============================================
$chartLabels = []; $chartInternalData = []; $chartExternalData = [];

if ($messageTypeId > 0) {
    try {
        $trendsSql = "SELECT 
            DATE(created_at) as date,
            COUNT(*) as total_messages,
            SUM(CASE WHEN is_external = 1 THEN 1 ELSE 0 END) as external_count
        FROM messages 
        WHERE jenis_pesan_id = :type_id AND created_at >= :start_date
        GROUP BY DATE(created_at) 
        ORDER BY date ASC 
        LIMIT 15";
        
        $trendsStmt = $db->prepare($trendsSql);
        $trendsStmt->execute([':type_id' => $messageTypeId, ':start_date' => $startDate]);
        $trends = $trendsStmt->fetchAll();
        
        foreach ($trends as $trend) {
            $chartLabels[] = date('d M', strtotime($trend['date']));
            $internalCount = (int)$trend['total_messages'] - (int)$trend['external_count'];
            $chartInternalData[] = $internalCount;
            $chartExternalData[] = (int)$trend['external_count'];
        }
    } catch (PDOException $e) { 
        error_log("Error trends: " . $e->getMessage()); 
    }
}

if (empty($chartLabels)) {
    $chartLabels = ['Tidak Ada Data'];
    $chartInternalData = [0];
    $chartExternalData = [0];
}

// ============================================
// 4. GET PERFORMANCE METRICS
// ============================================
$performance = [
    'internal' => ['total_messages_handled' => 0, 'messages_resolved' => 0, 'total_responses_given' => 0, 'avg_response_time' => 0],
    'external' => ['total_messages_handled' => 0, 'messages_resolved' => 0, 'total_responses_given' => 0, 'avg_response_time' => 0]
];

if ($messageTypeId > 0) {
    try {
        // Internal
        $internalPerfSql = "SELECT 
            COUNT(DISTINCT m.id) as total_messages_handled,
            COUNT(DISTINCT CASE WHEN m.status IN ('Disetujui','Ditolak','Selesai') THEN m.id END) as messages_resolved,
            COUNT(DISTINCT mr.id) as total_responses_given,
            AVG(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(mr.created_at, m.tanggal_respon, NOW()))) as avg_response_time
        FROM messages m
        LEFT JOIN message_responses mr ON m.id = mr.message_id AND mr.responder_id = :guru_id
        WHERE m.jenis_pesan_id = :type_id AND m.is_external = 0 AND m.created_at >= :start_date";
        
        $internalPerfStmt = $db->prepare($internalPerfSql);
        $internalPerfStmt->execute([':type_id' => $messageTypeId, ':guru_id' => $guruId, ':start_date' => $startDate]);
        $internalPerf = $internalPerfStmt->fetch();
        if ($internalPerf) {
            $performance['internal'] = [
                'total_messages_handled' => (int)($internalPerf['total_messages_handled'] ?? 0),
                'messages_resolved' => (int)($internalPerf['messages_resolved'] ?? 0),
                'total_responses_given' => (int)($internalPerf['total_responses_given'] ?? 0),
                'avg_response_time' => round($internalPerf['avg_response_time'] ?? 0, 1)
            ];
        }
        
        // External
        $externalPerfSql = str_replace('m.is_external = 0', 'm.is_external = 1', $internalPerfSql);
        $externalPerfStmt = $db->prepare($externalPerfSql);
        $externalPerfStmt->execute([':type_id' => $messageTypeId, ':guru_id' => $guruId, ':start_date' => $startDate]);
        $externalPerf = $externalPerfStmt->fetch();
        if ($externalPerf) {
            $performance['external'] = [
                'total_messages_handled' => (int)($externalPerf['total_messages_handled'] ?? 0),
                'messages_resolved' => (int)($externalPerf['messages_resolved'] ?? 0),
                'total_responses_given' => (int)($externalPerf['total_responses_given'] ?? 0),
                'avg_response_time' => round($externalPerf['avg_response_time'] ?? 0, 1)
            ];
        }
    } catch (PDOException $e) { 
        error_log("Error performance: " . $e->getMessage()); 
    }
}

// ============================================
// 5. GET TOP EXTERNAL SENDERS
// ============================================
$topExternalSenders = [];
if ($messageTypeId > 0) {
    try {
        $externalSendersSql = "SELECT 
            es.nama_lengkap, es.identitas, 
            COUNT(m.id) as message_count,
            GROUP_CONCAT(DISTINCT m.status ORDER BY m.status SEPARATOR ', ') as status_list
        FROM messages m
        INNER JOIN external_senders es ON m.external_sender_id = es.id
        WHERE m.jenis_pesan_id = :type_id 
            AND m.is_external = 1 
            AND m.created_at >= :start_date 
            AND m.external_sender_id IS NOT NULL
        GROUP BY es.id, es.nama_lengkap, es.identitas
        ORDER BY message_count DESC 
        LIMIT 5";
        
        $externalSendersStmt = $db->prepare($externalSendersSql);
        $externalSendersStmt->execute([':type_id' => $messageTypeId, ':start_date' => $startDate]);
        $topExternalSenders = $externalSendersStmt->fetchAll();
    } catch (PDOException $e) { 
        error_log("Error external senders: " . $e->getMessage()); 
    }
}

// ============================================
// 6. GET RECENT ACTIVITY
// ============================================
$recentActivity = [];
if ($messageTypeId > 0) {
    try {
        $recentActivitySql = "SELECT 
            m.id, m.isi_pesan, m.created_at, m.status, m.is_external,
            CASE WHEN m.is_external = 1 THEN es.nama_lengkap ELSE u.nama_lengkap END as sender_name,
            CASE WHEN m.is_external = 1 THEN es.identitas ELSE u.kelas END as sender_info
        FROM messages m
        LEFT JOIN users u ON m.pengirim_id = u.id
        LEFT JOIN external_senders es ON m.external_sender_id = es.id
        WHERE m.jenis_pesan_id = :type_id AND m.created_at >= :start_date
        ORDER BY m.created_at DESC 
        LIMIT 5";
        
        $recentActivityStmt = $db->prepare($recentActivitySql);
        $recentActivityStmt->execute([':type_id' => $messageTypeId, ':start_date' => $startDate]);
        $recentActivity = $recentActivityStmt->fetchAll();
    } catch (PDOException $e) { 
        error_log("Error recent activity: " . $e->getMessage()); 
    }
}

// ============================================
// ✅ GLOBAL SCOPE - USE STATEMENTS PHPSPREADSHEET 5.x
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
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Chart\Renderer\MtJpGraph;

// ============================================
// FUNCTION: Generate Grafik untuk PDF (menggunakan GD) - FIX DEPRECATED
// ============================================
function generatePieChartImage($data, $width = 300, $height = 200) {
    $image = imagecreatetruecolor($width, $height);
    
    // Warna background
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, $width, $height, $white);
    
    // Warna untuk chart
    $colors = [
        imagecolorallocate($image, 13, 110, 253),  // Biru Internal
        imagecolorallocate($image, 255, 193, 7)    // Kuning External
    ];
    
    // Hitung total
    $total = array_sum(array_column($data, 'count'));
    if ($total == 0) return $image;
    
    // Koordinat pusat dan radius
    $cx = (int)100;
    $cy = (int)100;
    $radius = (int)80;
    
    // Gambar pie slices - FIX DEPRECATED: gunakan array points tanpa $num_points
    $startAngle = 0;
    foreach ($data as $index => $item) {
        $angle = ($item['count'] / $total) * 360;
        
        // Gambar slice dengan imagefilledpolygon versi baru (tanpa $num_points)
        for ($a = 0; $a < 360; $a += 5) {
            if ($a >= $startAngle && $a <= $startAngle + $angle) {
                $x1 = (int)($cx + $radius * cos(deg2rad($a)));
                $y1 = (int)($cy + $radius * sin(deg2rad($a)));
                $x2 = (int)($cx + $radius * cos(deg2rad($a + 5)));
                $y2 = (int)($cy + $radius * sin(deg2rad($a + 5)));
                
                // FIX DEPRECATED: Parameter ke-4 adalah array points, bukan integer
                $points = [$cx, $cy, $x1, $y1, $x2, $y2];
                imagefilledpolygon($image, $points, $colors[$index]);
            }
        }
        
        $startAngle += $angle;
    }
    
    // Gambar lingkaran putih di tengah (efek donut)
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefilledellipse($image, $cx, $cy, 60, 60, $white);
    
    // Tambah teks total
    $black = imagecolorallocate($image, 0, 0, 0);
    imagestring($image, 5, $cx - 15, $cy - 5, (string)$total, $black);
    
    // Legend
    $legendY = 30;
    foreach ($data as $index => $item) {
        imagefilledrectangle($image, 210, (int)$legendY, 230, (int)($legendY + 15), $colors[$index]);
        imagestring($image, 4, 235, (int)$legendY, $item['source_type'] . ': ' . $item['count'] . ' (' . $item['percentage'] . '%)', $black);
        $legendY += 25;
    }
    
    return $image;
}

function generateBarChartImage($labels, $internalData, $externalData, $width = 500, $height = 250) {
    $image = imagecreatetruecolor($width, $height);
    
    // Warna background
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, $width, $height, $white);
    
    $black = imagecolorallocate($image, 0, 0, 0);
    $blue = imagecolorallocate($image, 13, 110, 253);
    $yellow = imagecolorallocate($image, 255, 193, 7);
    $gray = imagecolorallocate($image, 200, 200, 200);
    
    // Margin
    $left = (int)60;
    $right = (int)50;
    $top = (int)30;
    $bottom = (int)60;
    $chartWidth = $width - $left - $right;
    $chartHeight = $height - $top - $bottom;
    
    // Gambar sumbu
    imageline($image, $left, $top, $left, $height - $bottom, $black);
    imageline($image, $left, $height - $bottom, $width - $right, $height - $bottom, $black);
    
    // Cari nilai maksimum
    $maxValue = max(max($internalData), max($externalData));
    $maxValue = $maxValue > 0 ? $maxValue : 1;
    
    // Gambar grid - FIX: cast ke int
    for ($i = 0; $i <= 5; $i++) {
        $y = (int)($height - $bottom - ($i / 5) * $chartHeight);
        imageline($image, $left - 5, $y, $width - $right, $y, $gray);
        imagestring($image, 3, 5, $y - 5, (string)round(($i / 5) * $maxValue), $black);
    }
    
    // Gambar bar
    $barCount = count($internalData);
    $barWidth = (int)(($chartWidth - 20) / ($barCount * 3));
    
    for ($i = 0; $i < $barCount; $i++) {
        $x = (int)($left + 10 + ($i * $barWidth * 3));
        
        // Bar Internal
        $internalHeight = (int)(($internalData[$i] / $maxValue) * $chartHeight);
        imagefilledrectangle($image, $x, $height - $bottom - $internalHeight, 
                           $x + $barWidth, $height - $bottom - 2, $blue);
        
        // Bar External
        $externalHeight = (int)(($externalData[$i] / $maxValue) * $chartHeight);
        imagefilledrectangle($image, $x + $barWidth + 5, $height - $bottom - $externalHeight,
                           $x + $barWidth * 2 + 5, $height - $bottom - 2, $yellow);
        
        // Label tanggal
        $label = substr($labels[$i], 0, 6);
        imagestring($image, 2, $x, $height - $bottom + 5, $label, $black);
    }
    
    // Legend
    imagefilledrectangle($image, $width - 120, 30, $width - 100, 45, $blue);
    imagestring($image, 3, $width - 95, 30, 'Internal', $black);
    imagefilledrectangle($image, $width - 120, 55, $width - 100, 70, $yellow);
    imagestring($image, 3, $width - 95, 55, 'External', $black);
    
    return $image;
}

// ============================================
// PHASE 2: EXPORT HANDLER
// ============================================

if ($format === 'pdf') {
    // ========================================
    // PDF DENGAN FPDF - LAYOUT A4 LANDSCAPE PROFESIONAL
    // ========================================
    
    $fpdfPath = $_SERVER['DOCUMENT_ROOT'] . '/responsive-message-app/vendor/fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) {
        ob_end_clean();
        die('FPDF tidak ditemukan. Silakan download dari http://www.fpdf.org');
    }
    require_once $fpdfPath;
    
    // Bersihkan SEMUA output buffer sebelum generate PDF
    ob_end_clean();
    
    // ========================================
    // EXTEND FPDF CLASS UNTUK GRAFIK - LAYOUT PROFESIONAL
    // ========================================
    class PDF extends FPDF {
        // Header - Kop Surat Profesional
        function Header() {
            // Garis atas
            $this->SetDrawColor(13, 110, 253);
            $this->SetLineWidth(0.8);
            $this->Line(15, 10, 285, 10);
            
            // Logo / Nama Aplikasi
            $this->SetY(15);
            $this->SetFont('Arial', 'B', 22);
            $this->SetTextColor(13, 110, 253);
            $this->Cell(0, 12, APP_NAME, 0, 1, 'C');
            
            // Subtitle
            $this->SetFont('Arial', 'B', 16);
            $this->SetTextColor(108, 117, 125);
            $this->Cell(0, 10, 'LAPORAN DASHBOARD GURU', 0, 1, 'C');
            
            // Garis pemisah ganda
            $this->SetDrawColor(13, 110, 253);
            $this->SetLineWidth(0.3);
            $this->Line(15, $this->GetY(), 285, $this->GetY());
            $this->Ln(8);
        }
        
        // Footer - Informasi Lengkap
        function Footer() {
            $this->SetY(-18);
            $this->SetDrawColor(13, 110, 253);
            $this->SetLineWidth(0.5);
            $this->Line(15, $this->GetY(), 285, $this->GetY());
            
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(108, 117, 125);
            $this->Cell(0, 4, 'Dokumen ini digenerate secara otomatis oleh ' . APP_NAME . ' - SMKN 12 Jakarta', 0, 1, 'C');
            $this->Cell(0, 4, 'Halaman ' . $this->PageNo() . ' dari {nb} | Dicetak: ' . date('d/m/Y H:i:s'), 0, 0, 'C');
        }
        
        // KPI Card dengan Desain Elegan
        function KPICard($x, $y, $title, $value, $subtitle = '', $color = [13, 110, 253]) {
            // Background dengan bayangan
            $this->SetXY($x, $y);
            $this->SetFillColor(248, 249, 250);
            $this->SetDrawColor(222, 226, 230);
            $this->Rect($x, $y, 65, 32, 'FD');
            
            // Garis aksen atas
            $this->SetDrawColor($color[0], $color[1], $color[2]);
            $this->SetLineWidth(0.8);
            $this->Line($x, $y, $x + 65, $y);
            
            // Title
            $this->SetXY($x + 5, $y + 6);
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor($color[0], $color[1], $color[2]);
            $this->Cell(0, 5, strtoupper($title), 0, 1, 'L');
            
            // Value
            $this->SetXY($x + 5, $y + 14);
            $this->SetFont('Arial', 'B', 18);
            $this->SetTextColor(33, 37, 41);
            $this->Cell(0, 8, $value, 0, 1, 'L');
            
            // Subtitle
            if ($subtitle) {
                $this->SetXY($x + 5, $y + 24);
                $this->SetFont('Arial', '', 7);
                $this->SetTextColor(108, 117, 125);
                $this->Cell(0, 4, $subtitle, 0, 1, 'L');
            }
        }
        
        // Section Title dengan Desain
        function SectionTitle($title, $color = [13, 110, 253]) {
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor($color[0], $color[1], $color[2]);
            $this->Cell(0, 8, $title, 0, 1, 'L');
            
            $this->SetDrawColor($color[0], $color[1], $color[2]);
            $this->SetLineWidth(0.3);
            $this->Line(15, $this->GetY(), 70, $this->GetY());
            $this->Ln(6);
        }
        
        // Table Header dengan Gradasi
        function TableHeader($headers, $widths, $color = [13, 110, 253]) {
            $this->SetFillColor($color[0], $color[1], $color[2]);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 9);
            
            for ($i = 0; $i < count($headers); $i++) {
                $this->Cell($widths[$i], 10, $headers[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            
            $this->SetTextColor(33, 37, 41);
            $this->SetFont('Arial', '', 9);
        }
        
        // Insert Gambar Grafik dengan Posisi Presisi
        function InsertChart($image, $x, $y, $w, $h) {
            $tempFile = tempnam(sys_get_temp_dir(), 'chart_') . '.png';
            imagepng($image, $tempFile);
            $this->Image($tempFile, $x, $y, $w, $h);
            imagedestroy($image);
            unlink($tempFile);
        }
        
        // Tabel Data dengan Striped Rows
        function TableRow($data, $widths, $isEven = false) {
            if ($isEven) {
                $this->SetFillColor(248, 249, 250);
                $fill = true;
            } else {
                $fill = false;
            }
            
            for ($i = 0; $i < count($data); $i++) {
                $this->Cell($widths[$i], 8, $data[$i], 1, 0, 
                           ($i == 0 ? 'L' : 'C'), $fill);
            }
            $this->Ln();
        }
    }
    
    // ========================================
    // INISIALISASI PDF - A4 LANDSCAPE
    // ========================================
    $pdf = new PDF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->AddPage();
    
    // ========================================
    // HEADER INFORMASI - 2 KOLOM
    // ========================================
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(33, 37, 41);
    
    // Kolom Kiri
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(50, 7, 'Guru:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(90, 7, str_replace('_', ' ', $guruType), 0, 0, 'L');
    
    // Kolom Kanan
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 7, 'Jenis Pesan:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 7, $assignedType, 0, 1, 'L');
    
    // Baris Kedua
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(50, 7, 'Periode:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(90, 7, date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)) . ' (' . $periodText . ')', 0, 0, 'L');
    
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 7, 'Tanggal Cetak:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 7, date('d/m/Y H:i:s'), 0, 1, 'L');
    
    $pdf->Ln(12);
    
    // ========================================
    // SECTION 1: RINGKASAN EKSEKUTIF - 8 KPI CARDS
    // ========================================
    $pdf->SectionTitle('RINGKASAN EKSEKUTIF', [13, 110, 253]);
    
    $internalMessages = max(0, ($stats['total_assigned'] ?? 0) - ($stats['external_count'] ?? 0));
    $totalResponses = ($performance['internal']['total_responses_given'] ?? 0) + ($performance['external']['total_responses_given'] ?? 0);
    $completedMessages = ($stats['disetujui'] ?? 0) + ($stats['selesai'] ?? 0);
    $avgResponseTime = number_format($stats['avg_response_time'] ?? 0, 1) . ' jam';
    
    // Baris 1 - KPI Cards
    $y = $pdf->GetY();
    $pdf->KPICard(15, $y, 'Total Pesan', number_format($stats['total_assigned'] ?? 0), 'Periode ini', [13, 110, 253]);
    $pdf->KPICard(85, $y, 'Pesan External', number_format($stats['external_count'] ?? 0), 'Tanpa Login', [255, 193, 7]);
    $pdf->KPICard(155, $y, 'Pesan Internal', number_format($internalMessages), 'User Terdaftar', [13, 110, 253]);
    $pdf->KPICard(225, $y, 'Rata Respons', $avgResponseTime, 'Seluruh pesan', [255, 193, 7]);
    
    // Baris 2 - KPI Cards
    $pdf->SetY($y + 40);
    $y = $pdf->GetY();
    $pdf->KPICard(15, $y, 'Respons Diberikan', number_format($totalResponses), 'Total respons Anda', [40, 167, 69]);
    $pdf->KPICard(85, $y, 'Pesan Selesai', number_format($completedMessages), 'Disetujui/Selesai', [40, 167, 69]);
    $pdf->KPICard(155, $y, 'Pesan Ditolak', number_format($stats['ditolak'] ?? 0), 'Perlu evaluasi', [220, 53, 69]);
    $pdf->KPICard(225, $y, 'Pesan Expired', number_format($stats['expired'] ?? 0), '>72 jam', [108, 117, 125]);
    
    $pdf->SetY($y + 40);
    $pdf->Ln(5);
    
    // ========================================
    // SECTION 2: GRAFIK ANALISIS - 2 KOLOM
    // ========================================
    $pdf->SectionTitle('ANALISIS GRAFIK', [13, 110, 253]);
    
    // PIE CHART - Distribusi Sumber
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(13, 110, 253);
    $pdf->Cell(130, 7, 'Distribusi Sumber Pesan', 0, 0, 'L');
    $pdf->Cell(130, 7, 'Trend Pesan Harian', 0, 1, 'L');
    $pdf->Ln(2);
    
    $chartY = $pdf->GetY();
    
    // Grafik Pie
    $pieImage = generatePieChartImage($sourceDist, 400, 250);
    $pdf->InsertChart($pieImage, 15, $chartY, 130, 75);
    
    // Grafik Bar
    $barImage = generateBarChartImage($chartLabels, $chartInternalData, $chartExternalData, 500, 250);
    $pdf->InsertChart($barImage, 155, $chartY, 140, 75);
    
    $pdf->SetY($chartY + 85);
    $pdf->Ln(5);
    
    // ========================================
    // SECTION 3: STATISTIK DETAIL
    // ========================================
    $pdf->SectionTitle('STATISTIK DETAIL PESAN', [13, 110, 253]);
    
    // Header Tabel
    $headers = ['Status', 'Jumlah', 'Persentase'];
    $widths = [90, 50, 50];
    $pdf->TableHeader($headers, $widths, [13, 110, 253]);
    
    // Data Statistik
    $totalMessages = max(1, $stats['total_assigned'] ?? 1);
    $statsItems = [
        ['Pending', $stats['pending'] ?? 0],
        ['Dibaca', $stats['dibaca'] ?? 0],
        ['Diproses', $stats['diproses'] ?? 0],
        ['Disetujui', $stats['disetujui'] ?? 0],
        ['Ditolak', $stats['ditolak'] ?? 0],
        ['Selesai', $stats['selesai'] ?? 0],
        ['Expired', $stats['expired'] ?? 0],
        ['External', $stats['external_count'] ?? 0]
    ];
    
    $rowCount = 0;
    foreach ($statsItems as $item) {
        $percentage = round(($item[1] / $totalMessages) * 100, 1);
        
        // Warna teks sesuai status
        switch($item[0]) {
            case 'Pending': $pdf->SetTextColor(255, 193, 7); break;
            case 'Disetujui': $pdf->SetTextColor(40, 167, 69); break;
            case 'Ditolak': $pdf->SetTextColor(220, 53, 69); break;
            case 'Diproses': $pdf->SetTextColor(13, 110, 253); break;
            case 'Dibaca': $pdf->SetTextColor(23, 162, 184); break;
            case 'Selesai': $pdf->SetTextColor(108, 117, 125); break;
            case 'Expired': $pdf->SetTextColor(220, 53, 69); break;
            case 'External': $pdf->SetTextColor(255, 193, 7); break;
            default: $pdf->SetTextColor(33, 37, 41);
        }
        
        $pdf->Cell($widths[0], 9, '  ' . $item[0], 1, 0, 'L', ($rowCount % 2 == 0));
        $pdf->SetTextColor(33, 37, 41);
        $pdf->Cell($widths[1], 9, number_format($item[1]), 1, 0, 'C', ($rowCount % 2 == 0));
        $pdf->Cell($widths[2], 9, $percentage . '%', 1, 1, 'C', ($rowCount % 2 == 0));
        $rowCount++;
    }
    
    $pdf->Ln(12);
    
    // ========================================
    // SECTION 4: PERFORMANCE RESPONS
    // ========================================
    $pdf->SectionTitle('PERFORMANCE RESPONS ANDA', [40, 167, 69]);
    
    // Header Tabel
    $headers2 = ['Kategori', 'Internal', 'External', 'Total'];
    $widths2 = [100, 50, 50, 50];
    $pdf->TableHeader($headers2, $widths2, [40, 167, 69]);
    
    // Data Performance
    $perfRows = [
        [
            'Pesan Ditangani',
            $performance['internal']['total_messages_handled'] ?? 0,
            $performance['external']['total_messages_handled'] ?? 0,
            ($performance['internal']['total_messages_handled'] ?? 0) + ($performance['external']['total_messages_handled'] ?? 0)
        ],
        [
            'Pesan Diselesaikan',
            $performance['internal']['messages_resolved'] ?? 0,
            $performance['external']['messages_resolved'] ?? 0,
            ($performance['internal']['messages_resolved'] ?? 0) + ($performance['external']['messages_resolved'] ?? 0)
        ],
        [
            'Respons Diberikan',
            $performance['internal']['total_responses_given'] ?? 0,
            $performance['external']['total_responses_given'] ?? 0,
            ($performance['internal']['total_responses_given'] ?? 0) + ($performance['external']['total_responses_given'] ?? 0)
        ],
        [
            'Rata Waktu Respons (jam)',
            number_format($performance['internal']['avg_response_time'] ?? 0, 1),
            number_format($performance['external']['avg_response_time'] ?? 0, 1),
            number_format($stats['avg_response_time'] ?? 0, 1)
        ]
    ];
    
    $rowCount = 0;
    foreach ($perfRows as $row) {
        $pdf->Cell($widths2[0], 9, '  ' . $row[0], 1, 0, 'L', ($rowCount % 2 == 0));
        $pdf->Cell($widths2[1], 9, $row[1], 1, 0, 'C', ($rowCount % 2 == 0));
        $pdf->Cell($widths2[2], 9, $row[2], 1, 0, 'C', ($rowCount % 2 == 0));
        $pdf->Cell($widths2[3], 9, $row[3], 1, 1, 'C', ($rowCount % 2 == 0));
        $rowCount++;
    }
    
    $pdf->Ln(12);
    
    // ========================================
    // SECTION 5: DISTRIBUSI SUMBER
    // ========================================
    $pdf->SectionTitle('DISTRIBUSI SUMBER PESAN', [255, 193, 7]);
    
    // Header Tabel
    $headers3 = ['Sumber', 'Jumlah', 'Persentase'];
    $widths3 = [120, 50, 50];
    $pdf->TableHeader($headers3, $widths3, [255, 193, 7]);
    
    // Data Distribusi
    $rowCount = 0;
    foreach ($sourceDist as $source) {
        $pdf->Cell($widths3[0], 9, '  ' . $source['source_type'], 1, 0, 'L', ($rowCount % 2 == 0));
        $pdf->Cell($widths3[1], 9, number_format($source['count']), 1, 0, 'C', ($rowCount % 2 == 0));
        $pdf->Cell($widths3[2], 9, $source['percentage'] . '%', 1, 1, 'C', ($rowCount % 2 == 0));
        $rowCount++;
    }
    
    // Total
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($widths3[0], 9, '  TOTAL', 1, 0, 'L', true);
    $pdf->Cell($widths3[1], 9, number_format($stats['total_assigned'] ?? 0), 1, 0, 'C', true);
    $pdf->Cell($widths3[2], 9, '100%', 1, 1, 'C', true);
    
    $pdf->Ln(12);
    
    // ========================================
    // CEK APAKAH PERLU HALAMAN BARU
    // ========================================
    if ($pdf->GetY() > 150 || !empty($topExternalSenders) || !empty($recentActivity)) {
        $pdf->AddPage();
    }
    
    // ========================================
    // SECTION 6: TOP EXTERNAL SENDERS
    // ========================================
    if (!empty($topExternalSenders)) {
        $pdf->SectionTitle('TOP PENGIRIM EXTERNAL', [255, 193, 7]);
        
        // Header Tabel
        $headers4 = ['No', 'Nama Pengirim', 'Identitas', 'Jumlah', 'Status'];
        $widths4 = [15, 80, 55, 35, 70];
        $pdf->TableHeader($headers4, $widths4, [255, 193, 7]);
        
        // Data
        $no = 1;
        $rowCount = 0;
        foreach ($topExternalSenders as $sender) {
            $rowData = [
                $no++,
                substr($sender['nama_lengkap'] ?? '-', 0, 30),
                $sender['identitas'] ?? 'External',
                $sender['message_count'] ?? 0,
                substr($sender['status_list'] ?? '-', 0, 35)
            ];
            $pdf->TableRow($rowData, $widths4, ($rowCount % 2 == 0));
            $rowCount++;
        }
        $pdf->Ln(12);
    }
    
    // ========================================
    // SECTION 7: AKTIVITAS TERBARU
    // ========================================
    if (!empty($recentActivity)) {
        $pdf->SectionTitle('AKTIVITAS TERBARU (5 PESAN TERAKHIR)', [23, 162, 184]);
        
        // Header Tabel
        $headers5 = ['Waktu', 'Pengirim', 'Info', 'Isi Pesan', 'Status', 'Sumber'];
        $widths5 = [35, 60, 30, 70, 30, 30];
        $pdf->TableHeader($headers5, $widths5, [23, 162, 184]);
        
        // Data
        $rowCount = 0;
        foreach ($recentActivity as $activity) {
            $pdf->SetFont('Arial', '', 8);
            
            $pdf->Cell($widths5[0], 8, date('d/m/Y H:i', strtotime($activity['created_at'])), 1, 0, 'C', ($rowCount % 2 == 0));
            $pdf->Cell($widths5[1], 8, substr($activity['sender_name'] ?? 'Unknown', 0, 20), 1, 0, 'L', ($rowCount % 2 == 0));
            $pdf->Cell($widths5[2], 8, substr($activity['sender_info'] ?? '-', 0, 8), 1, 0, 'C', ($rowCount % 2 == 0));
            $pdf->Cell($widths5[3], 8, substr($activity['isi_pesan'], 0, 40) . '...', 1, 0, 'L', ($rowCount % 2 == 0));
            
            // Status dengan warna
            switch($activity['status']) {
                case 'Disetujui':
                case 'Selesai':
                    $pdf->SetTextColor(40, 167, 69);
                    break;
                case 'Ditolak':
                    $pdf->SetTextColor(220, 53, 69);
                    break;
                case 'Diproses':
                    $pdf->SetTextColor(13, 110, 253);
                    break;
                case 'Dibaca':
                    $pdf->SetTextColor(23, 162, 184);
                    break;
                default:
                    $pdf->SetTextColor(255, 193, 7);
            }
            $pdf->Cell($widths5[4], 8, $activity['status'], 1, 0, 'C', ($rowCount % 2 == 0));
            $pdf->SetTextColor(33, 37, 41);
            
            // Sumber
            $sumber = $activity['is_external'] ? 'External' : 'Internal';
            if ($activity['is_external']) {
                $pdf->SetTextColor(255, 193, 7);
            } else {
                $pdf->SetTextColor(13, 110, 253);
            }
            $pdf->Cell($widths5[5], 8, $sumber, 1, 1, 'C', ($rowCount % 2 == 0));
            $pdf->SetTextColor(33, 37, 41);
            $rowCount++;
        }
    }
    
    // ========================================
    // OUTPUT PDF
    // ========================================
    $filename = 'Dashboard_' . str_replace(' ', '_', $assignedType) . '_' . date('Ymd_His') . '.pdf';
    $pdf->Output('D', $filename);
    exit;
    
} elseif ($format === 'excel') {
    // ========================================
    // ✅ EXCEL DENGAN PHPSPREADSHEET 5.x - LAYOUT PROFESIONAL
    // ========================================
    
    $autoloadPath = 'C:/xampp/htdocs/responsive-message-app/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        ob_end_clean();
        die('PHPSpreadsheet tidak ditemukan. Jalankan: composer require phpoffice/phpspreadsheet:^5.0');
    }
    require_once $autoloadPath;
    
    // ========================================
    // SET CHART RENDERER
    // ========================================
    try {
        if (class_exists('PhpOffice\PhpSpreadsheet\Chart\Renderer\MtJpGraph')) {
            Settings::setChartRenderer(MtJpGraph::class);
        }
    } catch (Exception $e) {
        error_log("JpGraph not installed. Charts will be disabled.");
    }
    
    try {
        $spreadsheet = new Spreadsheet();
        
        // ========================================
        // SHEET 1: DASHBOARD UTAMA (DENGAN GRAFIK)
        // ========================================
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dashboard Utama');
        
        // Setting lebar kolom
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(20);
        $sheet->getColumnDimension('G')->setWidth(20);
        $sheet->getColumnDimension('H')->setWidth(20);
        
        // HEADER UTAMA
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', APP_NAME);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(24);
        $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FF0D6EFD');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->mergeCells('A2:F2');
        $sheet->setCellValue('A2', 'LAPORAN DASHBOARD GURU');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(18);
        $sheet->getStyle('A2')->getFont()->getColor()->setARGB('FF6C757D');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->mergeCells('A3:F3');
        $sheet->setCellValue('A3', str_replace('_', ' ', $guruType) . ' - ' . $assignedType);
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->mergeCells('A4:F4');
        $sheet->setCellValue('A4', 'Periode: ' . date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)) . ' (' . $periodText . ')');
        $sheet->getStyle('A4')->getFont()->setSize(12);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->mergeCells('A5:F5');
        $sheet->setCellValue('A5', 'Dicetak oleh: ' . ($_SESSION['nama_lengkap'] ?? $guruType) . ' | Tanggal: ' . date('d/m/Y H:i:s'));
        $sheet->getStyle('A5')->getFont()->setSize(11);
        $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->getStyle('A6:F6')->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
        $sheet->getStyle('A6:F6')->getBorders()->getTop()->getColor()->setARGB('FF0D6EFD');
        
        // RINGKASAN EKSEKUTIF
        $row = 8;
        $sheet->mergeCells('A' . $row . ':F' . $row);
        $sheet->setCellValue('A' . $row, 'RINGKASAN EKSEKUTIF');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row += 2;
        $internalMessages = max(0, ($stats['total_assigned'] ?? 0) - ($stats['external_count'] ?? 0));
        $totalResponses = ($performance['internal']['total_responses_given'] ?? 0) + ($performance['external']['total_responses_given'] ?? 0);
        $completedMessages = ($stats['disetujui'] ?? 0) + ($stats['selesai'] ?? 0);
        
        // Baris 1: KPI Cards
        $sheet->setCellValue('A' . $row, 'TOTAL PESAN:');
        $sheet->setCellValue('B' . $row, number_format($stats['total_assigned'] ?? 0));
        $sheet->setCellValue('C' . $row, 'EXTERNAL:');
        $sheet->setCellValue('D' . $row, number_format($stats['external_count'] ?? 0));
        $sheet->setCellValue('E' . $row, 'INTERNAL:');
        $sheet->setCellValue('F' . $row, number_format($internalMessages));
        
        $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
        $sheet->getStyle('B' . $row)->getFont()->getColor()->setARGB('FF0D6EFD');
        $sheet->getStyle('D' . $row)->getFont()->getColor()->setARGB('FFFFC107');
        $sheet->getStyle('F' . $row)->getFont()->getColor()->setARGB('FF0D6EFD');
        
        $row += 2;
        $sheet->setCellValue('A' . $row, 'RATA RESPONS:');
        $sheet->setCellValue('B' . $row, number_format($stats['avg_response_time'] ?? 0, 1) . ' jam');
        $sheet->setCellValue('C' . $row, 'RESPONS DIBERIKAN:');
        $sheet->setCellValue('D' . $row, number_format($totalResponses));
        $sheet->setCellValue('E' . $row, 'PESAN SELESAI:');
        $sheet->setCellValue('F' . $row, number_format($completedMessages));
        
        $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
        $sheet->getStyle('B' . $row)->getFont()->getColor()->setARGB('FF0D6EFD');
        $sheet->getStyle('D' . $row)->getFont()->getColor()->setARGB('FF28A745');
        $sheet->getStyle('F' . $row)->getFont()->getColor()->setARGB('FF28A745');
        
        $row += 2;
        $sheet->setCellValue('A' . $row, 'PESAN DITOLAK:');
        $sheet->setCellValue('B' . $row, number_format($stats['ditolak'] ?? 0));
        $sheet->setCellValue('C' . $row, 'PESAN EXPIRED:');
        $sheet->setCellValue('D' . $row, number_format($stats['expired'] ?? 0));
        $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
        $sheet->getStyle('B' . $row)->getFont()->getColor()->setARGB('FFDC3545');
        $sheet->getStyle('D' . $row)->getFont()->getColor()->setARGB('FF6C757D');
        
        $row += 3;
        
        // GRAFIK SECTION
        $sheet->mergeCells('A' . $row . ':F' . $row);
        $sheet->setCellValue('A' . $row, 'ANALISIS GRAFIK');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row += 2;
        
        // DATA UNTUK PIE CHART
        $pieDataRow = $row;
        $sheet->setCellValue('A' . $pieDataRow, 'Sumber');
        $sheet->setCellValue('B' . $pieDataRow, 'Jumlah');
        $sheet->getStyle('A' . $pieDataRow . ':B' . $pieDataRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $pieDataRow . ':B' . $pieDataRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
        
        $pieDataStartRow = $pieDataRow + 1;
        foreach ($sourceDist as $idx => $source) {
            $sheet->setCellValue('A' . ($pieDataStartRow + $idx), $source['source_type']);
            $sheet->setCellValue('B' . ($pieDataStartRow + $idx), $source['count']);
        }
        $pieDataEndRow = $pieDataStartRow + count($sourceDist) - 1;
        
        // PIE CHART
        if (class_exists('PhpOffice\PhpSpreadsheet\Chart\Renderer\MtJpGraph')) {
            try {
                $pieDataSeriesLabels = [
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Dashboard Utama!$A$' . $pieDataStartRow, null, 1),
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Dashboard Utama!$A$' . ($pieDataStartRow + 1), null, 1)
                ];
                
                $pieXAxisTickValues = [
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Dashboard Utama!$A$' . $pieDataStartRow . ':$A$' . $pieDataEndRow, null, count($sourceDist))
                ];
                
                $pieDataSeriesValues = [
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, 'Dashboard Utama!$B$' . $pieDataStartRow . ':$B$' . $pieDataEndRow, null, count($sourceDist))
                ];
                
                $pieSeries = new DataSeries(
                    DataSeries::TYPE_PIECHART,
                    null,
                    range(0, count($pieDataSeriesValues) - 1),
                    $pieDataSeriesLabels,
                    $pieXAxisTickValues,
                    $pieDataSeriesValues
                );
                
                $pieLayout = new Layout();
                $pieLayout->setShowVal(true);
                $pieLayout->setShowPercent(true);
                
                $piePlotArea = new PlotArea($pieLayout, [$pieSeries]);
                $pieLegend = new Legend(Legend::POSITION_RIGHT, null, false);
                $pieTitle = new Title('Distribusi Internal vs External');
                
                $pieChart = new Chart(
                    'pie_chart',
                    $pieTitle,
                    $pieLegend,
                    $piePlotArea,
                    true,
                    0,
                    null,
                    null
                );
                
                $pieChart->setTopLeftPosition('H' . ($pieDataRow - 1));
                $pieChart->setBottomRightPosition('R' . ($pieDataRow + 15));
                $sheet->addChart($pieChart);
                
            } catch (Exception $e) {
                error_log("Error creating pie chart: " . $e->getMessage());
            }
        }
        
        // DATA UNTUK BAR CHART
        $barRow = $pieDataRow + 20;
        $sheet->setCellValue('A' . $barRow, 'Tanggal');
        $sheet->setCellValue('B' . $barRow, 'Internal');
        $sheet->setCellValue('C' . $barRow, 'External');
        $sheet->getStyle('A' . $barRow . ':C' . $barRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $barRow . ':C' . $barRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
        
        $barDataStartRow = $barRow + 1;
        for ($i = 0; $i < count($chartLabels); $i++) {
            $sheet->setCellValue('A' . ($barDataStartRow + $i), $chartLabels[$i]);
            $sheet->setCellValue('B' . ($barDataStartRow + $i), $chartInternalData[$i]);
            $sheet->setCellValue('C' . ($barDataStartRow + $i), $chartExternalData[$i]);
        }
        $barDataEndRow = $barDataStartRow + count($chartLabels) - 1;
        
        // BAR CHART
        if (class_exists('PhpOffice\PhpSpreadsheet\Chart\Renderer\MtJpGraph')) {
            try {
                $barDataSeriesLabels = [
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Dashboard Utama!$B$' . $barRow, null, 1),
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Dashboard Utama!$C$' . $barRow, null, 1)
                ];
                
                $barXAxisTickValues = [
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Dashboard Utama!$A$' . $barDataStartRow . ':$A$' . $barDataEndRow, null, count($chartLabels))
                ];
                
                $barDataSeriesValues = [
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, 'Dashboard Utama!$B$' . $barDataStartRow . ':$B$' . $barDataEndRow, null, count($chartLabels)),
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, 'Dashboard Utama!$C$' . $barDataStartRow . ':$C$' . $barDataEndRow, null, count($chartLabels))
                ];
                
                $barSeries = new DataSeries(
                    DataSeries::TYPE_BARCHART,
                    DataSeries::GROUPING_CLUSTERED,
                    range(0, count($barDataSeriesValues) - 1),
                    $barDataSeriesLabels,
                    $barXAxisTickValues,
                    $barDataSeriesValues
                );
                
                $barPlotArea = new PlotArea(null, [$barSeries]);
                $barTitle = new Title('Trend Pesan Harian');
                
                $barChart = new Chart(
                    'bar_chart',
                    $barTitle,
                    null,
                    $barPlotArea,
                    true,
                    0,
                    null,
                    null
                );
                
                $barChart->setTopLeftPosition('H' . ($barRow - 1));
                $barChart->setBottomRightPosition('R' . ($barDataEndRow + 15));
                $sheet->addChart($barChart);
                
            } catch (Exception $e) {
                error_log("Error creating bar chart: " . $e->getMessage());
            }
        }
        
        // ========================================
        // SHEET 2: STATISTIK DETAIL
        // ========================================
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Statistik Detail');
        
        $sheet2->getColumnDimension('A')->setWidth(30);
        $sheet2->getColumnDimension('B')->setWidth(20);
        $sheet2->getColumnDimension('C')->setWidth(20);
        
        $row2 = 1;
        $sheet2->mergeCells('A' . $row2 . ':C' . $row2);
        $sheet2->setCellValue('A' . $row2, 'STATISTIK DETAIL PESAN');
        $sheet2->getStyle('A' . $row2)->getFont()->setBold(true)->setSize(16);
        $sheet2->getStyle('A' . $row2)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D6EFD');
        $sheet2->getStyle('A' . $row2)->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet2->getStyle('A' . $row2)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row2 += 2;
        $sheet2->setCellValue('A' . $row2, 'Status');
        $sheet2->setCellValue('B' . $row2, 'Jumlah');
        $sheet2->setCellValue('C' . $row2, 'Persentase');
        $sheet2->getStyle('A' . $row2 . ':C' . $row2)->getFont()->setBold(true);
        $sheet2->getStyle('A' . $row2 . ':C' . $row2)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
        
        $row2++;
        $totalMessages = max(1, $stats['total_assigned'] ?? 1);
        $statsItems = [
            ['Pending', $stats['pending'] ?? 0],
            ['Dibaca', $stats['dibaca'] ?? 0],
            ['Diproses', $stats['diproses'] ?? 0],
            ['Disetujui', $stats['disetujui'] ?? 0],
            ['Ditolak', $stats['ditolak'] ?? 0],
            ['Selesai', $stats['selesai'] ?? 0],
            ['Expired', $stats['expired'] ?? 0],
            ['External', $stats['external_count'] ?? 0]
        ];
        
        foreach ($statsItems as $item) {
            $sheet2->setCellValue('A' . $row2, $item[0]);
            $sheet2->setCellValue('B' . $row2, $item[1]);
            $sheet2->setCellValue('C' . $row2, round(($item[1] / $totalMessages) * 100, 1) . '%');
            $row2++;
        }
        
        // ========================================
        // SHEET 3: PERFORMANCE
        // ========================================
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Performance');
        
        $sheet3->getColumnDimension('A')->setWidth(40);
        $sheet3->getColumnDimension('B')->setWidth(20);
        $sheet3->getColumnDimension('C')->setWidth(20);
        $sheet3->getColumnDimension('D')->setWidth(20);
        
        $row3 = 1;
        $sheet3->mergeCells('A' . $row3 . ':D' . $row3);
        $sheet3->setCellValue('A' . $row3, 'PERFORMANCE RESPONS ANDA');
        $sheet3->getStyle('A' . $row3)->getFont()->setBold(true)->setSize(16);
        $sheet3->getStyle('A' . $row3)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF28A745');
        $sheet3->getStyle('A' . $row3)->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet3->getStyle('A' . $row3)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row3 += 2;
        $sheet3->setCellValue('A' . $row3, 'Kategori');
        $sheet3->setCellValue('B' . $row3, 'Internal');
        $sheet3->setCellValue('C' . $row3, 'External');
        $sheet3->setCellValue('D' . $row3, 'Total');
        $sheet3->getStyle('A' . $row3 . ':D' . $row3)->getFont()->setBold(true);
        $sheet3->getStyle('A' . $row3 . ':D' . $row3)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
        
        $row3++;
        $perfRows = [
            ['Pesan Ditangani', 
                $performance['internal']['total_messages_handled'] ?? 0,
                $performance['external']['total_messages_handled'] ?? 0,
                ($performance['internal']['total_messages_handled'] ?? 0) + ($performance['external']['total_messages_handled'] ?? 0)],
            ['Pesan Diselesaikan',
                $performance['internal']['messages_resolved'] ?? 0,
                $performance['external']['messages_resolved'] ?? 0,
                ($performance['internal']['messages_resolved'] ?? 0) + ($performance['external']['messages_resolved'] ?? 0)],
            ['Respons Diberikan',
                $performance['internal']['total_responses_given'] ?? 0,
                $performance['external']['total_responses_given'] ?? 0,
                ($performance['internal']['total_responses_given'] ?? 0) + ($performance['external']['total_responses_given'] ?? 0)],
            ['Rata Waktu Respons (jam)',
                $performance['internal']['avg_response_time'] ?? 0,
                $performance['external']['avg_response_time'] ?? 0,
                $stats['avg_response_time'] ?? 0]
        ];
        
        foreach ($perfRows as $perf) {
            $sheet3->setCellValue('A' . $row3, $perf[0]);
            $sheet3->setCellValue('B' . $row3, $perf[1]);
            $sheet3->setCellValue('C' . $row3, $perf[2]);
            $sheet3->setCellValue('D' . $row3, $perf[3]);
            $row3++;
        }
        
        // ========================================
        // SHEET 4: TOP EXTERNAL
        // ========================================
        if (!empty($topExternalSenders)) {
            $sheet4 = $spreadsheet->createSheet();
            $sheet4->setTitle('Top External');
            
            $sheet4->getColumnDimension('A')->setWidth(10);
            $sheet4->getColumnDimension('B')->setWidth(35);
            $sheet4->getColumnDimension('C')->setWidth(25);
            $sheet4->getColumnDimension('D')->setWidth(15);
            $sheet4->getColumnDimension('E')->setWidth(40);
            
            $row4 = 1;
            $sheet4->mergeCells('A' . $row4 . ':E' . $row4);
            $sheet4->setCellValue('A' . $row4, 'TOP PENGIRIM EXTERNAL');
            $sheet4->getStyle('A' . $row4)->getFont()->setBold(true)->setSize(16);
            $sheet4->getStyle('A' . $row4)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC107');
            $sheet4->getStyle('A' . $row4)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            $row4 += 2;
            $sheet4->setCellValue('A' . $row4, 'No');
            $sheet4->setCellValue('B' . $row4, 'Nama Pengirim');
            $sheet4->setCellValue('C' . $row4, 'Identitas');
            $sheet4->setCellValue('D' . $row4, 'Jumlah');
            $sheet4->setCellValue('E' . $row4, 'Status');
            $sheet4->getStyle('A' . $row4 . ':E' . $row4)->getFont()->setBold(true);
            $sheet4->getStyle('A' . $row4 . ':E' . $row4)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
            
            $row4++;
            foreach ($topExternalSenders as $index => $sender) {
                $sheet4->setCellValue('A' . $row4, $index + 1);
                $sheet4->setCellValue('B' . $row4, $sender['nama_lengkap'] ?? '-');
                $sheet4->setCellValue('C' . $row4, $sender['identitas'] ?? 'External');
                $sheet4->setCellValue('D' . $row4, $sender['message_count'] ?? 0);
                $sheet4->setCellValue('E' . $row4, $sender['status_list'] ?? '-');
                $row4++;
            }
        }
        
        // ========================================
        // SHEET 5: AKTIVITAS TERBARU
        // ========================================
        if (!empty($recentActivity)) {
            $sheet5 = $spreadsheet->createSheet();
            $sheet5->setTitle('Aktivitas Terbaru');
            
            $sheet5->getColumnDimension('A')->setWidth(20);
            $sheet5->getColumnDimension('B')->setWidth(30);
            $sheet5->getColumnDimension('C')->setWidth(15);
            $sheet5->getColumnDimension('D')->setWidth(50);
            $sheet5->getColumnDimension('E')->setWidth(15);
            $sheet5->getColumnDimension('F')->setWidth(15);
            
            $row5 = 1;
            $sheet5->mergeCells('A' . $row5 . ':F' . $row5);
            $sheet5->setCellValue('A' . $row5, 'AKTIVITAS TERBARU (5 PESAN TERAKHIR)');
            $sheet5->getStyle('A' . $row5)->getFont()->setBold(true)->setSize(16);
            $sheet5->getStyle('A' . $row5)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF17A2B8');
            $sheet5->getStyle('A' . $row5)->getFont()->getColor()->setARGB('FFFFFFFF');
            $sheet5->getStyle('A' . $row5)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            $row5 += 2;
            $sheet5->setCellValue('A' . $row5, 'Waktu');
            $sheet5->setCellValue('B' . $row5, 'Pengirim');
            $sheet5->setCellValue('C' . $row5, 'Info');
            $sheet5->setCellValue('D' . $row5, 'Isi Pesan');
            $sheet5->setCellValue('E' . $row5, 'Status');
            $sheet5->setCellValue('F' . $row5, 'Sumber');
            $sheet5->getStyle('A' . $row5 . ':F' . $row5)->getFont()->setBold(true);
            $sheet5->getStyle('A' . $row5 . ':F' . $row5)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
            
            $row5++;
            foreach ($recentActivity as $activity) {
                $sheet5->setCellValue('A' . $row5, date('d/m/Y H:i', strtotime($activity['created_at'])));
                $sheet5->setCellValue('B' . $row5, $activity['sender_name'] ?? 'Unknown');
                $sheet5->setCellValue('C' . $row5, $activity['sender_info'] ?? '-');
                $sheet5->setCellValue('D' . $row5, $activity['isi_pesan']);
                $sheet5->setCellValue('E' . $row5, $activity['status']);
                $sheet5->setCellValue('F' . $row5, $activity['is_external'] ? 'External' : 'Internal');
                $row5++;
            }
        }
        
        // ========================================
        // ANTI CORRUPT PROTOCOL - TEMP FILE
        // ========================================
        while (ob_get_level()) ob_end_clean();
        
        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts(class_exists('PhpOffice\PhpSpreadsheet\Chart\Renderer\MtJpGraph'));
        
        $tempFile = tempnam(sys_get_temp_dir(), 'dashboard_');
        $writer->save($tempFile);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Dashboard_' . str_replace(' ', '_', $assignedType) . '_' . date('Ymd_His') . '.xlsx"');
        header('Cache-Control: max-age=0, no-cache, must-revalidate');
        header('Content-Length: ' . filesize($tempFile));
        
        readfile($tempFile);
        unlink($tempFile);
        exit;
        
    } catch (Exception $e) {
        while (ob_get_level()) ob_end_clean();
        error_log("PHPSpreadsheet Error: " . $e->getMessage());
        die('Error: ' . $e->getMessage());
    }
    
} else {
    ob_end_clean();
    die('Format tidak didukung');
}
?>