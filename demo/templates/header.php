<?php
// templates/header.php
declare(strict_types=1);

// Check if current page is dashboard
$isDashboard = ($_GET['page'] ?? '') === 'dashboard';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Sati - Inventory Management System'); ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo asset_url('favicon.ico'); ?>">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo asset_url('css/style.css'); ?>">
    
    <!-- Dashboard specific CSS -->
    <?php if ($isDashboard): ?>
    <link rel="stylesheet" href="<?php echo asset_url('css/dashboard.css'); ?>">
    <?php endif; ?>
    
    <!-- Page-specific CSS -->
    <?php if (isset($cssFiles)): ?>
        <?php foreach ($cssFiles as $cssFile): ?>
            <link rel="stylesheet" href="<?php echo asset_url("css/{$cssFile}"); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- PWA Meta Tags (keep these in head) -->
    <link rel="manifest" href="./manifest.json">
    <meta name="theme-color" content="#4e73df">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SATI ERP">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192x192.png">
</head>
<body class="<?php echo $isDashboard ? 'dashboard-body' : ''; ?>">
    <!-- Loading Spinner -->
    <div id="loading-spinner" class="d-none">
        <div class="spinner-overlay">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    </div>

    <!-- Notification Toast Container -->
    <div aria-live="polite" aria-atomic="true" class="position-relative">
        <div class="toast-container position-fixed top-0 end-0 p-3" id="toast-container"></div>
    </div>

    <div class="container-fluid app-container <?php echo $isDashboard ? 'dashboard-container' : 'regular-container'; ?>">
        <div class="row">