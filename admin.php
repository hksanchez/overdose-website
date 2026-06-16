<?php
session_start();
require_once 'includes/db.php';

// ── ADMIN ROLES ──────────────────────────────────────────────────────────────
// Create admin_users table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('superadmin','staff','rider') NOT NULL DEFAULT 'staff',
        full_name VARCHAR(100) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Create inventory table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(100) NOT NULL,
        category VARCHAR(50) NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
        low_stock_threshold INT NOT NULL DEFAULT 20,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// Ensure fulfillment columns exist in orders
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS fulfillment_type VARCHAR(10) DEFAULT 'pickup'");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_address TEXT DEFAULT NULL");

// Seed default superadmin if none
$chk = $conn->query("SELECT COUNT(*) as c FROM admin_users");
$r = $chk->fetch_assoc();
if ($r['c'] == 0) {
    $pw = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO admin_users (username, password, role, full_name) VALUES
        ('superadmin', '$pw', 'superadmin', 'Super Admin'),
        ('" . $conn->real_escape_string(password_hash('staff123', PASSWORD_DEFAULT)) . "', '" . password_hash('staff123', PASSWORD_DEFAULT) . "', 'staff', 'Staff Member'),
        ('" . $conn->real_escape_string('rider01') . "', '" . password_hash('rider123', PASSWORD_DEFAULT) . "', 'rider', 'Delivery Rider')
    ");
    // Re-seed properly
    $conn->query("DELETE FROM admin_users");
    $pw_super = password_hash('admin123', PASSWORD_DEFAULT);
    $pw_staff = password_hash('staff123', PASSWORD_DEFAULT);
    $pw_rider = password_hash('rider123', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO admin_users (username, password, role, full_name) VALUES
        ('superadmin', '$pw_super', 'superadmin', 'Super Admin'),
        ('staff01', '$pw_staff', 'staff', 'Staff Member'),
        ('rider01', '$pw_rider', 'rider', 'Delivery Rider')
    ");
}

// Seed inventory if empty
$inv = $conn->query("SELECT COUNT(*) as c FROM inventory");
$inv_r = $inv->fetch_assoc();
if ($inv_r['c'] == 0) {
    $conn->query("INSERT INTO inventory (item_name, category, quantity, unit, low_stock_threshold) VALUES
        ('Straws', 'supplies', 200, 'pcs', 50),
        ('Small Cups', 'supplies', 150, 'pcs', 30),
        ('Medium Cups', 'supplies', 120, 'pcs', 30),
        ('Large Cups', 'supplies', 80, 'pcs', 20),
        ('Cup Lids (Small)', 'supplies', 150, 'pcs', 30),
        ('Cup Lids (Large)', 'supplies', 80, 'pcs', 20),
        ('Croissant', 'pastries', 25, 'pcs', 10),
        ('Cinnamon Roll', 'pastries', 18, 'pcs', 10),
        ('Blueberry Muffin', 'pastries', 30, 'pcs', 10),
        ('Chocolate Éclair', 'pastries', 22, 'pcs', 8),
        ('Cheese Danish', 'pastries', 15, 'pcs', 8),
        ('Egg Tart', 'pastries', 40, 'pcs', 10),
        ('Kouign Amann', 'pastries', 12, 'pcs', 5),
        ('Pain au Chocolat', 'pastries', 20, 'pcs', 8)
    ");
}

// ── AUTH ──────────────────────────────────────────────────────────────────────
$admin_error = '';

if (isset($_POST['admin_login'])) {
    $uname = trim($_POST['username']);
    $pass  = $_POST['password'];
    $stmt  = $conn->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
    $stmt->bind_param("s", $uname);
    $stmt->execute();
    $adm = $stmt->get_result()->fetch_assoc();
    if ($adm && password_verify($pass, $adm['password'])) {
        $_SESSION['admin_id']   = $adm['id'];
        $_SESSION['admin_role'] = $adm['role'];
        $_SESSION['admin_name'] = $adm['full_name'];
        $_SESSION['admin_user'] = $adm['username'];
        header("Location: admin.php");
        exit();
    } else {
        $admin_error = 'Invalid username or password.';
    }
}

if (isset($_GET['admin_logout'])) {
    unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_name'], $_SESSION['admin_user']);
    header("Location: admin.php");
    exit();
}

$is_admin  = isset($_SESSION['admin_id']);
$role      = $_SESSION['admin_role'] ?? '';
$is_super  = $role === 'superadmin';
$is_staff  = $role === 'staff';
$is_rider  = $role === 'rider';

// ── ACTION HANDLERS (authenticated) ──────────────────────────────────────────
$action_msg = '';

if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Update order status (staff/super/rider) ---
    if (isset($_POST['update_order_status'])) {
        $oid    = (int)$_POST['order_id'];
        $status = $_POST['new_status'];
        $allowed = ['Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled'];
        if (in_array($status, $allowed)) {
            // Riders can only set Out for Delivery / Completed
            if ($is_rider && !in_array($status, ['Out for Delivery','Completed'])) {
                $action_msg = 'error:Riders can only mark orders as Out for Delivery or Completed.';
            } else {
                $upd = $conn->prepare("UPDATE orders SET status=? WHERE id=?");
                $upd->bind_param("si", $status, $oid);
                $upd->execute();
                $action_msg = 'success:Order #' . $oid . ' status updated to ' . $status . '.';
            }
        }
    }

    // --- Inventory update (staff/super) ---
    if (isset($_POST['update_inventory']) && ($is_staff || $is_super)) {
        $iid = (int)$_POST['inv_id'];
        $qty = (int)$_POST['quantity'];
        $thresh = (int)$_POST['threshold'];
        $upd = $conn->prepare("UPDATE inventory SET quantity=?, low_stock_threshold=? WHERE id=?");
        $upd->bind_param("iii", $qty, $thresh, $iid);
        $upd->execute();
        $action_msg = 'success:Inventory updated.';
    }

    // --- Add inventory item (staff/super) ---
    if (isset($_POST['add_inventory']) && ($is_staff || $is_super)) {
        $name  = trim($_POST['item_name']);
        $cat   = trim($_POST['item_cat']);
        $qty   = (int)$_POST['item_qty'];
        $unit  = trim($_POST['item_unit']);
        $thresh = (int)$_POST['item_thresh'];
        $ins = $conn->prepare("INSERT INTO inventory (item_name, category, quantity, unit, low_stock_threshold) VALUES (?,?,?,?,?)");
        $ins->bind_param("ssisi", $name, $cat, $qty, $unit, $thresh);
        $ins->execute();
        $action_msg = 'success:Inventory item added.';
    }

    // --- Delete inventory item (super only) ---
    if (isset($_POST['delete_inventory']) && $is_super) {
        $iid = (int)$_POST['inv_id'];
        $del = $conn->prepare("DELETE FROM inventory WHERE id=?");
        $del->bind_param("i", $iid);
        $del->execute();
        $action_msg = 'success:Item removed from inventory.';
    }

    // --- Product actions (super only) ---
    if ($is_super) {
        if (isset($_POST['add_product'])) {
            $name  = trim($_POST['pname']);
            $cat   = trim($_POST['pcat']);
            $price = (float)$_POST['pprice'];
            $desc  = trim($_POST['pdesc']);
            $img   = trim($_POST['pimg']);
            $promo = isset($_POST['pis_promo']) ? 1 : 0;
            $pprice = $promo ? (float)$_POST['ppromo_price'] : null;
            $ins = $conn->prepare("INSERT INTO products (name,category,price,description,image,promo_price,is_promo) VALUES (?,?,?,?,?,?,?)");
            $ins->bind_param("ssdssdi", $name, $cat, $price, $desc, $img, $pprice, $promo);
            $ins->execute();
            $action_msg = 'success:Product added.';
        }
        if (isset($_POST['edit_product'])) {
            $pid   = (int)$_POST['pid'];
            $name  = trim($_POST['pname']);
            $cat   = trim($_POST['pcat']);
            $price = (float)$_POST['pprice'];
            $desc  = trim($_POST['pdesc']);
            $img   = trim($_POST['pimg']);
            $promo = isset($_POST['pis_promo']) ? 1 : 0;
            $pprice = $promo ? (float)$_POST['ppromo_price'] : null;
            $upd = $conn->prepare("UPDATE products SET name=?,category=?,price=?,description=?,image=?,promo_price=?,is_promo=? WHERE id=?");
            $upd->bind_param("ssdssdii", $name, $cat, $price, $desc, $img, $pprice, $promo, $pid);
            $upd->execute();
            $action_msg = 'success:Product updated.';
        }
        if (isset($_POST['delete_product'])) {
            $pid = (int)$_POST['pid'];
            $del = $conn->prepare("DELETE FROM products WHERE id=?");
            $del->bind_param("i", $pid);
            $del->execute();
            $action_msg = 'success:Product deleted.';
        }
        // Voucher actions
        if (isset($_POST['add_voucher'])) {
            $code  = strtoupper(trim($_POST['vcode']));
            $type  = $_POST['vtype'];
            $val   = (float)$_POST['vval'];
            $min   = (float)$_POST['vmin'];
            $ins = $conn->prepare("INSERT INTO vouchers (code,discount_type,discount_value,min_order,is_active) VALUES (?,?,?,?,1)");
            $ins->bind_param("ssdd", $code, $type, $val, $min);
            if (!$ins->execute()) $action_msg = 'error:Code already exists.';
            else $action_msg = 'success:Voucher added.';
        }
        if (isset($_POST['toggle_voucher'])) {
            $vid = (int)$_POST['vid'];
            $conn->query("UPDATE vouchers SET is_active = NOT is_active WHERE id=$vid");
            $action_msg = 'success:Voucher status toggled.';
        }
        if (isset($_POST['delete_voucher'])) {
            $vid = (int)$_POST['vid'];
            $conn->query("DELETE FROM vouchers WHERE id=$vid");
            $action_msg = 'success:Voucher deleted.';
        }
        // Account management
        if (isset($_POST['add_admin_user'])) {
            $aname = trim($_POST['a_username']);
            $afull = trim($_POST['a_fullname']);
            $arole = $_POST['a_role'];
            $apw   = password_hash($_POST['a_password'], PASSWORD_DEFAULT);
            $ins = $conn->prepare("INSERT INTO admin_users (username,password,role,full_name) VALUES (?,?,?,?)");
            $ins->bind_param("ssss", $aname, $apw, $arole, $afull);
            if (!$ins->execute()) $action_msg = 'error:Username already exists.';
            else $action_msg = 'success:Account created.';
        }
        if (isset($_POST['toggle_admin_user'])) {
            $aid = (int)$_POST['aid'];
            if ($aid !== (int)$_SESSION['admin_id']) {
                $conn->query("UPDATE admin_users SET is_active = NOT is_active WHERE id=$aid");
                $action_msg = 'success:Account status toggled.';
            }
        }
        if (isset($_POST['delete_admin_user'])) {
            $aid = (int)$_POST['aid'];
            if ($aid !== (int)$_SESSION['admin_id']) {
                $conn->query("DELETE FROM admin_users WHERE id=$aid");
                $action_msg = 'success:Account deleted.';
            }
        }
    }
}

// ── DATA FETCH ────────────────────────────────────────────────────────────────
if ($is_admin) {
    // Dashboard stats
    $today = date('Y-m-d');
    $sales_today = $conn->query("SELECT COALESCE(SUM(total_amount),0) as s FROM orders WHERE DATE(created_at)='$today' AND status NOT IN ('Cancelled')")->fetch_assoc()['s'];
    $orders_today = $conn->query("SELECT COUNT(*) as c FROM orders WHERE DATE(created_at)='$today'")->fetch_assoc()['c'];
    $orders_completed = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Completed'")->fetch_assoc()['c'];

    // Best selling product
    $best = $conn->query("SELECT p.name, SUM(oi.quantity) as total FROM order_items oi JOIN products p ON oi.product_id=p.id GROUP BY oi.product_id ORDER BY total DESC LIMIT 1")->fetch_assoc();
    $best_product = $best ? $best['name'] . ' (' . $best['total'] . ' sold)' : 'N/A';

    // Low stock alerts
    $low_stock = $conn->query("SELECT * FROM inventory WHERE quantity <= low_stock_threshold ORDER BY (quantity/low_stock_threshold) ASC")->fetch_all(MYSQLI_ASSOC);

    // All orders
    $all_orders = $conn->query("
        SELECT o.*, u.first_name, u.last_name, u.phone
        FROM orders o JOIN users u ON o.user_id=u.id
        ORDER BY o.created_at DESC LIMIT 100
    ")->fetch_all(MYSQLI_ASSOC);

    // Inventory
    $inventory_items = $conn->query("SELECT * FROM inventory ORDER BY category, item_name")->fetch_all(MYSQLI_ASSOC);

    // Products (super)
    $products = $conn->query("SELECT * FROM products ORDER BY category, name")->fetch_all(MYSQLI_ASSOC);

    // Vouchers (super)
    $vouchers = $conn->query("SELECT * FROM vouchers ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);

    // Admin accounts (super)
    $admin_accounts = $conn->query("SELECT * FROM admin_users ORDER BY role, id")->fetch_all(MYSQLI_ASSOC);

    // Pending orders count for badge
    $pending_count = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Pending'")->fetch_assoc()['c'];
    $low_stock_count = count($low_stock);
}

// Current section
$section = $_GET['section'] ?? 'dashboard';

// ── STATUS CONFIG ─────────────────────────────────────────────────────────────
$status_colors = [
    'Pending'          => '#D4AF5A',
    'Preparing'        => '#5B9BD4',
    'Ready'            => '#5BAD7E',
    'Out for Delivery' => '#9B7BD4',
    'Completed'        => '#5BAD7E',
    'Cancelled'        => '#E05555',
];
$all_statuses = ['Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled'];
$rider_statuses = ['Out for Delivery','Completed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin — Overdose Cafe</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=DM+Sans:wght@300;400;500;600&display=swap"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0D0A06; --surface: #131008; --panel: #1A1208; --card: #1F1610;
      --border: rgba(212,175,90,0.15); --border-h: rgba(212,175,90,0.35);
      --gold: #D4AF5A; --gold-l: #F0D080; --cream: #F5EDD8;
      --muted: rgba(245,237,216,0.45); --muted2: rgba(245,237,216,0.25);
      --error: #E05555; --success: #5BAD7E; --info: #5B9BD4;
      --sidebar-w: 220px; --nav-h: 62px;
    }
    body { background: var(--bg); color: var(--cream); font-family: 'DM Sans', sans-serif; min-height: 100vh; }

    /* ── LOGIN PAGE ── */
    .login-wrap {
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
      background: radial-gradient(ellipse 60% 50% at 20% 50%, rgba(100,60,10,0.2) 0%, transparent 70%);
    }
    .login-box {
      width: 400px; background: var(--surface); border: 1px solid var(--border);
      border-radius: 6px; padding: 48px 40px;
      box-shadow: 0 32px 80px rgba(0,0,0,0.5);
    }
    .login-logo { font-family: 'Playfair Display', serif; font-size: 1rem; font-weight: 700; letter-spacing: 4px; text-transform: uppercase; color: var(--gold); margin-bottom: 8px; }
    .login-title { font-size: 1.5rem; font-weight: 700; color: var(--cream); margin-bottom: 4px; }
    .login-sub { font-size: 0.82rem; color: var(--muted); margin-bottom: 32px; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; font-size: 0.7rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--gold); margin-bottom: 7px; }
    .form-group input, .form-group select, .form-group textarea {
      width: 100%; background: var(--panel); border: 1px solid var(--border); border-radius: 3px;
      padding: 10px 14px; font-family: 'DM Sans', sans-serif; font-size: 0.88rem; color: var(--cream); outline: none;
      transition: border-color 0.2s; resize: none;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--gold); }
    .form-group input::placeholder, .form-group textarea::placeholder { color: rgba(245,237,216,0.2); }
    .form-group select option { background: #1A1208; }

    /* ── LAYOUT ── */
    .admin-layout { display: flex; min-height: 100vh; }

    /* ── SIDEBAR ── */
    .admin-sidebar {
      width: var(--sidebar-w); background: var(--surface); border-right: 1px solid var(--border);
      display: flex; flex-direction: column; position: fixed; top: 0; left: 0; height: 100vh; z-index: 50; overflow-y: auto;
    }
    .sidebar-logo { padding: 24px 20px 20px; border-bottom: 1px solid var(--border); }
    .sidebar-logo-text { font-family: 'Playfair Display', serif; font-size: 0.9rem; font-weight: 700; letter-spacing: 3px; text-transform: uppercase; color: var(--gold); }
    .sidebar-logo-sub { font-size: 0.65rem; color: var(--muted2); margin-top: 3px; letter-spacing: 1px; text-transform: uppercase; }
    .sidebar-nav { flex: 1; padding: 20px 12px; }
    .sidebar-section { font-size: 0.6rem; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--gold); opacity: 0.5; padding: 0 8px; margin: 16px 0 8px; }
    .sidebar-item {
      display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 4px;
      font-size: 0.82rem; font-weight: 500; color: var(--muted); text-decoration: none;
      transition: all 0.18s; border-left: 2px solid transparent; margin-bottom: 2px; position: relative;
    }
    .sidebar-item:hover { color: var(--cream); background: rgba(212,175,90,0.05); }
    .sidebar-item.active { color: var(--cream); background: rgba(212,175,90,0.08); border-left-color: var(--gold); font-weight: 600; }
    .sidebar-item .icon { width: 18px; text-align: center; font-size: 0.95rem; }
    .sidebar-badge { background: var(--error); color: #fff; font-size: 0.6rem; font-weight: 700; padding: 1px 6px; border-radius: 10px; margin-left: auto; }
    .sidebar-footer { padding: 16px 12px 20px; border-top: 1px solid var(--border); }
    .sidebar-user { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding: 8px 12px; background: rgba(212,175,90,0.05); border-radius: 4px; }
    .sidebar-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--gold); color: #0D0A06; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .sidebar-uname { font-size: 0.78rem; font-weight: 600; color: var(--cream); }
    .sidebar-role-badge { font-size: 0.6rem; color: var(--gold); text-transform: uppercase; letter-spacing: 1px; }
    .btn-logout { display: block; width: 100%; text-align: center; padding: 8px; font-size: 0.75rem; font-weight: 600; color: rgba(224,85,85,0.7); border: 1px solid rgba(224,85,85,0.2); border-radius: 3px; text-decoration: none; transition: all 0.2s; }
    .btn-logout:hover { color: var(--error); border-color: rgba(224,85,85,0.5); background: rgba(224,85,85,0.06); }

    /* ── MAIN CONTENT ── */
    .admin-main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
    .admin-topbar {
      position: sticky; top: 0; z-index: 40; height: var(--nav-h); background: rgba(13,10,6,0.95);
      backdrop-filter: blur(10px); border-bottom: 1px solid var(--border);
      display: flex; align-items: center; padding: 0 36px; gap: 16px;
    }
    .topbar-title { font-family: 'Playfair Display', serif; font-size: 1.1rem; font-weight: 700; color: var(--cream); }
    .topbar-spacer { flex: 1; }
    .topbar-alert { display: flex; align-items: center; gap: 6px; padding: 6px 14px; background: rgba(224,85,85,0.1); border: 1px solid rgba(224,85,85,0.25); border-radius: 3px; font-size: 0.75rem; color: var(--error); }
    .admin-body { padding: 36px 40px; flex: 1; }

    /* ── COMMON ── */
    .page-header { margin-bottom: 28px; }
    .page-title { font-family: 'Playfair Display', serif; font-size: 1.7rem; font-weight: 700; color: var(--cream); margin-bottom: 4px; }
    .page-sub { font-size: 0.82rem; color: var(--muted); }

    .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 3px; font-family: 'DM Sans', sans-serif; font-size: 0.8rem; font-weight: 600; letter-spacing: 0.5px; cursor: pointer; border: none; text-decoration: none; transition: all 0.18s; }
    .btn-gold { background: var(--gold); color: #0D0A06; }
    .btn-gold:hover { background: var(--gold-l); }
    .btn-outline { background: transparent; color: var(--gold); border: 1px solid var(--border-h); }
    .btn-outline:hover { border-color: var(--gold); background: rgba(212,175,90,0.06); }
    .btn-danger { background: transparent; color: var(--error); border: 1px solid rgba(224,85,85,0.3); }
    .btn-danger:hover { background: rgba(224,85,85,0.08); border-color: var(--error); }
    .btn-sm { padding: 5px 12px; font-size: 0.73rem; }

    .alert { border-radius: 3px; padding: 11px 15px; font-size: 0.82rem; margin-bottom: 20px; }
    .alert-success { background: rgba(91,173,126,0.1); border: 1px solid rgba(91,173,126,0.3); color: var(--success); }
    .alert-error { background: rgba(224,85,85,0.1); border: 1px solid rgba(224,85,85,0.3); color: var(--error); }

    .card { background: var(--card); border: 1px solid var(--border); border-radius: 5px; }
    .card-header { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .card-title { font-size: 0.7rem; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--gold); }
    .card-body { padding: 20px; }

    /* ── STAT CARDS ── */
    .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
    .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 5px; padding: 20px 22px; }
    .stat-label { font-size: 0.68rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 10px; }
    .stat-value { font-size: 1.8rem; font-weight: 700; color: var(--cream); line-height: 1; margin-bottom: 6px; }
    .stat-value.gold { color: var(--gold); }
    .stat-meta { font-size: 0.72rem; color: var(--muted2); }
    .stat-icon { font-size: 1.5rem; margin-bottom: 10px; }

    /* ── TABLE ── */
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th { font-size: 0.65rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--gold); opacity: 0.7; padding: 10px 14px; border-bottom: 1px solid var(--border); text-align: left; }
    .data-table td { padding: 12px 14px; font-size: 0.83rem; color: var(--cream); border-bottom: 1px solid rgba(212,175,90,0.06); vertical-align: middle; }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: rgba(212,175,90,0.02); }

    /* ── STATUS BADGE ── */
    .status-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.5px; }

    /* ── MODAL OVERLAY ── */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 200; align-items: center; justify-content: center; }
    .modal-overlay.open { display: flex; }
    .modal-box { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; width: 500px; max-height: 88vh; overflow-y: auto; box-shadow: 0 24px 80px rgba(0,0,0,0.6); }
    .modal-header { padding: 18px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .modal-title { font-family: 'Playfair Display', serif; font-size: 1.1rem; font-weight: 700; color: var(--cream); }
    .modal-close { background: none; border: none; color: var(--muted); font-size: 1.2rem; cursor: pointer; padding: 4px; transition: color 0.2s; }
    .modal-close:hover { color: var(--cream); }
    .modal-body { padding: 24px; }
    .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }

    /* ── INVENTORY ── */
    .inv-progress { height: 4px; background: rgba(255,255,255,0.06); border-radius: 2px; margin-top: 4px; overflow: hidden; }
    .inv-progress-bar { height: 100%; border-radius: 2px; transition: width 0.3s; }

    /* ── ALERT ITEMS ── */
    .alert-list { display: flex; flex-direction: column; gap: 10px; }
    .alert-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--panel); border-radius: 4px; border-left: 3px solid; }
    .alert-item.critical { border-color: var(--error); }
    .alert-item.low { border-color: #D4AF5A; }
    .alert-item-name { font-size: 0.85rem; font-weight: 600; color: var(--cream); }
    .alert-item-meta { font-size: 0.72rem; color: var(--muted); }

    /* ── TABS ── */
    .tab-bar { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 1px solid var(--border); }
    .tab-btn { padding: 9px 18px; font-size: 0.8rem; font-weight: 600; color: var(--muted); background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; transition: all 0.18s; font-family: 'DM Sans', sans-serif; }
    .tab-btn.active { color: var(--cream); border-bottom-color: var(--gold); }
    .tab-content { display: none; }
    .tab-content.active { display: block; }

    /* ── ROLE TAG ── */
    .role-tag { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .role-superadmin { background: rgba(212,175,90,0.15); color: var(--gold); border: 1px solid rgba(212,175,90,0.3); }
    .role-staff { background: rgba(91,155,212,0.15); color: var(--info); border: 1px solid rgba(91,155,212,0.3); }
    .role-rider { background: rgba(155,123,212,0.15); color: #9B7BD4; border: 1px solid rgba(155,123,212,0.3); }

    /* ── FORM ROW ── */
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .checkbox-group { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
    .checkbox-group input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--gold); cursor: pointer; }
    .checkbox-group label { font-size: 0.82rem; color: var(--cream); cursor: pointer; }

    /* ── SEARCH ── */
    .search-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
    .search-input { background: var(--panel); border: 1px solid var(--border); border-radius: 3px; padding: 9px 14px; font-family: 'DM Sans', sans-serif; font-size: 0.84rem; color: var(--cream); outline: none; width: 260px; transition: border-color 0.2s; }
    .search-input:focus { border-color: var(--gold); }
    .search-input::placeholder { color: rgba(245,237,216,0.2); }

    .empty-state { text-align: center; padding: 48px 20px; color: var(--muted); }
    .empty-state .empty-icon { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.5; }

    /* ── ORDER DETAIL ── */
    .order-items-list { display: flex; flex-direction: column; gap: 6px; }
    .order-item-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: var(--panel); border-radius: 3px; }
  </style>
</head>
<body>

<?php if (!$is_admin): ?>
<!-- ═══════════════════════════════════════════════ LOGIN ══════════════════ -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">Overdose Cafe</div>
    <div class="login-title">Admin Portal</div>
    <div class="login-sub">Sign in to your admin account.</div>

    <?php if ($admin_error): ?>
      <div class="alert alert-error" style="margin-bottom:20px;"><?= htmlspecialchars($admin_error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" placeholder="Enter username" required autofocus/>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" required/>
      </div>
      <button type="submit" name="admin_login" class="btn btn-gold" style="width:100%;justify-content:center;padding:12px;margin-top:6px;">Sign In</button>
    </form>

    <div style="margin-top:24px;padding:16px;background:rgba(212,175,90,0.05);border:1px solid var(--border);border-radius:4px;font-size:0.75rem;color:var(--muted);line-height:1.7;">
      <strong style="color:var(--gold);display:block;margin-bottom:4px;">Default Credentials</strong>
      Super Admin: <code style="color:var(--cream);">superadmin / admin123</code><br/>
      Staff: <code style="color:var(--cream);">staff01 / staff123</code><br/>
      Rider: <code style="color:var(--cream);">rider01 / rider123</code>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════ ADMIN PANEL ═══════════ -->
<div class="admin-layout">

  <!-- SIDEBAR -->
  <aside class="admin-sidebar">
    <div class="sidebar-logo">
      <div class="sidebar-logo-text">Overdose</div>
      <div class="sidebar-logo-sub">Admin Portal</div>
    </div>

    <nav class="sidebar-nav">
      <div class="sidebar-section">Overview</div>
      <a href="admin.php?section=dashboard" class="sidebar-item <?= $section==='dashboard'?'active':'' ?>">
        <span class="icon">📊</span> Dashboard
        <?php if ($low_stock_count > 0): ?><span class="sidebar-badge"><?= $low_stock_count ?></span><?php endif; ?>
      </a>

      <?php if ($is_super || $is_staff): ?>
      <div class="sidebar-section">Operations</div>
      <a href="admin.php?section=orders" class="sidebar-item <?= $section==='orders'?'active':'' ?>">
        <span class="icon">📋</span> Orders
        <?php if ($pending_count > 0): ?><span class="sidebar-badge"><?= $pending_count ?></span><?php endif; ?>
      </a>
      <a href="admin.php?section=inventory" class="sidebar-item <?= $section==='inventory'?'active':'' ?>">
        <span class="icon">📦</span> Inventory
        <?php if ($low_stock_count > 0): ?><span class="sidebar-badge"><?= $low_stock_count ?></span><?php endif; ?>
      </a>
      <?php endif; ?>

      <?php if ($is_rider): ?>
      <div class="sidebar-section">Deliveries</div>
      <a href="admin.php?section=orders" class="sidebar-item <?= $section==='orders'?'active':'' ?>">
        <span class="icon">🛵</span> My Deliveries
        <?php if ($pending_count > 0): ?><span class="sidebar-badge"><?= $pending_count ?></span><?php endif; ?>
      </a>
      <?php endif; ?>

      <?php if ($is_super): ?>
      <div class="sidebar-section">Management</div>
      <a href="admin.php?section=products" class="sidebar-item <?= $section==='products'?'active':'' ?>">
        <span class="icon">☕</span> Products
      </a>
      <a href="admin.php?section=vouchers" class="sidebar-item <?= $section==='vouchers'?'active':'' ?>">
        <span class="icon">🏷️</span> Vouchers
      </a>
      <a href="admin.php?section=accounts" class="sidebar-item <?= $section==='accounts'?'active':'' ?>">
        <span class="icon">👥</span> Accounts
      </a>
      <?php endif; ?>

      <div class="sidebar-section">Store</div>
      <a href="products.php" target="_blank" class="sidebar-item">
        <span class="icon">🌐</span> View Store
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="sidebar-avatar"><?= strtoupper(substr($_SESSION['admin_name'], 0, 1)) ?></div>
        <div>
          <div class="sidebar-uname"><?= htmlspecialchars($_SESSION['admin_name']) ?></div>
          <div class="sidebar-role-badge"><?= ucfirst($role) ?></div>
        </div>
      </div>
      <a href="admin.php?admin_logout=1" class="btn-logout">Sign Out</a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="admin-main">
    <div class="admin-topbar">
      <div class="topbar-title">
        <?php
        $titles = ['dashboard'=>'Dashboard','orders'=>'Orders','inventory'=>'Inventory','products'=>'Products','vouchers'=>'Vouchers','accounts'=>'Accounts'];
        echo $titles[$section] ?? 'Dashboard';
        ?>
      </div>
      <div class="topbar-spacer"></div>
      <?php if ($low_stock_count > 0): ?>
        <a href="admin.php?section=inventory" class="topbar-alert">⚠️ <?= $low_stock_count ?> item<?= $low_stock_count>1?'s':'' ?> low in stock</a>
      <?php endif; ?>
    </div>

    <div class="admin-body">

      <?php if ($action_msg): ?>
        <?php [$type, $text] = explode(':', $action_msg, 2); ?>
        <div class="alert alert-<?= $type === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($text) ?></div>
      <?php endif; ?>

      <!-- ══════════════ DASHBOARD ══════════════ -->
      <?php if ($section === 'dashboard'): ?>
      <div class="page-header">
        <div class="page-title">Good <?= date('H') < 12 ? 'morning' : (date('H') < 18 ? 'afternoon' : 'evening') ?>, <?= htmlspecialchars($_SESSION['admin_name']) ?>!</div>
        <div class="page-sub">Here's what's happening at Overdose Cafe today.</div>
      </div>

      <!-- Stat cards -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-icon">💰</div>
          <div class="stat-label">Sales Today</div>
          <div class="stat-value gold">₱<?= number_format($sales_today, 2) ?></div>
          <div class="stat-meta">Excludes cancelled orders</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">📋</div>
          <div class="stat-label">Orders Today</div>
          <div class="stat-value"><?= $orders_today ?></div>
          <div class="stat-meta"><?= $pending_count ?> pending</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">✅</div>
          <div class="stat-label">Total Completed</div>
          <div class="stat-value"><?= $orders_completed ?></div>
          <div class="stat-meta">All-time completed orders</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">⭐</div>
          <div class="stat-label">Best Seller</div>
          <div class="stat-value" style="font-size:1rem;line-height:1.3;margin-top:4px;"><?= htmlspecialchars($best_product) ?></div>
        </div>
      </div>

      <!-- Two column: recent orders + low stock alerts -->
      <div style="display:grid;grid-template-columns:1fr 380px;gap:20px;">

        <!-- Recent Orders -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">Recent Orders</span>
            <a href="admin.php?section=orders" class="btn btn-outline btn-sm">View All</a>
          </div>
          <div style="overflow-x:auto;">
            <table class="data-table">
              <thead><tr>
                <th>#</th><th>Customer</th><th>Amount</th><th>Type</th><th>Status</th><th>Time</th>
              </tr></thead>
              <tbody>
              <?php foreach(array_slice($all_orders, 0, 8) as $o): ?>
                <tr>
                  <td style="color:var(--gold);font-weight:700;">#<?= $o['id'] ?></td>
                  <td><?= htmlspecialchars($o['first_name'] . ' ' . $o['last_name']) ?></td>
                  <td>₱<?= number_format($o['total_amount'], 2) ?></td>
                  <td style="text-transform:capitalize;font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($o['fulfillment_type'] ?? 'pickup') ?></td>
                  <td>
                    <?php $sc = $status_colors[$o['status']] ?? '#888'; ?>
                    <span class="status-badge" style="background:<?= $sc ?>22;color:<?= $sc ?>;border:1px solid <?= $sc ?>44;">
                      <?= htmlspecialchars($o['status']) ?>
                    </span>
                  </td>
                  <td style="color:var(--muted2);font-size:0.75rem;"><?= date('h:i A', strtotime($o['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($all_orders)): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px;">No orders yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Inventory Alerts -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">⚠️ Stock Alerts</span>
            <?php if ($is_super || $is_staff): ?>
            <a href="admin.php?section=inventory" class="btn btn-outline btn-sm">Manage</a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (empty($low_stock)): ?>
              <div style="text-align:center;padding:20px;color:var(--success);font-size:0.85rem;">✅ All items are sufficiently stocked!</div>
            <?php else: ?>
            <div class="alert-list">
              <?php foreach($low_stock as $it):
                $pct = $it['low_stock_threshold'] > 0 ? min(100, round($it['quantity'] / $it['low_stock_threshold'] * 100)) : 100;
                $critical = $it['quantity'] <= ($it['low_stock_threshold'] / 2);
              ?>
              <div class="alert-item <?= $critical ? 'critical' : 'low' ?>">
                <div style="flex:1;">
                  <div class="alert-item-name"><?= htmlspecialchars($it['item_name']) ?></div>
                  <div class="alert-item-meta"><?= $it['quantity'] ?> <?= $it['unit'] ?> left · min <?= $it['low_stock_threshold'] ?> <?= $it['unit'] ?></div>
                  <div class="inv-progress" style="margin-top:6px;">
                    <div class="inv-progress-bar" style="width:<?= $pct ?>%;background:<?= $critical ? 'var(--error)' : 'var(--gold)' ?>;"></div>
                  </div>
                </div>
                <span style="font-size:0.65rem;font-weight:700;color:<?= $critical ? 'var(--error)' : 'var(--gold)' ?>;text-transform:uppercase;"><?= $critical ? 'CRITICAL' : 'LOW' ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div>

      <!-- ══════════════ ORDERS ══════════════ -->
      <?php elseif ($section === 'orders'): ?>
      <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;">
        <div>
          <div class="page-title"><?= $is_rider ? 'My Deliveries' : 'Orders' ?></div>
          <div class="page-sub"><?= $is_rider ? 'Update delivery progress for assigned orders.' : 'View and manage all customer orders.' ?></div>
        </div>
      </div>

      <!-- Filter tabs -->
      <div class="tab-bar">
        <?php
        $filter_tabs = $is_rider
          ? ['all'=>'All','Out for Delivery'=>'Out for Delivery','Completed'=>'Completed']
          : ['all'=>'All','Pending'=>'Pending','Preparing'=>'Preparing','Ready'=>'Ready','Out for Delivery'=>'Out for Delivery','Completed'=>'Completed','Cancelled'=>'Cancelled'];
        $active_filter = $_GET['filter'] ?? 'all';
        foreach ($filter_tabs as $key => $label):
          $count_q = $conn->query("SELECT COUNT(*) as c FROM orders" . ($key !== 'all' ? " WHERE status='".mysqli_real_escape_string($conn,$key)."'" : ""))->fetch_assoc()['c'];
        ?>
        <a href="admin.php?section=orders&filter=<?= $key ?>" class="tab-btn <?= $active_filter === $key ? 'active' : '' ?>"><?= $label ?> (<?= $count_q ?>)</a>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr>
              <th>#</th><th>Customer</th><th>Phone</th><th>Items</th><th>Amount</th><th>Type</th><th>Status</th><th>Date</th><th>Action</th>
            </tr></thead>
            <tbody>
            <?php
            $where = $active_filter !== 'all' ? "WHERE o.status='" . mysqli_real_escape_string($conn, $active_filter) . "'" : '';
            // Riders only see delivery orders
            if ($is_rider) {
                $where = $active_filter === 'all'
                    ? "WHERE o.fulfillment_type='delivery'"
                    : "WHERE o.status='" . mysqli_real_escape_string($conn, $active_filter) . "' AND o.fulfillment_type='delivery'";
            }
            $filtered_orders = $conn->query("
                SELECT o.*, u.first_name, u.last_name, u.phone,
                    (SELECT COUNT(*) FROM order_items WHERE order_id=o.id) as item_count
                FROM orders o JOIN users u ON o.user_id=u.id
                $where ORDER BY o.created_at DESC
            ")->fetch_all(MYSQLI_ASSOC);
            foreach ($filtered_orders as $o):
                $sc = $status_colors[$o['status']] ?? '#888';
            ?>
            <tr>
              <td style="color:var(--gold);font-weight:700;">#<?= $o['id'] ?></td>
              <td><?= htmlspecialchars($o['first_name'] . ' ' . $o['last_name']) ?></td>
              <td style="color:var(--muted);font-size:0.78rem;"><?= htmlspecialchars($o['phone']) ?></td>
              <td style="color:var(--muted);"><?= $o['item_count'] ?> item<?= $o['item_count']!=1?'s':'' ?></td>
              <td style="font-weight:600;">₱<?= number_format($o['total_amount'], 2) ?></td>
              <td>
                <span style="font-size:0.72rem;color:var(--muted);text-transform:capitalize;">
                  <?= $o['fulfillment_type'] === 'delivery' ? '🛵 Delivery' : '🏪 Pickup' ?>
                </span>
              </td>
              <td><span class="status-badge" style="background:<?= $sc ?>22;color:<?= $sc ?>;border:1px solid <?= $sc ?>44;"><?= htmlspecialchars($o['status']) ?></span></td>
              <td style="color:var(--muted2);font-size:0.75rem;"><?= date('M j, g:i A', strtotime($o['created_at'])) ?></td>
              <td>
                <button class="btn btn-outline btn-sm" onclick="openOrderModal(<?= $o['id'] ?>)">Details</button>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($filtered_orders)): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:32px;">No orders found.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Order Detail Modals -->
      <?php foreach ($filtered_orders as $o):
        $oi = $conn->query("SELECT oi.*, p.name, p.category FROM order_items oi JOIN products p ON oi.product_id=p.id WHERE oi.order_id={$o['id']}")->fetch_all(MYSQLI_ASSOC);
        $sc = $status_colors[$o['status']] ?? '#888';
      ?>
      <div class="modal-overlay" id="order-modal-<?= $o['id'] ?>">
        <div class="modal-box" style="width:560px;">
          <div class="modal-header">
            <div class="modal-title">Order #<?= $o['id'] ?></div>
            <button class="modal-close" onclick="closeModal('order-modal-<?= $o['id'] ?>')">✕</button>
          </div>
          <div class="modal-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
              <div><div style="font-size:0.65rem;color:var(--gold);font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">Customer</div>
                <div style="font-size:0.88rem;"><?= htmlspecialchars($o['first_name'].' '.$o['last_name']) ?></div>
                <div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($o['phone']) ?></div>
              </div>
              <div><div style="font-size:0.65rem;color:var(--gold);font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">Fulfillment</div>
                <div style="font-size:0.88rem;text-transform:capitalize;"><?= $o['fulfillment_type'] === 'delivery' ? '🛵 Delivery' : '🏪 Pickup' ?></div>
                <?php if ($o['fulfillment_type'] === 'delivery' && $o['delivery_address']): ?>
                <div style="font-size:0.75rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars($o['delivery_address']) ?></div>
                <?php endif; ?>
              </div>
              <div><div style="font-size:0.65rem;color:var(--gold);font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">Ordered At</div>
                <div style="font-size:0.85rem;"><?= date('M j, Y g:i A', strtotime($o['created_at'])) ?></div>
              </div>
              <div><div style="font-size:0.65rem;color:var(--gold);font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">Status</div>
                <span class="status-badge" style="background:<?= $sc ?>22;color:<?= $sc ?>;border:1px solid <?= $sc ?>44;"><?= htmlspecialchars($o['status']) ?></span>
              </div>
            </div>

            <div style="margin-bottom:16px;">
              <div style="font-size:0.65rem;color:var(--gold);font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;">Order Items</div>
              <div class="order-items-list">
              <?php foreach ($oi as $it): ?>
                <div class="order-item-row">
                  <div>
                    <div style="font-size:0.85rem;font-weight:600;"><?= htmlspecialchars($it['name']) ?></div>
                    <div style="font-size:0.72rem;color:var(--muted);text-transform:capitalize;"><?= $it['category'] ?></div>
                  </div>
                  <div style="text-align:right;">
                    <div style="font-size:0.85rem;">₱<?= number_format($it['price'], 2) ?> × <?= $it['quantity'] ?></div>
                    <div style="font-size:0.75rem;color:var(--gold);">₱<?= number_format($it['price'] * $it['quantity'], 2) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
              </div>
            </div>

            <div style="background:var(--panel);border-radius:4px;padding:14px 16px;margin-bottom:20px;">
              <?php if ($o['discount'] > 0): ?>
              <div style="display:flex;justify-content:space-between;font-size:0.82rem;color:var(--muted);margin-bottom:6px;">
                <span>Discount <?= $o['voucher_code'] ? '('.$o['voucher_code'].')' : '' ?></span>
                <span style="color:var(--success);">−₱<?= number_format($o['discount'], 2) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($o['fulfillment_type'] === 'delivery'): ?>
              <div style="display:flex;justify-content:space-between;font-size:0.82rem;color:var(--muted);margin-bottom:6px;">
                <span>Delivery Fee</span><span>₱50.00</span>
              </div>
              <?php endif; ?>
              <div style="display:flex;justify-content:space-between;font-size:0.95rem;font-weight:700;color:var(--cream);border-top:1px solid var(--border);padding-top:10px;margin-top:6px;">
                <span>Total</span><span style="color:var(--gold);">₱<?= number_format($o['total_amount'], 2) ?></span>
              </div>
            </div>

            <!-- Update status -->
            <?php if ($o['status'] !== 'Completed' && $o['status'] !== 'Cancelled'): ?>
            <form method="POST">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>"/>
              <div style="display:flex;align-items:center;gap:10px;">
                <select name="new_status" style="flex:1;background:var(--panel);border:1px solid var(--border);border-radius:3px;padding:9px 12px;font-family:'DM Sans',sans-serif;font-size:0.85rem;color:var(--cream);outline:none;">
                  <?php
                  $statuses = $is_rider ? $rider_statuses : $all_statuses;
                  foreach ($statuses as $st): ?>
                  <option value="<?= $st ?>" <?= $o['status']===$st?'selected':'' ?>><?= $st ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" name="update_order_status" class="btn btn-gold">Update</button>
              </div>
            </form>
            <?php else: ?>
            <div style="text-align:center;padding:10px;font-size:0.8rem;color:var(--muted);font-style:italic;">Order is <?= strtolower($o['status']) ?>. No further updates.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- ══════════════ INVENTORY ══════════════ -->
      <?php elseif ($section === 'inventory' && ($is_super || $is_staff)): ?>
      <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;">
        <div>
          <div class="page-title">Inventory</div>
          <div class="page-sub">Manage supply and pastry stock levels.</div>
        </div>
        <button class="btn btn-gold" onclick="openModal('add-inv-modal')">+ Add Item</button>
      </div>

      <!-- Tabs: supplies / pastries -->
      <div class="tab-bar" id="inv-tabs">
        <button class="tab-btn active" onclick="switchInvTab('supplies')">Supplies</button>
        <button class="tab-btn" onclick="switchInvTab('pastries')">Pastries</button>
        <button class="tab-btn" onclick="switchInvTab('all')">All Items</button>
      </div>

      <?php foreach (['supplies','pastries','all'] as $inv_cat): ?>
      <div class="tab-content <?= $inv_cat === 'supplies' ? 'active' : '' ?>" id="inv-tab-<?= $inv_cat ?>">
        <?php if (!empty($low_stock) && ($inv_cat === 'all' || array_filter($low_stock, fn($x) => $x['category'] === $inv_cat || $inv_cat === 'all'))): ?>
        <div class="alert alert-error" style="margin-bottom:16px;">⚠️ Some items are running low. Please restock soon.</div>
        <?php endif; ?>
        <div class="card">
          <div style="overflow-x:auto;">
            <table class="data-table">
              <thead><tr><th>Item</th><th>Category</th><th>In Stock</th><th>Unit</th><th>Min. Threshold</th><th>Stock Level</th><th>Action</th></tr></thead>
              <tbody>
              <?php
              foreach ($inventory_items as $it):
                if ($inv_cat !== 'all' && $it['category'] !== $inv_cat) continue;
                $pct = $it['low_stock_threshold'] > 0 ? min(100, round($it['quantity'] / $it['low_stock_threshold'] * 100)) : 100;
                $critical = $it['quantity'] <= ($it['low_stock_threshold'] / 2);
                $ok = $it['quantity'] > $it['low_stock_threshold'];
                $bar_color = $ok ? 'var(--success)' : ($critical ? 'var(--error)' : 'var(--gold)');
              ?>
              <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($it['item_name']) ?></td>
                <td style="text-transform:capitalize;color:var(--muted);font-size:0.78rem;"><?= $it['category'] ?></td>
                <td style="font-size:1rem;font-weight:700;color:<?= $ok ? 'var(--cream)' : ($critical ? 'var(--error)' : 'var(--gold)') ?>;"><?= $it['quantity'] ?></td>
                <td style="color:var(--muted2);"><?= htmlspecialchars($it['unit']) ?></td>
                <td style="color:var(--muted2);"><?= $it['low_stock_threshold'] ?></td>
                <td style="width:130px;">
                  <?php $level = $ok ? 'OK' : ($critical ? 'CRITICAL' : 'LOW'); ?>
                  <div style="font-size:0.65rem;font-weight:700;color:<?= $bar_color ?>;margin-bottom:3px;"><?= $level ?></div>
                  <div class="inv-progress"><div class="inv-progress-bar" style="width:<?= $pct ?>%;background:<?= $bar_color ?>;"></div></div>
                </td>
                <td>
                  <button class="btn btn-outline btn-sm" onclick="openEditInv(<?= htmlspecialchars(json_encode($it)) ?>)">Edit</button>
                  <?php if ($is_super): ?>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this inventory item?')">
                    <input type="hidden" name="inv_id" value="<?= $it['id'] ?>"/>
                    <button type="submit" name="delete_inventory" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Add Inventory Modal -->
      <div class="modal-overlay" id="add-inv-modal">
        <div class="modal-box">
          <div class="modal-header">
            <div class="modal-title">Add Inventory Item</div>
            <button class="modal-close" onclick="closeModal('add-inv-modal')">✕</button>
          </div>
          <form method="POST">
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group"><label>Item Name</label><input type="text" name="item_name" placeholder="e.g. Straws" required/></div>
              <div class="form-group"><label>Category</label>
                <select name="item_cat"><option value="supplies">Supplies</option><option value="pastries">Pastries</option></select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>Quantity</label><input type="number" name="item_qty" min="0" placeholder="0" required/></div>
              <div class="form-group"><label>Unit</label><input type="text" name="item_unit" placeholder="pcs" value="pcs" required/></div>
            </div>
            <div class="form-group"><label>Low Stock Threshold</label><input type="number" name="item_thresh" min="1" placeholder="20" value="20" required/></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('add-inv-modal')">Cancel</button>
            <button type="submit" name="add_inventory" class="btn btn-gold">Add Item</button>
          </div>
          </form>
        </div>
      </div>

      <!-- Edit Inventory Modal -->
      <div class="modal-overlay" id="edit-inv-modal">
        <div class="modal-box">
          <div class="modal-header">
            <div class="modal-title" id="edit-inv-title">Edit Item</div>
            <button class="modal-close" onclick="closeModal('edit-inv-modal')">✕</button>
          </div>
          <form method="POST">
          <input type="hidden" name="inv_id" id="edit-inv-id"/>
          <div class="modal-body">
            <div class="form-group"><label>Item Name</label><input type="text" id="edit-inv-name" readonly style="opacity:0.6;"/></div>
            <div class="form-row">
              <div class="form-group"><label>Quantity</label><input type="number" name="quantity" id="edit-inv-qty" min="0" required/></div>
              <div class="form-group"><label>Low Stock Threshold</label><input type="number" name="threshold" id="edit-inv-thresh" min="1" required/></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('edit-inv-modal')">Cancel</button>
            <button type="submit" name="update_inventory" class="btn btn-gold">Save Changes</button>
          </div>
          </form>
        </div>
      </div>

      <!-- ══════════════ PRODUCTS ══════════════ -->
      <?php elseif ($section === 'products' && $is_super): ?>
      <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;">
        <div>
          <div class="page-title">Products</div>
          <div class="page-sub">Manage the café menu, prices, and promotions.</div>
        </div>
        <button class="btn btn-gold" onclick="openModal('add-prod-modal')">+ Add Product</button>
      </div>

      <div class="card">
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Name</th><th>Category</th><th>Price</th><th>Promo Price</th><th>Promo?</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
            <tr>
              <td style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
              <td style="text-transform:capitalize;color:var(--muted);font-size:0.78rem;"><?= $p['category'] ?></td>
              <td>₱<?= number_format($p['price'], 2) ?></td>
              <td><?= $p['promo_price'] ? '₱'.number_format($p['promo_price'],2) : '—' ?></td>
              <td><?= $p['is_promo'] ? '<span style="color:var(--success);font-weight:700;">Yes</span>' : '<span style="color:var(--muted2);">No</span>' ?></td>
              <td style="display:flex;gap:6px;align-items:center;">
                <button class="btn btn-outline btn-sm" onclick='openEditProd(<?= htmlspecialchars(json_encode($p)) ?>)'>Edit</button>
                <form method="POST" onsubmit="return confirm('Delete this product?')">
                  <input type="hidden" name="pid" value="<?= $p['id'] ?>"/>
                  <button type="submit" name="delete_product" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Add Product Modal -->
      <div class="modal-overlay" id="add-prod-modal">
        <div class="modal-box">
          <div class="modal-header">
            <div class="modal-title">Add Product</div>
            <button class="modal-close" onclick="closeModal('add-prod-modal')">✕</button>
          </div>
          <form method="POST">
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group"><label>Product Name</label><input type="text" name="pname" required/></div>
              <div class="form-group"><label>Category</label>
                <select name="pcat"><option value="coffee">Coffee</option><option value="pastries">Pastries</option></select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>Price (₱)</label><input type="number" name="pprice" step="0.01" min="0" required/></div>
              <div class="form-group"><label>Promo Price (₱)</label><input type="number" name="ppromo_price" step="0.01" min="0" placeholder="Leave blank if none"/></div>
            </div>
            <div class="form-group"><label>Description</label><textarea name="pdesc" rows="2"></textarea></div>
            <div class="form-group"><label>Image Path</label><input type="text" name="pimg" placeholder="assets/products/filename.jpg"/></div>
            <div class="checkbox-group"><input type="checkbox" name="pis_promo" id="add-pis-promo"/><label for="add-pis-promo">Mark as Promo Item</label></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('add-prod-modal')">Cancel</button>
            <button type="submit" name="add_product" class="btn btn-gold">Add Product</button>
          </div>
          </form>
        </div>
      </div>

      <!-- Edit Product Modal -->
      <div class="modal-overlay" id="edit-prod-modal">
        <div class="modal-box">
          <div class="modal-header">
            <div class="modal-title">Edit Product</div>
            <button class="modal-close" onclick="closeModal('edit-prod-modal')">✕</button>
          </div>
          <form method="POST">
          <input type="hidden" name="pid" id="edit-pid"/>
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group"><label>Product Name</label><input type="text" name="pname" id="edit-pname" required/></div>
              <div class="form-group"><label>Category</label>
                <select name="pcat" id="edit-pcat"><option value="coffee">Coffee</option><option value="pastries">Pastries</option></select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>Price (₱)</label><input type="number" name="pprice" id="edit-pprice" step="0.01" min="0" required/></div>
              <div class="form-group"><label>Promo Price (₱)</label><input type="number" name="ppromo_price" id="edit-ppromo_price" step="0.01" min="0"/></div>
            </div>
            <div class="form-group"><label>Description</label><textarea name="pdesc" id="edit-pdesc" rows="2"></textarea></div>
            <div class="form-group"><label>Image Path</label><input type="text" name="pimg" id="edit-pimg"/></div>
            <div class="checkbox-group"><input type="checkbox" name="pis_promo" id="edit-pis-promo"/><label for="edit-pis-promo">Mark as Promo Item</label></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('edit-prod-modal')">Cancel</button>
            <button type="submit" name="edit_product" class="btn btn-gold">Save Changes</button>
          </div>
          </form>
        </div>
      </div>

      <!-- ══════════════ VOUCHERS ══════════════ -->
      <?php elseif ($section === 'vouchers' && $is_super): ?>
      <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;">
        <div>
          <div class="page-title">Vouchers</div>
          <div class="page-sub">Manage discount codes and promotions.</div>
        </div>
        <button class="btn btn-gold" onclick="openModal('add-voucher-modal')">+ Add Voucher</button>
      </div>

      <div class="card">
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Min Order</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($vouchers as $v): ?>
            <tr>
              <td style="font-weight:700;font-family:monospace;font-size:0.9rem;color:var(--gold);"><?= htmlspecialchars($v['code']) ?></td>
              <td style="text-transform:capitalize;color:var(--muted);"><?= $v['discount_type'] ?></td>
              <td style="font-weight:600;"><?= $v['discount_type'] === 'percent' ? $v['discount_value'].'%' : '₱'.number_format($v['discount_value'],2) ?></td>
              <td>₱<?= number_format($v['min_order'], 2) ?></td>
              <td>
                <?php if ($v['is_active']): ?>
                  <span class="status-badge" style="background:rgba(91,173,126,0.15);color:var(--success);border:1px solid rgba(91,173,126,0.3);">Active</span>
                <?php else: ?>
                  <span class="status-badge" style="background:rgba(224,85,85,0.1);color:var(--error);border:1px solid rgba(224,85,85,0.3);">Inactive</span>
                <?php endif; ?>
              </td>
              <td style="display:flex;gap:6px;">
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="vid" value="<?= $v['id'] ?>"/>
                  <button type="submit" name="toggle_voucher" class="btn btn-outline btn-sm"><?= $v['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this voucher?')">
                  <input type="hidden" name="vid" value="<?= $v['id'] ?>"/>
                  <button type="submit" name="delete_voucher" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($vouchers)): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px;">No vouchers found.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Add Voucher Modal -->
      <div class="modal-overlay" id="add-voucher-modal">
        <div class="modal-box">
          <div class="modal-header">
            <div class="modal-title">Add Voucher</div>
            <button class="modal-close" onclick="closeModal('add-voucher-modal')">✕</button>
          </div>
          <form method="POST">
          <div class="modal-body">
            <div class="form-group"><label>Voucher Code</label><input type="text" name="vcode" placeholder="e.g. SUMMER20" required style="text-transform:uppercase;"/></div>
            <div class="form-row">
              <div class="form-group"><label>Discount Type</label>
                <select name="vtype"><option value="percent">Percentage (%)</option><option value="fixed">Fixed Amount (₱)</option></select>
              </div>
              <div class="form-group"><label>Discount Value</label><input type="number" name="vval" step="0.01" min="0" placeholder="e.g. 10" required/></div>
            </div>
            <div class="form-group"><label>Minimum Order (₱)</label><input type="number" name="vmin" step="0.01" min="0" placeholder="0" value="0" required/></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('add-voucher-modal')">Cancel</button>
            <button type="submit" name="add_voucher" class="btn btn-gold">Add Voucher</button>
          </div>
          </form>
        </div>
      </div>

      <!-- ══════════════ ACCOUNTS ══════════════ -->
      <?php elseif ($section === 'accounts' && $is_super): ?>
      <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;">
        <div>
          <div class="page-title">Admin Accounts</div>
          <div class="page-sub">Manage staff, rider, and admin accounts.</div>
        </div>
        <button class="btn btn-gold" onclick="openModal('add-account-modal')">+ Add Account</button>
      </div>

      <div class="card">
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Username</th><th>Full Name</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($admin_accounts as $acc): ?>
            <tr>
              <td style="font-weight:600;font-family:monospace;"><?= htmlspecialchars($acc['username']) ?></td>
              <td><?= htmlspecialchars($acc['full_name']) ?></td>
              <td><span class="role-tag role-<?= $acc['role'] ?>"><?= ucfirst($acc['role']) ?></span></td>
              <td>
                <?php if ($acc['is_active']): ?>
                  <span class="status-badge" style="background:rgba(91,173,126,0.15);color:var(--success);border:1px solid rgba(91,173,126,0.3);">Active</span>
                <?php else: ?>
                  <span class="status-badge" style="background:rgba(224,85,85,0.1);color:var(--error);border:1px solid rgba(224,85,85,0.3);">Inactive</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--muted2);font-size:0.75rem;"><?= date('M j, Y', strtotime($acc['created_at'])) ?></td>
              <td style="display:flex;gap:6px;align-items:center;">
                <?php if ($acc['id'] !== (int)$_SESSION['admin_id']): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="aid" value="<?= $acc['id'] ?>"/>
                  <button type="submit" name="toggle_admin_user" class="btn btn-outline btn-sm"><?= $acc['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this account?')">
                  <input type="hidden" name="aid" value="<?= $acc['id'] ?>"/>
                  <button type="submit" name="delete_admin_user" class="btn btn-danger btn-sm">Delete</button>
                </form>
                <?php else: ?>
                  <span style="font-size:0.72rem;color:var(--muted2);">Current session</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Add Account Modal -->
      <div class="modal-overlay" id="add-account-modal">
        <div class="modal-box">
          <div class="modal-header">
            <div class="modal-title">Add Admin Account</div>
            <button class="modal-close" onclick="closeModal('add-account-modal')">✕</button>
          </div>
          <form method="POST">
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group"><label>Full Name</label><input type="text" name="a_fullname" required/></div>
              <div class="form-group"><label>Username</label><input type="text" name="a_username" required/></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>Password</label><input type="password" name="a_password" placeholder="Min. 6 characters" required/></div>
              <div class="form-group"><label>Role</label>
                <select name="a_role">
                  <option value="staff">Staff</option>
                  <option value="rider">Rider</option>
                  <option value="superadmin">Super Admin</option>
                </select>
              </div>
            </div>
            <div style="padding:12px;background:rgba(212,175,90,0.05);border:1px solid var(--border);border-radius:4px;font-size:0.75rem;color:var(--muted);line-height:1.6;margin-top:4px;">
              <strong style="color:var(--gold);">Role Permissions:</strong><br/>
              <strong style="color:var(--cream);">Staff</strong> — Inventory & Order management<br/>
              <strong style="color:var(--cream);">Rider</strong> — Update delivery order status<br/>
              <strong style="color:var(--cream);">Super Admin</strong> — Full access
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('add-account-modal')">Cancel</button>
            <button type="submit" name="add_admin_user" class="btn btn-gold">Create Account</button>
          </div>
          </form>
        </div>
      </div>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">🔒</div>
        <p>You don't have permission to access this section.</p>
      </div>
      <?php endif; ?>

    </div><!-- /admin-body -->

    <footer style="border-top:1px solid var(--border);padding:16px 40px;font-size:0.72rem;color:var(--muted2);display:flex;justify-content:space-between;">
      <span>© <?= date('Y') ?> Overdose Cafe · Admin Portal</span>
      <span>Logged in as <strong style="color:var(--gold);"><?= htmlspecialchars($_SESSION['admin_user']) ?></strong> · <?= ucfirst($role) ?></span>
    </footer>
  </main>
</div>

<?php endif; ?>

<script>
function openModal(id) {
  document.getElementById(id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
function openOrderModal(id) {
  document.getElementById('order-modal-' + id).classList.add('open');
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(el) {
  el.addEventListener('click', function(e) {
    if (e.target === el) el.classList.remove('open');
  });
});

// Inventory tab switcher
function switchInvTab(cat) {
  document.querySelectorAll('#inv-tabs .tab-btn').forEach(function(b, i) {
    b.classList.remove('active');
    if (['supplies','pastries','all'][i] === cat) b.classList.add('active');
  });
  document.querySelectorAll('[id^="inv-tab-"]').forEach(function(el) {
    el.classList.remove('active');
  });
  document.getElementById('inv-tab-' + cat).classList.add('active');
}

// Edit Inventory
function openEditInv(item) {
  document.getElementById('edit-inv-id').value = item.id;
  document.getElementById('edit-inv-name').value = item.item_name;
  document.getElementById('edit-inv-qty').value = item.quantity;
  document.getElementById('edit-inv-thresh').value = item.low_stock_threshold;
  document.getElementById('edit-inv-title').textContent = 'Edit: ' + item.item_name;
  openModal('edit-inv-modal');
}

// Edit Product
function openEditProd(p) {
  document.getElementById('edit-pid').value = p.id;
  document.getElementById('edit-pname').value = p.name;
  document.getElementById('edit-pcat').value = p.category;
  document.getElementById('edit-pprice').value = p.price;
  document.getElementById('edit-ppromo_price').value = p.promo_price || '';
  document.getElementById('edit-pdesc').value = p.description || '';
  document.getElementById('edit-pimg').value = p.image || '';
  document.getElementById('edit-pis-promo').checked = p.is_promo == 1;
  openModal('edit-prod-modal');
}

// Auto-dismiss alerts
document.querySelectorAll('.alert').forEach(function(el) {
  setTimeout(function() {
    el.style.transition = 'opacity 0.4s';
    el.style.opacity = '0';
    setTimeout(function() { el.remove(); }, 400);
  }, 4000);
});
</script>

</body>
</html>