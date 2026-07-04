<?php
// /src/api/clear_logs.php
// Clear logs (should be protected)

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../init.php';

// Simple IP restriction for security
$allowedIps = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIps) && !DEBUG_MODE) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$type = $_GET['type'] ?? 'error';

$logger = Logger::getInstance();
$logger->clearLogs($type);

echo json_encode(['success' => true, 'message' => 'Logs cleared']);