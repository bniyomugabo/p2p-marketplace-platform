<?php
// config/routes.php
// ============================================
// APPLICATION ROUTING CONFIGURATION
// ============================================


return [
    // ============================================
    // DASHBOARD ROUTES
    // ============================================
    'dashboard' => [
        'handler' => 'pages/dashboard/main.php',
        'title' => 'Dashboard',
        'menu' => true,
        'icon' => 'fas fa-tachometer-alt',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],
    'notifications' => [
        'handler' => 'pages/index.php',
        'title' => 'Notifications',
        'menu' => true,
        'icon' => 'fas fa-bell',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    // ============================================
    // PRODUCT MODULE ROUTES
    // ============================================
    'products' => [
        'handler' => 'pages/products/list.php',
        'title' => 'Product List',
        'menu' => true,
        'icon' => 'fas fa-boxes',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'products/add' => [
        'handler' => 'pages/products/add.php',
        'title' => 'Add New Product',
        'menu' => false,
        'permissions' => ['ADM', 'MGR']
    ],
    'products/add-variant' => [
        'handler' => 'pages/products/add_variant.php',
        'title' => 'Add New Product Variant',
        'menu' => false,
        'permissions' => ['ADM', 'MGR']
    ],

    'products/stock-adjust-variant' => [
        'handler' => 'pages/products/stock_adjust_variant.php',
        'title' => 'Stock Adjustment for Variant',
        'menu' => false,
        'permissions' => ['ADM', 'MGR']
    ],
    'products/stock-movements-variant' => [
        'handler' => 'pages/products/stock_movements_variant.php',
        'title' => 'Stock Movements for Variant',
        'menu' => false,
        'permissions' => ['ADM', 'MGR']
    ],

    'products/edit' => [
        'handler' => 'pages/products/edit.php',
        'title' => 'Edit Product',
        'menu' => false,
        'permissions' => ['ADM', 'MGR']
    ],
    'products/edit-variant' => [
        'handler' => 'pages/products/edit_variant.php',
        'title' => 'Edit Product Variant',
        'menu' => false,
        'permissions' => ['ADM', 'MGR']
    ],


    'products/view' => [
        'handler' => 'pages/products/view.php',
        'title' => 'View Product',
        'menu' => false,
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'products/view-variant' => [
        'handler' => 'pages/products/view_variant.php',
        'title' => 'View Product Variant',
        'menu' => false,
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'products/categories' => [
        'handler' => 'pages/products/categories.php',
        'title' => 'Product Categories',
        'menu' => true,
        'icon' => 'fas fa-tags',
        'permissions' => ['ADM', 'MGR']
    ],
    'products/delete' => [
        'handler' => 'pages/products/delete.php',
        'title' => 'Delete Product',
        'menu' => false,
        'permissions' => ['ADM', 'MGR']
    ],
    'products/delete-variant' => [
        'handler' => 'pages/products/delete_variant.php',
        'title' => 'Delete Product Variant',
        'menu' => false,
        'permissions' => ['ADM', 'MGR']
    ],

    // ============================================
    // INVENTORY MODULE ROUTES
    // ============================================
    'inventory' => [
        'handler' => 'pages/inventory/dashboard.php',
        'title' => 'Inventory Dashboard',
        'menu' => true,
        'icon' => 'fas fa-warehouse',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],


    'inventory/stock-count' => [
        'handler' => 'pages/inventory/stock-count.php',
        'title' => 'Stock Count',
        'menu' => true,
        'icon' => 'fas fa-warehouse',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'inventory/stock' => [
        'handler' => 'pages/inventory/stock.php',
        'title' => 'Current Stock',
        'menu' => true,
        'icon' => 'fas fa-boxes-stacked',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'inventory/movements' => [
        'handler' => 'pages/inventory/movements.php',
        'title' => 'Stock Movements',
        'menu' => true,
        'icon' => 'fas fa-arrows-left-right',
        'permissions' => ['ADM', 'MGR']
    ],

    'inventory/adjustments' => [
        'handler' => 'pages/inventory/adjustments.php',
        'title' => 'Stock Adjustments',
        'menu' => true,
        'icon' => 'fas fa-sliders',
        'permissions' => ['ADM', 'MGR']
    ],

    'inventory/transfers' => [
        'handler' => 'pages/inventory/transfers.php',
        'title' => 'Stock Transfers',
        'menu' => true,
        'icon' => 'fas fa-truck-arrow-right',
        'permissions' => ['ADM', 'MGR']
    ],
    'inventory/warehouse' => [
        'handler' => 'pages/inventory/warehouse.php',
        'title' => 'Warehouse Management',
        'menu' => true,
        'icon' => 'fas fa-warehouse',
        'permissions' => ['ADM', 'MGR']
    ],
    'inventory/storage-locations' => [
        'handler' => 'pages/inventory/storage-locations.php',
        'title' => 'Storage Locations',
        'menu' => true,
        'icon' => 'fas fa-map-marker-alt',
        'permissions' => ['ADM', 'MGR']
    ],

    // ============================================
    // SALES MODULE ROUTES
    // ============================================
    'sales' => [
        'handler' => 'pages/sales/dashboard.php',
        'title' => 'Sales Dashboard',
        'menu' => true,
        'icon' => 'fas fa-shopping-cart',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],

    'sales/create' => [
        'handler' => 'pages/sales/create.php',
        'title' => 'Create Sale',
        'menu' => true,
        'icon' => 'fas fa-plus-circle',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],

    'sales/invoices' => [
        'handler' => 'pages/sales/invoices.php',
        'title' => 'Sales Invoices',
        'menu' => true,
        'icon' => 'fas fa-file-invoice',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],
    'sales/view-invoice' => [
        'handler' => 'pages/sales/view-invoice.php',
        'title' => 'View Sales Invoice',
        'menu' => true,
        'icon' => 'fas fa-file-invoice',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'sales/print-invoice' => [
        'handler' => 'pages/sales/print-invoice.php',
        'title' => 'Print Sales Invoice',
        'menu' => true,
        'icon' => 'fas fa-file-invoice',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'sales/customers' => [
        'handler' => 'pages/sales/customers.php',
        'title' => 'Customers',
        'menu' => true,
        'icon' => 'fas fa-users',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],

    'sales/view-customer' => [
        'handler' => 'pages/sales/view-customer.php',
        'title' => 'View Customers',
        'menu' => true,
        'icon' => 'fas fa-users',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],


    'sales/save-customer' => [
        'handler' => 'pages/sales/save-customer.php',
        'title' => 'Save Customer',
        'menu' => true,
        'icon' => 'fas fa-user-plus',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],

    'sales/update-customer' => [
        'handler' => 'pages/sales/update-customer.php',
        'title' => 'Update Customer',
        'menu' => true,
        'icon' => 'fas fa-user-edit',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],

    'sales/returns' => [
        'handler' => 'pages/sales/returns.php',
        'title' => 'Sales Returns',
        'menu' => true,
        'icon' => 'fas fa-undo',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],

    // ============================================
    // QUOTATIONS MODULE ROUTES
    // ============================================

    'quotations' => [
        'handler' => 'pages/quotations/list.php',
        'title' => 'Quotations',
        'menu' => true,
        'icon' => 'fas fa-file-contract',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],
    'quotations/create' => [
        'handler' => 'pages/quotations/create.php',
        'title' => 'Create Quotation',
        'menu' => false,
        'icon' => 'fas fa-file-contract',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],
    'quotations/delete' => [
        'handler' => 'pages/quotations/delete.php',
        'title' => 'Delete Quotation',
        'menu' => false,
        'icon' => 'fas fa-file-contract',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],
    'quotations/view' => [
        'handler' => 'pages/quotations/view.php',
        'title' => 'View Quotation',
        'menu' => false,
        'icon' => 'fas fa-file-contract',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],
    'quotations/print' => [
        'handler' => 'pages/quotations/print.php',
        'title' => 'Print Quotation',
        'menu' => false,
        'icon' => 'fas fa-print',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],
    'quotations/edit' => [
        'handler' => 'pages/quotations/edit.php',
        'title' => 'Edit Quotation',
        'menu' => false,
        'icon' => 'fas fa-file-contract',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],

    // ============================================
    // PURCHASING MODULE ROUTES
    // ============================================
    'purchasing' => [
        'handler' => 'pages/purchasing/dashboard.php',
        'title' => 'Purchasing Dashboard',
        'menu' => true,
        'icon' => 'fas fa-truck-loading',
        'permissions' => ['ADM', 'MGR']
    ],

    'purchasing/orders' => [
        'handler' => 'pages/purchasing/orders.php',
        'title' => 'Purchase Orders',
        'menu' => true,
        'icon' => 'fas fa-clipboard-list',
        'permissions' => ['ADM', 'MGR']
    ],

    'purchasing/create-order' => [
        'handler' => 'pages/purchasing/create-order.php',
        'title' => 'Create Purchase Order',
        'menu' => false,
        'icon' => 'fas fa-plus-circle',
        'permissions' => ['ADM', 'MGR']
    ],
    'purchasing/edit-order' => [
        'handler' => 'pages/purchasing/edit-order.php',
        'title' => 'Edit Purchase Order',
        'menu' => false,
        'icon' => 'fas fa-edit',
        'permissions' => ['ADM', 'MGR']
    ],
    'purchasing/update-order-status' => [
        'handler' => 'pages/purchasing/update-order-status.php',
        'title' => 'Update Order Status',
        'menu' => false,
        'icon' => 'fas fa-sync',
        'permissions' => ['ADM', 'MGR']
    ],
    'purchasing/view-order' => [
        'handler' => 'pages/purchasing/view-order.php',
        'title' => 'View Purchase Order',
        'menu' => false,
        'icon' => 'fas fa-eye',
        'permissions' => ['ADM', 'MGR']
    ],

    'purchasing/suppliers' => [
        'handler' => 'pages/purchasing/suppliers.php',
        'title' => 'Suppliers',
        'menu' => true,
        'icon' => 'fas fa-parachute-box',
        'permissions' => ['ADM', 'MGR']
    ],
    'purchasing/supplier-details' => [
        'handler' => 'pages/purchasing/supplier-details.php',
        'title' => 'Supplier Details',
        'menu' => false,
        'icon' => 'fas fa-info-circle',
        'permissions' => ['ADM', 'MGR']
    ],
    'purchasing/save-supplier' => [
        'handler' => 'pages/purchasing/save-supplier.php',
        'title' => 'Save Supplier',
        'menu' => false,
        'icon' => 'fas fa-user-plus',
        'permissions' => ['ADM', 'MGR']
    ],
    'purchasing/update-supplier' => [
        'handler' => 'pages/purchasing/update-supplier.php',
        'title' => 'Update Supplier',
        'menu' => false,
        'icon' => 'fas fa-user-edit',
        'permissions' => ['ADM', 'MGR']
    ],

    'purchasing/receiving' => [
        'handler' => 'pages/purchasing/receiving.php',
        'title' => 'Goods Receiving',
        'menu' => true,
        'icon' => 'fas fa-box-open',
        'permissions' => ['ADM', 'MGR']
    ],
    // Storefront routes (public)
    'store' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/home' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/products' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/product-detail' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/categories' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/category' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/cart' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/checkout' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/login' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/register' => [
        'handler' => 'store/index.php',
        'public' => true
    ],
    'store/account' => [
        'handler' => 'store/index.php',
        'public' => false
    ],
    'store/orders' => [
        'handler' => 'store/index.php',
        'public' => false
    ],

    // Store API routes
    '/api/store/products' => [
        'handler' => '/api/store/products.php',
        'method' => 'GET',
        'public' => true
    ],
    '/api/store/categories' => [
        'handler' => '/api/store/categories.php',
        'method' => 'GET',
        'public' => true
    ],
    '/api/store/product' => [
        'handler' => '/api/store/product.php',
        'method' => 'GET',
        'public' => true
    ],
    '/api/store/checkout' => [
        'handler' => '/api/store/checkout.php',
        'method' => 'POST',
        'public' => true
    ],
    // ============================================
    // REPORTS MODULE ROUTES
    // ============================================
    'reports' => [
        'handler' => 'pages/reports/dashboard.php',
        'title' => 'Reports Dashboard',
        'menu' => true,
        'icon' => 'fas fa-chart-bar',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],
    'reports/products' => [
        'handler' => 'pages/reports/products.php',
        'title' => 'Product Reports',
        'menu' => true,
        'icon' => 'fas fa-box',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'reports/sales' => [
        'handler' => 'pages/reports/sales.php',
        'title' => 'Sales Reports',
        'menu' => true,
        'icon' => 'fas fa-chart-line',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'reports/inventory' => [
        'handler' => 'pages/reports/inventory.php',
        'title' => 'Inventory Reports',
        'menu' => true,
        'icon' => 'fas fa-chart-pie',
        'permissions' => ['ADM', 'MGR', 'SEL', 'VIW']
    ],

    'reports/financial' => [
        'handler' => 'pages/reports/financial.php',
        'title' => 'Financial Reports',
        'menu' => true,
        'icon' => 'fas fa-coins',
        'permissions' => ['ADM', 'MGR']
    ],

    'reports/customers' => [
        'handler' => 'pages/reports/customers.php',
        'title' => 'Customer Reports',
        'menu' => true,
        'icon' => 'fas fa-user-chart',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],

    // ============================================
    // USER MODULE ROUTES
    // ============================================

    'profile' => [
        'handler' => 'pages/user/profile.php',
        'title' => 'User Profile',
        'menu' => true,
        'icon' => 'fas fa-user',
        'permissions' => ['ADM', 'MGR', 'SEL']
    ],

    // ============================================
    // ADMINISTRATION MODULE ROUTES
    // ============================================
    'admin/users' => [
        'handler' => 'pages/admin/users.php',
        'title' => 'User Management',
        'menu' => true,
        'icon' => 'fas fa-user-cog',
        'permissions' => ['ADM']
    ],
    'admin/save-user' => [
        'handler' => 'pages/admin/save-user.php',
        'title' => 'Save User',
        'menu' => false,
        'icon' => 'fas fa-user-plus',
        'permissions' => ['ADM']
    ],

    'admin/update-user' => [
        'handler' => 'pages/admin/update-user.php',
        'title' => 'Update User',
        'menu' => false,
        'icon' => 'fas fa-user-edit',
        'permissions' => ['ADM']
    ],
    'admin/role_edit' => [
        'handler' => 'pages/admin/role_edit.php',
        'title' => 'Edit Role',
        'menu' => false,
        'icon' => 'fas fa-user-edit',
        'permissions' => ['ADM']
    ],
    'admin/role_detail' => [
        'handler' => 'pages/admin/role_detail.php',
        'title' => 'Role Detail',
        'menu' => false,
        'icon' => 'fas fa-user-edit',
        'permissions' => ['ADM']
    ],

    'admin/user_detail' => [
        'handler' => 'pages/admin/user_detail.php',
        'title' => 'User Detail',
        'menu' => false,
        'icon' => 'fas fa-user-edit',
        'permissions' => ['ADM']
    ],

    'admin/roles' => [
        'handler' => 'pages/admin/roles.php',
        'title' => 'Role Management',
        'menu' => true,
        'icon' => 'fas fa-user-shield',
        'permissions' => ['ADM']
    ],
    'admin/save_roles' => [
        'handler' => 'pages/admin/save_role.php',
        'title' => 'Save Roles',
        'menu' => false,
        'icon' => 'fas fa-user-shield',
        'permissions' => ['ADM']
    ],

    'admin/permissions' => [
        'handler' => 'pages/admin/permissions.php',
        'title' => 'Permission Management',
        'menu' => true,
        'icon' => 'fas fa-key',
        'permissions' => ['ADM']
    ],

    'admin/settings' => [
        'handler' => 'pages/admin/settings.php',
        'title' => 'System Settings',
        'menu' => true,
        'icon' => 'fas fa-cogs',
        'permissions' => ['ADM']
    ],


    // In the admin section:
    'admin/invitations' => [
        'handler' => 'pages/admin/invitations.php',
        'title' => 'User Invitations',
        'menu' => true,
        'icon' => 'fas fa-envelope',
        'permissions' => ['ADM']
    ],

    'subscriptions' => [
        'handler' => 'pages/subscriptions/plans.php',
        'title' => 'Manage Subscriptions',
        'menu' => true,
        'icon' => 'fas fa-user-check',
        'permissions' => ['ADM']
    ],

    // ============================================
    // API ROUTES (For AJAX calls)
    // ============================================
    'api/auth/register' => [
        'handler' => 'api/auth/register.php',
        'type' => 'api',
        'method' => 'POST',
        'public' => true
    ],

    'api/auth/check-username' => [
        'handler' => 'api/auth/check-username.php',
        'type' => 'api',
        'method' => 'POST',
        'public' => true
    ],
    // Add these API routes:
    'api/invitations/resend' => [
        'handler' => 'api/invitations/resend.php',
        'type' => 'api',
        'method' => 'POST',
        'permissions' => ['ADM']
    ],

    'api/invitations/cancel' => [
        'handler' => 'api/invitations/cancel.php',
        'type' => 'api',
        'method' => 'POST',
        'permissions' => ['ADM']
    ],

    'api/users/approve' => [
        'handler' => 'api/users/approve.php',
        'type' => 'api',
        'method' => 'POST',
        'permissions' => ['ADM']
    ],

    'api/users/reject' => [
        'handler' => 'api/users/reject.php',
        'type' => 'api',
        'method' => 'POST',
        'permissions' => ['ADM']
    ],
    'api/products/list' => [
        'handler' => 'api/products/get.php',
        'type' => 'api',
        'method' => 'GET'
    ],

    'api/products/create' => [
        'handler' => 'api/products/quick-add.php',
        'type' => 'api',
        'method' => 'POST'
    ],

    'api/products/update' => [
        'handler' => 'api/products/update.php',
        'type' => 'api',
        'method' => 'PUT'
    ],

    'api/products/delete' => [
        'handler' => 'api/products/delete.php',
        'type' => 'api',
        'method' => 'DELETE'
    ],

    'api/sales/create' => [
        'handler' => 'api/sales/create.php',
        'type' => 'api',
        'method' => 'POST'
    ],

    'api/inventory/stock' => [
        'handler' => 'api/inventory/stock.php',
        'type' => 'api',
        'method' => 'GET'
    ],

    'api/customers/list' => [
        'handler' => 'api/customers/list.php',
        'type' => 'api',
        'method' => 'GET'
    ],

    // ============================================
    // AUTHENTICATION ROUTES
    // ============================================
    'register' => [
        'handler' => 'pages/auth/register.php',
        'title' => 'Request Account',
        'public' => true
    ],
    'login' => [
        'handler' => 'pages/auth/signin.php',
        'title' => 'Login',
        'public' => true
    ],

    'logout' => [
        'handler' => 'pages/auth/logout.php',
        'title' => 'Logout',
        'public' => true
    ],

    'forgot-password' => [
        'handler' => 'pages/auth/forgot-password.php',
        'title' => 'Forgot Password',
        'public' => true
    ],

    'reset-password' => [
        'handler' => 'pages/auth/reset-password.php',
        'title' => 'Reset Password',
        'public' => true
    ],



    // ============================================
    // EXPORT ROUTES
    // ============================================

    'exports/inventory' => [
        'handler' => 'pages/exports/inventory.php',
        'title' => 'Inventory Export',
        'public' => true
    ],
    'exports/products' => [
        'handler' => 'pages/exports/products.php',
        'title' => 'Product Reports',
        'public' => true
    ],

    'exports/sales' => [
        'handler' => 'pages/exports/sales.php',
        'title' => 'Sales Export',
        'public' => true
    ],
    'exports/customers' => [
        'handler' => 'pages/exports/customers.php',
        'title' => 'Customers Export',
        'public' => true
    ],
    'exports/financial' => [
        'handler' => 'pages/exports/financial.php',
        'title' => 'Financial Export',
        'public' => true
    ],
    'exports/stock' => [
        'handler' => 'pages/exports/stock.php',
        'title' => 'Stock Export',
        'public' => true
    ],

    // ============================================
    // ERROR ROUTES
    // ============================================
    '404' => [
        'handler' => 'pages/errors/404.php',
        'title' => 'Page Not Found'
    ],

    '403' => [
        'handler' => 'pages/errors/403.php',
        'title' => 'Access Denied'
    ],

    '500' => [
        'handler' => 'pages/errors/500.php',
        'title' => 'Server Error'
    ]
];
