<?php

$host = "localhost";
$username = "root";
$password = "";
$database = "onisubi";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'users'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $phoneCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
    if ($phoneCheck && $phoneCheck->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(40) NULL AFTER email");
    }

    $addressCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'address'");
    if ($addressCheck && $addressCheck->num_rows === 0) {
        if ($phoneCheck && $phoneCheck->num_rows > 0) {
            $conn->query("ALTER TABLE users ADD COLUMN address TEXT NULL AFTER phone");
        } else {
            $conn->query("ALTER TABLE users ADD COLUMN address TEXT NULL AFTER email");
        }
    }

    $passwordHashCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
    if ($passwordHashCheck && $passwordHashCheck->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL AFTER email");
    }

    $demoUserExists = $conn->query("SELECT id FROM users WHERE name = 'zleaxy' OR email = 'zleaxy' LIMIT 1");
    if ($demoUserExists && $demoUserExists->num_rows === 0) {
        $hash = password_hash('zleaxy156', PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (name, email, password_hash, password, phone, address, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $phone = '09123456789';
        $address = 'ONISUBI Demo Address';
        $role = 'customer';
        $stmt->bind_param('sssssss', 'zleaxy', 'zleaxy', $hash, $hash, $phone, $address, $role);
        $stmt->execute();
    }
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'orders'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $statusCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'status'");
    if ($statusCheck && $statusCheck->num_rows > 0) {
        $conn->query("UPDATE orders SET status='Order Placed' WHERE status IS NULL OR status='' OR status='Awaiting payment'");
        $conn->query("ALTER TABLE orders MODIFY status VARCHAR(40) NOT NULL DEFAULT 'Order Placed'");
    }
}

$productTableCheck = $conn->query("SHOW TABLES LIKE 'Products'");
if ($productTableCheck && $productTableCheck->num_rows > 0) {
    $products = [
        ['Spam & Egg', 'Main flavor with savory spam and fluffy egg.', '40.00', 'https://balancewithjess.com/wp-content/uploads/2022/02/Spam-Musubi-with-Egg-Feat-500x500.jpg', 20, 'active'],
        ['Hotdog', 'A tasty hotdog-filled bite with rich, smoky flavor.', '40.00', 'https://www.sbfoods-worldwide.com/recipes/rst0f40000000t14-img/416_UMAMI_TOPPING_Onigiri_ichiran.jpg', 18, 'active'],
        ['Tuna Mayo', 'Creamy, savory tuna filling for a classic bite.', '40.00', 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcR5-EjRHu8gJ2nWryFFe9zgbQhBY7L4A1fH9eJmrOwyLZ9l7tGJzfo_QtQ3&s=10', 12, 'active'],
        ['Egg', 'Simple, comforting, and filling egg musubi favorite.', '40.00', 'https://japanesecooking101.com/wp-content/uploads/2023/10/IMG_6350.jpeg', 15, 'active']
    ];

    $existingProducts = $conn->query('SELECT id, name FROM Products');
    $existingNames = [];
    while ($row = $existingProducts->fetch_assoc()) {
        $existingNames[strtolower($row['name'])] = (int) $row['id'];
    }

    $insertProductStmt = $conn->prepare('INSERT INTO Products (name, description, price, image, stock, status) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($products as $product) {
        $name = $product[0];
        if (isset($existingNames[strtolower($name)])) {
            continue;
        }
        $description = $product[1];
        $price = $product[2];
        $image = $product[3];
        $stock = (int) $product[4];
        $status = $product[5];
        $insertProductStmt->bind_param('ssdsis', $name, $description, $price, $image, $stock, $status);
        $insertProductStmt->execute();
    }

    $inventoryTableCheck = $conn->query("SHOW TABLES LIKE 'inventory'");
    if ($inventoryTableCheck && $inventoryTableCheck->num_rows > 0) {
        $productStmt = $conn->prepare('SELECT id, stock FROM Products WHERE name = ? LIMIT 1');
        $inventoryExistsStmt = $conn->prepare('SELECT id FROM inventory WHERE product_id = ? LIMIT 1');
        $insertInventoryStmt = $conn->prepare('INSERT INTO inventory (product_id, quantity, low_stock_limit, updated_at) VALUES (?, ?, ?, NOW())');
        foreach ($products as $product) {
            $name = $product[0];
            $productStmt->bind_param('s', $name);
            $productStmt->execute();
            $productRow = $productStmt->get_result()->fetch_assoc();
            if (!$productRow) {
                continue;
            }
            $productId = (int) $productRow['id'];
            $inventoryExistsStmt->bind_param('i', $productId);
            $inventoryExistsStmt->execute();
            $inventoryExistsStmt->store_result();
            if ($inventoryExistsStmt->num_rows === 0) {
                $quantity = (int) $productRow['stock'];
                $lowStockLimit = 5;
                $insertInventoryStmt->bind_param('iii', $productId, $quantity, $lowStockLimit);
                $insertInventoryStmt->execute();
            }
        }
    }
}

?>