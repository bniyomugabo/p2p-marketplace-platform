<?php
// api/dashboard/chart-data.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Sale.php';

header('Content-Type: application/json');
// Enable error logging but not display

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$companyId = $_SESSION['company_id'] ?? null;
$period = $_GET['period'] ?? 'week';

if (!$companyId) {
    echo json_encode(['success' => false, 'message' => 'Company context not found']);
    exit;
}

$saleModel = new Sale($companyId);
$labels = [];
$salesData = [];
$invoicesData = [];

if ($period === 'week') {
    // Get last 7 days
    $weeklySales = $saleModel->getWeeklyTrend();
    
    $last7Days = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dayName = date('D', strtotime("-$i days"));
        $last7Days[$date] = [
            'day' => $dayName,
            'sales' => 0,
            'invoices' => 0
        ];
    }
    
    foreach ($weeklySales as $day) {
        if (isset($day['date']) && isset($last7Days[$day['date']])) {
            $last7Days[$day['date']]['sales'] = (float) ($day['total_sales'] ?? $day['sales'] ?? 0);
            $last7Days[$day['date']]['invoices'] = (int) ($day['invoice_count'] ?? $day['invoices'] ?? 0);
        }
    }
    
    foreach ($last7Days as $dayData) {
        $labels[] = $dayData['day'];
        $salesData[] = $dayData['sales'];
        $invoicesData[] = $dayData['invoices'];
    }
    
} elseif ($period === 'month') {
    // Get last 4 weeks
    for ($i = 3; $i >= 0; $i--) {
        $weekStart = date('Y-m-d', strtotime("-$i weeks monday"));
        $weekEnd = date('Y-m-d', strtotime("-$i weeks sunday"));
        $weekNum = date('W', strtotime($weekStart));
        $labels[] = "Week $weekNum";
        
        $sql = "SELECT COALESCE(SUM(total_amount), 0) as sales, COUNT(*) as invoices 
                FROM sales_invoices 
                WHERE company_id = ? 
                AND invoice_date BETWEEN ? AND ?
                AND status != 'cancelled'";
        $stmt = $saleModel->getConnection()->prepare($sql);
        $stmt->execute([$companyId, $weekStart, $weekEnd]);
        $result = $stmt->fetch();
        
        $salesData[] = (float) ($result['sales'] ?? 0);
        $invoicesData[] = (int) ($result['invoices'] ?? 0);
    }
    
} elseif ($period === 'year') {
    // Get last 12 months
    for ($i = 11; $i >= 0; $i--) {
        $monthStart = date('Y-m-d', strtotime("-$i months", strtotime(date('Y-m-01'))));
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $monthName = date('M', strtotime($monthStart));
        $labels[] = $monthName;
        
        $sql = "SELECT COALESCE(SUM(total_amount), 0) as sales, COUNT(*) as invoices 
                FROM sales_invoices 
                WHERE company_id = ? 
                AND invoice_date BETWEEN ? AND ?
                AND status != 'cancelled'";
        $stmt = $saleModel->getConnection()->prepare($sql);
        $stmt->execute([$companyId, $monthStart, $monthEnd]);
        $result = $stmt->fetch();
        
        $salesData[] = (float) ($result['sales'] ?? 0);
        $invoicesData[] = (int) ($result['invoices'] ?? 0);
    }
}

echo json_encode([
    'success' => true,
    'labels' => $labels,
    'sales' => $salesData,
    'invoices' => $invoicesData
]);