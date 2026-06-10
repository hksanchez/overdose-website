<?php
// Database configuration
// Update these credentials to match your local setup
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'overdose_cafe');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
$conn->select_db(DB_NAME);

// Create users table
$conn->query("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(50) NOT NULL,
        last_name VARCHAR(50) NOT NULL,
        phone VARCHAR(15) NOT NULL,
        address TEXT NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Create products table
$conn->query("
    CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        category VARCHAR(50) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        description TEXT,
        image VARCHAR(255),
        promo_price DECIMAL(10,2) DEFAULT NULL,
        is_promo TINYINT(1) DEFAULT 0
    )
");

// Create orders table
$conn->query("
    CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL,
        discount DECIMAL(10,2) DEFAULT 0,
        voucher_code VARCHAR(50),
        status VARCHAR(30) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

// Create order_items table
$conn->query("
    CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id),
        FOREIGN KEY (product_id) REFERENCES products(id)
    )
");

// Create vouchers table
$conn->query("
    CREATE TABLE IF NOT EXISTS vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        discount_type VARCHAR(20) NOT NULL,
        discount_value DECIMAL(10,2) NOT NULL,
        min_order DECIMAL(10,2) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1
    )
");

// Seed products if empty
$check = $conn->query("SELECT COUNT(*) as cnt FROM products");
$row = $check->fetch_assoc();
if ($row['cnt'] == 0) {
    $products = [
        // Coffee
        ['Overdose Latte', 'coffee', 139.00, 'Bold, triple-shot espresso blended with smooth, velvety milk for the ultimate kick.', 'assets/products/coffee5.jpg', null, 0],
        ['Caramel Macchiato', 'coffee', 129.00, 'Creamy steamed milk marked with espresso and drizzled with sweet caramel.', 'assets/products/coffee6.jpg', null, 0],
        ['Seasalt Caramel Macchiato', 'coffee', 139.00, 'A sweet and savory blend of rich caramel, bold espresso, and a touch of sea salt.', 'assets/products/coffee7.jpg', null, 0],
        ['Caffe Latte', 'coffee', 109.00, 'A comforting, classic balance of smooth espresso and silky steamed milk.', 'assets/products/coffee8.jpg', 119.00, 1],
        ['White Mocha Latte', 'coffee', 139.00, 'Rich espresso and velvety milk infused with sweet white chocolate sauce.', 'assets/products/coffee4.jpg', null, 0],
        ['Dark Mocha Latte', 'coffee', 139.00, 'A decadent fusion of bittersweet dark chocolate, bold espresso, and creamy milk.', 'assets/products/coffee3.jpg', null, 0],
        ['Spanish Latte', 'coffee', 139.00, 'A sweet, creamy delicacy pairing robust espresso with smooth condensed milk.', 'assets/products/coffee2.jpg', 149.00, 1],
        ['Americano', 'coffee', 99.00, 'Espresso diluted with hot water for a smooth, clean cup.', 'assets/products/coffee1.jpg', null, 0],
        
        // Pastries (Don't forget to add assets/products/ here too if they live there!)
        ['Croissant', 'pastries', 79.00, 'Buttery, flaky layers baked to golden perfection.', 'assets/products/croissant.jpg', null, 0],
        ['Chocolate Éclair', 'pastries', 89.00, 'Choux pastry filled with vanilla cream and chocolate glaze.', 'assets/products/eclair.jpg', null, 0],
        ['Cinnamon Roll', 'pastries', 99.00, 'Warm spiral roll with cream cheese frosting.', 'assets/products/cinnamon_roll.jpg', 79.00, 1],
        ['Blueberry Muffin', 'pastries', 75.00, 'Moist muffin bursting with real blueberries.', 'assets/products/blueberry_muffin.jpg', null, 0],
        ['Cheese Danish', 'pastries', 85.00, 'Flaky pastry with a creamy cheese center.', 'assets/products/cheese_danish.jpg', null, 0],
        ['Egg Tart', 'pastries', 65.00, 'Silky egg custard in a crisp tart shell.', 'assets/products/egg_tart.jpg', null, 0],
        ['Kouign Amann', 'pastries', 109.00, 'Caramelized Breton pastry with layers of sweet butter.', 'assets/products/kouign.jpg', 89.00, 1],
        ['Pain au Chocolat', 'pastries', 95.00, 'Double chocolate bar wrapped in laminated dough.', 'assets/products/pain_choc.jpg', null, 0],
    ];

    $stmt = $conn->prepare("INSERT INTO products (name, category, price, description, image, promo_price, is_promo) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($products as $p) {
        $stmt->bind_param("ssdssdi", $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6]);
        $stmt->execute();
    }
}
// Seed vouchers if empty
$checkV = $conn->query("SELECT COUNT(*) as cnt FROM vouchers");
$rowV = $checkV->fetch_assoc();
if ($rowV['cnt'] == 0) {
    $conn->query("INSERT INTO vouchers (code, discount_type, discount_value, min_order) VALUES
        ('OVERDOSE10', 'percent', 10, 200),
        ('FIRSTCUP', 'fixed', 50, 150),
        ('CAFFEINE20', 'percent', 20, 500)
    ");
}
?>
