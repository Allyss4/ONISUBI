ONISUBI ADMIN SYSTEM
====================

The ONISUBI Admin Panel is fully powered by unified PHP files integrated with Supabase RPCs and PostgreSQL:

1. Unified Architecture:
   - Each page is an authenticated PHP file with server-side data rendering and live Supabase client synchronization.
   - Guarded by admin_require_user() in config/admin.php.

2. Management Modules:
   - dashboard.php: Store overview, sales metrics, recent orders, inventory watch.
   - customers.php: Customer accounts, spending totals, order history, profile view.
   - staff.php: Staff and administrator directory, role management.
   - orders.php: Orders management, real-time status updates (RPC), customer & items breakdown.
   - products.php: Menu catalog, add/edit products, pricing, stock levels, image URLs.
   - inventory.php: Real-time stock counts, quick adjustment (+/-), low-stock alert thresholds.
   - reports.php: Customer verified purchase reviews, 5-star ratings, feedback comments.
   - payment-settings.php: GCash mobile number, account name, QR code, payment method toggles.

3. Static .html redirects:
   - Legacy .html files automatically forward to their corresponding .php files.
