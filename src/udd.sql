-- 1. Enhance Companies for Public Storefronts
ALTER TABLE `companies` 
ADD COLUMN `slug` VARCHAR(100) UNIQUE AFTER `company_name`,
ADD COLUMN `store_description` TEXT AFTER `slug`,
ADD COLUMN `banner_url` VARCHAR(255) AFTER `logo_url`,
ADD COLUMN `is_public` TINYINT(1) DEFAULT 0;

-- 2. Enhance Products for SEO and Rich Content
ALTER TABLE `products` 
ADD COLUMN `long_description` TEXT AFTER `description`,
ADD COLUMN `meta_title` VARCHAR(150),
ADD COLUMN `meta_description` VARCHAR(255);

-- 1. Add Authentication and Marketplace fields
ALTER TABLE `customers` 
ADD COLUMN `password_hash` VARCHAR(255) NULL AFTER `email`,
ADD COLUMN `remember_token` VARCHAR(100) NULL AFTER `password_hash`,
ADD COLUMN `postal_code` VARCHAR(20) AFTER `city`,
ADD COLUMN `country` VARCHAR(100) DEFAULT 'Burundi' AFTER `postal_code`,
ADD COLUMN `last_login` TIMESTAMP NULL;

-- 2. Add Unique constraint to Email within a Company context
-- This ensures a user can't register twice with the same email for one store
ALTER TABLE `customers` ADD UNIQUE KEY `unique_email_per_company` (`company_id`, `email`);

-- 3. Global Categories (To allow browsing all shops by category)
-- Your current category_company is per-tenant; 
-- this allows a unified marketplace navigation.
CREATE TABLE `global_categories` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) UNIQUE,
  `icon` VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS `wishlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_wishlist` (`customer_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Product Reviews (Social Proof)
CREATE TABLE `product_reviews` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `customer_id` INT NOT NULL,
  `rating` TINYINT CHECK (rating BETWEEN 1 AND 5),
  `comment` TEXT,
  `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Inventory Reservations (Prevents overselling during checkout)
ALTER TABLE `variants` 
ADD COLUMN `reserved_stock` INT DEFAULT 0 AFTER `reorder_level`;


START TRANSACTION;

-- =========================================================================
-- 1. PEER-TO-PEER STORE & SIMPLIFIED PRODUCTS
-- =========================================================================

-- Create a table for Individual Stores (The User's Leboncoin-style Profile)
CREATE TABLE IF NOT EXISTS `customer_stores` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `store_name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_customer_store` (`customer_id`),
  UNIQUE KEY `unique_store_slug` (`slug`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standalone Simplified Table for Customer Items (Bypasses corporate ERP products/variants)
CREATE TABLE IF NOT EXISTS `customer_store_products` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_store_id` INT(11) NOT NULL,
  `global_category_id` INT(11) DEFAULT NULL, -- Link for marketplace-wide browsing
  `title` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `description` TEXT NOT NULL,
  `price` DECIMAL(12,2) NOT NULL,
  `condition` ENUM('new', 'like_new', 'good', 'fair') DEFAULT 'good',
  `stock_quantity` INT(11) DEFAULT 1,       -- Typically 1 for personal second-hand items
  `primary_image_url` VARCHAR(255) DEFAULT NULL,
  `additional_images` JSON DEFAULT NULL,     -- Stores extra photo URLs natively
  `city` VARCHAR(50) DEFAULT NULL,           -- Local pickup/filtering parameter
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_slug` (`slug`),
  FOREIGN KEY (`customer_store_id`) REFERENCES `customer_stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- 2. P2P ORDERS, ESCROW & REFUND ITEM-TRACKING
-- =========================================================================

-- The Master P2P Escrow Order Ledger
CREATE TABLE IF NOT EXISTS `p2p_orders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_number` VARCHAR(30) NOT NULL,
  `buyer_id` INT(11) NOT NULL,
  `seller_store_id` INT(11) NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL,
  `shipping_cost` DECIMAL(12,2) DEFAULT '0.00',
  `total_amount` DECIMAL(12,2) NOT NULL,
  `payment_status` ENUM('pending', 'held_in_escrow', 'released_to_seller', 'refunded') DEFAULT 'pending',
  `shipping_status` ENUM('pending', 'shipped', 'delivered', 'disputed') DEFAULT 'pending',
  `shipping_address` TEXT DEFAULT NULL,
  `tracking_number` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_p2p_order_num` (`order_number`),
  FOREIGN KEY (`buyer_id`) REFERENCES `customers` (`id`),
  FOREIGN KEY (`seller_store_id`) REFERENCES `customer_stores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Itemized Order Details featuring independent Damaged/Returned status management
CREATE TABLE IF NOT EXISTS `p2p_order_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `p2p_order_id` INT(11) NOT NULL,
  `customer_store_product_id` INT(11) DEFAULT NULL, -- Settable to NULL on item deletion to protect financial logs
  `product_title` VARCHAR(150) NOT NULL,            -- Hard-coded string receipt snapshot
  `quantity` INT(11) NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(12,2) NOT NULL,
  `line_total` DECIMAL(12,2) NOT NULL,
  `item_status` ENUM('delivered_ok', 'damaged', 'not_as_described', 'missing', 'returned') DEFAULT 'delivered_ok',
  `resolution_status` ENUM('none', 'refund_pending', 'refunded', 'replaced', 'dispute_rejected') DEFAULT 'none',
  `refunded_amount` DECIMAL(12,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`p2p_order_id`) REFERENCES `p2p_orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_store_product_id`) REFERENCES `customer_store_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dedicated P2P Claims Verification table for hosting dispute evidence photos
CREATE TABLE IF NOT EXISTS `p2p_item_disputes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `p2p_order_item_id` INT(11) NOT NULL,
  `customer_id` INT(11) NOT NULL,                   -- The complaining buyer
  `claim_type` ENUM('damage', 'return', 'missing_parts') NOT NULL,
  `buyer_notes` TEXT NOT NULL,
  `evidence_photos` JSON DEFAULT NULL,              -- Array of uploaded damage proof image links
  `seller_response` TEXT DEFAULT NULL,
  `admin_decision` TEXT DEFAULT NULL,               -- Escrow mediator resolution notes
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`p2p_order_item_id`) REFERENCES `p2p_order_items` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- 3. POLYMORPHIC REAL-TIME CHATTING (P2P & B2C UNIFIED)
-- =========================================================================

-- Shared Chat Rooms specifying who is communicating and the specific listing item context
CREATE TABLE IF NOT EXISTS `chat_rooms` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `room_type` ENUM('p2p', 'b2c') NOT NULL,
  `buyer_customer_id` INT(11) NOT NULL,             -- The logged-in customer initiating inquiry
  
  -- Flexible target sellers:
  `seller_store_id` INT(11) DEFAULT NULL,           -- Populated only if room_type = 'p2p'
  `seller_company_id` INT(11) DEFAULT NULL,         -- Populated only if room_type = 'b2c'
  
  -- Item context targets:
  `p2p_product_id` INT(11) DEFAULT NULL,            -- Points to customer_store_products
  `erp_product_id` INT(11) DEFAULT NULL,            -- Points to core ERP products table
  
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_p2p_chat` (`buyer_customer_id`, `seller_store_id`, `p2p_product_id`),
  UNIQUE KEY `unique_b2c_chat` (`buyer_customer_id`, `seller_company_id`, `erp_product_id`),
  FOREIGN KEY (`buyer_customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`seller_store_id`) REFERENCES `customer_stores` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`seller_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polling-friendly Messaging Ledger using variable sender accounts mapping
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `chat_room_id` INT(11) NOT NULL,
  `sender_type` ENUM('customer', 'company_user') NOT NULL, 
  `sender_id` INT(11) NOT NULL,                     -- References either customers.id OR ERP users.id based on type
  `message_text` TEXT NOT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `read_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`chat_room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;