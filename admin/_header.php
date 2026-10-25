<?php
declare(strict_types=1);

$activePage = $activePage ?? '';
$adminUser = $adminUser ?? admin_require_user();
$adminNavigation = [
    'dashboard' => ['Dashboard', 'dashboard.php'],
    'customers' => ['Customers', 'customers.php'],
    'staff'     => ['Staff & Admins', 'staff.php'],
    'orders'    => ['Orders', 'orders.php'],
    'products'  => ['Products', 'products.php'],
    'inventory' => ['Inventory', 'inventory.php'],
    'reports'   => ['Customer Reviews', 'reports.php'],
    'payment'   => ['Payment Settings', 'payment-settings.php'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ONISUBI Admin — <?= admin_escape(ucfirst($activePage)) ?></title>
    <link rel="icon" type="image/png" href="../assets/images/Onisubi_Logo.png">
    <link rel="apple-touch-icon" href="../assets/images/Onisubi_Logo.png">
    <link rel="stylesheet" href="assets/admin-ui.css">
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <img src="assets/images/onisubi-logo.svg" alt="ONISUBI">
            <div>
                ONI<span>SUBI</span>
                <small>ADMIN PANEL</small>
            </div>
        </div>
        <div class="nav-title">MANAGEMENT</div>
        <nav class="nav">
            <?php foreach ($adminNavigation as $key => [$label, $href]): ?>
                <a href="<?= $href ?>" class="<?= $activePage === $key ? 'active' : '' ?>" data-nav="<?= $href ?>">
                    <i class="dot"></i>
                    <?= admin_escape($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="bottom">
            <small><?= admin_escape(strtoupper($adminUser['role'] ?? 'ADMIN')) ?></small>
            <a href="../pages/auth.php?action=logout" style="display: inline-flex; align-items: center; gap: 6px; margin-top: 9px; font-weight: 700; color: #ffb4a2; text-decoration: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="vertical-align: middle;">
                    <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0z"/>
                    <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708z"/>
                </svg>
                Log Out
            </a>
        </div>
    </aside>
    <main class="main">
        <header class="top">
            <b>ONISUBI Admin</b>
            <div style="display: flex; align-items: center; gap: 16px;">
                <div class="user">
                    <span class="avatar"><?= admin_escape(strtoupper(substr((string) ($adminUser['name'] ?? 'A'), 0, 1))) ?></span>
                    <?= admin_escape($adminUser['name'] ?? 'Admin') ?>
                </div>
            </div>
        </header>
        <div class="content">
