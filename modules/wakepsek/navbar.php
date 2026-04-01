<?php
/**
 * Navigation for Wakepsek/Kepsek
 */
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo BASE_URL; ?>/modules/wakepsek/dashboard.php">
            <i class="fas fa-school me-2"></i>
            SMKN 12 Jakarta - <?php echo $_SESSION['user_type'] == 'Kepala_Sekolah' ? 'Kepala Sekolah' : 'Wakil Kepala Sekolah'; ?>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/modules/wakepsek/dashboard.php">
                        <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i> 
                        <?php echo htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username']); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="<?php echo BASE_URL; ?>/profile.php">
                                <i class="fas fa-id-card me-2"></i> Profile
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo BASE_URL; ?>/change_password.php">
                                <i class="fas fa-key me-2"></i> Ubah Password
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>/logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>