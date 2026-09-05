CREATE DATABASE IF NOT EXISTS onisubi;
USE onisubi;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    role VARCHAR(50) DEFAULT 'customer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS Products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    stock INT DEFAULT 0,
    status VARCHAR(40) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    customer_name VARCHAR(120) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    address TEXT NOT NULL,
    notes TEXT,
    total DECIMAL(10,2) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'Order Placed',
    payment_method VARCHAR(100) DEFAULT 'Cash on Delivery',
    payment_status VARCHAR(40) NOT NULL DEFAULT 'pending',
    paymongo_checkout_id VARCHAR(100) DEFAULT NULL,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity INT DEFAULT 0,
    low_stock_limit INT DEFAULT 5,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES Products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert default products
INSERT IGNORE INTO Products (name, description, price, image, stock, status)
VALUES 
    ('Spam & Egg', 'Main flavor with savory spam and fluffy egg.', 40.00, 'https://balancewithjess.com/wp-content/uploads/2022/02/Spam-Musubi-with-Egg-Feat-500x500.jpg', 20, 'active'),
    ('Hotdog', 'A tasty hotdog-filled bite with rich, smoky flavor.', 40.00, 'https://www.sbfoods-worldwide.com/recipes/rst0f40000000t14-img/416_UMAMI_TOPPING_Onigiri_ichiran.jpg', 18, 'active'),
    ('Tuna Mayo', 'Creamy, savory tuna filling for a classic bite.', 40.00, 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcR5-EjRHu8gJ2nWryFFe9zgbQhBY7L4A1fH9eJmrOwyLZ9l7tGJzfo_QtQ3&s=10', 12, 'active'),
    ('Egg', 'Simple, comforting, and filling egg musubi favorite.', 40.00, 'https://japanesecooking101.com/wp-content/uploads/2023/10/IMG_6350.jpeg', 15, 'active');
