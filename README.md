# P2P Marketplace & Multi-Tenant ERP Platform

A hybrid web application that combines a robust Multi-Tenant ERP engine with a highly flexible Peer-to-Peer (P2P) classified discovery marketplace. Users can create specialized corporate or individual profiles to announce, buy, or offer products, properties, vehicles, jobs, and professional services.

---

## 📂 Project Architecture

Based on the core application structure, the workspace is organized as follows:

*   **`config/`** — Centralized application settings, framework boots, security policies, and environment configurations.
*   **`logs/`** — Runtime application engine logs, error tracking files, and transactional security reports.
*   **`public/`** — Publicly accessible assets entry point (`index.php`/`index.js`), CSS styles, frontend client scripts, and user media uploads.
*   **`src/`** — Core backend engine processing. This encapsulates MVC Controllers, routing rules, domain services, database models, and global middleware.
*   **`templates/`** — Dynamic UI templates and frontend views handling directory search layout systems, active user messaging nodes, and flexible ad listing configurations.

---

## 🏛️ Hybrid Database Engine (Core Architecture)

The platform blends structured multi-tenant enterprise resource planning schemas with highly dynamic classified data structures:

### 1. Multi-Tenant ERP Core
*   **`companies` & `company_users`**: Isolates corporate accounts, allowing multi-employee access control.
*   **`inventory` & `inventory_transactions`**: Strict double-entry inventory auditing tracking enterprise-grade physical asset volumes and internal distributions.
*   **`invoice_items` & `expenses`**: Financial accounting metrics handling internal corporate balance ledgers.

### 2. Flexible Classified P2P Marketplace
To accommodate massive variations in directory entries (e.g., selling *Land* vs. offering *Freelance Jobs* vs. giving away items *For Free*), the listing model runs an EAV-inspired architecture rather than flat, sparse tables containing massive `NULL` column sets.

*   **`customer_store_products`**: The Master Classified Entry table tracking core indices (`title`, `price`, `is_free`, `latitude`, `longitude`, `parent_category`, `sub_category`, `child_category`).
*   **`p2p_listing_attributes`**: A dynamic key-value storage element matching attributes to the listing type (e.g., matching a vehicle ad to `mileage: 120k km`, or land to `square_meters: 500`).
*   **`chat_rooms` & `chat_messages`**: The core negotiation layer. Rather than processing standard rigid linear e-commerce checkouts, clients contact sellers via targeted room nodes linked directly to the product id to discuss physical delivery details or service dates.

---

## 🗺️ Listing Categories Classification Matrix

The marketplace discovery tree maps automatically across three operational classification tiers:

1.  **Real Estate**: Property Sales (Apartments, Houses, Land), Rentals, Commercial & Moving Services.
2.  **Vehicles**: Cars (Electric, SUV, Sedan), Motorbikes, Caravans, Boating, and Parts.
3.  **Holidays**: Rentals, Hotels, Campsites, and Travel Services.
4.  **Jobs**: Structural Offerings, Professional Training, Freelance & Gigs.
5.  **Fashion & Home**: Apparel, Accessories, Furniture, Appliances, Garden, and Tools.
6.  **Electronics**: Telephony, Laptops, Audio/Video, Gaming Consoles.
7.  **Hobbies, Others & Deals**: Sports, Musical Instruments, Tickets, Business Equipment, Flash Sales.

---

## 🚀 Getting Started

### Prerequisites
*   Web server configuration (Apache with `mod_rewrite` / Nginx / Node.js Engine)
*   MySQL 8.0+ or MariaDB 10.4+ Instance
*   Database schema loaded directly via your phpMyAdmin SQL initialization dump

### Installation
1. Clone the repository into your web server directory:
   ```bash
   git clone [https://github.com/bniyomugabo/p2p-marketplace-platform.git](https://github.com/bniyomugabo/p2p-marketplace-platform.git)
   cd p2p-marketplace-platform
