<?php
// features.php
// ============================================
// FEATURES PAGE - STANDALONE
// ============================================

session_name('sati');
session_start();

$pageTitle = 'Features - SATI ERP';
$currentPage = 'features';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        /* ===== NAVIGATION STYLES - PERFECTLY ALIGNED ===== */
        .navbar {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 15px 0;
            min-height: 80px;
            display: flex;
            align-items: center;
        }

        .navbar .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .navbar-brand {
            color: white;
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            height: 100%;
            line-height: 1;
            padding: 0;
            margin: 0;
        }

        .navbar-brand i {
            margin-right: 10px;
            font-size: 28px;
            display: flex;
            align-items: center;
        }

        .nav-links {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            height: 100%;
            margin: 0;
            padding: 0;
        }

        .nav-link {
            color: white !important;
            font-weight: 500;
            padding: 8px 16px !important;
            margin: 0 2px;
            border-radius: 50px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1.5;
            white-space: nowrap;
            position: relative;
            height: 100%;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            position: relative;
        }

        .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 25%;
            width: 50%;
            height: 2px;
            background: white;
            border-radius: 2px;
        }

        .btn-login {
            border: 2px solid white;
            color: white;
            padding: 8px 25px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            line-height: 1.5;
            white-space: nowrap;
            height: 42px;
            margin-right: 10px;
        }

        .btn-login:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
        }

        .btn-register {
            background: white;
            color: #667eea;
            padding: 8px 25px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1.5;
            white-space: nowrap;
            height: 42px;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        /* ===== PAGE CONTENT STYLES ===== */
        .page-header {
            text-align: center;
            color: white;
            padding: 60px 20px 40px;
        }

        .page-header h1 {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .page-header p {
            font-size: 18px;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            padding: 40px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px 30px;
            color: white;
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .feature-card:hover {
            transform: translateY(-10px);
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            font-size: 32px;
            transition: all 0.3s;
        }

        .feature-card:hover .feature-icon {
            background: white;
            color: #667eea;
            transform: rotate(360deg);
        }

        .feature-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .feature-description {
            opacity: 0.8;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .feature-list {
            list-style: none;
            padding: 0;
        }

        .feature-list li {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }

        .feature-list li i {
            color: #28a745;
            margin-right: 10px;
            font-size: 14px;
        }

        .back-home {
            text-align: center;
            padding: 40px 20px 60px;
        }

        .btn-home {
            background: white;
            color: #667eea;
            padding: 12px 40px;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }

        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .btn-dashboard {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 12px 40px;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            margin-left: 15px;
            border: 1px solid white;
        }

        .btn-dashboard:hover {
            background: white;
            color: #667eea;
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 991px) {
            .navbar .container {
                flex-direction: column;
                gap: 15px;
            }

            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 36px;
            }

            .btn-dashboard {
                margin-left: 0;
                margin-top: 15px;
                display: block;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Animation for page load */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .navbar,
        .page-header,
        .features-grid,
        .back-home {
            animation: fadeIn 0.8s ease-out forwards;
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="./index.html">
                <i class="fas fa-warehouse"></i>
                SATI ERP
            </a>
            <div class="nav-links">
                <a href="./index.html" class="nav-link">Home</a>
                <a href="./features.php" class="nav-link active">Features</a>
                <a href="./contact.php" class="nav-link">Contact</a>
                <a href="./index.html#services" class="nav-link">Services</a>
            </div>
            <div>
                <a href="./app/auth/signin.php" class="btn-login">Login</a>
                <a href="./app/auth/register.php" class="btn-register">Join Now</a>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <h1>Powerful Features for Your Business</h1>
        <p>Everything you need to manage your inventory, sales, and team in one place</p>
    </div>

    <!-- Features Grid -->
    <div class="features-grid">
        <!-- Inventory Management -->
        <div class="feature-card">
            <div class="feature-icon">
                <i class="fas fa-boxes"></i>
            </div>
            <h3 class="feature-title">Inventory Management</h3>
            <p class="feature-description">Real-time tracking, low stock alerts, and automated reordering to never run
                out of products.</p>
            <ul class="feature-list">
                <li><i class="fas fa-check"></i> Real-time stock tracking</li>
                <li><i class="fas fa-check"></i> Low stock notifications</li>
                <li><i class="fas fa-check"></i> Batch & expiry tracking</li>
                <li><i class="fas fa-check"></i> Multiple warehouses</li>
                <li><i class="fas fa-check"></i> Stock transfers</li>
                <li><i class="fas fa-check"></i> Inventory adjustments</li>
            </ul>
        </div>

        <!-- Sales & Invoicing -->
        <div class="feature-card">
            <div class="feature-icon">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <h3 class="feature-title">Sales & Invoicing</h3>
            <p class="feature-description">Create professional invoices, track payments, and manage customer
                relationships seamlessly.</p>
            <ul class="feature-list">
                <li><i class="fas fa-check"></i> Quick invoice creation</li>
                <li><i class="fas fa-check"></i> Payment tracking</li>
                <li><i class="fas fa-check"></i> Customer management</li>
                <li><i class="fas fa-check"></i> Sales returns & refunds</li>
                <li><i class="fas fa-check"></i> Quotations & proforma</li>
                <li><i class="fas fa-check"></i> Receipt printing</li>
            </ul>
        </div>

        <!-- Purchase Management -->
        <div class="feature-card">
            <div class="feature-icon">
                <i class="fas fa-truck-loading"></i>
            </div>
            <h3 class="feature-title">Purchase Management</h3>
            <p class="feature-description">Streamline procurement with purchase orders, supplier management, and goods
                receiving.</p>
            <ul class="feature-list">
                <li><i class="fas fa-check"></i> Purchase orders</li>
                <li><i class="fas fa-check"></i> Supplier management</li>
                <li><i class="fas fa-check"></i> Goods receiving</li>
                <li><i class="fas fa-check"></i> Purchase returns</li>
                <li><i class="fas fa-check"></i> Reorder automation</li>
                <li><i class="fas fa-check"></i> Supplier ratings</li>
            </ul>
        </div>

        <!-- Advanced Analytics -->
        <div class="feature-card">
            <div class="feature-icon">
                <i class="fas fa-chart-bar"></i>
            </div>
            <h3 class="feature-title">Advanced Analytics</h3>
            <p class="feature-description">Make data-driven decisions with comprehensive reports on sales, inventory,
                and financial performance.</p>
            <ul class="feature-list">
                <li><i class="fas fa-check"></i> Sales reports</li>
                <li><i class="fas fa-check"></i> Inventory valuation</li>
                <li><i class="fas fa-check"></i> Profit & loss</li>
                <li><i class="fas fa-check"></i> Customer analytics</li>
                <li><i class="fas fa-check"></i> Product performance</li>
                <li><i class="fas fa-check"></i> Custom reports</li>
            </ul>
        </div>

        <!-- Multi-company Support -->
        <div class="feature-card">
            <div class="feature-icon">
                <i class="fas fa-building"></i>
            </div>
            <h3 class="feature-title">Multi-company</h3>
            <p class="feature-description">Manage multiple businesses from a single account with complete data isolation
                and security.</p>
            <ul class="feature-list">
                <li><i class="fas fa-check"></i> Separate databases</li>
                <li><i class="fas fa-check"></i> Company switching</li>
                <li><i class="fas fa-check"></i> Consolidated reports</li>
                <li><i class="fas fa-check"></i> Role-based access</li>
                <li><i class="fas fa-check"></i> Data isolation</li>
                <li><i class="fas fa-check"></i> Cross-company views</li>
            </ul>
        </div>

        <!-- Security -->
        <div class="feature-card">
            <div class="feature-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h3 class="feature-title">Enterprise Security</h3>
            <p class="feature-description">Bank-level encryption, 2FA authentication, and role-based access control for
                maximum protection.</p>
            <ul class="feature-list">
                <li><i class="fas fa-check"></i> Two-factor authentication</li>
                <li><i class="fas fa-check"></i> Role-based permissions</li>
                <li><i class="fas fa-check"></i> Data encryption</li>
                <li><i class="fas fa-check"></i> Session management</li>
            </ul>
        </div>

        <!-- API Access -->
        <div class="feature-card">
            <div class="feature-icon">
                <i class="fas fa-code"></i>
            </div>
            <h3 class="feature-title">API Access</h3>
            <p class="feature-description">Integrate with your existing tools using our powerful REST API (Professional
                & Enterprise plans).</p>
            <ul class="feature-list">
                <li><i class="fas fa-check"></i> RESTful API</li>
                <li><i class="fas fa-check"></i> Webhooks</li>
                <li><i class="fas fa-check"></i> Rate limiting</li>
                <li><i class="fas fa-check"></i> API keys</li>
                <li><i class="fas fa-check"></i> Documentation</li>
                <li><i class="fas fa-check"></i> SDKs available</li>
            </ul>
        </div>

        <!-- Mobile Ready -->
        <div class="feature-card">
            <div class="feature-icon">
                <i class="fas fa-mobile-alt"></i>
            </div>
            <h3 class="feature-title">Mobile Ready</h3>
            <p class="feature-description">Access your business anywhere with our fully responsive design and
                mobile-optimized interface.</p>
            <ul class="feature-list">
                <li><i class="fas fa-check"></i> Responsive design</li>
                <li><i class="fas fa-check"></i> Mobile app (coming soon)</li>
                <li><i class="fas fa-check"></i> Offline mode</li>
                <li><i class="fas fa-check"></i> Push notifications</li>
                <li><i class="fas fa-check"></i> Barcode scanning</li>
                <li><i class="fas fa-check"></i> Touch optimized</li>
            </ul>
        </div>

        <!-- Customer Support -->
        <div class="feature-card">
            <div class="feature-icon">
                <i class="fas fa-headset"></i>
            </div>
            <h3 class="feature-title">Customer Support</h3>
            <p class="feature-description">Get help when you need it with our comprehensive support options and
                knowledge base.</p>
            <ul class="feature-list">
                <li><i class="fas fa-check"></i> 24/7 email support</li>
                <li><i class="fas fa-check"></i> Live chat (Pro/Enterprise)</li>
                <li><i class="fas fa-check"></i> Phone support</li>
                <li><i class="fas fa-check"></i> Knowledge base</li>
                <li><i class="fas fa-check"></i> Video tutorials</li>
                <li><i class="fas fa-check"></i> Community forum</li>
            </ul>
        </div>
    </div>

    <!-- Back to Home / Dashboard -->
    <div class="back-home">
        <a href="./index.html" class="btn-home">
            <i class="fas fa-home me-2"></i>Back to Home
        </a>
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="./app/index.php?page=dashboard" class="btn-dashboard">
                <i class="fas fa-tachometer-alt me-2"></i>Go to Dashboard
            </a>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>