<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin.php';
admin_require_user();
header('Location: customers.php');
exit;
