<?php
// File: includes/footer.php
?>
    </main>
    
    <!-- Footer -->
    <footer class="footer py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <h5><i class="fas fa-comments me-2"></i>Aplikasi Pesan Responsif</h5>
                    <p class="small">
                        Platform komunikasi terpadu untuk seluruh warga SMKN 12 Jakarta.
                        Kirim pesan, dapatkan respon cepat, dan pantau progress dengan mudah.
                    </p>
                </div>
                
                <div class="col-md-4 mb-3">
                    <h5>Kontak</h5>
                    <ul class="list-unstyled small">
                        <li><i class="fas fa-map-marker-alt me-2"></i> Jl. Kebon Bawang XV B No. 15 RT 19 RW 7, Tanjung Priok, Jakarta 14320</li>
                        <li><i class="fas fa-phone me-2"></i> (021) 43932785, 43913815</li>
                        <li><i class="fas fa-envelope me-2"></i> info@smkn12jakarta.sch.id</li>
                    </ul>
                </div>
                
                <div class="col-md-4 mb-3">
                    <h5>Jam Operasional</h5>
                    <ul class="list-unstyled small">
                        <li><i class="fas fa-clock me-2"></i> Senin - Jumat: 06:30 - 15:00</li>
                        <li><i class="fas fa-clock me-2"></i> Sabtu: Tutup</li>
                        <li><i class="fas fa-clock me-2"></i> Minggu: Tutup</li>
                    </ul>
                </div>
            </div>
            
            <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">
            
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0 small">
                        &copy; <?php echo date('Y'); ?> SMKN 12 Jakarta. All rights reserved.
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0 small">
                        <a href="<?php echo safe_output(app_url('privacy.php')); ?>" class="text-white text-decoration-none me-3">Kebijakan Privasi</a>
                        <a href="<?php echo safe_output(app_url('terms.php')); ?>" class="text-white text-decoration-none">Syarat & Ketentuan</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Toast Container -->
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
        <div id="liveToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                <strong class="me-auto" id="toastTitle">Notification</strong>
                <small id="toastTime">Just now</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body" id="toastMessage">
                Hello, world! This is a toast message.
            </div>
        </div>
    </div>
    
    <script>
    // Toast notification function
    function showToast(title, message, type = 'info') {
        const toastEl = document.getElementById('liveToast');
        const toastTitle = document.getElementById('toastTitle');
        const toastMessage = document.getElementById('toastMessage');
        const toastTime = document.getElementById('toastTime');
        
        if (!toastEl) return;
        
        // Set content
        toastTitle.textContent = title;
        toastMessage.textContent = message;
        toastTime.textContent = new Date().toLocaleTimeString();
        
        // Set color based on type
        const toastHeader = toastEl.querySelector('.toast-header');
        toastHeader.className = 'toast-header bg-' + type + ' text-white';
        
        // Show toast
        const toast = new bootstrap.Toast(toastEl);
        toast.show();
    }
    
    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            document.querySelectorAll('.alert.auto-dismiss').forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Close alert on click for dismissible alerts
        document.querySelectorAll('.alert-dismissible .btn-close').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const alert = this.closest('.alert');
                if (alert) {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }
            });
        });
    });
    </script>
</body>
</html>