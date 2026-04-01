/**
 * Form Validation & Real-time Contact Validator for Responsive Message App
 * File: assets/js/validation.js
 * 
 * Fitur:
 * 1. Validasi form umum (required, email, phone, password, dll)
 * 2. Real-time validasi nomor WhatsApp via API Fonnte
 * 3. Real-time validasi email via MX record
 * 4. Password strength meter
 * 5. File upload validation
 */

// ============================================================================
// CONTACT VALIDATOR - Validasi Real-time WhatsApp & Email
// ============================================================================

class ContactValidator {
    constructor() {
        this.whatsappCache = new Map();
        this.emailCache = new Map();
        this.validationInProgress = false;
        
        // Cache expiration time (30 menit)
        this.cacheExpiry = 30 * 60 * 1000;
    }

    /**
     * Validasi Nomor WhatsApp secara real-time
     */
    async validateWhatsApp(phoneNumber, elementId, options = {}) {
        const defaults = {
            showValidIcon: true,
            showInvalidIcon: true,
            checkViaAPI: true,
            minLength: 10,
            maxLength: 15
        };
        
        const config = {...defaults, ...options};
        const result = {
            isValid: false,
            formatted: '',
            message: '',
            exists: false,
            canReceive: false
        };

        // Jika nomor kosong, return early
        if (!phoneNumber || phoneNumber.trim() === '') {
            result.message = '';
            this.updateUI(elementId, result, config);
            return result;
        }

        // Bersihkan nomor
        let cleanPhone = phoneNumber.replace(/[^0-9]/g, '');
        
        // Cek panjang minimal
        if (cleanPhone.length < config.minLength) {
            result.message = `Nomor terlalu pendek (min ${config.minLength} digit)`;
            this.updateUI(elementId, result, config);
            return result;
        }

        // Format nomor
        if (cleanPhone.startsWith('0')) {
            result.formatted = '62' + cleanPhone.substring(1);
        } else if (cleanPhone.startsWith('62')) {
            result.formatted = cleanPhone;
        } else {
            result.formatted = '62' + cleanPhone;
        }

        // Validasi format (62 diikuti 8-13 digit)
        const formatRegex = /^62[0-9]{8,13}$/;
        if (!formatRegex.test(result.formatted)) {
            result.message = 'Format nomor tidak valid (harus 10-15 digit)';
            this.updateUI(elementId, result, config);
            return result;
        }

        result.isValid = true;
        result.message = '✓ Format nomor valid';

        // Cek via API jika diaktifkan
        if (config.checkViaAPI && cleanPhone.length >= config.minLength) {
            try {
                this.showLoading(elementId);
                
                // Cek cache dan expired
                if (this.whatsappCache.has(cleanPhone)) {
                    const cached = this.whatsappCache.get(cleanPhone);
                    const now = Date.now();
                    
                    if (now - cached.timestamp < this.cacheExpiry) {
                        result.exists = cached.exists;
                        result.canReceive = cached.canReceive;
                        result.message = cached.exists ? 
                            '✓ Nomor terdaftar di WhatsApp' : 
                            '⚠️ Nomor tidak terdeteksi di WhatsApp';
                    } else {
                        // Cache expired, hapus dan panggil API
                        this.whatsappCache.delete(cleanPhone);
                        await this.callWhatsAppAPI(cleanPhone, result);
                    }
                } else {
                    await this.callWhatsAppAPI(cleanPhone, result);
                }
                
            } catch (error) {
                console.error('WhatsApp validation error:', error);
                result.message = '⚠️ Gagal memvalidasi nomor (offline)';
            } finally {
                this.hideLoading(elementId);
            }
        }

        this.updateUI(elementId, result, config);
        return result;
    }

    /**
     * Panggil API WhatsApp
     */
    async callWhatsAppAPI(cleanPhone, result) {
        try {
            const response = await fetch('/api/check_whatsapp.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({phone: cleanPhone})
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            result.exists = data.exists || false;
            result.canReceive = data.canReceive || false;
            result.message = data.exists ? 
                '✓ Nomor terdaftar di WhatsApp' : 
                '⚠️ Nomor tidak terdeteksi di WhatsApp';
            
            // Simpan ke cache
            this.whatsappCache.set(cleanPhone, {
                exists: data.exists,
                canReceive: data.canReceive,
                timestamp: Date.now()
            });
            
        } catch (error) {
            console.error('API call failed:', error);
            // Fallback: anggap nomor valid
            result.exists = true;
            result.message = '⚠️ Tidak bisa memverifikasi (offline)';
        }
    }

    /**
     * Validasi Email secara real-time
     */
    async validateEmail(email, elementId, options = {}) {
        const defaults = {
            showValidIcon: true,
            showInvalidIcon: true,
            checkMX: true,
            checkDisposable: true
        };
        
        const config = {...defaults, ...options};
        const result = {
            isValid: false,
            message: '',
            mxExists: false,
            isDisposable: false,
            domain: ''
        };

        // Jika email kosong, return early
        if (!email || email.trim() === '') {
            result.message = '';
            this.updateUI(elementId, result, config);
            return result;
        }

        // Validasi format email
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            result.message = 'Format email tidak valid';
            this.updateUI(elementId, result, config);
            return result;
        }

        // Ambil domain
        const parts = email.split('@');
        result.domain = parts[1].toLowerCase();

        // Cek disposable email (daftar lengkap)
        const disposableDomains = [
            'tempmail.com', 'throwaway.com', 'mailinator.com', 'guerrillamail.com',
            'sharklasers.com', 'yopmail.com', 'temp-mail.org', '10minutemail.com',
            'trashmail.com', 'fakeinbox.com', 'mailnator.com', 'spamgourmet.com'
        ];
        
        result.isDisposable = disposableDomains.includes(result.domain);
        
        if (result.isDisposable) {
            result.message = '⚠️ Email sementara tidak diizinkan';
            this.updateUI(elementId, result, config);
            return result;
        }

        result.isValid = true;
        result.message = '✓ Format email valid';

        // Cek MX record jika diaktifkan
        if (config.checkMX) {
            try {
                this.showLoading(elementId);
                
                // Cek cache dan expired
                if (this.emailCache.has(result.domain)) {
                    const cached = this.emailCache.get(result.domain);
                    const now = Date.now();
                    
                    if (now - cached.timestamp < this.cacheExpiry) {
                        result.mxExists = cached.mxExists;
                    } else {
                        this.emailCache.delete(result.domain);
                        await this.callEmailAPI(result.domain, result);
                    }
                } else {
                    await this.callEmailAPI(result.domain, result);
                }

                if (!result.mxExists) {
                    result.message = '⚠️ Domain email tidak valid (tidak ada MX record)';
                } else {
                    result.message = '✓ Email valid dan dapat menerima pesan';
                }
                
            } catch (error) {
                console.error('Email validation error:', error);
                result.message = '⚠️ Gagal memvalidasi email (offline)';
            } finally {
                this.hideLoading(elementId);
            }
        }

        this.updateUI(elementId, result, config);
        return result;
    }

    /**
     * Panggil API Email
     */
    async callEmailAPI(domain, result) {
        try {
            const response = await fetch('/api/check_email.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({domain: domain})
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            result.mxExists = data.mxExists || false;
            
            // Simpan ke cache
            this.emailCache.set(domain, {
                mxExists: result.mxExists,
                timestamp: Date.now()
            });
            
        } catch (error) {
            console.error('Email API call failed:', error);
            // Fallback: anggap domain valid
            result.mxExists = true;
        }
    }

    /**
     * Update UI dengan hasil validasi
     */
    updateUI(elementId, result, config) {
        const container = document.getElementById(elementId + '-container');
        if (!container) return;

        // Hapus feedback lama
        const oldFeedback = container.querySelector('.validation-feedback');
        if (oldFeedback) oldFeedback.remove();

        // Jika tidak ada pesan (kosong), jangan tampilkan feedback
        if (!result.message) {
            const input = document.getElementById(elementId);
            if (input) {
                input.classList.remove('is-valid', 'is-invalid');
            }
            return;
        }

        // Buat feedback baru
        const feedback = document.createElement('div');
        feedback.className = 'validation-feedback mt-1';
        
        // Tentukan warna berdasarkan status
        if (result.message.includes('✓')) {
            feedback.classList.add('text-success');
        } else if (result.message.includes('⚠️')) {
            feedback.classList.add('text-warning');
        } else {
            feedback.classList.add('text-danger');
        }
        
        // Tentukan icon
        let icon = 'info-circle';
        if (result.message.includes('✓')) icon = 'check-circle';
        else if (result.message.includes('⚠️')) icon = 'exclamation-triangle';
        else if (result.message.includes('✗')) icon = 'times-circle';
        
        feedback.innerHTML = `
            <small>
                <i class="fas fa-${icon} me-1"></i>
                ${result.message}
                ${result.formatted ? `<br><span class="text-muted">Format: ${result.formatted}</span>` : ''}
            </small>
        `;

        container.appendChild(feedback);

        // Update input class
        const input = document.getElementById(elementId);
        if (input) {
            input.classList.remove('is-valid', 'is-invalid');
            if (result.message.includes('✓')) {
                input.classList.add('is-valid');
            } else if (!result.message.includes('✓') && result.message !== '') {
                input.classList.add('is-invalid');
            }
        }
    }

    /**
     * Tampilkan loading indicator
     */
    showLoading(elementId) {
        const container = document.getElementById(elementId + '-container');
        if (!container) return;

        const loading = document.createElement('div');
        loading.className = 'validation-loading mt-1 text-info';
        loading.id = elementId + '-loading';
        loading.innerHTML = '<small><i class="fas fa-spinner fa-spin me-1"></i>Memvalidasi...</small>';
        
        const oldLoading = document.getElementById(elementId + '-loading');
        if (oldLoading) oldLoading.remove();
        
        container.appendChild(loading);
    }

    /**
     * Sembunyikan loading indicator
     */
    hideLoading(elementId) {
        const loading = document.getElementById(elementId + '-loading');
        if (loading) loading.remove();
    }

    /**
     * Clear cache untuk testing
     */
    clearCache() {
        this.whatsappCache.clear();
        this.emailCache.clear();
        console.log('Contact validator cache cleared');
    }
}


// ============================================================================
// FORM VALIDATOR - Validasi Form Umum
// ============================================================================

class FormValidator {
    constructor(formId) {
        this.form = document.getElementById(formId);
        if (!this.form) return;
        
        this.init();
    }
    
    init() {
        this.setupRealTimeValidation();
        this.setupPasswordValidation();
        this.setupFileValidation();
        
        // Integrasi dengan ContactValidator
        this.setupContactValidation();
    }
    
    setupRealTimeValidation() {
        // Validate on input change
        this.form.querySelectorAll('input, select, textarea').forEach(field => {
            field.addEventListener('blur', () => this.validateField(field));
            field.addEventListener('input', () => this.clearFieldError(field));
        });
    }
    
    setupPasswordValidation() {
        const passwordField = this.form.querySelector('input[type="password"]');
        if (!passwordField) return;
        
        passwordField.addEventListener('input', (e) => {
            this.validatePasswordStrength(e.target.value);
        });
    }
    
    setupFileValidation() {
        const fileInput = this.form.querySelector('input[type="file"]');
        if (!fileInput) return;
        
        fileInput.addEventListener('change', (e) => {
            this.validateFiles(e.target.files);
        });
    }
    
    setupContactValidation() {
        // Validasi WhatsApp
        const phoneInput = this.form.querySelector('#phone_number');
        if (phoneInput && window.contactValidator) {
            let timeout = null;
            phoneInput.addEventListener('input', (e) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    window.contactValidator.validateWhatsApp(e.target.value, 'phone_number');
                }, 500);
            });
        }

        // Validasi Email
        const emailInput = this.form.querySelector('#email');
        if (emailInput && window.contactValidator) {
            let timeout = null;
            emailInput.addEventListener('input', (e) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    window.contactValidator.validateEmail(e.target.value, 'email');
                }, 500);
            });
        }

        // Tambahkan container untuk feedback jika belum ada
        ['phone_number', 'email'].forEach(id => {
            const input = this.form.querySelector('#' + id);
            if (input && !document.getElementById(id + '-container')) {
                const container = document.createElement('div');
                container.id = id + '-container';
                input.parentNode.insertBefore(container, input.nextSibling);
            }
        });
    }
    
    validateField(field) {
        let isValid = true;
        let errorMessage = '';
        
        // Skip validation untuk field tertentu
        if (field.id === 'phone_number' || field.id === 'email') {
            return true; // Biarkan ContactValidator yang handle
        }
        
        // Required validation
        if (field.hasAttribute('required') && !field.value.trim()) {
            isValid = false;
            errorMessage = 'Field ini wajib diisi';
        }
        
        // Email validation (jika bukan field yang dihandle ContactValidator)
        if (field.type === 'email' && field.value.trim() && field.id !== 'email') {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(field.value)) {
                isValid = false;
                errorMessage = 'Format email tidak valid';
            }
        }
        
        // Phone validation (jika bukan field yang dihandle ContactValidator)
        if (field.type === 'tel' && field.value.trim() && field.id !== 'phone_number') {
            const phoneRegex = /^[0-9+\-\s()]{10,15}$/;
            if (!phoneRegex.test(field.value)) {
                isValid = false;
                errorMessage = 'Format nomor telepon tidak valid';
            }
        }
        
        // NIS/NIP validation
        if (field.id === 'nis_nip' && field.value.trim()) {
            const userType = document.getElementById('user_type')?.value;
            let pattern = '';
            
            switch(userType) {
                case 'Siswa':
                    pattern = /^\d{8}$/;
                    errorMessage = 'NIS harus 8 digit angka';
                    break;
                case 'Guru':
                    pattern = /^\d{9}$/;
                    errorMessage = 'NIP harus 9 digit angka';
                    break;
                case 'Orang_Tua':
                    pattern = /^OT\d{7}$/;
                    errorMessage = 'Format: OT1234567';
                    break;
            }
            
            if (pattern && !pattern.test(field.value)) {
                isValid = false;
            }
        }
        
        // Minimum length validation
        const minLength = field.getAttribute('minlength');
        if (minLength && field.value.length < parseInt(minLength)) {
            isValid = false;
            errorMessage = `Minimal ${minLength} karakter`;
        }
        
        // Maximum length validation
        const maxLength = field.getAttribute('maxlength');
        if (maxLength && field.value.length > parseInt(maxLength)) {
            isValid = false;
            errorMessage = `Maksimal ${maxLength} karakter`;
        }
        
        // Pattern validation
        const pattern = field.getAttribute('pattern');
        if (pattern && field.value.trim()) {
            const regex = new RegExp(pattern);
            if (!regex.test(field.value)) {
                isValid = false;
                errorMessage = 'Format tidak sesuai';
            }
        }
        
        // Update UI
        this.updateFieldUI(field, isValid, errorMessage);
        
        return isValid;
    }
    
    validatePasswordStrength(password) {
        let strength = 0;
        const feedback = {
            length: password.length >= 8,
            lowercase: /[a-z]/.test(password),
            uppercase: /[A-Z]/.test(password),
            numbers: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password)
        };
        
        // Calculate strength (masing-masing 20%)
        Object.values(feedback).forEach(condition => {
            if (condition) strength += 20;
        });
        
        // Update UI
        const strengthBar = document.getElementById('password-strength-bar');
        const strengthText = document.getElementById('password-strength-text');
        
        if (strengthBar && strengthText) {
            strengthBar.style.width = strength + '%';
            
            let color = 'danger';
            let text = 'Sangat Lemah';
            
            if (strength >= 40 && strength < 60) {
                color = 'warning';
                text = 'Lemah';
            } else if (strength >= 60 && strength < 80) {
                color = 'info';
                text = 'Cukup';
            } else if (strength >= 80) {
                color = 'success';
                text = 'Kuat';
            }
            
            strengthBar.className = `progress-bar bg-${color}`;
            strengthText.textContent = text;
            strengthText.className = `form-text text-${color}`;
        }
        
        return strength;
    }
    
    validateFiles(files) {
        const maxFiles = 3;
        const maxSize = 5 * 1024 * 1024; // 5MB
        const allowedTypes = [
            'image/jpeg', 'image/png', 'image/gif',
            'application/pdf', 
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
        
        let isValid = true;
        let errorMessage = '';
        
        // Check number of files
        if (files.length > maxFiles) {
            isValid = false;
            errorMessage = `Maksimal ${maxFiles} file`;
        }
        
        // Check each file
        for (let file of files) {
            // Check file size
            if (file.size > maxSize) {
                isValid = false;
                errorMessage = `File ${file.name} terlalu besar (maks 5MB)`;
                break;
            }
            
            // Check file type
            if (!allowedTypes.includes(file.type)) {
                isValid = false;
                errorMessage = `Tipe file ${file.name} tidak diizinkan`;
                break;
            }
        }
        
        // Show error if any
        if (!isValid) {
            this.showFileError(errorMessage);
        }
        
        return isValid;
    }
    
    validateForm() {
        let isValid = true;
        const fields = this.form.querySelectorAll('input, select, textarea');
        
        // Clear all errors first
        this.clearAllErrors();
        
        // Validate each field
        fields.forEach(field => {
            if (!this.validateField(field)) {
                isValid = false;
            }
        });
        
        // Validate password confirmation
        const password = this.form.querySelector('#password');
        const confirmPassword = this.form.querySelector('#confirm_password');
        
        if (password && confirmPassword && 
            password.value && confirmPassword.value && 
            password.value !== confirmPassword.value) {
            
            isValid = false;
            this.updateFieldUI(confirmPassword, false, 'Password tidak cocok');
        }
        
        // Validate terms agreement
        const terms = this.form.querySelector('#terms');
        if (terms && !terms.checked) {
            isValid = false;
            this.updateFieldUI(terms, false, 'Anda harus menyetujui syarat dan ketentuan');
        }
        
        return isValid;
    }
    
    updateFieldUI(field, isValid, errorMessage = '') {
        const formGroup = field.closest('.mb-3') || field.closest('.form-group');
        
        if (!isValid) {
            field.classList.add('is-invalid');
            field.classList.remove('is-valid');
            
            // Show error message
            let errorElement = field.nextElementSibling;
            if (!errorElement || !errorElement.classList.contains('invalid-feedback')) {
                errorElement = document.createElement('div');
                errorElement.className = 'invalid-feedback';
                field.parentNode.insertBefore(errorElement, field.nextSibling);
            }
            errorElement.textContent = errorMessage;
            
            // Scroll to error (only first error)
            if (!formGroup.classList.contains('error-shown')) {
                formGroup.classList.add('error-shown');
                formGroup.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } else {
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');
            
            // Remove error message
            const errorElement = field.nextElementSibling;
            if (errorElement && errorElement.classList.contains('invalid-feedback')) {
                errorElement.remove();
            }
            
            formGroup?.classList.remove('error-shown');
        }
    }
    
    clearFieldError(field) {
        field.classList.remove('is-invalid');
        const errorElement = field.nextElementSibling;
        if (errorElement && errorElement.classList.contains('invalid-feedback')) {
            errorElement.remove();
        }
        field.closest('.mb-3')?.classList.remove('error-shown');
    }
    
    clearAllErrors() {
        this.form.querySelectorAll('.is-invalid').forEach(el => {
            el.classList.remove('is-invalid');
        });
        
        this.form.querySelectorAll('.invalid-feedback').forEach(el => {
            el.remove();
        });
        
        this.form.querySelectorAll('.error-shown').forEach(el => {
            el.classList.remove('error-shown');
        });
    }
    
    showFileError(message) {
        // Create or update error element
        let errorElement = document.getElementById('file-error');
        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.id = 'file-error';
            errorElement.className = 'alert alert-danger mt-2';
            const fileInput = this.form.querySelector('input[type="file"]');
            fileInput.parentNode.insertBefore(errorElement, fileInput.nextSibling);
        }
        
        errorElement.textContent = message;
        errorElement.style.display = 'block';
        
        // Auto hide after 5 seconds
        setTimeout(() => {
            errorElement.style.display = 'none';
        }, 5000);
    }
    
    showSuccess(message) {
        // Remove any existing success message
        const existingSuccess = this.form.querySelector('.alert-success');
        if (existingSuccess) {
            existingSuccess.remove();
        }
        
        // Create success message
        const successElement = document.createElement('div');
        successElement.className = 'alert alert-success mt-3';
        successElement.innerHTML = `<i class="fas fa-check-circle me-2"></i>${message}`;
        
        // Insert after form
        this.form.parentNode.insertBefore(successElement, this.form.nextSibling);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            successElement.remove();
        }, 5000);
    }
}


// ============================================================================
// INITIALIZATION
// ============================================================================

// Buat instance global ContactValidator
window.contactValidator = new ContactValidator();

// Auto-initialize validators saat DOM loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('Validation.js loaded - Contact & Form Validators ready');
    
    // Initialize validators for all forms with data-validate attribute
    document.querySelectorAll('form[data-validate]').forEach(form => {
        new FormValidator(form.id);
    });
    
    // Setup form submission validation untuk semua form
    document.querySelectorAll('form').forEach(form => {
        // Skip jika sudah punya data-validate
        if (form.hasAttribute('data-validate')) return;
        
        form.addEventListener('submit', function(e) {
            const validator = new FormValidator(this.id);
            if (!validator.validateForm()) {
                e.preventDefault();
                
                // Tampilkan toast error
                if (window.RMApp && window.RMApp.showToast) {
                    window.RMApp.showToast('error', 'Tolong perbaiki kesalahan di form');
                } else {
                    alert('Tolong perbaiki kesalahan di form sebelum mengirim');
                }
            }
        });
    });
    
    // Setup container untuk contact validation di form login/register
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        ['phone_number', 'email'].forEach(id => {
            const input = registerForm.querySelector('#' + id);
            if (input && !document.getElementById(id + '-container')) {
                const container = document.createElement('div');
                container.id = id + '-container';
                input.parentNode.insertBefore(container, input.nextSibling);
            }
        });
    }
});

// Export untuk penggunaan di module
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ContactValidator, FormValidator };
}