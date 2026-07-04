<?php
// pages/notifications.php
declare(strict_types=1);

$pageTitle = 'Notifications - Inventory Management System';
require_once __DIR__ . '/../config/autoload.php';
require_once __DIR__ . '/../models/Notification.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

if (!$userId) {
    SessionManager::flash('error', 'Please log in to view notifications.');
    header('Location: ' . BASE_URL . '/auth/signin.php');
    exit;
}

if (!$companyId) {
    SessionManager::flash('error', 'Company context not found.');
    header('Location: ' . BASE_URL . '/dashboard');
    exit;
}

// Initialize notification model with company context
$notificationModel = new Notification($companyId);

// Handle actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'mark_read' && isset($_GET['id'])) {
        $notificationModel->markAsRead((int) $_GET['id'], $userId);
        header('Location: ?page=notifications');
        exit;
    }

    if ($action === 'mark_all_read') {
        $notificationModel->markAllAsRead($userId);
        header('Location: ?page=notifications');
        exit;
    }

    if ($action === 'clear_all') {
        $notificationModel->clearAll($userId);
        SessionManager::flash('success', 'All notifications cleared.');
        header('Location: ?page=notifications');
        exit;
    }
}

// Get paginated notifications
$page = isset($_GET['p']) ? (int) $_GET['p'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$notifications = $notificationModel->getUserNotifications($userId, $limit, $offset);
$unreadCount = $notificationModel->getUnreadCount($userId);

// Get total count for pagination with company filter
$totalSql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND company_id = :company_id";
$stmt = $db->prepare($totalSql);
$stmt->execute([
    'user_id' => $userId,
    'company_id' => $companyId
]);
$total = $stmt->fetch()['count'];
$totalPages = ceil($total / $limit);

// Get notification statistics
$stats = $notificationModel->getStats($userId);
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-bell me-2"></i>Notifications
                    </h2>
                    <p class="mb-0 text-muted">View and manage your notifications</p>
                </div>
                <div>
                    <?php if ($unreadCount > 0): ?>
                        <a href="?page=notifications&action=mark_all_read" class="btn btn-success me-2">
                            <i class="fas fa-check-double me-2"></i>Mark All as Read
                        </a>
                    <?php endif; ?>
                    <?php if ($total > 0): ?>
                        <a href="?page=notifications&action=clear_all" class="btn btn-danger"
                            onclick="return confirm('Clear all notifications? This action cannot be undone.')">
                            <i class="fas fa-trash me-2"></i>Clear All
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Notifications
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-bell fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Unread
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['unread']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-envelope fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Read
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['read']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <ul class="nav nav-tabs card-header-tabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo !isset($_GET['filter']) ? 'active' : ''; ?>"
                        href="?page=notifications">
                        <i class="fas fa-list me-1"></i>All
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo isset($_GET['filter']) && $_GET['filter'] === 'unread' ? 'active' : ''; ?>"
                        href="?page=notifications&filter=unread">
                        <i class="fas fa-envelope me-1"></i>Unread
                        <?php if ($stats['unread'] > 0): ?>
                            <span class="badge bg-danger ms-1"><?php echo $stats['unread']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo isset($_GET['filter']) && $_GET['filter'] === 'read' ? 'active' : ''; ?>"
                        href="?page=notifications&filter=read">
                        <i class="fas fa-check-circle me-1"></i>Read
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body p-0">
            <?php if (empty($notifications)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-bell-slash fa-4x text-muted mb-3"></i>
                    <p class="mb-0">No notifications found</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($notifications as $notif): ?>
                        <?php
                        $icon = getNotificationIcon($notif['type']);
                        $timeAgo = timeAgo($notif['created_at']);
                        ?>
                        <div
                            class="list-group-item list-group-item-action <?php echo !$notif['is_read'] ? 'notification-unread' : ''; ?>">
                            <div class="d-flex w-100 justify-content-between align-items-start">
                                <div class="d-flex flex-grow-1">
                                    <div class="me-3">
                                        <i class="<?php echo $icon['icon']; ?> <?php echo $icon['color']; ?> fa-lg"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <h6 class="mb-0"><?php echo htmlspecialchars($notif['title']); ?></h6>
                                            <small class="text-muted ms-2">
                                                <i class="fas fa-clock me-1"></i><?php echo $timeAgo; ?>
                                            </small>
                                        </div>
                                        <p class="mb-1"><?php echo htmlspecialchars($notif['message']); ?></p>
                                        <?php if ($notif['data']): ?>
                                            <small class="text-muted">
                                                <?php
                                                $data = json_decode($notif['data'], true);
                                                if ($data && isset($data['amount'])): ?>
                                                    <i class="fas fa-money-bill-wave me-1"></i>Amount:
                                                    <?php echo number_format($data['amount'], 2); ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center ms-3">
                                    <?php if (!$notif['is_read']): ?>
                                        <a href="?page=notifications&action=mark_read&id=<?php echo $notif['id']; ?>"
                                            class="btn btn-sm btn-outline-primary me-2" title="Mark as read">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($notif['link']): ?>
                                        <a href="<?php echo htmlspecialchars($notif['link']); ?>"
                                            class="btn btn-sm btn-outline-info" title="View details">
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link"
                                href="?page=notifications&p=<?php echo $page - 1; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?>">
                                Previous
                            </a>
                        </li>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);

                        if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link"
                                    href="?page=notifications&p=1<?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?>">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link"
                                    href="?page=notifications&p=<?php echo $i; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link"
                                    href="?page=notifications&p=<?php echo $totalPages; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?>">
                                    <?php echo $totalPages; ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link"
                                href="?page=notifications&p=<?php echo $page + 1; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?>">
                                Next
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
function getNotificationIcon($type)
{
    $icons = [
        'low_stock' => ['icon' => 'fas fa-exclamation-triangle', 'color' => 'text-warning'],
        'overdue_invoice' => ['icon' => 'fas fa-clock', 'color' => 'text-danger'],
        'upcoming_invoice' => ['icon' => 'fas fa-hourglass-half', 'color' => 'text-info'],
        'pending_order' => ['icon' => 'fas fa-truck', 'color' => 'text-warning'],
        'sale_completed' => ['icon' => 'fas fa-shopping-cart', 'color' => 'text-success'],
        'order_received' => ['icon' => 'fas fa-box', 'color' => 'text-success'],
        'new_customer' => ['icon' => 'fas fa-user-plus', 'color' => 'text-primary'],
        'backup_reminder' => ['icon' => 'fas fa-database', 'color' => 'text-info'],
        'system_alert' => ['icon' => 'fas fa-exclamation-circle', 'color' => 'text-warning']
    ];

    return $icons[$type] ?? ['icon' => 'fas fa-bell', 'color' => 'text-secondary'];
}

function timeAgo($timestamp)
{
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60)
        return 'just now';
    if ($diff < 3600)
        return floor($diff / 60) . ' min ago';
    if ($diff < 86400)
        return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800)
        return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000)
        return floor($diff / 604800) . ' weeks ago';
    return date('d M Y', $time);
}
?>

<style>
    .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }

    .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }

    .border-left-warning {
        border-left: 4px solid #f6c23e !important;
    }

    .notification-unread {
        background-color: #f0f7ff;
        border-left: 3px solid #4e73df;
    }

    .notification-unread:hover {
        background-color: #e8f0fe;
    }

    .list-group-item:hover {
        background-color: #f8f9fc;
    }

    .nav-tabs .nav-link {
        color: #6c757d;
        border: none;
        padding: 0.75rem 1rem;
    }

    .nav-tabs .nav-link:hover {
        color: #4e73df;
        border: none;
    }

    .nav-tabs .nav-link.active {
        color: #4e73df;
        background: transparent;
        border-bottom: 2px solid #4e73df;
    }

    .card-header-tabs {
        margin-right: -0.625rem;
        margin-bottom: -0.75rem;
        margin-left: -0.625rem;
        border-bottom: 0;
    }
</style>