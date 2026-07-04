    <?php
// pages/chat/index.php
declare(strict_types=1);

$pageTitle = 'Team Chat - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/UserRole.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'VIW';

if (!$userId) {
    SessionManager::flash('error', 'Please log in to access chat.');
    header('Location: ' . BASE_URL . '/auth/signin.php');
    exit;
}

$userModel = new User();
$roleModel = new UserRole();

// Get current user info
$currentUser = $userModel->find($userId);
$userRole = $roleModel->find($currentUser['role_id']);

// Get all active users for contact list
$users = $userModel->all(['id', 'username', 'full_name', 'email', 'avatar', 'last_login', 'is_active'], 'is_active = 1 AND is_deleted = 0');

// Get user roles for filtering
$roles = $roleModel->all(['id', 'role_name', 'role_code'], '1=1');

// Get recent conversations (simulated - in real app, this would come from a messages table)
// For now, we'll create a sample structure
$conversations = [];

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo $pageTitle; ?>
    </title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Emoji Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/emoji-picker-element@1.21.0/index.min.css">

    <style>
        :root {
            --chat-primary: #4e73df;
            --chat-success: #1cc88a;
            --chat-info: #36b9cc;
            --chat-warning: #f6c23e;
            --chat-danger: #e74a3b;
            --chat-bg-light: #f8f9fc;
            --chat-bg-dark: #5a5c69;
        }

        .chat-container {
            height: calc(100vh - 200px);
            min-height: 500px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .chat-sidebar {
            height: 100%;
            background: var(--chat-bg-light);
            border-right: 1px solid #e3e6f0;
        }

        .chat-main {
            height: 100%;
            background: white;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            padding: 15px 20px;
            background: white;
            border-bottom: 1px solid #e3e6f0;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8f9fc;
        }

        .chat-footer {
            padding: 15px 20px;
            background: white;
            border-top: 1px solid #e3e6f0;
        }

        /* User List Styles */
        .user-list {
            height: calc(100% - 130px);
            overflow-y: auto;
            padding: 10px;
        }

        .user-item {
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 5px;
        }

        .user-item:hover {
            background: #e8ecf4;
        }

        .user-item.active {
            background: var(--chat-primary);
            color: white;
        }

        .user-item.active .text-muted {
            color: rgba(255, 255, 255, 0.8) !important;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--chat-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
        }

        .user-avatar.small {
            width: 32px;
            height: 32px;
            font-size: 14px;
        }

        .user-avatar.online {
            position: relative;
        }

        .user-avatar.online::after {
            content: '';
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 10px;
            height: 10px;
            background: #1cc88a;
            border: 2px solid white;
            border-radius: 50%;
        }

        /* Message Styles */
        .message {
            display: flex;
            margin-bottom: 20px;
        }

        .message.sent {
            justify-content: flex-end;
        }

        .message-content {
            max-width: 70%;
            padding: 10px 15px;
            border-radius: 15px;
            position: relative;
        }

        .message.received .message-content {
            background: white;
            border: 1px solid #e3e6f0;
            margin-left: 10px;
        }

        .message.sent .message-content {
            background: var(--chat-primary);
            color: white;
            margin-right: 10px;
        }

        .message-time {
            font-size: 11px;
            margin-top: 5px;
            color: #999;
        }

        .message.sent .message-time {
            text-align: right;
            color: rgba(255, 255, 255, 0.7);
        }

        /* Typing Indicator */
        .typing-indicator {
            display: inline-flex;
            align-items: center;
            padding: 10px 15px;
            background: #e8ecf4;
            border-radius: 20px;
        }

        .typing-indicator span {
            width: 8px;
            height: 8px;
            background: #999;
            border-radius: 50%;
            display: inline-block;
            margin: 0 2px;
            animation: typing 1.4s infinite;
        }

        .typing-indicator span:nth-child(2) {
            animation-delay: 0.2s;
        }

        .typing-indicator span:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes typing {

            0%,
            60%,
            100% {
                transform: translateY(0);
                opacity: 0.4;
            }

            30% {
                transform: translateY(-5px);
                opacity: 1;
            }
        }

        /* Search Box */
        .search-box {
            padding: 15px;
            border-bottom: 1px solid #e3e6f0;
        }

        .search-box input {
            border-radius: 20px;
            border: 1px solid #d1d3e2;
            padding: 8px 15px;
            width: 100%;
        }

        /* Emoji Picker */
        .emoji-picker-container {
            position: absolute;
            bottom: 80px;
            right: 20px;
            display: none;
            z-index: 1000;
        }

        .emoji-picker-container.show {
            display: block;
        }

        /* Attachment Preview */
        .attachment-preview {
            max-width: 200px;
            max-height: 150px;
            margin: 10px 0;
            border-radius: 8px;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Unread Badge */
        .unread-badge {
            background: var(--chat-danger);
            color: white;
            border-radius: 50%;
            min-width: 20px;
            height: 20px;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: auto;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../../templates/header.php'; ?>
    <?php require_once __DIR__ . '/../../templates/sidebar.php'; ?>

    <div class="container-fluid py-4">
        <div class="chat-container">
            <div class="row g-0 h-100">
                <!-- Sidebar - User List -->
                <div class="col-md-4 col-lg-3 chat-sidebar">
                    <div class="search-box">
                        <input type="text" class="form-control" placeholder="Search users..." id="searchUsers">
                    </div>

                    <!-- Filter Tabs -->
                    <div class="px-3 py-2 border-bottom">
                        <div class="btn-group w-100" role="group">
                            <button type="button" class="btn btn-sm btn-outline-primary active"
                                data-filter="all">All</button>
                            <button type="button" class="btn btn-sm btn-outline-success"
                                data-filter="online">Online</button>
                            <button type="button" class="btn btn-sm btn-outline-info" data-filter="team">Team</button>
                        </div>
                    </div>

                    <!-- User List -->
                    <div class="user-list" id="userList">
                        <?php foreach ($users as $user): ?>
                            <?php if ($user['id'] != $userId): ?>
                                <div class="user-item d-flex align-items-center" data-user-id="<?php echo $user['id']; ?>"
                                    data-user-name="<?php echo htmlspecialchars($user['full_name']); ?>">
                                    <div
                                        class="user-avatar me-3 <?php echo $user['last_login'] && (time() - strtotime($user['last_login']) < 300) ? 'online' : ''; ?>">
                                        <?php echo strtoupper(substr($user['full_name'] ?? $user['username'], 0, 1)); ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <strong>
                                                <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                            </strong>
                                            <small class="text-muted">12:30</small>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">Last message preview...</small>
                                            <span class="unread-badge" style="display: none;">3</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <!-- Current User Info -->
                    <div class="p-3 border-top">
                        <div class="d-flex align-items-center">
                            <div class="user-avatar small me-2 online">
                                <?php echo strtoupper(substr($currentUser['full_name'] ?? $currentUser['username'], 0, 1)); ?>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted d-block">
                                    <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>
                                </small>
                                <small class="text-success">Online</small>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" onclick="toggleUserMenu()">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Main Chat Area -->
                <div class="col-md-8 col-lg-9 chat-main">
                    <!-- Chat Header -->
                    <div class="chat-header d-flex justify-content-between align-items-center" id="chatHeader">
                        <div class="d-flex align-items-center" id="selectedUserInfo" style="display: none;">
                            <div class="user-avatar me-3" id="selectedUserAvatar"></div>
                            <div>
                                <h6 class="mb-0" id="selectedUserName">Select a user to start chatting</h6>
                                <small class="text-muted" id="selectedUserStatus"></small>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary" onclick="showUserInfo()">
                                <i class="fas fa-info-circle"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="clearChat()">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Chat Messages -->
                    <div class="chat-messages" id="chatMessages">
                        <div class="text-center text-muted py-5" id="welcomeMessage">
                            <i class="fas fa-comments fa-3x mb-3"></i>
                            <p>Select a user to start chatting</p>
                        </div>

                        <!-- Sample Messages (will be replaced with real data) -->
                        <div class="message received" style="display: none;">
                            <div class="user-avatar small">J</div>
                            <div class="message-content">
                                <p class="mb-0">Hello! How can I help you?</p>
                                <div class="message-time">10:30 AM</div>
                            </div>
                        </div>

                        <div class="message sent" style="display: none;">
                            <div class="message-content">
                                <p class="mb-0">I need help with inventory</p>
                                <div class="message-time">10:31 AM</div>
                            </div>
                        </div>
                    </div>

                    <!-- Chat Footer -->
                    <div class="chat-footer">
                        <!-- Typing Indicator -->
                        <div class="typing-indicator mb-2" id="typingIndicator" style="display: none;">
                            <span></span>
                            <span></span>
                            <span></span>
                            <small class="ms-2 text-muted">Someone is typing...</small>
                        </div>

                        <!-- Message Form -->
                        <form id="messageForm" onsubmit="sendMessage(event)">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" id="receiverId" name="receiver_id" value="">

                            <div class="input-group">
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleEmojiPicker()">
                                    <i class="far fa-smile"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleAttachment()">
                                    <i class="fas fa-paperclip"></i>
                                </button>
                                <input type="text" class="form-control" id="messageInput" name="message"
                                    placeholder="Type your message..." autocomplete="off" disabled>
                                <button type="submit" class="btn btn-primary" id="sendButton" disabled>
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>

                            <!-- Attachment Preview -->
                            <div id="attachmentPreview" class="attachment-preview" style="display: none;">
                                <img src="" alt="Attachment preview" class="img-fluid">
                                <button type="button" class="btn btn-sm btn-danger mt-2" onclick="removeAttachment()">
                                    <i class="fas fa-times"></i> Remove
                                </button>
                            </div>

                            <!-- Hidden file input -->
                            <input type="file" id="fileInput" style="display: none;"
                                accept="image/*,.pdf,.doc,.docx,.xls,.xlsx">
                        </form>
                    </div>

                    <!-- Emoji Picker -->
                    <div class="emoji-picker-container" id="emojiPicker"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Info Modal -->
    <div class="modal fade" id="userInfoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user-circle me-2"></i>User Information
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="userInfoContent">
                    <!-- User info will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="startVideoCall()">
                        <i class="fas fa-video me-2"></i>Video Call
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Emoji Picker -->
    <script src="https://cdn.jsdelivr.net/npm/emoji-picker-element@1.21.0/index.min.js"></script>

    <script>
        // State management
        let currentChatUser = null;
        let messagePollingInterval = null;
        let typingTimeout = null;
        let emojiPicker = null;

        // Initialize emoji picker
        document.addEventListener('DOMContentLoaded', function () {
            if (document.querySelector('emoji-picker')) {
                emojiPicker = document.querySelector('emoji-picker');
            } else {
                emojiPicker = document.createElement('emoji-picker');
                document.getElementById('emojiPicker').appendChild(emojiPicker);
            }

            emojiPicker.addEventListener('emoji-click', event => {
                const input = document.getElementById('messageInput');
                input.value += event.detail.unicode;
                toggleEmojiPicker();
            });

            // Initialize search
            document.getElementById('searchUsers').addEventListener('input', function (e) {
                searchUsers(e.target.value);
            });

            // Initialize filter buttons
            document.querySelectorAll('[data-filter]').forEach(btn => {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('[data-filter]').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    filterUsers(this.dataset.filter);
                });
            });

            // Load initial conversations
            loadConversations();
        });

        // User selection
        function selectUser(userId, userName, userAvatar) {
            currentChatUser = userId;

            document.getElementById('receiverId').value = userId;
            document.getElementById('selectedUserInfo').style.display = 'flex';
            document.getElementById('selectedUserName').textContent = userName;
            document.getElementById('selectedUserAvatar').textContent = userAvatar;
            document.getElementById('welcomeMessage').style.display = 'none';
            document.getElementById('messageInput').disabled = false;
            document.getElementById('sendButton').disabled = false;

            // Load chat history
            loadChatHistory(userId);

            // Start polling for new messages
            startMessagePolling(userId);

            // Mark user as active
            document.querySelectorAll('.user-item').forEach(item => {
                item.classList.remove('active');
                if (item.dataset.userId == userId) {
                    item.classList.add('active');
                }
            });
        }

        // Load chat history
        function loadChatHistory(userId) {
            fetch(`./api/chat/history.php?user_id=${userId}`, {
                credentials: 'include'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderMessages(data.messages);
                    }
                })
                .catch(error => console.error('Error loading chat history:', error));
        }

        // Render messages
        function renderMessages(messages) {
            const container = document.getElementById('chatMessages');
            container.innerHTML = '';

            messages.forEach(msg => {
                const messageDiv = document.createElement('div');
                messageDiv.className = `message ${msg.sender_id == <?php echo $userId; ?> ? 'sent' : 'received'
            }`;
                
                messageDiv.innerHTML = `
                    ${ msg.sender_id != <?php echo $userId; ?> ?
                `<div class="user-avatar small">${msg.sender_name.charAt(0)}</div>` : ''}
        <div class="message-content">
            <p class="mb-0">${escapeHtml(msg.message)}</p>
            <div class="message-time">${formatTime(msg.created_at)}</div>
        </div>
        `;
                
                container.appendChild(messageDiv);
            });
            
            // Scroll to bottom
            container.scrollTop = container.scrollHeight;
        }

        // Send message
        function sendMessage(event) {
            event.preventDefault();
            
            const message = document.getElementById('messageInput').value.trim();
            const receiverId = document.getElementById('receiverId').value;
            
            if (!message || !receiverId) return;
            
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');
            formData.append('receiver_id', receiverId);
            formData.append('message', message);
            
            fetch('./api/chat/send.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('messageInput').value = '';
                    // Immediately add message to chat
                    const container = document.getElementById('chatMessages');
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'message sent';
                    messageDiv.innerHTML = `
            < div class="message-content" >
                            <p class="mb-0">${escapeHtml(message)}</p>
                            <div class="message-time">Just now</div>
                        </div >
            `;
                    container.appendChild(messageDiv);
                    container.scrollTop = container.scrollHeight;
                }
            })
            .catch(error => console.error('Error sending message:', error));
        }

        // Start polling for new messages
        function startMessagePolling(userId) {
            if (messagePollingInterval) {
                clearInterval(messagePollingInterval);
            }
            
            messagePollingInterval = setInterval(() => {
                fetch(`./ api / chat / poll.php ? user_id = ${ userId }& last_id=${ lastMessageId } `, {
                    credentials: 'include'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.messages.length > 0) {
                        renderMessages(data.messages);
                    }
                })
                .catch(error => console.error('Error polling messages:', error));
            }, 3000);
        }

        // Typing indicator
        document.getElementById('messageInput').addEventListener('input', function() {
            if (currentChatUser) {
                clearTimeout(typingTimeout);
                
                // Send typing notification
                fetch('./api/chat/typing.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        receiver_id: currentChatUser,
                        is_typing: true
                    }),
                    credentials: 'include'
                });
                
                typingTimeout = setTimeout(() => {
                    fetch('./api/chat/typing.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            receiver_id: currentChatUser,
                            is_typing: false
                        }),
                        credentials: 'include'
                    });
                }, 2000);
            }
        });

        // Search users
        function searchUsers(term) {
            const items = document.querySelectorAll('.user-item');
            term = term.toLowerCase();
            
            items.forEach(item => {
                const name = item.dataset.userName.toLowerCase();
                if (name.includes(term)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Filter users
        function filterUsers(filter) {
            // Implement filtering logic
        }

        // Toggle emoji picker
        function toggleEmojiPicker() {
            document.getElementById('emojiPicker').classList.toggle('show');
        }

        // Toggle attachment
        function toggleAttachment() {
            document.getElementById('fileInput').click();
        }

        // Handle file selection
        document.getElementById('fileInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Preview image
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = document.getElementById('attachmentPreview');
                        preview.querySelector('img').src = e.target.result;
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                }
                
                // Upload file
                uploadFile(file);
            }
        });

        // Upload file
        function uploadFile(file) {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');
            
            fetch('./api/chat/upload.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Insert file link into message
                    document.getElementById('messageInput').value += ` [File: ${ data.filename }]`;
                }
            })
            .catch(error => console.error('Error uploading file:', error));
        }

        // Remove attachment
        function removeAttachment() {
            document.getElementById('attachmentPreview').style.display = 'none';
            document.getElementById('fileInput').value = '';
        }

        // Show user info
        function showUserInfo() {
            if (currentChatUser) {
                fetch(`./ api / users / get.php ? id = ${ currentChatUser } `, {
                    credentials: 'include'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const content = document.getElementById('userInfoContent');
                        content.innerHTML = `
            < div class="text-center mb-3" >
                                <div class="user-avatar mx-auto mb-2" style="width: 80px; height: 80px; font-size: 32px;">
                                    ${data.user.full_name.charAt(0)}
                                </div>
                                <h5>${escapeHtml(data.user.full_name)}</h5>
                                <p class="text-muted">@${escapeHtml(data.user.username)}</p>
                            </div >
            <table class="table table-sm">
                <tr>
                    <th>Email:</th>
                    <td><a href="mailto:${escapeHtml(data.user.email)}">${escapeHtml(data.user.email)}</a></td>
                </tr>
                <tr>
                    <th>Phone:</th>
                    <td>${data.user.phone ? escapeHtml(data.user.phone) : '-'}</td>
                </tr>
                <tr>
                    <th>Role:</th>
                    <td><span class="badge bg-info">${escapeHtml(data.user.role_name)}</span></td>
                </tr>
                <tr>
                    <th>Last Active:</th>
                    <td>${data.user.last_login ? timeAgo(data.user.last_login) : 'Never'}</td>
                </tr>
            </table>
                        `;

                        new bootstrap.Modal(document.getElementById('userInfoModal')).show();
                    }
                });
            }
        }

        // Start video call (placeholder)
        function startVideoCall() {
            alert('Video call feature coming soon!');
        }

        // Clear current chat
        function clearChat() {
            if (confirm('Clear this conversation?')) {
                document.getElementById('chatMessages').innerHTML = '';
                document.getElementById('welcomeMessage').style.display = 'block';
                document.getElementById('selectedUserInfo').style.display = 'none';
                document.getElementById('messageInput').disabled = true;
                document.getElementById('sendButton').disabled = true;
                currentChatUser = null;

                if (messagePollingInterval) {
                    clearInterval(messagePollingInterval);
                }
            }
        }

        // Helper functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatTime(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();

            if (date.toDateString() === now.toDateString()) {
                return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } else {
                return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
        }

        function timeAgo(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const seconds = Math.floor((now - date) / 1000);

            if (seconds < 60) return 'just now';
            if (seconds < 3600) return Math.floor(seconds / 60) + ' minutes ago';
            if (seconds < 86400) return Math.floor(seconds / 3600) + ' hours ago';
            return date.toLocaleDateString();
        }

        // Load conversations (placeholder)
        function loadConversations() {
            // This would load recent conversations from the server
        }

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (messagePollingInterval) {
                clearInterval(messagePollingInterval);
            }
        });

        // Toggle user menu
        function toggleUserMenu() {
            // Implement user menu
        }
    </script>
</body>

</html>