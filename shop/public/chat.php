<?php
// /public/chat.php - Updated version

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();

if (!$customer) {
    header('Location: login.php?redirect=chat.php');
    exit;
}

$pageTitle = 'Messages - ' . SITE_NAME;
$roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$sellerType = isset($_GET['seller_type']) ? $_GET['seller_type'] : '';
$sellerId = isset($_GET['seller_id']) ? (int)$_GET['seller_id'] : 0;
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

// Include header
include __DIR__ . '/../templates/header.php';
?>

<!-- Chat CSS -->
<link rel="stylesheet" href="./assets/css/chat.css">

<main class="container">
    <div class="chat-container">
        <!-- Chat Sidebar -->
        <div class="chat-sidebar">
            <div class="chat-sidebar-header">
                <h3><i class="fas fa-comments"></i> Messages</h3>
                <p>Your conversations</p>
            </div>
            <div class="chat-rooms-list" id="chat-rooms-list">
                <div class="chat-loading">
                    <div class="loading-dots">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Chat Main Area -->
        <div class="chat-main" id="chat-main">
            <div class="empty-chat">
                <div class="empty-chat-content">
                    <div class="empty-chat-icon">💬</div>
                    <h4>Select a conversation</h4>
                    <p>Choose a chat from the left to start messaging</p>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
const currentUserId = <?php echo $customer['id']; ?>;
const currentRoomId = <?php echo $roomId; ?>;
const sellerType = '<?php echo $sellerType; ?>';
const sellerId = <?php echo $sellerId; ?>;
const productId = <?php echo $productId; ?>;

// If we have seller info, create a room automatically
if (sellerType && sellerId && !currentRoomId) {
    (async function() {
        try {
            const response = await fetch('../src/api/send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create_room',
                    seller_type: sellerType,
                    seller_id: sellerId,
                    product_id: productId,
                    product_type: sellerType === 'peer' ? 'p2p' : 'b2c'
                })
            });
            
            const result = await response.json();
            if (result.success && result.room_id) {
                // Redirect to the new room
                window.location.href = `chat.php?room_id=${result.room_id}`;
            }
        } catch (error) {
            console.error('Failed to create room:', error);
        }
    })();
}
</script>

<script src="./assets/js/chat.js"></script>

<?php include __DIR__ . '/../templates/footer.php'; ?>