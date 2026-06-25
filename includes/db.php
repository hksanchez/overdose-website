<?php
// ════════════════════════════════════════════════════════════════════════════
// DATABASE CONFIGURATION
// ════════════════════════════════════════════════════════════════════════════
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'overdose_cafe_test');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create and select database
$conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
$conn->select_db(DB_NAME);

// ════════════════════════════════════════════════════════════════════════════
// TABLE DEFINITIONS
// All tables are defined here. Do NOT create tables in any other file.
// ════════════════════════════════════════════════════════════════════════════

// ── Customer accounts ────────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS users (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(50)  NOT NULL,
        last_name  VARCHAR(50)  NOT NULL,
        phone      VARCHAR(15)  NOT NULL,
        address    TEXT         NOT NULL,
        password   VARCHAR(255) NOT NULL,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

// ── Site Settings ────────────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS site_settings (
        setting_key   VARCHAR(50) PRIMARY KEY,
        setting_value TEXT
    )
");

$chkSS = $conn->query("SELECT COUNT(*) as c FROM site_settings");
if ($chkSS->fetch_assoc()['c'] == 0) {
    $conn->query("INSERT INTO site_settings (setting_key, setting_value) VALUES 
        ('store_status', 'online'),
        ('store_hours', 'Mon-Sun: 8:00 AM - 9:00 PM')
    ");
}

// ── Staff / admin accounts ───────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS admin_users (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(50)  NOT NULL UNIQUE,
        password   VARCHAR(255) NOT NULL,
        full_name  VARCHAR(100) NOT NULL,
        is_active  TINYINT(1)   DEFAULT 1,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS staff_users (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(50)  NOT NULL UNIQUE,
        password   VARCHAR(255) NOT NULL,
        full_name  VARCHAR(100) NOT NULL,
        is_active  TINYINT(1)   DEFAULT 1,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS rider_users (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(50)  NOT NULL UNIQUE,
        password   VARCHAR(255) NOT NULL,
        full_name  VARCHAR(100) NOT NULL,
        is_active  TINYINT(1)   DEFAULT 1,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

// ── Products ─────────────────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS products (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(100)   NOT NULL,
        category    VARCHAR(50)    NOT NULL,
        price       DECIMAL(10,2)  NOT NULL,
        description TEXT,
        image       VARCHAR(255),
        promo_price DECIMAL(10,2)  DEFAULT NULL,
        is_promo    TINYINT(1)     DEFAULT 0
    )
");

// ── Orders (includes fulfillment columns) ────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS orders (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT           NOT NULL,
        total_amount     DECIMAL(10,2) NOT NULL,
        discount         DECIMAL(10,2) DEFAULT 0,
        voucher_code     VARCHAR(50),
        status           VARCHAR(30)   DEFAULT 'Pending',
        fulfillment_type VARCHAR(10)   DEFAULT 'pickup',
        delivery_address TEXT          DEFAULT NULL,
        order_note       TEXT          DEFAULT NULL,
        created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

// Safe migration: add order_note and is_viewed to existing tables that don't have it yet
$col_chk = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_note'");
if ($col_chk && $col_chk->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN order_note TEXT DEFAULT NULL AFTER delivery_address");
}
$col_chk = $conn->query("SHOW COLUMNS FROM orders LIKE 'is_viewed'");
if ($col_chk && $col_chk->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN is_viewed TINYINT(1) DEFAULT 0 AFTER order_note");
}


// ── Order items ───────────────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS order_items (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        order_id   INT           NOT NULL,
        product_id INT           NOT NULL,
        quantity   INT           NOT NULL,
        price      DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (order_id)   REFERENCES orders(id),
        FOREIGN KEY (product_id) REFERENCES products(id)
    )
");

// ── Vouchers ──────────────────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS vouchers (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        code           VARCHAR(50)   NOT NULL UNIQUE,
        discount_type  VARCHAR(20)   NOT NULL,
        discount_value DECIMAL(10,2) NOT NULL,
        min_order      DECIMAL(10,2) DEFAULT 0,
        is_active      TINYINT(1)    DEFAULT 1
    )
");

// ── Inventory ─────────────────────────────────────────────────────────────────
// linked_product_id ties a pastry inventory row to its product row.
$conn->query("
    CREATE TABLE IF NOT EXISTS inventory (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        item_name           VARCHAR(100) NOT NULL,
        category            VARCHAR(50)  NOT NULL,
        quantity            INT          NOT NULL DEFAULT 0,
        unit                VARCHAR(20)  NOT NULL DEFAULT 'pcs',
        low_stock_threshold INT          NOT NULL DEFAULT 20,
        linked_product_id   INT          DEFAULT NULL,
        updated_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// ════════════════════════════════════════════════════════════════════════════
// SEED DATA
// Each block only inserts if the table is empty — safe to run on every request.
// ════════════════════════════════════════════════════════════════════════════

// ── Default admin account ─────────────────────────────────────────────────────
$chkA = $conn->query("SELECT COUNT(*) as c FROM admin_users");
if ($chkA->fetch_assoc()['c'] == 0) {
    $pw = password_hash('admin123', PASSWORD_DEFAULT);
    $s = $conn->prepare("INSERT INTO admin_users (username, password, full_name, is_active) VALUES (?,?,?,1)");
    $u = 'admin';
    $n = 'Admin';
    $s->bind_param("sss", $u, $pw, $n);
    $s->execute();
}

// ── Default staff account ─────────────────────────────────────────────────────
$chkS = $conn->query("SELECT COUNT(*) as c FROM staff_users");
if ($chkS->fetch_assoc()['c'] == 0) {
    $pw = password_hash('staff123', PASSWORD_DEFAULT);
    $s = $conn->prepare("INSERT INTO staff_users (username, password, full_name, is_active) VALUES (?,?,?,1)");
    $u = 'staff';
    $n = 'Staff Member';
    $s->bind_param("sss", $u, $pw, $n);
    $s->execute();
}

// ── Default rider account ─────────────────────────────────────────────────────
$chkR = $conn->query("SELECT COUNT(*) as c FROM rider_users");
if ($chkR->fetch_assoc()['c'] == 0) {
    $pw = password_hash('rider123', PASSWORD_DEFAULT);
    $s = $conn->prepare("INSERT INTO rider_users (username, password, full_name, is_active) VALUES (?,?,?,1)");
    $u = 'rider';
    $n = 'Rider';
    $s->bind_param("sss", $u, $pw, $n);
    $s->execute();
}

// ── Default products ──────────────────────────────────────────────────────────
$check = $conn->query("SELECT COUNT(*) as cnt FROM products");
if ($check->fetch_assoc()['cnt'] == 0) {
    $products = [
        // Coffee
        ['Overdose Latte', 'coffee', 139.00, 'Bold, triple-shot espresso blended with smooth, velvety milk for the ultimate kick.', 'assets/products/coffee10.jpg', null, 0],
        ['Caramel Macchiato', 'coffee', 129.00, 'Creamy steamed milk marked with espresso and drizzled with sweet caramel.', 'assets/products/coffee6.jpg', null, 0],
        ['Seasalt Caramel Macchiato', 'coffee', 139.00, 'A sweet and savory blend of rich caramel, bold espresso, and a touch of sea salt.', 'assets/products/coffee7.jpg', null, 0],
        ['Caffe Latte', 'coffee', 109.00, 'A comforting, classic balance of smooth espresso and silky steamed milk.', 'assets/products/coffee8.jpg', 119.00, 1],
        ['White Mocha Latte', 'coffee', 139.00, 'Rich espresso and velvety milk infused with sweet white chocolate sauce.', 'assets/products/coffee4.jpg', null, 0],
        ['Dark Mocha Latte', 'coffee', 139.00, 'A decadent fusion of bittersweet dark chocolate, bold espresso, and creamy milk.', 'assets/products/coffee3.jpg', null, 0],
        ['Spanish Latte', 'coffee', 139.00, 'A sweet, creamy delicacy pairing robust espresso with smooth condensed milk.', 'assets/products/coffee2.jpg', 149.00, 1],
        ['Americano', 'coffee', 99.00, 'Espresso diluted with hot water for a smooth, clean cup.', 'assets/products/coffee1.jpg', null, 0],
        // Pastries
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

// ── Default inventory ─────────────────────────────────────────────────────────
$chkI = $conn->query("SELECT COUNT(*) as c FROM inventory");
if ($chkI->fetch_assoc()['c'] == 0) {
    // Supplies
    $conn->query("INSERT INTO inventory (item_name, category, quantity, unit, low_stock_threshold) VALUES
        ('Cups',   'supplies', 200, 'pcs', 50),
        ('Lids',   'supplies', 200, 'pcs', 50),
        ('Straws', 'supplies', 200, 'pcs', 50)
    ");
    // One inventory row per pastry product
    $pastries = $conn->query("SELECT id, name FROM products WHERE category = 'pastries'");
    if ($pastries) {
        $ins = $conn->prepare("INSERT INTO inventory (item_name, category, quantity, unit, low_stock_threshold, linked_product_id) VALUES (?, 'pastries', 25, 'pcs', 10, ?)");
        while ($p = $pastries->fetch_assoc()) {
            $ins->bind_param("si", $p['name'], $p['id']);
            $ins->execute();
        }
    }
}

// ── Default vouchers ──────────────────────────────────────────────────────────
$checkV = $conn->query("SELECT COUNT(*) as cnt FROM vouchers");
if ($checkV->fetch_assoc()['cnt'] == 0) {
    $conn->query("INSERT INTO vouchers (code, discount_type, discount_value, min_order) VALUES
        ('OVERDOSE10', 'percent', 10,  200),
        ('FIRSTCUP',   'fixed',   50,  150),
        ('CAFFEINE20', 'percent', 20,  500)
    ");
}
?>