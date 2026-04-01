/**
 * Main JavaScript File - Simple Version
 * File: assets/js/main.js
 * Compatible with both root and responsive-message-app folder
 */

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing components...');
    initializeComponents();
});

/**
 * Initialize all components
 */
function initializeComponents() {
    console.log('Initializing components...');
    
    // Initialize active navigation
    initializeActiveNav();
    
    // Initialize password toggle
    initializePasswordToggle();
    
    // Initialize auto-dismiss alerts
    initializeAutoDismissAlerts();
    
    // Initialize form validations
    initializeFormValidations();
    
    // Initialize tooltips and popovers if Bootstrap is available
    if (typeof bootstrap !== 'undefined') {
        initializeTooltips();
        initializePopovers();
        initializeToast();
    }
    
    console.log('Components initialized.');
}

/**
 * Initialize active navigation highlighting - SIMPLE VERSION
 */
function initializeActiveNav() {
    try {
        console.log('Initializing active navigation...');
        const currentPath = window.location.pathname;
        console.log('Current path:', currentPath);
        
        const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
        console.log('Found nav links:', navLinks.length);
        
        if (!navLinks.length) {
            console.log('No nav links found');
            return;
        }
        
        navLinks.forEach(function(link, index) {
            const linkHref = link.getAttribute('href');
            console.log(`Link ${index}: href="${linkHref}"`);
            
            if (!linkHref) return;
            
            // Simple comparison - remove trailing slashes
            const current = currentPath.replace(/\/$/, '');
            const href = linkHref.replace(/\/$/, '');
            
            let isActive = false;
            
            // Check for exact match or contains
            if (href === '' && current === '') {
                isActive = true;
            } else if (href !== '' && current.includes(href)) {
                isActive = true;
            }
            
            console.log(`Link "${linkHref}" is active: ${isActive}`);
            
            if (isActive) {
                link.classList.add('active');
                link.setAttribute('aria-current', 'page');
            } else {
                link.classList.remove('active');
                link.removeAttribute('aria-current');
            }
        });
        
        console.log('Active navigation initialized.');
    } catch (error) {
        console.warn('Error initializing active navigation:', error);
    }
}

/**
 * Initialize password visibility toggle
 */
function initializePasswordToggle() {
    console.log('Initializing password toggle...');
    
    // Multiple ways to find password toggle buttons
    const selectors = [
        '#togglePassword',
        '.btn[data-toggle="password"]',
        '.password-toggle',
        'button[data-target="#password"]'
    ];
    
    selectors.forEach(function(selector) {
        const buttons = document.querySelectorAll(selector);
        buttons.forEach(function(button) {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target') || '#password';
                const passwordInput = document.querySelector(targetId);
                
                if (passwordInput) {
                    const type = passwordInput.type === 'password' ? 'text' : 'password';
                    passwordInput.type = type;
                    
                    const icon = this.querySelector('i');
                    if (icon) {
                        if (type === 'text') {
                            icon.classList.remove('fa-eye');
                            icon.classList.add('fa-eye-slash');
                        } else {
                            icon.classList.remove('fa-eye-slash');
                            icon.classList.add('fa-eye');
                        }
                    }
                }
            });
        });
    });
    
    console.log('Password toggle initialized.');
}

/**
 * Initialize auto-dismiss alerts
 */
function initializeAutoDismissAlerts() {
    console.log('Initializing auto-dismiss alerts...');
    
    // Auto-dismiss after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert.auto-dismiss');
        alerts.forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 500);
        });
    }, 5000);
    
    // Close button click
    const closeButtons = document.querySelectorAll('.alert-dismissible .btn-close');
    closeButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const alert = this.closest('.alert');
            if (alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    if (alert.parentNode) {
                        alert.parentNode.removeChild(alert);
                    }
                }, 500);
            }
        });
    });
    
    console.log('Auto-dismiss alerts initialized.');
}

/**
 * Initialize form validations
 */
function initializeFormValidations() {
    console.log('Initializing form validations...');
    
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
    
    console.log('Form validations initialized.');
}

/**
 * Initialize Bootstrap tooltips
 */
function initializeTooltips() {
    try {
        console.log('Initializing tooltips...');
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        console.log('Tooltips initialized.');
    } catch (error) {
        console.warn('Error initializing tooltips:', error);
    }
}

/**
 * Initialize Bootstrap popovers
 */
function initializePopovers() {
    try {
        console.log('Initializing popovers...');
        const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        popoverTriggerList.forEach(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
        console.log('Popovers initialized.');
    } catch (error) {
        console.warn('Error initializing popovers:', error);
    }
}

/**
 * Initialize toast notifications
 */
function initializeToast() {
    try {
        console.log('Initializing toast...');
        const toastElList = [].slice.call(document.querySelectorAll('.toast'));
        toastElList.forEach(function (toastEl) {
            return new bootstrap.Toast(toastEl, {
                autohide: true,
                delay: 5000
            });
        });
        
        // Auto-show toasts
        const autoShowToasts = document.querySelectorAll('.toast[data-show="true"]');
        autoShowToasts.forEach(function(toastEl) {
            const toast = new bootstrap.Toast(toastEl);
            toast.show();
        });
        console.log('Toast initialized.');
    } catch (error) {
        console.warn('Error initializing toast:', error);
    }
}

/**
 * Simple toast notification
 */
function showToast(message, type = 'info', duration = 3000) {
    console.log('Showing toast:', message);
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.innerHTML = `
        <div class="toast-content">
            <span>${message}</span>
            <button class="toast-close">&times;</button>
        </div>
    `;
    
    // Style
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#17a2b8'};
        color: white;
        padding: 12px 16px;
        border-radius: 4px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        z-index: 9999;
        min-width: 250px;
        max-width: 350px;
        animation: slideIn 0.3s ease;
    `;
    
    // Add to page
    document.body.appendChild(toast);
    
    // Close button
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', function() {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    });
    
    // Auto remove
    setTimeout(function() {
        if (toast.parentNode) {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }
    }, duration);
    
    // Add CSS for animations
    if (!document.getElementById('toast-styles')) {
        const style = document.createElement('style');
        style.id = 'toast-styles';
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            .toast-notification .toast-content {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .toast-notification .toast-close {
                background: none;
                border: none;
                color: white;
                font-size: 20px;
                cursor: pointer;
                padding: 0;
                margin-left: 10px;
            }
        `;
        document.head.appendChild(style);
    }
}

// Make functions available globally
window.showToast = showToast;

console.log('main.js loaded successfully');