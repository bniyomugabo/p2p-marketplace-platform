<?php
// controllers/DashboardController.php
// ============================================
// DASHBOARD CONTROLLER
// ============================================

require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Sale.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/Customer.php';

class DashboardController
{

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get dashboard statistics
     */
    public function getStats()
    {
        $stats = [];

        // Today's sales
        $saleModel = new Sale();
        $stats['today_sales'] = $saleModel->getDailySummary();

        // Total products
        $sql = "SELECT COUNT(*) as count FROM products WHERE is_active = 1 AND is_deleted = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['total_products'] = $stmt->fetch()['count'];

        // Total variants
        $sql = "SELECT COUNT(*) as count FROM variants WHERE is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['total_variants'] = $stmt->fetch()['count'];

        // Total customers
        $sql = "SELECT COUNT(*) as count FROM customers WHERE is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['total_customers'] = $stmt->fetch()['count'];

        // Low stock count
        $sql = "
            SELECT COUNT(*) as count 
            FROM inventory i
            JOIN variants v ON i.variant_id = v.id
            WHERE i.quantity <= v.reorder_level AND i.quantity > 0
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['low_stock_count'] = $stmt->fetch()['count'];

        // Out of stock count
        $sql = "
            SELECT COUNT(DISTINCT v.id) as count
            FROM variants v
            WHERE v.is_active = 1
                AND NOT EXISTS (
                    SELECT 1 FROM inventory i 
                    WHERE i.variant_id = v.id AND i.quantity > 0
                )
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['out_of_stock_count'] = $stmt->fetch()['count'];

        // Monthly sales chart data
        $stats['monthly_sales'] = $this->getMonthlySales();

        // Top selling products
        $stats['top_products'] = $this->getTopProducts(10);

        // Recent activities
        $stats['recent_activities'] = $this->getRecentActivities();

        return $stats;
    }

    /**
     * Get monthly sales for chart
     */
    private function getMonthlySales($months = 6)
    {
        $sql = "
            SELECT 
                DATE_FORMAT(invoice_date, '%Y-%m') as month,
                COUNT(*) as invoice_count,
                SUM(total_amount) as total_sales
            FROM sales_invoices
            WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
                AND status != 'cancelled'
            GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
            ORDER BY month ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':months', $months, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetchAll();

        // Format for chart
        $labels = [];
        $values = [];

        foreach ($data as $row) {
            $labels[] = date('M Y', strtotime($row['month'] . '-01'));
            $values[] = (float) $row['total_sales'];
        }

        return [
            'labels' => $labels,
            'values' => $values
        ];
    }

    /**
     * Get top selling products
     */
    private function getTopProducts($limit = 10)
    {
        $sql = "
            SELECT 
                p.product_name,
                v.variant_name,
                SUM(ii.quantity) as total_sold,
                SUM(ii.line_total) as total_revenue
            FROM invoice_items ii
            JOIN variants v ON ii.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            JOIN sales_invoices si ON ii.invoice_id = si.id
            WHERE si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY v.id
            ORDER BY total_sold DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get recent activities
     */
    private function getRecentActivities($limit = 20)
    {
        $activities = [];

        // Recent sales
        $sql = "
            SELECT 
                CONCAT('New sale: ', si.invoice_number) as description,
                CONCAT('Amount: ', si.total_amount) as details,
                si.created_at as created_at,
                u.full_name as user_name,
                'sale' as type
            FROM sales_invoices si
            LEFT JOIN users u ON si.created_by = u.id
            ORDER BY si.created_at DESC
            LIMIT 10
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $activities = array_merge($activities, $stmt->fetchAll());

        // Recent inventory movements
        $sql = "
            SELECT 
                CONCAT('Stock movement: ', it.transaction_type) as description,
                CONCAT('Quantity: ', it.quantity) as details,
                it.created_at as created_at,
                u.full_name as user_name,
                it.transaction_type as type
            FROM inventory_transactions it
            LEFT JOIN users u ON it.created_by = u.id
            ORDER BY it.created_at DESC
            LIMIT 10
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $activities = array_merge($activities, $stmt->fetchAll());

        // Recent customers
        $sql = "
            SELECT 
                CONCAT('New customer: ', full_name) as description,
                customer_code as details,
                created_at as created_at,
                NULL as user_name,
                'customer' as type
            FROM customers
            ORDER BY created_at DESC
            LIMIT 10
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $activities = array_merge($activities, $stmt->fetchAll());

        // Sort by created_at descending
        usort($activities, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return array_slice($activities, 0, $limit);
    }

    /**
     * Get inventory valuation
     */
    public function getInventoryValuation()
    {
        $sql = "
            SELECT 
                SUM(i.quantity * i.avg_landed_cost) as total_value,
                COUNT(DISTINCT i.variant_id) as total_items,
                SUM(i.quantity) as total_units
            FROM inventory i
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetch();
    }
}