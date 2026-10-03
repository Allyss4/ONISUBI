<?php
$activePage = $activePage ?? '';
$adminUser = $adminUser ?? admin_require_user();
$adminNavigation = [
    'dashboard' => ['Dashboard', 'dashboard.php'],
    'products' => ['Products', 'products.php'],
    'inventory' => ['Inventory', 'inventory.php'],
    'orders' => ['Orders', 'orders.php'],
    'users' => ['Users', 'users.php'],
    'reports' => ['Customer Reviews', 'reports.php'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ONISUBI Admin | <?= $activePage === 'reports' ? 'Customer Reviews' : admin_escape(ucfirst($activePage)) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">
    <aside class="admin-sidebar" id="admin-sidebar">
        <a class="admin-brand" href="dashboard.php">
            <img src="../assets/images/onisubi-logo.svg" alt="">
            <span><strong>ONI</strong>SUBI <small>OPERATIONS</small></span>
        </a>
        <span class="admin-nav-label">WORKSPACE</span>
        <nav class="admin-navigation" aria-label="Admin navigation">
            <?php foreach ($adminNavigation as $key => [$label, $href]): ?>
                <a class="<?= $activePage === $key ? 'is-active' : '' ?>" href="<?= admin_escape($href) ?>" <?= $activePage === $key ? 'aria-current="page"' : '' ?>>
                    <span class="admin-nav-mark" aria-hidden="true"></span><?= admin_escape($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-bottom">
            <a href="https://onisubi.onrender.com/" target="_blank" rel="noopener noreferrer">View Customer Website</a>
            <span>ONISUBI · ADMIN</span>
        </div>
    </aside>
    <div class="admin-main">
        <header class="admin-topbar">
            <button class="admin-menu-toggle" type="button" aria-label="Toggle admin navigation" aria-expanded="false" data-admin-menu-toggle><span></span><span></span></button>
            <div class="admin-topbar-label"><span class="admin-live-dot"></span> Store operations</div>
            <div class="admin-user"><span class="admin-user-avatar"><?= admin_escape(strtoupper(substr((string) $adminUser['name'], 0, 1))) ?></span><span><?= admin_escape($adminUser['name']) ?></span><a href="../auth.php?action=logout">Log out</a></div>
        </header>
        <main class="admin-content">
