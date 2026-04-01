-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Feb 19, 2026 at 03:14 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `responsive_message_db`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `cleanup_old_notification_logs` (IN `days_to_keep` INT)   BEGIN
    DELETE FROM `notification_logs` 
    WHERE `sent_at` < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetMessageStatistics` (IN `p_user_id` INT, IN `p_start_date` DATE, IN `p_end_date` DATE)   BEGIN
    SELECT 
        COUNT(*) as total_messages,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Dibaca' THEN 1 ELSE 0 END) as dibaca,
        SUM(CASE WHEN status = 'Diproses' THEN 1 ELSE 0 END) as diproses,
        SUM(CASE WHEN status = 'Disetujui' THEN 1 ELSE 0 END) as disetujui,
        SUM(CASE WHEN status = 'Ditolak' THEN 1 ELSE 0 END) as ditolak,
        SUM(CASE WHEN status = 'Expired' THEN 1 ELSE 0 END) as expired,
        SUM(CASE WHEN priority = 'Urgent' THEN 1 ELSE 0 END) as urgent,
        AVG(TIMESTAMPDIFF(HOUR, created_at, COALESCE(tanggal_respon, NOW()))) as avg_response_hours
    FROM messages
    WHERE pengirim_id = p_user_id
    AND DATE(created_at) BETWEEN p_start_date AND p_end_date;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetPendingFollowUps` (IN `p_guru_type` VARCHAR(20))   BEGIN
    SELECT 
        m.*,
        mt.jenis_pesan,
        u.nama_lengkap as pengirim_nama,
        u.kelas,
        u.jurusan,
        TIMESTAMPDIFF(HOUR, m.created_at, NOW()) as hours_since_created,
        (mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) as hours_remaining,
        CASE 
            WHEN (mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) <= 0 THEN 'Expired'
            WHEN (mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) <= 24 THEN 'Urgent'
            ELSE 'Normal'
        END as urgency_level
    FROM messages m
    INNER JOIN message_types mt ON m.jenis_pesan_id = mt.id
    INNER JOIN users u ON m.pengirim_id = u.id
    WHERE mt.responder_type = p_guru_type
    AND m.status IN ('Pending', 'Dibaca', 'Diproses')
    AND (m.expired_at IS NULL OR m.expired_at > NOW())
    ORDER BY 
        CASE 
            WHEN m.priority = 'Urgent' THEN 1
            WHEN m.priority = 'High' THEN 2
            WHEN m.priority = 'Medium' THEN 3
            ELSE 4
        END,
        hours_remaining ASC;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `CalculateMessageAge` (`p_message_id` INT) RETURNS INT(11) DETERMINISTIC BEGIN
    DECLARE age_hours INT;
    
    SELECT TIMESTAMPDIFF(HOUR, created_at, NOW()) INTO age_hours
    FROM messages WHERE id = p_message_id;
    
    RETURN COALESCE(age_hours, 0);
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `GetStatusColor` (`p_status` VARCHAR(20)) RETURNS VARCHAR(7) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC BEGIN
    RETURN CASE p_status
        WHEN 'Pending' THEN '#ffc107'
        WHEN 'Dibaca' THEN '#0dcaf0'
        WHEN 'Diproses' THEN '#0d6efd'
        WHEN 'Disetujui' THEN '#198754'
        WHEN 'Ditolak' THEN '#dc3545'
        WHEN 'Selesai' THEN '#6c757d'
        WHEN 'Expired' THEN '#212529'
        WHEN 'Dibatalkan' THEN '#6c757d'
        ELSE '#6c757d'
    END;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `api_tokens`
--

CREATE TABLE `api_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_value`)),
  `new_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_value`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_method` varchar(10) DEFAULT NULL,
  `request_url` text DEFAULT NULL,
  `response_code` int(11) DEFAULT NULL,
  `execution_time_ms` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action_type`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `user_agent`, `request_method`, `request_url`, `response_code`, `execution_time_ms`, `created_at`) VALUES
(1, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:22:14'),
(2, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:22:29'),
(3, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:22:43'),
(4, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:22:56'),
(5, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:23:07'),
(6, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:23:26'),
(7, 7, 'UPDATE', 'users', 7, '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:23:39'),
(8, 8, 'UPDATE', 'users', 8, '{\"username\": \"guru007\", \"user_type\": \"Wakil_Kepala\", \"nama_lengkap\": \"Joko Widodo\", \"email\": \"joko.widodo@smkn12jakarta.sch.id\", \"nis_nip\": \"198301017\", \"is_active\": 1}', '{\"username\": \"guru007\", \"user_type\": \"Wakil_Kepala\", \"nama_lengkap\": \"Joko Widodo\", \"email\": \"joko.widodo@smkn12jakarta.sch.id\", \"nis_nip\": \"198301017\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:23:51'),
(9, 9, 'UPDATE', 'users', 9, '{\"username\": \"guru008\", \"user_type\": \"Kepala_Sekolah\", \"nama_lengkap\": \"Sri Mulyani\", \"email\": \"sri.mulyani@smkn12jakarta.sch.id\", \"nis_nip\": \"198301018\", \"is_active\": 1}', '{\"username\": \"guru008\", \"user_type\": \"Kepala_Sekolah\", \"nama_lengkap\": \"Sri Mulyani\", \"email\": \"sri.mulyani@smkn12jakarta.sch.id\", \"nis_nip\": \"198301018\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:24:02'),
(10, 10, 'UPDATE', 'users', 10, '{\"username\": \"guru009\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Agus Salim\", \"email\": \"agus.salim@smkn12jakarta.sch.id\", \"nis_nip\": \"198301019\", \"is_active\": 1}', '{\"username\": \"guru009\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Agus Salim\", \"email\": \"agus.salim@smkn12jakarta.sch.id\", \"nis_nip\": \"198301019\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:24:13'),
(11, 11, 'UPDATE', 'users', 11, '{\"username\": \"guru010\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Rina Marlina\", \"email\": \"rina.marlina@smkn12jakarta.sch.id\", \"nis_nip\": \"198301020\", \"is_active\": 1}', '{\"username\": \"guru010\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Rina Marlina\", \"email\": \"rina.marlina@smkn12jakarta.sch.id\", \"nis_nip\": \"198301020\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:24:24'),
(12, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:24:38'),
(13, 13, 'UPDATE', 'users', 13, '{\"username\": \"siswa002\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Budi Pratama\", \"email\": \"budi.pratama@smkn12jakarta.sch.id\", \"nis_nip\": \"20230002\", \"is_active\": 1}', '{\"username\": \"siswa002\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Budi Pratama\", \"email\": \"budi.pratama@smkn12jakarta.sch.id\", \"nis_nip\": \"20230002\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:24:54'),
(14, 14, 'UPDATE', 'users', 14, '{\"username\": \"siswa003\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Citra Dewi\", \"email\": \"citra.dewi@smkn12jakarta.sch.id\", \"nis_nip\": \"20230003\", \"is_active\": 1}', '{\"username\": \"siswa003\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Citra Dewi\", \"email\": \"citra.dewi@smkn12jakarta.sch.id\", \"nis_nip\": \"20230003\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:25:12'),
(15, 15, 'UPDATE', 'users', 15, '{\"username\": \"siswa004\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Dedi Kurniawan\", \"email\": \"dedi.kurniawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230004\", \"is_active\": 1}', '{\"username\": \"siswa004\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Dedi Kurniawan\", \"email\": \"dedi.kurniawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230004\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:25:26'),
(16, 16, 'UPDATE', 'users', 16, '{\"username\": \"siswa005\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Eka Putri\", \"email\": \"eka.putri@smkn12jakarta.sch.id\", \"nis_nip\": \"20230005\", \"is_active\": 1}', '{\"username\": \"siswa005\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Eka Putri\", \"email\": \"eka.putri@smkn12jakarta.sch.id\", \"nis_nip\": \"20230005\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:25:39'),
(17, 17, 'UPDATE', 'users', 17, '{\"username\": \"siswa006\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Fajar Ramadan\", \"email\": \"fajar.ramadan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230006\", \"is_active\": 1}', '{\"username\": \"siswa006\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Fajar Ramadan\", \"email\": \"fajar.ramadan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230006\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:25:52'),
(18, 18, 'UPDATE', 'users', 18, '{\"username\": \"siswa007\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Gita Maya\", \"email\": \"gita.maya@smkn12jakarta.sch.id\", \"nis_nip\": \"20230007\", \"is_active\": 1}', '{\"username\": \"siswa007\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Gita Maya\", \"email\": \"gita.maya@smkn12jakarta.sch.id\", \"nis_nip\": \"20230007\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:26:04'),
(19, 19, 'UPDATE', 'users', 19, '{\"username\": \"siswa008\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Hendra Wijaya\", \"email\": \"hendra.wijaya@smkn12jakarta.sch.id\", \"nis_nip\": \"20230008\", \"is_active\": 1}', '{\"username\": \"siswa008\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Hendra Wijaya\", \"email\": \"hendra.wijaya@smkn12jakarta.sch.id\", \"nis_nip\": \"20230008\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:26:16'),
(20, 20, 'UPDATE', 'users', 20, '{\"username\": \"siswa009\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Indah Permata\", \"email\": \"indah.permata@smkn12jakarta.sch.id\", \"nis_nip\": \"20230009\", \"is_active\": 1}', '{\"username\": \"siswa009\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Indah Permata\", \"email\": \"indah.permata@smkn12jakarta.sch.id\", \"nis_nip\": \"20230009\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:26:29'),
(21, 21, 'UPDATE', 'users', 21, '{\"username\": \"siswa010\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Joko Susilo\", \"email\": \"joko.susilo@smkn12jakarta.sch.id\", \"nis_nip\": \"20230010\", \"is_active\": 1}', '{\"username\": \"siswa010\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Joko Susilo\", \"email\": \"joko.susilo@smkn12jakarta.sch.id\", \"nis_nip\": \"20230010\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:26:42'),
(22, 22, 'UPDATE', 'users', 22, '{\"username\": \"siswa011\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Kartika Sari\", \"email\": \"kartika.sari@smkn12jakarta.sch.id\", \"nis_nip\": \"20230011\", \"is_active\": 1}', '{\"username\": \"siswa011\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Kartika Sari\", \"email\": \"kartika.sari@smkn12jakarta.sch.id\", \"nis_nip\": \"20230011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:26:57'),
(23, 23, 'UPDATE', 'users', 23, '{\"username\": \"siswa012\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Lukman Hakim\", \"email\": \"lukman.hakim@smkn12jakarta.sch.id\", \"nis_nip\": \"20230012\", \"is_active\": 1}', '{\"username\": \"siswa012\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Lukman Hakim\", \"email\": \"lukman.hakim@smkn12jakarta.sch.id\", \"nis_nip\": \"20230012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:27:16'),
(24, 24, 'UPDATE', 'users', 24, '{\"username\": \"siswa013\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Maya Sari\", \"email\": \"maya.sari@smkn12jakarta.sch.id\", \"nis_nip\": \"20230013\", \"is_active\": 1}', '{\"username\": \"siswa013\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Maya Sari\", \"email\": \"maya.sari@smkn12jakarta.sch.id\", \"nis_nip\": \"20230013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:27:29'),
(25, 25, 'UPDATE', 'users', 25, '{\"username\": \"siswa014\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Nur Hidayat\", \"email\": \"nur.hidayat@smkn12jakarta.sch.id\", \"nis_nip\": \"20230014\", \"is_active\": 1}', '{\"username\": \"siswa014\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Nur Hidayat\", \"email\": \"nur.hidayat@smkn12jakarta.sch.id\", \"nis_nip\": \"20230014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:27:44'),
(26, 26, 'UPDATE', 'users', 26, '{\"username\": \"siswa015\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Oktavia Ayu\", \"email\": \"oktavia.ayu@smkn12jakarta.sch.id\", \"nis_nip\": \"20230015\", \"is_active\": 1}', '{\"username\": \"siswa015\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Oktavia Ayu\", \"email\": \"oktavia.ayu@smkn12jakarta.sch.id\", \"nis_nip\": \"20230015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:28:19'),
(27, 27, 'UPDATE', 'users', 27, '{\"username\": \"siswa016\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Pratama Adit\", \"email\": \"pratama.adit@smkn12jakarta.sch.id\", \"nis_nip\": \"20230016\", \"is_active\": 1}', '{\"username\": \"siswa016\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Pratama Adit\", \"email\": \"pratama.adit@smkn12jakarta.sch.id\", \"nis_nip\": \"20230016\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:28:31'),
(28, 28, 'UPDATE', 'users', 28, '{\"username\": \"siswa017\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Qory Sandioriva\", \"email\": \"qory.sandioriva@smkn12jakarta.sch.id\", \"nis_nip\": \"20230017\", \"is_active\": 1}', '{\"username\": \"siswa017\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Qory Sandioriva\", \"email\": \"qory.sandioriva@smkn12jakarta.sch.id\", \"nis_nip\": \"20230017\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:28:41'),
(29, 29, 'UPDATE', 'users', 29, '{\"username\": \"siswa018\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Rahmat Hidayat\", \"email\": \"rahmat.hidayat@smkn12jakarta.sch.id\", \"nis_nip\": \"20230018\", \"is_active\": 1}', '{\"username\": \"siswa018\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Rahmat Hidayat\", \"email\": \"rahmat.hidayat@smkn12jakarta.sch.id\", \"nis_nip\": \"20230018\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:28:52'),
(30, 30, 'UPDATE', 'users', 30, '{\"username\": \"siswa019\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Siti Nurhaliza\", \"email\": \"siti.nurhaliza@smkn12jakarta.sch.id\", \"nis_nip\": \"20230019\", \"is_active\": 1}', '{\"username\": \"siswa019\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Siti Nurhaliza\", \"email\": \"siti.nurhaliza@smkn12jakarta.sch.id\", \"nis_nip\": \"20230019\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:29:03'),
(31, 31, 'UPDATE', 'users', 31, '{\"username\": \"siswa020\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Taufik Hidayat\", \"email\": \"taufik.hidayat@smkn12jakarta.sch.id\", \"nis_nip\": \"20230020\", \"is_active\": 1}', '{\"username\": \"siswa020\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Taufik Hidayat\", \"email\": \"taufik.hidayat@smkn12jakarta.sch.id\", \"nis_nip\": \"20230020\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:29:14'),
(32, 32, 'UPDATE', 'users', 32, '{\"username\": \"siswa021\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ummu Kultsum\", \"email\": \"ummu.kultsum@smkn12jakarta.sch.id\", \"nis_nip\": \"20230021\", \"is_active\": 1}', '{\"username\": \"siswa021\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ummu Kultsum\", \"email\": \"ummu.kultsum@smkn12jakarta.sch.id\", \"nis_nip\": \"20230021\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:29:25'),
(33, 33, 'UPDATE', 'users', 33, '{\"username\": \"siswa022\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Vina Panduwinata\", \"email\": \"vina.panduwinata@smkn12jakarta.sch.id\", \"nis_nip\": \"20230022\", \"is_active\": 1}', '{\"username\": \"siswa022\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Vina Panduwinata\", \"email\": \"vina.panduwinata@smkn12jakarta.sch.id\", \"nis_nip\": \"20230022\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:29:36'),
(34, 34, 'UPDATE', 'users', 34, '{\"username\": \"siswa023\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Wawan Setiawan\", \"email\": \"wawan.setiawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230023\", \"is_active\": 1}', '{\"username\": \"siswa023\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Wawan Setiawan\", \"email\": \"wawan.setiawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230023\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:29:54'),
(35, 35, 'UPDATE', 'users', 35, '{\"username\": \"siswa024\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Xavier Smith\", \"email\": \"xavier.smith@smkn12jakarta.sch.id\", \"nis_nip\": \"20230024\", \"is_active\": 1}', '{\"username\": \"siswa024\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Xavier Smith\", \"email\": \"xavier.smith@smkn12jakarta.sch.id\", \"nis_nip\": \"20230024\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:30:06'),
(36, 36, 'UPDATE', 'users', 36, '{\"username\": \"siswa025\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Yudi Hermawan\", \"email\": \"yudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230025\", \"is_active\": 1}', '{\"username\": \"siswa025\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Yudi Hermawan\", \"email\": \"yudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230025\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:30:20'),
(37, 37, 'UPDATE', 'users', 37, '{\"username\": \"siswa026\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Zainal Abidin\", \"email\": \"zainal.abidin@smkn12jakarta.sch.id\", \"nis_nip\": \"20230026\", \"is_active\": 1}', '{\"username\": \"siswa026\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Zainal Abidin\", \"email\": \"zainal.abidin@smkn12jakarta.sch.id\", \"nis_nip\": \"20230026\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:30:34'),
(38, 38, 'UPDATE', 'users', 38, '{\"username\": \"siswa027\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ade Rahmawan\", \"email\": \"ade.rahmawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230027\", \"is_active\": 1}', '{\"username\": \"siswa027\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ade Rahmawan\", \"email\": \"ade.rahmawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230027\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:30:46'),
(39, 39, 'UPDATE', 'users', 39, '{\"username\": \"siswa028\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Bambang Sutrisno\", \"email\": \"bambang.sutrisno@smkn12jakarta.sch.id\", \"nis_nip\": \"20230028\", \"is_active\": 1}', '{\"username\": \"siswa028\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Bambang Sutrisno\", \"email\": \"bambang.sutrisno@smkn12jakarta.sch.id\", \"nis_nip\": \"20230028\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:31:00'),
(40, 40, 'UPDATE', 'users', 40, '{\"username\": \"siswa029\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Cindy Gultom\", \"email\": \"cindy.gultom@smkn12jakarta.sch.id\", \"nis_nip\": \"20230029\", \"is_active\": 1}', '{\"username\": \"siswa029\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Cindy Gultom\", \"email\": \"cindy.gultom@smkn12jakarta.sch.id\", \"nis_nip\": \"20230029\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:31:15'),
(41, 41, 'UPDATE', 'users', 41, '{\"username\": \"siswa030\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Dian Sastrowardoyo\", \"email\": \"dian.sastrowardoyo@smkn12jakarta.sch.id\", \"nis_nip\": \"20230030\", \"is_active\": 1}', '{\"username\": \"siswa030\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Dian Sastrowardoyo\", \"email\": \"dian.sastrowardoyo@smkn12jakarta.sch.id\", \"nis_nip\": \"20230030\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:31:29'),
(42, 42, 'UPDATE', 'users', 42, '{\"username\": \"siswa031\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Erik Setiawan\", \"email\": \"erik.setiawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230031\", \"is_active\": 1}', '{\"username\": \"siswa031\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Erik Setiawan\", \"email\": \"erik.setiawan@smkn12jakarta.sch.id\", \"nis_nip\": \"20230031\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:31:43'),
(43, 43, 'UPDATE', 'users', 43, '{\"username\": \"siswa032\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Fitri Wulandari\", \"email\": \"fitri.wulandari@smkn12jakarta.sch.id\", \"nis_nip\": \"20230032\", \"is_active\": 1}', '{\"username\": \"siswa032\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Fitri Wulandari\", \"email\": \"fitri.wulandari@smkn12jakarta.sch.id\", \"nis_nip\": \"20230032\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:31:55'),
(44, 44, 'UPDATE', 'users', 44, '{\"username\": \"siswa033\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Guntur Sukarno\", \"email\": \"guntur.sukarno@smkn12jakarta.sch.id\", \"nis_nip\": \"20230033\", \"is_active\": 1}', '{\"username\": \"siswa033\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Guntur Sukarno\", \"email\": \"guntur.sukarno@smkn12jakarta.sch.id\", \"nis_nip\": \"20230033\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:32:08'),
(45, 45, 'UPDATE', 'users', 45, '{\"username\": \"siswa034\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Hani Susanti\", \"email\": \"hani.susanti@smkn12jakarta.sch.id\", \"nis_nip\": \"20230034\", \"is_active\": 1}', '{\"username\": \"siswa034\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Hani Susanti\", \"email\": \"hani.susanti@smkn12jakarta.sch.id\", \"nis_nip\": \"20230034\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:32:24'),
(46, 46, 'UPDATE', 'users', 46, '{\"username\": \"siswa035\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Irfan Bachdim\", \"email\": \"irfan.bachdim@smkn12jakarta.sch.id\", \"nis_nip\": \"20230035\", \"is_active\": 1}', '{\"username\": \"siswa035\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Irfan Bachdim\", \"email\": \"irfan.bachdim@smkn12jakarta.sch.id\", \"nis_nip\": \"20230035\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:32:41'),
(47, 47, 'UPDATE', 'users', 47, '{\"username\": \"siswa036\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Jessica Mila\", \"email\": \"jessica.mila@smkn12jakarta.sch.id\", \"nis_nip\": \"20230036\", \"is_active\": 1}', '{\"username\": \"siswa036\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Jessica Mila\", \"email\": \"jessica.mila@smkn12jakarta.sch.id\", \"nis_nip\": \"20230036\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:32:59'),
(48, 48, 'UPDATE', 'users', 48, '{\"username\": \"siswa037\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Kevin Anggara\", \"email\": \"kevin.anggara@smkn12jakarta.sch.id\", \"nis_nip\": \"20230037\", \"is_active\": 1}', '{\"username\": \"siswa037\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Kevin Anggara\", \"email\": \"kevin.anggara@smkn12jakarta.sch.id\", \"nis_nip\": \"20230037\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:33:15'),
(49, 49, 'UPDATE', 'users', 49, '{\"username\": \"siswa038\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Luna Maya\", \"email\": \"luna.maya@smkn12jakarta.sch.id\", \"nis_nip\": \"20230038\", \"is_active\": 1}', '{\"username\": \"siswa038\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Luna Maya\", \"email\": \"luna.maya@smkn12jakarta.sch.id\", \"nis_nip\": \"20230038\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:33:29'),
(50, 50, 'UPDATE', 'users', 50, '{\"username\": \"siswa039\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Mario Teguh\", \"email\": \"mario.teguh@smkn12jakarta.sch.id\", \"nis_nip\": \"20230039\", \"is_active\": 1}', '{\"username\": \"siswa039\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Mario Teguh\", \"email\": \"mario.teguh@smkn12jakarta.sch.id\", \"nis_nip\": \"20230039\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:33:46'),
(51, 51, 'UPDATE', 'users', 51, '{\"username\": \"siswa040\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Nurul Arifin\", \"email\": \"nurul.arifin@smkn12jakarta.sch.id\", \"nis_nip\": \"20230040\", \"is_active\": 1}', '{\"username\": \"siswa040\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Nurul Arifin\", \"email\": \"nurul.arifin@smkn12jakarta.sch.id\", \"nis_nip\": \"20230040\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:34:06'),
(52, 52, 'UPDATE', 'users', 52, '{\"username\": \"ortu001\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Slamet Riyadi\", \"email\": \"slamet.riyadi@gmail.com\", \"nis_nip\": \"OT2023001\", \"is_active\": 1}', '{\"username\": \"ortu001\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Slamet Riyadi\", \"email\": \"slamet.riyadi@gmail.com\", \"nis_nip\": \"OT2023001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:34:16'),
(53, 53, 'UPDATE', 'users', 53, '{\"username\": \"ortu002\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Murni Lestari\", \"email\": \"murni.lestari@gmail.com\", \"nis_nip\": \"OT2023002\", \"is_active\": 1}', '{\"username\": \"ortu002\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Murni Lestari\", \"email\": \"murni.lestari@gmail.com\", \"nis_nip\": \"OT2023002\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:34:26'),
(54, 54, 'UPDATE', 'users', 54, '{\"username\": \"ortu003\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Tono Wijaya\", \"email\": \"tono.wijaya@gmail.com\", \"nis_nip\": \"OT2023003\", \"is_active\": 1}', '{\"username\": \"ortu003\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Tono Wijaya\", \"email\": \"tono.wijaya@gmail.com\", \"nis_nip\": \"OT2023003\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:34:37'),
(55, 55, 'UPDATE', 'users', 55, '{\"username\": \"ortu004\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Sari Dewi\", \"email\": \"sari.dewi@gmail.com\", \"nis_nip\": \"OT2023004\", \"is_active\": 1}', '{\"username\": \"ortu004\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Sari Dewi\", \"email\": \"sari.dewi@gmail.com\", \"nis_nip\": \"OT2023004\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:34:47'),
(56, 56, 'UPDATE', 'users', 56, '{\"username\": \"ortu005\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Bambang Setiawan\", \"email\": \"bambang.setiawan@gmail.com\", \"nis_nip\": \"OT2023005\", \"is_active\": 1}', '{\"username\": \"ortu005\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"Bambang Setiawan\", \"email\": \"bambang.setiawan@gmail.com\", \"nis_nip\": \"OT2023005\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:34:56'),
(57, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:36:53'),
(58, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-09 05:38:41'),
(59, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:38:46'),
(60, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-09 05:38:57'),
(61, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:39:02'),
(62, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-09 05:50:22'),
(63, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:50:26'),
(64, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-09 06:29:28'),
(65, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 06:29:36'),
(66, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-09 06:35:43'),
(67, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 06:36:22'),
(68, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-09 07:43:48'),
(69, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 07:43:52'),
(70, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-09 08:29:24'),
(71, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 08:29:29'),
(72, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-09 10:33:42'),
(73, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-09 10:33:48'),
(74, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-09 11:20:21'),
(75, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-10 10:01:14'),
(76, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-10 10:18:22'),
(77, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-10 10:18:26'),
(78, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-10 10:19:07'),
(79, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-10 10:19:13'),
(80, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-10 13:17:17'),
(81, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-10 13:17:21'),
(82, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-10 13:19:20'),
(83, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-10 13:19:26'),
(84, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 02:00:16'),
(85, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 02:00:28'),
(86, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 02:00:44'),
(87, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 02:00:48'),
(88, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 02:00:57'),
(89, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 02:01:09'),
(90, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 03:20:23'),
(91, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 03:20:28'),
(92, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 04:13:04'),
(93, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 04:13:10'),
(94, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 04:13:16'),
(95, 13, 'UPDATE', 'users', 13, '{\"username\": \"siswa002\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Budi Pratama\", \"email\": \"budi.pratama@smkn12jakarta.sch.id\", \"nis_nip\": \"20230002\", \"is_active\": 1}', '{\"username\": \"siswa002\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Budi Pratama\", \"email\": \"budi.pratama@smkn12jakarta.sch.id\", \"nis_nip\": \"20230002\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 04:13:20'),
(96, 13, 'LOGOUT', 'users', 13, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 04:13:26'),
(97, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 04:13:30'),
(98, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 04:13:53'),
(99, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 04:13:56'),
(100, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 05:24:57'),
(101, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 05:25:08'),
(102, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 05:25:20'),
(103, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 05:25:24'),
(104, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 05:38:11'),
(105, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 07:02:18'),
(106, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 07:02:23'),
(107, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 07:02:28'),
(108, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 07:02:32'),
(109, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-11 07:02:36'),
(110, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-11 07:05:07'),
(111, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 04:37:42'),
(112, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 04:40:01'),
(113, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 04:40:11'),
(114, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 04:44:05'),
(115, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 04:44:14'),
(116, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 04:46:16'),
(117, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 04:47:03'),
(118, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 05:09:09'),
(119, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 05:09:22'),
(120, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 06:13:16'),
(121, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 07:24:14'),
(122, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 07:32:46'),
(123, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 07:59:03'),
(124, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 07:59:07'),
(125, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 07:59:14');
INSERT INTO `audit_logs` (`id`, `user_id`, `action_type`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `user_agent`, `request_method`, `request_url`, `response_code`, `execution_time_ms`, `created_at`) VALUES
(126, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 07:59:19'),
(127, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 11:53:54'),
(128, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 11:54:02'),
(129, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 13:50:14'),
(130, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 13:50:25'),
(131, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-12 13:50:40'),
(132, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-12 13:51:49'),
(133, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 01:09:02'),
(134, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 01:09:19'),
(135, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 02:58:49'),
(136, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 02:58:53'),
(137, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 03:48:23'),
(138, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 03:51:30'),
(139, 6, 'LOGOUT', 'users', 6, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 03:53:56'),
(140, NULL, 'INSERT', 'users', 57, NULL, '{\"username\": \"ext_1770957898648\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 04:44:59'),
(141, NULL, 'UPDATE', 'users', 57, '{\"username\": \"ext_1770957898648\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_1770957898648\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 06:56:39'),
(142, NULL, 'INSERT', 'users', 58, NULL, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"wahono agung\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 07:08:52'),
(143, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"wahono agung\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"wahono agung\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 07:09:59'),
(144, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"wahono agung\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 07:09:59'),
(145, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 07:32:08'),
(146, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 07:32:08'),
(147, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 07:32:24'),
(148, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 07:32:24'),
(149, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 07:41:53'),
(150, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 07:41:53'),
(151, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:02:19'),
(152, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:02:19'),
(153, NULL, 'INSERT', 'users', 59, NULL, '{\"username\": \"ext_17709700022003\", \"user_type\": \"\", \"nama_lengkap\": \"didi\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:06:42'),
(154, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:18:29'),
(155, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dudu\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dedi\", \"email\": \"agung.wahono2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:18:29'),
(156, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dedi\", \"email\": \"agung.wahono2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dedi\", \"email\": \"agung.wahono2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:20:35'),
(157, NULL, 'UPDATE', 'users', 58, '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dedi\", \"email\": \"agung.wahono2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17709665328787\", \"user_type\": \"\", \"nama_lengkap\": \"dedi\", \"email\": \"agung.wahono2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:20:35'),
(158, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:35:42'),
(159, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 08:35:54'),
(160, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:35:59'),
(161, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 08:36:05'),
(162, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:36:09'),
(163, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 08:36:15'),
(164, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 08:36:19'),
(165, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 09:27:23'),
(166, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 09:27:31'),
(167, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 09:27:36'),
(168, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 09:27:57'),
(169, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 09:28:00'),
(170, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 10:28:37'),
(171, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 10:28:55'),
(172, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 22:39:08'),
(173, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 22:39:15'),
(174, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 22:39:22'),
(175, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 22:39:37'),
(176, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:13:25'),
(177, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:14:01'),
(178, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:14:05'),
(179, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:16:13'),
(180, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:16:19'),
(181, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:17:14'),
(182, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:18:24'),
(183, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:19:36'),
(184, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:19:41'),
(185, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:22:31'),
(186, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:22:40'),
(187, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:24:31'),
(188, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:24:38'),
(189, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:25:25'),
(190, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:25:30'),
(191, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:26:21'),
(192, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:30:21'),
(193, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:30:24'),
(194, NULL, 'INSERT', 'users', 60, NULL, '{\"username\": \"ext_17710266708149\", \"user_type\": \"\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:51:10'),
(195, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:53:05'),
(196, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:53:14'),
(197, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:53:17'),
(198, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-13 23:53:22'),
(199, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 23:53:26'),
(200, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 00:00:19'),
(201, NULL, 'INSERT', 'users', 61, NULL, '{\"username\": \"ext_17710273266713\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 00:02:06'),
(202, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 00:03:06'),
(203, 6, 'LOGOUT', 'users', 6, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 00:03:12'),
(204, 8, 'UPDATE', 'users', 8, '{\"username\": \"guru007\", \"user_type\": \"Wakil_Kepala\", \"nama_lengkap\": \"Joko Widodo\", \"email\": \"joko.widodo@smkn12jakarta.sch.id\", \"nis_nip\": \"198301017\", \"is_active\": 1}', '{\"username\": \"guru007\", \"user_type\": \"Wakil_Kepala\", \"nama_lengkap\": \"Joko Widodo\", \"email\": \"joko.widodo@smkn12jakarta.sch.id\", \"nis_nip\": \"198301017\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 00:03:22'),
(205, 8, 'LOGOUT', 'users', 8, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 00:03:38'),
(206, 7, 'UPDATE', 'users', 7, '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 00:04:09'),
(207, 7, 'LOGOUT', 'users', 7, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 00:12:03'),
(208, 7, 'UPDATE', 'users', 7, '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 00:18:04'),
(209, 7, 'LOGOUT', 'users', 7, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 01:27:31'),
(210, 7, 'UPDATE', 'users', 7, '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 06:44:23'),
(211, 7, 'LOGOUT', 'users', 7, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 06:44:36'),
(212, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 06:44:41'),
(213, 6, 'LOGOUT', 'users', 6, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 06:44:44'),
(214, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 06:44:49'),
(215, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:00:21'),
(216, NULL, 'INSERT', 'users', 62, NULL, '{\"username\": \"ext_17710528752536\", \"user_type\": \"\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:07:56'),
(217, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:08:59'),
(218, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:09:04'),
(219, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:09:08'),
(220, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:09:16'),
(221, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:09:19'),
(222, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:09:23'),
(223, 7, 'UPDATE', 'users', 7, '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', '{\"username\": \"guru006\", \"user_type\": \"Guru_Sarana\", \"nama_lengkap\": \"Maya Indah\", \"email\": \"maya.indah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301016\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:09:28'),
(224, 7, 'LOGOUT', 'users', 7, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:09:33'),
(225, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:09:38'),
(226, 6, 'LOGOUT', 'users', 6, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:12:50'),
(227, NULL, 'INSERT', 'users', 63, NULL, '{\"username\": \"ext_17710540802788\", \"user_type\": \"\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:28:00'),
(228, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:28:11'),
(229, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:28:17'),
(230, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:28:21'),
(231, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:28:26'),
(232, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:28:31'),
(233, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:54:54'),
(234, NULL, 'INSERT', 'users', 64, NULL, '{\"username\": \"ext_17710557458824\", \"user_type\": \"\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:55:45'),
(235, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:56:02'),
(236, 6, 'LOGOUT', 'users', 6, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:56:06'),
(237, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 07:56:10'),
(238, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 07:59:38'),
(239, NULL, 'INSERT', 'users', 65, NULL, '{\"username\": \"ext_17710578172240\", \"user_type\": \"\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 08:30:17'),
(240, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 08:30:52'),
(241, 6, 'LOGOUT', 'users', 6, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 10:28:30'),
(242, NULL, 'INSERT', 'users', 66, NULL, '{\"username\": \"ext_17710650005094\", \"user_type\": \"\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 10:30:00'),
(243, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 10:30:59'),
(244, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 11:02:24'),
(245, NULL, 'INSERT', 'users', 67, NULL, '{\"username\": \"ext_17710671118962\", \"user_type\": \"\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 11:05:11'),
(246, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 11:05:22'),
(247, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 11:05:30'),
(248, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 11:05:35'),
(249, 5, 'LOGOUT', 'users', 5, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 11:05:41'),
(250, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 11:05:45'),
(251, 6, 'LOGOUT', 'users', 6, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-14 11:09:05'),
(252, NULL, 'INSERT', 'users', 68, NULL, '{\"username\": \"ext_17711143056135\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 00:11:45'),
(253, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 00:14:29'),
(254, 6, 'LOGOUT', 'users', 6, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 01:46:43'),
(255, NULL, 'UPDATE', 'users', 68, '{\"username\": \"ext_17711143056135\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17711143056135\", \"user_type\": \"\", \"nama_lengkap\": \"agung\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 02:07:07'),
(256, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 02:07:18'),
(257, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 02:10:58'),
(258, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 04:00:51'),
(259, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 04:02:41'),
(260, NULL, 'INSERT', 'users', 69, NULL, '{\"username\": \"ext_17711295131510\", \"user_type\": \"\", \"nama_lengkap\": \"Nurhayati\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 04:25:14'),
(261, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 04:26:18'),
(262, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 04:26:39'),
(263, NULL, 'UPDATE', 'users', 69, '{\"username\": \"ext_17711295131510\", \"user_type\": \"\", \"nama_lengkap\": \"Nurhayati\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17711295131510\", \"user_type\": \"\", \"nama_lengkap\": \"dede\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 04:28:43'),
(264, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 04:28:53'),
(265, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 04:36:26'),
(266, NULL, 'INSERT', 'users', 70, NULL, '{\"username\": \"ext_17711302826146\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 04:38:02'),
(267, NULL, 'UPDATE', 'users', 70, '{\"username\": \"ext_17711302826146\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17711302826146\", \"user_type\": \"\", \"nama_lengkap\": \"agung\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 04:40:25'),
(268, NULL, 'INSERT', 'users', 71, NULL, '{\"username\": \"ext_17711327073697\", \"user_type\": \"\", \"nama_lengkap\": \"agung\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 05:18:27'),
(269, NULL, 'INSERT', 'users', 72, NULL, '{\"username\": \"ext_17711327921113\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agungway2901@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 05:19:52'),
(270, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 05:20:37');
INSERT INTO `audit_logs` (`id`, `user_id`, `action_type`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `user_agent`, `request_method`, `request_url`, `response_code`, `execution_time_ms`, `created_at`) VALUES
(271, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 09:26:53'),
(272, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 09:26:57'),
(273, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 11:12:01'),
(274, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 11:16:09'),
(275, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 11:16:29'),
(276, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 11:22:46'),
(277, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 11:23:10'),
(278, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 11:23:32'),
(279, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 12:01:15'),
(280, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 12:04:23'),
(281, NULL, 'INSERT', 'users', 73, NULL, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 12:34:02'),
(282, NULL, 'REGISTER', 'users', 73, NULL, '{\"username\":\"dede12\",\"user_type\":\"Siswa\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 12:34:02'),
(283, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 12:36:34'),
(284, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:19:11'),
(285, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:19:18'),
(286, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:22:26'),
(287, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:22:33'),
(288, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:22:59'),
(289, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:25:01'),
(290, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:35:45'),
(291, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:35:50'),
(292, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:38:34'),
(293, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:38:39'),
(294, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:38:48'),
(295, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:38:56'),
(296, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:39:02'),
(297, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:39:08'),
(298, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:39:23'),
(299, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:39:28'),
(300, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:39:45'),
(301, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:41:04'),
(302, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:41:18'),
(303, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:43:53'),
(304, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:45:29'),
(305, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:45:39'),
(306, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:45:52'),
(307, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:45:58'),
(308, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:50:39'),
(309, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:50:46'),
(310, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:50:59'),
(311, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:51:03'),
(312, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:51:12'),
(313, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:51:21'),
(314, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 13:51:28'),
(315, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 13:51:38'),
(316, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:00:26'),
(317, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:00:37'),
(318, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:01:02'),
(319, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:01:11'),
(320, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:01:30'),
(321, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:01:34'),
(322, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:06:05'),
(323, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:10:22'),
(324, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:10:26'),
(325, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:10:34'),
(326, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:10:40'),
(327, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:10:53'),
(328, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:11:27'),
(329, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:11:31'),
(330, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:11:35'),
(331, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:11:40'),
(332, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:11:44'),
(333, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:11:50'),
(334, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:11:53'),
(335, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:11:58'),
(336, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:15:17'),
(337, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:15:22'),
(338, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:15:27'),
(339, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:15:37'),
(340, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:17:00'),
(341, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:17:06'),
(342, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:19:06'),
(343, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:19:13'),
(344, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:19:17'),
(345, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:19:25'),
(346, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:19:31'),
(347, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:19:37'),
(348, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:19:41'),
(349, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:19:48'),
(350, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:33:36'),
(351, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:33:41'),
(352, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:33:45'),
(353, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:33:51'),
(354, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:34:01'),
(355, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:34:07'),
(356, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:35:13'),
(357, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:35:21'),
(358, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:35:24'),
(359, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:35:31'),
(360, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:40:46'),
(361, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:40:53'),
(362, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:54:04'),
(363, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:54:07'),
(364, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:54:12'),
(365, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:54:15'),
(366, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:54:23'),
(367, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:54:32'),
(368, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 14:54:43'),
(369, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 14:54:54'),
(370, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:04:10'),
(371, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:11:36'),
(372, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:12:34'),
(373, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:12:44'),
(374, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:12:47'),
(375, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:12:59'),
(376, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:13:02'),
(377, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:13:08'),
(378, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:14:36'),
(379, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:14:52'),
(380, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:16:02'),
(381, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:16:06'),
(382, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:16:09'),
(383, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:16:17'),
(384, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:16:21'),
(385, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:16:26'),
(386, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:17:45'),
(387, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:31:29'),
(388, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:31:49'),
(389, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:33:21'),
(390, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:33:24'),
(391, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:33:29'),
(392, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:33:32'),
(393, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:33:41'),
(394, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:33:47'),
(395, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:33:52'),
(396, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:34:07'),
(397, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:37:46'),
(398, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:37:49'),
(399, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:37:54'),
(400, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:38:17'),
(401, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:42:26'),
(402, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:42:30'),
(403, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:42:46'),
(404, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:42:50'),
(405, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:43:08'),
(406, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:43:15'),
(407, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:43:26'),
(408, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:43:53'),
(409, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:43:58'),
(410, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:44:11'),
(411, 12, 'UPDATE', 'users', 12, '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', '{\"username\": \"siswa001\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Ahmad Fadil\", \"email\": \"ahmad.fadil@smkn12jakarta.sch.id\", \"nis_nip\": \"20230001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:44:19'),
(412, 12, 'LOGOUT', 'users', 12, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:44:37'),
(413, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:44:44'),
(414, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:44:47'),
(415, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:45:03'),
(416, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:45:11'),
(417, 13, 'UPDATE', 'users', 13, '{\"username\": \"siswa002\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Budi Pratama\", \"email\": \"budi.pratama@smkn12jakarta.sch.id\", \"nis_nip\": \"20230002\", \"is_active\": 1}', '{\"username\": \"siswa002\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Budi Pratama\", \"email\": \"budi.pratama@smkn12jakarta.sch.id\", \"nis_nip\": \"20230002\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:45:21'),
(418, 13, 'LOGOUT', 'users', 13, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:46:16');
INSERT INTO `audit_logs` (`id`, `user_id`, `action_type`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `user_agent`, `request_method`, `request_url`, `response_code`, `execution_time_ms`, `created_at`) VALUES
(419, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:46:20'),
(420, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:50:26'),
(421, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:50:33'),
(422, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:52:06'),
(423, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:52:09'),
(424, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 15:52:34'),
(425, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-15 15:52:38'),
(426, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 16:05:46'),
(427, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 16:05:51'),
(428, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 16:06:04'),
(429, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 16:06:25'),
(430, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 16:07:16'),
(431, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-15 16:07:35'),
(432, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 01:55:38'),
(433, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 01:55:56'),
(434, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 01:56:49'),
(435, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 01:57:15'),
(436, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 01:59:40'),
(437, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:00:03'),
(438, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:00:11'),
(439, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:00:27'),
(440, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:00:32'),
(441, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:01:18'),
(442, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:01:24'),
(443, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:01:27'),
(444, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:01:30'),
(445, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:01:35'),
(446, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:01:42'),
(447, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:02:09'),
(448, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:02:33'),
(449, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:03:08'),
(450, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:03:13'),
(451, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:03:55'),
(452, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:04:05'),
(453, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:07:12'),
(454, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:07:48'),
(455, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:32:04'),
(456, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:32:08'),
(457, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:32:12'),
(458, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:32:18'),
(459, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:32:23'),
(460, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:32:30'),
(461, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:32:42'),
(462, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:32:46'),
(463, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:33:14'),
(464, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:46:01'),
(465, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:46:04'),
(466, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:46:13'),
(467, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:46:17'),
(468, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:46:23'),
(469, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 02:46:47'),
(470, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 02:58:41'),
(471, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 03:18:28'),
(472, 14, 'UPDATE', 'users', 14, '{\"username\": \"siswa003\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Citra Dewi\", \"email\": \"citra.dewi@smkn12jakarta.sch.id\", \"nis_nip\": \"20230003\", \"is_active\": 1}', '{\"username\": \"siswa003\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Citra Dewi\", \"email\": \"citra.dewi@smkn12jakarta.sch.id\", \"nis_nip\": \"20230003\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 03:18:58'),
(473, 14, 'LOGOUT', 'users', 14, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 03:52:52'),
(474, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 03:53:03'),
(475, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 03:53:07'),
(476, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 03:53:12'),
(477, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 03:53:16'),
(478, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 03:53:26'),
(479, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 03:57:10'),
(480, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 03:57:16'),
(481, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 03:57:28'),
(482, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 03:57:33'),
(483, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 03:59:19'),
(484, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 03:59:39'),
(485, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:00:35'),
(486, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:00:38'),
(487, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:00:46'),
(488, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:00:54'),
(489, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:00:57'),
(490, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:01:10'),
(491, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:01:39'),
(492, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:04:34'),
(493, 4, 'LOGOUT', 'users', 4, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:04:37'),
(494, 10, 'UPDATE', 'users', 10, '{\"username\": \"guru009\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Agus Salim\", \"email\": \"agus.salim@smkn12jakarta.sch.id\", \"nis_nip\": \"198301019\", \"is_active\": 1}', '{\"username\": \"guru009\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Agus Salim\", \"email\": \"agus.salim@smkn12jakarta.sch.id\", \"nis_nip\": \"198301019\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:04:56'),
(495, 10, 'LOGOUT', 'users', 10, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:06:32'),
(496, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:06:37'),
(497, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:23:42'),
(498, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:28:58'),
(499, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:29:02'),
(500, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:29:07'),
(501, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:40:50'),
(502, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:40:54'),
(503, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:40:57'),
(504, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:41:03'),
(505, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:41:07'),
(506, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:41:29'),
(507, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:41:41'),
(508, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:41:47'),
(509, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:41:53'),
(510, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:41:58'),
(511, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:42:08'),
(512, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:46:58'),
(513, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:48:20'),
(514, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:48:24'),
(515, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:48:30'),
(516, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:56:05'),
(517, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:56:08'),
(518, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:56:13'),
(519, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:56:18'),
(520, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:56:23'),
(521, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:57:42'),
(522, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:57:53'),
(523, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 04:58:17'),
(524, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 04:58:22'),
(525, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:01:50'),
(526, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:01:54'),
(527, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:01:58'),
(528, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:02:02'),
(529, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:02:05'),
(530, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:02:10'),
(531, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:16:25'),
(532, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:16:43'),
(533, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:17:37'),
(534, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:17:44'),
(535, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:17:47'),
(536, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:17:53'),
(537, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:17:58'),
(538, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:18:29'),
(539, 2, 'LOGOUT', 'users', 2, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:36:18'),
(540, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:39:24'),
(541, 1, 'LOGOUT', 'users', 1, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:39:28'),
(542, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:39:32'),
(543, 3, 'LOGOUT', 'users', 3, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 05:39:35'),
(544, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:39:40'),
(545, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:59:16'),
(546, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:59:24'),
(547, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 05:59:32'),
(548, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 06:00:48'),
(549, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 06:28:10'),
(550, NULL, 'LOGOUT', 'users', 73, NULL, '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-16 09:27:57'),
(551, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 09:28:27'),
(552, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 09:48:57'),
(553, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 09:51:24'),
(554, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 10:11:23'),
(555, 10, 'UPDATE', 'users', 10, '{\"username\": \"guru009\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Agus Salim\", \"email\": \"agus.salim@smkn12jakarta.sch.id\", \"nis_nip\": \"198301019\", \"is_active\": 1}', '{\"username\": \"guru009\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Agus Salim\", \"email\": \"agus.salim@smkn12jakarta.sch.id\", \"nis_nip\": \"198301019\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 10:11:51'),
(556, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 10:12:12'),
(557, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 10:12:25');
INSERT INTO `audit_logs` (`id`, `user_id`, `action_type`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `user_agent`, `request_method`, `request_url`, `response_code`, `execution_time_ms`, `created_at`) VALUES
(558, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 10:38:42'),
(559, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 10:48:16'),
(560, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 11:03:00'),
(561, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 11:44:07'),
(562, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 12:25:02'),
(563, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 12:43:59'),
(564, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 12:44:36'),
(565, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 12:54:45'),
(566, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 13:09:13'),
(567, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 13:23:37'),
(568, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 13:36:48'),
(569, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 14:15:59'),
(570, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 14:24:10'),
(571, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 15:56:23'),
(572, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-16 16:04:58'),
(573, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 01:45:52'),
(574, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 02:11:53'),
(575, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 02:12:15'),
(576, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 02:12:28'),
(577, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 02:33:58'),
(578, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 02:49:02'),
(579, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 02:50:13'),
(580, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 04:13:26'),
(581, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 04:13:51'),
(582, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 04:14:07'),
(583, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 07:03:54'),
(584, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 07:04:17'),
(585, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 07:05:26'),
(586, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 07:22:52'),
(587, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 07:34:36'),
(588, NULL, 'UPDATE', 'users', 73, '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', '{\"username\": \"dede12\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"dede sunandar\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"23456789\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 07:34:54'),
(589, 2, 'UPDATE', 'users', 2, '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', '{\"username\": \"guru001\", \"user_type\": \"Guru\", \"nama_lengkap\": \"Budi Santoso\", \"email\": \"budi.santoso@smkn12jakarta.sch.id\", \"nis_nip\": \"198301011\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 09:18:11'),
(590, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 09:18:30'),
(591, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 09:19:35'),
(592, NULL, 'INSERT', 'users', 74, NULL, '{\"username\": \"ext_17713217135464\", \"user_type\": \"\", \"nama_lengkap\": \"Wiwi\", \"email\": \"sri.widisastuti261077@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 09:48:33'),
(593, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 09:56:50'),
(594, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 10:29:58'),
(595, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 10:30:37'),
(596, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 10:30:48'),
(597, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 10:45:49'),
(598, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 11:35:23'),
(599, NULL, 'UPDATE', 'users', 74, '{\"username\": \"ext_17713217135464\", \"user_type\": \"\", \"nama_lengkap\": \"Wiwi\", \"email\": \"sri.widisastuti261077@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17713217135464\", \"user_type\": \"\", \"nama_lengkap\": \"Wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 11:55:57'),
(600, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 12:50:17'),
(601, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 12:59:15'),
(602, NULL, 'UPDATE', 'users', 74, '{\"username\": \"ext_17713217135464\", \"user_type\": \"\", \"nama_lengkap\": \"Wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17713217135464\", \"user_type\": \"\", \"nama_lengkap\": \"Wiwi\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 13:15:34'),
(603, NULL, 'UPDATE', 'users', 74, '{\"username\": \"ext_17713217135464\", \"user_type\": \"\", \"nama_lengkap\": \"Wiwi\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', '{\"username\": \"ext_17713217135464\", \"user_type\": \"\", \"nama_lengkap\": \"Wiwi\", \"email\": \"agung.wahono2901@gmail.com\", \"nis_nip\": null, \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 13:20:10'),
(604, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 13:21:06'),
(605, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 13:27:42'),
(606, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 13:30:25'),
(607, NULL, 'INSERT', 'users', 75, NULL, '{\"username\": \"widi26\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": \"OT2026001\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 22:31:26'),
(608, NULL, 'REGISTER', 'users', 75, NULL, '{\"username\":\"widi26\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-18 22:31:26'),
(609, NULL, 'INSERT', 'users', 76, NULL, '{\"username\": \"widi26\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": \"OT2006002\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 22:39:06'),
(610, NULL, 'REGISTER', 'users', 76, NULL, '{\"username\":\"widi26\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-18 22:39:06'),
(611, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 23:15:27'),
(612, NULL, 'INSERT', 'users', 77, NULL, '{\"username\": \"widi26\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"widiastuti\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": \"OT2026001\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 01:20:11'),
(613, NULL, 'REGISTER', 'users', 77, NULL, '{\"username\":\"widi26\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-19 01:20:11'),
(614, NULL, 'UPDATE', 'users', 77, '{\"username\": \"widi26\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"widiastuti\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": \"OT2026001\", \"is_active\": 1}', '{\"username\": \"widi26\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"widiastuti\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": \"OT2026001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 01:22:07'),
(615, NULL, 'INSERT', 'users', 78, NULL, '{\"username\": \"widi26\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"widiastuti\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": \"OT2026001\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 01:30:56'),
(616, NULL, 'REGISTER', 'users', 78, NULL, '{\"username\":\"widi26\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-19 01:30:56'),
(617, NULL, 'INSERT', 'users', 79, NULL, '{\"username\": \"widi2610\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"widiastuti\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": \"OT2026001\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 01:42:59'),
(618, NULL, 'REGISTER', 'users', 79, NULL, '{\"username\":\"widi2610\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-19 01:42:59'),
(619, NULL, 'INSERT', 'users', 80, NULL, '{\"username\": \"widi2610\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"widiastuti\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": \"OT2026001\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 01:54:40'),
(620, NULL, 'REGISTER', 'users', 80, NULL, '{\"username\":\"widi2610\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-19 01:54:40'),
(621, NULL, 'INSERT', 'users', 81, NULL, '{\"username\": \"widi26\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"widiastuti\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": \"OT2026001\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 02:09:13'),
(622, NULL, 'REGISTER', 'users', 81, NULL, '{\"username\":\"widi26\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-19 02:09:13'),
(623, NULL, 'INSERT', 'users', 82, NULL, '{\"username\": \"widi2610\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"widiastuti\", \"email\": \"sri.widiastuti2610@gmail.com\", \"nis_nip\": \"OT2026001\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 02:25:25'),
(624, NULL, 'REGISTER', 'users', 82, NULL, '{\"username\":\"widi2610\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-19 02:25:25'),
(625, NULL, 'INSERT', 'users', 83, NULL, '{\"username\": \"widi10\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": \"OT2026001\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 02:28:25'),
(626, NULL, 'REGISTER', 'users', 83, NULL, '{\"username\":\"widi10\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-19 02:28:25'),
(627, 85, 'INSERT', 'users', 85, NULL, '{\"username\": \"wiwi123\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": \"OT2026001\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 03:02:18'),
(628, 85, 'REGISTER', 'users', 85, NULL, '{\"username\":\"wiwi123\",\"user_type\":\"Orang_Tua\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', NULL, NULL, NULL, NULL, '2026-02-19 03:02:18'),
(629, 85, 'UPDATE', 'users', 85, '{\"username\": \"wiwi123\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": \"OT2026001\", \"is_active\": 1}', '{\"username\": \"wiwi123\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": \"OT2026001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 03:04:24'),
(630, NULL, 'INSERT', 'users', 86, NULL, '{\"username\": \"ext_17714719233112\", \"user_type\": \"\", \"nama_lengkap\": \"wahono\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 03:32:04'),
(631, 87, 'INSERT', 'users', 87, NULL, '{\"username\": \"ext_17714737238574\", \"user_type\": \"\", \"nama_lengkap\": \"wahono agung\", \"email\": \"agung.senen3@gmail.com\", \"nis_nip\": null}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 04:02:03'),
(632, 85, 'UPDATE', 'users', 85, '{\"username\": \"wiwi123\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": \"OT2026001\", \"is_active\": 1}', '{\"username\": \"wiwi123\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": \"OT2026001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 04:03:38'),
(633, 3, 'UPDATE', 'users', 3, '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', '{\"username\": \"guru002\", \"user_type\": \"Guru_BK\", \"nama_lengkap\": \"Siti Aisyah\", \"email\": \"siti.aisyah@smkn12jakarta.sch.id\", \"nis_nip\": \"198301012\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 04:07:16'),
(634, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 04:20:38'),
(635, 85, 'UPDATE', 'users', 85, '{\"username\": \"wiwi123\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": \"OT2026001\", \"is_active\": 1}', '{\"username\": \"wiwi123\", \"user_type\": \"Orang_Tua\", \"nama_lengkap\": \"wiwi\", \"email\": \"sri.widiastuti261077@gmail.com\", \"nis_nip\": \"OT2026001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 04:55:43'),
(636, 5, 'UPDATE', 'users', 5, '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', '{\"username\": \"guru004\", \"user_type\": \"Guru_Kurikulum\", \"nama_lengkap\": \"Dewi Lestari\", \"email\": \"dewi.lestari@smkn12jakarta.sch.id\", \"nis_nip\": \"198301014\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 05:05:31'),
(637, 4, 'UPDATE', 'users', 4, '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', '{\"username\": \"guru003\", \"user_type\": \"Guru_Humas\", \"nama_lengkap\": \"Ahmad Fauzi\", \"email\": \"ahmad.fauzi@smkn12jakarta.sch.id\", \"nis_nip\": \"198301013\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 05:05:42'),
(638, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 05:05:55'),
(639, 6, 'UPDATE', 'users', 6, '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', '{\"username\": \"guru005\", \"user_type\": \"Guru_Kesiswaan\", \"nama_lengkap\": \"Rudi Hermawan\", \"email\": \"rudi.hermawan@smkn12jakarta.sch.id\", \"nis_nip\": \"198301015\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 05:07:52'),
(640, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 05:15:42'),
(641, 1, 'UPDATE', 'users', 1, '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', '{\"username\": \"admin\", \"user_type\": \"Admin\", \"nama_lengkap\": \"Administrator Sistem\", \"email\": \"admin@smkn12jakarta.sch.id\", \"nis_nip\": \"ADM001\", \"is_active\": 1}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 05:17:48'),
(642, NULL, 'INSERT', 'users', 88, NULL, '{\"username\": \"siswa041\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agung.wahono2901@gmail.com\", \"nis_nip\": \"20230041\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 06:29:47'),
(643, NULL, 'INSERT', 'users', 89, NULL, '{\"username\": \"siswa041\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agung.wahono2901@gmail.com\", \"nis_nip\": \"20230041\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 06:34:56'),
(644, NULL, 'INSERT', 'users', 90, NULL, '{\"username\": \"siswa041\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Agung Wahono\", \"email\": \"agung.wahono2901@gmail.com\", \"nis_nip\": \"20230041\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 06:51:58'),
(645, 91, 'INSERT', 'users', 91, NULL, '{\"username\": \"siswa042\", \"user_type\": \"Siswa\", \"nama_lengkap\": \"Iskandarsyah\", \"email\": \"agungwahono7929@gmail.com\", \"nis_nip\": \"20230042\"}', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-19 06:55:32');

-- --------------------------------------------------------

--
-- Table structure for table `backups`
--

CREATE TABLE `backups` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `filepath` varchar(500) NOT NULL,
  `size_bytes` bigint(20) DEFAULT NULL,
  `backup_type` enum('Full','Incremental','Differential') DEFAULT 'Full',
  `status` enum('Success','Failed','In Progress') DEFAULT 'Success',
  `error_message` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_data`
--

CREATE TABLE `cache_data` (
  `cache_key` varchar(255) NOT NULL,
  `cache_value` longtext NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `daily_message_report`
-- (See below for the actual view)
--
CREATE TABLE `daily_message_report` (
`report_date` date
,`total_messages` bigint(21)
,`pending` bigint(21)
,`approved` bigint(21)
,`rejected` bigint(21)
,`expired` bigint(21)
,`urgent_messages` bigint(21)
,`avg_response_time` decimal(24,4)
);

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `recipient` varchar(100) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `status` enum('Pending','Sent','Failed') DEFAULT 'Pending',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `external_senders`
--

CREATE TABLE `external_senders` (
  `id` int(11) NOT NULL,
  `nama_lengkap` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `identitas` varchar(50) NOT NULL,
  `unique_hash` varchar(64) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `verification_code` varchar(32) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `external_senders`
--

INSERT INTO `external_senders` (`id`, `nama_lengkap`, `email`, `phone_number`, `identitas`, `unique_hash`, `is_verified`, `verification_code`, `created_at`, `updated_at`) VALUES
(36, 'wahono agung', 'agung.senen3@gmail.com', '085174207795', 'orang_tua', '6dbf06bb0ed39c5e64b987c0e9a035ca', 0, NULL, '2026-02-19 11:02:03', '2026-02-19 11:02:03');

-- --------------------------------------------------------

--
-- Stand-in structure for view `guru_performance_report`
-- (See below for the actual view)
--
CREATE TABLE `guru_performance_report` (
`id` int(11)
,`nis_nip` varchar(20)
,`nama_lengkap` varchar(100)
,`user_type` enum('Siswa','Guru','Orang_Tua','Admin','Guru_BK','Guru_Humas','Guru_Kurikulum','Guru_Kesiswaan','Guru_Sarana','Wakil_Kepala','Kepala_Sekolah')
,`total_messages_assigned` bigint(21)
,`approved_messages` bigint(21)
,`rejected_messages` bigint(21)
,`pending_messages` bigint(21)
,`avg_response_time` decimal(24,4)
,`late_responses` bigint(21)
);

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `success` tinyint(1) DEFAULT 0,
  `user_agent` text DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `tanggal_pesan` datetime DEFAULT current_timestamp(),
  `jenis_pesan_id` int(11) NOT NULL,
  `pengirim_id` int(11) NOT NULL,
  `external_sender_id` int(11) DEFAULT NULL,
  `pengirim_nama` varchar(100) NOT NULL,
  `pengirim_email` varchar(100) DEFAULT NULL,
  `pengirim_phone` varchar(20) DEFAULT NULL,
  `pengirim_nis_nip` varchar(20) DEFAULT NULL,
  `isi_pesan` text NOT NULL,
  `status` enum('Pending','Dibaca','Diproses','Disetujui','Ditolak','Selesai','Expired','Dibatalkan') DEFAULT 'Pending',
  `responder_id` int(11) DEFAULT NULL,
  `is_external` tinyint(1) DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `submission_channel` enum('web','mobile','api') DEFAULT 'web',
  `catatan_respon` text DEFAULT NULL,
  `tanggal_respon` datetime DEFAULT NULL,
  `expired_at` datetime DEFAULT NULL,
  `priority` enum('Low','Medium','High','Urgent') DEFAULT 'Medium',
  `followup_count` int(11) DEFAULT 0,
  `last_followup` datetime DEFAULT NULL,
  `email_notified` tinyint(1) DEFAULT 0,
  `whatsapp_notified` tinyint(1) DEFAULT 0,
  `sms_notified` tinyint(1) DEFAULT 0,
  `last_reminder_sent` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `email_notified_at` datetime DEFAULT NULL,
  `whatsapp_notified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `reference_number`, `tanggal_pesan`, `jenis_pesan_id`, `pengirim_id`, `external_sender_id`, `pengirim_nama`, `pengirim_email`, `pengirim_phone`, `pengirim_nis_nip`, `isi_pesan`, `status`, `responder_id`, `is_external`, `ip_address`, `user_agent`, `submission_channel`, `catatan_respon`, `tanggal_respon`, `expired_at`, `priority`, `followup_count`, `last_followup`, `email_notified`, `whatsapp_notified`, `sms_notified`, `last_reminder_sent`, `created_at`, `updated_at`, `email_notified_at`, `whatsapp_notified_at`) VALUES
(1, NULL, '2026-02-09 12:16:43', 1, 12, NULL, 'Ahmad Fadil', NULL, NULL, '20230001', 'Saya ingin berkonsultasi tentang pemilihan jurusan untuk kuliah nanti. Mohon bimbingan dari guru BK.', 'Ditolak', 3, 0, NULL, NULL, 'web', 'Maaf, pesan Anda tidak dapat kami setujui saat ini. Silakan hubungi kami untuk informasi lebih lanjut.', '2026-02-09 14:42:05', NULL, 'Medium', 2, '2026-02-09 13:54:36', 0, 0, 0, NULL, '2026-02-09 05:16:43', '2026-02-09 07:42:05', NULL, NULL),
(2, NULL, '2026-02-09 12:16:43', 2, 13, NULL, 'Budi Pratama', NULL, NULL, '20230002', 'Ada acara kunjungan industri dari perusahaan IT bulan depan. Bagaimana prosedur pendaftarannya?', 'Diproses', NULL, 0, NULL, NULL, 'web', NULL, NULL, NULL, 'High', 0, NULL, 0, 0, 0, NULL, '2026-02-09 05:16:43', '2026-02-09 05:16:43', NULL, NULL),
(3, NULL, '2026-02-09 12:16:43', 3, 14, NULL, 'Citra Dewi', NULL, NULL, '20230003', 'Mata pelajaran Matematika terlalu sulit dipahami. Apakah bisa diadakan tambahan pelajaran?', 'Disetujui', 5, 0, NULL, NULL, 'web', 'Disetujui melalui aksi cepat.', '2026-02-11 09:23:28', NULL, 'Medium', 4, '2026-02-11 09:23:28', 0, 0, 0, NULL, '2026-02-09 05:16:43', '2026-02-11 02:23:28', NULL, NULL),
(4, NULL, '2026-02-09 12:16:43', 4, 15, NULL, 'Dedi Kurniawan', NULL, NULL, '20230004', 'Saya ingin mengadakan kegiatan pentas seni kelas. Mohon persetujuan dan bimbingannya.', 'Disetujui', NULL, 0, NULL, NULL, 'web', NULL, NULL, NULL, 'Low', 0, NULL, 0, 0, 0, NULL, '2026-02-09 05:16:43', '2026-02-09 05:16:43', NULL, NULL),
(5, NULL, '2026-02-09 12:16:43', 5, 16, NULL, 'Eka Putri', NULL, NULL, '20230005', 'AC di lab komputer rusak. Mohon diperbaiki segera karena mengganggu kegiatan praktikum.', 'Ditolak', NULL, 0, NULL, NULL, 'web', NULL, NULL, NULL, 'Urgent', 0, NULL, 0, 0, 0, NULL, '2026-02-09 05:16:43', '2026-02-09 05:16:43', NULL, NULL),
(53, 'EXT20260219-18588', '2026-02-19 11:02:03', 4, 87, 36, 'wahono agung', 'agung.senen3@gmail.com', '085174207795', NULL, 'Perbanyak kegiatan kesiswaan yang berorientasi prestasi', 'Disetujui', 6, 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', 'web', 'Terima kasih atas masukannya. Akan segera kami tindak lanjuti', '2026-02-19 12:08:32', '2026-02-21 11:02:03', 'High', 1, '2026-02-19 12:08:32', 1, 1, 0, NULL, '2026-02-19 04:02:03', '2026-02-19 05:08:32', NULL, NULL),
(54, 'MSG-20260219-84BC10', '2026-02-19 11:05:44', 9, 85, NULL, 'wiwi', NULL, NULL, NULL, 'Toilet kurang bersih', 'Disetujui', 3, 0, NULL, NULL, 'web', 'Terima kasih atas infonya. Sebelumnya kami mohon maaf atas ketidaknyamanan Bpk/Ibu. Kami akan segera tindak lanjuti', '2026-02-19 11:18:06', '2026-02-20 11:05:44', 'Medium', 1, '2026-02-19 11:18:06', 1, 1, 0, NULL, '2026-02-19 04:05:44', '2026-02-19 04:18:06', NULL, NULL);

--
-- Triggers `messages`
--
DELIMITER $$
CREATE TRIGGER `set_message_expired_at` BEFORE INSERT ON `messages` FOR EACH ROW BEGIN
    DECLARE deadline_hours INT;
    
    SELECT response_deadline_hours INTO deadline_hours 
    FROM message_types 
    WHERE id = NEW.jenis_pesan_id;
    
    IF deadline_hours IS NOT NULL THEN
        SET NEW.expired_at = DATE_ADD(NOW(), INTERVAL deadline_hours HOUR);
    ELSE
        SET NEW.expired_at = DATE_ADD(NOW(), INTERVAL 72 HOUR);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `message_activity_logs`
--

CREATE TABLE `message_activity_logs` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_attachments`
--

CREATE TABLE `message_attachments` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `filepath` varchar(500) NOT NULL,
  `filetype` varchar(50) DEFAULT NULL,
  `filesize` bigint(20) DEFAULT NULL,
  `is_approved` tinyint(1) DEFAULT 1,
  `virus_scan_status` enum('Pending','Clean','Infected','Error') DEFAULT 'Pending',
  `download_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_responses`
--

CREATE TABLE `message_responses` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `responder_id` int(11) NOT NULL,
  `catatan_respon` text NOT NULL,
  `status` enum('Disetujui','Ditolak','Ditunda','Diproses') NOT NULL,
  `is_external` tinyint(1) DEFAULT 0,
  `attachment` varchar(255) DEFAULT NULL,
  `is_private` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `email_sent` tinyint(1) DEFAULT 0,
  `email_sent_at` datetime DEFAULT NULL,
  `whatsapp_sent` tinyint(1) DEFAULT 0,
  `whatsapp_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `message_responses`
--

INSERT INTO `message_responses` (`id`, `message_id`, `responder_id`, `catatan_respon`, `status`, `is_external`, `attachment`, `is_private`, `created_at`, `email_sent`, `email_sent_at`, `whatsapp_sent`, `whatsapp_sent_at`) VALUES
(1, 4, 7, 'Kegiatan pentas seni disetujui dengan catatan harus melibatkan seluruh siswa kelas dan tidak mengganggu jam pelajaran. Silakan buat proposal lengkapnya.', 'Disetujui', 0, NULL, 0, '2026-02-09 05:16:43', 0, NULL, 0, NULL),
(2, 5, 8, 'AC lab komputer sedang dalam proses perbaikan. Perkiraan selesai dalam 3 hari kerja. Mohon bersabar.', 'Ditunda', 0, NULL, 0, '2026-02-09 05:16:43', 0, NULL, 0, NULL),
(3, 1, 3, 'Disetujui melalui aksi cepat.', 'Disetujui', 0, NULL, 0, '2026-02-09 06:53:31', 0, NULL, 0, NULL),
(4, 1, 3, 'karena belum sesuai', 'Ditolak', 0, NULL, 0, '2026-02-09 07:42:05', 0, NULL, 0, NULL),
(5, 3, 5, 'Disetujui melalui aksi cepat.', 'Disetujui', 0, NULL, 0, '2026-02-11 02:02:41', 0, NULL, 0, NULL),
(6, 3, 5, 'Disetujui melalui aksi cepat.', 'Disetujui', 0, NULL, 0, '2026-02-11 02:03:00', 0, NULL, 0, NULL),
(7, 3, 5, 'Disetujui melalui aksi cepat.', 'Disetujui', 0, NULL, 0, '2026-02-11 02:18:17', 0, NULL, 0, NULL),
(8, 3, 5, 'Disetujui melalui aksi cepat.', 'Disetujui', 0, NULL, 0, '2026-02-11 02:23:28', 0, NULL, 0, NULL),
(25, 54, 3, 'Terima kasih atas infonya. Sebelumnya kami mohon maaf atas ketidaknyamanan Bpk/Ibu. Kami akan segera tindak lanjuti', 'Disetujui', 0, NULL, 0, '2026-02-19 04:18:06', 0, NULL, 0, NULL),
(26, 53, 6, 'Terima kasih atas masukannya. Akan segera kami tindak lanjuti', 'Disetujui', 1, NULL, 0, '2026-02-19 05:08:32', 0, NULL, 0, NULL);

--
-- Triggers `message_responses`
--
DELIMITER $$
CREATE TRIGGER `update_followup_count` AFTER INSERT ON `message_responses` FOR EACH ROW BEGIN
    UPDATE messages 
    SET followup_count = followup_count + 1,
        last_followup = NOW(),
        status = CASE 
            WHEN NEW.status = 'Disetujui' THEN 'Disetujui'
            WHEN NEW.status = 'Ditolak' THEN 'Ditolak'
            WHEN NEW.status = 'Ditunda' THEN 'Diproses'
            ELSE status
        END,
        responder_id = NEW.responder_id,
        catatan_respon = NEW.catatan_respon,
        tanggal_respon = NOW()
    WHERE id = NEW.message_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `message_submission_logs`
--

CREATE TABLE `message_submission_logs` (
  `id` int(11) NOT NULL,
  `message_id` int(11) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `submission_data` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `status` enum('success','failed','pending') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `message_submission_logs`
--

INSERT INTO `message_submission_logs` (`id`, `message_id`, `session_id`, `ip_address`, `user_agent`, `submission_data`, `error_message`, `status`, `created_at`) VALUES
(1, NULL, '8t5jd4q1kb0e578fsl6ap4ubel', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', '{\"nama_pengirim\":\"Agung Wahono\",\"email_pengirim\":\"agung.senen3@gmail.com\",\"nomor_hp\":\"08129469754\",\"identitas\":\"masyarakat\",\"jenis_pesan_id\":\"3\",\"prioritas\":\"High\",\"isi_pesan\":\"Tingkatkan kualitas kurikulum berbasis dunia kerja\",\"captcha\":\"on\",\"csrf_token\":\"e8c384d2a8b575dd336a4d184231abb1addd4b7534992071732c0659976bac55\",\"submit_external_message\":\"1\"}', NULL, 'pending', '2026-02-12 03:41:40'),
(2, 6, '8t5jd4q1kb0e578fsl6ap4ubel', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0', '{\"nama_pengirim\":\"Agung Wahono\",\"email_pengirim\":\"agung.senen3@gmail.com\",\"nomor_hp\":\"08129469754\",\"identitas\":\"masyarakat\",\"jenis_pesan_id\":\"3\",\"prioritas\":\"High\",\"isi_pesan\":\"Tingkatkan kualitas kurikulum berbasis dunia kerja\",\"captcha\":\"on\",\"csrf_token\":\"e8c384d2a8b575dd336a4d184231abb1addd4b7534992071732c0659976bac55\",\"submit_external_message\":\"1\"}', NULL, 'success', '2026-02-12 03:41:40');

-- --------------------------------------------------------

--
-- Table structure for table `message_types`
--

CREATE TABLE `message_types` (
  `id` int(11) NOT NULL,
  `jenis_pesan` varchar(50) NOT NULL,
  `responder_type` enum('Guru_BK','Guru_Humas','Guru_Kurikulum','Guru_Kesiswaan','Guru_Sarana') NOT NULL,
  `description` text DEFAULT NULL,
  `response_deadline_hours` int(11) DEFAULT 72,
  `color_code` varchar(7) DEFAULT '#0d6efd',
  `icon_class` varchar(50) DEFAULT 'fas fa-envelope',
  `is_active` tinyint(1) DEFAULT 1,
  `allow_external` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `message_types`
--

INSERT INTO `message_types` (`id`, `jenis_pesan`, `responder_type`, `description`, `response_deadline_hours`, `color_code`, `icon_class`, `is_active`, `allow_external`, `created_at`, `updated_at`) VALUES
(1, 'Konsultasi/Konseling', 'Guru_BK', 'Pesan terkait bimbingan dan konseling siswa', 72, '#0d6efd', 'fas fa-comments', 1, 1, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(2, 'Kehumasan', 'Guru_Humas', 'Pesan terkait hubungan masyarakat dan informasi sekolah', 48, '#198754', 'fas fa-handshake', 1, 1, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(3, 'Kurikulum', 'Guru_Kurikulum', 'Pesan terkait kurikulum, mata pelajaran, dan akademik', 72, '#ffc107', 'fas fa-book', 1, 1, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(4, 'Kesiswaan', 'Guru_Kesiswaan', 'Pesan terkait kegiatan dan masalah kesiswaan', 48, '#dc3545', 'fas fa-users', 1, 1, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(5, 'Sarana Prasarana', 'Guru_Sarana', 'Pesan terkait sarana dan prasarana sekolah', 96, '#6c757d', 'fas fa-school', 1, 1, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(6, 'Konsultasi Akademik', 'Guru_BK', NULL, 72, '#0d6efd', 'fas fa-envelope', 1, 1, '2026-02-11 16:14:17', '2026-02-11 16:14:17'),
(7, 'Konsultasi Non-Akademik', 'Guru_BK', NULL, 72, '#0d6efd', 'fas fa-envelope', 1, 1, '2026-02-11 16:14:17', '2026-02-11 16:14:17'),
(8, 'Saran dan Masukan', 'Guru_BK', NULL, 48, '#0d6efd', 'fas fa-envelope', 1, 1, '2026-02-11 16:14:17', '2026-02-11 16:14:17'),
(9, 'Keluhan', 'Guru_BK', NULL, 24, '#0d6efd', 'fas fa-envelope', 1, 1, '2026-02-11 16:14:17', '2026-02-11 16:14:17'),
(10, 'Informasi', 'Guru_BK', NULL, 48, '#0d6efd', 'fas fa-envelope', 1, 1, '2026-02-11 16:14:17', '2026-02-11 16:14:17'),
(11, 'Pengaduan', 'Guru_BK', NULL, 24, '#0d6efd', 'fas fa-envelope', 1, 1, '2026-02-11 16:14:17', '2026-02-11 16:14:17'),
(12, 'Permohonan Data', 'Guru_BK', NULL, 72, '#0d6efd', 'fas fa-envelope', 1, 1, '2026-02-11 16:14:17', '2026-02-11 16:14:17'),
(13, 'Lain-lain', 'Guru_BK', NULL, 72, '#0d6efd', 'fas fa-envelope', 1, 1, '2026-02-11 16:14:17', '2026-02-11 16:14:17');

-- --------------------------------------------------------

--
-- Table structure for table `message_type_assignments`
--

CREATE TABLE `message_type_assignments` (
  `id` int(11) NOT NULL,
  `message_type_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `message_type_statistics`
-- (See below for the actual view)
--
CREATE TABLE `message_type_statistics` (
`jenis_pesan` varchar(50)
,`total_messages` bigint(21)
,`pending` bigint(21)
,`approved` bigint(21)
,`rejected` bigint(21)
,`expired` bigint(21)
,`avg_response_time` decimal(24,4)
,`response_deadline_hours` int(11)
);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','danger','primary') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `action_url` varchar(500) DEFAULT NULL,
  `icon_class` varchar(50) DEFAULT 'fas fa-bell',
  `priority` enum('Low','Medium','High') DEFAULT 'Medium',
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_logs`
--

CREATE TABLE `notification_logs` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL COMMENT 'ID pesan dari tabel messages',
  `response_id` int(11) DEFAULT NULL COMMENT 'ID respons dari tabel message_responses (jika ada)',
  `notification_type` enum('email','whatsapp','sms','telegram') NOT NULL DEFAULT 'email' COMMENT 'Jenis notifikasi',
  `recipient` varchar(255) NOT NULL COMMENT 'Tujuan notifikasi (email atau nomor HP)',
  `status` enum('sent','failed','pending','delivered','read') NOT NULL DEFAULT 'pending' COMMENT 'Status pengiriman',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Detail lengkap respons API' CHECK (json_valid(`details`)),
  `sent_at` datetime NOT NULL COMMENT 'Waktu pengiriman',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log pengiriman notifikasi ke pengirim';

--
-- Dumping data for table `notification_logs`
--

INSERT INTO `notification_logs` (`id`, `message_id`, `response_id`, `notification_type`, `recipient`, `status`, `details`, `sent_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'email', 'siswa@example.com', 'sent', '{\"http_code\":202, \"message_id\":\"test123\", \"provider\":\"mailersend\"}', '2026-02-15 05:29:00', '2026-02-15 00:29:00', NULL),
(2, 1, 1, 'whatsapp', '628129469754', 'sent', '{\"http_code\":200, \"status\":true, \"provider\":\"fonnte\", \"id\":\"wa123\"}', '2026-02-15 05:29:00', '2026-02-15 00:29:00', NULL),
(3, 2, 2, 'email', 'orangtua@example.com', 'sent', '{\"http_code\":202, \"message_id\":\"test456\", \"provider\":\"mailersend\"}', '2026-02-15 06:29:00', '2026-02-15 00:29:00', NULL),
(4, 2, 2, 'whatsapp', '628129469755', 'failed', '{\"http_code\":400, \"reason\":\"Invalid number\", \"provider\":\"fonnte\"}', '2026-02-15 06:29:00', '2026-02-15 00:29:00', NULL),
(59, 54, 25, 'whatsapp', '085117128578', 'sent', '{\"success\":true,\"sent\":true,\"http_code\":200,\"response\":{\"detail\":\"success! message in queue\",\"id\":[144294917],\"process\":\"pending\",\"quota\":{\"6285174207795\":{\"details\":\"deduced from total quota\",\"quota\":977,\"remaining\":976,\"used\":1}},\"requestid\":387685841,\"status\":true,\"target\":[\"6285117128578\"]},\"error\":null}', '2026-02-19 11:18:06', '2026-02-19 04:18:06', NULL),
(60, 54, 25, 'email', 'sri.widiastuti261077@gmail.com', 'sent', '{\"success\":true,\"http_code\":202,\"error\":\"\",\"sent\":true}', '2026-02-19 11:18:06', '2026-02-19 04:18:06', NULL),
(61, 53, 26, 'whatsapp', '085174207795', 'sent', '{\"success\":true,\"sent\":true,\"http_code\":200,\"response\":{\"detail\":\"success! message in queue\",\"id\":[144305697],\"process\":\"pending\",\"quota\":{\"6285174207795\":{\"details\":\"deduced from total quota\",\"quota\":976,\"remaining\":975,\"used\":1}},\"requestid\":387743078,\"status\":true,\"target\":[\"6285174207795\"]},\"error\":null}', '2026-02-19 12:08:32', '2026-02-19 05:08:32', NULL),
(62, 53, 26, 'email', 'agung.senen3@gmail.com', 'sent', '{\"success\":true,\"http_code\":202,\"error\":\"\",\"sent\":true}', '2026-02-19 12:08:33', '2026-02-19 05:08:33', NULL);

--
-- Triggers `notification_logs`
--
DELIMITER $$
CREATE TRIGGER `after_notification_log_insert` AFTER INSERT ON `notification_logs` FOR EACH ROW BEGIN
    IF NEW.`notification_type` = 'whatsapp' AND NEW.`status` = 'sent' THEN
        UPDATE `whatsapp_devices` 
        SET `total_messages_sent` = `total_messages_sent` + 1
        WHERE `status` = 'connected' AND `is_active` = 1
        LIMIT 1;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `response_templates`
--

CREATE TABLE `response_templates` (
  `id` int(11) NOT NULL,
  `guru_type` enum('Guru_BK','Guru_Humas','Guru_Kurikulum','Guru_Kesiswaan','Guru_Sarana') NOT NULL,
  `name` varchar(100) NOT NULL,
  `content` text NOT NULL,
  `category` varchar(50) DEFAULT 'Umum',
  `default_status` enum('Disetujui','Ditolak','Ditunda','Diproses') DEFAULT 'Diproses',
  `is_active` tinyint(1) DEFAULT 1,
  `use_count` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `response_templates`
--

INSERT INTO `response_templates` (`id`, `guru_type`, `name`, `content`, `category`, `default_status`, `is_active`, `use_count`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Guru_BK', 'Konsultasi Jurusan', 'Terima kasih atas konsultasi Anda. Untuk pemilihan jurusan yang tepat, saya sarankan: 1. Kenali minat dan bakat Anda, 2. Pelajari prospek kerja jurusan tersebut, 3. Konsultasi dengan orang tua. Mari kita diskusikan lebih lanjut.', 'Konseling', 'Disetujui', 1, 1, NULL, '2026-02-09 05:16:43', '2026-02-09 06:53:31'),
(2, 'Guru_Kurikulum', 'Tambahan Pelajaran', 'Mengenai permintaan tambahan pelajaran, kami akan mengadakan kelas tambahan setiap hari Jumat sore pukul 14.00-16.00. Silakan daftar di ruang guru kurikulum.', 'Akademik', 'Disetujui', 1, 0, NULL, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(3, 'Guru_Sarana', 'Perbaikan Fasilitas', 'Laporan kerusakan telah diterima. Tim maintenance akan memeriksa dan memperbaiki dalam waktu 3-5 hari kerja. Terima kasih atas laporannya.', 'Fasilitas', 'Diproses', 1, 0, NULL, '2026-02-09 05:16:43', '2026-02-09 05:16:43');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `session_id` varchar(128) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp(),
  `csrf_token` varchar(64) DEFAULT NULL,
  `is_mobile` tinyint(1) DEFAULT 0,
  `device_info` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`device_info`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','integer','boolean','json','array') DEFAULT 'string',
  `category` varchar(50) DEFAULT 'General',
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `category`, `description`, `is_public`, `created_at`, `updated_at`) VALUES
(1, 'app_name', 'Responsive Message SMKN 12 Jakarta', 'string', 'General', 'Nama Aplikasi', 1, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(2, 'app_version', '1.0.0', 'string', 'General', 'Versi Aplikasi', 1, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(3, 'maintenance_mode', '0', 'boolean', 'System', 'Mode Maintenance', 0, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(4, 'max_login_attempts', '5', 'integer', 'Security', 'Maksimal Percobaan Login', 0, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(5, 'session_timeout', '3600', 'integer', 'Security', 'Timeout Session (detik)', 0, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(6, 'message_limit_per_day', '10', 'integer', 'Messages', 'Batas Pesan per Hari per User', 1, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(7, 'default_response_deadline', '72', 'integer', 'Messages', 'Default Deadline Respons (jam)', 0, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(8, 'enable_whatsapp', '1', 'boolean', 'Notifications', 'Aktifkan WhatsApp Notifications', 0, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(9, 'enable_email', '1', 'boolean', 'Notifications', 'Aktifkan Email Notifications', 0, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(10, 'enable_sms', '0', 'boolean', 'Notifications', 'Aktifkan SMS Notifications', 0, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(11, 'backup_retention_days', '30', 'integer', 'Backup', 'Hari Retensi Backup', 0, '2026-02-09 05:16:43', '2026-02-09 05:16:43'),
(12, 'auto_backup_time', '02:00', 'string', 'Backup', 'Waktu Auto Backup', 0, '2026-02-09 05:16:43', '2026-02-09 05:16:43');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `user_type` enum('Siswa','Guru','Orang_Tua','Admin','Guru_BK','Guru_Humas','Guru_Kurikulum','Guru_Kesiswaan','Guru_Sarana','Wakil_Kepala','Kepala_Sekolah') NOT NULL,
  `nis_nip` varchar(20) DEFAULT NULL,
  `nama_lengkap` varchar(100) NOT NULL,
  `kelas` varchar(10) DEFAULT NULL,
  `jurusan` varchar(50) DEFAULT NULL,
  `mata_pelajaran` varchar(100) DEFAULT NULL,
  `privilege_level` enum('Full_Access','Limited_Lv1','Limited_Lv2','Limited_Lv3') DEFAULT 'Limited_Lv3',
  `phone_number` varchar(20) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT 'default-avatar.png',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `api_key` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `email`, `user_type`, `nis_nip`, `nama_lengkap`, `kelas`, `jurusan`, `mata_pelajaran`, `privilege_level`, `phone_number`, `avatar`, `is_active`, `last_login`, `reset_token`, `reset_expires`, `api_key`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'admin@smkn12jakarta.sch.id', 'Admin', 'ADM001', 'Administrator Sistem', NULL, NULL, NULL, 'Full_Access', '081234567890', 'default-avatar.png', 1, '2026-02-19 12:17:48', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-19 05:17:48'),
(2, 'guru001', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'budi.santoso@smkn12jakarta.sch.id', 'Guru', '198301011', 'Budi Santoso', NULL, NULL, 'Matematika', 'Limited_Lv2', '081122334455', 'default-avatar.png', 1, '2026-02-17 16:18:11', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-17 09:18:11'),
(3, 'guru002', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'siti.aisyah@smkn12jakarta.sch.id', 'Guru_BK', '198301012', 'Siti Aisyah', NULL, NULL, 'Bimbingan Konseling', 'Limited_Lv1', '081122334456', 'default-avatar.png', 1, '2026-02-19 11:07:16', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-19 04:07:16'),
(4, 'guru003', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'ahmad.fauzi@smkn12jakarta.sch.id', 'Guru_Humas', '198301013', 'Ahmad Fauzi', NULL, NULL, 'Hubungan Masyarakat', 'Limited_Lv1', '081122334457', 'default-avatar.png', 1, '2026-02-19 12:05:42', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-19 05:05:42'),
(5, 'guru004', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'dewi.lestari@smkn12jakarta.sch.id', 'Guru_Kurikulum', '198301014', 'Dewi Lestari', NULL, NULL, 'Kurikulum', 'Limited_Lv1', '081122334458', 'default-avatar.png', 1, '2026-02-19 12:05:31', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-19 05:05:31'),
(6, 'guru005', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'rudi.hermawan@smkn12jakarta.sch.id', 'Guru_Kesiswaan', '198301015', 'Rudi Hermawan', NULL, NULL, 'Kesiswaan', 'Limited_Lv1', '081122334459', 'default-avatar.png', 1, '2026-02-19 12:07:52', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-19 05:07:52'),
(7, 'guru006', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'maya.indah@smkn12jakarta.sch.id', 'Guru_Sarana', '198301016', 'Maya Indah', NULL, NULL, 'Sarana Prasarana', 'Limited_Lv1', '081122334460', 'default-avatar.png', 1, '2026-02-14 14:09:28', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-14 07:09:28'),
(8, 'guru007', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'joko.widodo@smkn12jakarta.sch.id', 'Wakil_Kepala', '198301017', 'Joko Widodo', NULL, NULL, 'Wakil Kepala Sekolah', 'Limited_Lv3', '081122334461', 'default-avatar.png', 1, '2026-02-14 07:03:22', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-14 00:03:22'),
(9, 'guru008', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'sri.mulyani@smkn12jakarta.sch.id', 'Kepala_Sekolah', '198301018', 'Sri Mulyani', NULL, NULL, 'Kepala Sekolah', 'Full_Access', '081122334462', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:24:02'),
(10, 'guru009', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'agus.salim@smkn12jakarta.sch.id', 'Guru', '198301019', 'Agus Salim', NULL, NULL, 'Bahasa Indonesia', 'Limited_Lv2', '081122334463', 'default-avatar.png', 1, '2026-02-16 17:11:51', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-16 10:11:51'),
(11, 'guru010', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'rina.marlina@smkn12jakarta.sch.id', 'Guru', '198301020', 'Rina Marlina', NULL, NULL, 'Bahasa Inggris', 'Limited_Lv2', '081122334464', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:24:24'),
(12, 'siswa001', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'ahmad.fadil@smkn12jakarta.sch.id', 'Siswa', '20230001', 'Ahmad Fadil', 'X', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334401', 'default-avatar.png', 1, '2026-02-15 22:44:19', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-15 15:44:19'),
(13, 'siswa002', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'budi.pratama@smkn12jakarta.sch.id', 'Siswa', '20230002', 'Budi Pratama', 'X', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334402', 'default-avatar.png', 1, '2026-02-15 22:45:21', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-15 15:45:21'),
(14, 'siswa003', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'citra.dewi@smkn12jakarta.sch.id', 'Siswa', '20230003', 'Citra Dewi', 'X', 'Multimedia', NULL, 'Limited_Lv3', '081122334403', 'default-avatar.png', 1, '2026-02-16 10:18:58', NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-16 03:18:58'),
(15, 'siswa004', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'dedi.kurniawan@smkn12jakarta.sch.id', 'Siswa', '20230004', 'Dedi Kurniawan', 'X', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334404', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:25:26'),
(16, 'siswa005', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'eka.putri@smkn12jakarta.sch.id', 'Siswa', '20230005', 'Eka Putri', 'X', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334405', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:25:39'),
(17, 'siswa006', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'fajar.ramadan@smkn12jakarta.sch.id', 'Siswa', '20230006', 'Fajar Ramadan', 'XI', 'Multimedia', NULL, 'Limited_Lv3', '081122334406', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:25:52'),
(18, 'siswa007', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'gita.maya@smkn12jakarta.sch.id', 'Siswa', '20230007', 'Gita Maya', 'XI', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334407', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:26:04'),
(19, 'siswa008', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'hendra.wijaya@smkn12jakarta.sch.id', 'Siswa', '20230008', 'Hendra Wijaya', 'XI', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334408', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:26:16'),
(20, 'siswa009', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'indah.permata@smkn12jakarta.sch.id', 'Siswa', '20230009', 'Indah Permata', 'XI', 'Multimedia', NULL, 'Limited_Lv3', '081122334409', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:26:29'),
(21, 'siswa010', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'joko.susilo@smkn12jakarta.sch.id', 'Siswa', '20230010', 'Joko Susilo', 'XI', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334410', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:26:42'),
(22, 'siswa011', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'kartika.sari@smkn12jakarta.sch.id', 'Siswa', '20230011', 'Kartika Sari', 'XI', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334411', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:26:57'),
(23, 'siswa012', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'lukman.hakim@smkn12jakarta.sch.id', 'Siswa', '20230012', 'Lukman Hakim', 'XI', 'Multimedia', NULL, 'Limited_Lv3', '081122334412', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:27:16'),
(24, 'siswa013', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'maya.sari@smkn12jakarta.sch.id', 'Siswa', '20230013', 'Maya Sari', 'XII', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334413', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:27:29'),
(25, 'siswa014', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'nur.hidayat@smkn12jakarta.sch.id', 'Siswa', '20230014', 'Nur Hidayat', 'XII', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334414', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:27:44'),
(26, 'siswa015', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'oktavia.ayu@smkn12jakarta.sch.id', 'Siswa', '20230015', 'Oktavia Ayu', 'XII', 'Multimedia', NULL, 'Limited_Lv3', '081122334415', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:28:19'),
(27, 'siswa016', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'pratama.adit@smkn12jakarta.sch.id', 'Siswa', '20230016', 'Pratama Adit', 'XII', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334416', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:28:31'),
(28, 'siswa017', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'qory.sandioriva@smkn12jakarta.sch.id', 'Siswa', '20230017', 'Qory Sandioriva', 'XII', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334417', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:28:41'),
(29, 'siswa018', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'rahmat.hidayat@smkn12jakarta.sch.id', 'Siswa', '20230018', 'Rahmat Hidayat', 'XII', 'Multimedia', NULL, 'Limited_Lv3', '081122334418', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:28:52'),
(30, 'siswa019', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'siti.nurhaliza@smkn12jakarta.sch.id', 'Siswa', '20230019', 'Siti Nurhaliza', 'X', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334419', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:29:03'),
(31, 'siswa020', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'taufik.hidayat@smkn12jakarta.sch.id', 'Siswa', '20230020', 'Taufik Hidayat', 'X', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334420', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:29:14'),
(32, 'siswa021', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'ummu.kultsum@smkn12jakarta.sch.id', 'Siswa', '20230021', 'Ummu Kultsum', 'X', 'Multimedia', NULL, 'Limited_Lv3', '081122334421', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:29:25'),
(33, 'siswa022', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'vina.panduwinata@smkn12jakarta.sch.id', 'Siswa', '20230022', 'Vina Panduwinata', 'X', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334422', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:29:36'),
(34, 'siswa023', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'wawan.setiawan@smkn12jakarta.sch.id', 'Siswa', '20230023', 'Wawan Setiawan', 'XI', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334423', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:29:54'),
(35, 'siswa024', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'xavier.smith@smkn12jakarta.sch.id', 'Siswa', '20230024', 'Xavier Smith', 'XI', 'Multimedia', NULL, 'Limited_Lv3', '081122334424', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:30:06'),
(36, 'siswa025', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'yudi.hermawan@smkn12jakarta.sch.id', 'Siswa', '20230025', 'Yudi Hermawan', 'XI', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334425', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:30:20'),
(37, 'siswa026', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'zainal.abidin@smkn12jakarta.sch.id', 'Siswa', '20230026', 'Zainal Abidin', 'XI', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334426', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:30:34'),
(38, 'siswa027', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'ade.rahmawan@smkn12jakarta.sch.id', 'Siswa', '20230027', 'Ade Rahmawan', 'XII', 'Multimedia', NULL, 'Limited_Lv3', '081122334427', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:30:46'),
(39, 'siswa028', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'bambang.sutrisno@smkn12jakarta.sch.id', 'Siswa', '20230028', 'Bambang Sutrisno', 'XII', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334428', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:31:00'),
(40, 'siswa029', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'cindy.gultom@smkn12jakarta.sch.id', 'Siswa', '20230029', 'Cindy Gultom', 'XII', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334429', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:31:15'),
(41, 'siswa030', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'dian.sastrowardoyo@smkn12jakarta.sch.id', 'Siswa', '20230030', 'Dian Sastrowardoyo', 'XII', 'Multimedia', NULL, 'Limited_Lv3', '081122334430', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:31:29'),
(42, 'siswa031', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'erik.setiawan@smkn12jakarta.sch.id', 'Siswa', '20230031', 'Erik Setiawan', 'X', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334431', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:31:43'),
(43, 'siswa032', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'fitri.wulandari@smkn12jakarta.sch.id', 'Siswa', '20230032', 'Fitri Wulandari', 'X', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334432', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:31:55'),
(44, 'siswa033', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'guntur.sukarno@smkn12jakarta.sch.id', 'Siswa', '20230033', 'Guntur Sukarno', 'X', 'Multimedia', NULL, 'Limited_Lv3', '081122334433', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:32:08'),
(45, 'siswa034', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'hani.susanti@smkn12jakarta.sch.id', 'Siswa', '20230034', 'Hani Susanti', 'XI', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334434', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:32:24'),
(46, 'siswa035', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'irfan.bachdim@smkn12jakarta.sch.id', 'Siswa', '20230035', 'Irfan Bachdim', 'XI', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334435', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:32:41'),
(47, 'siswa036', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'jessica.mila@smkn12jakarta.sch.id', 'Siswa', '20230036', 'Jessica Mila', 'XI', 'Multimedia', NULL, 'Limited_Lv3', '081122334436', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:32:59'),
(48, 'siswa037', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'kevin.anggara@smkn12jakarta.sch.id', 'Siswa', '20230037', 'Kevin Anggara', 'XII', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334437', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:33:15'),
(49, 'siswa038', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'luna.maya@smkn12jakarta.sch.id', 'Siswa', '20230038', 'Luna Maya', 'XII', 'Teknik Komputer Jaringan', NULL, 'Limited_Lv3', '081122334438', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:33:29'),
(50, 'siswa039', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'mario.teguh@smkn12jakarta.sch.id', 'Siswa', '20230039', 'Mario Teguh', 'XII', 'Multimedia', NULL, 'Limited_Lv3', '081122334439', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:33:46'),
(51, 'siswa040', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'nurul.arifin@smkn12jakarta.sch.id', 'Siswa', '20230040', 'Nurul Arifin', 'XII', 'Rekayasa Perangkat Lunak', NULL, 'Limited_Lv3', '081122334440', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:34:06'),
(52, 'ortu001', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'slamet.riyadi@gmail.com', 'Orang_Tua', 'OT2023001', 'Slamet Riyadi', NULL, NULL, NULL, 'Limited_Lv3', '081122334501', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:34:16'),
(53, 'ortu002', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'murni.lestari@gmail.com', 'Orang_Tua', 'OT2023002', 'Murni Lestari', NULL, NULL, NULL, 'Limited_Lv3', '081122334502', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:34:26'),
(54, 'ortu003', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'tono.wijaya@gmail.com', 'Orang_Tua', 'OT2023003', 'Tono Wijaya', NULL, NULL, NULL, 'Limited_Lv3', '081122334503', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:34:37'),
(55, 'ortu004', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'sari.dewi@gmail.com', 'Orang_Tua', 'OT2023004', 'Sari Dewi', NULL, NULL, NULL, 'Limited_Lv3', '081122334504', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:34:47'),
(56, 'ortu005', '$2y$10$E1NAKHNbOiQz6puFodqji.6LxOZBePwHFTepLEuutdtlkt7Trc8H6', 'bambang.setiawan@gmail.com', 'Orang_Tua', 'OT2023005', 'Bambang Setiawan', NULL, NULL, NULL, 'Limited_Lv3', '081122334505', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-09 05:16:43', '2026-02-09 05:34:56'),
(85, 'wiwi123', '$2y$12$nO35tqJdA7zXVh3hY8plV.jLbDSDjsxAO0eC8nBPWzpEwH0I556Qa', 'sri.widiastuti261077@gmail.com', 'Orang_Tua', 'OT2026001', 'wiwi', '', '', NULL, 'Limited_Lv3', '085117128578', 'default-avatar.png', 1, '2026-02-19 11:55:43', NULL, NULL, NULL, '2026-02-19 03:02:18', '2026-02-19 04:55:43'),
(87, 'ext_17714737238574', '$2y$10$gpCfP/qrQMgBIf28z4RsmOftyb/w70m6ju1QPRLIEavlfXoTqaapC', 'agung.senen3@gmail.com', '', NULL, 'wahono agung', NULL, NULL, NULL, 'Limited_Lv3', '085174207795', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-19 04:02:03', '2026-02-19 04:02:03'),
(91, 'siswa042', '$2y$10$QwSMAvTVsGw.w0chZZtbyOMVJ3JG.Eu0f..kfGotkB86nDKp6NTdC', 'agungwahono7929@gmail.com', 'Siswa', '20230042', 'Iskandarsyah', 'X', 'RPL', NULL, '', '085174207795', 'default-avatar.png', 1, NULL, NULL, NULL, NULL, '2026-02-19 06:55:32', '2026-02-19 06:55:32');

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `audit_users_insert` AFTER INSERT ON `users` FOR EACH ROW BEGIN
    INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_value, created_at)
    VALUES (NEW.id, 'INSERT', 'users', NEW.id, 
            JSON_OBJECT(
                'username', NEW.username,
                'user_type', NEW.user_type,
                'nama_lengkap', NEW.nama_lengkap,
                'email', NEW.email,
                'nis_nip', NEW.nis_nip
            ), NOW());
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `audit_users_update` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
    INSERT INTO audit_logs (user_id, action_type, table_name, record_id, old_value, new_value, created_at)
    VALUES (NEW.id, 'UPDATE', 'users', NEW.id, 
            JSON_OBJECT(
                'username', OLD.username,
                'user_type', OLD.user_type,
                'nama_lengkap', OLD.nama_lengkap,
                'email', OLD.email,
                'nis_nip', OLD.nis_nip,
                'is_active', OLD.is_active
            ),
            JSON_OBJECT(
                'username', NEW.username,
                'user_type', NEW.user_type,
                'nama_lengkap', NEW.nama_lengkap,
                'email', NEW.email,
                'nis_nip', NEW.nis_nip,
                'is_active', NEW.is_active
            ), NOW());
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `user_activity_report`
-- (See below for the actual view)
--
CREATE TABLE `user_activity_report` (
`id` int(11)
,`username` varchar(50)
,`nama_lengkap` varchar(100)
,`user_type` enum('Siswa','Guru','Orang_Tua','Admin','Guru_BK','Guru_Humas','Guru_Kurikulum','Guru_Kesiswaan','Guru_Sarana','Wakil_Kepala','Kepala_Sekolah')
,`last_login` datetime
,`total_messages_sent` bigint(21)
,`approved_messages` bigint(21)
,`rejected_messages` bigint(21)
,`last_message_date` timestamp
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_notification_summary`
-- (See below for the actual view)
--
CREATE TABLE `view_notification_summary` (
`tanggal` date
,`notification_type` enum('email','whatsapp','sms','telegram')
,`status` enum('sent','failed','pending','delivered','read')
,`total` bigint(21)
,`unique_messages` bigint(21)
);

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_devices`
--

CREATE TABLE `whatsapp_devices` (
  `id` int(11) NOT NULL,
  `device_name` varchar(100) NOT NULL COMMENT 'Nama perangkat',
  `device_number` varchar(20) NOT NULL COMMENT 'Nomor perangkat (format internasional)',
  `api_token` varchar(255) NOT NULL COMMENT 'Token API untuk perangkat',
  `api_url` varchar(255) DEFAULT 'https://api.fonnte.com/send' COMMENT 'URL API',
  `status` enum('connected','disconnected','expired','pending') NOT NULL DEFAULT 'pending',
  `last_connected_at` datetime DEFAULT NULL,
  `expired_at` date DEFAULT NULL COMMENT 'Tanggal kadaluarsa paket',
  `total_messages_sent` int(11) NOT NULL DEFAULT 0,
  `total_messages_limit` int(11) DEFAULT 1000 COMMENT 'Batas pesan per bulan',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Konfigurasi perangkat WhatsApp';

--
-- Dumping data for table `whatsapp_devices`
--

INSERT INTO `whatsapp_devices` (`id`, `device_name`, `device_number`, `api_token`, `api_url`, `status`, `last_connected_at`, `expired_at`, `total_messages_sent`, `total_messages_limit`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Fonnte Device', '6285174207795', 'FS2cq8FckmaTegxtZpFB', 'https://api.fonnte.com/send', 'connected', '2026-02-15 07:27:40', '2026-03-17', 16, 1000, 'Perangkat utama SMKN 12 Jakarta', 1, '2026-02-15 00:27:40', '2026-02-19 05:08:32');

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_logs`
--

CREATE TABLE `whatsapp_logs` (
  `id` int(11) NOT NULL,
  `message_id` int(11) DEFAULT NULL,
  `recipient` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `status` enum('Pending','Sent','Delivered','Failed') DEFAULT 'Pending',
  `provider` varchar(50) DEFAULT 'CallMeBot',
  `response_code` int(11) DEFAULT NULL,
  `response_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure for view `daily_message_report`
--
DROP TABLE IF EXISTS `daily_message_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `daily_message_report`  AS SELECT cast(`messages`.`created_at` as date) AS `report_date`, count(0) AS `total_messages`, count(case when `messages`.`status` = 'Pending' then 1 end) AS `pending`, count(case when `messages`.`status` = 'Disetujui' then 1 end) AS `approved`, count(case when `messages`.`status` = 'Ditolak' then 1 end) AS `rejected`, count(case when `messages`.`status` = 'Expired' then 1 end) AS `expired`, count(case when `messages`.`priority` = 'Urgent' then 1 end) AS `urgent_messages`, avg(timestampdiff(HOUR,`messages`.`created_at`,coalesce(`messages`.`tanggal_respon`,current_timestamp()))) AS `avg_response_time` FROM `messages` GROUP BY cast(`messages`.`created_at` as date) ;

-- --------------------------------------------------------

--
-- Structure for view `guru_performance_report`
--
DROP TABLE IF EXISTS `guru_performance_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `guru_performance_report`  AS SELECT `u`.`id` AS `id`, `u`.`nis_nip` AS `nis_nip`, `u`.`nama_lengkap` AS `nama_lengkap`, `u`.`user_type` AS `user_type`, count(distinct `m`.`id`) AS `total_messages_assigned`, count(distinct case when `m`.`status` = 'Disetujui' then `m`.`id` end) AS `approved_messages`, count(distinct case when `m`.`status` = 'Ditolak' then `m`.`id` end) AS `rejected_messages`, count(distinct case when `m`.`status` in ('Pending','Dibaca','Diproses') then `m`.`id` end) AS `pending_messages`, avg(timestampdiff(HOUR,`m`.`created_at`,coalesce(`m`.`tanggal_respon`,current_timestamp()))) AS `avg_response_time`, count(distinct case when timestampdiff(HOUR,`m`.`created_at`,coalesce(`m`.`tanggal_respon`,current_timestamp())) > 72 then `m`.`id` end) AS `late_responses` FROM (`users` `u` left join `messages` `m` on(`u`.`id` = `m`.`responder_id`)) WHERE `u`.`user_type` in ('Guru_BK','Guru_Humas','Guru_Kurikulum','Guru_Kesiswaan','Guru_Sarana') GROUP BY `u`.`id`, `u`.`nis_nip`, `u`.`nama_lengkap`, `u`.`user_type` ;

-- --------------------------------------------------------

--
-- Structure for view `message_type_statistics`
--
DROP TABLE IF EXISTS `message_type_statistics`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `message_type_statistics`  AS SELECT `mt`.`jenis_pesan` AS `jenis_pesan`, count(`m`.`id`) AS `total_messages`, count(case when `m`.`status` = 'Pending' then 1 end) AS `pending`, count(case when `m`.`status` = 'Disetujui' then 1 end) AS `approved`, count(case when `m`.`status` = 'Ditolak' then 1 end) AS `rejected`, count(case when `m`.`status` = 'Expired' then 1 end) AS `expired`, avg(timestampdiff(HOUR,`m`.`created_at`,coalesce(`m`.`tanggal_respon`,current_timestamp()))) AS `avg_response_time`, `mt`.`response_deadline_hours` AS `response_deadline_hours` FROM (`message_types` `mt` left join `messages` `m` on(`mt`.`id` = `m`.`jenis_pesan_id`)) GROUP BY `mt`.`id`, `mt`.`jenis_pesan`, `mt`.`response_deadline_hours` ;

-- --------------------------------------------------------

--
-- Structure for view `user_activity_report`
--
DROP TABLE IF EXISTS `user_activity_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `user_activity_report`  AS SELECT `u`.`id` AS `id`, `u`.`username` AS `username`, `u`.`nama_lengkap` AS `nama_lengkap`, `u`.`user_type` AS `user_type`, `u`.`last_login` AS `last_login`, count(`m`.`id`) AS `total_messages_sent`, count(case when `m`.`status` = 'Disetujui' then 1 end) AS `approved_messages`, count(case when `m`.`status` = 'Ditolak' then 1 end) AS `rejected_messages`, max(`m`.`created_at`) AS `last_message_date` FROM (`users` `u` left join `messages` `m` on(`u`.`id` = `m`.`pengirim_id`)) WHERE `u`.`is_active` = 1 GROUP BY `u`.`id`, `u`.`username`, `u`.`nama_lengkap`, `u`.`user_type`, `u`.`last_login` ;

-- --------------------------------------------------------

--
-- Structure for view `view_notification_summary`
--
DROP TABLE IF EXISTS `view_notification_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_notification_summary`  AS SELECT cast(`notification_logs`.`sent_at` as date) AS `tanggal`, `notification_logs`.`notification_type` AS `notification_type`, `notification_logs`.`status` AS `status`, count(0) AS `total`, count(distinct `notification_logs`.`message_id`) AS `unique_messages` FROM `notification_logs` GROUP BY cast(`notification_logs`.`sent_at` as date), `notification_logs`.`notification_type`, `notification_logs`.`status` ORDER BY cast(`notification_logs`.`sent_at` as date) DESC, `notification_logs`.`notification_type` ASC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_action` (`user_id`,`action_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_action_type` (`action_type`),
  ADD KEY `idx_table_record` (`table_name`,`record_id`),
  ADD KEY `idx_ip_address` (`ip_address`),
  ADD KEY `idx_audit_date_range` (`created_at`,`action_type`);

--
-- Indexes for table `backups`
--
ALTER TABLE `backups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_backup_type` (`backup_type`);

--
-- Indexes for table `cache_data`
--
ALTER TABLE `cache_data`
  ADD PRIMARY KEY (`cache_key`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recipient` (`recipient`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `external_senders`
--
ALTER TABLE `external_senders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_phone` (`phone_number`),
  ADD KEY `idx_unique_hash` (`unique_hash`),
  ADD KEY `idx_verification` (`verification_code`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_username_ip` (`username`,`ip_address`),
  ADD KEY `idx_attempt_time` (`attempt_time`),
  ADD KEY `idx_success` (`success`),
  ADD KEY `idx_ip_address` (`ip_address`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_pengirim` (`pengirim_id`),
  ADD KEY `idx_responder` (`responder_id`),
  ADD KEY `idx_tanggal` (`tanggal_pesan`),
  ADD KEY `idx_expired` (`expired_at`),
  ADD KEY `idx_priority` (`priority`),
  ADD KEY `idx_jenis_pesan` (`jenis_pesan_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_status_priority` (`status`,`priority`),
  ADD KEY `idx_messages_search` (`pengirim_nama`,`isi_pesan`(100),`status`),
  ADD KEY `idx_messages_date_range` (`created_at`,`updated_at`),
  ADD KEY `fk_external_sender` (`external_sender_id`),
  ADD KEY `idx_submission_channel` (`submission_channel`),
  ADD KEY `idx_ip_address` (`ip_address`);

--
-- Indexes for table `message_activity_logs`
--
ALTER TABLE `message_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message_id` (`message_id`),
  ADD KEY `idx_activity_type` (`activity_type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message` (`message_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_filetype` (`filetype`),
  ADD KEY `idx_virus_status` (`virus_scan_status`);

--
-- Indexes for table `message_responses`
--
ALTER TABLE `message_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message` (`message_id`),
  ADD KEY `idx_responder` (`responder_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `message_submission_logs`
--
ALTER TABLE `message_submission_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session_id` (`session_id`),
  ADD KEY `idx_ip_address` (`ip_address`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `message_types`
--
ALTER TABLE `message_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `jenis_pesan` (`jenis_pesan`),
  ADD KEY `idx_responder_type` (`responder_type`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `message_type_assignments`
--
ALTER TABLE `message_type_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `message_type_id` (`message_type_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_priority` (`priority`),
  ADD KEY `idx_notifications_unread` (`user_id`,`is_read`,`created_at`);

--
-- Indexes for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message_id` (`message_id`),
  ADD KEY `idx_response_id` (`response_id`),
  ADD KEY `idx_notification_type` (`notification_type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sent_at` (`sent_at`),
  ADD KEY `idx_recipient` (`recipient`(191)),
  ADD KEY `idx_message_status` (`message_id`,`status`),
  ADD KEY `idx_daily_report` (`sent_at`,`notification_type`,`status`),
  ADD KEY `idx_monthly_stats` (`notification_type`,`status`,`sent_at`);
ALTER TABLE `notification_logs` ADD FULLTEXT KEY `ft_recipient` (`recipient`);

--
-- Indexes for table `response_templates`
--
ALTER TABLE `response_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_guru_type` (`guru_type`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_last_activity` (`last_activity`),
  ADD KEY `idx_csrf_token` (`csrf_token`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_setting_key` (`setting_key`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `nis_nip` (`nis_nip`),
  ADD UNIQUE KEY `api_key` (`api_key`),
  ADD KEY `idx_user_type` (`user_type`),
  ADD KEY `idx_nis_nip` (`nis_nip`),
  ADD KEY `idx_privilege` (`privilege_level`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_users_search` (`nama_lengkap`,`nis_nip`,`email`);

--
-- Indexes for table `whatsapp_devices`
--
ALTER TABLE `whatsapp_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_device_number` (`device_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `whatsapp_logs`
--
ALTER TABLE `whatsapp_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message_id` (`message_id`),
  ADD KEY `idx_recipient` (`recipient`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=646;

--
-- AUTO_INCREMENT for table `backups`
--
ALTER TABLE `backups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `external_senders`
--
ALTER TABLE `external_senders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `message_activity_logs`
--
ALTER TABLE `message_activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `message_attachments`
--
ALTER TABLE `message_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `message_responses`
--
ALTER TABLE `message_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `message_submission_logs`
--
ALTER TABLE `message_submission_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `message_types`
--
ALTER TABLE `message_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `message_type_assignments`
--
ALTER TABLE `message_type_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_logs`
--
ALTER TABLE `notification_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `response_templates`
--
ALTER TABLE `response_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT for table `whatsapp_devices`
--
ALTER TABLE `whatsapp_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `whatsapp_logs`
--
ALTER TABLE `whatsapp_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD CONSTRAINT `api_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `backups`
--
ALTER TABLE `backups`
  ADD CONSTRAINT `backups_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `fk_external_sender` FOREIGN KEY (`external_sender_id`) REFERENCES `external_senders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`jenis_pesan_id`) REFERENCES `message_types` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`pengirim_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`responder_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `message_activity_logs`
--
ALTER TABLE `message_activity_logs`
  ADD CONSTRAINT `fk_message_activity` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD CONSTRAINT `message_attachments_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `message_attachments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `message_responses`
--
ALTER TABLE `message_responses`
  ADD CONSTRAINT `message_responses_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `message_responses_ibfk_2` FOREIGN KEY (`responder_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD CONSTRAINT `fk_notification_logs_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notification_logs_response` FOREIGN KEY (`response_id`) REFERENCES `message_responses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `response_templates`
--
ALTER TABLE `response_templates`
  ADD CONSTRAINT `response_templates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `whatsapp_logs`
--
ALTER TABLE `whatsapp_logs`
  ADD CONSTRAINT `whatsapp_logs_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `cleanup_old_data` ON SCHEDULE EVERY 1 DAY STARTS '2026-02-09 12:19:07' ON COMPLETION NOT PRESERVE ENABLE DO BEGIN
    DELETE FROM sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    DELETE FROM cache_data WHERE expires_at < NOW();
    
    UPDATE messages m
    INNER JOIN message_types mt ON m.jenis_pesan_id = mt.id
    SET m.status = 'Expired'
    WHERE m.status IN ('Pending', 'Dibaca', 'Diproses')
    AND TIMESTAMPDIFF(HOUR, m.created_at, NOW()) > mt.response_deadline_hours;
END$$

CREATE DEFINER=`root`@`localhost` EVENT `auto_database_backup` ON SCHEDULE EVERY 1 DAY STARTS '2026-02-10 02:00:00' ON COMPLETION NOT PRESERVE ENABLE DO BEGIN
    DECLARE backup_filename VARCHAR(255);
    
    SET backup_filename = CONCAT('auto_backup_', DATE_FORMAT(NOW(), '%Y%m%d'), '.sql');
    
    INSERT INTO backups (filename, filepath, backup_type, status)
    VALUES (backup_filename, CONCAT('/backups/', backup_filename), 'Full', 'Success');
END$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
