<?php
session_start();
require_once 'includes/db.php';

// ── AUTH GUARD — rider session only ─────────────────────────────────────────
if (!isset($_SESSION['rider_id'])) {
    header("Location: admin_login.php");
    exit();
}

// ── LOGOUT ──────────────────────────────────────────────────────────────────
if (isset($_GET['rider_logout'])) {
    unset($_SESSION['rider_id'], $_SESSION['rider_name'], $_SESSION['rider_user']);
    header("Location: admin_login.php");
    exit();
}

$rider_name = $_SESSION['rider_name'] ?? 'Rider';
$rider_user = $_SESSION['rider_user'] ?? 'rider';

$action_msg = '';

// ═══════════════════════════════════════════════════════════════════════════
// POST ACTION HANDLERS
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Mark Delivered (Out for Delivery → Completed) ──────────────────────
    if (isset($_POST['mark_delivered'])) {
        $oid = (int)$_POST['order_id'];
        $upd = $conn->prepare("UPDATE orders SET status='Completed', is_viewed=0 WHERE id=? AND status='Out for Delivery'");
        $upd->bind_param("i", $oid);
        $upd->execute();
        if ($upd->affected_rows > 0) {
            $action_msg = 'success:<a href="rider.php?s=history">Order #' . $oid . ' marked as Delivered!</a>';
        } else {
            $action_msg = 'error:Could not update order. It may no longer be Out for Delivery.';
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION ROUTING
// ═══════════════════════════════════════════════════════════════════════════
$allowed_sections = ['dashboard', 'deliveries', 'history'];
$section = $_GET['s'] ?? 'dashboard';
if (!in_array($section, $allowed_sections)) {
    $section = 'dashboard';
}

// ── STATS ───────────────────────────────────────────────────────────────────
$out_for_delivery = 0;
$total_delivered  = 0;
$todays_delivered = 0;

$oq = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Out for Delivery' AND fulfillment_type='delivery'");
if ($oq) $out_for_delivery = $oq->fetch_assoc()['c'];

$dq = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Completed' AND fulfillment_type='delivery'");
if ($dq) $total_delivered = $dq->fetch_assoc()['c'];

$tq = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Completed' AND fulfillment_type='delivery' AND DATE(created_at) = CURDATE()");
if ($tq) $todays_delivered = $tq->fetch_assoc()['c'];

// ── DASHBOARD FEEDS ─────────────────────────────────────────────────────────
// Orders marked Out for Delivery by admin/staff — rider acts on these
$active_deliveries = $conn->query("SELECT o.*, u.first_name, u.last_name, u.phone FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status='Out for Delivery' AND o.fulfillment_type='delivery' ORDER BY o.created_at ASC");

// Recent delivered orders for dashboard sidebar
$recent_delivered = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status='Completed' AND o.fulfillment_type='delivery' ORDER BY o.created_at DESC LIMIT 5");

// ── ORDER DETAIL ─────────────────────────────────────────────────────────────
$order_detail       = null;
$order_detail_items = [];
if (isset($_GET['order_id']) && in_array($section, ['deliveries', 'history'])) {
    $oid = (int)$_GET['order_id'];
    $oq  = $conn->prepare("SELECT o.*, u.first_name, u.last_name, u.phone FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ? AND o.fulfillment_type='delivery'");
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
  <?php if (isset($_GET['s']) && $_GET['s'] === 'deliveries'): ?>
  <meta http-equiv="refresh" content="30">
  <?php elseif (!isset($_GET['s']) || $_GET['s'] === 'dashboard'): ?>
  <meta http-equiv="refresh" content="60">
  <?php endif; ?>
  <title>Rider Portal — Overdose Cafe</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400;1,700&family=DM+Sans:wght@300;400;500;600&display=swap"/>
  <link rel="stylesheet" href="assets/admin.css">
  <style>
    /* ── Rider accent: purple/violet sidebar marker ── */
    .rider-role-badge {
      display: inline-block;
      font-size: 0.58rem;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      background: rgba(155,123,212,0.15);
      color: #9B7BD4;
      border: 1px solid rgba(155,123,212,0.3);
      border-radius: 2px;
      padding: 2px 7px;
      margin-top: 4px;
    }

    /* Active nav item gets purple accent */
    .admin-sidebar .nav-item.active {
      color: #9B7BD4 !important;
      background: rgba(155,123,212,0.10) !important;
      border-left-color: #9B7BD4 !important;
    }

    /* Delivery card */
    .delivery-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 4px;
      padding: 18px 20px;
      display: flex;
      align-items: flex-start;
      gap: 16px;
      transition: border-color 0.2s;
    }
    .delivery-card:hover { border-color: rgba(155,123,212,0.35); }
    .delivery-card-num {
      font-size: 1rem;
      font-weight: 700;
      color: var(--gold);
      white-space: nowrap;
    }
    .delivery-card-meta {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: 3px;
    }
    .delivery-card-address {
      font-size: 0.78rem;
      color: var(--cream);
      margin-top: 5px;
      display: flex;
      align-items: flex-start;
      gap: 5px;
    }
    .delivery-card-address-icon {
      color: #9B7BD4;
      flex-shrink: 0;
      margin-top: 1px;
    }
    .delivery-card-actions { margin-left: auto; display: flex; gap: 7px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }

    /* Delivery grids */
    .delivery-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 4px;
    }
    @media (max-width: 900px) { .delivery-grid { grid-template-columns: 1fr; } }

    /* Purple btn variant */
    .btn-purple {
      background: rgba(155,123,212,0.15);
      color: #9B7BD4;
      border: 1px solid rgba(155,123,212,0.35);
    }
    .btn-purple:hover {
      background: rgba(155,123,212,0.25);
      border-color: rgba(155,123,212,0.5);
    }

    /* History row */
    .history-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid var(--border);
      gap: 12px;
    }
    .history-row:last-child { border-bottom: none; }
    .history-order-num { font-size: 0.85rem; font-weight: 700; color: var(--gold); }
    .history-order-meta { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }

    /* Status tag */
    .tag-out {
      font-size: 0.65rem; font-weight: 700;
      padding: 3px 9px; border-radius: 10px;
      background: rgba(155,123,212,0.12);
      color: #9B7BD4;
      border: 1px solid rgba(155,123,212,0.3);
      white-space: nowrap;
    }
    .tag-delivered {
      font-size: 0.65rem; font-weight: 700;
      padding: 3px 9px; border-radius: 10px;
      background: rgba(91,173,126,0.12);
      color: #5BAD7E;
      border: 1px solid rgba(91,173,126,0.3);
      white-space: nowrap;
    }
    .tag-ready {
      font-size: 0.65rem; font-weight: 700;
      padding: 3px 9px; border-radius: 10px;
      background: rgba(91,173,126,0.12);
      color: #5BAD7E;
      border: 1px solid rgba(91,173,126,0.3);
      white-space: nowrap;
    }

    /* Empty state */
    .empty-state {
      padding: 40px 20px;
      text-align: center;
      color: var(--muted);
    }
    .empty-state-icon { font-size: 2.2rem; margin-bottom: 10px; }
    .empty-state-title { font-size: 0.88rem; font-weight: 600; color: var(--cream); margin-bottom: 6px; }
    .empty-state-sub { font-size: 0.78rem; }

    /* Active delivery highlight border */
    .delivery-card.active-delivery {
      border-color: rgba(155,123,212,0.4);
      background: rgba(155,123,212,0.04);
    }
  </style>
</head>
<body>
<div class="admin-shell">

  <!-- ══ SIDEBAR ══════════════════════════════════════════════════════════ -->
  <aside class="admin-sidebar">
    <div class="sidebar-brand">
      <a href="rider.php?s=dashboard" style="text-decoration:none;color:inherit;display:block;">
        <div class="sidebar-brand-name">Overdose Cafe</div>
        <div class="sidebar-brand-sub">Rider Portal</div>
      </a>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group-label">Overview</div>
      <a href="rider.php?s=dashboard" class="nav-item <?= $section==='dashboard'?'active':'' ?>">
        <span class="nav-icon">◈</span> Dashboard
      </a>

      <div class="nav-group-label">Deliveries</div>
      <a href="rider.php?s=deliveries" class="nav-item <?= $section==='deliveries'?'active':'' ?>">
        <span class="nav-icon">🛵</span> Active Deliveries
        <?php if ($out_for_delivery > 0): ?>
          <span class="nav-badge"><?= $out_for_delivery ?></span>
        <?php endif; ?>
      </a>
      <a href="rider.php?s=history" class="nav-item <?= $section==='history'?'active':'' ?>">
        <span class="nav-icon">📋</span> Delivery History
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-admin-info">
        <div class="sidebar-avatar"><?= strtoupper(substr($rider_name, 0, 1)) ?></div>
        <div>
          <div class="sidebar-admin-name"><?= htmlspecialchars($rider_name) ?></div>
          <div class="sidebar-admin-role">Rider</div>
        </div>
      </div>
      <a href="rider.php?rider_logout=1" class="sidebar-logout">Sign Out</a>
    </div>
  </aside>

  <!-- ══ MAIN ═════════════════════════════════════════════════════════════ -->
  <div class="admin-main">

    <!-- Top bar -->
    <div class="admin-topbar">
      <?php
        $titles = [
          'dashboard'  => ['Dashboard', 'Good ' . (date('H')<12?'morning':(date('H')<18?'afternoon':'evening')) . ', ' . explode(' ',$rider_name)[0] . '! Ready to ride?'],
          'deliveries' => ['Active Deliveries', 'Accept and fulfill pending delivery orders'],
          'history'    => ['Delivery History', 'All completed deliveries'],
        ];
        $tt = $titles[$section] ?? ['Dashboard',''];
      ?>
      <div>
        <span class="topbar-title"><?= $tt[0] ?></span>
        <span class="topbar-subtitle"><?= $tt[1] ?></span>
      </div>
      <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
        <?php if ($out_for_delivery > 0): ?>
          <span style="font-size:0.72rem;background:rgba(155,123,212,0.12);color:#9B7BD4;border:1px solid rgba(155,123,212,0.25);border-radius:10px;padding:3px 12px;">
            🛵 <?= $out_for_delivery ?> on the road
          </span>
        <?php endif; ?>
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

        <!-- Stat cards -->
        <div class="grid-4">
          <div class="stat-card">
            <div class="stat-label">Out for Delivery</div>
            <div class="stat-icon">🛵</div>
            <div class="stat-value <?= $out_for_delivery > 0 ? 'stat-accent' : '' ?>"><?= $out_for_delivery ?></div>
            <div class="stat-sub">Assigned to rider</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Today's Deliveries</div>
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= $todays_delivered ?></div>
            <div class="stat-sub">Completed today</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Total Delivered</div>
            <div class="stat-icon">📊</div>
            <div class="stat-value"><?= $total_delivered ?></div>
            <div class="stat-sub">All time deliveries</div>
          </div>
        </div>

        <div class="dashboard-grid">
          <!-- Left column -->
          <div>

            <!-- Active Deliveries -->
            <div class="card" style="margin-top:20px;">
              <div class="card-header">
                Out for Delivery
                <?php if ($out_for_delivery > 0): ?>
                  <span style="margin-left:8px;font-size:0.65rem;background:rgba(155,123,212,0.12);color:#9B7BD4;border:1px solid rgba(155,123,212,0.25);border-radius:10px;padding:2px 8px;"><?= $out_for_delivery ?> en route</span>
                <?php endif; ?>
                <a href="rider.php?s=deliveries" class="btn btn-ghost btn-sm" style="margin-left:auto;">View All →</a>
              </div>
              <div class="card-body" style="padding:12px 20px;">
                <?php
                  $active_rows = [];
                  if ($active_deliveries) while ($a = $active_deliveries->fetch_assoc()) $active_rows[] = $a;
                ?>
                <?php if (empty($active_rows)): ?>
                  <div class="empty-state" style="padding:20px;">
                    <div class="empty-state-sub">No active deliveries right now.</div>
                  </div>
                <?php else: ?>
                  <div class="delivery-grid">
                    <?php foreach ($active_rows as $a): ?>
                      <div class="delivery-card active-delivery">
                        <div style="flex:1;min-width:0;">
                          <div class="delivery-card-num">#<?= $a['id'] ?></div>
                          <div class="delivery-card-meta"><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?></div>
                          <?php if (!empty($a['phone'])): ?>
                            <div class="delivery-card-meta">📞 <?= htmlspecialchars($a['phone']) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($a['delivery_address'])): ?>
                            <div class="delivery-card-address">
                              <span class="delivery-card-address-icon">📍</span>
                              <span><?= htmlspecialchars($a['delivery_address']) ?></span>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="delivery-card-actions">
                          <a href="rider.php?s=deliveries&order_id=<?= $a['id'] ?>" class="btn btn-gold btn-sm" style="padding:8px 18px;font-size:0.78rem;letter-spacing:0.5px;">View Order →</a>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

          </div>

          <!-- Right column: recent delivered -->
          <div>
            <div class="card" style="margin-top:24px;">
              <div class="card-header">
                Recently Delivered
                <a href="rider.php?s=history" class="btn btn-ghost btn-sm">Full History →</a>
              </div>
              <div class="card-body" style="padding:0 20px;">
                <?php
                  $rd_rows = [];
                  if ($recent_delivered) while ($rd = $recent_delivered->fetch_assoc()) $rd_rows[] = $rd;
                ?>
                <?php if (empty($rd_rows)): ?>
                  <div class="empty-state" style="padding:20px;">
                    <div class="empty-state-sub">No deliveries completed yet.</div>
                  </div>
                <?php else: ?>
                  <?php foreach ($rd_rows as $rd): ?>
                    <div class="history-row">
                      <div>
                        <div class="history-order-num">Order #<?= $rd['id'] ?></div>
                        <div class="history-order-meta"><?= htmlspecialchars($rd['first_name'] . ' ' . $rd['last_name']) ?> · ₱<?= number_format($rd['total_amount'],2) ?></div>
                        <div class="history-order-meta"><?= date('M d, g:i A', strtotime($rd['created_at'])) ?></div>
                      </div>
                      <span class="tag-delivered">Delivered</span>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

          </div>
        </div>

      <!-- ══════════════════════════════════════════════════════════════════
           DELIVERIES
      ══════════════════════════════════════════════════════════════════════ -->
      <?php elseif ($section === 'deliveries'): ?>

        <?php if ($order_detail): ?>

          <!-- Order Detail View -->
          <div style="margin-bottom:20px;">
            <a href="rider.php?s=deliveries" style="font-size:0.80rem;color:var(--muted);text-decoration:none;">← Back to Deliveries</a>
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

            <!-- Order info + rider actions -->
            <div class="card">
              <div class="card-header">Delivery Details</div>
              <div class="card-body">
                <div style="display:flex;flex-direction:column;gap:12px;font-size:0.83rem;">
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Customer</span>
                    <span style="font-weight:600;"><?= htmlspecialchars($order_detail['first_name'] . ' ' . $order_detail['last_name']) ?></span>
                  </div>
                  <?php if (!empty($order_detail['phone'])): ?>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Phone</span>
                    <a href="tel:<?= htmlspecialchars($order_detail['phone']) ?>" style="color:#9B7BD4;text-decoration:none;font-weight:600;">
                      📞 <?= htmlspecialchars($order_detail['phone']) ?>
                    </a>
                  </div>
                  <?php endif; ?>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Placed</span>
                    <span><?= date('M d, Y g:i A', strtotime($order_detail['created_at'])) ?></span>
                  </div>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Type</span>
                    <span style="color:#9B7BD4;font-weight:600;">🛵 Delivery</span>
                  </div>
                  <?php if (!empty($order_detail['delivery_address'])): ?>
                  <div style="display:flex;flex-direction:column;gap:5px;">
                    <span style="color:var(--muted);">Delivery Address</span>
                    <div style="background:rgba(155,123,212,0.07);border:1px solid rgba(155,123,212,0.2);border-radius:3px;padding:10px 12px;font-size:0.82rem;color:var(--cream);line-height:1.5;">
                      📍 <?= htmlspecialchars($order_detail['delivery_address']) ?>
                    </div>
                  </div>
                  <?php endif; ?>
                  <?php if (!empty($order_detail['order_note'])): ?>
                  <div style="margin-top:4px;padding:10px 12px;background:rgba(212,175,90,0.06);border:1px solid rgba(212,175,90,0.18);border-radius:3px;">
                    <div style="font-size:0.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);opacity:0.7;margin-bottom:5px;">Order Note</div>
                    <div style="font-size:0.83rem;color:var(--cream);line-height:1.5;"><?= nl2br(htmlspecialchars($order_detail['order_note'])) ?></div>
                  </div>
                  <?php endif; ?>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Status</span>
                    <?php $sc = $status_colors[$order_detail['status']] ?? '#888'; ?>
                    <span class="status-pill" style="color:<?= $sc ?>;border-color:<?= $sc ?>33;background:<?= $sc ?>15;"><?= $order_detail['status'] ?></span>
                  </div>
                </div>

                <!-- Rider actions -->
                <div style="margin-top:20px;border-top:1px solid var(--border);padding-top:18px;display:flex;flex-direction:column;gap:10px;">
                  <?php if ($order_detail['status'] === 'Out for Delivery'): ?>
                    <form method="POST">
                      <input type="hidden" name="order_id" value="<?= $order_detail['id'] ?>"/>
                      <button type="submit" name="mark_delivered" class="btn btn-success btn-sm" style="width:100%;justify-content:center;">
                        ✓ Mark as Delivered
                      </button>
                    </form>
                  <?php elseif ($order_detail['status'] === 'Completed'): ?>
                    <div style="text-align:center;padding:10px;font-size:0.82rem;color:#5BAD7E;background:rgba(91,173,126,0.08);border:1px solid rgba(91,173,126,0.2);border-radius:3px;">
                      ✓ This order has been delivered.
                    </div>
                  <?php else: ?>
                    <div style="text-align:center;padding:10px;font-size:0.82rem;color:var(--muted);background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:3px;">
                      Waiting for admin or staff to dispatch this order.
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

          </div>

        <?php else: ?>

          <!-- Active Deliveries List -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
            <div>
              <div class="section-title">Active Deliveries</div>
              <div class="section-sub" style="margin-bottom:0;">Orders dispatched by admin or staff — mark them as delivered once completed.</div>
            </div>
          </div>



          <div class="card">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Placed</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                  $dlist = $conn->query("SELECT o.*, u.first_name, u.last_name, u.phone FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status='Out for Delivery' AND o.fulfillment_type='delivery' ORDER BY o.created_at ASC LIMIT 100");
                  $found_orders = false;
                  while ($ord = $dlist->fetch_assoc()):
                    $found_orders = true;
                    $sc = $status_colors[$ord['status']] ?? '#888';
                ?>
                  <tr>
                    <td style="font-weight:700;color:var(--gold);">#<?= $ord['id'] ?></td>
                    <td><?= htmlspecialchars($ord['first_name'] . ' ' . $ord['last_name']) ?></td>
                    <td style="font-size:0.78rem;color:var(--muted);">
                      <?= !empty($ord['phone']) ? htmlspecialchars($ord['phone']) : '—' ?>
                    </td>
                    <td style="font-size:0.78rem;max-width:180px;word-break:break-word;">
                      <?= !empty($ord['delivery_address']) ? htmlspecialchars($ord['delivery_address']) : '<span style="color:var(--muted);">—</span>' ?>
                    </td>
                    <td style="font-weight:600;">₱<?= number_format($ord['total_amount'],2) ?></td>
                    <td>
                      <span class="status-pill" style="color:<?= $sc ?>;border-color:<?= $sc ?>33;background:<?= $sc ?>15;"><?= $ord['status'] ?></span>
                    </td>
                    <td style="color:var(--muted);font-size:0.78rem;white-space:nowrap;"><?= date('M d, g:i A', strtotime($ord['created_at'])) ?></td>
                    <td>
                      <a href="rider.php?s=deliveries&order_id=<?= $ord['id'] ?>" class="btn btn-gold btn-sm" style="padding:8px 18px;font-size:0.78rem;letter-spacing:0.5px;">View Order →</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
                <?php if (!$found_orders): ?>
                  <tr>
                    <td colspan="8" style="text-align:center;padding:40px;">
                      <div style="color:var(--muted);font-size:0.85rem;">
                        🛵 No deliveries out for delivery right now.
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════════════════
           HISTORY
      ══════════════════════════════════════════════════════════════════════ -->
      <?php elseif ($section === 'history'): ?>

        <?php if ($order_detail): ?>

          <!-- History Order Detail View -->
          <div style="margin-bottom:20px;">
            <a href="rider.php?s=history" style="font-size:0.80rem;color:var(--muted);text-decoration:none;">← Back to History</a>
          </div>

          <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">

            <div class="card">
              <div class="card-header">
                Order #<?= $order_detail['id'] ?> — Items
                <span class="tag-delivered" style="margin-left:4px;">Delivered</span>
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
                        <td style="font-weight:700;"><?= $oi['quantity'] ?></td>
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

            <div class="card">
              <div class="card-header">Delivery Info</div>
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
                    <span style="color:var(--muted);">Order Date</span>
                    <span><?= date('M d, Y g:i A', strtotime($order_detail['created_at'])) ?></span>
                  </div>
                  <?php if (!empty($order_detail['delivery_address'])): ?>
                  <div style="display:flex;flex-direction:column;gap:5px;">
                    <span style="color:var(--muted);">Delivered To</span>
                    <div style="background:rgba(91,173,126,0.06);border:1px solid rgba(91,173,126,0.18);border-radius:3px;padding:10px 12px;font-size:0.82rem;line-height:1.5;">
                      📍 <?= htmlspecialchars($order_detail['delivery_address']) ?>
                    </div>
                  </div>
                  <?php endif; ?>
                  <?php if (!empty($order_detail['order_note'])): ?>
                  <div style="margin-top:4px;padding:10px 12px;background:rgba(212,175,90,0.06);border:1px solid rgba(212,175,90,0.18);border-radius:3px;">
                    <div style="font-size:0.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold);opacity:0.7;margin-bottom:5px;">Order Note</div>
                    <div style="font-size:0.83rem;color:var(--cream);line-height:1.5;"><?= nl2br(htmlspecialchars($order_detail['order_note'])) ?></div>
                  </div>
                  <?php endif; ?>
                  <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Status</span>
                    <span class="tag-delivered">Delivered</span>
                  </div>
                </div>
              </div>
            </div>

          </div>

        <?php else: ?>

          <!-- History List -->
          <div style="margin-bottom:24px;">
            <div class="section-title">Delivery History</div>
            <div class="section-sub" style="margin-bottom:0;">All completed delivery orders.</div>
          </div>

          <div class="card">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Address</th>
                    <th>Total</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                  $hlist = $conn->query("SELECT o.*, u.first_name, u.last_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status='Completed' AND o.fulfillment_type='delivery' ORDER BY o.created_at DESC LIMIT 200");
                  $found_history = false;
                  while ($h = $hlist->fetch_assoc()):
                    $found_history = true;
                ?>
                  <tr>
                    <td style="font-weight:700;color:var(--gold);">#<?= $h['id'] ?></td>
                    <td><?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?></td>
                    <td style="font-size:0.78rem;max-width:200px;word-break:break-word;">
                      <?= !empty($h['delivery_address']) ? htmlspecialchars($h['delivery_address']) : '<span style="color:var(--muted);">—</span>' ?>
                    </td>
                    <td style="font-weight:600;">₱<?= number_format($h['total_amount'],2) ?></td>
                    <td style="color:var(--muted);font-size:0.78rem;white-space:nowrap;"><?= date('M d, Y g:i A', strtotime($h['created_at'])) ?></td>
                    <td><span class="tag-delivered">Delivered</span></td>
                    <td>
                      <a href="rider.php?s=history&order_id=<?= $h['id'] ?>" class="btn btn-ghost btn-sm">View</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
                <?php if (!$found_history): ?>
                  <tr>
                    <td colspan="7" style="text-align:center;padding:40px;">
                      <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <div class="empty-state-title">No history yet</div>
                        <div class="empty-state-sub">Completed deliveries will appear here.</div>
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        <?php endif; ?>

      <?php endif; ?>

    </div><!-- /admin-content -->

    <footer class="admin-footer">
      <span>© <?= date('Y') ?> Overdose Cafe · Rider Portal</span>
      <span>Logged in as <strong style="color:var(--gold);"><?= htmlspecialchars($rider_user) ?></strong></span>
    </footer>
  </div><!-- /admin-main -->
</div><!-- /admin-shell -->

<script>
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