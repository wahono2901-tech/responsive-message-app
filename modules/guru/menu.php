<?php
/**
 * Guru Menu Panel
 * File: modules/guru/menu.php
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

Auth::checkAuth();

$allowedTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'];
if (!in_array($_SESSION['user_type'], $allowedTypes)) {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}
?>

<div class="container-fluid py-4">
    <div class="row">
        <!-- Dashboard Card -->
        <div class="col-md-4 mb-4">
            <a href="dashboard_guru.php" class="card text-decoration-none border-0 shadow hover-lift">
                <div class="card-body text-center p-4">
                    <div class="icon-circle-lg bg-primary mb-3 mx-auto">
                        <i class="fas fa-chart-line fa-2x text-white"></i>
                    </div>
                    <h5 class="card-title mb-2">Dashboard Analisis</h5>
                    <p class="card-text text-muted small">
                        Lihat statistik, grafik, dan kinerja penanganan pesan
                    </p>
                </div>
            </a>
        </div>
        
        <!-- Follow-Up Card -->
        <div class="col-md-4 mb-4">
            <a href="followup.php" class="card text-decoration-none border-0 shadow hover-lift">
                <div class="card-body text-center p-4">
                    <div class="icon-circle-lg bg-success mb-3 mx-auto">
                        <i class="fas fa-tasks fa-2x text-white"></i>
                    </div>
                    <h5 class="card-title mb-2">Follow-Up Pesan</h5>
                    <p class="card-text text-muted small">
                        Kelola dan tanggapi pesan yang masuk
                    </p>
                </div>
            </a>
        </div>
        
        <!-- Response History Card -->
        <div class="col-md-4 mb-4">
            <a href="response.php" class="card text-decoration-none border-0 shadow hover-lift">
                <div class="card-body text-center p-4">
                    <div class="icon-circle-lg bg-info mb-3 mx-auto">
                        <i class="fas fa-history fa-2x text-white"></i>
                    </div>
                    <h5 class="card-title mb-2">Riwayat Respons</h5>
                    <p class="card-text text-muted small">
                        Lihat semua respons yang telah diberikan
                    </p>
                </div>
            </a>
        </div>
    </div>
</div>

<style>
.hover-lift {
    transition: transform 0.2s, box-shadow 0.2s;
}
.hover-lift:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
}
.icon-circle-lg {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>