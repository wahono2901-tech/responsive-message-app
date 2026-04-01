/* File: assets/js/realtime.js */
/* Realtime Updates for Responsive Message App */

class RealtimeManager {
    constructor() {
        this.socket = null;
        this.connected = false;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 3000;
        this.messageHandlers = new Map();
        
        this.init();
    }
    
    init() {
        this.connect();
        this.setupEventListeners();
        this.startHeartbeat();
    }
    
    connect() {
        try {
            // For production, use WebSocket server
            // For development, use polling
            this.startPolling();
        } catch (error) {
            console.error('Realtime connection error:', error);
            this.scheduleReconnect();
        }
    }
    
    startPolling() {
        // Use AJAX polling for realtime updates
        this.pollingInterval = setInterval(() => {
            this.checkUpdates();
        }, 10000); // Check every 10 seconds
        
        this.connected = true;
        console.log('Realtime polling started');
    }
    
    startWebSocket() {
        // WebSocket implementation for production
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const wsUrl = `${protocol}//${window.location.host}/ws`;
        
        this.socket = new WebSocket(wsUrl);
        
        this.socket.onopen = () => {
            this.connected = true;
            this.reconnectAttempts = 0;
            console.log('WebSocket connected');
            this.emit('connected');
        };
        
        this.socket.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                this.handleMessage(data);
            } catch (error) {
                console.error('Error parsing message:', error);
            }
        };
        
        this.socket.onclose = () => {
            this.connected = false;
            console.log('WebSocket disconnected');
            this.scheduleReconnect();
        };
        
        this.socket.onerror = (error) => {
            console.error('WebSocket error:', error);
        };
    }
    
    async checkUpdates() {
        if (!USER_ID) return;
        
        try {
            const response = await fetch(`${BASE_URL}api/messages.php?action=realtime_updates&user_id=${USER_ID}`);
            const data = await response.json();
            
            if (data.success) {
                this.handleUpdates(data.updates);
            }
        } catch (error) {
            console.error('Error checking updates:', error);
        }
    }
    
    handleUpdates(updates) {
        if (!updates || updates.length === 0) return;
        
        updates.forEach(update => {
            switch(update.type) {
                case 'new_message':
                    this.handleNewMessage(update.data);
                    break;
                case 'message_updated':
                    this.handleMessageUpdated(update.data);
                    break;
                case 'new_response':
                    this.handleNewResponse(update.data);
                    break;
                case 'notification':
                    this.handleNotification(update.data);
                    break;
            }
        });
    }
    
    handleNewMessage(message) {
        // Update message list if on messages page
        if (window.location.pathname.includes('messages')) {
            this.addMessageToList(message);
        }
        
        // Show notification
        RMApp.showToast('info', 
            `Pesan baru dari ${message.pengirim_nama}`, 
            'Pesan Baru');
        
        // Update notification count
        this.updateNotificationCount(1);
        
        // Play notification sound
        this.playNotificationSound();
    }
    
    handleMessageUpdated(message) {
        // Update message status in list
        const messageElement = document.querySelector(`[data-message-id="${message.id}"]`);
        if (messageElement) {
            this.updateMessageElement(messageElement, message);
        }
        
        // Show status update notification
        if (message.pengirim_id == USER_ID) {
            RMApp.showToast('success', 
                `Pesan Anda telah ${message.status}`, 
                'Status Diperbarui');
        }
    }
    
    handleNewResponse(response) {
        // Add response to message thread
        const responseList = document.querySelector(`[data-message-id="${response.message_id}"] .response-list`);
        if (responseList) {
            this.addResponseToList(responseList, response);
        }
        
        // Show notification
        RMApp.showToast('info', 
            `Respon baru dari ${response.responder_nama}`, 
            'Respon Baru');
    }
    
    handleNotification(notification) {
        // Show notification
        RMApp.showToast(notification.type, notification.message, notification.title);
        
        // Update notification count
        this.updateNotificationCount(1);
        
        // Play sound for urgent notifications
        if (notification.priority === 'urgent') {
            this.playNotificationSound();
        }
    }
    
    addMessageToList(message) {
        const messageList = document.getElementById('message-list');
        if (!messageList) return;
        
        const messageHtml = this.createMessageHtml(message);
        messageList.insertAdjacentHTML('afterbegin', messageHtml);
        
        // Update counters
        this.updateMessageCounters();
    }
    
    updateMessageElement(element, message) {
        // Update status badge
        const statusBadge = element.querySelector('.status-badge');
        if (statusBadge) {
            statusBadge.innerHTML = this.getStatusBadge(message.status);
        }
        
        // Update response timer
        const timerElement = element.querySelector('.response-timer');
        if (timerElement) {
            timerElement.innerHTML = this.getTimerHtml(message);
        }
        
        // Update progress bar
        const progressElement = element.querySelector('.response-progress');
        if (progressElement) {
            progressElement.style.width = `${message.progress}%`;
            progressElement.className = `response-progress progress-bar bg-${message.timer_color}`;
        }
    }
    
    addResponseToList(container, response) {
        const responseHtml = this.createResponseHtml(response);
        container.insertAdjacentHTML('beforeend', responseHtml);
    }
    
    createMessageHtml(message) {
        return `
            <div class="card mb-2" data-message-id="${message.id}">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">${message.jenis_pesan}</h6>
                            <p class="mb-1 small">${message.isi_pesan.substring(0, 100)}...</p>
                            <small class="text-muted">
                                <i class="fas fa-user me-1"></i>${message.pengirim_nama}
                                <i class="fas fa-clock ms-2 me-1"></i>${message.tanggal_pesan}
                            </small>
                        </div>
                        <div class="text-end">
                            <span class="status-badge">${this.getStatusBadge(message.status)}</span>
                            <div class="response-timer mt-1">${this.getTimerHtml(message)}</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    createResponseHtml(response) {
        return `
            <div class="response-item mb-2" data-response-id="${response.id}">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-user-circle fa-2x text-muted"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="mt-0">${response.responder_nama}</h6>
                        <p class="mb-1">${response.catatan_respon}</p>
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i>${response.created_at}
                            <span class="badge bg-${response.status === 'Disetujui' ? 'success' : 'danger'} ms-2">
                                ${response.status}
                            </span>
                        </small>
                    </div>
                </div>
            </div>
        `;
    }
    
    getStatusBadge(status) {
        const badges = {
            'Pending': '<span class="badge bg-warning">Menunggu</span>',
            'Dibaca': '<span class="badge bg-info">Dibaca</span>',
            'Diproses': '<span class="badge bg-primary">Diproses</span>',
            'Disetujui': '<span class="badge bg-success">Disetujui</span>',
            'Ditolak': '<span class="badge bg-danger">Ditolak</span>',
            'Selesai': '<span class="badge bg-secondary">Selesai</span>',
            'Expired': '<span class="badge bg-dark">Kadaluarsa</span>'
        };
        
        return badges[status] || '<span class="badge bg-light text-dark">-</span>';
    }
    
    getTimerHtml(message) {
        if (message.status === 'Selesai' || message.status === 'Expired') {
            return '<small class="text-muted">Selesai</small>';
        }
        
        const remaining = this.calculateRemainingTime(message.tanggal_pesan, message.deadline_hours);
        
        let color = 'success';
        if (remaining.percent > 66) color = 'warning';
        if (remaining.percent > 90) color = 'danger';
        
        return `
            <div class="progress" style="height: 5px;">
                <div class="progress-bar bg-${color}" style="width: ${remaining.percent}%"></div>
            </div>
            <small class="text-${color}">
                <i class="fas fa-clock me-1"></i>${remaining.text}
            </small>
        `;
    }
    
    calculateRemainingTime(startDate, deadlineHours) {
        const start = new Date(startDate);
        const now = new Date();
        const deadline = new Date(start.getTime() + (deadlineHours * 60 * 60 * 1000));
        
        const total = deadline - start;
        const elapsed = now - start;
        const remaining = deadline - now;
        
        const percent = Math.min(100, (elapsed / total) * 100);
        
        // Format remaining time
        const hours = Math.floor(remaining / (1000 * 60 * 60));
        const minutes = Math.floor((remaining % (1000 * 60 * 60)) / (1000 * 60));
        
        let text = '';
        if (remaining <= 0) {
            text = 'Waktu habis';
        } else if (hours > 0) {
            text = `${hours}j ${minutes}m`;
        } else {
            text = `${minutes}m`;
        }
        
        return { percent, text };
    }
    
    updateMessageCounters() {
        const counters = {
            pending: document.getElementById('pending-count'),
            processed: document.getElementById('processed-count'),
            approved: document.getElementById('approved-count'),
            total: document.getElementById('total-count')
        };
        
        Object.keys(counters).forEach(key => {
            if (counters[key]) {
                const current = parseInt(counters[key].textContent) || 0;
                counters[key].textContent = current + 1;
                
                // Animate counter update
                counters[key].classList.add('counter-update');
                setTimeout(() => {
                    counters[key].classList.remove('counter-update');
                }, 500);
            }
        });
    }
    
    updateNotificationCount(increment = 1) {
        const badge = document.getElementById('notification-count');
        if (!badge) return;
        
        const current = parseInt(badge.textContent) || 0;
        badge.textContent = current + increment;
        badge.style.display = 'block';
        
        // Animate notification bell
        const bell = document.getElementById('notification-bell');
        if (bell) {
            bell.classList.add('animate__animated', 'animate__headShake');
            setTimeout(() => {
                bell.classList.remove('animate__animated', 'animate__headShake');
            }, 1000);
        }
    }
    
    playNotificationSound() {
        // Create audio element for notification sound
        const audio = new Audio(`${BASE_URL}assets/sounds/notification.mp3`);
        audio.volume = 0.3;
        
        // Check if sound is allowed
        if (localStorage.getItem('notification_sound') !== 'disabled') {
            audio.play().catch(e => console.log('Audio play failed:', e));
        }
    }
    
    startHeartbeat() {
        // Send heartbeat every 30 seconds to keep connection alive
        setInterval(() => {
            if (this.connected) {
                this.send({ type: 'heartbeat' });
            }
        }, 30000);
    }
    
    send(data) {
        if (this.socket && this.socket.readyState === WebSocket.OPEN) {
            this.socket.send(JSON.stringify(data));
        }
    }
    
    on(event, handler) {
        if (!this.messageHandlers.has(event)) {
            this.messageHandlers.set(event, []);
        }
        this.messageHandlers.get(event).push(handler);
    }
    
    emit(event, data) {
        const handlers = this.messageHandlers.get(event);
        if (handlers) {
            handlers.forEach(handler => handler(data));
        }
    }
    
    handleMessage(data) {
        if (data.type && this.messageHandlers.has(data.type)) {
            this.emit(data.type, data);
        }
    }
    
    scheduleReconnect() {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.log('Max reconnection attempts reached');
            return;
        }
        
        this.reconnectAttempts++;
        const delay = this.reconnectDelay * Math.pow(1.5, this.reconnectAttempts - 1);
        
        console.log(`Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts})`);
        
        setTimeout(() => {
            this.connect();
        }, delay);
    }
    
    disconnect() {
        if (this.socket) {
            this.socket.close();
        }
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
        this.connected = false;
    }
    
    setupEventListeners() {
        // Handle page visibility change
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && !this.connected) {
                this.connect();
            }
        });
        
        // Handle beforeunload
        window.addEventListener('beforeunload', () => {
            this.disconnect();
        });
    }
}

// Initialize realtime manager
let realtimeManager = null;

document.addEventListener('DOMContentLoaded', function() {
    if (typeof USER_ID !== 'undefined' && USER_ID > 0) {
        realtimeManager = new RealtimeManager();
    }
});

// Export for use in other scripts
window.RealtimeManager = RealtimeManager;
window.realtimeManager = realtimeManager;