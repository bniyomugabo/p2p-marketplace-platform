<?php
// pages/products/delete_variant.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Variant.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to delete variants.');
    header('Location: ' . route_url('products'));
    exit;
}

$variantId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$variantId) {
    SessionManager::flash('error', 'Variant ID is required.');
    header('Location: ' . route_url('products'));
    exit;
}

try {
    $variantModel = new Variant();
    $variant = $variantModel->find($variantId);

    if (!$variant) {
        throw new Exception('Variant not found.');
    }

    $productId = $variant['product_id'];

    // Soft delete the variant
    $variantModel->update($variantId, ['is_active' => 0]);

    SessionManager::flash('success', 'Variant deleted successfully.');
    header('Location: ' . route_url('products/view', ['id' => $productId]));
    exit;

} catch (Exception $e) {
    error_log("Variant deletion error: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to delete variant: ' . $e->getMessage());
    header('Location: ' . route_url('products'));
    exit;
}