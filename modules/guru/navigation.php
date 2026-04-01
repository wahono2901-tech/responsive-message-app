<?php
/**
 * Guru Navigation Component
 * File: modules/guru/navigation.php
 */
?>

<div class="card border-0 shadow mb-4">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3 col-6">
                <a href="dashboard_guru.php" class="btn btn-outline-primary w-100 text-start">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-chart-line fa-2x me-3"></i>
                        <div>
                            <div class="fw-bold">Dashboard</div>
                            <small class="text-muted">Analisis & Statistik</small>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3 col-6">
                <a href="followup.php" class="btn btn-outline-success w-100 text-start">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-tasks fa-2x me-3"></i>
                        <div>
                            <div class="fw-bold">Follow-Up</div>
                            <small class="text-muted">Kelola Pesan</small>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3 col-6">
                <a href="response.php" class="btn btn-outline-info w-100 text-start">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-reply fa-2x me-3"></i>
                        <div>
                            <div class="fw-bold">Respons</div>
                            <small class="text-muted">Detail & Riwayat</small>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3 col-6">
                <a href="<?php echo BASE_URL; ?>" class="btn btn-outline-secondary w-100 text-start">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-home fa-2x me-3"></i>
                        <div>
                            <div class="fw-bold">Beranda</div>
                            <small class="text-muted">Utama</small>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>