<?php
// templates/sidebar.php
declare(strict_types=1);

// Check if current page is dashboard
$isDashboard = ($_GET['page'] ?? '') === 'dashboard';
// Get user permissions
$userPermissions = UserPermission::getAllowedPages($_SESSION['user_id'] ?? 0);
$currentPage = $_GET['page'] ?? 'dashboard';
$activeModule = $_SESSION['active_module'] ?? 'dashboard';

// Get user role for permission checking
$userRole = $_SESSION['user_role'] ?? 'VIW';

// Check if sidebar is collapsed from cookie/localStorage (default: false)
$sidebarCollapsed = isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] === 'true';
$sidebarClass = $sidebarCollapsed ? 'sidebar-collapsed' : '';

// Navigation menu structure (keep as is)
$menuItems = [
    'dashboard' => [
        'title' => 'Dashboard',
        'icon' => 'fas fa-tachometer-alt',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],
    'products' => [
        'title' => 'Products',
        'icon' => 'fas fa-boxes',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW'],
        'submenu' => [
            ['page' => 'products', 'title' => 'All Products', 'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']],
            ['page' => 'products/add', 'title' => 'Add Product', 'permissions' => ['ADM', 'MGR']],
            ['page' => 'products/categories', 'title' => 'Categories', 'permissions' => ['ADM', 'MGR']]
        ]
    ],
    'inventory' => [
        'title' => 'Inventory',
        'icon' => 'fas fa-warehouse',
        'permissions' => ['ADM', 'MGR', 'VIW'],
        'submenu' => [
            ['page' => 'inventory', 'title' => 'Dashboard', 'permissions' => ['ADM', 'MGR', 'VIW']],
            ['page' => 'inventory/stock', 'title' => 'Current Stock', 'permissions' => ['ADM', 'MGR', 'VIW']],
            ['page' => 'inventory/movements', 'title' => 'Movements', 'permissions' => ['ADM', 'MGR']],
            ['page' => 'inventory/adjustments', 'title' => 'Adjustments', 'permissions' => ['ADM', 'MGR']],
            ['page' => 'inventory/warehouse', 'title' => 'Warehouses', 'permissions' => ['ADM', 'MGR']]
        ]
    ],
    'sales' => [
        'title' => 'Sales',
        'icon' => 'fas fa-shopping-cart',
        'permissions' => ['ADM', 'MGR', 'SEL'],
        'submenu' => [
            ['page' => 'sales', 'title' => 'Dashboard', 'permissions' => ['ADM', 'MGR', 'SEL']],
            ['page' => 'sales/create', 'title' => 'Create Sale', 'permissions' => ['ADM', 'MGR', 'SEL']],
            ['page' => 'sales/invoices', 'title' => 'Invoices', 'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']],
            ['page' => 'sales/returns', 'title' => 'Returns', 'permissions' => ['ADM', 'MGR', 'SEL']],
            ['page' => 'sales/customers', 'title' => 'Customers', 'permissions' => ['ADM', 'MGR', 'SEL']]
        ]
    ],
    'quotations' => [
        'title' => 'Quotations',
        'icon' => 'fas fa-file-invoice',
        'permissions' => ['ADM', 'MGR', 'SEL'],
        'submenu' => [
            ['page' => 'quotations', 'title' => 'Dashboard', 'permissions' => ['ADM', 'MGR', 'SEL']],
            ['page' => 'quotations/create', 'title' => 'Create Quotation', 'permissions' => ['ADM', 'MGR', 'SEL']],
        ]
    ],
    'purchasing' => [
        'title' => 'Purchasing',
        'icon' => 'fas fa-truck-loading',
        'permissions' => ['ADM', 'MGR'],
        'submenu' => [
            ['page' => 'purchasing', 'title' => 'Dashboard', 'permissions' => ['ADM', 'MGR']],
            ['page' => 'purchasing/orders', 'title' => 'Purchase Orders', 'permissions' => ['ADM', 'MGR']],
            ['page' => 'purchasing/suppliers', 'title' => 'Suppliers', 'permissions' => ['ADM', 'MGR']],
            ['page' => 'purchasing/receiving', 'title' => 'Goods Receiving', 'permissions' => ['ADM', 'MGR']]
        ]
    ],
    'reports' => [
        'title' => 'Reports',
        'icon' => 'fas fa-chart-bar',
        'permissions' => ['ADM', 'MGR', 'VIW'],
        'submenu' => [
            ['page' => 'reports', 'title' => 'Dashboard', 'permissions' => ['ADM', 'MGR', 'VIW']],
            ['page' => 'reports/products', 'title' => 'Product Reports', 'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']],
            ['page' => 'reports/sales', 'title' => 'Sales Reports', 'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']],
            ['page' => 'reports/inventory', 'title' => 'Inventory Reports', 'permissions' => ['ADM', 'MGR', 'VIW']],
            ['page' => 'reports/financial', 'title' => 'Financial Reports', 'permissions' => ['ADM', 'MGR']],
            ['page' => 'reports/customers', 'title' => 'Customer Reports', 'permissions' => ['ADM', 'MGR', 'SEL']]
        ]
    ],
    'admin' => [
        'title' => 'Administration',
        'icon' => 'fas fa-cogs',
        'permissions' => ['ADM'],
        'submenu' => [
            ['page' => 'admin/users', 'title' => 'User Management', 'permissions' => ['ADM']],
            ['page' => 'admin/roles', 'title' => 'Role Management', 'permissions' => ['ADM']],
            ['page' => 'admin/settings', 'title' => 'System Settings', 'permissions' => ['ADM']]
        ]
    ]
];

// Role labels for display
$roleLabels = [
    'ADM' => 'Administrator',
    'MGR' => 'Manager',
    'SEL' => 'Sales',
    'VIW' => 'Viewer',
    'ACC' => 'Accountant',
    'WHS' => 'Warehouse Staff'
];
?>

<!-- Sidebar Navigation -->
<nav id="sidebar" class="col-md-3 col-lg-2 d-md-block bg-black sidebar <?php echo $sidebarClass; ?> demo-sidebar">
    <div class="position-sticky pt-3 h-100 d-flex flex-column">
        <!-- Brand Logo with Toggle Button -->
        <div class="sidebar-brand d-flex align-items-center justify-content-between mb-4 px-3">
            <div class="d-flex align-items-center">
                <i class="fas fa-warehouse fa-2x text-primary me-2"></i>
                <span class="fs-5 fw-bold text-white brand-text">SATI</span>
            </div>
            <button class="btn btn-sm btn-link text-white sidebar-collapse-btn" id="sidebarCollapseBtn" title="Collapse Sidebar">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>

        <!-- User Profile -->
        <div class="user-profile text-center mb-4 p-3  rounded">
            <div class="user-avatar mb-2">
                <i class="fas fa-user-circle fa-3x text-secondary"></i>
            </div>
            <h6 class="text-white mb-1 user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></h6>
            <small class="text-muted user-role">
                <?php echo $roleLabels[$userRole] ?? 'User'; ?>
            </small>
        </div>

       <!-- Navigation Menu - Scrollable -->
        <div class="sidebar-menu flex-grow-1 overflow-auto">
            <ul class="nav flex-column">
                <?php foreach ($menuItems as $module => $menuItem): ?>
                    <?php 
                    // Check if user has permission for this module
                    if (!in_array($userRole, $menuItem['permissions'])) continue;
                    
                    $isActive = ($activeModule === $module);
                    $hasSubmenu = isset($menuItem['submenu']);
                    ?>
                    
                    <li class="nav-item">
                        <?php if ($hasSubmenu): ?>
                            <a class="nav-link <?php echo $isActive ? 'active' : ''; ?>" 
                            data-bs-toggle="collapse" 
                            href="#submenu-<?php echo $module; ?>" 
                            role="button"
                            aria-expanded="<?php echo $isActive ? 'true' : 'false'; ?>">
                                <i class="<?php echo $menuItem['icon']; ?> me-2"></i>
                                <span class="nav-text"><?php echo $menuItem['title']; ?></span>
                                <i class="fas fa-chevron-down float-end mt-1"></i>
                            </a>
                            
                            <div class="collapse <?php echo $isActive ? 'show' : ''; ?>" id="submenu-<?php echo $module; ?>">
                                <ul class="nav flex-column ms-4">
                                    <?php foreach ($menuItem['submenu'] as $subitem): ?>
                                        <?php 
                                        // Check submenu item permissions
                                        if (!in_array($userRole, $subitem['permissions'])) continue;
                                        
                                        $isSubActive = ($currentPage === $subitem['page']);
                                        ?>
                                        <li class="nav-item">
                                            <a class="nav-link <?php echo $isSubActive ? 'active' : ''; ?>" 
                                            href="<?php echo route_url($subitem['page']); ?>">
                                                <span class="nav-text"><?php echo $subitem['title']; ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <a class="nav-link <?php echo $isActive ? 'active' : ''; ?>" 
                            href="<?php echo route_url($module); ?>">
                                <i class="<?php echo $menuItem['icon']; ?> me-2"></i>
                                <span class="nav-text"><?php echo $menuItem['title']; ?></span>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                
                <!-- Quick Actions -->
                <li class="nav-item mt-4">
                    <div class="nav-link text-uppercase small text-muted">
                        <i class="fas fa-bolt me-2"></i>
                        <span class="nav-text">Quick Actions</span>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo route_url('sales/create'); ?>">
                        <i class="fas fa-cash-register me-2"></i>
                        <span class="nav-text">New Sale</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo route_url('products/add'); ?>">
                        <i class="fas fa-plus-circle me-2"></i>
                        <span class="nav-text">Add Product</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo route_url('inventory/adjustments'); ?>">
                        <i class="fas fa-sliders me-2"></i>
                        <span class="nav-text">Adjust Stock</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" id="installAppBtn">
                        <i class="fas fa-download me-2"></i>
                        <span class="nav-text">Install App</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Sidebar Footer -->
        <div class="sidebar-footer pt-3 border-top border-secondary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <small class="text-muted">Version 1.0.0</small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-light" id="toggle-theme" title="Toggle theme">
                        <i class="fas fa-moon"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Main Content Area -->
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" id="main-content">
   <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom py-2" id="top-navbar">
        <div class="container-fluid">
            <!-- Toggle Sidebar Button -->
            <button class="btn btn-outline-secondary me-2" type="button" id="sidebarToggleBtn" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            
            <!-- Quick Search - Always Visible -->
            <div class="quick-search-wrapper me-3">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="fas fa-search text-muted"></i>
                    </span>
                    <input type="text" class="form-control border-start-0 ps-0" id="quickSearchInput" 
                        placeholder="Search products, customers, orders..." 
                        style="min-width: 250px;">
                    <button class="btn btn-primary" type="button" id="quickSearchBtn">
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
                <div id="quickSearchResults" class="quick-search-results" style="display: none;"></div>
            </div>
            
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="me-auto">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item">
                        <a href="<?php echo route_url('dashboard'); ?>">
                            <i class="fas fa-home"></i>
                        </a>
                    </li>
                    <?php 
                    $breadcrumbs = explode('/', $currentPage);
                    $breadcrumbPath = '';
                    foreach ($breadcrumbs as $index => $crumb):
                        $breadcrumbPath .= ($index > 0 ? '/' : '') . $crumb;
                        $isLast = ($index === count($breadcrumbs) - 1);
                        
                        // Skip empty crumb
                        if (empty($crumb)) continue;
                    ?>
                        <li class="breadcrumb-item <?php echo $isLast ? 'active' : ''; ?>">
                            <?php if (!$isLast): ?>
                                <a href="<?php echo route_url($breadcrumbPath); ?>">
                                    <?php echo ucfirst(str_replace(['_', '-'], ' ', $crumb)); ?>
                                </a>
                            <?php else: ?>
                                <?php echo ucfirst(str_replace(['_', '-'], ' ', $crumb)); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
            
            <!-- Top Right Actions -->
            <div class="d-flex align-items-center">
                <!-- Notifications -->
                <!-- A subtle, outlined emerald button style -->
                    <a href="../app" class="btn btn-real-mode nav-link d-flex align-items-center">
                        <i class="fas fa-eye me-2"></i> 
                        <span class="nav-text">change mode</span>
                    </a>
                <div class="dropdown me-3">
                    <button class="btn btn-outline-secondary position-relative" type="button" data-bs-toggle="dropdown" id="notificationBell" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notificationBadge" style="display: none;">
                            0
                            <span class="visually-hidden">unread notifications</span>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end" style="width: 350px;" id="notificationDropdown">
                        <h6 class="dropdown-header d-flex justify-content-between align-items-center">
                            <span>Notifications</span>
                            <button class="btn btn-sm btn-link" onclick="markAllAsRead()" id="markAllReadBtn">Mark all as read</button>
                        </h6>
                        <div id="notificationList">
                            <div class="text-center py-3">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="text-muted small mt-2 mb-0">Loading notifications...</p>
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center" href="?page=notifications">
                            View all notifications
                        </a>
                    </div>
                </div>
                
                <!-- User Dropdown -->
                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center" 
                            type="button" 
                            data-bs-toggle="dropdown"
                            id="userDropdown"
                            title="User menu">
                        <i class="fas fa-user-circle me-2"></i>
                        <span class="d-none d-md-inline"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="<?php echo route_url('profile'); ?>">
                                <i class="fas fa-user me-2"></i>My Profile
                            </a>
                        </li>
                       
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>auth/logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </li>

                    </ul>
                </div>
            </div>
        </div>
    </nav>
        
    <!-- Page Content -->
    <div class="container-fluid py-4 <?php echo $isDashboard ? 'dashboard-content' : 'regular-content'; ?>">