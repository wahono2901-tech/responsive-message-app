<?php
/**
 * Manajemen User - Guru
 * File: modules/admin/manage_users.php
 * 
 * PERBAIKAN:
 * - Mengubah style tab aktif dari background biru solid menjadi border biru tebal dengan background putih
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check authentication and admin privilege
Auth::checkAuth();
if ($_SESSION['user_type'] !== 'Admin' && $_SESSION['privilege_level'] !== 'Full_Access') {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

$pageTitle = 'Manajemen User';

// Get user type from query parameter
$userType = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get database connection
$db = Database::getInstance()->getConnection();

// Build query based on user type
$whereClause = "WHERE is_active = 1";
$params = [];

if ($userType !== 'all') {
    $whereClause .= " AND user_type = :user_type";
    $params[':user_type'] = $userType;
}

if (!empty($search)) {
    $whereClause .= " AND (username LIKE :search OR nama_lengkap LIKE :search OR email LIKE :search OR nis_nip LIKE :search)";
    $params[':search'] = "%$search%";
}

// Get total count for pagination
$countStmt = $db->prepare("SELECT COUNT(*) as total FROM users $whereClause");
$countStmt->execute($params);
$totalUsers = $countStmt->fetch()['total'];
$totalPages = ceil($totalUsers / $limit);

// Get users with pagination
$query = "
    SELECT 
        id, username, email, user_type, nama_lengkap, nis_nip, 
        kelas, jurusan, mata_pelajaran, phone_number, avatar,
        is_active, last_login, created_at
    FROM users 
    $whereClause
    ORDER BY created_at DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get user type statistics
$typeStatsQuery = "
    SELECT 
        user_type,
        COUNT(*) as count
    FROM users
    WHERE is_active = 1
    GROUP BY user_type
    ORDER BY count DESC
";
$typeStats = $db->query($typeStatsQuery)->fetchAll();

require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-users me-2"></i>Manajemen User
            </h1>
            <p class="text-muted mb-0">
                <?php 
                $typeLabels = [
                    'all' => 'Semua User',
                    'Siswa' => 'Siswa',
                    'Guru' => 'Guru',
                    'Guru_BK' => 'Guru BK',
                    'Guru_Humas' => 'Guru Humas',
                    'Guru_Kurikulum' => 'Guru Kurikulum',
                    'Guru_Kesiswaan' => 'Guru Kesiswaan',
                    'Guru_Sarana' => 'Guru Sarana',
                    'Orang_Tua' => 'Orang Tua',
                    'Admin' => 'Admin',
                    'Wakil_Kepala' => 'Wakil Kepala',
                    'Kepala_Sekolah' => 'Kepala Sekolah'
                ];
                echo $typeLabels[$userType] ?? 'Semua User';
                ?>
                <span class="badge bg-primary ms-2"><?php echo $totalUsers; ?> user</span>
            </p>
        </div>
        <div>
            <a href="add_user.php" class="btn btn-primary">
                <i class="fas fa-user-plus me-1"></i> Tambah User
            </a>
            <a href="manage_users.php?type=all" class="btn btn-outline-secondary">
                <i class="fas fa-redo me-1"></i> Reset Filter
            </a>
        </div>
    </div>
    
    <!-- User Type Filter Tabs -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-filter me-2"></i>Filter Berdasarkan Tipe User
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="nav-scroller">
                        <nav class="nav nav-pills nav-justified">
                            <a class="nav-link <?php echo $userType === 'all' ? 'active' : ''; ?>" 
                               href="manage_users.php?type=all">
                                <i class="fas fa-users me-1"></i> Semua
                                <span class="badge bg-secondary ms-1"><?php echo array_sum(array_column($typeStats, 'count')); ?></span>
                            </a>
                            <?php foreach ($typeStats as $stat): ?>
                                <?php if ($stat['user_type'] === 'Siswa'): ?>
                                <a class="nav-link <?php echo $userType === 'Siswa' ? 'active' : ''; ?>" 
                                   href="manage_users.php?type=Siswa">
                                    <i class="fas fa-user-graduate me-1"></i> Siswa
                                    <span class="badge bg-info ms-1"><?php echo $stat['count']; ?></span>
                                </a>
                                <?php elseif ($stat['user_type'] === 'Guru'): ?>
                                <a class="nav-link <?php echo $userType === 'Guru' ? 'active' : ''; ?>" 
                                   href="manage_users.php?type=Guru">
                                    <i class="fas fa-chalkboard-teacher me-1"></i> Guru
                                    <span class="badge bg-success ms-1"><?php echo $stat['count']; ?></span>
                                </a>
                                <?php elseif (strpos($stat['user_type'], 'Guru_') === 0): ?>
                                <a class="nav-link <?php echo $userType === $stat['user_type'] ? 'active' : ''; ?>" 
                                   href="manage_users.php?type=<?php echo $stat['user_type']; ?>">
                                    <i class="fas fa-user-tie me-1"></i> <?php echo substr($stat['user_type'], 5); ?>
                                    <span class="badge bg-warning ms-1"><?php echo $stat['count']; ?></span>
                                </a>
                                <?php elseif ($stat['user_type'] === 'Orang_Tua'): ?>
                                <a class="nav-link <?php echo $userType === 'Orang_Tua' ? 'active' : ''; ?>" 
                                   href="manage_users.php?type=Orang_Tua">
                                    <i class="fas fa-users me-1"></i> Orang Tua
                                    <span class="badge bg-primary ms-1"><?php echo $stat['count']; ?></span>
                                </a>
                                <?php elseif (in_array($stat['user_type'], ['Admin', 'Wakil_Kepala', 'Kepala_Sekolah'])): ?>
                                <a class="nav-link <?php echo $userType === $stat['user_type'] ? 'active' : ''; ?>" 
                                   href="manage_users.php?type=<?php echo $stat['user_type']; ?>">
                                    <i class="fas fa-user-shield me-1"></i> <?php echo $stat['user_type']; ?>
                                    <span class="badge bg-danger ms-1"><?php echo $stat['count']; ?></span>
                                </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Search and Filter -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <input type="hidden" name="type" value="<?php echo htmlspecialchars($userType); ?>">
                        
                        <div class="col-md-8">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-search"></i>
                                </span>
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Cari berdasarkan username, nama, email, atau NIS/NIP..."
                                       value="<?php echo htmlspecialchars($search); ?>">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search me-1"></i> Cari
                                </button>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="d-flex gap-2">
                                <select class="form-select" name="sort" onchange="this.form.submit()">
                                    <option value="newest" selected>Urutkan: Terbaru</option>
                                    <option value="oldest">Terlama</option>
                                    <option value="name_asc">Nama A-Z</option>
                                    <option value="name_desc">Nama Z-A</option>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" onclick="exportUsers()">
                                    <i class="fas fa-download"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Users Table -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Daftar User
                            <small class="text-muted ms-2">Menampilkan <?php echo count($users); ?> dari <?php echo $totalUsers; ?> user</small>
                        </h5>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="activateSelected()">
                                <i class="fas fa-check me-1"></i> Aktifkan
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deactivateSelected()">
                                <i class="fas fa-times me-1"></i> Nonaktifkan
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="exportSelected()">
                                <i class="fas fa-file-export me-1"></i> Export
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" id="select-all" onchange="toggleSelectAll()">
                                    </th>
                                    <th>User</th>
                                    <th>Tipe</th>
                                    <th>Kontak</th>
                                    <th>Info Tambahan</th>
                                    <th>Status</th>
                                    <th>Terakhir Login</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Tidak ada user ditemukan</h5>
                                        <p class="text-muted">Coba ubah filter atau kata kunci pencarian</p>
                                        <a href="add_user.php" class="btn btn-primary">
                                            <i class="fas fa-user-plus me-1"></i> Tambah User Baru
                                        </a>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="user-checkbox" value="<?php echo $user['id']; ?>">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-2">
                                                    <?php if (!empty($user['avatar']) && $user['avatar'] !== 'default-avatar.png'): ?>
                                                    <img src="<?php echo BASE_URL . 'assets/uploads/avatars/' . htmlspecialchars($user['avatar']); ?>" 
                                                         alt="Avatar" class="rounded-circle" width="36" height="36">
                                                    <?php else: ?>
                                                    <div class="avatar-title bg-primary-light rounded-circle">
                                                        <i class="fas fa-user text-primary"></i>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($user['nama_lengkap']); ?></h6>
                                                    <small class="text-muted d-block">@<?php echo htmlspecialchars($user['username']); ?></small>
                                                    <small class="text-muted">ID: <?php echo htmlspecialchars($user['id']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $typeBadges = [
                                                'Siswa' => 'bg-info',
                                                'Guru' => 'bg-success',
                                                'Guru_BK' => 'bg-warning',
                                                'Guru_Humas' => 'bg-warning',
                                                'Guru_Kurikulum' => 'bg-warning',
                                                'Guru_Kesiswaan' => 'bg-warning',
                                                'Guru_Sarana' => 'bg-warning',
                                                'Orang_Tua' => 'bg-primary',
                                                'Admin' => 'bg-danger',
                                                'Wakil_Kepala' => 'bg-danger',
                                                'Kepala_Sekolah' => 'bg-danger'
                                            ];
                                            $typeLabel = $user['user_type'];
                                            if (strpos($typeLabel, 'Guru_') === 0) {
                                                $typeLabel = substr($typeLabel, 5);
                                            }
                                            ?>
                                            <span class="badge <?php echo $typeBadges[$user['user_type']] ?? 'bg-secondary'; ?>">
                                                <?php echo htmlspecialchars($typeLabel); ?>
                                            </span>
                                            <?php if (!empty($user['nis_nip'])): ?>
                                            <small class="d-block text-muted mt-1">NIP/NIS: <?php echo htmlspecialchars($user['nis_nip']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <div class="mb-1">
                                                    <i class="fas fa-envelope me-1 text-muted"></i>
                                                    <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($user['email']); ?>
                                                    </a>
                                                </div>
                                                <?php if (!empty($user['phone_number'])): ?>
                                                <div>
                                                    <i class="fas fa-phone me-1 text-muted"></i>
                                                    <a href="tel:<?php echo htmlspecialchars($user['phone_number']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($user['phone_number']); ?>
                                                    </a>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small text-muted">
                                                <?php if (!empty($user['kelas'])): ?>
                                                <div class="mb-1">
                                                    <i class="fas fa-graduation-cap me-1"></i>
                                                    Kelas: <?php echo htmlspecialchars($user['kelas']); ?>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($user['jurusan'])): ?>
                                                <div class="mb-1">
                                                    <i class="fas fa-book me-1"></i>
                                                    Jurusan: <?php echo htmlspecialchars($user['jurusan']); ?>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($user['mata_pelajaran'])): ?>
                                                <div>
                                                    <i class="fas fa-chalkboard me-1"></i>
                                                    Mapel: <?php echo htmlspecialchars($user['mata_pelajaran']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check-circle me-1"></i> Aktif
                                            </span>
                                            <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-times-circle me-1"></i> Nonaktif
                                            </span>
                                            <?php endif; ?>
                                            <div class="small text-muted mt-1">
                                                Bergabung: <?php echo date('d/m/Y', strtotime($user['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['last_login'])): ?>
                                            <div class="small">
                                                <div class="text-success">
                                                    <i class="fas fa-sign-in-alt me-1"></i>
                                                    <?php echo Functions::timeAgo($user['last_login']); ?>
                                                </div>
                                                <div class="text-muted">
                                                    <?php echo date('d/m/Y H:i', strtotime($user['last_login'])); ?>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">Belum pernah login</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button class="btn btn-outline-primary" 
                                                        onclick="viewUser(<?php echo $user['id']; ?>)"
                                                        title="Lihat Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <a href="edit_user.php?id=<?php echo $user['id']; ?>" 
                                                   class="btn btn-outline-warning"
                                                   title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button class="btn btn-outline-danger" 
                                                        onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['nama_lengkap']); ?>')"
                                                        title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php if ($user['is_active']): ?>
                                                <button class="btn btn-outline-secondary" 
                                                        onclick="toggleActive(<?php echo $user['id']; ?>, 0)"
                                                        title="Nonaktifkan">
                                                    <i class="fas fa-user-slash"></i>
                                                </button>
                                                <?php else: ?>
                                                <button class="btn btn-outline-success" 
                                                        onclick="toggleActive(<?php echo $user['id']; ?>, 1)"
                                                        title="Aktifkan">
                                                    <i class="fas fa-user-check"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white border-0 py-3">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="?type=<?php echo $userType; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($i == $page): ?>
                                <li class="page-item active">
                                    <span class="page-link"><?php echo $i; ?></span>
                                </li>
                                <?php elseif ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                <li class="page-item">
                                    <a class="page-link" 
                                       href="?type=<?php echo $userType; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="?type=<?php echo $userType; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.avatar-sm {
    width: 36px;
    height: 36px;
}

.avatar-title {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.table tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.02);
}

.nav-scroller {
    position: relative;
    z-index: 2;
    height: 2.75rem;
    overflow-y: hidden;
}

.nav-scroller .nav {
    display: flex;
    flex-wrap: nowrap;
    padding-bottom: 1rem;
    margin-top: -1px;
    overflow-x: auto;
    text-align: center;
    white-space: nowrap;
}

/* =========================================================== */
/* MODIFIKASI: Style untuk tab aktif - Border biru tebal dengan background putih */
/* =========================================================== */
.nav-pills .nav-link {
    border-radius: 0;
    padding: 0.75rem 1rem;
    border: 3px solid transparent; /* Transparent border untuk menjaga layout */
    background-color: transparent;
    color: #495057;
    transition: all 0.2s ease;
}

.nav-pills .nav-link:hover {
    background-color: rgba(13, 110, 253, 0.05);
    border-color: rgba(13, 110, 253, 0.3);
}

.nav-pills .nav-link.active {
    background-color: white !important; /* Background putih */
    border: 3px solid #0d6efd !important; /* Border biru tebal 3mm */
    color: #0d6efd !important; /* Teks biru */
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(13, 110, 253, 0.2);
}

/* Pastikan badge tetap terlihat dengan baik pada tab aktif */
.nav-pills .nav-link.active .badge {
    background-color: #0d6efd !important;
    color: white !important;
    border: 1px solid white;
}
/* =========================================================== */
</style>

<script>
// Select/Deselect all
function toggleSelectAll() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.user-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
}

// Get selected user IDs
function getSelectedUserIds() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Activate selected users
function activateSelected() {
    const userIds = getSelectedUserIds();
    if (userIds.length === 0) {
        alert('Pilih minimal satu user untuk diaktifkan');
        return;
    }
    
    if (confirm(`Aktifkan ${userIds.length} user yang dipilih?`)) {
        showLoading('Mengaktifkan user...');
        
        fetch('<?php echo BASE_URL; ?>api/users.php?action=activate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ user_ids: userIds })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('success', `${userIds.length} user berhasil diaktifkan`);
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('error', data.message || 'Gagal mengaktifkan user');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', 'Gagal mengaktifkan user');
        })
        .finally(() => {
            hideLoading();
        });
    }
}

// Deactivate selected users
function deactivateSelected() {
    const userIds = getSelectedUserIds();
    if (userIds.length === 0) {
        alert('Pilih minimal satu user untuk dinonaktifkan');
        return;
    }
    
    if (confirm(`Nonaktifkan ${userIds.length} user yang dipilih?`)) {
        showLoading('Menonaktifkan user...');
        
        fetch('<?php echo BASE_URL; ?>api/users.php?action=deactivate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ user_ids: userIds })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('success', `${userIds.length} user berhasil dinonaktifkan`);
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('error', data.message || 'Gagal menonaktifkan user');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', 'Gagal menonaktifkan user');
        })
        .finally(() => {
            hideLoading();
        });
    }
}

// Export selected users
function exportSelected() {
    const userIds = getSelectedUserIds();
    if (userIds.length === 0) {
        alert('Pilih minimal satu user untuk diexport');
        return;
    }
    
    showLoading('Membuat file export...');
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo BASE_URL; ?>api/users.php?action=export';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'user_ids';
    input.value = JSON.stringify(userIds);
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
    
    setTimeout(() => hideLoading(), 2000);
}

// Toggle user active status
function toggleActive(userId, status) {
    const action = status ? 'aktifkan' : 'nonaktifkan';
    if (confirm(`Apakah Anda yakin ingin ${action} user ini?`)) {
        showLoading(`Meng${action} user...`);
        
        fetch(`<?php echo BASE_URL; ?>api/users.php?action=toggle_active&id=${userId}&status=${status}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('success', `User berhasil di${action}`);
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', data.message || `Gagal meng${action} user`);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', `Gagal meng${action} user`);
        })
        .finally(() => {
            hideLoading();
        });
    }
}

// Delete user
function deleteUser(userId, userName) {
    if (confirm(`Apakah Anda yakin ingin menghapus user "${userName}"?\n\nTindakan ini tidak dapat dibatalkan!`)) {
        showLoading('Menghapus user...');
        
        fetch(`<?php echo BASE_URL; ?>api/users.php?action=delete&id=${userId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('success', 'User berhasil dihapus');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', data.message || 'Gagal menghapus user');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', 'Gagal menghapus user');
        })
        .finally(() => {
            hideLoading();
        });
    }
}

// View user details
function viewUser(userId) {
    window.open(`user_detail.php?id=${userId}`, '_blank');
}

// Export all users
function exportUsers() {
    showLoading('Menyiapkan data untuk export...');
    
    const url = `<?php echo BASE_URL; ?>api/users.php?action=export_all&type=<?php echo $userType; ?>&search=<?php echo urlencode($search); ?>`;
    window.open(url, '_blank');
    
    setTimeout(() => hideLoading(), 1000);
}

// Helper functions
function showLoading(message) {
    // Implement loading indicator
    const loadingEl = document.getElementById('loading-indicator') || createLoadingIndicator();
    loadingEl.querySelector('.loading-message').textContent = message;
    loadingEl.style.display = 'flex';
}

function hideLoading() {
    const loadingEl = document.getElementById('loading-indicator');
    if (loadingEl) {
        loadingEl.style.display = 'none';
    }
}

function createLoadingIndicator() {
    const div = document.createElement('div');
    div.id = 'loading-indicator';
    div.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.7);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        color: white;
    `;
    div.innerHTML = `
        <div class="spinner-border mb-3" style="width: 3rem; height: 3rem;"></div>
        <div class="loading-message" style="font-size: 1.2rem;"></div>
    `;
    document.body.appendChild(div);
    return div;
}

function showToast(type, message) {
    // Use Bootstrap toast
    const toastEl = document.getElementById('liveToast') || createToast();
    const toastBody = toastEl.querySelector('.toast-body');
    const toastHeader = toastEl.querySelector('.toast-header');
    
    // Set colors based on type
    const colors = {
        success: { bg: 'bg-success', text: 'text-white' },
        error: { bg: 'bg-danger', text: 'text-white' },
        warning: { bg: 'bg-warning', text: 'text-dark' },
        info: { bg: 'bg-info', text: 'text-white' }
    };
    
    toastHeader.className = `toast-header ${colors[type].bg} ${colors[type].text}`;
    toastBody.textContent = message;
    
    const toast = new bootstrap.Toast(toastEl);
    toast.show();
}

function createToast() {
    const div = document.createElement('div');
    div.id = 'liveToast';
    div.className = 'toast position-fixed top-0 end-0 m-3';
    div.setAttribute('role', 'alert');
    div.setAttribute('aria-live', 'assertive');
    div.setAttribute('aria-atomic', 'true');
    div.innerHTML = `
        <div class="toast-header">
            <strong class="me-auto">Notifikasi</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body"></div>
    `;
    document.body.appendChild(div);
    return div;
}
</script>

<?php
require_once '../../includes/footer.php';
?>