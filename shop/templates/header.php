<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo SITE_NAME; ?> - Multi-vendor marketplace for corporate and peer-to-peer selling">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' . SITE_NAME : SITE_NAME; ?></title>
    
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="./assets/css/chat.css">
    <link rel="stylesheet" href="./assets/css/categoryNav.css">

    
    <?php if (isset($additionalStyles) && is_array($additionalStyles)): ?>
        <?php foreach ($additionalStyles as $style): ?>
            <link rel="stylesheet" href="./assets/css/<?php echo htmlspecialchars($style); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <nav class="navbar">
                <div class="logo">
                    <a href="./"><?php echo SITE_NAME; ?></a>
                </div>
                
                <div class="search-container">
                    <form action="./" method="GET" class="search-form">
                        <input type="text" name="search" placeholder="Search products..." 
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        <button type="submit"><i class="fas fa-search"></i></button>
                    </form>
                    <div class="autocomplete-results" id="autocomplete-results"></div>
                </div>
                
                <div class="nav-links">
                        
                    <a href="./" class="nav-link"><i class="fas fa-home"></i> Home</a>
                    
                    <a href="./chat.php" class="nav-link chat-link">
                        <i class="fas fa-comment-dots"></i> 
                        Messages
                        <span class="chat-badge" id="chat-badge" style="display: none;">0</span>
                    </a>
                    
                    <a href="./cart.php" class="nav-link cart-link">
                        <i class="fas fa-shopping-cart"></i> 
                        Cart 
                        <span class="cart-badge" id="cart-badge"><?php echo SessionManager::getCartCount(); ?></span>
                    </a>
                    
                    <?php if (SessionManager::isCustomerLoggedIn()): ?>
                        <?php $customer = SessionManager::getCustomer(); ?>
                        <div class="user-menu">
                            <button class="user-menu-btn">
                                <i class="fas fa-user-circle"></i> 
                                <?php echo htmlspecialchars(substr($customer['full_name'] ?? 'User', 0, 15)); ?>
                                <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="user-dropdown">
                                <a href="./account.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                                <a href="./account_orders.php"><i class="fas fa-shopping-bag"></i> My Orders</a>
                                <a href="./wishlist.php"><i class="fas fa-heart"></i> Wishlist</a>
                                <a href="./my_store.php"><i class="fas fa-store"></i> My Store</a>
                                <a href="./sell.php"><i class="fas fa-plus-circle"></i> Sell Item</a>
                                <a href="./chat.php"><i class="fas fa-comments"></i> Messages</a>
                                <a href="./account_profile.php"><i class="fas fa-user-edit"></i> Profile</a>
                                <a href="./account_password.php"><i class="fas fa-key"></i> Change Password</a>
                                <hr>
                                <a href="./logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="auth-links">
                            <a href="./login.php" class="btn-login"><i class="fas fa-sign-in-alt"></i> Login</a>
                            <a href="./register.php" class="btn-register"><i class="fas fa-user-plus"></i> Register</a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <button class="mobile-menu-toggle" id="mobile-menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
            </nav>

            <nav class="category-nav-wrapper">
                <ul class="nav-links" id="navLinks">
                    </ul>
            </nav>

            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    const menuData = <?php echo file_get_contents(__DIR__ . '/categoryNav.json'); ?>;

                    const navContainer = document.getElementById('navLinks');
                    if(!navContainer) return;

                    Object.keys(menuData).forEach(navKey => {
                    const navItemData = menuData[navKey];

                    const li = document.createElement('li');
                    li.classList.add('nav-item');

                    const a = document.createElement('a');
                    a.href = "./search.php?category=" + encodeURIComponent(navKey);
                    a.textContent = navKey;
                    li.appendChild(a);

                    if (navItemData.heads && navItemData.heads.length > 0) {
                        const megaMenu = document.createElement('div');
                        megaMenu.classList.add('mega-menu');

                        const leftCol = document.createElement('div');
                        leftCol.classList.add('menu-column');
                        const rightCol = document.createElement('div');
                        rightCol.classList.add('menu-column');

                        const halfLength = Math.ceil(navItemData.heads.length / 2);

                        navItemData.heads.forEach((headName, index) => {
                            const block = document.createElement('div');
                            block.classList.add('category-block');

                            const h3 = document.createElement('h3');
                            h3.textContent = headName;
                            block.appendChild(h3);

                            const subItems = navItemData.body[headName] || [];
                            if (subItems.length > 0) {
                                const ul = document.createElement('ul');
                                subItems.forEach(itemText => {
                                    const subLi = document.createElement('li');
                                    const subLink = document.createElement('a');
                                    subLink.href = "./search.php?category=" + encodeURIComponent(navKey) + "&type=" + encodeURIComponent(itemText);
                                    subLink.textContent = itemText;
                                    subLi.appendChild(subLink);
                                    ul.appendChild(subLi);
                                });
                                block.appendChild(ul);
                            }

                            if (index < halfLength) {
                                leftCol.appendChild(block);
                            } else {
                                rightCol.appendChild(block);
                            }
                        });

                        megaMenu.appendChild(leftCol);
                        megaMenu.appendChild(rightCol);
                        li.appendChild(megaMenu);

                        // --- NEW BOUNDARY DETECTOR LOGIC ---
                        // Calculate the screen position layout on user hover action
                        // --- OPTIMIZED BOUNDARY DETECTOR LOGIC ---
                        li.addEventListener('mouseenter', function() {
                            // Clear any previously assigned edge configurations
                            megaMenu.classList.remove('align-right', 'align-left');

                            const wrapper = document.querySelector('.category-nav-wrapper');
                            if (!wrapper) return;

                            const wrapperRect = wrapper.getBoundingClientRect();
                            const liRect = li.getBoundingClientRect();
                            const menuWidth = 760; // Total width of your mega-menu panel

                            // Find the horizontal midpoint position where a centered menu would sit
                            const liCenter = liRect.left + (liRect.width / 2);
                            const potentialMenuLeft = liCenter - (menuWidth / 2);
                            const potentialMenuRight = liCenter + (menuWidth / 2);

                            // If centering pushes the menu past the right edge of our wrapper container
                            if (potentialMenuRight > wrapperRect.right) {
                                megaMenu.classList.add('align-right');
                            } 
                            // If centering pushes the menu past the left edge of our wrapper container
                            else if (potentialMenuLeft < wrapperRect.left) {
                                megaMenu.classList.add('align-left');
                            }
                        });
                    }

                    navContainer.appendChild(li);
                    });
                                });
                </script>
        </div>
    </header>
    
    <div class="mobile-sidebar" id="mobile-sidebar">
        <div class="mobile-sidebar-header">
            <h3><?php echo SITE_NAME; ?></h3>
            <button class="close-sidebar">&times;</button>
        </div>
        <div class="mobile-sidebar-content">
            <a href="./"><i class="fas fa-home"></i> Home</a>
            <a href="./cart.php"><i class="fas fa-shopping-cart"></i> Cart</a>
            <a href="./chat.php"><i class="fas fa-comment-dots"></i> Messages</a>
            <div class="mobile-divider"></div>
            <div class="mobile-store-types">
                <strong>Filter by:</strong>
                <a href="./?seller_type=all">All Products</a>
                <a href="./?seller_type=corporate">🏢 Corporate Stores</a>
                <a href="./?seller_type=peer">👤 Individual Sellers</a>
            </div>
            <div class="mobile-divider"></div>
            <?php if (SessionManager::isCustomerLoggedIn()): ?>
                <a href="./account.php"><i class="fas fa-user"></i> My Account</a>
                <a href="./my_store.php"><i class="fas fa-store"></i> My Store</a>
                <a href="./sell.php"><i class="fas fa-plus-circle"></i> Sell Item</a>
                <a href="./logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            <?php else: ?>
                <a href="./login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
                <a href="./register.php"><i class="fas fa-user-plus"></i> Register</a>
            <?php endif; ?>
        </div>
    </div>
    
    <main>