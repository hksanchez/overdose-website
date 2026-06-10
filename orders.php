<?php
session_start();
require_once 'includes/db.php';

$page_title = 'My Orders — Overdose Cafe';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$uid = $_SESSION['user_id'];
$placed = isset($_GET['placed']) ? (int)$_GET['placed'] : 0;

// Cancel order (only Pending orders)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    $oid = (int)$_POST['order_id'];
    $stmt = $conn->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ? AND user_id = ? AND status = 'Pending'");
    $stmt->bind_param("ii", $oid, $uid);
    $stmt->execute();
    header("Location: orders.php?cancelled=" . $oid);
    exit();
}

// Fetch orders for user
$orders = $conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$orders->bind_param("i", $uid);
$orders->execute();
$orders_result = $orders->get_result();

// Fetch order items for a specific order (for detail view)
$detail_order = null;
$detail_items = [];
if (isset($_GET['view'])) {
    $oid = (int)$_GET['view'];
    $oq = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $oq->bind_param("ii", $oid, $uid);
    $oq->execute();
    $detail_order = $oq->get_result()->fetch_assoc();

    if ($detail_order) {
        $iq = $conn->prepare("
            SELECT oi.*, p.name, p.category 
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $iq->bind_param("i", $oid);
        $iq->execute();
        $detail_items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

require_once 'includes/header.php';

$status_colors = [
    'Pending'    => '#D4AF5A',
    'Preparing'  => '#5B9BD4',
    'Ready'      => '#5BAD7E',
    'Completed'  => '#5BAD7E',
    'Cancelled'  => '#E05555',
];
?>

<div class="page-layout">
  <aside class="page-sidebar">
    <div class="sidebar-section-label">Menu</div>
    <ul class="sidebar-menu">
      <li><a href="products.php"><span class="menu-icon">☕</span> Products</a></li>
      <li><a href="cart.php"><span class="menu-icon">🛒</span> Cart</a></li>
      <li><a href="orders.php" class="active"><span class="menu-icon">📋</span> My Orders</a></li>
      <li><a href="settings.php"><span class="menu-icon">⚙️</span> Settings</a></li>
    </ul>
  </aside>

  <main class="page-content">
    <?php if ($placed): ?>
      <div class="alert alert-success" style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:1.4rem;">✅</span>
        <div><strong>Order #<?= $placed ?> placed!</strong> We're preparing your order. Track it below.</div>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['cancelled'])): ?>
      <div class="alert alert-error" style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:1.4rem;">❌</span>
        <div><strong>Order #<?= (int)$_GET['cancelled'] ?> cancelled.</strong></div>
      </div>
    <?php endif; ?>

    <?php if ($detail_order): ?>
      <!-- Order Detail View -->
      <div style="margin-bottom:20px;">
        <a href="orders.php" style="color:var(--gold);text-decoration:none;font-size:0.82rem;">← Back to all orders</a>
      </div>

      <h1 class="page-title">Order #<?= $detail_order['id'] ?></h1>
      <p class="page-subtitle">Placed on <?= date('F d, Y g:i A', strtotime($detail_order['created_at'])) ?></p>

      <div class="order-detail-grid">
        <!-- Items -->
        <div class="detail-card">
          <h3>Items Ordered</h3>
          <div class="detail-items">
            <?php foreach ($detail_items as $it): ?>
              <div class="detail-item-row">
                <div>
                  <span class="item-name-d"><?= htmlspecialchars($it['name']) ?></span>
                  <span class="item-cat"><?= ucfirst($it['category']) ?></span>
                </div>
                <div style="text-align:right;">
                  <div style="font-size:0.8rem;color:var(--muted);">x<?= $it['quantity'] ?> × ₱<?= number_format($it['price'], 2) ?></div>
                  <div style="font-weight:700;color:var(--gold);">₱<?= number_format($it['price'] * $it['quantity'], 2) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="detail-totals">
            <?php
              // Calculate items subtotal to derive delivery fee
              $items_subtotal = 0;
              foreach ($detail_items as $it) {
                  $items_subtotal += $it['price'] * $it['quantity'];
              }
              $detail_disc = $detail_order['discount'] ?? 0;
              $detail_fulfillment = $detail_order['fulfillment_type'] ?? 'pickup';
              $detail_delivery_fee = ($detail_fulfillment === 'delivery') ? 50.00 : 0.00;
            ?>
            <div class="detail-total-row">
              <span>Subtotal</span>
              <span>₱<?= number_format($items_subtotal, 2) ?></span>
            </div>
            <?php if ($detail_disc > 0): ?>
              <div class="detail-total-row">
                <span>Voucher Discount</span>
                <span style="color:var(--success);">−₱<?= number_format($detail_disc, 2) ?></span>
              </div>
            <?php endif; ?>
            <?php if ($detail_delivery_fee > 0): ?>
              <div class="detail-total-row">
                <span>Delivery Fee</span>
                <span style="color:var(--cream);">+₱<?= number_format($detail_delivery_fee, 2) ?></span>
              </div>
            <?php endif; ?>
            <div class="detail-total-row" style="font-weight:700;font-size:1rem;color:var(--cream);border-top:1px solid var(--border);padding-top:12px;margin-top:4px;">
              <span>Total Paid</span>
              <span style="color:var(--gold);">₱<?= number_format($detail_order['total_amount'], 2) ?></span>
            </div>
          </div>
        </div>

        <!-- Status -->
        <div class="detail-card">
          <h3>Order Status</h3>
          <?php
            $status = $detail_order['status'];
            $steps = ['Pending', 'Preparing', 'Ready', 'Completed'];
            $current_step = array_search($status, $steps);
            if ($current_step === false) $current_step = -1;
          ?>
          <div class="status-track">
            <?php foreach ($steps as $i => $step): ?>
              <div class="status-step <?= $i <= $current_step ? 'done' : '' ?> <?= $step === $status && $status !== 'Completed' ? 'current' : '' ?>">
                <div class="step-dot"></div>
                <div class="step-label"><?= $step ?></div>
              </div>
              <?php if ($i < count($steps) - 1): ?>
                <div class="step-line <?= $i < $current_step ? 'done' : '' ?>"></div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <?php if ($status === 'Cancelled'): ?>
            <div style="margin-top:20px;" class="alert alert-error">This order was cancelled.</div>
          <?php endif; ?>

          <?php if ($status === 'Pending'): ?>
            <form method="POST" style="margin-top:20px;" onsubmit="return confirm('Are you sure you want to cancel this order?')">
              <input type="hidden" name="order_id" value="<?= $detail_order['id'] ?>"/>
              <button type="submit" name="cancel_order" class="btn-cancel" style="width:100%;justify-content:center;padding:10px;">Cancel Order</button>
            </form>
          <?php endif; ?>

          <?php if ($detail_order['voucher_code']): ?>
            <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
              <p style="font-size:0.75rem;color:var(--muted);">Voucher applied:</p>
              <span class="gold-badge" style="margin-top:6px;display:inline-block;"><?= htmlspecialchars($detail_order['voucher_code']) ?></span>
            </div>
          <?php endif; ?>

          <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
            <p style="font-size:0.72rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-bottom:8px;">Fulfillment</p>
            <?php if (($detail_order['fulfillment_type'] ?? 'pickup') === 'delivery'): ?>
              <span class="fulfillment-badge-detail badge-delivery-detail">🛵 Delivery</span>
              <?php if (!empty($detail_order['delivery_address'])): ?>
                <div style="margin-top:10px;">
                  <p style="font-size:0.72rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;">Deliver to</p>
                  <p style="font-size:0.83rem;color:var(--cream);line-height:1.5;"><?= htmlspecialchars($detail_order['delivery_address']) ?></p>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <span class="fulfillment-badge-detail badge-pickup-detail">🏪 Pick Up</span>
              <p style="font-size:0.75rem;color:var(--muted);margin-top:6px;">📍 Overdose Cafe · Manila, PH</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

    <?php else: ?>
      <!-- Orders List -->
      <h1 class="page-title">My Orders</h1>
      <p class="page-subtitle">Your order history and transaction status.</p>

      <?php if ($orders_result->num_rows === 0): ?>
        <div class="empty-orders">
          <div style="font-size:3rem;opacity:0.3;margin-bottom:16px;">📋</div>
          <p style="color:var(--muted);">No orders yet.</p>
          <a href="products.php" class="btn-gold" style="margin-top:16px;">Start Ordering</a>
        </div>
      <?php else: ?>
        <div class="orders-list">
          <?php while ($ord = $orders_result->fetch_assoc()):
            $sc = $status_colors[$ord['status']] ?? '#aaa';
          ?>
            <div class="order-row">
              <div class="order-row-left">
                <div class="order-num">#<?= $ord['id'] ?></div>
                <div class="order-date"><?= date('M d, Y · g:i A', strtotime($ord['created_at'])) ?></div>
                <?php if ($ord['voucher_code']): ?>
                  <span class="gold-badge" style="margin-top:6px;"><?= htmlspecialchars($ord['voucher_code']) ?></span>
                <?php endif; ?>
              </div>
              <div class="order-row-right">
                <div style="text-align:right;">
                  <div class="order-total">₱<?= number_format($ord['total_amount'], 2) ?></div>
                  <?php if ($ord['discount'] > 0): ?>
                    <div style="font-size:0.72rem;color:var(--success);">Saved ₱<?= number_format($ord['discount'], 2) ?></div>
                  <?php endif; ?>
                </div>
                <div class="order-status" style="color:<?= $sc ?>;border-color:<?= $sc ?>22;background:<?= $sc ?>11;">
                  <?= $ord['status'] ?>
                </div>
                <a href="orders.php?view=<?= $ord['id'] ?>" class="btn-outline" style="padding:7px 14px;font-size:0.75rem;">View</a>
                <?php if ($ord['status'] === 'Pending'): ?>
                  <form method="POST" onsubmit="return confirm('Cancel order #<?= $ord['id'] ?>?')">
                    <input type="hidden" name="order_id" value="<?= $ord['id'] ?>"/>
                    <button type="submit" name="cancel_order" class="btn-cancel">Cancel</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<footer class="oc-footer">
  <div>© <?= date('Y') ?> Overdose Cafe · Manila, PH</div>
  <span>Crafted with ☕ and too much caffeine</span>
</footer>

<style>
  .orders-list { display: flex; flex-direction: column; gap: 12px; }

  .order-row {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    transition: border-color 0.2s;
  }

  .order-row:hover { border-color: var(--border-hover); }

  .order-num {
    font-family: 'Playfair Display', serif;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--cream);
    margin-bottom: 4px;
  }

  .order-date { font-size: 0.78rem; color: var(--muted); }

  .order-row-right {
    display: flex;
    align-items: center;
    gap: 20px;
  }

  .order-total {
    font-size: 1rem;
    font-weight: 700;
    color: var(--gold);
  }

  .order-status {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 4px 12px;
    border-radius: 20px;
    border: 1px solid;
  }

  .empty-orders { text-align: center; padding: 80px 0; }

  .btn-cancel {
    display: inline-flex;
    align-items: center;
    background: transparent;
    color: var(--error);
    border: 1px solid rgba(224,85,85,0.35);
    border-radius: 2px;
    padding: 7px 14px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
  }

  .btn-cancel:hover {
    background: rgba(224,85,85,0.1);
    border-color: var(--error);
  }

  /* Detail view */
  .order-detail-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 24px;
    align-items: start;
  }

  .detail-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 24px;
  }

  .detail-card h3 {
    font-family: 'Playfair Display', serif;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--cream);
    margin-bottom: 20px;
  }

  .detail-item-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
  }

  .item-name-d { display: block; font-size: 0.88rem; font-weight: 500; color: var(--cream); }
  .item-cat { font-size: 0.72rem; color: var(--muted); }

  .detail-totals {
    margin-top: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .detail-total-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.85rem;
    color: var(--muted);
  }

  /* Status tracker */
  .status-track {
    display: flex;
    align-items: center;
    margin: 24px 0;
  }

  .status-step { display: flex; flex-direction: column; align-items: center; gap: 6px; }

  .step-dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--panel);
    border: 2px solid var(--border);
    transition: all 0.3s;
  }

  .status-step.done .step-dot { background: var(--gold); border-color: var(--gold); }
  .status-step.current .step-dot { background: var(--gold); border-color: var(--gold); box-shadow: 0 0 0 4px rgba(212,175,90,0.2); }

  .step-label {
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    color: var(--muted2);
    white-space: nowrap;
  }

  .status-step.done .step-label,
  .status-step.current .step-label { color: var(--gold); }

  .step-line {
    flex: 1;
    height: 2px;
    background: var(--border);
    margin: 0 4px;
    margin-bottom: 20px;
  }

  .step-line.done { background: var(--gold); }

  .fulfillment-badge-detail {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    border: 1px solid;
  }

  .badge-pickup-detail {
    color: var(--gold);
    border-color: rgba(212,175,90,0.3);
    background: rgba(212,175,90,0.08);
  }

  .badge-delivery-detail {
    color: #5B9BD4;
    border-color: rgba(91,155,212,0.3);
    background: rgba(91,155,212,0.08);
  }

  @media (max-width: 900px) { .order-detail-grid { grid-template-columns: 1fr; } }
</style>

</body>
</html>