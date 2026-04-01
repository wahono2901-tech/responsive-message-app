/**
 * Common JavaScript functions for Guru Pages
 */

class GuruActions {
    constructor() {
        this.baseUrl = window.BASE_URL || '';
        this.templatesData = window.templatesData || [];
    }
    
    // Show toast notification
    showToast(type, message) {
        const toastContainer = this.getToastContainer();
        const toastId = 'toast-' + Date.now();
        
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${this.getToastIcon(type)} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            delay: 3000
        });
        
        toast.show();
        
        toastElement.addEventListener('hidden.bs.toast', function() {
            this.remove();
        });
    }
    
    getToastIcon(type) {
        const icons = {
            'success': 'check-circle',
            'danger': 'exclamation-circle',
            'warning': 'exclamation-triangle',
            'info': 'info-circle'
        };
        return icons[type] || 'info-circle';
    }
    
    getToastContainer() {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'position-fixed bottom-0 end-0 p-3';
            document.body.appendChild(container);
        }
        return container;
    }
    
    // Show loading overlay
    showLoading(message = 'Memproses...') {
        let loading = document.getElementById('loadingOverlay');
        if (!loading) {
            loading = document.createElement('div');
            loading.id = 'loadingOverlay';
            loading.className = 'loading-overlay';
            loading.innerHTML = `
                <div class="loading-content">
                    <div class="spinner-border text-primary mb-3"></div>
                    <div class="loading-message">${message}</div>
                </div>
            `;
            document.body.appendChild(loading);
        }
        
        loading.style.display = 'flex';
    }
    
    hideLoading() {
        const loading = document.getElementById('loadingOverlay');
        if (loading) {
            loading.style.display = 'none';
        }
    }
    
    // Apply template
    applyTemplate(templateId, targetElementId = 'responseText') {
        const template = this.templatesData.find(t => t.id == templateId);
        if (!template) return false;
        
        const textarea = document.getElementById(targetElementId);
        if (textarea) {
            textarea.value = template.content;
            
            // Trigger change event
            const event = new Event('input');
            textarea.dispatchEvent(event);
            
            return true;
        }
        return false;
    }
    
    // Submit response via AJAX
    async submitResponse(formData) {
        try {
            const response = await fetch(this.baseUrl + 'modules/guru/api/handler.php', {
                method: 'POST',
                body: formData
            });
            
            return await response.json();
        } catch (error) {
            return {
                success: false,
                message: 'Koneksi gagal: ' + error.message
            };
        }
    }
    
    // Quick actions
    async quickApprove(messageId) {
        if (!confirm('Setujui pesan ini?')) return;
        
        this.showLoading('Menyetujui pesan...');
        
        const formData = new FormData();
        formData.append('action', 'quick_approve');
        formData.append('message_id', messageId);
        
        const result = await this.submitResponse(formData);
        
        this.hideLoading();
        
        if (result.success) {
            this.showToast('success', result.message);
            this.updateMessageStatus(messageId, 'Disetujui');
        } else {
            this.showToast('danger', result.message);
        }
    }
    
    async quickReject(messageId) {
        if (!confirm('Tolak pesan ini?')) return;
        
        this.showLoading('Menolak pesan...');
        
        const formData = new FormData();
        formData.append('action', 'quick_reject');
        formData.append('message_id', messageId);
        
        const result = await this.submitResponse(formData);
        
        this.hideLoading();
        
        if (result.success) {
            this.showToast('success', result.message);
            this.updateMessageStatus(messageId, 'Ditolak');
        } else {
            this.showToast('danger', result.message);
        }
    }
    
    updateMessageStatus(messageId, status) {
        const messageRow = document.querySelector(`tr[data-message-id="${messageId}"]`);
        if (messageRow) {
            const statusBadge = messageRow.querySelector('.badge');
            if (statusBadge) {
                statusBadge.className = 'badge ' + this.getStatusClass(status);
                statusBadge.textContent = status;
            }
        }
    }
    
    getStatusClass(status) {
        const classes = {
            'Disetujui': 'bg-success',
            'Ditolak': 'bg-danger',
            'Diproses': 'bg-primary',
            'Pending': 'bg-warning',
            'Dibaca': 'bg-info',
            'Selesai': 'bg-secondary'
        };
        return classes[status] || 'bg-secondary';
    }
}

// Initialize global instance
if (typeof window.guruActions === 'undefined') {
    window.guruActions = new GuruActions();
}