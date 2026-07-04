<?php
// api/graph.php
// Dynamic graph data API
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';
require_once __DIR__ . '/../../models/Quotation.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Customer.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'] ?? 0;

if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'Company context not found']);
    exit;
}

// Get parameters
$type = $_GET['type'] ?? 'sales';
$period = $_GET['period'] ?? 'weekly';
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;
$year = isset($_GET['year']) ? (int) $_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : date('m');

try {
    $db = Database::getInstance();
    $response = [];

    switch ($type) {
        case 'sales':
            $response = getSalesGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month);
            break;
        case 'purchases':
            $response = getPurchasesGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month);
            break;
        case 'quotations':
            $response = getQuotationsGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month);
            break;
        case 'invoices':
            $response = getInvoicesGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month);
            break;
        case 'stock':
            $response = getStockGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month);
            break;
        case 'customer_purchases':
            $customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
            $response = getCustomerPurchasesGraphData($db, $companyId, $customerId, $period, $startDate, $endDate, $year, $month);
            break;
        default:
            $response = ['error' => 'Invalid graph type'];
    }

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Graph API error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get Sales Graph Data
 */
function getSalesGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month)
{
    $result = ['labels' => [], 'values' => [], 'counts' => [], 'currency' => getCurrency($db, $companyId)];

    switch ($period) {
        case 'daily':
            $date = date('Y-m-d');
            $sql = "
                SELECT 
                    HOUR(invoice_date) as hour,
                    COUNT(*) as invoice_count,
                    COALESCE(SUM(total_amount), 0) as total_sales
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND DATE(invoice_date) = :date
                    AND status != 'cancelled'
                GROUP BY HOUR(invoice_date)
                ORDER BY hour ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':date' => $date]);
            $data = $stmt->fetchAll();

            for ($i = 0; $i < 24; $i++) {
                $result['labels'][] = sprintf('%02d:00', $i);
                $found = false;
                foreach ($data as $row) {
                    if ((int)$row['hour'] === $i) {
                        $result['values'][] = (float) $row['total_sales'];
                        $result['counts'][] = (int) $row['invoice_count'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $result['values'][] = 0;
                    $result['counts'][] = 0;
                }
            }
            break;

        case 'weekly':
            $sql = "
                SELECT 
                    DATE(invoice_date) as date,
                    COUNT(*) as invoice_count,
                    COALESCE(SUM(total_amount), 0) as total_sales
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    AND status != 'cancelled'
                GROUP BY DATE(invoice_date)
                ORDER BY date ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId]);
            $data = $stmt->fetchAll();

            foreach ($data as $row) {
                $result['labels'][] = date('D, M d', strtotime($row['date']));
                $result['values'][] = (float) $row['total_sales'];
                $result['counts'][] = (int) $row['invoice_count'];
            }
            break;

        case 'monthly':
            $sql = "
                SELECT 
                    DAY(invoice_date) as day,
                    COUNT(*) as invoice_count,
                    COALESCE(SUM(total_amount), 0) as total_sales
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND MONTH(invoice_date) = :month
                    AND YEAR(invoice_date) = :year
                    AND status != 'cancelled'
                GROUP BY DAY(invoice_date)
                ORDER BY day ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':month' => $month, ':year' => $year]);
            $data = $stmt->fetchAll();

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['day']] = $row;
            }

            for ($i = 1; $i <= $daysInMonth; $i++) {
                $result['labels'][] = $i;
                if (isset($dataMap[$i])) {
                    $result['values'][] = (float) $dataMap[$i]['total_sales'];
                    $result['counts'][] = (int) $dataMap[$i]['invoice_count'];
                } else {
                    $result['values'][] = 0;
                    $result['counts'][] = 0;
                }
            }
            break;

        case 'yearly':
            $sql = "
                SELECT 
                    MONTH(invoice_date) as month,
                    COUNT(*) as invoice_count,
                    COALESCE(SUM(total_amount), 0) as total_sales
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND YEAR(invoice_date) = :year
                    AND status != 'cancelled'
                GROUP BY MONTH(invoice_date)
                ORDER BY month ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':year' => $year]);
            $data = $stmt->fetchAll();

            $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['month']] = $row;
            }

            for ($i = 1; $i <= 12; $i++) {
                $result['labels'][] = $monthNames[$i - 1];
                if (isset($dataMap[$i])) {
                    $result['values'][] = (float) $dataMap[$i]['total_sales'];
                    $result['counts'][] = (int) $dataMap[$i]['invoice_count'];
                } else {
                    $result['values'][] = 0;
                    $result['counts'][] = 0;
                }
            }
            break;

        case 'custom':
            if ($startDate && $endDate) {
                $sql = "
                    SELECT 
                        DATE(invoice_date) as date,
                        COUNT(*) as invoice_count,
                        COALESCE(SUM(total_amount), 0) as total_sales
                    FROM sales_invoices
                    WHERE company_id = :company_id
                        AND invoice_date BETWEEN :start_date AND :end_date
                        AND status != 'cancelled'
                    GROUP BY DATE(invoice_date)
                    ORDER BY date ASC
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':company_id' => $companyId,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate
                ]);
                $data = $stmt->fetchAll();

                foreach ($data as $row) {
                    $result['labels'][] = date('M d', strtotime($row['date']));
                    $result['values'][] = (float) $row['total_sales'];
                    $result['counts'][] = (int) $row['invoice_count'];
                }
            }
            break;
    }

    return $result;
}

/**
 * Get Purchases Graph Data
 */
function getPurchasesGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month)
{
    $result = ['labels' => [], 'values' => [], 'counts' => [], 'currency' => getCurrency($db, $companyId)];

    switch ($period) {
        case 'daily':
            $date = date('Y-m-d');
            $sql = "
                SELECT 
                    HOUR(order_date) as hour,
                    COUNT(*) as order_count,
                    COALESCE(SUM(total_amount), 0) as total_purchases
                FROM purchase_orders
                WHERE company_id = :company_id
                    AND DATE(order_date) = :date
                    AND status != 'cancelled'
                GROUP BY HOUR(order_date)
                ORDER BY hour ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':date' => $date]);
            $data = $stmt->fetchAll();

            for ($i = 0; $i < 24; $i++) {
                $result['labels'][] = sprintf('%02d:00', $i);
                $found = false;
                foreach ($data as $row) {
                    if ((int)$row['hour'] === $i) {
                        $result['values'][] = (float) $row['total_purchases'];
                        $result['counts'][] = (int) $row['order_count'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $result['values'][] = 0;
                    $result['counts'][] = 0;
                }
            }
            break;

        case 'weekly':
            $sql = "
                SELECT 
                    DATE(order_date) as date,
                    COUNT(*) as order_count,
                    COALESCE(SUM(total_amount), 0) as total_purchases
                FROM purchase_orders
                WHERE company_id = :company_id
                    AND order_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    AND status != 'cancelled'
                GROUP BY DATE(order_date)
                ORDER BY date ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId]);
            $data = $stmt->fetchAll();

            foreach ($data as $row) {
                $result['labels'][] = date('D, M d', strtotime($row['date']));
                $result['values'][] = (float) $row['total_purchases'];
                $result['counts'][] = (int) $row['order_count'];
            }
            break;

        case 'monthly':
            $sql = "
                SELECT 
                    DAY(order_date) as day,
                    COUNT(*) as order_count,
                    COALESCE(SUM(total_amount), 0) as total_purchases
                FROM purchase_orders
                WHERE company_id = :company_id
                    AND MONTH(order_date) = :month
                    AND YEAR(order_date) = :year
                    AND status != 'cancelled'
                GROUP BY DAY(order_date)
                ORDER BY day ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':month' => $month, ':year' => $year]);
            $data = $stmt->fetchAll();

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['day']] = $row;
            }

            for ($i = 1; $i <= $daysInMonth; $i++) {
                $result['labels'][] = $i;
                $result['values'][] = isset($dataMap[$i]) ? (float) $dataMap[$i]['total_purchases'] : 0;
                $result['counts'][] = isset($dataMap[$i]) ? (int) $dataMap[$i]['order_count'] : 0;
            }
            break;

        case 'yearly':
            $sql = "
                SELECT 
                    MONTH(order_date) as month,
                    COUNT(*) as order_count,
                    COALESCE(SUM(total_amount), 0) as total_purchases
                FROM purchase_orders
                WHERE company_id = :company_id
                    AND YEAR(order_date) = :year
                    AND status != 'cancelled'
                GROUP BY MONTH(order_date)
                ORDER BY month ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':year' => $year]);
            $data = $stmt->fetchAll();

            $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['month']] = $row;
            }

            for ($i = 1; $i <= 12; $i++) {
                $result['labels'][] = $monthNames[$i - 1];
                $result['values'][] = isset($dataMap[$i]) ? (float) $dataMap[$i]['total_purchases'] : 0;
                $result['counts'][] = isset($dataMap[$i]) ? (int) $dataMap[$i]['order_count'] : 0;
            }
            break;

        case 'custom':
            if ($startDate && $endDate) {
                $sql = "
                    SELECT 
                        DATE(order_date) as date,
                        COUNT(*) as order_count,
                        COALESCE(SUM(total_amount), 0) as total_purchases
                    FROM purchase_orders
                    WHERE company_id = :company_id
                        AND order_date BETWEEN :start_date AND :end_date
                        AND status != 'cancelled'
                    GROUP BY DATE(order_date)
                    ORDER BY date ASC
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':company_id' => $companyId,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate
                ]);
                $data = $stmt->fetchAll();

                foreach ($data as $row) {
                    $result['labels'][] = date('M d', strtotime($row['date']));
                    $result['values'][] = (float) $row['total_purchases'];
                    $result['counts'][] = (int) $row['order_count'];
                }
            }
            break;
    }

    return $result;
}

/**
 * Get Quotations Graph Data
 */
function getQuotationsGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month)
{
    $result = ['labels' => [], 'values' => [], 'counts' => [], 'converted' => [], 'currency' => getCurrency($db, $companyId)];

    switch ($period) {
        case 'daily':
            $date = date('Y-m-d');
            $sql = "
                SELECT 
                    HOUR(quotation_date) as hour,
                    COUNT(*) as quote_count,
                    COALESCE(SUM(total_amount), 0) as total_amount,
                    SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_count
                FROM quotations
                WHERE company_id = :company_id
                    AND DATE(quotation_date) = :date
                    AND status != 'rejected'
                GROUP BY HOUR(quotation_date)
                ORDER BY hour ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':date' => $date]);
            $data = $stmt->fetchAll();

            for ($i = 0; $i < 24; $i++) {
                $result['labels'][] = sprintf('%02d:00', $i);
                $found = false;
                foreach ($data as $row) {
                    if ((int)$row['hour'] === $i) {
                        $result['values'][] = (float) $row['total_amount'];
                        $result['counts'][] = (int) $row['quote_count'];
                        $result['converted'][] = (int) $row['converted_count'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $result['values'][] = 0;
                    $result['counts'][] = 0;
                    $result['converted'][] = 0;
                }
            }
            break;

        case 'weekly':
            $sql = "
                SELECT 
                    DATE(quotation_date) as date,
                    COUNT(*) as quote_count,
                    COALESCE(SUM(total_amount), 0) as total_amount,
                    SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_count
                FROM quotations
                WHERE company_id = :company_id
                    AND quotation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    AND status != 'cancelled'
                GROUP BY DATE(quotation_date)
                ORDER BY date ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId]);
            $data = $stmt->fetchAll();

            foreach ($data as $row) {
                $result['labels'][] = date('D, M d', strtotime($row['date']));
                $result['values'][] = (float) $row['total_amount'];
                $result['counts'][] = (int) $row['quote_count'];
                $result['converted'][] = (int) $row['converted_count'];
            }
            break;

        case 'monthly':
            $sql = "
                SELECT 
                    DAY(quotation_date) as day,
                    COUNT(*) as quote_count,
                    COALESCE(SUM(total_amount), 0) as total_amount,
                    SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_count
                FROM quotations
                WHERE company_id = :company_id
                    AND MONTH(quotation_date) = :month
                    AND YEAR(quotation_date) = :year
                    AND status != 'rejected'
                GROUP BY DAY(quotation_date)
                ORDER BY day ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':month' => $month, ':year' => $year]);
            $data = $stmt->fetchAll();

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['day']] = $row;
            }

            for ($i = 1; $i <= $daysInMonth; $i++) {
                $result['labels'][] = $i;
                if (isset($dataMap[$i])) {
                    $result['values'][] = (float) $dataMap[$i]['total_amount'];
                    $result['counts'][] = (int) $dataMap[$i]['quote_count'];
                    $result['converted'][] = (int) $dataMap[$i]['converted_count'];
                } else {
                    $result['values'][] = 0;
                    $result['counts'][] = 0;
                    $result['converted'][] = 0;
                }
            }
            break;

        case 'yearly':
            $sql = "
                SELECT 
                    MONTH(quotation_date) as month,
                    COUNT(*) as quote_count,
                    COALESCE(SUM(total_amount), 0) as total_amount,
                    SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_count
                FROM quotations
                WHERE company_id = :company_id
                    AND YEAR(quotation_date) = :year
                    AND status != 'cancelled'
                GROUP BY MONTH(quotation_date)
                ORDER BY month ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':year' => $year]);
            $data = $stmt->fetchAll();

            $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['month']] = $row;
            }

            for ($i = 1; $i <= 12; $i++) {
                $result['labels'][] = $monthNames[$i - 1];
                if (isset($dataMap[$i])) {
                    $result['values'][] = (float) $dataMap[$i]['total_amount'];
                    $result['counts'][] = (int) $dataMap[$i]['quote_count'];
                    $result['converted'][] = (int) $dataMap[$i]['converted_count'];
                } else {
                    $result['values'][] = 0;
                    $result['counts'][] = 0;
                    $result['converted'][] = 0;
                }
            }
            break;

        case 'custom':
            if ($startDate && $endDate) {
                $sql = "
                    SELECT 
                        DATE(quotation_date) as date,
                        COUNT(*) as quote_count,
                        COALESCE(SUM(total_amount), 0) as total_amount,
                        SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_count
                    FROM quotations
                    WHERE company_id = :company_id
                        AND quotation_date BETWEEN :start_date AND :end_date
                        AND status != 'cancelled'
                    GROUP BY DATE(quotation_date)
                    ORDER BY date ASC
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':company_id' => $companyId,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate
                ]);
                $data = $stmt->fetchAll();

                foreach ($data as $row) {
                    $result['labels'][] = date('M d', strtotime($row['date']));
                    $result['values'][] = (float) $row['total_amount'];
                    $result['counts'][] = (int) $row['quote_count'];
                    $result['converted'][] = (int) $row['converted_count'];
                }
            }
            break;
    }

    return $result;
}

/**
 * Get Invoices Graph Data
 */
function getInvoicesGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month)
{
    $result = ['labels' => [], 'paid' => [], 'pending' => [], 'overdue' => [], 'currency' => getCurrency($db, $companyId)];

    switch ($period) {
        case 'daily':
            $date = date('Y-m-d');
            $sql = "
                SELECT 
                    HOUR(invoice_date) as hour,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as paid_amount,
                    COALESCE(SUM(CASE WHEN status IN ('issued', 'partial') THEN total_amount - amount_paid ELSE 0 END), 0) as pending_amount,
                    COALESCE(SUM(CASE WHEN status = 'overdue' THEN total_amount - amount_paid ELSE 0 END), 0) as overdue_amount
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND DATE(invoice_date) = :date
                    AND status != 'cancelled'
                GROUP BY HOUR(invoice_date)
                ORDER BY hour ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':date' => $date]);
            $data = $stmt->fetchAll();

            for ($i = 0; $i < 24; $i++) {
                $result['labels'][] = sprintf('%02d:00', $i);
                $found = false;
                foreach ($data as $row) {
                    if ((int)$row['hour'] === $i) {
                        $result['paid'][] = (float) $row['paid_amount'];
                        $result['pending'][] = (float) $row['pending_amount'];
                        $result['overdue'][] = (float) $row['overdue_amount'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $result['paid'][] = 0;
                    $result['pending'][] = 0;
                    $result['overdue'][] = 0;
                }
            }
            break;

        case 'weekly':
            $sql = "
                SELECT 
                    DATE(invoice_date) as date,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as paid_amount,
                    COALESCE(SUM(CASE WHEN status IN ('issued', 'partial') THEN total_amount - amount_paid ELSE 0 END), 0) as pending_amount,
                    COALESCE(SUM(CASE WHEN status = 'overdue' THEN total_amount - amount_paid ELSE 0 END), 0) as overdue_amount
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    AND status != 'cancelled'
                GROUP BY DATE(invoice_date)
                ORDER BY date ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId]);
            $data = $stmt->fetchAll();

            foreach ($data as $row) {
                $result['labels'][] = date('D, M d', strtotime($row['date']));
                $result['paid'][] = (float) $row['paid_amount'];
                $result['pending'][] = (float) $row['pending_amount'];
                $result['overdue'][] = (float) $row['overdue_amount'];
            }
            break;

        case 'monthly':
            $sql = "
                SELECT 
                    DAY(invoice_date) as day,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as paid_amount,
                    COALESCE(SUM(CASE WHEN status IN ('issued', 'partial') THEN total_amount - amount_paid ELSE 0 END), 0) as pending_amount,
                    COALESCE(SUM(CASE WHEN status = 'overdue' THEN total_amount - amount_paid ELSE 0 END), 0) as overdue_amount
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND MONTH(invoice_date) = :month
                    AND YEAR(invoice_date) = :year
                    AND status != 'cancelled'
                GROUP BY DAY(invoice_date)
                ORDER BY day ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':month' => $month, ':year' => $year]);
            $data = $stmt->fetchAll();

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['day']] = $row;
            }

            for ($i = 1; $i <= $daysInMonth; $i++) {
                $result['labels'][] = $i;
                if (isset($dataMap[$i])) {
                    $result['paid'][] = (float) $dataMap[$i]['paid_amount'];
                    $result['pending'][] = (float) $dataMap[$i]['pending_amount'];
                    $result['overdue'][] = (float) $dataMap[$i]['overdue_amount'];
                } else {
                    $result['paid'][] = 0;
                    $result['pending'][] = 0;
                    $result['overdue'][] = 0;
                }
            }
            break;

        case 'yearly':
            $sql = "
                SELECT 
                    MONTH(invoice_date) as month,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as paid_amount,
                    COALESCE(SUM(CASE WHEN status IN ('issued', 'partial') THEN total_amount - amount_paid ELSE 0 END), 0) as pending_amount,
                    COALESCE(SUM(CASE WHEN status = 'overdue' THEN total_amount - amount_paid ELSE 0 END), 0) as overdue_amount
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND YEAR(invoice_date) = :year
                    AND status != 'cancelled'
                GROUP BY MONTH(invoice_date)
                ORDER BY month ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':year' => $year]);
            $data = $stmt->fetchAll();

            $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['month']] = $row;
            }

            for ($i = 1; $i <= 12; $i++) {
                $result['labels'][] = $monthNames[$i - 1];
                if (isset($dataMap[$i])) {
                    $result['paid'][] = (float) $dataMap[$i]['paid_amount'];
                    $result['pending'][] = (float) $dataMap[$i]['pending_amount'];
                    $result['overdue'][] = (float) $dataMap[$i]['overdue_amount'];
                } else {
                    $result['paid'][] = 0;
                    $result['pending'][] = 0;
                    $result['overdue'][] = 0;
                }
            }
            break;

        case 'custom':
            if ($startDate && $endDate) {
                $sql = "
                    SELECT 
                        DATE(invoice_date) as date,
                        COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as paid_amount,
                        COALESCE(SUM(CASE WHEN status IN ('issued', 'partial') THEN total_amount - amount_paid ELSE 0 END), 0) as pending_amount,
                        COALESCE(SUM(CASE WHEN status = 'overdue' THEN total_amount - amount_paid ELSE 0 END), 0) as overdue_amount
                    FROM sales_invoices
                    WHERE company_id = :company_id
                        AND invoice_date BETWEEN :start_date AND :end_date
                        AND status != 'cancelled'
                    GROUP BY DATE(invoice_date)
                    ORDER BY date ASC
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':company_id' => $companyId,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate
                ]);
                $data = $stmt->fetchAll();

                foreach ($data as $row) {
                    $result['labels'][] = date('M d', strtotime($row['date']));
                    $result['paid'][] = (float) $row['paid_amount'];
                    $result['pending'][] = (float) $row['pending_amount'];
                    $result['overdue'][] = (float) $row['overdue_amount'];
                }
            }
            break;
    }

    return $result;
}

/**
 * Get Stock Graph Data
 */
function getStockGraphData($db, $companyId, $period, $startDate, $endDate, $year, $month)
{
    $result = ['labels' => [], 'in_stock' => [], 'low_stock' => [], 'out_of_stock' => []];

    $sql = "
        SELECT 
            p.category_id,
            c.category_name,
            SUM(CASE WHEN i.available_quantity > v.reorder_level THEN 1 ELSE 0 END) as in_stock,
            SUM(CASE WHEN i.available_quantity <= v.reorder_level AND i.available_quantity > 0 THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN i.available_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock
        FROM inventory i
        JOIN variants v ON i.variant_id = v.id
        JOIN products p ON v.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.company_id = :company_id
        GROUP BY p.category_id, c.category_name
        ORDER BY c.category_name
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':company_id' => $companyId]);
    $data = $stmt->fetchAll();

    foreach ($data as $row) {
        $result['labels'][] = $row['category_name'] ?? 'Uncategorized';
        $result['in_stock'][] = (int) $row['in_stock'];
        $result['low_stock'][] = (int) $row['low_stock'];
        $result['out_of_stock'][] = (int) $row['out_of_stock'];
    }

    return $result;
}

/**
 * Get Customer Purchases Graph Data
 */
function getCustomerPurchasesGraphData($db, $companyId, $customerId, $period, $startDate, $endDate, $year, $month)
{
    $result = ['labels' => [], 'values' => [], 'counts' => [], 'currency' => getCurrency($db, $companyId)];

    if (!$customerId) {
        return $result;
    }

    switch ($period) {
        case 'daily':
            $date = date('Y-m-d');
            $sql = "
                SELECT 
                    HOUR(invoice_date) as hour,
                    COUNT(*) as invoice_count,
                    COALESCE(SUM(total_amount), 0) as total_purchases
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND customer_id = :customer_id
                    AND DATE(invoice_date) = :date
                    AND status != 'cancelled'
                GROUP BY HOUR(invoice_date)
                ORDER BY hour ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':customer_id' => $customerId, ':date' => $date]);
            $data = $stmt->fetchAll();

            for ($i = 0; $i < 24; $i++) {
                $result['labels'][] = sprintf('%02d:00', $i);
                $found = false;
                foreach ($data as $row) {
                    if ((int)$row['hour'] === $i) {
                        $result['values'][] = (float) $row['total_purchases'];
                        $result['counts'][] = (int) $row['invoice_count'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $result['values'][] = 0;
                    $result['counts'][] = 0;
                }
            }
            break;

        case 'weekly':
            $sql = "
                SELECT 
                    DATE(invoice_date) as date,
                    COUNT(*) as invoice_count,
                    COALESCE(SUM(total_amount), 0) as total_purchases
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND customer_id = :customer_id
                    AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    AND status != 'cancelled'
                GROUP BY DATE(invoice_date)
                ORDER BY date ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':customer_id' => $customerId]);
            $data = $stmt->fetchAll();

            foreach ($data as $row) {
                $result['labels'][] = date('D, M d', strtotime($row['date']));
                $result['values'][] = (float) $row['total_purchases'];
                $result['counts'][] = (int) $row['invoice_count'];
            }
            break;

        case 'monthly':
            $sql = "
                SELECT 
                    DAY(invoice_date) as day,
                    COUNT(*) as invoice_count,
                    COALESCE(SUM(total_amount), 0) as total_purchases
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND customer_id = :customer_id
                    AND MONTH(invoice_date) = :month
                    AND YEAR(invoice_date) = :year
                    AND status != 'cancelled'
                GROUP BY DAY(invoice_date)
                ORDER BY day ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':customer_id' => $customerId, ':month' => $month, ':year' => $year]);
            $data = $stmt->fetchAll();

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['day']] = $row;
            }

            for ($i = 1; $i <= $daysInMonth; $i++) {
                $result['labels'][] = $i;
                if (isset($dataMap[$i])) {
                    $result['values'][] = (float) $dataMap[$i]['total_purchases'];
                    $result['counts'][] = (int) $dataMap[$i]['invoice_count'];
                } else {
                    $result['values'][] = 0;
                    $result['counts'][] = 0;
                }
            }
            break;

        case 'yearly':
            $sql = "
                SELECT 
                    MONTH(invoice_date) as month,
                    COUNT(*) as invoice_count,
                    COALESCE(SUM(total_amount), 0) as total_purchases
                FROM sales_invoices
                WHERE company_id = :company_id
                    AND customer_id = :customer_id
                    AND YEAR(invoice_date) = :year
                    AND status != 'cancelled'
                GROUP BY MONTH(invoice_date)
                ORDER BY month ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':customer_id' => $customerId, ':year' => $year]);
            $data = $stmt->fetchAll();

            $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $dataMap = [];
            foreach ($data as $row) {
                $dataMap[(int)$row['month']] = $row;
            }

            for ($i = 1; $i <= 12; $i++) {
                $result['labels'][] = $monthNames[$i - 1];
                $result['values'][] = isset($dataMap[$i]) ? (float) $dataMap[$i]['total_purchases'] : 0;
                $result['counts'][] = isset($dataMap[$i]) ? (int) $dataMap[$i]['invoice_count'] : 0;
            }
            break;

        case 'custom':
            if ($startDate && $endDate) {
                $sql = "
                    SELECT 
                        DATE(invoice_date) as date,
                        COUNT(*) as invoice_count,
                        COALESCE(SUM(total_amount), 0) as total_purchases
                    FROM sales_invoices
                    WHERE company_id = :company_id
                        AND customer_id = :customer_id
                        AND invoice_date BETWEEN :start_date AND :end_date
                        AND status != 'cancelled'
                    GROUP BY DATE(invoice_date)
                    ORDER BY date ASC
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':company_id' => $companyId,
                    ':customer_id' => $customerId,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate
                ]);
                $data = $stmt->fetchAll();

                foreach ($data as $row) {
                    $result['labels'][] = date('M d', strtotime($row['date']));
                    $result['values'][] = (float) $row['total_purchases'];
                    $result['counts'][] = (int) $row['invoice_count'];
                }
            }
            break;
    }

    return $result;
}

/**
 * Get company currency
 */
function getCurrency($db, $companyId)
{
    $stmt = $db->prepare("SELECT currency FROM companies WHERE id = :id");
    $stmt->execute([':id' => $companyId]);
    $result = $stmt->fetch();
    return $result ? $result['currency'] : 'RWF';
}
?>