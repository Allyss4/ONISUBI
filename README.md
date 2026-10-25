# ONISUBI (おにすび)

> **Japanese Onigiri + Hawaiian Musubi in one delicious bite.**  
> A full-stack e-commerce ordering system, real-time inventory tracker, and administrative management platform built for ONISUBI.

---

## Overview

**ONISUBI** is a culinary fusion snack brand bringing together Japanese rice balls (*onigiri*) and Hawaiian luncheon meat rice snacks (*musubi*). Handcrafted daily with premium Japanese sushi rice, crisp roasted nori, and savory-sweet teriyaki glazes, ONISUBI delivers a filling, portable, and budget-friendly meal at only **₱40.00** each.

This repository powers the complete digital storefront and operations system for ONISUBI, featuring real-time inventory tracking, customer authentication, automated review carousels, on-site feedback surveys, multi-method checkout (GCash & COD), and an integrated administrative dashboard.

---

## Key Features

### Customer Storefront
- **Dynamic Menu & Product Catalog**: Browse popular flavors (*Spam & Egg*, *Sizzling Hotdog*, *Tuna Mayo*, *Egg*) with live stock badges (`In Stock`, `Only X left!`, `Sold Out`).
- **Interactive Product Modal**: Independently scrollable detail modal showcasing high-res imagery, fresh ingredients chips, allergen & dietary warning badges, and quantity steppers with live price recalculation.
- **Automated Customer Reviews Carousel**: Continuous horizontal marquee displaying 4+ star verified reviews with pause-on-hover interaction and edge fading masks.
- **On-Site Feedback & Review Modal**: Direct questionnaire collecting customer satisfaction (role, portion size, pricing fairness, favorite flavors, suggestions, and bug reports) directly stored into the database.
- **Smart Cart & Checkout Flow**: LocalStorage + Cookie synced cart with instant stock verification, customer details auto-fill, and support for Cash on Delivery and GCash payments.
- **Live Order Tracking**: Interactive timeline tracking order states (`Pending` → `Processing` → `Completed` / `Cancelled`) with proof-of-payment upload.
- **Magic Account Dialog**: Seamless single-input email check that dynamically reveals login or registration fields without disruptive page jumps.

### Real-Time Inventory & Supabase Sync
- **WebSocket Synchronization**: Leverages Supabase Realtime channels (`postgres_changes`) to instantly reflect stock adjustments and order deductions across all open customer browsers without page refresh.
- **PostgreSQL Pooler Integration**: Fast and resilient database connection via PDO PostgreSQL pooler with transactional consistency.

### Administrative Management Portal (`/admin`)
- **Dashboard & KPI Analytics**: Real-time store statistics, gross sales, active orders, customer counts, and low-stock indicators.
- **Order Processing**: Detailed order history, status toggle with live RPC updates, delivery addresses, and line-item breakdowns.
- **Product Management**: Full CRUD interface for menu items, base pricing, descriptions, and stock quantities.
- **Inventory Control**: Live stock manager with threshold triggers and instant (+ / -) adjustment controls.
- **Feedback & Reports Center**: Review analytics, average rating metrics, flavor preference breakdowns, and issue tracking.
- **Payment Settings**: GCash QR code image upload, mobile number, account name, and payment gateway controls.
- **Staff & Customer Directories**: Role-based access control (`Admin`, `Staff`, `Customer`).

---

## Tech Stack

| Layer | Technologies |
|---|---|
| **Frontend** | HTML5, Vanilla CSS3 (Custom Design System, Glassmorphism, Micro-animations), Vanilla JavaScript (ES6+) |
| **Backend** | PHP 8.1+ / 8.2 (Strict Typing, PDO PostgreSQL, REST APIs) |
| **Database & Realtime** | [Supabase](https://supabase.com/) (PostgreSQL 15+, Row Level Security, Realtime WebSockets, RPCs) |
| **Payments** | Manual GCash QR / Proof of Payment & [PayMongo](https://paymongo.com/) API Integration |
| **Server & Container** | Apache Web Server (`mod_rewrite`), Docker, Composer, XAMPP |

---

## Directory Structure

```text
ONISUBI/
├── admin/                     # Admin portal pages and management modules
│   ├── assets/                # Admin scripts and stylesheets
│   ├── dashboard.php          # Store analytics and summary overview
│   ├── orders.php             # Order lifecycle management
│   ├── products.php           # Catalog and menu CRUD
│   ├── inventory.php          # Real-time stock monitor & thresholds
│   ├── reports.php            # Reviews & customer feedback analytics
│   ├── customers.php          # Customer directory & order records
│   ├── staff.php              # Admin user management & roles
│   └── payment-settings.php   # GCash QR and payment configurations
├── api/                       # REST API endpoints (JSON responses)
│   ├── check-email.php        # Account presence verification
│   ├── create-order.php       # Order creation & stock decrement
│   ├── get-stock.php          # Real-time stock query
│   ├── submit-feedback.php    # Feedback survey & review submission
│   └── user-orders.php        # Customer order history
├── assets/                    # Public static assets
│   ├── css/style.css          # Design system, components, and animations
│   ├── js/script.js           # Client application logic & Supabase realtime
│   └── images/                # Brand logos, product photos, and icons
├── config/                    # System configurations and database drivers
│   ├── bootstrap.php          # Environment loading and global session handler
│   ├── db.php                 # PDO PostgreSQL connection provider
│   ├── supabase.php           # Supabase REST, RPC, and frontend config helpers
│   ├── paymongo.php           # PayMongo API client helper
│   └── admin.php              # Role authentication guards for admin portal
├── pages/                     # Customer pages
│   ├── account.php            # User profile and order history
│   ├── auth.php               # Login, register, and logout handlers
│   ├── cart.php               # Shopping cart breakdown
│   ├── checkout.php           # Delivery address & payment method selection
│   ├── gcash-payment.php      # GCash QR payment & reference input
│   ├── order-status.php       # Order tracking and receipt view
│   ├── upload-proof.php       # Payment screenshot upload handler
│   ├── reset-password.php     # Account recovery
│   ├── terms.php              # Terms and conditions
│   └── privacy.php            # Privacy notice
├── supabase/                  # Supabase database configuration
│   ├── migrations/            # SQL migration history
│   └── schema.sql             # Complete database schema dump
├── dockerfile                 # Production Apache + PHP 8.2 container definition
├── index.php                  # Main storefront landing page
└── composer.json              # PHP dependencies (vlucas/phpdotenv)
```

---

## Getting Started

### Prerequisites

Ensure you have the following installed on your machine:
- **PHP** >= 8.1 with `pdo_pgsql` and `pgsql` extensions enabled
- **Composer** (PHP dependency manager)
- **Web Server** ([XAMPP](https://www.apachefriends.org/), Apache, or Docker)
- **Supabase Account / CLI** (for cloud PostgreSQL database and realtime features)

---

### Local Installation (XAMPP / Apache)

1. **Clone the repository**:
   ```bash
   git clone https://github.com/haruchanz64/ONISUBI.git
   cd ONISUBI
   ```

2. **Install PHP dependencies**:
   ```bash
   composer install
   ```

3. **Configure Environment Variables**:
   Copy or create the `.env` file in the project root:
   ```env
   # PayMongo Credentials
   PAYMONGO_TEST_SECRET_KEY=your_paymongo_secret_key

   # Supabase Configuration
   SUPABASE_URL=https://your-project-id.supabase.co
   SUPABASE_ANON_KEY=your_supabase_anon_key
   SUPABASE_SERVICE_ROLE_KEY=your_supabase_service_role_key

   # Supabase PostgreSQL Pooler Connection
   SUPABASE_DB_HOST=aws-0-ap-southeast-1.pooler.supabase.com
   SUPABASE_DB_PORT=6543
   SUPABASE_DB_NAME=postgres
   SUPABASE_DB_USER=postgres.your-project-id
   SUPABASE_DB_PASSWORD=your_db_password
   ```

4. **Verify PHP PostgreSQL Extension**:
   Ensure `extension=pdo_pgsql` and `extension=pgsql` are uncommented in your `php.ini`.

5. **Deploy Database Schema / Migrations**:
   Using the Supabase CLI:
   ```bash
   supabase link --project-ref your-project-id
   supabase db push
   ```
   *Or execute `supabase/schema.sql` directly inside the Supabase SQL Editor.*

6. **Serve the Application**:
   - If using **XAMPP**: Move or clone this folder into `c:/xampp/htdocs/ONISUBI/`.
   - Start Apache via XAMPP Control Panel.
   - Open your browser at:
     ```text
     Storefront: http://localhost/ONISUBI/
     Admin Panel: http://localhost/ONISUBI/admin/
     ```

---

### Running with Docker

You can also run ONISUBI in an isolated container using the included `dockerfile`:

```bash
# 1. Build the Docker image
docker build -t onisubi .

# 2. Run the container on port 80
docker run -d -p 80:80 --name onisubi-app onisubi
```

Access the application at `http://localhost/`.

---

## Database Schema Highlights

The application relies on several core relational tables in PostgreSQL:

- **`products`**: ID, name, price, stock, description, image URL, category, status.
- **`inventory`**: Product foreign key, live quantity, low stock thresholds, and last updated timestamps.
- **`orders`**: Customer details, delivery address, payment method (`COD` / `GCash`), payment status, order status, total price, and tracking timestamps.
- **`order_items`**: Line items per order, product ID, item name, price, and quantities.
- **`reviews`**: Customer ratings (1–5 stars), written testimonials, customer name, linked `product_id`, and creation timestamp.
- **`customer_feedback`**: Comprehensive survey responses (role, favorite flavor, portion satisfaction, price perception, loved features, comments, and bug reports).
- **`users`**: Authentication credentials, hashed passwords, roles (`customer`, `staff`, `admin`), phone, and delivery addresses.
- **`payment_settings`**: Active GCash mobile number, account holder name, QR code file URL, and gateway switches.

---

## Brand Palette & Aesthetics

| Color Name | Hex Code | Purpose |
|---|---|---|
| **Oni Orange** | `#FF951C` | Primary brand accent, ratings, CTA highlights |
| **Subi Brown** | `#842F0A` | Deep earthy tone, headings, borders, wordmark |
| **Warm Beige** | `#E7D4B2` | Background panels, subtle borders, chips |
| **Crisp Cream** | `#FFFFFF` | Card surfaces, modal backdrops, typography |
| **Dark Charcoal**| `#2B2523` | High-contrast body text and readable copy |

---

## License

This project is proprietary and developed for **ONISUBI**. All rights reserved © 2026.
