<?php
// /src/api/check_email.php
// Check if email already exists

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$email = isset($_GET['email']) ? trim($_GET['email']) : '';

if (empty($email)) {
    jsonResponse(['exists' => false, 'success' => true]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['exists' => false, 'success' => true, 'valid' => false]);
}

try {
    $db = Database::getInstance();
    
    $sql = "SELECT COUNT(*) as count FROM customers WHERE email = :email AND company_id = 0";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':email', $email);
    $stmt->execute();
    
    $result = $stmt->fetch();
    
    jsonResponse([
        'exists' => $result['count'] > 0,
        'success' => true
    ]);
    
} catch (PDOException $e) {
    error_log("Check email error: " . $e->getMessage());
    jsonResponse(['exists' => false, 'success' => false]);
}