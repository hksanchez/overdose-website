<?php
session_start();
require_once 'includes/db.php';

// ── AUTH GUARD — staff session only, admin_id is NOT accepted ───────────────
if (!isset($_SESSION['staff_id'])) {
    header("Location: admin_login.php");
    exit();
}

// ── LOGOUT ──────────────────────────────────────────────────────────────────
if (isset($_GET['staff_logout'])) {
    session_unset();
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

$staff_name = $_SESSION['staff_name'] ?? 'Staff';
$staff_user = $_SESSION['staff_user'] ?? 'staff';

// ── ENSURE COLUMNS EXIST ────────────────────────────────────────────────────
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS fulfillment_type VARCHAR(10) DEFAULT 'pickup'");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_address TEXT DEFAULT NULL");
$conn->query("ALTER TABLE inventory ADD COLUMN IF NOT EXISTS linked_product_id INT DEFAULT NULL");

$action_msg = '';

// ═══════════════════════════════════════════════════════════════════════════
// POST ACTION HANDLERS
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Accept Order (Pending → Preparing + deduct inventory) ──────────────
    if (isset($_POST['accept_order'])) {
        $oid = (int)$_POST['order_id'];
        $items_q = $conn->prepare("SELECT oi.product_id, oi.quantity, p.category FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
        $items_q->bind_param("i", $oid);
        $items_q->execute();
        $items_res = $items_q->get_result();
        while ($item = $items_res->fetch_assoc()) {
            if ($item['category'] === 'coffee') {
                $qty = $item['quantity'];
                $conn->query("UPDATE inventory SET quantity = GREATEST(0, quantity - $qty) WHERE item_name IN ('Cups','Lids','Straws') AND category = 'supplies'");
            }
            if ($item['category'] === 'pastries') {
                $pid = $item['product_id']; $qty = $item['quantity'];
                $conn->query("UPDATE inventory SET quantity = GREATEST(0, quantity - $qty) WHERE linked_product_id = $pid");
            }
        }
        $upd = $conn->prepare("UPDATE orders SET status='Preparing' WHERE id=? AND status='Pending'");
        $upd->bind_param("i", $oid);
        $upd->execute();
        $action_msg = 'success:Order #' . $oid . ' accepted — now Preparing.';
    }

    // ── Update Order Status ─────────────────────────────────────────────────
    if (isset($_POST['update_order_status'])) {
        $oid    = (int)$_POST['order_id'];
        $status = $_POST['new_status'];
        $allowed = ['Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled'];
        if (in_array($status, $allowed)) {
            $upd = $conn->prepare("UPDATE orders SET status=? WHERE id=?");
            $upd->bind_param("si", $status, $oid);
            $upd->execute();
            $action_msg = 'success:Order #' . $oid . ' → ' . $status . '.';
        }
    }

    // ── Restock Inventory ───────────────────────────────────────────────────
    if (isset($_POST['restock_inventory'])) {
        $iid = (int)$_POST['inv_id'];
        $amt = max(1, (int)$_POST['restock_amount']);
        $upd = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id=?");
        $upd->bind_param("ii", $amt, $iid);
        $upd->execute();
        $action_msg = 'success:Stock restocked successfully.';
    }

    // ── Update Inventory Quantity ───────────────────────────────────────────
    if (isset($_POST['update_inventory'])) {
        $iid    = (int)$_POST['inv_id'];
        $qty    = (int)$_POST['quantity'];
        $thresh = (int)$_POST['threshold'];
        $upd = $conn->prepare("UPDATE inventory SET quantity=?, low_stock_threshold=? WHERE id=?");
        $upd->bind_param("iii", $qty, $thresh, $iid);
        $upd->execute();
        $action_msg = 'success:Inventory updated.';
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// DATA FETCH
// ═══════════════════════════════════════════════════════════════════════════
// Staff can only access these sections — block any URL manipulation
$allowed_sections = ['dashboard', 'orders', 'inventory'];
$section = $_GET['s'] ?? 'dashboard';
if (!in_array($section, $allowed_sections)) {
    $section = 'dashboard';
}

// Dashboard stats — no sales figures or top-selling item for staff
$completed_orders = 0; $pending_orders = 0; $preparing_orders = 0; $ready_orders = 0;

$co = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Completed'");
if ($co) $completed_orders = $co->fetch_assoc()['c'];

$po = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Pending'");
if ($po) $pending_orders = $po->fetch_assoc()['c'];

$prq = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Preparing'");
if ($prq) $preparing_orders = $prq->fetch_assoc()['c'];

$rq = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Ready' OR status='Out for Delivery'");
if ($rq) $ready_orders = $rq->fetch_assoc()['c'];

// Inventory alerts (low stock)
$inv_alerts = $conn->query("SELECT * FROM inventory WHERE quantity <= low_stock_threshold ORDER BY (quantity/low_stock_threshold) ASC");

// Recent orders for dashboard
$recent_orders = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 10");

// Pending orders for dashboard quick-action
$recent_pending = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status = 'Pending' ORDER BY o.created_at DESC LIMIT 8");

// Completed orders for dashboard
$recent_completed = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status = 'Completed' ORDER BY o.created_at DESC LIMIT 5");

// Full inventory list
$inv_all = $conn->query("SELECT * FROM inventory ORDER BY category, item_name");

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
  <title>Staff Portal — Overdose Cafe</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400;1,700&family=DM+Sans:wght@300;400;500;600&display=swap"/>
  <link rel="stylesheet" href="assets/admin.css">
  <style>
    /* Staff-specific accent: keep gold brand but teal sidebar marker */
    .staff-role-badge {
      display: inline-block;
      font-size: 0.58rem;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      background: rgba(91,155,212,0.15);
      color: #5B9BD4;
      border: 1px solid rgba(91,155,212,0.3);
      border-radius: 2px;
      padding: 2px 7px;
      margin-top: 4px;
    }
    .order-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 4px;
      padding: 16px 18px;
      display: flex;
      align-items: center;
      gap: 14px;
      transition: border-color 0.2s;
    }
    .order-card:hover { border-color: rgba(212,175,90,0.3); }
    .order-card-num {
      font-size: 1rem;
      font-weight: 700;
      color: var(--gold);
      white-space: nowrap;
    }
    .order-card-meta {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: 2px;
    }
    .order-card-actions { margin-left: auto; display: flex; gap: 7px; flex-shrink: 0; }
    .pending-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-top: 4px;
    }
    @media (max-width: 900px) { .pending-grid { grid-template-columns: 1fr; } }
    .inv-alert-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 0;
      border-bottom: 1px solid var(--border);
      gap: 12px;
    }
    .inv-alert-row:last-child { border-bottom: none; }
    .inv-alert-name { font-size: 0.83rem; font-weight: 600; color: var(--cream); }
    .inv-alert-qty  { font-size: 0.78rem; color: var(--muted); margin-top: 2px; }
  </style>
</head>
<body>
<div class="admin-shell">

  <!-- ══ SIDEBAR ══════════════════════════════════════════════════════════ -->
  <aside class="admin-sidebar">
    <div class="sidebar-brand">
      <a href="staff.php?s=dashboard" style="text-decoration:none;color:inherit;display:block;">
        <div class="sidebar-brand-name">Overdose Cafe</div>
        <div class="sidebar-brand-sub">Staff Portal</div>
      </a>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-label">Overview</div>
      <a href="staff.php?s=dashboard" class="nav-item <?= $section==='dashboard'?'active':'' ?>">
        <span class="nav-icon">◈</span> Dashboard
      </a>

      <div class="nav-group-label">Operations</div>
      <a href="staff.php?s=orders" class="nav-item <?= $section==='orders'?'active':'' ?>">
        <span class="nav-icon">📋</span> Orders
        <?php if ($pending_orders > 0): ?>
          <span class="nav-badge"><?= $pending_orders ?></span>
        <?php endif; ?>
      </a>
      <a href="staff.php?s=inventory" class="nav-item <?= $section==='inventory'?'active':'' ?>">
        <span class="nav-icon">📦</span> Inventory
        <?php
          $low_count = $inv_alerts ? $inv_alerts->num_rows : 0;
          if ($low_count > 0): ?>
          <span class="nav-badge" style="background:rgba(224,85,85,0.2);color:#E05555;border-color:rgba(224,85,85,0.3);"><?= $low_count ?></span>
        <?php endif; ?>
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-admin-info">
        <div class="sidebar-avatar"><?= strtoupper(substr($staff_name, 0, 1)) ?></div>
        <div>
          <div class="sidebar-admin-name"><?= htmlspecialchars($staff_name) ?></div>
          <div class="sidebar-admin-role">Staff Member</div>
        </div>
      </div>
      <a href="staff.php?staff_logout=1" class="sidebar-logout">Sign Out</a>
    </div>
  </aside>

  <!-- ══ MAIN ═════════════════════════════════════════════════════════════ -->
  <div class="admin-main">

    <!-- Top bar -->
    <div class="admin-topbar">
      <?php
        $titles = [
          'dashboard' => ['Dashboard', 'Good ' . (date('H')<12?'morning':(date('H')<18?'afternoon':'evening')) . ', ' . explode(' ',$staff_name)[0] . '!'],
          'orders'    => ['Orders', 'Accept and update customer orders'],
          'inventory' => ['Inventory', 'Restock and manage stock levels'],
        ];
        $tt = $titles[$section] ?? ['Dashboard',''];
      ?>
      <div>
        <span class="topbar-title"><?= $tt[0] ?></span>
        <span class="topbar-subtitle"><?= $tt[1] ?></span>
      </div>
      <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
        <?php if ($pending_orders > 0): ?>
          <span style="font-size:0.72rem;background:rgba(212,175,90,0.12);color:var(--gold);border:1px solid rgba(212,175,90,0.25);border-radius:10px;padding:3px 12px;">
            ⏳ <?= $pending_orders ?> pending
          </span>
        <?php endif; ?>
        <a href="products.php" target="_blank" class="btn btn-ghost btn-sm">↗ View Menu</a>
      </div>
    </div>

    <div class="admin-content">

      <!-- ── ALERT ──────────────────────────────────────────────────────── -->
      <?php if ($action_msg):
        [$type, $msg] = explode(':', $action_msg, 2);
      ?>
        <div class="alert alert-<?= $type === 'success' ? 'success' : 'error' ?>">
          <?= $type === 'success' ? '✓' : '✕' ?> <?= htmlspecialchars($msg) ?>
        </div>
      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════════════════
           DASHBOARD
      ══════════════════════════════════════════════════════════════════════ -->
      <?php if ($section === 'dashboard'): ?>

        <!-- Stat cards (no sales figures or top-selling item) -->
        <div class="grid-4">
          <div class="stat-card">
            <div class="stat-label">Pending</div>
            <div class="stat-icon">⏳</div>
            <div class="stat-value <?= $pending_orders > 0 ? 'stat-accent' : '' ?>"><?= $pending_orders ?></div>
            <div class="stat-sub">Awaiting acceptance</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Preparing</div>
            <div class="stat-icon">🔥</div>
            <div class="stat-value <?= $preparing_orders > 0 ? 'stat-accent' : '' ?>"><?= $preparing_orders ?></div>
            <div class="stat-sub">Currently being made</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Ready / Out for Delivery</div>
            <div class="stat-icon">🛵</div>
            <div class="stat-value <?= $ready_orders > 0 ? 'stat-accent' : '' ?>"><?= $ready_orders ?></div>
            <div class="stat-sub">Waiting on customer</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Completed</div>
            <div class="stat-icon">✓</div>
            <div class="stat-value"><?= $completed_orders ?></div>
            <div class="stat-sub">All time fulfilled</div>
          </div>
        </div>

        <div class="dashboard-grid">
          <!-- Left column -->
          <div>

            <!-- Pending Orders — quick action -->
            <div class="card" style="margin-top:24px;">
              <div class="card-header">
                Pending Orders
                <?php if ($pending_orders > 0): ?>
                  <span style="font-size:0.65rem;background:rgba(212,175,90,0.12);color:var(--gold);border:1px solid rgba(212,175,90,0.25);border-radius:10px;padding:2px 8px;"><?= $pending_orders ?> waiting</span>
                <?php endif; ?>
                <a href="staff.php?s=orders&filter=Pending" class="btn btn-ghost btn-sm" style="margin-left:auto;">View All →</a>
              </div>
              <div class="card-body" style="padding:12px 20px;">
                <?php
                  $rp_rows = [];
                  if ($recent_pending) while ($rp = $recent_pending->fetch_assoc()) $rp_rows[] = $rp;
                ?>
                <?php if (empty($rp_rows)): ?>
                  <p style="color:var(--muted);font-size:0.82rem;padding:12px 0;">🎉 No pending orders — all clear!</p>
                <?php else: ?>
                  <div class="pending-grid">
                    <?php foreach ($rp_rows as $rp): ?>
                      <div class="order-card">
                        <div style="flex:1;min-width:0;">
                          <div class="order-card-num">#<?= $rp['id'] ?></div>
                          <div class="order-card-meta"><?= htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name']) ?></div>
                          <div class="order-card-meta" style="margin-top:2px;">₱<?= number_format($rp['total_amount'],2) ?> · <?= date('g:i A', strtotime($rp['created_at'])) ?></div>
                        </div>
                        <div class="order-card-actions">
                          <form method="POST" style="display:inline;">
                            <input type="hidden" name="order_id" value="<?= $rp['id'] ?>"/>
                            <button type="submit" name="accept_order" class="btn btn-success btn-sm">✓ Accept</button>
                          </form>
                          <a href="staff.php?s=orders&order_id=<?= $rp['id'] ?>" class="btn btn-ghost btn-sm">View</a>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Recent Orders -->
            <div class="card" style="margin-top:20px;">
              <div class="card-header">
                Recent Orders
                <a href="staff.php?s=orders" class="btn btn-ghost btn-sm">View All →</a>
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
                            <a href="staff.php?s=orders&order_id=<?= $ro['id'] ?>" style="color:var(--gold);font-weight:600;text-decoration:none;">#<?= $ro['id'] ?></a>
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

          </div>

          <!-- Right column: completed + inventory alerts -->
          <div>

            <!-- Completed Orders -->
            <div class="card" style="margin-top:24px;">
              <div class="card-header">
                Completed Orders
                <a href="staff.php?s=orders&filter=Completed" class="btn btn-ghost btn-sm">View All →</a>
              </div>
              <div class="card-body" style="padding:0 20px;">
                <?php
                  $rc_rows = [];
                  if ($recent_completed) while ($rc = $recent_completed->fetch_assoc()) $rc_rows[] = $rc;
                ?>
                <?php if (empty($rc_rows)): ?>
                  <p style="color:var(--muted);font-size:0.82rem;padding:12px 0;">No completed orders yet.</p>
                <?php else: ?>
                  <?php foreach ($rc_rows as $rc): ?>
                    <div class="pending-order-row">
                      <div style="flex:1;">
                        <div class="pending-order-num">Order #<?= $rc['id'] ?></div>
                        <div class="pending-order-meta"><?= htmlspecialchars($rc['first_name'] . ' ' . $rc['last_name']) ?> · ₱<?= number_format($rc['total_amount'],2) ?></div>
                      </div>
                      <span style="font-size:0.68rem;font-weight:700;color:#5BAD7E;background:rgba(91,173,126,0.1);border:1px solid rgba(91,173,126,0.25);border-radius:10px;padding:2px 9px;">Done</span>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <!-- Inventory Alerts -->
            <div class="card" style="margin-top:20px;">
              <div class="card-header">
                Inventory Alerts
                <?php if ($low_count > 0): ?>
                  <span style="font-size:0.65rem;background:rgba(224,85,85,0.15);color:var(--error);border:1px solid rgba(224,85,85,0.25);border-radius:10px;padding:2px 8px;"><?= $low_count ?> Low</span>
                <?php endif; ?>
                <a href="staff.php?s=inventory" class="btn btn-ghost btn-sm" style="margin-left:auto;">Manage →</a>
              </div>
              <div class="card-body" style="padding:0 20px;">
                <?php if ($low_count === 0): ?>
                  <p style="color:var(--muted);font-size:0.82rem;padding:12px 0;">✓ All stock levels are fine.</p>
                <?php else: ?>
                  <?php
                    $inv_alerts->data_seek(0);
                    while ($ia = $inv_alerts->fetch_assoc()):
                      $ratio   = $ia['low_stock_threshold'] > 0 ? $ia['quantity'] / $ia['low_stock_threshold'] : 0;
                      $bar_c   = $ia['quantity'] <= 0 ? '#E05555' : ($ratio <= 0.5 ? '#E05555' : '#D4914A');
                      $lvl     = $ia['quantity'] <= 0 ? 'Empty' : ($ratio <= 0.5 ? 'Critical' : 'Low');
                  ?>
                    <div class="inv-alert-row">
                      <div>
                        <div class="inv-alert-name"><?= htmlspecialchars($ia['item_name']) ?></div>
                        <div class="inv-alert-qty"><?= $ia['quantity'] ?> / <?= $ia['low_stock_threshold'] ?> <?= $ia['unit'] ?> threshold</div>
                      </div>
                      <div style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:0.62rem;font-weight:700;color:<?= $bar_c ?>;background:<?= $bar_c ?>18;border:1px solid <?= $bar_c ?>33;border-radius:10px;padding:2px 8px;"><?= $lvl ?></span>
                        <button class="btn btn-outline btn-sm" onclick='openRestockModal(<?= $ia['id'] ?>, "<?= addslashes($ia['item_name']) ?>")'>Restock</button>
                      </div>
                    </div>
                  <?php endwhile; ?>
                <?php endif; ?>
              </div>
            </div>

          </div>
        </div>

      <!-- ══════════════════════════════════════════════════════════════════
           ORDERS
      ══════════════════════════════════════════════════════════════════════ -->
      <?php elseif ($section === 'orders'): ?>

        <?php if ($order_detail): ?>

          <!-- Order Detail View -->
          <div style="margin-bottom:20px;">
            <a href="staff.php?s=orders" style="font-size:0.80rem;color:var(--muted);text-decoration:none;">← Back to Orders</a>
          </div>

          <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">

            <!-- Items card -->
            <div class="card">
              <div class="card-header">
                Order #<?= $order_detail['id'] ?> — Items
                <?php $sc = $status_colors[$order_detail['status']] ?? '#888'; ?>
                <span class="status-pill" style="color:<?= $sc ?>;border-color:<?= $sc ?>33;background:<?= $sc ?>15;"><?= $order_detail['status'] ?></span>
              </div>
              <div class="table-wrap">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Category</th>
                      <th>Qty</th>
                      <th style="text-align:right;">Unit Price</th>
                      <th style="text-align:right;">Subtotal</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($order_detail_items as $oi): ?>
                      <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($oi['name']) ?></td>
                        <td style="color:var(--muted);font-size:0.78rem;text-transform:capitalize;"><?= $oi['category'] ?></td>
                        <td style="font-weight:700;color:var(--cream);"><?= $oi['quantity'] ?></td>
                        <td style="text-align:right;color:var(--muted);">₱<?= number_format($oi['price'],2) ?></td>
                        <td style="text-align:right;font-weight:700;color:var(--gold);">₱<?= number_format($oi['price'] * $oi['quantity'],2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <tr style="border-top:1px solid var(--border);">
                      <td colspan="4" style="text-align:right;font-weight:600;color:var(--cream);padding-top:12px;">Total</td>
                      <td style="text-align:right;font-weight:800;color:var(--gold);font-size:1rem;padding-top:12px;">₱<?= number_format($order_detail['total_amount'],2) ?></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Order info + actions -->
            <div class="card">
              <div class="card-header">Order Details</div>
              <div class="card-body">
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
                    <span style="color:var(--muted);">Fulfillment</span>
                    <span><?= ucfirst($order_detail['fulfillment_type'] ?? 'pickup') ?></span>
                  </div>
                  <?php if (!empty($order_detail['delivery_address'])): ?>
                  <div style="display:flex;justify-content:space-between;gap:16px;">
                    <span style="color:var(--muted);">Address</span>
                    <span style="text-align:right;"><?= htmlspecialchars($order_detail['delivery_address']) ?></span>
                  </div>
                  <?php endif; ?>
                  <?php if (!empty($order_detail['voucher_code'])): ?>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Voucher</span>
                    <span style="color:var(--gold);"><?= htmlspecialchars($order_detail['voucher_code']) ?></span>
                  </div>
                  <?php endif; ?>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Status</span>
                    <?php $sc = $status_colors[$order_detail['status']] ?? '#888'; ?>
                    <span class="status-pill" style="color:<?= $sc ?>;border-color:<?= $sc ?>33;background:<?= $sc ?>15;"><?= $order_detail['status'] ?></span>
                  </div>
                </div>

                <div style="margin-top:20px;border-top:1px solid var(--border);padding-top:18px;">
                  <div style="font-size:0.68rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);opacity:0.7;margin-bottom:12px;">Update Status</div>
                  <form method="POST" style="display:flex;gap:10px;align-items:center;">
                    <input type="hidden" name="order_id" value="<?= $order_detail['id'] ?>"/>
                    <select name="new_status" style="flex:1;background:var(--panel);border:1px solid var(--border);border-radius:2px;padding:9px 12px;color:var(--cream);font-family:'DM Sans',sans-serif;font-size:0.83rem;outline:none;">
                      <?php foreach (['Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled'] as $st): ?>
                        <option value="<?= $st ?>" <?= $order_detail['status']===$st?'selected':'' ?>><?= $st ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" name="update_order_status" class="btn btn-gold btn-sm">Update</button>
                  </form>

                  <?php if ($order_detail['status'] === 'Pending'): ?>
                    <form method="POST" style="margin-top:10px;">
                      <input type="hidden" name="order_id" value="<?= $order_detail['id'] ?>"/>
                      <button type="submit" name="accept_order" class="btn btn-success btn-sm" style="width:100%;justify-content:center;">✓ Accept & Start Preparing</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>

          </div>

        <?php else: ?>

          <!-- Orders List -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
            <div>
              <div class="section-title">All Orders</div>
              <div class="section-sub" style="margin-bottom:0;">Accept, prepare, and update customer orders.</div>
            </div>
          </div>

          <!-- Filter tabs -->
          <div class="tab-nav" id="orders-tab-nav">
            <?php
              $order_tabs  = ['All','Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled'];
              $active_tab  = $_GET['filter'] ?? 'All';
            ?>
            <?php foreach ($order_tabs as $ot): ?>
              <a href="staff.php?s=orders&filter=<?= urlencode($ot) ?>" class="tab-btn <?= $active_tab===$ot?'active':'' ?>"><?= $ot ?></a>
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
                    <th>Status</th>
                    <th>Placed</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                  $filter_status = $_GET['filter'] ?? 'All';
                  if ($filter_status === 'All') {
                      $olist = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY FIELD(o.status,'Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled'), o.created_at DESC LIMIT 100");
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
                    <td>
                      <span class="status-pill" style="color:<?= $sc ?>;border-color:<?= $sc ?>33;background:<?= $sc ?>15;"><?= $ord['status'] ?></span>
                    </td>
                    <td style="color:var(--muted);font-size:0.78rem;"><?= date('M d, g:i A', strtotime($ord['created_at'])) ?></td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap;">
                      <?php if ($ord['status'] === 'Pending'): ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="order_id" value="<?= $ord['id'] ?>"/>
                          <button type="submit" name="accept_order" class="btn btn-success btn-sm">✓ Accept</button>
                        </form>
                      <?php endif; ?>
                      <button class="btn btn-outline btn-sm" onclick="openStatusModal(<?= $ord['id'] ?>, '<?= addslashes($ord['status']) ?>')">Status</button>
                      <a href="staff.php?s=orders&order_id=<?= $ord['id'] ?>&filter=<?= urlencode($filter_status) ?>" class="btn btn-ghost btn-sm">View</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
                <?php if (!$found_orders): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:32px;">No orders found.</td></tr>
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
            <div class="section-sub" style="margin-bottom:0;">View stock levels and restock items. Auto-deducts when orders are accepted.</div>
          </div>
        </div>

        <!-- Tabs -->
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
                    $ratio   = $iv['low_stock_threshold'] > 0 ? $iv['quantity'] / $iv['low_stock_threshold'] : 2;
                    $bar_c   = $iv['quantity'] <= 0 ? '#E05555' : ($ratio <= 0.5 ? '#E05555' : ($ratio <= 1 ? '#D4914A' : '#5BAD7E'));
                    $bar_p   = min(100, round($ratio * 100));
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
                <div class="form-group"><label>Quantity</label><input type="number" name="quantity" id="edit-inv-qty" min="0" required/></div>
                <div class="form-group"><label>Low Stock Threshold</label><input type="number" name="threshold" id="edit-inv-thresh" min="1" required/></div>
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

      <?php endif; ?>

    </div><!-- /admin-content -->

    <footer class="admin-footer">
      <span>© <?= date('Y') ?> Overdose Cafe · Staff Portal</span>
      <span>Logged in as <strong style="color:var(--gold);"><?= htmlspecialchars($staff_user) ?></strong></span>
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