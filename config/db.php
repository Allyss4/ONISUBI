<?php

$host     = "db.ghrexntoqduadflakijc.supabase.co";
$username = "postgres";
$password = "wFqNd8dyxWCeUHrZ";
$database = "postgres";
$port     = "5432";

try {
  $dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=require";

  $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper function to check if a table exists in PostgreSQL
function tableExists($conn, $tableName) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table");
    $stmt->execute(['table' => strtolower($tableName)]);
    return (bool)$stmt->fetchColumn();
}

// Helper function to check if a column exists in PostgreSQL
function columnExists($conn, $tableName, $columnName) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table AND column_name = :column");
    $stmt->execute(['table' => strtolower($tableName), 'column' => strtolower($columnName)]);
    return (bool)$stmt->fetchColumn();
}

// --- USERS TABLE SETUP ---
if (tableExists($conn, 'users')) {
    if (!columnExists($conn, 'users', 'phone')) {
        $conn->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(40) NULL");
    }

    if (!columnExists($conn, 'users', 'address')) {
        $conn->exec("ALTER TABLE users ADD COLUMN address TEXT NULL");
    }

    if (!columnExists($conn, 'users', 'password_hash')) {
        $conn->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL");
    }

    // Check and seed demo user
    $stmt = $conn->prepare("SELECT id FROM users WHERE name = :name OR email = :email LIMIT 1");
    $stmt->execute(['name' => 'zleaxy', 'email' => 'zleaxy']);
    if (!$stmt->fetch()) {
        $hash = password_hash('zleaxy156', PASSWORD_DEFAULT);
        
        // Note: Make sure your users table has a 'password' column if including it in INSERT
        $insertUser = $conn->prepare("INSERT INTO users (name, email, password_hash, phone, address, role, created_at) VALUES (:name, :email, :hash, :phone, :address, :role, CURRENT_TIMESTAMP)");
        $insertUser->execute([
            'name'     => 'zleaxy',
            'email'    => 'zleaxy',
            'hash'     => $hash,
            'phone'    => '09123456789',
            'address'  => 'ONISUBI Demo Address',
            'role'     => 'customer'
        ]);
    }
}

// --- ORDERS TABLE SETUP ---
if (tableExists($conn, 'orders')) {
    if (columnExists($conn, 'orders', 'status')) {
        $conn->exec("UPDATE orders SET status = 'Order Placed' WHERE status IS NULL OR status = '' OR status = 'Awaiting payment'");
        $conn->exec("ALTER TABLE orders ALTER COLUMN status SET DATA TYPE VARCHAR(40), ALTER COLUMN status SET DEFAULT 'Order Placed', ALTER COLUMN status SET NOT NULL");
    }
}

// --- PRODUCTS & INVENTORY SETUP ---
if (tableExists($conn, 'products')) {
    $products = [
        ['Spam & Egg', 'Main flavor with savory spam and fluffy egg.', '40.00', 'https://balancewithjess.com/wp-content/uploads/2022/02/Spam-Musubi-with-Egg-Feat-500x500.jpg', 20, 'active'],
        ['Hotdog', 'A tasty hotdog-filled bite with rich, smoky flavor.', '40.00', 'https://www.sbfoods-worldwide.com/recipes/rst0f40000000t14-img/416_UMAMI_TOPPING_Onigiri_ichiran.jpg', 18, 'active'],
        ['Tuna Mayo', 'Creamy, savory tuna filling for a classic bite.', '40.00', 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcR5-EjRHu8gJ2nWryFFe9zgbQhBY7L4A1fH9eJmrOwyLZ9l7tGJzfo_QtQ3&s=10', 12, 'active'],
        ['Egg', 'Simple, comforting, and filling egg musubi favorite.', '40.00', 'https://japanesecooking101.com/wp-content/uploads/2023/10/IMG_6350.jpeg', 15, 'active']
    ];

    $existingProducts = $conn->query("SELECT id, name FROM products")->fetchAll();
    $existingNames = [];
    foreach ($existingProducts as $row) {
        $existingNames[strtolower($row['name'])] = (int)$row['id'];
    }

    $insertProductStmt = $conn->prepare("INSERT INTO products (name, description, price, image, stock, status) VALUES (:name, :desc, :price, :image, :stock, :status)");

    foreach ($products as $product) {
        $name = $product[0];
        if (isset($existingNames[strtolower($name)])) {
            continue;
        }

        $insertProductStmt->execute([
            'name'   => $name,
            'desc'   => $product[1],
            'price'  => $product[2],
            'image'  => $product[3],
            'stock'  => (int)$product[4],
            'status' => $product[5]
        ]);
    }

    // --- INVENTORY SETUP ---
    if (tableExists($conn, 'inventory')) {
        $productStmt = $conn->prepare("SELECT id, stock FROM products WHERE name = :name LIMIT 1");
        $inventoryExistsStmt = $conn->prepare("SELECT id FROM inventory WHERE product_id = :product_id LIMIT 1");
        $insertInventoryStmt = $conn->prepare("INSERT INTO inventory (product_id, quantity, low_stock_limit, updated_at) VALUES (:product_id, :quantity, :low_stock_limit, CURRENT_TIMESTAMP)");

        foreach ($products as $product) {
            $name = $product[0];
            $productStmt->execute(['name' => $name]);
            $productRow = $productStmt->fetch();

            if (!$productRow) {
                continue;
            }

            $productId = (int)$productRow['id'];
            $inventoryExistsStmt->execute(['product_id' => $productId]);

            if (!$inventoryExistsStmt->fetch()) {
                $quantity = (int)$productRow['stock'];
                $lowStockLimit = 5;

                $insertInventoryStmt->execute([
                    'product_id'      => $productId,
                    'quantity'        => $quantity,
                    'low_stock_limit' => $lowStockLimit
                ]);
            }
        }
    }
}

?>