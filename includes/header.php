<?php
// File: includes/header.php

// Default meta tags dengan nilai yang aman
if (!isset($meta_tags)) {
    $meta_tags = [
        'charset' => 'UTF-8',
        'viewport' => 'width=device-width, initial-scale=1.0',
        'description' => 'Aplikasi Pesan Responsif SMKN 12 Jakarta',
        'keywords' => 'pesan, sekolah, komunikasi, smkn 12',
        'author' => 'SMKN 12 Jakarta'
    ];
} else {
    // Pastikan semua key memiliki nilai default jika null
    $default_meta = [
        'charset' => 'UTF-8',
        'viewport' => 'width=device-width, initial-scale=1.0',
        'description' => 'Aplikasi Pesan Responsif SMKN 12 Jakarta',
        'keywords' => 'pesan, sekolah, komunikasi, smkn 12',
        'author' => 'SMKN 12 Jakarta'
    ];
    
    foreach ($default_meta as $key => $value) {
        if (!isset($meta_tags[$key]) || $meta_tags[$key] === null) {
            $meta_tags[$key] = $value;
        }
    }
}

// Default title dengan fallback
if (!isset($title) || $title === null) {
    $title = 'Aplikasi Pesan Responsif';
}

// Fungsi helper untuk sanitize output
function safe_output($value, $default = '') {
    if ($value === null || $value === '') {
        return htmlspecialchars($default);
    }
    return htmlspecialchars($value);
}

// Cek preferensi dark mode dari cookie
$darkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled';

// =========================================================
// MODIFIKASI: CEK USER TYPE UNTUK NAVIGASI KHUSUS
// =========================================================
$currentUserType = $_SESSION['user_type'] ?? '';

// Jika user adalah Wakepsek atau Kepsek, kita akan menggunakan navbar khusus nanti
// Tapi kita tidak bisa require di sini karena masih di <head>
// Kita akan set flag untuk digunakan di bagian body nanti
$useWakepsekNavbar = in_array($currentUserType, ['Wakil_Kepala', 'Kepala_Sekolah']);
?>
<!DOCTYPE html>
<html lang="id" <?php echo $darkMode ? 'data-theme="dark"' : ''; ?>>
<head>
    <meta charset="<?php echo safe_output($meta_tags['charset']); ?>">
    <meta name="viewport" content="<?php echo safe_output($meta_tags['viewport']); ?>">
    <meta name="description" content="<?php echo safe_output($meta_tags['description']); ?>">
    <meta name="keywords" content="<?php echo safe_output($meta_tags['keywords']); ?>">
    <meta name="author" content="<?php echo safe_output($meta_tags['author']); ?>">
    
    <!-- Content Security Policy -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.jsdelivr.net; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data: https:; connect-src 'self'; frame-src 'none'; object-src 'none';">
    
    <!-- CSRF Token Meta Tag -->
    <meta name="csrf-token" content="<?php echo safe_output($_SESSION['csrf_token'] ?? ''); ?>">
    
    <!-- JavaScript Global Variables -->
    <script>
    window.APP_CONFIG = {
        BASE_URL: '<?php echo safe_output(BASE_URL); ?>',
        ASSET_URL: '<?php echo safe_output(asset_url("")); ?>',
        MODULE_URL: '<?php echo safe_output(module_url("")); ?>',
        APP_NAME: '<?php echo safe_output(APP_NAME); ?>',
        CSRF_TOKEN: '<?php echo safe_output($_SESSION['csrf_token'] ?? ''); ?>',
        USER_ID: '<?php echo safe_output($_SESSION['user_id'] ?? 0); ?>',
        USER_TYPE: '<?php echo safe_output($_SESSION['user_type'] ?? ''); ?>',
        DARK_MODE: <?php echo $darkMode ? 'true' : 'false'; ?>
    };
    </script>
    
    <title><?php echo safe_output($title); ?> | SMKN 12 Jakarta</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <!-- Custom CSS -->
    <link href="<?php echo safe_output(asset_url('css/style.css')); ?>" rel="stylesheet">
    
    <!-- Dark Mode CSS -->
    <link href="<?php echo safe_output(asset_url('css/dark-mode.css')); ?>" rel="stylesheet">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo safe_output(asset_url('images/favicon.ico')); ?>">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js" integrity="sha256-oP6HI9z1XaZNBrJURtCoUT5SUnxFr8s3BzRl+cbzUq8=" crossorigin="anonymous"></script>
    
    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Custom JS with cache busting -->
    <script src="<?php echo safe_output(asset_url('js/main.js')); ?>?v=<?php echo time(); ?>"></script>
    
    <!-- Dark Mode Toggle Script -->
    <script>
    // Dark mode toggle function
    function toggleDarkMode() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? '' : 'dark';
        
        // Set theme
        if (newTheme === 'dark') {
            html.setAttribute('data-theme', 'dark');
        } else {
            html.removeAttribute('data-theme');
        }
        
        // Save preference to cookie (30 days)
        const date = new Date();
        date.setTime(date.getTime() + (30 * 24 * 60 * 60 * 1000));
        document.cookie = `dark_mode=${newTheme === 'dark' ? 'enabled' : 'disabled'}; expires=${date.toUTCString()}; path=/`;
        
        // Update toggle button icon
        updateDarkModeIcon();
        
        // Trigger custom event for other scripts
        window.dispatchEvent(new CustomEvent('darkmodechange', { detail: { darkMode: newTheme === 'dark' } }));
    }
    
    // Update dark mode icon based on current theme
    function updateDarkModeIcon() {
        const html = document.documentElement;
        const isDark = html.getAttribute('data-theme') === 'dark';
        const toggleBtn = document.getElementById('darkModeToggle');
        
        if (toggleBtn) {
            const icon = toggleBtn.querySelector('i');
            if (icon) {
                if (isDark) {
                    icon.className = 'fas fa-sun';
                    toggleBtn.setAttribute('title', 'Mode Terang');
                } else {
                    icon.className = 'fas fa-moon';
                    toggleBtn.setAttribute('title', 'Mode Gelap');
                }
            }
        }
    }
    
    // Initialize dark mode on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateDarkModeIcon();
        
        // Listen for system preference changes
        const prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)');
        prefersDarkScheme.addEventListener('change', function(e) {
            if (!document.cookie.includes('dark_mode')) {
                // Only apply if user hasn't set preference
                const html = document.documentElement;
                if (e.matches) {
                    html.setAttribute('data-theme', 'dark');
                } else {
                    html.removeAttribute('data-theme');
                }
                updateDarkModeIcon();
            }
        });
    });
    </script>
    
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #198754;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #0dcaf0;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        main {
            flex: 1;
        }
        
        .navbar-brand {
            font-weight: bold;
        }
        
        .card {
            border-radius: 10px;
            transition: transform 0.3s, box-shadow 0.3s, background-color 0.3s;
            border: 1px solid rgba(0,0,0,.125);
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,.1) !important;
        }
        
        .feature-icon {
            color: var(--primary-color);
        }
        
        .hero-section {
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            border-radius: 0 0 20px 20px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0a58ca;
        }
        
        .footer {
            background-color: #343a40;
            color: white;
            margin-top: auto;
            transition: background-color 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        .alert {
            border-radius: 8px;
        }
        
        .nav-link.active {
            font-weight: bold;
            color: var(--primary-color) !important;
        }
        
        /* Dark Mode Toggle Button */
        #darkModeToggle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            margin-left: 8px;
            transition: all 0.3s ease;
        }
        
        #darkModeToggle i {
            font-size: 1.1rem;
        }
        
        #darkModeToggle:hover {
            transform: rotate(15deg);
        }
        
        /* Responsive adjustments for dark mode toggle */
        @media (max-width: 991px) {
            #darkModeToggle {
                margin: 8px 0 8px 8px;
                width: 100%;
                border-radius: 8px;
                justify-content: flex-start;
                padding: 0 12px;
            }
            
            #darkModeToggle i {
                margin-right: 8px;
            }
        }
        
        /* Smooth transitions */
        * {
            transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
        }
    </style>
</head>
<body>
    <!-- ========================================================= -->
    <!-- MODIFIKASI: Navigation - Pilih navbar berdasarkan user type -->
    <!-- ========================================================= -->
    <?php if ($useWakepsekNavbar): ?>
        <!-- Navbar khusus untuk Wakepsek/Kepsek -->
        <?php 
        // Cek apakah file navbar wakepsek ada
        $wakepsekNavbarFile = __DIR__ . '/../modules/wakepsek/navbar.php';
        if (file_exists($wakepsekNavbarFile)) {
            require_once $wakepsekNavbarFile;
        } else {
            // Fallback ke navbar default jika file tidak ditemukan
            require_once __DIR__ . '/navbar.php';
        }
        ?>
    <?php else: ?>
        <!-- Navbar default (yang sudah ada di bawah) -->
        <!-- Navigation -->
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
            <div class="container">
                <a class="navbar-brand d-flex align-items-center" href="<?php echo safe_output(app_url()); ?>">
                    <i class="fas fa-comments text-primary me-2"></i>
                    <span class="fw-bold">SMKN 12 Jakarta</span>
                </a>
                
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo safe_output(app_url()); ?>">
                                <i class="fas fa-home me-1"></i> Beranda
                            </a>
                        </li>
                        
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <!-- Menu untuk user yang sudah login -->
                            
                            <!-- DASHBOARD LINK UNTUK GURU - TAMBAHAN -->
                            <?php if (in_array($_SESSION['user_type'] ?? '', ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'])): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo safe_output(module_url('guru/dashboard_guru.php')); ?>">
                                    <i class="fas fa-chart-line me-1"></i>Dashboard
                                </a>
                            </li>
                            <?php endif; ?>
                            <!-- END DASHBOARD LINK -->
                            
                            <!-- ========================================================= -->
                            <!-- MODIFIKASI: Dashboard Admin - Link Langsung (KELUAR DARI DROPDOWN) -->
                            <!-- ========================================================= -->
                            <?php if (in_array($_SESSION['user_type'] ?? '', ['Admin', 'Kepala_Sekolah', 'Wakil_Kepala'])): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo safe_output(module_url('admin/dashboard.php')); ?>">
                                    <i class="fas fa-tachometer-alt me-1"></i> Dashboard Admin
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <!-- ========================================================= -->
                            <!-- MODIFIKASI: Dropdown User - SEKARANG TANPA DASHBOARD ADMIN -->
                            <!-- ========================================================= -->
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-user me-1"></i> <?php echo safe_output($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <?php if (strpos($_SESSION['user_type'] ?? '', 'Guru_') === 0): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?php echo safe_output(module_url('guru/followup.php')); ?>">
                                                <i class="fas fa-tasks me-2"></i>Follow Up Pesan
                                            </a>
                                        </li>
                                        <!-- Tambahkan link Dashboard juga di dropdown untuk akses cepat -->
                                        <li>
                                            <a class="dropdown-item" href="<?php echo safe_output(module_url('guru/dashboard_guru.php')); ?>">
                                                <i class="fas fa-chart-line me-2"></i>Dashboard Analisis
                                            </a>
                                        </li>
                                    <?php elseif (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Kepala_Sekolah', 'Wakil_Kepala'])): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?php echo safe_output(module_url('user/send_message.php')); ?>">
                                                <i class="fas fa-paper-plane me-2"></i>Kirim Pesan
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="<?php echo safe_output(module_url('user/messages.php')); ?>">
                                                <i class="fas fa-inbox me-2"></i>Pesan Saya
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item" href="<?php echo safe_output(app_url('profile.php')); ?>">
                                            <i class="fas fa-user-circle me-2"></i>Profil
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="<?php echo safe_output(app_url('logout.php')); ?>">
                                            <i class="fas fa-sign-out-alt me-2"></i>Keluar
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php else: ?>
                            <!-- Menu untuk user belum login -->
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo safe_output(app_url('login.php')); ?>">
                                    <i class="fas fa-sign-in-alt me-1"></i> Masuk
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo safe_output(app_url('register.php')); ?>">
                                    <i class="fas fa-user-plus me-1"></i> Daftar
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Dark Mode Toggle Button -->
                        <li class="nav-item">
                            <button class="btn btn-outline-secondary" id="darkModeToggle" onclick="toggleDarkMode()" title="<?php echo $darkMode ? 'Mode Terang' : 'Mode Gelap'; ?>">
                                <i class="fas fa-<?php echo $darkMode ? 'sun' : 'moon'; ?>"></i>
                                <span class="d-lg-none ms-2"><?php echo $darkMode ? 'Mode Terang' : 'Mode Gelap'; ?></span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
    <?php endif; ?>
    
    <!-- Main Content -->
    <main class="py-4">