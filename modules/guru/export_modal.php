<?php
/**
 * Modal for Export PDF
 * File: modules/guru/export_modal.php
 */
?>
<!-- Modal Export PDF -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-pdf me-2"></i>Ekspor Laporan PDF
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="exportForm" action="export_pdf.php" method="GET" target="_blank">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Periode Laporan</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-calendar"></i>
                                </span>
                                <input type="date" class="form-control" name="start_date" 
                                       value="<?php echo date('Y-m-01'); ?>" required>
                                <span class="input-group-text">s/d</span>
                                <input type="date" class="form-control" name="end_date" 
                                       value="<?php echo date('Y-m-t'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Jenis Laporan</label>
                            <select class="form-select" name="report_type" required>
                                <option value="summary">Ringkasan (Statistik + Daftar Pesan)</option>
                                <option value="detailed">Detail Lengkap (Dengan Respons)</option>
                                <option value="performance">Analisis Performance</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="fas fa-info-circle me-2 text-info"></i>Informasi Laporan
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small class="d-block mb-1">
                                                <i class="fas fa-user me-2"></i>
                                                <strong>Guru:</strong> <?php echo htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Guru'); ?>
                                            </small>
                                            <small class="d-block mb-1">
                                                <i class="fas fa-tag me-2"></i>
                                                <strong>Jenis Pesan:</strong> <?php echo htmlspecialchars($assignedType ?? 'Tidak ditentukan'); ?>
                                            </small>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="d-block mb-1">
                                                <i class="fas fa-calendar-alt me-2"></i>
                                                <strong>Periode Default:</strong> Bulan Ini
                                            </small>
                                            <small class="d-block mb-1">
                                                <i class="fas fa-file me-2"></i>
                                                <strong>Format:</strong> PDF (Portable Document Format)
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Pratinjau Data</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="60">#</th>
                                            <th>Bulan</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">Selesai</th>
                                            <th class="text-end">Ditanggapi</th>
                                        </tr>
                                    </thead>
                                    <tbody id="previewData">
                                        <tr>
                                            <td colspan="5" class="text-center">
                                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                                Memuat data...
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Batal
                    </button>
                    <button type="submit" class="btn btn-primary" id="exportButton">
                        <i class="fas fa-download me-1"></i> Ekspor PDF
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Load preview data when modal opens
document.getElementById('exportModal').addEventListener('show.bs.modal', function () {
    loadPreviewData();
});

// Load preview data based on selected dates
document.querySelectorAll('#exportForm input[name="start_date"], #exportForm input[name="end_date"]').forEach(input => {
    input.addEventListener('change', function() {
        loadPreviewData();
    });
});

// Function to load preview data
function loadPreviewData() {
    const startDate = document.querySelector('#exportForm input[name="start_date"]').value;
    const endDate = document.querySelector('#exportForm input[name="end_date"]').value;
    
    // Show loading
    const previewTable = document.getElementById('previewData');
    previewTable.innerHTML = `
        <tr>
            <td colspan="5" class="text-center">
                <div class="spinner-border spinner-border-sm text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                Memuat data...
            </td>
        </tr>
    `;
    
    // Fetch preview data via AJAX
    fetch(`export_preview.php?start_date=${startDate}&end_date=${endDate}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                
                if (data.monthly_data && data.monthly_data.length > 0) {
                    data.monthly_data.forEach((item, index) => {
                        const month = new Date(item.month + '-01').toLocaleDateString('id-ID', {
                            month: 'short',
                            year: 'numeric'
                        });
                        
                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${month}</td>
                                <td class="text-end">${item.total}</td>
                                <td class="text-end">${item.completed}</td>
                                <td class="text-end">${item.responded}</td>
                            </tr>
                        `;
                    });
                } else {
                    html = `
                        <tr>
                            <td colspan="5" class="text-center text-muted">
                                <i class="fas fa-inbox me-2"></i>Tidak ada data untuk periode ini
                            </td>
                        </tr>
                    `;
                }
                
                previewTable.innerHTML = html;
            } else {
                previewTable.innerHTML = `
                    <tr>
                        <td colspan="5" class="text-center text-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>${data.message}
                        </td>
                    </tr>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            previewTable.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>Gagal memuat data
                    </td>
                </tr>
            `;
        });
}

// Form submission
document.getElementById('exportForm').addEventListener('submit', function(e) {
    const exportBtn = document.getElementById('exportButton');
    exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Membuat PDF...';
    exportBtn.disabled = true;
    
    // Form akan submit secara normal ke export_pdf.php
});
</script>

<style>
#exportModal .modal-dialog {
    max-width: 800px;
}

#exportModal .input-group-text {
    background-color: #f8f9fa;
    border-color: #dee2e6;
}

#previewData td {
    vertical-align: middle;
}

#exportModal .card {
    border: 1px dashed #dee2e6;
}
</style>