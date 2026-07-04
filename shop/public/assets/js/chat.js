// /public/assets/js/chat.js

class ChatApp {
    constructor(userId, initialRoomId = 0) {
        this.userId = userId;
        this.currentRoomId = initialRoomId;
        this.pollingInterval = null;
        this.lastMessageCount = 0;
    }
    
    async init() {
        await this.loadRooms();
        this.setupEventListeners();
    }
    
    async loadRooms() {
        try {
            const response = await fetch('../src/api/send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_rooms' })
            });
            
            const result = await response.json();
            
            if (result.success && result.rooms) {
                this.renderRooms(result.rooms);
                
                if (this.currentRoomId > 0) {
                    this.loadMessages(this.currentRoomId);
                } else if (result.rooms.length > 0) {
                    this.loadMessages(result.rooms[0].id);
                }
            }
        } catch (error) {
            console.error('Failed to load rooms:', error);
        }
    }
    
    renderRooms(rooms) {
        const container = document.getElementById('chat-rooms-list');
        if (!container) return;
        
        if (rooms.length === 0) {
            container.innerHTML = `
                <div class="empty-chat" style="padding: 2rem;">
                    <p>No conversations yet.<br>Start chatting with sellers!</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = rooms.map(room => `
            <div class="chat-room-item ${room.id === this.currentRoomId ? 'active' : ''}" 
                 data-room-id="${room.id}">
                <div class="chat-room-avatar">
                    ${room.other_party_name ? room.other_party_name.charAt(0).toUpperCase() : 'S'}
                </div>
                <div class="chat-room-info">
                    <div class="chat-room-name">${this.escapeHtml(room.other_party_name || 'Unknown')}</div>
                    <div class="chat-room-last-message">${this.escapeHtml(room.last_message || 'No messages yet')}</div>
                    <div class="chat-room-time">${this.formatTime(room.last_message_time)}</div>
                </div>
                ${room.unread_count > 0 ? `<span class="chat-unread-badge">${room.unread_count}</span>` : ''}
            </div>
        `).join('');
        
        // Attach click handlers
        document.querySelectorAll('.chat-room-item').forEach(item => {
            item.addEventListener('click', () => {
                const roomId = parseInt(item.dataset.roomId);
                this.loadMessages(roomId);
            });
        });
    }
    
    async loadMessages(roomId) {
        if (!roomId) return;
        
        this.currentRoomId = roomId;
        
        // Update active state
        document.querySelectorAll('.chat-room-item').forEach(item => {
            item.classList.toggle('active', parseInt(item.dataset.roomId) === roomId);
        });
        
        try {
            const response = await fetch('../src/api/send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_messages',
                    room_id: roomId,
                    limit: 100
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.renderMessages(result.messages);
                this.startPolling(roomId);
                this.markAsRead(roomId);
            }
        } catch (error) {
            console.error('Failed to load messages:', error);
        }
    }
    
    renderMessages(messages) {
        const mainContainer = document.getElementById('chat-main');
        if (!mainContainer) return;
        
        const activeRoom = document.querySelector('.chat-room-item.active');
        const roomName = activeRoom ? activeRoom.querySelector('.chat-room-name')?.textContent || 'Chat' : 'Chat';
        
        this.lastMessageCount = messages.length;
        
        mainContainer.innerHTML = `
            <div class="chat-header">
                <div class="chat-header-info">
                    <h3><i class="fas fa-user-circle"></i> ${this.escapeHtml(roomName)}</h3>
                    <p>Online</p>
                </div>
                <div class="chat-header-actions">
                    <button class="chat-action-btn" onclick="chatApp.refreshMessages()" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button class="chat-action-btn" onclick="chatApp.clearChat()" title="Clear chat">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
            <div class="chat-messages" id="chat-messages">
                ${messages.length === 0 ? `
                    <div class="empty-chat">
                        <div class="empty-chat-content">
                            <div class="empty-chat-icon">💬</div>
                            <h4>No messages yet</h4>
                            <p>Send a message to start the conversation!</p>
                        </div>
                    </div>
                ` : messages.map(msg => `
                    <div class="message ${msg.sender_type === 'customer' && msg.sender_id == this.userId ? 'sent' : 'received'}">
                        <div class="message-bubble">
                            ${msg.sender_type !== 'customer' || msg.sender_id != this.userId ? 
                                `<div class="message-sender">${this.escapeHtml(msg.sender_name || 'Seller')}</div>` : ''}
                            <div class="message-text">${this.escapeHtml(msg.message_text)}</div>
                            <div class="message-time">
                                ${this.formatTime(msg.created_at)}
                                ${msg.sender_type === 'customer' && msg.sender_id == this.userId ? 
                                    `<span class="message-status ${msg.is_read ? 'read' : 'sent'}">
                                        ${msg.is_read ? '✓✓ Read' : '✓ Sent'}
                                    </span>` : ''}
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
            <div class="chat-input-area">
                <textarea id="message-input" placeholder="Type your message..." rows="1" 
                          onkeydown="if(event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); chatApp.sendMessage(); }"></textarea>
                <div class="chat-input-actions">
                    <button class="chat-attach-btn" onclick="chatApp.attachFile()" title="Attach file">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <button class="chat-send-btn" onclick="chatApp.sendMessage()">
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                </div>
            </div>
        `;
        
        // Auto-resize textarea
        const textarea = document.getElementById('message-input');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            });
            textarea.focus();
        }
        
        // Scroll to bottom
        const messagesContainer = document.getElementById('chat-messages');
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }
    
    async sendMessage() {
        const input = document.getElementById('message-input');
        const message = input?.value.trim();
        
        if (!message || !this.currentRoomId) return;
        
        input.disabled = true;
        
        try {
            const response = await fetch('../src/api/send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'send',
                    room_id: this.currentRoomId,
                    message: message
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                input.value = '';
                input.style.height = 'auto';
                await this.loadMessages(this.currentRoomId);
            } else {
                alert(result.message || 'Failed to send message');
            }
        } catch (error) {
            console.error('Send message error:', error);
            alert('Failed to send message. Please try again.');
        } finally {
            input.disabled = false;
            input.focus();
        }
    }
    
    startPolling(roomId) {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
        
        this.pollingInterval = setInterval(async () => {
            if (this.currentRoomId === roomId && document.hasFocus()) {
                try {
                    const response = await fetch('../src/api/send_message.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'get_messages',
                            room_id: roomId,
                            limit: 100
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success && result.messages.length !== this.lastMessageCount) {
                        this.renderMessages(result.messages);
                        this.loadRooms(); // Update room list
                    }
                } catch (error) {
                    console.error('Polling error:', error);
                }
            }
        }, 3000);
    }
    
    async markAsRead(roomId) {
        try {
            await fetch('../src/api/send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'mark_read',
                    room_id: roomId
                })
            });
            this.loadRooms(); // Update unread counts
        } catch (error) {
            console.error('Mark as read error:', error);
        }
    }
    
    refreshMessages() {
        if (this.currentRoomId) {
            this.loadMessages(this.currentRoomId);
        }
    }
    
    clearChat() {
        if (confirm('Clear this conversation? This action cannot be undone.')) {
            // Implement clear chat functionality
            alert('Feature coming soon');
        }
    }
    
    attachFile() {
        alert('File attachment feature coming soon!');
    }
    
    formatTime(timestamp) {
        if (!timestamp) return '';
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;
        
        if (diff < 60000) return 'Just now';
        if (diff < 3600000) return `${Math.floor(diff / 60000)} min ago`;
        if (diff < 86400000) return `${Math.floor(diff / 3600000)} hours ago`;
        if (diff < 604800000) return `${Math.floor(diff / 86400000)} days ago`;
        
        return date.toLocaleDateString();
    }
    
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    setupEventListeners() {
        window.addEventListener('beforeunload', () => {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
            }
        });
    }
}

// Initialize when DOM is ready
let chatApp;
document.addEventListener('DOMContentLoaded', () => {
    chatApp = new ChatApp(currentUserId, currentRoomId);
    chatApp.init();
});