<?php
// /src/api/sell_item.php
// API to create new P2P listing

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');

$customer = SessionManager::getCustomer();

if (!$customer) {
    jsonResponse(['success' => false, 'message' => 'Please login to sell items']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed']);
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !SessionManager::verifyCsrfToken($_POST['csrf_token'])) {
    jsonResponse(['success' => false, 'message' => 'Security validation failed']);
}

// Validate required fields
$required = ['title', 'description', 'price', 'condition', 'city', 'global_category_id', 'store_id'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        jsonResponse(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
    }
}

$title = trim($_POST['title']);
$description = trim($_POST['description']);
$price = (float)$_POST['price'];
$condition = $_POST['condition'];
$city = trim($_POST['city']);
$categoryId = (int)$_POST['global_category_id'];
$storeId = (int)$_POST['store_id'];
$stockQuantity = (int)($_POST['stock_quantity'] ?? 1);

// Validate price
if ($price < 100) {
    jsonResponse(['success' => false, 'message' => 'Price must be at least 100 RWF']);
}

// Validate condition
$validConditions = ['new', 'like_new', 'good', 'fair'];
if (!in_array($condition, $validConditions)) {
    jsonResponse(['success' => false, 'message' => 'Invalid condition']);
}

// Handle image uploads
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/p2p/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Upload primary image
$primaryImage = null;
if (isset($_FILES['primary_image']) && $_FILES['primary_image']['error'] === UPLOAD_ERR_OK) {
    $primaryImage = uploadImage($_FILES['primary_image'], $uploadDir);
    if (!$primaryImage) {
        jsonResponse(['success' => false, 'message' => 'Failed to upload primary image']);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'Primary image is required']);
}

// Upload additional images
$additionalImages = [];
if (isset($_FILES['additional_images'])) {
    foreach ($_FILES['additional_images']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['additional_images']['error'][$key] === UPLOAD_ERR_OK) {
            $file = [
                'name' => $_FILES['additional_images']['name'][$key],
                'tmp_name' => $tmpName,
                'type' => $_FILES['additional_images']['type'][$key],
                'size' => $_FILES['additional_images']['size'][$key]
            ];
            $uploaded = uploadImage($file, $uploadDir);
            if ($uploaded) {
                $additionalImages[] = $uploaded;
            }
        }
    }
}

// Generate slug
$slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title))) . '-' . time();

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $sql = "INSERT INTO customer_store_products 
            (customer_store_id, global_category_id, title, slug, description, price, `condition`, 
             stock_quantity, primary_image_url, additional_images, city, created_at) 
            VALUES 
            (:store_id, :category_id, :title, :slug, :description, :price, :condition, 
             :stock, :primary_image, :additional_images, :city, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT);
    $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $stmt->bindValue(':title', $title);
    $stmt->bindValue(':slug', $slug);
    $stmt->bindValue(':description', $description);
    $stmt->bindValue(':price', $price);
    $stmt->bindValue(':condition', $condition);
    $stmt->bindValue(':stock', $stockQuantity, PDO::PARAM_INT);
    $stmt->bindValue(':primary_image', $primaryImage);
    $stmt->bindValue(':additional_images', json_encode($additionalImages));
    $stmt->bindValue(':city', $city);
    $stmt->execute();
    
    jsonResponse([
        'success' => true,
        'message' => 'Item listed successfully',
        'product_id' => $conn->lastInsertId()
    ]);
    
} catch (PDOException $e) {
    error_log("Sell API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

function uploadImage($file, $uploadDir) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        return false;
    }
    
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        return false;
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $extension;
    $destination = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return '/uploads/p2p/' . $filename;
    }
    
    return false;
}