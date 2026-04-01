-- Database: responsive_message_db
-- Aplikasi Pesan Responsif Multiplatform SMKN 12 Jakarta

-- Hapus database jika sudah ada (untuk development)
-- DROP DATABASE IF EXISTS responsive_message_db;

CREATE DATABASE IF NOT EXISTS responsive_message_db 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE responsive_message_db;

-- ======================================================================
-- TABEL UTAMA
-- ======================================================================

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE,
    user_type ENUM('Siswa', 'Guru', 'Orang_Tua', 'Admin', 'Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Wakil_Kepala', 'Kepala_Sekolah') NOT NULL,
    nis_nip VARCHAR(20) UNIQUE,
    nama_lengkap VARCHAR(100) NOT NULL,
    kelas VARCHAR(10),
    jurusan VARCHAR(50),
    mata_pelajaran VARCHAR(100),
    privilege_level ENUM('Full_Access', 'Limited_Lv1', 'Limited_Lv2', 'Limited_Lv3') DEFAULT 'Limited_Lv3',
    phone_number VARCHAR(20),
    avatar VARCHAR(255) DEFAULT 'default-avatar.png',
    is_active BOOLEAN DEFAULT TRUE,
    last_login DATETIME,
    reset_token VARCHAR(64),
    reset_expires DATETIME,
    api_key VARCHAR(64) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user_type (user_type),
    INDEX idx_nis_nip (nis_nip),
    INDEX idx_privilege (privilege_level),
    INDEX idx_email (email),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    jenis_pesan VARCHAR(50) NOT NULL UNIQUE,
    responder_type ENUM('Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana') NOT NULL,
    description TEXT,
    response_deadline_hours INT DEFAULT 72,
    color_code VARCHAR(7) DEFAULT '#0d6efd',
    icon_class VARCHAR(50) DEFAULT 'fas fa-envelope',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_responder_type (responder_type),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tanggal_pesan DATETIME DEFAULT CURRENT_TIMESTAMP,
    jenis_pesan_id INT NOT NULL,
    pengirim_id INT NOT NULL,
    pengirim_nama VARCHAR(100) NOT NULL,
    pengirim_nis_nip VARCHAR(20),
    isi_pesan TEXT NOT NULL,
    status ENUM('Pending', 'Dibaca', 'Diproses', 'Disetujui', 'Ditolak', 'Selesai', 'Expired', 'Dibatalkan') DEFAULT 'Pending',
    responder_id INT,
    catatan_respon TEXT,
    tanggal_respon DATETIME,
    expired_at DATETIME,
    priority ENUM('Low', 'Medium', 'High', 'Urgent') DEFAULT 'Medium',
    followup_count INT DEFAULT 0,
    last_followup DATETIME,
    email_notified BOOLEAN DEFAULT FALSE,
    whatsapp_notified BOOLEAN DEFAULT FALSE,
    sms_notified BOOLEAN DEFAULT FALSE,
    last_reminder_sent DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (jenis_pesan_id) REFERENCES message_types(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (pengirim_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (responder_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    
    INDEX idx_status (status),
    INDEX idx_pengirim (pengirim_id),
    INDEX idx_responder (responder_id),
    INDEX idx_tanggal (tanggal_pesan),
    INDEX idx_expired (expired_at),
    INDEX idx_priority (priority),
    INDEX idx_jenis_pesan (jenis_pesan_id),
    INDEX idx_created_at (created_at),
    INDEX idx_status_priority (status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_responses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    responder_id INT NOT NULL,
    catatan_respon TEXT NOT NULL,
    status ENUM('Disetujui', 'Ditolak', 'Ditunda', 'Diproses') NOT NULL,
    attachment VARCHAR(255),
    is_private BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (responder_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    
    INDEX idx_message (message_id),
    INDEX idx_responder (responder_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_attachments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    filetype VARCHAR(50),
    filesize BIGINT,
    is_approved BOOLEAN DEFAULT TRUE,
    virus_scan_status ENUM('Pending', 'Clean', 'Infected', 'Error') DEFAULT 'Pending',
    download_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    
    INDEX idx_message (message_id),
    INDEX idx_user (user_id),
    INDEX idx_filetype (filetype),
    INDEX idx_virus_status (virus_scan_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action_type VARCHAR(50) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_value JSON,
    new_value JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    request_method VARCHAR(10),
    request_url TEXT,
    response_code INT,
    execution_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    
    INDEX idx_user_action (user_id, action_type),
    INDEX idx_created_at (created_at),
    INDEX idx_action_type (action_type),
    INDEX idx_table_record (table_name, record_id),
    INDEX idx_ip_address (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    csrf_token VARCHAR(64),
    is_mobile BOOLEAN DEFAULT FALSE,
    device_info JSON,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity),
    INDEX idx_csrf_token (csrf_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT FALSE,
    user_agent TEXT,
    country_code VARCHAR(2),
    city VARCHAR(50),
    
    INDEX idx_username_ip (username, ip_address),
    INDEX idx_attempt_time (attempt_time),
    INDEX idx_success (success),
    INDEX idx_ip_address (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    size_bytes BIGINT,
    backup_type ENUM('Full', 'Incremental', 'Differential') DEFAULT 'Full',
    status ENUM('Success', 'Failed', 'In Progress') DEFAULT 'Success',
    error_message TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    
    INDEX idx_created_at (created_at),
    INDEX idx_status (status),
    INDEX idx_backup_type (backup_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'danger', 'primary') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    action_url VARCHAR(500),
    icon_class VARCHAR(50) DEFAULT 'fas fa-bell',
    priority ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at),
    INDEX idx_type (type),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE response_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    guru_type ENUM('Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana') NOT NULL,
    name VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'Umum',
    default_status ENUM('Disetujui', 'Ditolak', 'Ditunda', 'Diproses') DEFAULT 'Diproses',
    is_active BOOLEAN DEFAULT TRUE,
    use_count INT DEFAULT 0,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    
    INDEX idx_guru_type (guru_type),
    INDEX idx_category (category),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    permissions JSON,
    last_used_at DATETIME,
    expires_at DATETIME,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    
    INDEX idx_user_id (user_id),
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'boolean', 'json', 'array') DEFAULT 'string',
    category VARCHAR(50) DEFAULT 'General',
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_setting_key (setting_key),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT,
    recipient VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('Pending', 'Sent', 'Delivered', 'Failed') DEFAULT 'Pending',
    provider VARCHAR(50) DEFAULT 'CallMeBot',
    response_code INT,
    response_message TEXT,
    sent_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL ON UPDATE CASCADE,
    
    INDEX idx_message_id (message_id),
    INDEX idx_recipient (recipient),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    recipient VARCHAR(100) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('Pending', 'Sent', 'Failed') DEFAULT 'Pending',
    error_message TEXT,
    sent_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_recipient (recipient),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cache_data (
    cache_key VARCHAR(255) PRIMARY KEY,
    cache_value LONGTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_expires_at (expires_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================================
-- INSERT DATA AWAL
-- ======================================================================

-- Insert jenis pesan
INSERT INTO message_types (jenis_pesan, responder_type, description, response_deadline_hours, color_code, icon_class) VALUES
('Konsultasi/Konseling', 'Guru_BK', 'Pesan terkait bimbingan dan konseling siswa', 72, '#0d6efd', 'fas fa-comments'),
('Kehumasan', 'Guru_Humas', 'Pesan terkait hubungan masyarakat dan informasi sekolah', 48, '#198754', 'fas fa-handshake'),
('Kurikulum', 'Guru_Kurikulum', 'Pesan terkait kurikulum, mata pelajaran, dan akademik', 72, '#ffc107', 'fas fa-book'),
('Kesiswaan', 'Guru_Kesiswaan', 'Pesan terkait kegiatan dan masalah kesiswaan', 48, '#dc3545', 'fas fa-users'),
('Sarana Prasarana', 'Guru_Sarana', 'Pesan terkait sarana dan prasarana sekolah', 96, '#6c757d', 'fas fa-school');

-- Password default: password123 (di-hash dengan bcrypt)
-- Untuk pengguna: username123 (contoh: admin123, guru001123, siswa001123)

-- Insert admin (password: admin123)
INSERT INTO users (username, password_hash, email, user_type, nis_nip, nama_lengkap, privilege_level, phone_number) VALUES
('admin', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'admin@smkn12jakarta.sch.id', 'Admin', 'ADM001', 'Administrator Sistem', 'Full_Access', '081234567890');

-- Insert guru (password: username123)
INSERT INTO users (username, password_hash, email, user_type, nis_nip, nama_lengkap, mata_pelajaran, privilege_level, phone_number) VALUES
('guru001', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'budi.santoso@smkn12jakarta.sch.id', 'Guru', '198301011', 'Budi Santoso', 'Matematika', 'Limited_Lv2', '081122334455'),
('guru002', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'siti.aisyah@smkn12jakarta.sch.id', 'Guru_BK', '198301012', 'Siti Aisyah', 'Bimbingan Konseling', 'Limited_Lv1', '081122334456'),
('guru003', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'ahmad.fauzi@smkn12jakarta.sch.id', 'Guru_Humas', '198301013', 'Ahmad Fauzi', 'Hubungan Masyarakat', 'Limited_Lv1', '081122334457'),
('guru004', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'dewi.lestari@smkn12jakarta.sch.id', 'Guru_Kurikulum', '198301014', 'Dewi Lestari', 'Kurikulum', 'Limited_Lv1', '081122334458'),
('guru005', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'rudi.hermawan@smkn12jakarta.sch.id', 'Guru_Kesiswaan', '198301015', 'Rudi Hermawan', 'Kesiswaan', 'Limited_Lv1', '081122334459'),
('guru006', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'maya.indah@smkn12jakarta.sch.id', 'Guru_Sarana', '198301016', 'Maya Indah', 'Sarana Prasarana', 'Limited_Lv1', '081122334460'),
('guru007', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'joko.widodo@smkn12jakarta.sch.id', 'Wakil_Kepala', '198301017', 'Joko Widodo', 'Wakil Kepala Sekolah', 'Limited_Lv3', '081122334461'),
('guru008', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'sri.mulyani@smkn12jakarta.sch.id', 'Kepala_Sekolah', '198301018', 'Sri Mulyani', 'Kepala Sekolah', 'Full_Access', '081122334462'),
('guru009', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'agus.salim@smkn12jakarta.sch.id', 'Guru', '198301019', 'Agus Salim', 'Bahasa Indonesia', 'Limited_Lv2', '081122334463'),
('guru010', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'rina.marlina@smkn12jakarta.sch.id', 'Guru', '198301020', 'Rina Marlina', 'Bahasa Inggris', 'Limited_Lv2', '081122334464');

-- Insert siswa (password: username123)
INSERT INTO users (username, password_hash, email, user_type, nis_nip, nama_lengkap, kelas, jurusan, privilege_level, phone_number) VALUES
('siswa001', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'ahmad.fadil@smkn12jakarta.sch.id', 'Siswa', '20230001', 'Ahmad Fadil', 'X', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334401'),
('siswa002', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'budi.pratama@smkn12jakarta.sch.id', 'Siswa', '20230002', 'Budi Pratama', 'X', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334402'),
('siswa003', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'citra.dewi@smkn12jakarta.sch.id', 'Siswa', '20230003', 'Citra Dewi', 'X', 'Multimedia', 'Limited_Lv3', '081122334403'),
('siswa004', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'dedi.kurniawan@smkn12jakarta.sch.id', 'Siswa', '20230004', 'Dedi Kurniawan', 'X', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334404'),
('siswa005', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'eka.putri@smkn12jakarta.sch.id', 'Siswa', '20230005', 'Eka Putri', 'X', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334405'),
('siswa006', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'fajar.ramadan@smkn12jakarta.sch.id', 'Siswa', '20230006', 'Fajar Ramadan', 'XI', 'Multimedia', 'Limited_Lv3', '081122334406'),
('siswa007', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'gita.maya@smkn12jakarta.sch.id', 'Siswa', '20230007', 'Gita Maya', 'XI', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334407'),
('siswa008', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'hendra.wijaya@smkn12jakarta.sch.id', 'Siswa', '20230008', 'Hendra Wijaya', 'XI', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334408'),
('siswa009', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'indah.permata@smkn12jakarta.sch.id', 'Siswa', '20230009', 'Indah Permata', 'XI', 'Multimedia', 'Limited_Lv3', '081122334409'),
('siswa010', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'joko.susilo@smkn12jakarta.sch.id', 'Siswa', '20230010', 'Joko Susilo', 'XI', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334410'),
('siswa011', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'kartika.sari@smkn12jakarta.sch.id', 'Siswa', '20230011', 'Kartika Sari', 'XI', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334411'),
('siswa012', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'lukman.hakim@smkn12jakarta.sch.id', 'Siswa', '20230012', 'Lukman Hakim', 'XI', 'Multimedia', 'Limited_Lv3', '081122334412'),
('siswa013', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'maya.sari@smkn12jakarta.sch.id', 'Siswa', '20230013', 'Maya Sari', 'XII', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334413'),
('siswa014', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'nur.hidayat@smkn12jakarta.sch.id', 'Siswa', '20230014', 'Nur Hidayat', 'XII', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334414'),
('siswa015', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'oktavia.ayu@smkn12jakarta.sch.id', 'Siswa', '20230015', 'Oktavia Ayu', 'XII', 'Multimedia', 'Limited_Lv3', '081122334415'),
('siswa016', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'pratama.adit@smkn12jakarta.sch.id', 'Siswa', '20230016', 'Pratama Adit', 'XII', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334416'),
('siswa017', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'qory.sandioriva@smkn12jakarta.sch.id', 'Siswa', '20230017', 'Qory Sandioriva', 'XII', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334417'),
('siswa018', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'rahmat.hidayat@smkn12jakarta.sch.id', 'Siswa', '20230018', 'Rahmat Hidayat', 'XII', 'Multimedia', 'Limited_Lv3', '081122334418'),
('siswa019', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'siti.nurhaliza@smkn12jakarta.sch.id', 'Siswa', '20230019', 'Siti Nurhaliza', 'X', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334419'),
('siswa020', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'taufik.hidayat@smkn12jakarta.sch.id', 'Siswa', '20230020', 'Taufik Hidayat', 'X', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334420'),
('siswa021', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'ummu.kultsum@smkn12jakarta.sch.id', 'Siswa', '20230021', 'Ummu Kultsum', 'X', 'Multimedia', 'Limited_Lv3', '081122334421'),
('siswa022', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'vina.panduwinata@smkn12jakarta.sch.id', 'Siswa', '20230022', 'Vina Panduwinata', 'X', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334422'),
('siswa023', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'wawan.setiawan@smkn12jakarta.sch.id', 'Siswa', '20230023', 'Wawan Setiawan', 'XI', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334423'),
('siswa024', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'xavier.smith@smkn12jakarta.sch.id', 'Siswa', '20230024', 'Xavier Smith', 'XI', 'Multimedia', 'Limited_Lv3', '081122334424'),
('siswa025', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'yudi.hermawan@smkn12jakarta.sch.id', 'Siswa', '20230025', 'Yudi Hermawan', 'XI', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334425'),
('siswa026', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'zainal.abidin@smkn12jakarta.sch.id', 'Siswa', '20230026', 'Zainal Abidin', 'XI', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334426'),
('siswa027', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'ade.rahmawan@smkn12jakarta.sch.id', 'Siswa', '20230027', 'Ade Rahmawan', 'XII', 'Multimedia', 'Limited_Lv3', '081122334427'),
('siswa028', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'bambang.sutrisno@smkn12jakarta.sch.id', 'Siswa', '20230028', 'Bambang Sutrisno', 'XII', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334428'),
('siswa029', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'cindy.gultom@smkn12jakarta.sch.id', 'Siswa', '20230029', 'Cindy Gultom', 'XII', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334429'),
('siswa030', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'dian.sastrowardoyo@smkn12jakarta.sch.id', 'Siswa', '20230030', 'Dian Sastrowardoyo', 'XII', 'Multimedia', 'Limited_Lv3', '081122334430'),
('siswa031', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'erik.setiawan@smkn12jakarta.sch.id', 'Siswa', '20230031', 'Erik Setiawan', 'X', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334431'),
('siswa032', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'fitri.wulandari@smkn12jakarta.sch.id', 'Siswa', '20230032', 'Fitri Wulandari', 'X', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334432'),
('siswa033', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'guntur.sukarno@smkn12jakarta.sch.id', 'Siswa', '20230033', 'Guntur Sukarno', 'X', 'Multimedia', 'Limited_Lv3', '081122334433'),
('siswa034', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'hani.susanti@smkn12jakarta.sch.id', 'Siswa', '20230034', 'Hani Susanti', 'XI', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334434'),
('siswa035', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'irfan.bachdim@smkn12jakarta.sch.id', 'Siswa', '20230035', 'Irfan Bachdim', 'XI', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334435'),
('siswa036', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'jessica.mila@smkn12jakarta.sch.id', 'Siswa', '20230036', 'Jessica Mila', 'XI', 'Multimedia', 'Limited_Lv3', '081122334436'),
('siswa037', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'kevin.anggara@smkn12jakarta.sch.id', 'Siswa', '20230037', 'Kevin Anggara', 'XII', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334437'),
('siswa038', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'luna.maya@smkn12jakarta.sch.id', 'Siswa', '20230038', 'Luna Maya', 'XII', 'Teknik Komputer Jaringan', 'Limited_Lv3', '081122334438'),
('siswa039', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'mario.teguh@smkn12jakarta.sch.id', 'Siswa', '20230039', 'Mario Teguh', 'XII', 'Multimedia', 'Limited_Lv3', '081122334439'),
('siswa040', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'nurul.arifin@smkn12jakarta.sch.id', 'Siswa', '20230040', 'Nurul Arifin', 'XII', 'Rekayasa Perangkat Lunak', 'Limited_Lv3', '081122334440');

-- Insert orang tua (password: username123)
INSERT INTO users (username, password_hash, email, user_type, nis_nip, nama_lengkap, privilege_level, phone_number) VALUES
('ortu001', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'slamet.riyadi@gmail.com', 'Orang_Tua', 'OT2023001', 'Slamet Riyadi', 'Limited_Lv3', '081122334501'),
('ortu002', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'murni.lestari@gmail.com', 'Orang_Tua', 'OT2023002', 'Murni Lestari', 'Limited_Lv3', '081122334502'),
('ortu003', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'tono.wijaya@gmail.com', 'Orang_Tua', 'OT2023003', 'Tono Wijaya', 'Limited_Lv3', '081122334503'),
('ortu004', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'sari.dewi@gmail.com', 'Orang_Tua', 'OT2023004', 'Sari Dewi', 'Limited_Lv3', '081122334504'),
('ortu005', '$2y$10$3Z7Yc8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z', 'bambang.setiawan@gmail.com', 'Orang_Tua', 'OT2023005', 'Bambang Setiawan', 'Limited_Lv3', '081122334505');

-- Insert contoh pesan
INSERT INTO messages (jenis_pesan_id, pengirim_id, pengirim_nama, pengirim_nis_nip, isi_pesan, status, priority) VALUES
(1, 12, 'Ahmad Fadil', '20230001', 'Saya ingin berkonsultasi tentang pemilihan jurusan untuk kuliah nanti. Mohon bimbingan dari guru BK.', 'Pending', 'Medium'),
(2, 13, 'Budi Pratama', '20230002', 'Ada acara kunjungan industri dari perusahaan IT bulan depan. Bagaimana prosedur pendaftarannya?', 'Diproses', 'High'),
(3, 14, 'Citra Dewi', '20230003', 'Mata pelajaran Matematika terlalu sulit dipahami. Apakah bisa diadakan tambahan pelajaran?', 'Dibaca', 'Medium'),
(4, 15, 'Dedi Kurniawan', '20230004', 'Saya ingin mengadakan kegiatan pentas seni kelas. Mohon persetujuan dan bimbingannya.', 'Disetujui', 'Low'),
(5, 16, 'Eka Putri', '20230005', 'AC di lab komputer rusak. Mohon diperbaiki segera karena mengganggu kegiatan praktikum.', 'Ditolak', 'Urgent');

-- Insert contoh respons
INSERT INTO message_responses (message_id, responder_id, catatan_respon, status) VALUES
(4, 7, 'Kegiatan pentas seni disetujui dengan catatan harus melibatkan seluruh siswa kelas dan tidak mengganggu jam pelajaran. Silakan buat proposal lengkapnya.', 'Disetujui'),
(5, 8, 'AC lab komputer sedang dalam proses perbaikan. Perkiraan selesai dalam 3 hari kerja. Mohon bersabar.', 'Ditunda');

-- Insert template respons
INSERT INTO response_templates (guru_type, name, content, category, default_status) VALUES
('Guru_BK', 'Konsultasi Jurusan', 'Terima kasih atas konsultasi Anda. Untuk pemilihan jurusan yang tepat, saya sarankan: 1. Kenali minat dan bakat Anda, 2. Pelajari prospek kerja jurusan tersebut, 3. Konsultasi dengan orang tua. Mari kita diskusikan lebih lanjut.', 'Konseling', 'Disetujui'),
('Guru_Kurikulum', 'Tambahan Pelajaran', 'Mengenai permintaan tambahan pelajaran, kami akan mengadakan kelas tambahan setiap hari Jumat sore pukul 14.00-16.00. Silakan daftar di ruang guru kurikulum.', 'Akademik', 'Disetujui'),
('Guru_Sarana', 'Perbaikan Fasilitas', 'Laporan kerusakan telah diterima. Tim maintenance akan memeriksa dan memperbaiki dalam waktu 3-5 hari kerja. Terima kasih atas laporannya.', 'Fasilitas', 'Diproses');

-- Insert pengaturan sistem
INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description, is_public) VALUES
('app_name', 'Responsive Message SMKN 12 Jakarta', 'string', 'General', 'Nama Aplikasi', TRUE),
('app_version', '1.0.0', 'string', 'General', 'Versi Aplikasi', TRUE),
('maintenance_mode', '0', 'boolean', 'System', 'Mode Maintenance', FALSE),
('max_login_attempts', '5', 'integer', 'Security', 'Maksimal Percobaan Login', FALSE),
('session_timeout', '3600', 'integer', 'Security', 'Timeout Session (detik)', FALSE),
('message_limit_per_day', '10', 'integer', 'Messages', 'Batas Pesan per Hari per User', TRUE),
('default_response_deadline', '72', 'integer', 'Messages', 'Default Deadline Respons (jam)', FALSE),
('enable_whatsapp', '1', 'boolean', 'Notifications', 'Aktifkan WhatsApp Notifications', FALSE),
('enable_email', '1', 'boolean', 'Notifications', 'Aktifkan Email Notifications', FALSE),
('enable_sms', '0', 'boolean', 'Notifications', 'Aktifkan SMS Notifications', FALSE),
('backup_retention_days', '30', 'integer', 'Backup', 'Hari Retensi Backup', FALSE),
('auto_backup_time', '02:00', 'string', 'Backup', 'Waktu Auto Backup', FALSE);

-- ======================================================================
-- TRIGGERS
-- ======================================================================

DELIMITER //

CREATE TRIGGER audit_users_insert AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_value, created_at)
    VALUES (NEW.id, 'INSERT', 'users', NEW.id, 
            JSON_OBJECT(
                'username', NEW.username,
                'user_type', NEW.user_type,
                'nama_lengkap', NEW.nama_lengkap,
                'email', NEW.email,
                'nis_nip', NEW.nis_nip
            ), NOW());
END //

CREATE TRIGGER audit_users_update AFTER UPDATE ON users
FOR EACH ROW
BEGIN
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
END //

CREATE TRIGGER set_message_expired_at BEFORE INSERT ON messages
FOR EACH ROW
BEGIN
    DECLARE deadline_hours INT;
    
    SELECT response_deadline_hours INTO deadline_hours 
    FROM message_types 
    WHERE id = NEW.jenis_pesan_id;
    
    IF deadline_hours IS NOT NULL THEN
        SET NEW.expired_at = DATE_ADD(NOW(), INTERVAL deadline_hours HOUR);
    ELSE
        SET NEW.expired_at = DATE_ADD(NOW(), INTERVAL 72 HOUR);
    END IF;
END //

CREATE TRIGGER update_followup_count AFTER INSERT ON message_responses
FOR EACH ROW
BEGIN
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
END //

DELIMITER ;

-- ======================================================================
-- STORED PROCEDURES
-- ======================================================================

DELIMITER //

CREATE PROCEDURE GetMessageStatistics(IN p_user_id INT, IN p_start_date DATE, IN p_end_date DATE)
BEGIN
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
END //

CREATE PROCEDURE GetPendingFollowUps(IN p_guru_type VARCHAR(20))
BEGIN
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
END //

CREATE FUNCTION CalculateMessageAge(p_message_id INT) RETURNS INT
DETERMINISTIC
BEGIN
    DECLARE age_hours INT;
    
    SELECT TIMESTAMPDIFF(HOUR, created_at, NOW()) INTO age_hours
    FROM messages WHERE id = p_message_id;
    
    RETURN COALESCE(age_hours, 0);
END //

CREATE FUNCTION GetStatusColor(p_status VARCHAR(20)) RETURNS VARCHAR(7)
DETERMINISTIC
BEGIN
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
END //

DELIMITER ;

-- ======================================================================
-- VIEWS
-- ======================================================================

CREATE VIEW daily_message_report AS
SELECT 
    DATE(created_at) as report_date,
    COUNT(*) as total_messages,
    COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending,
    COUNT(CASE WHEN status = 'Disetujui' THEN 1 END) as approved,
    COUNT(CASE WHEN status = 'Ditolak' THEN 1 END) as rejected,
    COUNT(CASE WHEN status = 'Expired' THEN 1 END) as expired,
    COUNT(CASE WHEN priority = 'Urgent' THEN 1 END) as urgent_messages,
    AVG(TIMESTAMPDIFF(HOUR, created_at, COALESCE(tanggal_respon, NOW()))) as avg_response_time
FROM messages
GROUP BY DATE(created_at);

CREATE VIEW message_type_statistics AS
SELECT 
    mt.jenis_pesan,
    COUNT(m.id) as total_messages,
    COUNT(CASE WHEN m.status = 'Pending' THEN 1 END) as pending,
    COUNT(CASE WHEN m.status = 'Disetujui' THEN 1 END) as approved,
    COUNT(CASE WHEN m.status = 'Ditolak' THEN 1 END) as rejected,
    COUNT(CASE WHEN m.status = 'Expired' THEN 1 END) as expired,
    AVG(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW()))) as avg_response_time,
    mt.response_deadline_hours
FROM message_types mt
LEFT JOIN messages m ON mt.id = m.jenis_pesan_id
GROUP BY mt.id, mt.jenis_pesan, mt.response_deadline_hours;

CREATE VIEW user_activity_report AS
SELECT 
    u.id,
    u.username,
    u.nama_lengkap,
    u.user_type,
    u.last_login,
    COUNT(m.id) as total_messages_sent,
    COUNT(CASE WHEN m.status = 'Disetujui' THEN 1 END) as approved_messages,
    COUNT(CASE WHEN m.status = 'Ditolak' THEN 1 END) as rejected_messages,
    MAX(m.created_at) as last_message_date
FROM users u
LEFT JOIN messages m ON u.id = m.pengirim_id
WHERE u.is_active = 1
GROUP BY u.id, u.username, u.nama_lengkap, u.user_type, u.last_login;

CREATE VIEW guru_performance_report AS
SELECT 
    u.id,
    u.nis_nip,
    u.nama_lengkap,
    u.user_type,
    COUNT(DISTINCT m.id) as total_messages_assigned,
    COUNT(DISTINCT CASE WHEN m.status = 'Disetujui' THEN m.id END) as approved_messages,
    COUNT(DISTINCT CASE WHEN m.status = 'Ditolak' THEN m.id END) as rejected_messages,
    COUNT(DISTINCT CASE WHEN m.status IN ('Pending', 'Dibaca', 'Diproses') THEN m.id END) as pending_messages,
    AVG(TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW()))) as avg_response_time,
    COUNT(DISTINCT CASE WHEN TIMESTAMPDIFF(HOUR, m.created_at, COALESCE(m.tanggal_respon, NOW())) > 72 THEN m.id END) as late_responses
FROM users u
LEFT JOIN messages m ON u.id = m.responder_id
WHERE u.user_type IN ('Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana')
GROUP BY u.id, u.nis_nip, u.nama_lengkap, u.user_type;

-- ======================================================================
-- INDEXES TAMBAHAN
-- ======================================================================

CREATE INDEX idx_messages_search ON messages(pengirim_nama, isi_pesan(100), status);
CREATE INDEX idx_messages_date_range ON messages(created_at, updated_at);
CREATE INDEX idx_users_search ON users(nama_lengkap, nis_nip, email);
CREATE INDEX idx_audit_date_range ON audit_logs(created_at, action_type);
CREATE INDEX idx_notifications_unread ON notifications(user_id, is_read, created_at);

-- ======================================================================
-- EVENT SCHEDULER
-- ======================================================================

-- Pastikan event scheduler diaktifkan terlebih dahulu:
-- SET GLOBAL event_scheduler = ON;

DELIMITER //

CREATE EVENT IF NOT EXISTS cleanup_old_data
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
    -- Hapus session yang lebih dari 7 hari
    DELETE FROM sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- Hapus login attempts yang lebih dari 30 hari
    DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Hapus cache yang expired
    DELETE FROM cache_data WHERE expires_at < NOW();
    
    -- Update pesan yang expired
    UPDATE messages m
    INNER JOIN message_types mt ON m.jenis_pesan_id = mt.id
    SET m.status = 'Expired'
    WHERE m.status IN ('Pending', 'Dibaca', 'Diproses')
    AND TIMESTAMPDIFF(HOUR, m.created_at, NOW()) > mt.response_deadline_hours;
END //

CREATE EVENT IF NOT EXISTS auto_database_backup
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE() + INTERVAL 1 DAY, ' 02:00:00')
DO
BEGIN
    DECLARE backup_filename VARCHAR(255);
    
    SET backup_filename = CONCAT('auto_backup_', DATE_FORMAT(NOW(), '%Y%m%d'), '.sql');
    
    -- Simpan log backup
    INSERT INTO backups (filename, filepath, backup_type, status)
    VALUES (backup_filename, CONCAT('/backups/', backup_filename), 'Full', 'Success');
END //

DELIMITER ;

-- ======================================================================
-- VERIFIKASI DAN TESTING
-- ======================================================================

SELECT 'Database responsive_message_db berhasil dibuat!' as message;

SELECT 'Jumlah tabel yang dibuat:' as info, COUNT(*) as total_tables 
FROM information_schema.tables 
WHERE table_schema = 'responsive_message_db';

SELECT 'Data awal berhasil dimasukkan:' as summary;
SELECT 'Pengguna:' as kategori, COUNT(*) as jumlah FROM users
UNION ALL
SELECT 'Jenis Pesan:' as kategori, COUNT(*) as jumlah FROM message_types
UNION ALL
SELECT 'Pesan:' as kategori, COUNT(*) as jumlah FROM messages
UNION ALL
SELECT 'Respons:' as kategori, COUNT(*) as jumlah FROM message_responses
UNION ALL
SELECT 'Template:' as kategori, COUNT(*) as jumlah FROM response_templates
UNION ALL
SELECT 'Pengaturan:' as kategori, COUNT(*) as jumlah FROM system_settings;

SELECT '====================================' as separator;
SELECT 'INFORMASI LOGIN DEFAULT' as title;
SELECT '====================================' as separator;
SELECT 'Format password: username123' as note;
SELECT 'Contoh:' as example;
SELECT '  Username: admin, Password: admin123' as login_info;
SELECT '  Username: guru001, Password: guru001123' as login_info;
SELECT '  Username: siswa001, Password: siswa001123' as login_info;
SELECT '  Username: ortu001, Password: ortu001123' as login_info;
SELECT '====================================' as separator;
SELECT 'Seluruh pengguna menggunakan password yang sama: username123' as warning;
SELECT 'Wajib mengganti password setelah login pertama!' as warning;

-- Test beberapa fungsi dan procedures
SELECT 'Test fungsi CalculateMessageAge:' as test, CalculateMessageAge(1) as age_hours;
SELECT 'Test fungsi GetStatusColor:' as test, GetStatusColor('Pending') as color_code;