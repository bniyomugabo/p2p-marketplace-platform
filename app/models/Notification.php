<?php
// models/Notification.php
// ============================================
// NOTIFICATIONS MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Notification extends BaseModel
{
    protected $table = 'notifications';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = true;

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }


    /**
     * Check if a similar notification already exists
     */
    public function checkExistingNotification($userId, $type, $data = null, $hours = 24)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "
            SELECT id FROM {$this->table}
            WHERE user_id = :user_id 
                AND type = :type 
                AND company_id = :company_id
                AND created_at > DATE_SUB(NOW(), INTERVAL :hours HOUR)
                AND is_read = 0
        ";

        $params = [
            'user_id' => $userId,
            'type' => $type,
            'company_id' => $this->companyId,
            'hours' => $hours
        ];

        // If data is provided, check for similar data
        if ($data) {
            $sql .= " AND data = :data";
            $params['data'] = is_array($data) ? json_encode($data) : $data;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * Get recent notifications (unread first, then recent) - company-specific
     */
    public function getRecent($userId, $limit = 10)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT n.* 
            FROM {$this->table} n
            WHERE n.user_id = :user_id 
                AND n.company_id = :company_id
            ORDER BY n.is_read ASC, n.created_at DESC 
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Create a new notification
     */
    public function createNotification($userId, $type, $title, $message, $link = null, $data = null)
    {
        if (!$this->companyId) {
            return false;
        }

        // Check for existing similar notification to avoid duplicates
        if ($this->checkExistingNotification($userId, $type, $data, 24)) {
            return false; // Skip duplicate notification
        }

        $notificationData = [
            'company_id' => $this->companyId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'data' => $data ? json_encode($data) : null,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];

        return $this->create($notificationData);
    }

    /**
     * Get unread notifications for a user (company-specific)
     */
    public function getUnread($userId, $limit = 10)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT n.* 
            FROM {$this->table} n
            WHERE n.user_id = :user_id 
                AND n.company_id = :company_id
                AND n.is_read = 0
            ORDER BY n.created_at DESC 
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get all notifications for a user (company-specific)
     */
    public function getUserNotifications($userId, $limit = 50, $offset = 0)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT n.* 
            FROM {$this->table} n
            WHERE n.user_id = :user_id 
                AND n.company_id = :company_id
            ORDER BY n.created_at DESC 
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get unread count for a user (company-specific)
     */
    public function getUnreadCount($userId)
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "
            SELECT COUNT(*) as count 
            FROM {$this->table} n
            WHERE n.user_id = :user_id 
                AND n.company_id = :company_id
                AND n.is_read = 0
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'company_id' => $this->companyId
        ]);

        $result = $stmt->fetch();
        return $result ? (int) $result['count'] : 0;
    }

    /**
     * Mark notification as read (with company check)
     */
    public function markAsRead($notificationId, $userId = null)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "UPDATE {$this->table} 
                SET is_read = 1, read_at = NOW() 
                WHERE id = :id AND company_id = :company_id";

        $params = [
            'id' => $notificationId,
            'company_id' => $this->companyId
        ];

        if ($userId) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $userId;
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Mark all notifications as read for a user (company-specific)
     */
    public function markAllAsRead($userId)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "UPDATE {$this->table} 
                SET is_read = 1, read_at = NOW() 
                WHERE user_id = :user_id 
                    AND company_id = :company_id
                    AND is_read = 0";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'user_id' => $userId,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Delete notification (with company check)
     */
    public function deleteNotification($notificationId, $userId = null)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "DELETE FROM {$this->table} 
                WHERE id = :id AND company_id = :company_id";

        $params = [
            'id' => $notificationId,
            'company_id' => $this->companyId
        ];

        if ($userId) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $userId;
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Clear all notifications for a user (company-specific)
     */
    public function clearAll($userId)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "DELETE FROM {$this->table} 
                WHERE user_id = :user_id AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'user_id' => $userId,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Get notifications by type (company-specific)
     */
    public function getByType($userId, $type, $limit = 50)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT n.* 
            FROM {$this->table} n
            WHERE n.user_id = :user_id 
                AND n.type = :type 
                AND n.company_id = :company_id
            ORDER BY n.created_at DESC 
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get notifications by date range (company-specific)
     */
    public function getByDateRange($userId, $startDate, $endDate)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT n.* 
            FROM {$this->table} n
            WHERE n.user_id = :user_id 
                AND n.company_id = :company_id
                AND DATE(n.created_at) BETWEEN :start_date AND :end_date
            ORDER BY n.created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'company_id' => $this->companyId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Generate all system notifications for the company
     * This method is called by the cron job
     */
    public function generateSystemNotifications()
    {
        if (!$this->companyId) {
            return [];
        }

        $notifications = [];

        // 1. Low stock alerts
        $lowStockNotifs = $this->generateLowStockAlerts();
        $notifications = array_merge($notifications, $lowStockNotifs);

        // 2. Overdue invoices
        $overdueNotifs = $this->generateOverdueInvoices();
        $notifications = array_merge($notifications, $overdueNotifs);

        // 3. Upcoming due invoices (3 days before due date)
        $upcomingNotifs = $this->generateUpcomingInvoices();
        $notifications = array_merge($notifications, $upcomingNotifs);

        // 4. Pending purchase orders
        $pendingNotifs = $this->generatePendingPurchaseOrders();
        $notifications = array_merge($notifications, $pendingNotifs);

        // 5. New customers (last 24 hours)
        $newCustomerNotifs = $this->generateNewCustomerAlerts();
        $notifications = array_merge($notifications, $newCustomerNotifs);

        // 6. System backup reminders (weekly)
        $backupNotifs = $this->generateBackupReminders();
        $notifications = array_merge($notifications, $backupNotifs);

        return $notifications;
    }

    /**
     * Generate overdue invoice notifications
     */
    protected function generateOverdueInvoices()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
        SELECT 
            si.id,
            si.invoice_number,
            si.customer_id,
            si.total_amount,
            si.amount_paid,
            si.due_date,
            c.full_name as customer_name,
            (si.total_amount - si.amount_paid) as balance_due,
            u.id as user_id
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        JOIN users u ON si.company_id = u.company_id
        JOIN user_roles r ON u.role_id = r.id
        WHERE si.status IN ('issued', 'partial')
            AND si.due_date < CURDATE()
            AND si.company_id = :company_id
            AND r.role_code IN ('ADM', 'MGR', 'ACC')
            AND u.is_active = 1
        GROUP BY u.id, si.id
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $invoices = $stmt->fetchAll();

        $notifications = [];
        foreach ($invoices as $invoice) {
            $balanceDue = $invoice['balance_due'];

            $notifications[] = [
                'user_id' => $invoice['user_id'],
                'type' => 'overdue_invoice',
                'title' => 'Overdue Invoice',
                'message' => "Invoice #{$invoice['invoice_number']} for {$invoice['customer_name']} is overdue. Balance due: " . number_format($balanceDue, 2),
                'link' => '?page=sales/view-invoice&id=' . $invoice['id'],
                'data' => [
                    'invoice_id' => $invoice['id'],
                    'invoice_number' => $invoice['invoice_number'],
                    'customer_name' => $invoice['customer_name'],
                    'balance_due' => $balanceDue
                ]
            ];
        }

        return $notifications;
    }

    /**
     * Generate upcoming invoice notifications (3 days before due date)
     */
    protected function generateUpcomingInvoices()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
        SELECT 
            si.id,
            si.invoice_number,
            si.customer_id,
            si.total_amount,
            si.amount_paid,
            si.due_date,
            c.full_name as customer_name,
            (si.total_amount - si.amount_paid) as balance_due,
            u.id as user_id
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        JOIN users u ON si.company_id = u.company_id
        JOIN user_roles r ON u.role_id = r.id
        WHERE si.status IN ('issued', 'partial')
            AND si.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            AND si.company_id = :company_id
            AND r.role_code IN ('ADM', 'MGR', 'ACC')
            AND u.is_active = 1
        GROUP BY u.id, si.id
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $invoices = $stmt->fetchAll();

        $notifications = [];
        foreach ($invoices as $invoice) {
            $daysLeft = (new DateTime($invoice['due_date']))->diff(new DateTime())->days;
            $balanceDue = $invoice['balance_due'];

            $notifications[] = [
                'user_id' => $invoice['user_id'],
                'type' => 'upcoming_invoice',
                'title' => 'Upcoming Payment Due',
                'message' => "Invoice #{$invoice['invoice_number']} for {$invoice['customer_name']} is due in {$daysLeft} days. Amount: " . number_format($balanceDue, 2),
                'link' => '?page=sales/view-invoice&id=' . $invoice['id'],
                'data' => [
                    'invoice_id' => $invoice['id'],
                    'invoice_number' => $invoice['invoice_number'],
                    'customer_name' => $invoice['customer_name'],
                    'balance_due' => $balanceDue,
                    'days_left' => $daysLeft
                ]
            ];
        }

        return $notifications;
    }

    /**
     * Generate pending purchase order notifications
     */
    protected function generatePendingPurchaseOrders()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
        SELECT 
            po.id,
            po.po_number,
            po.order_date,
            po.expected_date,
            po.status,
            s.supplier_name,
            u.id as user_id
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        JOIN users u ON po.company_id = u.company_id
        JOIN user_roles r ON u.role_id = r.id
        WHERE po.status = 'pending'
            AND po.company_id = :company_id
            AND r.role_code IN ('ADM', 'MGR')
            AND u.is_active = 1
        GROUP BY u.id, po.id
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $orders = $stmt->fetchAll();

        $notifications = [];
        foreach ($orders as $order) {
            $notifications[] = [
                'user_id' => $order['user_id'],
                'type' => 'pending_order',
                'title' => 'Pending Purchase Order',
                'message' => "Purchase Order #{$order['po_number']} for {$order['supplier_name']} is pending approval.",
                'link' => '?page=purchasing/view-order&id=' . $order['id'],
                'data' => [
                    'order_id' => $order['id'],
                    'po_number' => $order['po_number'],
                    'supplier_name' => $order['supplier_name']
                ]
            ];
        }

        return $notifications;
    }

    /**
     * Generate new customer alerts (last 24 hours)
     */
    protected function generateNewCustomerAlerts()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
        SELECT 
            c.id,
            c.full_name,
            c.customer_code,
            c.created_at,
            u.id as user_id
        FROM customers c
        JOIN users u ON c.company_id = u.company_id
        JOIN user_roles r ON u.role_id = r.id
        WHERE c.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND c.company_id = :company_id
            AND r.role_code IN ('ADM', 'MGR', 'SEL')
            AND u.is_active = 1
        GROUP BY u.id, c.id
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $customers = $stmt->fetchAll();

        $notifications = [];
        foreach ($customers as $customer) {
            $notifications[] = [
                'user_id' => $customer['user_id'],
                'type' => 'new_customer',
                'title' => 'New Customer Registered',
                'message' => "New customer '{$customer['full_name']}' ({$customer['customer_code']}) has been added.",
                'link' => '?page=sales/customers&id=' . $customer['id'],
                'data' => [
                    'customer_id' => $customer['id'],
                    'customer_name' => $customer['full_name'],
                    'customer_code' => $customer['customer_code']
                ]
            ];
        }

        return $notifications;
    }

    /**
     * Generate backup reminders (weekly)
     */
    protected function generateBackupReminders()
    {
        if (!$this->companyId) {
            return [];
        }

        // Check if last backup reminder was sent within 7 days
        $sql = "
        SELECT COUNT(*) as count
        FROM notifications
        WHERE type = 'backup_reminder'
            AND company_id = :company_id
            AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $result = $stmt->fetch();

        if ($result['count'] > 0) {
            return []; // Already sent reminder this week
        }

        // Get admin users
        $sql = "
        SELECT DISTINCT u.id as user_id
        FROM users u
        JOIN user_roles r ON u.role_id = r.id
        WHERE u.company_id = :company_id
            AND r.role_code IN ('ADM')
            AND u.is_active = 1
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $admins = $stmt->fetchAll();

        $notifications = [];
        foreach ($admins as $admin) {
            $notifications[] = [
                'user_id' => $admin['user_id'],
                'type' => 'backup_reminder',
                'title' => 'Weekly Backup Reminder',
                'message' => 'Please ensure your database backup is completed this week.',
                'link' => '?page=admin/backup',
                'data' => ['reminder_type' => 'weekly_backup']
            ];
        }

        return $notifications;
    }

    /**
     * Update the existing generateLowStockAlerts method to return array for cron
     */
    public function generateLowStockAlerts()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
        SELECT 
            i.variant_id,
            v.sku,
            v.variant_name,
            p.product_name,
            i.quantity,
            v.reorder_level,
            w.warehouse_name,
            u.id as user_id
        FROM inventory i
        JOIN variants v ON i.variant_id = v.id
        JOIN products p ON v.product_id = p.id
        JOIN warehouses w ON i.warehouse_id = w.id
        JOIN users u ON p.company_id = u.company_id
        JOIN user_roles r ON u.role_id = r.id
        WHERE i.quantity <= v.reorder_level 
            AND i.quantity > 0
            AND p.company_id = :company_id
            AND r.role_code IN ('ADM', 'MGR', 'WHS')
            AND u.is_active = 1
        GROUP BY u.id, i.variant_id
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $items = $stmt->fetchAll();

        $notifications = [];
        foreach ($items as $item) {
            $notifications[] = [
                'user_id' => $item['user_id'],
                'type' => 'low_stock',
                'title' => 'Low Stock Alert',
                'message' => "{$item['product_name']} ({$item['variant_name']}) is low on stock. Current: {$item['quantity']}, Reorder at: {$item['reorder_level']}",
                'link' => '?page=inventory/stock&variant_id=' . $item['variant_id'],
                'data' => [
                    'variant_id' => $item['variant_id'],
                    'quantity' => $item['quantity'],
                    'reorder_level' => $item['reorder_level']
                ]
            ];
        }

        return $notifications;
    }

    /**
     * Generate all system notifications for the company
     */
    public function generateAllNotifications()
    {
        if (!$this->companyId) {
            return [];
        }

        $notifications = [];

        // Low stock alerts
        $lowStock = $this->generateLowStockAlerts();
        $notifications = array_merge($notifications, $lowStock);

        // Add other notification types here as needed
        // $overdueInvoices = $this->generateOverdueInvoices();
        // $pendingOrders = $this->generatePendingPurchaseOrders();

        return $notifications;
    }

    /**
     * Get notification statistics for a user
     */
    public function getStats($userId)
    {
        if (!$this->companyId) {
            return [
                'total' => 0,
                'unread' => 0,
                'read' => 0,
                'by_type' => []
            ];
        }

        // Get totals
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count
            FROM {$this->table}
            WHERE user_id = :user_id AND company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'company_id' => $this->companyId
        ]);
        $totals = $stmt->fetch();

        // Get counts by type
        $sql = "
            SELECT 
                type,
                COUNT(*) as count,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count
            FROM {$this->table}
            WHERE user_id = :user_id AND company_id = :company_id
            GROUP BY type
            ORDER BY count DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'company_id' => $this->companyId
        ]);
        $byType = $stmt->fetchAll();

        return [
            'total' => (int) ($totals['total'] ?? 0),
            'unread' => (int) ($totals['unread_count'] ?? 0),
            'read' => (int) ($totals['read_count'] ?? 0),
            'by_type' => $byType
        ];
    }
}