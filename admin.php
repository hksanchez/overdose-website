<?php
session_start();
require_once 'includes/db.php';

// ── AUTH GUARD ──────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// ── LOGOUT ──────────────────────────────────────────────────────────────────
if (isset($_GET['admin_logout'])) {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_user']);
    header("Location: admin_login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'];
$admin_user = $_SESSION['admin_user'];

$action_msg = '';

// ═══════════════════════════════════════════════════════════════════════════
// POST ACTION HANDLERS
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Orders ──────────────────────────────────────────────────────────────
    if (isset($_POST['accept_order'])) {
        $oid = (int)$_POST['order_id'];
        // Deduct inventory when order is accepted
        $items_q = $conn->prepare("SELECT oi.product_id, oi.quantity, p.category FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
        $items_q->bind_param("i", $oid);
        $items_q->execute();
        $items_res = $items_q->get_result();
        while ($item = $items_res->fetch_assoc()) {
            // Deduct cup + lid + straw per coffee item
            if ($item['category'] === 'coffee') {
                $qty = $item['quantity'];
                $conn->query("UPDATE inventory SET quantity = GREATEST(0, quantity - $qty) WHERE item_name IN ('Cups','Lids','Straws') AND category = 'supplies'");
            }
            // Deduct pastry stock
            if ($item['category'] === 'pastries') {
                $pid = $item['product_id']; $qty = $item['quantity'];
                $conn->query("UPDATE inventory SET quantity = GREATEST(0, quantity - $qty) WHERE linked_product_id = $pid");
            }
        }
        $upd = $conn->prepare("UPDATE orders SET status='Preparing', is_viewed=0 WHERE id=? AND status='Pending'");
        $upd->bind_param("i", $oid);
        $upd->execute();
        $action_msg = 'success:<a href="admin.php?s=orders&filter=Preparing">Order #' . $oid . ' accepted — inventory updated.</a>';
    }

    if (isset($_POST['update_order_status'])) {
        $oid    = (int)$_POST['order_id'];
        $status = $_POST['new_status'];
        $allowed = ['Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled'];
        if (in_array($status, $allowed)) {
            $upd = $conn->prepare("UPDATE orders SET status=?, is_viewed=0 WHERE id=?");
            $upd->bind_param("si", $status, $oid);
            $upd->execute();
            $action_msg = 'success:<a href="admin.php?s=orders&filter=' . urlencode($status) . '">Order #' . $oid . ' → ' . htmlspecialchars($status) . '.</a>';
        }
    }

    // ── Inventory ────────────────────────────────────────────────────────────
    if (isset($_POST['update_inventory'])) {
        $iid    = (int)$_POST['inv_id'];
        $qty    = (int)$_POST['quantity'];
        $thresh = (int)$_POST['threshold'];
        $upd = $conn->prepare("UPDATE inventory SET quantity=?, low_stock_threshold=? WHERE id=?");
        $upd->bind_param("iii", $qty, $thresh, $iid);
        $upd->execute();
        $action_msg = 'success:Inventory updated.';
    }

    if (isset($_POST['restock_inventory'])) {
        $iid = (int)$_POST['inv_id'];
        $amt = (int)$_POST['restock_amount'];
        $upd = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id=?");
        $upd->bind_param("ii", $amt, $iid);
        $upd->execute();
        $action_msg = 'success:Stock restocked.';
    }

    if (isset($_POST['add_inventory'])) {
        $name   = trim($_POST['item_name']);
        $cat    = trim($_POST['item_cat']);
        $qty    = (int)$_POST['item_qty'];
        $unit   = trim($_POST['item_unit']);
        $thresh = (int)$_POST['item_thresh'];
        // Duplicate name check (case-insensitive, across all categories)
        $dup_chk = $conn->prepare("SELECT id FROM inventory WHERE LOWER(item_name) = LOWER(?)");
        $dup_chk->bind_param("s", $name);
        $dup_chk->execute();
        $dup_chk->store_result();
        if ($dup_chk->num_rows > 0) {
            $action_msg = 'error:An inventory item named "' . htmlspecialchars($name) . '" already exists. Please use a different name.';
        } else {
            $ins = $conn->prepare("INSERT INTO inventory (item_name, category, quantity, unit, low_stock_threshold) VALUES (?,?,?,?,?)");
            $ins->bind_param("ssisi", $name, $cat, $qty, $unit, $thresh);
            $ins->execute();
            $action_msg = 'success:Item added to inventory.';
        }
        $dup_chk->close();
    }

    if (isset($_POST['delete_inventory'])) {
        $iid = (int)$_POST['inv_id'];
        $del = $conn->prepare("DELETE FROM inventory WHERE id=?");
        $del->bind_param("i", $iid);
        $del->execute();
        $action_msg = 'success:Inventory item removed.';
    }

    // ── Settings ─────────────────────────────────────────────────────────────
    if (isset($_POST['update_store_status'])) {
        $status = $_POST['store_status'] === 'online' ? 'online' : 'offline';
        $hours = trim($_POST['store_hours']);
        $upd1 = $conn->prepare("UPDATE site_settings SET setting_value=? WHERE setting_key='store_status'");
        $upd1->bind_param("s", $status);
        $upd1->execute();
        $upd2 = $conn->prepare("UPDATE site_settings SET setting_value=? WHERE setting_key='store_hours'");
        $upd2->bind_param("s", $hours);
        $upd2->execute();
        $action_msg = 'success:Store status updated.';
    }

    // ── Products ─────────────────────────────────────────────────────────────
    if (isset($_POST['add_product'])) {
        $name   = trim($_POST['pname']);
        $cat    = trim($_POST['pcat']);
        $price  = (float)$_POST['pprice'];
        $desc   = trim($_POST['pdesc']);
        $img    = trim($_POST['pimg'] ?? '');
        $upload_error = null;
        if (isset($_FILES['pimg_file']) && $_FILES['pimg_file']['error'] != UPLOAD_ERR_NO_FILE) {
            if ($_FILES['pimg_file']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = 'assets/products/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($_FILES['pimg_file']['name']));
                $target = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['pimg_file']['tmp_name'], $target)) {
                    $img = $target;
                } else {
                    $upload_error = 'Failed to move uploaded file.';
                }
            } else {
                $upload_error = 'Upload error code: ' . $_FILES['pimg_file']['error'];
            }
        }
        $promo  = isset($_POST['pis_promo']) ? 1 : 0;
        $pprice = $promo ? (float)$_POST['ppromo_price'] : null;
        $ins = $conn->prepare("INSERT INTO products (name,category,price,description,image,promo_price,is_promo) VALUES (?,?,?,?,?,?,?)");
        $ins->bind_param("ssdssdi", $name, $cat, $price, $desc, $img, $pprice, $promo);
        $ins->execute();
        $new_pid = $conn->insert_id;
        if ($cat === 'pastries') {
            $iqty = 25; $ithresh = 10;
            $insi = $conn->prepare("INSERT INTO inventory (item_name, category, quantity, unit, low_stock_threshold, linked_product_id) VALUES (?, 'pastries', ?, 'pcs', ?, ?)");
            $insi->bind_param("siii", $name, $iqty, $ithresh, $new_pid);
            $insi->execute();
        }
        if ($upload_error) {
            $action_msg = 'error:' . $upload_error;
        } else {
            $action_msg = 'success:Product added.';
        }
    }

    if (isset($_POST['edit_product'])) {
        $pid    = (int)$_POST['pid'];
        $name   = trim($_POST['pname']);
        $cat    = trim($_POST['pcat']);
        $price  = (float)$_POST['pprice'];
        $desc   = trim($_POST['pdesc']);
        $img    = trim($_POST['pimg'] ?? '');
        $upload_error = null;
        if (isset($_FILES['pimg_file']) && $_FILES['pimg_file']['error'] != UPLOAD_ERR_NO_FILE) {
            if ($_FILES['pimg_file']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = 'assets/products/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($_FILES['pimg_file']['name']));
                $target = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['pimg_file']['tmp_name'], $target)) {
                    $img = $target;
                } else {
                    $upload_error = 'Failed to move uploaded file.';
                }
            } else {
                $upload_error = 'Upload error code: ' . $_FILES['pimg_file']['error'];
            }
        }
        // If pis_promo checkbox present (from Promos section), use it; otherwise preserve via hidden field
        if (isset($_POST['pis_promo'])) {
            $promo = 1;
        } elseif (isset($_POST['pis_promo_val'])) {
            $promo = (int)$_POST['pis_promo_val'];
        } else {
            $promo = 0;
        }
        $pprice = ($promo && !empty($_POST['ppromo_price'])) ? (float)$_POST['ppromo_price'] : null;
        $upd = $conn->prepare("UPDATE products SET name=?,category=?,price=?,description=?,image=?,promo_price=?,is_promo=? WHERE id=?");
        $upd->bind_param("ssdssdii", $name, $cat, $price, $desc, $img, $pprice, $promo, $pid);
        $upd->execute();
        $ename = $conn->real_escape_string($name);
        $conn->query("UPDATE inventory SET item_name='$ename' WHERE linked_product_id=$pid");
        if ($upload_error) {
            $action_msg = 'error:' . $upload_error;
        } else {
            $action_msg = 'success:Product updated.';
        }
    }

    if (isset($_POST['delete_product'])) {
        $pid = (int)$_POST['pid'];
        try {
            $conn->query("DELETE FROM inventory WHERE linked_product_id=$pid");
            $del = $conn->prepare("DELETE FROM products WHERE id=?");
            $del->bind_param("i", $pid);
            $del->execute();
            $action_msg = 'success:Product deleted.';
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1451) {
                // MySQL Error 1451: Cannot delete or update a parent row (foreign key constraint fails)
                $action_msg = 'error:Cannot delete this product because it has already been ordered by customers. Consider removing it from stock instead.';
            } else {
                $action_msg = 'error:Database error: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['toggle_promo'])) {
        $pid = (int)$_POST['pid'];
        $conn->query("UPDATE products SET is_promo = NOT is_promo WHERE id=$pid");
        $action_msg = 'success:Promo status toggled.';
    }

    // ── Vouchers ─────────────────────────────────────────────────────────────
    if (isset($_POST['add_voucher'])) {
        $code = strtoupper(trim($_POST['vcode']));
        $type = $_POST['vtype'];
        $val  = (float)$_POST['vval'];
        $min  = (float)$_POST['vmin'];
        $ins  = $conn->prepare("INSERT INTO vouchers (code,discount_type,discount_value,min_order,is_active) VALUES (?,?,?,?,1)");
        $ins->bind_param("ssdd", $code, $type, $val, $min);
        if (!$ins->execute()) $action_msg = 'error:Voucher code already exists.';
        else $action_msg = 'success:Voucher <strong>' . htmlspecialchars($code) . '</strong> Voucher Added';
    }

    if (isset($_POST['toggle_voucher'])) {
        $vid = (int)$_POST['vid'];
        // Get current state before toggle
        $cv = $conn->query("SELECT code, is_active FROM vouchers WHERE id=$vid")->fetch_assoc();
        $conn->query("UPDATE vouchers SET is_active = NOT is_active WHERE id=$vid");
        if ($cv && $cv['is_active']) {
            // Was active, now deactivated — customers with this voucher applied will have it removed automatically
            $action_msg = 'success:Voucher <strong>' . htmlspecialchars($cv['code']) . '</strong> Voucher Deactivated.';
        } else {
            $action_msg = 'success:Voucher Activated.';
        }
    }

    if (isset($_POST['delete_voucher'])) {
        $vid = (int)$_POST['vid'];
        $cv = $conn->query("SELECT code FROM vouchers WHERE id=$vid")->fetch_assoc();
        $conn->query("DELETE FROM vouchers WHERE id=$vid");
        $action_msg = 'success:Voucher <strong>' . htmlspecialchars($cv['code'] ?? '') . '</strong> Voucher Deleted.';
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// DATA FETCH
// ═══════════════════════════════════════════════════════════════════════════
$section = $_GET['s'] ?? 'dashboard';

// Mark orders as viewed if we are on a specific filter tab
if ($section === 'orders') {
    $current_filter = $_GET['filter'] ?? 'Pending';
    if ($current_filter !== 'All') {
        $fs_esc = $conn->real_escape_string($current_filter);
        $conn->query("UPDATE orders SET is_viewed = 1 WHERE status = '$fs_esc' AND is_viewed = 0");
    }
}

// Dashboard stats
$today_sales = 0; $today_orders = 0; $completed_orders = 0; $pending_orders = 0;
$top_item = '—';

$ts = $conn->query("SELECT COALESCE(SUM(total_amount),0) as total, COUNT(*) as cnt FROM orders WHERE DATE(created_at)=CURDATE() AND status != 'Cancelled'");
if ($ts) { $r = $ts->fetch_assoc(); $today_sales = $r['total']; $today_orders = $r['cnt']; }

$status_counts = [];
$unseen_counts = [];
$sc_q = $conn->query("SELECT status, COUNT(*) as c, SUM(CASE WHEN is_viewed = 0 THEN 1 ELSE 0 END) as unseen FROM orders GROUP BY status");
while ($sc_row = $sc_q->fetch_assoc()) {
    $status_counts[$sc_row['status']] = (int)$sc_row['c'];
    $unseen_counts[$sc_row['status']] = (int)$sc_row['unseen'];
}
$status_counts['All'] = array_sum($status_counts);
$unseen_counts['All'] = array_sum($unseen_counts);

$completed_orders = $status_counts['Completed'] ?? 0;
$pending_orders   = $status_counts['Pending'] ?? 0;

$ti = $conn->query("SELECT p.name, SUM(oi.quantity) as total_qty FROM order_items oi JOIN products p ON oi.product_id = p.id GROUP BY oi.product_id ORDER BY total_qty DESC LIMIT 1");
if ($ti && $row = $ti->fetch_assoc()) $top_item = $row['name'];

// Inventory alerts (low stock)
$inv_alerts = $conn->query("SELECT * FROM inventory WHERE quantity <= low_stock_threshold ORDER BY (quantity/low_stock_threshold) ASC");

// Orders list
$orders_list = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 100");

// Products
$products_all = $conn->query("SELECT * FROM products ORDER BY category, name");

// Inventory
$inv_all = $conn->query("SELECT * FROM inventory ORDER BY category, item_name");

// Vouchers
$vouchers_all = $conn->query("SELECT * FROM vouchers ORDER BY id DESC");

// Recent pending orders for dashboard
$recent_pending = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status = 'Pending' ORDER BY o.created_at DESC LIMIT 5");

// Recent orders for dashboard (last 10)
$recent_orders = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 10");

// Order detail
$order_detail = null;
$order_detail_items = [];
if (isset($_GET['order_id']) && $section === 'orders') {
    $oid = (int)$_GET['order_id'];
    $oq = $conn->prepare("SELECT o.*, u.first_name, u.last_name, u.phone FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
    $oq->bind_param("i", $oid);
    $oq->execute();
    $order_detail = $oq->get_result()->fetch_assoc();
    if ($order_detail) {
        $iq = $conn->prepare("SELECT oi.*, p.name, p.category FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
        $iq->bind_param("i", $oid);
        $iq->execute();
        $order_detail_items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$status_colors = [
    'Pending'          => '#D4AF5A',
    'Preparing'        => '#5B9BD4',
    'Ready'            => '#5BAD7E',
    'Out for Delivery' => '#9B7BD4',
    'Completed'        => '#5BAD7E',
    'Cancelled'        => '#E05555',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin — Overdose Cafe</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400;1,700&family=DM+Sans:wght@300;400;500;600&display=swap"/>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="admin-shell">

  <!-- ══ SIDEBAR ══════════════════════════════════════════════════════════ -->
  <aside class="admin-sidebar">
    <div class="sidebar-brand">
      <a href="admin.php?s=dashboard" style="text-decoration:none;color:inherit;display:block;">
        <div class="sidebar-brand-name">Overdose Cafe</div>
        <div class="sidebar-brand-sub">Admin Portal</div>
      </a>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-label">Overview</div>
      <a href="admin.php?s=dashboard" class="nav-item <?= $section==='dashboard'?'active':'' ?>">
        <span class="nav-icon">◈</span> Dashboard
      </a>

      <div class="nav-group-label">Operations</div>
      <a href="admin.php?s=orders" class="nav-item <?= $section==='orders'?'active':'' ?>">
        <span class="nav-icon">📋</span> Orders
        <?php if ($pending_orders > 0): ?>
          <span class="nav-badge"><?= $pending_orders ?></span>
        <?php endif; ?>
      </a>
      <a href="admin.php?s=inventory" class="nav-item <?= $section==='inventory'?'active':'' ?>">
        <span class="nav-icon">📦</span> Inventory
      </a>

      <div class="nav-group-label">Catalogue</div>
      <a href="admin.php?s=products" class="nav-item <?= $section==='products'?'active':'' ?>">
        <span class="nav-icon">☕</span> Products
      </a>
      <a href="admin.php?s=promos" class="nav-item <?= $section==='promos'?'active':'' ?>">
        <span class="nav-icon">🏷️</span> Promos &amp; Vouchers
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-admin-info">
        <div class="sidebar-avatar"><?= strtoupper(substr($admin_name, 0, 1)) ?></div>
        <div>
          <div class="sidebar-admin-name"><?= htmlspecialchars($admin_name) ?></div>
          <div class="sidebar-admin-role">Administrator</div>
        </div>
      </div>
      <a href="admin.php?admin_logout=1" class="sidebar-logout">Sign Out</a>
    </div>
  </aside>

  <!-- ══ MAIN ═════════════════════════════════════════════════════════════ -->
  <div class="admin-main">

    <!-- Top bar -->
    <div class="admin-topbar">
      <?php
        $titles = [
          'dashboard' => ['Dashboard', 'Good ' . (date('H')<12?'morning':(date('H')<18?'afternoon':'evening')) . ', ' . explode(' ',$admin_name)[0] . '!'],
          'orders'    => ['Orders', 'Manage and update customer orders'],
          'inventory' => ['Inventory', 'Track stock levels and restock alerts'],
          'products'  => ['Products', 'Add, edit, or remove menu items'],
          'promos'    => ['Promos & Vouchers', 'Manage product promos and discount codes'],
        ];
        $tt = $titles[$section] ?? ['Dashboard',''];
      ?>
      <div>
        <span class="topbar-title"><?= $tt[0] ?></span>
        <span class="topbar-subtitle"><?= $tt[1] ?></span>
      </div>
      <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
        <a href="products.php" target="_blank" class="btn btn-ghost btn-sm">↗ View Storefront</a>
      </div>
    </div>

    <div class="admin-content">

      <!-- ── ALERT ──────────────────────────────────────────────────────── -->
      <?php if ($action_msg):
        [$type, $msg] = explode(':', $action_msg, 2);
      ?>
        <div class="alert alert-<?= $type === 'success' ? 'success' : 'error' ?>">
          <span style="font-size:1.1rem;"><?= $type === 'success' ? '✅' : '❌' ?></span>
          <div><?= $msg ?></div>
        </div>
      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════════════════
           DASHBOARD
      ══════════════════════════════════════════════════════════════════════ -->
      <?php if ($section === 'dashboard'): ?>

        <!-- Stat cards row -->
        <div class="grid-4">
          <div class="stat-card">
            <div class="stat-label">Sales Today</div>
            <div class="stat-icon">₱</div>
            <div class="stat-value stat-accent">₱<?= number_format($today_sales, 0) ?></div>
            <div class="stat-sub"><?= $today_orders ?> order<?= $today_orders != 1 ? 's' : '' ?> today</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Completed Orders</div>
            <div class="stat-icon">✓</div>
            <div class="stat-value"><?= $completed_orders ?></div>
            <div class="stat-sub">All time</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Top Selling Item</div>
            <div class="stat-value" style="font-size:1.1rem;line-height:1.3;"><?= htmlspecialchars($top_item) ?></div>
            <div class="stat-sub">Most ordered product</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Pending Orders</div>
            <div class="stat-icon">⏳</div>
            <div class="stat-value <?= $pending_orders > 0 ? 'stat-accent' : '' ?>"><?= $pending_orders ?></div>
            <div class="stat-sub">Awaiting action</div>
          </div>
        </div>

        <div class="dashboard-grid">
          <!-- Left column: Recent Orders + pending orders -->
          <div>
            <!-- Recent Orders -->
            <div class="card" style="margin-top:24px;">
              <div class="card-header">
                Recent Orders
                <a href="admin.php?s=orders" class="btn btn-ghost btn-sm">View All →</a>
              </div>
              <div class="card-body" style="padding:0;">
                <?php
                  $ro_rows = [];
                  if ($recent_orders) while ($ro = $recent_orders->fetch_assoc()) $ro_rows[] = $ro;
                ?>
                <?php if (empty($ro_rows)): ?>
                  <p style="color:var(--muted);font-size:0.82rem;padding:20px;">No orders yet.</p>
                <?php else: ?>
                  <table style="width:100%;border-collapse:collapse;font-size:0.80rem;">
                    <thead>
                      <tr style="border-bottom:1px solid var(--border);">
                        <th style="padding:10px 20px;text-align:left;color:var(--muted);font-weight:500;">Order</th>
                        <th style="padding:10px 12px;text-align:left;color:var(--muted);font-weight:500;">Customer</th>
                        <th style="padding:10px 12px;text-align:left;color:var(--muted);font-weight:500;">Status</th>
                        <th style="padding:10px 12px;text-align:right;color:var(--muted);font-weight:500;">Total</th>
                        <th style="padding:10px 20px;text-align:right;color:var(--muted);font-weight:500;">Time</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($ro_rows as $ro):
                        $sc = $status_colors[$ro['status']] ?? '#888';
                      ?>
                        <tr style="border-bottom:1px solid var(--border);transition:background 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background=''">
                          <td style="padding:10px 20px;">
                            <a href="admin.php?s=orders&order_id=<?= $ro['id'] ?>" style="color:var(--gold);font-weight:600;text-decoration:none;">#<?= $ro['id'] ?></a>
                          </td>
                          <td style="padding:10px 12px;color:var(--cream);"><?= htmlspecialchars($ro['first_name'] . ' ' . $ro['last_name']) ?></td>
                          <td style="padding:10px 12px;">
                            <span style="font-size:0.70rem;font-weight:600;padding:2px 8px;border-radius:10px;color:<?= $sc ?>;background:<?= $sc ?>18;border:1px solid <?= $sc ?>33;"><?= $ro['status'] ?></span>
                          </td>
                          <td style="padding:10px 12px;text-align:right;font-weight:700;color:var(--gold);">₱<?= number_format($ro['total_amount'],2) ?></td>
                          <td style="padding:10px 20px;text-align:right;color:var(--muted2);font-size:0.72rem;"><?= date('M d, g:i A', strtotime($ro['created_at'])) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </div>

            <!-- Pending orders quick-action -->
            <div class="card" style="margin-top:20px;">
              <div class="card-header">
                Pending Orders
                <a href="admin.php?s=orders" class="btn btn-ghost btn-sm">View All →</a>
              </div>
              <div class="card-body" style="padding:0 20px;">
                <?php
                  $rp_rows = [];
                  if ($recent_pending) while ($rp = $recent_pending->fetch_assoc()) $rp_rows[] = $rp;
                ?>
                <?php if (empty($rp_rows)): ?>
                  <p style="color:var(--muted);font-size:0.82rem;padding:20px 0;">No pending orders — all clear!</p>
                <?php else: ?>
                  <?php foreach ($rp_rows as $rp): ?>
                    <div class="pending-order-row">
                      <div style="flex:1;">
                        <div class="pending-order-num">Order #<?= $rp['id'] ?></div>
                        <div class="pending-order-meta"><?= htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name']) ?> · ₱<?= number_format($rp['total_amount'],2) ?> · <?= date('g:i A', strtotime($rp['created_at'])) ?></div>
                        <?php if (!empty($rp['order_note'])): ?>
                          <div style="margin-top:5px;font-size:0.72rem;color:var(--gold);background:rgba(212,175,90,0.07);border:1px solid rgba(212,175,90,0.18);border-radius:3px;padding:4px 8px;display:inline-block;max-width:100%;">
                            <?= htmlspecialchars(mb_strimwidth($rp['order_note'], 0, 60, '…')) ?>
                          </div>
                        <?php endif; ?>
                      </div>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="order_id" value="<?= $rp['id'] ?>"/>
                        <button type="submit" name="accept_order" class="btn btn-success btn-sm">Accept</button>
                      </form>
                      <a href="admin.php?s=orders&order_id=<?= $rp['id'] ?>" class="btn btn-ghost btn-sm">View</a>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Right column: inventory alerts -->
          <div>
            <div class="card" style="margin-top:24px;">
              <div class="card-header">
                Inventory Alerts
                <?php
                  $alert_count = 0;
                  if ($inv_alerts) $alert_count = $inv_alerts->num_rows;
                ?>
                <?php if ($alert_count > 0): ?>
                  <span style="font-size:0.65rem;background:rgba(224,85,85,0.15);color:var(--error);border:1px solid rgba(224,85,85,0.25);border-radius:10px;padding:2px 8px;"><?= $alert_count ?> Low</span>
                <?php endif; ?>
              </div>
              <div class="card-body" style="padding:0 20px;">
                <?php if (!$inv_alerts || $inv_alerts->num_rows === 0): ?>
                  <p style="color:var(--success);font-size:0.82rem;padding:20px 0;">✓ All stock levels are healthy.</p>
                <?php else:
                  $inv_alerts->data_seek(0);
                  while ($ia = $inv_alerts->fetch_assoc()):
                    $ratio = $ia['low_stock_threshold'] > 0 ? $ia['quantity'] / $ia['low_stock_threshold'] : 1;
                    $level = $ia['quantity'] <= 0 ? 'critical' : ($ratio <= 0.5 ? 'critical' : 'low');
                    $bar_color = $level === 'critical' ? '#E05555' : '#D4914A';
                    $bar_pct = min(100, round($ratio * 100));
                    $level_label = $ia['quantity'] <= 0 ? 'Out of Stock' : ($level === 'critical' ? 'Critical' : 'Low');
                    $badge_style = $level === 'critical' ? 'background:rgba(224,85,85,0.12);color:#E05555;border:1px solid rgba(224,85,85,0.25);' : 'background:rgba(212,145,74,0.12);color:#D4914A;border:1px solid rgba(212,145,74,0.25);';
                ?>
                  <div class="inv-alert-item">
                    <div class="inv-alert-icon <?= $level ?>">
                      <?= $level === 'critical' ? '🔴' : '🟡' ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                      <div class="inv-alert-name"><?= htmlspecialchars($ia['item_name']) ?></div>
                      <div class="inv-alert-qty"><?= $ia['quantity'] ?> <?= $ia['unit'] ?> left · threshold <?= $ia['low_stock_threshold'] ?></div>
                      <div style="margin-top:5px;">
                        <div class="stock-bar-wrap">
                          <div class="stock-bar-fill" style="width:<?= $bar_pct ?>%;background:<?= $bar_color ?>;"></div>
                        </div>
                      </div>
                    </div>
                    <span class="inv-alert-badge" style="<?= $badge_style ?>"><?= $level_label ?></span>
                  </div>
                <?php endwhile; endif; ?>
                <div style="padding:14px 0;">
                  <a href="admin.php?s=inventory" class="btn btn-outline btn-sm" style="width:100%;justify-content:center;">Manage Inventory →</a>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Store Status (bottom of dashboard) -->
        <?php
          $site_st2 = $conn->query("SELECT * FROM site_settings");
          $settings2 = [];
          if ($site_st2) while($r2 = $site_st2->fetch_assoc()) $settings2[$r2['setting_key']] = $r2['setting_value'];
          $is_online2 = ($settings2['store_status'] ?? 'online') === 'online';
          $store_hours2 = $settings2['store_hours'] ?? 'Mon-Sun: 8:00 AM - 9:00 PM';
        ?>
        <div class="card" style="margin-top:24px;">
          <div class="card-header">
            Shop Status
            <span style="font-size:0.68rem;font-weight:700;padding:2px 10px;border-radius:10px;<?= $is_online2 ? 'background:rgba(91,173,126,0.12);color:#5BAD7E;border:1px solid rgba(91,173,126,0.25);' : 'background:rgba(224,85,85,0.12);color:#E05555;border:1px solid rgba(224,85,85,0.25);' ?>">
              <?= $is_online2 ? '🟢 Online' : '🔴 Closed' ?>
            </span>
          </div>
          <div class="card-body" style="padding:20px;">
            <form method="POST">
              <div style="display:flex; gap:20px; align-items:flex-end; flex-wrap:wrap;">
                <div>
                  <div style="font-size:0.63rem; font-weight:700; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold); margin-bottom:8px;">Status</div>
                  <select name="store_status" style="padding:9px 12px; background:var(--panel); color:var(--cream); border:1px solid var(--border); border-radius:3px; outline:none;">
                    <option value="online" <?= $is_online2 ? 'selected' : '' ?>>🟢 Online (Accepting Orders)</option>
                    <option value="offline" <?= !$is_online2 ? 'selected' : '' ?>>🔴 Offline (Closed)</option>
                  </select>
                </div>
                <div style="flex:1; min-width:220px;">
                  <div style="font-size:0.63rem; font-weight:700; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold); margin-bottom:8px;">Message</div>
                  <input type="text" name="store_hours" value="<?= htmlspecialchars($store_hours2) ?>" placeholder="e.g. Mon-Sun: 8:00 AM – 9:00 PM" style="width:100%; padding:9px 12px; background:var(--panel); color:var(--cream); border:1px solid var(--border); border-radius:3px; outline:none;"/>
                </div>
                <div>
                  <button type="submit" name="update_store_status" class="btn btn-gold btn-sm">Save Changes</button>
                </div>
              </div>
            </form>
          </div>
        </div>

      <!-- ══════════════════════════════════════════════════════════════════
           ORDERS
      ══════════════════════════════════════════════════════════════════════ -->
      <?php elseif ($section === 'orders'): ?>

        <?php if ($order_detail): ?>
          <!-- Order detail view -->
          <div style="margin-bottom:18px;">
            <a href="admin.php?s=orders" class="btn btn-ghost btn-sm">← Back to Orders</a>
          </div>
          <div class="section-title">Order #<?= $order_detail['id'] ?></div>

          <div class="grid-2" style="align-items:start;">
            <div>
              <div class="order-panel">
                <div class="card-header" style="margin:-24px -24px 18px;padding:14px 24px;border-radius:4px 4px 0 0;">Items Ordered</div>
                <?php foreach ($order_detail_items as $it): ?>
                  <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);">
                    <div>
                      <div style="font-weight:600;font-size:0.88rem;"><?= htmlspecialchars($it['name']) ?></div>
                      <div style="font-size:0.72rem;color:var(--muted);"><?= ucfirst($it['category']) ?> · x<?= $it['quantity'] ?></div>
                    </div>
                    <div style="font-weight:700;color:var(--gold);">₱<?= number_format($it['price'] * $it['quantity'], 2) ?></div>
                  </div>
                <?php endforeach; ?>
                <div style="margin-top:14px;font-size:0.85rem;display:flex;justify-content:space-between;color:var(--muted);padding-top:4px;">
                  <span>Discount</span><span style="color:var(--success);">−₱<?= number_format($order_detail['discount'],2) ?></span>
                </div>
                <div style="margin-top:8px;font-size:1rem;font-weight:700;display:flex;justify-content:space-between;color:var(--cream);">
                  <span>Total</span><span style="color:var(--gold);">₱<?= number_format($order_detail['total_amount'],2) ?></span>
                </div>
              </div>
            </div>

            <div>
              <div class="order-panel">
                <div class="card-header" style="margin:-24px -24px 18px;padding:14px 24px;border-radius:4px 4px 0 0;">Order Details</div>
                <div style="display:flex;flex-direction:column;gap:12px;font-size:0.83rem;">
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Customer</span>
                    <span style="font-weight:600;"><?= htmlspecialchars($order_detail['first_name'] . ' ' . $order_detail['last_name']) ?></span>
                  </div>
                  <?php if (!empty($order_detail['phone'])): ?>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Phone</span>
                    <span><?= htmlspecialchars($order_detail['phone']) ?></span>
                  </div>
                  <?php endif; ?>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Placed</span>
                    <span><?= date('M d, Y g:i A', strtotime($order_detail['created_at'])) ?></span>
                  </div>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Order Type</span>
                    <span><?= ucfirst($order_detail['fulfillment_type'] ?? 'pickup') ?></span>
                  </div>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Status</span>
                    <?php $sc = $status_colors[$order_detail['status']] ?? '#888'; ?>
                    <span class="status-pill" style="color:<?= $sc ?>;border-color:<?= $sc ?>33;background:<?= $sc ?>15;"><?= $order_detail['status'] ?></span>
                  </div>
                  <?php if ($order_detail['delivery_address']): ?>
                  <div style="display:flex;justify-content:space-between;gap:20px;">
                    <span style="color:var(--muted);">Address</span>
                    <span style="text-align:right;"><?= htmlspecialchars($order_detail['delivery_address']) ?></span>
                  </div>
                  <?php endif; ?>
                  <?php if (!empty($order_detail['order_note'])): ?>
                  <div style="margin-top:4px;padding:10px 12px;background:rgba(212,175,90,0.06);border:1px solid rgba(212,175,90,0.18);border-radius:3px;">
                    <div style="font-size:0.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);opacity:0.7;margin-bottom:5px;">Order Note</div>
                    <div style="font-size:0.83rem;color:var(--cream);line-height:1.5;"><?= nl2br(htmlspecialchars($order_detail['order_note'])) ?></div>
                  </div>
                  <?php endif; ?>
                  <?php if ($order_detail['voucher_code']): ?>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Voucher</span>
                    <span style="color:var(--gold);"><?= htmlspecialchars($order_detail['voucher_code']) ?></span>
                  </div>
                  <?php endif; ?>
                </div>


              </div>
            </div>
          </div>

        <?php else: ?>
          <!-- Orders list -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
            <div>
              <div class="section-title">All Orders</div>
              <div class="section-sub" style="margin-bottom:0;">View, accept, and update customer orders.</div>
            </div>
          </div>

          <!-- Filter tabs -->
          <div class="tab-nav" id="orders-tab-nav" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
            <?php
              $order_tabs = ['Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled','All'];
              $active_tab = $_GET['filter'] ?? 'Pending';
            ?>
            <?php foreach ($order_tabs as $ot): ?>
              <?php $c = $unseen_counts[$ot] ?? 0; ?>
              <a href="admin.php?s=orders&filter=<?= urlencode($ot) ?>" class="tab-btn <?= $active_tab===$ot?'active':'' ?>" style="display:inline-flex;align-items:center;">
                <?= $ot ?>
                <?php if ($c > 0): ?>
                  <span style="display:inline-flex;align-items:center;justify-content:center;background:var(--error);color:#fff;border-radius:10px;font-size:0.6rem;font-weight:700;height:16px;min-width:16px;padding:0 4px;margin-left:6px;"><?= $c ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>

          <div class="card">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Type</th>
                    <th>Note</th>
                    <th>Status</th>
                    <th>Placed</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                  // Re-query with filter
                  $filter_status = $_GET['filter'] ?? 'Pending';
                  if ($filter_status === 'All') {
                      $olist = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 100");
                  } else {
                      $fs = $conn->real_escape_string($filter_status);
                      $olist = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status='$fs' ORDER BY o.created_at DESC LIMIT 100");
                  }
                  $found_orders = false;
                  while ($ord = $olist->fetch_assoc()):
                    $found_orders = true;
                    $sc = $status_colors[$ord['status']] ?? '#888';
                ?>
                  <tr>
                    <td style="font-weight:700;color:var(--gold);">#<?= $ord['id'] ?></td>
                    <td><?= htmlspecialchars($ord['first_name'] . ' ' . $ord['last_name']) ?></td>
                    <td style="font-weight:600;">₱<?= number_format($ord['total_amount'],2) ?></td>
                    <td style="color:var(--muted);font-size:0.78rem;"><?= ucfirst($ord['fulfillment_type'] ?? 'pickup') ?></td>
                    <td style="max-width:160px;">
                      <?php if (!empty($ord['order_note'])): ?>
                        <span title="<?= htmlspecialchars($ord['order_note']) ?>" style="display:inline-flex;align-items:center;gap:5px;font-size:0.75rem;color:var(--gold);background:rgba(212,175,90,0.08);border:1px solid rgba(212,175,90,0.22);border-radius:3px;padding:3px 8px;cursor:default;max-width:150px;">
                          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(mb_strimwidth($ord['order_note'], 0, 28, '…')) ?></span>
                        </span>
                      <?php else: ?>
                        <span style="color:var(--muted);font-size:0.75rem;">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="status-pill" style="color:<?= $sc ?>;border-color:<?= $sc ?>33;background:<?= $sc ?>15;"><?= $ord['status'] ?></span>
                    </td>
                    <td style="color:var(--muted);font-size:0.78rem;"><?= date('M d, g:i A', strtotime($ord['created_at'])) ?></td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap;">
                      <?php if ($ord['status'] === 'Pending'): ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="order_id" value="<?= $ord['id'] ?>"/>
                          <button type="submit" name="accept_order" class="btn btn-success btn-sm">Accept</button>
                        </form>
                      <?php endif; ?>
                      <button class="btn btn-outline btn-sm" onclick="openStatusModal(<?= $ord['id'] ?>, '<?= addslashes($ord['status']) ?>')">Order Status</button>
                      <a href="admin.php?s=orders&order_id=<?= $ord['id'] ?>&filter=<?= urlencode($filter_status) ?>" class="btn btn-ghost btn-sm">View</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
                <?php if (!$found_orders): ?>
                  <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:32px;">No orders found.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <!-- Update Status Modal -->
        <div class="modal-overlay" id="update-status-modal">
          <div class="modal-box" style="max-width:380px;">
            <div class="modal-header">
              <div class="modal-title" id="update-status-title">Update Order Status</div>
              <button class="modal-close" onclick="closeModal('update-status-modal')">✕</button>
            </div>
            <form method="POST">
              <input type="hidden" name="order_id" id="status-modal-order-id"/>
              <div class="modal-body">
                <div class="form-group">
                  <label>New Status</label>
                  <select name="new_status" id="status-modal-select" style="background:var(--panel);border:1px solid var(--border);border-radius:2px;padding:10px 12px;color:var(--cream);font-family:'DM Sans',sans-serif;font-size:0.85rem;outline:none;width:100%;">
                    <?php foreach (['Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled'] as $st): ?>
                      <option value="<?= $st ?>"><?= $st ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('update-status-modal')">Cancel</button>
                <button type="submit" name="update_order_status" class="btn btn-gold btn-sm">Save Status</button>
              </div>
            </form>
          </div>
        </div>

      <!-- ══════════════════════════════════════════════════════════════════
           INVENTORY
      ══════════════════════════════════════════════════════════════════════ -->
      <?php elseif ($section === 'inventory'): ?>

        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;">
          <div>
            <div class="section-title">Inventory</div>
          </div>
          <button class="btn btn-gold btn-md" onclick="openModal('add-inv-modal')">+ Add Item</button>
        </div>

        <!-- Tabs: All / Supplies / Pastries -->
        <div class="tab-nav">
          <button class="tab-btn active" onclick="switchInvTab('all',this)">All</button>
          <button class="tab-btn" onclick="switchInvTab('supplies',this)">Supplies</button>
          <button class="tab-btn" onclick="switchInvTab('pastries',this)">Pastries</button>
        </div>

        <?php
          $inv_data = [];
          if ($inv_all) { $inv_all->data_seek(0); while ($ir = $inv_all->fetch_assoc()) $inv_data[] = $ir; }
          $cats = ['all','supplies','pastries'];
        ?>

        <?php foreach ($cats as $cat): ?>
        <div class="inv-tab-panel" id="inv-tab-<?= $cat ?>" style="<?= $cat !== 'all' ? 'display:none;' : '' ?>">
          <div class="card">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th>Unit</th>
                    <th>Threshold</th>
                    <th>Level</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                  $found = false;
                  foreach ($inv_data as $iv):
                    if ($cat !== 'all' && $iv['category'] !== $cat) continue;
                    $found = true;
                    $ratio = $iv['low_stock_threshold'] > 0 ? $iv['quantity'] / $iv['low_stock_threshold'] : 2;
                    $bar_c = $iv['quantity'] <= 0 ? '#E05555' : ($ratio <= 0.5 ? '#E05555' : ($ratio <= 1 ? '#D4914A' : '#5BAD7E'));
                    $bar_p = min(100, round($ratio * 100));
                    $lvl_label = $iv['quantity'] <= 0 ? 'Empty' : ($ratio <= 0.5 ? 'Critical' : ($ratio <= 1 ? 'Low' : 'OK'));
                ?>
                  <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($iv['item_name']) ?></td>
                    <td style="color:var(--muted);font-size:0.78rem;text-transform:capitalize;"><?= htmlspecialchars($iv['category']) ?></td>
                    <td style="font-weight:700;color:<?= $bar_c ?>;"><?= $iv['quantity'] ?></td>
                    <td style="color:var(--muted);font-size:0.78rem;"><?= $iv['unit'] ?></td>
                    <td style="color:var(--muted);font-size:0.78rem;"><?= $iv['low_stock_threshold'] ?></td>
                    <td>
                      <div style="display:flex;align-items:center;gap:8px;">
                        <div class="stock-bar-wrap" style="width:60px;">
                          <div class="stock-bar-fill" style="width:<?= $bar_p ?>%;background:<?= $bar_c ?>;"></div>
                        </div>
                        <span style="font-size:0.65rem;font-weight:700;color:<?= $bar_c ?>;"><?= $lvl_label ?></span>
                      </div>
                    </td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap;">
                      <button class="btn btn-ghost btn-sm" onclick='openEditInv(<?= htmlspecialchars(json_encode($iv)) ?>)'>Edit</button>
                      <button class="btn btn-outline btn-sm" onclick='openRestockModal(<?= $iv['id'] ?>, "<?= addslashes($iv['item_name']) ?>")'>Restock</button>
                      <form method="POST" onsubmit="return confirm('Delete this item?')">
                        <input type="hidden" name="inv_id" value="<?= $iv['id'] ?>"/>
                        <button type="submit" name="delete_inventory" class="btn btn-danger btn-sm">Del</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$found): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px;">No items found.</td></tr>
                <?php endif; ?>
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
              <div class="form-group"><label>Item Name</label><input type="text" name="item_name" required/></div>
              <div class="form-row">
                <div class="form-group">
                  <label>Category</label>
                  <select name="item_cat">
                    <option value="supplies">Supplies</option>
                    <option value="pastries">Pastries</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div class="form-group"><label>Unit</label><input type="text" name="item_unit" value="pcs"/></div>
              </div>
              <div class="form-row">
                <div class="form-group"><label>Quantity</label><input type="number" name="item_qty" min="0" value="0"/></div>
                <div class="form-group"><label>Low Stock Threshold</label><input type="number" name="item_thresh" min="1" value="20"/></div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('add-inv-modal')">Cancel</button>
              <button type="submit" name="add_inventory" class="btn btn-gold btn-sm">Add Item</button>
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
              <div class="form-group"><label>Item Name</label><input type="text" id="edit-inv-name" disabled style="opacity:0.5;"/></div>
              <div class="form-row">
                <div class="form-group"><label>Quantity</label><input type="number" name="quantity" id="edit-inv-qty" min="0"/></div>
                <div class="form-group"><label>Low Stock Threshold</label><input type="number" name="threshold" id="edit-inv-thresh" min="1"/></div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('edit-inv-modal')">Cancel</button>
              <button type="submit" name="update_inventory" class="btn btn-gold btn-sm">Save Changes</button>
            </div>
            </form>
          </div>
        </div>

        <!-- Restock Modal -->
        <div class="modal-overlay" id="restock-modal">
          <div class="modal-box">
            <div class="modal-header">
              <div class="modal-title" id="restock-title">Restock Item</div>
              <button class="modal-close" onclick="closeModal('restock-modal')">✕</button>
            </div>
            <form method="POST">
            <input type="hidden" name="inv_id" id="restock-inv-id"/>
            <div class="modal-body">
              <div class="form-group"><label>Add Stock Amount</label><input type="number" name="restock_amount" min="1" value="50" required/></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('restock-modal')">Cancel</button>
              <button type="submit" name="restock_inventory" class="btn btn-gold btn-sm">Restock</button>
            </div>
            </form>
          </div>
        </div>

      <!-- ══════════════════════════════════════════════════════════════════
           PRODUCTS
      ══════════════════════════════════════════════════════════════════════ -->
      <?php elseif ($section === 'products'): ?>

        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;">
          <div>
            <div class="section-title">Products</div>
            <div class="section-sub" style="margin-bottom:0;">Manage your full menu — coffee and pastries.</div>
          </div>
          <button class="btn btn-gold btn-md" onclick="openModal('add-prod-modal')">+ Add Product</button>
        </div>

        <?php
          $prods = [];
          if ($products_all) { $products_all->data_seek(0); while ($pr = $products_all->fetch_assoc()) $prods[] = $pr; }
          $prod_cats = ['coffee' => [], 'pastries' => []];
          foreach ($prods as $pr) $prod_cats[$pr['category']][] = $pr;
        ?>

        <div class="tab-nav" id="prod-tab-nav">
          <button class="tab-btn active" onclick="switchProdTab('coffee',this)">Coffee (<?= count($prod_cats['coffee']) ?>)</button>
          <button class="tab-btn" onclick="switchProdTab('pastries',this)">Pastries (<?= count($prod_cats['pastries']) ?>)</button>
        </div>

        <?php foreach ($prod_cats as $pcat => $pitems): ?>
        <div class="prod-tab-panel" id="prod-tab-<?= $pcat ?>" style="<?= $pcat !== 'coffee' ? 'display:none;' : '' ?>">
          <div class="card">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Promo Price</th>
                    <th>Promo</th>
                    <th>Image</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (empty($pitems)): ?>
                  <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px;">No products.</td></tr>
                <?php endif; ?>
                <?php foreach ($pitems as $pr): ?>
                  <tr>
                    <td>
                      <div style="font-weight:600;"><?= htmlspecialchars($pr['name']) ?></div>
                      <div style="font-size:0.72rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars(substr($pr['description'],0,55)) ?>…</div>
                    </td>
                    <td style="font-weight:600;">₱<?= number_format($pr['price'],2) ?></td>
                    <td style="color:var(--gold);">
                      <?= $pr['promo_price'] ? '₱'.number_format($pr['promo_price'],2) : '<span style="color:var(--muted2);">—</span>' ?>
                    </td>
                    <td>
                      <?php if ($pr['is_promo']): ?>
                        <span class="promo-tag">SALE</span>
                      <?php else: ?>
                        <span style="color:var(--muted2);font-size:0.75rem;">—</span>
                      <?php endif; ?>
                    </td>
                    <td style="color:var(--muted2);font-size:0.72rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($pr['image'] ?? '') ?></td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap;">
                      <button class="btn btn-ghost btn-sm" onclick='openEditProd(<?= htmlspecialchars(json_encode($pr)) ?>)'>Edit</button>
                      <form method="POST" onsubmit="return confirm('Delete this product?')" style="display:inline;">
                        <input type="hidden" name="pid" value="<?= $pr['id'] ?>"/>
                        <button type="submit" name="delete_product" class="btn btn-danger btn-sm">Del</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Add Product Modal -->
        <div class="modal-overlay" id="add-prod-modal">
          <div class="modal-box">
            <div class="modal-header">
              <div class="modal-title">Add Product</div>
              <button class="modal-close" onclick="closeModal('add-prod-modal')">✕</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
              <div class="form-row">
                <div class="form-group"><label>Name</label><input type="text" name="pname" required/></div>
                <div class="form-group">
                  <label>Category</label>
                  <select name="pcat">
                    <option value="coffee">Coffee</option>
                    <option value="pastries">Pastries</option>
                  </select>
                </div>
              </div>
              <div class="form-group"><label>Regular Price (₱)</label><input type="number" step="0.01" name="pprice" required/></div>
              <div class="form-group"><label>Description</label><textarea name="pdesc" rows="2"></textarea></div>
              <div class="form-group">
                <label>Upload Image</label>
                <input type="file" name="pimg_file" accept="image/*"/>
                <input type="hidden" name="pimg" value="assets/products/default.jpg"/>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('add-prod-modal')">Cancel</button>
              <button type="submit" name="add_product" class="btn btn-gold btn-sm">Add Product</button>
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
            <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="pid" id="edit-pid"/>
            <div class="modal-body">
              <div class="form-row">
                <div class="form-group"><label>Name</label><input type="text" name="pname" id="edit-pname" required/></div>
                <div class="form-group">
                  <label>Category</label>
                  <select name="pcat" id="edit-pcat">
                    <option value="coffee">Coffee</option>
                    <option value="pastries">Pastries</option>
                  </select>
                </div>
              </div>
              <div class="form-group"><label>Description</label><textarea name="pdesc" id="edit-pdesc" rows="2"></textarea></div>
              <div class="form-group">
                <label>Update Image</label>
                <input type="file" name="pimg_file" accept="image/*"/>
                <input type="hidden" name="pimg" id="edit-pimg"/>
              </div>
              <!-- hidden fields so edit_product handler still receives required values -->
              <input type="hidden" name="pprice" id="edit-pprice"/>
              <input type="hidden" name="ppromo_price" id="edit-ppromo_price"/>
              <input type="hidden" name="pis_promo_val" id="edit-pis-promo-val"/>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('edit-prod-modal')">Cancel</button>
              <button type="submit" name="edit_product" class="btn btn-gold btn-sm">Save Changes</button>
            </div>
            </form>
          </div>
        </div>

      <!-- ══════════════════════════════════════════════════════════════════
           PROMOS & VOUCHERS
      ══════════════════════════════════════════════════════════════════════ -->
      <?php elseif ($section === 'promos'): ?>

        <div class="section-title">Promos &amp; Vouchers</div>
        <div class="section-sub">Manage sale tags on products and active discount codes.</div>

        <!-- Product Promos table -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div style="font-size:0.68rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gold);opacity:0.7;">Product Sale Tags</div>
        </div>

        <?php
          $promo_prods = [];
          if ($products_all) { $products_all->data_seek(0); while ($pr = $products_all->fetch_assoc()) $promo_prods[] = $pr; }
          $promo_cats = ['coffee' => [], 'pastries' => []];
          foreach ($promo_prods as $pr) $promo_cats[$pr['category']][] = $pr;
        ?>

        <div class="tab-nav" id="promo-tab-nav">
          <button class="tab-btn active" onclick="switchPromoTab('coffee',this)">Coffee (<?= count($promo_cats['coffee']) ?>)</button>
          <button class="tab-btn" onclick="switchPromoTab('pastries',this)">Pastries (<?= count($promo_cats['pastries']) ?>)</button>
        </div>

        <?php foreach ($promo_cats as $pcat => $pitems): ?>
        <div class="promo-tab-panel" id="promo-tab-<?= $pcat ?>" style="<?= $pcat !== 'coffee' ? 'display:none;' : '' ?>margin-bottom:32px;">
          <div class="card">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th>Regular Price</th>
                    <th>Promo Price</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (empty($pitems)): ?>
                  <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px;">No products.</td></tr>
                <?php endif; ?>
                <?php foreach ($pitems as $pr): ?>
                  <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($pr['name']) ?></td>
                    <td>₱<?= number_format($pr['price'],2) ?></td>
                    <td style="color:var(--gold);">
                      <?php if ($pr['promo_price']): ?>
                        ₱<?= number_format($pr['promo_price'],2) ?>
                      <?php else: ?>
                        <span style="color:var(--muted2);">Not set</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($pr['is_promo']): ?>
                        <span class="status-pill" style="color:#e07070;border-color:rgba(224,112,112,0.3);background:rgba(224,112,112,0.1);">On Sale</span>
                      <?php else: ?>
                        <span class="status-pill" style="color:var(--muted2);border-color:var(--border);">Regular</span>
                      <?php endif; ?>
                    </td>
                    <td style="display:flex;gap:6px;">
                      <button class="btn btn-ghost btn-sm" onclick='openEditProdPromo(<?= htmlspecialchars(json_encode($pr)) ?>)'>Edit Promo</button>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="pid" value="<?= $pr['id'] ?>"/>
                        <button type="submit" name="toggle_promo" class="btn btn-outline btn-sm"><?= $pr['is_promo'] ? 'Remove Sale' : 'Set Sale' ?></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Vouchers -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div style="font-size:0.68rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gold);opacity:0.7;">Discount Vouchers</div>
          <button class="btn btn-gold btn-sm" onclick="openModal('add-voucher-modal')">+ New Voucher</button>
        </div>

        <div class="card">
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Type</th>
                  <th>Value</th>
                  <th>Min Order</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php
                if ($vouchers_all) while ($v = $vouchers_all->fetch_assoc()):
              ?>
                <tr>
                  <td style="font-weight:700;color:var(--gold);letter-spacing:1px;"><?= htmlspecialchars($v['code']) ?></td>
                  <td style="color:var(--muted);font-size:0.78rem;text-transform:capitalize;"><?= $v['discount_type'] ?></td>
                  <td><?= $v['discount_type'] === 'percent' ? $v['discount_value'].'%' : '₱'.number_format($v['discount_value'],2) ?></td>
                  <td style="color:var(--muted);">₱<?= number_format($v['min_order'],2) ?>+</td>
                  <td>
                    <?php if ($v['is_active']): ?>
                      <span class="status-pill" style="color:var(--success);border-color:rgba(91,173,126,0.3);background:rgba(91,173,126,0.1);">Active</span>
                    <?php else: ?>
                      <span class="status-pill" style="color:var(--muted2);border-color:var(--border);">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td style="display:flex;gap:6px;">
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="vid" value="<?= $v['id'] ?>"/>
                      <button type="submit" name="toggle_voucher" class="btn btn-ghost btn-sm"><?= $v['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Delete this voucher?')" style="display:inline;">
                      <input type="hidden" name="vid" value="<?= $v['id'] ?>"/>
                      <button type="submit" name="delete_voucher" class="btn btn-danger btn-sm">Del</button>
                    </form>
                  </td>
                </tr>
              <?php endwhile; ?>
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
              <div class="form-group"><label>Voucher Code</label><input type="text" name="vcode" placeholder="e.g. WEEKEND20" required/></div>
              <div class="form-row">
                <div class="form-group">
                  <label>Discount Type</label>
                  <select name="vtype">
                    <option value="percent">Percentage (%)</option>
                    <option value="fixed">Fixed Amount (₱)</option>
                  </select>
                </div>
                <div class="form-group"><label>Value</label><input type="number" step="0.01" name="vval" placeholder="e.g. 10 or 50" required/></div>
              </div>
              <div class="form-group"><label>Minimum Order (₱)</label><input type="number" step="0.01" name="vmin" value="0"/></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('add-voucher-modal')">Cancel</button>
              <button type="submit" name="add_voucher" class="btn btn-gold btn-sm">Add Voucher</button>
            </div>
            </form>
          </div>
        </div>

        <!-- Edit Promo Modal (for promos section) -->
        <div class="modal-overlay" id="edit-prod-promo-modal">
          <div class="modal-box">
            <div class="modal-header">
              <div class="modal-title" id="edit-promo-title">Edit Promo</div>
              <button class="modal-close" onclick="closeModal('edit-prod-promo-modal')">✕</button>
            </div>
            <form method="POST">
            <input type="hidden" name="pid" id="edit-promo-pid"/>
            <div class="modal-body">
              <div class="form-row">
                <div class="form-group"><label>Regular Price (₱)</label><input type="number" step="0.01" name="pprice" id="edit-promo-price" required/></div>
                <div class="form-group"><label>Promo Price (₱)</label><input type="number" step="0.01" name="ppromo_price" id="edit-promo-pprice"/></div>
              </div>
              <input type="hidden" name="pname" id="edit-promo-pname"/>
              <input type="hidden" name="pcat"  id="edit-promo-pcat"/>
              <input type="hidden" name="pdesc" id="edit-promo-pdesc"/>
              <input type="hidden" name="pimg"  id="edit-promo-pimg"/>
              <div class="checkbox-row">
                <input type="checkbox" name="pis_promo" id="edit-promo-is"/>
                <label for="edit-promo-is">Mark as Promo / Sale Item</label>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('edit-prod-promo-modal')">Cancel</button>
              <button type="submit" name="edit_product" class="btn btn-gold btn-sm">Save Promo</button>
            </div>
            </form>
          </div>
        </div>

      <?php endif; ?>

    </div><!-- /admin-content -->

    <footer class="admin-footer">
      <span>© <?= date('Y') ?> Overdose Cafe · Admin Portal</span>
      <span>Logged in as <strong style="color:var(--gold);"><?= htmlspecialchars($admin_user) ?></strong></span>
    </footer>
  </div><!-- /admin-main -->
</div><!-- /admin-shell -->

<script>
// ── Modal helpers ──────────────────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

// ── Inventory tabs ─────────────────────────────────────────────────────────
function switchInvTab(cat, btn) {
  document.querySelectorAll('.inv-tab-panel').forEach(p => p.style.display = 'none');
  document.getElementById('inv-tab-' + cat).style.display = '';
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

// ── Product tabs ───────────────────────────────────────────────────────────
function switchProdTab(cat, btn) {
  document.querySelectorAll('.prod-tab-panel').forEach(p => p.style.display = 'none');
  document.getElementById('prod-tab-' + cat).style.display = '';
  document.querySelectorAll('#prod-tab-nav .tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

// ── Promo tabs ─────────────────────────────────────────────────────────────
function switchPromoTab(cat, btn) {
  document.querySelectorAll('.promo-tab-panel').forEach(p => p.style.display = 'none');
  document.getElementById('promo-tab-' + cat).style.display = '';
  document.querySelectorAll('#promo-tab-nav .tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

// ── Open edit product modal ────────────────────────────────────────────────
function openEditProd(p) {
  document.getElementById('edit-pid').value          = p.id;
  document.getElementById('edit-pname').value        = p.name;
  document.getElementById('edit-pcat').value         = p.category;
  document.getElementById('edit-pprice').value       = p.price;
  document.getElementById('edit-ppromo_price').value = p.promo_price || '';
  document.getElementById('edit-pdesc').value        = p.description || '';
  document.getElementById('edit-pimg').value         = p.image || '';
  document.getElementById('edit-pis-promo-val').value = p.is_promo || 0;
  openModal('edit-prod-modal');
}

// ── Open edit promo modal (promos section) ─────────────────────────────────
function openEditProdPromo(p) {
  document.getElementById('edit-promo-pid').value   = p.id;
  document.getElementById('edit-promo-title').textContent = 'Edit Promo: ' + p.name;
  document.getElementById('edit-promo-price').value = p.price;
  document.getElementById('edit-promo-pprice').value= p.promo_price || '';
  document.getElementById('edit-promo-pname').value = p.name;
  document.getElementById('edit-promo-pcat').value  = p.category;
  document.getElementById('edit-promo-pdesc').value = p.description || '';
  document.getElementById('edit-promo-pimg').value  = p.image || '';
  document.getElementById('edit-promo-is').checked  = p.is_promo == 1;
  openModal('edit-prod-promo-modal');
}

// ── Open edit inventory modal ──────────────────────────────────────────────
function openEditInv(item) {
  document.getElementById('edit-inv-id').value     = item.id;
  document.getElementById('edit-inv-name').value   = item.item_name;
  document.getElementById('edit-inv-qty').value    = item.quantity;
  document.getElementById('edit-inv-thresh').value = item.low_stock_threshold;
  document.getElementById('edit-inv-title').textContent = 'Edit: ' + item.item_name;
  openModal('edit-inv-modal');
}

// ── Open restock modal ─────────────────────────────────────────────────────
function openRestockModal(id, name) {
  document.getElementById('restock-inv-id').value = id;
  document.getElementById('restock-title').textContent = 'Restock: ' + name;
  openModal('restock-modal');
}

// ── Open update status modal ───────────────────────────────────────────────
function openStatusModal(orderId, currentStatus) {
  document.getElementById('status-modal-order-id').value = orderId;
  document.getElementById('update-status-title').textContent = 'Update Order #' + orderId;
  const sel = document.getElementById('status-modal-select');
  for (let i = 0; i < sel.options.length; i++) {
    if (sel.options[i].value === currentStatus) { sel.selectedIndex = i; break; }
  }
  openModal('update-status-modal');
}

// ── Auto-dismiss alerts ────────────────────────────────────────────────────
document.querySelectorAll('.alert').forEach(el => {
  setTimeout(() => {
    el.style.transition = 'opacity 0.4s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 400);
  }, 4500);
});
</script>

</body>
</html>